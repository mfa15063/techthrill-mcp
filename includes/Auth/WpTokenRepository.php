<?php
/**
 * Token storage in the WordPress database.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * `$wpdb`-backed TokenRepository for `{prefix}techthrill_mcp_tokens`.
 */
final class WpTokenRepository implements TokenRepository {

	/**
	 * Database handle.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Full table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * @param \wpdb $db WordPress database handle.
	 */
	public function __construct( \wpdb $db ) {
		$this->db    = $db;
		$this->table = $db->prefix . 'techthrill_mcp_tokens';
	}

	/**
	 * Inserts a row and returns its new id.
	 *
	 * @param array $row Row without an id.
	 */
	public function insert( array $row ): int {
		unset( $row['id'] );

		$inserted = $this->db->insert( $this->table, $row, $this->formats( array_keys( $row ) ) );
		if ( false === $inserted ) {
			return 0;
		}

		return (int) $this->db->insert_id;
	}

	/**
	 * Finds all rows whose lookup column equals the given value.
	 *
	 * @param string $lookup First 12 characters of a plain token.
	 * @return array[]
	 */
	public function findByLookup( string $lookup ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE lookup = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$lookup
			),
			ARRAY_A
		);

		return is_array( $rows ) ? array_map( array( $this, 'normalize' ), $rows ) : array();
	}

	/**
	 * Finds the row whose current refresh token has the given SHA-256 hash.
	 *
	 * @param string $hash SHA-256 hex digest of a plain refresh token.
	 */
	public function findByRefreshHash( string $hash ): ?array {
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE refresh_hash = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$hash
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->normalize( $row ) : null;
	}

	/**
	 * Finds a row by id.
	 *
	 * @param int $id Row id.
	 */
	public function find( int $id ): ?array {
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->normalize( $row ) : null;
	}

	/**
	 * Updates columns of one row.
	 *
	 * @param int   $id     Row id.
	 * @param array $fields Column => value.
	 */
	public function update( int $id, array $fields ): void {
		unset( $fields['id'] );
		if ( ! $fields ) {
			return;
		}

		$this->db->update( $this->table, $fields, array( 'id' => $id ), $this->formats( array_keys( $fields ) ), array( '%d' ) );
	}

	/**
	 * Returns every row, newest first.
	 *
	 * @return array[]
	 */
	public function all(): array {
		$rows = $this->db->get_results(
			"SELECT * FROM {$this->table} ORDER BY created_at DESC, id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return is_array( $rows ) ? array_map( array( $this, 'normalize' ), $rows ) : array();
	}

	/**
	 * Deletes one row.
	 *
	 * @param int $id Row id.
	 */
	public function delete( int $id ): void {
		$this->db->delete( $this->table, array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Placeholder formats for the given columns: %d for user_id, %s for the rest.
	 *
	 * @param string[] $columns Column names.
	 * @return string[]
	 */
	private function formats( array $columns ): array {
		return array_map(
			static function ( $column ): string {
				return 'user_id' === $column ? '%d' : '%s';
			},
			$columns
		);
	}

	/**
	 * Casts integer columns, which MySQL returns as strings.
	 *
	 * @param array $row Raw row.
	 */
	private function normalize( array $row ): array {
		foreach ( array( 'id', 'user_id' ) as $column ) {
			if ( isset( $row[ $column ] ) ) {
				$row[ $column ] = (int) $row[ $column ];
			}
		}

		return $row;
	}
}
