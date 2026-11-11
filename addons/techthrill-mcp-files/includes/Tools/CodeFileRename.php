<?php
/**
 * The code_file_rename tool.
 *
 * @package TechThrill\MCP\Files
 */

namespace TechThrill\MCP\Files\Tools;

use TechThrill\MCP\Files\Support\FileRules;
use TechThrill\MCP\Files\Support\ToolFailure;
use TechThrill\MCP\Tools\ToolContext;
use TechThrill\MCP\Tools\ToolResult;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- Moves inside theme and plugin folders that PathJail has already confined; every silenced call is checked through its return value.
defined( 'ABSPATH' ) || exit;

/**
 * Renames or moves a file or a folder, inside the theme and plugin folders.
 */
final class CodeFileRename extends AbstractCodeTool {

	public function name(): string {
		return 'code_file_rename';
	}

	public function description(): string {
		return 'Rename or move a file or folder inside the theme and plugin folders. Works without WP-CLI. An existing destination file is replaced only with overwrite=true (its old version is backed up). Renaming an active theme or plugin folder or main file needs confirm_active=true because it deactivates or breaks it; the TechThrill MCP plugin folders can never be renamed.';
	}

	public function inputSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'from'           => array(
					'type'        => 'string',
					'description' => 'Existing path relative to the WordPress root.',
				),
				'to'             => array(
					'type'        => 'string',
					'description' => 'New path relative to the WordPress root.',
				),
				'overwrite'      => array(
					'type'        => 'boolean',
					'description' => 'Replace an existing destination file.',
					'default'     => false,
				),
				'create_dirs'    => array(
					'type'        => 'boolean',
					'description' => 'Create missing parent folders of the destination.',
					'default'     => true,
				),
				'confirm_active' => array(
					'type'        => 'boolean',
					'description' => 'Required to rename the folder or main file of an active theme or plugin.',
					'default'     => false,
				),
			),
			'required'   => array( 'from', 'to' ),
		);
	}

	public function scope(): ?string {
		return 'files:write';
	}

	protected function run( array $args, ToolContext $ctx ): ToolResult {
		$from_arg  = (string) ( $args['from'] ?? '' );
		$to_arg    = (string) ( $args['to'] ?? '' );
		$overwrite = $this->flag( $args, 'overwrite', false );
		$create    = $this->flag( $args, 'create_dirs', true );
		$confirmed = $this->flag( $args, 'confirm_active', false );

		$from = $this->existing( $from_arg );
		$to   = $this->forCreate( $to_arg );
		if ( $from->absolute === $to->absolute ) {
			throw new ToolFailure( 'The source and the destination are the same path.' );
		}

		// A theme or plugin folder may be renamed as a whole; a file must stay inside one.
		$is_dir = is_dir( $from->absolute );
		$this->allow( $from, ! $is_dir );
		$this->allow( $to, ! $is_dir );
		$name = $this->baseName( $to_arg );
		if ( $is_dir ) {
			if ( ! FileRules::isSafeDirName( $name ) ) {
				throw new ToolFailure( $name . ' is not an acceptable folder name (letters, digits, dots, dashes, underscores and spaces; no dot folders).' );
			}
			$prefix = rtrim( $from->absolute, '/\\' ) . DIRECTORY_SEPARATOR;
			if ( 0 === strncmp( $to->absolute, $prefix, strlen( $prefix ) ) ) {
				throw new ToolFailure( 'A folder cannot be moved into itself.' );
			}
		} elseif ( ! FileRules::isCodeFile( $name ) && ! FileRules::isAssetFile( $name ) ) {
			throw new ToolFailure( $name . ' is not a file name this addon allows. Allowed text types: ' . FileRules::codeList() . '. Allowed asset types: ' . FileRules::assetList() . '.' );
		}

		foreach ( array( $from, $to ) as $side ) {
			if ( $this->isNeverMove( $side->relative ) ) {
				throw new ToolFailure( $side->relative . ' is part of TechThrill MCP and cannot be renamed, moved or replaced through the tools.' );
			}
			if ( ! $confirmed && $this->isActive( $side->relative ) ) {
				throw new ToolFailure( $side->relative . ' belongs to an active theme or plugin, and changing it would deactivate or break it. Set confirm_active to true if you really mean it.' );
			}
		}

		$replacing = file_exists( $to->absolute );
		if ( $replacing ) {
			if ( is_dir( $to->absolute ) || $is_dir ) {
				throw new ToolFailure( $to->relative . ' already exists; choose a new name for the folder, or move the file to a full destination file path.' );
			}
			if ( ! $overwrite ) {
				throw new ToolFailure( $to->relative . ' already exists; set overwrite to true to replace it.' );
			}
		}

		$this->ensureParent( $to, $create );

		$backup = $replacing ? $this->backupOf( $to ) : null;
		if ( ! @rename( $from->absolute, $to->absolute ) ) {
			// Windows cannot rename over an existing file.
			if ( ! ( $replacing && @unlink( $to->absolute ) && @rename( $from->absolute, $to->absolute ) ) ) {
				throw new ToolFailure( 'Could not rename ' . $from->relative . ' to ' . $to->relative . '.' );
			}
		}

		return $this->ok(
			array(
				'from'        => $from->relative,
				'to'          => $to->relative,
				'type'        => $is_dir ? 'dir' : 'file',
				'overwritten' => $replacing,
				'backup'      => $backup,
			)
		);
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged
