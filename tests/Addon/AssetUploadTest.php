<?php
namespace TechThrill\MCP\Tests\Addon;

use TechThrill\MCP\Files\Tools\AssetUpload;

class AssetUploadTest extends AddonTestCase {

	private string $png;

	protected function setUp(): void {
		parent::setUp();
		// A tiny PNG signature plus every byte value, so any text handling corrupts it.
		$this->png = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR" . implode( '', array_map( 'chr', range( 0, 255 ) ) ) . "\0\xff";
	}

	private function upload( array $args, bool $safe = false ) {
		return $this->run_tool( AssetUpload::class, $args, $safe );
	}

	public function test_name_scope_and_schema(): void {
		$tool = $this->tool( AssetUpload::class );
		$this->assertSame( 'asset_upload', $tool->name() );
		$this->assertSame( 'files:write', $tool->scope() );
		$this->assertSame( array( 'path', 'content_base64' ), $tool->inputSchema()['required'] );
	}

	public function test_adds_a_png_to_a_child_theme_byte_for_byte(): void {
		$data = $this->data( $this->upload( array( 'path' => 'wp-content/themes/child/screenshot.png', 'content_base64' => base64_encode( $this->png ) ) ) );
		$this->assertSame( 'wp-content/themes/child/screenshot.png', $data['path'] );
		$this->assertSame( strlen( $this->png ), $data['bytes'] );
		$this->assertTrue( $data['created'] );
		$this->assertSame( $this->png, $this->read( 'wp-content/themes/child/screenshot.png' ) );
		$this->assertSame( array(), $this->tempFiles() );
	}

	public function test_creates_folders_and_accepts_a_data_uri_and_line_breaks(): void {
		$encoded = 'data:image/png;base64,' . chunk_split( base64_encode( $this->png ), 64, "\n" );
		$this->data( $this->upload( array( 'path' => 'wp-content/themes/child/assets/img/logo.png', 'content_base64' => $encoded ) ) );
		$this->assertSame( $this->png, $this->read( 'wp-content/themes/child/assets/img/logo.png' ) );

		$r = $this->upload( array( 'path' => 'wp-content/themes/child/other/logo.png', 'content_base64' => base64_encode( $this->png ), 'create_dirs' => false ) );
		$this->assertTrue( $r->isError() );
		$this->assertDirectoryDoesNotExist( $this->root . '/wp-content/themes/child/other' );
	}

	public function test_replacing_needs_overwrite_and_backs_up_the_old_image(): void {
		$path = 'wp-content/themes/parent/screenshot.png';
		file_put_contents( $this->root . '/' . $path, "\x89PNG\r\n\x1a\nold" );

		$r = $this->upload( array( 'path' => $path, 'content_base64' => base64_encode( $this->png ) ) );
		$this->assertSame( $path . ' already exists; set overwrite to true to replace it.', $r->text() );
		$this->assertSame( "\x89PNG\r\n\x1a\nold", $this->read( $path ) );

		$data = $this->data( $this->upload( array( 'path' => $path, 'content_base64' => base64_encode( $this->png ), 'overwrite' => true ) ) );
		$this->assertFalse( $data['created'] );
		$this->assertNotNull( $data['backup'] );
		$this->assertSame( $this->png, $this->read( $path ) );
		$this->assertSame( "\x89PNG\r\n\x1a\nold", file_get_contents( $this->backupDir . '/' . sha1( $path ) . '/' . $data['backup'] . '.bak' ) );
	}

	public function test_the_content_must_match_the_file_type(): void {
		$r = $this->upload( array( 'path' => 'wp-content/themes/child/fake.png', 'content_base64' => base64_encode( '<?php echo 1;' ) ) );
		$this->assertTrue( $r->isError() );
		$this->assertSame( 'The content does not look like a real .png file, so it was not written. Set skip_type_check to true to write it anyway.', $r->text() );
		$this->assertFileDoesNotExist( $this->root . '/wp-content/themes/child/fake.png' );
	}

