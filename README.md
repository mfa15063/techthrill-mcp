# TechThrill MCP

TechThrill MCP turns a WordPress site into an MCP (Model Context Protocol) server, so AI clients such as Claude Code or Codex can connect to the site and work with it through a controlled set of tools.

## What it does

The plugin adds one endpoint, `POST /wp-json/techthrill-mcp/v1/mcp`, that speaks JSON-RPC 2.0 MCP over plain HTTP. A client authenticates with `Authorization: Bearer ttm_...`. Each token has scopes that decide which tools it can use. Nothing is reachable until an administrator creates a token. Every tool call is written to an activity log.

## Requirements

- WordPress 6.0 or newer, PHP 7.4 or newer, MySQL 5.7+ or MariaDB 10.3+.
- HTTPS for any site reachable from the internet (the Connect page warns when it is missing).
- For the `wp_cli` tool: a host that allows `proc_open` and a WP-CLI binary (`wp`, `wp.exe` or `wp-cli.phar`).

## Install

1. Build the zip with `composer build` (it is written to `dist/techthrill-mcp.zip`), then upload it under Plugins > Add New > Upload Plugin. Or copy the plugin folder to `wp-content/plugins/techthrill-mcp/`.
2. Activate the plugin.
3. Open **TechThrill MCP** in the admin menu. The Connect tab shows the status of your host and lets you create a token.

## Connect Claude Code

Create a token on the Connect tab, then run the command it shows on your own machine:

```bash
claude mcp add --transport http <slug> <url> --header "Authorization: Bearer <token>"
```

For example, with `<url>` set to `https://example.com/wp-json/techthrill-mcp/v1/mcp`. Run `/mcp` inside Claude Code to confirm the server is connected.

## Connect Codex

Export the token, then add the server to `~/.codex/config.toml`:

```bash
export TECHTHRILL_MCP_<SLUG>_TOKEN=<token>
```

```toml
[mcp_servers.<slug>]
url = "<url>"
bearer_token_env_var = "TECHTHRILL_MCP_<SLUG>_TOKEN"
```

`<SLUG>` is the slug upper-cased with hyphens turned into underscores. The Connect tab also shows a generic `mcpServers` JSON block for other clients:

```json
{
    "mcpServers": {
        "<slug>": {
            "type": "http",
            "url": "<url>",
            "headers": { "Authorization": "Bearer <token>" }
        }
    }
}
```

## Tools

| Tool | Scope | What it does |
|---|---|---|
| `site_info` | none | URLs, versions, paths, active theme and plugins, safe mode, host capabilities and the token's scopes. Call it first. |
| `wp_cli` | `wp-cli` | Run one WP-CLI command (without the leading `wp`). |
| `db_query` | `db:read` | Run a read-only SQL statement. `{prefix}` expands to the table prefix. |
| `db_execute` | `db:write` | Run a write SQL statement, subject to safe mode. |
| `db_schema` | `db:read` | List tables, or describe one table's columns and indexes. |
| `file_list` | `files:read` | List files and directories under the WordPress root. |
| `file_read` | `files:read` | Read a text file with optional line offset and limit. |
| `file_search` | `files:read` | Search file contents with a regular expression and an optional filename glob. |
| `file_write` | `files:write` | Create or overwrite a file. |
| `file_edit` | `files:write` | Replace an exact string in a file. |
| `file_delete` | `files:write` | Delete a file, or a directory with `recursive`. |

## Safety

- **Safe mode** is on by default. While it is on, the plugin refuses: `DELETE` and `UPDATE` without a `WHERE` clause; `DROP` and `TRUNCATE`; the WP-CLI commands `db reset`, `db drop`, `db clean`, `db import`, `site empty`, `core download`, `core update-db`, `plugin uninstall`, `plugin delete`, `theme delete`, `user delete`, `post delete --force` and `site delete`; any `db export` that writes a dump file (only `db export -`, which writes to standard output, is allowed, and not with `--result-file` or `--tab`); deleting directories; and writes to protected paths. With safe mode off, destructive SQL still needs `confirm_destructive: true`.
- **Protected paths:** `wp-config.php`, `wp-cli.yml` and `wp-cli.local.yml` can never be written or deleted through the file tools (`wp-config.php` is readable only by a token that also has `db:read`). `.htaccess`, `wp-content/uploads/techthrill-mcp/` and the plugin's own directory are not writable while safe mode is on.
- **Scopes:** `wp-cli`, `db:read`, `db:write`, `files:read`, `files:write`. Presets on the Connect tab: Full access, Read only, Files only.
- **Token binding:** tokens are stored only as SHA-256 hashes and shown once. They can expire, be revoked, and are rate limited per token. A token acts as the administrator who created it.
- **Audit log:** every tool call, including refusals, is recorded (tool, short argument summary, ok / error / refused, duration) under TechThrill MCP > Activity and pruned after the retention period.
- Files are confined to the WordPress root by a path jail that resolves symlinks.

