# TechThrill MCP File Tools Addon - Design Spec

**Date:** 2026-11-11
**Status:** Implemented in addon version 1.1.0 (1.0.0 restricted the tools to theme and plugin folders) (`addons/techthrill-mcp-files`)
**Extends:** `docs/superpowers/specs/2026-10-15-techthrill-mcp-design.md` (section 4.6 file tools)

## 1. Purpose

On some hosts `proc_open` is disabled, so WP-CLI cannot run, and `eval` is blocked by design. The main plugin's
`file_write`, `file_edit`, `file_copy` and `media_upload` still work, but an agent has no safe way to rename or
move files, replace a binary such as a child theme's `screenshot.png` at an exact path, search and replace across
a theme, or undo a bad edit. The addon adds those, as a separate plugin so
that sites choose whether to give an agent this reach.

## 2. Requirement on the main plugin

The addon builds on classes of the main plugin (`AbstractFileTool`, `PathJail`, `SafeMode`, `ToolRegistry`), which
do not exist when the main plugin is missing, so nothing of the main plugin is touched until a pure check passes.

- `Requirements::status( installed, active, version )` returns `ok`, `missing`, `inactive` or `too_old` (minimum
  main version 1.5.1) and `Requirements::message()` says what to do.
- **Header:** `Requires Plugins: techthrill-mcp`. WordPress 6.5+ then disables the Activate link until the main
  plugin (folder `techthrill-mcp`) is active.
- **Activation guard** (every WordPress version): the activation hook re-checks, deactivates the addon and stops
  with `wp_die` and the message when the status is not `ok`.
- **Runtime guard:** the filter that registers the tools is added only when the status is `ok`; an active addon
  with an unusable main plugin shows a warning notice and registers nothing. The Plugins row replaces Activate by
  "Needs TechThrill MCP" while the status is not `ok`.
- The tools are registered through the main plugin's `techthrill_mcp_tools` filter at `plugins_loaded` priority 5
  (the main plugin boots at 10). A failure while registering is logged and never breaks the main plugin.

## 3. Tools

All tools take root-relative paths, resolve them with the main plugin's `PathJail` (symlinks cannot leave the
site), and pass the main plugin's `SafeMode::checkPathWrite`. Scope `files:write`; the listing needs `files:read`.

| Tool | What it does |
|---|---|
| `code_file_write` | Create a file or replace its whole content. `overwrite` (default true), `create_dirs` (default true), `skip_syntax_check` (default false). PHP must parse or nothing is written. |
| `code_file_edit` | Replace an exact string (`replace_all` optional). An edit that makes a previously valid PHP file invalid is refused; an already broken file may be improved step by step (a warning is returned). |
| `code_file_rename` | Rename or move a file or a whole folder anywhere on the site. Existing destination files need `overwrite` (backed up). A folder cannot move into itself. Active themes and plugins, core paths, `wp-config.php` and `.htaccess` need `confirm_active`. |
| `asset_upload` | Any binary file from base64 at an exact path (`screenshot.png` in a child theme). 20 MB; the first bytes must match the type for common image and font types unless `skip_type_check`. `overwrite` default false. |
| `code_replace_in_files` | Search and replace across the text files of any folder or the whole site (plain text or regex, case option, glob, `max_files`); binary files are skipped. `dry_run` defaults to true. A PHP result that would stop parsing is skipped; safe-mode refusals are skipped; every changed file is backed up. |
| `code_file_backups`, `code_file_restore` | List and restore saved versions (restoring backs up the current content first, so it can be undone). |

## 4. Rules

Revised in addon 1.1.0: the folder and file type limits of 1.0.0 were removed because the addon is installed only on
the sites that need it and the site owner wants every file reachable.

- **Reach:** any path inside the WordPress folder (themes, plugins, mu-plugins, uploads, core, `wp-config.php`,
  `.htaccess`, dot files, every extension). `PathJail` still rejects traversal and absolute paths and resolves
  symlinks, so nothing outside the WordPress folder can be touched.
- **Safe mode:** the main plugin's safe mode is the site administrator's own switch and is still honoured while it is
  on (protected paths, `.htaccess`, the plugin folder and `wp-config.php` are refused). With safe mode off, the addon
  also writes `wp-config.php`, which the main plugin's own file tools never do.
- **PHP syntax:** `PhpSyntax::check` uses `token_get_all( $code, TOKEN_PARSE )`, which parses without running anything
  and needs no exec. Applied to every PHP file (`.php`, `.phtml`, `.php3-8`, `.pht`), `wp-config.php` included;
  `skip_syntax_check` overrides it.
- **Rename guard:** renaming an active theme or plugin (the main plugin and this addon included), a WordPress core
  folder (`wp-admin`, `wp-includes`, `wp-content`, plugins, themes, mu-plugins, uploads), `wp-config.php`, `index.php`,
  `wp-load.php`, `wp-settings.php` or `.htaccess` needs `confirm_active`.
- **asset_upload:** any file name; common image and font types are checked against their first bytes unless
  `skip_type_check` is true. 20 MB limit.
- **Backups** (`BackupStore`): before a file is overwritten, edited, replaced by a rename or by a bulk replace, the old
  version is copied to `wp-content/uploads/techthrill-mcp-files-<random token>/<sha1 of path>/<id>.bak` (ids sort by
  time, 20 kept per file, files over 5 MB are not backed up). The folder gets `.htaccess` (deny all) and an
  `index.php`; hosts that ignore `.htaccess` should block it themselves. `code_replace_in_files` never searches this
  folder. Uninstalling the addon deletes it and the token option.

## 5. Out of scope

Deleting files (the main plugin's `file_delete` covers it), reading files (`file_read`, `file_list`,
`file_search`), creating archives, anything outside the WordPress folder.

## 6. Testing

`tests/Addon` runs against a throw-away folder tree: every tool, writing wp-config.php and .htaccess, symlinks,
safe mode, the rename guard, backups and restore, the requirement statuses and messages. The activation guard was
also exercised in a stand-alone script with stand-in WordPress functions. It has not been run on a live site.
