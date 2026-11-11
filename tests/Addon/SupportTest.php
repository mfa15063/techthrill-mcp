<?php
namespace TechThrill\MCP\Tests\Addon;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\Files\Requirements;
use TechThrill\MCP\Files\Support\AllowedRoots;
use TechThrill\MCP\Files\Support\BackupStore;
use TechThrill\MCP\Files\Support\FileRules;
use TechThrill\MCP\Files\Support\PhpSyntax;

class SupportTest extends TestCase {

	// Requirements.

	public function test_requirements_statuses(): void {
		$this->assertSame( 'missing', Requirements::status( false, false, null ) );
		$this->assertSame( 'inactive', Requirements::status( true, false, null ) );
		$this->assertSame( 'too_old', Requirements::status( true, true, '1.0.0' ) );
		$this->assertSame( 'too_old', Requirements::status( true, true, null ) );
		$this->assertSame( 'too_old', Requirements::status( true, true, '' ) );
		$this->assertSame( 'ok', Requirements::status( true, true, Requirements::MIN_VERSION ) );
		$this->assertSame( 'ok', Requirements::status( true, true, '2.0.0' ) );
		$this->assertSame( 'ok', Requirements::status( false, true, '1.5.1' ), 'A loaded main plugin counts as installed.' );
	}

	public function test_requirements_messages_say_what_to_do(): void {
		$this->assertSame( '', Requirements::message( Requirements::OK ) );
		$this->assertStringContainsString( 'not installed. Install and activate TechThrill MCP', Requirements::message( Requirements::MISSING ) );
		$this->assertStringContainsString( 'installed but not active. Activate TechThrill MCP first', Requirements::message( Requirements::INACTIVE ) );
		$old = Requirements::message( Requirements::TOO_OLD, '1.2.0' );
		$this->assertStringContainsString( 'version ' . Requirements::MIN_VERSION . ' or newer', $old );
		$this->assertStringContainsString( 'this site has 1.2.0', $old );
		$this->assertSame( '', Requirements::message( 'nonsense' ) );
	}

	// PHP syntax.

	public function test_php_syntax_check(): void {
		$this->assertNull( PhpSyntax::check( "<?php\nfunction a() { return 1; }\n" ) );
		$this->assertNull( PhpSyntax::check( "<h1>Plain HTML</h1>\n<?php echo 1; ?>\n<p>more</p>" ) );
		$this->assertNull( PhpSyntax::check( '' ) );
		$error = PhpSyntax::check( "<?php\nfunction a() {\n  return 1;\n" );
		$this->assertNotNull( $error );
		$this->assertStringContainsString( 'line', $error );
		$this->assertNotNull( PhpSyntax::check( "<?php\n\$a = ;\n" ) );
		$this->assertNotNull( PhpSyntax::check( "<?php\nif (true) {\n" ) );
	}

	public function test_php_syntax_check_never_runs_the_code(): void {
		$marker = sys_get_temp_dir() . '/ttm-syntax-ran-' . uniqid();
		PhpSyntax::check( "<?php\nfile_put_contents('" . $marker . "', 'ran');\n" );
		$this->assertFileDoesNotExist( $marker );
	}

	// File rules.

	public function test_code_and_asset_names(): void {
		foreach ( array( 'style.css', 'a.PHP', 'index.html', 'app.js', 'data.json', 'readme.md', 'my file.css' ) as $name ) {
			$this->assertTrue( FileRules::isCodeFile( $name ), $name );
		}
		foreach ( array( 'screenshot.png', 'logo.JPG', 'font.woff2', 'icon.ico' ) as $name ) {
			$this->assertTrue( FileRules::isAssetFile( $name ), $name );
		}
		$this->assertFalse( FileRules::isCodeFile( 'photo.png' ) );
		$this->assertFalse( FileRules::isAssetFile( 'style.css' ) );
	}

	public function test_dangerous_names_are_refused_everywhere(): void {
		foreach ( array( '.htaccess', '.user.ini', '.env', 'shell.php.png', 'shell.phtml', 'x.phar', 'a.php5', 'run.sh', 'evil.pHp.jpg', 'a.php.css', 'noext', '', 'a/b.css', "a\0.css", str_repeat( 'a', 201 ) . '.css' ) as $name ) {
			$this->assertFalse( FileRules::isCodeFile( $name ) || FileRules::isAssetFile( $name ), json_encode( $name ) );
		}
		$this->assertTrue( FileRules::isPhp( 'x.php' ) );
		$this->assertFalse( FileRules::isPhp( 'x.phtml' ) );
		$this->assertSame( 'png', FileRules::extension( 'dir/Shot.PNG' ) );
		$this->assertSame( '', FileRules::extension( 'noext' ) );
	}

	public function test_folder_names(): void {
		foreach ( array( 'my-child', 'child_2', 'Theme Name', 'v1.2' ) as $name ) {
			$this->assertTrue( FileRules::isSafeDirName( $name ), $name );
		}
		foreach ( array( '.git', '..', '', '-x', 'a/b' ) as $name ) {
			$this->assertFalse( FileRules::isSafeDirName( $name ), json_encode( $name ) );
		}
	}

