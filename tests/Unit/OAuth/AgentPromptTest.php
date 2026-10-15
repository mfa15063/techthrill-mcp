<?php
namespace TechThrill\MCP\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\OAuth\AgentPrompt;

class AgentPromptTest extends TestCase {

	private function prompt(): string {
		return AgentPrompt::text( 'https://example.com/', 'https://example.com/wp-json/techthrill-mcp/v1/mcp', 'https://example.com/wp-json/techthrill-mcp/v1/oauth/agent/start', 'https://example.com/wp-json/techthrill-mcp/v1/oauth/agent/poll' );
	}

	public function test_contains_the_site_and_all_three_addresses(): void {
		$p = $this->prompt();
		$this->assertStringContainsString( 'Site: https://example.com/', $p );
		$this->assertStringContainsString( 'MCP endpoint: https://example.com/wp-json/techthrill-mcp/v1/mcp', $p );
		$this->assertStringContainsString( 'curl -s -X POST "https://example.com/wp-json/techthrill-mcp/v1/oauth/agent/start"', $p );
		$this->assertStringContainsString( 'curl -s -X POST "https://example.com/wp-json/techthrill-mcp/v1/oauth/agent/poll"', $p );
	}

	public function test_contains_no_token_or_secret(): void {
		$p = $this->prompt();
		foreach ( array( 'ttm_', 'ttr_' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $p );
		}
		$this->assertStringContainsString( 'Never show poll_code', $p );
		$this->assertStringContainsString( 'never write it to a file', $p );
	}

	public function test_tells_a_normal_chat_to_stop_and_explains_what_to_use_instead(): void {
		$p = $this->prompt();
		$this->assertStringContainsString( 'STEP 0', $p );
		$this->assertStringContainsString( 'ordinary chat window without tools', $p );
		$this->assertStringContainsString( 'do not make up results', $p );
		$this->assertStringContainsString( "I can't connect to this site from a normal chat.", $p );
		$this->assertStringContainsString( 'Claude Code, Codex or Cursor', $p );
		$this->assertStringContainsString( 'Customize > Connectors > Add custom connector, then paste https://example.com/wp-json/techthrill-mcp/v1/mcp', $p );
		$this->assertLessThan( strpos( $p, 'STEP 1' ), strpos( $p, 'STEP 0' ) );
	}

	public function test_asks_the_human_to_approve_and_check_the_code(): void {
		$p = $this->prompt();
		$this->assertStringContainsString( 'check that the code on the page matches user_code', $p );
		$this->assertStringContainsString( 'Do not open the link yourself', $p );
		$this->assertStringContainsString( 'ask me before anything destructive', $p );
		$this->assertStringContainsString( 'site_info', $p );
	}

	public function test_initialize_example_is_valid_json_rpc(): void {
		$p = $this->prompt();
		preg_match( '/Start with (\{.*?\}\}\}) then/s', $p, $m );
		$json = json_decode( $m[1] ?? '', true );
		$this->assertSame( '2.0', $json['jsonrpc'] );
		$this->assertSame( 'initialize', $json['method'] );
		$this->assertSame( '2025-06-18', $json['params']['protocolVersion'] );
	}
}
