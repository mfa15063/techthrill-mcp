<?php
/**
 * Uninstall handler: removes tables, options and the scheduled event.
 *
 * @package TechThrill\MCP
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Table names come from $wpdb->prefix, not user input.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}techthrill_mcp_tokens" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}techthrill_mcp_log" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange

delete_option( 'techthrill_mcp_settings' );
delete_option( 'techthrill_mcp_db_version' );

wp_clear_scheduled_hook( 'techthrill_mcp_prune_log' );
