<?php
/**
 * Policy refusal value object.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Safety;

defined( 'ABSPATH' ) || exit;

/**
 * A reason an action was refused. The text is sent to the AI agent verbatim.
 */
final class Refusal {

	/**
	 * Human-readable (agent-readable) refusal text.
	 *
	 * @var string
	 */
	private string $reason;

	/**
	 * @param string $reason Refusal text.
	 */
	public function __construct( string $reason ) {
		$this->reason = $reason;
	}

	/**
	 * Refusal text.
	 */
	public function reason(): string {
		return $this->reason;
	}
}
