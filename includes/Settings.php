<?php
/**
 * Plugin settings.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and sanitizes the `techthrill_mcp_settings` option.
 */
final class Settings {

	const OPTION = 'techthrill_mcp_settings';

	const DEFAULTS = array(
		'safe_mode'          => 1,
		'rate_limit'         => 120,
		'cli_timeout'        => 60,
		'cli_path'           => '',
		'log_retention_days' => 30,
	);

	/**
	 * Inclusive [min, max] bounds for the integer settings.
	 */
	const LIMITS = array(
		'rate_limit'         => array( 0, 10000 ),
		'cli_timeout'        => array( 5, 300 ),
		'log_retention_days' => array( 1, 365 ),
	);

	/**
	 * Effective values (stored values merged over the defaults).
	 *
	 * @var array
	 */
	private array $values;

	/**
	 * @param array|null $override Values to use instead of the stored option (tests).
	 */
	public function __construct( ?array $override = null ) {
		if ( null === $override ) {
			$stored   = get_option( self::OPTION, array() );
			$override = is_array( $stored ) ? $stored : array();
		}

		$this->values = array_merge( self::DEFAULTS, $override );
	}

	/**
	 * Returns one setting, or null for an unknown key.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public function get( string $key ) {
		return $this->values[ $key ] ?? null;
	}

	/**
	 * Returns every setting.
	 */
	public function all(): array {
		return $this->values;
	}

	/**
	 * Sanitizes raw form input into the five known settings.
	 *
	 * @param array $input Raw input.
	 */
	public static function sanitize( array $input ): array {
		$out = array();

		foreach ( self::DEFAULTS as $key => $default ) {
			if ( 'safe_mode' === $key ) {
				$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
			} elseif ( 'cli_path' === $key ) {
				$out[ $key ] = isset( $input[ $key ] ) && is_scalar( $input[ $key ] )
					? sanitize_text_field( (string) $input[ $key ] )
					: $default;
			} else {
				$raw         = $input[ $key ] ?? null;
				$out[ $key ] = is_numeric( $raw )
					? max( self::LIMITS[ $key ][0], min( self::LIMITS[ $key ][1], (int) $raw ) )
					: $default;
			}
		}

		return $out;
	}
}
