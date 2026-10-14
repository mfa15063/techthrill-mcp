<?php
/**
 * The file_edit tool.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Tools;

use TechThrill\MCP\Safety\PathException;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces an exact string in a file.
 */
final class FileEdit extends AbstractFileTool {

	public function name(): string {
		return 'file_edit';
	}

	public function description(): string {
		return 'Replace an exact string in a file. Fails unless old_string occurs exactly once, or set replace_all to true.';
	}

	public function inputSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'path'        => array(
					'type'        => 'string',
					'description' => 'File path relative to the WordPress root.',
				),
				'old_string'  => array(
					'type'        => 'string',
					'description' => 'Exact text to replace.',
				),
				'new_string'  => array(
					'type'        => 'string',
					'description' => 'Replacement text.',
				),
				'replace_all' => array(
					'type'        => 'boolean',
					'description' => 'Replace every occurrence instead of requiring exactly one.',
					'default'     => false,
				),
			),
			'required'   => array( 'path', 'old_string', 'new_string' ),
		);
	}

	public function scope(): ?string {
		return 'files:write';
	}

	public function execute( array $args, ToolContext $ctx ): ToolResult {
		$old         = (string) ( $args['old_string'] ?? '' );
		$new         = (string) ( $args['new_string'] ?? '' );
		$replace_all = ! empty( $args['replace_all'] );

		try {
			$file = $this->jail->resolve( (string) ( $args['path'] ?? '' ) );
		} catch ( PathException $e ) {
			return $this->fail( $e->getMessage() );
		}
		if ( ! is_file( $file->absolute ) ) {
			return $this->fail( $file->relative . ' is not a file.' );
		}

		$refusal = $this->safeMode->checkPathWrite( $file->relative );
		if ( null !== $refusal ) {
			return $this->fail( $refusal->reason() );
		}
		if ( '' === $old ) {
			return $this->fail( 'old_string must not be empty.' );
		}
		if ( $old === $new ) {
			return $this->fail( 'old_string and new_string are identical.' );
		}

		$text = file_get_contents( $file->absolute );
		if ( false === $text ) {
			return $this->fail( 'Could not read ' . $file->relative . '.' );
		}

		$count = substr_count( $text, $old );
		if ( 0 === $count && false !== strpos( $text, "\r\n" ) && false !== strpos( $old, "\n" ) && false === strpos( $old, "\r" ) ) {
			// file_read hides CRLF, so retry an LF multi-line string against a CRLF file.
			$old   = str_replace( "\n", "\r\n", $old );
			$new   = str_replace( "\n", "\r\n", $new );
			$count = substr_count( $text, $old );
		}
		if ( 0 === $count ) {
			return $this->fail( 'old_string was not found in ' . $file->relative . '.' );
		}
		if ( $count > 1 && ! $replace_all ) {
			return $this->fail( 'old_string matches ' . $count . ' places in ' . $file->relative . '; make it unique or set replace_all to true.' );
		}

		$error = $this->atomicWrite( $file->absolute, str_replace( $old, $new, $text ) );
		if ( null !== $error ) {
			return $this->fail( $error );
		}

		return $this->ok(
			array(
				'path'         => $file->relative,
				'replacements' => $count,
			)
		);
	}
}
