<?php
/**
 * Decision logic of the agent consent page.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Turns an agent access request and the visitor's state into one of: login, error, consent, done.
 */
final class AgentConsentFlow {

	private AgentAccess $agent;

	/**
	 * @param AgentAccess $agent Access requests.
	 */
	public function __construct( AgentAccess $agent ) {
		$this->agent = $agent;
	}

	/**
	 * Decides what to do.
	 *
	 * @param string   $method       GET or POST.
	 * @param string   $requestId    Request ID from the approval link.
	 * @param bool     $loggedIn     Whether the visitor is logged in.
	 * @param bool     $isAdmin      Whether the visitor has manage_options.
	 * @param bool     $nonceValid   Whether the POST nonce verified.
	 * @param int      $userId       Current user ID.
	 * @param string[] $postedScopes Scopes ticked on the form.
	 * @param string   $decision     approve or deny (POST only).
	 * @return array{type:string,message?:string,status?:int,view?:array,checked?:string[],notice?:string,outcome?:string}
	 */
	public function handle( string $method, string $requestId, bool $loggedIn, bool $isAdmin, bool $nonceValid, int $userId, array $postedScopes, string $decision ): array {
		if ( ! $loggedIn ) {
			return array( 'type' => 'login' );
		}
		if ( ! $isAdmin ) {
			return $this->error( 'Only a site administrator can approve an AI agent.', 403 );
		}

		$post = 'POST' === strtoupper( $method );
		if ( $post && ! $nonceValid ) {
			return $this->error( 'The security check failed. Go back, reload the page and try again.', 403 );
		}

		$view = $this->agent->find( $requestId );
		if ( null === $view ) {
			return $this->error( 'This request has expired or was already answered. Ask your AI agent to start again.', 404 );
		}

		if ( ! $post ) {
			return $this->consent( $view, '' );
		}

		if ( 'deny' === $decision ) {
			$this->agent->deny( $requestId );

			return array(
				'type'    => 'done',
				'outcome' => 'denied',
			);
		}
		if ( 'approve' !== $decision ) {
			return $this->error( 'Unknown choice.', 400 );
		}

		try {
			$this->agent->approve( $requestId, $userId, $postedScopes );
		} catch ( OAuthException $e ) {
			return 'invalid_scope' === $e->error() ? $this->consent( $view, $e->description() ) : $this->error( $e->description(), 400 );
		}

		return array(
			'type'    => 'done',
			'outcome' => 'approved',
		);
	}

	/**
	 * The consent screen result.
	 *
	 * @param array  $view   What the page shows.
	 * @param string $notice Optional message above the form.
	 */
	private function consent( array $view, string $notice ): array {
		$result = array(
			'type'    => 'consent',
			'view'    => $view,
			'checked' => Authorizer::DEFAULT_SCOPES,
		);
		if ( '' !== $notice ) {
			$result['notice'] = $notice;
		}

		return $result;
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
