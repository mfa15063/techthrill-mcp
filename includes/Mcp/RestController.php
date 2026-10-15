<?php
/**
 * REST endpoint that speaks MCP over JSON-RPC.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Mcp;

use TechThrill\MCP\Auth\Authenticator;
use TechThrill\MCP\Tools\ToolContext;

defined( 'ABSPATH' ) || exit;

/**
 * Maps HTTP requests on POST|GET /wp-json/techthrill-mcp/v1/mcp to the MCP server.
 *
 * The bearer token is the only credential. Authentication happens inside the handler
 * (not in a permission callback) so that failures are JSON-RPC shaped.
 */
final class RestController {

	const REST_NAMESPACE = 'techthrill-mcp/v1';

	const ROUTE = '/mcp';

	const GET_MESSAGE = 'This is an MCP endpoint. Connect with an MCP client using a bearer token. See https://techthrill.com/mcp';

	/**
	 * Bearer token authentication.
	 *
	 * @var Authenticator
	 */
	private $auth;

	/**
	 * MCP server.
	 *
	 * @var Server
	 */
	private $server;

	/**
	 * Called with the token id once a request is authenticated, before the server runs.
	 *
	 * @var callable|null
	 */
	private $on_authenticated;

	/**
	 * Builds the WWW-Authenticate value for a 401: fn( bool $invalidToken ): string.
	 *
	 * @var callable|null
	 */
	private $challenge;

	/**
	 * @param Authenticator $auth             Bearer token authentication.
	 * @param Server        $server           MCP server.
	 * @param callable|null $on_authenticated Optional fn( int $token_id ): void.
	 * @param callable|null $challenge        Optional fn( bool $invalidToken ): string for the WWW-Authenticate header on 401 responses.
	 */
	public function __construct( Authenticator $auth, Server $server, ?callable $on_authenticated = null, ?callable $challenge = null ) {
		$this->auth             = $auth;
		$this->server           = $server;
		$this->on_authenticated = $on_authenticated;
		$this->challenge        = $challenge;
	}

