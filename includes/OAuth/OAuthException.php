<?php
/**
 * OAuth protocol error.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * An error with an RFC 6749 error code, a description for the client and an HTTP status.
 */
class OAuthException extends \RuntimeException {

	/**
	 * RFC 6749 error code.
	 *
	 * @var string
	 */
	private string $error;

	/**
	 * Human-readable description.
	 *
	 * @var string
	 */
	private string $description;

	/**
	 * HTTP status.
	 *
	 * @var int
	 */
	private int $status;

	/**
	 * @param string $error       Error code such as invalid_request.
	 * @param string $description Description safe to show to the client.
	 * @param int    $status      HTTP status.
	 */
	public function __construct( string $error, string $description, int $status = 400 ) {
		parent::__construct( $error . ': ' . $description );
		$this->error       = $error;
		$this->description = $description;
		$this->status      = $status;
	}

	public function error(): string {
		return $this->error;
	}

	public function description(): string {
		return $this->description;
	}

	public function status(): int {
		return $this->status;
	}
}
