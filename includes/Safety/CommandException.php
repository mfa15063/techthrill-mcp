<?php
/**
 * WP-CLI command parsing failure.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Safety;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when a command string cannot be tokenized safely.
 */
class CommandException extends \RuntimeException {
}
