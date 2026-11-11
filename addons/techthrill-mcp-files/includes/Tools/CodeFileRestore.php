<?php
/**
 * The code_file_restore tool.
 *
 * @package TechThrill\MCP\Files
 */

namespace TechThrill\MCP\Files\Tools;

use TechThrill\MCP\Files\Support\ToolFailure;
use TechThrill\MCP\Tools\ToolContext;
use TechThrill\MCP\Tools\ToolResult;

defined( 'ABSPATH' ) || exit;

/**
 * Puts a saved earlier version of a file back.
 */
final class CodeFileRestore extends AbstractCodeTool {

	public function name(): string {
		return 'code_file_restore';
	}

	public function description(): string {
		return 'Restore a saved earlier version of a file (the newest one unless you give an id from code_file_backups). The current content is backed up first, so a restore can be undone the same way.';
	}

	public function inputSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'path'   => array(
					'type'        => 'string',
					'description' => 'File path relative to the WordPress root.',
				),
				'backup' => array(
					'type'        => 'string',
					'description' => 'Backup id from code_file_backups. Defaults to the newest.',
				),
			),
			'required'   => array( 'path' ),
		);
	}

	public function scope(): ?string {
		return 'files:write';
	}

	protected function run( array $args, ToolContext $ctx ): ToolResult {
		$requested = (string) ( $args['path'] ?? '' );
		$id        = isset( $args['backup'] ) && '' !== (string) $args['backup'] ? (string) $args['backup'] : null;

		$path = $this->forCreate( $requested );
		$this->allow( $path );
		if ( is_dir( $path->absolute ) ) {
			throw new ToolFailure( $path->relative . ' is a folder.' );
		}

		$source = $this->backups->pathFor( $path->relative, $id );
		if ( null === $source ) {
			throw new ToolFailure( 'There is no ' . ( null === $id ? '' : 'such ' ) . 'backup of ' . $path->relative . '. Use code_file_backups to see what exists.' );
		}
		$content = @file_get_contents( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- Reads the addon's own backup file.
		if ( false === $content ) {
			throw new ToolFailure( 'Could not read the backup.' );
		}

		$this->ensureParent( $path, true );
		$current = $this->backupOf( $path );

		$error = $this->atomicWrite( $path->absolute, $content );
		if ( null !== $error ) {
			throw new ToolFailure( $error );
		}

		return $this->ok(
			array(
				'path'            => $path->relative,
				'restored'        => basename( $source, '.bak' ),
				'bytes'           => strlen( $content ),
				'previous_backup' => $current,
			)
		);
	}
}
