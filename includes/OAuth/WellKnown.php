<?php
/**
 * Root /.well-known discovery documents.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Serves the authorization-server and protected-resource documents at their RFC 8414 / RFC 9728 root paths,
 * from an init hook that runs before WordPress routing, so it also works on subdirectory installs.
 */
final class WellKnown {

	private Metadata $metadata;

	/**
	 * @param Metadata $metadata Discovery documents.
	 */
	public function __construct( Metadata $metadata ) {
		$this->metadata = $metadata;
	}

	/**
	 * The document for a request, or null when the request is not for a well-known path.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Request path without the query string.
	 */
	public function match( string $method, string $path ): ?array {
		if ( ! in_array( strtoupper( $method ), array( 'GET', 'HEAD' ), true ) ) {
			return null;
		}

		$path = rtrim( $path, '/' );
		if ( Metadata::wellKnownPath( 'oauth-authorization-server', $this->metadata->issuer() ) === $path ) {
			return $this->metadata->authorizationServer();
		}
		if ( Metadata::wellKnownPath( 'oauth-protected-resource', $this->metadata->resource() ) === $path ) {
			return $this->metadata->protectedResource();
		}

		return null;
	}

	/**
	 * Hooked to init at priority 1: answers a matching request and stops.
	 */
	public function serve(): void {
		$uri    = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only parsed and compared, never output.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		$path   = (string) wp_parse_url( $uri, PHP_URL_PATH );
		if ( 0 !== strpos( $path, '/.well-known/' ) ) {
			return;
		}

		$document = $this->match( $method, $path );
		if ( null === $document ) {
			return;
		}

		status_header( 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=300' );
		if ( 'HEAD' !== strtoupper( $method ) ) {
			echo wp_json_encode( $document, JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON document.
		}
		exit;
	}
}
