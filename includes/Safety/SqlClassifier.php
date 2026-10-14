<?php
/**
 * Pure-PHP SQL statement classifier.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Safety;

defined( 'ABSPATH' ) || exit;

/**
 * Classifies a single SQL statement without ever consulting the database.
 *
 * The classifier is deliberately conservative: anything it cannot classify is
 * "other", and any sign of more than one statement is an error. Comments,
 * quoted strings and backticked identifiers are tokenised away so keywords
 * inside them never influence the result.
 */
final class SqlClassifier {

	private const TYPE_WORD  = 'w';
	private const TYPE_IDENT = 'i';
	private const TYPE_STR   = 's';
	private const TYPE_PUNCT = 'p';

	/**
	 * First keyword to statement type. WITH is resolved separately.
	 */
	private const TYPES = array(
		'SELECT'   => 'select',
		'SHOW'     => 'show',
		'DESC'     => 'describe',
		'DESCRIBE' => 'describe',
		'EXPLAIN'  => 'explain',
		'INSERT'   => 'insert',
		'UPDATE'   => 'update',
		'DELETE'   => 'delete',
		'REPLACE'  => 'replace',
		'CREATE'   => 'create',
		'ALTER'    => 'alter',
		'DROP'     => 'drop',
		'TRUNCATE' => 'truncate',
		'RENAME'   => 'rename',
	);

	/**
	 * Top-level keywords that end a WHERE clause.
	 */
	private const WHERE_END = array( 'LIMIT', 'ORDER', 'GROUP', 'HAVING' );

	/**
	 * WHERE clauses that match every row (upper-cased, whitespace removed).
	 */
	private const TAUTOLOGIES = array( '1', 'TRUE', '1=1' );

	/**
	 * Keywords that start the main statement after a WITH (CTE) clause.
	 */
	private const WITH_MAIN = array(
		'SELECT'  => 'select',
		'UPDATE'  => 'update',
		'DELETE'  => 'delete',
		'INSERT'  => 'insert',
		'REPLACE' => 'replace',
	);

	/**
	 * Classifies one SQL statement.
	 *
	 * @param string $sql Raw SQL.
	 * @return SqlStatement
	 *
	 * @throws SqlException When the SQL is empty or holds more than one statement.
	 */
	public function classify( string $sql ): SqlStatement {
		$scan   = $this->tokenize( $sql );
		$tokens = $scan['tokens'];

		if ( array() === $tokens ) {
			throw new SqlException( 'Empty SQL statement.' );
		}

		$normalised = rtrim( substr( $sql, $scan['start'], $scan['end'] - $scan['start'] ) );

		$type  = 'other';
		$index = 0;
		$first = $tokens[0];

		if ( self::TYPE_WORD === $first['k'] ) {
			if ( isset( self::TYPES[ $first['u'] ] ) ) {
				$type = self::TYPES[ $first['u'] ];
			} elseif ( 'WITH' === $first['u'] ) {
				// A CTE may prefix a write, so the main statement decides the type.
				foreach ( $tokens as $i => $token ) {
					if ( 0 === $token['d'] && self::TYPE_WORD === $token['k'] && isset( self::WITH_MAIN[ $token['u'] ] ) ) {
						$type  = self::WITH_MAIN[ $token['u'] ];
						$index = $i;
						break;
					}
				}
			}
		}

		if ( ( 'explain' === $type || 'describe' === $type ) && $this->wordAt( $tokens, 1, 'ANALYZE' ) ) {
			// DESC, DESCRIBE and EXPLAIN are one keyword; ANALYZE executes the statement it describes.
			$type = 'other';
		}

		if ( 'select' === $type && $this->writesFile( $tokens ) ) {
			$type = 'other';
		}

		if ( 'other' === $type ) {
			return new SqlStatement( 'other', false, false, null, $normalised );
		}

		$has_where = false;
		$has_limit = false;
		$count     = count( $tokens );
		for ( $i = $index; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( 0 !== $token['d'] || self::TYPE_WORD !== $token['k'] ) {
				continue;
			}
			if ( 'WHERE' === $token['u'] ) {
				if ( ! $this->isTautology( $tokens, $i + 1 ) ) {
					$has_where = true;
				}
			} elseif ( 'LIMIT' === $token['u'] ) {
				$has_limit = true;
			}
		}

		return new SqlStatement( $type, $has_where, $has_limit, $this->findTable( $type, $tokens, $index ), $normalised );
	}

	/**
	 * Splits SQL into tokens, discarding comments and whitespace.
	 *
	 * Each token is array{k: string, v: string, u: string, d: int} with kind,
	 * value (identifiers unquoted), upper-cased value and parenthesis depth.
	 *
	 * @param string $sql Raw SQL.
	 * @return array{tokens: array<int, array>, start: int, end: int}
	 *
	 * @throws SqlException On multiple statements or MySQL executable comments.
	 */
	private function tokenize( string $sql ): array {
		$len        = strlen( $sql );
		$i          = 0;
		$depth      = 0;
		$tokens     = array();
		$start      = 0;
		$end        = 0;
		$terminated = false;

		while ( $i < $len ) {
			$c = $sql[ $i ];

			if ( ctype_space( $c ) ) {
				++$i;
				continue;
			}

			// Comments: skipped, never part of a token.
			if ( '#' === $c || ( '-' === $c && $this->isDashComment( $sql, $i, $len ) ) ) {
				$nl = strpos( $sql, "\n", $i );
				$i  = false === $nl ? $len : $nl + 1;
				continue;
			}
			if ( '/' === $c && $i + 1 < $len && '*' === $sql[ $i + 1 ] ) {
				if ( $this->isExecutableComment( $sql, $i, $len ) ) {
					// MySQL runs the body of /*! ... */ and MariaDB that of /*M! ... */, so it can hide a statement.
					throw new SqlException( 'MySQL/MariaDB executable comments (/*! ... */, /*M! ... */) are not allowed.' );
				}
				$close = strpos( $sql, '*/', $i + 2 );
				$i     = false === $close ? $len : $close + 2;
				continue;
			}

			// A real token starts here.
			if ( $terminated ) {
				throw new SqlException( 'Multiple SQL statements are not allowed.' );
			}

			if ( ';' === $c ) {
				if ( array() === $tokens ) {
					throw new SqlException( 'Empty SQL statement.' );
				}
				$terminated = true;
				++$i;
				continue;
			}

			if ( array() === $tokens ) {
				$start = $i;
			}

			if ( "'" === $c || '"' === $c || '`' === $c ) {
				$value = '';
				++$i;
				while ( $i < $len ) {
					$ch = $sql[ $i ];
					if ( '\\' === $ch && '`' !== $c ) {
						$i += 2;
						continue;
					}
					if ( $ch === $c ) {
						if ( $i + 1 < $len && $sql[ $i + 1 ] === $c ) {
							$value .= $c;
							$i     += 2;
							continue;
						}
						++$i;
						break;
					}
					$value .= $ch;
					++$i;
				}
				$kind     = '`' === $c ? self::TYPE_IDENT : self::TYPE_STR;
				$tokens[] = array(
					'k' => $kind,
					'v' => $value,
					'u' => '',
					'd' => $depth,
				);
				$end      = min( $i, $len );
				continue;
			}

			if ( ctype_alnum( $c ) || '_' === $c || '$' === $c || '@' === $c || ord( $c ) >= 128 ) {
				$word_start = $i;
				while ( $i < $len && ( ctype_alnum( $sql[ $i ] ) || '_' === $sql[ $i ] || '$' === $sql[ $i ] || '@' === $sql[ $i ] || ord( $sql[ $i ] ) >= 128 ) ) {
					++$i;
				}
				$word     = substr( $sql, $word_start, $i - $word_start );
				$tokens[] = array(
					'k' => self::TYPE_WORD,
					'v' => $word,
					'u' => strtoupper( $word ),
					'd' => $depth,
				);
				$end      = min( $i, $len );
				continue;
			}

			if ( ')' === $c && $depth > 0 ) {
				--$depth;
			}
			$tokens[] = array(
				'k' => self::TYPE_PUNCT,
				'v' => $c,
				'u' => $c,
				'd' => $depth,
			);
			if ( '(' === $c ) {
				++$depth;
			}
			++$i;
			$end = $i;
		}

		return array(
			'tokens' => $tokens,
			'start'  => $start,
			'end'    => $end,
		);
	}

