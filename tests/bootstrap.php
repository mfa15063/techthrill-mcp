<?php
/**
 * PHPUnit bootstrap: loads the plugin without WordPress, using function stubs.
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fixtures/wp-root/' );
}

// Stubs needed for the plugin main file to load.
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		return true;
	}
}
if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook( $file, $callback ) {
	}
}
if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( $file ) {
		return 'http://example.test/wp-content/plugins/techthrill-mcp/';
	}
}
if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( $file ) {
		return rtrim( str_replace( '\\', '/', dirname( $file ) ), '/' ) . '/';
	}
}

require_once dirname( __DIR__ ) . '/techthrill-mcp.php';

// Stubs for WordPress helpers used by plugin classes under test.
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return $text;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $flags = 0 ) {
		return json_encode( $data, $flags );
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		return $value;
	}
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $path ) {
		return rtrim( $path, '/\\' ) . '/';
	}
}
if ( ! function_exists( 'wp_normalize_path' ) ) {
	function wp_normalize_path( $path ) {
		$path = str_replace( '\\', '/', $path );
		return preg_replace( '|(?<=.)/+|', '/', $path );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}

// Stubs for the REST layer.
require_once __DIR__ . '/Support/wp-rest-stubs.php';

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $namespace, $route, $args = array() ) {
		$GLOBALS['ttm_registered_routes'][] = array( $namespace, $route, $args );
		return true;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['ttm_filters'][ $hook ][] = array( $callback, $priority );
		return true;
	}
}
if ( ! function_exists( 'wp_set_current_user' ) ) {
	function wp_set_current_user( $id ) {
		$GLOBALS['ttm_current_user'] = (int) $id;
		return null;
	}
}
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '' ) {
		return 'http://example.test/wp-json/' . ltrim( $path, '/' );
	}
}
if ( ! function_exists( '__return_true' ) ) {
	function __return_true() {
		return true;
	}
}
if ( ! function_exists( 'user_can' ) ) {
	function user_can( $user, $capability ) {
		return false;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return $default;
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}
