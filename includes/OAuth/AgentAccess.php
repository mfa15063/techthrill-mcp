<?php
/**
 * Access requests from AI agents that can run commands (the "connect with a prompt" flow).
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\OAuth;

use TechThrill\MCP\Auth\Scopes;
use TechThrill\MCP\Auth\TokenManager;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- OAuthException messages are plain-text JSON error fields, never printed as HTML.
defined( 'ABSPATH' ) || exit;

/**
 * An agent asks for access, shows the administrator a link and a short code, the administrator picks the
 * permissions and approves, and the agent collects a token once. No secret travels through the chat:
 * the token is created only after approval and is handed over a single time.
 */
final class AgentAccess {

	const PREFIX = 'techthrill_mcp_oauth_agent_';

	/**
	 * Seconds an unapproved request stays valid.
	 */
	const REQUEST_TTL = 600;

	/**
	 * Seconds an approved token waits to be collected.
	 */
	const COLLECT_TTL = 300;

	const INTERVAL = 3;

	/**
	 * Lifetime of the token an approval creates, in days.
	 */
	const TOKEN_DAYS = 30;

	const START_LIMIT = 10;

	const START_WINDOW = 3600;

	const POLL_LIMIT = 60;

	const POLL_WINDOW = 60;

	const MAX_LABEL = 60;

	private OptionsGateway $options;

	private TokenManager $tokens;

	private AttemptLimiter $limiter;

	/**
	 * Returns the current unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * @param OptionsGateway $options Option access.
	 * @param TokenManager   $tokens  Token creation.
	 * @param AttemptLimiter $limiter Per-IP limiter.
	 * @param callable|null  $clock   Returns the current unix time.
	 */
	public function __construct( OptionsGateway $options, TokenManager $tokens, AttemptLimiter $limiter, ?callable $clock = null ) {
		$this->options = $options;
		$this->tokens  = $tokens;
		$this->limiter = $limiter;
		$this->clock   = $clock ?? 'time';
	}

	/**
	 * Starts a request.
	 *
	 * @param string $label Agent's name for itself, for example "Claude Code".
	 * @param string $ip    Client IP address.
	 * @return array{request_id:string,poll_code:string,user_code:string,expires_in:int,interval:int}
	 * @throws OAuthException When rate limited.
	 */
	public function start( string $label, string $ip ): array {
		if ( ! $this->limiter->allow( 'agent_start', $ip, self::START_LIMIT, self::START_WINDOW ) ) {
			throw new OAuthException( 'temporarily_unavailable', 'Too many requests. Try again later.', 429 );
		}

		$id        = Random::alnum( 24 );
		$poll_code = Random::alnum( 40 );
		$user_code = Random::code( 4 ) . '-' . Random::code( 4 );
		$label     = ClientName::clean( '' === trim( $label ) ? 'AI agent' : $label, self::MAX_LABEL );
		$now       = $this->now();

		$this->options->add(
			self::PREFIX . $id,
			array(
				'poll_hash'  => hash( 'sha256', $poll_code ),
				'user_code'  => $user_code,
				'label'      => $label,
				'ip'         => $ip,
				'status'     => 'pending',
				'created_at' => $now,
				'expires_at' => $now + self::REQUEST_TTL,
			)
		);

		return array(
			'request_id' => $id,
			'poll_code'  => $poll_code,
			'user_code'  => $user_code,
			'expires_in' => self::REQUEST_TTL,
			'interval'   => self::INTERVAL,
		);
	}

	/**
	 * What the consent page may show about a pending request, or null when it is unknown, expired or decided.
	 *
	 * @param string $requestId Request ID from the approval link.
	 * @return array{label:string,user_code:string,ip:string,created_at:int}|null
	 */
	public function find( string $requestId ): ?array {
		$record = $this->load( $requestId );
		if ( null === $record || 'pending' !== $record['status'] ) {
			return null;
		}

		return array(
			'label'      => (string) $record['label'],
			'user_code'  => (string) $record['user_code'],
			'ip'         => (string) $record['ip'],
			'created_at' => (int) $record['created_at'],
		);
	}

