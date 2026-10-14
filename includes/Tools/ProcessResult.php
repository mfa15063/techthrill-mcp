<?php
/**
 * Outcome of a child process.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Value object returned by a ProcessRunner.
 */
final class ProcessResult {

	public int $exitCode;

	public string $stdout;

	public string $stderr;

	public int $durationMs;

	public bool $timedOut;

	public bool $stdoutTruncated;

	public bool $stderrTruncated;

	/**
	 * @param int    $exitCode        Exit code, or -1 when the process did not finish normally.
	 * @param string $stdout          Captured standard output.
	 * @param string $stderr          Captured standard error.
	 * @param int    $durationMs      Wall-clock run time in milliseconds.
	 * @param bool   $timedOut        Whether the process was killed for exceeding the timeout.
	 * @param bool   $stdoutTruncated Whether standard output was cut at the size cap.
	 * @param bool   $stderrTruncated Whether standard error was cut at the size cap.
	 */
	public function __construct( int $exitCode, string $stdout, string $stderr, int $durationMs, bool $timedOut, bool $stdoutTruncated = false, bool $stderrTruncated = false ) {
		$this->exitCode        = $exitCode;
		$this->stdout          = $stdout;
		$this->stderr          = $stderr;
		$this->durationMs      = $durationMs;
		$this->timedOut        = $timedOut;
		$this->stdoutTruncated = $stdoutTruncated;
		$this->stderrTruncated = $stderrTruncated;
	}
}
