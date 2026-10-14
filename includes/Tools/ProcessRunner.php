<?php
/**
 * Process runner contract.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Runs one child process from an argument vector, never through a shell.
 */
interface ProcessRunner {

	/**
	 * Runs a process and waits for it to finish or time out.
	 *
	 * @param string[]              $argv           Program followed by its arguments.
	 * @param string                $cwd            Working directory.
	 * @param array<string, string> $env            Complete environment for the child.
	 * @param int                   $timeoutSeconds Seconds before the process is killed.
	 */
	public function run( array $argv, string $cwd, array $env, int $timeoutSeconds ): ProcessResult;
}
