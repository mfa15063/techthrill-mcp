<?php
namespace TechThrill\MCP\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\Admin\TokenBulk;
use TechThrill\MCP\Auth\TokenManager;
use TechThrill\MCP\Tests\Support\InMemoryTokenRepository;

class TokenBulkTest extends TestCase {

	private int $now = 1800000000;

	private InMemoryTokenRepository $repo;

	private TokenManager $tokens;

	protected function setUp(): void {
		$this->repo   = new InMemoryTokenRepository();
		$this->tokens = new TokenManager( $this->repo, fn() => $this->now );
	}

	private function make( ?string $expires = null ): int {
		return $this->tokens->create( 'T', array( 'db:read' ), 1, $expires )['id'];
	}

	public function test_revoke_selected_revokes_every_active_token(): void {
		$a = $this->make();
		$b = $this->make();
		$c = $this->make();

		$r = TokenBulk::apply( 'revoke', array( $a, $b ), $this->repo, $this->tokens, $this->now );

		$this->assertSame( array( 'done' => 2, 'skipped' => 0 ), $r );
		$this->assertNotNull( $this->repo->find( $a )['revoked_at'] );
		$this->assertNotNull( $this->repo->find( $b )['revoked_at'] );
		$this->assertNull( $this->repo->find( $c )['revoked_at'], 'An unselected token is untouched.' );
	}

	public function test_revoke_skips_tokens_that_are_already_revoked_expired_or_missing(): void {
		$active  = $this->make();
		$revoked = $this->make();
		$this->tokens->revoke( $revoked );
		$expired = $this->make( gmdate( 'Y-m-d H:i:s', $this->now - 60 ) );

		$r = TokenBulk::apply( 'revoke', array( $active, $revoked, $expired, 9999 ), $this->repo, $this->tokens, $this->now );

		$this->assertSame( array( 'done' => 1, 'skipped' => 3 ), $r );
	}

	public function test_a_revoked_token_stops_working_at_once(): void {
		$made = $this->tokens->create( 'T', array( 'db:read' ), 1, null );
		$this->assertNotNull( $this->tokens->verify( $made['token'] ) );

		TokenBulk::apply( 'revoke', array( $made['id'] ), $this->repo, $this->tokens, $this->now );
		$this->assertNull( $this->tokens->verify( $made['token'] ) );
	}

	public function test_an_oauth_grant_cannot_be_refreshed_after_a_bulk_revoke(): void {
		$grant = $this->tokens->createOAuth( 'ttc_a', 'Claude', array( 'db:read' ), 1 );
		TokenBulk::apply( 'revoke', array( $grant['id'] ), $this->repo, $this->tokens, $this->now );

		$this->assertNull( $this->tokens->verify( $grant['access_token'] ) );
		$this->assertNull( $this->tokens->refreshOAuth( $grant['refresh_token'], 'ttc_a' ) );
	}

	public function test_delete_selected_removes_the_tokens_whatever_their_status(): void {
		$active  = $this->make();
		$revoked = $this->make();
		$this->tokens->revoke( $revoked );
		$keep = $this->make();

		$r = TokenBulk::apply( 'delete', array( $active, $revoked, 12345 ), $this->repo, $this->tokens, $this->now );

		$this->assertSame( array( 'done' => 2, 'skipped' => 1 ), $r );
		$this->assertNull( $this->repo->find( $active ) );
		$this->assertNull( $this->repo->find( $revoked ) );
		$this->assertNotNull( $this->repo->find( $keep ) );
	}

	public function test_a_deleted_active_token_no_longer_authenticates(): void {
		$made = $this->tokens->create( 'T', array( 'db:read' ), 1, null );
		TokenBulk::apply( 'delete', array( $made['id'] ), $this->repo, $this->tokens, $this->now );
		$this->assertNull( $this->tokens->verify( $made['token'] ) );
	}

	public function test_unknown_actions_change_nothing(): void {
		$a = $this->make();
		foreach ( array( '', '-1', 'purge', 'REVOKE', 'revoke ' ) as $action ) {
			$r = TokenBulk::apply( $action, array( $a ), $this->repo, $this->tokens, $this->now );
			$this->assertSame( array( 'done' => 0, 'skipped' => 1 ), $r, json_encode( $action ) );
		}
		$this->assertNotNull( $this->repo->find( $a ) );
		$this->assertNull( $this->repo->find( $a )['revoked_at'] );
		$this->assertFalse( TokenBulk::isAction( 'purge' ) );
		$this->assertTrue( TokenBulk::isAction( 'revoke' ) );
		$this->assertTrue( TokenBulk::isAction( 'delete' ) );
	}

	public function test_posted_ids_are_cleaned(): void {
		$this->assertSame( array( 3, 5 ), TokenBulk::cleanIds( array( '3', 5, '3', '5' ) ) );
		$this->assertSame( array(), TokenBulk::cleanIds( array( '0', '-4', 'abc', '1; DROP TABLE x', '', 1.5, null, array( 7 ), '07x' ) ) );
		$this->assertSame( array(), TokenBulk::cleanIds( 'not an array' ) );
		$this->assertSame( array(), TokenBulk::cleanIds( null ) );
		$this->assertSame( array( 7 ), TokenBulk::cleanIds( array( '007' ) ), 'Leading zeros are plain digits.' );
	}

	public function test_at_most_500_tokens_are_handled_in_one_request(): void {
		$this->assertCount( TokenBulk::MAX_IDS, TokenBulk::cleanIds( range( 1, 600 ) ) );
		$this->assertSame( 1, TokenBulk::cleanIds( range( 1, 600 ) )[0] );
	}

	public function test_no_ids_means_nothing_happens(): void {
		$this->assertSame( array( 'done' => 0, 'skipped' => 0 ), TokenBulk::apply( 'delete', array(), $this->repo, $this->tokens, $this->now ) );
	}
}
