<?php
namespace TechThrill\MCP\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\OAuth\OAuthException;
use TechThrill\MCP\OAuth\Pkce;

class PkceTest extends TestCase {

	const VERIFIER  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
	const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

	public function test_rfc7636_vector_verifies(): void {
		$this->assertSame( self::CHALLENGE, Pkce::challengeFor( self::VERIFIER ) );
		$this->assertTrue( Pkce::verify( self::VERIFIER, self::CHALLENGE ) );
	}

	public function test_wrong_verifier_fails(): void {
		$this->assertFalse( Pkce::verify( str_repeat( 'a', 43 ), self::CHALLENGE ) );
	}

	public function test_challenge_must_be_43_base64url_chars(): void {
		$this->assertTrue( Pkce::isValidChallenge( self::CHALLENGE ) );
		$this->assertFalse( Pkce::isValidChallenge( substr( self::CHALLENGE, 0, 42 ) ) );
		$this->assertFalse( Pkce::isValidChallenge( self::CHALLENGE . 'a' ) );
		$this->assertFalse( Pkce::isValidChallenge( substr( self::CHALLENGE, 0, 42 ) . '+' ) );
		$this->assertFalse( Pkce::isValidChallenge( substr( self::CHALLENGE, 0, 42 ) . '=' ) );
		$this->assertFalse( Pkce::isValidChallenge( '' ) );
	}

	public function test_verifier_length_and_alphabet(): void {
		$this->assertFalse( Pkce::isValidVerifier( str_repeat( 'a', 42 ) ) );
		$this->assertTrue( Pkce::isValidVerifier( str_repeat( 'a', 43 ) ) );
		$this->assertTrue( Pkce::isValidVerifier( str_repeat( 'a', 128 ) ) );
		$this->assertFalse( Pkce::isValidVerifier( str_repeat( 'a', 129 ) ) );
		$this->assertFalse( Pkce::isValidVerifier( str_repeat( 'a', 42 ) . ' ' ) );
		$this->assertTrue( Pkce::isValidVerifier( str_repeat( 'a', 40 ) . '-._~' ) );
	}

	public function test_a_malformed_verifier_never_verifies(): void {
		$this->assertFalse( Pkce::verify( 'short', Pkce::challengeFor( 'short' ) ) );
	}

	public function test_exception_exposes_error_description_and_status(): void {
		$e = new OAuthException( 'invalid_grant', 'Nope.', 401 );
		$this->assertSame( 'invalid_grant', $e->error() );
		$this->assertSame( 'Nope.', $e->description() );
		$this->assertSame( 401, $e->status() );
		$this->assertSame( 400, ( new OAuthException( 'x', 'y' ) )->status() );
	}
}
