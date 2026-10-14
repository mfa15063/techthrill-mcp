<?php
namespace TechThrill\MCP\Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\Safety\SafeMode;
use TechThrill\MCP\Safety\SqlClassifier;
use TechThrill\MCP\Tests\Support\FakeWpdb;
use TechThrill\MCP\Tools\DbExecute;
use TechThrill\MCP\Tools\DbQuery;
use TechThrill\MCP\Tools\DbSchema;
use TechThrill\MCP\Tools\ToolContext;

class DbToolsTest extends TestCase {

	private FakeWpdb $db;

	protected function setUp(): void {
		$this->db = new FakeWpdb();
	}

	private function ctx(): ToolContext {
		return new ToolContext( 1, 1, array( 'db:read', 'db:write' ) );
	}

	private function query(): DbQuery {
		return new DbQuery( $this->db, new SqlClassifier() );
	}

	private function execute( bool $safe = true ): DbExecute {
		return new DbExecute( $this->db, new SqlClassifier(), new SafeMode( $safe, 'wp-content/plugins/techthrill-mcp' ) );
	}

	public function test_scopes_and_names(): void {
		$schema = new DbSchema( $this->db );
		$this->assertSame( 'db_query', $this->query()->name() );
		$this->assertSame( 'db:read', $this->query()->scope() );
		$this->assertSame( 'db_execute', $this->execute()->name() );
		$this->assertSame( 'db:write', $this->execute()->scope() );
		$this->assertSame( 'db_schema', $schema->name() );
		$this->assertSame( 'db:read', $schema->scope() );
	}

	public function test_query_rejects_write_statement(): void {
		$result = $this->query()->execute( array( 'sql' => 'DELETE FROM wp_posts WHERE ID = 1' ), $this->ctx() );
		$this->assertTrue( $result->isError() );
		$this->assertSame( 'db_query only runs SELECT, SHOW, DESCRIBE and EXPLAIN. Use db_execute for INSERT, UPDATE, DELETE and schema changes.', $result->text() );
		$this->assertSame( array(), $this->db->queries );
	}

	public function test_query_replaces_prefix_and_appends_limit(): void {
		$this->db->queueResults( array( array( 'ID' => '1' ), array( 'ID' => '2' ), array( 'ID' => '3' ) ) );
		$result = $this->query()->execute( array( 'sql' => 'SELECT * FROM {prefix}posts', 'limit' => 2 ), $this->ctx() );

		$this->assertFalse( $result->isError() );
		$this->assertSame( array( 'SELECT * FROM wp_posts LIMIT 3' ), $this->db->queries );
		$data = json_decode( $result->text(), true );
		$this->assertSame( 2, $data['row_count'] );
		$this->assertTrue( $data['truncated'] );
		$this->assertSame( array( 'ID' ), $data['columns'] );
		$this->assertSame( array( array( 'ID' => '1' ), array( 'ID' => '2' ) ), $data['rows'] );
	}

	public function test_query_not_truncated_when_rows_fit(): void {
		$this->db->queueResults( array( array( 'ID' => '1' ) ) );
		$result = $this->query()->execute( array( 'sql' => 'SELECT ID FROM wp_posts' ), $this->ctx() );
		$data   = json_decode( $result->text(), true );
		$this->assertSame( array( 'SELECT ID FROM wp_posts LIMIT 101' ), $this->db->queries );
		$this->assertFalse( $data['truncated'] );
		$this->assertSame( 1, $data['row_count'] );
	}

	public function test_query_wraps_existing_limit(): void {
		$this->db->queueResults( array( array( 'ID' => '1' ) ) );
		$this->query()->execute( array( 'sql' => 'SELECT * FROM wp_posts LIMIT 5', 'limit' => 100 ), $this->ctx() );
		$this->assertSame( array( 'SELECT * FROM (SELECT * FROM wp_posts LIMIT 5) AS ttm_q LIMIT 101' ), $this->db->queries );
	}

