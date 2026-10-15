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

	public function test_oauth_commands_have_no_token_or_header(): void {
		$this->assertSame( 'claude mcp add --transport http my-site ' . self::URL, Snippets::claudeCodeOAuth( 'my-site', self::URL ) );
		$this->assertSame( 'codex mcp add my-site --url ' . self::URL, Snippets::codexOAuth( 'my-site', self::URL ) );
	}

	public function test_cursor_link_encodes_name_and_config(): void {
		$link = Snippets::cursorLink( 'My Site', self::URL );
		$this->assertStringStartsWith( 'cursor://anysphere.cursor-deeplink/mcp/install?name=My%20Site&config=', $link );

		parse_str( (string) parse_url( $link, PHP_URL_QUERY ), $q );
		$this->assertSame( 'My Site', $q['name'] );
		$this->assertSame( array( 'url' => self::URL ), json_decode( base64_decode( $q['config'], true ), true ) );
	}

	public function test_vscode_link_is_url_encoded_json(): void {
		$link = Snippets::vscodeLink( 'my-site', self::URL );
		$this->assertStringStartsWith( 'vscode:mcp/install?', $link );
		$json = rawurldecode( substr( $link, strlen( 'vscode:mcp/install?' ) ) );
		$this->assertSame( array( 'name' => 'my-site', 'type' => 'http', 'url' => self::URL ), json_decode( $json, true ) );
	}

	public function test_install_links_and_oauth_commands_carry_no_secret_material(): void {
		$cursor = Snippets::cursorLink( 'x', self::URL );
		parse_str( (string) parse_url( $cursor, PHP_URL_QUERY ), $q );

		$all = $cursor . base64_decode( $q['config'], true ) . rawurldecode( Snippets::vscodeLink( 'x', self::URL ) ) . Snippets::claudeCodeOAuth( 'x', self::URL ) . Snippets::codexOAuth( 'x', self::URL );
		foreach ( array( 'ttm_', 'ttr_', 'Bearer', 'Authorization', 'headers' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $all, $needle );
		}
	}
}
