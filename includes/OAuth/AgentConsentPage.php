<?php
/**
 * The approval page for AI agent requests.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\OAuth;

use TechThrill\MCP\Auth\Scopes;

defined( 'ABSPATH' ) || exit;

/**
 * Served from admin-post.php like the OAuth consent page. Shows the agent's name, the short code the agent
 * displayed and where the request came from, so the administrator can tell their own request from a stranger's.
 */
final class AgentConsentPage {

	const ACTION = 'techthrill_mcp_agent';

	const NONCE = 'techthrill_mcp_agent_consent';

	private AgentConsentFlow $flow;

	/**
	 * @param AgentConsentFlow $flow Decision logic.
	 */
	public function __construct( AgentConsentFlow $flow ) {
		$this->flow = $flow;
	}

	/**
	 * Approval link for a request.
	 *
	 * @param string $requestId Request ID.
	 */
	public static function urlFor( string $requestId ): string {
		return admin_url( 'admin-post.php?action=' . self::ACTION . '&request=' . rawurlencode( $requestId ) );
	}

	/**
	 * Handles a GET (show the page) or POST (approve or deny).
	 */
	public function handle(): void {
		$post   = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) );
		$source = $post ? $_POST : $_GET; // phpcs:ignore WordPress.Security.NonceVerification -- The nonce is verified below for POST; GET only displays a form and changes nothing.

		$request_id = isset( $source['request'] ) && is_string( $source['request'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', wp_unslash( $source['request'] ) ) : '';

		$scopes = array();
		if ( $post && isset( $_POST['scopes'] ) && is_array( $_POST['scopes'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified through the flow.
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
			(string) $request_id,
			is_user_logged_in(),
			current_user_can( 'manage_options' ),
			'' !== $nonce && false !== wp_verify_nonce( $nonce, self::NONCE ),
			get_current_user_id(),
			Scopes::sanitize( $scopes ),
			$decision
		);

		switch ( $result['type'] ) {
			case 'login':
				wp_safe_redirect( wp_login_url( self::urlFor( (string) $request_id ) ) );
				exit;
			case 'consent':
				$this->renderConsent( (string) $request_id, $result['view'], $result['checked'], $result['notice'] ?? '' );
				exit;
			case 'done':
				$this->renderDone( (string) $result['outcome'] );
				exit;
			default:
				Page::send(
					esc_html__( 'Connection not possible', 'techthrill-mcp' ),
					'<h1>' . esc_html__( 'This request cannot be completed', 'techthrill-mcp' ) . '</h1><p>' . esc_html( (string) ( $result['message'] ?? '' ) ) . '</p>',
					(int) ( $result['status'] ?? 400 )
				);
				exit;
		}
	}

	/**
	 * Prints the approval screen.
	 *
	 * @param string   $requestId Request ID.
	 * @param array    $view      Label, code, IP and time of the request.
	 * @param string[] $checked   Scopes ticked by default.
	 * @param string   $notice    Optional message above the form.
	 */
	private function renderConsent( string $requestId, array $view, array $checked, string $notice ): void {
		$labels = array(
			'wp-cli'      => __( 'Run WP-CLI commands (full control of the site)', 'techthrill-mcp' ),
			'db:read'     => __( 'Read the database', 'techthrill-mcp' ),
			'db:write'    => __( 'Change the database', 'techthrill-mcp' ),
			'files:read'  => __( 'Read files', 'techthrill-mcp' ),
			'files:write' => __( 'Change, copy and delete files', 'techthrill-mcp' ),
		);
		$user   = wp_get_current_user();

		ob_start();
		echo '<h1>' . esc_html__( 'Let this AI agent work on your site?', 'techthrill-mcp' ) . '</h1>';
		if ( '' !== $notice ) {
			echo '<p class="ttm-notice">' . esc_html( $notice ) . '</p>';
		}
		echo '<p class="ttm-client"><strong>' . esc_html( (string) $view['label'] ) . '</strong><br />';
		/* translators: %s: short code shown by the AI agent */
		echo '<span>' . esc_html( sprintf( __( 'Code: %s', 'techthrill-mcp' ), (string) $view['user_code'] ) ) . '</span><br />';
		/* translators: %s: IP address the request came from */
		echo '<span>' . esc_html( sprintf( __( 'Request came from IP address %s', 'techthrill-mcp' ), (string) $view['ip'] ) ) . '</span></p>';
		echo '<p><strong>' . esc_html__( 'Only approve if the code above is exactly the one your AI agent just showed you.', 'techthrill-mcp' ) . '</strong> ';
		/* translators: %s: administrator display name */
		echo esc_html( sprintf( __( 'If you approve, it acts as you (%s) with only the permissions you tick, for 30 days. You can revoke it any time under TechThrill MCP > Tokens.', 'techthrill-mcp' ), $user->display_name ) ) . '</p>';

		echo '<form method="post" action="' . esc_url( self::urlFor( $requestId ) ) . '">';
		echo '<input type="hidden" name="request" value="' . esc_attr( $requestId ) . '" />';
		wp_nonce_field( self::NONCE );
		echo '<fieldset><legend>' . esc_html__( 'Permissions', 'techthrill-mcp' ) . '</legend>';
		foreach ( Scopes::ALL as $scope ) {
			echo '<label><input type="checkbox" name="scopes[]" value="' . esc_attr( $scope ) . '"' . checked( in_array( $scope, $checked, true ), true, false ) . ' /> <code>' . esc_html( $scope ) . '</code> ' . esc_html( $labels[ $scope ] ?? '' ) . '</label>';
		}
		echo '</fieldset>';
		echo '<p class="ttm-actions"><button type="submit" name="decision" value="approve" class="ttm-approve">' . esc_html__( 'Approve', 'techthrill-mcp' ) . '</button> ';
		echo '<button type="submit" name="decision" value="deny" class="ttm-deny">' . esc_html__( 'Deny', 'techthrill-mcp' ) . '</button></p>';
		echo '</form>';

		Page::send( esc_html__( 'Approve an AI agent', 'techthrill-mcp' ), (string) ob_get_clean(), 200 );
	}

	/**
	 * Prints the confirmation after an answer.
	 *
	 * @param string $outcome approved or denied.
	 */
	private function renderDone( string $outcome ): void {
		$text = 'approved' === $outcome
			? __( 'Approved. Your AI agent receives its access within a few seconds. You can close this tab and go back to it.', 'techthrill-mcp' )
			: __( 'Denied. Nothing was shared with the AI agent. You can close this tab.', 'techthrill-mcp' );

		Page::send( esc_html__( 'Done', 'techthrill-mcp' ), '<h1>' . esc_html__( 'Done', 'techthrill-mcp' ) . '</h1><p>' . esc_html( $text ) . '</p>', 200 );
	}
}
