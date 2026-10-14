<?php
/**
 * Bearer token authentication for MCP requests.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Auth;

use TechThrill\MCP\Mcp\JsonRpcError;

defined( 'ABSPATH' ) || exit;

/**
 * Runs, in order: header parse, token verify, owner is still an administrator, rate limit, touch.
 */
final class Authenticator {

	/**
	 * @var TokenManager
	 */
	private $tokens;

	/**
	 * @var RateLimiter
	 */
	private $limiter;

	/**
	 * Returns whether a user id still belongs to an administrator.
	 *
	 * @var callable
	 */
	private $user_is_admin;

	/**
	 * @param TokenManager $tokens        Token verification.
	 * @param RateLimiter  $limiter       Per-token rate limiter.
	 * @param callable     $user_is_admin fn( int $user_id ): bool.
	 */
	public function __construct( TokenManager $tokens, RateLimiter $limiter, callable $user_is_admin ) {
		$this->tokens        = $tokens;
		$this->limiter       = $limiter;
		$this->user_is_admin = $user_is_admin;
	}

	/**
	 * Authenticates a request from its Authorization header.
	 *
	 * @param string|null $authorization_header Raw header value.
	 */
	public function authenticate( ?string $authorization_header ): AuthResult {
		if ( null === $authorization_header || 1 !== preg_match( '/^\s*Bearer\s+(\S+)\s*$/i', $authorization_header, $matches ) ) {
			return AuthResult::fail(
				401,
				JsonRpcError::UNAUTHORIZED,
				'Missing or invalid Authorization header. Use: Authorization: Bearer <token>'
			);
		}

		$row = $this->tokens->verify( $matches[1] );
		if ( null === $row ) {
			return AuthResult::fail( 401, JsonRpcError::UNAUTHORIZED, 'Invalid, expired or revoked token.' );
		}

		if ( ! call_user_func( $this->user_is_admin, (int) $row['user_id'] ) ) {
			return AuthResult::fail(
				401,
				JsonRpcError::UNAUTHORIZED,
				"This token's owner is no longer an administrator, so the token no longer works."
			);
		}

		$id = (int) $row['id'];
		if ( ! $this->limiter->allow( $id ) ) {
			$retry_after = $this->limiter->retryAfter( $id );

			return AuthResult::fail(
				429,
				JsonRpcError::RATE_LIMITED,
				'Rate limit exceeded. Retry after ' . $retry_after . ' seconds.',
				$retry_after
			);
		}

		$this->tokens->touch( $id );

		return AuthResult::ok( $row, Scopes::sanitize( explode( ',', (string) $row['scopes'] ) ) );
	}
}
