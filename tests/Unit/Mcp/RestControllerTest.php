<?php
namespace TechThrill\MCP\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\Auth\Authenticator;
use TechThrill\MCP\Auth\RateLimiter;
use TechThrill\MCP\Auth\TokenManager;
use TechThrill\MCP\Mcp\JsonRpcError;
use TechThrill\MCP\Mcp\RestController;
use TechThrill\MCP\Mcp\SchemaValidator;
use TechThrill\MCP\Mcp\Server;
use TechThrill\MCP\Tests\Support\InMemoryRateLimitStore;
use TechThrill\MCP\Tests\Support\InMemoryTokenRepository;
use TechThrill\MCP\Tools\ToolContext;
use TechThrill\MCP\Tools\ToolInterface;
use TechThrill\MCP\Tools\ToolRegistry;
use TechThrill\MCP\Tools\ToolResult;

class RestControllerTest extends TestCase {

	const INITIALIZE = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}';

	/**
	 * @var int
	 */
	private $now = 1700000000;

	/**
	 * @var TokenManager
	 */
	private $tokens;

	/**
	 * Plain bearer token of an administrator (user 7) with the db:read scope.
	 *
	 * @var string
	 */
	private $plain;

	/**
	 * @var int
	 */
	private $token_id;

	/**
	 * Token ids passed to the on-authenticated callback.
	 *
	 * @var int[]
	 */
	private $seen_token_ids = array();

	/**
	 * @var ToolRegistry
	 */
	private $registry;

	protected function setUp(): void {
		$this->tokens   = new TokenManager( new InMemoryTokenRepository(), fn() => $this->now );
		$created        = $this->tokens->create( 'test', array( 'db:read' ), 7, null );
		$this->plain    = $created['token'];
		$this->token_id = $created['id'];
		$this->registry = new ToolRegistry();

		$this->seen_token_ids = array();
		unset( $GLOBALS['ttm_current_user'], $GLOBALS['ttm_registered_routes'], $GLOBALS['ttm_filters'], $GLOBALS['wp'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ttm_current_user'], $GLOBALS['ttm_registered_routes'], $GLOBALS['ttm_filters'], $GLOBALS['wp'], $_SERVER['REQUEST_URI'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
	}

	private function controller( int $limit = 100 ): RestController {
		$limiter = new RateLimiter( $limit, new InMemoryRateLimitStore(), fn() => $this->now );
		$auth    = new Authenticator( $this->tokens, $limiter, fn( int $user_id ) => true );
		$server  = new Server( $this->registry, new SchemaValidator(), '1.0.0' );

		return new RestController(
			$auth,
			$server,
			function ( int $token_id ): void {
				$this->seen_token_ids[] = $token_id;
			}
		);
	}

	private function post( string $body, ?string $bearer = 'valid', array $headers = array() ): \WP_REST_Request {
		if ( 'valid' === $bearer ) {
			$bearer = $this->plain;
		}
		if ( null !== $bearer ) {
			$headers['Authorization'] = 'Bearer ' . $bearer;
		}

		return new \WP_REST_Request( 'POST', $headers, $body );
	}

	public function test_get_returns_405_with_allow_header(): void {
		$response = $this->controller()->handle( new \WP_REST_Request( 'GET' ) );

		$this->assertSame( 405, $response->get_status() );
		$this->assertSame( 'POST', $response->get_headers()['Allow'] );
		$this->assertSame(
			array( 'error' => 'This is an MCP endpoint. Connect with an MCP client using a bearer token. See https://techthrill.com/mcp' ),
			$response->get_data()
		);
	}

	public function test_post_without_authorization_is_401_json_rpc_error(): void {
		$response = $this->controller()->handle( $this->post( self::INITIALIZE, null ) );

		$this->assertSame( 401, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( '2.0', $body['jsonrpc'] );
		$this->assertNull( $body['id'] );
		$this->assertSame( JsonRpcError::UNAUTHORIZED, $body['error']['code'] );
		$this->assertArrayNotHasKey( 'Retry-After', $response->get_headers() );
		$this->assertArrayNotHasKey( 'ttm_current_user', $GLOBALS );
	}

	public function test_post_with_unknown_token_is_401(): void {
		$response = $this->controller()->handle( $this->post( self::INITIALIZE, 'ttm_' . str_repeat( 'z', 40 ) ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( JsonRpcError::UNAUTHORIZED, $response->get_data()['error']['code'] );
	}

	public function test_post_rate_limited_is_429_with_retry_after(): void {
		$controller = $this->controller( 1 );

		$first = $controller->handle( $this->post( self::INITIALIZE ) );
		$this->assertSame( 200, $first->get_status() );

		$second = $controller->handle( $this->post( self::INITIALIZE ) );
		$this->assertSame( 429, $second->get_status() );
		$this->assertSame( JsonRpcError::RATE_LIMITED, $second->get_data()['error']['code'] );
		$this->assertArrayHasKey( 'Retry-After', $second->get_headers() );
		$this->assertGreaterThan( 0, (int) $second->get_headers()['Retry-After'] );
	}

	public function test_invalid_json_is_400_parse_error(): void {
		foreach ( array( '{not json', '' ) as $body ) {
			$response = $this->controller()->handle( $this->post( $body ) );

			$this->assertSame( 400, $response->get_status(), $body );
			$data = $response->get_data();
			$this->assertSame( JsonRpcError::PARSE, $data['error']['code'] );
			$this->assertSame( 'Parse error', $data['error']['message'] );
			$this->assertNull( $data['id'] );
		}
	}

	public function test_initialize_returns_server_info(): void {
		$response = $this->controller()->handle( $this->post( self::INITIALIZE ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 1, $data['id'] );
		$this->assertSame( 'techthrill-mcp', $data['result']['serverInfo']['name'] );
	}

	public function test_tools_list_is_empty_without_registered_tools(): void {
		$body     = '{"jsonrpc":"2.0","id":2,"method":"tools/list"}';
		$response = $this->controller()->handle( $this->post( $body ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data()['result']['tools'] );
	}

	public function test_notification_is_202_with_null_body(): void {
		$body     = '{"jsonrpc":"2.0","method":"notifications/initialized"}';
		$response = $this->controller()->handle( $this->post( $body ) );

		$this->assertSame( 202, $response->get_status() );
		$this->assertNull( $response->get_data() );
	}

	public function test_protocol_version_header_is_echoed(): void {
		$response = $this->controller()->handle( $this->post( self::INITIALIZE, 'valid', array( 'MCP-Protocol-Version' => '2025-06-18' ) ) );
		$this->assertSame( '2025-06-18', $response->get_headers()['MCP-Protocol-Version'] );

		$response = $this->controller()->handle( $this->post( self::INITIALIZE ) );
		$this->assertArrayNotHasKey( 'MCP-Protocol-Version', $response->get_headers() );
	}

	public function test_current_user_and_token_id_are_set_before_the_tool_runs(): void {
		$seen = new \stdClass();
		$this->registry->register(
			new class( $seen ) implements ToolInterface {
				private $seen;

				public function __construct( $seen ) {
					$this->seen = $seen;
				}

				public function name(): string {
					return 'probe';
				}

				public function description(): string {
					return 'Records the current user.';
				}

				public function inputSchema(): array {
					return array( 'type' => 'object' );
				}

				public function scope(): ?string {
					return null;
				}

				public function execute( array $args, ToolContext $ctx ): ToolResult {
					$this->seen->user     = $GLOBALS['ttm_current_user'] ?? null;
					$this->seen->token_id = $ctx->tokenId();
					$this->seen->user_id  = $ctx->userId();
					$this->seen->scopes   = $ctx->scopes();

					return ToolResult::ok( 'done' );
				}
			}
		);

		$body     = '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"probe","arguments":{}}}';
		$response = $this->controller()->handle( $this->post( $body ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 7, $seen->user );
		$this->assertSame( 7, $seen->user_id );
		$this->assertSame( $this->token_id, $seen->token_id );
		$this->assertSame( array( 'db:read' ), $seen->scopes );
		$this->assertSame( array( 0, $this->token_id ), $this->seen_token_ids );
	}

	public function test_exception_from_the_server_becomes_internal_error(): void {
		$this->registry->register(
			new class() implements ToolInterface {
				public function name(): string {
					return 'broken';
				}

				public function description(): string {
					return 'Throws while being listed.';
				}

				public function inputSchema(): array {
					throw new \RuntimeException( 'boom' );
				}

				public function scope(): ?string {
					return null;
				}

				public function execute( array $args, ToolContext $ctx ): ToolResult {
					return ToolResult::ok( 'unreachable' );
				}
			}
		);

		$log_file = tempnam( sys_get_temp_dir(), 'ttm' );
		$previous = ini_set( 'error_log', $log_file );

		$response = $this->controller()->handle( $this->post( '{"jsonrpc":"2.0","id":4,"method":"tools/list"}' ) );

		ini_set( 'error_log', (string) $previous );
		$logged = (string) file_get_contents( $log_file );
		unlink( $log_file );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( JsonRpcError::INTERNAL, $data['error']['code'] );
		$this->assertSame( 'Internal error', $data['error']['message'] );
		$this->assertNull( $data['id'] );
		$this->assertStringContainsString( 'boom', $logged );
	}

	public function test_redirected_authorization_header_is_used_as_fallback(): void {
		$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer ' . $this->plain;

		$response = $this->controller()->handle( $this->post( self::INITIALIZE, null ) );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_register_adds_the_route_and_auth_filter(): void {
		$this->controller()->register();

		$this->assertCount( 1, $GLOBALS['ttm_registered_routes'] );
		list( $namespace, $route, $args ) = $GLOBALS['ttm_registered_routes'][0];
		$this->assertSame( 'techthrill-mcp/v1', $namespace );
		$this->assertSame( '/mcp', $route );
		$this->assertSame( array( 'POST', 'GET' ), $args['methods'] );
		$this->assertSame( '__return_true', $args['permission_callback'] );
		$this->assertFalse( $args['show_in_index'] );
		$this->assertCount( 1, $GLOBALS['ttm_filters']['rest_authentication_errors'] );
		$this->assertCount( 1, $GLOBALS['ttm_filters']['rest_request_before_callbacks'] );
		$this->assertCount( 1, $GLOBALS['ttm_filters']['rest_post_dispatch'] );
	}

	public function test_cookie_auth_is_bypassed_only_for_the_mcp_route(): void {
		$controller = $this->controller();
		$error      = new \stdClass();

		$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'rest_route' => '/techthrill-mcp/v1/mcp' ) );
		$this->assertTrue( $controller->bypassCookieAuth( $error ) );

		$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'rest_route' => '/wp/v2/posts' ) );
		$this->assertSame( $error, $controller->bypassCookieAuth( $error ) );
		$this->assertNull( $controller->bypassCookieAuth( null ) );

		// Without a parsed rest_route the value is left alone, whatever REQUEST_URI says.
		$_SERVER['REQUEST_URI'] = '/wp-json/techthrill-mcp/v1/mcp';
		$GLOBALS['wp']          = (object) array( 'query_vars' => array() );
		$this->assertSame( $error, $controller->bypassCookieAuth( $error ) );

		unset( $GLOBALS['wp'] );
		$this->assertSame( $error, $controller->bypassCookieAuth( $error ) );
		$this->assertNull( $controller->bypassCookieAuth( null ) );
	}

	public function test_invalid_json_error_is_let_through_on_the_mcp_route_only(): void {
		$controller = $this->controller();
		$error      = new \WP_Error( 'rest_invalid_json', 'Invalid JSON body passed.', array( 'status' => 400 ) );
		$mcp        = new \WP_REST_Request( 'POST', array(), '{bad', '/techthrill-mcp/v1/mcp' );
		$other      = new \WP_REST_Request( 'POST', array(), '{bad', '/wp/v2/posts' );

		$this->assertNull( $controller->allowInvalidJson( $error, array(), $mcp ) );
		$this->assertSame( $error, $controller->allowInvalidJson( $error, array(), $other ) );

		$other_error = new \WP_Error( 'rest_forbidden', 'Nope.' );
		$this->assertSame( $other_error, $controller->allowInvalidJson( $other_error, array(), $mcp ) );

		$ok = new \WP_REST_Response( array(), 200 );
		$this->assertSame( $ok, $controller->allowInvalidJson( $ok, array(), $mcp ) );
		$this->assertNull( $controller->allowInvalidJson( null, array(), $mcp ) );
	}

	public function test_allow_header_is_restored_on_the_405_for_the_mcp_route_only(): void {
		$controller = $this->controller();
		$mcp        = new \WP_REST_Request( 'GET', array(), '', '/techthrill-mcp/v1/mcp' );
		$other      = new \WP_REST_Request( 'GET', array(), '', '/wp/v2/posts' );

		$response = new \WP_REST_Response( array(), 405 );
		$response->header( 'Allow', 'POST, GET' );
		$this->assertSame( $response, $controller->fixAllowHeader( $response, null, $mcp ) );
		$this->assertSame( 'POST', $response->get_headers()['Allow'] );

		$other_route = new \WP_REST_Response( array(), 405 );
		$other_route->header( 'Allow', 'POST, GET' );
		$controller->fixAllowHeader( $other_route, null, $other );
		$this->assertSame( 'POST, GET', $other_route->get_headers()['Allow'] );

		$not_405 = new \WP_REST_Response( array(), 200 );
		$not_405->header( 'Allow', 'POST, GET' );
		$controller->fixAllowHeader( $not_405, null, $mcp );
		$this->assertSame( 'POST, GET', $not_405->get_headers()['Allow'] );
	}

	public function test_token_id_is_reset_to_zero_before_authentication(): void {
		$this->controller()->handle( $this->post( self::INITIALIZE, 'ttm_' . str_repeat( 'z', 40 ) ) );
		$this->assertSame( array( 0 ), $this->seen_token_ids );

		$this->controller()->handle( $this->post( self::INITIALIZE, null ) );
		$this->assertSame( array( 0, 0 ), $this->seen_token_ids );
		$this->assertSame( 0, end( $this->seen_token_ids ) );
	}
}
