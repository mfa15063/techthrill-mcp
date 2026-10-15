<?php
/**
 * The file_read tool.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Tools;

use TechThrill\MCP\Safety\PathException;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- This tool operates directly on site files that PathJail has already confined; WP_Filesystem needs credentials and an admin request context, and every silenced call is checked through its return value.
defined( 'ABSPATH' ) || exit;

/**
 * Reads a text file, optionally a window of lines.
 */
final class FileRead extends AbstractFileTool {

	/**
	 * Largest readable file, in bytes.
	 */
	const MAX_BYTES = 5242880;

	public function name(): string {
		return 'file_read';
	}

	public function description(): string {
		return 'Read a text file relative to the WordPress root, with optional line offset and limit. Binary files and files over 5 MB are refused.';
	}

	public function inputSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'path'   => array(
					'type'        => 'string',
					'description' => 'File path relative to the WordPress root.',
				),
				'offset' => array(
					'type'        => 'integer',
					'description' => 'First line to return (1-based).',
					'default'     => 1,
					'minimum'     => 1,
				),
				'limit'  => array(
					'type'        => 'integer',
					'description' => 'Maximum number of lines to return.',
					'default'     => 2000,
					'minimum'     => 1,
					'maximum'     => 5000,
				),
			),
			'required'   => array( 'path' ),
		);
	}

	public function scope(): ?string {
		return 'files:read';
	}

	public function execute( array $args, ToolContext $ctx ): ToolResult {
		$offset = isset( $args['offset'] ) ? max( 1, (int) $args['offset'] ) : 1;
		$limit  = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : 2000;

		try {
			$file = $this->jail->resolve( (string) ( $args['path'] ?? '' ) );
		} catch ( PathException $e ) {
			return $this->fail( $e->getMessage() );
		}
		if ( ! is_file( $file->absolute ) ) {
			return $this->fail( $file->relative . ' is not a file.' );
		}
		if ( 'wp-config.php' === strtolower( $file->relative ) && ! $ctx->hasScope( 'db:read' ) ) {
			return $this->fail( 'Refused: reading wp-config.php requires the db:read scope because it contains database credentials.' );
		}

		$size = (int) filesize( $file->absolute );
		if ( $size > self::MAX_BYTES ) {
			return $this->fail( $file->relative . ' is ' . $size . ' bytes; file_read is limited to 5 MB.' );
		}
		if ( $this->isBinary( $file->absolute ) ) {
			$mime = function_exists( 'mime_content_type' ) ? mime_content_type( $file->absolute ) : false;
			$mime = false === $mime ? 'application/octet-stream' : $mime;
			return $this->fail( $file->relative . ' is a binary file (' . $size . ' bytes, ' . $mime . '); file_read only returns text files. To duplicate it, use file_copy.' );
		}

		$text = file_get_contents( $file->absolute );
		if ( false === $text ) {
			return $this->fail( 'Could not read ' . $file->relative . '.' );
		}

		$lines = '' === $text ? array() : explode( "\n", $text );
		if ( array() !== $lines && '' === end( $lines ) ) {
			array_pop( $lines );
		}
		$total  = count( $lines );
		$window = array_map(
			static function ( string $line ): string {
				return rtrim( $line, "\r" );
			},
			array_slice( $lines, $offset - 1, $limit )
		);

		return $this->ok(
			array(
				'path'        => $file->relative,
				'content'     => implode( "\n", $window ),
				'total_lines' => $total,
				'offset'      => $offset,
				'truncated'   => $offset - 1 + $limit < $total,
				'line_ending' => false !== strpos( $text, "\r\n" ) ? 'crlf' : 'lf',
			)
		);
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged
