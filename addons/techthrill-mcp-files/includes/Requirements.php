<?php
/**
 * What this addon needs from the main plugin.
 *
 * @package TechThrill\MCP\Files
 */

namespace TechThrill\MCP\Files;

defined( 'ABSPATH' ) || exit;

/**
 * Pure checks, with no dependency on the main plugin's classes (they may not exist when this runs).
 */
final class Requirements {

	/**
	 * Oldest main plugin version this addon works with.
	 */
	const MIN_VERSION = '1.5.1';

	const OK = 'ok';

	const MISSING = 'missing';

	const INACTIVE = 'inactive';

	const TOO_OLD = 'too_old';

	/**
	 * Where the main plugin stands.
	 *
	 * @param bool        $installed Whether the main plugin is installed.
	 * @param bool        $active    Whether it is active and loaded.
	 * @param string|null $version   Its version when loaded.
	 * @param string      $min       Oldest acceptable version.
	 */
	public static function status( bool $installed, bool $active, ?string $version, string $min = self::MIN_VERSION ): string {
		if ( ! $installed && ! $active ) {
			return self::MISSING;
		}
		if ( ! $active ) {
			return self::INACTIVE;
		}
		if ( null === $version || '' === $version || version_compare( $version, $min, '<' ) ) {
			return self::TOO_OLD;
		}

		return self::OK;
	}

	/**
	 * Plain-text message for a status, safe to show to an administrator. Empty when everything is fine.
	 *
	 * @param string      $status  One of the status constants.
	 * @param string|null $version The main plugin's version, when known.
	 * @param string      $min     Oldest acceptable version.
	 */
	public static function message( string $status, ?string $version = null, string $min = self::MIN_VERSION ): string {
		switch ( $status ) {
			case self::MISSING:
				return 'TechThrill MCP File Tools needs the main TechThrill MCP plugin, and it is not installed. Install and activate TechThrill MCP (version ' . $min . ' or newer) first, then activate this addon.';
			case self::INACTIVE:
				return 'TechThrill MCP File Tools needs the main TechThrill MCP plugin, which is installed but not active. Activate TechThrill MCP first, then activate this addon.';
			case self::TOO_OLD:
				return 'TechThrill MCP File Tools needs TechThrill MCP version ' . $min . ' or newer' . ( null !== $version && '' !== $version ? ' (this site has ' . $version . ')' : '' ) . '. Update the main plugin first.';
			default:
				return '';
		}
	}
}
