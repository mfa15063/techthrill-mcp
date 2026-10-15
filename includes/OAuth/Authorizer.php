<?php
/**
 * Approval and denial of authorization requests.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\OAuth;

use TechThrill\MCP\Auth\Scopes;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- OAuthException messages are plain-text error fields, never printed as HTML.
defined( 'ABSPATH' ) || exit;

/**
 * Turns the administrator's decision into the redirect URL the client is sent to.
 */
final class Authorizer {

	/**
	 * Scopes pre-ticked on the consent screen: read only.
	 */
	const DEFAULT_SCOPES = array( 'db:read', 'files:read' );

	const CODE_TTL = 60;

	private AuthorizationCodeStore $codes;

	/**
	 * @param AuthorizationCodeStore $codes Code storage.
	 */
	public function __construct( AuthorizationCodeStore $codes ) {
		$this->codes = $codes;
	}

	/**
	 * Issues a code for the chosen scopes.
	 *
	 * @param AuthorizationRequest $request Validated request.
	 * @param int                  $userId  Administrator who approved.
	 * @param string[]             $scopes  Scopes the administrator granted.
	 * @return string Redirect URL carrying the code and state.
	 * @throws OAuthException When no valid scope was granted.
	 */
	public function approve( AuthorizationRequest $request, int $userId, array $scopes ): string {
		$scopes = Scopes::sanitize( $scopes );
		if ( array() === $scopes ) {
			throw new OAuthException( 'invalid_scope', 'Choose at least one permission.' );
		}

		$code = $this->codes->issue(
			array(
				'client_id'      => (string) $request->client()['client_id'],
				'redirect_uri'   => $request->redirectUri(),
				'code_challenge' => $request->codeChallenge(),
				'resource'       => $request->resource(),
				'scopes'         => $scopes,
				'user_id'        => $userId,
			),
			self::CODE_TTL
		);

		return self::redirect(
			$request->redirectUri(),
			array(
				'code'  => $code,
				'state' => $request->state(),
			)
		);
	}

	/**
	 * Redirect URL for a denial.
	 *
	 * @param AuthorizationRequest $request Validated request.
	 */
	public function deny( AuthorizationRequest $request ): string {
		return self::redirect(
			$request->redirectUri(),
			array(
				'error' => 'access_denied',
				'state' => $request->state(),
			)
		);
	}

	/**
	 * Redirect URL for a validation error, or null when the error must be shown on a page instead.
	 *
	 * @param AuthorizeException $e Exception from the validator.
	 */
	public function errorRedirect( AuthorizeException $e ): ?string {
		if ( null === $e->redirectUri() ) {
			return null;
		}

		return self::redirect(
			$e->redirectUri(),
			array(
				'error'             => $e->error(),
				'error_description' => $e->description(),
				'state'             => $e->state(),
			)
		);
	}

	/**
	 * Appends query parameters, keeping any the URI already has. Empty values are left out.
	 *
	 * @param string               $uri    Base URI.
	 * @param array<string,string> $params Parameters.
	 */
	private static function redirect( string $uri, array $params ): string {
		$query = http_build_query( array_filter( $params, static fn( $v ) => '' !== $v ), '', '&', PHP_QUERY_RFC3986 );

		return $uri . ( false === strpos( $uri, '?' ) ? '?' : '&' ) . $query;
	}
}

// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
