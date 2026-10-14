<?php
/**
 * proc_open based process runner.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Spawns a process with proc_open and an argv array (no shell), capturing capped output.
 *
 * On POSIX the output is read from non-blocking pipes. PHP cannot make pipes
 * non-blocking on Windows (a read would block until the child writes or exits,
 * defeating the timeout), so there the child writes to temporary files instead.
 *
 * Process-tree kill: on POSIX, when a setsid binary is available the child is
 * launched through it so it leads its own process group, and a timeout also
 * sends SIGKILL to that whole group (via posix_kill, when the extension is
 * loaded). Without setsid, only the direct child is terminated, so grandchildren
 * (for example a mysqldump started by a shell-wrapper `wp`) may outlive the call.
 * setsid forks instead of exec'ing when its caller is already a group leader;
 * PHP workers normally are not, but in that case the exit code would be lost.
 *
 * Batch files are refused on Windows: CreateProcess runs them through cmd.exe,
 * which interprets metacharacters in the arguments (CVE-2024-1874).
 */
final class ProcOpenRunner implements ProcessRunner {

	/**
	 * Most bytes kept per output stream.
	 */
	const OUTPUT_CAP = 204800;

	/**
	 * Pause between polls when nothing was read, in microseconds.
	 */
	const POLL_INTERVAL_US = 50000;

	/**
	 * Most bytes read from one stream per drain() call, so the deadline is checked often.
	 */
	const READ_LIMIT_PER_DRAIN = 65536;

	/**
	 * Locations searched for setsid when none is injected.
	 */
	const SETSID_CANDIDATES = array( '/usr/bin/setsid', '/bin/setsid' );

	/**
	 * Path of an executable setsid, or null when unavailable.
	 *
	 * @var string|null
	 */
	private ?string $setsid_path;

	/**
	 * @param string|null $setsid_path Path of setsid; null auto-detects it on POSIX hosts.
	 */
	public function __construct( ?string $setsid_path = null ) {
		if ( null === $setsid_path && '\\' !== DIRECTORY_SEPARATOR ) {
			foreach ( self::SETSID_CANDIDATES as $candidate ) {
				if ( is_file( $candidate ) && is_executable( $candidate ) ) {
					$setsid_path = $candidate;
					break;
				}
			}
		}
		$this->setsid_path = $setsid_path;
	}

	/**
	 * Prefixes an argv with setsid so the child leads its own process group.
	 *
	 * @param string[]    $argv        Program and arguments.
	 * @param string|null $setsid_path Path of setsid, or null for no prefix.
	 * @return string[]
	 */
	public static function withSetsid( array $argv, ?string $setsid_path ): array {
		if ( null === $setsid_path || '' === $setsid_path ) {
			return $argv;
		}

		return array_merge( array( $setsid_path ), $argv );
	}

