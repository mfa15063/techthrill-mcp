<?php
/**
 * The media_upload tool.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Adds an image or other allowed file to the Media Library from base64 data, so it works without WP-CLI.
 */
final class MediaUpload extends AbstractTool {

	/**
	 * Largest decoded upload, in bytes (20 MB).
	 */
	const MAX_BYTES = 20971520;

	const MAX_NAME = 200;

	private MediaLibrary $library;

	/**
	 * @param MediaLibrary $library Media Library access.
	 */
	public function __construct( MediaLibrary $library ) {
		$this->library = $library;
	}

	public function name(): string {
		return 'media_upload';
	}

	public function description(): string {
		return 'Upload an image or other file to the WordPress Media Library from base64 data. Works without WP-CLI. WordPress decides which file types are allowed (for example jpg, png, gif, webp, pdf; not php or svg by default). Limited to 20 MB. Returns the attachment ID and URL.';
	}

	public function inputSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'name'           => array(
					'type'        => 'string',
					'description' => 'File name with its extension, for example hero.jpg. No directories.',
				),
				'content_base64' => array(
					'type'        => 'string',
					'description' => 'The file contents, base64 encoded. A data: URI prefix is accepted.',
				),
				'title'          => array(
					'type'        => 'string',
					'description' => 'Media title. Defaults to the file name without its extension.',
				),
				'alt_text'       => array(
					'type'        => 'string',
					'description' => 'Alternative text for images.',
				),
				'caption'        => array(
					'type'        => 'string',
					'description' => 'Caption.',
				),
				'description'    => array(
					'type'        => 'string',
					'description' => 'Description.',
				),
				'post_id'        => array(
					'type'        => 'integer',
					'description' => 'Optional post to attach the file to.',
					'minimum'     => 0,
				),
			),
			'required'   => array( 'name', 'content_base64' ),
		);
	}

	public function scope(): ?string {
		return 'files:write';
	}

	public function execute( array $args, ToolContext $ctx ): ToolResult {
		if ( ! $this->library->canUpload() ) {
			return $this->fail( 'The user behind this token is not allowed to upload files to the Media Library.' );
		}

		$name = str_replace( '\\', '/', (string) ( $args['name'] ?? '' ) );
		$name = trim( basename( $name ) );
		$name = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $name );
		if ( '' === $name || '.' === $name || '..' === $name || false === strpos( $name, '.' ) ) {
			return $this->fail( 'name must be a file name with an extension, for example hero.jpg.' );
		}
		if ( strlen( $name ) > self::MAX_NAME ) {
			return $this->fail( 'name is too long (the limit is ' . self::MAX_NAME . ' characters).' );
		}

		$encoded = (string) ( $args['content_base64'] ?? '' );
		$encoded = (string) preg_replace( '#^data:[^,]*,#i', '', trim( $encoded ) );
		$encoded = (string) preg_replace( '/\s+/', '', $encoded );
		if ( '' === $encoded ) {
			return $this->fail( 'content_base64 is empty.' );
		}
		// The base64 text is about a third larger than the file, so a cheap length check comes before decoding.
		if ( strlen( $encoded ) > (int) ceil( self::MAX_BYTES * 4 / 3 ) + 4 ) {
			return $this->fail( 'The file is larger than 20 MB, which is the limit for media_upload.' );
		}

		$bytes = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding an uploaded file, not obfuscated code.
		if ( false === $bytes || '' === $bytes ) {
			return $this->fail( 'content_base64 is not valid base64.' );
		}
		if ( strlen( $bytes ) > self::MAX_BYTES ) {
			return $this->fail( 'The file is ' . strlen( $bytes ) . ' bytes; media_upload is limited to 20 MB.' );
		}

		$title = isset( $args['title'] ) && is_string( $args['title'] ) && '' !== trim( $args['title'] )
			? $args['title']
			: (string) pathinfo( $name, PATHINFO_FILENAME );

		$result = $this->library->add(
			$name,
			$bytes,
			array(
				'title'       => $title,
				'alt_text'    => isset( $args['alt_text'] ) && is_string( $args['alt_text'] ) ? $args['alt_text'] : '',
				'caption'     => isset( $args['caption'] ) && is_string( $args['caption'] ) ? $args['caption'] : '',
				'description' => isset( $args['description'] ) && is_string( $args['description'] ) ? $args['description'] : '',
				'post_id'     => isset( $args['post_id'] ) ? max( 0, (int) $args['post_id'] ) : 0,
			)
		);
		if ( is_string( $result ) ) {
			return $this->fail( $result );
		}

		$result['bytes'] = strlen( $bytes );

		return $this->ok( $result );
	}
}
