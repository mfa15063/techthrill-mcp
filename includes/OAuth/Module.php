<?php
/**
 * Wires the OAuth authorization server into WordPress.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\OAuth;

use TechThrill\MCP\Auth\TokenManager;
use TechThrill\MCP\Auth\TransientRateLimitStore;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the OAuth object graph and registers its hooks. Everything it uses is unit tested on its own.
 */
final class Module {

	const REST_NAMESPACE = 'techthrill-mcp/v1';

	private \wpdb $db;

	private TokenManager $tokens;

	/**
	 * The object graph, built on first use. rest_url() cannot be called at plugins_loaded (the rewrite
	 * object does not exist yet), so nothing here is built before the init hook.
	 *
	 * @var array<string,object>|null
	 */
	private ?array $graph = null;

	/**
	 * @param \wpdb        $db     WordPress database handle.
	 * @param TokenManager $tokens Token manager.
	 */
	public function __construct( \wpdb $db, TokenManager $tokens ) {
		$this->db     = $db;
		$this->tokens = $tokens;
	}

	/**
	 * Registers routes, the well-known handler and the consent page. Nothing is built until a hook fires.
	 */
	public function boot(): void {
		add_action(
			'rest_api_init',
			function (): void {
				$this->graph()['routes']->register();
			}
		);
		add_action(
			'init',
			function (): void {
				$this->graph()['well_known']->serve();
			},
			1
		);
		add_action(
			'admin_post_' . ConsentPage::ACTION,
			function (): void {
				$this->graph()['consent']->handle();
			}
		);
		add_action(
			'admin_post_nopriv_' . ConsentPage::ACTION,
			function (): void {
				$this->graph()['consent']->handle();
			}
		);
	}

	/**
	 * Discovery documents and the WWW-Authenticate value.
	 */
	public function metadata(): Metadata {
		return $this->graph()['metadata'];
	}

	/**
	 * Problems that block or limit OAuth on this site; see Availability.
	 *
	 * @return string[]
	 */
	public function problems(): array {
		return Availability::problems( $this->metadata()->issuer(), (string) get_option( 'permalink_structure', '' ) );
	}

	/**
	 * Whether the one-paste flow can work on this site.
	 */
	public function isAvailable(): bool {
		return Availability::isAvailable( $this->metadata()->issuer(), (string) get_option( 'permalink_structure', '' ) );
	}

	/**
	 * Builds the object graph once.
	 *
	 * @return array<string,object>
	 */
	private function graph(): array {
		if ( null !== $this->graph ) {
			return $this->graph;
		}

		$endpoint = rest_url( self::REST_NAMESPACE . '/mcp' );
		$metadata = new Metadata(
			rest_url( self::REST_NAMESPACE ),
			$endpoint,
			ConsentPage::url(),
			rest_url( self::REST_NAMESPACE . '/oauth/token' ),
			rest_url( self::REST_NAMESPACE . '/oauth/register' ),
			rest_url( self::REST_NAMESPACE . '/oauth/protected-resource' )
		);

		$clients   = new OptionClientStore();
		$limiter   = new AttemptLimiter( new TransientRateLimitStore() );
		$resolver  = new ClientResolver( $clients, new ClientMetadataFetcher( array( self::class, 'fetchMetadataDocument' ), $clients ) );
		$registrar = new ClientRegistrar( $clients, $limiter );
		$codes     = new OptionAuthorizationCodeStore( new WpOptionsGateway( $this->db ) );

		$this->graph = array(
			'metadata'   => $metadata,
			'registrar'  => $registrar,
			'codes'      => $codes,
			'routes'     => new RestRoutes( $metadata, $registrar, new TokenEndpoint( $resolver, $codes, $this->tokens, $limiter, $endpoint ), array( self::class, 'clientIp' ) ),
			'well_known' => new WellKnown( $metadata ),
			'consent'    => new ConsentPage( new ConsentFlow( new AuthorizeValidator( $resolver, $endpoint ), new Authorizer( $codes ) ) ),
		);

		return $this->graph;
	}

	/**
	 * Daily cleanup: expired codes and stale registered clients.
	 */
	public function prune(): void {
		$graph = $this->graph();
		$graph['codes']->prune();
		$graph['registrar']->prune();
	}

	/**
	 * Fetches a client metadata document without following redirects, with a size cap and a short timeout.
	 *
	 * @param string $url Document URL.
	 * @return array{status:int,content_type:string,body:string}|null Null on transport failure.
	 */
	public static function fetchMetadataDocument( string $url ): ?array {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 5,
				'redirection'         => 0,
				'limit_response_size' => ClientMetadataFetcher::MAX_BYTES + 1,
				'headers'             => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}

		return array(
			'status'       => (int) wp_remote_retrieve_response_code( $response ),
			'content_type' => (string) wp_remote_retrieve_header( $response, 'content-type' ),
			'body'         => (string) wp_remote_retrieve_body( $response ),
		);
	}

	/**
	 * The client IP address from REMOTE_ADDR only (forwarding headers are spoofable), used for rate limiting.
	 */
	public static function clientIp(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : 'unknown';
	}
}
