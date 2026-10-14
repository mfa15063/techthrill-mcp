<?php
/**
 * Path jail violation.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Safety;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when a path is malformed, missing, or would escape the jail root.
 */
class PathException extends \RuntimeException {
}
