<?php
/**
 * PHP syntax check that needs no shell and no eval.
 *
 * @package TechThrill\MCP\Files
 */

namespace TechThrill\MCP\Files\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Uses the tokenizer's parser (token_get_all with TOKEN_PARSE), which reports the same syntax errors as `php -l`
 * for the PHP version the site runs, without running any of the code and without exec.
 */
final class PhpSyntax {

	/**
	 * The first syntax error in a piece of PHP, or null when it parses.
	 *
	 * @param string $code File contents.
	 */
	public static function check( string $code ): ?string {
		if ( ! function_exists( 'token_get_all' ) || ! defined( 'TOKEN_PARSE' ) ) {
			return null;
		}

		try {
			token_get_all( $code, TOKEN_PARSE );
		} catch ( \ParseError $e ) {
			return $e->getMessage() . ' on line ' . $e->getLine();
		} catch ( \Throwable $e ) {
			return null;
		}

		return null;
	}
}
