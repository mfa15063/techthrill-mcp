<?php
/**
 * Safe mode policy: the single place tools ask before a destructive action.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Safety;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether SQL, WP-CLI and file operations are allowed.
 *
 * Every check returns null when the action is allowed, or a Refusal whose
 * reason is shown to the agent. Safe-mode refusals start with
 * "Refused by safe mode: ", refusals that apply in every mode start with
 * "Refused: " and confirmation refusals start with "Confirmation required: ".
 */
final class SafeMode {

	/**
	 * WP-CLI commands blocked while safe mode is on.
	 *
	 * @var string[]
	 */
	const SAFE_MODE_BLOCKED_CLI = array(
		'db reset',
		'db drop',
		'db clean',
		'db import',
		'site empty',
		'core download',
		'core update-db',
		'plugin uninstall',
		'plugin delete',
		'theme delete',
		'user delete',
		'post delete --force',
		'site delete',
	);

	/**
	 * Paths that can never be written, in any mode.
	 *
	 * @var string[]
	 */
	const PROTECTED_NEVER_WRITE = array( 'wp-config.php', 'wp-cli.yml', 'wp-cli.local.yml' );

	/**
	 * Paths that cannot be written while safe mode is on (the plugin directory is added at runtime).
	 *
	 * @var string[]
	 */
	const PROTECTED_SAFE_MODE = array( '.htaccess', 'wp-content/uploads/techthrill-mcp' );

	/**
	 * Suffix of every safe-mode refusal.
	 */
	private const SAFE_MODE_HINT = ' The site administrator can disable safe mode under TechThrill MCP → Settings if this is intended.';

	/**
	 * Suffix of every confirmation refusal.
	 */
	private const CONFIRM_HINT = ' Ask the user to confirm, then call again with confirm_destructive set to true.';

	/**
	 * Whether safe mode is on.
	 *
	 * @var bool
	 */
	private bool $enabled;

	/**
	 * Plugin directory relative to the site root, e.g. wp-content/plugins/techthrill-mcp.
	 *
	 * @var string
	 */
	private string $pluginRelativeDir;

	/**
	 * Parser used to match WP-CLI commands against the blocklist.
	 *
	 * @var CommandParser
	 */
	private CommandParser $parser;

	/**
	 * Filter function with the apply_filters( $hook, $value ) signature.
	 *
	 * @var callable
	 */
	private $filter;

	/**
	 * @param bool               $enabled           Whether safe mode is on.
	 * @param string             $pluginRelativeDir Plugin directory relative to the site root.
	 * @param CommandParser|null $parser            Command parser (default: a new one).
	 * @param callable|null      $filter            Filter function (default: apply_filters).
	 */
	public function __construct( bool $enabled, string $pluginRelativeDir, ?CommandParser $parser = null, ?callable $filter = null ) {
		$this->enabled           = $enabled;
		$this->pluginRelativeDir = $pluginRelativeDir;
		$this->parser            = $parser ?? new CommandParser();
		$this->filter            = $filter ?? 'apply_filters';
	}

	/**
	 * Whether safe mode is on.
	 */
	public function isEnabled(): bool {
		return $this->enabled;
	}

	/**
	 * Check a classified SQL statement.
	 *
	 * @param SqlStatement $s         Classified statement.
	 * @param bool         $confirmed Whether the agent passed confirm_destructive.
	 */
	public function checkSql( SqlStatement $s, bool $confirmed ): ?Refusal {
		$noWhere = in_array( $s->type, array( 'delete', 'update' ), true ) && ! $s->hasWhere;
		if ( ! $noWhere && ! in_array( $s->type, array( 'drop', 'truncate' ), true ) ) {
			return null;
		}

		$type  = strtoupper( $s->type );
		$table = '`' . ( null !== $s->table && '' !== $s->table ? $s->table : 'the target table' ) . '`';

		if ( $this->enabled ) {
			$what = $noWhere
				? $type . ' without a WHERE clause would affect every row in ' . $table . '.'
				: $type . ' on ' . $table . ' is a destructive operation.';
			return new Refusal( 'Refused by safe mode: ' . $what . self::SAFE_MODE_HINT );
		}

		if ( $confirmed ) {
			return null;
		}

		$what = $noWhere
			? $type . ' without a WHERE clause would affect every row in ' . $table . ' and cannot be undone.'
			: $type . ' on ' . $table . ' is destructive and cannot be undone.';
		return new Refusal( 'Confirmation required: ' . $what . self::CONFIRM_HINT );
	}

