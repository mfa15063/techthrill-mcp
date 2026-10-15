<?php
namespace TechThrill\MCP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\Settings;

class SettingsTest extends TestCase {

	public function test_defaults_and_override(): void {
		$defaults = new Settings( array() );
		$this->assertSame( 0, $defaults->get( 'safe_mode' ) );
		$this->assertSame( 120, $defaults->get( 'rate_limit' ) );
		$this->assertSame( 60, $defaults->get( 'cli_timeout' ) );
		$this->assertSame( '', $defaults->get( 'cli_path' ) );
		$this->assertSame( '', $defaults->get( 'php_path' ) );
		$this->assertSame( 30, $defaults->get( 'log_retention_days' ) );
		$this->assertNull( $defaults->get( 'nope' ) );

		$custom = new Settings( array( 'rate_limit' => 5 ) );
		$this->assertSame( 5, $custom->get( 'rate_limit' ) );
		$this->assertSame( 60, $custom->get( 'cli_timeout' ) );
		$this->assertSame( 5, $custom->all()['rate_limit'] );
		$this->assertCount( 6, $custom->all() );
	}

	public function test_stored_safe_mode_overrides_the_default_in_both_directions(): void {
		$this->assertSame( 1, ( new Settings( array( 'safe_mode' => 1 ) ) )->get( 'safe_mode' ) );
		$this->assertSame( 0, ( new Settings( array( 'safe_mode' => 0 ) ) )->get( 'safe_mode' ) );
	}

	public function test_sanitize_clamps(): void {
		$out = Settings::sanitize(
			array(
				'rate_limit'         => -5,
				'cli_timeout'        => 1000,
				'log_retention_days' => 0,
				'safe_mode'          => 'yes',
			)
		);

		$this->assertSame( 0, $out['rate_limit'] );
		$this->assertSame( 300, $out['cli_timeout'] );
		$this->assertSame( 1, $out['log_retention_days'] );
		$this->assertSame( 1, $out['safe_mode'] );
	}

	public function test_sanitize_upper_and_lower_bounds(): void {
		$out = Settings::sanitize(
			array(
				'rate_limit'         => '99999',
				'cli_timeout'        => 1,
				'log_retention_days' => 9999,
			)
		);

		$this->assertSame( 10000, $out['rate_limit'] );
		$this->assertSame( 5, $out['cli_timeout'] );
		$this->assertSame( 365, $out['log_retention_days'] );
	}

	public function test_sanitize_returns_only_known_keys_with_defaults(): void {
		$out = Settings::sanitize(
			array(
				'rate_limit'  => 'abc',
				'cli_timeout' => '45',
				'evil'        => 'x',
			)
		);

		$this->assertSame( array( 'safe_mode', 'rate_limit', 'cli_timeout', 'cli_path', 'php_path', 'log_retention_days' ), array_keys( $out ) );
		$this->assertSame( 120, $out['rate_limit'] );
		$this->assertSame( 45, $out['cli_timeout'] );
		$this->assertSame( '', $out['cli_path'] );
		$this->assertSame( '', $out['php_path'] );
		$this->assertSame( 30, $out['log_retention_days'] );
		// Unchecked checkbox is absent from the form post.
		$this->assertSame( 0, $out['safe_mode'] );
	}

	public function test_sanitize_safe_mode_zero_string_is_off(): void {
		$this->assertSame( 0, Settings::sanitize( array( 'safe_mode' => '0' ) )['safe_mode'] );
		$this->assertSame( 1, Settings::sanitize( array( 'safe_mode' => '1' ) )['safe_mode'] );
	}

	public function test_sanitize_cli_path_is_text_field_sanitized(): void {
		$out = Settings::sanitize( array( 'cli_path' => '  <b>/usr/bin/wp</b> ' ) );

		$this->assertSame( '/usr/bin/wp', $out['cli_path'] );
	}

	public function test_sanitize_php_path_is_text_field_sanitized(): void {
		$out = Settings::sanitize( array( 'php_path' => '  <i>/usr/bin/php8.2</i> ' ) );

		$this->assertSame( '/usr/bin/php8.2', $out['php_path'] );
		$this->assertSame( '', Settings::sanitize( array( 'php_path' => array( 'x' ) ) )['php_path'] );
	}
}
