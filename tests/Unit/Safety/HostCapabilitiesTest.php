<?php
namespace TechThrill\MCP\Tests\Unit\Safety;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\Safety\HostCapabilities;
use TechThrill\MCP\Settings;

class HostCapabilitiesTest extends TestCase {
	/**
	 * Temp directories to remove after each test.
	 *
	 * @var string[]
	 */
	private array $tmp = array();

	protected function tearDown(): void {
		foreach ( $this->tmp as $dir ) {
			$this->removeDir( $dir );
		}
		$this->tmp = array();
	}

	private function tempDir(): string {
		$dir = sys_get_temp_dir() . '/ttm-host-' . bin2hex( random_bytes( 6 ) );
		mkdir( $dir, 0777, true );
		$this->tmp[] = $dir;
		return $dir;
	}

	private function removeDir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			is_dir( $path ) ? $this->removeDir( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	private function host( array $settings, string $uploads, string $path = '', string $disabled = '', string $site = 'example.com', bool $ssl = false ): HostCapabilities {
		return new HostCapabilities( new Settings( $settings ), $uploads, $path, $disabled, $site, $ssl );
	}

	public function test_exec_available_when_not_disabled(): void {
		$this->assertTrue( $this->host( array(), $this->tempDir(), '', 'exec,shell_exec' )->execAvailable() );
	}

	public function test_exec_unavailable_when_disabled(): void {
		$this->assertFalse( $this->host( array(), $this->tempDir(), '', 'exec, PROC_OPEN ,shell_exec' )->execAvailable() );
		$this->assertFalse( $this->host( array(), $this->tempDir(), '', 'exec,proc_open' )->execAvailable() );
	}

	public function test_exec_unavailable_when_any_process_function_is_disabled(): void {
		foreach ( array( 'proc_get_status', 'proc_terminate', 'proc_close' ) as $function ) {
			$this->assertFalse( $this->host( array(), $this->tempDir(), '', 'exec, ' . strtoupper( $function ) )->execAvailable(), $function );
		}
	}

	public function test_settings_path_wins(): void {
		$dir = $this->tempDir();
		$cli = $dir . ( DIRECTORY_SEPARATOR === '\\' ? '/custom-wp.exe' : '/custom-wp' );
		file_put_contents( $cli, '' );
		chmod( $cli, 0755 );
		$bin = $dir . '/bin';
		mkdir( $bin );
		file_put_contents( $bin . '/wp', '' );
		file_put_contents( $bin . '/wp.bat', '' );
		file_put_contents( $bin . '/wp.cmd', '' );
		chmod( $bin . '/wp', 0755 );

		$host = $this->host( array( 'cli_path' => $cli ), $dir, $bin );
		$this->assertSame( array( $cli ), $host->wpBinary() );
	}

	public function test_path_scan_finds_wp(): void {
		$dir = $this->tempDir();
		$bin = $dir . '/bin';
		mkdir( $bin );
		$name = DIRECTORY_SEPARATOR === '\\' ? 'wp.exe' : 'wp';
		file_put_contents( $bin . '/' . $name, '' );
		chmod( $bin . '/' . $name, 0755 );

		$path = $dir . '/empty' . PATH_SEPARATOR . $bin;
		$host = $this->host( array(), $dir, $path );
		$this->assertSame( array( $bin . '/' . $name ), $host->wpBinary() );
	}

	private function skipUnlessWindows(): void {
		if ( DIRECTORY_SEPARATOR !== '\\' ) {
			$this->markTestSkipped( 'Windows PATH resolution only.' );
		}
	}

	public function test_windows_batch_file_alone_is_not_a_binary(): void {
		$this->skipUnlessWindows();
		$dir = $this->tempDir();
		$bin = $dir . '/bin';
		mkdir( $bin );
		file_put_contents( $bin . '/wp', '' );
		file_put_contents( $bin . '/wp.bat', '' );
		file_put_contents( $bin . '/wp.cmd', '' );

		$this->assertNull( $this->host( array(), $dir, $bin )->wpBinary() );
	}

	public function test_windows_batch_file_with_sibling_phar_uses_the_phar(): void {
		$this->skipUnlessWindows();
		$dir = $this->tempDir();
		$bin = $dir . '/bin';
		mkdir( $bin );
		file_put_contents( $bin . '/wp.bat', '' );
		file_put_contents( $bin . '/wp-cli.phar', '' );

		$this->assertSame( array( PHP_BINARY, $bin . '/wp-cli.phar' ), $this->host( array(), $dir, $bin )->wpBinary() );
	}

	public function test_windows_exe_is_used_directly(): void {
		$this->skipUnlessWindows();
		$dir = $this->tempDir();
		$bin = $dir . '/bin';
		mkdir( $bin );
		file_put_contents( $bin . '/wp.exe', '' );
		file_put_contents( $bin . '/wp.bat', '' );

		$this->assertSame( array( $bin . '/wp.exe' ), $this->host( array(), $dir, $bin )->wpBinary() );
	}

	public function test_windows_cli_path_accepts_exe_and_phar_only(): void {
		$this->skipUnlessWindows();
		$dir = $this->tempDir();
		file_put_contents( $dir . '/tool.bat', '' );
		file_put_contents( $dir . '/tool.cmd', '' );
		file_put_contents( $dir . '/tool.exe', '' );
		file_put_contents( $dir . '/tool.phar', '' );

		$this->assertNull( $this->host( array( 'cli_path' => $dir . '/tool.bat' ), $dir, '' )->wpBinary() );
		$this->assertNull( $this->host( array( 'cli_path' => $dir . '/tool.cmd' ), $dir, '' )->wpBinary() );
		$this->assertSame( array( $dir . '/tool.exe' ), $this->host( array( 'cli_path' => $dir . '/tool.exe' ), $dir, '' )->wpBinary() );
		$this->assertSame( array( PHP_BINARY, $dir . '/tool.phar' ), $this->host( array( 'cli_path' => $dir . '/tool.phar' ), $dir, '' )->wpBinary() );
	}

	public function test_windows_batch_cli_path_falls_through_to_path(): void {
		$this->skipUnlessWindows();
		$dir = $this->tempDir();
		$bin = $dir . '/bin';
		mkdir( $bin );
		file_put_contents( $dir . '/custom.bat', '' );
		file_put_contents( $bin . '/wp.exe', '' );

		$this->assertSame( array( $bin . '/wp.exe' ), $this->host( array( 'cli_path' => $dir . '/custom.bat' ), $dir, $bin )->wpBinary() );
	}

	public function test_non_executable_cli_path_falls_through_on_posix(): void {
		if ( DIRECTORY_SEPARATOR === '\\' ) {
			$this->markTestSkipped( 'Executable bit is POSIX only.' );
		}
		$dir = $this->tempDir();
		$bin = $dir . '/bin';
		mkdir( $bin );
		file_put_contents( $bin . '/wp', '' );
		chmod( $bin . '/wp', 0755 );
		$data = $dir . '/data.txt';
		file_put_contents( $data, '' );
		chmod( $data, 0644 );

		$this->assertSame( array( $bin . '/wp' ), $this->host( array( 'cli_path' => $data ), $dir, $bin )->wpBinary() );
	}

	public function test_directory_cli_path_falls_through(): void {
		$dir = $this->tempDir();
		$bin = $dir . '/bin';
		mkdir( $bin );
		$name = DIRECTORY_SEPARATOR === '\\' ? 'wp.exe' : 'wp';
		file_put_contents( $bin . '/' . $name, '' );
		chmod( $bin . '/' . $name, 0755 );

		$this->assertSame( array( $bin . '/' . $name ), $this->host( array( 'cli_path' => $dir ), $dir, $bin )->wpBinary() );
	}

	public function test_empty_uploads_dir_disables_phar(): void {
		$host = $this->host( array(), '', $this->tempDir() );
		$this->assertSame( '', $host->pharPath() );
		$this->assertNull( $host->wpBinary() );
	}

	public function test_phar_fallback(): void {
		$uploads = $this->tempDir();
		mkdir( $uploads . '/techthrill-mcp' );
		file_put_contents( $uploads . '/techthrill-mcp/wp-cli.phar', '' );

		$host = $this->host( array(), $uploads . '/' );
		$this->assertSame( $uploads . '/techthrill-mcp/wp-cli.phar', $host->pharPath() );
		$this->assertSame( array( PHP_BINARY, $host->pharPath() ), $host->wpBinary() );
	}

	public function test_no_binary_returns_null(): void {
		$host = $this->host( array(), $this->tempDir(), $this->tempDir() );
		$this->assertNull( $host->wpBinary() );
		$this->assertNull( $host->toArray()['wp_binary'] );
	}

	public function test_local_host_detection(): void {
		$uploads = $this->tempDir();
		$this->assertTrue( $this->host( array(), $uploads, '', '', 'example.test' )->isLocalHost() );
		$this->assertTrue( $this->host( array(), $uploads, '', '', 'localhost' )->isLocalHost() );
		$this->assertTrue( $this->host( array(), $uploads, '', '', 'site.local' )->isLocalHost() );
		$this->assertTrue( $this->host( array(), $uploads, '', '', 'App.LocalHost' )->isLocalHost() );
		$this->assertTrue( $this->host( array(), $uploads, '', '', '127.0.0.1' )->isLocalHost() );
		$this->assertFalse( $this->host( array(), $uploads, '', '', 'example.com' )->isLocalHost() );
	}

	public function test_to_array_shape(): void {
		$uploads = $this->tempDir();
		mkdir( $uploads . '/techthrill-mcp' );
		file_put_contents( $uploads . '/techthrill-mcp/wp-cli.phar', '' );

		$info = $this->host( array(), $uploads, '', 'exec', 'example.com', true )->toArray();
		$this->assertSame(
			array(
				'exec_available' => true,
				'wp_binary'      => PHP_BINARY . ' ' . $uploads . '/techthrill-mcp/wp-cli.phar',
				'https'          => true,
				'local_host'     => false,
			),
			$info
		);
	}
}
