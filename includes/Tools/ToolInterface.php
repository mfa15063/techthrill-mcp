<?php
/**
 * Tool contract.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * An MCP tool exposed by the server.
 */
interface ToolInterface {

	/**
	 * Unique tool name.
	 */
	public function name(): string;

	/**
	 * Human-readable description shown to the model.
	 */
	public function description(): string;

	/**
	 * JSON schema describing the tool's arguments.
	 */
	public function inputSchema(): array;

	/**
	 * Scope a token needs to use this tool, or null when none is required.
	 */
	public function scope(): ?string;

	/**
	 * Runs the tool with already validated arguments.
	 *
	 * @param array       $args Validated arguments.
	 * @param ToolContext $ctx  Calling token context.
	 */
	public function execute( array $args, ToolContext $ctx ): ToolResult;
}
