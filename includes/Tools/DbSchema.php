<?php
/**
 * The db_schema tool.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Lists database tables, or describes one table's columns and indexes.
 */
final class DbSchema extends AbstractTool {

	/**
	 * WordPress database handle.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	/**
	 * @param \wpdb $db WordPress database handle.
	 */
	public function __construct( \wpdb $db ) {
		$this->db = $db;
	}

	public function name(): string {
		return 'db_schema';
	}

	public function description(): string {
		return "List database tables with engine and row counts, or describe one table's columns and indexes.";
	}

	public function inputSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'table' => array(
					'type'        => 'string',
					'description' => 'Table name to describe, including its prefix. Omit to list all tables.',
				),
			),
		);
	}

	public function scope(): ?string {
		return 'db:read';
	}

	public function execute( array $args, ToolContext $ctx ): ToolResult {
		$table = isset( $args['table'] ) ? (string) $args['table'] : '';

		if ( '' === $table ) {
			$rows = $this->db->get_results(
				'SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_ROWS AS row_count FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME',
				ARRAY_A
			);
			if ( '' !== (string) $this->db->last_error ) {
				return $this->fail( 'Database error: ' . $this->db->last_error );
			}

			return $this->ok( array( 'tables' => is_array( $rows ) ? $rows : array() ) );
		}

		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $table ) ) {
			return $this->fail( 'Invalid table name.' );
		}

		$columns = $this->db->get_results( "SHOW FULL COLUMNS FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( '' !== (string) $this->db->last_error ) {
			return $this->fail( 'Database error: ' . $this->db->last_error );
		}

		$indexes = $this->db->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( '' !== (string) $this->db->last_error ) {
			return $this->fail( 'Database error: ' . $this->db->last_error );
		}

		$described = array();
		foreach ( is_array( $columns ) ? $columns : array() as $column ) {
			$described[] = array(
				'name'    => $column['Field'] ?? null,
				'type'    => $column['Type'] ?? null,
				'null'    => $column['Null'] ?? null,
				'key'     => $column['Key'] ?? null,
				'default' => $column['Default'] ?? null,
				'extra'   => $column['Extra'] ?? null,
			);
		}

		return $this->ok(
			array(
				'table'   => $table,
				'columns' => $described,
				'indexes' => is_array( $indexes ) ? $indexes : array(),
			)
		);
	}
}
