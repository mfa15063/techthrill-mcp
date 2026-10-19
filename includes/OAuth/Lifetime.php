<?php
/**
 * How long an approved connection lasts.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * The expiry choices on the consent screens. A choice maps to a number of seconds, or null for never.
 */
final class Lifetime {

	const DEFAULT_KEY = '30d';

	/**
	 * Seconds of the default choice (30 days).
	 */
	const DEFAULT_SECONDS = 2592000;

	/**
	 * Choices in display order: key => seconds (null = never expires).
	 *
	 * @return array<string,int|null>
	 */
	public static function choices(): array {
		return array(
			'1d'    => DAY_IN_SECONDS,
			'1w'    => 7 * DAY_IN_SECONDS,
			'30d'   => 30 * DAY_IN_SECONDS,
			'never' => null,
		);
	}

	/**
	 * Labels for the dropdown.
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		return array(
			'1d'    => __( '1 day', 'techthrill-mcp' ),
			'1w'    => __( '1 week', 'techthrill-mcp' ),
			'30d'   => __( '30 days', 'techthrill-mcp' ),
			'never' => __( 'Never expire', 'techthrill-mcp' ),
		);
	}

	/**
	 * Seconds for a posted key, or null for never. An unknown key gets the default, never "never".
	 *
	 * @param string $key Posted choice.
	 */
	public static function seconds( string $key ): ?int {
		$choices = self::choices();

		return array_key_exists( $key, $choices ) ? $choices[ $key ] : self::DEFAULT_SECONDS;
	}
}
