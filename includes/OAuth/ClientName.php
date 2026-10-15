<?php
/**
 * Client name sanitising.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Client names come from outside, so they are reduced to plain text before they are stored or shown.
 */
final class ClientName {

	const DEFAULT_NAME = 'MCP client';

	const MAX_LENGTH = 100;

	/**
	 * Plain-text name of at most $max characters; a default when nothing usable is left.
	 *
	 * @param mixed $value Raw name.
	 * @param int   $max   Longest allowed name.
	 */
	public static function clean( $value, int $max = self::MAX_LENGTH ): string {
		if ( ! is_string( $value ) ) {
			return self::DEFAULT_NAME;
		}

		$name = (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', wp_strip_all_tags( $value ) );
		$name = trim( (string) preg_replace( '/\s+/', ' ', $name ) );
		$name = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, $max ) : substr( $name, 0, $max );

		return '' === $name ? self::DEFAULT_NAME : $name;
	}
}
