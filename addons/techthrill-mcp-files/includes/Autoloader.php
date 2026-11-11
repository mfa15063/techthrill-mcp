<?php
/**
 * PSR-4 autoloader for the TechThrill\MCP\Files namespace.
 *
 * @package TechThrill\MCP\Files
 */

namespace TechThrill\MCP\Files;

defined( 'ABSPATH' ) || exit;

/**
 * Maps TechThrill\MCP\Files\Foo\Bar to <baseDir>/includes/Foo/Bar.php.
 */
final class Autoloader {

	private const PREFIX = 'TechThrill\\MCP\\Files\\';

	/**
	 * Registers the autoloader.
	 *
	 * @param string $baseDir Addon root directory.
	 */
	public static function register( string $baseDir ): void {
		$root = rtrim( $baseDir, '/\\' ) . '/includes/';

		spl_autoload_register(
			static function ( string $className ) use ( $root ): void {
				if ( 0 !== strpos( $className, self::PREFIX ) ) {
					return;
				}

				$relative = substr( $className, strlen( self::PREFIX ) );
				$file     = $root . str_replace( '\\', '/', $relative ) . '.php';

				if ( is_file( $file ) ) {
					require_once $file;
				}
			}
		);
	}
}
