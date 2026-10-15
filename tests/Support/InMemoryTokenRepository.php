<?php
namespace TechThrill\MCP\Tests\Support;

use TechThrill\MCP\Auth\TokenRepository;

class InMemoryTokenRepository implements TokenRepository {

	/**
	 * Rows keyed by id.
	 *
	 * @var array<int,array>
	 */
	public $rows = array();

	/**
	 * Last assigned id.
	 *
	 * @var int
	 */
	private $last_id = 0;

	public function insert( array $row ): int {
		$id                = ++$this->last_id;
		$row['id']         = $id;
		$this->rows[ $id ] = $row;

		return $id;
	}

	public function findByLookup( string $lookup ): array {
		return array_values(
			array_filter(
				$this->rows,
				function ( $row ) use ( $lookup ) {
					return $row['lookup'] === $lookup;
				}
			)
		);
	}

	public function findByRefreshHash( string $hash ): ?array {
		foreach ( $this->rows as $row ) {
			if ( isset( $row['refresh_hash'] ) && hash_equals( (string) $row['refresh_hash'], $hash ) ) {
				return $row;
			}
		}

		return null;
	}

	public function find( int $id ): ?array {
		return $this->rows[ $id ] ?? null;
	}

	public function update( int $id, array $fields ): void {
		if ( isset( $this->rows[ $id ] ) ) {
			$this->rows[ $id ] = array_merge( $this->rows[ $id ], $fields );
		}
	}

	public function all(): array {
		return array_values( $this->rows );
	}

	public function delete( int $id ): void {
		unset( $this->rows[ $id ] );
	}
}
