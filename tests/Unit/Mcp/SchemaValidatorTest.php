<?php
namespace TechThrill\MCP\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use TechThrill\MCP\Mcp\SchemaValidator;
use TechThrill\MCP\Mcp\ValidationException;

class SchemaValidatorTest extends TestCase {
	private function validator(): SchemaValidator {
		return new SchemaValidator();
	}

	public function test_missing_required_key_throws(): void {
		$schema = array(
			'type'       => 'object',
			'properties' => array( 'sql' => array( 'type' => 'string' ) ),
			'required'   => array( 'sql' ),
		);
		$this->expectException( ValidationException::class );
		$this->expectExceptionMessage( 'sql' );
		$this->validator()->validate( $schema, array() );
	}

	public function test_exception_is_invalid_argument(): void {
		$this->assertInstanceOf( \InvalidArgumentException::class, new ValidationException( 'x' ) );
	}

	public function test_defaults_applied(): void {
		$schema = array(
			'type'       => 'object',
			'properties' => array( 'limit' => array( 'type' => 'integer', 'default' => 100 ) ),
		);
		$this->assertSame( array( 'limit' => 100 ), $this->validator()->validate( $schema, array() ) );
	}

	public function test_wrong_type_throws(): void {
		$schema = array(
			'type'       => 'object',
			'properties' => array( 'limit' => array( 'type' => 'integer' ) ),
		);
		$this->expectException( ValidationException::class );
		$this->expectExceptionMessage( 'Argument "limit" must be an integer.' );
		$this->validator()->validate( $schema, array( 'limit' => 'ten' ) );
	}

	public function test_enum_enforced(): void {
		$schema = array(
			'type'       => 'object',
			'properties' => array( 'mode' => array( 'type' => 'string', 'enum' => array( 'a', 'b' ) ) ),
		);
		$this->assertSame( array( 'mode' => 'a' ), $this->validator()->validate( $schema, array( 'mode' => 'a' ) ) );
		$this->expectException( ValidationException::class );
		$this->expectExceptionMessage( 'mode' );
		$this->validator()->validate( $schema, array( 'mode' => 'c' ) );
	}

	public function test_integer_bounds(): void {
		$schema = array(
			'type'       => 'object',
			'properties' => array( 'n' => array( 'type' => 'integer', 'minimum' => 5, 'maximum' => 300 ) ),
		);
		$this->assertSame( array( 'n' => 300 ), $this->validator()->validate( $schema, array( 'n' => 300 ) ) );
		$this->assertSame( array( 'n' => 5 ), $this->validator()->validate( $schema, array( 'n' => 5 ) ) );
		$this->expectException( ValidationException::class );
		$this->expectExceptionMessage( 'n' );
		$this->validator()->validate( $schema, array( 'n' => 301 ) );
	}

	public function test_below_minimum_throws(): void {
		$schema = array(
			'type'       => 'object',
			'properties' => array( 'n' => array( 'type' => 'integer', 'minimum' => 5 ) ),
		);
		$this->expectException( ValidationException::class );
		$this->validator()->validate( $schema, array( 'n' => 4 ) );
	}

	public function test_unknown_keys_dropped(): void {
		$schema = array(
			'type'       => 'object',
			'properties' => array( 'a' => array( 'type' => 'string' ) ),
		);
		$this->assertSame( array( 'a' => 'x' ), $this->validator()->validate( $schema, array( 'a' => 'x', 'zzz' => 1 ) ) );
	}

	public function test_boolean_and_string_types(): void {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'flag' => array( 'type' => 'boolean' ),
				's'    => array( 'type' => 'string' ),
			),
		);
		$this->assertSame( array( 'flag' => false, 's' => '' ), $this->validator()->validate( $schema, array( 'flag' => false, 's' => '' ) ) );
		$this->expectException( ValidationException::class );
		$this->expectExceptionMessage( 'Argument "flag" must be a boolean.' );
		$this->validator()->validate( $schema, array( 'flag' => 'yes' ) );
	}

	public function test_integer_rejects_float(): void {
		$schema = array(
			'type'       => 'object',
			'properties' => array( 'n' => array( 'type' => 'integer' ) ),
		);
		$this->expectException( ValidationException::class );
		$this->validator()->validate( $schema, array( 'n' => 1.5 ) );
	}

	public function test_array_items_type(): void {
		$schema = array(
			'type'       => 'object',
			'properties' => array( 'ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ) ),
		);
		$this->assertSame( array( 'ids' => array( 1, 2 ) ), $this->validator()->validate( $schema, array( 'ids' => array( 1, 2 ) ) ) );
		$this->expectException( ValidationException::class );
		$this->expectExceptionMessage( 'ids' );
		$this->validator()->validate( $schema, array( 'ids' => array( 1, 'x' ) ) );
	}

	public function test_array_type_rejects_non_array(): void {
		$schema = array(
			'type'       => 'object',
			'properties' => array( 'ids' => array( 'type' => 'array' ) ),
		);
		$this->expectException( ValidationException::class );
		$this->expectExceptionMessage( 'Argument "ids" must be an array.' );
		$this->validator()->validate( $schema, array( 'ids' => 'nope' ) );
	}
}
