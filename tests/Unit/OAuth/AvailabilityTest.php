<?php
namespace TechThrill\MCP\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\OAuth\Availability;

class AvailabilityTest extends TestCase {

	public function test_a_public_https_site_with_pretty_permalinks_has_no_problems(): void {
		$this->assertSame( array(), Availability::problems( 'https://example.com/wp-json/techthrill-mcp/v1', '/%postname%/' ) );
		$this->assertTrue( Availability::isAvailable( 'https://example.com/wp-json/techthrill-mcp/v1', '/%postname%/' ) );
	}

	public function test_plain_permalinks_block_oauth(): void {
		$this->assertSame( array( 'permalinks' ), Availability::problems( 'https://example.com/wp-json/techthrill-mcp/v1', '' ) );
		$this->assertContains( 'permalinks', Availability::problems( 'https://example.com/?rest_route=/techthrill-mcp/v1', '/%postname%/' ) );
		$this->assertFalse( Availability::isAvailable( 'https://example.com/?rest_route=/techthrill-mcp/v1', '' ) );
	}

	public function test_http_on_a_public_host_blocks_oauth(): void {
		$this->assertSame( array( 'https' ), Availability::problems( 'http://example.com/wp-json/techthrill-mcp/v1', '/%postname%/' ) );
		$this->assertFalse( Availability::isAvailable( 'http://example.com/wp-json/techthrill-mcp/v1', '/%postname%/' ) );
	}

	public function test_local_sites_work_but_are_flagged_as_unreachable_for_hosted_clients(): void {
		foreach ( array( 'http://localhost/wp-json/x', 'http://127.0.0.1:8080/wp-json/x', 'http://mysite.test/wp-json/x', 'http://192.168.1.20/wp-json/x', 'http://10.0.0.4/wp-json/x', 'https://shop.local/wp-json/x' ) as $url ) {
			$problems = Availability::problems( $url, '/%postname%/' );
			$this->assertContains( 'local', $problems, $url );
			$this->assertNotContains( 'https', $problems, $url );
			$this->assertTrue( Availability::isAvailable( $url, '/%postname%/' ), $url );
		}
	}

	public function test_public_ip_and_domain_are_not_local(): void {
		$this->assertFalse( Availability::isLocalHost( '8.8.8.8' ) );
		$this->assertFalse( Availability::isLocalHost( 'example.com' ) );
		$this->assertTrue( Availability::isLocalHost( 'intranet' ) );
	}
}
