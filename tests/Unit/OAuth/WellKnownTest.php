<?php
namespace TechThrill\MCP\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\OAuth\Metadata;
use TechThrill\MCP\OAuth\WellKnown;

class WellKnownTest extends TestCase {

	private function wellKnown( string $base = 'https://example.com' ): WellKnown {
		$rest = $base . '/wp-json/techthrill-mcp/v1';

		return new WellKnown( new Metadata( $rest, $rest . '/mcp', $base . '/wp-admin/admin-post.php?action=x', $rest . '/oauth/token', $rest . '/oauth/register', $rest . '/oauth/protected-resource' ) );
	}

	public function test_serves_authorization_server_metadata_at_the_root_well_known_path(): void {
		$doc = $this->wellKnown()->match( 'GET', '/.well-known/oauth-authorization-server/wp-json/techthrill-mcp/v1' );
		$this->assertSame( 'https://example.com/wp-json/techthrill-mcp/v1', $doc['issuer'] );
		$this->assertTrue( $doc['client_id_metadata_document_supported'] );
	}

	public function test_serves_protected_resource_metadata_for_the_mcp_path(): void {
		$doc = $this->wellKnown()->match( 'GET', '/.well-known/oauth-protected-resource/wp-json/techthrill-mcp/v1/mcp' );
		$this->assertSame( 'https://example.com/wp-json/techthrill-mcp/v1/mcp', $doc['resource'] );
		$this->assertSame( array( 'https://example.com/wp-json/techthrill-mcp/v1' ), $doc['authorization_servers'] );
	}

	public function test_works_for_a_subdirectory_install_and_tolerates_a_trailing_slash_and_head(): void {
		$w = $this->wellKnown( 'https://example.com/blog' );
		$this->assertNotNull( $w->match( 'GET', '/.well-known/oauth-authorization-server/blog/wp-json/techthrill-mcp/v1' ) );
		$this->assertNotNull( $w->match( 'HEAD', '/.well-known/oauth-authorization-server/blog/wp-json/techthrill-mcp/v1/' ) );
		$this->assertNull( $w->match( 'GET', '/.well-known/oauth-authorization-server/wp-json/techthrill-mcp/v1' ), 'The subdirectory is part of the path.' );
	}

	public function test_ignores_other_paths_and_non_get_methods(): void {
		$w = $this->wellKnown();
		$this->assertNull( $w->match( 'GET', '/' ) );
		$this->assertNull( $w->match( 'GET', '/.well-known/security.txt' ) );
		$this->assertNull( $w->match( 'GET', '/.well-known/oauth-authorization-server' ) );
		$this->assertNull( $w->match( 'GET', '/.well-known/oauth-authorization-server/other' ) );
		$this->assertNull( $w->match( 'POST', '/.well-known/oauth-authorization-server/wp-json/techthrill-mcp/v1' ) );
		$this->assertNull( $w->match( 'DELETE', '/.well-known/oauth-authorization-server/wp-json/techthrill-mcp/v1' ) );
	}
}
