<?php
namespace TechThrill\MCP\Tests\Unit\Safety;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\Safety\PathException;
use TechThrill\MCP\Safety\PathJail;

class PathJailTest extends TestCase {
	/** @var string Real path of the per-test jail root. */
	private $root;

	/** @var string[] Directories created for the test, removed in tearDown. */
	private $extra = array();

	protected function setUp(): void {
		$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ttmcp-jail-' . uniqid( '', true );
		mkdir( $base . DIRECTORY_SEPARATOR . 'site', 0777, true );
		$this->root    = realpath( $base . DIRECTORY_SEPARATOR . 'site' );
		$this->extra[] = $base;
	}

	protected function tearDown(): void {
		foreach ( $this->extra as $dir ) {
			$this->removeTree( $dir );
		}
	}

	private function removeTree( string $path ): void {
		if ( is_link( $path ) ) {
			if ( ! @unlink( $path ) ) {
				@rmdir( $path );
			}
			return;
		}
		if ( is_dir( $path ) ) {
			foreach ( scandir( $path ) as $entry ) {
				if ( '.' !== $entry && '..' !== $entry ) {
					$this->removeTree( $path . DIRECTORY_SEPARATOR . $entry );
				}
			}
			@rmdir( $path );
			return;
		}
		@unlink( $path );
	}

	private function jail(): PathJail {
		return new PathJail( $this->root );
	}

	private function makeSymlinkOrSkip( string $target, string $link ): void {
		$ok = false;
		try {
			$ok = @symlink( $target, $link );
		} catch ( \Throwable $e ) {
			$ok = false;
		}
		if ( ! $ok ) {
			$this->markTestSkipped( 'Symlinks cannot be created in this environment.' );
		}
	}

	private function outsideDir(): string {
		$dir = dirname( $this->root ) . DIRECTORY_SEPARATOR . 'outside';
		mkdir( $dir );
		file_put_contents( $dir . DIRECTORY_SEPARATOR . 'x', 'secret' );
		return $dir;
	}

	public function test_normalizes_separators_and_dot_segments(): void {
		$jail = $this->jail();
		$this->assertSame( 'wp-content/themes', $jail->normalize( '\\wp-content\\.\\themes\\' ) );
		$this->assertSame( '.', $jail->normalize( '/' ) );
		$this->assertSame( '.', $jail->normalize( '' ) );
		$this->assertSame( '.', $jail->normalize( '.' ) );
		$this->assertSame( 'a/b', $jail->normalize( 'a//b/./' ) );
	}

	/**
	 * @dataProvider traversalProvider
	 */
	public function test_rejects_dotdot_anywhere( string $input ): void {
		$this->expectException( PathException::class );
		$this->jail()->normalize( $input );
	}

	public function traversalProvider(): array {
		return array(
			'middle'            => array( 'wp-content/../wp-config.php' ),
			'backslash'         => array( '..\\x' ),
			'encoded'           => array( '%2e%2e/x' ),
			'encoded slash'     => array( '..%2fx' ),
			'encoded backslash' => array( '..%5cx' ),
			'mixed case enc'    => array( '%2E%2e/x' ),
			'trailing'          => array( 'wp-content/..' ),
			'dot then dotdot'   => array( 'wp-content/./../x' ),
			'bare'              => array( '..' ),
			'leading slash'     => array( '/../x' ),
			'many dots'         => array( '....//x' ),
			'three dots'        => array( '.../x' ),
			'dotdot space'      => array( '.. /x' ),
		);
	}

	/**
	 * @dataProvider absoluteProvider
	 */
	public function test_absolute_input_rejected( string $input ): void {
		$this->expectException( PathException::class );
		$this->jail()->normalize( $input );
	}

	public function absoluteProvider(): array {
		return array(
			'windows drive'    => array( 'C:/Windows/x' ),
			'windows drive bs' => array( 'C:\\Windows\\x' ),
			'drive no slash'   => array( 'C:' ),
			'drive relative'   => array( 'c:foo' ),
			'slash drive'      => array( '/C:/x' ),
			'posix absolute'   => array( '//etc/passwd' ),
			'unc'              => array( '\\\\server\\share' ),
			'encoded double'   => array( '%2f%2fetc' ),
			'nul'              => array( "a\0b" ),
			'encoded nul'      => array( 'a%00b' ),
		);
	}

	public function test_single_leading_slash_is_relative_to_root(): void {
		// '/etc/passwd' is treated as root-relative; it cannot escape because it is joined to the root.
		$this->assertSame( 'etc/passwd', $this->jail()->normalize( '/etc/passwd' ) );
		$this->expectException( PathException::class );
		$this->jail()->resolve( '/etc/passwd' ); // Does not exist under the root.
	}

	public function test_resolve_existing_file(): void {
		mkdir( $this->root . DIRECTORY_SEPARATOR . 'wp-content' );
		file_put_contents( $this->root . DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR . 'a.php', 'x' );
		$jp = $this->jail()->resolve( 'wp-content/a.php' );
		$this->assertSame( $this->root . DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR . 'a.php', $jp->absolute );
		$this->assertSame( 'wp-content/a.php', $jp->relative );
	}

	public function test_resolve_root_itself(): void {
		$jp = $this->jail()->resolve( '/' );
		$this->assertSame( $this->root, $jp->absolute );
		$this->assertSame( '.', $jp->relative );
	}

	public function test_root_with_trailing_slash(): void {
		mkdir( $this->root . DIRECTORY_SEPARATOR . 'd' );
		$jail = new PathJail( $this->root . DIRECTORY_SEPARATOR );
		$this->assertSame( 'd', $jail->resolve( 'd' )->relative );
		$this->assertSame( '.', $jail->resolve( '.' )->relative );
	}

