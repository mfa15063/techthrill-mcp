<?php
/**
 * File name helpers for the addon.
 *
 * @package TechThrill\MCP\Files
 */

namespace TechThrill\MCP\Files\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The addon has no file type allowlist: any file on the site can be written, including dot files such as
 * .htaccess and every script type. Only names that cannot be a real file name are refused.
 */
final class FileRules {

	const MAX_NAME = 255;

	/**
	 * Extensions the PHP syntax check applies to.
	 */
	const PHP = array( 'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht' );

	/**
	 * Lower-case extension of a file name, or an empty string.
	 *
	 * @param string $name File name.
	 */
	public static function extension( string $name ): string {
		$base = strtolower( basename( str_replace( '\\', '/', $name ) ) );
		$dot  = strrpos( $base, '.' );

		return false === $dot || 0 === $dot ? '' : substr( $base, $dot + 1 );
	}

	/**
	 * Whether a base name can be a file or folder name: not empty, not too long, no control characters or slashes.
	 *
	 * @param string $name Base name.
	 */
	public static function isValidName( string $name ): bool {
		return '' !== $name && strlen( $name ) <= self::MAX_NAME && 1 === preg_match( '/^[^\x00-\x1F\x7F\/\\\\]+$/', $name );
	}

	/**
	 * Whether a file is PHP (and gets the syntax check).
	 *
	 * @param string $name File name.
	 */
	public static function isPhp( string $name ): bool {
		return in_array( self::extension( $name ), self::PHP, true );
	}
}