## Filters

`techthrill_mcp_tools` receives the `ToolRegistry`, so you can add your own tool:

```php
add_filter( 'techthrill_mcp_tools', function ( $registry ) {
	$registry->register( new My_Custom_Tool() ); // Implements TechThrill\MCP\Tools\ToolInterface.
	return $registry;
} );
```

`techthrill_mcp_blocked_cli_commands` is the list of WP-CLI command prefixes safe mode refuses:

```php
add_filter( 'techthrill_mcp_blocked_cli_commands', function ( array $blocked ) {
	$blocked[] = 'cron event run';
	return $blocked;
} );
```

`techthrill_mcp_protected_paths` is the list of relative paths that are not writable while safe mode is on:

```php
add_filter( 'techthrill_mcp_protected_paths', function ( array $paths ) {
	$paths[] = 'wp-content/mu-plugins';
	return $paths;
} );
```

`techthrill_mcp_search_ignore` is the list of directory names `file_search` skips:

```php
add_filter( 'techthrill_mcp_search_ignore', function ( array $dirs ) {
	$dirs[] = 'cache';
	return $dirs;
} );
```

## Settings

Under TechThrill MCP > Settings (stored as the single option `techthrill_mcp_settings`):

- **Safe mode:** on by default.
- **Rate limit:** requests per minute per token. Default 120, `0` turns it off.
- **WP-CLI timeout:** default seconds for `wp_cli` (default 60).
- **WP-CLI path:** optional override for the `wp` executable or `wp-cli.phar`.
- **PHP CLI binary path override:** the command-line PHP used to run `wp-cli.phar`. Leave empty to auto-detect (the running PHP when it is a CLI binary, otherwise a `php` on `PATH`). Under php-fpm, mod_php or CGI the web server's PHP cannot run a phar, so this matters on hosts without a `wp` executable.
- **Log retention:** days to keep activity rows (default 30).

On Windows the plugin never launches `wp.bat` or `wp.cmd`. It uses `wp.exe`, a `wp-cli.phar` next to `wp.bat`, or the phar downloaded from the Connect page, run through a command-line PHP (`php.exe`).

## Development

```bash
composer install
composer test   # run the PHPUnit suite
composer lint   # run PHP_CodeSniffer (WordPress standard + PHPCompatibilityWP 7.4-)
composer build  # write dist/techthrill-mcp.zip
```

Tests run without WordPress loaded; `tests/bootstrap.php` stubs the WordPress functions the plugin needs. On Windows, 14 tests are skipped (symlink and POSIX-only cases). CI runs the suite on Linux where the symlink and POSIX-only tests execute, on PHP 7.4, 8.1 and 8.3. On Linux 13 tests are skipped instead, all of them Windows-only cases (PATH resolution, trailing dot/space stripping, batch-file refusal).

CI ran on Linux (ubuntu-latest) for v1.0.0 on 2026-10-15: all three matrix jobs passed `composer lint` and `composer test` (355 tests, 13 Windows-only skips), so the 14 tests skipped on Windows have executed and passed. The composer platform is pinned to PHP 7.4.33 so the lock file installs on every matrix job; run `composer update` on any PHP version and the lock stays 7.4-compatible.

`languages/techthrill-mcp.pot` currently holds only the header. Regenerate it with WP-CLI:

```bash
wp i18n make-pot . languages/techthrill-mcp.pot --domain=techthrill-mcp
```

`phpcs.xml.dist` excludes a few sniffs on purpose (file naming for PSR-4, camelCase method names, docblock presence); each exclusion carries a reason in the file.

## Manual verification checklist

Run this once against a real WordPress site before a release. Nothing here has been verified against a live site during development; the unit tests cover the logic only. Replace `{prefix}` with your table prefix.

