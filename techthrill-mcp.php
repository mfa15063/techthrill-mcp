<?php
/**
 * Plugin Name:       TechThrill MCP
 * Plugin URI:        https://github.com/mfa15063/techthrill-mcp
 * Description:       Turns your WordPress site into an MCP server so Claude Code or Codex can connect to it.
 * Version:           1.2.3
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            TechThrill
 * Author URI:        https://github.com/mfa15063
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       techthrill-mcp
 *
 * @package TechThrill\MCP
 */

defined( 'ABSPATH' ) || exit;

define( 'TECHTHRILL_MCP_VERSION', '1.2.3' );
define( 'TECHTHRILL_MCP_FILE', __FILE__ );
define( 'TECHTHRILL_MCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'TECHTHRILL_MCP_URL', plugin_dir_url( __FILE__ ) );

require_once TECHTHRILL_MCP_DIR . 'includes/Autoloader.php';

\TechThrill\MCP\Autoloader::register( __DIR__ );

register_activation_hook( __FILE__, array( \TechThrill\MCP\Activator::class, 'activate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		\TechThrill\MCP\Plugin::instance()->boot();
	}
);
