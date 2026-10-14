<?php
namespace TechThrill\MCP\Tests\Unit\Safety;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\Safety\SqlClassifier;
use TechThrill\MCP\Safety\SqlException;
use TechThrill\MCP\Safety\SqlStatement;

class SqlClassifierTest extends TestCase {
	private function classify( string $sql ): SqlStatement {
		return ( new SqlClassifier() )->classify( $sql );
	}

	public function test_plain_select(): void {
		$s = $this->classify( 'SELECT * FROM wp_posts' );
		$this->assertSame( 'select', $s->type );
		$this->assertFalse( $s->hasWhere );
		$this->assertFalse( $s->hasLimit );
		$this->assertNull( $s->table );
		$this->assertSame( 'SELECT * FROM wp_posts', $s->sql );
	}

	public function test_select_with_limit_and_trailer(): void {
		$s = $this->classify( '  select 1 limit 5;' );
		$this->assertSame( 'select', $s->type );
		$this->assertFalse( $s->hasWhere );
		$this->assertTrue( $s->hasLimit );
		$this->assertNull( $s->table );
		$this->assertSame( 'select 1 limit 5', $s->sql );
	}

	public function test_with_after_line_comment_is_select(): void {
		$s = $this->classify( "-- c\nWITH x AS (SELECT 1) SELECT * FROM x" );
		$this->assertSame( 'select', $s->type );
		$this->assertFalse( $s->hasWhere );
		$this->assertFalse( $s->hasLimit );
		$this->assertNull( $s->table );
		$this->assertSame( 'WITH x AS (SELECT 1) SELECT * FROM x', $s->sql );
	}

	public function test_show_after_block_comment(): void {
		$s = $this->classify( '/* c */ SHOW TABLES' );
		$this->assertSame( 'show', $s->type );
		$this->assertSame( 'SHOW TABLES', $s->sql );
	}

	public function test_desc_and_describe(): void {
		$this->assertSame( 'describe', $this->classify( 'DESC wp_posts' )->type );
		$this->assertSame( 'describe', $this->classify( 'DESCRIBE wp_posts' )->type );
	}

	public function test_update_with_where_ignores_where_inside_string(): void {
		$s = $this->classify( "UPDATE wp_options SET v='a;b WHERE' WHERE id=1" );
		$this->assertSame( 'update', $s->type );
		$this->assertTrue( $s->hasWhere );
		$this->assertSame( 'wp_options', $s->table );
	}

	public function test_update_without_where(): void {
		$s = $this->classify( "UPDATE wp_options SET v='x'" );
		$this->assertSame( 'update', $s->type );
		$this->assertFalse( $s->hasWhere );
		$this->assertSame( 'wp_options', $s->table );
	}

	public function test_where_only_inside_string_is_not_a_where(): void {
		$s = $this->classify( "UPDATE wp_options SET v='x WHERE id=1'" );
		$this->assertFalse( $s->hasWhere );
	}

	public function test_delete_with_where_in_subquery(): void {
		$s = $this->classify( 'DELETE FROM `wp_posts` WHERE id IN (SELECT id FROM t WHERE x=1)' );
		$this->assertSame( 'delete', $s->type );
		$this->assertTrue( $s->hasWhere );
		$this->assertSame( 'wp_posts', $s->table );
	}

	public function test_where_only_in_subquery_is_not_top_level(): void {
		$s = $this->classify( 'DELETE t FROM wp_posts t JOIN (SELECT id FROM x WHERE a=1) y ON y.id=t.id' );
		$this->assertFalse( $s->hasWhere );
	}

	public function test_delete_without_where(): void {
		$s = $this->classify( 'DELETE FROM wp_posts' );
		$this->assertSame( 'delete', $s->type );
		$this->assertFalse( $s->hasWhere );
		$this->assertSame( 'wp_posts', $s->table );
	}

	public function test_truncate(): void {
		$s = $this->classify( 'TRUNCATE TABLE wp_terms' );
		$this->assertSame( 'truncate', $s->type );
		$this->assertSame( 'wp_terms', $s->table );
		$this->assertSame( 'wp_terms', $this->classify( 'TRUNCATE wp_terms' )->table );
	}

	public function test_drop_table_if_exists(): void {
		$s = $this->classify( 'DROP TABLE IF EXISTS wp_x' );
		$this->assertSame( 'drop', $s->type );
		$this->assertSame( 'wp_x', $s->table );
	}

	public function test_insert_with_semicolon_in_string(): void {
		$s = $this->classify( "INSERT INTO wp_x (a) VALUES ('1;2')" );
		$this->assertSame( 'insert', $s->type );
		$this->assertSame( 'wp_x', $s->table );
	}