	/**
	 * Approves a pending request: creates the token and keeps it until the agent collects it.
	 *
	 * @param string   $requestId Request ID.
	 * @param int      $userId    Administrator who approved.
	 * @param string[] $scopes    Granted scopes.
	 * @throws OAuthException When the request is gone or no valid scope was chosen.
	 */
	public function approve( string $requestId, int $userId, array $scopes ): void {
		$scopes = Scopes::sanitize( $scopes );
		if ( array() === $scopes ) {
			throw new OAuthException( 'invalid_scope', 'Choose at least one permission.' );
		}

		$record = $this->load( $requestId );
		if ( null === $record || 'pending' !== $record['status'] ) {
			throw new OAuthException( 'invalid_request', 'This request has expired or was already answered.' );
		}

		$now     = $this->now();
		$created = $this->tokens->create(
			'AI agent: ' . $record['label'] . ' ' . gmdate( 'Y-m-d', $now ),
			$scopes,
			$userId,
			gmdate( 'Y-m-d H:i:s', $now + self::TOKEN_DAYS * DAY_IN_SECONDS )
		);

		$record['status']     = 'approved';
		$record['scopes']     = $scopes;
		$record['token']      = $created['token'];
		$record['expires_at'] = $now + self::COLLECT_TTL;
		$this->options->update( self::PREFIX . $requestId, $record );
	}

	/**
	 * Denies a pending request.
	 *
	 * @param string $requestId Request ID.
	 */
	public function deny( string $requestId ): void {
		$record = $this->load( $requestId );
		if ( null !== $record && 'pending' === $record['status'] ) {
			$record['status']     = 'denied';
			$record['expires_at'] = $this->now() + self::COLLECT_TTL;
			$this->options->update( self::PREFIX . $requestId, $record );
		}
	}

	/**
	 * The agent asks whether the administrator has answered. An approved token is returned once.
	 *
	 * @param string $requestId Request ID.
	 * @param string $pollCode  Secret poll code from start().
	 * @param string $ip        Client IP address.
	 * @return array{status:string,access_token?:string,token_type?:string,scope?:string,expires_in?:int,interval?:int}
	 * @throws OAuthException When rate limited.
	 */
	public function poll( string $requestId, string $pollCode, string $ip ): array {
		if ( ! $this->limiter->allow( 'agent_poll', $ip, self::POLL_LIMIT, self::POLL_WINDOW ) ) {
			throw new OAuthException( 'temporarily_unavailable', 'Polling too fast. Wait a few seconds between requests.', 429 );
		}

		$name   = self::PREFIX . $requestId;
		$record = 1 === preg_match( '/^[A-Za-z0-9]{24}$/D', $requestId ) ? $this->options->get( $name ) : null;
		if ( null === $record || ! hash_equals( (string) $record['poll_hash'], hash( 'sha256', $pollCode ) ) ) {
			return array( 'status' => 'expired' );
		}

		if ( (int) $record['expires_at'] <= $this->now() ) {
			$this->options->delete( $name );

			return array( 'status' => 'expired' );
		}
		if ( 'pending' === $record['status'] ) {
			return array(
				'status'   => 'pending',
				'interval' => self::INTERVAL,
			);
		}

		// Whoever deletes the record owns the answer, so a token is handed out at most once.
		if ( ! $this->options->delete( $name ) ) {
			return array( 'status' => 'expired' );
		}
		if ( 'denied' === $record['status'] ) {
			return array( 'status' => 'denied' );
		}

		return array(
			'status'       => 'approved',
			'access_token' => (string) $record['token'],
			'token_type'   => 'Bearer',
			'scope'        => implode( ' ', (array) $record['scopes'] ),
			'expires_in'   => self::TOKEN_DAYS * DAY_IN_SECONDS,
		);
	}

	/**
	 * Deletes expired requests.
	 *
	 * @return int Number deleted.
	 */
	public function prune(): int {
		$deleted = 0;
		foreach ( $this->options->names( self::PREFIX ) as $name ) {
			$record = $this->options->get( $name );
			if ( null === $record || (int) ( $record['expires_at'] ?? 0 ) <= $this->now() ) {
				if ( $this->options->delete( $name ) ) {
					++$deleted;
				}
			}
		}

		return $deleted;
	}

	/**
	 * A request that exists and has not expired, or null.
	 *
	 * @param string $requestId Request ID.
	 */
	private function load( string $requestId ): ?array {
		if ( 1 !== preg_match( '/^[A-Za-z0-9]{24}$/D', $requestId ) ) {
			return null;
		}
		$record = $this->options->get( self::PREFIX . $requestId );

		return null !== $record && (int) $record['expires_at'] > $this->now() ? $record : null;
	}

	private function now(): int {
		return (int) call_user_func( $this->clock );
	}
}

// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
