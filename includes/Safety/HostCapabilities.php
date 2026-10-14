<?php
/**
 * Detects what the host running WordPress can do.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Safety;

use TechThrill\MCP\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Reports whether WP-CLI can run here and whether the site is served over HTTPS.
 *
 * Never shells out: binaries are found by scanning the PATH directories.
 */
class HostCapabilities {

	/**
	 * Directly launchable WP-CLI names on Windows (a bare `wp` script cannot be run there).
	 */
	const WINDOWS_NAMES = array( 'wp.exe' );

	/**
	 * Batch wrappers on Windows. They are never launched: CreateProcess runs them through cmd.exe,
	 * which interprets metacharacters in arguments (CVE-2024-1874). Only a sibling phar is used.
	 */
	const WINDOWS_BATCH_NAMES = array( 'wp.bat', 'wp.cmd' );

	/**
	 * Functions the process runner needs.
	 */
	const PROCESS_FUNCTIONS = array( 'proc_open', 'proc_get_status', 'proc_terminate', 'proc_close' );

	private Settings $settings;

	private string $uploads_dir;

	private ?string $path_env;

	private ?string $disable_functions;

	private ?string $site_host;

	private ?bool $is_ssl;

	/**
	 * The optional arguments exist for tests; when null they are read lazily from the environment.
	 *
	 * @param Settings    $settings          Plugin settings.
	 * @param string      $uploads_dir       Base uploads directory.
	 * @param string|null $path_env          PATH environment value.
	 * @param string|null $disable_functions PHP disable_functions ini value.
	 * @param string|null $site_host         Host of the site's home URL.
	 * @param bool|null   $is_ssl            Whether the current request is HTTPS.
	 */
	public function __construct( Settings $settings, string $uploads_dir, ?string $path_env = null, ?string $disable_functions = null, ?string $site_host = null, ?bool $is_ssl = null ) {
		$this->settings          = $settings;
		$this->uploads_dir       = $uploads_dir;
		$this->path_env          = $path_env;
		$this->disable_functions = $disable_functions;
		$this->site_host         = $site_host;
		$this->is_ssl            = $is_ssl;
	}

	/**
	 * Whether PHP can spawn and supervise a process (every proc_* function the runner uses exists and is enabled).
	 */
	public function execAvailable(): bool {
		$disabled = null !== $this->disable_functions ? $this->disable_functions : (string) ini_get( 'disable_functions' );
		$disabled = array_map( 'trim', explode( ',', strtolower( $disabled ) ) );

		foreach ( self::PROCESS_FUNCTIONS as $function ) {
			if ( ! function_exists( $function ) || in_array( $function, $disabled, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Argument-vector prefix that launches WP-CLI, or null when none is available.
	 *
	 * Order: the cli_path setting, a `wp` executable on PATH, the downloaded phar.
	 *
	 * @return string[]|null
	 */
	public function wpBinary(): ?array {
		$configured = $this->configuredBinary();
		if ( null !== $configured ) {
			return $configured;
		}

		$found = $this->findOnPath();
		if ( null !== $found ) {
			return $found;
		}

		if ( '' !== $this->pharPath() && is_file( $this->pharPath() ) ) {
			return array( PHP_BINARY, $this->pharPath() );
		}

		return null;
	}

	/**
	 * Location of the downloaded WP-CLI phar.
	 */
	public function pharPath(): string {
		if ( '' === $this->uploads_dir ) {
			return '';
		}

		return rtrim( $this->uploads_dir, '/\\' ) . '/techthrill-mcp/wp-cli.phar';
	}

	/**
	 * Whether the site is served over HTTPS.
	 */
	public function isHttps(): bool {
		return null !== $this->is_ssl ? $this->is_ssl : (bool) is_ssl();
	}

	/**
	 * Whether the site host looks like a local development host.
	 */
	public function isLocalHost(): bool {
		if ( null !== $this->site_host ) {
			$host = $this->site_host;
		} else {
			$parsed = wp_parse_url( home_url(), PHP_URL_HOST );
			$host   = is_string( $parsed ) ? $parsed : '';
		}

		$host = strtolower( trim( $host, '[]' ) );
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}

		foreach ( array( '.local', '.test', '.localhost' ) as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Summary for site_info and the admin screen.
	 */
	public function toArray(): array {
		$binary = $this->wpBinary();

		return array(
			'exec_available' => $this->execAvailable(),
			'wp_binary'      => null === $binary ? null : implode( ' ', $binary ),
			'https'          => $this->isHttps(),
			'local_host'     => $this->isLocalHost(),
		);
	}

	/**
	 * Argument-vector prefix for the cli_path setting, or null when it is unset or unusable.
	 *
	 * On Windows only .exe and .phar files are accepted (never batch files).
	 *
	 * @return string[]|null
	 */
	private function configuredBinary(): ?array {
		$configured = (string) $this->settings->get( 'cli_path' );
		if ( '' === $configured || ! is_file( $configured ) ) {
			return null;
		}

		if ( '\\' !== DIRECTORY_SEPARATOR ) {
			return is_executable( $configured ) ? array( $configured ) : null;
		}

		$extension = strtolower( pathinfo( $configured, PATHINFO_EXTENSION ) );
		if ( 'exe' === $extension ) {
			return array( $configured );
		}
		if ( 'phar' === $extension ) {
			return array( PHP_BINARY, $configured );
		}

		return null;
	}

	/**
	 * The wp-cli.phar next to a wp.bat/wp.cmd wrapper in a directory, or null.
	 *
	 * @param string $dir PATH directory.
	 */
	private function siblingPhar( string $dir ): ?string {
		$base = rtrim( $dir, '/\\' ) . '/';
		foreach ( self::WINDOWS_BATCH_NAMES as $name ) {
			if ( is_file( $base . $name ) && is_file( $base . 'wp-cli.phar' ) ) {
				return $base . 'wp-cli.phar';
			}
		}

		return null;
	}

	/**
	 * Scans the PATH directories for a WP-CLI executable without running anything.
	 *
	 * @return string[]|null
	 */
	private function findOnPath(): ?array {
		$path = null !== $this->path_env ? $this->path_env : (string) getenv( 'PATH' );
		if ( '' === $path ) {
			return null;
		}

		$windows = '\\' === DIRECTORY_SEPARATOR;
		$names   = $windows ? self::WINDOWS_NAMES : array( 'wp' );

		foreach ( explode( PATH_SEPARATOR, $path ) as $dir ) {
			$dir = trim( $dir, " \t\"" );
			if ( '' === $dir ) {
				continue;
			}

			foreach ( $names as $name ) {
				$candidate = rtrim( $dir, '/\\' ) . '/' . $name;
				if ( is_file( $candidate ) && ( $windows || is_executable( $candidate ) ) ) {
					return array( $candidate );
				}
			}

			if ( $windows ) {
				$phar = $this->siblingPhar( $dir );
				if ( null !== $phar ) {
					return array( PHP_BINARY, $phar );
				}
			}
		}

		return null;
	}
}