	/**
	 * Registers the route and the cookie-auth bypass. Hooked to rest_api_init.
	 */
	public function register(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => array( 'POST', 'GET' ),
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
				'show_in_index'       => false,
			)
		);

		add_filter( 'rest_authentication_errors', array( $this, 'bypassCookieAuth' ), 100 );
		add_filter( 'rest_request_before_callbacks', array( $this, 'allowInvalidJson' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( $this, 'fixAllowHeader' ), 20, 3 );
	}

	/**
	 * Lets malformed JSON reach handle(), which answers with a JSON-RPC parse error after authenticating.
	 *
	 * WordPress rejects invalid JSON with a rest_invalid_json error before the route callback runs.
	 *
	 * @param mixed            $response Response or error so far.
	 * @param mixed            $handler  Matched route handler.
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed Null for an invalid-JSON error on the MCP route, otherwise $response unchanged.
	 */
	public function allowInvalidJson( $response, $handler, $request ) {
		if (
			$response instanceof \WP_Error
			&& 'rest_invalid_json' === $response->get_error_code()
			&& $request instanceof \WP_REST_Request
			&& $this->is_mcp_request_route( $request )
		) {
			return null;
		}

		return $response;
	}

	/**
	 * Restores `Allow: POST` on the 405 response; core rewrites it to the route's registered methods.
	 *
	 * @param mixed            $response Response.
	 * @param mixed            $server   REST server.
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public function fixAllowHeader( $response, $server, $request ) {
		if (
			is_object( $response )
			&& method_exists( $response, 'get_status' )
			&& method_exists( $response, 'header' )
			&& 405 === $response->get_status()
			&& $request instanceof \WP_REST_Request
			&& $this->is_mcp_request_route( $request )
		) {
			$response->header( 'Allow', 'POST' );
		}

		return $response;
	}

	/**
	 * Stops cookie/nonce checks from rejecting MCP requests, which carry a bearer token instead.
	 *
	 * @param mixed $result Value from earlier authentication filters.
	 * @return mixed True for the MCP route, otherwise $result unchanged.
	 */
	public function bypassCookieAuth( $result ) {
		return $this->is_mcp_route() ? true : $result;
	}

	/**
	 * Handles one request.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		if ( 'POST' !== strtoupper( (string) $request->get_method() ) ) {
			$response = new \WP_REST_Response( array( 'error' => self::GET_MESSAGE ), 405 );
			$response->header( 'Allow', 'POST' );

			return $response;
		}

		try {
			return $this->handle_post( $request );
		} catch ( \Throwable $e ) {
			error_log( 'TechThrill MCP: ' . get_class( $e ) . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			return new \WP_REST_Response( JsonRpcError::envelope( null, JsonRpcError::INTERNAL, 'Internal error' ), 200 );
		}
	}

	/**
	 * Authenticates, parses and dispatches a POST request.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	private function handle_post( \WP_REST_Request $request ): \WP_REST_Response {
		// Forget any token id from earlier in this process so a failed authentication never inherits one.
		$this->notify_token( 0 );

		$auth = $this->auth->authenticate( $this->authorization_header( $request ) );
		if ( ! $auth->isOk() ) {
			$response = new \WP_REST_Response( JsonRpcError::envelope( null, $auth->code(), $auth->message() ), $auth->status() );
			if ( $auth->retryAfter() > 0 ) {
				$response->header( 'Retry-After', (string) $auth->retryAfter() );
			}
			// A 401 (never a 200) tells OAuth clients where to find the authorization server.
			if ( 401 === $auth->status() && null !== $this->challenge ) {
				$response->header( 'WWW-Authenticate', (string) call_user_func( $this->challenge, 'invalid' === $auth->reason() ) );
			}

			return $response;
		}

		$decoded = json_decode( (string) $request->get_body(), true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new \WP_REST_Response( JsonRpcError::envelope( null, JsonRpcError::PARSE, 'Parse error' ), 400 );
		}

		// Tools and capability checks must run as the token's owner.
		$row = $auth->row();
		wp_set_current_user( (int) $row['user_id'] );

		$token_id = (int) $row['id'];
		$this->notify_token( $token_id );

		$ctx    = new ToolContext( $token_id, (int) $row['user_id'], $auth->scopes() );
		$result = $this->server->handle( $decoded, $ctx );

		// A null body (notification) is sent by WordPress as an empty body.
		$response = new \WP_REST_Response( $result->body, null === $result->body ? 202 : $result->status );

		$protocol = $request->get_header( 'mcp_protocol_version' );
		if ( is_string( $protocol ) && '' !== $protocol ) {
			$response->header( 'MCP-Protocol-Version', sanitize_text_field( $protocol ) );
		}

		return $response;
	}

	/**
	 * Returns the Authorization header, falling back to the value some Apache/CGI setups rename.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	private function authorization_header( \WP_REST_Request $request ): ?string {
		$header = $request->get_header( 'authorization' );
		if ( is_string( $header ) && '' !== $header ) {
			return $header;
		}

		if ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) && is_string( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		}

		return null;
	}

	/**
	 * Whether the current REST request targets the MCP route.
	 */
	private function is_mcp_route(): bool {
		$target = '/' . self::REST_NAMESPACE . self::ROUTE;

		$wp = $GLOBALS['wp'] ?? null;
		if ( ! is_object( $wp ) || ! isset( $wp->query_vars['rest_route'] ) || ! is_string( $wp->query_vars['rest_route'] ) ) {
			return false;
		}

		return '/' . trim( $wp->query_vars['rest_route'], '/' ) === $target;
	}

	/**
	 * Whether a dispatched request matched the MCP route.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	private function is_mcp_request_route( \WP_REST_Request $request ): bool {
		return '/' . self::REST_NAMESPACE . self::ROUTE === $request->get_route();
	}

	/**
	 * Reports the token id of the request being handled (0 when none) to the observer.
	 *
	 * @param int $token_id Token id.
	 */
	private function notify_token( int $token_id ): void {
		if ( null !== $this->on_authenticated ) {
			call_user_func( $this->on_authenticated, $token_id );
		}
	}
}
