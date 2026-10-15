<?php
/**
 * The OAuth token endpoint.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\OAuth;

use TechThrill\MCP\Auth\Scopes;
use TechThrill\MCP\Auth\TokenManager;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped, Squiz.Commenting.FunctionCommentThrowTag.Missing -- OAuthException messages are plain-text JSON error fields, never printed as HTML; handle() catches every OAuthException it throws.
defined( 'ABSPATH' ) || exit;

/**
 * Handles the authorization_code and refresh_token grants for public clients.
 */
final class TokenEndpoint {

	const LIMIT = 30;

	const WINDOW = 60;

	private ClientResolver $clients;

	private AuthorizationCodeStore $codes;

	private TokenManager $tokens;

	private AttemptLimiter $limiter;

	private string $resourceUrl;

	/**
	 * @param ClientResolver         $clients     Client lookup.
	 * @param AuthorizationCodeStore $codes       Authorization codes.
	 * @param TokenManager           $tokens      Token creation and rotation.
	 * @param AttemptLimiter         $limiter     Per-IP limiter.
	 * @param string                 $resourceUrl The MCP endpoint URL.
	 */
	public function __construct( ClientResolver $clients, AuthorizationCodeStore $codes, TokenManager $tokens, AttemptLimiter $limiter, string $resourceUrl ) {
		$this->clients     = $clients;
		$this->codes       = $codes;
		$this->tokens      = $tokens;
		$this->limiter     = $limiter;
		$this->resourceUrl = $resourceUrl;
	}

	/**
	 * Handles a token request.
	 *
	 * @param array  $form Form-encoded body fields.
	 * @param string $ip   Client IP address, used only for rate limiting.
	 * @return array{status:int,headers:array<string,string>,body:array}
	 */
	public function handle( array $form, string $ip ): array {
		$headers = array(
			'Cache-Control' => 'no-store',
			'Pragma'        => 'no-cache',
		);

		try {
			if ( ! $this->limiter->allow( 'token', $ip, self::LIMIT, self::WINDOW ) ) {
				$headers['Retry-After'] = (string) $this->limiter->retryAfter( 'token', $ip, self::WINDOW );
				throw new OAuthException( 'temporarily_unavailable', 'Too many requests. Try again later.', 429 );
			}

			$grant = $this->field( $form, 'grant_type' );
			if ( 'authorization_code' === $grant ) {
				$body = $this->authorizationCode( $form );
			} elseif ( 'refresh_token' === $grant ) {
				$body = $this->refreshToken( $form );
			} else {
				throw new OAuthException( 'unsupported_grant_type', 'grant_type must be authorization_code or refresh_token.' );
			}

			return array(
				'status'  => 200,
				'headers' => $headers,
				'body'    => $body,
			);
		} catch ( OAuthException $e ) {
			return array(
				'status'  => $e->status(),
				'headers' => $headers,
				'body'    => array(
					'error'             => $e->error(),
					'error_description' => $e->description(),
				),
			);
		}
	}

	/**
	 * The authorization_code grant.
	 *
	 * @param array $form Form fields.
	 * @throws OAuthException On any failure.
	 */
	private function authorizationCode( array $form ): array {
		$code      = $this->field( $form, 'code' );
		$redirect  = $this->field( $form, 'redirect_uri' );
		$client_id = $this->field( $form, 'client_id' );
		$verifier  = $this->field( $form, 'code_verifier' );
		if ( '' === $code || '' === $redirect || '' === $client_id || '' === $verifier ) {
			throw new OAuthException( 'invalid_request', 'code, redirect_uri, client_id and code_verifier are required.' );
		}

		// The code is burned before anything else is checked, so a failed attempt cannot be retried.
		$payload = $this->codes->consume( $code );
		if ( null === $payload ) {
			throw new OAuthException( 'invalid_grant', 'The authorization code is invalid, expired or already used.' );
		}

		$client = $this->clients->find( $client_id );
		if ( null === $client ) {
			throw new OAuthException( 'invalid_client', 'Unknown client.', 401 );
		}
		if ( ! hash_equals( (string) $payload['client_id'], $client_id ) || ! hash_equals( (string) $payload['redirect_uri'], $redirect ) ) {
			throw new OAuthException( 'invalid_grant', 'The code was issued to a different client or redirect URI.' );
		}

		if ( ! Pkce::isValidVerifier( $verifier ) ) {
			throw new OAuthException( 'invalid_request', 'code_verifier is malformed.' );
		}
		if ( ! Pkce::verify( $verifier, (string) $payload['code_challenge'] ) ) {
			throw new OAuthException( 'invalid_grant', 'PKCE verification failed.' );
		}

		$resource_url = $this->field( $form, 'resource' );
		if ( '' !== $resource_url && $resource_url !== $this->resourceUrl ) {
			throw new OAuthException( 'invalid_target', 'The resource must be this MCP endpoint.' );
		}

		$grant = $this->tokens->createOAuth( $client_id, (string) $client['client_name'], (array) $payload['scopes'], (int) $payload['user_id'] );
		$this->clients->markUsed( $client_id );

		return $this->response( $grant );
	}

	/**
	 * The refresh_token grant.
	 *
	 * @param array $form Form fields.
	 * @throws OAuthException On any failure.
	 */
	private function refreshToken( array $form ): array {
		$refresh   = $this->field( $form, 'refresh_token' );
		$client_id = $this->field( $form, 'client_id' );
		if ( '' === $refresh || '' === $client_id ) {
			throw new OAuthException( 'invalid_request', 'refresh_token and client_id are required.' );
		}
		if ( null === $this->clients->find( $client_id ) ) {
			throw new OAuthException( 'invalid_client', 'Unknown client.', 401 );
		}

		$narrow = null;
		$scope  = $this->field( $form, 'scope' );
		if ( '' !== $scope ) {
			$narrow = preg_split( '/\s+/', $scope, -1, PREG_SPLIT_NO_EMPTY );
			foreach ( $narrow as $name ) {
				if ( ! in_array( $name, Scopes::ALL, true ) ) {
					throw new OAuthException( 'invalid_scope', 'Unknown scope.' );
				}
			}
		}

		$grant = $this->tokens->refreshOAuth( $refresh, $client_id, $narrow );
		if ( null === $grant ) {
			throw new OAuthException( 'invalid_grant', 'The refresh token is invalid, expired or revoked.' );
		}

		return $this->response( $grant );
	}

	/**
	 * Token response body.
	 *
	 * @param array $grant Result of TokenManager::createOAuth or refreshOAuth.
	 */
	private function response( array $grant ): array {
		return array(
			'access_token'  => $grant['access_token'],
			'token_type'    => 'Bearer',
			'expires_in'    => $grant['expires_in'],
			'refresh_token' => $grant['refresh_token'],
			'scope'         => implode( ' ', $grant['scopes'] ),
		);
	}

	/**
	 * A string form field, or an empty string when missing or not a string.
	 *
	 * @param array  $form Form fields.
	 * @param string $key  Field.
	 */
	private function field( array $form, string $key ): string {
		return isset( $form[ $key ] ) && is_string( $form[ $key ] ) ? $form[ $key ] : '';
	}
}

// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped, Squiz.Commenting.FunctionCommentThrowTag.Missing
