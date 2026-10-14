<?php
/**
 * List table for connection tokens.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Admin;

use TechThrill\MCP\Auth\TokenRepository;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Shows every token. The stored hash and lookup are never rendered.
 */
final class TokensListTable extends \WP_List_Table {

	private TokenRepository $repository;

	/**
	 * @param TokenRepository $repository Token storage.
	 */
	public function __construct( TokenRepository $repository ) {
		parent::__construct(
			array(
				'singular' => 'token',
				'plural'   => 'tokens',
				'ajax'     => false,
			)
		);
		$this->repository = $repository;
	}

	public function get_columns() {
		return array(
			'name'         => __( 'Name', 'techthrill-mcp' ),
			'scopes'       => __( 'Permissions', 'techthrill-mcp' ),
			'created_by'   => __( 'Created by', 'techthrill-mcp' ),
			'created_at'   => __( 'Created', 'techthrill-mcp' ),
			'expires_at'   => __( 'Expires', 'techthrill-mcp' ),
			'last_used_at' => __( 'Last used', 'techthrill-mcp' ),
			'status'       => __( 'Status', 'techthrill-mcp' ),
		);
	}

	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->items           = $this->repository->all();
	}

	public function no_items() {
		esc_html_e( 'No connections yet. Create one on the Connect tab.', 'techthrill-mcp' );
	}

	/**
	 * Fallback for columns without a dedicated method; renders nothing.
	 *
	 * @param array  $item        Token row.
	 * @param string $column_name Column key.
	 */
	protected function column_default( $item, $column_name ) {
		return '';
	}

	/**
	 * @param array $item Token row.
	 */
	protected function column_name( $item ) {
		$id      = (int) $item['id'];
		$status  = TokenStatus::of( $item, time() );
		$actions = array();

		if ( TokenStatus::ACTIVE === $status ) {
			$actions['revoke'] = '<a href="' . esc_url( $this->actionUrl( 'techthrill_mcp_revoke_token', $id ) ) . '" data-ttm-confirm="' . esc_attr__( 'Revoke this token? Clients using it will stop working immediately.', 'techthrill-mcp' ) . '">' . esc_html__( 'Revoke', 'techthrill-mcp' ) . '</a>';
		} else {
			$actions['delete'] = '<a href="' . esc_url( $this->actionUrl( 'techthrill_mcp_delete_token', $id ) ) . '" class="submitdelete" data-ttm-confirm="' . esc_attr__( 'Delete this token permanently?', 'techthrill-mcp' ) . '">' . esc_html__( 'Delete', 'techthrill-mcp' ) . '</a>';
		}

		return '<strong>' . esc_html( (string) $item['name'] ) . '</strong>' . $this->row_actions( $actions );
	}

	/**
	 * @param array $item Token row.
	 */
	protected function column_scopes( $item ) {
		$scopes = $item['scopes'] ?? '';
		if ( ! is_array( $scopes ) ) {
			$scopes = array_filter( array_map( 'trim', explode( ',', (string) $scopes ) ), 'strlen' );
		}

		$badges = '';
		foreach ( $scopes as $scope ) {
			$badges .= '<span class="ttm-badge">' . esc_html( (string) $scope ) . '</span> ';
		}

		return $badges;
	}

	/**
	 * @param array $item Token row.
	 */
	protected function column_created_by( $item ) {
		$user_id = (int) ( $item['user_id'] ?? 0 );
		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;

		return esc_html( $user ? (string) $user->display_name : '#' . $user_id );
	}

	/**
	 * @param array $item Token row.
	 */
	protected function column_created_at( $item ) {
		return esc_html( $this->formatDate( $item['created_at'] ?? null, '' ) );
	}

	/**
	 * @param array $item Token row.
	 */
	protected function column_expires_at( $item ) {
		return esc_html( $this->formatDate( $item['expires_at'] ?? null, __( 'Never', 'techthrill-mcp' ) ) );
	}

	/**
	 * @param array $item Token row.
	 */
	protected function column_last_used_at( $item ) {
		return esc_html( $this->formatDate( $item['last_used_at'] ?? null, __( 'Never', 'techthrill-mcp' ) ) );
	}

	/**
	 * @param array $item Token row.
	 */
	protected function column_status( $item ) {
		$status = TokenStatus::of( $item, time() );
		$labels = array(
			TokenStatus::ACTIVE  => __( 'Active', 'techthrill-mcp' ),
			TokenStatus::EXPIRED => __( 'Expired', 'techthrill-mcp' ),
			TokenStatus::REVOKED => __( 'Revoked', 'techthrill-mcp' ),
		);

		return '<span class="ttm-status-badge ttm-token-' . esc_attr( $status ) . '">' . esc_html( $labels[ $status ] ) . '</span>';
	}

	private function actionUrl( string $action, int $id ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . $action . '&id=' . $id ), $action );
	}

	/**
	 * @param string|null $value    UTC datetime.
	 * @param string      $fallback Text for an empty value.
	 */
	private function formatDate( $value, string $fallback ): string {
		if ( empty( $value ) ) {
			return $fallback;
		}

		return get_date_from_gmt( (string) $value, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
	}
}
