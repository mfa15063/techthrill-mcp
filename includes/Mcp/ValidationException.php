<?php
/**
 * Tool argument validation failure.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when tools/call arguments do not satisfy a tool's input schema.
 */
class ValidationException extends \InvalidArgumentException {
}
