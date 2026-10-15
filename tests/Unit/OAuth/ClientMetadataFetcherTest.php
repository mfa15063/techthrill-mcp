<?php
namespace TechThrill\MCP\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\OAuth\ClientMetadataFetcher;
use TechThrill\MCP\OAuth\ClientResolver;
use TechThrill\MCP\OAuth\OAuthException;
use TechThrill\MCP\Tests\Support\InMemoryClientStore;

class ClientMetadataFetcherTest extends TestCase {

	const URL = 'https://app.example.com/oauth/client.json';

	private int $now = 1700000000;

	private InMemoryClientStore $store;

	private int $calls = 0;

	private $response;

	protected function setUp(): void {
		$this->store    = new InMemoryClientStore();
		$this->calls    = 0;
		$this->response = $this->ok();
	}

	private function doc( array $extra = array() ): array {
		return array_merge(
			array(
				'client_id'     => self::URL,
				'client_name'   => 'Example App',
				'redirect_uris' => array( 'http://localhost:53682/callback' ),
			),
			$extra
		);
	}

	private function ok( ?array $doc = null ): array {
		return array( 'status' => 200, 'content_type' => 'application/json; charset=utf-8', 'body' => json_encode( $doc ?? $this->doc() ) );
	}

	private function fetcher(): ClientMetadataFetcher {
		return new ClientMetadataFetcher(
			function ( string $url ) {
				++$this->calls;
				return $this->response;
			},
			$this->store,
			fn() => $this->now
		);
	}

	private function fails(): OAuthException {
		try {
			$this->fetcher()->resolve( self::URL );
		} catch ( OAuthException $e ) {
			return $e;
		}
		$this->fail( 'Expected an OAuthException.' );
	}

	public function test_accepts_a_valid_document_and_caches_it(): void {
		$f      = $this->fetcher();
		$client = $f->resolve( self::URL );

		$this->assertSame( 'Example App', $client['client_name'] );
		$this->assertSame( array( 'http://localhost:53682/callback' ), $client['redirect_uris'] );
		$this->assertSame( 'cimd', $client['source'] );
		$f->resolve( self::URL );
		$this->assertSame( 1, $this->calls, 'The second resolve uses the cache.' );
	}

	public function test_refetches_after_the_cache_expires(): void {
		$f = $this->fetcher();
		$f->resolve( self::URL );
		$this->now += ClientMetadataFetcher::CACHE_TTL + 1;
		$f->resolve( self::URL );
		$this->assertSame( 2, $this->calls );
	}

	public function test_refetch_keeps_created_at_and_last_used_at(): void {
		$f = $this->fetcher();
		$f->resolve( self::URL );
		$record                 = $this->store->find( self::URL );
		$record['last_used_at'] = 123;
		$this->store->save( $record );
		$this->now += ClientMetadataFetcher::CACHE_TTL + 1;

		$again = $f->resolve( self::URL );
		$this->assertSame( 1700000000, $again['created_at'] );
		$this->assertSame( 123, $again['last_used_at'] );
	}

	public function test_rejects_when_client_id_in_document_differs_from_url(): void {
		$this->response = $this->ok( $this->doc( array( 'client_id' => 'https://evil.example.com/x.json' ) ) );
		$this->assertSame( 'invalid_client', $this->fails()->error() );
		$this->assertNull( $this->store->find( self::URL ) );
	}

	public function test_rejects_non_200_non_json_oversize_and_invalid_json(): void {
		$this->response = array( 'status' => 404, 'content_type' => 'application/json', 'body' => '{}' );
		$this->assertSame( 401, $this->fails()->status() );

		$this->response = array( 'status' => 200, 'content_type' => 'text/html', 'body' => json_encode( $this->doc() ) );
		$this->assertSame( 'invalid_client', $this->fails()->error() );

		$this->response = array( 'status' => 200, 'content_type' => 'application/json', 'body' => json_encode( $this->doc( array( 'pad' => str_repeat( 'x', 11000 ) ) ) ) );
		$this->assertSame( 'invalid_client', $this->fails()->error() );

		$this->response = array( 'status' => 200, 'content_type' => 'application/json', 'body' => '{not json' );
		$this->assertSame( 'invalid_client', $this->fails()->error() );
	}

	public function test_rejects_documents_with_a_redirect_uri_outside_the_allowlist(): void {
		$this->response = $this->ok( $this->doc( array( 'redirect_uris' => array( 'https://evil.com/cb' ) ) ) );
		$this->assertSame( 'invalid_client', $this->fails()->error() );

		$this->response = $this->ok( $this->doc( array( 'redirect_uris' => array() ) ) );
		$this->assertSame( 'invalid_client', $this->fails()->error() );

		$this->response = $this->ok( $this->doc( array( 'token_endpoint_auth_method' => 'client_secret_post' ) ) );
		$this->assertSame( 'invalid_client', $this->fails()->error() );
	}

	public function test_rejects_transport_failure(): void {
		$this->response = null;
		$this->assertSame( 'invalid_client', $this->fails()->error() );
	}

	public function test_is_metadata_url_rules(): void {
		$f = $this->fetcher();
		$this->assertTrue( $f->isMetadataUrl( self::URL ) );
		$this->assertTrue( $f->isMetadataUrl( 'https://app.example.com:443/x' ) );
		foreach ( array(
			'http://app.example.com/x',
			'https://app.example.com',
			'https://app.example.com/',
			'https://127.0.0.1/x',
			'https://[::1]/x',
			'https://10.0.0.5/x',
			'https://localhost/x',
			'https://app.example.com:8443/x',
			'https://user@app.example.com/x',
			'https://app.example.com/x?y=1',
			'https://app.example.com/x#frag',
			'ttc_abc123',
			'',
		) as $bad ) {
			$this->assertFalse( $f->isMetadataUrl( $bad ), $bad );
		}
	}

	public function test_resolver_routes_urls_to_the_fetcher_and_ids_to_the_store(): void {
		$this->store->save( array( 'client_id' => 'ttc_abc', 'client_name' => 'Reg', 'redirect_uris' => array(), 'source' => 'dcr', 'created_at' => 1, 'last_used_at' => null ) );
		$resolver = new ClientResolver( $this->store, $this->fetcher() );

		$this->assertSame( 'Reg', $resolver->find( 'ttc_abc' )['client_name'] );
		$this->assertSame( 0, $this->calls );
		$this->assertSame( 'Example App', $resolver->find( self::URL )['client_name'] );
		$this->assertSame( 1, $this->calls );
	}

	public function test_resolver_returns_null_for_unknown_ids_and_failed_fetches(): void {
		$resolver = new ClientResolver( $this->store, $this->fetcher() );
		$this->assertNull( $resolver->find( 'ttc_missing' ) );

		$this->response = null;
		$this->assertNull( $resolver->find( self::URL ) );
	}

	public function test_mark_used_sets_last_used_at(): void {
		$this->store->save( array( 'client_id' => 'ttc_abc', 'client_name' => 'Reg', 'redirect_uris' => array(), 'source' => 'dcr', 'created_at' => 1, 'last_used_at' => null ) );
		( new ClientResolver( $this->store, $this->fetcher() ) )->markUsed( 'ttc_abc' );
		$this->assertNotNull( $this->store->find( 'ttc_abc' )['last_used_at'] );
		( new ClientResolver( $this->store, $this->fetcher() ) )->markUsed( 'ttc_missing' );
		$this->assertNull( $this->store->find( 'ttc_missing' ) );
	}
}
