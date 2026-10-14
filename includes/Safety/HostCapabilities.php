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
final class HostCapabilities {

	/**
	 * Names tried for the WP-CLI executable on Windows.
	 */
	const WINDOWS_NAMES = array( 'wp', 'wp.bat', 'wp.cmd' );

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
	 * Whether PHP can spawn a process (proc_open exists and is not disabled).
	 */
	public function execAvailable(): bool {
		if ( ! function_exists( 'proc_open' ) ) {
			return false;
		}

		$disabled = null !== $this->disable_functions ? $this->disable_functions : (string) ini_get( 'disable_functions' );
		foreach ( explode( ',', $disabled ) as $function ) {
			if ( 'proc_open' === strtolower( trim( $function ) ) ) {
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
		$configured = (string) $this->settings->get( 'cli_path' );
		if ( '' !== $configured && is_file( $configured ) ) {
			return array( $configured );
		}

		$found = $this->findOnPath();
		if ( null !== $found ) {
			return array( $found );
		}

		if ( is_file( $this->pharPath() ) ) {
			return array( PHP_BINARY, $this->pharPath() );
		}

		return null;
	}

	/**
	 * Location of the downloaded WP-CLI phar.
	 */
	public function pharPath(): string {
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
	 * Scans the PATH directories for a WP-CLI executable without running anything.
	 */
	private function findOnPath(): ?string {
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
					return $candidate;
				}
			}
		}

		return null;
	}
}