	public function test_missing_root_throws(): void {
		$this->expectException( PathException::class );
		new PathJail( $this->root . DIRECTORY_SEPARATOR . 'nope' );
	}

	public function test_resolve_missing_file_throws(): void {
		$this->expectException( PathException::class );
		$this->jail()->resolve( 'missing.php' );
	}

	public function test_overlong_path_rejected(): void {
		$this->expectException( PathException::class );
		$this->jail()->normalize( str_repeat( 'a/', 3000 ) );
	}

	public function test_symlink_escape_rejected(): void {
		$outside = $this->outsideDir();
		$this->makeSymlinkOrSkip( $outside, $this->root . DIRECTORY_SEPARATOR . 'link' );
		$this->expectException( PathException::class );
		$this->jail()->resolve( 'link/x' );
	}

	public function test_symlink_to_sibling_with_matching_prefix_rejected(): void {
		$sibling         = $this->root . '2';
		$this->extra[]   = $sibling;
		mkdir( $sibling );
		file_put_contents( $sibling . DIRECTORY_SEPARATOR . 'x', 'secret' );
		$this->makeSymlinkOrSkip( $sibling, $this->root . DIRECTORY_SEPARATOR . 'sib' );
		$this->expectException( PathException::class );
		$this->jail()->resolve( 'sib/x' );
	}

	public function test_symlink_inside_root_is_allowed(): void {
		mkdir( $this->root . DIRECTORY_SEPARATOR . 'real' );
		file_put_contents( $this->root . DIRECTORY_SEPARATOR . 'real' . DIRECTORY_SEPARATOR . 'f', 'x' );
		$this->makeSymlinkOrSkip( $this->root . DIRECTORY_SEPARATOR . 'real', $this->root . DIRECTORY_SEPARATOR . 'alias' );
		$this->assertSame( 'real/f', $this->jail()->resolve( 'alias/f' )->relative );
	}

	public function test_root_that_is_a_symlink_works(): void {
		mkdir( $this->root . DIRECTORY_SEPARATOR . 'd' );
		$link_root = dirname( $this->root ) . DIRECTORY_SEPARATOR . 'rootlink';
		$this->makeSymlinkOrSkip( $this->root, $link_root );
		$jail = new PathJail( $link_root );
		$this->assertSame( $this->root . DIRECTORY_SEPARATOR . 'd', $jail->resolve( 'd' )->absolute );
	}

	public function test_resolve_for_create_uses_ancestor(): void {
		mkdir( $this->root . DIRECTORY_SEPARATOR . 'wp-content' );
		$jp       = $this->jail()->resolveForCreate( 'wp-content/new/dir/file.php' );
		$expected = $this->root . DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR . 'new' . DIRECTORY_SEPARATOR . 'dir' . DIRECTORY_SEPARATOR . 'file.php';
		$this->assertSame( $expected, $jp->absolute );
		$this->assertSame( 'wp-content/new/dir/file.php', $jp->relative );
		$this->assertFalse( file_exists( $this->root . DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR . 'new' ), 'must not touch the filesystem' );
	}

	public function test_resolve_for_create_existing_file_passes_through(): void {
		file_put_contents( $this->root . DIRECTORY_SEPARATOR . 'a.txt', 'x' );
		$jp = $this->jail()->resolveForCreate( 'a.txt' );
		$this->assertSame( $this->root . DIRECTORY_SEPARATOR . 'a.txt', $jp->absolute );
		$this->assertSame( 'a.txt', $jp->relative );
	}

	public function test_resolve_for_create_rejects_root(): void {
		$this->expectException( PathException::class );
		$this->jail()->resolveForCreate( '/' );
	}

	public function test_resolve_for_create_rejects_traversal_and_absolute(): void {
		foreach ( array( '../x', 'a/../../x', 'C:/x', '\\\\srv\\share\\x' ) as $input ) {
			try {
				$this->jail()->resolveForCreate( $input );
				$this->fail( 'Expected PathException for ' . $input );
			} catch ( PathException $e ) {
				$this->assertInstanceOf( PathException::class, $e );
			}
		}
	}

	public function test_resolve_for_create_ancestor_must_be_directory(): void {
		file_put_contents( $this->root . DIRECTORY_SEPARATOR . 'file.txt', 'x' );
		$this->expectException( PathException::class );
		$this->jail()->resolveForCreate( 'file.txt/sub/new.php' );
	}

	public function test_resolve_for_create_symlink_escape_rejected(): void {
		$outside = $this->outsideDir();
		$this->makeSymlinkOrSkip( $outside, $this->root . DIRECTORY_SEPARATOR . 'link' );
		$this->expectException( PathException::class );
		$this->jail()->resolveForCreate( 'link/new/file.php' );
	}

	public function test_resolve_for_create_existing_file_via_escaping_symlink_rejected(): void {
		$outside = $this->outsideDir();
		$this->makeSymlinkOrSkip( $outside . DIRECTORY_SEPARATOR . 'x', $this->root . DIRECTORY_SEPARATOR . 'flink' );
		$this->expectException( PathException::class );
		$this->jail()->resolveForCreate( 'flink' );
	}

	public function test_resolve_for_create_dangling_symlink_rejected(): void {
		$outside = dirname( $this->root ) . DIRECTORY_SEPARATOR . 'not-yet';
		$this->makeSymlinkOrSkip( $outside . DIRECTORY_SEPARATOR . 'target.php', $this->root . DIRECTORY_SEPARATOR . 'dangling' );
		$this->expectException( PathException::class );
		$this->jail()->resolveForCreate( 'dangling' );
	}
}
