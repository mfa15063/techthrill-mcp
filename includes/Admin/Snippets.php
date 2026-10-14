<?php
/**
 * Connection snippets for MCP clients.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the copy-and-paste configuration for Claude Code, Codex and generic JSON clients.
 *
 * Pure PHP: no WordPress calls.
 */
final class Snippets {

	const MAX_SLUG_LENGTH = 30;

	/**
	 * Lower-case, hyphenated identifier derived from the site name.
	 *
	 * @param string $siteName Site name.
	 */
	public static function slug( string $siteName ): string {
		$slug = strtolower( $siteName );
		$slug = (string) preg_replace( '/[^a-z0-9]+/', '-', $slug );
		$slug = trim( $slug, '-' );
		$slug = trim( substr( $slug, 0, self::MAX_SLUG_LENGTH ), '-' );

		return '' === $slug ? 'wordpress' : $slug;
	}

	/**
	 * Name of the environment variable that holds the token in the Codex setup.
	 *
	 * @param string $slug Server slug.
	 */
	public static function envName( string $slug ): string {
		return 'TECHTHRILL_MCP_' . strtoupper( str_replace( '-', '_', $slug ) ) . '_TOKEN';
	}

	/**
	 * `claude mcp add` command.
	 *
	 * @param string $slug  Server slug.
	 * @param string $url   Endpoint URL.
	 * @param string $token Plain token.
	 */
	public static function claudeCode( string $slug, string $url, string $token ): string {
		return 'claude mcp add --transport http ' . $slug . ' ' . $url . ' --header "Authorization: Bearer ' . $token . '"';
	}

	/**
	 * Codex config.toml block plus the export line for the token.
	 *
	 * @param string $slug  Server slug.
	 * @param string $url   Endpoint URL.
	 * @param string $token Plain token.
	 */
	public static function codex( string $slug, string $url, string $token ): string {
		$env = self::envName( $slug );

		return 'export ' . $env . '=' . $token . "\n\n"
			. '[mcp_servers.' . $slug . "]\n"
			. 'url = "' . $url . "\"\n"
			. 'bearer_token_env_var = "' . $env . '"';
	}

	/**
	 * Generic `mcpServers` JSON configuration.
	 *
	 * @param string $slug  Server slug.
	 * @param string $url   Endpoint URL.
	 * @param string $token Plain token.
	 */
	public static function json( string $slug, string $url, string $token ): string {
		return (string) json_encode(
			array(
				'mcpServers' => array(
					$slug => array(
						'type'    => 'http',
						'url'     => $url,
						'headers' => array( 'Authorization' => 'Bearer ' . $token ),
					),
				),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
	}
}