	/**
	 * Check a parsed WP-CLI command. Throws CommandException on a leading @alias.
	 *
	 * @param string[] $argv Parsed arguments.
	 */
	public function checkCli( array $argv ): ?Refusal {
		if ( ! $this->enabled ) {
			return null;
		}

		$list = call_user_func( $this->filter, 'techthrill_mcp_blocked_cli_commands', self::SAFE_MODE_BLOCKED_CLI );
		$list = array_values( array_filter( is_array( $list ) ? $list : self::SAFE_MODE_BLOCKED_CLI, 'is_string' ) );

		$entry = $this->parser->firstBlocked( $argv, $list );
		if ( null !== $entry ) {
			return new Refusal( 'Refused by safe mode: the command "wp ' . $entry . '" is destructive.' . self::SAFE_MODE_HINT );
		}

		return $this->checkDbExportFile( $argv );
	}

	/**
	 * Refuses every `wp db export` form that writes a dump into the site.
	 *
	 * WP-CLI writes to standard output only when the file argument is `-`; with no file argument it
	 * writes {DB_NAME}-{date}-{hex}.sql into the working directory (ABSPATH), and unknown flags such
	 * as --result-file or --tab are passed to mysqldump, which then writes files itself.
	 *
	 * @param string[] $argv Parsed arguments.
	 */
	private function checkDbExportFile( array $argv ): ?Refusal {
		$positional = array();
		foreach ( $argv as $token ) {
			// A lone "-" is the file argument (standard output), not a flag.
			if ( '-' === $token || 0 !== strpos( $token, '-' ) ) {
				$positional[] = $token;
			}
		}

		if ( count( $positional ) < 2 || 'db' !== $positional[0] || 'export' !== $positional[1] ) {
			return null;
		}

		$to_stdout = isset( $positional[2] ) && '-' === $positional[2];
		foreach ( $argv as $token ) {
			if ( 0 === strpos( $token, '--result-file' ) || '--tab' === $token || 0 === strpos( $token, '--tab=' ) ) {
				$to_stdout = false;
			}
		}
		if ( $to_stdout ) {
			return null;
		}

		return new Refusal( 'Refused by safe mode: "wp db export" writes a database dump into the site unless the output file is "-" (standard output). Use "wp db export -" or disable safe mode.' . self::SAFE_MODE_HINT );
	}

	/**
	 * Check a path (relative to the site root) about to be written or deleted.
	 *
	 * @param string $relative Path relative to the site root.
	 */
	public function checkPathWrite( string $relative ): ?Refusal {
		$path = $this->normalize( $relative );

		foreach ( self::PROTECTED_NEVER_WRITE as $entry ) {
			if ( $this->matches( $path, $entry ) ) {
				return new Refusal( 'Refused: ' . $entry . ' cannot be modified or deleted through TechThrill MCP.' );
			}
		}

		if ( ! $this->enabled ) {
			return null;
		}

		$default = array_merge( self::PROTECTED_SAFE_MODE, array( $this->pluginRelativeDir ) );
		$list    = call_user_func( $this->filter, 'techthrill_mcp_protected_paths', $default );
		$list    = array_values( array_filter( is_array( $list ) ? $list : $default, 'is_string' ) );

		foreach ( $list as $entry ) {
			if ( $this->matches( $path, $entry ) ) {
				return new Refusal( 'Refused by safe mode: writing to `' . $relative . '` is blocked because it is a protected location.' . self::SAFE_MODE_HINT );
			}
		}
		return null;
	}

	/**
	 * Check whether a directory may be deleted.
	 */
	public function checkDirectoryDelete(): ?Refusal {
		if ( ! $this->enabled ) {
			return null;
		}
		return new Refusal( 'Refused by safe mode: deleting a directory is blocked.' . self::SAFE_MODE_HINT );
	}

	/**
	 * Lower-case, forward-slash form without empty or "." segments.
	 *
	 * @param string $path Path.
	 */
	private function normalize( string $path ): string {
		$segments = explode( '/', str_replace( '\\', '/', strtolower( $path ) ) );
		$segments = array_filter(
			$segments,
			static function ( $segment ) {
				return '' !== $segment && '.' !== $segment;
			}
		);
		return implode( '/', $segments );
	}

	/**
	 * Whether a normalised path equals the entry or lies inside it.
	 *
	 * @param string $path  Normalised path.
	 * @param string $entry Protected entry (any case or slash style).
	 */
	private function matches( string $path, string $entry ): bool {
		$entry = $this->normalize( $entry );
		if ( '' === $entry ) {
			return false;
		}
		return $path === $entry || 0 === strpos( $path, $entry . '/' );
	}
}
