<?php
/**
 * Tool registry.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Tools;

use TechThrill\MCP\Auth\Scopes;

defined( 'ABSPATH' ) || exit;

/**
 * Holds registered tools and filters them by token scope.
 */
final class ToolRegistry {

	/**
	 * Tools keyed by name.
	 *
	 * @var ToolInterface[]
	 */
	private array $tools = array();

	/**
	 * Registers a tool, replacing any tool with the same name.
	 *
	 * @param ToolInterface $tool Tool.
	 */
	public function register( ToolInterface $tool ): void {
		$this->tools[ $tool->name() ] = $tool;
	}

	/**
	 * Finds a tool by name.
	 *
	 * @param string $name Tool name.
	 */
	public function get( string $name ): ?ToolInterface {
		return $this->tools[ $name ] ?? null;
	}

	/**
	 * Tools available to the given scopes, sorted by name.
	 *
	 * @param string[] $scopes Granted scopes.
	 * @return ToolInterface[]
	 */
	public function forScopes( array $scopes ): array {
		$tools = array_filter(
			$this->tools,
			fn( ToolInterface $tool ) => Scopes::has( $scopes, $tool->scope() )
		);
		ksort( $tools );

		return array_values( $tools );
	}

	/**
	 * All registered tools keyed by name.
	 *
	 * @return ToolInterface[]
	 */
	public function all(): array {
		return $this->tools;
	}
}
