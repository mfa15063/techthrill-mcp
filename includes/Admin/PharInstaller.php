<?php
/**
 * Downloads and verifies the WP-CLI phar.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Admin;

use TechThrill\MCP\Safety\HostCapabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches wp-cli.phar, checks its SHA-512 against the published checksum, and installs it.
 */
final class PharInstaller {

	const URL = 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar';

	private HostCapabilities $host;

	/**
	 * @param HostCapabilities $host Host capabilities (supplies the install path).
	 */
	public function __construct( HostCapabilities $host ) {
		$this->host = $host;
	}

	/**
	 * Downloads, verifies and installs the phar.
	 *
	 * @return true|\WP_Error
	 */
	public function download() {
		$target = $this->host->pharPath();
		if ( '' === $target ) {
			return new \WP_Error( 'techthrill_mcp_phar_path', __( 'The uploads directory is not available, so WP-CLI cannot be installed.', 'techthrill-mcp' ) );
		}

		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$tmp = wp_tempnam( 'wp-cli.phar' );
		if ( ! is_string( $tmp ) || '' === $tmp ) {
			return new \WP_Error( 'techthrill_mcp_phar_tmp', __( 'Could not create a temporary file.', 'techthrill-mcp' ) );
		}

		$response = wp_remote_get(
			self::URL,
			array(
				'timeout'  => 60,
				'stream'   => true,
				'filename' => $tmp,
			)
		);
		if ( is_wp_error( $response ) ) {
			$this->discard( $tmp );
			return $response;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			$this->discard( $tmp );
			return new \WP_Error( 'techthrill_mcp_phar_download', __( 'WP-CLI could not be downloaded.', 'techthrill-mcp' ) );
		}

		$checksum_response = wp_remote_get( self::URL . '.sha512', array( 'timeout' => 15 ) );
		if ( is_wp_error( $checksum_response ) ) {
			$this->discard( $tmp );
			return $checksum_response;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $checksum_response ) ) {
			$this->discard( $tmp );
			return new \WP_Error( 'techthrill_mcp_phar_download', __( 'The WP-CLI checksum could not be downloaded.', 'techthrill-mcp' ) );
		}

		$expected = strtolower( trim( substr( (string) wp_remote_retrieve_body( $checksum_response ), 0, 128 ) ) );
		$actual   = hash_file( 'sha512', $tmp );
		if ( false === $actual || ! hash_equals( $expected, $actual ) ) {
			$this->discard( $tmp );
			return new \WP_Error( 'techthrill_mcp_phar_checksum', __( 'The downloaded WP-CLI file did not match its published checksum and was discarded.', 'techthrill-mcp' ) );
		}

		wp_mkdir_p( dirname( $target ) );
		if ( ! @rename( $tmp, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Moves the verified download into uploads; falls back to copy.
			$copied = @copy( $tmp, $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$this->discard( $tmp );
			if ( ! $copied ) {
				return new \WP_Error( 'techthrill_mcp_phar_write', __( 'WP-CLI could not be saved to the uploads directory.', 'techthrill-mcp' ) );
			}
		}

		return true;
	}

	private function discard( string $path ): void {
		if ( is_file( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}
}