	public function test_multiple_statements_throw(): void {
		$this->expectException( SqlException::class );
		$this->classify( 'SELECT 1; SELECT 2' );
	}

	public function test_empty_input_throws(): void {
		$this->expectException( SqlException::class );
		$this->classify( '' );
	}

	public function test_unknown_statement_is_other(): void {
		$s = $this->classify( 'GRANT ALL ON *.* TO x' );
		$this->assertSame( 'other', $s->type );
	}

	public function test_is_read_and_is_write(): void {
		foreach ( array( 'SELECT 1', 'SHOW TABLES', 'DESCRIBE t', 'EXPLAIN SELECT 1' ) as $sql ) {
			$s = $this->classify( $sql );
			$this->assertTrue( $s->isRead(), $sql );
			$this->assertFalse( $s->isWrite(), $sql );
		}
		$writes = array(
			'INSERT INTO t VALUES (1)',
			'UPDATE t SET a=1',
			'DELETE FROM t',
			'REPLACE INTO t VALUES (1)',
			'CREATE TABLE t (a INT)',
			'ALTER TABLE t ADD b INT',
			'DROP TABLE t',
			'TRUNCATE t',
			'RENAME TABLE a TO b',
		);
		foreach ( $writes as $sql ) {
			$s = $this->classify( $sql );
			$this->assertTrue( $s->isWrite(), $sql );
			$this->assertFalse( $s->isRead(), $sql );
		}
		$other = $this->classify( 'GRANT ALL ON *.* TO x' );
		$this->assertFalse( $other->isRead() );
		$this->assertFalse( $other->isWrite() );
	}

	public function test_replace_into(): void {
		$s = $this->classify( 'REPLACE INTO wp_x VALUES (1)' );
		$this->assertSame( 'replace', $s->type );
		$this->assertSame( 'wp_x', $s->table );
	}

	public function test_keyword_is_case_insensitive(): void {
		$s = $this->classify( 'dElEtE fRoM wp_x wHeRe a=1' );
		$this->assertSame( 'delete', $s->type );
		$this->assertTrue( $s->hasWhere );
	}

	public function test_schema_qualified_table_keeps_final_part(): void {
		$this->assertSame( 'wp_x', $this->classify( 'DELETE FROM `db`.`wp_x`' )->table );
		$this->assertSame( 'wp_x', $this->classify( 'UPDATE db.wp_x SET a=1' )->table );
	}

	public function test_insert_modifiers_are_skipped(): void {
		$this->assertSame( 'wp_x', $this->classify( 'INSERT IGNORE INTO wp_x VALUES (1)' )->table );
		$this->assertSame( 'wp_x', $this->classify( 'INSERT LOW_PRIORITY INTO wp_x VALUES (1)' )->table );
	}

	public function test_limit_inside_subquery_does_not_count(): void {
		$s = $this->classify( 'SELECT * FROM (SELECT id FROM t LIMIT 5) x' );
		$this->assertFalse( $s->hasLimit );
	}

	public function test_limit_inside_string_does_not_count(): void {
		$this->assertFalse( $this->classify( "SELECT 'limit 5'" )->hasLimit );
	}

	public function test_trailer_followed_by_whitespace_or_comment_is_allowed(): void {
		$this->assertSame( 'SELECT 1', $this->classify( "SELECT 1;  \n\t" )->sql );
		$this->assertSame( 'SELECT 1', $this->classify( 'SELECT 1; -- done' )->sql );
	}

	public function test_semicolon_after_trailer_throws(): void {
		$this->expectException( SqlException::class );
		$this->classify( 'SELECT 1;;' );
	}

	public function test_comment_only_input_throws(): void {
		$this->expectException( SqlException::class );
		$this->classify( '/* nothing */ -- here' );
	}

	public function test_lone_semicolon_throws(): void {
		$this->expectException( SqlException::class );
		$this->classify( ';' );
	}

	public function test_second_statement_hidden_after_comment_throws(): void {
		$this->expectException( SqlException::class );
		$this->classify( 'SELECT 1; /* x */ DROP TABLE wp_x' );
	}

	public function test_comments_between_keywords_do_not_hide_where(): void {
		$s = $this->classify( 'DELETE /* a */ FROM /* b */ wp_x /* c */ WHERE /* d */ id = 1' );
		$this->assertSame( 'delete', $s->type );
		$this->assertTrue( $s->hasWhere );
		$this->assertSame( 'wp_x', $s->table );
	}

