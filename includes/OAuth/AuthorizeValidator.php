<?php
/**
 * Validation of /authorize requests.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\OAuth;

use TechThrill\MCP\Auth\Scopes;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain-text error fields; the consent page escapes them when rendering.
defined( 'ABSPATH' ) || exit;

/**
 * Checks the client and redirect URI first (an untrusted target is never redirected to), then everything else.
 */
final class AuthorizeValidator {

	const MAX_STATE_LENGTH = 1024;

	private ClientResolver $clients;

	private string $resourceUrl;

	/**
	 * @param ClientResolver $clients     Client lookup.
	 * @param string         $resourceUrl The MCP endpoint URL, the only valid resource indicator.
	 */
	public function __construct( ClientResolver $clients, string $resourceUrl ) {
		$this->clients     = $clients;
		$this->resourceUrl = $resourceUrl;
	}

	/**
	 * Validates request parameters.
	 *
	 * @param array $params Query or form parameters.
	 * @throws AuthorizeException With a redirect URI when the error may be sent to the client.
	 */
	public function validate( array $params ): AuthorizationRequest {
		$client_id = $this->string( $params, 'client_id' );
		$client    = '' === $client_id ? null : $this->clients->find( $client_id );
		if ( null === $client ) {
			throw new AuthorizeException( 'invalid_client', 'Unknown client.' );
		}

		$redirect = $this->string( $params, 'redirect_uri' );
		if ( '' === $redirect || ! in_array( $redirect, (array) $client['redirect_uris'], true ) || ! RedirectPolicy::isAllowed( $redirect ) ) {
			throw new AuthorizeException( 'invalid_request', 'The redirect URI is not registered for this client.' );
		}

		// From here on the redirect URI is trusted, so errors go back to the client.
		$state = $this->string( $params, 'state' );
		if ( strlen( $state ) > self::MAX_STATE_LENGTH ) {
			throw new AuthorizeException( 'invalid_request', 'state is too long.', $redirect );
		}

		if ( 'code' !== $this->string( $params, 'response_type' ) ) {
			throw new AuthorizeException( 'invalid_request', 'response_type must be code.', $redirect, $state );
		}
		if ( 'S256' !== $this->string( $params, 'code_challenge_method' ) ) {
			throw new AuthorizeException( 'invalid_request', 'PKCE with code_challenge_method S256 is required.', $redirect, $state );
		}
		$challenge = $this->string( $params, 'code_challenge' );
		if ( ! Pkce::isValidChallenge( $challenge ) ) {
			throw new AuthorizeException( 'invalid_request', 'code_challenge is missing or malformed.', $redirect, $state );
		}

		$resource_url = $this->string( $params, 'resource' );
		if ( '' !== $resource_url && $resource_url !== $this->resourceUrl ) {
			throw new AuthorizeException( 'invalid_target', 'The resource must be this MCP endpoint.', $redirect, $state );
		}

		$scopes = array();
		foreach ( preg_split( '/\s+/', $this->string( $params, 'scope' ), -1, PREG_SPLIT_NO_EMPTY ) as $scope ) {
			if ( ! in_array( $scope, Scopes::ALL, true ) ) {
				throw new AuthorizeException( 'invalid_scope', 'Unknown scope.', $redirect, $state );
			}
			$scopes[] = $scope;
		}

		return new AuthorizationRequest( $client, $redirect, $state, $challenge, array_values( array_unique( $scopes ) ), $resource_url );
	}

	/**
	 * A string parameter, or an empty string when missing or not a string.
	 *
	 * @param array  $params Parameters.
	 * @param string $key    Key.
	 */
	private function string( array $params, string $key ): string {
		return isset( $params[ $key ] ) && is_string( $params[ $key ] ) ? $params[ $key ] : '';
	}
}

// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
