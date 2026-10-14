<?php
/**
 * List table for the activity log.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Admin;

use TechThrill\MCP\Logging\AuditLog;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Newest-first view of the audit log, 50 rows per page.
 */
final class ActivityListTable extends \WP_List_Table {

	const PER_PAGE = 50;

	private AuditLog $log;

	/**
	 * Token names by id.
	 *
	 * @var array<int,string>
	 */
	private array $token_names;

	/**
	 * Active filters (`token_id`, `tool`).
	 *
	 * @var array
	 */
	private array $filters;

	/**
	 * @param AuditLog          $log         Audit log.
	 * @param array<int,string> $token_names Token names keyed by id.
	 * @param array             $filters     Optional `token_id` (int) and `tool` (string).
	 */
	public function __construct( AuditLog $log, array $token_names, array $filters ) {
		parent::__construct(
			array(
				'singular' => 'entry',
				'plural'   => 'entries',
				'ajax'     => false,
			)
		);
		$this->log         = $log;
		$this->token_names = $token_names;
		$this->filters     = $filters;
	}

	public function get_columns() {
		return array(
			'time'     => __( 'Time', 'techthrill-mcp' ),
			'token'    => __( 'Token', 'techthrill-mcp' ),
			'tool'     => __( 'Tool', 'techthrill-mcp' ),
			'summary'  => __( 'Summary', 'techthrill-mcp' ),
			'status'   => __( 'Status', 'techthrill-mcp' ),
			'duration' => __( 'Duration (ms)', 'techthrill-mcp' ),
		);
	}

	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$result      = $this->log->query( $this->filters, $this->get_pagenum(), self::PER_PAGE );
		$this->items = $result['rows'];

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	public function no_items() {
		esc_html_e( 'No activity recorded yet.', 'techthrill-mcp' );
	}

	/**
	 * Fallback for columns without a dedicated method; renders nothing.
	 *
	 * @param array  $item        Log row.
	 * @param string $column_name Column key.
	 */
	protected function column_default( $item, $column_name ) {
		return '';
	}

	/**
	 * @param array $item Log row.
	 */
	protected function column_time( $item ) {
		$value = (string) ( $item['created_at'] ?? '' );
		if ( '' === $value ) {
			return '';
		}

		return esc_html( get_date_from_gmt( $value, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) );
	}

	/**
	 * @param array $item Log row.
	 */
	protected function column_token( $item ) {
		$id = (int) ( $item['token_id'] ?? 0 );

		/* translators: %d: token id */
		return esc_html( $this->token_names[ $id ] ?? sprintf( __( 'Deleted token #%d', 'techthrill-mcp' ), $id ) );
	}

	/**
	 * @param array $item Log row.
	 */
	protected function column_tool( $item ) {
		return '<code>' . esc_html( (string) ( $item['tool'] ?? '' ) ) . '</code>';
	}

	/**
	 * @param array $item Log row.
	 */
	protected function column_summary( $item ) {
		return esc_html( (string) ( $item['summary'] ?? '' ) );
	}

	/**
	 * @param array $item Log row.
	 */
	protected function column_status( $item ) {
		$status = (string) ( $item['status'] ?? '' );
		$class  = in_array( $status, array( 'ok', 'error', 'refused' ), true ) ? $status : 'error';

		return '<span class="ttm-status-badge ttm-status-' . esc_attr( $class ) . '">' . esc_html( $status ) . '</span>';
	}

	/**
	 * @param array $item Log row.
	 */
	protected function column_duration( $item ) {
		return esc_html( number_format_i18n( (int) ( $item['duration_ms'] ?? 0 ) ) );
	}
}
