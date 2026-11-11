<?php
/**
 * Wires the addon into WordPress and into the main plugin.
 *
 * @package TechThrill\MCP\Files
 */

namespace TechThrill\MCP\Files;

use TechThrill\MCP\Files\Support\AllowedRoots;
use TechThrill\MCP\Files\Support\BackupStore;
use TechThrill\MCP\Files\Tools\AssetUpload;
use TechThrill\MCP\Files\Tools\CodeFileBackups;
use TechThrill\MCP\Files\Tools\CodeFileEdit;
use TechThrill\MCP\Files\Tools\CodeFileRename;
use TechThrill\MCP\Files\Tools\CodeFileRestore;
use TechThrill\MCP\Files\Tools\CodeFileWrite;
use TechThrill\MCP\Files\Tools\CodeReplaceInFiles;

defined( 'ABSPATH' ) || exit;

/**
 * Nothing here touches the main plugin's classes until the requirements check has passed, because they do not
 * exist when the main plugin is missing or inactive.
 */
final class Bootstrap {

	const MAIN_BASENAME = 'techthrill-mcp/techthrill-mcp.php';

	const TOKEN_OPTION = 'techthrill_mcp_files_token';

	/**
	 * This addon's main file.
	 *
	 * @var string
	 */
	private static string $file = '';

	/**
	 * Registers the hooks. Called from the addon's main file.
	 *
	 * @param string $file Main file of the addon.
	 */
	public static function init( string $file ): void {
		self::$file = $file;

		add_action( 'plugins_loaded', array( self::class, 'boot' ), 5 );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( $file ), array( self::class, 'actionLinks' ) );
	}

	/**
	 * Where the main plugin stands right now.
	 */
	public static function status(): string {
		$active  = defined( 'TECHTHRILL_MCP_VERSION' ) && class_exists( '\\TechThrill\\MCP\\Plugin' );
		$version = $active ? (string) constant( 'TECHTHRILL_MCP_VERSION' ) : null;

		return Requirements::status( $active || self::mainInstalled(), $active, $version );
	}

	/**
	 * Whether the main plugin is installed, active or not.
	 */
	private static function mainInstalled(): bool {
		if ( defined( 'WP_PLUGIN_DIR' ) && is_file( WP_PLUGIN_DIR . '/' . self::MAIN_BASENAME ) ) {
			return true;
		}
		if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) && is_file( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( function_exists( 'get_plugins' ) ) {
			foreach ( get_plugins() as $data ) {
				if ( isset( $data['Name'] ) && 'TechThrill MCP' === $data['Name'] ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Adds the tools to the main plugin, but only when its requirements are met.
	 */
	public static function boot(): void {
		if ( Requirements::OK === self::status() ) {
			add_filter( 'techthrill_mcp_tools', array( self::class, 'registerTools' ), 20 );
		}
	}

	/**
	 * Activation guard: refuses to activate (and says why) when the main plugin is missing, inactive or too old.
	 */
	public static function onActivate(): void {
		$status = self::status();
		if ( Requirements::OK === $status ) {
			return;
		}

		deactivate_plugins( plugin_basename( self::$file ) );
		wp_die(
			esc_html( Requirements::message( $status, defined( 'TECHTHRILL_MCP_VERSION' ) ? (string) constant( 'TECHTHRILL_MCP_VERSION' ) : null ) ),
			esc_html__( 'TechThrill MCP File Tools cannot be activated', 'techthrill-mcp-files' ),
			array( 'back_link' => true )
		);
	}

	/**
	 * A warning in the admin when the addon is active but the main plugin is not usable.
	 */
	public static function notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$status = self::status();
		if ( Requirements::OK === $status ) {
			return;
		}

		$version = defined( 'TECHTHRILL_MCP_VERSION' ) ? (string) constant( 'TECHTHRILL_MCP_VERSION' ) : null;
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'TechThrill MCP File Tools is switched off.', 'techthrill-mcp-files' ) . '</strong> ' . esc_html( Requirements::message( $status, $version ) ) . '</p></div>';
	}

	/**
	 * Greys out the Activate link while the requirements are not met.
	 *
	 * @param array $links Plugin row action links.
	 * @return array
	 */
	public static function actionLinks( $links ): array {
		$links = (array) $links;
		if ( isset( $links['activate'] ) && Requirements::OK !== self::status() ) {
			$links['activate'] = '<span style="color:#996800;font-weight:600" title="' . esc_attr( Requirements::message( self::status() ) ) . '">' . esc_html__( 'Needs TechThrill MCP', 'techthrill-mcp-files' ) . '</span>';
		}

		return $links;
	}

	/**
	 * Registers the tools on the main plugin's registry (filter techthrill_mcp_tools).
	 *
	 * @param mixed $registry The main plugin's ToolRegistry.
	 * @return mixed The same registry.
	 */
	public static function registerTools( $registry ) {
		if ( ! is_object( $registry ) || ! method_exists( $registry, 'register' ) ) {
			return $registry;
		}

		try {
			$plugin  = \TechThrill\MCP\Plugin::instance();
			$roots   = new AllowedRoots( self::allowedRoots() );
			$backups = new BackupStore( self::backupDir() );
			$never   = self::neverMove();

			foreach ( array( CodeFileWrite::class, CodeFileEdit::class, CodeFileRename::class, AssetUpload::class, CodeReplaceInFiles::class, CodeFileBackups::class, CodeFileRestore::class ) as $class ) {
				$registry->register( new $class( $plugin->pathJail(), $plugin->safeMode(), $roots, $backups, array( self::class, 'activeRoots' ), $never ) );
			}
		} catch ( \Throwable $e ) {
			// Never break the main plugin because of this addon.
			error_log( 'TechThrill MCP File Tools: ' . get_class( $e ) . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return $registry;
	}

	/**
	 * Root-relative theme and plugin folders. Extend them with the techthrill_mcp_files_allowed_roots filter.
	 *
	 * @return string[]
	 */
	public static function allowedRoots(): array {
		$roots = array();
		foreach ( array( get_theme_root(), WP_PLUGIN_DIR ) as $dir ) {
			$relative = self::relative( (string) $dir );
			if ( null !== $relative ) {
				$roots[] = $relative;
			}
		}

		$filtered = apply_filters( 'techthrill_mcp_files_allowed_roots', $roots );

		return is_array( $filtered ) ? array_values( array_filter( $filtered, 'is_string' ) ) : $roots;
	}

	/**
	 * Root-relative folders and main files of the active themes and plugins.
	 *
	 * @return string[]
	 */
	public static function activeRoots(): array {
		$out     = array();
		$plugins = self::relative( WP_PLUGIN_DIR );
		$themes  = self::relative( get_theme_root() );

		if ( null !== $plugins ) {
			$active = (array) get_option( 'active_plugins', array() );
			if ( function_exists( 'is_multisite' ) && is_multisite() ) {
				$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
			}
			foreach ( $active as $file ) {
				$file  = (string) $file;
				$out[] = $plugins . '/' . $file;
				if ( false !== strpos( $file, '/' ) ) {
					$out[] = $plugins . '/' . dirname( $file );
				}
			}
		}
		if ( null !== $themes ) {
			foreach ( array( 'stylesheet', 'template' ) as $option ) {
				$slug = (string) get_option( $option, '' );
				if ( '' !== $slug ) {
					$out[] = $themes . '/' . $slug;
				}
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Folders that can never be renamed or moved: the main plugin and this addon.
	 *
	 * @return string[]
	 */
	public static function neverMove(): array {
		$never = array();
		foreach ( array( defined( 'TECHTHRILL_MCP_DIR' ) ? constant( 'TECHTHRILL_MCP_DIR' ) : '', plugin_dir_path( self::$file ) ) as $dir ) {
			$relative = '' === $dir ? null : self::relative( (string) $dir );
			if ( null !== $relative ) {
				$never[] = $relative;
			}
		}

		return $never;
	}

	/**
	 * Folder that holds the backups: inside uploads, under an unguessable name, closed to web access.
	 */
	public static function backupDir(): string {
		$token = (string) get_option( self::TOKEN_OPTION, '' );
		if ( '' === $token ) {
			$token = substr( bin2hex( random_bytes( 8 ) ), 0, 16 );
			add_option( self::TOKEN_OPTION, $token, '', 'no' );
		}
		$uploads = wp_upload_dir( null, false );

		return rtrim( (string) ( $uploads['basedir'] ?? WP_CONTENT_DIR . '/uploads' ), '/\\' ) . '/techthrill-mcp-files-' . $token;
	}

	/**
	 * Root-relative path of an absolute path, or null when it is outside the WordPress root.
	 *
	 * @param string $dir Absolute path.
	 */
	private static function relative( string $dir ): ?string {
		$root = realpath( ABSPATH );
		$real = realpath( $dir );
		if ( false === $root || false === $real ) {
			return null;
		}

		$root = rtrim( str_replace( '\\', '/', $root ), '/' ) . '/';
		$real = str_replace( '\\', '/', $real );
		if ( 0 !== strpos( $real . '/', $root ) ) {
			return null;
		}

		return trim( substr( $real . '/', strlen( $root ) ), '/' );
	}
}
