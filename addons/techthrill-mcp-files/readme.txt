=== TechThrill MCP - File Tools (Addon) ===
Contributors: techthrill
Tags: mcp, claude, codex, ai, files
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Addon for TechThrill MCP. Lets an AI client create, edit, rename, replace and back up php, html, css and js files, and add images and fonts, inside theme and plugin folders, even when WP-CLI and PHP eval are not available.

== Description ==

This addon needs the main TechThrill MCP plugin (version 1.5.1 or newer). It does nothing on its own: it adds tools to the MCP endpoint of the main plugin. If the main plugin is not installed or not active, the addon refuses to activate and shows a warning, and an addon that is already active switches itself off and shows a notice.

Many hosts block `proc_open`, so WP-CLI cannot run and the agent cannot upload files or change code through it. These tools work with plain PHP file functions instead.

= Tools =

* `code_file_write` - create a file or replace its whole content (php, html, css, js, json, md, xml, svg and other text files). PHP is checked for syntax errors first, so a typo cannot take the site down.
* `code_file_edit` - replace an exact string in a file. Refuses an edit that would break a PHP file.
* `code_file_rename` - rename or move a file or a whole folder. Renaming an active theme or plugin needs an explicit confirmation.
* `asset_upload` - add or replace a binary file at an exact path from base64 data, for example `screenshot.png` in a child theme. Images, fonts, pdf, audio and video; the content must match the file type.
* `code_replace_in_files` - find and replace across a theme or plugin folder. Runs as a dry run first by default.
* `code_file_backups` and `code_file_restore` - every overwrite, edit, replace or rename-over saves the previous version (the newest 20 per file are kept) and can be brought back.

= Safety =

* Everything stays inside the theme and plugin folders. `wp-config.php`, the uploads folder, the WordPress core and every other path are out of reach, and files directly in the themes or plugins folder are refused.
* Dot files such as `.htaccess` and every script type other than `.php` (`.phtml`, `.phar`, `.sh`, `shell.php.png` and so on) are refused.
* The main plugin's path jail and safe mode apply: symlinks cannot escape, and while safe mode is on the main plugin's own folder cannot be changed.
* The TechThrill MCP plugin folder and this addon's folder can never be renamed or moved through the tools.
* Tools need the same permissions as the main plugin's file tools: `files:write` (and `files:read` for listing backups).
* Backups are stored under `wp-content/uploads` in a folder with an unguessable name that is closed to web access with `.htaccess` (hosts that ignore `.htaccess` should block that folder themselves) and are deleted when the addon is deleted.

== Installation ==

1. Install and activate TechThrill MCP first. Its folder must be named `techthrill-mcp`.
2. Upload this addon under Plugins > Add New > Upload Plugin and activate it.
3. Reconnect your AI client (or call `tools/list` again) to see the new tools.

== Changelog ==

= 1.0.0 =
* First release.
