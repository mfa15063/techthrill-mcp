<?php
/**
 * Base class for the addon's file tools.
 *
 * @package TechThrill\MCP\Files
 */

namespace TechThrill\MCP\Files\Tools;

use TechThrill\MCP\Files\Support\AllowedRoots;
use TechThrill\MCP\Files\Support\BackupStore;
use TechThrill\MCP\Files\Support\FileRules;
use TechThrill\MCP\Files\Support\ToolFailure;
use TechThrill\MCP\Safety\JailedPath;
use TechThrill\MCP\Safety\PathException;
use TechThrill\MCP\Safety\PathJail;
use TechThrill\MCP\Safety\SafeMode;
use TechThrill\MCP\Tools\AbstractFileTool;
use TechThrill\MCP\Tools\ToolContext;
use TechThrill\MCP\Tools\ToolResult;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- These tools operate directly on theme and plugin files that PathJail has already confined; WP_Filesystem needs credentials and an admin request context, and every silenced call is checked through its return value.
defined( 'ABSPATH' ) || exit;

/**
 * Shared checks: every path is resolved through the main plugin's PathJail (so symlinks cannot escape the site),
 * must lie strictly below an allowed folder (themes, plugins), and passes the main plugin's safe mode.
 */
abstract class AbstractCodeTool extends AbstractFileTool {

	/**
	 * Largest text file the tools read or write, in bytes (5 MB).
	 */
	const MAX_TEXT = 5242880;

	protected AllowedRoots $roots;

	protected BackupStore $backups;

	/**
	 * Returns root-relative paths of the active themes and plugins (folders and main files).
	 *
	 * @var callable
	 */
	protected $activeRoots;

	/**
	 * Root-relative folders that can never be renamed or moved, such as the main plugin and this addon.
	 *
	 * @var string[]
	 */
	protected array $neverMove;

	/**
	 * @param PathJail      $jail        Main plugin's path jail.
	 * @param SafeMode      $safeMode    Main plugin's safe mode.
	 * @param AllowedRoots  $roots       Folders the tools may touch.
	 * @param BackupStore   $backups     Backup storage.
	 * @param callable|null $activeRoots Returns the active themes' and plugins' root-relative paths.
	 * @param string[]      $neverMove   Folders that can never be renamed or moved.
	 */
	public function __construct( PathJail $jail, SafeMode $safeMode, AllowedRoots $roots, BackupStore $backups, ?callable $activeRoots = null, array $neverMove = array() ) {
		parent::__construct( $jail, $safeMode );
		$this->roots       = $roots;
		$this->backups     = $backups;
		$this->activeRoots = $activeRoots ?? static function (): array {
			return array();
		};
		$this->neverMove   = array_map( array( AllowedRoots::class, 'normalize' ), $neverMove );
	}

	/**
	 * Runs the tool and turns a refusal into an error result.
	 *
	 * @param array       $args Arguments.
	 * @param ToolContext $ctx  Call context.
	 */
	final public function execute( array $args, ToolContext $ctx ): ToolResult {
		try {
			return $this->run( $args, $ctx );
		} catch ( ToolFailure $e ) {
			return $this->fail( $e->getMessage() );
		} catch ( PathException $e ) {
			return $this->fail( $e->getMessage() );
		}
	}

	/**
	 * The tool's work.
	 *
	 * @param array       $args Arguments.
	 * @param ToolContext $ctx  Call context.
	 * @throws ToolFailure When the call must be refused.
	 */
	abstract protected function run( array $args, ToolContext $ctx ): ToolResult;

	/**
	 * Resolves an existing path.
	 *
	 * @param string $path Root-relative path.
	 * @throws PathException When missing or outside the site.
	 */
	protected function existing( string $path ): JailedPath {
		return $this->jail->resolve( $path );
	}

	/**
	 * Resolves a path that may not exist yet.
	 *
	 * @param string $path Root-relative path.
	 * @throws PathException When invalid or outside the site.
	 */
	protected function forCreate( string $path ): JailedPath {
		return $this->jail->resolveForCreate( $path );
	}

