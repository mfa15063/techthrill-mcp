<?php
/**
 * Client ID Metadata Documents.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\OAuth;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- OAuthException messages are plain-text JSON error fields, never printed as HTML.
defined( 'ABSPATH' ) || exit;

/**
 * Resolves a client whose client_id is an https URL by fetching and validating the document at that URL.
 */
final class ClientMetadataFetcher {

	const MAX_BYTES = 10240;

	const CACHE_TTL = 3600;

	/**
	 * Fetches a URL: fn( string $url ): ?array{status:int,content_type:string,body:string}.
	 *
	 * @var callable
	 */
	private $http;

	private ClientStore $store;

	/**
	 * Returns the current unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * @param callable      $http  HTTP function; returns null on transport failure.
	 * @param ClientStore   $store Where fetched clients are cached.
	 * @param callable|null $clock Returns the current unix time.
	 */
	public function __construct( callable $http, ClientStore $store, ?callable $clock = null ) {
		$this->http  = $http;
		$this->store = $store;
		$this->clock = $clock ?? 'time';
	}

	/**
	 * Whether a client_id is a metadata document URL: https, a hostname (not an IP literal), the default port,
	 * a non-empty path, and no user info, query or fragment.
	 *
	 * @param string $clientId Client ID.
	 */
	public function isMetadataUrl( string $clientId ): bool {
		if ( 0 !== strpos( $clientId, 'https://' ) || 1 === preg_match( '/[\s#]/', $clientId ) ) {
			return false;
		}

		$parts = wp_parse_url( $clientId );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! isset( $parts['path'] ) || '/' === $parts['path'] ) {
			return false;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) ) {
			return false;
		}

		$host = trim( $parts['host'], '[]' );

		return false === filter_var( $host, FILTER_VALIDATE_IP ) && false !== strpos( $host, '.' );
	}

	/**
	 * The client for a metadata URL, from cache or freshly fetched.
	 *
	 * @param string $clientId Metadata document URL.
	 * @return array Client record.
	 * @throws OAuthException When the document cannot be fetched or is not valid.
	 */
	public function resolve( string $clientId ): array {
		$now      = (int) call_user_func( $this->clock );
		$existing = $this->store->find( $clientId );
		if ( null !== $existing && 'cimd' === ( $existing['source'] ?? '' ) && null !== ( $existing['fetched_at'] ?? null ) && (int) $existing['fetched_at'] > $now - self::CACHE_TTL ) {
			return $existing;
		}

		if ( ! $this->isMetadataUrl( $clientId ) ) {
			throw $this->invalid( 'The client_id is not a valid metadata document URL.' );
		}

		$response = call_user_func( $this->http, $clientId );
		if ( ! is_array( $response ) ) {
			throw $this->invalid( 'The client metadata document could not be fetched.' );
		}
		if ( 200 !== (int) ( $response['status'] ?? 0 ) ) {
			throw $this->invalid( 'The client metadata document did not return HTTP 200.' );
		}
		if ( 0 !== stripos( (string) ( $response['content_type'] ?? '' ), 'application/json' ) ) {
			throw $this->invalid( 'The client metadata document is not application/json.' );
		}
		$body = (string) ( $response['body'] ?? '' );
		if ( strlen( $body ) > self::MAX_BYTES ) {
			throw $this->invalid( 'The client metadata document is too large.' );
		}

		$doc = json_decode( $body, true );
		if ( ! is_array( $doc ) || ( $doc['client_id'] ?? null ) !== $clientId ) {
			throw $this->invalid( 'The client metadata document is invalid or its client_id does not match its URL.' );
		}
		if ( isset( $doc['token_endpoint_auth_method'] ) && 'none' !== $doc['token_endpoint_auth_method'] ) {
			throw $this->invalid( 'Only public clients are supported.' );
		}

		$uris = $doc['redirect_uris'] ?? null;
		if ( ! is_array( $uris ) || array() === $uris || count( $uris ) > ClientRegistrar::MAX_REDIRECT_URIS ) {
			throw $this->invalid( 'The client metadata document needs between 1 and ' . ClientRegistrar::MAX_REDIRECT_URIS . ' redirect_uris.' );
		}
		foreach ( $uris as $uri ) {
			if ( ! is_string( $uri ) || ! RedirectPolicy::isAllowed( $uri ) ) {
				throw $this->invalid( 'A redirect URI in the client metadata document is not allowed for this server.' );
			}
		}

		$client = array(
			'client_id'     => $clientId,
			'client_name'   => ClientName::clean( $doc['client_name'] ?? null ),
			'redirect_uris' => array_values( array_unique( $uris ) ),
			'source'        => 'cimd',
			'created_at'    => (int) ( $existing['created_at'] ?? $now ),
			'last_used_at'  => $existing['last_used_at'] ?? null,
			'fetched_at'    => $now,
		);
		$this->store->save( $client );

		return $client;
	}

	private function invalid( string $description ): OAuthException {
		return new OAuthException( 'invalid_client', $description, 401 );
	}
}

// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
