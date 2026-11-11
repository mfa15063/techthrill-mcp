<?php
/**
 * The code_file_edit tool.
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
 * Replaces an exact piece of text in a theme or plugin file.
 */
final class CodeFileEdit extends AbstractCodeTool {

	public function name(): string {
		return 'code_file_edit';
	}

	public function description(): string {
		return 'Replace an exact string in a file inside a theme or plugin folder. Works without WP-CLI or PHP eval. Fails when the text is not found, or is found more than once while replace_all is false. A PHP edit that would introduce a syntax error is refused; the previous version is backed up.';
	}

	public function inputSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'path'        => array(
					'type'        => 'string',
					'description' => 'Path relative to the WordPress root, inside wp-content/themes or wp-content/plugins.',
				),
				'old_string'  => array(
					'type'        => 'string',
					'description' => 'The exact text to replace, including whitespace.',
				),
				'new_string'  => array(
					'type'        => 'string',
					'description' => 'The replacement text.',
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

	protected function run( array $args, ToolContext $ctx ): ToolResult {
		$requested = (string) ( $args['path'] ?? '' );
		$old       = (string) ( $args['old_string'] ?? '' );
		$new       = (string) ( $args['new_string'] ?? '' );
		$all       = $this->flag( $args, 'replace_all', false );

		if ( '' === $old ) {
			throw new ToolFailure( 'old_string must not be empty.' );
		}
		if ( $old === $new ) {
			throw new ToolFailure( 'old_string and new_string are identical; nothing to change.' );
		}

		$path = $this->existing( $requested );
		$this->allow( $path );
		$this->requireCodeName( $this->baseName( $requested ) );

		$text  = $this->readText( $path );
		$count = substr_count( $text, $old );
		if ( 0 === $count ) {
			throw new ToolFailure( 'old_string was not found in ' . $path->relative . '.' );
		}
		if ( $count > 1 && ! $all ) {
			throw new ToolFailure( 'old_string was found ' . $count . ' times in ' . $path->relative . '. Add more surrounding text to make it unique, or set replace_all to true.' );
		}

		$result = $all ? str_replace( $old, $new, $text ) : substr_replace( $text, $new, (int) strpos( $text, $old ), strlen( $old ) );

		$warning = null;
		if ( FileRules::isPhp( $requested ) ) {
			$error = PhpSyntax::check( $result );
			if ( null !== $error ) {
				if ( null === PhpSyntax::check( $text ) ) {
					throw new ToolFailure( 'The edit would introduce a PHP syntax error: ' . $error . '. Nothing was changed.' );
				}
				$warning = 'The file did not parse before this edit and still does not: ' . $error;
			}
		}

		$backup = $this->backupOf( $path );
		$error  = $this->atomicWrite( $path->absolute, $result );
		if ( null !== $error ) {
			throw new ToolFailure( $error );
		}

		$data = array(
			'path'         => $path->relative,
			'replacements' => $all ? $count : 1,
			'bytes'        => strlen( $result ),
			'backup'       => $backup,
		);
		if ( null !== $warning ) {
			$data['warning'] = $warning;
		}

		return $this->ok( $data );
	}
}
