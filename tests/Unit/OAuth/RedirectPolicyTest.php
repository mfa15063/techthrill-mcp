<?php
namespace TechThrill\MCP\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\OAuth\RedirectPolicy;

class RedirectPolicyTest extends TestCase {

	/**
	 * @dataProvider allowed
	 */
	public function test_allowed_uris( string $uri ): void {
		$this->assertTrue( RedirectPolicy::isAllowed( $uri ), $uri );
	}

	public function allowed(): array {
		return array(
			'claude callback'    => array( 'https://claude.ai/api/mcp/auth_callback' ),
			'chatgpt callback'   => array( 'https://chatgpt.com/connector/oauth/abc123' ),
			'chatgpt with dash'  => array( 'https://chatgpt.com/connector/oauth/a-b_C9' ),
			'localhost port'     => array( 'http://localhost:53682/callback' ),
			'127.0.0.1 port'     => array( 'http://127.0.0.1:8080/x' ),
			'localhost no port'  => array( 'http://localhost/' ),
			'localhost no path'  => array( 'http://localhost:3000' ),
			'loopback query'     => array( 'http://127.0.0.1:9/cb?x=1' ),
		);
	}

	/**
	 * @dataProvider rejected
	 */
	public function test_rejected_uris( string $uri ): void {
		$this->assertFalse( RedirectPolicy::isAllowed( $uri ), $uri );
	}

	public function rejected(): array {
		return array(
			'claude other path'      => array( 'https://claude.ai/api/mcp/other' ),
			'claude trailing slash'  => array( 'https://claude.ai/api/mcp/auth_callback/' ),
			'claude query'           => array( 'https://claude.ai/api/mcp/auth_callback?x=1' ),
			'claude http'            => array( 'http://claude.ai/api/mcp/auth_callback' ),
			'claude subdomain'       => array( 'https://evil.claude.ai/api/mcp/auth_callback' ),
			'chatgpt empty id'       => array( 'https://chatgpt.com/connector/oauth/' ),
			'chatgpt nested'         => array( 'https://chatgpt.com/connector/oauth/a/b' ),
			'chatgpt query'          => array( 'https://chatgpt.com/connector/oauth/abc?x=1' ),
			'chatgpt lookalike'      => array( 'https://chatgpt.com.evil.com/connector/oauth/abc' ),
			'chatgpt http'           => array( 'http://chatgpt.com/connector/oauth/abc' ),
			'localhost lookalike'    => array( 'http://localhost.evil.com/cb' ),
			'loopback lookalike'     => array( 'http://127.0.0.1.evil.com/cb' ),
			'localhost https'        => array( 'https://localhost/cb' ),
			'ipv6 loopback'          => array( 'http://[::1]/cb' ),
			'other private ip'       => array( 'http://127.0.0.2/cb' ),
			'custom scheme'          => array( 'cursor://anysphere.cursor/cb' ),
			'javascript'             => array( 'javascript:alert(1)' ),
			'fragment'               => array( 'http://localhost/cb#x' ),
			'user info'              => array( 'http://user@localhost/cb' ),
			'user info password'     => array( 'http://u:p@127.0.0.1/cb' ),
			'whitespace'             => array( 'http://localhost/cb x' ),
			'newline'                => array( "http://localhost/cb\n" ),
			'empty'                  => array( '' ),
			'relative'               => array( '/cb' ),
		);
	}

	public function test_host_for_display(): void {
		$this->assertSame( 'claude.ai', RedirectPolicy::host( 'https://claude.ai/api/mcp/auth_callback' ) );
		$this->assertSame( 'localhost:53682', RedirectPolicy::host( 'http://localhost:53682/callback' ) );
		$this->assertSame( '127.0.0.1', RedirectPolicy::host( 'http://127.0.0.1/x' ) );
		$this->assertSame( '', RedirectPolicy::host( 'not a url' ) );
	}
}
