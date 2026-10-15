<?php
namespace TechThrill\MCP\Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\Safety\PathJail;
use TechThrill\MCP\Safety\SafeMode;
use TechThrill\MCP\Tools\FileCopy;
use TechThrill\MCP\Tools\ToolContext;
use TechThrill\MCP\Tools\ToolResult;

class FileCopyTest extends TestCase {

	private string $root;

	private ?string $outside = null;

	/**
	 * A tiny PNG-like payload: signature, NUL bytes and every byte value, so any text-mode handling corrupts it.
	 */
	private string $binary;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ttm-copy-' . uniqid( '', true );
		mkdir( $this->root . '/wp-content/themes/parent/inc', 0777, true );
		mkdir( $this->root . '/wp-content/plugins/techthrill-mcp', 0777, true );
		$this->root = realpath( $this->root );

		$this->binary = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR" . implode( '', array_map( 'chr', range( 0, 255 ) ) ) . "\0\xff";
		file_put_contents( $this->root . '/wp-content/themes/parent/screenshot.png', $this->binary );
		file_put_contents( $this->root . '/wp-content/themes/parent/style.css', "/* parent */\n" );
		file_put_contents( $this->root . '/wp-content/themes/parent/inc/helpers.php', "<?php\n" );
		file_put_contents( $this->root . '/wp-config.php', "<?php\n// config\n" );
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

	private function tool( bool $safe = true ): FileCopy {
		return new FileCopy( new PathJail( $this->root ), new SafeMode( $safe, 'wp-content/plugins/techthrill-mcp' ) );
	}

