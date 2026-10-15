<?php
/**
 * Media Library access for the media_upload tool.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * The few WordPress calls media_upload needs, behind an interface so the tool is unit tested without WordPress.
 */
interface MediaLibrary {

	/**
	 * Whether the current user may upload files.
	 */
	public function canUpload(): bool;

	/**
	 * Stores the bytes as a new Media Library item.
	 *
	 * @param string              $name  File name, no directories.
	 * @param string              $bytes File contents.
	 * @param array<string,mixed> $meta  Optional title, alt_text, caption, description and post_id.
	 * @return array{id:int,url:string,file:string,mime:string}|string The new item, or an error message.
	 */
	public function add( string $name, string $bytes, array $meta );
}