1. Gate: run the suite on Linux (CI, WSL or Docker) and confirm the 14 tests skipped on Windows run and pass. Do not continue until they do.
2. Copy the plugin folder into `wp-content/plugins/` (or upload the zip) and activate it. Confirm the tables `{prefix}techthrill_mcp_tokens` (id, name, lookup, hash, scopes, user_id, created_at, expires_at, last_used_at, revoked_at) and `{prefix}techthrill_mcp_log` (id, token_id, tool, summary, status, duration_ms, created_at) exist.
3. Open TechThrill MCP > Connect. The status card shows rows for HTTPS, REST reachable, PHP exec and WP-CLI. On a non-local site served over plain HTTP, confirm the HTTPS row shows the warning.
4. Create a token with each preset (Full access, Read only, Files only). Confirm the one-time token box shows once and is gone after a page reload.
5. Run the Claude Code command from the Connect tab on your machine, run `/mcp` in Claude Code, and confirm the server shows as connected. Repeat the setup with the Codex snippet and confirm Codex lists the server.
6. Call `site_info` and confirm every key: site and home URL, WordPress, PHP and MySQL versions, `ABSPATH`, `WP_CONTENT_DIR`, table prefix, multisite flag, active theme, active plugins, locale, timezone, `safe_mode`, `host_capabilities` (including `php_binary`) and the token's scopes.
7. With a Read-only token, call `tools/list` and confirm it shows no `db_execute`, `file_write`, `file_edit`, `file_delete` or `wp_cli`.
8. Call `wp_cli` with `core version`, then with `plugin list --format=json`. On a Windows host that only has `wp.bat`, confirm the batch-file refusal message, click the Connect page's Download WP-CLI button, and retry.
9. On a Linux host without `wp` on `PATH` (php-fpm or Apache), click Download WP-CLI, confirm the status card's WP-CLI row shows the phar run via a CLI php (`via /usr/bin/php wp-cli.phar` or similar, not `php-fpm`), then call `wp_cli` with `core version`.
10. Call `wp_cli` with `eval "1;"` and with `db query "SELECT 1"` and confirm both are refused as always blocked ("Refused: the command ... is not available through TechThrill MCP."). Call it with `db reset --yes` and confirm safe mode refuses it.
11. Call `db_query` with `SELECT ID, post_title FROM {prefix}posts`. Call `db_schema` with no table, then with `{prefix}posts`.
12. Call `db_execute` with an `UPDATE ... WHERE` statement (it works). Call it with `DELETE FROM {prefix}postmeta` without a `WHERE` and confirm safe mode refuses it with the text "Refused by safe mode: DELETE without a WHERE clause would affect every row in ... The site administrator can disable safe mode under TechThrill MCP → Settings if this is intended." Turn safe mode off, repeat the `DELETE` without `confirm_destructive` and confirm it asks for confirmation, then run it with `confirm_destructive: true` against a throwaway table. Turn safe mode back on.
13. Call `file_list` on `.`, `file_read` on the active theme's `style.css`, then `file_edit` that `style.css` to add a comment, read it back, and `file_edit` again to remove the comment. Call `file_search` for a known string with glob `*.css`, then `file_write` a new file under `wp-content/uploads/`, `file_edit` it, and `file_delete` it. Confirm `file_write` to `wp-config.php`, `wp-cli.yml` and the plugin's own directory are refused.
14. With a Files-only token, call `file_read` on `wp-config.php` and confirm it is refused, and `file_search` for `DB_PASSWORD` and confirm it returns no match from `wp-config.php`.
15. Send a request with `Content-Type: application/json` and an invalid JSON body and confirm a 400 with JSON-RPC code `-32700` (not WordPress's `rest_invalid_json`). Send a `GET` to the endpoint and confirm 405 with `Allow: POST`.
16. In Settings set the rate limit to 5 per minute, exceed it, and confirm a 429 with a `Retry-After` header. Set it back to 120.
17. Revoke the token on the Tokens tab and confirm Claude Code now gets a 401. Delete the revoked token.
18. Open the Activity tab and confirm it shows every call above with ok, error and refused badges, and that the token and tool filters work. Confirm Clear log works.
19. In Settings toggle safe mode off and on, change the rate limit and retention, save, reload, and confirm the values persist (including safe mode unchecked meaning off).
20. Run `wp cron event run techthrill_mcp_prune_log` (or wait a day) and confirm rows older than the retention period are pruned.
21. Deactivate and delete the plugin and confirm both tables, both options (`techthrill_mcp_settings`, `techthrill_mcp_db_version`) and every `_transient_techthrill_mcp_*` / `_transient_timeout_techthrill_mcp_*` row are gone.

## Known limitations

- On multisite, tokens and logs are per site while files on disk are shared by every site.
- `file_delete` on a Windows directory junction removes the target; junctions are not detected as links.
- `wp_cli` on Windows needs `wp.exe` or the phar; `wp.bat` and `wp.cmd` are never launched.
- WP-CLI output on Windows is buffered to a temp file for the duration of the command.
- Sites whose `WP_CONTENT_DIR` lives outside `ABSPATH` (Bedrock-style layouts) cannot reach themes or plugins through the file tools, because the path jail is the WordPress root.
- On multisite, deleting the plugin removes only the current site's tables and options.

## License

GPL-2.0-or-later.
