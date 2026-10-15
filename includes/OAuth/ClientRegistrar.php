<?php
/**
 * Dynamic Client Registration for public clients.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\OAuth;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- OAuthException messages are plain-text JSON error fields, never printed as HTML.
defined( 'ABSPATH' ) || exit;

/**
 * Accepts RFC 7591 registration requests for public clients with allowlisted redirect URIs only.
 */
final class ClientRegistrar {

	const MAX_CLIENTS = 200;

	const MAX_REDIRECT_URIS = 5;

	/**
	 * Seconds after which a client that never completed a grant is deleted (30 days).
	 */
	const UNUSED_TTL = 2592000;

	const LIMIT = 10;

	const WINDOW = 3600;

	const GRANTS = array( 'authorization_code', 'refresh_token' );

	private ClientStore $store;

	private AttemptLimiter $limiter;

	/**
	 * Returns the current unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * @param ClientStore    $store   Client storage.
	 * @param AttemptLimiter $limiter Per-IP limiter.
	 * @param callable|null  $clock   Returns the current unix time.
	 */
	public function __construct( ClientStore $store, AttemptLimiter $limiter, ?callable $clock = null ) {
		$this->store   = $store;
		$this->limiter = $limiter;
		$this->clock   = $clock ?? 'time';
	}

	/**
	 * Registers a client.
	 *
	 * @param array  $metadata Decoded JSON request body.
	 * @param string $ip       Client IP address, used only for rate limiting.
	 * @return array RFC 7591 response fields.
	 * @throws OAuthException On invalid metadata or when rate limited.
	 */
	public function register( array $metadata, string $ip ): array {
		if ( ! $this->limiter->allow( 'register', $ip, self::LIMIT, self::WINDOW ) ) {
			throw new OAuthException( 'temporarily_unavailable', 'Too many registrations. Try again later.', 429 );
		}

		$uris = $metadata['redirect_uris'] ?? null;
		if ( ! is_array( $uris ) || array() === $uris || count( $uris ) > self::MAX_REDIRECT_URIS ) {
			throw $this->invalid( 'redirect_uris must list between 1 and ' . self::MAX_REDIRECT_URIS . ' URIs.' );
		}
		foreach ( $uris as $uri ) {
			if ( ! is_string( $uri ) || ! RedirectPolicy::isAllowed( $uri ) ) {
				throw $this->invalid( 'A redirect URI is not allowed for this server.' );
			}
		}

		if ( isset( $metadata['token_endpoint_auth_method'] ) && 'none' !== $metadata['token_endpoint_auth_method'] ) {
			throw $this->invalid( 'Only public clients are supported: token_endpoint_auth_method must be none.' );
		}

		$grants = $metadata['grant_types'] ?? self::GRANTS;
		if ( ! is_array( $grants ) || array() === $grants || array() !== array_diff( $grants, self::GRANTS ) ) {
			throw $this->invalid( 'grant_types may only contain authorization_code and refresh_token.' );
		}

		$types = $metadata['response_types'] ?? array( 'code' );
		if ( array( 'code' ) !== $types ) {
			throw $this->invalid( 'response_types must be ["code"].' );
		}

		if ( isset( $metadata['client_name'] ) && ! is_string( $metadata['client_name'] ) ) {
			throw $this->invalid( 'client_name must be a string.' );
		}

		$now    = $this->now();
		$client = array(
			'client_id'     => 'ttc_' . Random::alnum( 24 ),
			'client_name'   => ClientName::clean( $metadata['client_name'] ?? null ),
			'redirect_uris' => array_values( array_unique( $uris ) ),
			'source'        => 'dcr',
			'created_at'    => $now,
			'last_used_at'  => null,
			'fetched_at'    => null,
		);
		$this->store->save( $client );
		$this->prune();

		return array(
			'client_id'                  => $client['client_id'],
			'client_name'                => $client['client_name'],
			'redirect_uris'              => $client['redirect_uris'],
			'grant_types'                => array_values( $grants ),
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => 'none',
			'client_id_issued_at'        => $now,
		);
	}

	/**
	 * Deletes registered clients that never completed a grant after 30 days, then trims to the cap, oldest unused first.
	 *
	 * @return int Number of clients deleted.
	 */
	public function prune(): int {
		$now     = $this->now();
		$deleted = 0;
		$kept    = array();

		foreach ( $this->store->all() as $client ) {
			$unused = 'dcr' === ( $client['source'] ?? '' ) && null === ( $client['last_used_at'] ?? null );
			if ( $unused && (int) $client['created_at'] <= $now - self::UNUSED_TTL ) {
				$this->store->delete( (string) $client['client_id'] );
				++$deleted;
				continue;
			}
			$kept[] = $client;
		}

		$excess = count( $kept ) - self::MAX_CLIENTS;
		if ( $excess > 0 ) {
			usort(
				$kept,
				static function ( array $a, array $b ): int {
					$a_unused = null === ( $a['last_used_at'] ?? null ) ? 0 : 1;
					$b_unused = null === ( $b['last_used_at'] ?? null ) ? 0 : 1;

					return array( $a_unused, (int) $a['created_at'] ) <=> array( $b_unused, (int) $b['created_at'] );
				}
			);
			foreach ( array_slice( $kept, 0, $excess ) as $client ) {
				$this->store->delete( (string) $client['client_id'] );
				++$deleted;
			}
		}

		return $deleted;
	}

	private function invalid( string $description ): OAuthException {
		return new OAuthException( 'invalid_client_metadata', $description, 400 );
	}

	private function now(): int {
		return (int) call_user_func( $this->clock );
	}
}

// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
