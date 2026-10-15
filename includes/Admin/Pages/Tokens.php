<?php
/**
 * Tokens tab: list of connections with revoke and delete actions.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Admin\Pages;

use TechThrill\MCP\Admin\Flash;
use TechThrill\MCP\Admin\TokensListTable;
use TechThrill\MCP\Admin\TokenStatus;
use TechThrill\MCP\Auth\TokenRepository;
use TechThrill\MCP\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Tokens tab and handles revoke and delete.
 */
final class Tokens {

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
		$table = new TokensListTable( $this->repository() );
		$table->prepare_items();
		$table->display();
		echo '<p class="description">' . esc_html__( 'Connections marked OAuth were approved on the consent screen. They renew automatically until you revoke them or they go unused for 30 days. Revoking one stops it immediately.', 'techthrill-mcp' ) . '</p>';
	}

	/**
	 * Admin-post handler: revokes a token.
	 */
	public function handleRevoke(): void {
		Flash::guard( 'techthrill_mcp_revoke_token' );

		$id = $this->requestedId();
		if ( $id > 0 && null !== $this->repository()->find( $id ) ) {
			$this->plugin->tokenManager()->revoke( $id );
			Flash::notice( 'success', __( 'The token was revoked.', 'techthrill-mcp' ) );
		} else {
			Flash::notice( 'error', __( 'That token no longer exists.', 'techthrill-mcp' ) );
		}
		Flash::redirect( 'tokens' );
	}

	/**
	 * Admin-post handler: deletes a revoked or expired token.
	 */
	public function handleDelete(): void {
		Flash::guard( 'techthrill_mcp_delete_token' );

		$id  = $this->requestedId();
		$row = $id > 0 ? $this->repository()->find( $id ) : null;
		if ( null === $row ) {
			Flash::notice( 'error', __( 'That token no longer exists.', 'techthrill-mcp' ) );
		} elseif ( TokenStatus::ACTIVE === TokenStatus::of( $row, time() ) ) {
			Flash::notice( 'error', __( 'Revoke the token before deleting it.', 'techthrill-mcp' ) );
		} else {
			$this->repository()->delete( $id );
			Flash::notice( 'success', __( 'The token was deleted.', 'techthrill-mcp' ) );
		}
		Flash::redirect( 'tokens' );
	}

	private function repository(): TokenRepository {
		return $this->plugin->tokenRepository();
	}

	private function requestedId(): int {
		return isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified in Flash::guard().
	}
}
