<?php
namespace TechThrill\MCP\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\OAuth\AgentPrompt;

class AgentPromptTest extends TestCase {

	private function prompt(): string {
		return AgentPrompt::text( 'https://www.example.com/', 'https://www.example.com/wp-json/techthrill-mcp/v1/mcp', 'https://www.example.com/wp-json/techthrill-mcp/v1/oauth/agent/start', 'https://www.example.com/wp-json/techthrill-mcp/v1/oauth/agent/poll' );
	}

	public function test_contains_the_site_and_all_addresses(): void {
		$p = $this->prompt();
		$this->assertStringContainsString( 'Site: https://www.example.com/', $p );
		$this->assertStringContainsString( 'MCP endpoint: https://www.example.com/wp-json/techthrill-mcp/v1/mcp', $p );
		$this->assertStringContainsString( 'curl -s -X POST "https://www.example.com/wp-json/techthrill-mcp/v1/oauth/agent/start"', $p );
		$this->assertStringContainsString( 'curl -s -X POST "https://www.example.com/wp-json/techthrill-mcp/v1/oauth/agent/poll"', $p );
		$this->assertStringNotContainsString( '{{', $p, 'Every placeholder is filled in.' );
	}

	public function test_contains_no_token_or_secret(): void {
		$p = $this->prompt();
		foreach ( array( 'ttm_', 'ttr_' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $p );
		}
		$this->assertStringContainsString( 'Never show poll_code', $p );
	}

	public function test_names_the_saved_connection_file_after_the_site(): void {
		$p = $this->prompt();
		$this->assertStringContainsString( '~/.techthrill-mcp/www.example.com.json', $p );
		$this->assertSame( 'www.example.com.json', AgentPrompt::fileName( 'https://www.example.com/' ) );
		$this->assertSame( 'example.com.json', AgentPrompt::fileName( 'https://example.com:8443/blog' ) );
		$this->assertSame( 'site.json', AgentPrompt::fileName( 'not a url' ) );
		$this->assertSame( 'site.json', AgentPrompt::fileName( '' ) );
		$this->assertStringNotContainsString( '/', AgentPrompt::fileName( 'https://evil.com/../../x' ) );
	}

	public function test_the_agent_must_reuse_a_saved_connection_before_asking_again(): void {
		$p = $this->prompt();
		$this->assertStringContainsString( 'STEP 1 - Reuse the saved connection before asking for anything.', $p );
		$this->assertLessThan( strpos( $p, 'STEP 2' ), strpos( $p, 'STEP 1' ) );
		$this->assertStringContainsString( 'Do NOT ask me to approve anything', $p );
		$this->assertStringContainsString( 'The file is your memory', $p );
		$this->assertStringContainsString( 'Never ask me to approve again just because a new message or a new conversation started', $p );
		$this->assertStringContainsString( 'HTTP 401', $p );
		$this->assertStringContainsString( 'Delete the file and go to STEP 2', $p );
		$this->assertStringContainsString( 'Do not run STEP 2 again while the saved connection works', $p );
	}

	public function test_asking_again_is_limited_to_a_missing_file_a_401_or_a_missing_permission(): void {
		$p = $this->prompt();
		$this->assertStringContainsString( 'Ask again only when the file is missing, the server answers 401, or you need a permission I did not give', $p );
		$this->assertStringContainsString( 'tell me which permission is missing', $p );
	}

	public function test_the_token_is_saved_privately_and_never_shown(): void {
		$p = $this->prompt();
		$this->assertStringContainsString( 'mode 700', $p );
		$this->assertStringContainsString( 'mode 600', $p );
		$this->assertStringContainsString( 'never print it', $p );
		$this->assertStringContainsString( 'never commit it', $p );
		$this->assertStringContainsString( 'never repeat it in the chat', $p );
		$this->assertStringContainsString( 'expires_at', $p );
	}

	public function test_tells_a_normal_chat_to_stop_and_explains_what_to_use_instead(): void {
		$p = $this->prompt();
		$this->assertStringContainsString( 'STEP 0', $p );
		$this->assertStringContainsString( 'ordinary chat window without tools', $p );
		$this->assertStringContainsString( 'do not make up results', $p );
		$this->assertStringContainsString( "I can't connect to this site from a normal chat.", $p );
		$this->assertStringContainsString( 'Claude Code, Codex or Cursor', $p );
		$this->assertStringContainsString( 'Customize > Connectors > Add custom connector, then paste https://www.example.com/wp-json/techthrill-mcp/v1/mcp', $p );
		$this->assertLessThan( strpos( $p, 'STEP 1' ), strpos( $p, 'STEP 0' ) );
	}

	public function test_an_agent_that_forgets_its_files_says_so_instead_of_asking_every_time(): void {
		$this->assertStringContainsString( 'do not survive until my next message', $this->prompt() );
		$this->assertStringContainsString( 'I would have to approve again every time', $this->prompt() );
	}

	public function test_the_approval_instructions_match_the_approval_screen(): void {
		$p = $this->prompt();
		$this->assertStringContainsString( 'check that the code on the page matches user_code', $p );
		$this->assertStringContainsString( 'all are ticked by default', $p );
		$this->assertStringContainsString( 'choose how long the access lasts (30 days is the default)', $p );
		$this->assertStringContainsString( 'Do not open the link yourself', $p );
		$this->assertStringContainsString( 'ask me before anything destructive', $p );
		$this->assertStringContainsString( 'site_info', $p );
	}

	public function test_initialize_example_is_valid_json_rpc(): void {
		preg_match( '/with this body (\{.*?\}\}\}) and then/s', $this->prompt(), $m );
		$json = json_decode( $m[1] ?? '', true );
		$this->assertSame( '2.0', $json['jsonrpc'] );
		$this->assertSame( 'initialize', $json['method'] );
		$this->assertSame( '2025-06-18', $json['params']['protocolVersion'] );
	}
}
