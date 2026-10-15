<?php
/**
 * The file_copy tool.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Tools;

use TechThrill\MCP\Safety\JailedPath;
use TechThrill\MCP\Safety\PathException;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- This tool operates directly on site files that PathJail has already confined; WP_Filesystem needs credentials and an admin request context, and every silenced call is checked through its return value.
defined( 'ABSPATH' ) || exit;

/**
 * Copies a file, or a directory tree, byte for byte.
 */
final class FileCopy extends AbstractFileTool {

	/**
	 * Largest copyable file, in bytes.
	 */
	const MAX_FILE_BYTES = 52428800;

	public function name(): string {
		return 'file_copy';
	}

	public function description(): string {
		return 'Copy a file or directory inside the WordPress root, byte for byte, so binary files such as images work. Copies are atomic, the source is never modified, and each file is limited to 50 MB. A directory needs recursive=true; symlinks inside it are skipped. Safe mode protects wp-config.php, .htaccess and the plugin\'s own directory as destinations.';
	}

	public function inputSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'from'        => array(
					'type'        => 'string',
					'description' => 'Source file or directory, relative to the WordPress root.',
				),
				'to'          => array(
					'type'        => 'string',
					'description' => 'Destination path, relative to the WordPress root. For a directory this is the new directory itself, not its parent.',
				),
				'overwrite'   => array(
					'type'        => 'boolean',
					'description' => 'Replace an existing destination file, or merge into an existing destination directory (extra files are kept).',
					'default'     => false,
				),
				'create_dirs' => array(
					'type'        => 'boolean',
					'description' => 'Create missing parent directories of the destination.',
					'default'     => true,
				),
				'recursive'   => array(
					'type'        => 'boolean',
					'description' => 'Required to copy a directory and everything in it.',
					'default'     => false,
				),
			),
			'required'   => array( 'from', 'to' ),
		);
	}

	public function scope(): ?string {
		return 'files:write';
	}

	public function execute( array $args, ToolContext $ctx ): ToolResult {
		$overwrite   = ! empty( $args['overwrite'] );
		$create_dirs = ! isset( $args['create_dirs'] ) || (bool) $args['create_dirs'];
		$recursive   = ! empty( $args['recursive'] );

		$from = (string) ( $args['from'] ?? '' );
		$to   = (string) ( $args['to'] ?? '' );
		// PathJail reads a single leading slash as root-relative, so '/tmp/x' would silently land in <root>/tmp/x.
		// A copy would create the wrong file rather than fail, so a leading slash is refused outright here.
		if ( 1 === preg_match( '#^[\\\\/]#', $from ) || 1 === preg_match( '#^[\\\\/]#', $to ) ) {
			return $this->fail( 'Absolute paths are not allowed.' );
		}

		try {
			$source = $this->jail->resolve( $from );
			$dest   = $this->jail->resolveForCreate( $to );
		} catch ( PathException $e ) {
			return $this->fail( $e->getMessage() );
		}

		if ( $source->absolute === $dest->absolute ) {
			return $this->fail( 'The source and the destination are the same path.' );
		}

		if ( is_dir( $source->absolute ) ) {
			return $this->copyDirectory( $source, $dest, $overwrite, $create_dirs, $recursive );
		}
		if ( ! is_file( $source->absolute ) ) {
			return $this->fail( $source->relative . ' is not a file or directory.' );
		}
		if ( 'wp-config.php' === strtolower( $source->relative ) && ! $ctx->hasScope( 'db:read' ) ) {
			return $this->fail( 'Refused: copying wp-config.php requires the db:read scope because it contains database credentials.' );
		}

		$size = (int) filesize( $source->absolute );
		if ( $size > self::MAX_FILE_BYTES ) {
			return $this->fail( $source->relative . ' is ' . $size . ' bytes; file_copy is limited to 50 MB per file.' );
		}

		$refusal = $this->safeMode->checkPathWrite( $dest->relative );
		if ( null !== $refusal ) {
			return $this->fail( $refusal->reason() );
		}
		if ( is_dir( $dest->absolute ) ) {
			return $this->fail( $dest->relative . ' is a directory; give the full destination file path.' );
		}
		$existed = file_exists( $dest->absolute );
		if ( $existed && ! $overwrite ) {
			return $this->fail( $dest->relative . ' already exists; set overwrite to true to replace it.' );
		}

		$error = $this->ensureParent( $dest, $create_dirs );
		if ( null === $error ) {
			$error = $this->atomicCopy( $source->absolute, $dest->absolute );
		}
		if ( null !== $error ) {
			return $this->fail( $error );
		}

		return $this->ok(
			array(
				'from'        => $source->relative,
				'to'          => $dest->relative,
				'type'        => 'file',
				'files'       => 1,
				'bytes'       => $size,
				'overwritten' => $existed,
			)
		);
	}

	/**
	 * Copies a directory tree. Every destination is validated before anything is written.
	 *
	 * @param JailedPath $source      Resolved source directory.
	 * @param JailedPath $dest        Resolved destination.
	 * @param bool       $overwrite   Merge into an existing destination.
	 * @param bool       $create_dirs Create missing parents of the destination.
	 * @param bool       $recursive   Whether recursive was set.
	 */
	private function copyDirectory( JailedPath $source, JailedPath $dest, bool $overwrite, bool $create_dirs, bool $recursive ): ToolResult {
		if ( ! $recursive ) {
			return $this->fail( $source->relative . ' is a directory; set recursive to true to copy it and its contents.' );
		}
		if ( '.' === $dest->relative ) {
			return $this->fail( 'The root itself cannot be created.' );
		}

		$prefix = rtrim( $source->absolute, '/\\' ) . DIRECTORY_SEPARATOR;
		if ( 0 === strncmp( $dest->absolute, $prefix, strlen( $prefix ) ) ) {
			return $this->fail( 'A directory cannot be copied into itself.' );
		}
		if ( is_file( $dest->absolute ) ) {
			return $this->fail( $dest->relative . ' is a file; a directory cannot replace it.' );
		}
		$existed = is_dir( $dest->absolute );
		if ( $existed && ! $overwrite ) {
			return $this->fail( $dest->relative . ' already exists; set overwrite to true to merge into it and replace files with the same name.' );
		}
		if ( ! $existed && ! is_dir( dirname( $dest->absolute ) ) && ! $create_dirs ) {
			return $this->fail( 'The parent directory of ' . $dest->relative . ' does not exist; set create_dirs to true.' );
		}

		$plan = $this->plan( $source, $dest );
		if ( is_string( $plan ) ) {
			return $this->fail( $plan );
		}

		$error = $this->ensureParent( $dest, $create_dirs );
		if ( null !== $error ) {
			return $this->fail( $error );
		}

		$files = 0;
		$bytes = 0;
		foreach ( $plan['steps'] as $step ) {
			if ( 'dir' === $step['type'] ) {
				if ( ! is_dir( $step['dst'] ) && ! @mkdir( $step['dst'], 0755 ) && ! is_dir( $step['dst'] ) ) {
					return $this->fail( 'Could not create ' . $step['rel'] . '. Copied ' . $files . ' of ' . $plan['files'] . ' files before failing.' );
				}
				continue;
			}
			$error = $this->atomicCopy( $step['src'], $step['dst'] );
			if ( null !== $error ) {
				return $this->fail( $error . ' (' . $step['rel'] . '). Copied ' . $files . ' of ' . $plan['files'] . ' files before failing.' );
			}
			++$files;
			$bytes += $step['size'];
		}

		return $this->ok(
			array(
				'from'          => $source->relative,
				'to'            => $dest->relative,
				'type'          => 'dir',
				'files'         => $files,
				'bytes'         => $bytes,
				'overwritten'   => $existed,
				'skipped_links' => $plan['skipped_links'],
			)
		);
	}

	/**
	 * Lists every directory and file to create, refusing the whole copy when any destination is not allowed.
	 *
	 * @param JailedPath $source Resolved source directory.
	 * @param JailedPath $dest   Resolved destination directory (may not exist yet).
	 * @return array|string Plan with 'steps', 'files' and 'skipped_links', or an error message.
	 */
	private function plan( JailedPath $source, JailedPath $dest ) {
		$refusal = $this->safeMode->checkPathWrite( $dest->relative );
		if ( null !== $refusal ) {
			return $refusal->reason();
		}

		$steps = array(
			array(
				'type' => 'dir',
				'src'  => $source->absolute,
				'dst'  => $dest->absolute,
				'rel'  => $dest->relative,
				'size' => 0,
			),
		);
		$files = 0;
		$links = 0;

		try {
			foreach ( $this->walk( $source->absolute ) as $item ) {
				if ( $item->isLink() ) {
					++$links;
					continue;
				}
				$is_dir = $item->isDir();
				if ( ! $is_dir && ! $item->isFile() ) {
					continue;
				}

				$tail = $this->relativePath( $item, $source->absolute, '.' );
				try {
					$target = $this->jail->resolveForCreate( $dest->relative . '/' . $tail );
				} catch ( PathException $e ) {
					return $e->getMessage();
				}

				$refusal = $this->safeMode->checkPathWrite( $target->relative );
				if ( null !== $refusal ) {
					return $refusal->reason();
				}
				if ( $target->absolute === $item->getPathname() ) {
					return 'The source and the destination are the same path.';
				}

				$size = 0;
				if ( $is_dir ) {
					if ( is_file( $target->absolute ) ) {
						return $target->relative . ' is a file; a directory cannot replace it.';
					}
				} else {
					$size = (int) $item->getSize();
					if ( $size > self::MAX_FILE_BYTES ) {
						return $this->relativePath( $item, $source->absolute, $source->relative ) . ' is ' . $size . ' bytes; file_copy is limited to 50 MB per file.';
					}
					if ( is_dir( $target->absolute ) ) {
						return $target->relative . ' is a directory; a file cannot replace it.';
					}
					++$files;
				}

				$steps[] = array(
					'type' => $is_dir ? 'dir' : 'file',
					'src'  => $item->getPathname(),
					'dst'  => $target->absolute,
					'rel'  => $target->relative,
					'size' => $size,
				);
			}
		} catch ( \UnexpectedValueException $e ) {
			return 'Could not read ' . $source->relative . '.';
		}

		return array(
			'steps'         => $steps,
			'files'         => $files,
			'skipped_links' => $links,
		);
	}

	/**
	 * Makes sure the parent directory of the destination exists.
	 *
	 * @param JailedPath $dest        Resolved destination.
	 * @param bool       $create_dirs Whether missing parents may be created.
	 * @return string|null Error message, or null when the parent is ready.
	 */
	private function ensureParent( JailedPath $dest, bool $create_dirs ): ?string {
		$parent = dirname( $dest->absolute );
		if ( is_dir( $parent ) ) {
			return null;
		}
		if ( ! $create_dirs ) {
			return 'The parent directory of ' . $dest->relative . ' does not exist; set create_dirs to true.';
		}
		if ( ! @mkdir( $parent, 0755, true ) && ! is_dir( $parent ) ) {
			return 'Could not create the parent directory of ' . $dest->relative . '.';
		}
		return null;
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged
