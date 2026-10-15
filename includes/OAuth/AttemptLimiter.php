<?php
/**
 * Sliding-window limiter for unauthenticated OAuth endpoints.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\OAuth;

use TechThrill\MCP\Auth\RateLimitStore;

defined( 'ABSPATH' ) || exit;

/**
 * Limits attempts per bucket and subject (an IP address). Subjects are hashed, so no address is stored.
 */
final class AttemptLimiter {

	private RateLimitStore $store;

	/**
	 * Returns the current unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * @param RateLimitStore $store Window storage.
	 * @param callable|null  $clock Returns the current unix time.
	 */
	public function __construct( RateLimitStore $store, ?callable $clock = null ) {
		$this->store = $store;
		$this->clock = $clock ?? 'time';
	}

	/**
	 * Records an attempt and returns whether it is within the limit.
	 *
	 * @param string $bucket        Name of the limited action.
	 * @param string $subject       Who is limited, for example an IP address.
	 * @param int    $max           Attempts allowed per window.
	 * @param int    $windowSeconds Window length.
	 */
	public function allow( string $bucket, string $subject, int $max, int $windowSeconds ): bool {
		$now    = (int) call_user_func( $this->clock );
		$key    = self::key( $bucket, $subject );
		$stamps = $this->recent( $key, $now, $windowSeconds );

		if ( count( $stamps ) >= $max ) {
			return false;
		}

		$stamps[] = $now;
		$this->store->set( $key, $stamps, $windowSeconds );

		return true;
	}

	/**
	 * Seconds until another attempt is allowed (at least 1).
	 *
	 * @param string $bucket        Name of the limited action.
	 * @param string $subject       Who is limited.
	 * @param int    $windowSeconds Window length.
	 */
	public function retryAfter( string $bucket, string $subject, int $windowSeconds ): int {
		$now    = (int) call_user_func( $this->clock );
		$stamps = $this->recent( self::key( $bucket, $subject ), $now, $windowSeconds );

		return array() === $stamps ? 1 : max( 1, min( $stamps ) + $windowSeconds - $now );
	}

	/**
	 * Storage key; the subject is hashed.
	 *
	 * @param string $bucket  Bucket name.
	 * @param string $subject Subject.
	 */
	private static function key( string $bucket, string $subject ): string {
		return 'techthrill_mcp_oa_' . substr( hash( 'sha256', $bucket . '|' . $subject ), 0, 40 );
	}

	/**
	 * Timestamps still inside the window.
	 *
	 * @param string $key    Storage key.
	 * @param int    $now    Current unix time.
	 * @param int    $window Window length.
	 * @return int[]
	 */
	private function recent( string $key, int $now, int $window ): array {
		$stamps = array();
		foreach ( $this->store->get( $key ) as $stamp ) {
			if ( is_int( $stamp ) && $stamp > $now - $window ) {
				$stamps[] = $stamp;
			}
		}

		return $stamps;
	}
}
