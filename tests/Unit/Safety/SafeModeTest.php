<?php
namespace TechThrill\MCP\Tests\Unit\Safety;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\Safety\Refusal;
use TechThrill\MCP\Safety\SafeMode;
use TechThrill\MCP\Safety\SqlStatement;

class SafeModeTest extends TestCase {
	private const PLUGIN_DIR = 'wp-content/plugins/techthrill-mcp';

	private function on(): SafeMode {
		return new SafeMode( true, self::PLUGIN_DIR );
	}

	private function off(): SafeMode {
		return new SafeMode( false, self::PLUGIN_DIR );
	}

	public function test_sql_drop_refused_in_safe_mode(): void {
		$s = new SqlStatement( 'drop', false, false, 'wp_x', 'DROP TABLE wp_x' );
		$r = $this->on()->checkSql( $s, false );
		$this->assertInstanceOf( Refusal::class, $r );
		$this->assertSame(
			'Refused by safe mode: DROP on `wp_x` is a destructive operation. The site administrator can disable safe mode under TechThrill MCP → Settings if this is intended.',
			$r->reason()
		);
		// Confirmation does not override safe mode.
		$this->assertNotNull( $this->on()->checkSql( $s, true ) );
		// TRUNCATE is treated the same way; unknown table falls back to a generic name.
		$t = new SqlStatement( 'truncate', false, false, null, 'TRUNCATE TABLE wp_x' );
		$this->assertStringContainsString( 'TRUNCATE on `the target table`', $this->on()->checkSql( $t, false )->reason() );
	}

	public function test_sql_delete_without_where_refused(): void {
		$s = new SqlStatement( 'delete', false, false, 'wp_posts', 'DELETE FROM wp_posts' );
		$r = $this->on()->checkSql( $s, true );
		$this->assertNotNull( $r );
		$this->assertSame(
			'Refused by safe mode: DELETE without a WHERE clause would affect every row in `wp_posts`. The site administrator can disable safe mode under TechThrill MCP → Settings if this is intended.',
			$r->reason()
		);
		$u = new SqlStatement( 'update', false, false, null, 'UPDATE wp_posts SET a=1' );
		$this->assertStringStartsWith(
			'Refused by safe mode: UPDATE without a WHERE clause would affect every row in `the target table`.',
			$this->on()->checkSql( $u, false )->reason()
		);
	}

	public function test_sql_delete_with_where_allowed(): void {
		$s = new SqlStatement( 'delete', true, false, 'wp_posts', 'DELETE FROM wp_posts WHERE ID = 1' );
		$this->assertNull( $this->on()->checkSql( $s, false ) );
		$this->assertNull( $this->off()->checkSql( $s, false ) );
		$sel = new SqlStatement( 'select', false, false, null, 'SELECT 1' );
		$this->assertNull( $this->on()->checkSql( $sel, false ) );
		$ins = new SqlStatement( 'insert', false, false, 'wp_x', 'INSERT INTO wp_x VALUES (1)' );
		$this->assertNull( $this->on()->checkSql( $ins, false ) );
	}

	public function test_sql_drop_needs_confirmation_when_safe_mode_off(): void {
		$s = new SqlStatement( 'drop', false, false, 'wp_x', 'DROP TABLE wp_x' );
		$r = $this->off()->checkSql( $s, false );
		$this->assertNotNull( $r );
		$this->assertSame(
			'Confirmation required: DROP on `wp_x` is destructive and cannot be undone. Ask the user to confirm, then call again with confirm_destructive set to true.',
			$r->reason()
		);
		$this->assertNull( $this->off()->checkSql( $s, true ) );

		$d = new SqlStatement( 'delete', false, false, 'wp_posts', 'DELETE FROM wp_posts' );
		$this->assertSame(
			'Confirmation required: DELETE without a WHERE clause would affect every row in `wp_posts` and cannot be undone. Ask the user to confirm, then call again with confirm_destructive set to true.',
			$this->off()->checkSql( $d, false )->reason()
		);
		$this->assertNull( $this->off()->checkSql( $d, true ) );
	}

	public function test_cli_safe_mode_list_applied_only_when_enabled(): void {
		$r = $this->on()->checkCli( array( 'db', 'reset' ) );
		$this->assertNotNull( $r );
		$this->assertSame(
			'Refused by safe mode: the command "wp db reset" is destructive. The site administrator can disable safe mode under TechThrill MCP → Settings if this is intended.',
			$r->reason()
		);
		$this->assertNull( $this->off()->checkCli( array( 'db', 'reset' ) ) );
		$this->assertNull( $this->on()->checkCli( array( 'post', 'list' ) ) );
	}

