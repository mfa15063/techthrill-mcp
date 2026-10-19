<?php
namespace TechThrill\MCP\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\OAuth\Lifetime;

class LifetimeTest extends TestCase {

	public function test_choices_are_one_day_one_week_thirty_days_and_never_in_that_order(): void {
		$this->assertSame( array( '1d', '1w', '30d', 'never' ), array_keys( Lifetime::choices() ) );
		$this->assertSame( array( '1d' => 86400, '1w' => 604800, '30d' => 2592000, 'never' => null ), Lifetime::choices() );
		$this->assertSame( array( '1d', '1w', '30d', 'never' ), array_keys( Lifetime::labels() ) );
		$this->assertSame( array( '1 day', '1 week', '30 days', 'Never expire' ), array_values( Lifetime::labels() ) );
	}

	public function test_thirty_days_is_the_default(): void {
		$this->assertSame( '30d', Lifetime::DEFAULT_KEY );
		$this->assertSame( 2592000, Lifetime::DEFAULT_SECONDS );
		$this->assertSame( Lifetime::choices()[ Lifetime::DEFAULT_KEY ], Lifetime::DEFAULT_SECONDS );
	}

	public function test_seconds_for_each_choice_and_unknown_keys_get_the_default_not_never(): void {
		$this->assertSame( 86400, Lifetime::seconds( '1d' ) );
		$this->assertSame( 604800, Lifetime::seconds( '1w' ) );
		$this->assertSame( 2592000, Lifetime::seconds( '30d' ) );
		$this->assertNull( Lifetime::seconds( 'never' ) );
		foreach ( array( '', 'forever', '0', 'NEVER', '1D' ) as $bad ) {
			$this->assertSame( 2592000, Lifetime::seconds( $bad ), json_encode( $bad ) );
		}
	}
}
