<?php
/**
 * OAuth discovery documents.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\OAuth;

use TechThrill\MCP\Auth\Scopes;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the protected-resource document (RFC 9728), the authorization-server document (RFC 8414)
 * and the WWW-Authenticate challenge.
 */
final class Metadata {

	private string $issuer;

	private string $resource;

	private string $authorizationEndpoint;

	private string $tokenEndpoint;

	private string $registrationEndpoint;

	private string $resourceMetadataUrl;

	/**
	 * @param string $issuer                Authorization server identifier (a URL).
	 * @param string $resourceUrl           The MCP endpoint URL.
	 * @param string $authorizationEndpoint Consent page URL.
	 * @param string $tokenEndpoint         Token endpoint URL.
	 * @param string $registrationEndpoint  Dynamic client registration URL.
	 * @param string $resourceMetadataUrl   URL of the protected-resource document.
	 */
	public function __construct( string $issuer, string $resourceUrl, string $authorizationEndpoint, string $tokenEndpoint, string $registrationEndpoint, string $resourceMetadataUrl ) {
		$this->issuer                = $issuer;
		$this->resource              = $resourceUrl;
		$this->authorizationEndpoint = $authorizationEndpoint;
		$this->tokenEndpoint         = $tokenEndpoint;
		$this->registrationEndpoint  = $registrationEndpoint;
		$this->resourceMetadataUrl   = $resourceMetadataUrl;
	}

	public function issuer(): string {
		return $this->issuer;
	}

	public function resource(): string {
		return $this->resource;
	}

	public function resourceMetadataUrl(): string {
		return $this->resourceMetadataUrl;
	}

	/**
	 * Protected-resource document. Lists exactly one authorization server, because clients use only the first.
	 */
	public function protectedResource(): array {
		return array(
			'resource'                 => $this->resource,
			'authorization_servers'    => array( $this->issuer ),
			'scopes_supported'         => Scopes::ALL,
			'bearer_methods_supported' => array( 'header' ),
			'resource_name'            => 'TechThrill MCP',
		);
	}

	/**
	 * Authorization-server document. Public clients only, PKCE S256 only.
	 */
	public function authorizationServer(): array {
		return array(
			'issuer'                                => $this->issuer,
			'authorization_endpoint'                => $this->authorizationEndpoint,
			'token_endpoint'                        => $this->tokenEndpoint,
			'registration_endpoint'                 => $this->registrationEndpoint,
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none' ),
			'scopes_supported'                      => Scopes::ALL,
			'client_id_metadata_document_supported' => true,
		);
	}

	/**
	 * Value of the WWW-Authenticate header on a 401.
	 *
	 * @param bool $invalidToken Whether a token was presented but is invalid, expired or revoked.
	 */
	public function challenge( bool $invalidToken ): string {
		return 'Bearer ' . ( $invalidToken ? 'error="invalid_token", ' : '' ) . 'resource_metadata="' . $this->resourceMetadataUrl . '"';
	}

	/**
	 * Root path of a well-known document: the document name inserted between host and path (RFC 8414 section 3).
	 *
	 * @param string $document oauth-authorization-server or oauth-protected-resource.
	 * @param string $url      Issuer or resource URL.
	 */
	public static function wellKnownPath( string $document, string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		return '/.well-known/' . $document . rtrim( $path, '/' );
	}
}
