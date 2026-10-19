<?php
namespace TechThrill\MCP\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\Auth\Scopes;
use TechThrill\MCP\OAuth\Page;

class PageHelpersTest extends TestCase {

	private function labels(): array {
		return array( 'db:read' => 'Read the database', 'files:write' => 'Change files' );
	}

	public function test_select_all_checkbox_comes_first_with_the_requested_label(): void {
		$html = Page::permissionsFieldset( $this->labels(), Scopes::ALL );
		$this->assertStringContainsString( 'id="ttm-select-all"', $html );
		$this->assertStringContainsString( 'Select all / Deselect all', $html );
		$this->assertLessThan( strpos( $html, '<input type="checkbox" name="scopes[]"' ), strpos( $html, 'id="ttm-select-all"' ) );
	}

	public function test_every_scope_gets_a_checkbox_and_the_ticked_ones_are_checked(): void {
		$all = Page::permissionsFieldset( $this->labels(), Scopes::ALL );
		$this->assertSame( 5, substr_count( $all, '<input type="checkbox" name="scopes[]"' ) );
		$this->assertSame( 5, substr_count( $all, 'checked="checked"' ) );

		$some = Page::permissionsFieldset( $this->labels(), array( 'db:read' ) );
		$this->assertSame( 1, substr_count( $some, 'checked="checked"' ) );
		$this->assertStringContainsString( 'value="db:read" checked="checked"', $some );

		$none = Page::permissionsFieldset( $this->labels(), array() );
		$this->assertSame( 0, substr_count( $none, 'checked="checked"' ) );
	}

	public function test_the_select_all_script_is_present_and_the_select_all_box_has_no_name(): void {
		$html = Page::permissionsFieldset( $this->labels(), Scopes::ALL );
		$this->assertStringContainsString( '<script>', $html );
		$this->assertStringContainsString( 'indeterminate', $html );
		$this->assertStringNotContainsString( 'name="ttm-select-all"', $html, 'The helper checkbox must never be submitted as a scope.' );
	}

	public function test_expiry_dropdown_options_and_default(): void {
		$html = Page::expirySelect();
		$this->assertStringContainsString( 'name="expires"', $html );
		preg_match_all( '/<option value="([^"]+)"( selected="selected")?>([^<]+)<\/option>/', $html, $m, PREG_SET_ORDER );
		$this->assertSame( array( '1d', '1w', '30d', 'never' ), array_column( $m, 1 ) );
		$this->assertSame( array( '1 day', '1 week', '30 days', 'Never expire' ), array_column( $m, 3 ) );
		$selected = array_values( array_filter( $m, fn( $o ) => ! empty( $o[2] ) ) );
		$this->assertCount( 1, $selected );
		$this->assertSame( '30d', $selected[0][1] );
	}

	public function test_expiry_dropdown_can_preselect_another_choice(): void {
		$html = Page::expirySelect( 'never' );
		$this->assertStringContainsString( '<option value="never" selected="selected">', $html );
		$this->assertStringNotContainsString( '<option value="30d" selected="selected">', $html );
	}
}
