<?php
namespace TechThrill\MCP\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\Admin\TokenStatus;

class TokenKindLabelTest extends TestCase {

	public function test_kind_label_for_oauth_bearer_and_null(): void {
		$this->assertSame( 'OAuth', TokenStatus::kindLabel( array( 'kind' => 'oauth' ) ) );
		$this->assertSame( 'Bearer', TokenStatus::kindLabel( array( 'kind' => 'bearer' ) ) );
		$this->assertSame( 'Bearer', TokenStatus::kindLabel( array( 'kind' => null ) ) );
		$this->assertSame( 'Bearer', TokenStatus::kindLabel( array() ) );
	}

	public function test_expiry_of_uses_the_refresh_expiry_for_oauth_rows(): void {
		$oauth = array( 'kind' => 'oauth', 'expires_at' => '2026-01-01 01:00:00', 'refresh_expires_at' => '2026-01-31 00:00:00' );
		$this->assertSame( '2026-01-31 00:00:00', TokenStatus::expiryOf( $oauth ) );
		$this->assertSame( '2026-03-01 00:00:00', TokenStatus::expiryOf( array( 'kind' => null, 'expires_at' => '2026-03-01 00:00:00' ) ) );
		$this->assertNull( TokenStatus::expiryOf( array( 'kind' => null, 'expires_at' => null ) ) );
		$this->assertNull( TokenStatus::expiryOf( array( 'kind' => 'oauth' ) ) );
	}
}
