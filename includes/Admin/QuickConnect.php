<?php
/**
 * Presets for the one-click "Quick connect" form.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Admin;

use TechThrill\MCP\Auth\Scopes;

defined( 'ABSPATH' ) || exit;

/**
 * Maps a preset key to scopes and a connection name. Pure PHP apart from translation calls.
 */
final class QuickConnect {

	const DEFAULT_PRESET = 'read';

	/**
	 * Presets in display order.
	 *
	 * @return array<string,array{label:string,scopes:string[]}>
	 */
	public static function presets(): array {
		return array(
			'read'  => array(
				'label'  => __( 'Read only', 'techthrill-mcp' ),
				'scopes' => array( 'db:read', 'files:read' ),
			),
			'files' => array(
				'label'  => __( 'Files only', 'techthrill-mcp' ),
				'scopes' => array( 'files:read', 'files:write' ),
			),
			'full'  => array(
				'label'  => __( 'Full access', 'techthrill-mcp' ),
				'scopes' => Scopes::ALL,
			),
		);
	}

	/**
	 * Scopes for a preset. An unknown key gets read-only, never more access.
	 *
	 * @param string $key Preset key.
	 * @return string[]
	 */
	public static function scopesFor( string $key ): array {
		$presets = self::presets();

		return $presets[ isset( $presets[ $key ] ) ? $key : self::DEFAULT_PRESET ]['scopes'];
	}

	/**
	 * Connection name such as "Quick connect (Read only) 2026-10-15".
	 *
	 * @param string $key Preset key.
	 * @param int    $now Unix timestamp.
	 */
	public static function name( string $key, int $now ): string {
		$presets = self::presets();
		$label   = $presets[ isset( $presets[ $key ] ) ? $key : self::DEFAULT_PRESET ]['label'];

		return 'Quick connect (' . $label . ') ' . gmdate( 'Y-m-d', $now );
	}
}
