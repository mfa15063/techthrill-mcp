<?php
/**
 * Which file names the addon will create, change or move.
 *
 * @package TechThrill\MCP\Files
 */

namespace TechThrill\MCP\Files\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Text and code files go through the code tools, binary assets through asset_upload. Anything that can run as a
 * server-side script other than .php (and every dot file such as .htaccess) is refused outright.
 */
final class FileRules {

	const CODE = array( 'php', 'html', 'htm', 'css', 'scss', 'sass', 'less', 'js', 'mjs', 'json', 'txt', 'md', 'xml', 'svg', 'twig', 'po', 'pot', 'yml', 'yaml', 'csv' );

	const ASSET = array( 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'bmp', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'pdf', 'mp3', 'mp4', 'webm', 'ogg', 'wav' );

	/**
	 * Extensions a web server may run as a script (plus a few that change server behaviour).
	 */
	const EXECUTABLE = array( 'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar', 'pgif', 'shtml', 'cgi', 'pl', 'py', 'sh', 'bat', 'exe', 'asp', 'aspx', 'jsp', 'htaccess', 'htpasswd', 'ini' );

	const MAX_NAME = 200;

	/**
	 * Lower-case extension of a file name, or an empty string.
	 *
	 * @param string $name File name.
	 */
	public static function extension( string $name ): string {
		$base = strtolower( basename( str_replace( '\\', '/', $name ) ) );
		$dot  = strrpos( $base, '.' );

		return false === $dot ? '' : substr( $base, $dot + 1 );
	}

	/**
	 * Whether a base name is acceptable at all: no control characters, not a dot file, not absurdly long, and
	 * no script extension hidden in the middle (shell.php.png).
	 *
	 * @param string $name Base name.
	 */
	public static function isSafeName( string $name ): bool {
		if ( '' === $name || strlen( $name ) > self::MAX_NAME || 1 === preg_match( '/[\x00-\x1F\x7F\/\\\\]/', $name ) ) {
			return false;
		}
		if ( '.' === $name[0] ) {
			return false;
		}

		$parts = explode( '.', strtolower( $name ) );
		array_shift( $parts );
		array_pop( $parts );

		return array() === array_intersect( $parts, self::EXECUTABLE );
	}

	/**
	 * Whether the name is a text or code file the code tools may write.
	 *
	 * @param string $name Base name.
	 */
	public static function isCodeFile( string $name ): bool {
		return self::isSafeName( $name ) && in_array( self::extension( $name ), self::CODE, true );
	}

	/**
	 * Whether the name is a binary asset asset_upload may write.
	 *
	 * @param string $name Base name.
	 */
	public static function isAssetFile( string $name ): bool {
		return self::isSafeName( $name ) && in_array( self::extension( $name ), self::ASSET, true );
	}

	/**
	 * Whether a file is PHP.
	 *
	 * @param string $name File name.
	 */
	public static function isPhp( string $name ): bool {
		return 'php' === self::extension( $name );
	}

	/**
	 * Whether a directory name may be created or renamed to.
	 *
	 * @param string $name Base name.
	 */
	public static function isSafeDirName( string $name ): bool {
		return self::isSafeName( $name ) && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._ -]*$/', $name );
	}

	/**
	 * Human list of the code extensions, for error messages.
	 */
	public static function codeList(): string {
		return implode( ', ', self::CODE );
	}

	/**
	 * Human list of the asset extensions, for error messages.
	 */
	public static function assetList(): string {
		return implode( ', ', self::ASSET );
	}
}
