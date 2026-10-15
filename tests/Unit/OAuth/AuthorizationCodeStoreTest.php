<?php
namespace TechThrill\MCP\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\OAuth\OptionAuthorizationCodeStore;
use TechThrill\MCP\Tests\Support\InMemoryOptionsGateway;

class AuthorizationCodeStoreTest extends TestCase {

	private int $now = 1700000000;

	private InMemoryOptionsGateway $options;

	protected function setUp(): void {
		$this->options = new InMemoryOptionsGateway();
	}

	private function store(): OptionAuthorizationCodeStore {
		return new OptionAuthorizationCodeStore( $this->options, fn() => $this->now );
	}

	private function payload(): array {
		return array( 'client_id' => 'ttc_a', 'redirect_uri' => 'http://localhost:1/cb', 'code_challenge' => 'x', 'resource' => 'r', 'scopes' => array( 'db:read' ), 'user_id' => 3 );
	}

	public function test_issue_then_consume_returns_the_payload(): void {
		$s    = $this->store();
		$code = $s->issue( $this->payload() );

		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $code );
		$this->assertSame( $this->payload(), $s->consume( $code ) );
	}

	public function test_second_consume_returns_null(): void {
		$s    = $this->store();
		$code = $s->issue( $this->payload() );
		$s->consume( $code );
		$this->assertNull( $s->consume( $code ) );
	}

	public function test_expired_code_returns_null_and_is_removed(): void {
		$s    = $this->store();
		$code = $s->issue( $this->payload(), 60 );
		$this->now += 61;

		$this->assertNull( $s->consume( $code ) );
		$this->assertSame( array(), $this->options->options );
	}

	public function test_code_is_valid_up_to_its_expiry_second(): void {
		$s    = $this->store();
		$code = $s->issue( $this->payload(), 60 );
		$this->now += 59;
		$this->assertNotNull( $s->consume( $code ) );
	}

	public function test_unknown_code_returns_null(): void {
		$this->assertNull( $this->store()->consume( 'nope' ) );
	}

	public function test_plain_code_is_not_stored(): void {
		$code = $this->store()->issue( $this->payload() );
		$this->assertStringNotContainsString( $code, json_encode( $this->options->options ) );
		$this->assertSame( array( OptionAuthorizationCodeStore::PREFIX . hash( 'sha256', $code ) ), array_keys( $this->options->options ) );
	}

	public function test_prune_removes_only_expired_codes(): void {
		$s    = $this->store();
		$old  = $s->issue( $this->payload(), 10 );
		$live = $s->issue( $this->payload(), 600 );
		$this->options->add( 'unrelated_option', array( 'a' => 1 ) );
		$this->now += 30;

		$this->assertSame( 1, $s->prune() );
		$this->assertNull( $s->consume( $old ) );
		$this->assertNotNull( $s->consume( $live ) );
		$this->assertArrayHasKey( 'unrelated_option', $this->options->options );
	}

	public function test_losing_the_delete_race_returns_null(): void {
		$s    = $this->store();
		$code = $s->issue( $this->payload() );
		$this->options->lose_delete_race = true;

		$this->assertNull( $s->consume( $code ) );
	}
}
