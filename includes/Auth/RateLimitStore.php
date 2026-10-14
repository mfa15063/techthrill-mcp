<?php
/**
 * Rate limit storage contract.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Short-lived key/value storage for rate limit windows.
 */
interface RateLimitStore {

	/**
	 * Returns the stored list for a key, or an empty array.
	 *
	 * @param string $key Storage key.
	 * @return array
	 */
	public function get( string $key ): array;

	/**
	 * Stores a list for a key.
	 *
	 * @param string $key   Storage key.
	 * @param array  $value Value to store.
	 * @param int    $ttl   Seconds until the value expires.
	 */
	public function set( string $key, array $value, int $ttl ): void;
}
