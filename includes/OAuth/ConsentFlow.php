<?php
/**
 * Decision logic of the consent page.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Turns an /authorize request and the visitor's state into one of: login, error, consent, redirect.
 * Pure, so every branch is unit tested; ConsentPage does the WordPress I/O.
 */
final class ConsentFlow {

	private AuthorizeValidator $validator;

	private Authorizer $authorizer;

	/**
	 * @param AuthorizeValidator $validator  Request validation.
	 * @param Authorizer         $authorizer Approval and denial redirects.
	 */
	public function __construct( AuthorizeValidator $validator, Authorizer $authorizer ) {
		$this->validator  = $validator;
		$this->authorizer = $authorizer;
	}

	/**
	 * Decides what to do.
	 *
	 * @param string   $method       HTTP method, GET or POST.
	 * @param array    $params       Request parameters (query for GET, hidden form fields for POST).
	 * @param bool     $loggedIn     Whether the visitor is logged in.
	 * @param bool     $isAdmin      Whether the visitor has manage_options.
	 * @param bool     $nonceValid   Whether the POST nonce verified.
	 * @param int      $userId       Current user ID.
	 * @param string[] $postedScopes Scopes ticked on the form.
	 * @param string   $decision     approve or deny (POST only).
	 * @return array{type:string,url?:string,message?:string,status?:int,request?:AuthorizationRequest,notice?:string,checked?:string[]}
	 */
	public function handle( string $method, array $params, bool $loggedIn, bool $isAdmin, bool $nonceValid, int $userId, array $postedScopes, string $decision ): array {
		if ( ! $loggedIn ) {
			return array( 'type' => 'login' );
		}
		if ( ! $isAdmin ) {
			return $this->error( 'Only a site administrator can approve a connection.', 403 );
		}

		$post = 'POST' === strtoupper( $method );
		if ( $post && ! $nonceValid ) {
			return $this->error( 'The security check failed. Go back, reload the page and try again.', 403 );
		}

		try {
			$request = $this->validator->validate( $params );
		} catch ( AuthorizeException $e ) {
			$url = $this->authorizer->errorRedirect( $e );

			return null !== $url ? array(
				'type' => 'redirect',
				'url'  => $url,
			) : $this->error( $e->description(), 400 );
		}

		if ( ! $post ) {
			return array(
				'type'    => 'consent',
				'request' => $request,
				'checked' => Authorizer::DEFAULT_SCOPES,
			);
		}

		if ( 'deny' === $decision ) {
			return array(
				'type' => 'redirect',
				'url'  => $this->authorizer->deny( $request ),
			);
		}
		if ( 'approve' !== $decision ) {
			return $this->error( 'Unknown choice.', 400 );
		}

		try {
			return array(
				'type' => 'redirect',
				'url'  => $this->authorizer->approve( $request, $userId, $postedScopes ),
			);
		} catch ( OAuthException $e ) {
			return array(
				'type'    => 'consent',
				'request' => $request,
				'checked' => Authorizer::DEFAULT_SCOPES,
				'notice'  => $e->description(),
			);
		}
	}

	/**
	 * An error page result.
	 *
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 */
	private function error( string $message, int $status ): array {
		return array(
			'type'    => 'error',
			'message' => $message,
			'status'  => $status,
		);
	}
}
