<?php
namespace TechThrill\MCP\Tests\Unit\Logging;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\Logging\ArgumentSummarizer;
use TechThrill\MCP\Logging\AuditLog;
use TechThrill\MCP\Tools\ToolResult;

class ArgumentSummarizerTest extends TestCase {

	public function test_truncates_and_masks_content(): void {
		$summary = ArgumentSummarizer::summarize(
			array(
				'path'    => 'a.php',
				'content' => str_repeat( 'x', 1000 ),
			)
		);

		$this->assertSame( 'path=a.php content=<1000 chars>', $summary );
	}

	public function test_masks_new_string(): void {
		$this->assertSame(
			'new_string=<5 chars>',
			ArgumentSummarizer::summarize( array( 'new_string' => 'hello' ) )
		);
	}

	public function test_masks_old_string(): void {
		$this->assertSame(
			'old_string=<11 chars>',
			ArgumentSummarizer::summarize( array( 'old_string' => 'secret code' ) )
		);
	}

	public function test_truncates_long_values_to_80_chars_with_ellipsis(): void {
		$summary = ArgumentSummarizer::summarize( array( 'sql' => str_repeat( 'a', 200 ) ) );

		$this->assertSame( 'sql=' . str_repeat( 'a', 80 ) . '…', $summary );
	}

	public function test_renders_scalars_and_json_for_non_scalars(): void {
		$summary = ArgumentSummarizer::summarize(
			array(
				'flag'  => true,
				'off'   => false,
				'none'  => null,
				'count' => 3,
				'list'  => array( 'a', 'b' ),
			)
		);

		$this->assertSame( 'flag=true off=false none=null count=3 list=["a","b"]', $summary );
	}

	public function test_collapses_newlines_to_a_single_line(): void {
		$this->assertSame(
			'sql=SELECT 1 FROM x',
			ArgumentSummarizer::summarize( array( 'sql' => "SELECT 1\n  FROM\tx" ) )
		);
	}

	public function test_empty_args_give_empty_string(): void {
		$this->assertSame( '', ArgumentSummarizer::summarize( array() ) );
	}

	public function test_hard_cap_500(): void {
		$args = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$args[ 'key' . $i ] = str_repeat( 'v', 70 );
		}

		$this->assertSame( 500, mb_strlen( ArgumentSummarizer::summarize( $args ) ) );
	}

	public function test_status_refused_for_safe_mode_and_confirmation(): void {
		$this->assertSame( 'refused', AuditLog::statusFor( ToolResult::error( 'Refused by safe mode: no.' ) ) );
		$this->assertSame( 'refused', AuditLog::statusFor( ToolResult::error( 'Confirmation required: pass confirm=true' ) ) );
	}

	public function test_status_refused_for_always_on_refusals(): void {
		$this->assertSame( 'refused', AuditLog::statusFor( ToolResult::error( 'Refused: wp-config.php cannot be modified or deleted through TechThrill MCP.' ) ) );
		$this->assertSame( 'refused', AuditLog::statusFor( ToolResult::error( 'Refused: the command "wp eval" is not available through TechThrill MCP.' ) ) );
		$this->assertSame( 'refused', AuditLog::statusFor( ToolResult::error( 'Refused: reading wp-config.php requires the db:read scope because it contains database credentials.' ) ) );
	}

	public function test_status_error_for_other_errors(): void {
		$this->assertSame( 'error', AuditLog::statusFor( ToolResult::error( 'File not found' ) ) );
		// A success whose text happens to start with the prefix is still ok.
		$this->assertSame( 'ok', AuditLog::statusFor( ToolResult::ok( 'Refused by safe mode: quoted' ) ) );
	}

	public function test_status_ok(): void {
		$this->assertSame( 'ok', AuditLog::statusFor( ToolResult::ok( 'done' ) ) );
	}
}
