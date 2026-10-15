<?php
namespace TechThrill\MCP\Tests\Support;

use TechThrill\MCP\Tools\MediaLibrary;

class FakeMediaLibrary implements MediaLibrary {

	/**
	 * Whether uploads are allowed.
	 *
	 * @var bool
	 */
	public $can = true;

	/**
	 * Error message to return, or null to succeed.
	 *
	 * @var string|null
	 */
	public $error = null;

	/**
	 * Calls received.
	 *
	 * @var array<int,array>
	 */
	public $calls = array();

	public function canUpload(): bool {
		return $this->can;
	}

	public function add( string $name, string $bytes, array $meta ) {
		$this->calls[] = array( $name, $bytes, $meta );
		if ( null !== $this->error ) {
			return $this->error;
		}

		return array(
			'id'   => 42,
			'url'  => 'https://example.com/wp-content/uploads/2026/10/' . $name,
			'file' => $name,
			'mime' => 'image/png',
		);
	}
}
