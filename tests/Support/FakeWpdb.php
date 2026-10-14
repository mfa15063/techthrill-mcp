<?php
namespace {
	if ( ! class_exists( 'wpdb' ) ) {
		/**
		 * Minimal stand-in so tool constructors typed against \wpdb accept the fake.
		 */
		class wpdb {
		}
	}
	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}
}

namespace TechThrill\MCP\Tests\Support {

	/**
	 * Recording $wpdb double. No database is touched.
	 */
	class FakeWpdb extends \wpdb {

		public $prefix        = 'wp_';
		public $last_error    = '';
		public $rows_affected = 0;
		public $insert_id     = 0;

		/**
		 * Every SQL string passed to get_results, query, get_var or get_col, in order.
		 *
		 * @var string[]
		 */
		public array $queries = array();

		/**
		 * Value returned by query().
		 *
		 * @var mixed
		 */
		public $queryResult = true;

		private array $results = array();
		private array $vars    = array();
		private array $cols    = array();

		public function queueResults( array $rows ): void {
			$this->results[] = $rows;
		}

		public function queueVar( $value ): void {
			$this->vars[] = $value;
		}

		public function queueCol( array $values ): void {
			$this->cols[] = $values;
		}

		public function get_results( $sql, $output = 'OBJECT' ) {
			$this->queries[] = $sql;
			return $this->results ? array_shift( $this->results ) : array();
		}

		public function query( $sql ) {
			$this->queries[] = $sql;
			return $this->queryResult;
		}

		public function get_var( $sql = null ) {
			$this->queries[] = (string) $sql;
			return $this->vars ? array_shift( $this->vars ) : null;
		}

		public function get_col( $sql = null ) {
			$this->queries[] = (string) $sql;
			return $this->cols ? array_shift( $this->cols ) : array();
		}

		public function prepare( $query, ...$args ) {
			$args = array_map(
				static function ( $arg ) {
					return is_int( $arg ) || is_float( $arg ) ? $arg : "'" . addslashes( (string) $arg ) . "'";
				},
				$args
			);
			return vsprintf( $query, $args );
		}

		public function esc_like( $s ) {
			return addcslashes( $s, '_%\\' );
		}

		public function db_version() {
			return '8.0.0';
		}
	}
}
