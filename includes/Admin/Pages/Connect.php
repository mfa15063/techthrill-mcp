<?php
/**
 * Connect tab: status card, create-connection form and the one-time token box.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Admin\Pages;

use TechThrill\MCP\Admin\Flash;
use TechThrill\MCP\Admin\PharInstaller;
use TechThrill\MCP\Admin\Snippets;
use TechThrill\MCP\Auth\Scopes;
use TechThrill\MCP\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Connect tab and handles token creation and the WP-CLI download.
 */
final class Connect {

	const REST_CHECK_TRANSIENT = 'techthrill_mcp_rest_check';

	private Plugin $plugin;

	private PharInstaller $installer;

	/**
	 * The connection created in this request, shown once and never stored.
	 *
	 * @var array{token:string,id:int,name:string}|null
	 */
	private ?array $created = null;

	/**
	 * @param Plugin        $plugin    Plugin instance.
	 * @param PharInstaller $installer WP-CLI phar installer.
	 */
	public function __construct( Plugin $plugin, PharInstaller $installer ) {
		$this->plugin    = $plugin;
		$this->installer = $installer;
	}

	/**
	 * Prints the tab.
	 */
	public function render(): void {
		$this->renderStatusCard();
		$this->renderNewToken();
		$this->renderForm();
	}

