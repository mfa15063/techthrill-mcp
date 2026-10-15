<?php
/**
 * REST routes of the authorization server.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Discovery documents, dynamic client registration and the token endpoint, under techthrill-mcp/v1/oauth.
 */
final class RestRoutes {

	const REST_NAMESPACE = 'techthrill-mcp/v1';

	const BASE = '/oauth';

	private Metadata $metadata;

	private ClientRegistrar $registrar;

	private TokenEndpoint $token;

	private AgentAccess $agent;

	/**
	 * Builds the approval link for a request ID.
	 *
	 * @var callable
	 */
	private $approvalUrl;

	/**
	 * Returns the client IP address.
	 *
	 * @var callable
	 */
	private $ip;

	/**
	 * @param Metadata        $metadata  Discovery documents.
	 * @param ClientRegistrar $registrar Dynamic client registration.
	 * @param TokenEndpoint   $token     Token endpoint.
	 * @param callable        $ip        Returns the client IP address.
	 * @param AgentAccess     $agent       Agent access requests.
	 * @param callable        $approvalUrl Builds the approval link: fn( string $requestId ): string.
	 */
	public function __construct( Metadata $metadata, ClientRegistrar $registrar, TokenEndpoint $token, callable $ip, AgentAccess $agent, callable $approvalUrl ) {
		$this->metadata    = $metadata;
		$this->registrar   = $registrar;
		$this->token       = $token;
		$this->ip          = $ip;
		$this->agent       = $agent;
		$this->approvalUrl = $approvalUrl;
	}

	/**
	 * Registers the routes and the cookie-auth bypass. Hooked to rest_api_init.
	 */
	public function register(): void {
		$routes = array(
			'/protected-resource'   => array( 'GET', 'protectedResource' ),
			'/authorization-server' => array( 'GET', 'authorizationServer' ),
			'/register'             => array( 'POST', 'registerClient' ),
			'/token'                => array( 'POST', 'issueToken' ),
			'/agent/start'          => array( 'POST', 'agentStart' ),
			'/agent/poll'           => array( 'POST', 'agentPoll' ),
		);
		foreach ( $routes as $path => $route ) {
			register_rest_route(
				self::REST_NAMESPACE,
				self::BASE . $path,
				array(
					'methods'             => $route[0],
					'callback'            => array( $this, $route[1] ),
					'permission_callback' => '__return_true',
					'show_in_index'       => false,
				)
			);
		}

		add_filter( 'rest_authentication_errors', array( $this, 'bypassCookieAuth' ), 100 );
	}

	/**
	 * Keeps cookie/nonce checks and REST lockdown plugins from rejecting these public endpoints.
	 *
	 * @param mixed $result Value from earlier authentication filters.
	 * @return mixed True for these routes, otherwise $result unchanged.
	 */
	public function bypassCookieAuth( $result ) {
		$wp = $GLOBALS['wp'] ?? null;
		if ( ! is_object( $wp ) || ! isset( $wp->query_vars['rest_route'] ) || ! is_string( $wp->query_vars['rest_route'] ) ) {
			return $result;
		}

		$route = '/' . trim( $wp->query_vars['rest_route'], '/' );

		return 0 === strpos( $route, '/' . self::REST_NAMESPACE . self::BASE . '/' ) ? true : $result;
	}

	/**
	 * GET protected-resource document.
	 */
	public function protectedResource(): \WP_REST_Response {
		return $this->document( $this->metadata->protectedResource() );
	}

	/**
	 * GET authorization-server document.
	 */
	public function authorizationServer(): \WP_REST_Response {
		return $this->document( $this->metadata->authorizationServer() );
	}

	/**
	 * POST dynamic client registration (JSON body).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function registerClient( \WP_REST_Request $request ): \WP_REST_Response {
		$data = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $data ) ) {
			return $this->noStore(
				array(
					'error'             => 'invalid_client_metadata',
					'error_description' => 'The body must be a JSON object.',
				),
				400
			);
		}

		try {
			return $this->noStore( $this->registrar->register( $data, (string) call_user_func( $this->ip ) ), 201 );
		} catch ( OAuthException $e ) {
			return $this->noStore(
				array(
					'error'             => $e->error(),
					'error_description' => $e->description(),
				),
				$e->status()
			);
		}
	}

	/**
	 * POST token endpoint (form-encoded body).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function issueToken( \WP_REST_Request $request ): \WP_REST_Response {
		$content_type = strtolower( (string) $request->get_header( 'content_type' ) );
		$body         = (string) $request->get_body();
		$form         = array();
		if ( false !== strpos( $content_type, 'json' ) ) {
			$decoded = json_decode( $body, true );
			$form    = is_array( $decoded ) ? $decoded : array();
		} else {
			parse_str( $body, $form );
		}

		$result   = $this->token->handle( $form, (string) call_user_func( $this->ip ) );
		$response = new \WP_REST_Response( $result['body'], $result['status'] );
		foreach ( $result['headers'] as $name => $value ) {
			$response->header( $name, $value );
		}

		return $response;
	}

	/**
	 * POST agent/start: an AI agent asks for access and gets an approval link and a short code.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function agentStart( \WP_REST_Request $request ): \WP_REST_Response {
		$data  = json_decode( (string) $request->get_body(), true );
		$label = is_array( $data ) && isset( $data['label'] ) && is_string( $data['label'] ) ? $data['label'] : '';

		try {
			$started                 = $this->agent->start( $label, (string) call_user_func( $this->ip ) );
			$started['approval_url'] = (string) call_user_func( $this->approvalUrl, $started['request_id'] );
			$started['mcp_url']      = $this->metadata->resource();

			return $this->noStore( $started, 201 );
		} catch ( OAuthException $e ) {
			return $this->noStore(
				array(
					'error'             => $e->error(),
					'error_description' => $e->description(),
				),
				$e->status()
			);
		}
	}

	/**
	 * POST agent/poll: the agent asks whether the administrator answered.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function agentPoll( \WP_REST_Request $request ): \WP_REST_Response {
		$data = json_decode( (string) $request->get_body(), true );
		$id   = is_array( $data ) && isset( $data['request_id'] ) && is_string( $data['request_id'] ) ? $data['request_id'] : '';
		$code = is_array( $data ) && isset( $data['poll_code'] ) && is_string( $data['poll_code'] ) ? $data['poll_code'] : '';

		try {
			$response = $this->noStore( $this->agent->poll( $id, $code, (string) call_user_func( $this->ip ) ), 200 );
			if ( 'pending' === ( $response->get_data()['status'] ?? '' ) ) {
				$response->header( 'Retry-After', (string) AgentAccess::INTERVAL );
			}

			return $response;
		} catch ( OAuthException $e ) {
			return $this->noStore(
				array(
					'error'             => $e->error(),
					'error_description' => $e->description(),
				),
				$e->status()
			);
		}
	}

	/**
	 * A cacheable discovery document response.
	 *
	 * @param array $document Document.
	 */
	private function document( array $document ): \WP_REST_Response {
		$response = new \WP_REST_Response( $document, 200 );
		$response->header( 'Cache-Control', 'public, max-age=300' );

		return $response;
	}

	/**
	 * A response that must not be cached.
	 *
	 * @param array $data   Body.
	 * @param int   $status Status.
	 */
	private function noStore( array $data, int $status ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );

		return $response;
	}
}
