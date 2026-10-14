<?php
/**
 * Rate-limit window storage backed by transients.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Stores rate-limit state with get_transient / set_transient.
 */
final class TransientRateLimitStore implements RateLimitStore {

	/**
	 * Returns the stored value, or an empty array when none.
	 *
	 * @param string $key Storage key.
	 */
	public function get( string $key ): array {
		$value = get_transient( $key );

		return is_array( $value ) ? $value : array();
	}

	/**
	 * Stores a value for the given number of seconds.
	 *
	 * @param string $key   Storage key.
	 * @param array  $value Value.
	 * @param int    $ttl   Time to live in seconds.
	 */
	public function set( string $key, array $value, int $ttl ): void {
		set_transient( $key, $value, $ttl );
	}
}
