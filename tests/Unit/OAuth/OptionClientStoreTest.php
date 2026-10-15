<?php
namespace TechThrill\MCP\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\OAuth\ClientName;
use TechThrill\MCP\OAuth\OptionClientStore;
use TechThrill\MCP\OAuth\Random;

class OptionClientStoreTest extends TestCase {

	private array $options = array();

	private array $autoload = array();

	private function store(): OptionClientStore {
		return new OptionClientStore(
			fn( string $name, $default = false ) => $this->options[ $name ] ?? $default,
			function ( string $name, $value, $autoload ): bool {
				$this->options[ $name ]  = $value;
				$this->autoload[ $name ] = $autoload;
				return true;
			}
		);
	}

	public function test_store_round_trip_find_save_delete_all(): void {
		$s = $this->store();
		$this->assertNull( $s->find( 'a' ) );
		$this->assertSame( array(), $s->all() );

		$s->save( array( 'client_id' => 'a', 'client_name' => 'A' ) );
		$s->save( array( 'client_id' => 'b', 'client_name' => 'B' ) );
		$this->assertSame( 'A', $s->find( 'a' )['client_name'] );
		$this->assertCount( 2, $s->all() );

		$s->save( array( 'client_id' => 'a', 'client_name' => 'A2' ) );
		$this->assertSame( 'A2', $s->find( 'a' )['client_name'] );
		$this->assertCount( 2, $s->all() );

		$s->delete( 'a' );
		$this->assertNull( $s->find( 'a' ) );
		$this->assertCount( 1, $s->all() );
	}

	public function test_option_is_not_autoloaded(): void {
		$this->store()->save( array( 'client_id' => 'a' ) );
		$this->assertFalse( $this->autoload[ OptionClientStore::OPTION ] );
	}

	public function test_a_corrupt_option_value_reads_as_empty(): void {
		$this->options[ OptionClientStore::OPTION ] = 'garbage';
		$this->assertSame( array(), $this->store()->all() );
	}

	public function test_client_name_is_plain_text_and_bounded(): void {
		$this->assertSame( 'Claude', ClientName::clean( '<b>Claude</b>' ) );
		$this->assertSame( 'a b', ClientName::clean( "a\n\tb" ) );
		$this->assertSame( ClientName::DEFAULT_NAME, ClientName::clean( '   ' ) );
		$this->assertSame( ClientName::DEFAULT_NAME, ClientName::clean( array( 'x' ) ) );
		$this->assertSame( 100, strlen( ClientName::clean( str_repeat( 'x', 500 ) ) ) );
	}

	public function test_random_helpers(): void {
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{24}$/', Random::alnum( 24 ) );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', Random::urlSafe43() );
		$this->assertNotSame( Random::urlSafe43(), Random::urlSafe43() );
	}
}
