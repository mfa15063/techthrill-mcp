<?php
/**
 * The db_execute tool.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Tools;

use TechThrill\MCP\Safety\SafeMode;
use TechThrill\MCP\Safety\SqlClassifier;
use TechThrill\MCP\Safety\SqlException;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a write SQL statement, subject to safe mode.
 */
final class DbExecute extends AbstractTool {

	/**
	 * WordPress database handle.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	private SqlClassifier $classifier;

	private SafeMode $safe_mode;

	/**
	 * @param \wpdb         $db         WordPress database handle.
	 * @param SqlClassifier $classifier SQL classifier.
	 * @param SafeMode      $safe_mode  Safe mode state.
	 */
	public function __construct( \wpdb $db, SqlClassifier $classifier, SafeMode $safe_mode ) {
		$this->db         = $db;
		$this->classifier = $classifier;
		$this->safe_mode  = $safe_mode;
	}

	public function name(): string {
		return 'db_execute';
	}

	public function description(): string {
		return 'Run a write SQL statement (INSERT, UPDATE, DELETE, REPLACE, CREATE, ALTER, DROP, TRUNCATE, RENAME). Safe mode may refuse destructive statements; set confirm_destructive to true after the user confirms when safe mode is off.';
	}

	public function inputSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'sql'                 => array(
					'type'        => 'string',
					'description' => 'The SQL statement. Use {prefix} for the table prefix.',
				),
				'confirm_destructive' => array(
					'type'        => 'boolean',
					'description' => 'Set to true only after the user has confirmed a destructive statement.',
					'default'     => false,
				),
			),
			'required'   => array( 'sql' ),
		);
	}

	public function scope(): ?string {
		return 'db:write';
	}

	public function execute( array $args, ToolContext $ctx ): ToolResult {
		$confirmed = (bool) ( $args['confirm_destructive'] ?? false );
		$sql       = str_replace( '{prefix}', $this->db->prefix, (string) ( $args['sql'] ?? '' ) );

		try {
			$statement = $this->classifier->classify( $sql );
		} catch ( SqlException $e ) {
			return $this->fail( $e->getMessage() );
		}

		if ( ! $statement->isWrite() ) {
			return $this->fail( 'db_execute only runs INSERT, UPDATE, DELETE, REPLACE, CREATE, ALTER, DROP, TRUNCATE and RENAME. Use db_query to read data.' );
		}

		$refusal = $this->safe_mode->checkSql( $statement, $confirmed );
		if ( null !== $refusal ) {
			return $this->fail( $refusal->reason() );
		}

		$result = $this->db->query( $statement->sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( false === $result || '' !== (string) $this->db->last_error ) {
			$error = '' !== (string) $this->db->last_error ? $this->db->last_error : 'the query failed.';
			return $this->fail( 'Database error: ' . $error );
		}

		// wpdb only sets insert_id for INSERT and REPLACE and never resets it, so ignore stale values.
		$inserts = in_array( $statement->type, array( 'insert', 'replace' ), true );

		return $this->ok(
			array(
				'affected_rows' => (int) $this->db->rows_affected,
				'insert_id'     => $inserts ? (int) $this->db->insert_id : 0,
			)
		);
	}
}
