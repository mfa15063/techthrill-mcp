<?php
namespace TechThrill\MCP\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\Auth\Authenticator;
use TechThrill\MCP\Auth\RateLimiter;
use TechThrill\MCP\Auth\TokenManager;
use TechThrill\MCP\Mcp\JsonRpcError;
use TechThrill\MCP\Tests\Support\InMemoryRateLimitStore;
use TechThrill\MCP\Tests\Support\InMemoryTokenRepository;

class AuthenticatorTest extends TestCase {

	const BAD_HEADER = 'Missing or invalid Authorization header. Use: Authorization: Bearer <token>';

	/**
	 * Current fake time.
	 *
	 * @var int
	 */
	private $now = 1700000000;

	/**
	 * @var InMemoryTokenRepository
	 */
	private $repo;

	/**
	 * @var TokenManager
	 */
	private $tokens;

	/**
	 * @var InMemoryRateLimitStore
	 */
	private $store;

	protected function setUp(): void {
		$this->repo   = new InMemoryTokenRepository();
		$this->tokens = new TokenManager( $this->repo, fn() => $this->now );
		$this->store  = new InMemoryRateLimitStore();
	}

	private function authenticator( int $limit = 100, ?callable $is_admin = null ): Authenticator {
		$limiter = new RateLimiter( $limit, $this->store, fn() => $this->now );

		return new Authenticator( $this->tokens, $limiter, $is_admin ?? fn( int $user_id ) => true );
	}

	public function test_missing_header_401(): void {
		$auth = $this->authenticator();

		foreach ( array( null, '', '   ' ) as $header ) {
			$result = $auth->authenticate( $header );
			$this->assertFalse( $result->isOk() );
			$this->assertSame( 401, $result->status() );
			$this->assertSame( JsonRpcError::UNAUTHORIZED, $result->code() );
			$this->assertSame( self::BAD_HEADER, $result->message() );
			$this->assertSame( array(), $result->row() );
			$this->assertSame( array(), $result->scopes() );
			$this->assertSame( 0, $result->retryAfter() );
		}
	}

	public function test_basic_scheme_401(): void {
		$result = $this->authenticator()->authenticate( 'Basic abc' );

		$this->assertFalse( $result->isOk() );
		$this->assertSame( 401, $result->status() );
		$this->assertSame( JsonRpcError::UNAUTHORIZED, $result->code() );
		$this->assertSame( self::BAD_HEADER, $result->message() );
	}

	public function test_bearer_without_token_or_with_extra_words_401(): void {
		$auth = $this->authenticator();

		foreach ( array( 'Bearer', 'Bearer ', 'Bearer a b' ) as $header ) {
			$result = $auth->authenticate( $header );
			$this->assertSame( 401, $result->status(), $header );
			$this->assertSame( self::BAD_HEADER, $result->message(), $header );
		}
	}

	public function test_unknown_token_401(): void {
		$result = $this->authenticator()->authenticate( 'Bearer ttm_' . str_repeat( 'a', 40 ) );

		$this->assertFalse( $result->isOk() );
		$this->assertSame( 401, $result->status() );
		$this->assertSame( JsonRpcError::UNAUTHORIZED, $result->code() );
		$this->assertSame( 'Invalid, expired or revoked token.', $result->message() );
	}

	public function test_revoked_token_401(): void {
		$created = $this->tokens->create( 'Old', array( 'db:read' ), 1, null );
		$this->tokens->revoke( $created['id'] );

		$result = $this->authenticator()->authenticate( 'Bearer ' . $created['token'] );

		$this->assertSame( 401, $result->status() );
		$this->assertSame( 'Invalid, expired or revoked token.', $result->message() );
	}

