<?php
namespace TechThrill\MCP\Tests\Addon;

use TechThrill\MCP\Files\Tools\CodeFileRename;

class CodeFileRenameTest extends AddonTestCase {

	private function rename( array $args, bool $safe = false ) {
		return $this->run_tool( CodeFileRename::class, $args, $safe );
	}

	public function test_name_scope_and_schema(): void {
		$tool = $this->tool( CodeFileRename::class );
		$this->assertSame( 'code_file_rename', $tool->name() );
		$this->assertSame( 'files:write', $tool->scope() );
		$this->assertSame( array( 'from', 'to' ), $tool->inputSchema()['required'] );
	}

	public function test_renames_a_file(): void {
		$data = $this->data( $this->rename( array( 'from' => 'wp-content/themes/parent/style.css', 'to' => 'wp-content/themes/parent/main.css' ) ) );
		$this->assertSame( 'file', $data['type'] );
		$this->assertFalse( $data['overwritten'] );
		$this->assertFileDoesNotExist( $this->root . '/wp-content/themes/parent/style.css' );
		$this->assertSame( "/* parent */\nbody { color: red; }\n", $this->read( 'wp-content/themes/parent/main.css' ) );
	}

	public function test_moves_a_file_to_a_new_folder_creating_it(): void {
		$this->data( $this->rename( array( 'from' => 'wp-content/themes/parent/functions.php', 'to' => 'wp-content/themes/child/new/dir/functions.php' ) ) );
		$this->assertFileExists( $this->root . '/wp-content/themes/child/new/dir/functions.php' );

		$r = $this->rename( array( 'from' => 'wp-content/themes/child/new/dir/functions.php', 'to' => 'wp-content/themes/child/other/functions.php', 'create_dirs' => false ) );
		$this->assertSame( 'The parent folder of wp-content/themes/child/other/functions.php does not exist; set create_dirs to true.', $r->text() );
		$this->assertFileExists( $this->root . '/wp-content/themes/child/new/dir/functions.php' );
	}

	public function test_renames_a_folder_with_its_content(): void {
		$data = $this->data( $this->rename( array( 'from' => 'wp-content/themes/parent/inc', 'to' => 'wp-content/themes/parent/includes' ) ) );
		$this->assertSame( 'dir', $data['type'] );
		$this->assertFileExists( $this->root . '/wp-content/themes/parent/includes/helpers.php' );
		$this->assertDirectoryDoesNotExist( $this->root . '/wp-content/themes/parent/inc' );
	}

	public function test_an_existing_destination_needs_overwrite_and_is_backed_up(): void {
		file_put_contents( $this->root . '/wp-content/themes/parent/other.css', 'other' );

		$r = $this->rename( array( 'from' => 'wp-content/themes/parent/style.css', 'to' => 'wp-content/themes/parent/other.css' ) );
		$this->assertSame( 'wp-content/themes/parent/other.css already exists; set overwrite to true to replace it.', $r->text() );
		$this->assertSame( 'other', $this->read( 'wp-content/themes/parent/other.css' ) );
		$this->assertFileExists( $this->root . '/wp-content/themes/parent/style.css' );

		$data = $this->data( $this->rename( array( 'from' => 'wp-content/themes/parent/style.css', 'to' => 'wp-content/themes/parent/other.css', 'overwrite' => true ) ) );
		$this->assertTrue( $data['overwritten'] );
		$this->assertNotNull( $data['backup'] );
		$this->assertSame( "/* parent */\nbody { color: red; }\n", $this->read( 'wp-content/themes/parent/other.css' ) );
		$this->assertSame( 'other', file_get_contents( $this->backupDir . '/' . sha1( 'wp-content/themes/parent/other.css' ) . '/' . $data['backup'] . '.bak' ) );
	}

	public function test_folder_edge_cases(): void {
		$r = $this->rename( array( 'from' => 'wp-content/themes/parent', 'to' => 'wp-content/themes/parent/inc/inside' ) );
		$this->assertSame( 'A folder cannot be moved into itself.', $r->text() );

		$r = $this->rename( array( 'from' => 'wp-content/themes/parent/inc', 'to' => 'wp-content/themes/child' ) );
		$this->assertStringContainsString( 'already exists', $r->text() );
		$this->assertDirectoryExists( $this->root . '/wp-content/themes/parent/inc' );

		$this->data( $this->rename( array( 'from' => 'wp-content/themes/parent/inc', 'to' => 'wp-content/themes/parent/.hidden' ) ) );
		$this->assertDirectoryExists( $this->root . '/wp-content/themes/parent/.hidden' );

		$r = $this->rename( array( 'from' => 'wp-content/themes/parent/style.css', 'to' => 'wp-content/themes/parent/style.css' ) );
		$this->assertSame( 'The source and the destination are the same path.', $r->text() );
	}

