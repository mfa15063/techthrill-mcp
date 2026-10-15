<?php
/**
 * Storage for OAuth clients.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Persists client records: client_id, client_name, redirect_uris (string[]), source (dcr or cimd),
 * created_at, last_used_at (int|null) and fetched_at (int|null).
 */
interface ClientStore {

	/**
	 * Finds a client.
	 *
	 * @param string $clientId Client ID.
	 */
	public function find( string $clientId ): ?array;

	/**
	 * Inserts or replaces a client, keyed by its client_id.
	 *
	 * @param array $client Client record.
	 */
	public function save( array $client ): void;

	/**
	 * Deletes a client.
	 *
	 * @param string $clientId Client ID.
	 */
	public function delete( string $clientId ): void;

	/**
	 * Every client.
	 *
	 * @return array[]
	 */
	public function all(): array;
}
