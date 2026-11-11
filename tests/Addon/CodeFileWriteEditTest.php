<?php
namespace TechThrill\MCP\Tests\Addon;

use TechThrill\MCP\Files\Tools\CodeFileEdit;
use TechThrill\MCP\Files\Tools\CodeFileWrite;

class CodeFileWriteEditTest extends AddonTestCase {

	private function write( array $args, bool $safe = false ) {
		return $this->run_tool( CodeFileWrite::class, $args, $safe );
	}

	private function edit( array $args, bool $safe = false ) {
		return $this->run_tool( CodeFileEdit::class, $args, $safe );
	}

	public function test_name_scope_and_schema(): void {
		$tool = $this->tool( CodeFileWrite::class );
		$this->assertSame( 'code_file_write', $tool->name() );
		$this->assertSame( 'files:write', $tool->scope() );
		$this->assertSame( array( 'path', 'content' ), $tool->inputSchema()['required'] );
		$this->assertSame( 'code_file_edit', $this->tool( CodeFileEdit::class )->name() );
		$this->assertSame( array( 'path', 'old_string', 'new_string' ), $this->tool( CodeFileEdit::class )->inputSchema()['required'] );
	}

	public function test_creates_a_new_php_file_with_parent_folders(): void {
		$data = $this->data( $this->write( array( 'path' => 'wp-content/themes/child/inc/new.php', 'content' => "<?php\nreturn 1;\n" ) ) );
		$this->assertSame( 'wp-content/themes/child/inc/new.php', $data['path'] );
		$this->assertTrue( $data['created'] );
		$this->assertSame( 'ok', $data['php_syntax'] );
		$this->assertNull( $data['backup'] );
		$this->assertSame( "<?php\nreturn 1;\n", $this->read( 'wp-content/themes/child/inc/new.php' ) );
		$this->assertSame( array(), $this->tempFiles() );
	}

	public function test_replaces_a_file_and_backs_up_the_old_version(): void {
		$data = $this->data( $this->write( array( 'path' => 'wp-content/themes/parent/style.css', 'content' => "/* new */\n" ) ) );
		$this->assertFalse( $data['created'] );
		$this->assertSame( 'not_php', $data['php_syntax'] );
		$this->assertNotNull( $data['backup'] );
		$this->assertSame( "/* new */\n", $this->read( 'wp-content/themes/parent/style.css' ) );
		$this->assertSame( "/* parent */\nbody { color: red; }\n", file_get_contents( $this->backupDir . '/' . sha1( 'wp-content/themes/parent/style.css' ) . '/' . $data['backup'] . '.bak' ) );
	}

	public function test_overwrite_false_only_creates(): void {
		$r = $this->write( array( 'path' => 'wp-content/themes/parent/style.css', 'content' => 'x', 'overwrite' => false ) );
		$this->assertTrue( $r->isError() );
		$this->assertSame( 'wp-content/themes/parent/style.css already exists; set overwrite to true to replace it.', $r->text() );
		$this->assertSame( "/* parent */\nbody { color: red; }\n", $this->read( 'wp-content/themes/parent/style.css' ) );
	}

	public function test_broken_php_is_refused_and_nothing_is_written(): void {
		$before = $this->read( 'wp-content/themes/parent/functions.php' );
		$r      = $this->write( array( 'path' => 'wp-content/themes/parent/functions.php', 'content' => "<?php\nfunction broken( {\n" ) );
		$this->assertTrue( $r->isError() );
		$this->assertStringStartsWith( 'PHP syntax error: ', $r->text() );
		$this->assertStringContainsString( 'Nothing was written', $r->text() );
		$this->assertSame( $before, $this->read( 'wp-content/themes/parent/functions.php' ) );
		$this->assertDirectoryDoesNotExist( $this->backupDir, 'A refused write makes no backup.' );
	}

	public function test_skip_syntax_check_writes_it_anyway(): void {
		$data = $this->data( $this->write( array( 'path' => 'wp-content/themes/child/odd.php', 'content' => "<?php\n\$a = ;\n", 'skip_syntax_check' => true ) ) );
		$this->assertSame( 'skipped', $data['php_syntax'] );
		$this->assertFileExists( $this->root . '/wp-content/themes/child/odd.php' );
	}

	public function test_any_file_on_the_site_can_be_written(): void {
		foreach ( array( 'wp-content/uploads/a.php', 'index.php', 'wp-content/mu-plugins/a.php', 'wp-content/themes/index.php', 'wp-content/plugins/hello.php', 'wp-content/themes/child/.htaccess', '.htaccess', 'wp-content/themes/child/run.sh', 'wp-content/themes/child/noext', 'wp-content/themes/child/a.php.png' ) as $path ) {
			$data = $this->data( $this->write( array( 'path' => $path, 'content' => "<?php\n" ) ) );
			$this->assertSame( $path, $data['path'] );
			$this->assertSame( "<?php\n", $this->read( $path ), $path );
		}
	}