	public function test_where_inside_comment_does_not_count(): void {
		$this->assertFalse( $this->classify( 'DELETE FROM wp_x /* WHERE id=1 */' )->hasWhere );
		$this->assertFalse( $this->classify( "DELETE FROM wp_x -- WHERE id=1\n" )->hasWhere );
		$this->assertFalse( $this->classify( "DELETE FROM wp_x # WHERE id=1\n" )->hasWhere );
	}

	public function test_semicolon_inside_comment_is_not_a_separator(): void {
		$s = $this->classify( 'SELECT 1 /* ; DROP TABLE x */' );
		$this->assertSame( 'select', $s->type );
	}

	public function test_mysql_executable_comment_throws(): void {
		$this->expectException( SqlException::class );
		$this->classify( 'SELECT 1 /*!50000 ; DROP TABLE wp_x */' );
	}

	public function test_leading_mysql_executable_comment_throws(): void {
		$this->expectException( SqlException::class );
		$this->classify( '/*!50000 DROP TABLE wp_x */' );
	}

	public function test_semicolon_inside_backticked_identifier(): void {
		$s = $this->classify( 'DELETE FROM `we;ird` WHERE a=1' );
		$this->assertSame( 'delete', $s->type );
		$this->assertSame( 'we;ird', $s->table );
	}

	public function test_escaped_and_doubled_quotes(): void {
		$s = $this->classify( "UPDATE t SET a='it\\'s; WHERE', b='it''s; WHERE'" );
		$this->assertFalse( $s->hasWhere );
		$s = $this->classify( 'UPDATE t SET a="x\\";y" WHERE a=1' );
		$this->assertTrue( $s->hasWhere );
	}

	public function test_tautological_where_is_not_a_where(): void {
		$forms = array(
			'DELETE FROM wp_posts WHERE 1',
			'DELETE FROM wp_posts WHERE TRUE',
			'delete from wp_posts where true',
			'DELETE FROM wp_posts WHERE 1=1',
			'DELETE FROM wp_posts WHERE 1 = 1',
			'UPDATE wp_posts SET a=1 WHERE 1 = 1 LIMIT 10',
			'UPDATE wp_posts SET a=1 WHERE 1 ORDER BY ID',
			'SELECT a FROM t WHERE TRUE GROUP BY a HAVING COUNT(*) > 1',
			'DELETE FROM wp_posts WHERE 1;',
		);
		foreach ( $forms as $sql ) {
			$this->assertFalse( $this->classify( $sql )->hasWhere, $sql );
		}
	}

	public function test_real_condition_next_to_a_tautology_is_a_where(): void {
		$this->assertTrue( $this->classify( 'DELETE FROM wp_posts WHERE 1 AND id=5' )->hasWhere );
		$this->assertTrue( $this->classify( 'DELETE FROM wp_posts WHERE 1=2' )->hasWhere );
		$this->assertTrue( $this->classify( "DELETE FROM wp_posts WHERE '1'" )->hasWhere );
		$this->assertTrue( $this->classify( 'DELETE FROM wp_posts WHERE id = 1 LIMIT 1' )->hasWhere );
		$this->assertTrue( $this->classify( 'DELETE FROM wp_posts WHERE 11' )->hasWhere );
	}

	public function test_unterminated_quote_terminates(): void {
		$this->assertSame( 'select', $this->classify( "SELECT 'abc; DROP TABLE x" )->type );
		$this->assertSame( 'select', $this->classify( 'SELECT `abc' )->type );
		$this->assertSame( 'select', $this->classify( 'SELECT 1 /* never closed; DROP TABLE x' )->type );
		$this->assertSame( 'select', $this->classify( "SELECT 'abc\\" )->type );
	}

	public function test_with_cte_cannot_disguise_a_write_as_select(): void {
		$s = $this->classify( 'WITH x AS (SELECT 1) DELETE FROM wp_posts' );
		$this->assertSame( 'delete', $s->type );
		$this->assertFalse( $s->hasWhere );
		$this->assertSame( 'wp_posts', $s->table );
		$this->assertSame( 'update', $this->classify( 'WITH x AS (SELECT 1) UPDATE wp_posts SET a=1' )->type );
	}

	public function test_with_without_main_statement_is_other(): void {
		$this->assertSame( 'other', $this->classify( 'WITH x AS (SELECT 1)' )->type );
	}

	public function test_select_into_outfile_is_other(): void {
		$this->assertSame( 'other', $this->classify( "SELECT * FROM wp_users INTO OUTFILE '/tmp/x'" )->type );
		$this->assertSame( 'other', $this->classify( "SELECT 1 INTO DUMPFILE '/tmp/x'" )->type );
		$this->assertSame( 'select', $this->classify( 'SELECT 1 INTO @v' )->type );
	}

