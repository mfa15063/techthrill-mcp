<?php
/**
 * The code_file_write tool.
 *
 * @package TechThrill\MCP\Files
 */

namespace TechThrill\MCP\Files\Tools;

use TechThrill\MCP\Files\Support\FileRules;
use TechThrill\MCP\Files\Support\PhpSyntax;
use TechThrill\MCP\Files\Support\ToolFailure;
use TechThrill\MCP\Tools\ToolContext;
use TechThrill\MCP\Tools\ToolResult;

defined( 'ABSPATH' ) || exit;

/**
 * Creates a file or replaces one completely, anywhere on the site.
 */
final class CodeFileWrite extends AbstractCodeTool {

	public function name(): string {
		return 'code_file_write';
	}

	public function description(): string {
		return 'Create a file, or replace its whole content, anywhere on the site (php, html, css, js, json and other text files). Works without WP-CLI or PHP eval. PHP is syntax-checked first and nothing is written when it would break the site; the previous version is backed up and can be restored with code_file_restore.';
	}

	public function inputSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'path'              => array(
					'type'        => 'string',
					'description' => 'Path relative to the WordPress root, for example wp-content/themes/my-child/style.css, wp-config.php or .htaccess.',
				),
				'content'           => array(
					'type'        => 'string',
					'description' => 'The complete new content of the file.',
				),
				'overwrite'         => array(
					'type'        => 'boolean',
					'description' => 'Replace the file when it exists. Set false to only create.',
					'default'     => true,
				),
				'create_dirs'       => array(
					'type'        => 'boolean',
					'description' => 'Create missing parent folders.',
					'default'     => true,
				),
				'skip_syntax_check' => array(
					'type'        => 'boolean',
					'description' => 'Write PHP even when it does not parse. Only when you are sure; a PHP syntax error can take the site down.',
					'default'     => false,
				),
			),
			'required'   => array( 'path', 'content' ),
		);
	}

	public function scope(): ?string {
		return 'files:write';
	}

	protected function run( array $args, ToolContext $ctx ): ToolResult {
		$requested = (string) ( $args['path'] ?? '' );
		$content   = (string) ( $args['content'] ?? '' );
		$overwrite = $this->flag( $args, 'overwrite', true );
		$create    = $this->flag( $args, 'create_dirs', true );
		$skip      = $this->flag( $args, 'skip_syntax_check', false );

		$path = $this->forCreate( $requested );
		$this->allow( $path );
		if ( strlen( $content ) > self::MAX_TEXT ) {
			throw new ToolFailure( 'The content is ' . strlen( $content ) . ' bytes; this tool is limited to 5 MB.' );
		}
		if ( is_dir( $path->absolute ) ) {
			throw new ToolFailure( $path->relative . ' is a folder.' );
		}

		$existed = file_exists( $path->absolute );
		if ( $existed && ! $overwrite ) {
			throw new ToolFailure( $path->relative . ' already exists; set overwrite to true to replace it.' );
		}

		$syntax = 'not_php';
		if ( FileRules::isPhp( $requested ) ) {
			$syntax = 'skipped';
			if ( ! $skip ) {
				$error = PhpSyntax::check( $content );
				if ( null !== $error ) {
					throw new ToolFailure( 'PHP syntax error: ' . $error . '. Nothing was written. Fix the code and try again, or set skip_syntax_check to true only if you are sure.' );
				}
				$syntax = 'ok';
			}
		}

		$this->ensureParent( $path, $create );
		$backup = $existed ? $this->backupOf( $path ) : null;

		$error = $this->atomicWrite( $path->absolute, $content );
		if ( null !== $error ) {
			throw new ToolFailure( $error );
		}

		return $this->ok(
			array(
				'path'       => $path->relative,
				'bytes'      => strlen( $content ),
				'created'    => ! $existed,
				'php_syntax' => $syntax,
				'backup'     => $backup,
			)
		);
	}
}
