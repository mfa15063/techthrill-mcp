<?php
/**
 * The site_info tool.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Tools;

use TechThrill\MCP\Safety\HostCapabilities;
use TechThrill\MCP\Safety\SafeMode;

defined( 'ABSPATH' ) || exit;

/**
 * Describes the WordPress site, its environment and what the calling token may do.
 */
final class SiteInfo extends AbstractTool {

	private HostCapabilities $host;

	private SafeMode $safe_mode;

	/**
	 * @param HostCapabilities $host      Host capability detector.
	 * @param SafeMode         $safe_mode Safe mode state.
	 */
	public function __construct( HostCapabilities $host, SafeMode $safe_mode ) {
		$this->host      = $host;
		$this->safe_mode = $safe_mode;
	}

	public function name(): string {
		return 'site_info';
	}

	public function description(): string {
		return 'Returns facts about this WordPress site: URLs, versions, paths, active theme and plugins, locale, timezone, safe mode, what the host can run (WP-CLI, HTTPS) and the scopes of the calling token. Call this first.';
	}

	public function inputSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(),
		);
	}

	public function scope(): ?string {
		return null;
	}

	public function execute( array $args, ToolContext $ctx ): ToolResult {
		global $wpdb;

		$theme = wp_get_theme();

		return $this->ok(
			array(
				'site_url'          => site_url(),
				'home_url'          => home_url(),
				'wp_version'        => get_bloginfo( 'version' ),
				'php_version'       => PHP_VERSION,
				'mysql_version'     => $wpdb->db_version(),
				'abspath'           => wp_normalize_path( ABSPATH ),
				'content_dir'       => wp_normalize_path( WP_CONTENT_DIR ),
				'table_prefix'      => $wpdb->prefix,
				'multisite'         => is_multisite(),
				'theme'             => array(
					'name'    => $theme->get( 'Name' ),
					'version' => $theme->get( 'Version' ),
					'path'    => wp_normalize_path( get_stylesheet_directory() ),
				),
				'plugins'           => $this->activePlugins(),
				'locale'            => get_locale(),
				'timezone'          => wp_timezone_string(),
				'safe_mode'         => $this->safe_mode->isEnabled(),
				'host_capabilities' => $this->host->toArray(),
				'token_scopes'      => $ctx->scopes(),
			)
		);
	}

	/**
	 * Active plugins as name, version and path relative to the site root.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function activePlugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			if ( ! is_plugin_active( $file ) ) {
				continue;
			}

			$plugins[] = array(
				'name'    => (string) $data['Name'],
				'version' => (string) $data['Version'],
				'path'    => 'wp-content/plugins/' . $file,
			);
		}

		return $plugins;
	}
}
