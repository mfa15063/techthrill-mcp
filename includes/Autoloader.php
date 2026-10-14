<?php
/**
 * PSR-4 autoloader for the TechThrill\MCP namespace.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Maps TechThrill\MCP\Foo\Bar to <baseDir>/includes/Foo/Bar.php.
 */
final class Autoloader {

	private const PREFIX = 'TechThrill\\MCP\\';

	/**
	 * Registers the autoloader.
	 *
	 * @param string $baseDir Plugin root directory.
	 */
	public static function register( string $baseDir ): void {
		$root = rtrim( $baseDir, '/\\' ) . '/includes/';

		spl_autoload_register(
			static function ( string $className ) use ( $root ): void {
				if ( strpos( $className, self::PREFIX ) !== 0 ) {
					return;
				}

				$relative = substr( $className, strlen( self::PREFIX ) );
				$file     = $root . str_replace( '\\', '/', $relative ) . '.php';

				if ( is_file( $file ) ) {
					require $file;
				}
			}
		);
	}
}
