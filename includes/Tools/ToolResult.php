<?php
/**
 * Tool result value object.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Outcome of a tool execution.
 */
final class ToolResult {

	/**
	 * Result text.
	 *
	 * @var string
	 */
	private string $text;

	/**
	 * Whether the result is an error.
	 *
	 * @var bool
	 */
	private bool $is_error;

	private function __construct( string $text, bool $is_error ) {
		$this->text     = $text;
		$this->is_error = $is_error;
	}

	/**
	 * Successful result.
	 *
	 * @param string $text Output text.
	 */
	public static function ok( string $text ): self {
		return new self( $text, false );
	}

	/**
	 * Error result.
	 *
	 * @param string $text Error message.
	 */
	public static function error( string $text ): self {
		return new self( $text, true );
	}

	/**
	 * Whether this is an error result.
	 */
	public function isError(): bool {
		return $this->is_error;
	}

	/**
	 * Result text.
	 */
	public function text(): string {
		return $this->text;
	}

	/**
	 * MCP tools/call result shape.
	 */
	public function toMcp(): array {
		return array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => $this->text,
				),
			),
			'isError' => $this->is_error,
		);
	}
}
