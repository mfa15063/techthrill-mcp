<?php
/**
 * Uninstall handler: removes the backups and the option that names their folder.
 *
 * @package TechThrill\MCP\Files
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/Support/BackupStore.php';

$techthrill_mcp_files_token = (string) get_option( 'techthrill_mcp_files_token', '' );
if ( '' !== $techthrill_mcp_files_token ) {
	$techthrill_mcp_files_uploads = wp_upload_dir( null, false );
	$techthrill_mcp_files_store   = new \TechThrill\MCP\Files\Support\BackupStore( rtrim( (string) $techthrill_mcp_files_uploads['basedir'], '/\\' ) . '/techthrill-mcp-files-' . $techthrill_mcp_files_token );
	$techthrill_mcp_files_store->deleteAll();
}

delete_option( 'techthrill_mcp_files_token' );
