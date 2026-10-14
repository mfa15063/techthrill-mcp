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
}
