<?php
namespace TechThrill\MCP\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\OAuth\Authorizer;
use TechThrill\MCP\OAuth\AuthorizeValidator;
use TechThrill\MCP\OAuth\ClientMetadataFetcher;
use TechThrill\MCP\OAuth\ClientResolver;
use TechThrill\MCP\OAuth\ConsentFlow;
use TechThrill\MCP\OAuth\OptionAuthorizationCodeStore;
use TechThrill\MCP\OAuth\Pkce;
use TechThrill\MCP\Tests\Support\InMemoryClientStore;
use TechThrill\MCP\Tests\Support\InMemoryOptionsGateway;

class ConsentFlowTest extends TestCase {

	const RESOURCE = 'https://example.com/wp-json/techthrill-mcp/v1/mcp';
	const CB       = 'http://localhost:53682/callback';

	private OptionAuthorizationCodeStore $codes;

	protected function setUp(): void {
		$this->codes = new OptionAuthorizationCodeStore( new InMemoryOptionsGateway() );
	}

	private function flow(): ConsentFlow {
		$store = new InMemoryClientStore();
		$store->save( array( 'client_id' => 'ttc_a', 'client_name' => 'Claude Code', 'redirect_uris' => array( self::CB ), 'source' => 'dcr', 'created_at' => 1, 'last_used_at' => null ) );
		$resolver = new ClientResolver( $store, new ClientMetadataFetcher( fn( string $u ) => null, $store ) );

		return new ConsentFlow( new AuthorizeValidator( $resolver, self::RESOURCE ), new Authorizer( $this->codes ) );
	}

	private function params( array $override = array() ): array {
		return array_merge(
			array(
				'response_type'         => 'code',
				'client_id'             => 'ttc_a',
				'redirect_uri'          => self::CB,
				'state'                 => 's',
				'code_challenge'        => Pkce::challengeFor( str_repeat( 'v', 43 ) ),
				'code_challenge_method' => 'S256',
				'scope'                 => '',
				'resource'              => self::RESOURCE,
			),
			$override
		);
	}

	private function get( array $params, bool $logged_in = true, bool $admin = true ): array {
		return $this->flow()->handle( 'GET', $params, $logged_in, $admin, false, 9, array(), '' );
	}

	private function post( array $params, string $decision, array $scopes = array( 'db:read' ), bool $nonce = true ): array {
		return $this->flow()->handle( 'POST', $params, true, true, $nonce, 9, $scopes, $decision );
	}

	private function query( string $url ): array {
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
		return $q;
	}

	public function test_logged_out_visitor_is_sent_to_login(): void {
		$this->assertSame( 'login', $this->get( $this->params(), false, false )['type'] );
	}

	public function test_non_admin_sees_an_error_and_no_consent_form(): void {
		$r = $this->get( $this->params(), true, false );
		$this->assertSame( 'error', $r['type'] );
		$this->assertSame( 403, $r['status'] );
	}

	public function test_get_shows_consent_with_read_only_defaults(): void {
		$r = $this->get( $this->params() );
		$this->assertSame( 'consent', $r['type'] );
		$this->assertSame( array( 'db:read', 'files:read' ), $r['checked'] );
		$this->assertSame( 'Claude Code', $r['request']->client()['client_name'] );
	}

	public function test_defaults_stay_read_only_even_when_the_client_asks_for_more(): void {
		$r = $this->get( $this->params( array( 'scope' => 'wp-cli db:write files:write' ) ) );
		$this->assertSame( array( 'db:read', 'files:read' ), $r['checked'] );
	}

	public function test_unknown_client_renders_an_error_page_and_never_redirects(): void {
		$r = $this->get( $this->params( array( 'client_id' => 'ttc_nope' ) ) );
		$this->assertSame( 'error', $r['type'] );
		$r = $this->get( $this->params( array( 'redirect_uri' => 'http://localhost:1/evil' ) ) );
		$this->assertSame( 'error', $r['type'] );
	}

	public function test_a_trusted_validation_error_redirects_back_to_the_client(): void {
		$r = $this->get( $this->params( array( 'code_challenge_method' => 'plain' ) ) );
		$this->assertSame( 'redirect', $r['type'] );
		$q = $this->query( $r['url'] );
		$this->assertSame( 'invalid_request', $q['error'] );
		$this->assertSame( 's', $q['state'] );
	}

	public function test_post_without_a_valid_nonce_is_rejected(): void {
		$r = $this->post( $this->params(), 'approve', array( 'db:read' ), false );
		$this->assertSame( 'error', $r['type'] );
		$this->assertSame( 403, $r['status'] );
	}

	public function test_post_revalidates_hidden_fields(): void {
		$r = $this->post( $this->params( array( 'redirect_uri' => 'http://localhost:9/tampered' ) ), 'approve' );
		$this->assertSame( 'error', $r['type'], 'A tampered redirect_uri is never redirected to.' );
	}

	public function test_approve_redirects_with_a_code_for_the_ticked_scopes_only(): void {
		$r = $this->post( $this->params( array( 'scope' => 'wp-cli db:write' ) ), 'approve', array( 'files:read' ) );
		$this->assertSame( 'redirect', $r['type'] );
		$q = $this->query( $r['url'] );
		$this->assertStringStartsWith( self::CB, $r['url'] );
		$payload = $this->codes->consume( $q['code'] );
		$this->assertSame( array( 'files:read' ), $payload['scopes'] );
		$this->assertSame( 9, $payload['user_id'] );
		$this->assertSame( 's', $q['state'] );
	}

	public function test_deny_redirects_with_access_denied_and_issues_no_code(): void {
		$r = $this->post( $this->params(), 'deny' );
		$q = $this->query( $r['url'] );
		$this->assertSame( 'access_denied', $q['error'] );
		$this->assertArrayNotHasKey( 'code', $q );
	}

	public function test_approve_with_no_scope_ticked_shows_the_form_again_with_a_notice(): void {
		$r = $this->post( $this->params(), 'approve', array() );
		$this->assertSame( 'consent', $r['type'] );
		$this->assertNotEmpty( $r['notice'] );
	}

	public function test_unknown_decision_is_an_error(): void {
		$this->assertSame( 'error', $this->post( $this->params(), 'maybe' )['type'] );
	}
}
