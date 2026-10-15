<?php
/**
 * Bearer token creation and verification.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Creates, verifies and revokes API tokens. Only a SHA-256 hash of a token is stored.
 */
final class TokenManager {

	const PREFIX = 'ttm_';

	const REFRESH_PREFIX = 'ttr_';

	/**
	 * Lifetime of an OAuth access token, in seconds.
	 */
	const ACCESS_TTL = 3600;

	/**
	 * Lifetime of an OAuth refresh token, in seconds (30 days).
	 */
	const REFRESH_TTL = 2592000;

	const LENGTH = 40;

	const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

	/**
	 * Seconds between last_used_at updates.
	 */
	const TOUCH_INTERVAL = 60;

	/**
	 * Token storage.
	 *
	 * @var TokenRepository
	 */
	private $repo;

	/**
	 * Returns the current unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * @param TokenRepository $repo  Token storage.
	 * @param callable|null   $clock Returns the current unix time.
	 */
	public function __construct( TokenRepository $repo, ?callable $clock = null ) {
		$this->repo  = $repo;
		$this->clock = $clock ?? 'time';
	}

	/**
	 * Creates a token. The plain token is returned once and never stored.
	 *
	 * @param string      $name       Label for the token.
	 * @param string[]    $scopes     Requested scopes.
	 * @param int         $user_id    Owning WordPress user.
	 * @param string|null $expires_at Expiry as `Y-m-d H:i:s` UTC, or null for none.
	 * @return array{id:int,token:string}
	 * @throws \RuntimeException When the token cannot be stored.
	 */
	public function create( string $name, array $scopes, int $user_id, ?string $expires_at ): array {
		$plain = self::PREFIX . self::randomString( self::LENGTH );

		$expires_at = null === $expires_at ? null : trim( $expires_at );
		if ( '' === $expires_at ) {
			$expires_at = null;
		}

		$id = $this->repo->insert(
			array(
				'name'         => substr( trim( $name ), 0, 100 ),
				'lookup'       => self::lookup( $plain ),
				'hash'         => self::hash( $plain ),
				'scopes'       => implode( ',', Scopes::sanitize( $scopes ) ),
				'user_id'      => $user_id,
				'created_at'   => gmdate( 'Y-m-d H:i:s', $this->now() ),
				'expires_at'   => $expires_at,
				'last_used_at' => null,
				'revoked_at'   => null,
			)
		);

		if ( $id <= 0 ) {
			throw new \RuntimeException( 'Could not store the token.' );
		}

		return array(
			'id'    => $id,
			'token' => $plain,
		);
	}

	/**
	 * Creates an OAuth grant: a one-hour access token (a normal bearer token) and a 30-day single-use refresh token.
	 *
	 * @param string   $clientId   OAuth client the grant belongs to.
	 * @param string   $clientName Client name shown on the Tokens page.
	 * @param string[] $scopes     Granted scopes.
	 * @param int      $user_id    Administrator who approved the grant.
	 * @return array{id:int,access_token:string,refresh_token:string,expires_in:int,scopes:string[]}
	 * @throws \RuntimeException When the grant cannot be stored.
	 */
	public function createOAuth( string $clientId, string $clientName, array $scopes, int $user_id ): array {
		$access  = self::PREFIX . self::randomString( self::LENGTH );
		$refresh = self::REFRESH_PREFIX . self::randomString( self::LENGTH );
		$scopes  = Scopes::sanitize( $scopes );
		$now     = $this->now();

		$id = $this->repo->insert(
			array(
				'name'               => substr( 'OAuth: ' . trim( $clientName ), 0, 100 ),
				'lookup'             => self::lookup( $access ),
				'hash'               => self::hash( $access ),
				'scopes'             => implode( ',', $scopes ),
				'user_id'            => $user_id,
				'created_at'         => gmdate( 'Y-m-d H:i:s', $now ),
				'expires_at'         => gmdate( 'Y-m-d H:i:s', $now + self::ACCESS_TTL ),
				'last_used_at'       => null,
				'revoked_at'         => null,
				'kind'               => 'oauth',
				'client_id'          => $clientId,
				'refresh_hash'       => self::hash( $refresh ),
				'refresh_expires_at' => gmdate( 'Y-m-d H:i:s', $now + self::REFRESH_TTL ),
			)
		);

		if ( $id <= 0 ) {
			throw new \RuntimeException( 'Could not store the token.' );
		}

		return array(
			'id'            => $id,
			'access_token'  => $access,
			'refresh_token' => $refresh,
			'expires_in'    => self::ACCESS_TTL,
			'scopes'        => $scopes,
		);
	}

