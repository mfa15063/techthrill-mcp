<?php
namespace TechThrill\MCP\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\OAuth\AttemptLimiter;
use TechThrill\MCP\Tests\Support\InMemoryRateLimitStore;

class AttemptLimiterTest extends TestCase {

	private int $now = 1700000000;

	private InMemoryRateLimitStore $store;

	private function limiter(): AttemptLimiter {
		$this->store = $this->store ?? new InMemoryRateLimitStore();

		return new AttemptLimiter( $this->store, fn() => $this->now );
	}

	public function test_allows_up_to_max_then_blocks_within_window(): void {
		$l = $this->limiter();
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertTrue( $l->allow( 'register', '1.2.3.4', 3, 3600 ) );
		}
		$this->assertFalse( $l->allow( 'register', '1.2.3.4', 3, 3600 ) );
	}

	public function test_window_slides_with_the_clock(): void {
		$l = $this->limiter();
		$l->allow( 'register', 'ip', 1, 60 );
		$this->assertFalse( $l->allow( 'register', 'ip', 1, 60 ) );
		$this->now += 61;
		$this->assertTrue( $l->allow( 'register', 'ip', 1, 60 ) );
	}

	public function test_buckets_and_subjects_are_independent(): void {
		$l = $this->limiter();
		$this->assertTrue( $l->allow( 'a', 'ip1', 1, 60 ) );
		$this->assertTrue( $l->allow( 'b', 'ip1', 1, 60 ) );
		$this->assertTrue( $l->allow( 'a', 'ip2', 1, 60 ) );
		$this->assertFalse( $l->allow( 'a', 'ip1', 1, 60 ) );
	}

	public function test_retry_after_is_seconds_until_the_oldest_hit_expires(): void {
		$l = $this->limiter();
		$l->allow( 'a', 'ip', 1, 60 );
		$this->now += 20;
		$this->assertSame( 40, $l->retryAfter( 'a', 'ip', 60 ) );
		$this->assertSame( 1, $l->retryAfter( 'never', 'ip', 60 ) );
	}

	public function test_no_address_is_stored_in_clear(): void {
		$l = $this->limiter();
		$l->allow( 'register', '203.0.113.9', 5, 60 );
		$this->assertStringNotContainsString( '203.0.113.9', json_encode( array_keys( $this->store->data ) ) );
	}
}
