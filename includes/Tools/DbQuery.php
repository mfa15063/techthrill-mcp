<?php
/**
 * The db_query tool.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Tools;

use TechThrill\MCP\Safety\SqlClassifier;
use TechThrill\MCP\Safety\SqlException;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a read-only SQL statement and returns the rows.
 */
final class DbQuery extends AbstractTool {

	private const DEFAULT_LIMIT = 100;

	/**
	 * WordPress database handle.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	private SqlClassifier $classifier;

	/**
	 * @param \wpdb         $db         WordPress database handle.
	 * @param SqlClassifier $classifier SQL classifier.
	 */
	public function __construct( \wpdb $db, SqlClassifier $classifier ) {
		$this->db         = $db;
		$this->classifier = $classifier;
	}

	public function name(): string {
		return 'db_query';
	}

	public function description(): string {
		return 'Run a read-only SQL statement (SELECT, SHOW, DESCRIBE, EXPLAIN) against the WordPress database. Use {prefix} for the table prefix, e.g. SELECT * FROM {prefix}posts. Results are capped by limit.';
	}

	public function inputSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'sql'   => array(
					'type'        => 'string',
					'description' => 'The SQL statement. Use {prefix} for the table prefix.',
				),
				'limit' => array(
					'type'        => 'integer',
					'description' => 'Maximum rows to return.',
					'minimum'     => 1,
					'maximum'     => 500,
					'default'     => self::DEFAULT_LIMIT,
				),
			),
			'required'   => array( 'sql' ),
		);
	}

	public function scope(): ?string {
		return 'db:read';
	}

	public function execute( array $args, ToolContext $ctx ): ToolResult {
		$limit = (int) ( $args['limit'] ?? self::DEFAULT_LIMIT );
		$sql   = str_replace( '{prefix}', $this->db->prefix, (string) ( $args['sql'] ?? '' ) );

		try {
			$statement = $this->classifier->classify( $sql );
		} catch ( SqlException $e ) {
			return $this->fail( $e->getMessage() );
		}

		if ( ! $statement->isRead() ) {
			return $this->fail( 'db_query only runs SELECT, SHOW, DESCRIBE and EXPLAIN. Use db_execute for INSERT, UPDATE, DELETE and schema changes.' );
		}

		$run = $statement->sql;
		if ( 'select' === $statement->type && ! $statement->hasLimit ) {
			$run .= ' LIMIT ' . ( $limit + 1 );
		}

		$rows = $this->db->get_results( $run, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( '' !== (string) $this->db->last_error ) {
			return $this->fail( 'Database error: ' . $this->db->last_error );
		}

		$rows      = is_array( $rows ) ? $rows : array();
		$truncated = count( $rows ) > $limit;
		if ( $truncated ) {
			$rows = array_slice( $rows, 0, $limit );
		}

		return $this->ok(
			array(
				'columns'   => array_keys( $rows[0] ?? array() ),
				'rows'      => $rows,
				'row_count' => count( $rows ),
				'truncated' => $truncated,
			)
		);
	}
}
