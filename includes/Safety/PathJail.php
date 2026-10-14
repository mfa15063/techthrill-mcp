<?php
/**
 * Path jail: keeps file operations inside a root directory.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Safety;

defined( 'ABSPATH' ) || exit;

/**
 * Validates agent-supplied relative paths and resolves them under a fixed root.
 *
 * Input is rejected on syntax alone (traversal, absolute paths, NUL bytes) before
 * the filesystem is consulted; the resolved real path is then checked against the
 * real root so symlinks cannot escape.
 */
final class PathJail {
	/**
	 * Longest accepted input, in bytes.
	 */
	const MAX_INPUT_LENGTH = 4096;

	/**
	 * Real path of the jail root.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * @param string $root Jail root directory; must exist.
	 * @throws PathException When the root does not exist.
	 */
	public function __construct( string $root ) {
		$real = false === strpos( $root, "\0" ) ? realpath( $root ) : false;
		if ( false === $real || ! is_dir( $real ) ) {
			throw new PathException( 'Jail root does not exist.' );
		}
		$this->root = $real;
	}

	/**
	 * Normalizes an agent-supplied path to forward-slash, root-relative form.
	 *
	 * @param string $relative Raw path.
	 * @return string Normalized path, or '.' for the root.
	 * @throws PathException On traversal, absolute paths, NUL bytes or oversize input.
	 */
	public function normalize( string $relative ): string {
		if ( strlen( $relative ) > self::MAX_INPUT_LENGTH ) {
			throw new PathException( 'Path is too long.' );
		}
		// The input is a literal string: it is deliberately not URL-decoded.
		if ( false !== strpos( $relative, "\0" ) ) {
			throw new PathException( 'Path contains a NUL byte.' );
		}
		$path = str_replace( '\\', '/', $relative );
		if ( '/' === substr( $path, 0, 1 ) ) {
			$path = substr( $path, 1 );
		}
		if ( '/' === substr( $path, 0, 1 ) || 1 === preg_match( '/^[A-Za-z]:/', $path ) ) {
			throw new PathException( 'Absolute paths are not allowed.' );
		}

		$segments = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			// Dot-only segments ('..', '...') and dot/space runs are collapsed by Windows into '..'.
			if ( '' === rtrim( $segment, '. ' ) ) {
				throw new PathException( 'Path traversal is not allowed.' );
			}
			if ( '\\' === DIRECTORY_SEPARATOR ) {
				// Win32 strips trailing dots/spaces, so 'wp-config.php.' would address 'wp-config.php'.
				if ( rtrim( $segment, '. ' ) !== $segment ) {
					throw new PathException( 'Path segments must not end with a dot or space.' );
				}
				if ( false !== strpos( $segment, ':' ) ) {
					throw new PathException( 'Invalid character in path.' );
				}
			}
			$segments[] = $segment;
		}

		return array() === $segments ? '.' : implode( '/', $segments );
	}

	/**
	 * Resolves an existing path inside the root.
	 *
	 * @param string $relative Raw path.
	 * @return JailedPath
	 * @throws PathException When the path is invalid, missing or outside the root.
	 */
	public function resolve( string $relative ): JailedPath {
		return $this->resolveExisting( $this->join( $this->normalize( $relative ) ) );
	}

	/**
	 * Resolves a path that may not exist yet; its nearest existing ancestor must be a directory inside the root.
	 *
	 * @param string $relative Raw path.
	 * @return JailedPath
	 * @throws PathException When the path is invalid, is the root, or would be created outside the root.
	 */
	public function resolveForCreate( string $relative ): JailedPath {
		$normalized = $this->normalize( $relative );
		if ( '.' === $normalized ) {
			throw new PathException( 'The root itself cannot be created.' );
		}

		$segments = explode( '/', $normalized );
		$count    = count( $segments );

		for ( $i = $count; $i >= 0; $i-- ) {
			$candidate = $this->join( implode( '/', array_slice( $segments, 0, $i ) ) );
			// is_link catches dangling symlinks, which file_exists reports as missing.
			if ( ! file_exists( $candidate ) && ! is_link( $candidate ) ) {
				continue;
			}
			$existing = $this->resolveExisting( $candidate );
			if ( $i === $count ) {
				return $existing;
			}
			if ( ! is_dir( $existing->absolute ) ) {
				throw new PathException( 'Parent is not a directory.' );
			}
			$rest     = array_slice( $segments, $i );
			$absolute = rtrim( $existing->absolute, '/\\' ) . DIRECTORY_SEPARATOR . implode( DIRECTORY_SEPARATOR, $rest );
			$rel      = '.' === $existing->relative ? implode( '/', $rest ) : $existing->relative . '/' . implode( '/', $rest );
			return new JailedPath( $absolute, $rel );
		}

		// Unreachable: the root always exists.
		throw new PathException( 'Path is outside the allowed root.' );
	}

	/**
	 * Joins a normalized relative path to the root.
	 *
	 * @param string $normalized Normalized path ('.' or segments).
	 * @return string
	 */
	private function join( string $normalized ): string {
		if ( '' === $normalized || '.' === $normalized ) {
			return $this->root;
		}
		return rtrim( $this->root, '/\\' ) . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $normalized );
	}

	/**
	 * Resolves symlinks of an existing path and checks it is inside the root.
	 *
	 * @param string $candidate Path under the root.
	 * @return JailedPath
	 * @throws PathException When missing, a dangling link, or outside the root.
	 */
	private function resolveExisting( string $candidate ): JailedPath {
		$real = realpath( $candidate );
		if ( false === $real ) {
			throw new PathException( 'Path does not exist.' );
		}
		if ( ! $this->isInside( $real ) ) {
			throw new PathException( 'Path is outside the allowed root.' );
		}
		return new JailedPath( $real, $this->relativeTo( $real ) );
	}

	/**
	 * Whether a real path equals the root or lies beneath it.
	 *
	 * @param string $real Real path.
	 * @return bool
	 */
	private function isInside( string $real ): bool {
		$root = $this->root;
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			// NTFS is case-insensitive and realpath may change drive-letter case.
			$real = strtolower( $real );
			$root = strtolower( $root );
		}
		if ( $real === $root ) {
			return true;
		}
		$prefix = rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR;
		return 0 === strncmp( $real, $prefix, strlen( $prefix ) );
	}

	/**
	 * Root-relative, forward-slash form of a real path already known to be inside the root.
	 *
	 * @param string $real Real path.
	 * @return string
	 */
	private function relativeTo( string $real ): string {
		$tail = substr( $real, strlen( rtrim( $this->root, '/\\' ) ) );
		$tail = trim( str_replace( '\\', '/', $tail ), '/' );
		return '' === $tail ? '.' : $tail;
	}
}
