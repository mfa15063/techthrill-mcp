<?php
/**
 * Standalone page shell for the consent screens.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\OAuth;

use TechThrill\MCP\Auth\Scopes;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the security headers and a minimal HTML page. Used by the OAuth and the agent consent pages.
 */
final class Page {

	/**
	 * The permissions fieldset: a Select all / Deselect all checkbox on top, then one checkbox per scope.
	 * Escaped HTML, including the few lines of script that keep the top checkbox in step with the others.
	 *
	 * @param array<string,string> $labels  Scope => description.
	 * @param string[]             $checked Scopes ticked.
	 */
	public static function permissionsFieldset( array $labels, array $checked ): string {
		$html  = '<fieldset><legend>' . esc_html__( 'Permissions', 'techthrill-mcp' ) . '</legend>';
		$html .= '<label class="ttm-all"><input type="checkbox" id="ttm-select-all" /> <strong>' . esc_html__( 'Select all / Deselect all', 'techthrill-mcp' ) . '</strong></label>';
		foreach ( Scopes::ALL as $scope ) {
			$html .= '<label><input type="checkbox" name="scopes[]" value="' . esc_attr( $scope ) . '"' . ( in_array( $scope, $checked, true ) ? ' checked="checked"' : '' ) . ' /> <code>' . esc_html( $scope ) . '</code> ' . esc_html( $labels[ $scope ] ?? '' ) . '</label>';
		}
		$html .= '</fieldset>';
		// Keeps the top checkbox ticked, empty or half-ticked to match the others; without script the boxes still work.
		$html .= '<script>(function(){var all=document.getElementById("ttm-select-all");var boxes=[].slice.call(document.querySelectorAll(\'input[name="scopes[]"]\'));function sync(){var n=0;boxes.forEach(function(b){if(b.checked){n++;}});all.checked=n===boxes.length;all.indeterminate=n>0&&n<boxes.length;}all.addEventListener("change",function(){boxes.forEach(function(b){b.checked=all.checked;});sync();});boxes.forEach(function(b){b.addEventListener("change",sync);});sync();}());</script>';

		return $html;
	}

	/**
	 * The expiry dropdown (1 day, 1 week, 30 days, Never expire), 30 days selected by default.
	 *
	 * @param string $selected Choice key selected.
	 */
	public static function expirySelect( string $selected = Lifetime::DEFAULT_KEY ): string {
		$html = '<p><label for="ttm-expires"><strong>' . esc_html__( 'Access expires after', 'techthrill-mcp' ) . '</strong></label><select name="expires" id="ttm-expires">';
		foreach ( Lifetime::labels() as $key => $label ) {
			$html .= '<option value="' . esc_attr( $key ) . '"' . ( $key === $selected ? ' selected="selected"' : '' ) . '>' . esc_html( $label ) . '</option>';
		}

		return $html . '</select></p>';
	}

	/**
	 * Sends headers and prints the page shell. $body must already be escaped.
	 *
	 * @param string $title  Escaped title.
	 * @param string $body   Escaped HTML body.
	 * @param int    $status HTTP status.
	 */
	public static function send( string $title, string $body, int $status ): void {
		nocache_headers();
		status_header( $status );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Frame-Options: DENY' );
		header( "Content-Security-Policy: frame-ancestors 'none'" );
		header( 'Cache-Control: no-store' );
		header( 'Referrer-Policy: no-referrer' );

		$css = 'body{margin:0;padding:24px 16px;background:#f0f0f1;font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1d2327}'
			. 'main{max-width:520px;margin:0 auto;background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:24px}'
			. 'h1{font-size:22px;margin:0 0 16px}fieldset{border:1px solid #c3c4c7;border-radius:4px;margin:16px 0;padding:8px 12px}'
			. 'label{display:block;padding:6px 0}code{background:#f0f0f1;padding:1px 5px;border-radius:3px}'
			. '.ttm-client{background:#f6f7f7;padding:10px 12px;border-radius:4px}.ttm-client span{color:#50575e;font-size:14px}'
			. '.ttm-notice{background:#fcf0f1;border-left:4px solid #d63638;padding:8px 12px}'
			. 'button{font:inherit;padding:8px 18px;border-radius:4px;border:1px solid #2271b1;cursor:pointer}'
			. '.ttm-approve{background:#2271b1;color:#fff}.ttm-deny{background:#fff;color:#2271b1}'
			. '.ttm-all{border-bottom:1px solid #dcdcde;margin-bottom:4px;padding-bottom:8px}select{font:inherit;padding:6px 8px;margin-top:4px}';

		echo '<!doctype html><html lang="en"><head><meta charset="utf-8" /><meta name="viewport" content="width=device-width, initial-scale=1" /><meta name="robots" content="noindex,nofollow" /><title>' . $title . '</title><style>' . $css . '</style></head><body><main>' . $body . '</main></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $title and $body are escaped by the callers; $css is a constant.
	}
}