	public function test_demoted_user_401(): void {
		$created = $this->tokens->create( 'Demoted', array( 'db:read' ), 42, null );
		$seen    = array();
		$auth    = $this->authenticator(
			100,
			function ( int $user_id ) use ( &$seen ) {
				$seen[] = $user_id;
				return false;
			}
		);

		$result = $auth->authenticate( 'Bearer ' . $created['token'] );

		$this->assertFalse( $result->isOk() );
		$this->assertSame( 401, $result->status() );
		$this->assertSame( JsonRpcError::UNAUTHORIZED, $result->code() );
		$this->assertStringContainsString( 'no longer', $result->message() );
		$this->assertSame( array( 42 ), $seen );
		$this->assertNull( $this->repo->find( $created['id'] )['last_used_at'] );
		// The admin check runs before the rate limiter, so no request was recorded.
		$this->assertSame( array(), $this->store->data );
	}

	public function test_rate_limited_429(): void {
		$created = $this->tokens->create( 'Busy', array( 'db:read' ), 1, null );
		$auth    = $this->authenticator( 1 );
		$header  = 'Bearer ' . $created['token'];

		$this->assertTrue( $auth->authenticate( $header )->isOk() );

		$this->now += 10;
		$result     = $auth->authenticate( $header );

		$this->assertFalse( $result->isOk() );
		$this->assertSame( 429, $result->status() );
		$this->assertSame( JsonRpcError::RATE_LIMITED, $result->code() );
		$this->assertSame( 50, $result->retryAfter() );
		$this->assertSame( 'Rate limit exceeded. Retry after 50 seconds.', $result->message() );
	}

	public function test_rate_limited_request_does_not_touch(): void {
		$created = $this->tokens->create( 'Busy', array( 'db:read' ), 1, null );

		// Pre-fill the window so the very first request is blocked.
		$this->store->data[ 'techthrill_mcp_rl_' . $created['id'] ] = array( $this->now - 5 );

		$result = $this->authenticator( 1 )->authenticate( 'Bearer ' . $created['token'] );

		$this->assertSame( 429, $result->status() );
		$this->assertNull( $this->repo->find( $created['id'] )['last_used_at'] );
	}

	public function test_header_scheme_is_case_insensitive_and_trimmed(): void {
		$created = $this->tokens->create( 'Case', array( 'db:read' ), 1, null );

		$result = $this->authenticator()->authenticate( '  bEaReR   ' . $created['token'] . '  ' );

		$this->assertTrue( $result->isOk() );
	}

	public function test_success_returns_expanded_scopes_and_touches(): void {
		$created = $this->tokens->create( 'Writer', array( 'files:write' ), 9, null );
		$result  = $this->authenticator()->authenticate( 'Bearer ' . $created['token'] );

		$this->assertTrue( $result->isOk() );
		$this->assertSame( 200, $result->status() );
		$this->assertSame( 0, $result->code() );
		$this->assertSame( '', $result->message() );
		$this->assertSame( 0, $result->retryAfter() );
		$this->assertContains( 'files:write', $result->scopes() );
		$this->assertContains( 'files:read', $result->scopes() );
		$this->assertSame( $created['id'], $result->row()['id'] );
		$this->assertSame( 9, $result->row()['user_id'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $this->now ), $this->repo->find( $created['id'] )['last_used_at'] );
	}

	public function test_success_never_exposes_hash_or_lookup(): void {
		$created = $this->tokens->create( 'Safe', array( 'db:read' ), 1, null );
		$result  = $this->authenticator()->authenticate( 'Bearer ' . $created['token'] );

		$this->assertTrue( $result->isOk() );
		$this->assertArrayNotHasKey( 'hash', $result->row() );
		$this->assertArrayNotHasKey( 'lookup', $result->row() );
		$this->assertStringNotContainsString( TokenManager::hash( $created['token'] ), json_encode( $result->row() ) );
		// The stored row is untouched.
		$this->assertArrayHasKey( 'hash', $this->repo->find( $created['id'] ) );
	}

	public function test_unknown_stored_scopes_are_dropped(): void {
		$created = $this->tokens->create( 'Odd', array( 'db:read' ), 1, null );
		$this->repo->update( $created['id'], array( 'scopes' => 'db:read,bogus, wp-cli' ) );

		$result = $this->authenticator()->authenticate( 'Bearer ' . $created['token'] );

		$this->assertSame( array( 'wp-cli', 'db:read' ), $result->scopes() );
	}
}
