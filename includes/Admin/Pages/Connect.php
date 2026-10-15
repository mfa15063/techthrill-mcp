<?php
/**
 * Connect tab: status card, create-connection form and the one-time token box.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Admin\Pages;

use TechThrill\MCP\Admin\Flash;
use TechThrill\MCP\Admin\PharInstaller;
use TechThrill\MCP\Admin\QuickConnect;
use TechThrill\MCP\Admin\Snippets;
use TechThrill\MCP\Auth\Scopes;
use TechThrill\MCP\OAuth\Availability;
use TechThrill\MCP\OAuth\Metadata;
use TechThrill\MCP\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Connect tab and handles token creation and the WP-CLI download.
 */
final class Connect {

	const REST_CHECK_TRANSIENT = 'techthrill_mcp_rest_check';

	const WELL_KNOWN_TRANSIENT = 'techthrill_mcp_well_known_check';

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
		$problems = $this->plugin->oauth()->problems();

		$this->renderOneClick( $problems );
		$this->renderAgentPrompt();
		$this->renderStatusCard();

		// The bearer-token flow stays available as an Advanced fallback. It opens by itself right after a token
		// is created (the one-time token box lives inside it) or when the one-paste flow cannot work on this site.
		$open = null !== $this->created || ! $this->plugin->oauth()->isAvailable();
		echo '<details class="ttm-advanced"' . ( $open ? ' open' : '' ) . '><summary>' . esc_html__( 'Advanced: connect with a bearer token', 'techthrill-mcp' ) . '</summary>';
		$this->renderNewToken();
		$this->renderQuickConnect();
		$this->renderForm();
		echo '</details>';
	}

	/**
	 * Hooked to the plugin page's load action: handles the create-connection form when it was posted.
	 *
	 * The form posts to the plugin page itself (not admin-post.php) so the new token is rendered
	 * in this response and never written to a transient or option.
	 */
	public function maybeHandleCreate(): void {
		$action = isset( $_POST['ttm_action'] ) ? sanitize_key( wp_unslash( $_POST['ttm_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Routing only; the handlers verify the nonce.
		if ( 'quick_connect' === $action ) {
			$this->handleQuickConnect();
		} elseif ( 'create_token' === $action ) {
			$this->handleCreate();
		}
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

		$this->storeToken( $name, $scopes, $expires_at );
	}

	/**
	 * Quick connect: one button creates a token from a preset and shows ready-to-paste commands.
	 */
	public function handleQuickConnect(): void {
		Flash::guard( 'techthrill_mcp_quick_connect' );

		$preset = isset( $_POST['preset'] ) ? sanitize_key( wp_unslash( $_POST['preset'] ) ) : QuickConnect::DEFAULT_PRESET; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in Flash::guard().

		$this->storeToken( QuickConnect::name( $preset, time() ), QuickConnect::scopesFor( $preset ), null );
	}

	/**
	 * Creates the token and keeps it in memory for this request's render; on failure a notice is stored and the browser is redirected back.
	 *
	 * @param string      $name       Connection name.
	 * @param string[]    $scopes     Scopes.
	 * @param string|null $expires_at UTC expiry or null.
	 */
	private function storeToken( string $name, array $scopes, ?string $expires_at ): void {
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

	/**
	 * Card with a copy-and-paste prompt for AI agents that can run commands (Claude Code, Codex, Cursor).
	 */
	private function renderAgentPrompt(): void {
		if ( ! $this->plugin->oauth()->isAvailable() ) {
			return;
		}

		echo '<div class="ttm-card ttm-agent-prompt"><h2>' . esc_html__( 'Connect an AI agent with a prompt', 'techthrill-mcp' ) . '</h2>';
		echo '<p>' . esc_html__( 'Copy this prompt and paste it into Claude Code, Codex or the Cursor agent. The agent shows you a link; open it, check the code, choose the permissions and approve. The prompt contains no token and nothing secret.', 'techthrill-mcp' ) . '</p>';
		echo '<p class="ttm-warning">' . esc_html__( 'A normal chat window (for example free Claude or ChatGPT in the browser) cannot do this. If you paste the prompt there, it replies that you need an agent that can run commands, or a plan with custom connectors. For those use "Connect with one paste" above.', 'techthrill-mcp' ) . '</p>';
		echo '<pre class="ttm-prompt"><code id="ttm-agent-prompt">' . esc_html( $this->plugin->oauth()->agentPrompt() ) . '</code></pre>';
		$this->copyButton( 'ttm-agent-prompt' );
		echo '</div>';
	}

	/**
	 * Top card: connect by pasting only the endpoint URL; the client logs in through the consent screen.
	 *
	 * @param string[] $problems Availability problem codes.
	 */
	private function renderOneClick( array $problems ): void {
		$url  = $this->plugin->endpointUrl();
		$slug = Snippets::slug( html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ) );

		echo '<div class="ttm-card ttm-oneclick"><h2>' . esc_html__( 'Connect with one paste', 'techthrill-mcp' ) . '</h2>';

		if ( in_array( Availability::PERMALINKS, $problems, true ) || in_array( Availability::HTTPS, $problems, true ) ) {
			echo '<p>' . esc_html__( 'The one-paste connection is not available on this site yet:', 'techthrill-mcp' ) . '</p><ul class="ttm-list">';
			if ( in_array( Availability::PERMALINKS, $problems, true ) ) {
				echo '<li>' . esc_html__( 'Turn on pretty permalinks under Settings > Permalinks (choose anything except Plain).', 'techthrill-mcp' ) . '</li>';
			}
			if ( in_array( Availability::HTTPS, $problems, true ) ) {
				echo '<li>' . esc_html__( 'The site address must start with https://.', 'techthrill-mcp' ) . '</li>';
			}
			echo '</ul><p>' . esc_html__( 'Until then, use the bearer-token section below.', 'techthrill-mcp' ) . '</p></div>';
			return;
		}

		echo '<p>' . esc_html__( 'Paste only this address into your AI app. You then approve the connection on this site. Nothing secret is copied, and read-only access is pre-selected.', 'techthrill-mcp' ) . '</p>';
		echo '<p class="ttm-copy-row"><code id="ttm-endpoint" class="ttm-token">' . esc_html( $url ) . '</code> ';
		$this->copyButton( 'ttm-endpoint' );
		echo '</p>';

		if ( in_array( Availability::LOCAL, $problems, true ) ) {
			echo '<p class="ttm-warning">' . esc_html__( 'This looks like a local or private site. Claude on the web and ChatGPT cannot reach it. Use Claude Code or Codex on this same computer.', 'techthrill-mcp' ) . '</p>';
		}

		$claude_code = Snippets::claudeCodeOAuth( $slug, $url );
		$codex       = Snippets::codexOAuth( $slug, $url );
		$tabs        = array(
			'claude'      => __( 'Claude', 'techthrill-mcp' ),
			'chatgpt'     => __( 'ChatGPT', 'techthrill-mcp' ),
			'claude-code' => __( 'Claude Code', 'techthrill-mcp' ),
			'codex'       => __( 'Codex', 'techthrill-mcp' ),
			'cursor'      => __( 'Cursor', 'techthrill-mcp' ),
			'vscode'      => __( 'VS Code', 'techthrill-mcp' ),
		);

		echo '<div class="ttm-snippet-tabs" role="tablist">';
		$first = true;
		foreach ( $tabs as $key => $label ) {
			echo '<button type="button" class="button' . ( $first ? ' button-primary' : '' ) . '" data-ttm-group="connect" data-ttm-tab="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</button> ';
			$first = false;
		}
		echo '</div>';

		// Each step is plain text, a copyable command (id, text) or an install link (href, label).
		$panels = array(
			'claude'      => array(
				array( 'text' => __( 'Open Claude (claude.ai or the desktop app) and go to Customize > Connectors > Add custom connector.', 'techthrill-mcp' ) ),
				array( 'text' => __( 'Paste the address above and add it, then press Connect.', 'techthrill-mcp' ) ),
				array( 'text' => __( 'Log in to this site if asked, choose the permissions and approve.', 'techthrill-mcp' ) ),
				array( 'text' => __( 'In a chat, open the + menu and turn the connector on for that chat.', 'techthrill-mcp' ) ),
			),
			'chatgpt'     => array(
				array( 'text' => __( 'In ChatGPT, turn on Developer mode, then add a custom MCP connector.', 'techthrill-mcp' ) ),
				array( 'text' => __( 'Paste the address above and choose OAuth if asked.', 'techthrill-mcp' ) ),
				array( 'text' => __( 'Log in to this site if asked, choose the permissions and approve.', 'techthrill-mcp' ) ),
			),
			'claude-code' => array(
				array( 'text' => __( 'Run this command in your terminal:', 'techthrill-mcp' ) ),
				array( 'code' => array( 'ttm-cmd-claude-code', $claude_code ) ),
				array( 'text' => __( 'In Claude Code run /mcp (or claude mcp login) and approve in the browser.', 'techthrill-mcp' ) ),
			),
			'codex'       => array(
				array( 'text' => __( 'Run this command in your terminal:', 'techthrill-mcp' ) ),
				array( 'code' => array( 'ttm-cmd-codex', $codex ) ),
				array( 'text' => __( 'Then run codex mcp login and approve in the browser.', 'techthrill-mcp' ) ),
			),
			'cursor'      => array(
				array( 'link' => array( Snippets::cursorLink( $slug, $url ), __( 'Add to Cursor', 'techthrill-mcp' ) ) ),
				array( 'text' => __( 'Cursor opens with the server filled in. Approve the login when it asks.', 'techthrill-mcp' ) ),
			),
			'vscode'      => array(
				array( 'link' => array( Snippets::vscodeLink( $slug, $url ), __( 'Add to VS Code', 'techthrill-mcp' ) ) ),
				array( 'text' => __( 'VS Code opens with the server filled in. Approve the login when it asks.', 'techthrill-mcp' ) ),
			),
		);
		$first  = true;
		foreach ( $panels as $key => $steps ) {
			echo '<div class="ttm-snippet' . ( $first ? '' : ' ttm-hidden' ) . '" data-ttm-group="connect" data-ttm-panel="' . esc_attr( $key ) . '"><ol class="ttm-quick-steps">';
			foreach ( $steps as $step ) {
				if ( isset( $step['code'] ) ) {
					// A command sits between two numbered steps, so the list is closed and reopened around it.
					echo '</ol><pre><code id="' . esc_attr( $step['code'][0] ) . '">' . esc_html( $step['code'][1] ) . '</code></pre>';
					$this->copyButton( $step['code'][0] );
					echo '<ol class="ttm-quick-steps" start="2">';
				} elseif ( isset( $step['link'] ) ) {
					echo '<li><a class="button button-primary" href="' . esc_url( $step['link'][0], array( 'cursor', 'vscode' ) ) . '">' . esc_html( $step['link'][1] ) . '</a></li>';
				} else {
					echo '<li>' . esc_html( $step['text'] ) . '</li>';
				}
			}
			echo '</ol></div>';
			$first = false;
		}

		echo '<p class="description">' . esc_html__( 'You can see and revoke every connection on the Tokens tab.', 'techthrill-mcp' ) . '</p></div>';
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

		if ( $this->plugin->oauth()->isAvailable() ) {
			$well_known = $this->wellKnownStatus();
			$rows[]     = array( __( 'One-paste discovery', 'techthrill-mcp' ), $well_known['state'], $well_known['text'], '' );
		}

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
	 * Self-request to the root /.well-known document. Cached for an hour.
	 *
	 * @return array{state:string,text:string}
	 */
	private function wellKnownStatus(): array {
		$cached = get_transient( self::WELL_KNOWN_TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['state'], $cached['text'] ) ) {
			return $cached;
		}

		$issuer = $this->plugin->oauth()->metadata()->issuer();
		$parts  = wp_parse_url( $issuer );
		$root   = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );

		$response = wp_remote_get(
			$root . Metadata::wellKnownPath( 'oauth-authorization-server', $issuer ),
			array(
				'timeout'   => 5,
				'sslverify' => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WordPress hook.
			)
		);

		$body = is_wp_error( $response ) ? null : json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( is_array( $body ) && isset( $body['issuer'] ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$result = array(
				'state' => 'ok',
				'text'  => __( 'The login discovery document is reachable.', 'techthrill-mcp' ),
			);
		} else {
			$result = array(
				'state' => 'warning',
				'text'  => __( 'The /.well-known document is not reachable (a server rule or security plugin may block it). Most clients still work through the 401 link; if one does not, use the bearer-token section.', 'techthrill-mcp' ),
			);
		}

		set_transient( self::WELL_KNOWN_TRANSIENT, $result, HOUR_IN_SECONDS );

		return $result;
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

	private function renderQuickConnect(): void {
		echo '<div class="ttm-card ttm-quick"><h2>' . esc_html__( 'Quick connect', 'techthrill-mcp' ) . '</h2>';
		echo '<p>' . esc_html__( 'Pick what the AI client may do, press the button, then paste one command into Claude Code or Codex. No other setup.', 'techthrill-mcp' ) . '</p>';
		echo '<form method="post" action="' . esc_url( Flash::tabUrl( 'connect' ) ) . '" class="ttm-quick-form">';
		echo '<input type="hidden" name="ttm_action" value="quick_connect" />';
		wp_nonce_field( 'techthrill_mcp_quick_connect' );
		foreach ( QuickConnect::presets() as $key => $preset ) {
			echo '<label class="ttm-scope"><input type="radio" name="preset" value="' . esc_attr( $key ) . '"' . checked( QuickConnect::DEFAULT_PRESET, $key, false ) . ' /> <strong>' . esc_html( $preset['label'] ) . '</strong> ';
			echo '<span class="description">(' . esc_html( implode( ', ', $preset['scopes'] ) ) . ')</span></label><br />';
		}
		submit_button( __( 'Create connection and show commands', 'techthrill-mcp' ), 'primary', 'submit', true, array( 'style' => 'margin-top:12px' ) );
		echo '</form>';
		echo '<ol class="ttm-quick-steps">';
		echo '<li>' . esc_html__( 'Claude Code: paste the Claude Code command, then run /mcp inside Claude Code to check it is connected.', 'techthrill-mcp' ) . '</li>';
		echo '<li>' . esc_html__( 'Codex: paste the Codex block (it sets one environment variable and adds the server to config.toml), then restart Codex.', 'techthrill-mcp' ) . '</li>';
		echo '<li>' . esc_html__( 'The token is shown once. You can revoke it any time on the Tokens tab.', 'techthrill-mcp' ) . '</li>';
		echo '</ol></div>';
	}

	private function renderForm(): void {
		$labels = array(
			'wp-cli'      => __( 'Run WP-CLI commands', 'techthrill-mcp' ),
			'db:read'     => __( 'Read the database', 'techthrill-mcp' ),
			'db:write'    => __( 'Change the database', 'techthrill-mcp' ),
			'files:read'  => __( 'Read files', 'techthrill-mcp' ),
			'files:write' => __( 'Change and delete files', 'techthrill-mcp' ),
		);

		echo '<div class="ttm-card"><h2>' . esc_html__( 'Custom connection', 'techthrill-mcp' ) . '</h2>';
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
