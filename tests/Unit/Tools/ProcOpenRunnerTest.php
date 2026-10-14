<?php
namespace TechThrill\MCP\Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\Tools\ProcOpenRunner;

class ProcOpenRunnerTest extends TestCase {

	protected function setUp(): void {
		if ( ! function_exists( 'proc_open' ) ) {
			$this->markTestSkipped( 'proc_open is not available.' );
		}
	}

	private function env(): array {
		$env = array( 'PATH' => (string) getenv( 'PATH' ) );
		foreach ( array( 'SYSTEMROOT', 'TEMP' ) as $key ) {
			if ( false !== getenv( $key ) ) {
				$env[ $key ] = (string) getenv( $key );
			}
		}
		return $env;
	}

	public function test_captures_output_and_exit_code(): void {
		$runner = new ProcOpenRunner();
		$result = $runner->run( array( PHP_BINARY, '-r', 'echo "hi"; fwrite(STDERR, "err"); exit(3);' ), sys_get_temp_dir(), $this->env(), 20 );

		$this->assertSame( 'hi', $result->stdout );
		$this->assertSame( 'err', $result->stderr );
		$this->assertSame( 3, $result->exitCode );
		$this->assertFalse( $result->timedOut );
		$this->assertFalse( $result->stdoutTruncated );
		$this->assertFalse( $result->stderrTruncated );
	}

	public function test_timeout_kills_the_process(): void {
		$runner  = new ProcOpenRunner();
		$started = microtime( true );
		$result  = $runner->run( array( PHP_BINARY, '-r', 'sleep(10);' ), sys_get_temp_dir(), $this->env(), 1 );
		$elapsed = microtime( true ) - $started;

		$this->assertTrue( $result->timedOut );
		$this->assertSame( -1, $result->exitCode );
		$this->assertLessThan( 6, $elapsed );
	}

	public function test_sustained_writer_cannot_extend_the_timeout(): void {
		$runner  = new ProcOpenRunner();
		$started = microtime( true );
		$result  = $runner->run( array( PHP_BINARY, '-r', 'while (true) { echo str_repeat("a", 4096); }' ), sys_get_temp_dir(), $this->env(), 1 );
		$elapsed = microtime( true ) - $started;

		$this->assertTrue( $result->timedOut );
		$this->assertLessThan( 5, $elapsed );
		$this->assertLessThanOrEqual( ProcOpenRunner::OUTPUT_CAP, strlen( $result->stdout ) );
	}

	public function test_setsid_prefixes_argv_when_available(): void {
		$this->assertSame(
			array( '/usr/bin/setsid', '/usr/bin/wp', 'plugin', 'list' ),
			ProcOpenRunner::withSetsid( array( '/usr/bin/wp', 'plugin', 'list' ), '/usr/bin/setsid' )
		);
		$this->assertSame( array( 'a', 'b' ), ProcOpenRunner::withSetsid( array( 'a', 'b' ), null ) );
	}

	public function test_batch_files_are_refused_on_windows_without_spawning(): void {
		if ( '\\' !== DIRECTORY_SEPARATOR ) {
			$this->markTestSkipped( 'Windows only.' );
		}
		$runner = new ProcOpenRunner();
		foreach ( array( 'C:/x/wp.bat', 'C:/x/WP.CMD' ) as $batch ) {
			$result = $runner->run( array( $batch, 'core', 'version' ), sys_get_temp_dir(), $this->env(), 5 );
			$this->assertSame( -1, $result->exitCode );
			$this->assertSame( 'Refusing to launch a batch file.', $result->stderr );
			$this->assertFalse( $result->timedOut );
		}
	}

	public function test_output_is_capped(): void {
		$runner = new ProcOpenRunner();
		$result = $runner->run( array( PHP_BINARY, '-r', 'echo str_repeat("a", 300000);' ), sys_get_temp_dir(), $this->env(), 20 );

		$this->assertSame( 204800, strlen( $result->stdout ) );
		$this->assertTrue( $result->stdoutTruncated );
		$this->assertFalse( $result->stderrTruncated );
		$this->assertSame( 0, $result->exitCode );
	}
}
