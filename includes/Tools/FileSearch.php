<?php
/**
 * The file_search tool.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Tools;

use TechThrill\MCP\Safety\PathException;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- This tool operates directly on site files that PathJail has already confined; WP_Filesystem needs credentials and an admin request context, and every silenced call is checked through its return value.
defined( 'ABSPATH' ) || exit;

/**
 * Searches file contents with a regular expression.
 */
final class FileSearch extends AbstractFileTool {

	/**
	 * Largest searched file, in bytes.
	 */
	const MAX_FILE_BYTES = 2097152;

	/**
	 * Longest returned line, in bytes.
	 */
	const MAX_LINE_BYTES = 500;

	public function name(): string {
		return 'file_search';
	}

	public function description(): string {
		return 'Search file contents under a path with a PCRE regular expression, optionally filtered by a filename glob such as *.php.';
	}

	public function inputSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'pattern'        => array(
					'type'        => 'string',
					'description' => 'PCRE regular expression without delimiters.',
				),
				'path'           => array(
					'type'        => 'string',
					'description' => 'Directory to search, relative to the WordPress root.',
					'default'     => '.',
				),
				'glob'           => array(
					'type'        => 'string',
					'description' => 'Filename filter such as *.php. Empty matches all files.',
					'default'     => '',
				),
				'max_results'    => array(
					'type'        => 'integer',
					'description' => 'Maximum number of matching lines to return.',
					'default'     => 200,
					'minimum'     => 1,
					'maximum'     => 1000,
				),
				'case_sensitive' => array(
					'type'        => 'boolean',
					'description' => 'Match case.',
					'default'     => true,
				),
			),
			'required'   => array( 'pattern' ),
		);
	}

	public function scope(): ?string {
		return 'files:read';
	}

	public function execute( array $args, ToolContext $ctx ): ToolResult {
		$glob = isset( $args['glob'] ) ? (string) $args['glob'] : '';
		$max  = isset( $args['max_results'] ) ? max( 1, (int) $args['max_results'] ) : 200;
		$case = ! isset( $args['case_sensitive'] ) || (bool) $args['case_sensitive'];

		$regex = '/' . $this->escapeDelimiter( (string) ( $args['pattern'] ?? '' ) ) . '/' . ( $case ? '' : 'i' );
		error_clear_last();
		if ( false === @preg_match( $regex, '' ) ) {
			return $this->fail( 'Invalid regular expression: ' . $this->regexError() );
		}

		try {
			$dir = $this->jail->resolve( isset( $args['path'] ) ? (string) $args['path'] : '.' );
		} catch ( PathException $e ) {
			return $this->fail( $e->getMessage() );
		}
		if ( ! is_dir( $dir->absolute ) ) {
			return $this->fail( $dir->relative . ' is not a directory.' );
		}

		$ignore = apply_filters( 'techthrill_mcp_search_ignore', array( '.git', 'node_modules', 'vendor' ) );
		$ignore = is_array( $ignore ) ? $ignore : array( '.git', 'node_modules', 'vendor' );

		$matches   = array();
		$truncated = false;
		try {
			$walker = $this->walk(
				$dir->absolute,
				\RecursiveIteratorIterator::SELF_FIRST,
				static function ( \SplFileInfo $item ) use ( $ignore ): bool {
					return ! ( $item->isDir() && in_array( $item->getFilename(), $ignore, true ) );
				}
			);
			foreach ( $walker as $item ) {
				// Symlinked files are never read: they could point outside the jail.
				if ( $item->isLink() || ! $item->isFile() || ( '' !== $glob && ! fnmatch( $glob, $item->getFilename() ) ) ) {
					continue;
				}
				$path = $this->relativePath( $item, $dir->absolute, $dir->relative );
				if ( 'wp-config.php' === strtolower( $path ) && ! $ctx->hasScope( 'db:read' ) ) {
					continue;
				}
				if ( (int) $item->getSize() > self::MAX_FILE_BYTES || $this->isBinary( $item->getPathname() ) ) {
					continue;
				}
				if ( $this->searchFile( $item->getPathname(), $path, $regex, $max, $matches ) ) {
					$truncated = true;
					break;
				}
			}
		} catch ( \UnexpectedValueException $e ) {
			return $this->fail( 'Could not read ' . $dir->relative . '.' );
		}

		return $this->ok(
			array(
				'matches'   => $matches,
				'count'     => count( $matches ),
				'truncated' => $truncated,
			)
		);
	}

	/**
	 * Appends the matching lines of one file.
	 *
	 * @param string $absolute Absolute file path.
	 * @param string $path     Root-relative path.
	 * @param string $regex    Compiled pattern.
	 * @param int    $max      Result cap.
	 * @param array  $matches  Result list (by reference).
	 * @return bool True when another match was found beyond the cap.
	 */
	private function searchFile( string $absolute, string $path, string $regex, int $max, array &$matches ): bool {
		$content = @file_get_contents( $absolute );
		if ( false === $content ) {
			return false;
		}
		foreach ( explode( "\n", $content ) as $index => $line ) {
			$text = rtrim( $line, "\r\n" );
			if ( 1 !== @preg_match( $regex, $text ) ) {
				continue;
			}
			if ( count( $matches ) >= $max ) {
				return true;
			}
			$matches[] = array(
				'path' => $path,
				'line' => $index + 1,
				'text' => function_exists( 'mb_strcut' ) ? mb_strcut( $text, 0, self::MAX_LINE_BYTES, 'UTF-8' ) : substr( $text, 0, self::MAX_LINE_BYTES ),
			);
		}
		return false;
	}

	/**
	 * Escapes unescaped "/" so the pattern can sit between "/" delimiters.
	 *
	 * @param string $pattern Raw pattern.
	 */
	private function escapeDelimiter( string $pattern ): string {
		$out    = '';
		$length = strlen( $pattern );
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $pattern[ $i ];
			if ( '\\' === $char && $i + 1 < $length ) {
				$out .= $char . $pattern[ ++$i ];
			} else {
				$out .= '/' === $char ? '\/' : $char;
			}
		}
		return $out;
	}

	/**
	 * Reason the last pattern failed to compile.
	 */
	private function regexError(): string {
		$last = error_get_last();
		if ( null !== $last && 1 === preg_match( '/^preg_match\(\): (.+)$/s', (string) $last['message'], $m ) ) {
			return $m[1];
		}
		return function_exists( 'preg_last_error_msg' ) ? preg_last_error_msg() : 'the pattern could not be compiled.';
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged
