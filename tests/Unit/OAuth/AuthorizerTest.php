<?php
namespace TechThrill\MCP\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\OAuth\AuthorizationRequest;
use TechThrill\MCP\OAuth\Authorizer;
use TechThrill\MCP\OAuth\AuthorizeException;
use TechThrill\MCP\OAuth\OAuthException;
use TechThrill\MCP\OAuth\OptionAuthorizationCodeStore;
use TechThrill\MCP\Tests\Support\InMemoryOptionsGateway;

class AuthorizerTest extends TestCase {

	private OptionAuthorizationCodeStore $codes;

	protected function setUp(): void {
		$this->codes = new OptionAuthorizationCodeStore( new InMemoryOptionsGateway() );
	}

	private function request( string $redirect = 'http://localhost:53682/callback', string $state = 'st4te' ): AuthorizationRequest {
		return new AuthorizationRequest( array( 'client_id' => 'ttc_a', 'client_name' => 'C' ), $redirect, $state, str_repeat( 'c', 43 ), array(), 'https://example.com/mcp' );
	}

	private function query( string $url ): array {
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
		return $q;
	}

	public function test_approve_returns_redirect_with_code_and_state_and_stores_the_payload(): void {
		$url = ( new Authorizer( $this->codes ) )->approve( $this->request(), 7, array( 'db:read', 'bogus', 'files:read' ) );

		$this->assertStringStartsWith( 'http://localhost:53682/callback?', $url );
		$q = $this->query( $url );
		$this->assertSame( 'st4te', $q['state'] );
		$payload = $this->codes->consume( $q['code'] );
		$this->assertSame( 'ttc_a', $payload['client_id'] );
		$this->assertSame( 'http://localhost:53682/callback', $payload['redirect_uri'] );
		$this->assertSame( str_repeat( 'c', 43 ), $payload['code_challenge'] );
		$this->assertSame( 'https://example.com/mcp', $payload['resource'] );
		$this->assertSame( array( 'db:read', 'files:read' ), $payload['scopes'] );
		$this->assertSame( 7, $payload['user_id'] );
	}

	public function test_approve_keeps_an_existing_query_string_on_the_redirect_uri(): void {
		$url = ( new Authorizer( $this->codes ) )->approve( $this->request( 'http://localhost:1/cb?x=1' ), 7, array( 'db:read' ) );
		$q   = $this->query( $url );
		$this->assertSame( '1', $q['x'] );
		$this->assertArrayHasKey( 'code', $q );
	}

	public function test_state_with_special_characters_survives_encoding(): void {
		$url = ( new Authorizer( $this->codes ) )->approve( $this->request( 'http://localhost:1/cb', 'a b&c=d/é' ), 7, array( 'db:read' ) );
		$this->assertSame( 'a b&c=d/é', $this->query( $url )['state'] );
	}

	public function test_approve_with_no_valid_scope_throws(): void {
		$this->expectException( OAuthException::class );
		( new Authorizer( $this->codes ) )->approve( $this->request(), 7, array( 'nonsense' ) );
	}

	public function test_deny_redirects_with_access_denied_and_state(): void {
		$q = $this->query( ( new Authorizer( $this->codes ) )->deny( $this->request() ) );
		$this->assertSame( 'access_denied', $q['error'] );
		$this->assertSame( 'st4te', $q['state'] );
		$this->assertArrayNotHasKey( 'code', $q );
	}

	public function test_empty_state_is_left_out(): void {
		$q = $this->query( ( new Authorizer( $this->codes ) )->deny( $this->request( 'http://localhost:1/cb', '' ) ) );
		$this->assertArrayNotHasKey( 'state', $q );
	}

	public function test_error_redirect_only_when_the_uri_is_trusted(): void {
		$a = new Authorizer( $this->codes );
		$this->assertNull( $a->errorRedirect( new AuthorizeException( 'invalid_client', 'Unknown client.' ) ) );

		$q = $this->query( (string) $a->errorRedirect( new AuthorizeException( 'invalid_scope', 'Unknown scope.', 'http://localhost:1/cb', 's' ) ) );
		$this->assertSame( 'invalid_scope', $q['error'] );
		$this->assertSame( 'Unknown scope.', $q['error_description'] );
		$this->assertSame( 's', $q['state'] );
	}

	public function test_default_scopes_are_read_only(): void {
		$this->assertSame( array( 'db:read', 'files:read' ), Authorizer::DEFAULT_SCOPES );
	}
}
