<?php
/**
 * Minimal stand-ins for WP_REST_Request and WP_REST_Response (the unit suite runs without WordPress).
 */

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {

		/**
		 * @var string
		 */
		private $method;

		/**
		 * @var array<string,string>
		 */
		private $headers = array();

		/**
		 * @var string
		 */
		private $body;

		/**
		 * @param string               $method  HTTP method.
		 * @param array<string,string> $headers Headers keyed by name (any case, dashes or underscores).
		 * @param string               $body    Raw body.
		 */
		public function __construct( $method = 'POST', array $headers = array(), $body = '' ) {
			$this->method = $method;
			$this->body   = $body;
			foreach ( $headers as $key => $value ) {
				$this->headers[ self::canonical( $key ) ] = $value;
			}
		}

		public function get_method() {
			return $this->method;
		}

		public function get_header( $key ) {
			$key = self::canonical( $key );

			return $this->headers[ $key ] ?? null;
		}

		public function get_body() {
			return $this->body;
		}

		private static function canonical( $key ) {
			return str_replace( '-', '_', strtolower( $key ) );
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {

		/**
		 * @var mixed
		 */
		private $data;

		/**
		 * @var int
		 */
		private $status;

		/**
		 * @var array<string,string>
		 */
		private $headers = array();

		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		public function get_status() {
			return $this->status;
		}

		public function get_data() {
			return $this->data;
		}

		public function header( $key, $value, $replace = true ) {
			$this->headers[ $key ] = $value;
		}

		public function get_headers() {
			return $this->headers;
		}
	}
}
