<?php
/**
 * A path that has passed the path jail.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Safety;

defined( 'ABSPATH' ) || exit;

/**
 * Value object: a verified absolute path and its root-relative form.
 */
final class JailedPath {
	/**
	 * Absolute filesystem path using the platform's native separators.
	 *
	 * @var string
	 */
	public $absolute;

	/**
	 * Path relative to the jail root: forward slashes, no leading slash, '.' for the root.
	 *
	 * @var string
	 */
	public $relative;

	/**
	 * @param string $absolute Absolute path.
	 * @param string $relative Root-relative path.
	 */
	public function __construct( string $absolute, string $relative ) {
		$this->absolute = $absolute;
		$this->relative = $relative;
	}
}
