<?php
/**
 * WordPress-backed option gateway.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Uses add_option, get_option and delete_option; names() queries the options table.
 */
final class WpOptionsGateway implements OptionsGateway {

	private \wpdb $db;

	/**
	 * @param \wpdb $db WordPress database handle.
	 */
	public function __construct( \wpdb $db ) {
		$this->db = $db;
	}

	public function get( string $name ): ?array {
		$value = get_option( $name, null );

		return is_array( $value ) ? $value : null;
	}

	public function add( string $name, array $value ): bool {
		return (bool) add_option( $name, $value, '', 'no' );
	}

	public function update( string $name, array $value ): bool {
		return (bool) update_option( $name, $value, false );
	}

	public function delete( string $name ): bool {
		return (bool) delete_option( $name );
	}

	public function names( string $prefix ): array {
		$names = $this->db->get_col(
			$this->db->prepare(
				"SELECT option_name FROM {$this->db->options} WHERE option_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->db->esc_like( $prefix ) . '%'
			)
		);

		return is_array( $names ) ? array_map( 'strval', $names ) : array();
	}
}
