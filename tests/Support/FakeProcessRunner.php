<?php
namespace TechThrill\MCP\Tests\Support;

use TechThrill\MCP\Tools\ProcessResult;
use TechThrill\MCP\Tools\ProcessRunner;

/**
 * Records what it was asked to run and returns a preset result.
 */
final class FakeProcessRunner implements ProcessRunner {

	public ?array $argv = null;

	public ?string $cwd = null;

	public ?array $env = null;

	public ?int $timeout = null;

	public int $calls = 0;

	private ProcessResult $result;

	public function __construct( ?ProcessResult $result = null ) {
		$this->result = $result ?? new ProcessResult( 0, '', '', 0, false );
	}

	public function run( array $argv, string $cwd, array $env, int $timeoutSeconds ): ProcessResult {
		++$this->calls;
		$this->argv    = $argv;
		$this->cwd     = $cwd;
		$this->env     = $env;
		$this->timeout = $timeoutSeconds;

		return $this->result;
	}
}
