<?php
/**
 * Per-call tool context.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Tools;

use TechThrill\MCP\Auth\Scopes;

defined( 'ABSPATH' ) || exit;

/**
 * Identifies the token and user a tool call runs for.
 */
final class ToolContext {

	/**
	 * Token ID.
	 *
	 * @var int
	 */
	private int $token_id;

	/**
	 * User ID.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Granted scopes.
	 *
	 * @var string[]
	 */
	private array $scopes;

	/**
	 * @param int      $token_id Token ID.
	 * @param int      $user_id  User ID.
	 * @param string[] $scopes   Granted scopes.
	 */
	public function __construct( int $token_id, int $user_id, array $scopes ) {
		$this->token_id = $token_id;
		$this->user_id  = $user_id;
		$this->scopes   = $scopes;
	}

	/**
	 * Token ID.
	 */
	public function tokenId(): int {
		return $this->token_id;
	}

	/**
	 * User ID.
	 */
	public function userId(): int {
		return $this->user_id;
	}

	/**
	 * Granted scopes.
	 *
	 * @return string[]
	 */
	public function scopes(): array {
		return $this->scopes;
	}

	/**
	 * Whether the token satisfies a scope (write scopes imply read).
	 *
	 * @param string $scope Required scope.
	 */
	public function hasScope( string $scope ): bool {
		return Scopes::has( $this->scopes, $scope );
	}
}
