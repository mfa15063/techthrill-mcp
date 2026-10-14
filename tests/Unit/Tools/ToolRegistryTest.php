<?php
namespace TechThrill\MCP\Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\Tools\AbstractTool;
use TechThrill\MCP\Tools\ToolContext;
use TechThrill\MCP\Tools\ToolRegistry;
use TechThrill\MCP\Tools\ToolResult;

class FakeTool extends AbstractTool {
	private string $tool_name;
	private ?string $tool_scope;
	private $payload;

	public function __construct( string $name, ?string $scope, $payload = 'done' ) {
		$this->tool_name  = $name;
		$this->tool_scope = $scope;
		$this->payload    = $payload;
	}

	public function name(): string {
		return $this->tool_name;
	}

	public function description(): string {
		return 'fake';
	}

	public function inputSchema(): array {
		return array( 'type' => 'object', 'properties' => array() );
	}

	public function scope(): ?string {
		return $this->tool_scope;
	}

	public function execute( array $args, ToolContext $ctx ): ToolResult {
		return $this->ok( $this->payload );
	}

	public function failWith( string $msg ): ToolResult {
		return $this->fail( $msg );
	}
}

class ToolRegistryTest extends TestCase {
	public function test_for_scopes_filters_and_sorts(): void {
		$registry = new ToolRegistry();
		$registry->register( new FakeTool( 'zeta', 'db:read' ) );
		$registry->register( new FakeTool( 'alpha', 'db:read' ) );
		$registry->register( new FakeTool( 'writer', 'db:write' ) );
		$registry->register( new FakeTool( 'open', null ) );

		$names = array_map( fn( $t ) => $t->name(), $registry->forScopes( array( 'db:read' ) ) );
		$this->assertSame( array( 'alpha', 'open', 'zeta' ), $names );

		$names = array_map( fn( $t ) => $t->name(), $registry->forScopes( array( 'db:write' ) ) );
		$this->assertSame( array( 'alpha', 'open', 'writer', 'zeta' ), $names );
	}

	public function test_get_unknown_returns_null(): void {
		$registry = new ToolRegistry();
		$this->assertNull( $registry->get( 'nope' ) );
	}

	public function test_get_and_all(): void {
		$registry = new ToolRegistry();
		$tool     = new FakeTool( 'one', null );
		$registry->register( $tool );
		$this->assertSame( $tool, $registry->get( 'one' ) );
		$this->assertSame( array( 'one' => $tool ), $registry->all() );
	}

	public function test_context_accessors(): void {
		$ctx = new ToolContext( 7, 3, array( 'db:write', 'db:read' ) );
		$this->assertSame( 7, $ctx->tokenId() );
		$this->assertSame( 3, $ctx->userId() );
		$this->assertSame( array( 'db:write', 'db:read' ), $ctx->scopes() );
		$this->assertTrue( $ctx->hasScope( 'db:read' ) );
		$this->assertFalse( $ctx->hasScope( 'files:read' ) );
	}

	public function test_tool_result_to_mcp(): void {
		$this->assertSame(
			array( 'content' => array( array( 'type' => 'text', 'text' => 'hi' ) ), 'isError' => false ),
			ToolResult::ok( 'hi' )->toMcp()
		);
		$err = ToolResult::error( 'bad' );
		$this->assertTrue( $err->isError() );
		$this->assertSame( 'bad', $err->text() );
		$this->assertTrue( $err->toMcp()['isError'] );
	}

	public function test_ok_string_passthrough_and_array_json(): void {
		$ctx = new ToolContext( 1, 1, array() );

		$this->assertSame( 'plain', ( new FakeTool( 't', null, 'plain' ) )->execute( array(), $ctx )->text() );

		$res = ( new FakeTool( 't', null, array( 'path' => 'a/b' ) ) )->execute( array(), $ctx );
		$this->assertFalse( $res->isError() );
		$this->assertSame( json_encode( array( 'path' => 'a/b' ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), $res->text() );
		$this->assertStringContainsString( "\n", $res->text() );
		$this->assertStringContainsString( 'a/b', $res->text() );
	}

	public function test_ok_large_array_is_compact(): void {
		$ctx  = new ToolContext( 1, 1, array() );
		$data = array( 'blob' => str_repeat( 'x', 52000 ) );
		$res  = ( new FakeTool( 't', null, $data ) )->execute( array(), $ctx );
		$this->assertSame( json_encode( $data, JSON_UNESCAPED_SLASHES ), $res->text() );
	}

	public function test_fail_returns_error_result(): void {
		$res = ( new FakeTool( 't', null ) )->failWith( 'boom' );
		$this->assertTrue( $res->isError() );
		$this->assertSame( 'boom', $res->text() );
	}
}
