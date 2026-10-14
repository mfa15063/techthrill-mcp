<?php
/**
 * SQL classification failure.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Safety;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when SQL is empty or contains more than one statement.
 */
class SqlException extends \RuntimeException {
}
