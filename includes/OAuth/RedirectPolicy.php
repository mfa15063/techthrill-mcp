<?php
/**
 * Redirect URI allowlist for OAuth clients.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Only these redirect targets are ever accepted at registration or redirected to:
 * the Claude hosted-app callback, ChatGPT connector callbacks, and loopback addresses for local clients.
 */
final class RedirectPolicy {

	const CLAUDE = 'https://claude.ai/api/mcp/auth_callback';

	/**
	 * Whether a redirect URI is acceptable.
	 *
	 * @param string $uri Redirect URI.
	 */
	public static function isAllowed( string $uri ): bool {
		if ( self::CLAUDE === $uri ) {
			return true;
		}
		if ( 1 === preg_match( '#^https://chatgpt\.com/connector/oauth/[A-Za-z0-9_-]+$#D', $uri ) ) {
			return true;
		}

		// Loopback: http, host exactly localhost or 127.0.0.1, optional port, any path and query, no user info or fragment.
		return 1 === preg_match( '#^http://(?:localhost|127\.0\.0\.1)(?::[0-9]{1,5})?(?:/[^\s\#@]*)?$#D', $uri );
	}

	/**
	 * Host (with port) of a redirect URI, for the consent page. Empty when unparsable.
	 *
	 * @param string $uri Redirect URI.
	 */
	public static function host( string $uri ): string {
		$parts = wp_parse_url( $uri );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		return $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	}
}
