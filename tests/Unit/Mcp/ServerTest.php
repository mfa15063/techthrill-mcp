<?php
namespace TechThrill\MCP\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\Mcp\HandleResult;
use TechThrill\MCP\Mcp\SchemaValidator;
use TechThrill\MCP\Mcp\Server;
use TechThrill\MCP\Tools\ToolContext;
use TechThrill\MCP\Tools\ToolInterface;
use TechThrill\MCP\Tools\ToolRegistry;
use TechThrill\MCP\Tools\ToolResult;

class ServerTest extends TestCase {

	private function echoTool(): ToolInterface {
		return new class() implements ToolInterface {
			public function name(): string {
				return 'echo_tool';
			}

			public function description(): string {
				return 'Echoes text.';
			}

			public function inputSchema(): array {
				return array(
					'type'       => 'object',
					'properties' => array( 'text' => array( 'type' => 'string' ) ),
					'required'   => array( 'text' ),
				);
			}

			public function scope(): ?string {
				return null;
			}

			public function execute( array $args, ToolContext $ctx ): ToolResult {
				return ToolResult::ok( $args['text'] );
			}
		};
	}

	private function dbTool(): ToolInterface {
		return new class() implements ToolInterface {
			public function name(): string {
				return 'db_tool';
			}

			public function description(): string {
				return 'Reads the database.';
			}

			public function inputSchema(): array {
				return array( 'type' => 'object' );
			}

			public function scope(): ?string {
				return 'db:read';
			}

			public function execute( array $args, ToolContext $ctx ): ToolResult {
				return ToolResult::error( 'refused' );
			}
		};
	}

	private function throwingTool(): ToolInterface {
		return new class() implements ToolInterface {
			public function name(): string {
				return 'boom_tool';
			}

			public function description(): string {
				return 'Throws.';
			}

			public function inputSchema(): array {
				return array( 'type' => 'object', 'properties' => array() );
			}

			public function scope(): ?string {
				return null;
			}

			public function execute( array $args, ToolContext $ctx ): ToolResult {
				throw new \RuntimeException( 'secret detail' );
			}
		};
	}

	private function badSchemaTool(): ToolInterface {
		return new class() implements ToolInterface {
			public function name(): string {
				return 'bad_schema_tool';
			}

			public function description(): string {
				return 'Has a malformed schema.';
			}

			public function inputSchema(): array {
				return array( 'type' => 'object', 'required' => array( array( 'x' ) ) );
			}

			public function scope(): ?string {
				return null;
			}

			public function execute( array $args, ToolContext $ctx ): ToolResult {
				return ToolResult::ok( 'never' );
			}
		};
	}

	private function server( ?callable $on_call = null ): Server {
		$registry = new ToolRegistry();
		$registry->register( $this->echoTool() );
		$registry->register( $this->dbTool() );
		$registry->register( $this->throwingTool() );
		$registry->register( $this->badSchemaTool() );

		return new Server( $registry, new SchemaValidator(), '1.2.3', $on_call );
	}

	private function ctx( array $scopes = array() ): ToolContext {
		return new ToolContext( 1, 1, $scopes );
	}

	private function rpc( string $method, $params = null, $id = 1 ): array {
		$msg = array( 'jsonrpc' => '2.0', 'method' => $method );
		if ( null !== $id ) {
			$msg['id'] = $id;
		}
		if ( null !== $params ) {
			$msg['params'] = $params;
		}
		return $msg;
	}

	private function assertError( HandleResult $r, int $code, int $status = 200 ): void {
		$this->assertSame( $status, $r->status );
		$this->assertIsArray( $r->body );
		$this->assertSame( '2.0', $r->body['jsonrpc'] );
		$this->assertSame( $code, $r->body['error']['code'] );
	}

	public function test_non_object_body_is_invalid_request(): void {
		$r = $this->server()->handle( 'x', $this->ctx() );
		$this->assertError( $r, -32600, 400 );
		$this->assertNull( $r->body['id'] );
	}

	public function test_batch_rejected(): void {
		$r = $this->server()->handle( array( $this->rpc( 'ping' ) ), $this->ctx() );
		$this->assertError( $r, -32600, 400 );
	}

	public function test_missing_jsonrpc_or_method_is_invalid_request(): void {
		$r = $this->server()->handle( array( 'method' => 'ping', 'id' => 1 ), $this->ctx() );
		$this->assertError( $r, -32600, 400 );

		$r = $this->server()->handle( array( 'jsonrpc' => '2.0', 'id' => 7 ), $this->ctx() );
		$this->assertError( $r, -32600, 400 );
		$this->assertSame( 7, $r->body['id'] );

		$r = $this->server()->handle( array( 'jsonrpc' => '2.0', 'method' => '', 'id' => 7 ), $this->ctx() );
		$this->assertError( $r, -32600, 400 );
	}

	public function test_non_scalar_id_is_invalid_request(): void {
		$r = $this->server()->handle( array( 'jsonrpc' => '2.0', 'method' => 'ping', 'id' => array( 1 ) ), $this->ctx() );
		$this->assertError( $r, -32600, 400 );
		$this->assertNull( $r->body['id'] );
	}

