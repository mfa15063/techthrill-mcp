<?php
/**
 * Token scope helper.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Known scopes and the implications between them.
 */
final class Scopes {

	const ALL = array( 'wp-cli', 'db:read', 'db:write', 'files:read', 'files:write' );

	/**
	 * Scopes implied by a broader scope.
	 */
	const IMPLIES = array(
		'db:write'    => array( 'db:read' ),
		'files:write' => array( 'files:read' ),
	);

	/**
	 * Adds implied scopes to a list.
	 *
	 * @param string[] $scopes Granted scopes.
	 * @return string[]
	 */
	public static function expand( array $scopes ): array {
		$expanded = array_values( $scopes );
		foreach ( $scopes as $scope ) {
			if ( isset( self::IMPLIES[ $scope ] ) ) {
				$expanded = array_merge( $expanded, self::IMPLIES[ $scope ] );
			}
		}

		return array_values( array_unique( $expanded ) );
	}

	/**
	 * Whether the granted scopes satisfy a required scope. A null requirement is always satisfied.
	 *
	 * @param string[]    $scopes Granted scopes.
	 * @param string|null $scope  Required scope.
	 */
	public static function has( array $scopes, ?string $scope ): bool {
		if ( null === $scope ) {
			return true;
		}

		return in_array( $scope, self::expand( $scopes ), true );
	}

	/**
	 * Keeps only known scopes, expands implications, and returns them in canonical order.
	 *
	 * @param array $input Raw scope values.
	 * @return string[]
	 */
	public static function sanitize( array $input ): array {
		$known = array();
		foreach ( $input as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$value = trim( (string) $value );
			if ( in_array( $value, self::ALL, true ) ) {
				$known[] = $value;
			}
		}

		$expanded = self::expand( $known );

		return array_values( array_filter( self::ALL, fn( $scope ) => in_array( $scope, $expanded, true ) ) );
	}
}
