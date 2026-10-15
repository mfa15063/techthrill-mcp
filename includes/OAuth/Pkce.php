<?php
/**
 * PKCE (RFC 7636) S256 helpers.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Validates and verifies S256 code challenges. The plain method is not supported.
 */
final class Pkce {

	/**
	 * Whether a string is a well-formed S256 challenge: 43 base64url characters.
	 *
	 * @param string $challenge Candidate challenge.
	 */
	public static function isValidChallenge( string $challenge ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/D', $challenge );
	}

	/**
	 * Whether a string is a well-formed verifier: 43 to 128 characters of A-Z a-z 0-9 - . _ ~.
	 *
	 * @param string $verifier Candidate verifier.
	 */
	public static function isValidVerifier( string $verifier ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9._~-]{43,128}$/D', $verifier );
	}

	/**
	 * S256 challenge for a verifier.
	 *
	 * @param string $verifier Verifier.
	 */
	public static function challengeFor( string $verifier ): string {
		// base64url encoding of a digest, as RFC 7636 requires; not obfuscation.
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Whether the verifier matches the stored challenge.
	 *
	 * @param string $verifier  Verifier from the token request.
	 * @param string $challenge Challenge stored with the code.
	 */
	public static function verify( string $verifier, string $challenge ): bool {
		return self::isValidVerifier( $verifier ) && hash_equals( $challenge, self::challengeFor( $verifier ) );
	}
}
