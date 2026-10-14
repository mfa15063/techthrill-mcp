<?php
/**
 * Classified SQL statement value object.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Safety;

defined( 'ABSPATH' ) || exit;

/**
 * Result of classifying one SQL statement.
 *
 * Type is one of select|show|describe|explain|insert|update|delete|replace|
 * create|alter|drop|truncate|rename|other.
 */
final class SqlStatement {

	private const READ_TYPES  = array( 'select', 'show', 'describe', 'explain' );
	private const WRITE_TYPES = array( 'insert', 'update', 'delete', 'replace', 'create', 'alter', 'drop', 'truncate', 'rename' );

	/**
	 * Statement type.
	 *
	 * @var string
	 */
	public string $type;

	/**
	 * Whether a top-level WHERE clause is present.
	 *
	 * @var bool
	 */
	public bool $hasWhere;

	/**
	 * Whether a top-level LIMIT clause is present.
	 *
	 * @var bool
	 */
	public bool $hasLimit;

	/**
	 * Target table for update/delete/insert/replace/truncate/drop, otherwise null.
	 *
	 * @var string|null
	 */
	public ?string $table;

	/**
	 * Trimmed SQL with leading comments and the trailing semicolon removed.
	 *
	 * @var string
	 */
	public string $sql;

	/**
	 * @param string      $type     Statement type.
	 * @param bool        $hasWhere Top-level WHERE present.
	 * @param bool        $hasLimit Top-level LIMIT present.
	 * @param string|null $table    Target table, if any.
	 * @param string      $sql      Normalised SQL.
	 */
	public function __construct( string $type, bool $hasWhere, bool $hasLimit, ?string $table, string $sql ) {
		$this->type     = $type;
		$this->hasWhere = $hasWhere;
		$this->hasLimit = $hasLimit;
		$this->table    = $table;
		$this->sql      = $sql;
	}

	/**
	 * Whether the statement only reads data.
	 */
	public function isRead(): bool {
		return in_array( $this->type, self::READ_TYPES, true );
	}

	/**
	 * Whether the statement changes data or schema.
	 */
	public function isWrite(): bool {
		return in_array( $this->type, self::WRITE_TYPES, true );
	}
}
