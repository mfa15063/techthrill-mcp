<?php
namespace TechThrill\MCP\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\Auth\TokenManager;
use TechThrill\MCP\OAuth\AgentAccess;
use TechThrill\MCP\OAuth\AttemptLimiter;
use TechThrill\MCP\OAuth\OAuthException;
use TechThrill\MCP\Tests\Support\InMemoryOptionsGateway;
use TechThrill\MCP\Tests\Support\InMemoryRateLimitStore;
use TechThrill\MCP\Tests\Support\InMemoryTokenRepository;

class AgentAccessTest extends TestCase {

	private int $now = 1700000000;

	private InMemoryOptionsGateway $options;

	private InMemoryTokenRepository $repo;

	private TokenManager $tokens;

	protected function setUp(): void {
		$this->options = new InMemoryOptionsGateway();
		$this->repo    = new InMemoryTokenRepository();
		$this->tokens  = new TokenManager( $this->repo, fn() => $this->now );
	}

	private function agent(): AgentAccess {
		return new AgentAccess( $this->options, $this->tokens, new AttemptLimiter( new InMemoryRateLimitStore(), fn() => $this->now ), fn() => $this->now );
	}

	public function test_start_returns_link_ids_and_a_short_code_and_stores_only_the_poll_hash(): void {
		$r = $this->agent()->start( 'Claude Code', '1.2.3.4' );

		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{24}$/', $r['request_id'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{40}$/', $r['poll_code'] );
		$this->assertMatchesRegularExpression( '/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $r['user_code'] );
		$this->assertSame( 600, $r['expires_in'] );
		$this->assertStringNotContainsString( $r['poll_code'], json_encode( $this->options->options ) );
	}

	public function test_find_shows_label_code_and_ip_only_while_pending(): void {
		$a = $this->agent();
		$r = $a->start( '<b>Codex</b>', '9.9.9.9' );

		$view = $a->find( $r['request_id'] );
		$this->assertSame( 'Codex', $view['label'] );
		$this->assertSame( $r['user_code'], $view['user_code'] );
		$this->assertSame( '9.9.9.9', $view['ip'] );
		$this->assertArrayNotHasKey( 'poll_hash', $view );

		$this->assertNull( $a->find( 'nope' ) );
		$this->assertNull( $a->find( str_repeat( 'a', 24 ) ) );
		$this->now += 601;
		$this->assertNull( $a->find( $r['request_id'] ) );
	}

	public function test_label_is_sanitised_bounded_and_defaulted(): void {
		$a = $this->agent();
		$this->assertSame( 'AI agent', $a->find( $a->start( '   ', '1.1.1.1' )['request_id'] )['label'] );
		$this->assertSame( 60, strlen( $a->find( $a->start( str_repeat( 'x', 300 ), '1.1.1.2' )['request_id'] )['label'] ) );
	}

	public function test_poll_is_pending_until_approved(): void {
		$a = $this->agent();
		$r = $a->start( 'Claude Code', '1.1.1.1' );

		$p = $a->poll( $r['request_id'], $r['poll_code'], '1.1.1.1' );
		$this->assertSame( 'pending', $p['status'] );
		$this->assertSame( 3, $p['interval'] );
	}

	public function test_approve_creates_a_token_with_the_chosen_scopes_and_hands_it_over_once(): void {
		$a = $this->agent();
		$r = $a->start( 'Claude Code', '1.1.1.1' );

		$a->approve( $r['request_id'], 5, array( 'db:read', 'files:read', 'bogus' ) );

		$p = $a->poll( $r['request_id'], $r['poll_code'], '1.1.1.1' );
		$this->assertSame( 'approved', $p['status'] );
		$this->assertSame( 'Bearer', $p['token_type'] );
		$this->assertSame( 'db:read files:read', $p['scope'] );
		$this->assertMatchesRegularExpression( '/^ttm_[A-Za-z0-9]{40}$/', $p['access_token'] );
		$this->assertSame( 2592000, $p['expires_in'] );

		$row = $this->tokens->verify( $p['access_token'] );
		$this->assertSame( 5, $row['user_id'] );
		$this->assertSame( 'db:read,files:read', $row['scopes'] );
		$this->assertSame( 'AI agent: Claude Code 2023-11-14', $row['name'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $this->now + 2592000 ), $row['expires_at'] );

		$again = $a->poll( $r['request_id'], $r['poll_code'], '1.1.1.1' );
		$this->assertSame( 'expired', $again['status'] );
		$this->assertArrayNotHasKey( 'access_token', $again );
		$this->assertSame( array(), $this->options->options, 'Nothing is left behind once collected.' );
	}

