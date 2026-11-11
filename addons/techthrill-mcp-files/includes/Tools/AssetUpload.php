<?php
/**
 * The asset_upload tool.
 *
 * @package TechThrill\MCP\Files
 */

namespace TechThrill\MCP\Files\Tools;

use TechThrill\MCP\Files\Support\FileRules;
use TechThrill\MCP\Files\Support\ToolFailure;
use TechThrill\MCP\Tools\ToolContext;
use TechThrill\MCP\Tools\ToolResult;

defined( 'ABSPATH' ) || exit;

/**
 * Writes a binary asset (a PNG for a child theme, a font, an icon) into a theme or plugin folder from base64.
 */
final class AssetUpload extends AbstractCodeTool {

	/**
	 * Largest upload after decoding, in bytes (20 MB).
	 */
	const MAX_BYTES = 20971520;

	public function name(): string {
		return 'asset_upload';
	}

	public function description(): string {
		return 'Add or replace a binary file (png, jpg, gif, webp, ico, bmp, fonts, pdf, audio, video) at an exact path inside a theme or plugin folder, for example wp-content/themes/my-child/screenshot.png, from base64 data. Works without WP-CLI. The file type must match its name. Limited to 20 MB. A replaced file is backed up.';
	}

	public function inputSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'path'           => array(
					'type'        => 'string',
					'description' => 'Full destination file path relative to the WordPress root, inside wp-content/themes or wp-content/plugins.',
				),
				'content_base64' => array(
					'type'        => 'string',
					'description' => 'The file contents, base64 encoded. A data: URI prefix is accepted.',
				),
				'overwrite'      => array(
					'type'        => 'boolean',
					'description' => 'Replace the file when it exists.',
					'default'     => false,
				),
				'create_dirs'    => array(
					'type'        => 'boolean',
					'description' => 'Create missing parent folders.',
					'default'     => true,
				),
			),
			'required'   => array( 'path', 'content_base64' ),
		);
	}

	public function scope(): ?string {
		return 'files:write';
	}

	protected function run( array $args, ToolContext $ctx ): ToolResult {
		$requested = (string) ( $args['path'] ?? '' );
		$overwrite = $this->flag( $args, 'overwrite', false );
		$create    = $this->flag( $args, 'create_dirs', true );

		$path = $this->forCreate( $requested );
		$this->allow( $path );

		$name = $this->baseName( $requested );
		if ( ! FileRules::isAssetFile( $name ) ) {
			throw new ToolFailure( $name . ' is not an asset type this tool writes. Allowed: ' . FileRules::assetList() . '. (Text and code files go through code_file_write; script types and dot files are never allowed.)' );
		}
		if ( is_dir( $path->absolute ) ) {
			throw new ToolFailure( $path->relative . ' is a folder; give the full destination file path.' );
		}

		$encoded = (string) preg_replace( '/\s+/', '', (string) preg_replace( '#^data:[^,]*,#i', '', trim( (string) ( $args['content_base64'] ?? '' ) ) ) );
		if ( '' === $encoded ) {
			throw new ToolFailure( 'content_base64 is empty.' );
		}
		if ( strlen( $encoded ) > (int) ceil( self::MAX_BYTES * 4 / 3 ) + 4 ) {
			throw new ToolFailure( 'The file is larger than 20 MB, which is the limit for asset_upload.' );
		}
		$bytes = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding an uploaded asset, not obfuscated code.
		if ( false === $bytes || '' === $bytes ) {
			throw new ToolFailure( 'content_base64 is not valid base64.' );
		}
		if ( strlen( $bytes ) > self::MAX_BYTES ) {
			throw new ToolFailure( 'The file is ' . strlen( $bytes ) . ' bytes; asset_upload is limited to 20 MB.' );
		}

		$extension = FileRules::extension( $name );
		if ( ! self::looksLike( $extension, $bytes ) ) {
			throw new ToolFailure( 'The content does not look like a real .' . $extension . ' file, so it was not written.' );
		}

		$existed = file_exists( $path->absolute );
		if ( $existed && ! $overwrite ) {
			throw new ToolFailure( $path->relative . ' already exists; set overwrite to true to replace it.' );
		}

		$this->ensureParent( $path, $create );
		$backup = $existed ? $this->backupOf( $path ) : null;

		$error = $this->atomicWrite( $path->absolute, $bytes );
		if ( null !== $error ) {
			throw new ToolFailure( $error );
		}

		return $this->ok(
			array(
				'path'    => $path->relative,
				'bytes'   => strlen( $bytes ),
				'created' => ! $existed,
				'backup'  => $backup,
			)
		);
	}

	/**
	 * Whether the first bytes match what the extension claims. Types without a reliable signature pass.
	 *
	 * @param string $extension Lower-case extension.
	 * @param string $bytes     File contents.
	 */
	public static function looksLike( string $extension, string $bytes ): bool {
		switch ( $extension ) {
			case 'png':
				return 0 === strncmp( $bytes, "\x89PNG\r\n\x1a\n", 8 );
			case 'jpg':
			case 'jpeg':
				return 0 === strncmp( $bytes, "\xFF\xD8\xFF", 3 );
			case 'gif':
				return 0 === strncmp( $bytes, 'GIF87a', 6 ) || 0 === strncmp( $bytes, 'GIF89a', 6 );
			case 'webp':
				return 0 === strncmp( $bytes, 'RIFF', 4 ) && 'WEBP' === substr( $bytes, 8, 4 );
			case 'ico':
				return 0 === strncmp( $bytes, "\x00\x00\x01\x00", 4 );
			case 'bmp':
				return 0 === strncmp( $bytes, 'BM', 2 );
			case 'woff':
				return 0 === strncmp( $bytes, 'wOFF', 4 );
			case 'woff2':
				return 0 === strncmp( $bytes, 'wOF2', 4 );
			case 'otf':
				return 0 === strncmp( $bytes, 'OTTO', 4 );
			case 'ttf':
				return 0 === strncmp( $bytes, "\x00\x01\x00\x00", 4 ) || 0 === strncmp( $bytes, 'true', 4 );
			case 'pdf':
				return 0 === strncmp( $bytes, '%PDF', 4 );
			default:
				return true;
		}
	}
}
