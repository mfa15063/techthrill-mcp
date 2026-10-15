<?php
namespace TechThrill\MCP\Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\Tests\Support\FakeMediaLibrary;
use TechThrill\MCP\Tools\MediaUpload;
use TechThrill\MCP\Tools\ToolContext;
use TechThrill\MCP\Tools\ToolResult;

class MediaUploadTest extends TestCase {

	private FakeMediaLibrary $library;

	private string $png;

	protected function setUp(): void {
		$this->library = new FakeMediaLibrary();
		$this->png     = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR" . implode( '', array_map( 'chr', range( 0, 255 ) ) );
	}

	private function run_tool( array $args ): ToolResult {
		return ( new MediaUpload( $this->library ) )->execute( $args, new ToolContext( 1, 1, array( 'files:write' ) ) );
	}

	public function test_name_scope_and_schema(): void {
		$tool = new MediaUpload( $this->library );
		$this->assertSame( 'media_upload', $tool->name() );
		$this->assertSame( 'files:write', $tool->scope() );
		$this->assertSame( array( 'name', 'content_base64' ), $tool->inputSchema()['required'] );
		$this->assertSame( array( 'name', 'content_base64', 'title', 'alt_text', 'caption', 'description', 'post_id' ), array_keys( $tool->inputSchema()['properties'] ) );
	}

	public function test_uploads_binary_bytes_exactly_and_returns_id_and_url(): void {
		$r = $this->run_tool( array( 'name' => 'hero.png', 'content_base64' => base64_encode( $this->png ), 'alt_text' => 'A hero', 'post_id' => 7 ) );

		$this->assertFalse( $r->isError(), $r->text() );
		$data = json_decode( $r->text(), true );
		$this->assertSame( 42, $data['id'] );
		$this->assertSame( 'https://example.com/wp-content/uploads/2026/10/hero.png', $data['url'] );
		$this->assertSame( strlen( $this->png ), $data['bytes'] );

		$this->assertCount( 1, $this->library->calls );
		list( $name, $bytes, $meta ) = $this->library->calls[0];
		$this->assertSame( 'hero.png', $name );
		$this->assertSame( $this->png, $bytes );
		$this->assertSame( 'hero', $meta['title'], 'The title defaults to the file name without its extension.' );
		$this->assertSame( 'A hero', $meta['alt_text'] );
		$this->assertSame( 7, $meta['post_id'] );
	}

	public function test_explicit_title_caption_and_description_are_passed_on(): void {
		$this->run_tool( array( 'name' => 'a.png', 'content_base64' => base64_encode( 'x' ), 'title' => 'My title', 'caption' => 'Cap', 'description' => 'Desc' ) );
		$meta = $this->library->calls[0][2];
		$this->assertSame( 'My title', $meta['title'] );
		$this->assertSame( 'Cap', $meta['caption'] );
		$this->assertSame( 'Desc', $meta['description'] );
		$this->assertSame( 0, $meta['post_id'] );
	}

	public function test_data_uri_prefix_and_line_breaks_are_accepted(): void {
		$encoded = 'data:image/png;base64,' . chunk_split( base64_encode( $this->png ), 60, "\n" );
		$r       = $this->run_tool( array( 'name' => 'a.png', 'content_base64' => $encoded ) );
		$this->assertFalse( $r->isError(), $r->text() );
		$this->assertSame( $this->png, $this->library->calls[0][1] );
	}

	public function test_directories_in_the_name_are_dropped(): void {
		$this->run_tool( array( 'name' => '../../wp-config.png', 'content_base64' => base64_encode( 'x' ) ) );
		$this->run_tool( array( 'name' => 'a\\b\\c.png', 'content_base64' => base64_encode( 'x' ) ) );
		$this->assertSame( 'wp-config.png', $this->library->calls[0][0] );
		$this->assertSame( 'c.png', $this->library->calls[1][0] );
	}

	public function test_bad_names_are_refused(): void {
		foreach ( array( '', '..', '.', 'noextension', "\0\0" ) as $name ) {
			$r = $this->run_tool( array( 'name' => $name, 'content_base64' => base64_encode( 'x' ) ) );
			$this->assertTrue( $r->isError(), 'Name: ' . json_encode( $name ) );
			$this->assertSame( 'name must be a file name with an extension, for example hero.jpg.', $r->text() );
		}
		$long = $this->run_tool( array( 'name' => str_repeat( 'a', 201 ) . '.png', 'content_base64' => base64_encode( 'x' ) ) );
		$this->assertTrue( $long->isError() );
		$this->assertSame( array(), $this->library->calls );
	}

	public function test_invalid_or_empty_base64_is_refused(): void {
		$bad = $this->run_tool( array( 'name' => 'a.png', 'content_base64' => 'not*valid*base64!' ) );
		$this->assertSame( 'content_base64 is not valid base64.', $bad->text() );

		$empty = $this->run_tool( array( 'name' => 'a.png', 'content_base64' => '' ) );
		$this->assertSame( 'content_base64 is empty.', $empty->text() );

		$missing = $this->run_tool( array( 'name' => 'a.png' ) );
		$this->assertTrue( $missing->isError() );
		$this->assertSame( array(), $this->library->calls );
	}

	public function test_oversize_files_are_refused_before_anything_is_stored(): void {
		$big = $this->run_tool( array( 'name' => 'big.bin', 'content_base64' => base64_encode( str_repeat( 'a', MediaUpload::MAX_BYTES + 1 ) ) ) );
		$this->assertTrue( $big->isError() );
		$this->assertStringContainsString( '20 MB', $big->text() );

		$ok = $this->run_tool( array( 'name' => 'ok.bin', 'content_base64' => base64_encode( str_repeat( 'a', MediaUpload::MAX_BYTES ) ) ) );
		$this->assertFalse( $ok->isError(), $ok->text() );
		$this->assertCount( 1, $this->library->calls );
	}

	public function test_a_user_who_cannot_upload_is_refused(): void {
		$this->library->can = false;
		$r                  = $this->run_tool( array( 'name' => 'a.png', 'content_base64' => base64_encode( 'x' ) ) );
		$this->assertTrue( $r->isError() );
		$this->assertStringContainsString( 'not allowed to upload', $r->text() );
		$this->assertSame( array(), $this->library->calls );
	}

	public function test_wordpress_refusals_are_relayed_to_the_agent(): void {
		$this->library->error = 'Sorry, you are not allowed to upload this file type.';
		$r                    = $this->run_tool( array( 'name' => 'shell.php', 'content_base64' => base64_encode( '<?php' ) ) );
		$this->assertTrue( $r->isError() );
		$this->assertSame( 'Sorry, you are not allowed to upload this file type.', $r->text() );
	}
}
