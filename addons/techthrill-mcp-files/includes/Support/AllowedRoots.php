<?php
/**
 * The folders the addon may touch.
 *
 * @package TechThrill\MCP\Files
 */

namespace TechThrill\MCP\Files\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Root-relative folders such as wp-content/themes and wp-content/plugins. A path is allowed only strictly below
 * one of them, so the folder itself (and everything outside the folders) stays out of reach.
 */
final class AllowedRoots {

	/**
	 * Normalised roots, lower-cased for comparison only.
	 *
	 * @var string[]
	 */
	private array $roots = array();

	/**
	 * @param string[] $roots Root-relative folders with forward slashes.
	 */
	public function __construct( array $roots ) {
		foreach ( $roots as $root ) {
			$normal = self::normalize( (string) $root );
			if ( '' !== $normal ) {
				$this->roots[] = $normal;
			}
		}
		$this->roots = array_values( array_unique( $this->roots ) );
	}

	/**
	 * The configured roots.
	 *
	 * @return string[]
	 */
	public function roots(): array {
		return $this->roots;
	}

	/**
	 * Whether a root-relative path lies strictly below an allowed root.
	 *
	 * @param string $relative Root-relative path.
	 */
	public function contains( string $relative ): bool {
		return null !== $this->rootOf( $relative );
	}

	/**
	 * The allowed root a path lies below, or null.
	 *
	 * @param string $relative Root-relative path.
	 */
	public function rootOf( string $relative ): ?string {
		$path = self::normalize( $relative );
		foreach ( $this->roots as $root ) {
			if ( 0 === strpos( strtolower( $path ), strtolower( $root ) . '/' ) && strlen( $path ) > strlen( $root ) + 1 ) {
				return $root;
			}
		}

		return null;
	}

	/**
	 * How many path segments lie below the allowed root (1 for a theme or plugin folder, 2 for a file directly
	 * inside one), or null when the path is not below a root.
	 *
	 * @param string $relative Root-relative path.
	 */
	public function depth( string $relative ): ?int {
		$root = $this->rootOf( $relative );
		if ( null === $root ) {
			return null;
		}

		return count( explode( '/', substr( self::normalize( $relative ), strlen( $root ) + 1 ) ) );
	}

	/**
	 * The first path segment below the root (the theme or plugin folder name), or null.
	 *
	 * @param string $relative Root-relative path.
	 */
	public function packageOf( string $relative ): ?string {
		$root = $this->rootOf( $relative );
		if ( null === $root ) {
			return null;
		}

		$rest = substr( self::normalize( $relative ), strlen( $root ) + 1 );

		return explode( '/', $rest )[0];
	}

	/**
	 * Forward-slash path without empty or "." segments and without a leading or trailing slash.
	 *
	 * @param string $path Path.
	 */
	public static function normalize( string $path ): string {
		$segments = array_filter(
			explode( '/', str_replace( '\\', '/', $path ) ),
			static function ( $segment ) {
				return '' !== $segment && '.' !== $segment;
			}
		);

		return implode( '/', $segments );
	}
}
