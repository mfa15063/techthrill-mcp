<?php
/**
 * Tokens tab: list of connections with revoke and delete actions.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Admin\Pages;

use TechThrill\MCP\Admin\Flash;
use TechThrill\MCP\Admin\TokensListTable;
use TechThrill\MCP\Admin\TokenBulk;
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

		// The form posts to this page (not admin-post.php) because the bulk dropdown is named "action", which
		// admin-post.php would read as the handler to run. display() adds the nonce for the bulk actions.
		echo '<form method="post" class="ttm-bulk-form" action="' . esc_url( Flash::tabUrl( 'tokens' ) ) . '" data-ttm-confirm-revoke="' . esc_attr__( 'Revoke the selected tokens? Clients using them will stop working immediately.', 'techthrill-mcp' ) . '" data-ttm-confirm-delete="' . esc_attr__( 'Delete the selected tokens permanently? Clients using them will stop working immediately.', 'techthrill-mcp' ) . '">';
		$table->display();
		echo '</form>';
		echo '<p class="description">' . esc_html__( 'Connections marked OAuth were approved on the consent screen. They last for the expiry chosen there (1 day, 1 week, 30 days or never) unless you revoke them. Tick the boxes to revoke or delete several tokens at once. Revoking or deleting a token stops it immediately.', 'techthrill-mcp' ) . '</p>';
	}

	/**
	 * Hooked to the plugin page's load action: handles the bulk actions on the Tokens table when they were posted.
	 */
	public function maybeHandleBulk(): void {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing only; handleBulk() verifies the nonce.
		if ( 'tokens' !== $tab || ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}

		$action = $this->postedAction();
		if ( ! TokenBulk::isAction( $action ) ) {
			return;
		}

		$this->handleBulk( $action );
	}

	/**
	 * Revokes or deletes the selected tokens, then redirects back with a notice.
	 *
	 * @param string $action revoke or delete.
	 */
	private function handleBulk( string $action ): void {
		// Capability and the nonce WP_List_Table prints for the plural "tokens".
		Flash::guard( 'bulk-tokens' );

		$ids = TokenBulk::cleanIds( isset( $_POST['token'] ) ? wp_unslash( $_POST['token'] ) : array() ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified in Flash::guard(); every value is validated by cleanIds().
		if ( array() === $ids ) {
			Flash::notice( 'error', __( 'Select at least one token first.', 'techthrill-mcp' ) );
			Flash::redirect( 'tokens' );
		}

		$result = TokenBulk::apply( $action, $ids, $this->repository(), $this->plugin->tokenManager(), time() );

		if ( TokenBulk::DELETE === $action ) {
			/* translators: %d: number of tokens */
			$text = sprintf( _n( '%d token was deleted.', '%d tokens were deleted.', $result['done'], 'techthrill-mcp' ), $result['done'] );
		} else {
			/* translators: %d: number of tokens */
			$text = sprintf( _n( '%d token was revoked.', '%d tokens were revoked.', $result['done'], 'techthrill-mcp' ), $result['done'] );
		}
		if ( $result['skipped'] > 0 ) {
			/* translators: %d: number of tokens left unchanged */
			$text .= ' ' . sprintf( _n( '%d was skipped (already revoked, expired or gone).', '%d were skipped (already revoked, expired or gone).', $result['skipped'], 'techthrill-mcp' ), $result['skipped'] );
		}

		Flash::notice( $result['done'] > 0 ? 'success' : 'error', $text );
		Flash::redirect( 'tokens' );
	}

	/**
	 * The bulk action chosen in the top or the bottom dropdown, or an empty string.
	 */
	private function postedAction(): string {
		foreach ( array( 'action', 'action2' ) as $field ) {
			if ( isset( $_POST[ $field ] ) && is_string( $_POST[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handleBulk().
				$value = sanitize_key( wp_unslash( $_POST[ $field ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handleBulk().
				if ( TokenBulk::isAction( $value ) ) {
					return $value;
				}
			}
		}

		return '';
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
