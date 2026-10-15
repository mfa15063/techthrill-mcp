<?php
namespace TechThrill\MCP\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\OAuth\AuthorizeException;
use TechThrill\MCP\OAuth\AuthorizeValidator;
use TechThrill\MCP\OAuth\ClientMetadataFetcher;
use TechThrill\MCP\OAuth\ClientResolver;
use TechThrill\MCP\OAuth\Pkce;
use TechThrill\MCP\Tests\Support\InMemoryClientStore;

class AuthorizeValidatorTest extends TestCase {

	const RESOURCE = 'https://example.com/wp-json/techthrill-mcp/v1/mcp';
	const CB       = 'http://localhost:53682/callback';

	private InMemoryClientStore $store;

	private $http_response = null;

	protected function setUp(): void {
		$this->store = new InMemoryClientStore();
		$this->store->save( array( 'client_id' => 'ttc_a', 'client_name' => 'Claude Code', 'redirect_uris' => array( self::CB, 'https://claude.ai/api/mcp/auth_callback' ), 'source' => 'dcr', 'created_at' => 1, 'last_used_at' => null ) );
	}

	private function validator(): AuthorizeValidator {
		$fetcher = new ClientMetadataFetcher( fn( string $u ) => $this->http_response, $this->store );

		return new AuthorizeValidator( new ClientResolver( $this->store, $fetcher ), self::RESOURCE );
	}

	private function params( array $override = array() ): array {
		return array_merge(
			array(
				'response_type'         => 'code',
				'client_id'             => 'ttc_a',
				'redirect_uri'          => self::CB,
				'state'                 => 'st4te',
				'code_challenge'        => Pkce::challengeFor( str_repeat( 'v', 43 ) ),
				'code_challenge_method' => 'S256',
				'scope'                 => 'db:read files:read',
				'resource'              => self::RESOURCE,
			),
			$override
		);
	}

	private function error( array $params ): AuthorizeException {
		try {
			$this->validator()->validate( $params );
		} catch ( AuthorizeException $e ) {
			return $e;
		}
		$this->fail( 'Expected an AuthorizeException.' );
	}

	public function test_valid_request_returns_a_request_object(): void {
		$r = $this->validator()->validate( $this->params() );
		$this->assertSame( 'ttc_a', $r->client()['client_id'] );
		$this->assertSame( self::CB, $r->redirectUri() );
		$this->assertSame( 'st4te', $r->state() );
		$this->assertSame( array( 'db:read', 'files:read' ), $r->requestedScopes() );
		$this->assertSame( self::RESOURCE, $r->resource() );
		$this->assertSame( 'S256', $r->params()['code_challenge_method'] );
	}

	public function test_scope_and_resource_are_optional(): void {
		$p = $this->params();
		unset( $p['scope'], $p['resource'] );
		$r = $this->validator()->validate( $p );
		$this->assertSame( array(), $r->requestedScopes() );
		$this->assertSame( '', $r->resource() );
	}

	public function test_unknown_client_and_unregistered_redirect_uri_do_not_redirect(): void {
		$this->assertNull( $this->error( $this->params( array( 'client_id' => 'nope' ) ) )->redirectUri() );
		$this->assertNull( $this->error( $this->params( array( 'client_id' => '' ) ) )->redirectUri() );
		$this->assertNull( $this->error( $this->params( array( 'redirect_uri' => 'http://localhost:9999/other' ) ) )->redirectUri() );
		$this->assertNull( $this->error( $this->params( array( 'redirect_uri' => '' ) ) )->redirectUri() );
		$this->assertNull( $this->error( $this->params( array( 'client_id' => array( 'x' ) ) ) )->redirectUri() );
	}

	public function test_redirect_uri_must_match_byte_for_byte(): void {
		foreach ( array( self::CB . '/', 'HTTP://localhost:53682/callback', 'http://localhost:53683/callback', 'http://localhost:53682/Callback' ) as $uri ) {
			$this->assertNull( $this->error( $this->params( array( 'redirect_uri' => $uri ) ) )->redirectUri(), $uri );
		}
	}

	public function test_a_stored_uri_that_fails_the_allowlist_is_never_used(): void {
		$this->store->save( array( 'client_id' => 'ttc_bad', 'client_name' => 'X', 'redirect_uris' => array( 'https://evil.com/cb' ), 'source' => 'dcr', 'created_at' => 1, 'last_used_at' => null ) );
		$this->assertNull( $this->error( $this->params( array( 'client_id' => 'ttc_bad', 'redirect_uri' => 'https://evil.com/cb' ) ) )->redirectUri() );
	}

	public function test_missing_plain_or_malformed_challenge_is_invalid_request(): void {
		foreach ( array(
			array( 'code_challenge_method' => 'plain' ),
			array( 'code_challenge_method' => '' ),
			array( 'code_challenge' => '' ),
			array( 'code_challenge' => 'short' ),
		) as $override ) {
			$e = $this->error( $this->params( $override ) );
			$this->assertSame( 'invalid_request', $e->error() );
			$this->assertSame( self::CB, $e->redirectUri() );
			$this->assertSame( 'st4te', $e->state() );
		}
	}

	public function test_response_type_must_be_code(): void {
		$this->assertSame( 'invalid_request', $this->error( $this->params( array( 'response_type' => 'token' ) ) )->error() );
	}

	public function test_resource_must_equal_the_endpoint_when_present(): void {
		$e = $this->error( $this->params( array( 'resource' => 'https://evil.com/mcp' ) ) );
		$this->assertSame( 'invalid_target', $e->error() );
		$this->assertSame( self::CB, $e->redirectUri() );
	}

	public function test_unknown_scope_is_invalid_scope_but_known_scopes_pass(): void {
		$this->assertSame( 'invalid_scope', $this->error( $this->params( array( 'scope' => 'db:read admin' ) ) )->error() );
		$r = $this->validator()->validate( $this->params( array( 'scope' => 'wp-cli db:write wp-cli' ) ) );
		$this->assertSame( array( 'wp-cli', 'db:write' ), $r->requestedScopes() );
	}

	public function test_overlong_state_is_refused(): void {
		$this->assertSame( 'invalid_request', $this->error( $this->params( array( 'state' => str_repeat( 's', 1025 ) ) ) )->error() );
	}

	public function test_cimd_client_ids_are_resolved(): void {
		$url                 = 'https://app.example.com/client.json';
		$this->http_response = array(
			'status'       => 200,
			'content_type' => 'application/json',
			'body'         => json_encode( array( 'client_id' => $url, 'client_name' => 'App', 'redirect_uris' => array( self::CB ) ) ),
		);
		$r = $this->validator()->validate( $this->params( array( 'client_id' => $url ) ) );
		$this->assertSame( 'App', $r->client()['client_name'] );
	}
}
