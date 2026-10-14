<?php
namespace TechThrill\MCP\Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\Safety\CommandParser;
use TechThrill\MCP\Safety\HostCapabilities;
use TechThrill\MCP\Safety\SafeMode;
use TechThrill\MCP\Settings;
use TechThrill\MCP\Tests\Support\FakeProcessRunner;
use TechThrill\MCP\Tools\ProcessResult;
use TechThrill\MCP\Tools\ToolContext;
use TechThrill\MCP\Tools\WpCli;

class WpCliTest extends TestCase {

	private string $bin_dir;

	protected function setUp(): void {
		$this->bin_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ttm-wp-' . uniqid( '', true );
		mkdir( $this->bin_dir, 0777, true );
		$name = '\\' === DIRECTORY_SEPARATOR ? 'wp.bat' : 'wp';
		file_put_contents( $this->bin_dir . DIRECTORY_SEPARATOR . $name, "#!/bin/sh\nexit 0\n" );
		chmod( $this->bin_dir . DIRECTORY_SEPARATOR . $name, 0755 );
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->bin_dir . DIRECTORY_SEPARATOR . '*' ) as $file ) {
			@unlink( $file );
		}
		@rmdir( $this->bin_dir );
	}

	private function host( ?string $path_env = null, string $disabled = '' ): HostCapabilities {
		return new HostCapabilities( new Settings(), '', null === $path_env ? $this->bin_dir : $path_env, $disabled );
	}

	private function tool( FakeProcessRunner $runner, bool $safe = false, ?HostCapabilities $host = null, ?Settings $settings = null ): WpCli {
		return new WpCli(
			new CommandParser(),
			new SafeMode( $safe, 'wp-content/plugins/techthrill-mcp' ),
			$host ?? $this->host(),
			$runner,
			$settings ?? new Settings(),
			'/cache/dir'
		);
	}

	private function ctx(): ToolContext {
		return new ToolContext( 1, 7, array( 'wp-cli' ) );
	}

	public function test_name_scope_and_schema(): void {
		$tool   = $this->tool( new FakeProcessRunner() );
		$schema = $tool->inputSchema();
		$this->assertSame( 'wp_cli', $tool->name() );
		$this->assertSame( 'wp-cli', $tool->scope() );
		$this->assertSame( array( 'command' ), $schema['required'] );
		$this->assertSame( 5, $schema['properties']['timeout']['minimum'] );
		$this->assertSame( 300, $schema['properties']['timeout']['maximum'] );
		$this->assertSame( 60, $schema['properties']['timeout']['default'] );
	}

	public function test_argv_assembled_with_forced_flags(): void {
		$runner = new FakeProcessRunner( new ProcessResult( 0, 'ok', '', 5, false ) );
		$host   = $this->host();
		$result = $this->tool( $runner, false, $host )->execute( array( 'command' => 'plugin list --url=x --format=json' ), $this->ctx() );

		$this->assertFalse( $result->isError(), $result->text() );
		$expected = array_merge(
			$host->wpBinary(),
			array( 'plugin', 'list', '--format=json', '--path=' . rtrim( wp_normalize_path( ABSPATH ), '/' ), '--user=7', '--no-color', '--skip-packages' )
		);
		$this->assertSame( $expected, $runner->argv );
		$this->assertSame( ABSPATH, $runner->cwd );
		$this->assertSame( 60, $runner->timeout );

		$required = array( 'PATH', 'HOME', 'WP_CLI_DISABLE_AUTO_CHECK_UPDATE', 'WP_CLI_CACHE_DIR' );
		$allowed  = $required;
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$allowed = array_merge( $allowed, array( 'SYSTEMROOT', 'TEMP' ) );
		}
		$this->assertSame( array(), array_diff( array_keys( $runner->env ), $allowed ) );
		foreach ( $required as $key ) {
			$this->assertArrayHasKey( $key, $runner->env );
		}
		$this->assertSame( '1', $runner->env['WP_CLI_DISABLE_AUTO_CHECK_UPDATE'] );
		$this->assertSame( '/cache/dir', $runner->env['WP_CLI_CACHE_DIR'] );
	}

	public function test_timeout_argument_is_passed_to_runner(): void {
		$runner = new FakeProcessRunner();
		$this->tool( $runner )->execute( array( 'command' => 'core version', 'timeout' => 120 ), $this->ctx() );
		$this->assertSame( 120, $runner->timeout );
	}

	public function test_always_blocked(): void {
		$runner = new FakeProcessRunner();
		$result = $this->tool( $runner )->execute( array( 'command' => 'eval "1"' ), $this->ctx() );
		$this->assertTrue( $result->isError() );
		$this->assertStringContainsString( 'eval', $result->text() );
		$this->assertStringContainsString( 'is not available through TechThrill MCP.', $result->text() );
		$this->assertSame( 0, $runner->calls );
	}

	public function test_safe_mode_blocked(): void {
		$runner = new FakeProcessRunner();
		$result = $this->tool( $runner, true )->execute( array( 'command' => 'db reset --yes' ), $this->ctx() );
		$this->assertTrue( $result->isError() );
		$this->assertStringContainsString( 'Refused by safe mode:', $result->text() );
		$this->assertSame( 0, $runner->calls );
	}

	public function test_exec_unavailable_message(): void {
		$runner = new FakeProcessRunner();
		$result = $this->tool( $runner, false, $this->host( null, 'proc_open' ) )->execute( array( 'command' => 'core version' ), $this->ctx() );
		$this->assertTrue( $result->isError() );
		$this->assertSame( 'This host does not allow PHP to start processes (proc_open is disabled), so WP-CLI cannot run. Database and file tools still work.', $result->text() );
		$this->assertSame( 0, $runner->calls );
	}

	public function test_no_binary_message(): void {
		$runner = new FakeProcessRunner();
		$result = $this->tool( $runner, false, $this->host( '' ) )->execute( array( 'command' => 'core version' ), $this->ctx() );
		$this->assertTrue( $result->isError() );
		$this->assertSame( 'WP-CLI was not found on this host. The site administrator can install it from TechThrill MCP → Connect.', $result->text() );
		$this->assertSame( 0, $runner->calls );
	}

	public function test_output_shape_and_timeout_flag(): void {
		$runner = new FakeProcessRunner( new ProcessResult( -1, 'out', 'err', 1234, true, true, false ) );
		$result = $this->tool( $runner )->execute( array( 'command' => 'core version' ), $this->ctx() );
		$this->assertFalse( $result->isError() );
		$data = json_decode( $result->text(), true );
		$this->assertSame(
			array(
				'exit_code'        => -1,
				'stdout'           => 'out',
				'stderr'           => 'err',
				'duration_ms'      => 1234,
				'timed_out'        => true,
				'stdout_truncated' => true,
				'stderr_truncated' => false,
			),
			$data
		);
	}

	public function test_unterminated_quote_is_tool_error_not_exception(): void {
		$runner = new FakeProcessRunner();
		$result = $this->tool( $runner )->execute( array( 'command' => 'option get "oops' ), $this->ctx() );
		$this->assertTrue( $result->isError() );
		$this->assertSame( 0, $runner->calls );
	}

	public function test_empty_command_is_tool_error(): void {
		$runner = new FakeProcessRunner();
		$result = $this->tool( $runner )->execute( array( 'command' => '   ' ), $this->ctx() );
		$this->assertTrue( $result->isError() );
		$this->assertSame( 'Provide a WP-CLI command, for example "plugin list".', $result->text() );
	}

	public function test_alias_positional_is_tool_error(): void {
		$runner = new FakeProcessRunner();
		$result = $this->tool( $runner )->execute( array( 'command' => '@prod plugin list' ), $this->ctx() );
		$this->assertTrue( $result->isError() );
		$this->assertSame( 0, $runner->calls );
	}
}
