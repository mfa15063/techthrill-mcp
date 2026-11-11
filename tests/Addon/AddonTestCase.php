<?php
namespace TechThrill\MCP\Tests\Addon;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\Files\Support\AllowedRoots;
use TechThrill\MCP\Files\Support\BackupStore;
use TechThrill\MCP\Files\Tools\AbstractCodeTool;
use TechThrill\MCP\Safety\PathJail;
use TechThrill\MCP\Safety\SafeMode;
use TechThrill\MCP\Tools\ToolContext;
use TechThrill\MCP\Tools\ToolResult;

/**
 * A throw-away WordPress-like folder tree: themes, plugins, uploads and a wp-config.php.
 */
abstract class AddonTestCase extends TestCase {

	protected string $root;

	protected string $backupDir;

	/**
	 * Folders to treat as active themes or plugins.
	 *
	 * @var string[]
	 */
	protected array $active = array();

	protected function setUp(): void {
		$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ttm-addon-' . uniqid( '', true );
		foreach ( array( 'wp-content/themes/parent/inc', 'wp-content/themes/child', 'wp-content/plugins/foo', 'wp-content/plugins/techthrill-mcp', 'wp-content/plugins/techthrill-mcp-files', 'wp-content/uploads' ) as $dir ) {
			mkdir( $base . '/' . $dir, 0777, true );
		}
		$this->root      = realpath( $base );
		$this->backupDir = $this->root . '/wp-content/uploads/backups-test';

		file_put_contents( $this->root . '/wp-config.php', "<?php\n// config\n" );
		file_put_contents( $this->root . '/wp-content/themes/parent/style.css', "/* parent */\nbody { color: red; }\n" );
		file_put_contents( $this->root . '/wp-content/themes/parent/functions.php', "<?php\nfunction parent_setup() {\n\treturn 1;\n}\n" );
		file_put_contents( $this->root . '/wp-content/themes/parent/inc/helpers.php', "<?php\nreturn 'helpers';\n" );
		file_put_contents( $this->root . '/wp-content/plugins/foo/foo.php', "<?php\n/* Plugin Name: Foo */\n" );
		file_put_contents( $this->root . '/wp-content/plugins/techthrill-mcp/techthrill-mcp.php', "<?php\n// main\n" );
		$this->active = array();
	}

	protected function tearDown(): void {
		$this->removeTree( $this->root );
	}

	protected function removeTree( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			@unlink( $path );
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
		@rmdir( $path );
	}

	/**
	 * Builds a tool of the given class against the temp tree.
	 *
	 * @param string $class    Tool class.
	 * @param bool   $safeMode Whether safe mode is on.
	 */
	protected function tool( string $class, bool $safeMode = false ): AbstractCodeTool {
		return new $class(
			new PathJail( $this->root ),
			new SafeMode( $safeMode, 'wp-content/plugins/techthrill-mcp' ),
			new AllowedRoots( array( 'wp-content/themes', 'wp-content/plugins' ) ),
			new BackupStore( $this->backupDir ),
			fn() => $this->active,
			array( 'wp-content/plugins/techthrill-mcp', 'wp-content/plugins/techthrill-mcp-files' )
		);
	}

	protected function run_tool( string $class, array $args, bool $safeMode = false ): ToolResult {
		return $this->tool( $class, $safeMode )->execute( $args, new ToolContext( 1, 1, array( 'files:read', 'files:write' ) ) );
	}

	protected function data( ToolResult $result ): array {
		$this->assertFalse( $result->isError(), $result->text() );

		return json_decode( $result->text(), true );
	}

	protected function read( string $relative ): string {
		return (string) file_get_contents( $this->root . '/' . $relative );
	}

	/**
	 * Leftover temp files anywhere under the root.
	 */
	protected function tempFiles(): array {
		$found = array();
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->root, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( '.tmp' === substr( $file->getFilename(), -4 ) ) {
				$found[] = $file->getPathname();
			}
		}

		return $found;
	}
}
