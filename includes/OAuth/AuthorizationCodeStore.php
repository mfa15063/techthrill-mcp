<?php
/**
 * Storage for authorization codes.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Single-use authorization codes. Payload keys: client_id, redirect_uri, code_challenge, resource,
 * scopes (string[]) and user_id.
 */
interface AuthorizationCodeStore {

	/**
	 * Stores a payload and returns the plain code.
	 *
	 * @param array $payload Grant details.
	 * @param int   $ttl     Lifetime in seconds.
	 */
	public function issue( array $payload, int $ttl = 60 ): string;

	/**
	 * Returns the payload for a code and invalidates it. Null for unknown, expired or already used codes.
	 *
	 * @param string $code Plain code.
	 */
	public function consume( string $code ): ?array;
}
