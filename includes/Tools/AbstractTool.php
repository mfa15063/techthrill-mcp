<?php
/**
 * Base class for tools.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Shared result helpers for tools.
 */
abstract class AbstractTool implements ToolInterface {

	/**
	 * Size under which array results are pretty-printed.
	 */
	const PRETTY_PRINT_LIMIT = 51200;

	/**
	 * Builds a success result. Strings are used as-is; other data is JSON encoded.
	 *
	 * @param mixed $data Result data.
	 */
	protected function ok( $data ): ToolResult {
		if ( is_string( $data ) ) {
			return ToolResult::ok( $data );
		}

		$compact = wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
		if ( false === $compact ) {
			return ToolResult::error( 'Failed to encode the result as JSON.' );
		}

		if ( strlen( $compact ) < self::PRETTY_PRINT_LIMIT ) {
			$pretty = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			if ( false !== $pretty ) {
				return ToolResult::ok( $pretty );
			}
		}

		return ToolResult::ok( $compact );
	}

	/**
	 * Builds an error result.
	 *
	 * @param string $msg Error message.
	 */
	protected function fail( string $msg ): ToolResult {
		return ToolResult::error( $msg );
	}
}
