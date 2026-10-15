<?php
/**
 * A validated authorization request.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Value object produced by AuthorizeValidator.
 */
final class AuthorizationRequest {

	private array $client;

	private string $redirectUri;

	private string $state;

	private string $codeChallenge;

	/**
	 * @var string[]
	 */
	private array $requestedScopes;

	private string $resourceUrl;

	/**
	 * @param array    $client          Client record.
	 * @param string   $redirectUri     Validated redirect URI.
	 * @param string   $state           Opaque state from the client.
	 * @param string   $codeChallenge   PKCE S256 challenge.
	 * @param string[] $requestedScopes Scopes the client asked for.
	 * @param string   $resourceUrl     Resource indicator, or an empty string.
	 */
	public function __construct( array $client, string $redirectUri, string $state, string $codeChallenge, array $requestedScopes, string $resourceUrl ) {
		$this->client          = $client;
		$this->redirectUri     = $redirectUri;
		$this->state           = $state;
		$this->codeChallenge   = $codeChallenge;
		$this->requestedScopes = $requestedScopes;
		$this->resourceUrl     = $resourceUrl;
	}

	public function client(): array {
		return $this->client;
	}

	public function redirectUri(): string {
		return $this->redirectUri;
	}

	public function state(): string {
		return $this->state;
	}

	public function codeChallenge(): string {
		return $this->codeChallenge;
	}

	/**
	 * @return string[]
	 */
	public function requestedScopes(): array {
		return $this->requestedScopes;
	}

	public function resource(): string {
		return $this->resourceUrl;
	}

	/**
	 * The normalised request parameters, for the consent form's hidden fields.
	 *
	 * @return array<string,string>
	 */
	public function params(): array {
		return array(
			'response_type'         => 'code',
			'client_id'             => (string) $this->client['client_id'],
			'redirect_uri'          => $this->redirectUri,
			'state'                 => $this->state,
			'code_challenge'        => $this->codeChallenge,
			'code_challenge_method' => 'S256',
			'scope'                 => implode( ' ', $this->requestedScopes ),
			'resource'              => $this->resourceUrl,
		);
	}
}