	public function test_initialize_shape(): void {
		$r = $this->server()->handle(
			$this->rpc( 'initialize', array( 'protocolVersion' => '2025-06-18' ) ),
			$this->ctx()
		);
		$this->assertSame( 200, $r->status );
		$this->assertSame( 1, $r->body['id'] );
		$result = $r->body['result'];
		$this->assertSame( '2025-06-18', $result['protocolVersion'] );
		$this->assertFalse( $result['capabilities']['tools']['listChanged'] );
		$this->assertSame( 'techthrill-mcp', $result['serverInfo']['name'] );
		$this->assertSame( '1.2.3', $result['serverInfo']['version'] );
		$this->assertSame( Server::INSTRUCTIONS, $result['instructions'] );
	}

	public function test_instructions_text_is_exact(): void {
		$this->assertSame(
			'This is a WordPress site served by TechThrill MCP. Call site_info first to learn the WordPress version, paths, table prefix, host capabilities and which scopes your token has. Safe mode may refuse destructive operations; when a tool reports a refusal, tell the user what was refused and why instead of retrying.',
			Server::INSTRUCTIONS
		);
	}

	public function test_initialize_echoes_accepted_older_version(): void {
		$r = $this->server()->handle(
			$this->rpc( 'initialize', array( 'protocolVersion' => '2025-03-26' ) ),
			$this->ctx()
		);
		$this->assertSame( '2025-03-26', $r->body['result']['protocolVersion'] );

		$r = $this->server()->handle(
			$this->rpc( 'initialize', array( 'protocolVersion' => '1999-01-01' ) ),
			$this->ctx()
		);
		$this->assertSame( '2025-06-18', $r->body['result']['protocolVersion'] );

		$r = $this->server()->handle( $this->rpc( 'initialize' ), $this->ctx() );
		$this->assertSame( '2025-06-18', $r->body['result']['protocolVersion'] );
	}

	public function test_notification_returns_202_no_body(): void {
		$r = $this->server()->handle( $this->rpc( 'notifications/initialized', null, null ), $this->ctx() );
		$this->assertSame( 202, $r->status );
		$this->assertNull( $r->body );
	}

	public function test_notification_for_request_method_is_ignored(): void {
		$r = $this->server()->handle( $this->rpc( 'tools/list', null, null ), $this->ctx() );
		$this->assertSame( 202, $r->status );
		$this->assertNull( $r->body );
	}

	public function test_ping(): void {
		$r = $this->server()->handle( $this->rpc( 'ping' ), $this->ctx() );
		$this->assertSame( 200, $r->status );
		$this->assertSame( '{"jsonrpc":"2.0","id":1,"result":{}}', json_encode( $r->body ) );
	}

	public function test_string_id_is_echoed(): void {
		$r = $this->server()->handle( $this->rpc( 'ping', null, 'abc' ), $this->ctx() );
		$this->assertSame( 'abc', $r->body['id'] );
	}

	public function test_tools_list_filtered_by_scope(): void {
		$r     = $this->server()->handle( $this->rpc( 'tools/list' ), $this->ctx( array() ) );
		$names = array_column( $r->body['result']['tools'], 'name' );
		$this->assertSame( array( 'bad_schema_tool', 'boom_tool', 'echo_tool' ), $names );

		$r     = $this->server()->handle( $this->rpc( 'tools/list' ), $this->ctx( array( 'db:read' ) ) );
		$tools = $r->body['result']['tools'];
		$this->assertSame( array( 'bad_schema_tool', 'boom_tool', 'db_tool', 'echo_tool' ), array_column( $tools, 'name' ) );
		foreach ( $tools as $tool ) {
			$this->assertArrayHasKey( 'description', $tool );
			$this->assertArrayHasKey( 'inputSchema', $tool );
		}
	}

	public function test_tools_list_schema_without_properties_encodes_as_object(): void {
		$r = $this->server()->handle( $this->rpc( 'tools/list' ), $this->ctx( array( 'db:read' ) ) );
		$this->assertStringContainsString(
			'"name":"db_tool","description":"Reads the database.","inputSchema":{"type":"object","properties":{}}',
			json_encode( $r->body )
		);
		$this->assertStringContainsString( '"name":"boom_tool","description":"Throws.","inputSchema":{"type":"object","properties":{}}', json_encode( $r->body ) );
	}

	public function test_tools_call_routes_and_wraps(): void {
		$r = $this->server()->handle(
			$this->rpc( 'tools/call', array( 'name' => 'echo_tool', 'arguments' => array( 'text' => 'hi' ) ) ),
			$this->ctx()
		);
		$this->assertSame( 200, $r->status );
		$this->assertSame( 'hi', $r->body['result']['content'][0]['text'] );
		$this->assertFalse( $r->body['result']['isError'] );
	}

	public function test_tools_call_unknown_tool(): void {
		$r = $this->server()->handle( $this->rpc( 'tools/call', array( 'name' => 'nope' ) ), $this->ctx() );
		$this->assertError( $r, -32602 );
		$this->assertSame( 'Unknown tool "nope".', $r->body['error']['message'] );

		$r = $this->server()->handle( $this->rpc( 'tools/call', array( 'name' => 5 ) ), $this->ctx() );
		$this->assertError( $r, -32602 );
	}

