<?php
/**
 * Plugin singleton.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP;

use TechThrill\MCP\Admin\Menu;
use TechThrill\MCP\Auth\Authenticator;
use TechThrill\MCP\Auth\RateLimiter;
use TechThrill\MCP\Auth\TokenManager;
use TechThrill\MCP\Auth\TokenRepository;
use TechThrill\MCP\Auth\TransientRateLimitStore;
use TechThrill\MCP\Auth\WpTokenRepository;
use TechThrill\MCP\Logging\ArgumentSummarizer;
use TechThrill\MCP\Logging\AuditLog;
use TechThrill\MCP\Mcp\RestController;
use TechThrill\MCP\Mcp\SchemaValidator;
use TechThrill\MCP\Mcp\Server;
use TechThrill\MCP\OAuth\Module as OAuthModule;
use TechThrill\MCP\Safety\CommandParser;
use TechThrill\MCP\Safety\HostCapabilities;
use TechThrill\MCP\Safety\PathJail;
use TechThrill\MCP\Safety\SafeMode;
use TechThrill\MCP\Safety\SqlClassifier;
use TechThrill\MCP\Tools\DbExecute;
use TechThrill\MCP\Tools\DbQuery;
use TechThrill\MCP\Tools\DbSchema;
use TechThrill\MCP\Tools\FileCopy;
use TechThrill\MCP\Tools\FileDelete;
use TechThrill\MCP\Tools\FileEdit;
use TechThrill\MCP\Tools\FileList;
use TechThrill\MCP\Tools\FileRead;
use TechThrill\MCP\Tools\FileSearch;
use TechThrill\MCP\Tools\FileWrite;
use TechThrill\MCP\Tools\MediaUpload;
use TechThrill\MCP\Tools\ProcOpenRunner;
use TechThrill\MCP\Tools\SiteInfo;
use TechThrill\MCP\Tools\ToolRegistry;
use TechThrill\MCP\Tools\ToolResult;
use TechThrill\MCP\Tools\WpCli;
use TechThrill\MCP\Tools\WpMediaLibrary;

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

	private HostCapabilities $host_capabilities;

	private ToolRegistry $registry;

	private AuditLog $audit_log;

	private TokenManager $token_manager;

	private TokenRepository $token_repository;

	private OAuthModule $oauth;

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

		$uploads                 = wp_upload_dir( null, false );
		$uploads_dir             = (string) ( $uploads['basedir'] ?? '' );
		$this->host_capabilities = new HostCapabilities( $this->settings, $uploads_dir );

		$plugin_relative_dir = trim( str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( TECHTHRILL_MCP_DIR ) ), '/' );
		$this->safe_mode     = new SafeMode( (bool) $this->settings->get( 'safe_mode' ), $plugin_relative_dir );

		$this->token_repository = new WpTokenRepository( $wpdb );
		$this->token_manager    = new TokenManager( $this->token_repository );
		$limiter                = new RateLimiter( (int) $this->settings->get( 'rate_limit' ), new TransientRateLimitStore() );
		$authenticator          = new Authenticator(
			$this->token_manager,
			$limiter,
			function ( int $user_id ): bool {
				return user_can( $user_id, 'manage_options' );
			}
		);

		$registry = new ToolRegistry();
		$registry->register( new SiteInfo( $this->host_capabilities, $this->safe_mode ) );
		$classifier = new SqlClassifier();
		$registry->register( new DbQuery( $wpdb, $classifier ) );
		$registry->register( new DbExecute( $wpdb, $classifier, $this->safe_mode ) );
		$registry->register( new DbSchema( $wpdb ) );
		foreach ( array( FileList::class, FileRead::class, FileSearch::class, FileWrite::class, FileEdit::class, FileCopy::class, FileDelete::class ) as $file_tool ) {
			$registry->register( new $file_tool( $this->path_jail, $this->safe_mode ) );
		}
		$registry->register( new MediaUpload( new WpMediaLibrary() ) );
		$registry->register(
			new WpCli(
				new CommandParser(),
				$this->safe_mode,
				$this->host_capabilities,
				new ProcOpenRunner(),
				$this->settings,
				rtrim( $uploads_dir, '/\\' ) . '/techthrill-mcp/cache'
			)
		);
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

		$this->oauth = new OAuthModule( $wpdb, $this->token_manager );
		$this->oauth->boot();

		// Resolved lazily: rest_url() cannot be called this early, and the challenge is only needed on a 401.
		$challenge  = function ( bool $invalid_token ): string {
			return $this->oauth->metadata()->challenge( $invalid_token );
		};
		$controller = new RestController( $authenticator, $server, array( $this, 'setCurrentTokenId' ), $challenge );
		add_action( 'rest_api_init', array( $controller, 'register' ) );

		$settings = $this->settings;
		$oauth    = $this->oauth;
		add_action(
			Activator::PRUNE_HOOK,
			function () use ( $audit_log, $settings, $oauth ): void {
				$audit_log->prune( (int) $settings->get( 'log_retention_days' ) );
				$oauth->prune();
			}
		);

		if ( is_admin() ) {
			( new Menu( $this ) )->register();
		}
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

	public function hostCapabilities(): HostCapabilities {
		return $this->host_capabilities;
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

	public function oauth(): OAuthModule {
		return $this->oauth;
	}

	public function tokenRepository(): TokenRepository {
		return $this->token_repository;
	}
}
