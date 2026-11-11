<?php
/**
 * A tool refusal with a message for the agent.
 *
 * @package TechThrill\MCP\Files
 */

namespace TechThrill\MCP\Files\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown inside a tool and turned into an error result. The message is shown to the agent verbatim.
 */
final class ToolFailure extends \RuntimeException {
}
