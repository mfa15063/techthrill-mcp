<?php
/**
 * The consent page (the authorization endpoint).
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\OAuth;

use TechThrill\MCP\Auth\Scopes;

defined( 'ABSPATH' ) || exit;

/**
 * Served from admin-post.php, because a browser navigation carries the login cookie but no REST nonce.
 * Reads the request, asks ConsentFlow what to do, then redirects or renders.
 */
final class ConsentPage {

	const ACTION = 'techthrill_mcp_authorize';

	const NONCE = 'techthrill_mcp_oauth_consent';

	const PARAMS = array( 'response_type', 'client_id', 'redirect_uri', 'state', 'code_challenge', 'code_challenge_method', 'scope', 'resource' );

	private ConsentFlow $flow;

	/**
	 * @param ConsentFlow $flow Decision logic.
	 */
	public function __construct( ConsentFlow $flow ) {
		$this->flow = $flow;
	}

	/**
	 * URL of the authorization endpoint.
	 */
	public static function url(): string {
		return admin_url( 'admin-post.php?action=' . self::ACTION );
	}

	/**
	 * Registers the handler for logged-in and logged-out visitors.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Handles a GET (show the consent screen) or POST (approve or deny).
	 */
	public function handle(): void {
		$post   = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) );
		$source = $post ? $_POST : $_GET; // phpcs:ignore WordPress.Security.NonceVerification -- The nonce is verified below for POST; GET only displays a form and changes nothing.

		$params = array();
		foreach ( self::PARAMS as $key ) {
			// Kept raw on purpose: the validator compares the redirect URI byte for byte, and every value is escaped before output.
			if ( isset( $source[ $key ] ) && is_string( $source[ $key ] ) ) {
				$params[ $key ] = wp_unslash( $source[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			}
		}

		$scopes = array();
		if ( $post && isset( $_POST['scopes'] ) && is_array( $_POST['scopes'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified through ConsentFlow.
			foreach ( wp_unslash( $_POST['scopes'] ) as $scope ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Filtered through Scopes::sanitize().
				if ( is_string( $scope ) ) {
					$scopes[] = $scope;
				}
			}
		}

		$nonce    = $post && isset( $_POST['_wpnonce'] ) && is_string( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		$decision = $post && isset( $_POST['decision'] ) && is_string( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';

		$result = $this->flow->handle(
			$post ? 'POST' : 'GET',
			$params,
			is_user_logged_in(),
			current_user_can( 'manage_options' ),
			'' !== $nonce && false !== wp_verify_nonce( $nonce, self::NONCE ),
			get_current_user_id(),
			Scopes::sanitize( $scopes ),
			$decision
		);

		switch ( $result['type'] ) {
			case 'login':
				wp_safe_redirect( wp_login_url( self::url() . '&' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ) ) );
				exit;
			case 'redirect':
				// The target is an allowlisted, registered redirect URI (see AuthorizeValidator), on an external host.
				wp_redirect( $result['url'] ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
				exit;
			case 'consent':
				$this->renderConsent( $result['request'], $result['checked'], $result['notice'] ?? '' );
				exit;
			default:
				$this->renderError( (string) ( $result['message'] ?? '' ), (int) ( $result['status'] ?? 400 ) );
				exit;
		}
	}

	/**
	 * Prints the consent screen.
	 *
	 * @param AuthorizationRequest $request Validated request.
	 * @param string[]             $checked Scopes ticked by default.
	 * @param string               $notice  Optional message above the form.
	 */
	private function renderConsent( AuthorizationRequest $request, array $checked, string $notice ): void {
		$labels = array(
			'wp-cli'      => __( 'Run WP-CLI commands (full control of the site)', 'techthrill-mcp' ),
			'db:read'     => __( 'Read the database', 'techthrill-mcp' ),
			'db:write'    => __( 'Change the database', 'techthrill-mcp' ),
			'files:read'  => __( 'Read files', 'techthrill-mcp' ),
			'files:write' => __( 'Change and delete files', 'techthrill-mcp' ),
		);
		$user   = wp_get_current_user();
		$client = $request->client();

		ob_start();
		echo '<h1>' . esc_html__( 'Connect an AI client to this site?', 'techthrill-mcp' ) . '</h1>';
		if ( '' !== $notice ) {
			echo '<p class="ttm-notice">' . esc_html( $notice ) . '</p>';
		}
		echo '<p class="ttm-client"><strong>' . esc_html( (string) $client['client_name'] ) . '</strong><br />';
		/* translators: %s: host name the client will return to */
		echo '<span>' . esc_html( sprintf( __( 'Returns to: %s', 'techthrill-mcp' ), RedirectPolicy::host( $request->redirectUri() ) ) ) . '</span></p>';
		/* translators: %s: administrator display name */
		echo '<p>' . esc_html( sprintf( __( 'If you approve, this client acts as you (%s) on this site, with only the permissions you tick below. You can revoke it any time under TechThrill MCP > Tokens.', 'techthrill-mcp' ), $user->display_name ) ) . '</p>';

		echo '<form method="post" action="' . esc_url( self::url() ) . '">';
		foreach ( $request->params() as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
		}
		wp_nonce_field( self::NONCE );
		echo '<fieldset><legend>' . esc_html__( 'Permissions', 'techthrill-mcp' ) . '</legend>';
		foreach ( Scopes::ALL as $scope ) {
			echo '<label><input type="checkbox" name="scopes[]" value="' . esc_attr( $scope ) . '"' . checked( in_array( $scope, $checked, true ), true, false ) . ' /> <code>' . esc_html( $scope ) . '</code> ' . esc_html( $labels[ $scope ] ?? '' ) . '</label>';
		}
		echo '</fieldset>';
		echo '<p class="ttm-actions"><button type="submit" name="decision" value="approve" class="ttm-approve">' . esc_html__( 'Approve', 'techthrill-mcp' ) . '</button> ';
		echo '<button type="submit" name="decision" value="deny" class="ttm-deny">' . esc_html__( 'Deny', 'techthrill-mcp' ) . '</button></p>';
		echo '</form>';

		$this->page( esc_html__( 'Connect an AI client', 'techthrill-mcp' ), (string) ob_get_clean(), 200 );
	}

	/**
	 * Prints an error page.
	 *
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 */
	private function renderError( string $message, int $status ): void {
		$this->page(
			esc_html__( 'Connection not possible', 'techthrill-mcp' ),
			'<h1>' . esc_html__( 'This connection request cannot be completed', 'techthrill-mcp' ) . '</h1><p>' . esc_html( $message ) . '</p>',
			$status
		);
	}

	/**
	 * Sends headers and prints the page shell. $body must already be escaped.
	 *
	 * @param string $title  Escaped title.
	 * @param string $body   Escaped HTML body.
	 * @param int    $status HTTP status.
	 */
	private function page( string $title, string $body, int $status ): void {
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
