<?php
namespace TechThrill\MCP\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\Admin\Snippets;

class SnippetsTest extends TestCase {

	const URL = 'https://example.com/wp-json/techthrill-mcp/v1/mcp';

	public function test_claude_code_command(): void {
		$this->assertSame(
			'claude mcp add --transport http my-site https://example.com/wp-json/techthrill-mcp/v1/mcp --header "Authorization: Bearer ttm_x"',
			Snippets::claudeCode( 'my-site', self::URL, 'ttm_x' )
		);
	}

	public function test_codex_block(): void {
		$expected = "export TECHTHRILL_MCP_MY_SITE_TOKEN=ttm_x\n\n[mcp_servers.my-site]\nurl = \"https://example.com/wp-json/techthrill-mcp/v1/mcp\"\nbearer_token_env_var = \"TECHTHRILL_MCP_MY_SITE_TOKEN\"";
		$this->assertSame( $expected, Snippets::codex( 'my-site', self::URL, 'ttm_x' ) );
	}

	public function test_json_config(): void {
		$decoded = json_decode( Snippets::json( 'my-site', self::URL, 'ttm_x' ), true );
		$this->assertSame(
			array(
				'mcpServers' => array(
					'my-site' => array(
						'type'    => 'http',
						'url'     => self::URL,
						'headers' => array( 'Authorization' => 'Bearer ttm_x' ),
					),
				),
			),
			$decoded
		);
		$this->assertStringContainsString( "\n", Snippets::json( 'my-site', self::URL, 'ttm_x' ) );
		$this->assertStringContainsString( 'https://example.com/wp-json', Snippets::json( 'my-site', self::URL, 'ttm_x' ) );
	}

	public function test_slug(): void {
		$this->assertSame( 'my-site-2026', Snippets::slug( 'My Site! 2026' ) );
		$this->assertSame( 'wordpress', Snippets::slug( '' ) );
		$this->assertSame( 'wordpress', Snippets::slug( '!!!' ) );
		$this->assertSame( 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaa', Snippets::slug( str_repeat( 'a', 29 ) . '-' . str_repeat( 'b', 20 ) ) );
	}
}
