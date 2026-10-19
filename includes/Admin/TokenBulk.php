<?php
/**
 * Bulk actions on the Tokens table.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Admin;

use TechThrill\MCP\Auth\TokenManager;
use TechThrill\MCP\Auth\TokenRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Revokes or deletes several tokens at once. Pure PHP: the page checks the nonce and the capability first.
 */
final class TokenBulk {

	const REVOKE = 'revoke';

	const DELETE = 'delete';

	/**
	 * Most tokens handled in one request.
	 */
	const MAX_IDS = 500;

	/**
	 * Whether a posted bulk action is one this class handles.
	 *
	 * @param string $action Posted action.
	 */
	public static function isAction( string $action ): bool {
		return in_array( $action, array( self::REVOKE, self::DELETE ), true );
	}

	/**
	 * Reduces posted IDs to unique positive integers, at most MAX_IDS.
	 *
	 * @param mixed $posted Raw posted value.
	 * @return int[]
	 */
	public static function cleanIds( $posted ): array {
		if ( ! is_array( $posted ) ) {
			return array();
		}

		$ids = array();
		foreach ( $posted as $value ) {
			if ( is_scalar( $value ) && 1 === preg_match( '/^[0-9]{1,18}$/D', (string) $value ) && (int) $value > 0 ) {
				$ids[ (int) $value ] = (int) $value;
			}
		}

		return array_slice( array_values( $ids ), 0, self::MAX_IDS );
	}

	/**
	 * Applies an action to the given tokens.
	 *
	 * Revoke only affects active tokens. Delete removes any selected token, which also ends it at once.
	 * Tokens that no longer exist, or that revoke cannot change, are counted as skipped.
	 *
	 * @param string          $action Revoke or delete.
	 * @param int[]           $ids    Token IDs.
	 * @param TokenRepository $repo   Token storage.
	 * @param TokenManager    $tokens Token manager.
	 * @param int             $now    Unix time.
	 * @return array{done:int,skipped:int}
	 */
	public static function apply( string $action, array $ids, TokenRepository $repo, TokenManager $tokens, int $now ): array {
		$done    = 0;
		$skipped = 0;
		if ( ! self::isAction( $action ) ) {
			return array(
				'done'    => 0,
				'skipped' => count( $ids ),
			);
		}

		foreach ( self::cleanIds( $ids ) as $id ) {
			$row = $repo->find( $id );
			if ( null === $row ) {
				++$skipped;
				continue;
			}

			if ( self::DELETE === $action ) {
				$repo->delete( $id );
				++$done;
			} elseif ( TokenStatus::ACTIVE === TokenStatus::of( $row, $now ) ) {
				$tokens->revoke( $id );
				++$done;
			} else {
				++$skipped;
			}
		}

		return array(
			'done'    => $done,
			'skipped' => $skipped,
		);
	}
}
