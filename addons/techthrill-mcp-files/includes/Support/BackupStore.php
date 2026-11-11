<?php
/**
 * Backups of files the addon is about to overwrite, move over or change.
 *
 * @package TechThrill\MCP\Files
 */

namespace TechThrill\MCP\Files\Support;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- Plain file copies inside the addon's own backup folder; every silenced call is checked through its return value.
defined( 'ABSPATH' ) || exit;

/**
 * One folder per file path (named by a hash of the root-relative path), one .bak file per version.
 * The newest versions are kept; older ones are deleted.
 */
final class BackupStore {

	/**
	 * Files bigger than this (5 MB) are not backed up.
	 */
	const MAX_BYTES = 5242880;

	/**
	 * Versions kept per file.
	 */
	const KEEP = 20;

	private string $baseDir;

	/**
	 * Returns the current unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * @param string        $baseDir Absolute folder that holds the backups.
	 * @param callable|null $clock   Returns the current unix time.
	 */
	public function __construct( string $baseDir, ?callable $clock = null ) {
		$this->baseDir = rtrim( str_replace( '\\', '/', $baseDir ), '/' );
		$this->clock   = $clock ?? 'time';
	}

	/**
	 * The folder the backups live in.
	 */
	public function baseDir(): string {
		return $this->baseDir;
	}

	/**
	 * Copies a file into the store.
	 *
	 * @param string $relative Root-relative path of the file (identifies its backups).
	 * @param string $source   Absolute path of the existing file.
	 * @return string|null Backup id, or null when the file is missing, too big or the copy failed.
	 */
	public function save( string $relative, string $source ): ?string {
		if ( ! is_file( $source ) || (int) filesize( $source ) > self::MAX_BYTES ) {
			return null;
		}

		$dir = $this->dirFor( $relative );
		if ( ! $this->ensureDir( $dir ) ) {
			return null;
		}

		// The id sorts by time: seconds first, then the microsecond of the moment, so two saves in the same second
		// still come out in order. A taken id (two saves in the same microsecond) moves on by one.
		$now    = (int) call_user_func( $this->clock );
		$micros = (int) ( fmod( microtime( true ), 1 ) * 1000000 );
		do {
			$id = gmdate( 'Ymd-His', $now ) . '-' . sprintf( '%06x', $micros++ );
		} while ( is_file( $dir . '/' . $id . '.bak' ) );
		if ( ! @copy( $source, $dir . '/' . $id . '.bak' ) ) {
			return null;
		}

		$this->prune( $dir );

		return $id;
	}

	/**
	 * The backups of a file, newest first.
	 *
	 * @param string $relative Root-relative path.
	 * @return array<int,array{id:string,time:int,bytes:int}>
	 */
	public function listFor( string $relative ): array {
		$out = array();
		foreach ( $this->files( $this->dirFor( $relative ) ) as $id => $path ) {
			$out[] = array(
				'id'    => $id,
				'time'  => (int) strtotime( substr( $id, 0, 8 ) . 'T' . substr( $id, 9, 6 ) . 'Z' ),
				'bytes' => (int) filesize( $path ),
			);
		}

		return $out;
	}

	/**
	 * Absolute path of a stored backup, or null when the id is malformed or unknown.
	 *
	 * @param string      $relative Root-relative path.
	 * @param string|null $id       Backup id, or null for the newest.
	 */
	public function pathFor( string $relative, ?string $id = null ): ?string {
		$files = $this->files( $this->dirFor( $relative ) );
		if ( null === $id ) {
			return array() === $files ? null : (string) reset( $files );
		}

		return 1 === preg_match( '/^[0-9]{8}-[0-9]{6}-[0-9a-f]{6}$/D', $id ) && isset( $files[ $id ] ) ? $files[ $id ] : null;
	}

	/**
	 * Deletes every backup (used when the addon is uninstalled).
	 */
	public function deleteAll(): void {
		$this->removeTree( $this->baseDir );
	}

	private function dirFor( string $relative ): string {
		return $this->baseDir . '/' . sha1( strtolower( trim( str_replace( '\\', '/', $relative ), '/' ) ) );
	}

	/**
	 * Backup files of a folder by id, newest first.
	 *
	 * @param string $dir Backup folder.
	 * @return array<string,string>
	 */
	private function files( string $dir ): array {
		$found = array();
		foreach ( (array) glob( $dir . '/*.bak' ) as $path ) {
			$id = basename( (string) $path, '.bak' );
			if ( 1 === preg_match( '/^[0-9]{8}-[0-9]{6}-[0-9a-f]{6}$/D', $id ) ) {
				$found[ $id ] = (string) $path;
			}
		}
		krsort( $found );

		return $found;
	}

	private function prune( string $dir ): void {
		$files = $this->files( $dir );
		foreach ( array_slice( $files, self::KEEP, null, true ) as $path ) {
			@unlink( $path );
		}
	}

	/**
	 * Creates the folder with files that stop a web server from serving or listing it.
	 *
	 * @param string $dir Folder to create.
	 */
	private function ensureDir( string $dir ): bool {
		if ( ! is_dir( $this->baseDir ) && ! @mkdir( $this->baseDir, 0755, true ) && ! is_dir( $this->baseDir ) ) {
			return false;
		}
		if ( ! is_file( $this->baseDir . '/.htaccess' ) ) {
			@file_put_contents( $this->baseDir . '/.htaccess', "Require all denied\nDeny from all\n" );
		}
		if ( ! is_file( $this->baseDir . '/index.php' ) ) {
			@file_put_contents( $this->baseDir . '/index.php', "<?php\n// Silence is golden.\n" );
		}

		return is_dir( $dir ) || @mkdir( $dir, 0755, true ) || is_dir( $dir );
	}

	private function removeTree( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			@unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( (array) scandir( $path ) as $name ) {
			if ( '.' !== $name && '..' !== $name ) {
				$this->removeTree( $path . '/' . $name );
			}
		}
		@rmdir( $path );
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged
