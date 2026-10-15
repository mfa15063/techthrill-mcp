<?php
namespace TechThrill\MCP\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\Auth\TokenManager;
use TechThrill\MCP\OAuth\AgentAccess;
use TechThrill\MCP\OAuth\AttemptLimiter;
use TechThrill\MCP\OAuth\ClientMetadataFetcher;
use TechThrill\MCP\OAuth\ClientRegistrar;
use TechThrill\MCP\OAuth\ClientResolver;
use TechThrill\MCP\OAuth\Metadata;
use TechThrill\MCP\OAuth\OptionAuthorizationCodeStore;
use TechThrill\MCP\OAuth\Pkce;
use TechThrill\MCP\OAuth\RestRoutes;
use TechThrill\MCP\OAuth\TokenEndpoint;
use TechThrill\MCP\Tests\Support\InMemoryClientStore;
use TechThrill\MCP\Tests\Support\InMemoryOptionsGateway;
use TechThrill\MCP\Tests\Support\InMemoryRateLimitStore;
use TechThrill\MCP\Tests\Support\InMemoryTokenRepository;

class RestRoutesTest extends TestCase {

	const BASE = 'https://example.com/wp-json/techthrill-mcp/v1';
	const CB   = 'http://localhost:53682/callback';

	private OptionAuthorizationCodeStore $codes;

	private InMemoryClientStore $store;

	private AgentAccess $agent;

	private InMemoryOptionsGateway $gateway;

	private InMemoryTokenRepository $tokenRepo;

	protected function setUp(): void {
		$this->store     = new InMemoryClientStore();
		$this->gateway   = new InMemoryOptionsGateway();
		$this->tokenRepo = new InMemoryTokenRepository();
		unset( $GLOBALS['ttm_registered_routes'], $GLOBALS['ttm_filters'], $GLOBALS['wp'] );
		$this->codes = new OptionAuthorizationCodeStore( new InMemoryOptionsGateway() );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ttm_registered_routes'], $GLOBALS['ttm_filters'], $GLOBALS['wp'] );
	}

	private function routes(): RestRoutes {
		$store    = $this->store;
		$limiter  = new AttemptLimiter( new InMemoryRateLimitStore() );
		$resolver = new ClientResolver( $store, new ClientMetadataFetcher( fn( string $u ) => null, $store ) );
		$metadata = new Metadata( self::BASE, self::BASE . '/mcp', 'https://example.com/wp-admin/admin-post.php?action=x', self::BASE . '/oauth/token', self::BASE . '/oauth/register', self::BASE . '/oauth/protected-resource' );
		$endpoint = new TokenEndpoint( $resolver, $this->codes, new TokenManager( new InMemoryTokenRepository() ), $limiter, self::BASE . '/mcp' );

		$this->agent = new AgentAccess( $this->gateway, new TokenManager( $this->tokenRepo ), $limiter );

		return new RestRoutes( $metadata, new ClientRegistrar( $store, $limiter ), $endpoint, fn() => '1.2.3.4', $this->agent, fn( string $id ) => 'https://example.com/wp-admin/admin-post.php?action=techthrill_mcp_agent&request=' . $id );
	}

	public function test_registers_the_four_routes_and_the_bypass_filter(): void {
		$this->routes()->register();

		$paths = array_map( fn( $r ) => $r[1], $GLOBALS['ttm_registered_routes'] );
		$this->assertSame( array( '/oauth/protected-resource', '/oauth/authorization-server', '/oauth/register', '/oauth/token', '/oauth/agent/start', '/oauth/agent/poll' ), $paths );
		foreach ( $GLOBALS['ttm_registered_routes'] as $route ) {
			$this->assertSame( 'techthrill-mcp/v1', $route[0] );
			$this->assertSame( '__return_true', $route[2]['permission_callback'] );
			$this->assertFalse( $route[2]['show_in_index'] );
		}
		$this->assertSame( 'GET', $GLOBALS['ttm_registered_routes'][0][2]['methods'] );
		$this->assertSame( 'POST', $GLOBALS['ttm_registered_routes'][3][2]['methods'] );
		$this->assertSame( 'POST', $GLOBALS['ttm_registered_routes'][4][2]['methods'] );
		$this->assertSame( 'POST', $GLOBALS['ttm_registered_routes'][5][2]['methods'] );
		$this->assertCount( 1, $GLOBALS['ttm_filters']['rest_authentication_errors'] );
	}

	public function test_cookie_auth_is_bypassed_only_for_oauth_routes(): void {
		$routes = $this->routes();
		$this->assertSame( 'untouched', $routes->bypassCookieAuth( 'untouched' ) );

		$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'rest_route' => '/techthrill-mcp/v1/oauth/token' ) );
		$this->assertTrue( $routes->bypassCookieAuth( 'x' ) );

		$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'rest_route' => '/techthrill-mcp/v1/mcp' ) );
		$this->assertSame( 'x', $routes->bypassCookieAuth( 'x' ) );

		$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'rest_route' => '/wp/v2/users' ) );
		$this->assertSame( 'x', $routes->bypassCookieAuth( 'x' ) );
	}

	public function test_discovery_documents_are_cacheable_json(): void {
		$r = $this->routes()->protectedResource();
		$this->assertSame( 200, $r->get_status() );
		$this->assertSame( array( self::BASE ), $r->get_data()['authorization_servers'] );
		$this->assertSame( 'public, max-age=300', $r->get_headers()['Cache-Control'] );

		$r = $this->routes()->authorizationServer();
		$this->assertSame( self::BASE, $r->get_data()['issuer'] );
	}

	public function test_register_endpoint_returns_201_for_valid_metadata_and_400_for_invalid(): void {
		$ok = $this->routes()->registerClient( new \WP_REST_Request( 'POST', array( 'Content-Type' => 'application/json' ), json_encode( array( 'client_name' => 'App', 'redirect_uris' => array( self::CB ) ) ) ) );
		$this->assertSame( 201, $ok->get_status() );
		$this->assertMatchesRegularExpression( '/^ttc_/', $ok->get_data()['client_id'] );
		$this->assertSame( 'no-store', $ok->get_headers()['Cache-Control'] );

		$bad = $this->routes()->registerClient( new \WP_REST_Request( 'POST', array(), json_encode( array( 'redirect_uris' => array( 'https://evil.com/cb' ) ) ) ) );
		$this->assertSame( 400, $bad->get_status() );
		$this->assertSame( 'invalid_client_metadata', $bad->get_data()['error'] );

		$junk = $this->routes()->registerClient( new \WP_REST_Request( 'POST', array(), 'not json' ) );
		$this->assertSame( 400, $junk->get_status() );
	}

	public function test_token_endpoint_reads_form_encoded_bodies(): void {
		$verifier = str_repeat( 'v', 50 );
		$code     = $this->codes->issue( array( 'client_id' => 'ttc_none', 'redirect_uri' => self::CB, 'code_challenge' => Pkce::challengeFor( $verifier ), 'resource' => '', 'scopes' => array( 'db:read' ), 'user_id' => 1 ) );
		$body     = http_build_query( array( 'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::CB, 'client_id' => 'ttc_none', 'code_verifier' => $verifier ) );

		$this->store->save( array( 'client_id' => 'ttc_none', 'client_name' => 'App', 'redirect_uris' => array( self::CB ), 'source' => 'dcr', 'created_at' => 1, 'last_used_at' => null ) );
		$r = $this->routes()->issueToken( new \WP_REST_Request( 'POST', array( 'Content-Type' => 'application/x-www-form-urlencoded' ), $body ) );

		$this->assertSame( 200, $r->get_status() );
		$this->assertSame( 'Bearer', $r->get_data()['token_type'] );
		$this->assertSame( 'db:read', $r->get_data()['scope'] );
	}

	public function test_token_responses_are_not_cacheable(): void {
		$r = $this->routes()->issueToken( new \WP_REST_Request( 'POST', array( 'Content-Type' => 'application/x-www-form-urlencoded' ), 'grant_type=password' ) );
		$this->assertSame( 400, $r->get_status() );
		$this->assertSame( 'unsupported_grant_type', $r->get_data()['error'] );
		$this->assertSame( 'no-store', $r->get_headers()['Cache-Control'] );
		$this->assertSame( 'no-cache', $r->get_headers()['Pragma'] );
	}

	public function test_agent_start_returns_the_approval_link_and_a_code(): void {
		$r = $this->routes()->agentStart( new \WP_REST_Request( 'POST', array( 'Content-Type' => 'application/json' ), json_encode( array( 'label' => 'Claude Code' ) ) ) );

		$this->assertSame( 201, $r->get_status() );
		$d = $r->get_data();
		$this->assertStringEndsWith( '&request=' . $d['request_id'], $d['approval_url'] );
		$this->assertMatchesRegularExpression( '/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $d['user_code'] );
		$this->assertSame( self::BASE . '/mcp', $d['mcp_url'] );
		$this->assertSame( 'no-store', $r->get_headers()['Cache-Control'] );
	}

	public function test_agent_start_tolerates_a_missing_or_invalid_body(): void {
		$this->assertSame( 201, $this->routes()->agentStart( new \WP_REST_Request( 'POST', array(), '' ) )->get_status() );
		$this->assertSame( 201, $this->routes()->agentStart( new \WP_REST_Request( 'POST', array(), 'not json' ) )->get_status() );
		$this->assertSame( 201, $this->routes()->agentStart( new \WP_REST_Request( 'POST', array(), json_encode( array( 'label' => array( 'x' ) ) ) ) )->get_status() );
	}

	public function test_agent_poll_reports_pending_then_the_token_once(): void {
		$routes = $this->routes();
		$d      = $routes->agentStart( new \WP_REST_Request( 'POST', array(), '{}' ) )->get_data();
		$poll   = fn() => $routes->agentPoll( new \WP_REST_Request( 'POST', array(), json_encode( array( 'request_id' => $d['request_id'], 'poll_code' => $d['poll_code'] ) ) ) );

		$pending = $poll();
		$this->assertSame( 'pending', $pending->get_data()['status'] );
		$this->assertSame( '3', $pending->get_headers()['Retry-After'] );

		$this->agent->approve( $d['request_id'], 3, array( 'db:read' ) );
		$ok = $poll();
		$this->assertSame( 'approved', $ok->get_data()['status'] );
		$this->assertMatchesRegularExpression( '/^ttm_/', $ok->get_data()['access_token'] );
		$this->assertSame( 'no-store', $ok->get_headers()['Cache-Control'] );

		$this->assertSame( 'expired', $poll()->get_data()['status'] );
	}

	public function test_agent_poll_with_garbage_is_expired_not_an_error(): void {
		$r = $this->routes()->agentPoll( new \WP_REST_Request( 'POST', array(), '["x"]' ) );
		$this->assertSame( 200, $r->get_status() );
		$this->assertSame( 'expired', $r->get_data()['status'] );
	}
}
