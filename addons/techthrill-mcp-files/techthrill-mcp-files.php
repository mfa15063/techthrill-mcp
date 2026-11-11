<?php
/**
 * Plugin Name:       TechThrill MCP - File Tools (Addon)
 * Plugin URI:        https://github.com/mfa15063/techthrill-mcp
 * Description:       Addon for TechThrill MCP: lets an AI client create, edit, rename, replace and back up php, html, css and js files, and add images and fonts, anywhere on the site (wp-config.php and .htaccess included) when WP-CLI or PHP eval is not available. Needs the main TechThrill MCP plugin.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  techthrill-mcp
 * Author:            TechThrill
 * Author URI:        https://github.com/mfa15063
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       techthrill-mcp-files
 *
 * @package TechThrill\MCP\Files
 */

defined( 'ABSPATH' ) || exit;

define( 'TECHTHRILL_MCP_FILES_VERSION', '1.1.0' );

require_once __DIR__ . '/includes/Autoloader.php';
require_once __DIR__ . '/includes/Requirements.php';

\TechThrill\MCP\Files\Autoloader::register( __DIR__ );

\TechThrill\MCP\Files\Bootstrap::init( __FILE__ );

register_activation_hook( __FILE__, array( \TechThrill\MCP\Files\Bootstrap::class, 'onActivate' ) );
