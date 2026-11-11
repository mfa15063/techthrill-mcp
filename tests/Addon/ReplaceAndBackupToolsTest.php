<?php
namespace TechThrill\MCP\Tests\Addon;

use TechThrill\MCP\Files\Tools\CodeFileBackups;
use TechThrill\MCP\Files\Tools\CodeFileEdit;
use TechThrill\MCP\Files\Tools\CodeFileRestore;
use TechThrill\MCP\Files\Tools\CodeFileWrite;
use TechThrill\MCP\Files\Tools\CodeReplaceInFiles;
use TechThrill\MCP\Tools\ToolRegistry;

class ReplaceAndBackupToolsTest extends AddonTestCase {

	private function replace( array $args, bool $safe = false ) {
		return $this->run_tool( CodeReplaceInFiles::class, $args, $safe );
	}

	protected function setUp(): void {
		parent::setUp();
		file_put_contents( $this->root . '/wp-content/themes/parent/a.css', "a { color: #111; }\nb { color: #111; }\n" );
		file_put_contents( $this->root . '/wp-content/themes/parent/inc/b.css', 'c { color: #111; }' );
		file_put_contents( $this->root . '/wp-content/themes/child/c.css', 'c { color: #111; }' );
		file_put_contents( $this->root . '/wp-content/themes/parent/logo.png', "\x89PNG\r\n\x1a\n#111" );
	}

	public function test_names_scopes_and_schemas(): void {
		$tool = $this->tool( CodeReplaceInFiles::class );
		$this->assertSame( 'code_replace_in_files', $tool->name() );
		$this->assertSame( 'files:write', $tool->scope() );
		$this->assertSame( array( 'folder', 'search' ), $tool->inputSchema()['required'] );
		$this->assertTrue( $tool->inputSchema()['properties']['dry_run']['default'] );
		$this->assertSame( 'code_file_backups', $this->tool( CodeFileBackups::class )->name() );
		$this->assertSame( 'files:read', $this->tool( CodeFileBackups::class )->scope() );
		$this->assertSame( 'code_file_restore', $this->tool( CodeFileRestore::class )->name() );
		$this->assertSame( 'files:write', $this->tool( CodeFileRestore::class )->scope() );
	}

	public function test_a_dry_run_is_the_default_and_changes_nothing(): void {
		$data = $this->data( $this->replace( array( 'folder' => 'wp-content/themes/parent', 'search' => '#111', 'replace' => '#222' ) ) );
		$this->assertTrue( $data['dry_run'] );
		$this->assertSame( 3, $data['replacements'] );
		$paths = array_column( $data['files_changed'], 'path' );
		sort( $paths );
		$this->assertSame( array( 'wp-content/themes/parent/a.css', 'wp-content/themes/parent/inc/b.css' ), $paths );
		$this->assertStringContainsString( '#111', $this->read( 'wp-content/themes/parent/a.css' ) );
		$this->assertDirectoryDoesNotExist( $this->backupDir );
	}

	public function test_the_real_run_replaces_backs_up_and_skips_binary_files(): void {
		$data = $this->data( $this->replace( array( 'folder' => 'wp-content/themes/parent', 'search' => '#111', 'replace' => '#222', 'dry_run' => false ) ) );
		$this->assertFalse( $data['dry_run'] );
		$this->assertSame( 3, $data['replacements'] );
		$this->assertSame( "a { color: #222; }\nb { color: #222; }\n", $this->read( 'wp-content/themes/parent/a.css' ) );
		$this->assertSame( "\x89PNG\r\n\x1a\n#111", $this->read( 'wp-content/themes/parent/logo.png' ), 'Non-code files are never touched.' );
		$this->assertStringContainsString( '#111', $this->read( 'wp-content/themes/child/c.css' ), 'Other folders are untouched.' );

		$backups = $this->data( $this->run_tool( CodeFileBackups::class, array( 'path' => 'wp-content/themes/parent/a.css' ) ) );
		$this->assertCount( 1, $backups['backups'] );
		$this->assertSame( 0, strpos( $backups['backups'][0]['saved'], gmdate( 'Y' ) ) );
	}