	public function test_approve_needs_a_valid_scope_and_a_live_pending_request(): void {
		$a = $this->agent();
		$r = $a->start( 'x', '1.1.1.1' );

		try {
			$a->approve( $r['request_id'], 5, array( 'nonsense' ) );
			$this->fail( 'Expected an error.' );
		} catch ( OAuthException $e ) {
			$this->assertSame( 'invalid_scope', $e->error() );
		}
		$this->assertSame( array(), $this->repo->all(), 'No token without a valid scope.' );

		try {
			$a->approve( 'unknownunknownunknownunkn', 5, array( 'db:read' ) );
			$this->fail( 'Expected an error.' );
		} catch ( OAuthException $e ) {
			$this->assertSame( 'invalid_request', $e->error() );
		}

		$a->approve( $r['request_id'], 5, array( 'db:read' ) );
		$this->expectException( OAuthException::class );
		$a->approve( $r['request_id'], 5, array( 'db:read' ) );
	}

	public function test_a_second_approval_does_not_create_a_second_token(): void {
		$a = $this->agent();
		$r = $a->start( 'x', '1.1.1.1' );
		$a->approve( $r['request_id'], 5, array( 'db:read' ) );
		try {
			$a->approve( $r['request_id'], 5, array( 'db:write' ) );
		} catch ( OAuthException $e ) {
			// Expected.
		}
		$this->assertCount( 1, $this->repo->all() );
	}

	public function test_deny_is_reported_once_and_creates_no_token(): void {
		$a = $this->agent();
		$r = $a->start( 'x', '1.1.1.1' );
		$a->deny( $r['request_id'] );

		$this->assertSame( 'denied', $a->poll( $r['request_id'], $r['poll_code'], '1.1.1.1' )['status'] );
		$this->assertSame( 'expired', $a->poll( $r['request_id'], $r['poll_code'], '1.1.1.1' )['status'] );
		$this->assertSame( array(), $this->repo->all() );
	}

	public function test_wrong_poll_code_or_unknown_id_reveals_nothing(): void {
		$a = $this->agent();
		$r = $a->start( 'x', '1.1.1.1' );
		$a->approve( $r['request_id'], 5, array( 'db:read' ) );

		$this->assertSame( array( 'status' => 'expired' ), $a->poll( $r['request_id'], str_repeat( 'z', 40 ), '1.1.1.1' ) );
		$this->assertSame( array( 'status' => 'expired' ), $a->poll( 'short', $r['poll_code'], '1.1.1.1' ) );
		$this->assertSame( 'approved', $a->poll( $r['request_id'], $r['poll_code'], '1.1.1.1' )['status'], 'A wrong guess does not consume the token.' );
	}

	public function test_expiry_of_pending_and_uncollected_requests(): void {
		$a = $this->agent();
		$p = $a->start( 'x', '1.1.1.1' );
		$this->now += 601;
		$this->assertSame( 'expired', $a->poll( $p['request_id'], $p['poll_code'], '1.1.1.1' )['status'] );

		$q = $a->start( 'x', '1.1.1.1' );
		$a->approve( $q['request_id'], 5, array( 'db:read' ) );
		$this->now += 301;
		$this->assertSame( 'expired', $a->poll( $q['request_id'], $q['poll_code'], '1.1.1.1' )['status'] );
	}

	public function test_losing_the_delete_race_hands_out_no_token(): void {
		$a = $this->agent();
		$r = $a->start( 'x', '1.1.1.1' );
		$a->approve( $r['request_id'], 5, array( 'db:read' ) );
		$this->options->lose_delete_race = true;

		$this->assertSame( 'expired', $a->poll( $r['request_id'], $r['poll_code'], '1.1.1.1' )['status'] );
	}

	public function test_rate_limits_start_per_ip_and_poll_per_ip(): void {
		$a = $this->agent();
		for ( $i = 0; $i < AgentAccess::START_LIMIT; $i++ ) {
			$a->start( 'x', '5.5.5.5' );
		}
		try {
			$a->start( 'x', '5.5.5.5' );
			$this->fail( 'Expected a rate-limit error.' );
		} catch ( OAuthException $e ) {
			$this->assertSame( 429, $e->status() );
		}
		$this->assertNotEmpty( $a->start( 'x', '6.6.6.6' ) );

		for ( $i = 0; $i < AgentAccess::POLL_LIMIT; $i++ ) {
			$a->poll( 'aaaaaaaaaaaaaaaaaaaaaaaa', 'x', '7.7.7.7' );
		}
		$this->expectException( OAuthException::class );
		$a->poll( 'aaaaaaaaaaaaaaaaaaaaaaaa', 'x', '7.7.7.7' );
	}

	public function test_prune_removes_only_expired_requests(): void {
		$a    = $this->agent();
		$old  = $a->start( 'old', '1.1.1.1' );
		$this->now += 400;
		$live = $a->start( 'live', '2.2.2.2' );
		$this->now += 300;

		$this->assertSame( 1, $a->prune() );
		$this->assertNull( $a->find( $old['request_id'] ) );
		$this->assertNotNull( $a->find( $live['request_id'] ) );
	}
}
