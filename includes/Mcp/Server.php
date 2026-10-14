<?php
/**
 * MCP JSON-RPC server.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Mcp;

use TechThrill\MCP\Tools\ToolContext;
use TechThrill\MCP\Tools\ToolRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Handles decoded MCP messages (initialize, ping, tools/list, tools/call).
 */
final class Server {

	const PROTOCOL = '2025-06-18';

	const ACCEPTED = array( '2025-06-18', '2025-03-26' );

	const INSTRUCTIONS = 'This is a WordPress site served by TechThrill MCP. Call site_info first to learn the WordPress version, paths, table prefix, host capabilities and which scopes your token has. Safe mode may refuse destructive operations; when a tool reports a refusal, tell the user what was refused and why instead of retrying.';

	/**
	 * Tool registry.
	 *
	 * @var ToolRegistry
	 */
	private ToolRegistry $registry;

	/**
	 * Argument validator.
	 *
	 * @var SchemaValidator
	 */
	private SchemaValidator $validator;

	/**
	 * Plugin version reported in serverInfo.
	 *
	 * @var string
	 */
	private string $version;

	/**
	 * Called after each tool execution as fn( string $tool, array $args, ToolResult $result, int $ms ).
	 *
	 * @var callable|null
	 */
	private $on_tool_call;

	/**
	 * @param ToolRegistry    $registry     Tool registry.
	 * @param SchemaValidator $validator    Argument validator.
	 * @param string          $version      Plugin version.
	 * @param callable|null   $on_tool_call Optional tool call observer.
	 */
	public function __construct( ToolRegistry $registry, SchemaValidator $validator, string $version, ?callable $on_tool_call = null ) {
		$this->registry     = $registry;
		$this->validator    = $validator;
		$this->version      = $version;
		$this->on_tool_call = $on_tool_call;
	}

	/**
	 * Handles one decoded JSON-RPC message.
	 *
	 * @param mixed       $decoded Result of json_decode( $body, true ).
	 * @param ToolContext $ctx     Caller context.
	 */
	public function handle( $decoded, ToolContext $ctx ): HandleResult {
		if ( ! is_array( $decoded ) || $this->is_batch( $decoded ) ) {
			return $this->invalid_request( null );
		}

		$has_id = array_key_exists( 'id', $decoded );
		$id     = $has_id ? $decoded['id'] : null;

		if ( $has_id && null !== $id && ! is_int( $id ) && ! is_string( $id ) ) {
			return $this->invalid_request( null );
		}
		if ( ! isset( $decoded['jsonrpc'] ) || '2.0' !== $decoded['jsonrpc'] ) {
			return $this->invalid_request( $id );
		}
		if ( ! isset( $decoded['method'] ) || ! is_string( $decoded['method'] ) || '' === $decoded['method'] ) {
			return $this->invalid_request( $id );
		}

		// Notifications carry no id and never get a response body.
		if ( ! $has_id ) {
			return new HandleResult( 202, null );
		}

		$params = isset( $decoded['params'] ) && is_array( $decoded['params'] ) ? $decoded['params'] : array();

		switch ( $decoded['method'] ) {
			case 'initialize':
				return $this->ok( $id, $this->initialize( $params ) );
			case 'ping':
				return $this->ok( $id, new \stdClass() );
			case 'tools/list':
				return $this->ok( $id, $this->tools_list( $ctx ) );
			case 'tools/call':
				return $this->tools_call( $id, $params, $ctx );
			default:
				return $this->error( $id, JsonRpcError::METHOD_NOT_FOUND, 'Method not found' );
		}
	}

	/**
	 * Whether the value is a non-empty JSON array (batch).
	 *
	 * @param array $decoded Decoded body.
	 */
	private function is_batch( array $decoded ): bool {
		return array() !== $decoded && array_keys( $decoded ) === range( 0, count( $decoded ) - 1 );
	}

	/**
	 * Builds the initialize result.
	 *
	 * @param array $params Request params.
	 */
	private function initialize( array $params ): array {
		$requested = $params['protocolVersion'] ?? null;
		$protocol  = is_string( $requested ) && in_array( $requested, self::ACCEPTED, true ) ? $requested : self::PROTOCOL;

		return array(
			'protocolVersion' => $protocol,
			'capabilities'    => array( 'tools' => array( 'listChanged' => false ) ),
			'serverInfo'      => array(
				'name'    => 'techthrill-mcp',
				'version' => $this->version,
			),
			'instructions'    => self::INSTRUCTIONS,
		);
	}

