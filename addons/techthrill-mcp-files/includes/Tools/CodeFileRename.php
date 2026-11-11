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

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- Moves inside the site that PathJail has already confined; every silenced call is checked through its return value.
defined( 'ABSPATH' ) || exit;

/**
 * Renames or moves a file or a folder, anywhere on the site.
 */
final class CodeFileRename extends AbstractCodeTool {

	public function name(): string {
		return 'code_file_rename';
	}

	public function description(): string {
		return 'Rename or move any file or folder on the site. Works without WP-CLI. An existing destination file is replaced only with overwrite=true (its old version is backed up). Renaming an active theme or plugin (folder or main file), WordPress core folders, wp-config.php or .htaccess needs confirm_active=true because it can break the site.';
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
					'description' => 'Required to rename an active theme or plugin, a WordPress core folder, wp-config.php or .htaccess.',
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

		$is_dir = is_dir( $from->absolute );
		$this->allow( $from );
		$this->allow( $to );
		$name = $this->baseName( $to_arg );
		if ( ! FileRules::isValidName( $name ) ) {
			throw new ToolFailure( $name . ' is not a valid file or folder name.' );
		}
		if ( $is_dir ) {
			$prefix = rtrim( $from->absolute, '/\\' ) . DIRECTORY_SEPARATOR;
			if ( 0 === strncmp( $to->absolute, $prefix, strlen( $prefix ) ) ) {
				throw new ToolFailure( 'A folder cannot be moved into itself.' );
			}
		}

		foreach ( array( $from, $to ) as $side ) {
			if ( ! $confirmed && $this->isActive( $side->relative ) ) {
				throw new ToolFailure( $side->relative . ' is an active theme or plugin, a WordPress core path or a site config file, and changing it can break the site. Set confirm_active to true if you really mean it.' );
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
