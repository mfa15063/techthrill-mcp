<?php
/**
 * The code_replace_in_files tool.
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
 * Find and replace across the code files of one theme or plugin folder, a dry run first by default.
 */
final class CodeReplaceInFiles extends AbstractCodeTool {

	const MAX_FILES_LIMIT = 1000;

	public function name(): string {
		return 'code_replace_in_files';
	}

	public function description(): string {
		return 'Find and replace text across the text files (php, html, css, js, htaccess and any other non-binary file) inside any folder of the site, like a search-replace without WP-CLI. dry_run defaults to true: it only reports what would change, so run it first, then repeat with dry_run=false. A PHP file whose result would no longer parse is skipped, and every changed file is backed up.';
	}

	public function inputSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'folder'         => array(
					'type'        => 'string',
					'description' => 'Folder to search, relative to the WordPress root. Use a single dot for the whole site.',
				),
				'search'         => array(
					'type'        => 'string',
					'description' => 'Text to find, or a regular expression when regex is true.',
				),
				'replace'        => array(
					'type'        => 'string',
					'description' => 'Replacement text. With regex, $1 style references work.',
					'default'     => '',
				),
				'glob'           => array(
					'type'        => 'string',
					'description' => 'File name pattern such as *.css or style-*.php.',
					'default'     => '*',
				),
				'regex'          => array(
					'type'        => 'boolean',
					'description' => 'Treat search as a regular expression.',
					'default'     => false,
				),
				'case_sensitive' => array(
					'type'        => 'boolean',
					'description' => 'Match case.',
					'default'     => true,
				),
				'dry_run'        => array(
					'type'        => 'boolean',
					'description' => 'Only report what would change.',
					'default'     => true,
				),
				'max_files'      => array(
					'type'        => 'integer',
					'description' => 'Stop after this many changed files.',
					'default'     => 200,
					'minimum'     => 1,
					'maximum'     => 1000,
				),
			),
			'required'   => array( 'folder', 'search' ),
		);
	}

	public function scope(): ?string {
		return 'files:write';
	}

	protected function run( array $args, ToolContext $ctx ): ToolResult {
		$search  = (string) ( $args['search'] ?? '' );
		$replace = (string) ( $args['replace'] ?? '' );
		$glob    = isset( $args['glob'] ) && '' !== (string) $args['glob'] ? (string) $args['glob'] : '*';
		$regex   = $this->flag( $args, 'regex', false );
		$cs      = $this->flag( $args, 'case_sensitive', true );
		$dry     = $this->flag( $args, 'dry_run', true );
		$max     = max( 1, min( self::MAX_FILES_LIMIT, isset( $args['max_files'] ) ? (int) $args['max_files'] : 200 ) );

		if ( '' === $search || strlen( $search ) > 2000 || strlen( $replace ) > 20000 ) {
			throw new ToolFailure( 'search must be 1 to 2000 characters and replace at most 20000.' );
		}
		$pattern = null;
		if ( $regex ) {
			$pattern = '~' . str_replace( '~', '\\~', $search ) . '~' . ( $cs ? '' : 'i' );
			if ( false === @preg_match( $pattern, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Compiling a caller-supplied pattern; a false result is handled.
				throw new ToolFailure( 'search is not a valid regular expression.' );
			}
		}

		$folder = $this->existing( (string) ( $args['folder'] ?? '' ) );
		if ( ! is_dir( $folder->absolute ) ) {
			throw new ToolFailure( $folder->relative . ' is not a folder.' );
		}

		$backup_dir = rtrim( str_replace( '\\', '/', $this->backups->baseDir() ), '/' ) . '/';
		$scanned    = 0;
		$total      = 0;
		$changed    = array();
		$skipped    = array();
		$truncated  = false;

		try {
			foreach ( $this->walk( $folder->absolute ) as $item ) {
				if ( $item->isLink() || ! $item->isFile() ) {
					continue;
				}
				$name = $item->getFilename();
				if ( ! fnmatch( $glob, $name ) ) {
					continue;
				}

				if ( 0 === strncmp( str_replace( '\\', '/', $item->getPathname() ), $backup_dir, strlen( $backup_dir ) ) ) {
					continue;
				}
				$relative = $this->relativePath( $item, $folder->absolute, $folder->relative );
				if ( $item->getSize() > self::MAX_TEXT || $this->isBinary( $item->getPathname() ) ) {
					continue;
				}
				++$scanned;

				$text = (string) @file_get_contents( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local theme or plugin file already confined by the path jail.
				if ( $regex ) {
					$result = preg_replace( (string) $pattern, $replace, $text, -1, $count );
				} else {
					$result = $cs ? str_replace( $search, $replace, $text, $count ) : str_ireplace( $search, $replace, $text, $count );
				}
				if ( null === $result ) {
					$skipped[] = array(
						'path'   => $relative,
						'reason' => 'the replacement failed (invalid pattern or text)',
					);
					continue;
				}
				if ( 0 === $count ) {
					continue;
				}

				try {
					$this->allow( $this->existing( $relative ) );
				} catch ( ToolFailure $e ) {
					$skipped[] = array(
						'path'   => $relative,
						'reason' => $e->getMessage(),
					);
					continue;
				}
				if ( FileRules::isPhp( $name ) && null === PhpSyntax::check( $text ) ) {
					$error = PhpSyntax::check( $result );
					if ( null !== $error ) {
						$skipped[] = array(
							'path'   => $relative,
							'reason' => 'the result would no longer parse as PHP: ' . $error,
						);
						continue;
					}
				}

				if ( ! $dry ) {
					$this->backups->save( $relative, $item->getPathname() );
					$error = $this->atomicWrite( $item->getPathname(), $result );
					if ( null !== $error ) {
						$skipped[] = array(
							'path'   => $relative,
							'reason' => $error,
						);
						continue;
					}
				}

				$total    += $count;
				$changed[] = array(
					'path'         => $relative,
					'replacements' => $count,
				);
				if ( count( $changed ) >= $max ) {
					$truncated = true;
					break;
				}
			}
		} catch ( \UnexpectedValueException $e ) {
			throw new ToolFailure( 'Could not read ' . $folder->relative . '.' );
		}

		return $this->ok(
			array(
				'dry_run'       => $dry,
				'files_scanned' => $scanned,
				'files_changed' => $changed,
				'replacements'  => $total,
				'skipped'       => $skipped,
				'truncated'     => $truncated,
			)
		);
	}
}
