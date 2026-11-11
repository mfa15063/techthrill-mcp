=== TechThrill MCP - File Tools (Addon) ===
Contributors: techthrill
Tags: mcp, claude, codex, ai, files
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Addon for TechThrill MCP. Lets an AI client create, edit, rename, replace and back up php, html, css and js files, and add images and fonts, anywhere on the site (wp-config.php and .htaccess included), even when WP-CLI and PHP eval are not available.

== Description ==

This addon needs the main TechThrill MCP plugin (version 1.5.1 or newer). It does nothing on its own: it adds tools to the MCP endpoint of the main plugin. If the main plugin is not installed or not active, the addon refuses to activate and shows a warning, and an addon that is already active switches itself off and shows a notice.

Many hosts block `proc_open`, so WP-CLI cannot run and the agent cannot upload files or change code through it. These tools work with plain PHP file functions instead.

This addon is meant to be installed only on the sites that need it, and then every file inside the WordPress folder can be changed: themes, plugins, mu-plugins, uploads, WordPress core, `wp-config.php` and `.htaccess`. Install it only for an AI client you trust, and deactivate it when the work is done.

= Tools =

* `code_file_write` - create a file or replace its whole content (any text file: php, html, css, js, json, `.htaccess`, `wp-config.php` and so on). PHP is checked for syntax errors first, so a typo cannot take the site down.
* `code_file_edit` - replace an exact string in a file. Refuses an edit that would break a PHP file.
* `code_file_rename` - rename or move a file or a whole folder. Renaming an active theme or plugin, a WordPress core folder, `wp-config.php` or `.htaccess` needs an explicit confirmation.
* `asset_upload` - add or replace a binary file at an exact path from base64 data, for example `screenshot.png` in a child theme. Any file type; common image and font types are checked against their file signature unless `skip_type_check` is set.
* `code_replace_in_files` - find and replace across any folder, or the whole site. Runs as a dry run first by default.
* `code_file_backups` and `code_file_restore` - every overwrite, edit, replace or rename-over saves the previous version (the newest 20 per file are kept) and can be brought back.

= Safety =

* No folder or file type is out of reach: dot files, `wp-config.php`, script files and core files can all be changed.
* PHP is syntax-checked before it is written, and every overwrite saves the old version first, so a typo or a bad edit can be undone with `code_file_restore`.
* Renaming an active theme or plugin (including TechThrill MCP itself), a WordPress core folder, `wp-config.php` or `.htaccess` needs `confirm_active`, so it cannot happen by accident.
* The main plugin's path jail still applies: paths cannot leave the WordPress folder, and symlinks cannot escape it. If the administrator switches safe mode on in the main plugin, safe mode's protected paths (and `wp-config.php`) are refused again.
* Tools need the same permissions as the main plugin's file tools: `files:write` (and `files:read` for listing backups).
* Backups are stored under `wp-content/uploads` in a folder with an unguessable name that is closed to web access with `.htaccess` (hosts that ignore `.htaccess` should block that folder themselves) and are deleted when the addon is deleted.

== Installation ==

1. Install and activate TechThrill MCP first. Its folder must be named `techthrill-mcp`.
2. Upload this addon under Plugins > Add New > Upload Plugin and activate it.
3. Reconnect your AI client (or call `tools/list` again) to see the new tools.

== Changelog ==

= 1.1.0 =
* No folder or file type limits any more: every file on the site can be created, edited, renamed and replaced, including `wp-config.php` and `.htaccess`. Backups and the PHP syntax check stay.

= 1.0.0 =
* First release.