	private function ctx( array $scopes = array( 'files:read', 'files:write' ) ): ToolContext {
		return new ToolContext( 1, 1, $scopes );
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

	public function test_name_scope_and_schema(): void {
		$tool = $this->tool();
		$this->assertSame( 'file_copy', $tool->name() );
		$this->assertSame( 'files:write', $tool->scope() );
		$schema = $tool->inputSchema();
		$this->assertSame( array( 'from', 'to' ), $schema['required'] );
		$this->assertSame( array( 'from', 'to', 'overwrite', 'create_dirs', 'recursive' ), array_keys( $schema['properties'] ) );
	}

	public function test_copies_a_binary_file_byte_for_byte_and_creates_parent_dirs(): void {
		$data = $this->data(
			$this->tool()->execute(
				array(
					'from' => 'wp-content/themes/parent/screenshot.png',
					'to'   => 'wp-content/themes/child/screenshot.png',
				),
				$this->ctx()
			)
		);

		$this->assertSame(
			array(
				'from'        => 'wp-content/themes/parent/screenshot.png',
				'to'          => 'wp-content/themes/child/screenshot.png',
				'type'        => 'file',
				'files'       => 1,
				'bytes'       => strlen( $this->binary ),
				'overwritten' => false,
			),
			$data
		);
		$this->assertSame( $this->binary, file_get_contents( $this->root . '/wp-content/themes/child/screenshot.png' ) );
		$this->assertSame( $this->binary, file_get_contents( $this->root . '/wp-content/themes/parent/screenshot.png' ), 'The source is untouched.' );
		$this->assertSame( array(), $this->tempFiles() );
	}

	public function test_existing_destination_needs_overwrite(): void {
		file_put_contents( $this->root . '/wp-content/themes/parent/copy.png', 'old' );

		$refused = $this->tool()->execute(
			array(
				'from' => 'wp-content/themes/parent/screenshot.png',
				'to'   => 'wp-content/themes/parent/copy.png',
			),
			$this->ctx()
		);
		$this->assertTrue( $refused->isError() );
		$this->assertSame( 'wp-content/themes/parent/copy.png already exists; set overwrite to true to replace it.', $refused->text() );
		$this->assertSame( 'old', file_get_contents( $this->root . '/wp-content/themes/parent/copy.png' ) );

		$data = $this->data(
			$this->tool()->execute(
				array(
					'from'      => 'wp-content/themes/parent/screenshot.png',
					'to'        => 'wp-content/themes/parent/copy.png',
					'overwrite' => true,
				),
				$this->ctx()
			)
		);
		$this->assertTrue( $data['overwritten'] );
		$this->assertSame( $this->binary, file_get_contents( $this->root . '/wp-content/themes/parent/copy.png' ) );
		$this->assertSame( array(), $this->tempFiles() );
	}

	public function test_create_dirs_false_does_not_create_the_parent(): void {
		$result = $this->tool()->execute(
			array(
				'from'        => 'wp-content/themes/parent/style.css',
				'to'          => 'wp-content/themes/missing/style.css',
				'create_dirs' => false,
			),
			$this->ctx()
		);
		$this->assertTrue( $result->isError() );
		$this->assertSame( 'The parent directory of wp-content/themes/missing/style.css does not exist; set create_dirs to true.', $result->text() );
		$this->assertDirectoryDoesNotExist( $this->root . '/wp-content/themes/missing' );
	}

	public function test_missing_source_same_path_and_directory_destination_are_errors(): void {
		$missing = $this->tool()->execute( array( 'from' => 'nope.png', 'to' => 'x.png' ), $this->ctx() );
		$this->assertTrue( $missing->isError() );
		$this->assertSame( 'Path does not exist.', $missing->text() );

		$same = $this->tool()->execute( array( 'from' => 'wp-content/themes/parent/style.css', 'to' => 'wp-content/themes/parent/./style.css', 'overwrite' => true ), $this->ctx() );
		$this->assertTrue( $same->isError() );
		$this->assertSame( 'The source and the destination are the same path.', $same->text() );

		$dir = $this->tool()->execute( array( 'from' => 'wp-content/themes/parent/style.css', 'to' => 'wp-content/themes/parent/inc', 'overwrite' => true ), $this->ctx() );
		$this->assertTrue( $dir->isError() );
		$this->assertSame( 'wp-content/themes/parent/inc is a directory; give the full destination file path.', $dir->text() );
	}

	public function test_path_jail_blocks_escapes_on_both_sides(): void {
		$up = $this->tool()->execute( array( 'from' => '../secret.png', 'to' => 'x.png' ), $this->ctx() );
		$this->assertTrue( $up->isError() );
		$this->assertSame( 'Path traversal is not allowed.', $up->text() );

		$out = $this->tool()->execute( array( 'from' => 'wp-content/themes/parent/style.css', 'to' => '../evil.css' ), $this->ctx() );
		$this->assertTrue( $out->isError() );
		$this->assertSame( 'Path traversal is not allowed.', $out->text() );

		$abs = $this->tool()->execute( array( 'from' => 'wp-content/themes/parent/style.css', 'to' => '/tmp/evil.css' ), $this->ctx() );
		$this->assertTrue( $abs->isError() );
		$this->assertSame( 'Absolute paths are not allowed.', $abs->text() );
	}

	public function test_a_symlink_to_outside_the_root_cannot_be_copied(): void {
		$outside = $this->root . '-outside';
		mkdir( $outside );
		$this->outside = $outside;
		file_put_contents( $outside . '/secret.png', 'secret' );
		if ( ! @symlink( $outside . '/secret.png', $this->root . '/wp-content/link.png' ) ) {
			$this->markTestSkipped( 'symlink() unavailable on this platform' );
		}

		$result = $this->tool()->execute( array( 'from' => 'wp-content/link.png', 'to' => 'wp-content/stolen.png' ), $this->ctx() );
		$this->assertTrue( $result->isError() );
		$this->assertSame( 'Path is outside the allowed root.', $result->text() );
		$this->assertFileDoesNotExist( $this->root . '/wp-content/stolen.png' );
	}

	public function test_protected_destinations_are_refused(): void {
		$config = $this->tool( false )->execute( array( 'from' => 'wp-content/themes/parent/style.css', 'to' => 'wp-config.php', 'overwrite' => true ), $this->ctx() );
		$this->assertTrue( $config->isError() );
		$this->assertSame( 'Refused: wp-config.php cannot be modified or deleted through TechThrill MCP.', $config->text() );
		$this->assertSame( "<?php\n// config\n", file_get_contents( $this->root . '/wp-config.php' ) );

		$plugin = $this->tool( true )->execute( array( 'from' => 'wp-content/themes/parent/style.css', 'to' => 'wp-content/plugins/techthrill-mcp/x.css' ), $this->ctx() );
		$this->assertTrue( $plugin->isError() );
		$this->assertStringStartsWith( 'Refused by safe mode: ', $plugin->text() );
		$this->assertFileDoesNotExist( $this->root . '/wp-content/plugins/techthrill-mcp/x.css' );

		$off = $this->tool( false )->execute( array( 'from' => 'wp-content/themes/parent/style.css', 'to' => 'wp-content/plugins/techthrill-mcp/x.css' ), $this->ctx() );
		$this->assertFalse( $off->isError(), $off->text() );
	}

	public function test_copying_wp_config_needs_db_read_scope(): void {
		$denied = $this->tool()->execute( array( 'from' => 'wp-config.php', 'to' => 'wp-content/leak.txt' ), $this->ctx() );
		$this->assertTrue( $denied->isError() );
		$this->assertSame( 'Refused: copying wp-config.php requires the db:read scope because it contains database credentials.', $denied->text() );
		$this->assertFileDoesNotExist( $this->root . '/wp-content/leak.txt' );

		$upper = $this->tool()->execute( array( 'from' => './WP-CONFIG.PHP', 'to' => 'wp-content/leak.txt' ), $this->ctx() );
		if ( file_exists( $this->root . '/WP-CONFIG.PHP' ) ) {
			$this->assertTrue( $upper->isError(), 'Case variants on case-insensitive filesystems are covered too.' );
		}

		$allowed = $this->data( $this->tool()->execute( array( 'from' => 'wp-config.php', 'to' => 'wp-content/backup.txt' ), $this->ctx( array( 'files:write', 'db:read' ) ) ) );
		$this->assertSame( 'file', $allowed['type'] );
	}

	public function test_oversize_file_is_refused(): void {
		$fh = fopen( $this->root . '/wp-content/big.bin', 'wb' );
		ftruncate( $fh, FileCopy::MAX_FILE_BYTES + 1 );
		fclose( $fh );

		$result = $this->tool()->execute( array( 'from' => 'wp-content/big.bin', 'to' => 'wp-content/big-copy.bin' ), $this->ctx() );
		$this->assertTrue( $result->isError() );
		$this->assertSame( 'wp-content/big.bin is ' . ( FileCopy::MAX_FILE_BYTES + 1 ) . ' bytes; file_copy is limited to 50 MB per file.', $result->text() );
		$this->assertFileDoesNotExist( $this->root . '/wp-content/big-copy.bin' );
	}

	public function test_directory_needs_recursive_then_copies_the_tree(): void {
		$refused = $this->tool()->execute( array( 'from' => 'wp-content/themes/parent', 'to' => 'wp-content/themes/child' ), $this->ctx() );
		$this->assertTrue( $refused->isError() );
		$this->assertSame( 'wp-content/themes/parent is a directory; set recursive to true to copy it and its contents.', $refused->text() );
		$this->assertDirectoryDoesNotExist( $this->root . '/wp-content/themes/child' );

		$data = $this->data( $this->tool()->execute( array( 'from' => 'wp-content/themes/parent', 'to' => 'wp-content/themes/child', 'recursive' => true ), $this->ctx() ) );
		$this->assertSame( 'dir', $data['type'] );
		$this->assertSame( 3, $data['files'] );
		$this->assertSame( strlen( $this->binary ) + strlen( "/* parent */\n" ) + strlen( "<?php\n" ), $data['bytes'] );
		$this->assertSame( $this->binary, file_get_contents( $this->root . '/wp-content/themes/child/screenshot.png' ) );
		$this->assertSame( "<?php\n", file_get_contents( $this->root . '/wp-content/themes/child/inc/helpers.php' ) );
		$this->assertSame( array(), $this->tempFiles() );
	}

	public function test_directory_copy_rules_for_existing_destination_and_nesting(): void {
		mkdir( $this->root . '/wp-content/themes/child' );
		file_put_contents( $this->root . '/wp-content/themes/child/keep.txt', 'keep' );
		file_put_contents( $this->root . '/wp-content/themes/child/style.css', 'old' );

		$exists = $this->tool()->execute( array( 'from' => 'wp-content/themes/parent', 'to' => 'wp-content/themes/child', 'recursive' => true ), $this->ctx() );
		$this->assertTrue( $exists->isError() );
		$this->assertSame( 'wp-content/themes/child already exists; set overwrite to true to merge into it and replace files with the same name.', $exists->text() );
		$this->assertSame( 'old', file_get_contents( $this->root . '/wp-content/themes/child/style.css' ) );

		$merged = $this->data( $this->tool()->execute( array( 'from' => 'wp-content/themes/parent', 'to' => 'wp-content/themes/child', 'recursive' => true, 'overwrite' => true ), $this->ctx() ) );
		$this->assertSame( 3, $merged['files'] );
		$this->assertSame( "/* parent */\n", file_get_contents( $this->root . '/wp-content/themes/child/style.css' ) );
		$this->assertSame( 'keep', file_get_contents( $this->root . '/wp-content/themes/child/keep.txt' ), 'Merging never deletes extra files.' );

		$into_self = $this->tool()->execute( array( 'from' => 'wp-content/themes/parent', 'to' => 'wp-content/themes/parent/inc/copy', 'recursive' => true ), $this->ctx() );
		$this->assertTrue( $into_self->isError() );
		$this->assertSame( 'A directory cannot be copied into itself.', $into_self->text() );

		$onto_file = $this->tool()->execute( array( 'from' => 'wp-content/themes/parent', 'to' => 'wp-content/themes/parent/style.css', 'recursive' => true, 'overwrite' => true ), $this->ctx() );
		$this->assertTrue( $onto_file->isError() );
	}

	public function test_directory_copy_checks_every_destination_before_writing_anything(): void {
		mkdir( $this->root . '/src/plugins/techthrill-mcp', 0777, true );
		file_put_contents( $this->root . '/src/a.txt', 'a' );
		file_put_contents( $this->root . '/src/plugins/techthrill-mcp/evil.php', '<?php' );

		$result = $this->tool( true )->execute( array( 'from' => 'src', 'to' => 'wp-content', 'recursive' => true, 'overwrite' => true ), $this->ctx() );
		$this->assertTrue( $result->isError() );
		$this->assertStringStartsWith( 'Refused by safe mode: ', $result->text() );
		$this->assertFileDoesNotExist( $this->root . '/wp-content/a.txt', 'Nothing is copied when any destination is refused.' );
		$this->assertFileDoesNotExist( $this->root . '/wp-content/plugins/techthrill-mcp/evil.php' );

		// A tree copied onto the WordPress root would overwrite wp-config.php, so the root is never a destination.
		mkdir( $this->root . '/cfg' );
		file_put_contents( $this->root . '/cfg/wp-config.php', 'x' );
		$root = $this->tool( false )->execute( array( 'from' => 'cfg', 'to' => '.', 'recursive' => true, 'overwrite' => true ), $this->ctx() );
		$this->assertTrue( $root->isError() );
		$this->assertSame( 'The root itself cannot be created.', $root->text() );
		$this->assertSame( "<?php\n// config\n", file_get_contents( $this->root . '/wp-config.php' ) );
	}

	public function test_directory_copy_skips_symlinks(): void {
		$outside = $this->root . '-outside';
		mkdir( $outside );
		$this->outside = $outside;
		file_put_contents( $outside . '/secret.txt', 'secret' );
		if ( ! @symlink( $outside, $this->root . '/wp-content/themes/parent/link' ) ) {
			$this->markTestSkipped( 'symlink() unavailable on this platform' );
		}

		$data = $this->data( $this->tool()->execute( array( 'from' => 'wp-content/themes/parent', 'to' => 'wp-content/themes/child', 'recursive' => true ), $this->ctx() ) );
		$this->assertSame( 1, $data['skipped_links'] );
		$this->assertFileDoesNotExist( $this->root . '/wp-content/themes/child/link' );
		$this->assertFileDoesNotExist( $this->root . '/wp-content/themes/child/link/secret.txt' );
	}
}