	/**
	 * Hooked to the plugin page's load action: handles the create-connection form when it was posted.
	 *
	 * The form posts to the plugin page itself (not admin-post.php) so the new token is rendered
	 * in this response and never written to a transient or option.
	 */
	public function maybeHandleCreate(): void {
		if ( ! isset( $_POST['ttm_action'] ) || 'create_token' !== sanitize_key( wp_unslash( $_POST['ttm_action'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Routing only; handleCreate() verifies the nonce.
			return;
		}

		$this->handleCreate();
	}

	/**
	 * Creates a token. On success the token is kept in memory for this request's render; on failure
	 * a notice is stored and the browser is redirected back to the tab.
	 */
	public function handleCreate(): void {
		Flash::guard( 'techthrill_mcp_create_token' );

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in Flash::guard().
		$name = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 100 ) : substr( $name, 0, 100 );
		if ( '' === $name ) {
			Flash::notice( 'error', __( 'Give the connection a name.', 'techthrill-mcp' ) );
			Flash::redirect( 'connect' );
		}

		$raw_scopes = isset( $_POST['scopes'] ) && is_array( $_POST['scopes'] ) ? array_filter( wp_unslash( $_POST['scopes'] ), 'is_scalar' ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified in Flash::guard(); sanitized by Scopes::sanitize().
		$scopes     = Scopes::sanitize( $raw_scopes );
		if ( ! $scopes ) {
			Flash::notice( 'error', __( 'Choose at least one permission.', 'techthrill-mcp' ) );
			Flash::redirect( 'connect' );
		}

		$choice = isset( $_POST['expiry'] ) ? sanitize_key( wp_unslash( $_POST['expiry'] ) ) : 'never'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$date   = isset( $_POST['expiry_date'] ) ? sanitize_text_field( wp_unslash( $_POST['expiry_date'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		try {
			$expires_at = $this->expiryFrom( $choice, $date );
		} catch ( \InvalidArgumentException $e ) {
			Flash::notice( 'error', $e->getMessage() );
			Flash::redirect( 'connect' );
		}

		try {
			$created = $this->plugin->tokenManager()->create( $name, $scopes, get_current_user_id(), $expires_at );
		} catch ( \RuntimeException $e ) {
			Flash::notice( 'error', __( 'The token could not be saved. Please try again.', 'techthrill-mcp' ) );
			Flash::redirect( 'connect' );
		}

		$this->created = array(
			'token' => (string) $created['token'],
			'id'    => (int) $created['id'],
			'name'  => $name,
		);
	}

	/**
	 * Admin-post handler: downloads WP-CLI.
	 */
	public function handleInstallPhar(): void {
		Flash::guard( 'techthrill_mcp_install_phar' );

		if ( ! $this->plugin->hostCapabilities()->execAvailable() ) {
			Flash::notice( 'error', __( 'This host cannot run commands, so WP-CLI is not usable here.', 'techthrill-mcp' ) );
			Flash::redirect( 'connect' );
		}

		$result = $this->installer->download();
		if ( is_wp_error( $result ) ) {
			Flash::notice( 'error', $result->get_error_message() );
		} else {
			Flash::notice( 'success', __( 'WP-CLI was downloaded and verified.', 'techthrill-mcp' ) );
		}
		Flash::redirect( 'connect' );
	}

	/**
	 * Converts the expiry form choice into a UTC datetime string or null.
	 *
	 * @param string $choice never, 7, 30, 90 or custom.
	 * @param string $date   Y-m-d, used for custom.
	 * @return string|null
	 * @throws \InvalidArgumentException When the date is invalid or not in the future.
	 */
	private function expiryFrom( string $choice, string $date ): ?string {
		if ( in_array( $choice, array( '7', '30', '90' ), true ) ) {
			return gmdate( 'Y-m-d H:i:s', time() + (int) $choice * DAY_IN_SECONDS );
		}

		if ( 'custom' !== $choice ) {
			return null;
		}

		$parsed = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? \DateTime::createFromFormat( 'Y-m-d', $date, new \DateTimeZone( 'UTC' ) ) : false;
		if ( ! $parsed || $parsed->format( 'Y-m-d' ) !== $date ) {
			throw new \InvalidArgumentException( __( 'Choose a valid expiry date.', 'techthrill-mcp' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		$parsed->setTime( 23, 59, 59 );
		if ( $parsed->getTimestamp() <= time() ) {
			throw new \InvalidArgumentException( __( 'The expiry date must be in the future.', 'techthrill-mcp' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $parsed->format( 'Y-m-d H:i:s' );
	}

	private function renderStatusCard(): void {
		$host   = $this->plugin->hostCapabilities();
		$rows   = array();
		$https  = $host->isHttps();
		$local  = $host->isLocalHost();
		$rows[] = array(
			__( 'HTTPS', 'techthrill-mcp' ),
			( $https || $local ) ? 'ok' : 'warning',
			$https ? __( 'This site is served over HTTPS.', 'techthrill-mcp' ) : ( $local ? __( 'Local site: HTTPS is not required.', 'techthrill-mcp' ) : __( 'This site is not served over HTTPS. Tokens would travel in clear text.', 'techthrill-mcp' ) ),
			'',
		);

		$rest   = $this->restStatus();
		$rows[] = array( __( 'REST API reachable', 'techthrill-mcp' ), $rest['state'], $rest['text'], '' );

		$safe   = $this->plugin->safeMode()->isEnabled();
		$rows[] = array(
			__( 'Safe mode', 'techthrill-mcp' ),
			$safe ? 'ok' : 'warning',
			$safe
				? __( 'On: destructive operations are refused.', 'techthrill-mcp' )
				: __( 'Off: connected AI clients can run destructive SQL (after confirmation), delete directories and run any allowed WP-CLI command. Turn it on for production sites.', 'techthrill-mcp' ),
			$safe ? '' : 'safe-mode',
		);

		$exec   = $host->execAvailable();
		$rows[] = array(
			__( 'PHP exec', 'techthrill-mcp' ),
			$exec ? 'ok' : 'warning',
			$exec ? __( 'Available.', 'techthrill-mcp' ) : __( 'Not available: commands cannot be run on this host.', 'techthrill-mcp' ),
			'',
		);

		$binary = $host->wpBinary();
		if ( null !== $binary ) {
			$is_phar = 2 === count( $binary ) && '.phar' === strtolower( substr( $binary[1], -5 ) );
			/* translators: 1: command-line PHP binary, 2: phar file name */
			$text   = $is_phar ? sprintf( __( 'via %1$s %2$s', 'techthrill-mcp' ), $binary[0], basename( $binary[1] ) ) : implode( ' ', $binary );
			$rows[] = array( __( 'WP-CLI', 'techthrill-mcp' ), 'ok', $text, '' );
		} elseif ( '' !== $host->pharPath() && is_file( $host->pharPath() ) && null === $host->phpBinary() ) {
			$rows[] = array( __( 'WP-CLI', 'techthrill-mcp' ), 'warning', __( 'wp-cli.phar is installed but no command-line PHP was found to run it. Set the PHP CLI binary path under Settings.', 'techthrill-mcp' ), '' );
		} elseif ( $exec ) {
			$rows[] = array( __( 'WP-CLI', 'techthrill-mcp' ), 'warning', __( 'Not found.', 'techthrill-mcp' ), 'download' );
		} else {
			$rows[] = array( __( 'WP-CLI', 'techthrill-mcp' ), 'warning', __( 'Not possible on this host.', 'techthrill-mcp' ), '' );
		}

		echo '<div class="ttm-card"><h2>' . esc_html__( 'Status', 'techthrill-mcp' ) . '</h2><ul class="ttm-status-list">';
		foreach ( $rows as $row ) {
			echo '<li class="ttm-status-row ttm-' . esc_attr( $row[1] ) . '"><span class="ttm-dot" aria-hidden="true"></span><strong>' . esc_html( $row[0] ) . '</strong> <span>' . esc_html( $row[2] ) . '</span>';
			if ( 'safe-mode' === $row[3] ) {
				echo ' <a class="button button-small" href="' . esc_url( Flash::tabUrl( 'settings' ) ) . '">' . esc_html__( 'Open Settings', 'techthrill-mcp' ) . '</a>';
			}
			if ( 'download' === $row[3] ) {
				echo ' <form class="ttm-inline-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				echo '<input type="hidden" name="action" value="techthrill_mcp_install_phar" />';
				wp_nonce_field( 'techthrill_mcp_install_phar' );
				echo '<button type="submit" class="button button-small">' . esc_html__( 'Download WP-CLI', 'techthrill-mcp' ) . '</button></form>';
			}
			echo '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Self-request to the endpoint; a 401 means the route is reachable and guarded. Cached for five minutes.
	 *
	 * @return array{state:string,text:string}
	 */
	private function restStatus(): array {
		$cached = get_transient( self::REST_CHECK_TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['state'], $cached['text'] ) ) {
			return $cached;
		}

		$response = wp_remote_post(
			$this->plugin->endpointUrl(),
			array(
				'timeout'   => 5,
				'headers'   => array( 'Content-Type' => 'application/json' ),
				'body'      => '{}',
				'sslverify' => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WordPress hook.
			)
		);

		if ( is_wp_error( $response ) ) {
			$result = array(
				'state' => 'warning',
				/* translators: %s: error message */
				'text'  => sprintf( __( 'The endpoint could not be reached: %s', 'techthrill-mcp' ), $response->get_error_message() ),
			);
		} else {
			$code   = (int) wp_remote_retrieve_response_code( $response );
			$result = 401 === $code
				? array(
					'state' => 'ok',
					'text'  => __( 'The endpoint is reachable and asks for a token.', 'techthrill-mcp' ),
				)
				: array(
					'state' => 'warning',
					/* translators: %d: HTTP status code */
					'text'  => sprintf( __( 'Unexpected response (HTTP %d). Expected 401 without a token.', 'techthrill-mcp' ), $code ),
				);
		}

		set_transient( self::REST_CHECK_TRANSIENT, $result, 5 * MINUTE_IN_SECONDS );

		return $result;
	}

	private function renderNewToken(): void {
		$new           = $this->created;
		$this->created = null;
		if ( null === $new || '' === $new['token'] ) {
			return;
		}

		$token = $new['token'];
		$slug  = Snippets::slug( html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ) );
		$url   = $this->plugin->endpointUrl();
		$tabs  = array(
			'claude' => array( __( 'Claude Code', 'techthrill-mcp' ), Snippets::claudeCode( $slug, $url, $token ) ),
			'codex'  => array( __( 'Codex', 'techthrill-mcp' ), Snippets::codex( $slug, $url, $token ) ),
			'json'   => array( __( 'JSON', 'techthrill-mcp' ), Snippets::json( $slug, $url, $token ) ),
		);

		echo '<div class="ttm-card ttm-token-box">';
		/* translators: %s: connection name */
		echo '<h2>' . esc_html( sprintf( __( 'Connection created: %s', 'techthrill-mcp' ), $new['name'] ) ) . '</h2>';
		echo '<p><strong>' . esc_html__( 'Copy this token now. It is shown only once and cannot be recovered.', 'techthrill-mcp' ) . '</strong></p>';
		echo '<p class="ttm-copy-row"><code id="ttm-token" class="ttm-token">' . esc_html( $token ) . '</code> ';
		$this->copyButton( 'ttm-token' );
		echo '</p><div class="ttm-snippet-tabs" role="tablist">';
		$first = true;
		foreach ( $tabs as $key => $tab ) {
			echo '<button type="button" class="button' . ( $first ? ' button-primary' : '' ) . '" data-ttm-tab="' . esc_attr( $key ) . '">' . esc_html( $tab[0] ) . '</button> ';
			$first = false;
		}
		echo '</div>';
		$first = true;
		foreach ( $tabs as $key => $tab ) {
			echo '<div class="ttm-snippet' . ( $first ? '' : ' ttm-hidden' ) . '" data-ttm-panel="' . esc_attr( $key ) . '">';
			echo '<pre><code id="ttm-snippet-' . esc_attr( $key ) . '">' . esc_html( $tab[1] ) . '</code></pre>';
			$this->copyButton( 'ttm-snippet-' . $key );
			echo '</div>';
			$first = false;
		}
		echo '</div>';
	}

	/**
	 * @param string $target_id Id of the element whose text is copied.
	 */
	private function copyButton( string $target_id ): void {
		echo '<button type="button" class="button button-small" data-ttm-copy="' . esc_attr( $target_id ) . '" data-ttm-copied="' . esc_attr__( 'Copied', 'techthrill-mcp' ) . '">' . esc_html__( 'Copy', 'techthrill-mcp' ) . '</button>';
	}

	private function renderForm(): void {
		$labels = array(
			'wp-cli'      => __( 'Run WP-CLI commands', 'techthrill-mcp' ),
			'db:read'     => __( 'Read the database', 'techthrill-mcp' ),
			'db:write'    => __( 'Change the database', 'techthrill-mcp' ),
			'files:read'  => __( 'Read files', 'techthrill-mcp' ),
			'files:write' => __( 'Change and delete files', 'techthrill-mcp' ),
		);

		echo '<div class="ttm-card"><h2>' . esc_html__( 'Create a connection', 'techthrill-mcp' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( Flash::tabUrl( 'connect' ) ) . '" class="ttm-create-form">';
		echo '<input type="hidden" name="ttm_action" value="create_token" />';
		wp_nonce_field( 'techthrill_mcp_create_token' );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="ttm-name">' . esc_html__( 'Name', 'techthrill-mcp' ) . '</label></th><td>';
		echo '<input type="text" id="ttm-name" name="name" class="regular-text" maxlength="100" required /></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Permissions', 'techthrill-mcp' ) . '</th><td>';
		echo '<p class="ttm-presets">';
		echo '<button type="button" class="button" data-ttm-preset="' . esc_attr( implode( ',', Scopes::ALL ) ) . '">' . esc_html__( 'Full access', 'techthrill-mcp' ) . '</button> ';
		echo '<button type="button" class="button" data-ttm-preset="db:read,files:read">' . esc_html__( 'Read only', 'techthrill-mcp' ) . '</button> ';
		echo '<button type="button" class="button" data-ttm-preset="files:read,files:write">' . esc_html__( 'Files only', 'techthrill-mcp' ) . '</button></p>';
		foreach ( Scopes::ALL as $scope ) {
			echo '<label class="ttm-scope"><input type="checkbox" name="scopes[]" value="' . esc_attr( $scope ) . '" /> ';
			echo '<code>' . esc_html( $scope ) . '</code> ' . esc_html( $labels[ $scope ] ?? '' ) . '</label><br />';
		}
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="ttm-expiry">' . esc_html__( 'Expires', 'techthrill-mcp' ) . '</label></th><td>';
		echo '<select id="ttm-expiry" name="expiry">';
		$options = array(
			'never'  => __( 'Never', 'techthrill-mcp' ),
			'7'      => __( 'In 7 days', 'techthrill-mcp' ),
			'30'     => __( 'In 30 days', 'techthrill-mcp' ),
			'90'     => __( 'In 90 days', 'techthrill-mcp' ),
			'custom' => __( 'On a date', 'techthrill-mcp' ),
		);
		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select> <input type="date" id="ttm-expiry-date" name="expiry_date" /></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Create connection', 'techthrill-mcp' ) );
		echo '</form></div>';
	}
}
