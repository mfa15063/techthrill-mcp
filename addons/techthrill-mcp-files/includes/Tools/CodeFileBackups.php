<?php
/**
 * The code_file_backups tool.
 *
 * @package TechThrill\MCP\Files
 */

namespace TechThrill\MCP\Files\Tools;

use TechThrill\MCP\Tools\ToolContext;
use TechThrill\MCP\Tools\ToolResult;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the saved earlier versions of a file.
 */
final class CodeFileBackups extends AbstractCodeTool {

	public function name(): string {
		return 'code_file_backups';
	}

	public function description(): string {
		return 'List the saved earlier versions (backups) of a theme or plugin file, newest first. Every overwrite, edit, rename-over and replace made with this addon saves one; the newest 20 are kept. Use code_file_restore to bring one back.';
	}

	public function inputSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'path' => array(
					'type'        => 'string',
					'description' => 'File path relative to the WordPress root.',
				),
			),
			'required'   => array( 'path' ),
		);
	}

	public function scope(): ?string {
		return 'files:read';
	}

	protected function run( array $args, ToolContext $ctx ): ToolResult {
		$path = $this->forCreate( (string) ( $args['path'] ?? '' ) );
		if ( ! $this->roots->contains( $path->relative ) ) {
			return $this->fail( $path->relative . ' is outside the theme and plugin folders.' );
		}

		$list = array();
		foreach ( $this->backups->listFor( $path->relative ) as $backup ) {
			$list[] = array(
				'id'    => $backup['id'],
				'saved' => gmdate( 'Y-m-d H:i:s', $backup['time'] ) . ' UTC',
				'bytes' => $backup['bytes'],
			);
		}

		return $this->ok(
			array(
				'path'    => $path->relative,
				'backups' => $list,
			)
		);
	}
}
