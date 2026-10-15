<?php
namespace TechThrill\MCP\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\OAuth\AttemptLimiter;
use TechThrill\MCP\OAuth\ClientRegistrar;
use TechThrill\MCP\OAuth\OAuthException;
use TechThrill\MCP\Tests\Support\InMemoryClientStore;
use TechThrill\MCP\Tests\Support\InMemoryRateLimitStore;

class ClientRegistrarTest extends TestCase {

	private int $now = 1700000000;

	private InMemoryClientStore $store;

	private function registrar(): ClientRegistrar {
		$this->store = $this->store ?? new InMemoryClientStore();

		return new ClientRegistrar( $this->store, new AttemptLimiter( new InMemoryRateLimitStore(), fn() => $this->now ), fn() => $this->now );
	}

	private function valid( array $extra = array() ): array {
		return array_merge(
			array(
				'client_name'   => 'Claude',
				'redirect_uris' => array( 'https://claude.ai/api/mcp/auth_callback' ),
			),
			$extra
		);
	}

	private function error( array $metadata, string $ip = '1.1.1.1' ): OAuthException {
		try {
			$this->registrar()->register( $metadata, $ip );
		} catch ( OAuthException $e ) {
			return $e;
		}
		$this->fail( 'Expected an OAuthException.' );
	}

	public function test_registers_a_public_client_with_allowlisted_uris(): void {
		$result = $this->registrar()->register( $this->valid(), '1.1.1.1' );

		$this->assertMatchesRegularExpression( '/^ttc_[A-Za-z0-9]{24}$/', $result['client_id'] );
		$this->assertSame( 'Claude', $result['client_name'] );
		$this->assertSame( 'none', $result['token_endpoint_auth_method'] );
		$this->assertSame( array( 'code' ), $result['response_types'] );
		$this->assertSame( array( 'authorization_code', 'refresh_token' ), $result['grant_types'] );
		$this->assertArrayNotHasKey( 'client_secret', $result );

		$stored = $this->store->find( $result['client_id'] );
		$this->assertSame( 'dcr', $stored['source'] );
		$this->assertNull( $stored['last_used_at'] );
		$this->assertSame( $this->now, $stored['created_at'] );
	}

	public function test_rejects_uris_outside_the_allowlist_and_empty_or_too_many(): void {
		foreach ( array( array(), array( 'https://evil.com/cb' ), array( 'https://claude.ai/api/mcp/auth_callback', 'http://localhost.evil.com/' ), array( 5 ) ) as $uris ) {
			$e = $this->error( $this->valid( array( 'redirect_uris' => $uris ) ) );
			$this->assertSame( 'invalid_client_metadata', $e->error() );
			$this->assertSame( 400, $e->status() );
		}
		$many = array_map( fn( $i ) => 'http://localhost:' . ( 3000 + $i ) . '/cb', range( 1, 6 ) );
		$this->assertSame( 'invalid_client_metadata', $this->error( $this->valid( array( 'redirect_uris' => $many ) ) )->error() );
		$this->assertSame( 'invalid_client_metadata', $this->error( array( 'client_name' => 'x' ) )->error() );
		$this->assertSame( 'invalid_client_metadata', $this->error( $this->valid( array( 'redirect_uris' => 'https://claude.ai/api/mcp/auth_callback' ) ) )->error() );
	}

	public function test_rejects_confidential_auth_methods_and_unsupported_grants(): void {
		$this->assertSame( 'invalid_client_metadata', $this->error( $this->valid( array( 'token_endpoint_auth_method' => 'client_secret_basic' ) ) )->error() );
		$this->assertSame( 'invalid_client_metadata', $this->error( $this->valid( array( 'grant_types' => array( 'authorization_code', 'client_credentials' ) ) ) )->error() );
		$this->assertSame( 'invalid_client_metadata', $this->error( $this->valid( array( 'grant_types' => array() ) ) )->error() );
		$this->assertSame( 'invalid_client_metadata', $this->error( $this->valid( array( 'response_types' => array( 'token' ) ) ) )->error() );
		$ok = $this->registrar()->register( $this->valid( array( 'token_endpoint_auth_method' => 'none', 'grant_types' => array( 'authorization_code' ) ) ), '2.2.2.2' );
		$this->assertSame( array( 'authorization_code' ), $ok['grant_types'] );
	}

	public function test_client_name_is_sanitized_and_defaulted(): void {
		$this->assertSame( 'Claude', $this->registrar()->register( $this->valid( array( 'client_name' => '<b>Claude</b>' ) ), '3.3.3.3' )['client_name'] );
		$this->assertSame( 'MCP client', $this->registrar()->register( array( 'redirect_uris' => array( 'http://localhost:1/cb' ) ), '3.3.3.4' )['client_name'] );
		$this->assertSame( 'invalid_client_metadata', $this->error( $this->valid( array( 'client_name' => array( 'x' ) ) ) )->error() );
	}

	public function test_rate_limit_blocks_the_eleventh_registration_from_one_ip(): void {
		$r = $this->registrar();
		for ( $i = 0; $i < 10; $i++ ) {
			$r->register( $this->valid(), '9.9.9.9' );
		}
		try {
			$r->register( $this->valid(), '9.9.9.9' );
			$this->fail( 'Expected a rate-limit error.' );
		} catch ( OAuthException $e ) {
			$this->assertSame( 429, $e->status() );
		}
		$this->assertNotEmpty( $r->register( $this->valid(), '8.8.8.8' ), 'Another IP is not limited.' );
	}

	public function test_prune_removes_old_unused_clients_and_keeps_used_ones(): void {
		$r = $this->registrar();
		$this->store->save( array( 'client_id' => 'old_unused', 'source' => 'dcr', 'created_at' => $this->now - 2592001, 'last_used_at' => null ) );
		$this->store->save( array( 'client_id' => 'old_used', 'source' => 'dcr', 'created_at' => $this->now - 2592001, 'last_used_at' => $this->now - 5 ) );
		$this->store->save( array( 'client_id' => 'new_unused', 'source' => 'dcr', 'created_at' => $this->now - 10, 'last_used_at' => null ) );
		$this->store->save( array( 'client_id' => 'cimd', 'source' => 'cimd', 'created_at' => $this->now - 9999999, 'last_used_at' => null ) );

		$this->assertSame( 1, $r->prune() );
		$this->assertNull( $this->store->find( 'old_unused' ) );
		$this->assertNotNull( $this->store->find( 'old_used' ) );
		$this->assertNotNull( $this->store->find( 'new_unused' ) );
		$this->assertNotNull( $this->store->find( 'cimd' ), 'Cached metadata clients are not pruned here.' );
	}

	public function test_prune_enforces_the_cap_oldest_unused_first(): void {
		$r = $this->registrar();
		for ( $i = 0; $i < ClientRegistrar::MAX_CLIENTS + 3; $i++ ) {
			$this->store->save( array( 'client_id' => 'c' . $i, 'source' => 'dcr', 'created_at' => $this->now - 1000 + $i, 'last_used_at' => null ) );
		}
		$this->store->save( array( 'client_id' => 'used_oldest', 'source' => 'dcr', 'created_at' => $this->now - 5000, 'last_used_at' => $this->now - 1 ) );

		$this->assertSame( 4, $r->prune() );
		$this->assertCount( ClientRegistrar::MAX_CLIENTS, $this->store->all() );
		$this->assertNotNull( $this->store->find( 'used_oldest' ), 'A client with a grant outranks unused ones.' );
		$this->assertNull( $this->store->find( 'c0' ) );
		$this->assertNotNull( $this->store->find( 'c' . ( ClientRegistrar::MAX_CLIENTS + 2 ) ) );
	}
}
