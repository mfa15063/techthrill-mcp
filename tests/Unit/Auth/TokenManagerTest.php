<?php
namespace TechThrill\MCP\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\Auth\TokenManager;
use TechThrill\MCP\Tests\Support\InMemoryTokenRepository;

class TokenManagerTest extends TestCase {

	/**
	 * Current fake time.
	 *
	 * @var int
	 */
	private $now = 1700000000;

	private function manager( InMemoryTokenRepository $repo ): TokenManager {
		return new TokenManager( $repo, fn() => $this->now );
	}

	public function test_create_returns_well_formed_token_and_stores_hash_only(): void {
		$repo   = new InMemoryTokenRepository();
		$result = $this->manager( $repo )->create( 'Laptop', array( 'db:read' ), 7, null );

		$this->assertMatchesRegularExpression( '/^ttm_[A-Za-z0-9]{40}$/', $result['token'] );
		$row = $repo->find( $result['id'] );
		$this->assertSame( TokenManager::hash( $result['token'] ), $row['hash'] );
		$this->assertSame( substr( $result['token'], 0, 12 ), $row['lookup'] );
		$this->assertStringNotContainsString( $result['token'], json_encode( $row ) );
		$this->assertSame( 'Laptop', $row['name'] );
		$this->assertSame( 7, $row['user_id'] );
		$this->assertNull( $row['expires_at'] );
		$this->assertNull( $row['last_used_at'] );
		$this->assertNull( $row['revoked_at'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $this->now ), $row['created_at'] );
	}

	public function test_verify_round_trip(): void {
		$repo   = new InMemoryTokenRepository();
		$mgr    = $this->manager( $repo );
		$result = $mgr->create( 'CI', array( 'wp-cli' ), 3, null );

		$row = $mgr->verify( $result['token'] );
		$this->assertNotNull( $row );
		$this->assertSame( $result['id'], $row['id'] );
		$this->assertSame( 'wp-cli', $row['scopes'] );
	}

	public function test_verify_rejects_unknown_revoked_expired_and_malformed(): void {
		$repo = new InMemoryTokenRepository();
		$mgr  = $this->manager( $repo );

		// Unknown but well formed.
		$this->assertNull( $mgr->verify( 'ttm_' . str_repeat( 'a', 40 ) ) );

		// Revoked.
		$revoked = $mgr->create( 'Old', array( 'db:read' ), 1, null );
		$this->assertNotNull( $mgr->verify( $revoked['token'] ) );
		$mgr->revoke( $revoked['id'] );
		$this->assertNull( $mgr->verify( $revoked['token'] ) );

		// Expired: clock = expires_at + 1.
		$expires = gmdate( 'Y-m-d H:i:s', $this->now + 100 );
		$expired = $mgr->create( 'Short', array( 'db:read' ), 1, $expires );
		$this->assertNotNull( $mgr->verify( $expired['token'] ) );
		$this->now = strtotime( $expires . ' UTC' ) + 1;
		$this->assertNull( $mgr->verify( $expired['token'] ) );

		// Malformed.
		$this->assertNull( $mgr->verify( 'not-a-token' ) );
	}

	public function test_scopes_are_sanitized_and_expanded_on_create(): void {
		$repo   = new InMemoryTokenRepository();
		$result = $this->manager( $repo )->create( 'Scoped', array( 'db:write', 'bogus' ), 1, null );

		// Canonical Scopes::ALL order: the implied read scope comes first.
		$this->assertSame( 'db:read,db:write', $repo->find( $result['id'] )['scopes'] );
	}

	public function test_touch_only_after_sixty_seconds(): void {
		$repo   = new InMemoryTokenRepository();
		$mgr    = $this->manager( $repo );
		$result = $mgr->create( 'Touch', array( 'db:read' ), 1, null );
		$id     = $result['id'];

		$mgr->touch( $id );
		$first = gmdate( 'Y-m-d H:i:s', $this->now );
		$this->assertSame( $first, $repo->find( $id )['last_used_at'] );

		$this->now += 30;
		$mgr->touch( $id );
		$this->assertSame( $first, $repo->find( $id )['last_used_at'] );

		$this->now += 30;
		$mgr->touch( $id );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $this->now ), $repo->find( $id )['last_used_at'] );
	}

	public function test_name_is_trimmed_and_truncated(): void {
		$repo   = new InMemoryTokenRepository();
		$result = $this->manager( $repo )->create( '  ' . str_repeat( 'x', 150 ) . '  ', array(), 1, null );

		$this->assertSame( str_repeat( 'x', 100 ), $repo->find( $result['id'] )['name'] );
	}

	public function test_tokens_are_unique(): void {
		$mgr = $this->manager( new InMemoryTokenRepository() );
		$a   = $mgr->create( 'A', array(), 1, null );
		$b   = $mgr->create( 'B', array(), 1, null );

		$this->assertNotSame( $a['token'], $b['token'] );
	}

	public function test_is_well_formed(): void {
		$this->assertTrue( TokenManager::isWellFormed( 'ttm_' . str_repeat( 'Z', 40 ) ) );
		$this->assertFalse( TokenManager::isWellFormed( 'ttm_' . str_repeat( 'Z', 39 ) ) );
		$this->assertFalse( TokenManager::isWellFormed( 'ttm_' . str_repeat( 'Z', 40 ) . "\n" ) );
		$this->assertFalse( TokenManager::isWellFormed( 'xxx_' . str_repeat( 'Z', 40 ) ) );
	}
}