	/**
	 * Builds the tools/list result for the caller's scopes.
	 *
	 * @param ToolContext $ctx Caller context.
	 */
	private function tools_list( ToolContext $ctx ): array {
		$tools = array();
		foreach ( $this->registry->forScopes( $ctx->scopes() ) as $tool ) {
			$schema = $tool->inputSchema();
			if ( empty( $schema['properties'] ) ) {
				$schema['type']       = $schema['type'] ?? 'object';
				$schema['properties'] = new \stdClass();
			}
			$tools[] = array(
				'name'        => $tool->name(),
				'description' => $tool->description(),
				'inputSchema' => $schema,
			);
		}

		return array( 'tools' => $tools );
	}

	/**
	 * Runs a tool.
	 *
	 * @param int|string|null $id     Request ID.
	 * @param array           $params Request params.
	 * @param ToolContext     $ctx    Caller context.
	 */
	private function tools_call( $id, array $params, ToolContext $ctx ): HandleResult {
		$name = $params['name'] ?? null;
		if ( ! is_string( $name ) ) {
			return $this->error( $id, JsonRpcError::INVALID_PARAMS, 'Missing or invalid tool name.' );
		}

		$tool = $this->registry->get( $name );
		if ( null === $tool ) {
			return $this->error( $id, JsonRpcError::INVALID_PARAMS, sprintf( 'Unknown tool "%s".', $name ) );
		}

		$scope = $tool->scope();
		if ( null !== $scope && ! $ctx->hasScope( $scope ) ) {
			return $this->error(
				$id,
				JsonRpcError::INVALID_PARAMS,
				sprintf( 'Tool "%s" requires the %s scope, which this token does not have.', $name, $scope )
			);
		}

		$args = $params['arguments'] ?? array();
		if ( ! is_array( $args ) ) {
			return $this->error( $id, JsonRpcError::INVALID_PARAMS, 'Tool arguments must be an object.' );
		}

		try {
			$args = $this->validator->validate( $tool->inputSchema(), $args );
		} catch ( ValidationException $e ) {
			return $this->error( $id, JsonRpcError::INVALID_PARAMS, $e->getMessage() );
		} catch ( \Throwable $e ) {
			return $this->internal_error( $id, $e );
		}

		$start = microtime( true );
		try {
			$result  = $tool->execute( $args, $ctx );
			$payload = $result->toMcp();
		} catch ( \Throwable $e ) {
			return $this->internal_error( $id, $e );
		}
		$ms = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( null !== $this->on_tool_call ) {
			// A failing observer (for example the audit log) must never change the response of a completed call.
			try {
				call_user_func( $this->on_tool_call, $name, $args, $result, $ms );
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'TechThrill MCP: tool call observer failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			}
		}

		return $this->ok( $id, $payload );
	}

	/**
	 * Internal error response; the exception message is exposed only when WP_DEBUG is on.
	 *
	 * @param int|string|null $id Request ID.
	 * @param \Throwable      $e  Exception.
	 */
	private function internal_error( $id, \Throwable $e ): HandleResult {
		$data = defined( 'WP_DEBUG' ) && WP_DEBUG ? $e->getMessage() : null;

		return $this->error( $id, JsonRpcError::INTERNAL, 'Internal error', $data );
	}

	/**
	 * Success response.
	 *
	 * @param int|string|null $id     Request ID.
	 * @param mixed           $result Result payload.
	 */
	private function ok( $id, $result ): HandleResult {
		return new HandleResult( 200, JsonRpcError::success( $id, $result ) );
	}

	/**
	 * Error response with HTTP 200 (the error lives in the JSON-RPC body).
	 *
	 * @param int|string|null $id      Request ID.
	 * @param int             $code    Error code.
	 * @param string          $message Error message.
	 * @param mixed           $data    Optional error data.
	 */
	private function error( $id, int $code, string $message, $data = null ): HandleResult {
		return new HandleResult( 200, JsonRpcError::envelope( $id, $code, $message, $data ) );
	}

	/**
	 * Invalid request response (HTTP 400).
	 *
	 * @param int|string|null $id Request ID when it could be determined.
	 */
	private function invalid_request( $id ): HandleResult {
		return new HandleResult( 400, JsonRpcError::envelope( $id, JsonRpcError::INVALID_REQUEST, 'Invalid request' ) );
	}
}
