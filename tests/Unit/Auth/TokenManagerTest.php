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

	public function test_empty_expiry_is_normalised_to_null_and_verifies(): void {
		$repo   = new InMemoryTokenRepository();
		$mgr    = $this->manager( $repo );
		$result = $mgr->create( 'Blank', array( 'db:read' ), 1, '  ' );

		$this->assertNull( $repo->find( $result['id'] )['expires_at'] );
		$this->assertNotNull( $mgr->verify( $result['token'] ) );

		// A legacy row that stored an empty string still verifies.
		$repo->update( $result['id'], array( 'expires_at' => '' ) );
		$this->assertNotNull( $mgr->verify( $result['token'] ) );
	}

	public function test_unparseable_expiry_fails_closed(): void {
		$repo   = new InMemoryTokenRepository();
		$mgr    = $this->manager( $repo );
		$result = $mgr->create( 'Bad', array( 'db:read' ), 1, null );
		$repo->update( $result['id'], array( 'expires_at' => 'garbage' ) );

		$this->assertNull( $mgr->verify( $result['token'] ) );
	}

	public function test_expiry_equal_to_now_is_expired(): void {
		$repo   = new InMemoryTokenRepository();
		$mgr    = $this->manager( $repo );
		$result = $mgr->create( 'Edge', array( 'db:read' ), 1, gmdate( 'Y-m-d H:i:s', $this->now ) );

		$this->assertNull( $mgr->verify( $result['token'] ) );
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

	public function test_create_throws_when_the_repository_fails_to_store(): void {
		$repo = new class() extends InMemoryTokenRepository {
			public function insert( array $row ): int {
				return 0;
			}
		};

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Could not store the token.' );
		$this->manager( $repo )->create( 'Laptop', array( 'db:read' ), 7, null );
	}

	public function test_create_oauth_returns_two_tokens_and_stores_only_hashes(): void {
		$repo   = new InMemoryTokenRepository();
		$result = $this->manager( $repo )->createOAuth( 'ttc_client1', 'Claude', array( 'db:read', 'files:read' ), 5 );

		$this->assertMatchesRegularExpression( '/^ttm_[A-Za-z0-9]{40}$/', $result['access_token'] );
		$this->assertMatchesRegularExpression( '/^ttr_[A-Za-z0-9]{40}$/', $result['refresh_token'] );
		$this->assertSame( 3600, $result['expires_in'] );
		$this->assertSame( array( 'db:read', 'files:read' ), $result['scopes'] );

		$row = $repo->find( $result['id'] );
		$this->assertSame( 'oauth', $row['kind'] );
		$this->assertSame( 'ttc_client1', $row['client_id'] );
		$this->assertSame( 'OAuth: Claude', $row['name'] );
		$this->assertSame( 5, $row['user_id'] );
		$this->assertSame( TokenManager::hash( $result['refresh_token'] ), $row['refresh_hash'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $this->now + 3600 ), $row['expires_at'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $this->now + 2592000 ), $row['refresh_expires_at'] );
		$json = json_encode( $row );
		$this->assertStringNotContainsString( $result['access_token'], $json );
		$this->assertStringNotContainsString( $result['refresh_token'], $json );
	}

	public function test_access_token_verifies_like_any_bearer_token_until_it_expires(): void {
		$mgr    = $this->manager( new InMemoryTokenRepository() );
		$result = $mgr->createOAuth( 'ttc_client1', 'Claude', array( 'db:read' ), 5 );

		$this->assertNotNull( $mgr->verify( $result['access_token'] ) );
		$this->now += 3601;
		$this->assertNull( $mgr->verify( $result['access_token'] ) );
	}

	public function test_refresh_rotates_both_tokens_in_place(): void {
		$repo = new InMemoryTokenRepository();
		$mgr  = $this->manager( $repo );
		$old  = $mgr->createOAuth( 'ttc_client1', 'Claude', array( 'db:read', 'files:read' ), 5 );

		$this->now += 4000;
		$new = $mgr->refreshOAuth( $old['refresh_token'], 'ttc_client1' );

		$this->assertNotNull( $new );
		$this->assertSame( $old['id'], $new['id'] );
		$this->assertNotSame( $old['access_token'], $new['access_token'] );
		$this->assertNotSame( $old['refresh_token'], $new['refresh_token'] );
		$this->assertNull( $mgr->verify( $old['access_token'] ) );
		$this->assertNull( $mgr->refreshOAuth( $old['refresh_token'], 'ttc_client1' ), 'A refresh token works once.' );
		$this->assertNotNull( $mgr->verify( $new['access_token'] ) );
		$this->assertCount( 1, $repo->all() );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $this->now + 3600 ), $repo->find( $old['id'] )['expires_at'] );
	}

	public function test_refresh_with_wrong_client_or_unknown_token_returns_null(): void {
		$mgr = $this->manager( new InMemoryTokenRepository() );
		$old = $mgr->createOAuth( 'ttc_client1', 'Claude', array( 'db:read' ), 5 );

		$this->assertNull( $mgr->refreshOAuth( $old['refresh_token'], 'ttc_other' ) );
		$this->assertNull( $mgr->refreshOAuth( 'ttr_' . str_repeat( 'x', 40 ), 'ttc_client1' ) );
		$this->assertNull( $mgr->refreshOAuth( 'garbage', 'ttc_client1' ) );
		$this->assertNotNull( $mgr->refreshOAuth( $old['refresh_token'], 'ttc_client1' ), 'A wrong-client attempt does not burn the token.' );
	}

	public function test_refresh_after_expiry_or_revocation_returns_null(): void {
		$repo = new InMemoryTokenRepository();
		$mgr  = $this->manager( $repo );
		$a    = $mgr->createOAuth( 'ttc_client1', 'Claude', array( 'db:read' ), 5 );
		$b    = $mgr->createOAuth( 'ttc_client1', 'Claude', array( 'db:read' ), 5 );

		$mgr->revoke( $b['id'] );
		$this->assertNull( $mgr->refreshOAuth( $b['refresh_token'], 'ttc_client1' ) );

		$this->now += 2592001;
		$this->assertNull( $mgr->refreshOAuth( $a['refresh_token'], 'ttc_client1' ) );
	}

	public function test_refresh_can_only_narrow_scopes(): void {
		$mgr = $this->manager( new InMemoryTokenRepository() );
		$old = $mgr->createOAuth( 'ttc_client1', 'Claude', array( 'db:read', 'files:read' ), 5 );

		$narrow = $mgr->refreshOAuth( $old['refresh_token'], 'ttc_client1', array( 'db:read', 'db:write' ) );
		$this->assertSame( array( 'db:read' ), $narrow['scopes'] );

		$none = $mgr->refreshOAuth( $narrow['refresh_token'], 'ttc_client1', array( 'wp-cli' ) );
		$this->assertNull( $none, 'An empty intersection is refused.' );
	}

	public function test_bearer_rows_are_not_refreshable_and_are_unaffected(): void {
		$mgr    = $this->manager( new InMemoryTokenRepository() );
		$bearer = $mgr->create( 'CI', array( 'wp-cli' ), 3, null );

		$this->assertNotNull( $mgr->verify( $bearer['token'] ) );
		$this->assertNull( $mgr->refreshOAuth( 'ttr_' . str_repeat( 'a', 40 ), '' ) );
	}
}
