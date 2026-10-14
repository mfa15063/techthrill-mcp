<?php
/**
 * Activation and upgrade routines.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the database tables, default settings and the log-pruning cron event.
 */
final class Activator {

	const DB_VERSION_OPTION = 'techthrill_mcp_db_version';
	const PRUNE_HOOK        = 'techthrill_mcp_prune_log';

	/**
	 * Runs on plugin activation (and on upgrade).
	 */
	public static function activate(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$tokens  = $wpdb->prefix . 'techthrill_mcp_tokens';
		$log     = $wpdb->prefix . 'techthrill_mcp_log';

		// dbDelta is picky: two spaces after PRIMARY KEY, one definition per line.
		$tokens_sql = "CREATE TABLE {$tokens} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	name varchar(100) NOT NULL,
	lookup char(12) NOT NULL,
	hash char(64) NOT NULL,
	scopes varchar(255) NOT NULL DEFAULT '',
	user_id bigint(20) unsigned NOT NULL,
	created_at datetime NOT NULL,
	expires_at datetime NULL DEFAULT NULL,
	last_used_at datetime NULL DEFAULT NULL,
	revoked_at datetime NULL DEFAULT NULL,
	PRIMARY KEY  (id),
	KEY lookup (lookup)
) {$charset};";

		$log_sql = "CREATE TABLE {$log} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	token_id bigint(20) unsigned NOT NULL,
	tool varchar(50) NOT NULL,
	summary varchar(500) NOT NULL DEFAULT '',
	status varchar(10) NOT NULL,
	duration_ms int(10) unsigned NOT NULL DEFAULT 0,
	created_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY token_id (token_id),
	KEY created_at (created_at)
) {$charset};";

		dbDelta( $tokens_sql );
		dbDelta( $log_sql );

		update_option( self::DB_VERSION_OPTION, TECHTHRILL_MCP_VERSION );
		// add_option leaves existing settings untouched on reactivation.
		add_option( Settings::OPTION, Settings::DEFAULTS );

		if ( ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::PRUNE_HOOK );
		}
	}

	/**
	 * Re-runs activation when the stored schema version differs from the plugin version.
	 */
	public static function maybeUpgrade(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== TECHTHRILL_MCP_VERSION ) {
			self::activate();
		}
	}
}
