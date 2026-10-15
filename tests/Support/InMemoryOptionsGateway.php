<?php
namespace TechThrill\MCP\Tests\Support;

use TechThrill\MCP\OAuth\OptionsGateway;

class InMemoryOptionsGateway implements OptionsGateway {

	/**
	 * Options by name.
	 *
	 * @var array<string,array>
	 */
	public $options = array();

	/**
	 * When true, delete() reports failure without removing, as if another request won the race.
	 *
	 * @var bool
	 */
	public $lose_delete_race = false;

	public function get( string $name ): ?array {
		return $this->options[ $name ] ?? null;
	}

	public function add( string $name, array $value ): bool {
		if ( isset( $this->options[ $name ] ) ) {
			return false;
		}
		$this->options[ $name ] = $value;

		return true;
	}

	public function delete( string $name ): bool {
		if ( $this->lose_delete_race || ! isset( $this->options[ $name ] ) ) {
			return false;
		}
		unset( $this->options[ $name ] );

		return true;
	}

	public function names( string $prefix ): array {
		return array_values( array_filter( array_keys( $this->options ), fn( $n ) => 0 === strpos( $n, $prefix ) ) );
	}
}
