<?php
namespace TechThrill\MCP\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\Auth\Authenticator;
use TechThrill\MCP\Auth\RateLimiter;
use TechThrill\MCP\Auth\TokenManager;
use TechThrill\MCP\OAuth\AttemptLimiter;
use TechThrill\MCP\OAuth\ClientMetadataFetcher;
use TechThrill\MCP\OAuth\ClientResolver;
use TechThrill\MCP\OAuth\OptionAuthorizationCodeStore;
use TechThrill\MCP\OAuth\Pkce;
use TechThrill\MCP\OAuth\TokenEndpoint;
use TechThrill\MCP\Tests\Support\InMemoryClientStore;
use TechThrill\MCP\Tests\Support\InMemoryOptionsGateway;
use TechThrill\MCP\Tests\Support\InMemoryRateLimitStore;
use TechThrill\MCP\Tests\Support\InMemoryTokenRepository;

class TokenEndpointTest extends TestCase {

	const RESOURCE = 'https://example.com/wp-json/techthrill-mcp/v1/mcp';
	const CB       = 'http://localhost:53682/callback';

	private int $now = 1700000000;

	private InMemoryClientStore $clients;

	private InMemoryTokenRepository $repo;

	private TokenManager $tokens;

	private OptionAuthorizationCodeStore $codes;

	private string $verifier;

	private InMemoryRateLimitStore $limits;

	protected function setUp(): void {
		$this->clients  = new InMemoryClientStore();
		$this->clients->save( array( 'client_id' => 'ttc_a', 'client_name' => 'Claude Code', 'redirect_uris' => array( self::CB ), 'source' => 'dcr', 'created_at' => 1, 'last_used_at' => null ) );
		$this->clients->save( array( 'client_id' => 'ttc_b', 'client_name' => 'Other', 'redirect_uris' => array( self::CB ), 'source' => 'dcr', 'created_at' => 1, 'last_used_at' => null ) );
		$this->repo     = new InMemoryTokenRepository();
		$this->tokens   = new TokenManager( $this->repo, fn() => $this->now );
		$this->codes    = new OptionAuthorizationCodeStore( new InMemoryOptionsGateway(), fn() => $this->now );
		$this->verifier = str_repeat( 'v', 50 );
		$this->limits   = new InMemoryRateLimitStore();
	}

	private function endpoint(): TokenEndpoint {
		$resolver = new ClientResolver( $this->clients, new ClientMetadataFetcher( fn( string $u ) => null, $this->clients ) );

		return new TokenEndpoint( $resolver, $this->codes, $this->tokens, new AttemptLimiter( $this->limits, fn() => $this->now ), self::RESOURCE );
	}

	private function code( array $override = array() ): string {
		return $this->codes->issue(
			array_merge(
				array(
					'client_id'      => 'ttc_a',
					'redirect_uri'   => self::CB,
					'code_challenge' => Pkce::challengeFor( $this->verifier ),
					'resource'       => self::RESOURCE,
					'scopes'         => array( 'db:read', 'files:read' ),
					'user_id'        => 5,
				),
				$override
			),
			60
		);
	}

