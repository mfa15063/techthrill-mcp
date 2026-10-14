<?php
/**
 * Settings tab: Settings API form for the plugin option.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Admin\Pages;

use TechThrill\MCP\Plugin;
use TechThrill\MCP\Settings as PluginSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the plugin settings.
 */
final class Settings {

	const GROUP   = 'techthrill_mcp';
	const SECTION = 'techthrill-mcp-settings';

	private Plugin $plugin;

	/**
	 * @param Plugin $plugin Plugin instance.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hooked to admin_init: registers the option, section and fields.
	 */
	public function register(): void {
		register_setting(
			self::GROUP,
			PluginSettings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( PluginSettings::class, 'sanitize' ),
				'default'           => PluginSettings::DEFAULTS,
			)
		);

		add_settings_section( self::SECTION, '', '__return_false', self::SECTION );

		$fields = array(
			'safe_mode'          => array( __( 'Safe mode', 'techthrill-mcp' ), 'renderSafeMode' ),
			'rate_limit'         => array( __( 'Rate limit per minute', 'techthrill-mcp' ), 'renderRateLimit' ),
			'cli_timeout'        => array( __( 'WP-CLI timeout (seconds)', 'techthrill-mcp' ), 'renderCliTimeout' ),
			'cli_path'           => array( __( 'WP-CLI binary path', 'techthrill-mcp' ), 'renderCliPath' ),
			'php_path'           => array( __( 'PHP CLI binary path override', 'techthrill-mcp' ), 'renderPhpPath' ),
			'log_retention_days' => array( __( 'Log retention (days)', 'techthrill-mcp' ), 'renderRetention' ),
		);
		foreach ( $fields as $key => $field ) {
			add_settings_field( $key, $field[0], array( $this, $field[1] ), self::SECTION, self::SECTION, array( 'label_for' => 'ttm-' . $key ) );
		}
	}

	/**
	 * Prints the tab.
	 */
	public function render(): void {
		settings_errors();
		echo '<form method="post" action="options.php">';
		settings_fields( self::GROUP );
		do_settings_sections( self::SECTION );
		submit_button();
		echo '</form>';
	}

	public function renderSafeMode(): void {
		// Unchecked checkboxes are not submitted; this hidden 0 lets "off" be saved.
		echo '<input type="hidden" name="' . esc_attr( $this->name( 'safe_mode' ) ) . '" value="0" />';
		echo '<label for="ttm-safe_mode"><input type="checkbox" id="ttm-safe_mode" name="' . esc_attr( $this->name( 'safe_mode' ) ) . '" value="1"' . checked( (int) $this->value( 'safe_mode' ), 1, false ) . ' /> ' . esc_html__( 'Enable safe mode', 'techthrill-mcp' ) . '</label>';
		$this->description( __( 'When on, blocks DROP/TRUNCATE, DELETE and UPDATE without WHERE, destructive WP-CLI commands, directory deletion, and writes to .htaccess, the uploads folder used by this plugin, and the plugin\'s own directory.', 'techthrill-mcp' ) );
	}

	public function renderRateLimit(): void {
		$this->number( 'rate_limit', 0, 10000 );
		$this->description( __( 'Maximum requests per token per minute. 0 turns the limit off.', 'techthrill-mcp' ) );
	}

	public function renderCliTimeout(): void {
		$this->number( 'cli_timeout', 5, 300 );
		$this->description( __( 'How long a WP-CLI command may run when the caller does not ask for a different timeout.', 'techthrill-mcp' ) );
	}

	public function renderCliPath(): void {
		echo '<input type="text" class="regular-text" id="ttm-cli_path" name="' . esc_attr( $this->name( 'cli_path' ) ) . '" value="' . esc_attr( (string) $this->value( 'cli_path' ) ) . '" />';
		$this->description( __( 'Optional. Full path to a wp executable or wp-cli.phar. Leave empty to detect one automatically.', 'techthrill-mcp' ) );
	}

	public function renderPhpPath(): void {
		echo '<input type="text" class="regular-text" id="ttm-php_path" name="' . esc_attr( $this->name( 'php_path' ) ) . '" value="' . esc_attr( (string) $this->value( 'php_path' ) ) . '" />';
		$this->description( __( 'Used to run wp-cli.phar. Leave empty to auto-detect.', 'techthrill-mcp' ) );
	}

	public function renderRetention(): void {
		$this->number( 'log_retention_days', 1, 365 );
		$this->description( __( 'Activity log entries older than this are deleted automatically.', 'techthrill-mcp' ) );
	}

	private function number( string $key, int $min, int $max ): void {
		echo '<input type="number" class="small-text" id="ttm-' . esc_attr( $key ) . '" name="' . esc_attr( $this->name( $key ) ) . '" value="' . esc_attr( (string) $this->value( $key ) ) . '" min="' . esc_attr( (string) $min ) . '" max="' . esc_attr( (string) $max ) . '" step="1" />';
	}

	private function description( string $text ): void {
		echo '<p class="description">' . esc_html( $text ) . '</p>';
	}

	private function name( string $key ): string {
		return PluginSettings::OPTION . '[' . $key . ']';
	}

	/**
	 * Reads a setting value.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	private function value( string $key ) {
		return $this->plugin->settings()->get( $key );
	}
}
