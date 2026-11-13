<?php
namespace TechThrill\MCP\Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\Safety\PathJail;
use TechThrill\MCP\Safety\SafeMode;
use TechThrill\MCP\Tools\AbstractFileTool;
use TechThrill\MCP\Tools\ToolContext;
use TechThrill\MCP\Tools\ToolResult;

/**
 * Hosts that refuse a rename onto .php files must still get the file written (in place), and a total refusal must
 * come back as a clear message with no temp file left behind.
 */
class AtomicWriteFallbackTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ttm-atomic-' . uniqid( '', true );
		mkdir( $this->dir );
		$this->dir = realpath( $this->dir );
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->dir . '/{,.}*', GLOB_BRACE ) as $item ) {
			if ( is_file( $item ) ) {
				unlink( $item );
			} elseif ( is_dir( $item ) && ! in_array( basename( $item ), array( '.', '..' ), true ) ) {
				@rmdir( $item );
			}
		}
		@rmdir( $this->dir );
	}

	private function tool( bool $renameWorks ) {
		return new class( new PathJail( $this->dir ), new SafeMode( false, 'x' ), $renameWorks ) extends AbstractFileTool {
			private bool $renameWorks;

			public function __construct( PathJail $jail, SafeMode $safe, bool $renameWorks ) {
				parent::__construct( $jail, $safe );
				$this->renameWorks = $renameWorks;
			}

			protected function moveIntoPlace( string $temp, string $target ): bool {
				return $this->renameWorks && parent::moveIntoPlace( $temp, $target );
			}

			public function write( string $target, string $content ): ?string {
				return $this->atomicWrite( $target, $content );
			}

			public function copyFile( string $source, string $target ): ?string {
				return $this->atomicCopy( $source, $target );
			}

			public function name(): string {
				return 't';
			}

			public function description(): string {
				return 't';
			}

			public function inputSchema(): array {
				return array();
			}

			public function scope(): ?string {
				return null;
			}

			public function execute( array $args, ToolContext $ctx ): ToolResult {
				return ToolResult::error( 'unused' );
			}
		};
	}

	private function leftovers(): array {
		return array_values( array_filter( (array) glob( $this->dir . '/.*.tmp' ) ) );
	}

	public function test_a_normal_write_still_renames_into_place(): void {
		$this->assertNull( $this->tool( true )->write( $this->dir . '/a.php', "<?php\n" ) );
		$this->assertSame( "<?php\n", file_get_contents( $this->dir . '/a.php' ) );
		$this->assertSame( array(), $this->leftovers() );
	}

	public function test_a_blocked_rename_falls_back_to_writing_in_place(): void {
		file_put_contents( $this->dir . '/index.php', 'old' );
		chmod( $this->dir . '/index.php', 0640 );
		$this->assertNull( $this->tool( false )->write( $this->dir . '/index.php', "<?php\n// new\n" ) );
		$this->assertSame( "<?php\n// new\n", file_get_contents( $this->dir . '/index.php' ) );
		$this->assertSame( 0640, fileperms( $this->dir . '/index.php' ) & 0777, 'The existing permissions are kept.' );
		$this->assertSame( array(), $this->leftovers() );

		$this->assertNull( $this->tool( false )->write( $this->dir . '/brand-new.php', "<?php\n" ) );
		$this->assertSame( "<?php\n", file_get_contents( $this->dir . '/brand-new.php' ) );
		$this->assertSame( array(), $this->leftovers() );
	}

	public function test_a_blocked_rename_falls_back_for_copies_too(): void {
		file_put_contents( $this->dir . '/src.php', "<?php\n// src\n" );
		$this->assertNull( $this->tool( false )->copyFile( $this->dir . '/src.php', $this->dir . '/dst.php' ) );
		$this->assertSame( "<?php\n// src\n", file_get_contents( $this->dir . '/dst.php' ) );
		$this->assertSame( array(), $this->leftovers() );
	}

	public function test_when_nothing_can_write_the_message_explains_and_no_temp_file_is_left(): void {
		mkdir( $this->dir . '/folder-in-the-way' );
		$error = $this->tool( false )->write( $this->dir . '/folder-in-the-way', 'x' );
		$this->assertNotNull( $error );
		$this->assertStringStartsWith( 'Could not replace the file.', $error );
		$this->assertStringContainsString( 'refused both a rename and a direct write', $error );
		$this->assertSame( array(), $this->leftovers() );
	}
}