	private function form( string $code, array $override = array() ): array {
		return array_merge(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => self::CB,
				'client_id'     => 'ttc_a',
				'code_verifier' => $this->verifier,
			),
			$override
		);
	}

	private function exchange(): array {
		return $this->endpoint()->handle( $this->form( $this->code() ), '1.1.1.1' )['body'];
	}

	public function test_authorization_code_exchange_returns_tokens_and_the_granted_scopes(): void {
		$r = $this->endpoint()->handle( $this->form( $this->code() ), '1.1.1.1' );

		$this->assertSame( 200, $r['status'] );
		$b = $r['body'];
		$this->assertSame( 'Bearer', $b['token_type'] );
		$this->assertSame( 3600, $b['expires_in'] );
		$this->assertSame( 'db:read files:read', $b['scope'] );
		$this->assertMatchesRegularExpression( '/^ttm_[A-Za-z0-9]{40}$/', $b['access_token'] );
		$this->assertMatchesRegularExpression( '/^ttr_[A-Za-z0-9]{40}$/', $b['refresh_token'] );
		$this->assertNotNull( $this->clients->find( 'ttc_a' )['last_used_at'] );
		$this->assertSame( 'OAuth: Claude Code', $this->repo->all()[0]['name'] );
	}

	public function test_every_response_is_no_store(): void {
		$ok  = $this->endpoint()->handle( $this->form( $this->code() ), '1.1.1.1' );
		$bad = $this->endpoint()->handle( array( 'grant_type' => 'password' ), '1.1.1.1' );
		foreach ( array( $ok, $bad ) as $r ) {
			$this->assertSame( 'no-store', $r['headers']['Cache-Control'] );
			$this->assertSame( 'no-cache', $r['headers']['Pragma'] );
		}
	}

	public function test_the_code_cannot_be_used_twice(): void {
		$code = $this->code();
		$this->assertSame( 200, $this->endpoint()->handle( $this->form( $code ), '1.1.1.1' )['status'] );

		$second = $this->endpoint()->handle( $this->form( $code ), '1.1.1.1' );
		$this->assertSame( 400, $second['status'] );
		$this->assertSame( 'invalid_grant', $second['body']['error'] );
		$this->assertCount( 1, $this->repo->all(), 'Only one grant was created.' );
	}

	public function test_wrong_verifier_fails_and_still_burns_the_code(): void {
		$code = $this->code();
		$bad  = $this->endpoint()->handle( $this->form( $code, array( 'code_verifier' => str_repeat( 'w', 50 ) ) ), '1.1.1.1' );
		$this->assertSame( 'invalid_grant', $bad['body']['error'] );

		$retry = $this->endpoint()->handle( $this->form( $code ), '1.1.1.1' );
		$this->assertSame( 'invalid_grant', $retry['body']['error'], 'The correct verifier no longer helps.' );
		$this->assertSame( array(), $this->repo->all() );
	}

	public function test_wrong_client_or_redirect_uri_is_invalid_grant(): void {
		$r = $this->endpoint()->handle( $this->form( $this->code(), array( 'client_id' => 'ttc_b' ) ), '1.1.1.1' );
		$this->assertSame( 'invalid_grant', $r['body']['error'] );

		$r = $this->endpoint()->handle( $this->form( $this->code(), array( 'redirect_uri' => 'http://localhost:1/other' ) ), '1.1.1.1' );
		$this->assertSame( 'invalid_grant', $r['body']['error'] );

		$r = $this->endpoint()->handle( $this->form( $this->code(), array( 'client_id' => 'ttc_unknown' ) ), '1.1.1.1' );
		$this->assertSame( 401, $r['status'] );
		$this->assertSame( array(), $this->repo->all() );
	}

	public function test_expired_code_is_invalid_grant(): void {
		$code       = $this->code();
		$this->now += 61;
		$this->assertSame( 'invalid_grant', $this->endpoint()->handle( $this->form( $code ), '1.1.1.1' )['body']['error'] );
	}

	public function test_resource_must_match_when_sent(): void {
		$r = $this->endpoint()->handle( $this->form( $this->code(), array( 'resource' => 'https://evil.com/mcp' ) ), '1.1.1.1' );
		$this->assertSame( 'invalid_target', $r['body']['error'] );

		$ok = $this->endpoint()->handle( $this->form( $this->code(), array( 'resource' => self::RESOURCE ) ), '1.1.1.1' );
		$this->assertSame( 200, $ok['status'] );
	}

	public function test_malformed_verifier_is_invalid_request(): void {
		$r = $this->endpoint()->handle( $this->form( $this->code(), array( 'code_verifier' => 'short' ) ), '1.1.1.1' );
		$this->assertSame( 'invalid_request', $r['body']['error'] );
	}

	public function test_missing_fields_and_unknown_grant_type_errors(): void {
		$r = $this->endpoint()->handle( array( 'grant_type' => 'authorization_code', 'code' => 'x' ), '1.1.1.1' );
		$this->assertSame( 'invalid_request', $r['body']['error'] );

		$r = $this->endpoint()->handle( array( 'grant_type' => 'client_credentials' ), '1.1.1.1' );
		$this->assertSame( 'unsupported_grant_type', $r['body']['error'] );

		$r = $this->endpoint()->handle( array(), '1.1.1.1' );
		$this->assertSame( 'unsupported_grant_type', $r['body']['error'] );

		$r = $this->endpoint()->handle( array( 'grant_type' => array( 'authorization_code' ) ), '1.1.1.1' );
		$this->assertSame( 'unsupported_grant_type', $r['body']['error'] );
	}

	public function test_refresh_rotates_and_the_old_refresh_token_fails(): void {
		$first = $this->exchange();
		$r     = $this->endpoint()->handle( array( 'grant_type' => 'refresh_token', 'refresh_token' => $first['refresh_token'], 'client_id' => 'ttc_a' ), '1.1.1.1' );

		$this->assertSame( 200, $r['status'] );
		$this->assertNotSame( $first['refresh_token'], $r['body']['refresh_token'] );
		$this->assertNotSame( $first['access_token'], $r['body']['access_token'] );

		$again = $this->endpoint()->handle( array( 'grant_type' => 'refresh_token', 'refresh_token' => $first['refresh_token'], 'client_id' => 'ttc_a' ), '1.1.1.1' );
		$this->assertSame( 'invalid_grant', $again['body']['error'] );
		$this->assertCount( 1, $this->repo->all() );
	}

	public function test_refresh_with_another_clients_id_fails(): void {
		$first = $this->exchange();
		$r     = $this->endpoint()->handle( array( 'grant_type' => 'refresh_token', 'refresh_token' => $first['refresh_token'], 'client_id' => 'ttc_b' ), '1.1.1.1' );
		$this->assertSame( 'invalid_grant', $r['body']['error'] );
	}

	public function test_refresh_scope_can_only_narrow(): void {
		$first  = $this->exchange();
		$narrow = $this->endpoint()->handle( array( 'grant_type' => 'refresh_token', 'refresh_token' => $first['refresh_token'], 'client_id' => 'ttc_a', 'scope' => 'db:read db:write' ), '1.1.1.1' );
		$this->assertSame( 'db:read', $narrow['body']['scope'] );

		$bad = $this->endpoint()->handle( array( 'grant_type' => 'refresh_token', 'refresh_token' => $narrow['body']['refresh_token'], 'client_id' => 'ttc_a', 'scope' => 'admin' ), '1.1.1.1' );
		$this->assertSame( 'invalid_scope', $bad['body']['error'] );
	}

	public function test_refresh_requires_fields_and_a_known_client(): void {
		$this->assertSame( 'invalid_request', $this->endpoint()->handle( array( 'grant_type' => 'refresh_token' ), '1.1.1.1' )['body']['error'] );
		$this->assertSame( 401, $this->endpoint()->handle( array( 'grant_type' => 'refresh_token', 'refresh_token' => 'ttr_x', 'client_id' => 'nope' ), '1.1.1.1' )['status'] );
	}

	public function test_rate_limit_returns_429_with_retry_after(): void {
		$e = $this->endpoint();
		for ( $i = 0; $i < TokenEndpoint::LIMIT; $i++ ) {
			$e->handle( array( 'grant_type' => 'x' ), '7.7.7.7' );
		}
		$r = $e->handle( array( 'grant_type' => 'x' ), '7.7.7.7' );
		$this->assertSame( 429, $r['status'] );
		$this->assertArrayHasKey( 'Retry-After', $r['headers'] );
		$this->assertSame( 400, $e->handle( array( 'grant_type' => 'x' ), '8.8.8.8' )['status'] );
	}

	public function test_error_bodies_never_echo_submitted_secrets(): void {
		$r = $this->endpoint()->handle( $this->form( 'SECRETCODE-123', array( 'code_verifier' => 'SECRETVERIFIER' . str_repeat( 'z', 40 ) ) ), '1.1.1.1' );
		$json = json_encode( $r );
		$this->assertStringNotContainsString( 'SECRETCODE', $json );
		$this->assertStringNotContainsString( 'SECRETVERIFIER', $json );
	}

	public function test_issued_access_token_authenticates_through_the_existing_authenticator(): void {
		$grant = $this->exchange();
		$auth  = new Authenticator( $this->tokens, new RateLimiter( 100, new InMemoryRateLimitStore(), fn() => $this->now ), fn( int $u ) => true );

		$result = $auth->authenticate( 'Bearer ' . $grant['access_token'] );
		$this->assertTrue( $result->isOk() );
		$this->assertSame( array( 'db:read', 'files:read' ), $result->scopes() );
		$this->assertSame( 5, $result->row()['user_id'] );

		$this->now += 3601;
		$this->assertFalse( $auth->authenticate( 'Bearer ' . $grant['access_token'] )->isOk(), 'The access token expires after an hour.' );
		$this->assertSame( 'invalid', $auth->authenticate( 'Bearer ' . $grant['access_token'] )->reason() );
	}
}
