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

		return array(
			'id'    => $id,
			'token' => $plain,
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

		while ( strlen( $out ) < $length ) {
			foreach ( unpack( 'C*', random_bytes( $length ) ) as $byte ) {
				if ( $byte >= $limit ) {
					continue;
				}
				$out .= $alphabet[ $byte % $size ];
				if ( strlen( $out ) === $length ) {
					break;
				}
			}
		}

		return $out;
	}
}
