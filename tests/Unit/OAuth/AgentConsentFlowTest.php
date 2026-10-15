<?php
namespace TechThrill\MCP\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\Auth\TokenManager;
use TechThrill\MCP\OAuth\AgentAccess;
use TechThrill\MCP\OAuth\AgentConsentFlow;
use TechThrill\MCP\OAuth\AttemptLimiter;
use TechThrill\MCP\Tests\Support\InMemoryOptionsGateway;
use TechThrill\MCP\Tests\Support\InMemoryRateLimitStore;
use TechThrill\MCP\Tests\Support\InMemoryTokenRepository;

class AgentConsentFlowTest extends TestCase {

	private AgentAccess $agent;

	private InMemoryTokenRepository $repo;

	private array $started;

	protected function setUp(): void {
		$this->repo    = new InMemoryTokenRepository();
		$this->agent   = new AgentAccess( new InMemoryOptionsGateway(), new TokenManager( $this->repo ), new AttemptLimiter( new InMemoryRateLimitStore() ) );
		$this->started = $this->agent->start( 'Claude Code', '1.1.1.1' );
	}

	private function get( bool $logged_in = true, bool $admin = true, ?string $id = null ): array {
		return ( new AgentConsentFlow( $this->agent ) )->handle( 'GET', $id ?? $this->started['request_id'], $logged_in, $admin, false, 9, array(), '' );
	}

	private function post( string $decision, array $scopes = array( 'db:read' ), bool $nonce = true ): array {
		return ( new AgentConsentFlow( $this->agent ) )->handle( 'POST', $this->started['request_id'], true, true, $nonce, 9, $scopes, $decision );
	}

	public function test_logged_out_visitor_is_sent_to_login(): void {
		$this->assertSame( 'login', $this->get( false, false )['type'] );
	}

	public function test_non_admin_gets_an_error_and_no_form(): void {
		$r = $this->get( true, false );
		$this->assertSame( 'error', $r['type'] );
		$this->assertSame( 403, $r['status'] );
	}

	public function test_get_shows_the_request_with_read_only_defaults(): void {
		$r = $this->get();
		$this->assertSame( 'consent', $r['type'] );
		$this->assertSame( 'Claude Code', $r['view']['label'] );
		$this->assertSame( $this->started['user_code'], $r['view']['user_code'] );
		$this->assertSame( array( 'db:read', 'files:read' ), $r['checked'] );
	}

	public function test_unknown_or_expired_request_is_an_error(): void {
		$this->assertSame( 404, $this->get( true, true, 'nope' )['status'] );
		$this->assertSame( 404, $this->get( true, true, str_repeat( 'a', 24 ) )['status'] );
	}

	public function test_post_without_a_valid_nonce_creates_nothing(): void {
		$r = $this->post( 'approve', array( 'db:read' ), false );
		$this->assertSame( 403, $r['status'] );
		$this->assertSame( array(), $this->repo->all() );
	}

	public function test_approve_creates_a_token_with_only_the_ticked_scopes(): void {
		$r = $this->post( 'approve', array( 'files:read', 'files:write' ) );
		$this->assertSame( 'done', $r['type'] );
		$this->assertSame( 'approved', $r['outcome'] );
		$this->assertSame( 'files:read,files:write', $this->repo->all()[0]['scopes'] );
		$this->assertSame( 9, $this->repo->all()[0]['user_id'] );
	}

	public function test_approve_with_nothing_ticked_shows_the_form_again(): void {
		$r = $this->post( 'approve', array() );
		$this->assertSame( 'consent', $r['type'] );
		$this->assertNotEmpty( $r['notice'] );
		$this->assertSame( array(), $this->repo->all() );
	}

	public function test_deny_creates_nothing_and_the_request_is_closed(): void {
		$r = $this->post( 'deny' );
		$this->assertSame( 'denied', $r['outcome'] );
		$this->assertSame( array(), $this->repo->all() );
		$this->assertSame( 404, $this->get()['status'] );
	}

	public function test_unknown_decision_is_an_error(): void {
		$this->assertSame( 'error', $this->post( 'maybe' )['type'] );
	}

	public function test_a_request_cannot_be_approved_twice(): void {
		$this->post( 'approve' );
		$this->assertSame( 404, $this->post( 'approve' )['status'] );
		$this->assertCount( 1, $this->repo->all() );
	}
}
