<?php
/**
 * Activity tab: filterable log of tool calls.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Admin\Pages;

use TechThrill\MCP\Admin\ActivityListTable;
use TechThrill\MCP\Admin\Flash;
use TechThrill\MCP\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Activity tab and handles clearing the log.
 */
final class Activity {

	private Plugin $plugin;

	/**
	 * @param Plugin $plugin Plugin instance.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Prints the tab.
	 */
	public function render(): void {
		$names = array();
		foreach ( $this->plugin->tokenRepository()->all() as $row ) {
			$names[ (int) $row['id'] ] = (string) $row['name'];
		}

		$token_id = isset( $_GET['token_id'] ) ? absint( wp_unslash( $_GET['token_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$tool     = isset( $_GET['tool'] ) ? sanitize_text_field( wp_unslash( $_GET['tool'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$filters = array();
		if ( $token_id > 0 ) {
			$filters['token_id'] = $token_id;
		}
		if ( '' !== $tool ) {
			$filters['tool'] = $tool;
		}

		$this->renderFilters( $names, $token_id, $tool );

		$table = new ActivityListTable( $this->plugin->auditLog(), $names, $filters );
		$table->prepare_items();
		$table->display();

		$this->renderClearForm();
	}

	/**
	 * Admin-post handler: empties the log.
	 */
	public function handleClear(): void {
		Flash::guard( 'techthrill_mcp_clear_log' );

		$this->plugin->auditLog()->clear();
		Flash::notice( 'success', __( 'The activity log was cleared.', 'techthrill-mcp' ) );
		Flash::redirect( 'activity' );
	}

	/**
	 * @param array<int,string> $names    Token names by id.
	 * @param int               $token_id Selected token id.
	 * @param string            $tool     Selected tool.
	 */
	private function renderFilters( array $names, int $token_id, string $tool ): void {
		echo '<form method="get" class="ttm-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( Flash::PAGE_SLUG ) . '" />';
		echo '<input type="hidden" name="tab" value="activity" />';

		echo '<label class="screen-reader-text" for="ttm-filter-token">' . esc_html__( 'Filter by token', 'techthrill-mcp' ) . '</label>';
		echo '<select id="ttm-filter-token" name="token_id"><option value="0">' . esc_html__( 'All tokens', 'techthrill-mcp' ) . '</option>';
		foreach ( $names as $id => $name ) {
			echo '<option value="' . esc_attr( (string) $id ) . '"' . selected( $token_id, $id, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select> ';

		echo '<label class="screen-reader-text" for="ttm-filter-tool">' . esc_html__( 'Filter by tool', 'techthrill-mcp' ) . '</label>';
		echo '<select id="ttm-filter-tool" name="tool"><option value="">' . esc_html__( 'All tools', 'techthrill-mcp' ) . '</option>';
		foreach ( $this->plugin->auditLog()->distinctTools() as $name ) {
			echo '<option value="' . esc_attr( $name ) . '"' . selected( $tool, $name, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select> ';

		echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'techthrill-mcp' ) . '</button>';
		echo '</form>';
	}

	private function renderClearForm(): void {
		echo '<form method="post" class="ttm-clear-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="techthrill_mcp_clear_log" />';
		wp_nonce_field( 'techthrill_mcp_clear_log' );
		echo '<button type="submit" class="button" data-ttm-confirm="' . esc_attr__( 'Clear the whole activity log? This cannot be undone.', 'techthrill-mcp' ) . '">' . esc_html__( 'Clear log', 'techthrill-mcp' ) . '</button>';
		echo '</form>';
	}
}
