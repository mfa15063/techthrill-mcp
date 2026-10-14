<?php
/**
 * The file_list tool.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Tools;

use TechThrill\MCP\Safety\PathException;

defined( 'ABSPATH' ) || exit;

/**
 * Lists files and directories under a path.
 */
final class FileList extends AbstractFileTool {

	public function name(): string {
		return 'file_list';
	}

	public function description(): string {
		return 'List files and directories under a path relative to the WordPress root. Use "." for the root.';
	}

	public function inputSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'path'        => array(
					'type'        => 'string',
					'description' => 'Directory relative to the WordPress root.',
					'default'     => '.',
				),
				'recursive'   => array(
					'type'        => 'boolean',
					'description' => 'List subdirectories too. Symlinked directories are listed but not entered.',
					'default'     => false,
				),
				'max_entries' => array(
					'type'        => 'integer',
					'description' => 'Maximum number of entries to return.',
					'default'     => 500,
					'minimum'     => 1,
					'maximum'     => 5000,
				),
			),
		);
	}

	public function scope(): ?string {
		return 'files:read';
	}

	public function execute( array $args, ToolContext $ctx ): ToolResult {
		$recursive = ! empty( $args['recursive'] );
		$max       = isset( $args['max_entries'] ) ? max( 1, (int) $args['max_entries'] ) : 500;

		try {
			$dir = $this->jail->resolve( isset( $args['path'] ) ? (string) $args['path'] : '.' );
		} catch ( PathException $e ) {
			return $this->fail( $e->getMessage() );
		}
		if ( ! is_dir( $dir->absolute ) ) {
			return $this->fail( $dir->relative . ' is not a directory.' );
		}

		$entries   = array();
		$truncated = false;
		try {
			$walker = $this->walk( $dir->absolute );
			if ( ! $recursive ) {
				$walker->setMaxDepth( 0 );
			}
			foreach ( $walker as $item ) {
				if ( count( $entries ) >= $max ) {
					$truncated = true;
					break;
				}
				$entries[] = $this->describe( $item, $this->relativePath( $item, $dir->absolute, $dir->relative ) );
			}
		} catch ( \UnexpectedValueException $e ) {
			return $this->fail( 'Could not read ' . $dir->relative . '.' );
		}

		usort(
			$entries,
			static function ( array $a, array $b ): int {
				return strcmp( $a['path'], $b['path'] );
			}
		);

		return $this->ok(
			array(
				'path'      => $dir->relative,
				'entries'   => $entries,
				'count'     => count( $entries ),
				'truncated' => $truncated,
			)
		);
	}

	/**
	 * Builds one entry.
	 *
	 * @param \SplFileInfo $item Item.
	 * @param string       $path Root-relative path.
	 */
	private function describe( \SplFileInfo $item, string $path ): array {
		$type  = $item->isLink() ? 'link' : ( $item->isDir() ? 'dir' : 'file' );
		$entry = array(
			'path' => $path,
			'type' => $type,
		);
		if ( 'file' === $type ) {
			$entry['size']      = (int) @filesize( $item->getPathname() );
			$entry['extension'] = strtolower( pathinfo( $item->getFilename(), PATHINFO_EXTENSION ) );
		}
		$entry['mtime'] = gmdate( 'Y-m-d\TH:i:s\Z', (int) @filemtime( $item->getPathname() ) );
		return $entry;
	}
}
