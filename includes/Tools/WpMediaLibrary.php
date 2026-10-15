<?php
/**
 * WordPress implementation of the Media Library access.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Tools;

// phpcs:disable WordPress.WP.AlternativeFunctions -- The uploaded file is removed again when the attachment cannot be created.
defined( 'ABSPATH' ) || exit;

/**
 * Uses wp_upload_bits (which applies WordPress's own allowed-file-type check), wp_insert_attachment and
 * wp_generate_attachment_metadata, exactly like an upload through the admin.
 */
final class WpMediaLibrary implements MediaLibrary {

	public function canUpload(): bool {
		return current_user_can( 'upload_files' );
	}

	public function add( string $name, string $bytes, array $meta ) {
		$upload = wp_upload_bits( $name, null, $bytes );
		if ( ! empty( $upload['error'] ) ) {
			return (string) $upload['error'];
		}

		$file = (string) $upload['file'];
		$type = wp_check_filetype( $file );
		$mime = (string) ( $type['type'] ? $type['type'] : '' );

		$attachment = array(
			'post_mime_type' => $mime,
			'post_title'     => sanitize_text_field( (string) ( $meta['title'] ?? '' ) ),
			'post_excerpt'   => sanitize_text_field( (string) ( $meta['caption'] ?? '' ) ),
			'post_content'   => wp_kses_post( (string) ( $meta['description'] ?? '' ) ),
			'post_status'    => 'inherit',
		);

		$id = wp_insert_attachment( $attachment, $file, (int) ( $meta['post_id'] ?? 0 ), true );
		if ( is_wp_error( $id ) ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Cleanup after a failed insert.

			return $id->get_error_message();
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );

		$alt = sanitize_text_field( (string) ( $meta['alt_text'] ?? '' ) );
		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}

		return array(
			'id'   => (int) $id,
			'url'  => (string) wp_get_attachment_url( $id ),
			'file' => basename( $file ),
			'mime' => $mime,
		);
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions
