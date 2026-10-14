<?php
/**
 * Token lifecycle status.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Computes whether a token row is active, expired or revoked.
 */
final class TokenStatus {

	const ACTIVE  = 'active';
	const EXPIRED = 'expired';
	const REVOKED = 'revoked';

	/**
	 * Status of a token row at a point in time.
	 *
	 * @param array $row Token row with `expires_at` and `revoked_at` (UTC `Y-m-d H:i:s` or null).
	 * @param int   $now Unix timestamp.
	 */
	public static function of( array $row, int $now ): string {
		if ( ! empty( $row['revoked_at'] ) ) {
			return self::REVOKED;
		}

		if ( ! empty( $row['expires_at'] ) ) {
			$expires = strtotime( (string) $row['expires_at'] . ' UTC' );
			if ( false !== $expires && $expires <= $now ) {
				return self::EXPIRED;
			}
		}

		return self::ACTIVE;
	}
}
