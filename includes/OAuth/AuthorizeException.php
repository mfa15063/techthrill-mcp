<?php
/**
 * An /authorize error.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Carries the trusted redirect URI and state when the error may be sent back to the client.
 * A null redirect URI means the client or URI is untrusted and an error page must be shown instead.
 */
final class AuthorizeException extends OAuthException {

	private ?string $redirectUri;

	private string $state;

	/**
	 * @param string      $error       Error code.
	 * @param string      $description Description.
	 * @param string|null $redirectUri Trusted redirect URI, or null to render an error page.
	 * @param string      $state       State to return to the client.
	 */
	public function __construct( string $error, string $description, ?string $redirectUri = null, string $state = '' ) {
		parent::__construct( $error, $description, 400 );
		$this->redirectUri = $redirectUri;
		$this->state       = $state;
	}

	public function redirectUri(): ?string {
		return $this->redirectUri;
	}

	public function state(): string {
		return $this->state;
	}
}
