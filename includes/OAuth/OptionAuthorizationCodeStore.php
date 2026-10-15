<?php
/**
 * Authorization codes kept in options.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Each code is one non-autoloaded option named after the SHA-256 of the code, so the plain code is never stored.
 * consume() reads the option and then deletes it; only the caller whose delete succeeds gets the payload,
 * so two concurrent requests cannot both use a code.
 */
final class OptionAuthorizationCodeStore implements AuthorizationCodeStore {

	const PREFIX = 'techthrill_mcp_oauth_code_';

	private OptionsGateway $options;

	/**
	 * Returns the current unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * @param OptionsGateway $options Option access.
	 * @param callable|null  $clock   Returns the current unix time.
	 */
	public function __construct( OptionsGateway $options, ?callable $clock = null ) {
		$this->options = $options;
		$this->clock   = $clock ?? 'time';
	}

	public function issue( array $payload, int $ttl = 60 ): string {
		$code = Random::urlSafe43();

		$this->options->add(
			self::name( $code ),
			array(
				'payload'    => $payload,
				'expires_at' => $this->now() + $ttl,
			)
		);

		return $code;
	}

	public function consume( string $code ): ?array {
		$name   = self::name( $code );
		$record = $this->options->get( $name );
		if ( null === $record ) {
			return null;
		}

		// Whoever deletes the record owns it; a loser of a concurrent race gets nothing.
		if ( ! $this->options->delete( $name ) ) {
			return null;
		}

		if ( (int) ( $record['expires_at'] ?? 0 ) <= $this->now() || ! is_array( $record['payload'] ?? null ) ) {
			return null;
		}

		return $record['payload'];
	}

	/**
	 * Deletes expired codes.
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

	private static function name( string $code ): string {
		return self::PREFIX . hash( 'sha256', $code );
	}

	private function now(): int {
		return (int) call_user_func( $this->clock );
	}
}
