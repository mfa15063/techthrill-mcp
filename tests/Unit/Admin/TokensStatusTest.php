<?php
namespace TechThrill\MCP\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\Admin\TokenStatus;

class TokensStatusTest extends TestCase {

	const NOW = 1800000000;

	public function test_active(): void {
		$this->assertSame( 'active', TokenStatus::of( array( 'expires_at' => null, 'revoked_at' => null ), self::NOW ) );
		$this->assertSame( 'active', TokenStatus::of( array( 'expires_at' => gmdate( 'Y-m-d H:i:s', self::NOW + 60 ), 'revoked_at' => null ), self::NOW ) );
	}

	public function test_expired(): void {
		$this->assertSame( 'expired', TokenStatus::of( array( 'expires_at' => gmdate( 'Y-m-d H:i:s', self::NOW - 60 ), 'revoked_at' => null ), self::NOW ) );
	}

	public function test_revoked_wins_over_expired(): void {
		$row = array( 'expires_at' => gmdate( 'Y-m-d H:i:s', self::NOW - 60 ), 'revoked_at' => '2026-01-01 00:00:00' );
		$this->assertSame( 'revoked', TokenStatus::of( $row, self::NOW ) );
	}

	public function test_oauth_row_with_expired_access_token_is_still_active_while_refresh_is_valid(): void {
		$row = array(
			'kind'               => 'oauth',
			'expires_at'         => gmdate( 'Y-m-d H:i:s', self::NOW - 60 ),
			'refresh_expires_at' => gmdate( 'Y-m-d H:i:s', self::NOW + 86400 ),
			'revoked_at'         => null,
		);
		$this->assertSame( 'active', TokenStatus::of( $row, self::NOW ) );
	}

	public function test_oauth_row_expires_with_its_refresh_token_and_can_be_revoked(): void {
		$row = array(
			'kind'               => 'oauth',
			'expires_at'         => gmdate( 'Y-m-d H:i:s', self::NOW - 7200 ),
			'refresh_expires_at' => gmdate( 'Y-m-d H:i:s', self::NOW - 60 ),
			'revoked_at'         => null,
		);
		$this->assertSame( 'expired', TokenStatus::of( $row, self::NOW ) );
		$row['revoked_at'] = '2026-01-01 00:00:00';
		$this->assertSame( 'revoked', TokenStatus::of( $row, self::NOW ) );
	}
}
