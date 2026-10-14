<?php
/**
 * WP-CLI command parser: tokenizer, flag stripper and blocklist matcher.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Safety;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a command string into an argv array without involving a shell.
 */
final class CommandParser {
	/**
	 * Commands that are never allowed, regardless of settings.
	 *
	 * @var string[]
	 */
	const ALWAYS_BLOCKED = array(
		'shell',
		'db cli',
		'db query',
		'eval',
		'eval-file',
		'cli update',
		'package install',
		'package update',
	);

	/**
	 * Global flags the agent must not control.
	 *
	 * @var string[]
	 */
	const STRIPPED_FLAGS = array(
		'ssh',
		'http',
		'url',
		'path',
		'user',
		'skip-plugins',
		'skip-themes',
		'require',
		'exec',
	);

	/**
	 * Split a command string into words using POSIX-style quoting rules.
	 *
	 * Unquoted whitespace splits words. Single quotes are literal. Double
	 * quotes allow only \" and \\ escapes. A backslash outside quotes escapes
	 * the next character. A leading `wp` word is dropped.
	 *
	 * @param string $command Raw command string.
	 * @return string[] Argument list (re-indexed).
	 * @throws CommandException On an unterminated quote or trailing backslash.
	 */
	public function tokenize( string $command ): array {
		$tokens  = array();
		$current = '';
		$in_word = false;
		$state   = 'none'; // none | single | double.
		$length  = strlen( $command );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $command[ $i ];

			if ( 'single' === $state ) {
				if ( "'" === $char ) {
					$state = 'none';
				} else {
					$current .= $char;
				}
				continue;
			}

			if ( 'double' === $state ) {
				if ( '"' === $char ) {
					$state = 'none';
				} elseif ( '\\' === $char && $i + 1 < $length && ( '"' === $command[ $i + 1 ] || '\\' === $command[ $i + 1 ] ) ) {
					++$i;
					$current .= $command[ $i ];
				} else {
					$current .= $char;
				}
				continue;
			}

			if ( ' ' === $char || "\t" === $char || "\n" === $char || "\r" === $char ) {
				if ( $in_word ) {
					$tokens[] = $current;
					$current  = '';
					$in_word  = false;
				}
				continue;
			}

			$in_word = true;
			if ( "'" === $char ) {
				$state = 'single';
			} elseif ( '"' === $char ) {
				$state = 'double';
			} elseif ( '\\' === $char ) {
				if ( $i + 1 >= $length ) {
					throw new CommandException( 'Command ends with a trailing backslash.' );
				}
				++$i;
				$current .= $command[ $i ];
			} else {
				$current .= $char;
			}
		}

		if ( 'none' !== $state ) {
			throw new CommandException( 'Command contains an unterminated quote.' );
		}
		if ( $in_word ) {
			$tokens[] = $current;
		}

		if ( isset( $tokens[0] ) && 'wp' === $tokens[0] ) {
			array_shift( $tokens );
		}

		return $tokens;
	}

	/**
	 * Remove flags the agent must not control.
	 *
	 * Handles `--name`, `--name=value` and `--name value` (the value token is
	 * consumed only when it exists and does not start with `--`).
	 *
	 * @param string[] $argv Argument list.
	 * @return string[] Filtered, re-indexed list.
	 */
	public function stripFlags( array $argv ): array {
		$argv   = array_values( $argv );
		$result = array();
		$count  = count( $argv );

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $argv[ $i ];

			if ( 0 === strpos( $token, '--' ) ) {
				$body = substr( $token, 2 );
				$eq   = strpos( $body, '=' );
				$name = false === $eq ? $body : substr( $body, 0, $eq );

				if ( in_array( $name, self::STRIPPED_FLAGS, true ) ) {
					if ( false === $eq && $i + 1 < $count && 0 !== strpos( $argv[ $i + 1 ], '--' ) ) {
						++$i;
					}
					continue;
				}
			}

			$result[] = $token;
		}

		return $result;
	}

	/**
	 * Find the first blocklist entry that matches the argument list.
	 *
	 * An entry matches when argv's positional tokens (those not starting with
	 * `-`) begin with the entry's positional words, and every `--flag` word of
	 * the entry appears in argv as an exact token.
	 *
	 * @param string[] $argv      Argument list.
	 * @param string[] $blocklist Entries such as `db cli` or `post delete --force`.
	 * @return string|null The matching entry, or null.
	 */
	public function firstBlocked( array $argv, array $blocklist ): ?string {
		$positional = array();
		foreach ( $argv as $token ) {
			if ( 0 !== strpos( $token, '-' ) ) {
				$positional[] = $token;
			}
		}

		foreach ( $blocklist as $entry ) {
			$words = preg_split( '/ +/', trim( $entry ), -1, PREG_SPLIT_NO_EMPTY );
			if ( empty( $words ) ) {
				continue;
			}

			$entry_positional = array();
			$entry_flags      = array();
			foreach ( $words as $word ) {
				if ( 0 === strpos( $word, '--' ) ) {
					$entry_flags[] = $word;
				} else {
					$entry_positional[] = $word;
				}
			}

			if ( array_slice( $positional, 0, count( $entry_positional ) ) !== $entry_positional ) {
				continue;
			}
			if ( array_diff( $entry_flags, $argv ) ) {
				continue;
			}

			return $entry;
		}

		return null;
	}
}
