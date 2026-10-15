<?php
/**
 * Outcome of authenticating a request.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable result of {@see Authenticator::authenticate()}.
 */
final class AuthResult {

	/**
	 * @var bool
	 */
	private $ok;

	/**
	 * Token row without its hash and lookup.
	 *
	 * @var array
	 */
	private $row;

	/**
	 * @var string[]
	 */
	private $scopes;

	/**
	 * @var int
	 */
	private $status;

	/**
	 * @var int
	 */
	private $code;

	/**
	 * @var string
	 */
	private $message;

	/**
	 * @var int
	 */
	private $retry_after;

	/**
	 * Why authentication failed: missing, invalid or empty.
	 *
	 * @var string
	 */
	private $reason;

	private function __construct( bool $ok, array $row, array $scopes, int $status, int $code, string $message, int $retry_after, string $reason = '' ) {
		$this->reason      = $reason;
		$this->ok          = $ok;
		$this->row         = $row;
		$this->scopes      = $scopes;
		$this->status      = $status;
		$this->code        = $code;
		$this->message     = $message;
		$this->retry_after = $retry_after;
	}

	/**
	 * Successful authentication. The hash and lookup are dropped from the row.
	 *
	 * @param array    $row    Token row.
	 * @param string[] $scopes Granted scopes, implications expanded.
	 */
	public static function ok( array $row, array $scopes ): self {
		unset( $row['hash'], $row['lookup'] );

		return new self( true, $row, array_values( $scopes ), 200, 0, '', 0 );
	}

	/**
	 * Failed authentication.
	 *
	 * @param int    $status      HTTP status.
	 * @param int    $code        JSON-RPC error code.
	 * @param string $message     Message safe to show the client.
	 * @param int    $retry_after Seconds to wait before retrying; 0 when not applicable.
	 * @param string $reason      missing (no usable header), invalid (bad, expired or revoked token) or empty.
	 */
	public static function fail( int $status, int $code, string $message, int $retry_after = 0, string $reason = '' ): self {
		return new self( false, array(), array(), $status, $code, $message, $retry_after, $reason );
	}

	/**
	 * Why authentication failed: `missing`, `invalid`, or an empty string for other results.
	 */
	public function reason(): string {
		return $this->reason;
	}

	public function isOk(): bool {
		return $this->ok;
	}

	public function row(): array {
		return $this->row;
	}

	/**
	 * @return string[]
	 */
	public function scopes(): array {
		return $this->scopes;
	}

	public function status(): int {
		return $this->status;
	}

	public function code(): int {
		return $this->code;
	}

	public function message(): string {
		return $this->message;
	}

	/**
	 * Seconds for the Retry-After header; 0 unless rate limited.
	 */
	public function retryAfter(): int {
		return $this->retry_after;
	}
}
