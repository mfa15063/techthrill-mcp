<?php
/**
 * The wp_cli tool.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Tools;

use TechThrill\MCP\Safety\CommandException;
use TechThrill\MCP\Safety\CommandParser;
use TechThrill\MCP\Safety\HostCapabilities;
use TechThrill\MCP\Safety\SafeMode;
use TechThrill\MCP\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Runs one WP-CLI command as a child process, without a shell.
 */
final class WpCli extends AbstractTool {

	const MIN_TIMEOUT = 5;

	const MAX_TIMEOUT = 300;

	private CommandParser $parser;

	private SafeMode $safe_mode;

	private HostCapabilities $host;

	private ProcessRunner $runner;

	private Settings $settings;

	private string $cache_dir;

	/**
	 * @param CommandParser    $parser    Command tokenizer and blocklist matcher.
	 * @param SafeMode         $safe_mode Safe mode policy.
	 * @param HostCapabilities $host      Host capability detection.
	 * @param ProcessRunner    $runner    Process runner.
	 * @param Settings         $settings  Plugin settings.
	 * @param string           $cache_dir WP-CLI cache directory.
	 */
	public function __construct( CommandParser $parser, SafeMode $safe_mode, HostCapabilities $host, ProcessRunner $runner, Settings $settings, string $cache_dir ) {
		$this->parser    = $parser;
		$this->safe_mode = $safe_mode;
		$this->host      = $host;
		$this->runner    = $runner;
		$this->settings  = $settings;
		$this->cache_dir = $cache_dir;
	}

	public function name(): string {
		return 'wp_cli';
	}

	public function description(): string {
		return 'Run one WP-CLI command on this site without the leading "wp", e.g. "plugin list --format=json". Global flags --path, --url, --ssh, --http and --user are managed by the server and cannot be overridden. Some commands are blocked for safety.';
	}

	public function inputSchema(): array {
		$default = max( self::MIN_TIMEOUT, min( self::MAX_TIMEOUT, (int) $this->settings->get( 'cli_timeout' ) ) );

		return array(
			'type'       => 'object',
			'properties' => array(
				'command' => array(
					'type'        => 'string',
					'description' => 'The WP-CLI command without the leading "wp", e.g. "plugin list --format=json".',
				),
				'timeout' => array(
					'type'        => 'integer',
					'description' => 'Seconds before the command is killed.',
					'minimum'     => self::MIN_TIMEOUT,
					'maximum'     => self::MAX_TIMEOUT,
					'default'     => $default,
				),
			),
			'required'   => array( 'command' ),
		);
	}

	public function scope(): ?string {
		return 'wp-cli';
	}

	public function execute( array $args, ToolContext $ctx ): ToolResult {
		if ( ! $this->host->execAvailable() ) {
			return $this->fail( 'This host does not allow PHP to start processes (proc_open is disabled), so WP-CLI cannot run. Database and file tools still work.' );
		}

		$binary = $this->host->wpBinary();
		if ( null === $binary ) {
			return $this->fail( 'WP-CLI was not found on this host. The site administrator can install it from TechThrill MCP → Connect.' );
		}

		try {
			$argv = $this->parser->parse( (string) ( $args['command'] ?? '' ) );
			if ( array() === $argv ) {
				return $this->fail( 'Provide a WP-CLI command, for example "plugin list".' );
			}

			$entry = $this->parser->firstBlocked( $argv, CommandParser::ALWAYS_BLOCKED );
			if ( null !== $entry ) {
				return $this->fail( 'The command "' . $entry . '" is not available through TechThrill MCP.' );
			}

			$refusal = $this->safe_mode->checkCli( $argv );
		} catch ( CommandException $e ) {
			return $this->fail( $e->getMessage() );
		}
		if ( null !== $refusal ) {
			return $this->fail( $refusal->reason() );
		}

		$timeout = isset( $args['timeout'] )
			? (int) $args['timeout']
			: (int) $this->settings->get( 'cli_timeout' );
		$timeout = max( self::MIN_TIMEOUT, min( self::MAX_TIMEOUT, $timeout ) );

		$full = array_merge(
			$binary,
			$argv,
			array(
				'--path=' . rtrim( wp_normalize_path( ABSPATH ), '/' ),
				'--user=' . $ctx->userId(),
				'--no-color',
				'--skip-packages',
			)
		);

		$result = $this->runner->run( $full, ABSPATH, $this->environment(), $timeout );

		return $this->ok(
			array(
				'exit_code'        => $result->exitCode,
				'stdout'           => $result->stdout,
				'stderr'           => $result->stderr,
				'duration_ms'      => $result->durationMs,
				'timed_out'        => $result->timedOut,
				'stdout_truncated' => $result->stdoutTruncated,
				'stderr_truncated' => $result->stderrTruncated,
			)
		);
	}

	/**
	 * Environment handed to the child: nothing from the server beyond what WP-CLI needs.
	 *
	 * @return array<string, string>
	 */
	private function environment(): array {
		$env = array(
			'PATH'                             => (string) ( getenv( 'PATH' ) ?: '' ),
			'HOME'                             => (string) ( getenv( 'HOME' ) ?: ( getenv( 'USERPROFILE' ) ?: '' ) ),
			'WP_CLI_DISABLE_AUTO_CHECK_UPDATE' => '1',
			'WP_CLI_CACHE_DIR'                 => $this->cache_dir,
		);

		// proc_open on Windows needs these to start a child at all.
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			foreach ( array( 'SYSTEMROOT', 'TEMP' ) as $key ) {
				$value = getenv( $key );
				if ( false !== $value && '' !== $value ) {
					$env[ $key ] = $value;
				}
			}
		}

		return $env;
	}
}