	public function test_query_explicit_limit_above_cap_is_bounded_and_truncated(): void {
		$this->db->queueResults( array( array( 'ID' => '1' ), array( 'ID' => '2' ), array( 'ID' => '3' ) ) );
		$result = $this->query()->execute( array( 'sql' => 'SELECT * FROM {prefix}posts LIMIT 5', 'limit' => 2 ), $this->ctx() );

		$this->assertSame( array( 'SELECT * FROM (SELECT * FROM wp_posts LIMIT 5) AS ttm_q LIMIT 3' ), $this->db->queries );
		$data = json_decode( $result->text(), true );
		$this->assertSame( 2, $data['row_count'] );
		$this->assertCount( 2, $data['rows'] );
		$this->assertTrue( $data['truncated'] );
	}

	public function test_query_runs_classifier_output_not_raw_sql(): void {
		$this->db->queueResults( array() );
		$this->query()->execute( array( 'sql' => '/* c */ SELECT 1;' ), $this->ctx() );
		$this->assertSame( array( 'SELECT 1 LIMIT 101' ), $this->db->queries );
	}

	public function test_query_show_is_not_given_a_limit(): void {
		$this->db->queueResults( array() );
		$this->query()->execute( array( 'sql' => 'SHOW TABLES' ), $this->ctx() );
		$this->assertSame( array( 'SHOW TABLES' ), $this->db->queries );
	}

	public function test_query_surfaces_db_error(): void {
		$this->db->last_error = 'Unknown column x';
		$result               = $this->query()->execute( array( 'sql' => 'SELECT x FROM wp_posts' ), $this->ctx() );
		$this->assertTrue( $result->isError() );
		$this->assertSame( 'Database error: Unknown column x', $result->text() );
	}

	public function test_query_rejects_multiple_statements(): void {
		$result = $this->query()->execute( array( 'sql' => 'SELECT 1; DROP TABLE wp_posts' ), $this->ctx() );
		$this->assertTrue( $result->isError() );
		$this->assertSame( array(), $this->db->queries );
	}

	public function test_execute_rejects_read_statement(): void {
		$result = $this->execute()->execute( array( 'sql' => 'SELECT 1' ), $this->ctx() );
		$this->assertTrue( $result->isError() );
		$this->assertSame( 'db_execute only runs INSERT, UPDATE, DELETE, REPLACE, CREATE, ALTER, DROP, TRUNCATE and RENAME. Use db_query to read data.', $result->text() );
		$this->assertSame( array(), $this->db->queries );
	}

	public function test_execute_refused_by_safe_mode(): void {
		$result = $this->execute( true )->execute( array( 'sql' => 'DELETE FROM {prefix}posts' ), $this->ctx() );
		$this->assertTrue( $result->isError() );
		$this->assertStringStartsWith( 'Refused by safe mode:', $result->text() );
		$this->assertSame( array(), $this->db->queries );
	}

	public function test_execute_needs_confirmation_when_safe_mode_off(): void {
		$result = $this->execute( false )->execute( array( 'sql' => 'DELETE FROM wp_posts' ), $this->ctx() );
		$this->assertTrue( $result->isError() );
		$this->assertSame( array(), $this->db->queries );

		$result = $this->execute( false )->execute( array( 'sql' => 'DELETE FROM wp_posts', 'confirm_destructive' => true ), $this->ctx() );
		$this->assertFalse( $result->isError() );
		$this->assertSame( array( 'DELETE FROM wp_posts' ), $this->db->queries );
	}

	public function test_execute_returns_affected_rows(): void {
		$this->db->rows_affected = 3;
		$result                  = $this->execute()->execute( array( 'sql' => 'UPDATE {prefix}posts SET post_status = "draft" WHERE ID > 5;' ), $this->ctx() );
		$this->assertFalse( $result->isError() );
		$this->assertSame( array( 'UPDATE wp_posts SET post_status = "draft" WHERE ID > 5' ), $this->db->queries );
		$this->assertSame( array( 'affected_rows' => 3, 'insert_id' => 0 ), json_decode( $result->text(), true ) );
	}

	public function test_execute_surfaces_db_error(): void {
		$this->db->queryResult = false;
		$this->db->last_error  = 'Table missing';
		$result                = $this->execute()->execute( array( 'sql' => 'INSERT INTO wp_nope (a) VALUES (1)' ), $this->ctx() );
		$this->assertTrue( $result->isError() );
		$this->assertSame( 'Database error: Table missing', $result->text() );
	}

	public function test_schema_lists_tables_and_describes(): void {
		$schema = new DbSchema( $this->db );

		$this->db->queueResults( array( array( 'name' => 'wp_posts', 'engine' => 'InnoDB', 'row_count' => '10' ) ) );
		$result = $schema->execute( array(), $this->ctx() );
		$this->assertFalse( $result->isError() );
		$this->assertStringContainsString( 'information_schema.TABLES', $this->db->queries[0] );
		$data = json_decode( $result->text(), true );
		$this->assertSame( 'wp_posts', $data['tables'][0]['name'] );

		$this->db->queries = array();
		$this->db->queueResults( array( array( 'Field' => 'ID', 'Type' => 'bigint', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => 'auto_increment', 'Collation' => null ) ) );
		$this->db->queueResults( array( array( 'Table' => 'wp_posts', 'Key_name' => 'PRIMARY', 'Column_name' => 'ID' ) ) );
		$result = $schema->execute( array( 'table' => 'wp_posts' ), $this->ctx() );
		$this->assertFalse( $result->isError() );
		$this->assertSame( array( 'SHOW FULL COLUMNS FROM `wp_posts`', 'SHOW INDEX FROM `wp_posts`' ), $this->db->queries );
		$data = json_decode( $result->text(), true );
		$this->assertSame( 'wp_posts', $data['table'] );
		$this->assertSame(
			array( array( 'name' => 'ID', 'type' => 'bigint', 'null' => 'NO', 'key' => 'PRI', 'default' => null, 'extra' => 'auto_increment' ) ),
			$data['columns']
		);
		$this->assertSame( 'PRIMARY', $data['indexes'][0]['Key_name'] );
	}

	public function test_schema_rejects_invalid_table_name(): void {
		$schema = new DbSchema( $this->db );
		$result = $schema->execute( array( 'table' => 'wp_posts`; DROP TABLE x' ), $this->ctx() );
		$this->assertTrue( $result->isError() );
		$this->assertSame( 'Invalid table name.', $result->text() );
		$this->assertSame( array(), $this->db->queries );
	}

	public function test_execute_reports_insert_id_only_for_inserts(): void {
		$this->db->insert_id = 42;
		$result              = $this->execute()->execute( array( 'sql' => 'UPDATE wp_posts SET post_title = "x" WHERE ID = 1' ), $this->ctx() );
		$this->assertSame( 0, json_decode( $result->text(), true )['insert_id'] );

		$result = $this->execute()->execute( array( 'sql' => 'INSERT INTO wp_posts (post_title) VALUES ("x")' ), $this->ctx() );
		$this->assertSame( 42, json_decode( $result->text(), true )['insert_id'] );
	}

	public function test_execute_failure_without_error_text_has_fallback_message(): void {
		$this->db->queryResult = false;
		$result                = $this->execute()->execute( array( 'sql' => 'INSERT INTO wp_nope (a) VALUES (1)' ), $this->ctx() );
		$this->assertTrue( $result->isError() );
		$this->assertSame( 'Database error: the query failed.', $result->text() );
	}

	public function test_schema_rejects_table_name_with_trailing_newline(): void {
		$schema = new DbSchema( $this->db );
		$result = $schema->execute( array( 'table' => "wp_posts\n" ), $this->ctx() );
		$this->assertTrue( $result->isError() );
		$this->assertSame( 'Invalid table name.', $result->text() );
		$this->assertSame( array(), $this->db->queries );
	}
}
