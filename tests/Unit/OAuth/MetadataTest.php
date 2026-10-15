<?php
namespace TechThrill\MCP\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\Auth\Scopes;
use TechThrill\MCP\OAuth\Metadata;

class MetadataTest extends TestCase {

	private function metadata(): Metadata {
		$base = 'https://example.com/wp-json/techthrill-mcp/v1';
		return new Metadata(
			$base,
			$base . '/mcp',
			'https://example.com/wp-admin/admin-post.php?action=techthrill_mcp_authorize',
			$base . '/oauth/token',
			$base . '/oauth/register',
			$base . '/oauth/protected-resource'
		);
	}

	public function test_protected_resource_lists_exactly_one_authorization_server(): void {
		$doc = $this->metadata()->protectedResource();
		$this->assertSame( 'https://example.com/wp-json/techthrill-mcp/v1/mcp', $doc['resource'] );
		$this->assertSame( array( 'https://example.com/wp-json/techthrill-mcp/v1' ), $doc['authorization_servers'] );
		$this->assertSame( array( 'header' ), $doc['bearer_methods_supported'] );
		$this->assertSame( 'TechThrill MCP', $doc['resource_name'] );
	}

	public function test_authorization_server_advertises_cimd_s256_and_none_auth(): void {
		$doc = $this->metadata()->authorizationServer();
		$this->assertSame( 'https://example.com/wp-json/techthrill-mcp/v1', $doc['issuer'] );
		$this->assertSame( 'https://example.com/wp-admin/admin-post.php?action=techthrill_mcp_authorize', $doc['authorization_endpoint'] );
		$this->assertSame( 'https://example.com/wp-json/techthrill-mcp/v1/oauth/token', $doc['token_endpoint'] );
		$this->assertSame( 'https://example.com/wp-json/techthrill-mcp/v1/oauth/register', $doc['registration_endpoint'] );
		$this->assertSame( array( 'code' ), $doc['response_types_supported'] );
		$this->assertSame( array( 'authorization_code', 'refresh_token' ), $doc['grant_types_supported'] );
		$this->assertSame( array( 'S256' ), $doc['code_challenge_methods_supported'] );
		$this->assertSame( array( 'none' ), $doc['token_endpoint_auth_methods_supported'] );
		$this->assertTrue( $doc['client_id_metadata_document_supported'] );
	}

	public function test_scopes_supported_are_the_five_plugin_scopes(): void {
		$this->assertSame( Scopes::ALL, $this->metadata()->protectedResource()['scopes_supported'] );
		$this->assertSame( Scopes::ALL, $this->metadata()->authorizationServer()['scopes_supported'] );
	}

	public function test_challenge_header_values(): void {
		$this->assertSame(
			'Bearer resource_metadata="https://example.com/wp-json/techthrill-mcp/v1/oauth/protected-resource"',
			$this->metadata()->challenge( false )
		);
		$this->assertSame(
			'Bearer error="invalid_token", resource_metadata="https://example.com/wp-json/techthrill-mcp/v1/oauth/protected-resource"',
			$this->metadata()->challenge( true )
		);
	}

	public function test_well_known_path_for_root_and_subdirectory_installs(): void {
		$this->assertSame(
			'/.well-known/oauth-authorization-server/wp-json/techthrill-mcp/v1',
			Metadata::wellKnownPath( 'oauth-authorization-server', 'https://example.com/wp-json/techthrill-mcp/v1' )
		);
		$this->assertSame(
			'/.well-known/oauth-protected-resource/blog/wp-json/techthrill-mcp/v1/mcp',
			Metadata::wellKnownPath( 'oauth-protected-resource', 'https://example.com/blog/wp-json/techthrill-mcp/v1/mcp' )
		);
		$this->assertSame( '/.well-known/oauth-authorization-server', Metadata::wellKnownPath( 'oauth-authorization-server', 'https://example.com' ) );
		$this->assertSame( '/.well-known/oauth-authorization-server', Metadata::wellKnownPath( 'oauth-authorization-server', 'https://example.com/' ) );
	}
}
