<?php
/**
 * The file_delete tool.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Tools;

use TechThrill\MCP\Safety\PathException;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes a file, or a directory tree.
 */
final class FileDelete extends AbstractFileTool {

	public function name(): string {
		return 'file_delete';
	}

	public function description(): string {
		return 'Delete a file, or a directory with recursive=true (directory deletion is blocked in safe mode).';
	}

	public function inputSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'path'      => array(
					'type'        => 'string',
					'description' => 'Path relative to the WordPress root.',
				),
				'recursive' => array(
					'type'        => 'boolean',
					'description' => 'Required to delete a directory and everything in it.',
					'default'     => false,
				),
			),
			'required'   => array( 'path' ),
		);
	}

	public function scope(): ?string {
		return 'files:write';
	}

	public function execute( array $args, ToolContext $ctx ): ToolResult {
		try {
			$target = $this->jail->resolve( (string) ( $args['path'] ?? '' ) );
		} catch ( PathException $e ) {
			return $this->fail( $e->getMessage() );
		}
		if ( '.' === $target->relative ) {
			return $this->fail( 'The WordPress root cannot be deleted.' );
		}

		$refusal = $this->safeMode->checkPathWrite( $target->relative );
		if ( null !== $refusal ) {
			return $this->fail( $refusal->reason() );
		}

		if ( ! is_dir( $target->absolute ) ) {
			if ( ! @unlink( $target->absolute ) ) {
				return $this->fail( 'Could not delete ' . $target->relative . '.' );
			}
			return $this->ok(
				array(
					'path'    => $target->relative,
					'deleted' => true,
					'type'    => 'file',
				)
			);
		}

		if ( empty( $args['recursive'] ) ) {
			return $this->fail( $target->relative . ' is a directory; set recursive to true to delete it and its contents.' );
		}
		$refusal = $this->safeMode->checkDirectoryDelete();
		if ( null !== $refusal ) {
			return $this->fail( $refusal->reason() );
		}

		try {
			foreach ( $this->walk( $target->absolute, \RecursiveIteratorIterator::CHILD_FIRST ) as $item ) {
				if ( ! $this->remove( $item ) ) {
					return $this->fail( 'Could not delete ' . $this->relativePath( $item, $target->absolute, $target->relative ) . '.' );
				}
			}
		} catch ( \UnexpectedValueException $e ) {
			return $this->fail( 'Could not read ' . $target->relative . '.' );
		}
		if ( ! @rmdir( $target->absolute ) ) {
			return $this->fail( 'Could not delete ' . $target->relative . '.' );
		}

		return $this->ok(
			array(
				'path'    => $target->relative,
				'deleted' => true,
				'type'    => 'dir',
			)
		);
	}

	/**
	 * Removes one item; a symlink is removed itself, never its target.
	 *
	 * @param \SplFileInfo $item Item.
	 */
	private function remove( \SplFileInfo $item ): bool {
		$path = $item->getPathname();
		if ( ! $item->isLink() && $item->isDir() ) {
			return @rmdir( $path );
		}
		// Directory symlinks and junctions (which PHP may report as plain files) need rmdir on Windows.
		return @unlink( $path ) || @rmdir( $path );
	}
}
