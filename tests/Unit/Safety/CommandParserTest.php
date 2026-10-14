<?php
namespace TechThrill\MCP\Tests\Unit\Safety;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\Safety\CommandException;
use TechThrill\MCP\Safety\CommandParser;

class CommandParserTest extends TestCase {
	public function test_tokenize_quotes(): void {
		$p = new CommandParser();
		$this->assertSame(
			array( 'post', 'create', '--post_title=Hello World', '--porcelain' ),
			$p->tokenize( 'post create --post_title="Hello World" --porcelain' )
		);
		$this->assertSame( array( "it's" ), $p->tokenize( "'it'\"'\"'s'" ) );
		$this->assertSame( array( 'a b' ), $p->tokenize( 'a\ b' ) );
	}

	public function test_tokenize_single_quotes_are_literal(): void {
		$p = new CommandParser();
		$this->assertSame( array( 'a\nb "x" $y' ), $p->tokenize( '\'a\nb "x" $y\'' ) );
	}

	public function test_tokenize_double_quote_escapes(): void {
		$p = new CommandParser();
		$this->assertSame( array( 'say "hi" \ done' ), $p->tokenize( '"say \"hi\" \\\\ done"' ) );
		$this->assertSame( array( 'a\nb' ), $p->tokenize( '"a\nb"' ) );
	}

	public function test_tokenize_whitespace_variants(): void {
		$p = new CommandParser();
		$this->assertSame( array( 'a', 'b', 'c', 'd' ), $p->tokenize( "  a\tb\nc\r\n  d  " ) );
	}

	public function test_tokenize_empty_quoted_word_is_kept(): void {
		$p = new CommandParser();
		$this->assertSame( array( 'option', 'update', 'x', '' ), $p->tokenize( 'option update x ""' ) );
		$this->assertSame( array( 'a', '' ), $p->tokenize( "a ''" ) );
	}

	public function test_tokenize_does_not_interpret_shell_metacharacters(): void {
		$p = new CommandParser();
		$this->assertSame(
			array( 'plugin', 'list', ';', 'rm', '-rf', '/', '&&', '$(id)', '`id`', '|', 'cat', '>', 'f' ),
			$p->tokenize( 'plugin list ; rm -rf / && $(id) `id` | cat > f' )
		);
	}

	public function test_unterminated_quote_throws(): void {
		$p = new CommandParser();
		$this->expectException( CommandException::class );
		$p->tokenize( 'post create --title="x' );
	}

	public function test_unterminated_single_quote_throws(): void {
		$p = new CommandParser();
		$this->expectException( CommandException::class );
		$p->tokenize( "post create --title='x" );
	}

	public function test_trailing_backslash_throws(): void {
		$p = new CommandParser();
		$this->expectException( CommandException::class );
		$p->tokenize( 'post list \\' );
	}

	public function test_leading_wp_removed(): void {
		$p = new CommandParser();
		$this->assertSame( array( 'plugin', 'list' ), $p->tokenize( 'wp plugin list' ) );
	}

	public function test_wp_only_removed_when_first_word(): void {
		$p = new CommandParser();
		$this->assertSame( array( 'plugin', 'wp', 'list' ), $p->tokenize( 'plugin wp list' ) );
		$this->assertSame( array( 'wp', 'plugin' ), $p->tokenize( 'wp wp plugin' ) );
		$this->assertSame( array( 'wpx', 'list' ), $p->tokenize( 'wpx list' ) );
	}

	public function test_empty_input_returns_empty_list(): void {
		$p = new CommandParser();
		$this->assertSame( array(), $p->tokenize( '' ) );
		$this->assertSame( array(), $p->tokenize( "  \t\n " ) );
		$this->assertSame( array(), $p->tokenize( 'wp' ) );
		$this->assertSame( array(), $p->tokenize( '  wp  ' ) );
	}