	public function test_wp_config_and_htaccess_can_be_edited_with_a_backup_and_a_syntax_check(): void {
		$data = $this->data( $this->write( array( 'path' => 'wp-config.php', 'content' => "<?php\ndefine( 'WP_DEBUG', true );\n" ) ) );
		$this->assertNotNull( $data['backup'] );
		$this->assertSame( "<?php\ndefine( 'WP_DEBUG', true );\n", $this->read( 'wp-config.php' ) );

		$bad = $this->write( array( 'path' => 'wp-config.php', 'content' => "<?php\ndefine( 'X', ;\n" ) );
		$this->assertTrue( $bad->isError() );
		$this->assertStringContainsString( 'PHP syntax error', $bad->text() );
		$this->assertSame( "<?php\ndefine( 'WP_DEBUG', true );\n", $this->read( 'wp-config.php' ) );

		$this->data( $this->write( array( 'path' => '.htaccess', 'content' => "# BEGIN\nOptions -Indexes\n# END\n" ) ) );
		$this->data( $this->edit( array( 'path' => '.htaccess', 'old_string' => '-Indexes', 'new_string' => '+Indexes' ) ) );
		$this->assertStringContainsString( '+Indexes', $this->read( '.htaccess' ) );
	}

	public function test_safe_mode_still_protects_wp_config_and_htaccess_while_it_is_on(): void {
		file_put_contents( $this->root . '/.htaccess', "# keep\n" );
		foreach ( array( 'wp-config.php', '.htaccess' ) as $path ) {
			$r = $this->write( array( 'path' => $path, 'content' => "<?php\n" ), true );
			$this->assertTrue( $r->isError(), $path );
		}
		$this->assertSame( "<?php\n// config\n", $this->read( 'wp-config.php' ) );
		$this->assertSame( "# keep\n", $this->read( '.htaccess' ) );
	}

	public function test_path_jail_still_blocks_traversal_and_absolute_paths(): void {
		$this->assertSame( 'Path traversal is not allowed.', $this->write( array( 'path' => 'wp-content/themes/../../x.php', 'content' => "<?php\n" ) )->text() );
		$this->assertSame( 'Absolute paths are not allowed.', $this->write( array( 'path' => 'C:/x.php', 'content' => "<?php\n" ) )->text() );
	}

	public function test_a_symlink_out_of_the_site_cannot_be_written_through(): void {
		$outside = sys_get_temp_dir() . '/ttm-outside-' . uniqid() . '.css';
		file_put_contents( $outside, 'keep' );
		if ( ! @symlink( $outside, $this->root . '/wp-content/themes/child/link.css' ) ) {
			$this->markTestSkipped( 'symlink() unavailable on this platform' );
		}
		$r = $this->write( array( 'path' => 'wp-content/themes/child/link.css', 'content' => 'pwned' ) );
		$this->assertTrue( $r->isError() );
		$this->assertSame( 'keep', file_get_contents( $outside ) );
		unlink( $outside );
	}

	public function test_a_folder_cannot_be_overwritten_and_create_dirs_false_is_respected(): void {
		$r = $this->write( array( 'path' => 'wp-content/themes/parent/inc', 'content' => 'x' ) );
		$this->assertTrue( $r->isError() );

		$r = $this->write( array( 'path' => 'wp-content/themes/child/missing/a.css', 'content' => 'x', 'create_dirs' => false ) );
		$this->assertSame( 'The parent folder of wp-content/themes/child/missing/a.css does not exist; set create_dirs to true.', $r->text() );
		$this->assertDirectoryDoesNotExist( $this->root . '/wp-content/themes/child/missing' );
	}

	public function test_oversize_content_is_refused(): void {
		$r = $this->write( array( 'path' => 'wp-content/themes/child/big.css', 'content' => str_repeat( 'a', 5242881 ) ) );
		$this->assertTrue( $r->isError() );
		$this->assertStringContainsString( 'limited to 5 MB', $r->text() );
	}

	public function test_safe_mode_protects_the_main_plugin_folder_but_only_while_on(): void {
		$path = 'wp-content/plugins/techthrill-mcp/extra.php';
		$on   = $this->write( array( 'path' => $path, 'content' => "<?php\n" ), true );
		$this->assertTrue( $on->isError() );
		$this->assertStringStartsWith( 'Refused by safe mode: ', $on->text() );
		$off = $this->write( array( 'path' => $path, 'content' => "<?php\n" ), false );
		$this->assertFalse( $off->isError(), $off->text() );
	}

	// Edit.

	public function test_edit_replaces_exactly_one_occurrence(): void {
		$data = $this->data( $this->edit( array( 'path' => 'wp-content/themes/parent/style.css', 'old_string' => 'color: red', 'new_string' => 'color: blue' ) ) );
		$this->assertSame( 1, $data['replacements'] );
		$this->assertNotNull( $data['backup'] );
		$this->assertSame( "/* parent */\nbody { color: blue; }\n", $this->read( 'wp-content/themes/parent/style.css' ) );
	}

	public function test_edit_replacement_text_is_literal(): void {
		file_put_contents( $this->root . '/wp-content/themes/parent/a.css', 'x PLACEHOLDER y' );
		$this->data( $this->edit( array( 'path' => 'wp-content/themes/parent/a.css', 'old_string' => 'PLACEHOLDER', 'new_string' => 'cost $1 and \\1 and $0' ) ) );
		$this->assertSame( 'x cost $1 and \\1 and $0 y', $this->read( 'wp-content/themes/parent/a.css' ) );
	}

	public function test_edit_not_found_ambiguous_identical_and_empty(): void {
		$p = 'wp-content/themes/parent/style.css';
		file_put_contents( $this->root . '/wp-content/themes/parent/dup.css', "a\na\n" );

		$this->assertStringContainsString( 'was not found', $this->edit( array( 'path' => $p, 'old_string' => 'nope', 'new_string' => 'x' ) )->text() );
		$this->assertStringContainsString( 'found 2 times', $this->edit( array( 'path' => 'wp-content/themes/parent/dup.css', 'old_string' => 'a', 'new_string' => 'b' ) )->text() );
		$this->assertSame( 'old_string and new_string are identical; nothing to change.', $this->edit( array( 'path' => $p, 'old_string' => 'red', 'new_string' => 'red' ) )->text() );
		$this->assertSame( 'old_string must not be empty.', $this->edit( array( 'path' => $p, 'old_string' => '', 'new_string' => 'x' ) )->text() );

		$data = $this->data( $this->edit( array( 'path' => 'wp-content/themes/parent/dup.css', 'old_string' => 'a', 'new_string' => 'b', 'replace_all' => true ) ) );
		$this->assertSame( 2, $data['replacements'] );
		$this->assertSame( "b\nb\n", $this->read( 'wp-content/themes/parent/dup.css' ) );
	}

	public function test_edit_that_would_break_php_is_refused(): void {
		$before = $this->read( 'wp-content/themes/parent/functions.php' );
		$r      = $this->edit( array( 'path' => 'wp-content/themes/parent/functions.php', 'old_string' => 'return 1;', 'new_string' => 'return (;' ) );
		$this->assertTrue( $r->isError() );
		$this->assertStringStartsWith( 'The edit would introduce a PHP syntax error: ', $r->text() );
		$this->assertSame( $before, $this->read( 'wp-content/themes/parent/functions.php' ) );
	}

	public function test_edit_can_repair_a_file_that_already_does_not_parse(): void {
		file_put_contents( $this->root . '/wp-content/themes/parent/broken.php', "<?php\nfunction a( {\n  echo 1 \n" );
		$data = $this->data( $this->edit( array( 'path' => 'wp-content/themes/parent/broken.php', 'old_string' => 'echo 1', 'new_string' => 'echo 2' ) ) );
		$this->assertArrayHasKey( 'warning', $data, 'A step towards a fix is allowed, with a warning.' );
		$this->assertStringContainsString( 'echo 2', $this->read( 'wp-content/themes/parent/broken.php' ) );
	}

	public function test_edit_refuses_binary_missing_and_folders(): void {
		file_put_contents( $this->root . '/wp-content/themes/parent/bin.css', "a\0b" );
		$this->assertStringContainsString( 'binary file', $this->edit( array( 'path' => 'wp-content/themes/parent/bin.css', 'old_string' => 'a', 'new_string' => 'b' ) )->text() );
		$this->assertSame( 'Path does not exist.', $this->edit( array( 'path' => 'wp-content/themes/parent/missing.css', 'old_string' => 'a', 'new_string' => 'b' ) )->text() );
		$this->assertStringContainsString( 'is not a file', $this->edit( array( 'path' => 'wp-content/themes/parent/inc', 'old_string' => 'a', 'new_string' => 'b' ) )->text() );
	}
}
