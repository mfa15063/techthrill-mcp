<?php
/**
 * Client store backed by one non-autoloaded option.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps all clients in the option techthrill_mcp_oauth_clients, keyed by client_id.
 */
final class OptionClientStore implements ClientStore {

	const OPTION = 'techthrill_mcp_oauth_clients';

	/**
	 * Reads the option: fn( string $name, $default ).
	 *
	 * @var callable
	 */
	private $get;

	/**
	 * Writes the option: fn( string $name, $value, $autoload ).
	 *
	 * @var callable
	 */
	private $update;

	/**
	 * @param callable|null $get    Option reader (default get_option).
	 * @param callable|null $update Option writer (default update_option).
	 */
	public function __construct( ?callable $get = null, ?callable $update = null ) {
		$this->get    = $get ?? 'get_option';
		$this->update = $update ?? 'update_option';
	}

	public function find( string $clientId ): ?array {
		$all = $this->read();

		return $all[ $clientId ] ?? null;
	}

	public function save( array $client ): void {
		$all                                  = $this->read();
		$all[ (string) $client['client_id'] ] = $client;
		$this->write( $all );
	}

	public function delete( string $clientId ): void {
		$all = $this->read();
		unset( $all[ $clientId ] );
		$this->write( $all );
	}

	public function all(): array {
		return array_values( $this->read() );
	}

	/**
	 * @return array<string,array>
	 */
	private function read(): array {
		$value = call_user_func( $this->get, self::OPTION, array() );

		return is_array( $value ) ? $value : array();
	}

	/**
	 * @param array<string,array> $all Clients keyed by id.
	 */
	private function write( array $all ): void {
		call_user_func( $this->update, self::OPTION, $all, false );
	}
}