	/**
	 * Refuses a path outside the theme and plugin folders, or one safe mode protects.
	 *
	 * @param JailedPath $path        Resolved path.
	 * @param bool       $insideFolder True for a file: it must sit inside a theme or plugin folder, not directly in the themes or plugins folder.
	 * @throws ToolFailure When refused.
	 */
	protected function allow( JailedPath $path, bool $insideFolder = true ): void {
		if ( ! $this->roots->contains( $path->relative ) ) {
			throw new ToolFailure( $path->relative . ' is outside the theme and plugin folders. This addon only works inside: ' . implode( ', ', $this->roots->roots() ) . '.' );
		}

		if ( $insideFolder && (int) $this->roots->depth( $path->relative ) < 2 ) {
			throw new ToolFailure( $path->relative . ' is directly in the themes or plugins folder. Give a path inside a theme or plugin folder, for example ' . $this->roots->roots()[0] . '/my-theme/file.css.' );
		}

		$refusal = $this->safeMode->checkPathWrite( $path->relative );
		if ( null !== $refusal ) {
			throw new ToolFailure( $refusal->reason() );
		}
	}

	/**
	 * The base name from the path the caller asked for (not from a resolved symlink target).
	 *
	 * @param string $path Requested path.
	 */
	protected function baseName( string $path ): string {
		return basename( str_replace( '\\', '/', $path ) );
	}

	/**
	 * Refuses a name the code tools may not write.
	 *
	 * @param string $name Base name.
	 * @throws ToolFailure When refused.
	 */
	protected function requireCodeName( string $name ): void {
		if ( ! FileRules::isCodeFile( $name ) ) {
			throw new ToolFailure( $name . ' is not a file type this tool writes. Allowed: ' . FileRules::codeList() . '. (Dot files such as .htaccess and other script types are never allowed; binary files go through asset_upload.)' );
		}
	}

	/**
	 * Reads a text file for editing.
	 *
	 * @param JailedPath $path Resolved path.
	 * @throws ToolFailure When it is not a readable text file within the size limit.
	 */
	protected function readText( JailedPath $path ): string {
		if ( ! is_file( $path->absolute ) ) {
			throw new ToolFailure( $path->relative . ' is not a file.' );
		}
		$size = (int) filesize( $path->absolute );
		if ( $size > self::MAX_TEXT ) {
			throw new ToolFailure( $path->relative . ' is ' . $size . ' bytes; this tool is limited to 5 MB.' );
		}
		if ( $this->isBinary( $path->absolute ) ) {
			throw new ToolFailure( $path->relative . ' is a binary file; this tool only edits text.' );
		}

		$text = @file_get_contents( $path->absolute );
		if ( false === $text ) {
			throw new ToolFailure( 'Could not read ' . $path->relative . '.' );
		}

		return $text;
	}

	/**
	 * Makes sure the parent folder exists.
	 *
	 * @param JailedPath $path   Resolved path.
	 * @param bool       $create Whether missing folders may be created.
	 * @throws ToolFailure When the folder is missing and may not be created, or cannot be created.
	 */
	protected function ensureParent( JailedPath $path, bool $create ): void {
		$parent = dirname( $path->absolute );
		if ( is_dir( $parent ) ) {
			return;
		}
		if ( ! $create ) {
			throw new ToolFailure( 'The parent folder of ' . $path->relative . ' does not exist; set create_dirs to true.' );
		}
		if ( ! @mkdir( $parent, 0755, true ) && ! is_dir( $parent ) ) {
			throw new ToolFailure( 'Could not create the parent folder of ' . $path->relative . '.' );
		}
	}

	/**
	 * Saves a backup of the file that is about to change.
	 *
	 * @param JailedPath $path Resolved path.
	 * @return string|null Backup id, or null when there was nothing to back up or it was too big.
	 */
	protected function backupOf( JailedPath $path ): ?string {
		return is_file( $path->absolute ) ? $this->backups->save( $path->relative, $path->absolute ) : null;
	}

	/**
	 * Whether a root-relative path is an active theme or plugin folder or main file.
	 *
	 * @param string $relative Root-relative path.
	 */
	protected function isActive( string $relative ): bool {
		$needle = strtolower( AllowedRoots::normalize( $relative ) );
		foreach ( (array) call_user_func( $this->activeRoots ) as $active ) {
			if ( strtolower( AllowedRoots::normalize( (string) $active ) ) === $needle ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a path is one of the folders that can never be renamed or moved.
	 *
	 * @param string $relative Root-relative path.
	 */
	protected function isNeverMove( string $relative ): bool {
		return in_array( strtolower( AllowedRoots::normalize( $relative ) ), array_map( 'strtolower', $this->neverMove ), true );
	}

	/**
	 * An optional boolean argument.
	 *
	 * @param array  $args     Arguments.
	 * @param string $key      Name.
	 * @param bool   $fallback Value when absent.
	 */
	protected function flag( array $args, string $key, bool $fallback ): bool {
		return isset( $args[ $key ] ) ? (bool) $args[ $key ] : $fallback;
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged
