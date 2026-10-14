<?php
namespace TechThrill\MCP\Tests\Support;

use TechThrill\MCP\Auth\RateLimitStore;

class InMemoryRateLimitStore implements RateLimitStore {

	/**
	 * Stored values keyed by cache key. TTL is ignored.
	 *
	 * @var array<string,array>
	 */
	public $data = array();

	public function get( string $key ): array {
		return $this->data[ $key ] ?? array();
	}

	public function set( string $key, array $value, int $ttl ): void {
		$this->data[ $key ] = $value;
	}
}
