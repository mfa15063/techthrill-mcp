<?php
/**
 * Token storage contract.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Persists token rows. Row keys: id, name, lookup, hash, scopes, user_id, created_at,
 * expires_at, last_used_at, revoked_at. Datetimes are `Y-m-d H:i:s` strings in UTC.
 */
interface TokenRepository {

	/**
	 * Inserts a row and returns its new id.
	 *
	 * @param array $row Row without an id.
	 */
	public function insert( array $row ): int;

	/**
	 * Finds all rows whose lookup column equals the given value.
	 *
	 * @param string $lookup First 12 characters of a plain token.
	 * @return array[]
	 */
	public function findByLookup( string $lookup ): array;

	/**
	 * Finds a row by id.
	 *
	 * @param int $id Row id.
	 */
	public function find( int $id ): ?array;

	/**
	 * Updates fields on a row.
	 *
	 * @param int   $id     Row id.
	 * @param array $fields Column values to change.
	 */
	public function update( int $id, array $fields ): void;

	/**
	 * Returns every row.
	 *
	 * @return array[]
	 */
	public function all(): array;

	/**
	 * Deletes a row.
	 *
	 * @param int $id Row id.
	 */
	public function delete( int $id ): void;
}