	public function test_any_destination_name_is_allowed_but_not_an_invalid_one(): void {
		foreach ( array( '.htaccess', 'x.phtml', 'x.php.png', 'x.sh', 'noext' ) as $name ) {
			file_put_contents( $this->root . '/wp-content/themes/parent/src.txt', 'x' );
			$this->data( $this->rename( array( 'from' => 'wp-content/themes/parent/src.txt', 'to' => 'wp-content/themes/parent/' . $name ) ) );
			$this->assertSame( 'x', $this->read( 'wp-content/themes/parent/' . $name ), $name );
		}
		$r = $this->rename( array( 'from' => 'wp-content/themes/parent/style.css', 'to' => "wp-content/themes/parent/a\0b" ) );
		$this->assertTrue( $r->isError() );
		$this->assertFileExists( $this->root . '/wp-content/themes/parent/style.css' );
	}

	public function test_files_can_move_anywhere_on_the_site(): void {
		$this->data( $this->rename( array( 'from' => 'wp-content/themes/parent/style.css', 'to' => 'wp-content/uploads/style.css' ) ) );
		$this->assertFileExists( $this->root . '/wp-content/uploads/style.css' );
		$this->data( $this->rename( array( 'from' => 'wp-content/uploads/style.css', 'to' => 'style-root.css' ) ) );
		$this->assertFileExists( $this->root . '/style-root.css' );
		$this->data( $this->rename( array( 'from' => 'wp-content/plugins/foo', 'to' => 'wp-content/plugins/foo2' ) ) );
		$this->assertDirectoryExists( $this->root . '/wp-content/plugins/foo2' );
	}

	public function test_core_paths_need_confirmation(): void {
		foreach ( array( 'wp-config.php', 'wp-content/themes', 'wp-content/plugins', 'wp-content/uploads' ) as $path ) {
			$r = $this->rename( array( 'from' => $path, 'to' => $path . '-old' ) );
			$this->assertTrue( $r->isError(), $path );
			$this->assertStringContainsString( 'confirm_active', $r->text(), $path );
			$this->assertFileExists( $this->root . '/' . $path );
		}
		$this->data( $this->rename( array( 'from' => 'wp-config.php', 'to' => 'wp-config.bak', 'confirm_active' => true ) ) );
		$this->assertFileExists( $this->root . '/wp-config.bak' );
	}

	public function test_active_themes_and_plugins_need_confirmation(): void {
		$this->active = array( 'wp-content/themes/parent', 'wp-content/plugins/foo', 'wp-content/plugins/foo/foo.php' );

		$r = $this->rename( array( 'from' => 'wp-content/plugins/foo', 'to' => 'wp-content/plugins/foo-old' ) );
		$this->assertStringContainsString( 'is an active theme or plugin', $r->text() );
		$this->assertDirectoryExists( $this->root . '/wp-content/plugins/foo' );

		$r = $this->rename( array( 'from' => 'wp-content/plugins/foo/foo.php', 'to' => 'wp-content/plugins/foo/main.php' ) );
		$this->assertStringContainsString( 'is an active theme or plugin', $r->text() );

		$data = $this->data( $this->rename( array( 'from' => 'wp-content/plugins/foo', 'to' => 'wp-content/plugins/foo-old', 'confirm_active' => true ) ) );
		$this->assertSame( 'dir', $data['type'] );

		// Files inside an active theme are normal editing and need no confirmation.
		$this->data( $this->rename( array( 'from' => 'wp-content/themes/parent/inc/helpers.php', 'to' => 'wp-content/themes/parent/inc/utils.php' ) ) );
	}

	public function test_the_main_plugin_and_this_addon_can_be_renamed_with_confirmation(): void {
		$this->active = array( 'wp-content/plugins/techthrill-mcp', 'wp-content/plugins/techthrill-mcp-files' );
		foreach ( $this->active as $dir ) {
			$r = $this->rename( array( 'from' => $dir, 'to' => $dir . '-x' ) );
			$this->assertStringContainsString( 'confirm_active', $r->text(), $dir );
			$this->assertDirectoryExists( $this->root . '/' . $dir );
			$this->data( $this->rename( array( 'from' => $dir, 'to' => $dir . '-x', 'confirm_active' => true ) ) );
			$this->assertDirectoryExists( $this->root . '/' . $dir . '-x' );
		}
	}

	public function test_a_missing_source_is_an_error(): void {
		$this->assertSame( 'Path does not exist.', $this->rename( array( 'from' => 'wp-content/themes/parent/nope.css', 'to' => 'wp-content/themes/parent/x.css' ) )->text() );
	}

	public function test_safe_mode_protects_the_main_plugin_files(): void {
		$r = $this->rename( array( 'from' => 'wp-content/plugins/techthrill-mcp/techthrill-mcp.php', 'to' => 'wp-content/plugins/techthrill-mcp/x.php' ), true );
		$this->assertStringStartsWith( 'Refused by safe mode: ', $r->text() );
		$this->assertFileExists( $this->root . '/wp-content/plugins/techthrill-mcp/techthrill-mcp.php' );
	}
}
