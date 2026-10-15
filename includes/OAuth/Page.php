<?php
/**
 * Standalone page shell for the consent screens.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the security headers and a minimal HTML page. Used by the OAuth and the agent consent pages.
 */
final class Page {

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
			. '.ttm-approve{background:#2271b1;color:#fff}.ttm-deny{background:#fff;color:#2271b1}';

		echo '<!doctype html><html lang="en"><head><meta charset="utf-8" /><meta name="viewport" content="width=device-width, initial-scale=1" /><meta name="robots" content="noindex,nofollow" /><title>' . $title . '</title><style>' . $css . '</style></head><body><main>' . $body . '</main></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $title and $body are escaped by the callers; $css is a constant.
	}
}