	/**
	 * Whether a block comment at an offset is a MySQL (/*!) or MariaDB (/*M!) executable comment.
	 *
	 * @param string $sql Raw SQL.
	 * @param int    $i   Offset of the slash.
	 * @param int    $len SQL length.
	 */
	private function isExecutableComment( string $sql, int $i, int $len ): bool {
		if ( $i + 2 >= $len ) {
			return false;
		}
		if ( '!' === $sql[ $i + 2 ] ) {
			return true;
		}
		return ( 'M' === $sql[ $i + 2 ] || 'm' === $sql[ $i + 2 ] ) && $i + 3 < $len && '!' === $sql[ $i + 3 ];
	}

	/**
	 * A "--" only starts a comment when followed by whitespace or end of input.
	 *
	 * @param string $sql Raw SQL.
	 * @param int    $i   Offset of the first dash.
	 * @param int    $len SQL length.
	 */
	private function isDashComment( string $sql, int $i, int $len ): bool {
		if ( $i + 1 >= $len || '-' !== $sql[ $i + 1 ] ) {
			return false;
		}
		return $i + 2 >= $len || ctype_space( $sql[ $i + 2 ] ) || ctype_cntrl( $sql[ $i + 2 ] );
	}

	/**
	 * Whether the token at an index is the given keyword.
	 *
	 * @param array  $tokens Tokens.
	 * @param int    $index  Token index.
	 * @param string $word   Upper-case keyword.
	 */
	private function wordAt( array $tokens, int $index, string $word ): bool {
		return isset( $tokens[ $index ] ) && self::TYPE_WORD === $tokens[ $index ]['k'] && $word === $tokens[ $index ]['u'];
	}

	/**
	 * Whether the WHERE clause starting at an index is exactly 1, TRUE or 1=1, which match every row.
	 *
	 * The clause runs to the end of the statement or to a top-level LIMIT, ORDER, GROUP or HAVING.
	 *
	 * @param array $tokens Tokens.
	 * @param int   $index  Index of the first token after WHERE.
	 */
	private function isTautology( array $tokens, int $index ): bool {
		$clause = '';
		$count  = count( $tokens );
		for ( $i = $index; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( 0 === $token['d'] && self::TYPE_WORD === $token['k'] && in_array( $token['u'], self::WHERE_END, true ) ) {
				break;
			}
			if ( self::TYPE_WORD !== $token['k'] && self::TYPE_PUNCT !== $token['k'] ) {
				return false;
			}
			$clause .= $token['u'];
		}

		return in_array( $clause, self::TAUTOLOGIES, true );
	}

