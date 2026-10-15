<?php
namespace TechThrill\MCP\Tests\Support;

use TechThrill\MCP\OAuth\ClientStore;

class InMemoryClientStore implements ClientStore {

	/**
	 * Clients keyed by id.
	 *
	 * @var array<string,array>
	 */
	public $clients = array();

	public function find( string $clientId ): ?array {
		return $this->clients[ $clientId ] ?? null;
	}

	public function save( array $client ): void {
		$this->clients[ $client['client_id'] ] = $client;
	}

	public function delete( string $clientId ): void {
		unset( $this->clients[ $clientId ] );
	}

	public function all(): array {
		return array_values( $this->clients );
	}
}
