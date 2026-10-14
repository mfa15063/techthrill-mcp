<?php
/**
 * Audit log of tool calls.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Logging;

use TechThrill\MCP\Tools\ToolResult;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the `{prefix}techthrill_mcp_log` table.
 */
final class AuditLog {

	const STATUSES = array( 'ok', 'error', 'refused' );

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
		$this->table = $db->prefix . 'techthrill_mcp_log';
	}

	/**
	 * Maps a tool result to a log status.
	 *
	 * @param ToolResult $result Tool result.
	 */
	public static function statusFor( ToolResult $result ): string {
		if ( ! $result->isError() ) {
			return 'ok';
		}

		$text = $result->text();
		if ( 0 === strpos( $text, 'Refused by safe mode:' ) || 0 === strpos( $text, 'Confirmation required:' ) ) {
			return 'refused';
		}

		return 'error';
	}

	/**
	 * Writes one log row.
	 *
	 * @param int    $tokenId    Token id.
	 * @param string $tool       Tool name.
	 * @param string $summary    One-line argument summary.
	 * @param string $status     ok, error or refused.
	 * @param int    $durationMs Execution time in milliseconds.
	 */
	public function record( int $tokenId, string $tool, string $summary, string $status, int $durationMs ): void {
		$this->db->insert(
			$this->table,
			array(
				'token_id'    => $tokenId,
				'tool'        => mb_substr( $tool, 0, 50 ),
				'summary'     => mb_substr( $summary, 0, 500 ),
				'status'      => in_array( $status, self::STATUSES, true ) ? $status : 'error',
				'duration_ms' => max( 0, $durationMs ),
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Returns a page of rows (newest first) and the total matching count.
	 *
	 * @param array $filters Optional `token_id` (int) and `tool` (string).
	 * @param int   $page    1-based page number.
	 * @param int   $perPage Rows per page.
	 * @return array{rows: array[], total: int}
	 */
	public function query( array $filters, int $page, int $perPage ): array {
		$perPage = max( 1, $perPage );
		$offset  = ( max( 1, $page ) - 1 ) * $perPage;

		$where  = array();
		$params = array();

		if ( ! empty( $filters['token_id'] ) ) {
			$where[]  = 'token_id = %d';
			$params[] = (int) $filters['token_id'];
		}
		if ( isset( $filters['tool'] ) && '' !== $filters['tool'] ) {
			$where[]  = 'tool = %s';
			$params[] = (string) $filters['tool'];
		}
		$clause = $where ? ' WHERE ' . implode( ' AND ', $where ) : '';

		$count_sql = "SELECT COUNT(*) FROM {$this->table}{$clause}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $params ) {
			$count_sql = $this->db->prepare( $count_sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$total = (int) $this->db->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$rows_sql = $this->db->prepare(
			"SELECT * FROM {$this->table}{$clause} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			array_merge( $params, array( $perPage, $offset ) )
		);
		$rows     = $this->db->get_results( $rows_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Deletes rows older than the given number of days.
	 *
	 * @param int $days Retention in days.
	 * @return int Rows deleted.
	 */
	public function prune( int $days ): int {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 0, $days ) * DAY_IN_SECONDS );

		$deleted = $this->db->query(
			$this->db->prepare(
				"DELETE FROM {$this->table} WHERE created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			)
		);

		return (int) $deleted;
	}

	/**
	 * Removes every log row.
	 */
	public function clear(): void {
		// DELETE rather than TRUNCATE: restricted DB users often lack the DROP privilege.
		$this->db->query( "DELETE FROM {$this->table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Distinct tool names present in the log, alphabetical.
	 *
	 * @return string[]
	 */
	public function distinctTools(): array {
		$tools = $this->db->get_col( "SELECT DISTINCT tool FROM {$this->table} ORDER BY tool ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $tools ) ? array_map( 'strval', $tools ) : array();
	}
}
