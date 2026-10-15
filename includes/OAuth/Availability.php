<?php
/**
 * Whether the OAuth flow can work on this site.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Pure checks over the issuer URL and the permalink setting.
 */
final class Availability {

	const PERMALINKS = 'permalinks';

	const HTTPS = 'https';

	const LOCAL = 'local';

	/**
	 * Problems that stop OAuth from working, or limit who can reach the site.
	 *
	 * `permalinks` and `https` block OAuth; `local` only warns that hosted clients cannot reach the site.
	 *
	 * @param string $issuerUrl          REST base URL used as the issuer.
	 * @param string $permalinkStructure The permalink_structure option (empty means plain permalinks).
	 * @return string[]
	 */
	public static function problems( string $issuerUrl, string $permalinkStructure ): array {
		$problems = array();
		$parts    = wp_parse_url( $issuerUrl );
		$scheme   = is_array( $parts ) && isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
		$host     = is_array( $parts ) && isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		$local    = self::isLocalHost( $host );

		if ( '' === $permalinkStructure || false !== strpos( $issuerUrl, '?' ) ) {
			$problems[] = self::PERMALINKS;
		}
		if ( 'https' !== $scheme && ! $local ) {
			$problems[] = self::HTTPS;
		}
		if ( $local ) {
			$problems[] = self::LOCAL;
		}

		return $problems;
	}

	/**
	 * Whether OAuth can be offered: no blocking problem.
	 *
	 * @param string $issuerUrl          Issuer URL.
	 * @param string $permalinkStructure Permalink structure.
	 */
	public static function isAvailable( string $issuerUrl, string $permalinkStructure ): bool {
		return array() === array_intersect( self::problems( $issuerUrl, $permalinkStructure ), array( self::PERMALINKS, self::HTTPS ) );
	}

	/**
	 * Whether a host is a loopback or private-network host that hosted clients cannot reach.
	 *
	 * @param string $host Lower-case host name or IP.
	 */
	public static function isLocalHost( string $host ): bool {
		$host = trim( $host, '[]' );
		if ( '' === $host || 'localhost' === $host || '::1' === $host ) {
			return true;
		}
		foreach ( array( '.localhost', '.local', '.test', '.example', '.invalid', '.internal', '.lan' ) as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false === filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}

		return false === strpos( $host, '.' );
	}
}