	public function test_strip_flags_all_forms(): void {
		$p  = new CommandParser();
		$in = array( 'plugin', 'list', '--url=http://evil', '--path', '/x', '--ssh=host', '--skip-plugins', '--format=json' );
		$this->assertSame( array( 'plugin', 'list', '--format=json' ), $p->stripFlags( $in ) );
	}

	public function test_strip_flags_empty_value_form(): void {
		$p = new CommandParser();
		$this->assertSame( array( 'option', 'list' ), $p->stripFlags( array( 'option', 'list', '--url=' ) ) );
	}

	public function test_strip_flags_is_case_sensitive_and_exact(): void {
		$p  = new CommandParser();
		$in = array( 'plugin', 'list', '--URL=x', '--urlx=y', '--url-foo', '--ssh2' );
		$this->assertSame( $in, $p->stripFlags( $in ) );
	}

	public function test_strip_flags_value_not_consumed_when_next_is_flag_or_missing(): void {
		$p = new CommandParser();
		$this->assertSame( array( 'a', '--format=json' ), $p->stripFlags( array( 'a', '--user', '--format=json' ) ) );
		$this->assertSame( array( 'a' ), $p->stripFlags( array( 'a', '--user' ) ) );
	}

	public function test_strip_flags_value_with_spaces_from_tokenizer(): void {
		$p    = new CommandParser();
		$argv = $p->tokenize( 'option get home --path "/var/www/my site" --format=json' );
		$this->assertSame( array( 'option', 'get', 'home', '--format=json' ), $p->stripFlags( $argv ) );
	}

	public function test_strip_flags_every_listed_name(): void {
		$p = new CommandParser();
		foreach ( CommandParser::STRIPPED_FLAGS as $name ) {
			$this->assertSame( array( 'x' ), $p->stripFlags( array( 'x', '--' . $name . '=v' ) ), $name );
			$this->assertSame( array( 'x' ), $p->stripFlags( array( 'x', '--' . $name, 'v' ) ), $name );
			$this->assertSame( array( 'x' ), $p->stripFlags( array( 'x', '--' . $name ) ), $name );
		}
	}

	public function test_strip_flags_returns_reindexed_list(): void {
		$p = new CommandParser();
		$this->assertSame( array( 0, 1 ), array_keys( $p->stripFlags( array( '--url=x', 'a', 'b' ) ) ) );
	}

	public function test_blocklist_matches_positional_prefix_only(): void {
		$p = new CommandParser();
		$this->assertSame( 'db cli', $p->firstBlocked( array( 'db', 'cli', '--foo' ), CommandParser::ALWAYS_BLOCKED ) );
		$this->assertNull( $p->firstBlocked( array( 'db', 'export' ), CommandParser::ALWAYS_BLOCKED ) );
		$this->assertNull( $p->firstBlocked( array( 'post', 'list', '--s=shell' ), CommandParser::ALWAYS_BLOCKED ) );
	}

	public function test_blocklist_is_exact_on_words(): void {
		$p = new CommandParser();
		$this->assertNull( $p->firstBlocked( array( 'dbx', 'cli' ), CommandParser::ALWAYS_BLOCKED ) );
		$this->assertNull( $p->firstBlocked( array( 'db', 'clix' ), CommandParser::ALWAYS_BLOCKED ) );
		$this->assertNull( $p->firstBlocked( array( 'shellx' ), CommandParser::ALWAYS_BLOCKED ) );
		$this->assertNull( $p->firstBlocked( array( 'Shell' ), CommandParser::ALWAYS_BLOCKED ) );
		$this->assertNull( $p->firstBlocked( array( 'db' ), CommandParser::ALWAYS_BLOCKED ) );
		$this->assertNull( $p->firstBlocked( array( 'eval-fil' ), CommandParser::ALWAYS_BLOCKED ) );
	}

