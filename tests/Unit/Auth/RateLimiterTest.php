<?php
namespace TechThrill\MCP\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\Auth\RateLimiter;
use TechThrill\MCP\Tests\Support\InMemoryRateLimitStore;

class RateLimiterTest extends TestCase {

	/**
	 * Current fake time.
	 *
	 * @var int
	 */
	private $now = 1700000000;

	private function limiter( int $limit, ?InMemoryRateLimitStore $store = null ): RateLimiter {
		return new RateLimiter( $limit, $store ?? new InMemoryRateLimitStore(), fn() => $this->now );
	}

	public function test_allows_up_to_limit_then_blocks(): void {
		$limiter = $this->limiter( 3 );

		$this->assertTrue( $limiter->allow( 1 ) );
		$this->assertTrue( $limiter->allow( 1 ) );
		$this->assertTrue( $limiter->allow( 1 ) );
		$this->assertFalse( $limiter->allow( 1 ) );
		// Other tokens have their own window.
		$this->assertTrue( $limiter->allow( 2 ) );
	}

	public function test_window_slides(): void {
		$limiter = $this->limiter( 2 );
		$limiter->allow( 1 );
		$limiter->allow( 1 );
		$this->assertFalse( $limiter->allow( 1 ) );

		$this->now += 61;
		$this->assertTrue( $limiter->allow( 1 ) );
	}

	public function test_zero_limit_disables(): void {
		$limiter = $this->limiter( 0 );

		for ( $i = 0; $i < 50; $i++ ) {
			$this->assertTrue( $limiter->allow( 1 ) );
		}
		$this->assertSame( 0, $limiter->retryAfter( 1 ) );
	}

	public function test_retry_after_positive(): void {
		$limiter = $this->limiter( 1 );
		$limiter->allow( 1 );
		$this->now += 20;

		$this->assertFalse( $limiter->allow( 1 ) );
		$this->assertSame( 40, $limiter->retryAfter( 1 ) );
		$this->assertSame( 1, $this->limiter( 5 )->retryAfter( 9 ) );
	}

	public function test_blocked_attempts_are_not_recorded(): void {
		$store   = new InMemoryRateLimitStore();
		$limiter = $this->limiter( 1, $store );
		$limiter->allow( 1 );
		$limiter->allow( 1 );
		$limiter->allow( 1 );

		$this->assertCount( 1, $store->get( 'techthrill_mcp_rl_1' ) );
	}
}