	public function run( array $argv, string $cwd, array $env, int $timeoutSeconds ): ProcessResult {
		$started = microtime( true );
		$windows = '\\' === DIRECTORY_SEPARATOR;
		$pipes   = array();
		$files   = array();

		if ( $windows && isset( $argv[0] ) && 1 === preg_match( '/\.(bat|cmd)$/i', (string) $argv[0] ) ) {
			return new ProcessResult( -1, '', 'Refusing to launch a batch file.', 0, false );
		}

		$grouped = ! $windows && null !== $this->setsid_path;
		if ( $grouped ) {
			$argv = self::withSetsid( $argv, $this->setsid_path );
		}

		if ( $windows ) {
			$files = array( tempnam( sys_get_temp_dir(), 'ttm' ), tempnam( sys_get_temp_dir(), 'ttm' ) );
			if ( false === $files[0] || false === $files[1] ) {
				$this->removeFiles( $files );
				return new ProcessResult( -1, '', 'Failed to start process.', $this->elapsedMs( $started ), false );
			}
			$descriptors = array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'file', $files[0], 'w' ),
				2 => array( 'file', $files[1], 'w' ),
			);
		} else {
			$descriptors = array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			);
		}

		$proc = proc_open( $argv, $descriptors, $pipes, $cwd, $env, $windows ? array( 'bypass_shell' => true ) : array() );
		if ( ! is_resource( $proc ) ) {
			$this->removeFiles( $files );
			return new ProcessResult( -1, '', 'Failed to start process.', $this->elapsedMs( $started ), false );
		}

		fclose( $pipes[0] );
		if ( ! $windows ) {
			stream_set_blocking( $pipes[1], false );
			stream_set_blocking( $pipes[2], false );
		}

		$out       = '';
		$err       = '';
		$out_trunc = false;
		$err_trunc = false;
		$timed_out = false;
		$exit_code = -1;
		$deadline  = $started + $timeoutSeconds;

		while ( true ) {
			$read = 0;
			if ( ! $windows ) {
				$read = $this->drain( $pipes[1], $out, $out_trunc ) + $this->drain( $pipes[2], $err, $err_trunc );
			}

			$status = proc_get_status( $proc );
			if ( ! $status['running'] ) {
				// proc_get_status reports the exit code only once, so keep it.
				$exit_code = (int) $status['exitcode'];
				break;
			}

			if ( microtime( true ) >= $deadline ) {
				$timed_out = true;
				if ( $windows ) {
					proc_terminate( $proc );
				} else {
					proc_terminate( $proc, 9 );
					if ( $grouped && function_exists( 'posix_kill' ) && $status['pid'] > 0 ) {
						posix_kill( -$status['pid'], 9 );
					}
				}
				break;
			}

			if ( 0 === $read ) {
				usleep( self::POLL_INTERVAL_US );
			}
		}

		if ( $windows ) {
			// Let the terminated process release its output files before reading them.
			$closed = proc_close( $proc );
			list( $out, $out_trunc ) = $this->readCapped( $files[0] );
			list( $err, $err_trunc ) = $this->readCapped( $files[1] );
			$this->removeFiles( $files );
		} else {
			// Collect what the process wrote. After a timeout one bounded read is enough, since
			// a surviving pipe holder could otherwise keep this loop going indefinitely.
			if ( $timed_out ) {
				$this->drain( $pipes[1], $out, $out_trunc );
				$this->drain( $pipes[2], $err, $err_trunc );
			} else {
				do {
					$read = $this->drain( $pipes[1], $out, $out_trunc ) + $this->drain( $pipes[2], $err, $err_trunc );
				} while ( $read > 0 && microtime( true ) < $deadline );
			}
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			$closed = proc_close( $proc );
		}

		if ( $timed_out ) {
			$exit_code = -1;
		} elseif ( -1 === $exit_code ) {
			$exit_code = $closed;
		}

		return new ProcessResult( $exit_code, $out, $err, $this->elapsedMs( $started ), $timed_out, $out_trunc, $err_trunc );
	}

	/**
	 * Reads what is currently available from a pipe (at most READ_LIMIT_PER_DRAIN bytes per call),
	 * keeping at most OUTPUT_CAP bytes in total.
	 *
	 * @param resource $pipe      Non-blocking read pipe.
	 * @param string   $buffer    Collected output so far.
	 * @param bool     $truncated Set to true when bytes were discarded.
	 * @return int Bytes read from the pipe (including discarded ones).
	 */
	private function drain( $pipe, string &$buffer, bool &$truncated ): int {
		$total = 0;
		while ( $total < self::READ_LIMIT_PER_DRAIN ) {
			$chunk = fread( $pipe, 8192 );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}

			$total += strlen( $chunk );
			$room   = self::OUTPUT_CAP - strlen( $buffer );
			if ( $room >= strlen( $chunk ) ) {
				$buffer .= $chunk;
			} else {
				if ( $room > 0 ) {
					$buffer .= substr( $chunk, 0, $room );
				}
				$truncated = true;
			}
		}

		return $total;
	}

	/**
	 * Reads the first OUTPUT_CAP bytes of a file and whether more followed.
	 *
	 * @param string $path File path.
	 * @return array{0: string, 1: bool}
	 */
	private function readCapped( string $path ): array {
		$data = file_get_contents( $path, false, null, 0, self::OUTPUT_CAP + 1 );
		if ( false === $data ) {
			return array( '', false );
		}
		if ( strlen( $data ) > self::OUTPUT_CAP ) {
			return array( substr( $data, 0, self::OUTPUT_CAP ), true );
		}

		return array( $data, false );
	}

	/**
	 * Deletes temporary output files.
	 *
	 * @param array $files Paths (false entries are ignored).
	 */
	private function removeFiles( array $files ): void {
		foreach ( $files as $file ) {
			if ( is_string( $file ) ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
	}

	private function elapsedMs( float $started ): int {
		return (int) round( ( microtime( true ) - $started ) * 1000 );
	}
}
