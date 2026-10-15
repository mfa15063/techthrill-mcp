<?php
namespace TechThrill\MCP\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\Admin\QuickConnect;
use TechThrill\MCP\Auth\Scopes;

class QuickConnectTest extends TestCase {

	public function test_presets_are_read_only_files_and_full_access(): void {
		$presets = QuickConnect::presets();
		$this->assertSame( array( 'read', 'files', 'full' ), array_keys( $presets ) );
		$this->assertSame( array( 'db:read', 'files:read' ), $presets['read']['scopes'] );
		$this->assertSame( array( 'files:read', 'files:write' ), $presets['files']['scopes'] );
		$this->assertSame( Scopes::ALL, $presets['full']['scopes'] );
	}

	public function test_default_preset_is_read_only(): void {
		$this->assertSame( 'read', QuickConnect::DEFAULT_PRESET );
		$this->assertSame( array( 'db:read', 'files:read' ), QuickConnect::scopesFor( QuickConnect::DEFAULT_PRESET ) );
	}

	public function test_unknown_preset_falls_back_to_read_only_never_to_full_access(): void {
		$this->assertSame( array( 'db:read', 'files:read' ), QuickConnect::scopesFor( 'bogus' ) );
		$this->assertSame( array( 'db:read', 'files:read' ), QuickConnect::scopesFor( '' ) );
	}

	public function test_every_preset_uses_only_known_scopes(): void {
		foreach ( QuickConnect::presets() as $preset ) {
			$this->assertSame( $preset['scopes'], Scopes::sanitize( $preset['scopes'] ) );
		}
	}

	public function test_name_includes_label_and_date_and_fits_the_limit(): void {
		$name = QuickConnect::name( 'files', gmmktime( 12, 0, 0, 10, 15, 2026 ) );
		$this->assertSame( 'Quick connect (Files only) 2026-10-15', $name );
		$this->assertLessThanOrEqual( 100, strlen( QuickConnect::name( 'bogus', 0 ) ) );
	}
}
