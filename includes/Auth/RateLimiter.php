<?php
/**
 * Sliding-window rate limiter.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Limits requests per token over a sliding 60 second window.
 */
final class RateLimiter {

	const WINDOW = 60;

	/**
	 * Requests allowed per window; zero or less disables limiting.
	 *
	 * @var int
	 */
	private $limit;

	/**
	 * Window storage.
	 *
	 * @var RateLimitStore
	 */
	private $store;

	/**
	 * Returns the current unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * @param int            $limit_per_minute Requests allowed per minute; 0 disables.
	 * @param RateLimitStore $store            Window storage.
	 * @param callable|null  $clock            Returns the current unix time.
	 */
	public function __construct( int $limit_per_minute, RateLimitStore $store, ?callable $clock = null ) {
		$this->limit = $limit_per_minute;
		$this->store = $store;
		$this->clock = $clock ?? 'time';
	}

	/**
	 * Records a request and returns whether it is within the limit.
	 *
	 * @param int $token_id Token id.
	 */
	public function allow( int $token_id ): bool {
		if ( $this->limit <= 0 ) {
			return true;
		}

		$now    = (int) call_user_func( $this->clock );
		$key    = self::key( $token_id );
		$stamps = $this->recent( $key, $now );

		if ( count( $stamps ) >= $this->limit ) {
			return false;
		}

		$stamps[] = $now;
		$this->store->set( $key, $stamps, self::WINDOW );

		return true;
	}

	/**
	 * Seconds until a blocked request may be retried (at least 1), or 0 when limiting is disabled.
	 *
	 * @param int $token_id Token id.
	 */
	public function retryAfter( int $token_id ): int {
		if ( $this->limit <= 0 ) {
			return 0;
		}

		$now    = (int) call_user_func( $this->clock );
		$stamps = $this->recent( self::key( $token_id ), $now );

		if ( count( $stamps ) < $this->limit ) {
			return 1;
		}

		return max( 1, min( $stamps ) + self::WINDOW - $now );
	}

	/**
	 * Storage key for a token.
	 *
	 * @param int $token_id Token id.
	 */
	private static function key( int $token_id ): string {
		return 'techthrill_mcp_rl_' . $token_id;
	}

	/**
	 * Timestamps still inside the window.
	 *
	 * @param string $key Storage key.
	 * @param int    $now Current unix time.
	 * @return int[]
	 */
	private function recent( string $key, int $now ): array {
		$stamps = array();
		foreach ( $this->store->get( $key ) as $stamp ) {
			if ( is_int( $stamp ) && $stamp >= $now - self::WINDOW ) {
				$stamps[] = $stamp;
			}
		}

		return $stamps;
	}
}