	public function test_glob_case_regex_and_limits(): void {
		$d = $this->data( $this->replace( array( 'folder' => 'wp-content/themes/parent', 'search' => '#111', 'replace' => 'X', 'glob' => 'a.*' ) ) );
		$this->assertSame( array( 'wp-content/themes/parent/a.css' ), array_column( $d['files_changed'], 'path' ) );

		file_put_contents( $this->root . '/wp-content/themes/parent/case.css', 'Color COLOR color' );
		$d = $this->data( $this->replace( array( 'folder' => 'wp-content/themes/parent', 'search' => 'color', 'replace' => 'x', 'glob' => 'case.css', 'case_sensitive' => false ) ) );
		$this->assertSame( 3, $d['replacements'] );
		$d = $this->data( $this->replace( array( 'folder' => 'wp-content/themes/parent', 'search' => 'color', 'replace' => 'x', 'glob' => 'case.css' ) ) );
		$this->assertSame( 1, $d['replacements'] );

		$d = $this->data( $this->replace( array( 'folder' => 'wp-content/themes/parent', 'search' => '(#)(\d+)', 'replace' => '$1x$2', 'regex' => true, 'glob' => 'a.css', 'dry_run' => false ) ) );
		$this->assertSame( 2, $d['replacements'] );
		$this->assertSame( "a { color: #x111; }\nb { color: #x111; }\n", $this->read( 'wp-content/themes/parent/a.css' ) );

		$d = $this->data( $this->replace( array( 'folder' => 'wp-content/themes/parent', 'search' => 'color', 'replace' => 'x', 'glob' => '*.css', 'max_files' => 1, 'case_sensitive' => false ) ) );
		$this->assertCount( 1, $d['files_changed'] );
		$this->assertTrue( $d['truncated'] );
	}

	public function test_plain_search_text_is_literal_not_a_pattern(): void {
		file_put_contents( $this->root . '/wp-content/themes/parent/lit.css', 'a.b a+b (x)' );
		$d = $this->data( $this->replace( array( 'folder' => 'wp-content/themes/parent', 'search' => 'a.b', 'replace' => 'Z', 'glob' => 'lit.css', 'dry_run' => false ) ) );
		$this->assertSame( 1, $d['replacements'] );
		$this->assertSame( 'Z a+b (x)', $this->read( 'wp-content/themes/parent/lit.css' ) );
	}

	public function test_a_php_file_that_would_stop_parsing_is_skipped(): void {
		$d = $this->data( $this->replace( array( 'folder' => 'wp-content/themes/parent', 'search' => 'return 1;', 'replace' => 'return (;', 'dry_run' => false ) ) );
		$this->assertSame( 0, $d['replacements'] );
		$this->assertCount( 1, $d['skipped'] );
		$this->assertSame( 'wp-content/themes/parent/functions.php', $d['skipped'][0]['path'] );
		$this->assertStringContainsString( 'no longer parse as PHP', $d['skipped'][0]['reason'] );
		$this->assertStringContainsString( 'return 1;', $this->read( 'wp-content/themes/parent/functions.php' ) );
	}

	public function test_invalid_input_and_scope_are_refused(): void {
		$this->assertSame( 'search is not a valid regular expression.', $this->replace( array( 'folder' => 'wp-content/themes/parent', 'search' => '(', 'regex' => true ) )->text() );
		$this->assertStringContainsString( 'search must be', $this->replace( array( 'folder' => 'wp-content/themes/parent', 'search' => '' ) )->text() );
		$this->assertStringContainsString( 'outside the theme and plugin folders', $this->replace( array( 'folder' => 'wp-content/uploads', 'search' => 'a' ) )->text() );
		$this->assertStringContainsString( 'outside the theme and plugin folders', $this->replace( array( 'folder' => 'wp-content/themes', 'search' => 'a' ) )->text(), 'The themes folder as a whole is too broad.' );
		$this->assertStringContainsString( 'is not a folder', $this->replace( array( 'folder' => 'wp-content/themes/parent/a.css', 'search' => 'a' ) )->text() );
	}

	public function test_safe_mode_refuses_a_protected_folder_up_front(): void {
		file_put_contents( $this->root . '/wp-content/plugins/techthrill-mcp/x.css', 'a { color: #111; }' );
		$r = $this->replace( array( 'folder' => 'wp-content/plugins/techthrill-mcp', 'search' => '#111', 'replace' => '#222', 'dry_run' => false ), true );
		$this->assertTrue( $r->isError() );
		$this->assertStringStartsWith( 'Refused by safe mode', $r->text() );
		$this->assertSame( 'a { color: #111; }', $this->read( 'wp-content/plugins/techthrill-mcp/x.css' ) );

		$off = $this->data( $this->replace( array( 'folder' => 'wp-content/plugins/techthrill-mcp', 'search' => '#111', 'replace' => '#222' ), false ) );
		$this->assertSame( 1, $off['replacements'] );
	}

