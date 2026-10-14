<?php
/**
 * The file_delete tool.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Tools;

use TechThrill\MCP\Safety\PathException;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- This tool operates directly on site files that PathJail has already confined; WP_Filesystem needs credentials and an admin request context, and every silenced call is checked through its return value.
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
			$normalized = $this->jail->normalize( (string) ( $args['path'] ?? '' ) );
			if ( '.' === $normalized ) {
				return $this->fail( 'The WordPress root cannot be deleted.' );
			}

			// Resolve the parent only, so a symlink leaf is deleted itself rather than its target.
			$name   = basename( $normalized );
			$parent = $this->jail->resolve( dirname( $normalized ) );
			$leaf   = rtrim( $parent->absolute, '/\\' ) . DIRECTORY_SEPARATOR . $name;
			if ( is_link( $leaf ) ) {
				$link = ( '.' === $parent->relative ? '' : $parent->relative . '/' ) . $name;
				return $this->deleteLink( $leaf, $link );
			}

			$target = $this->jail->resolve( $normalized );
		} catch ( PathException $e ) {
			return $this->fail( $e->getMessage() );
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
	 * Deletes a symlink itself, never what it points to.
	 *
	 * @param string $absolute Absolute path of the link.
	 * @param string $relative Root-relative path of the link.
	 */
	private function deleteLink( string $absolute, string $relative ): ToolResult {
		$refusal = $this->safeMode->checkPathWrite( $relative );
		if ( null !== $refusal ) {
			return $this->fail( $refusal->reason() );
		}
		// Directory symlinks need rmdir on Windows.
		if ( ! @unlink( $absolute ) && ! @rmdir( $absolute ) ) {
			return $this->fail( 'Could not delete ' . $relative . '.' );
		}
		return $this->ok(
			array(
				'path'    => $relative,
				'deleted' => true,
				'type'    => 'link',
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

// phpcs:enable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged
