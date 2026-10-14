<?php
/**
 * Base class for file tools.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Tools;

use TechThrill\MCP\Safety\PathJail;
use TechThrill\MCP\Safety\SafeMode;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- This tool operates directly on site files that PathJail has already confined; WP_Filesystem needs credentials and an admin request context, and every silenced call is checked through its return value.
defined( 'ABSPATH' ) || exit;

/**
 * Holds the path jail and safe mode, and the filesystem helpers the file tools share.
 */
abstract class AbstractFileTool extends AbstractTool {

	/**
	 * Number of leading bytes inspected for a null byte.
	 */
	const BINARY_SNIFF_BYTES = 8192;

	/**
	 * Path jail.
	 *
	 * @var PathJail
	 */
	protected PathJail $jail;

	/**
	 * Safe mode policy.
	 *
	 * @var SafeMode
	 */
	protected SafeMode $safeMode;

	/**
	 * @param PathJail $jail     Path jail.
	 * @param SafeMode $safeMode Safe mode policy.
	 */
	public function __construct( PathJail $jail, SafeMode $safeMode ) {
		$this->jail     = $jail;
		$this->safeMode = $safeMode;
	}

	/**
	 * Whether a file looks binary (null byte in its first 8 KB).
	 *
	 * @param string $absolute Absolute path of an existing file.
	 */
	protected function isBinary( string $absolute ): bool {
		$handle = @fopen( $absolute, 'rb' );
		if ( false === $handle ) {
			return false;
		}
		$head = (string) fread( $handle, self::BINARY_SNIFF_BYTES );
		fclose( $handle );
		return false !== strpos( $head, "\0" );
	}

	/**
	 * Writes a file atomically: temp file in the same directory, then rename.
	 *
	 * Preserves the permissions of an existing target. Leaves no temp file behind on failure.
	 *
	 * @param string $target  Absolute target path; its directory must exist.
	 * @param string $content File content.
	 * @return string|null Error message, or null on success.
	 */
	protected function atomicWrite( string $target, string $content ): ?string {
		$temp = dirname( $target ) . DIRECTORY_SEPARATOR . '.' . basename( $target ) . '.' . uniqid( '', true ) . '.tmp';

		if ( false === @file_put_contents( $temp, $content ) ) {
			@unlink( $temp );
			return 'Could not write the file.';
		}
		if ( file_exists( $target ) ) {
			$perms = @fileperms( $target );
			if ( false !== $perms ) {
				@chmod( $temp, $perms & 0777 );
			}
		}
		if ( ! @rename( $temp, $target ) ) {
			@unlink( $temp );
			return 'Could not replace the file.';
		}
		return null;
	}

	/**
	 * Iterator over everything below a directory. Symlinked directories are listed but never entered.
	 *
	 * @param string        $absolute Absolute directory path.
	 * @param int           $mode     RecursiveIteratorIterator mode.
	 * @param callable|null $filter   Optional accept callback ( SplFileInfo ): bool, applied at every level.
	 */
	protected function walk( string $absolute, int $mode = \RecursiveIteratorIterator::SELF_FIRST, ?callable $filter = null ): \RecursiveIteratorIterator {
		$inner = new \RecursiveDirectoryIterator( $absolute, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS );
		if ( null !== $filter ) {
			$inner = new \RecursiveCallbackFilterIterator(
				$inner,
				static function ( $current ) use ( $filter ) {
					return $filter( $current );
				}
			);
		}
		return new \RecursiveIteratorIterator( $inner, $mode, \RecursiveIteratorIterator::CATCH_GET_CHILD );
	}

	/**
	 * Root-relative, forward-slash path of an item found by walk().
	 *
	 * @param \SplFileInfo $item         Item.
	 * @param string       $baseAbsolute Absolute path walk() started from.
	 * @param string       $baseRelative Root-relative path walk() started from.
	 */
	protected function relativePath( \SplFileInfo $item, string $baseAbsolute, string $baseRelative ): string {
		$base = rtrim( str_replace( '\\', '/', $baseAbsolute ), '/' );
		$tail = ltrim( substr( str_replace( '\\', '/', $item->getPathname() ), strlen( $base ) ), '/' );
		return '.' === $baseRelative ? $tail : $baseRelative . '/' . $tail;
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged
