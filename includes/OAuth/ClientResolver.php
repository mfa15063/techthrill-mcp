<?php
/**
 * Finds a client by ID, whether registered or described by a metadata document.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Routes metadata-document client IDs to the fetcher and everything else to the store.
 */
final class ClientResolver {

	private ClientStore $store;

	private ClientMetadataFetcher $fetcher;

	/**
	 * @param ClientStore           $store   Client storage.
	 * @param ClientMetadataFetcher $fetcher Metadata document fetcher.
	 */
	public function __construct( ClientStore $store, ClientMetadataFetcher $fetcher ) {
		$this->store   = $store;
		$this->fetcher = $fetcher;
	}

	/**
	 * The client, or null when it is unknown or its metadata document is unusable.
	 *
	 * @param string $clientId Client ID.
	 */
	public function find( string $clientId ): ?array {
		if ( $this->fetcher->isMetadataUrl( $clientId ) ) {
			try {
				return $this->fetcher->resolve( $clientId );
			} catch ( OAuthException $e ) {
				return null;
			}
		}

		return $this->store->find( $clientId );
	}

	/**
	 * Records that a client completed a grant, which protects it from pruning.
	 *
	 * @param string $clientId Client ID.
	 */
	public function markUsed( string $clientId ): void {
		$client = $this->store->find( $clientId );
		if ( null === $client ) {
			return;
		}

		$client['last_used_at'] = time();
		$this->store->save( $client );
	}
}
