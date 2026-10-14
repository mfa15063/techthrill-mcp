<?php
/**
 * Outcome of handling one JSON-RPC message.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * HTTP status and JSON-RPC body (null when there is nothing to send back).
 */
final class HandleResult {

	/**
	 * HTTP status code.
	 *
	 * @var int
	 */
	public int $status;

	/**
	 * JSON-RPC response body, or null for notifications.
	 *
	 * @var array|null
	 */
	public ?array $body;

	/**
	 * @param int        $status HTTP status code.
	 * @param array|null $body   Response body.
	 */
	public function __construct( int $status, ?array $body ) {
		$this->status = $status;
		$this->body   = $body;
	}
}