	public function test_path_wp_config_never_writable(): void {
		foreach ( array( $this->on(), $this->off() ) as $mode ) {
			foreach ( array( 'wp-config.php', 'WP-Config.PHP', '/wp-config.php/', '.\\wp-config.php' ) as $path ) {
				$r = $mode->checkPathWrite( $path );
				$this->assertNotNull( $r, $path );
				$this->assertSame( 'wp-config.php cannot be modified or deleted through TechThrill MCP.', $r->reason() );
			}
		}
		$this->assertNull( $this->on()->checkPathWrite( 'wp-config.php.bak' ) );
		$this->assertNull( $this->on()->checkPathWrite( 'wp-config-sample.php' ) );
	}

	public function test_path_htaccess_and_plugin_dir_safe_mode_only(): void {
		$paths = array(
			'.htaccess',
			'.HTACCESS',
			'wp-content/uploads/techthrill-mcp',
			'wp-content/uploads/techthrill-mcp/logs/x.log',
			'wp-content/plugins/techthrill-mcp',
			'wp-content/plugins/TechThrill-MCP/includes/Plugin.php',
			'wp-content\\plugins\\techthrill-mcp\\techthrill-mcp.php',
		);
		foreach ( $paths as $path ) {
			$r = $this->on()->checkPathWrite( $path );
			$this->assertNotNull( $r, $path );
			$this->assertStringStartsWith( 'Refused by safe mode: writing to `', $r->reason() );
			$this->assertStringEndsWith( ' The site administrator can disable safe mode under TechThrill MCP → Settings if this is intended.', $r->reason() );
			$this->assertNull( $this->off()->checkPathWrite( $path ), $path );
		}
		$this->assertSame(
			'Refused by safe mode: writing to `.htaccess` is blocked because it is a protected location. The site administrator can disable safe mode under TechThrill MCP → Settings if this is intended.',
			$this->on()->checkPathWrite( '.htaccess' )->reason()
		);
		// Siblings are not protected.
		$this->assertNull( $this->on()->checkPathWrite( 'wp-content/plugins/techthrill-mcp-extra/a.php' ) );
		$this->assertNull( $this->on()->checkPathWrite( 'wp-content/uploads/other/a.txt' ) );
		$this->assertNull( $this->on()->checkPathWrite( 'wp-content/themes/x/style.css' ) );
	}

	public function test_directory_delete_safe_mode_only(): void {
		$r = $this->on()->checkDirectoryDelete();
		$this->assertNotNull( $r );
		$this->assertSame(
			'Refused by safe mode: deleting a directory is blocked. The site administrator can disable safe mode under TechThrill MCP → Settings if this is intended.',
			$r->reason()
		);
		$this->assertNull( $this->off()->checkDirectoryDelete() );
	}

	public function test_filters_applied(): void {
		$calls  = array();
		$filter = function ( $hook, $value ) use ( &$calls ) {
			$calls[ $hook ] = $value;
			if ( 'techthrill_mcp_blocked_cli_commands' === $hook ) {
				$value[] = 'cron event run';
			}
			if ( 'techthrill_mcp_protected_paths' === $hook ) {
				$value[] = 'wp-content/mu-plugins';
			}
			return $value;
		};
		$mode = new SafeMode( true, self::PLUGIN_DIR, null, $filter );

		$this->assertNotNull( $mode->checkCli( array( 'cron', 'event', 'run' ) ) );
		$this->assertArrayHasKey( 'techthrill_mcp_blocked_cli_commands', $calls );
		$this->assertSame( SafeMode::SAFE_MODE_BLOCKED_CLI, $calls['techthrill_mcp_blocked_cli_commands'] );

		$this->assertNotNull( $mode->checkPathWrite( 'wp-content/mu-plugins/a.php' ) );
		$this->assertArrayHasKey( 'techthrill_mcp_protected_paths', $calls );
		$this->assertContains( self::PLUGIN_DIR, $calls['techthrill_mcp_protected_paths'] );
		$this->assertContains( '.htaccess', $calls['techthrill_mcp_protected_paths'] );

		// Disabled mode never consults the CLI filter.
		$calls = array();
		$off   = new SafeMode( false, self::PLUGIN_DIR, null, $filter );
		$this->assertNull( $off->checkCli( array( 'db', 'reset' ) ) );
		$this->assertArrayNotHasKey( 'techthrill_mcp_blocked_cli_commands', $calls );
	}
}
