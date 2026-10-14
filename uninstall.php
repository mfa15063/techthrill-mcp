<?php
/**
 * Uninstall handler: removes tables, options, transients and the scheduled event.
 *
 * On multisite this runs for the current site only; other sites' tables are left in place.
 *
 * @package TechThrill\MCP
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Table names come from $wpdb->prefix, not user input.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}techthrill_mcp_tokens" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dropping plugin-owned tables on uninstall.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}techthrill_mcp_log" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dropping plugin-owned tables on uninstall.

delete_option( 'techthrill_mcp_settings' );
delete_option( 'techthrill_mcp_db_version' );

// Transients (rate-limit windows, notices, the REST self-check) and their timeouts.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup of plugin transients on uninstall.
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_techthrill_mcp_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_techthrill_mcp_' ) . '%'
	)
);

wp_clear_scheduled_hook( 'techthrill_mcp_prune_log' );
