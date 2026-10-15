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
	 * Type label of a token row: OAuth for grants made through the consent screen, Bearer otherwise.
	 *
	 * @param array $row Token row.
	 */
	public static function kindLabel( array $row ): string {
		return 'oauth' === ( $row['kind'] ?? null ) ? 'OAuth' : 'Bearer';
	}

	/**
	 * The expiry that matters for a row: an OAuth grant lasts as long as its refresh token.
	 *
	 * @param array $row Token row.
	 * @return string|null UTC `Y-m-d H:i:s`, or null for no expiry.
	 */
	public static function expiryOf( array $row ): ?string {
		$value = 'oauth' === ( $row['kind'] ?? null ) ? ( $row['refresh_expires_at'] ?? null ) : ( $row['expires_at'] ?? null );

		return empty( $value ) ? null : (string) $value;
	}

	/**
	 * Status of a token row at a point in time.
	 *
	 * @param array $row Token row with `expires_at` and `revoked_at` (UTC `Y-m-d H:i:s` or null); OAuth rows use `refresh_expires_at`.
	 * @param int   $now Unix timestamp.
	 */
	public static function of( array $row, int $now ): string {
		if ( ! empty( $row['revoked_at'] ) ) {
			return self::REVOKED;
		}

		// An OAuth grant lives as long as its refresh token; its one-hour access token expiring is routine.
		if ( 'oauth' === ( $row['kind'] ?? null ) ) {
			$refresh = ! empty( $row['refresh_expires_at'] ) ? strtotime( (string) $row['refresh_expires_at'] . ' UTC' ) : false;

			return false !== $refresh && $refresh <= $now ? self::EXPIRED : self::ACTIVE;
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
