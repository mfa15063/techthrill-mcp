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
	 */
	public function __construct( Metadata $metadata, ClientRegistrar $registrar, TokenEndpoint $token, callable $ip ) {
		$this->metadata  = $metadata;
		$this->registrar = $registrar;
		$this->token     = $token;
		$this->ip        = $ip;
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
