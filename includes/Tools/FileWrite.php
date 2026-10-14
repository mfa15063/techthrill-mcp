<?php
/**
 * The file_write tool.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Tools;

use TechThrill\MCP\Safety\PathException;

defined( 'ABSPATH' ) || exit;

/**
 * Creates or overwrites a file atomically.
 */
final class FileWrite extends AbstractFileTool {

	public function name(): string {
		return 'file_write';
	}

	public function description(): string {
		return "Create or overwrite a file relative to the WordPress root. Writes are atomic. Safe mode protects wp-config.php, .htaccess and the plugin's own directory.";
	}

	public function inputSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'path'        => array(
					'type'        => 'string',
					'description' => 'File path relative to the WordPress root.',
				),
				'content'     => array(
					'type'        => 'string',
					'description' => 'Full file content.',
				),
				'create_dirs' => array(
					'type'        => 'boolean',
					'description' => 'Create missing parent directories.',
					'default'     => true,
				),
			),
			'required'   => array( 'path', 'content' ),
		);
	}

	public function scope(): ?string {
		return 'files:write';
	}

	public function execute( array $args, ToolContext $ctx ): ToolResult {
		$content     = (string) ( $args['content'] ?? '' );
		$create_dirs = ! isset( $args['create_dirs'] ) || (bool) $args['create_dirs'];

		try {
			$target = $this->jail->resolveForCreate( (string) ( $args['path'] ?? '' ) );
		} catch ( PathException $e ) {
			return $this->fail( $e->getMessage() );
		}

		$refusal = $this->safeMode->checkPathWrite( $target->relative );
		if ( null !== $refusal ) {
			return $this->fail( $refusal->reason() );
		}
		if ( is_dir( $target->absolute ) ) {
			return $this->fail( $target->relative . ' is a directory.' );
		}

		$parent = dirname( $target->absolute );
		if ( ! is_dir( $parent ) ) {
			if ( ! $create_dirs ) {
				return $this->fail( 'The parent directory of ' . $target->relative . ' does not exist; set create_dirs to true.' );
			}
			if ( ! @mkdir( $parent, 0755, true ) && ! is_dir( $parent ) ) {
				return $this->fail( 'Could not create the parent directory of ' . $target->relative . '.' );
			}
		}

		$created = ! file_exists( $target->absolute );
		$error   = $this->atomicWrite( $target->absolute, $content );
		if ( null !== $error ) {
			return $this->fail( $error );
		}

		return $this->ok(
			array(
				'path'    => $target->relative,
				'bytes'   => strlen( $content ),
				'created' => $created,
			)
		);
	}
}