	public function test_explain_analyze_is_other(): void {
		$this->assertSame( 'other', $this->classify( 'EXPLAIN ANALYZE DELETE FROM wp_posts' )->type );
		$this->assertSame( 'explain', $this->classify( 'EXPLAIN DELETE FROM wp_posts' )->type );
	}

	public function test_parenthesised_statement_is_other(): void {
		$this->assertSame( 'other', $this->classify( '(SELECT 1)' )->type );
	}

	public function test_hash_comment_before_statement(): void {
		$this->assertSame( 'select', $this->classify( "# c\nSELECT 1" )->type );
	}

	public function test_double_dash_without_space_is_not_a_comment(): void {
		$s = $this->classify( 'DELETE FROM t WHERE a=1--1' );
		$this->assertTrue( $s->hasWhere );
	}

	public function test_drop_non_table_has_no_table(): void {
		$s = $this->classify( 'DROP DATABASE wp' );
		$this->assertSame( 'drop', $s->type );
		$this->assertNull( $s->table );
	}

	public function test_drop_temporary_table(): void {
		$this->assertSame( 'wp_x', $this->classify( 'DROP TEMPORARY TABLE IF EXISTS wp_x' )->table );
	}

	public function test_multi_table_delete_uses_from_target(): void {
		$s = $this->classify( 'DELETE a FROM wp_a a JOIN wp_b b ON a.id=b.id' );
		$this->assertSame( 'wp_a', $s->table );
	}

	public function test_desc_and_describe_analyze_are_other(): void {
		$sql = 'DELETE p FROM wp_posts p JOIN wp_postmeta m ON m.post_id=p.ID';
		foreach ( array( 'DESC', 'DESCRIBE', 'desc', 'EXPLAIN' ) as $kw ) {
			$s = $this->classify( $kw . ' ANALYZE ' . $sql );
			$this->assertSame( 'other', $s->type, $kw );
			$this->assertFalse( $s->isRead(), $kw );
		}
		$this->assertSame( 'describe', $this->classify( 'DESC wp_posts' )->type );
	}

	public function test_mariadb_executable_comment_throws_everywhere(): void {
		$cases = array(
			"SELECT user_pass FROM wp_users /*M! INTO OUTFILE '/tmp/x' */",
			'/*M! DELETE FROM wp_posts */ SELECT 1',
			'/*M!100100 DELETE FROM wp_posts */ SELECT 1',
			'/*m! DELETE FROM wp_posts */ SELECT 1',
			'/*M! DELETE FROM wp_posts WHERE ID IN ( */ SELECT post_id FROM wp_postmeta)',
			'SELECT 1 /*!50000 + 1 */',
		);
		foreach ( $cases as $sql ) {
			try {
				$this->classify( $sql );
				$this->fail( 'Expected SqlException for: ' . $sql );
			} catch ( SqlException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	public function test_ordinary_comments_starting_with_m_are_fine(): void {
		$this->assertSame( 'select', $this->classify( 'SELECT 1 /* M is for me */' )->type );
		$this->assertSame( 'select', $this->classify( 'SELECT 1 /*M*/' )->type );
	}

	public function test_trailing_comments_are_dropped_from_sql(): void {
		$this->assertSame( 'SELECT 1', $this->classify( 'SELECT 1 -- x' )->sql );
		$this->assertSame( 'SELECT 1', $this->classify( 'SELECT 1 # y' )->sql );
		$this->assertSame( 'SELECT 1', $this->classify( 'SELECT 1 /* z */' )->sql );
		$this->assertSame( 'SELECT 1', $this->classify( "SELECT 1 -- x
;" )->sql );
		$this->assertSame( 'SELECT * FROM wp_posts', $this->classify( "SELECT * FROM wp_posts -- x
" )->sql );
		$this->assertSame( "SELECT 'a -- b'", $this->classify( "SELECT 'a -- b' -- c" )->sql );
	}

	public function test_statement_is_value_object(): void {
		$s = new SqlStatement( 'select', true, false, 'wp_x', 'SELECT 1' );
		$this->assertSame( 'select', $s->type );
		$this->assertTrue( $s->hasWhere );
		$this->assertFalse( $s->hasLimit );
		$this->assertSame( 'wp_x', $s->table );
		$this->assertSame( 'SELECT 1', $s->sql );
	}

	public function test_exception_is_runtime_exception(): void {
		$this->assertInstanceOf( \RuntimeException::class, new SqlException( 'x' ) );
	}
}