	public function test_blocklist_must_match_from_the_front(): void {
		$p = new CommandParser();
		$this->assertNull( $p->firstBlocked( array( 'post', 'shell' ), CommandParser::ALWAYS_BLOCKED ) );
		$this->assertSame( 'shell', $p->firstBlocked( array( '--debug', 'shell' ), CommandParser::ALWAYS_BLOCKED ) );
		$this->assertSame( 'eval', $p->firstBlocked( array( 'eval', 'echo 1;' ), CommandParser::ALWAYS_BLOCKED ) );
		$this->assertSame( 'eval-file', $p->firstBlocked( array( 'eval-file', 'x.php' ), CommandParser::ALWAYS_BLOCKED ) );
	}

	public function test_blocklist_entry_with_flag(): void {
		$p    = new CommandParser();
		$list = array( 'post delete --force' );
		$this->assertSame( 'post delete --force', $p->firstBlocked( array( 'post', 'delete', '1', '--force' ), $list ) );
		$this->assertSame( 'post delete --force', $p->firstBlocked( array( 'post', '--force', 'delete', '1' ), $list ) );
		$this->assertNull( $p->firstBlocked( array( 'post', 'delete', '1' ), $list ) );
		$this->assertSame( 'post delete --force', $p->firstBlocked( array( 'post', 'delete', '1', '--force=1' ), $list ) );
		$this->assertNull( $p->firstBlocked( array( 'post', 'delete', '1', '--forcex' ), $list ) );
		$this->assertNull( $p->firstBlocked( array( 'post', 'delete', '1', '--forcex=1' ), $list ) );
		$this->assertNull( $p->firstBlocked( array( 'post', 'list', '--force' ), $list ) );
	}

	public function test_blocklist_returns_first_match_in_blocklist_order(): void {
		$p    = new CommandParser();
		$list = array( 'plugin delete', 'plugin' );
		$this->assertSame( 'plugin delete', $p->firstBlocked( array( 'plugin', 'delete', 'x' ), $list ) );
		$this->assertSame( 'plugin', $p->firstBlocked( array( 'plugin', 'list' ), $list ) );
		$this->assertNull( $p->firstBlocked( array( 'plugin', 'list' ), array() ) );
	}

	public function test_blocklist_ignores_empty_entries(): void {
		$p = new CommandParser();
		$this->assertNull( $p->firstBlocked( array( 'post', 'list' ), array( '', '  ' ) ) );
	}

	public function test_site_alias_as_first_positional_throws(): void {
		$p = new CommandParser();
		try {
			$p->firstBlocked( array( '@x', 'eval' ), CommandParser::ALWAYS_BLOCKED );
			$this->fail( 'Expected CommandException.' );
		} catch ( CommandException $e ) {
			$this->assertSame( 'Site aliases (@name) are not available through TechThrill MCP.', $e->getMessage() );
		}
		$this->expectException( CommandException::class );
		$p->firstBlocked( array( '--debug', '@prod', 'db', 'query' ), CommandParser::ALWAYS_BLOCKED );
	}

	public function test_cli_alias_is_blocked(): void {
		$p = new CommandParser();
		$this->assertSame( 'cli alias', $p->firstBlocked( array( 'cli', 'alias', 'add', '@x' ), CommandParser::ALWAYS_BLOCKED ) );
	}

	public function test_db_connect_is_blocked(): void {
		$p = new CommandParser();
		$this->assertSame( 'db connect', $p->firstBlocked( array( 'db', 'connect', '--execute=x' ), CommandParser::ALWAYS_BLOCKED ) );
	}

	public function test_blog_flag_is_stripped(): void {
		$p = new CommandParser();
		$this->assertSame( array( 'option', 'list' ), $p->stripFlags( array( 'option', 'list', '--blog=http://other' ) ) );
		$this->assertSame( array( 'option', 'list' ), $p->stripFlags( array( 'option', 'list', '--blog', 'http://other' ) ) );
	}

	public function test_parse_strips_flags_so_blocklist_sees_real_command(): void {
		$p = new CommandParser();
		$this->assertSame( 'eval', $p->firstBlocked( $p->parse( '--url x eval code' ), CommandParser::ALWAYS_BLOCKED ) );
		$this->assertSame( array( 'plugin', 'list' ), $p->parse( 'wp plugin list --path=/x' ) );
	}
}