	/**
	 * Exchanges a refresh token for a new pair, replacing the old pair on the same row.
	 *
	 * Returns null when the token is unknown, malformed, expired, revoked, belongs to another client, or
	 * when narrowing the scopes would leave none. A refresh token works once.
	 *
	 * @param string        $refreshToken Plain refresh token.
	 * @param string        $clientId     Client presenting it.
	 * @param string[]|null $narrowScopes Optional scopes to keep; can only remove scopes.
	 * @return array{id:int,access_token:string,refresh_token:string,expires_in:int,scopes:string[]}|null
	 */
	public function refreshOAuth( string $refreshToken, string $clientId, ?array $narrowScopes = null ): ?array {
		if ( 1 !== preg_match( '/^ttr_[A-Za-z0-9]{40}$/D', $refreshToken ) ) {
			return null;
		}

		$row = $this->repo->findByRefreshHash( self::hash( $refreshToken ) );
		if ( null === $row || 'oauth' !== ( $row['kind'] ?? null ) || ! hash_equals( (string) ( $row['client_id'] ?? '' ), $clientId ) ) {
			return null;
		}
		if ( null !== $row['revoked_at'] || $this->isExpired( $row['refresh_expires_at'] ?? null ) ) {
			return null;
		}

		$scopes = Scopes::sanitize( explode( ',', (string) $row['scopes'] ) );
		if ( null !== $narrowScopes ) {
			$scopes = array_values( array_intersect( $scopes, Scopes::sanitize( $narrowScopes ) ) );
		}
		if ( ! $scopes ) {
			return null;
		}

		$access  = self::PREFIX . self::randomString( self::LENGTH );
		$refresh = self::REFRESH_PREFIX . self::randomString( self::LENGTH );
		$now     = $this->now();

		$this->repo->update(
			(int) $row['id'],
			array(
				'lookup'             => self::lookup( $access ),
				'hash'               => self::hash( $access ),
				'scopes'             => implode( ',', $scopes ),
				'expires_at'         => gmdate( 'Y-m-d H:i:s', $now + self::ACCESS_TTL ),
				'refresh_hash'       => self::hash( $refresh ),
				'refresh_expires_at' => gmdate( 'Y-m-d H:i:s', $now + self::REFRESH_TTL ),
			)
		);

		return array(
			'id'            => (int) $row['id'],
			'access_token'  => $access,
			'refresh_token' => $refresh,
			'expires_in'    => self::ACCESS_TTL,
			'scopes'        => $scopes,
		);
	}

	/**
	 * Returns the token row for a valid plain token, or null when unknown, revoked, expired or malformed.
	 *
	 * @param string $plain Plain bearer token.
	 */
	public function verify( string $plain ): ?array {
		if ( ! self::isWellFormed( $plain ) ) {
			return null;
		}

		$hash = self::hash( $plain );
		foreach ( $this->repo->findByLookup( self::lookup( $plain ) ) as $row ) {
			if ( ! hash_equals( (string) $row['hash'], $hash ) ) {
				continue;
			}
			if ( null !== $row['revoked_at'] ) {
				return null;
			}
			if ( $this->isExpired( $row['expires_at'] ) ) {
				return null;
			}

			return $row;
		}

		return null;
	}

	/**
	 * Records last use, at most once every 60 seconds.
	 *
	 * @param int $id Token id.
	 */
	public function touch( int $id ): void {
		$row = $this->repo->find( $id );
		if ( null === $row ) {
			return;
		}

		$now = $this->now();
		if ( null === $row['last_used_at'] || strtotime( $row['last_used_at'] . ' UTC' ) <= $now - self::TOUCH_INTERVAL ) {
			$this->repo->update( $id, array( 'last_used_at' => gmdate( 'Y-m-d H:i:s', $now ) ) );
		}
	}

	/**
	 * Revokes a token.
	 *
	 * @param int $id Token id.
	 */
	public function revoke( int $id ): void {
		$this->repo->update( $id, array( 'revoked_at' => gmdate( 'Y-m-d H:i:s', $this->now() ) ) );
	}

	/**
	 * SHA-256 hash of a plain token.
	 *
	 * @param string $plain Plain token.
	 */
	public static function hash( string $plain ): string {
		return hash( 'sha256', $plain );
	}

	/**
	 * Indexed lookup key: the first 12 characters of a plain token.
	 *
	 * @param string $plain Plain token.
	 */
	public static function lookup( string $plain ): string {
		return substr( $plain, 0, 12 );
	}

	/**
	 * Whether a string has the shape of a token.
	 *
	 * @param string $plain Candidate token.
	 */
	public static function isWellFormed( string $plain ): bool {
		return 1 === preg_match( '/^ttm_[A-Za-z0-9]{40}$/D', $plain );
	}

	/**
	 * Whether an expiry value has passed. Null or empty means no expiry; unparseable values fail closed.
	 *
	 * @param string|null $value Expiry as `Y-m-d H:i:s` UTC.
	 */
	private function isExpired( ?string $value ): bool {
		if ( null === $value || '' === $value ) {
			return false;
		}

		$time = strtotime( $value . ' UTC' );

		return false === $time || $time <= $this->now();
	}

	/**
	 * Current unix time.
	 */
	private function now(): int {
		return (int) call_user_func( $this->clock );
	}

	/**
	 * Random alphanumeric string without modulo bias.
	 *
	 * @param int $length Number of characters.
	 */
	private static function randomString( int $length ): string {
		$alphabet = self::ALPHABET;
		$size     = strlen( $alphabet );
		$limit    = 256 - ( 256 % $size );
		$out      = '';

		$count = 0;
		while ( $count < $length ) {
			foreach ( unpack( 'C*', random_bytes( $length ) ) as $byte ) {
				if ( $byte >= $limit ) {
					continue;
				}
				$out .= $alphabet[ $byte % $size ];
				++$count;
				if ( $count === $length ) {
					break;
				}
			}
		}

		return $out;
	}
}