	public function test_signature_checks(): void {
		$this->assertTrue( AssetUpload::looksLike( 'jpg', "\xFF\xD8\xFF\xE0abc" ) );
		$this->assertTrue( AssetUpload::looksLike( 'jpeg', "\xFF\xD8\xFF\xE0abc" ) );
		$this->assertTrue( AssetUpload::looksLike( 'gif', 'GIF89a...' ) );
		$this->assertTrue( AssetUpload::looksLike( 'webp', 'RIFF1234WEBPVP8 ' ) );
		$this->assertFalse( AssetUpload::looksLike( 'webp', 'RIFF1234WAVEfmt ' ) );
		$this->assertTrue( AssetUpload::looksLike( 'woff2', 'wOF2....' ) );
		$this->assertTrue( AssetUpload::looksLike( 'pdf', '%PDF-1.7' ) );
		$this->assertFalse( AssetUpload::looksLike( 'pdf', 'MZ....' ) );
		$this->assertFalse( AssetUpload::looksLike( 'ico', 'GIF89a' ) );
		$this->assertTrue( AssetUpload::looksLike( 'mp3', 'anything' ), 'Types without a reliable signature pass.' );
	}

	public function test_skip_type_check_writes_the_bytes_anyway(): void {
		$r = $this->data( $this->upload( array( 'path' => 'wp-content/themes/child/fake.png', 'content_base64' => base64_encode( 'not a png' ), 'skip_type_check' => true ) ) );
		$this->assertSame( 'not a png', $this->read( 'wp-content/themes/child/fake.png' ) );
		$this->assertTrue( $r['created'] );
	}

	public function test_any_file_type_and_any_folder_is_allowed(): void {
		foreach ( array( 'wp-content/themes/child/shell.php', 'wp-content/themes/child/.htaccess', 'wp-content/themes/child/style.css', 'wp-content/themes/child/noext', 'wp-content/uploads/logo.png', 'wp-content/themes/logo.png', 'logo.png', 'wp-content/plugins/techthrill-mcp/logo.png' ) as $path ) {
			$data = $this->data( $this->upload( array( 'path' => $path, 'content_base64' => base64_encode( $this->png ), 'skip_type_check' => true ) ) );
			$this->assertSame( $path, $data['path'] );
			$this->assertSame( $this->png, $this->read( $path ), $path );
		}
	}

	public function test_traversal_is_still_blocked(): void {
		$r = $this->upload( array( 'path' => 'wp-content/themes/../../logo.png', 'content_base64' => base64_encode( $this->png ) ) );
		$this->assertSame( 'Path traversal is not allowed.', $r->text() );
	}

	public function test_a_folder_destination_invalid_base64_and_empty_content(): void {
		mkdir( $this->root . '/wp-content/themes/parent/dir.png' );
		$this->assertStringContainsString( 'is a folder', $this->upload( array( 'path' => 'wp-content/themes/parent/dir.png', 'content_base64' => base64_encode( $this->png ) ) )->text() );
		$this->assertSame( 'content_base64 is not valid base64.', $this->upload( array( 'path' => 'wp-content/themes/child/a.png', 'content_base64' => 'not*base64!' ) )->text() );
		$this->assertSame( 'content_base64 is empty.', $this->upload( array( 'path' => 'wp-content/themes/child/a.png', 'content_base64' => '' ) )->text() );
	}

	public function test_oversize_uploads_are_refused(): void {
		$big = "\x89PNG\r\n\x1a\n" . str_repeat( 'a', AssetUpload::MAX_BYTES );
		$r   = $this->upload( array( 'path' => 'wp-content/themes/child/big.png', 'content_base64' => base64_encode( $big ) ) );
		$this->assertTrue( $r->isError() );
		$this->assertStringContainsString( '20 MB', $r->text() );
		$this->assertFileDoesNotExist( $this->root . '/wp-content/themes/child/big.png' );
	}

	public function test_safe_mode_applies(): void {
		$r = $this->upload( array( 'path' => 'wp-content/plugins/techthrill-mcp/x.png', 'content_base64' => base64_encode( $this->png ) ), true );
		$this->assertStringStartsWith( 'Refused by safe mode: ', $r->text() );
	}
}
