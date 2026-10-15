<?php
/**
 * Random string helpers.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Cryptographically secure random strings.
 */
final class Random {

	const ALNUM = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

	/**
	 * Random alphanumeric string without modulo bias.
	 *
	 * @param int $length Number of characters.
	 */
	public static function alnum( int $length ): string {
		$size  = strlen( self::ALNUM );
		$limit = 256 - ( 256 % $size );
		$out   = '';
		$count = 0;

		while ( $count < $length ) {
			foreach ( unpack( 'C*', random_bytes( $length ) ) as $byte ) {
				if ( $byte >= $limit ) {
					continue;
				}
				$out .= self::ALNUM[ $byte % $size ];
				++$count;
				if ( $count === $length ) {
					break;
				}
			}
		}

		return $out;
	}

	/**
	 * Random code from an alphabet without look-alike characters (no 0, O, 1, I), without modulo bias.
	 *
	 * @param int $length Number of characters.
	 */
	public static function code( int $length ): string {
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$size     = strlen( $alphabet );
		$limit    = 256 - ( 256 % $size );
		$out      = '';
		$count    = 0;

		while ( $count < $length ) {
			foreach ( unpack( 'C*', random_bytes( $length ) ) as $byte ) {
				if ( $byte >= $limit ) {
					continue;
				}
				$out .= $alphabet[ $byte % $size ];
				++$count;
				if ( $count === $length ) {
					break;
				}
			}
		}

		return $out;
	}

	/**
	 * 43 base64url characters (256 bits), used for authorization codes.
	 */
	public static function urlSafe43(): string {
		// base64url encoding of random bytes; not obfuscation.
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
