<?php
namespace TechThrill\MCP\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\Tests\Support\InMemoryTokenRepository;

class TokenRepositoryContractTest extends TestCase {

	private function row( array $extra = array() ): array {
		return array_merge(
			array(
				'name'         => 'T',
				'lookup'       => 'ttm_abcdefgh',
				'hash'         => str_repeat( 'a', 64 ),
				'scopes'       => 'db:read',
				'user_id'      => 1,
				'created_at'   => '2026-10-15 00:00:00',
				'expires_at'   => null,
				'last_used_at' => null,
				'revoked_at'   => null,
			),
			$extra
		);
	}

	public function test_insert_and_find_by_refresh_hash(): void {
		$repo = new InMemoryTokenRepository();
		$id   = $repo->insert( $this->row( array( 'kind' => 'oauth', 'client_id' => 'c1', 'refresh_hash' => str_repeat( 'b', 64 ) ) ) );

		$found = $repo->findByRefreshHash( str_repeat( 'b', 64 ) );
		$this->assertSame( $id, $found['id'] );
		$this->assertNull( $repo->findByRefreshHash( str_repeat( 'c', 64 ) ) );
	}

	public function test_rows_without_oauth_columns_still_work(): void {
		$repo = new InMemoryTokenRepository();
		$id   = $repo->insert( $this->row() );

		$this->assertSame( $id, $repo->find( $id )['id'] );
		$this->assertCount( 1, $repo->findByLookup( 'ttm_abcdefgh' ) );
		$this->assertNull( $repo->findByRefreshHash( str_repeat( 'b', 64 ) ), 'A bearer row has no refresh hash.' );
		$this->assertArrayNotHasKey( 'kind', $repo->find( $id ) );
	}

	public function test_update_replaces_refresh_hash_in_place(): void {
		$repo = new InMemoryTokenRepository();
		$id   = $repo->insert( $this->row( array( 'kind' => 'oauth', 'refresh_hash' => str_repeat( 'b', 64 ) ) ) );

		$repo->update( $id, array( 'refresh_hash' => str_repeat( 'd', 64 ) ) );

		$this->assertNull( $repo->findByRefreshHash( str_repeat( 'b', 64 ) ) );
		$this->assertSame( $id, $repo->findByRefreshHash( str_repeat( 'd', 64 ) )['id'] );
	}
}
