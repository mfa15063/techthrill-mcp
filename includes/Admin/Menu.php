<?php
/**
 * Admin menu, assets and form-handler registration.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Admin;

use TechThrill\MCP\Admin\Pages\Activity;
use TechThrill\MCP\Admin\Pages\Connect;
use TechThrill\MCP\Admin\Pages\Settings;
use TechThrill\MCP\Admin\Pages\Tokens;
use TechThrill\MCP\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Owns every admin hook of the plugin.
 */
final class Menu {

	const HOOK_SUFFIX = 'toplevel_page_techthrill-mcp';

	private Connect $connect;

	private Tokens $tokens;

	private Activity $activity;

	private Settings $settings;

	/**
	 * @param Plugin $plugin Plugin instance.
	 */
	public function __construct( Plugin $plugin ) {
		$this->connect  = new Connect( $plugin, new PharInstaller( $plugin->hostCapabilities() ) );
		$this->tokens   = new Tokens( $plugin );
		$this->activity = new Activity( $plugin );
		$this->settings = new Settings( $plugin );
	}

	/**
	 * Registers the menu, assets, settings and admin-post handlers.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addMenu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_init', array( $this->settings, 'register' ) );

		add_action( 'admin_post_techthrill_mcp_create_token', array( $this->connect, 'handleCreate' ) );
		add_action( 'admin_post_techthrill_mcp_install_phar', array( $this->connect, 'handleInstallPhar' ) );
		add_action( 'admin_post_techthrill_mcp_revoke_token', array( $this->tokens, 'handleRevoke' ) );
		add_action( 'admin_post_techthrill_mcp_delete_token', array( $this->tokens, 'handleDelete' ) );
		add_action( 'admin_post_techthrill_mcp_clear_log', array( $this->activity, 'handleClear' ) );
	}

	/**
	 * Adds the top-level menu entry.
	 */
	public function addMenu(): void {
		add_menu_page(
			'TechThrill MCP',
			'TechThrill MCP',
			'manage_options',
			Flash::PAGE_SLUG,
			array( $this, 'render' ),
			'dashicons-rest-api',
			81
		);
	}

	/**
	 * Loads the stylesheet and script on this plugin's page only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue( $hook ): void {
		if ( self::HOOK_SUFFIX !== $hook ) {
			return;
		}

		wp_enqueue_style( 'techthrill-mcp-admin', plugins_url( 'includes/Admin/assets/admin.css', TECHTHRILL_MCP_FILE ), array(), TECHTHRILL_MCP_VERSION );
		wp_enqueue_script( 'techthrill-mcp-admin', plugins_url( 'includes/Admin/assets/admin.js', TECHTHRILL_MCP_FILE ), array(), TECHTHRILL_MCP_VERSION, true );
	}

	/**
	 * Prints the plugin page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'techthrill-mcp' ), '', array( 'response' => 403 ) );
		}

		$tabs = array(
			'connect'  => __( 'Connect', 'techthrill-mcp' ),
			'tokens'   => __( 'Tokens', 'techthrill-mcp' ),
			'activity' => __( 'Activity', 'techthrill-mcp' ),
			'settings' => __( 'Settings', 'techthrill-mcp' ),
		);

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'connect'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection.
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'connect';
		}

		echo '<div class="wrap ttm-wrap"><h1>' . esc_html__( 'TechThrill MCP', 'techthrill-mcp' ) . '</h1>';

		$notice = Flash::takeNotice();
		if ( null !== $notice ) {
			echo '<div class="notice notice-' . esc_attr( $notice['type'] ) . ' is-dismissible"><p>' . esc_html( $notice['text'] ) . '</p></div>';
		}

		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			echo '<a href="' . esc_url( Flash::tabUrl( $slug ) ) . '" class="nav-tab' . ( $slug === $tab ? ' nav-tab-active' : '' ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';

		echo '<div class="ttm-tab ttm-tab-' . esc_attr( $tab ) . '">';
		switch ( $tab ) {
			case 'tokens':
				$this->tokens->render();
				break;
			case 'activity':
				$this->activity->render();
				break;
			case 'settings':
				$this->settings->render();
				break;
			default:
				$this->connect->render();
		}
		echo '</div></div>';
	}
}
