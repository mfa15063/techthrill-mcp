<?php
/**
 * Minimal option access used by the code store.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * One option per record, so a record can be consumed with a single atomic delete.
 */
interface OptionsGateway {

	/**
	 * The stored value, or null.
	 *
	 * @param string $name Option name.
	 */
	public function get( string $name ): ?array;

	/**
	 * Adds an option without autoloading it. Returns false when it already exists.
	 *
	 * @param string $name  Option name.
	 * @param array  $value Value.
	 */
	public function add( string $name, array $value ): bool;

	/**
	 * Deletes an option. True only for the caller whose delete removed it.
	 *
	 * @param string $name Option name.
	 */
	public function delete( string $name ): bool;

	/**
	 * Option names that start with a prefix.
	 *
	 * @param string $prefix Name prefix.
	 * @return string[]
	 */
	public function names( string $prefix ): array;
}