	public function test_tools_call_scope_denied(): void {
		$r = $this->server()->handle( $this->rpc( 'tools/call', array( 'name' => 'db_tool' ) ), $this->ctx() );
		$this->assertError( $r, -32602 );
		$this->assertStringContainsString( 'scope', $r->body['error']['message'] );
		$this->assertSame(
			'Tool "db_tool" requires the db:read scope, which this token does not have.',
			$r->body['error']['message']
		);
	}

	public function test_tools_call_scope_allowed_returns_tool_error_result(): void {
		$r = $this->server()->handle( $this->rpc( 'tools/call', array( 'name' => 'db_tool' ) ), $this->ctx( array( 'db:read' ) ) );
		$this->assertSame( 200, $r->status );
		$this->assertTrue( $r->body['result']['isError'] );
		$this->assertSame( 'refused', $r->body['result']['content'][0]['text'] );
	}

	public function test_tools_call_validation_error(): void {
		$r = $this->server()->handle(
			$this->rpc( 'tools/call', array( 'name' => 'echo_tool', 'arguments' => array() ) ),
			$this->ctx()
		);
		$this->assertError( $r, -32602 );
		$this->assertStringContainsString( 'text', $r->body['error']['message'] );
	}

	public function test_tools_call_arguments_must_be_array(): void {
		$r = $this->server()->handle(
			$this->rpc( 'tools/call', array( 'name' => 'echo_tool', 'arguments' => 'x' ) ),
			$this->ctx()
		);
		$this->assertError( $r, -32602 );
	}

	public function test_tool_exception_becomes_internal_error(): void {
		$called = false;
		$server = $this->server(
			function () use ( &$called ) {
				$called = true;
			}
		);
		$r      = $server->handle( $this->rpc( 'tools/call', array( 'name' => 'boom_tool' ) ), $this->ctx() );
		$this->assertError( $r, -32603 );
		$this->assertSame( 'Internal error', $r->body['error']['message'] );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$this->assertSame( 'secret detail', $r->body['error']['data'] );
		} else {
			$this->assertArrayNotHasKey( 'data', $r->body['error'] );
		}
		$this->assertFalse( $called, 'Callback is not invoked for internal errors.' );
	}

	public function test_unknown_method(): void {
		$r = $this->server()->handle( $this->rpc( 'resources/list' ), $this->ctx() );
		$this->assertError( $r, -32601 );
		$this->assertSame( 'Method not found', $r->body['error']['message'] );
	}

	public function test_throwing_observer_does_not_change_response(): void {
		$server = $this->server(
			function () {
				throw new \RuntimeException( 'audit log down' );
			}
		);
		$r      = $server->handle(
			$this->rpc( 'tools/call', array( 'name' => 'echo_tool', 'arguments' => array( 'text' => 'hi' ) ) ),
			$this->ctx()
		);
		$this->assertSame( 200, $r->status );
		$this->assertSame( 'hi', $r->body['result']['content'][0]['text'] );
		$this->assertFalse( $r->body['result']['isError'] );
		$this->assertArrayNotHasKey( 'error', $r->body );
	}

	public function test_malformed_schema_becomes_internal_error(): void {
		$called = false;
		$server = $this->server(
			function () use ( &$called ) {
				$called = true;
			}
		);
		$r      = $server->handle( $this->rpc( 'tools/call', array( 'name' => 'bad_schema_tool' ) ), $this->ctx() );
		$this->assertError( $r, -32603 );
		$this->assertSame( 'Internal error', $r->body['error']['message'] );
		$this->assertFalse( $called, 'Callback is not invoked for internal errors.' );
	}

	public function test_on_tool_call_invoked(): void {
		$calls  = array();
		$server = $this->server(
			function ( string $tool, array $args, ToolResult $result, int $ms ) use ( &$calls ) {
				$calls[] = array( $tool, $args, $result, $ms );
			}
		);
		$server->handle(
			$this->rpc( 'tools/call', array( 'name' => 'echo_tool', 'arguments' => array( 'text' => 'hi', 'junk' => 1 ) ) ),
			$this->ctx()
		);
		$server->handle( $this->rpc( 'tools/call', array( 'name' => 'db_tool' ) ), $this->ctx( array( 'db:read' ) ) );
		// Protocol errors do not invoke the callback.
		$server->handle( $this->rpc( 'tools/call', array( 'name' => 'nope' ) ), $this->ctx() );
		$server->handle( $this->rpc( 'tools/call', array( 'name' => 'db_tool' ) ), $this->ctx() );

		$this->assertCount( 2, $calls );
		$this->assertSame( 'echo_tool', $calls[0][0] );
		$this->assertSame( array( 'text' => 'hi' ), $calls[0][1] );
		$this->assertFalse( $calls[0][2]->isError() );
		$this->assertGreaterThanOrEqual( 0, $calls[0][3] );
		$this->assertSame( 'db_tool', $calls[1][0] );
		$this->assertTrue( $calls[1][2]->isError() );
	}
}
