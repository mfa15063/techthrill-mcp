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

	/**
	 * SAPIs whose PHP_BINARY is a command-line PHP that can run a phar.
	 */
	const CLI_SAPIS = array( 'cli', 'cli-server', 'phpdbg' );

	/**
	 * CLI PHP names looked up on PATH on POSIX hosts, in order.
	 */
	const POSIX_PHP_NAMES = array( 'php', 'php8.3', 'php83', 'php8.2', 'php82', 'php8.1', 'php81', 'php8.0', 'php80', 'php7.4', 'php74' );

	/**
	 * CLI PHP names looked up on PATH on Windows.
	 */
	const WINDOWS_PHP_NAMES = array( 'php.exe' );

	private Settings $settings;

	private string $uploads_dir;

	private ?string $path_env;

	private ?string $disable_functions;

	private ?string $site_host;

	private ?bool $is_ssl;

	private ?string $sapi;

	private ?string $php_bindir;

	/**
	 * The optional arguments exist for tests; when null they are read lazily from the environment.
	 *
	 * @param Settings    $settings          Plugin settings.
	 * @param string      $uploads_dir       Base uploads directory.
	 * @param string|null $path_env          PATH environment value.
	 * @param string|null $disable_functions PHP disable_functions ini value.
	 * @param string|null $site_host         Host of the site's home URL.
	 * @param bool|null   $is_ssl            Whether the current request is HTTPS.
	 * @param string|null $sapi              Server API name (default: PHP_SAPI).
	 * @param string|null $php_bindir        PHP binary directory (default: PHP_BINDIR).
	 */
	public function __construct( Settings $settings, string $uploads_dir, ?string $path_env = null, ?string $disable_functions = null, ?string $site_host = null, ?bool $is_ssl = null, ?string $sapi = null, ?string $php_bindir = null ) {
		$this->settings          = $settings;
		$this->uploads_dir       = $uploads_dir;
		$this->path_env          = $path_env;
		$this->disable_functions = $disable_functions;
		$this->site_host         = $site_host;
		$this->is_ssl            = $is_ssl;
		$this->sapi              = $sapi;
		$this->php_bindir        = $php_bindir;
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
	 * A phar is only used when a command-line PHP is available to run it.
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
			return $this->pharCommand( $this->pharPath() );
		}

		return null;
	}

	/**
	 * Command-line PHP binary used to run wp-cli.phar, or null when none is found.
	 */
	public function phpBinary(): ?string {
		return $this->phpCli();
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
			'php_binary'     => $this->phpBinary(),
			'https'          => $this->isHttps(),
			'local_host'     => $this->isLocalHost(),
		);
	}

	/**
	 * Argument-vector prefix that runs a phar through the command-line PHP, or null without one.
	 *
	 * @param string $phar Phar path.
	 * @return string[]|null
	 */
	private function pharCommand( string $phar ): ?array {
		$php = $this->phpCli();

		return null === $php ? null : array( $php, $phar );
	}

	/**
	 * Finds a command-line PHP binary.
	 *
	 * Order: the php_path setting; PHP_BINARY when the current SAPI is a CLI one (under php-fpm,
	 * mod_php or CGI it is not a CLI binary); a php binary on PATH; the php next to PHP_BINDIR.
	 * On Windows only an .exe is accepted for php_path, so a batch file is never launched.
	 */
	private function phpCli(): ?string {
		$windows = '\\' === DIRECTORY_SEPARATOR;

		$configured = (string) $this->settings->get( 'php_path' );
		if ( '' !== $configured && is_file( $configured ) && $this->launchable( $configured ) ) {
			return $configured;
		}

		$sapi = null !== $this->sapi ? $this->sapi : PHP_SAPI;
		if ( in_array( $sapi, self::CLI_SAPIS, true ) && '' !== PHP_BINARY && is_file( PHP_BINARY ) ) {
			return PHP_BINARY;
		}

		$found = $this->findInPath( $windows ? self::WINDOWS_PHP_NAMES : self::POSIX_PHP_NAMES );
		if ( null !== $found ) {
			return $found;
		}

		$bindir = null !== $this->php_bindir ? $this->php_bindir : PHP_BINDIR;
		if ( '' !== $bindir ) {
			$candidate = rtrim( $bindir, '/\\' ) . ( $windows ? '/php.exe' : '/php' );
			if ( is_file( $candidate ) && ( $windows || is_executable( $candidate ) ) ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Whether a configured binary may be launched directly: an .exe on Windows, executable on POSIX.
	 *
	 * @param string $file Existing file path.
	 */
	private function launchable( string $file ): bool {
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			return 'exe' === strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		}

		return is_executable( $file );
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
			return $this->pharCommand( $configured );
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
	 * The PATH directories, trimmed, without empty entries.
	 *
	 * @return string[]
	 */
	private function pathDirs(): array {
		$path = null !== $this->path_env ? $this->path_env : (string) getenv( 'PATH' );
		if ( '' === $path ) {
			return array();
		}

		$dirs = array();
		foreach ( explode( PATH_SEPARATOR, $path ) as $dir ) {
			$dir = trim( $dir, " \t\"" );
			if ( '' !== $dir ) {
				$dirs[] = $dir;
			}
		}

		return $dirs;
	}

	/**
	 * First file with one of the names in a PATH directory (executable on POSIX), or null.
	 *
	 * @param string[] $names    File names, in order of preference within a directory.
	 * @param string   $only_dir Restrict the scan to this directory (empty: every PATH directory).
	 */
	private function findInPath( array $names, string $only_dir = '' ): ?string {
		$windows = '\\' === DIRECTORY_SEPARATOR;
		$dirs    = '' !== $only_dir ? array( $only_dir ) : $this->pathDirs();

		foreach ( $dirs as $dir ) {
			foreach ( $names as $name ) {
				$candidate = rtrim( $dir, '/\\' ) . '/' . $name;
				if ( is_file( $candidate ) && ( $windows || is_executable( $candidate ) ) ) {
					return $candidate;
				}
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
		$windows = '\\' === DIRECTORY_SEPARATOR;
		$names   = $windows ? self::WINDOWS_NAMES : array( 'wp' );

		foreach ( $this->pathDirs() as $dir ) {
			$found = $this->findInPath( $names, $dir );
			if ( null !== $found ) {
				return array( $found );
			}

			if ( $windows ) {
				$phar = $this->siblingPhar( $dir );
				if ( null !== $phar ) {
					$command = $this->pharCommand( $phar );
					if ( null !== $command ) {
						return $command;
					}
				}
			}
		}

		return null;
	}
}