	// Backups and restore.

	public function test_restore_brings_back_the_previous_version_and_is_itself_undoable(): void {
		$p = 'wp-content/themes/parent/style.css';
		$this->data( $this->run_tool( CodeFileWrite::class, array( 'path' => $p, 'content' => "/* v2 */\n" ) ) );
		$this->data( $this->run_tool( CodeFileEdit::class, array( 'path' => $p, 'old_string' => 'v2', 'new_string' => 'v3' ) ) );
		$this->assertSame( "/* v3 */\n", $this->read( $p ) );

		$list = $this->data( $this->run_tool( CodeFileBackups::class, array( 'path' => $p ) ) )['backups'];
		$this->assertCount( 2, $list );

		$restored = $this->data( $this->run_tool( CodeFileRestore::class, array( 'path' => $p ) ) );
		$this->assertSame( "/* v2 */\n", $this->read( $p ), 'The newest backup is the version before the last change.' );
		$this->assertNotNull( $restored['previous_backup'] );

		$oldest = $list[1]['id'];
		$this->data( $this->run_tool( CodeFileRestore::class, array( 'path' => $p, 'backup' => $oldest ) ) );
		$this->assertSame( "/* parent */\nbody { color: red; }\n", $this->read( $p ) );
		$this->assertCount( 4, $this->data( $this->run_tool( CodeFileBackups::class, array( 'path' => $p ) ) )['backups'] );
	}

	public function test_restore_errors(): void {
		$p = 'wp-content/themes/parent/style.css';
		$r = $this->run_tool( CodeFileRestore::class, array( 'path' => $p ) );
		$this->assertTrue( $r->isError() );
		$this->assertStringContainsString( 'There is no backup of', $r->text() );

		$this->data( $this->run_tool( CodeFileWrite::class, array( 'path' => $p, 'content' => 'x' ) ) );
		$r = $this->run_tool( CodeFileRestore::class, array( 'path' => $p, 'backup' => '../../../etc/passwd' ) );
		$this->assertStringContainsString( 'such backup', $r->text() );
		$r = $this->run_tool( CodeFileRestore::class, array( 'path' => 'wp-config.php' ) );
		$this->assertStringContainsString( 'outside the theme and plugin folders', $r->text() );
		$this->assertStringContainsString( 'outside', $this->run_tool( CodeFileBackups::class, array( 'path' => 'wp-config.php' ) )->text() );
	}

	public function test_a_deleted_file_can_be_restored_from_its_backup(): void {
		$p = 'wp-content/themes/parent/style.css';
		$this->data( $this->run_tool( CodeFileWrite::class, array( 'path' => $p, 'content' => 'new' ) ) );
		unlink( $this->root . '/' . $p );

		$this->data( $this->run_tool( CodeFileRestore::class, array( 'path' => $p ) ) );
		$this->assertSame( "/* parent */\nbody { color: red; }\n", $this->read( $p ) );
	}

	// Registration.

	public function test_every_tool_registers_on_the_main_registry_without_a_name_clash(): void {
		$registry = new ToolRegistry();
		foreach ( array( \TechThrill\MCP\Files\Tools\CodeFileWrite::class, \TechThrill\MCP\Files\Tools\CodeFileEdit::class, \TechThrill\MCP\Files\Tools\CodeFileRename::class, \TechThrill\MCP\Files\Tools\AssetUpload::class, CodeReplaceInFiles::class, CodeFileBackups::class, CodeFileRestore::class ) as $class ) {
			$registry->register( $this->tool( $class ) );
		}

		$names = array( 'code_file_write', 'code_file_edit', 'code_file_rename', 'asset_upload', 'code_replace_in_files', 'code_file_backups', 'code_file_restore' );
		foreach ( $names as $name ) {
			$this->assertNotNull( $registry->get( $name ), $name );
		}

		$main = array( 'site_info', 'wp_cli', 'db_query', 'db_execute', 'db_schema', 'file_list', 'file_read', 'file_search', 'file_write', 'file_edit', 'file_copy', 'file_delete', 'media_upload' );
		$this->assertSame( array(), array_intersect( $names, $main ), 'No name collides with a main-plugin tool.' );

		$read_only = $registry->forScopes( array( 'files:read' ) );
		$this->assertSame( array( 'code_file_backups' ), array_map( fn( $t ) => $t->name(), array_values( $read_only ) ), 'A files:read token sees only the listing tool.' );
	}
}
