<?php
/**
 * Admin-post helpers: capability and nonce guard, redirect notices.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Shared plumbing for the admin-post.php form handlers.
 */
final class Flash {

	const PAGE_SLUG = 'techthrill-mcp';

	/**
	 * Stops the request unless the user may manage options and the nonce for the action is valid.
	 *
	 * @param string $action Nonce action.
	 */
	public static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'techthrill-mcp' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( $action );
	}

	/**
	 * Stores a one-minute notice for the current user, shown on the next admin page load.
	 *
	 * @param string $type success or error.
	 * @param string $text Message (plain text).
	 */
	public static function notice( string $type, string $text ): void {
		set_transient(
			self::noticeKey(),
			array(
				'type' => 'success' === $type ? 'success' : 'error',
				'text' => $text,
			),
			60
		);
	}

	/**
	 * Returns and removes the pending notice, if any.
	 *
	 * @return array{type:string,text:string}|null
	 */
	public static function takeNotice(): ?array {
		$notice = get_transient( self::noticeKey() );
		if ( false === $notice ) {
			return null;
		}
		delete_transient( self::noticeKey() );

		return is_array( $notice ) && isset( $notice['type'], $notice['text'] ) ? $notice : null;
	}

	/**
	 * URL of one tab of the plugin page.
	 *
	 * @param string $tab connect, tokens, activity or settings.
	 */
	public static function tabUrl( string $tab ): string {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=' . rawurlencode( $tab ) );
	}

	/**
	 * Redirects to a tab and ends the request.
	 *
	 * @param string $tab Tab slug.
	 */
	public static function redirect( string $tab ): void {
		wp_safe_redirect( self::tabUrl( $tab ) );
		exit;
	}

	private static function noticeKey(): string {
		return 'techthrill_mcp_notice_' . get_current_user_id();
	}
}
