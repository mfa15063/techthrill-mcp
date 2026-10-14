<?php
/**
 * Minimal JSON-schema validator for tool arguments.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Mcp;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Validation messages are returned to the MCP client as JSON-RPC error text and are never rendered as HTML.
defined( 'ABSPATH' ) || exit;

/**
 * Validates arguments against the supported subset of JSON schema:
 * type (object|string|integer|boolean|array), required, enum, default, minimum, maximum, items.type.
 */
final class SchemaValidator {

	/**
	 * Validates and normalises arguments.
	 *
	 * @param array $schema Tool input schema.
	 * @param array $args    Raw arguments.
	 * @return array Arguments with defaults applied and unknown keys dropped.
	 *
	 * @throws ValidationException When an argument is missing or invalid.
	 */
	public function validate( array $schema, array $args ): array {
		$properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
		$required   = isset( $schema['required'] ) && is_array( $schema['required'] ) ? $schema['required'] : array();

		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $args ) ) {
				throw new ValidationException( sprintf( 'Missing required argument "%s".', $key ) );
			}
		}

		$clean = array();
		foreach ( $properties as $key => $rules ) {
			$key = (string) $key;
			if ( array_key_exists( $key, $args ) ) {
				$this->check( $key, $args[ $key ], (array) $rules );
				$clean[ $key ] = $args[ $key ];
			} elseif ( is_array( $rules ) && array_key_exists( 'default', $rules ) ) {
				$clean[ $key ] = $rules['default'];
			}
		}

		return $clean;
	}

	/**
	 * Checks one value against its property rules.
	 *
	 * @param string $key   Argument name.
	 * @param mixed  $value Argument value.
	 * @param array  $rules Property schema.
	 *
	 * @throws ValidationException When the value is invalid.
	 */
	private function check( string $key, $value, array $rules ): void {
		if ( isset( $rules['type'] ) ) {
			$this->check_type( $key, $value, (string) $rules['type'] );
		}

		if ( isset( $rules['enum'] ) && is_array( $rules['enum'] ) && ! in_array( $value, $rules['enum'], true ) ) {
			throw new ValidationException(
				sprintf( 'Argument "%s" must be one of: %s.', $key, implode( ', ', array_map( 'strval', $rules['enum'] ) ) )
			);
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			if ( isset( $rules['minimum'] ) && $value < $rules['minimum'] ) {
				throw new ValidationException( sprintf( 'Argument "%s" must be at least %s.', $key, $rules['minimum'] ) );
			}
			if ( isset( $rules['maximum'] ) && $value > $rules['maximum'] ) {
				throw new ValidationException( sprintf( 'Argument "%s" must be at most %s.', $key, $rules['maximum'] ) );
			}
		}

		if ( is_array( $value ) && isset( $rules['items']['type'] ) ) {
			foreach ( $value as $item ) {
				$this->check_type( $key, $item, (string) $rules['items']['type'], true );
			}
		}
	}

	/**
	 * Checks a value's JSON type.
	 *
	 * @param string $key   Argument name.
	 * @param mixed  $value Value.
	 * @param string $type  Expected type.
	 * @param bool   $item  Whether the value is an array element.
	 *
	 * @throws ValidationException When the type does not match.
	 */
	private function check_type( string $key, $value, string $type, bool $item = false ): void {
		switch ( $type ) {
			case 'string':
				$ok = is_string( $value );
				$as = 'a string';
				break;
			case 'integer':
				$ok = is_int( $value );
				$as = 'an integer';
				break;
			case 'boolean':
				$ok = is_bool( $value );
				$as = 'a boolean';
				break;
			case 'array':
				$ok = is_array( $value );
				$as = 'an array';
				break;
			case 'object':
				$ok = is_array( $value );
				$as = 'an object';
				break;
			default:
				return;
		}

		if ( ! $ok ) {
			throw new ValidationException(
				$item
					? sprintf( 'Every item of argument "%s" must be %s.', $key, $as )
					: sprintf( 'Argument "%s" must be %s.', $key, $as )
			);
		}
	}
}

// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
