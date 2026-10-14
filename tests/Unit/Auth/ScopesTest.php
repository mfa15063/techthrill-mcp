<?php
namespace TechThrill\MCP\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\Auth\Scopes;

class ScopesTest extends TestCase {
	public function test_write_implies_read(): void {
		$this->assertEqualsCanonicalizing( array( 'db:write', 'db:read' ), Scopes::expand( array( 'db:write' ) ) );
		$this->assertEqualsCanonicalizing( array( 'files:write', 'files:read' ), Scopes::expand( array( 'files:write' ) ) );
	}

	public function test_null_scope_always_allowed(): void {
		$this->assertTrue( Scopes::has( array(), null ) );
		$this->assertFalse( Scopes::has( array( 'files:read' ), 'files:write' ) );
	}

	public function test_has_honours_implied_scopes(): void {
		$this->assertTrue( Scopes::has( array( 'db:write' ), 'db:read' ) );
		$this->assertFalse( Scopes::has( array( 'db:read' ), 'db:write' ) );
	}

	public function test_sanitize_keeps_known_expands_and_orders(): void {
		$result = Scopes::sanitize( array( ' files:write ', 'bogus', 'db:write', 'db:write', 42, 'wp-cli' ) );
		$this->assertSame( array( 'wp-cli', 'db:read', 'db:write', 'files:read', 'files:write' ), $result );
	}

	public function test_sanitize_empty(): void {
		$this->assertSame( array(), Scopes::sanitize( array( 'nope' ) ) );
	}
}