	/**
	 * Detects SELECT ... INTO OUTFILE|DUMPFILE, which writes server files.
	 *
	 * @param array $tokens Tokens.
	 */
	private function writesFile( array $tokens ): bool {
		foreach ( $tokens as $i => $token ) {
			if ( self::TYPE_WORD === $token['k'] && 'INTO' === $token['u'] && ( $this->wordAt( $tokens, $i + 1, 'OUTFILE' ) || $this->wordAt( $tokens, $i + 1, 'DUMPFILE' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Skips any of the given keywords starting at an index.
	 *
	 * @param array    $tokens Tokens.
	 * @param int      $index  Start index.
	 * @param string[] $words  Upper-case keywords to skip.
	 * @return int First index that is not one of the keywords.
	 */
	private function skipWords( array $tokens, int $index, array $words ): int {
		while ( isset( $tokens[ $index ] ) && self::TYPE_WORD === $tokens[ $index ]['k'] && in_array( $tokens[ $index ]['u'], $words, true ) ) {
			++$index;
		}
		return $index;
	}

	/**
	 * Finds the target table of a write statement.
	 *
	 * @param string $type   Statement type.
	 * @param array  $tokens Tokens.
	 * @param int    $index  Index of the statement keyword.
	 * @return string|null Table name without backticks or schema, or null.
	 */
	private function findTable( string $type, array $tokens, int $index ): ?string {
		$j = $index + 1;

		switch ( $type ) {
			case 'update':
				return $this->readIdentifier( $tokens, $this->skipWords( $tokens, $j, array( 'LOW_PRIORITY', 'IGNORE' ) ) );

			case 'insert':
			case 'replace':
				return $this->readIdentifier( $tokens, $this->skipWords( $tokens, $j, array( 'LOW_PRIORITY', 'DELAYED', 'HIGH_PRIORITY', 'IGNORE', 'INTO' ) ) );

			case 'truncate':
				return $this->readIdentifier( $tokens, $this->skipWords( $tokens, $j, array( 'TABLE' ) ) );

			case 'delete':
				$j = $this->skipWords( $tokens, $j, array( 'LOW_PRIORITY', 'QUICK', 'IGNORE' ) );
				if ( $this->wordAt( $tokens, $j, 'FROM' ) ) {
					return $this->readIdentifier( $tokens, $j + 1 );
				}
				// Multi-table form: DELETE t1 FROM t1 JOIN ...
				for ( $count = count( $tokens ); $j < $count; $j++ ) {
					if ( 0 === $tokens[ $j ]['d'] && $this->wordAt( $tokens, $j, 'FROM' ) ) {
						return $this->readIdentifier( $tokens, $j + 1 );
					}
				}
				return null;

			case 'drop':
				$j = $this->skipWords( $tokens, $j, array( 'TEMPORARY' ) );
				if ( ! $this->wordAt( $tokens, $j, 'TABLE' ) ) {
					return null;
				}
				++$j;
				if ( $this->wordAt( $tokens, $j, 'IF' ) && $this->wordAt( $tokens, $j + 1, 'EXISTS' ) ) {
					$j += 2;
				}
				return $this->readIdentifier( $tokens, $j );
		}

		return null;
	}

	/**
	 * Reads a possibly schema-qualified identifier, keeping only the final part.
	 *
	 * @param array $tokens Tokens.
	 * @param int   $index  Index of the first identifier part.
	 * @return string|null
	 */
	private function readIdentifier( array $tokens, int $index ): ?string {
		if ( ! $this->isIdentifierToken( $tokens, $index ) ) {
			return null;
		}
		$name = $tokens[ $index ]['v'];
		while ( isset( $tokens[ $index + 1 ] ) && self::TYPE_PUNCT === $tokens[ $index + 1 ]['k'] && '.' === $tokens[ $index + 1 ]['v'] && $this->isIdentifierToken( $tokens, $index + 2 ) ) {
			$name   = $tokens[ $index + 2 ]['v'];
			$index += 2;
		}
		return '' === $name ? null : $name;
	}

	/**
	 * Whether the token at an index can be an identifier part.
	 *
	 * @param array $tokens Tokens.
	 * @param int   $index  Token index.
	 */
	private function isIdentifierToken( array $tokens, int $index ): bool {
		return isset( $tokens[ $index ] ) && ( self::TYPE_WORD === $tokens[ $index ]['k'] || self::TYPE_IDENT === $tokens[ $index ]['k'] );
	}
}
