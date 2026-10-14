<?php
/**
 * Plugin singleton.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP;

use TechThrill\MCP\Auth\Authenticator;
use TechThrill\MCP\Auth\RateLimiter;
use TechThrill\MCP\Auth\TokenManager;
use TechThrill\MCP\Auth\TransientRateLimitStore;
use TechThrill\MCP\Auth\WpTokenRepository;
use TechThrill\MCP\Logging\ArgumentSummarizer;
use TechThrill\MCP\Logging\AuditLog;
use TechThrill\MCP\Mcp\RestController;
use TechThrill\MCP\Mcp\SchemaValidator;
use TechThrill\MCP\Mcp\Server;
use TechThrill\MCP\Safety\PathJail;
use TechThrill\MCP\Safety\SafeMode;
use TechThrill\MCP\Tools\ToolRegistry;
use TechThrill\MCP\Tools\ToolResult;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin's components into WordPress.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Whether boot() has already run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Id of the token behind the request being handled; read by the tool-call logger.
	 *
	 * @var int
	 */
	private int $current_token_id = 0;

	private Settings $settings;

	private SafeMode $safe_mode;

	private PathJail $path_jail;

	private ToolRegistry $registry;

	private AuditLog $audit_log;

	private TokenManager $token_manager;

	private function __construct() {}

	/**
	 * Returns the singleton instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Builds the object graph and registers hooks. Runs once, on plugins_loaded.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		global $wpdb;

		// Create or upgrade the tables before anything reads them.
		Activator::maybeUpgrade();

		$this->settings  = new Settings();
		$this->path_jail = new PathJail( ABSPATH );

		$plugin_relative_dir = trim( str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( TECHTHRILL_MCP_DIR ) ), '/' );
		$this->safe_mode     = new SafeMode( (bool) $this->settings->get( 'safe_mode' ), $plugin_relative_dir );

		$this->token_manager = new TokenManager( new WpTokenRepository( $wpdb ) );
		$limiter             = new RateLimiter( (int) $this->settings->get( 'rate_limit' ), new TransientRateLimitStore() );
		$authenticator       = new Authenticator(
			$this->token_manager,
			$limiter,
			function ( int $user_id ): bool {
				return user_can( $user_id, 'manage_options' );
			}
		);

		$registry = new ToolRegistry();
		$filtered = apply_filters( 'techthrill_mcp_tools', $registry );
		if ( $filtered instanceof ToolRegistry ) {
			$registry = $filtered;
		}
		$this->registry = $registry;

		$this->audit_log = new AuditLog( $wpdb );
		$audit_log       = $this->audit_log;
		$server          = new Server(
			$this->registry,
			new SchemaValidator(),
			TECHTHRILL_MCP_VERSION,
			function ( string $tool, array $args, ToolResult $result, int $ms ) use ( $audit_log ): void {
				$audit_log->record(
					$this->current_token_id,
					$tool,
					ArgumentSummarizer::summarize( $args ),
					AuditLog::statusFor( $result ),
					$ms
				);
			}
		);

		$controller = new RestController( $authenticator, $server, array( $this, 'setCurrentTokenId' ) );
		add_action( 'rest_api_init', array( $controller, 'register' ) );

		$settings = $this->settings;
		add_action(
			Activator::PRUNE_HOOK,
			function () use ( $audit_log, $settings ): void {
				$audit_log->prune( (int) $settings->get( 'log_retention_days' ) );
			}
		);
	}

	/**
	 * Records the token behind the request being handled, so tool calls are logged against it.
	 *
	 * @param int $id Token id.
	 */
	public function setCurrentTokenId( int $id ): void {
		$this->current_token_id = $id;
	}

	/**
	 * Full URL of the MCP endpoint.
	 */
	public function endpointUrl(): string {
		return rest_url( RestController::REST_NAMESPACE . RestController::ROUTE );
	}

	public function settings(): Settings {
		return $this->settings;
	}

	public function safeMode(): SafeMode {
		return $this->safe_mode;
	}

	public function pathJail(): PathJail {
		return $this->path_jail;
	}

	public function registry(): ToolRegistry {
		return $this->registry;
	}

	public function auditLog(): AuditLog {
		return $this->audit_log;
	}

	public function tokenManager(): TokenManager {
		return $this->token_manager;
	}
}
