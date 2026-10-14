<?php
/**
 * Plugin singleton.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin's components into WordPress.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	private function __construct() {}

	/**
	 * Returns the singleton instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers hooks.
	 */
	public function boot(): void {
		// Wired in later tasks.
	}
}
