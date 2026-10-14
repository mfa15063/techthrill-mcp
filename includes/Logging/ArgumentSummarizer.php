<?php
/**
 * Builds the one-line argument summary stored in the audit log.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Logging;

defined( 'ABSPATH' ) || exit;

/**
 * Summarizes tool arguments without ever leaking file contents.
 */
final class ArgumentSummarizer {

	const MAX_VALUE = 80;
	const MAX_TOTAL = 500;

	/**
	 * Argument names whose values are replaced by their length.
	 */
	const MASKED = array( 'content', 'old_string', 'new_string' );

	/**
	 * Returns "key=value key=value" for the given arguments.
	 *
	 * @param array $args Tool arguments.
	 */
	public static function summarize( array $args ): string {
		$parts = array();

		foreach ( $args as $key => $value ) {
			$text = self::stringify( $value );

			if ( in_array( $key, self::MASKED, true ) ) {
				$parts[] = $key . '=<' . mb_strlen( $text ) . ' chars>';
				continue;
			}

			// Keep the summary on one line.
			$text = trim( preg_replace( '/\s+/u', ' ', $text ) ?? $text );
			if ( mb_strlen( $text ) > self::MAX_VALUE ) {
				$text = mb_substr( $text, 0, self::MAX_VALUE ) . '…';
			}

			$parts[] = $key . '=' . $text;
		}

		return mb_substr( implode( ' ', $parts ), 0, self::MAX_TOTAL );
	}

	/**
	 * Renders any argument value as text.
	 *
	 * @param mixed $value Argument value.
	 */
	private static function stringify( $value ): string {
		if ( null === $value ) {
			return 'null';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		$json = wp_json_encode( $value );

		return false === $json ? '' : $json;
	}
}
