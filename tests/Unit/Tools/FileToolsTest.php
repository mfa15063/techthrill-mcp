<?php
namespace TechThrill\MCP\Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\Safety\PathJail;
use TechThrill\MCP\Safety\SafeMode;
use TechThrill\MCP\Tools\FileDelete;
use TechThrill\MCP\Tools\FileEdit;
use TechThrill\MCP\Tools\FileList;
use TechThrill\MCP\Tools\FileRead;
use TechThrill\MCP\Tools\FileSearch;
use TechThrill\MCP\Tools\FileWrite;
use TechThrill\MCP\Tools\ToolContext;
use TechThrill\MCP\Tools\ToolResult;

class FileToolsTest extends TestCase {

	private string $root;

	private ?string $outside = null;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ttm-files-' . uniqid( '', true );
		mkdir( $this->root . '/wp-content/themes/t', 0777, true );
		mkdir( $this->root . '/wp-content/node_modules/x', 0777, true );
		mkdir( $this->root . '/wp-content/plugins/techthrill-mcp', 0777, true );
		file_put_contents( $this->root . '/wp-config.php', "<?php\n// config\n" );
		file_put_contents( $this->root . '/.htaccess', "# htaccess\n" );
		file_put_contents( $this->root . '/wp-content/themes/t/style.css', "a\nb\nc\n" );
		file_put_contents( $this->root . '/wp-content/node_modules/x/b.css', 'b' );
		file_put_contents( $this->root . '/bin.dat', "\0abc" );
		$fh = fopen( $this->root . '/big.bin', 'wb' );
		ftruncate( $fh, 6 * 1024 * 1024 );
		fclose( $fh );
		$this->root = realpath( $this->root );
	}

	protected function tearDown(): void {
		$this->removeTree( $this->root );
		if ( null !== $this->outside ) {
			$this->removeTree( $this->outside );
		}
	}

	private function removeTree( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			if ( ! @unlink( $path ) ) {
				@rmdir( $path );
			}
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( scandir( $path ) as $name ) {
			if ( '.' !== $name && '..' !== $name ) {
				$this->removeTree( $path . DIRECTORY_SEPARATOR . $name );
			}
		}
		rmdir( $path );
	}

	private function ctx( array $scopes = array( 'files:read', 'files:write' ) ): ToolContext {
		return new ToolContext( 1, 1, $scopes );
	}

	private function safe( bool $enabled = true ): SafeMode {
		return new SafeMode( $enabled, 'wp-content/plugins/techthrill-mcp' );
	}

	private function data( ToolResult $result ): array {
		$this->assertFalse( $result->isError(), $result->text() );
		return json_decode( $result->text(), true );
	}

	/**
	 * Names of leftover temp files anywhere under the root.
	 */
	private function tempFiles(): array {
		$found = array();
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->root, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( '.tmp' === substr( $file->getFilename(), -4 ) ) {
				$found[] = $file->getPathname();
			}
		}
		return $found;
	}

	public function test_scopes_and_names(): void {
		$jail = new PathJail( $this->root );
		$safe = $this->safe();
		$this->assertSame( 'file_list', ( new FileList( $jail, $safe ) )->name() );
		$this->assertSame( 'files:read', ( new FileList( $jail, $safe ) )->scope() );
		$this->assertSame( 'files:read', ( new FileRead( $jail, $safe ) )->scope() );
		$this->assertSame( 'files:read', ( new FileSearch( $jail, $safe ) )->scope() );
		$this->assertSame( 'file_write', ( new FileWrite( $jail, $safe ) )->name() );
		$this->assertSame( 'files:write', ( new FileWrite( $jail, $safe ) )->scope() );
		$this->assertSame( 'files:write', ( new FileEdit( $jail, $safe ) )->scope() );
		$this->assertSame( 'files:write', ( new FileDelete( $jail, $safe ) )->scope() );
	}

	public function test_list_root_and_recursive_limit(): void {
		$tool = new FileList( new PathJail( $this->root ), $this->safe() );

		$root = $this->data( $tool->execute( array(), $this->ctx() ) );
		$this->assertSame( '.', $root['path'] );
		$this->assertFalse( $root['truncated'] );
		$by_path = array();
		foreach ( $root['entries'] as $entry ) {
			$by_path[ $entry['path'] ] = $entry;
		}
		$this->assertSame( 'file', $by_path['wp-config.php']['type'] );
		$this->assertSame( 'php', $by_path['wp-config.php']['extension'] );
		$this->assertSame( strlen( "<?php\n// config\n" ), $by_path['wp-config.php']['size'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $by_path['wp-config.php']['mtime'] );
		$this->assertSame( 'dir', $by_path['wp-content']['type'] );
		$this->assertArrayNotHasKey( 'size', $by_path['wp-content'] );
		$this->assertArrayHasKey( '.htaccess', $by_path, 'Hidden entries are included.' );
		$this->assertSame( 'htaccess', $by_path['.htaccess']['extension'] );
		$this->assertArrayNotHasKey( 'wp-content/themes', $by_path, 'Non-recursive listing stays one level deep.' );
		$paths = array_keys( $by_path );
		$sorted = $paths;
		sort( $sorted );
		$this->assertSame( $sorted, $paths );

		$all = $this->data( $tool->execute( array( 'path' => 'wp-content', 'recursive' => true ), $this->ctx() ) );
		$all_paths = array_column( $all['entries'], 'path' );
		$this->assertContains( 'wp-content/themes/t/style.css', $all_paths );
		$this->assertContains( 'wp-content/themes/t', $all_paths );

		$limited = $this->data( $tool->execute( array( 'recursive' => true, 'max_entries' => 2 ), $this->ctx() ) );
		$this->assertSame( 2, $limited['count'] );
		$this->assertCount( 2, $limited['entries'] );
		$this->assertTrue( $limited['truncated'] );
	}

	public function test_list_rejects_files_and_escapes(): void {
		$tool = new FileList( new PathJail( $this->root ), $this->safe() );

		$file = $tool->execute( array( 'path' => 'wp-config.php' ), $this->ctx() );
		$this->assertTrue( $file->isError() );
		$this->assertSame( 'wp-config.php is not a directory.', $file->text() );

		$escape = $tool->execute( array( 'path' => '../outside' ), $this->ctx() );
		$this->assertTrue( $escape->isError() );
		$this->assertSame( 'Path traversal is not allowed.', $escape->text() );
	}

	public function test_list_reports_symlinks_without_following(): void {
		$outside = $this->outsideDir( array( 'secret.txt' => 'x' ), $this->root . '/wp-content/link' );

		$tool  = new FileList( new PathJail( $this->root ), $this->safe() );
		$data  = $this->data( $tool->execute( array( 'path' => 'wp-content', 'recursive' => true ), $this->ctx() ) );
		$types = array_column( $data['entries'], 'type', 'path' );
		$this->assertSame( 'link', $types['wp-content/link'] );
		$this->assertNotContains( 'wp-content/link/secret.txt', array_keys( $types ) );
		$this->assertDirectoryExists( $outside );
	}

	/**
	 * Creates a directory outside the root and a symlink to it, or skips the test when symlinks are unavailable.
	 *
	 * @param array<string,string> $files Files to create in the outside directory.
	 * @param string               $link  Where to create the symlink.
	 * @param string|null          $file  When set, link to this file inside the outside directory instead.
	 */
	private function outsideDir( array $files, string $link, ?string $file = null ): string {
		$outside = $this->root . '-outside';
		mkdir( $outside );
		foreach ( $files as $name => $content ) {
			file_put_contents( $outside . '/' . $name, $content );
		}
		if ( ! @symlink( null === $file ? $outside : $outside . '/' . $file, $link ) ) {
			$this->removeTree( $outside );
			$this->markTestSkipped( 'symlink() unavailable on this platform' );
		}
		$this->outside = $outside;
		return $outside;
	}

	public function test_read_with_offset_and_limit_reports_total(): void {
		$tool = new FileRead( new PathJail( $this->root ), $this->safe() );

		$data = $this->data( $tool->execute( array( 'path' => 'wp-content/themes/t/style.css', 'offset' => 2, 'limit' => 1 ), $this->ctx() ) );
		$this->assertSame( 'b', $data['content'] );
		$this->assertSame( 3, $data['total_lines'] );
		$this->assertSame( 2, $data['offset'] );
		$this->assertTrue( $data['truncated'] );
		$this->assertSame( 'wp-content/themes/t/style.css', $data['path'] );

		$whole = $this->data( $tool->execute( array( 'path' => '/wp-content/themes/t/style.css' ), $this->ctx() ) );
		$this->assertSame( "a\nb\nc", $whole['content'] );
		$this->assertSame( 3, $whole['total_lines'] );
		$this->assertFalse( $whole['truncated'] );

		$past = $this->data( $tool->execute( array( 'path' => 'wp-content/themes/t/style.css', 'offset' => 10 ), $this->ctx() ) );
		$this->assertSame( '', $past['content'] );
		$this->assertFalse( $past['truncated'] );

		$dir = $tool->execute( array( 'path' => 'wp-content' ), $this->ctx() );
		$this->assertTrue( $dir->isError() );
		$this->assertSame( 'wp-content is not a file.', $dir->text() );
	}

	public function test_read_wp_config_requires_db_read_scope(): void {
		$tool = new FileRead( new PathJail( $this->root ), $this->safe() );

		$denied = $tool->execute( array( 'path' => 'wp-config.php' ), $this->ctx( array( 'files:read' ) ) );
		$this->assertTrue( $denied->isError() );
		$this->assertSame( 'Refused: reading wp-config.php requires the db:read scope because it contains database credentials.', $denied->text() );

		$upper = $tool->execute( array( 'path' => './WP-CONFIG.PHP' ), $this->ctx( array( 'files:read' ) ) );
		if ( file_exists( $this->root . '/WP-CONFIG.PHP' ) ) {
			$this->assertTrue( $upper->isError(), 'Case variants on case-insensitive filesystems are covered too.' );
		}

		$allowed = $this->data( $tool->execute( array( 'path' => 'wp-config.php' ), $this->ctx( array( 'files:read', 'db:read' ) ) ) );
		$this->assertStringContainsString( '// config', $allowed['content'] );
	}

	public function test_read_binary_and_oversize_refused(): void {
		$tool = new FileRead( new PathJail( $this->root ), $this->safe() );

		$binary = $tool->execute( array( 'path' => 'bin.dat' ), $this->ctx() );
		$this->assertTrue( $binary->isError() );
		$this->assertMatchesRegularExpression( '/^bin\.dat is a binary file \(4 bytes, [^)]+\); file_read only returns text files\.$/', $binary->text() );

		$big = $tool->execute( array( 'path' => 'big.bin' ), $this->ctx() );
		$this->assertTrue( $big->isError() );
		$this->assertSame( 'big.bin is 6291456 bytes; file_read is limited to 5 MB.', $big->text() );
	}

	public function test_search_regex_with_glob_and_ignore(): void {
		$tool = new FileSearch( new PathJail( $this->root ), $this->safe() );

		$data = $this->data( $tool->execute( array( 'pattern' => 'b', 'glob' => '*.css' ), $this->ctx() ) );
		$this->assertSame( 1, $data['count'] );
		$this->assertFalse( $data['truncated'] );
		$this->assertSame(
			array(
				array(
					'path' => 'wp-content/themes/t/style.css',
					'line' => 2,
					'text' => 'b',
				),
			),
			$data['matches']
		);

		$sensitive = $this->data( $tool->execute( array( 'pattern' => 'B', 'glob' => '*.css' ), $this->ctx() ) );
		$this->assertSame( 0, $sensitive['count'] );
		$insensitive = $this->data( $tool->execute( array( 'pattern' => 'B', 'glob' => '*.css', 'case_sensitive' => false ), $this->ctx() ) );
		$this->assertSame( 1, $insensitive['count'] );
	}

	public function test_search_skips_binary_and_oversize_limits_results_and_validates_regex(): void {
		$tool = new FileSearch( new PathJail( $this->root ), $this->safe() );

		// bin.dat contains "abc" but is binary; big.bin is over 2 MB.
		$none = $this->data( $tool->execute( array( 'pattern' => 'abc' ), $this->ctx() ) );
		$this->assertSame( 0, $none['count'] );

		file_put_contents( $this->root . '/many.txt', "x\nx\nx\nx\n" );
		$limited = $this->data( $tool->execute( array( 'pattern' => '^x$', 'max_results' => 2 ), $this->ctx() ) );
		$this->assertSame( 2, $limited['count'] );
		$this->assertTrue( $limited['truncated'] );

		file_put_contents( $this->root . '/slash.txt', "see a/b here\n" );
		$slash = $this->data( $tool->execute( array( 'pattern' => 'a/b', 'path' => '.' ), $this->ctx() ) );
		$this->assertSame( 'slash.txt', $slash['matches'][0]['path'] );
		$escaped = $this->data( $tool->execute( array( 'pattern' => 'a\/b' ), $this->ctx() ) );
		$this->assertSame( 1, $escaped['count'] );

		$bad = $tool->execute( array( 'pattern' => '(unclosed' ), $this->ctx() );
		$this->assertTrue( $bad->isError() );
		$this->assertStringStartsWith( 'Invalid regular expression', $bad->text() );
	}

	public function test_write_atomic_and_creates_dirs(): void {
		$tool = new FileWrite( new PathJail( $this->root ), $this->safe() );

		$data = $this->data( $tool->execute( array( 'path' => 'wp-content/new/deep/file.txt', 'content' => "hello\nworld" ), $this->ctx() ) );
		$this->assertSame( 'wp-content/new/deep/file.txt', $data['path'] );
		$this->assertSame( 11, $data['bytes'] );
		$this->assertTrue( $data['created'] );
		$this->assertSame( "hello\nworld", file_get_contents( $this->root . '/wp-content/new/deep/file.txt' ) );

		$again = $this->data( $tool->execute( array( 'path' => 'wp-content/new/deep/file.txt', 'content' => 'x' ), $this->ctx() ) );
		$this->assertFalse( $again['created'] );
		$this->assertSame( 'x', file_get_contents( $this->root . '/wp-content/new/deep/file.txt' ) );

		$this->assertSame( array(), $this->tempFiles() );

		$no_dirs = $tool->execute( array( 'path' => 'wp-content/missing/file.txt', 'content' => 'x', 'create_dirs' => false ), $this->ctx() );
		$this->assertTrue( $no_dirs->isError() );
		$this->assertFalse( is_dir( $this->root . '/wp-content/missing' ) );

		$dir = $tool->execute( array( 'path' => 'wp-content/new', 'content' => 'x' ), $this->ctx() );
		$this->assertTrue( $dir->isError() );
		$this->assertSame( 'wp-content/new is a directory.', $dir->text() );

		$escape = $tool->execute( array( 'path' => '../evil.txt', 'content' => 'x' ), $this->ctx() );
		$this->assertTrue( $escape->isError() );
		$this->assertSame( array(), $this->tempFiles() );
	}

	public function test_write_refuses_wp_config_and_plugin_dir_in_safe_mode(): void {
		$jail = new PathJail( $this->root );

		$config = ( new FileWrite( $jail, $this->safe( false ) ) )->execute( array( 'path' => 'wp-config.php', 'content' => 'x' ), $this->ctx() );
		$this->assertTrue( $config->isError() );
		$this->assertSame( 'Refused: wp-config.php cannot be modified or deleted through TechThrill MCP.', $config->text() );
		$this->assertSame( "<?php\n// config\n", file_get_contents( $this->root . '/wp-config.php' ) );

		$safe_tool = new FileWrite( $jail, $this->safe( true ) );
		$plugin    = $safe_tool->execute( array( 'path' => 'wp-content/plugins/techthrill-mcp/x.php', 'content' => 'x' ), $this->ctx() );
		$this->assertTrue( $plugin->isError() );
		$this->assertStringStartsWith( 'Refused by safe mode: ', $plugin->text() );
		$this->assertFileDoesNotExist( $this->root . '/wp-content/plugins/techthrill-mcp/x.php' );

		$htaccess = $safe_tool->execute( array( 'path' => '.htaccess', 'content' => 'x' ), $this->ctx() );
		$this->assertTrue( $htaccess->isError() );
		$this->assertSame( "# htaccess\n", file_get_contents( $this->root . '/.htaccess' ) );

		$off = ( new FileWrite( $jail, $this->safe( false ) ) )->execute( array( 'path' => 'wp-content/plugins/techthrill-mcp/x.php', 'content' => 'x' ), $this->ctx() );
		$this->assertFalse( $off->isError(), $off->text() );
		$this->assertFileExists( $this->root . '/wp-content/plugins/techthrill-mcp/x.php' );
	}

	public function test_edit_single_multiple_replace_all_identical_empty(): void {
		$tool = new FileEdit( new PathJail( $this->root ), $this->safe() );
		$path = $this->root . '/edit.txt';
		file_put_contents( $path, 'foo bar foo' );

		// 1. Single occurrence.
		$single = $this->data( $tool->execute( array( 'path' => 'edit.txt', 'old_string' => 'bar', 'new_string' => 'baz' ), $this->ctx() ) );
		$this->assertSame( 1, $single['replacements'] );
		$this->assertSame( 'edit.txt', $single['path'] );
		$this->assertSame( 'foo baz foo', file_get_contents( $path ) );

		// 2. Multiple occurrences without replace_all, and no match.
		$multiple = $tool->execute( array( 'path' => 'edit.txt', 'old_string' => 'foo', 'new_string' => 'x' ), $this->ctx() );
		$this->assertTrue( $multiple->isError() );
		$this->assertSame( 'old_string matches 2 places in edit.txt; make it unique or set replace_all to true.', $multiple->text() );
		$this->assertSame( 'foo baz foo', file_get_contents( $path ) );

		$missing = $tool->execute( array( 'path' => 'edit.txt', 'old_string' => 'nope', 'new_string' => 'x' ), $this->ctx() );
		$this->assertTrue( $missing->isError() );
		$this->assertSame( 'old_string was not found in edit.txt.', $missing->text() );

		// 3. replace_all.
		$all = $this->data( $tool->execute( array( 'path' => 'edit.txt', 'old_string' => 'foo', 'new_string' => 'x', 'replace_all' => true ), $this->ctx() ) );
		$this->assertSame( 2, $all['replacements'] );
		$this->assertSame( 'x baz x', file_get_contents( $path ) );

		// 4. Identical strings.
		$identical = $tool->execute( array( 'path' => 'edit.txt', 'old_string' => 'x', 'new_string' => 'x' ), $this->ctx() );
		$this->assertTrue( $identical->isError() );
		$this->assertSame( 'old_string and new_string are identical.', $identical->text() );

		// 5. Empty old_string.
		$empty = $tool->execute( array( 'path' => 'edit.txt', 'old_string' => '', 'new_string' => 'x' ), $this->ctx() );
		$this->assertTrue( $empty->isError() );
		$this->assertSame( 'old_string must not be empty.', $empty->text() );
		$this->assertSame( 'x baz x', file_get_contents( $path ) );

		$this->assertSame( array(), $this->tempFiles() );
	}

	public function test_edit_refuses_protected_and_missing_files(): void {
		$tool = new FileEdit( new PathJail( $this->root ), $this->safe( false ) );

		$config = $tool->execute( array( 'path' => 'wp-config.php', 'old_string' => 'config', 'new_string' => 'x' ), $this->ctx() );
		$this->assertTrue( $config->isError() );
		$this->assertSame( 'Refused: wp-config.php cannot be modified or deleted through TechThrill MCP.', $config->text() );

		$missing = $tool->execute( array( 'path' => 'nope.txt', 'old_string' => 'a', 'new_string' => 'b' ), $this->ctx() );
		$this->assertTrue( $missing->isError() );
		$this->assertSame( 'Path does not exist.', $missing->text() );
	}

	public function test_delete_file_and_dir_rules(): void {
		$jail = new PathJail( $this->root );

		$file = $this->data( ( new FileDelete( $jail, $this->safe() ) )->execute( array( 'path' => 'bin.dat' ), $this->ctx() ) );
		$this->assertSame( array( 'path' => 'bin.dat', 'deleted' => true, 'type' => 'file' ), $file );
		$this->assertFileDoesNotExist( $this->root . '/bin.dat' );

		$config = ( new FileDelete( $jail, $this->safe( false ) ) )->execute( array( 'path' => 'wp-config.php' ), $this->ctx() );
		$this->assertTrue( $config->isError() );
		$this->assertFileExists( $this->root . '/wp-config.php' );

		$no_recursive = ( new FileDelete( $jail, $this->safe( false ) ) )->execute( array( 'path' => 'wp-content/themes' ), $this->ctx() );
		$this->assertTrue( $no_recursive->isError() );
		$this->assertSame( 'wp-content/themes is a directory; set recursive to true to delete it and its contents.', $no_recursive->text() );
		$this->assertDirectoryExists( $this->root . '/wp-content/themes' );

		$safe_on = ( new FileDelete( $jail, $this->safe( true ) ) )->execute( array( 'path' => 'wp-content/themes', 'recursive' => true ), $this->ctx() );
		$this->assertTrue( $safe_on->isError() );
		$this->assertSame( 'Refused by safe mode: deleting a directory is blocked. The site administrator can disable safe mode under TechThrill MCP → Settings if this is intended.', $safe_on->text() );
		$this->assertDirectoryExists( $this->root . '/wp-content/themes' );

		$root = ( new FileDelete( $jail, $this->safe( false ) ) )->execute( array( 'path' => '.', 'recursive' => true ), $this->ctx() );
		$this->assertTrue( $root->isError() );
		$this->assertFileExists( $this->root . '/wp-config.php' );

		$off = $this->data( ( new FileDelete( $jail, $this->safe( false ) ) )->execute( array( 'path' => 'wp-content/themes', 'recursive' => true ), $this->ctx() ) );
		$this->assertSame( array( 'path' => 'wp-content/themes', 'deleted' => true, 'type' => 'dir' ), $off );
		$this->assertDirectoryDoesNotExist( $this->root . '/wp-content/themes' );
		$this->assertFileExists( $this->root . '/wp-content/node_modules/x/b.css' );
	}


	public function test_recursive_delete_does_not_follow_symlinks(): void {
		mkdir( $this->root . '/victim' );
		file_put_contents( $this->root . '/victim/a.txt', 'a' );
		$outside = $this->outsideDir( array( 'keep.txt' => 'keep' ), $this->root . '/victim/link' );

		$result = ( new FileDelete( new PathJail( $this->root ), $this->safe( false ) ) )->execute( array( 'path' => 'victim', 'recursive' => true ), $this->ctx() );
		$this->assertFalse( $result->isError(), $result->text() );
		$this->assertDirectoryDoesNotExist( $this->root . '/victim' );
		$this->assertFileExists( $outside . '/keep.txt' );
	}

	public function test_delete_symlink_removes_link_not_target(): void {
		if ( ! @symlink( $this->root . '/wp-content/themes/t', $this->root . '/theme-link' ) ) {
			$this->markTestSkipped( 'symlink() unavailable on this platform' );
		}

		$tool   = new FileDelete( new PathJail( $this->root ), $this->safe( false ) );
		$result = $this->data( $tool->execute( array( 'path' => 'theme-link', 'recursive' => true ), $this->ctx() ) );
		$this->assertSame( array( 'path' => 'theme-link', 'deleted' => true, 'type' => 'link' ), $result );
		$this->assertFalse( is_link( $this->root . '/theme-link' ) );
		$this->assertFileExists( $this->root . '/wp-content/themes/t/style.css' );
	}

	public function test_delete_rejects_dot_segments_and_missing_parent(): void {
		$tool = new FileDelete( new PathJail( $this->root ), $this->safe( false ) );
		$this->assertTrue( $tool->execute( array( 'path' => 'wp-content/..' ), $this->ctx() )->isError() );
		$this->assertTrue( $tool->execute( array( 'path' => 'nope/file.txt' ), $this->ctx() )->isError() );
		$this->assertFileExists( $this->root . '/wp-config.php' );
	}

	public function test_search_does_not_read_symlinked_files(): void {
		$this->outsideDir( array( 'secret.txt' => "topsecret\n" ), $this->root . '/link.txt', 'secret.txt' );

		$data = $this->data( ( new FileSearch( new PathJail( $this->root ), $this->safe() ) )->execute( array( 'pattern' => 'topsecret' ), $this->ctx() ) );
		$this->assertSame( 0, $data['count'] );
	}

	public function test_search_skips_wp_config_without_db_read_scope(): void {
		file_put_contents( $this->root . '/wp-config.php', "<?php\ndefine( 'DB_PASSWORD', 'hunter2' );\n" );
		$tool = new FileSearch( new PathJail( $this->root ), $this->safe() );

		$denied = $this->data( $tool->execute( array( 'pattern' => 'DB_' ), $this->ctx( array( 'files:read' ) ) ) );
		$this->assertSame( 0, $denied['count'] );

		$allowed = $this->data( $tool->execute( array( 'pattern' => 'DB_' ), $this->ctx( array( 'files:read', 'db:read' ) ) ) );
		$this->assertSame( 1, $allowed['count'] );
		$this->assertSame( 'wp-config.php', $allowed['matches'][0]['path'] );
	}

	public function test_search_end_anchor_matches_crlf_lines(): void {
		file_put_contents( $this->root . '/crlf.txt', "foo\r\nbar\r\n" );
		$data = $this->data( ( new FileSearch( new PathJail( $this->root ), $this->safe() ) )->execute( array( 'pattern' => 'foo$', 'glob' => 'crlf.txt' ), $this->ctx() ) );
		$this->assertSame( 1, $data['count'] );
		$this->assertSame( 'foo', $data['matches'][0]['text'] );
	}

	public function test_read_reports_line_ending(): void {
		file_put_contents( $this->root . '/crlf.txt', "one\r\ntwo\r\n" );
		$tool = new FileRead( new PathJail( $this->root ), $this->safe() );

		$crlf = $this->data( $tool->execute( array( 'path' => 'crlf.txt' ), $this->ctx() ) );
		$this->assertSame( 'crlf', $crlf['line_ending'] );
		$this->assertSame( "one\ntwo", $crlf['content'] );

		$lf = $this->data( $tool->execute( array( 'path' => 'wp-content/themes/t/style.css' ), $this->ctx() ) );
		$this->assertSame( 'lf', $lf['line_ending'] );
	}

	public function test_edit_lf_old_string_matches_crlf_file_and_keeps_crlf(): void {
		file_put_contents( $this->root . '/crlf.txt', "one\r\ntwo\r\nthree\r\n" );
		$tool = new FileEdit( new PathJail( $this->root ), $this->safe() );

		$result = $this->data( $tool->execute( array( 'path' => 'crlf.txt', 'old_string' => "one\ntwo", 'new_string' => "uno\ndos" ), $this->ctx() ) );
		$this->assertSame( 1, $result['replacements'] );
		$this->assertSame( "uno\r\ndos\r\nthree\r\n", file_get_contents( $this->root . '/crlf.txt' ) );

		$missing = $tool->execute( array( 'path' => 'crlf.txt', 'old_string' => "nope\nnope", 'new_string' => 'x' ), $this->ctx() );
		$this->assertTrue( $missing->isError() );
		$this->assertSame( 'old_string was not found in crlf.txt.', $missing->text() );
	}
}