	// Allowed roots.

	public function test_allowed_roots_are_strict(): void {
		$roots = new AllowedRoots( array( 'wp-content/themes', 'wp-content/plugins/', '\\wp-content\\x' ) );
		$this->assertTrue( $roots->contains( 'wp-content/themes/child/style.css' ) );
		$this->assertTrue( $roots->contains( 'WP-CONTENT/Plugins/foo' ) );
		$this->assertFalse( $roots->contains( 'wp-content/themes' ), 'The root itself is not allowed.' );
		$this->assertFalse( $roots->contains( 'wp-content/themes/' ) );
		$this->assertFalse( $roots->contains( 'wp-content/themes-evil/a.css' ) );
		$this->assertFalse( $roots->contains( 'wp-config.php' ) );
		$this->assertFalse( $roots->contains( 'wp-content/uploads/a.png' ) );
		$this->assertFalse( $roots->contains( '' ) );
		$this->assertSame( 'child', $roots->packageOf( 'wp-content/themes/child/inc/a.php' ) );
		$this->assertSame( 1, $roots->depth( 'wp-content/themes/child' ) );
		$this->assertSame( 2, $roots->depth( 'wp-content/themes/child/style.css' ) );
		$this->assertSame( 4, $roots->depth( 'wp-content/plugins/foo/inc/sub/a.php' ) );
		$this->assertNull( $roots->depth( 'wp-content/uploads/a.png' ) );
		$this->assertNull( $roots->packageOf( 'wp-content/uploads/x' ) );
		$this->assertSame( array( 'wp-content/themes', 'wp-content/plugins', 'wp-content/x' ), $roots->roots() );
	}

	// Backups.

	public function test_backup_save_list_and_path(): void {
		$dir = sys_get_temp_dir() . '/ttm-bk-' . uniqid();
		$src = sys_get_temp_dir() . '/ttm-bk-src-' . uniqid();
		file_put_contents( $src, 'v1' );
		$now   = 1800000000;
		$store = new BackupStore( $dir, function () use ( &$now ) {
			return $now;
		} );

		$id1 = $store->save( 'wp-content/themes/a/style.css', $src );
		$this->assertMatchesRegularExpression( '/^[0-9]{8}-[0-9]{6}-[0-9a-f]{6}$/', $id1 );
		file_put_contents( $src, 'v2' );
		$now += 5;
		$id2 = $store->save( 'wp-content/themes/a/style.css', $src );

		$list = $store->listFor( 'wp-content/themes/a/style.css' );
		$this->assertSame( array( $id2, $id1 ), array_column( $list, 'id' ), 'Newest first.' );
		$this->assertSame( 'v1', file_get_contents( $store->pathFor( 'wp-content/themes/a/style.css', $id1 ) ) );
		$this->assertSame( 'v2', file_get_contents( $store->pathFor( 'wp-content/themes/a/style.css' ) ), 'No id means the newest.' );
		$this->assertSame( array(), $store->listFor( 'wp-content/themes/other.css' ) );
		$this->assertNull( $store->pathFor( 'wp-content/themes/a/style.css', '../../etc/passwd' ) );
		$this->assertNull( $store->pathFor( 'wp-content/themes/a/style.css', '20200101-000000-aaaaaa' ) );

		$this->assertFileExists( $dir . '/.htaccess' );
		$this->assertFileExists( $dir . '/index.php' );
		$this->assertStringContainsString( 'denied', (string) file_get_contents( $dir . '/.htaccess' ) );

		$store->deleteAll();
		$this->assertDirectoryDoesNotExist( $dir );
		unlink( $src );
	}

	public function test_backup_keeps_only_the_newest_twenty_and_skips_big_or_missing_files(): void {
		$dir = sys_get_temp_dir() . '/ttm-bk-' . uniqid();
		$src = sys_get_temp_dir() . '/ttm-bk-src-' . uniqid();
		file_put_contents( $src, 'x' );
		$now   = 1800000000;
		$store = new BackupStore( $dir, function () use ( &$now ) {
			return ++$now;
		} );

		for ( $i = 0; $i < BackupStore::KEEP + 5; $i++ ) {
			$store->save( 'wp-content/themes/a/a.css', $src );
		}
		$this->assertCount( BackupStore::KEEP, $store->listFor( 'wp-content/themes/a/a.css' ) );

		$this->assertNull( $store->save( 'wp-content/themes/a/a.css', $src . '-missing' ) );
		$fh = fopen( $src, 'wb' );
		ftruncate( $fh, BackupStore::MAX_BYTES + 1 );
		fclose( $fh );
		$this->assertNull( $store->save( 'wp-content/themes/a/big.css', $src ) );

		$store->deleteAll();
		unlink( $src );
	}
}
