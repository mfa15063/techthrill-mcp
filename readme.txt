=== TechThrill MCP ===
Contributors: techthrill
Tags: mcp, claude, codex, ai, wp-cli
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turns your WordPress site into an MCP server so Claude Code or Codex can run WP-CLI, query the database and edit files, under tokens you control.

== Description ==

TechThrill MCP adds a Model Context Protocol (MCP) endpoint to your site. AI clients such as Claude Code and Codex connect to it with a bearer token and get a small, well-defined set of tools:

* `site_info` - URLs, versions, paths, active theme and plugins, and what the host can run.
* `wp_cli` - run a WP-CLI command (needs the `wp-cli` scope).
* `db_query`, `db_execute`, `db_schema` - read, change and inspect the database (`db:read`, `db:write`).
* `file_list`, `file_read`, `file_search`, `file_write`, `file_edit`, `file_copy`, `file_delete` - work with files under the WordPress root (`files:read`, `files:write`).

Nothing is exposed until an administrator connects a client. The easiest way is the Connect page's "Connect with one paste" card: paste the endpoint URL into Claude, ChatGPT (developer mode), Claude Code or Codex and approve one consent screen; no token is copied. You can also create a bearer token (shown once) with only the scopes you choose, and the Connect page shows copy-and-paste setup commands for Claude Code, Codex and generic MCP clients.

The endpoint is `POST /wp-json/techthrill-mcp/v1/mcp` with an `Authorization: Bearer ttm_...` header.

= Safety in plain words =

* No token, no access. A fresh install exposes nothing.
* Safe mode is off by default (changed 2026-10-15 at the owner's request) and is recommended on for production sites. Existing installs keep their stored setting. When on, it refuses DELETE or UPDATE without a WHERE clause, destructive WP-CLI commands (including `db import`, `plugin delete` and any `db export` except `db export -` to standard output), deleting directories, and writes to protected paths.
* File tools are jailed to the WordPress root, and `wp-config.php`, `wp-cli.yml` and `wp-cli.local.yml` can never be modified through them.
* Every tool call is written to an activity log you can read and clear.
* Requests are rate limited per token.

Developers can adjust the tool list, the blocked WP-CLI commands, the protected paths and the file search ignore list with filters (see the README on GitHub).

== Installation ==

1. Upload the `techthrill-mcp` folder to `/wp-content/plugins/`, or upload the zip under Plugins > Add New > Upload Plugin.
2. Activate the plugin through the Plugins screen.
3. Open TechThrill MCP in the admin menu. On the Connect tab, create a token with the scopes you want (Full access, Read only or Files only).
4. Copy the command for your client from the Connect tab and run it on your own machine.
5. Use HTTPS on any site that is reachable from the internet.

== Frequently Asked Questions ==

= Does it work without WP-CLI? =

Yes. The database and file tools work without it. Only `wp_cli` needs a WP-CLI binary and a host that allows running programs. The Connect page shows what your host supports. On Windows hosts that only have `wp.bat`, the plugin does not run batch files; use `wp.exe`, or the Download WP-CLI button on the Connect page. The downloaded `wp-cli.phar` is run with a command-line PHP; if none is found automatically, set "PHP CLI binary path override" under Settings.

= Is it safe? =

It is designed to be careful, but you are handing an AI client real control of your site. Create tokens with the fewest scopes needed, keep safe mode on, use HTTPS, and read the Activity tab. Treat a full-access token like an administrator password.

= How do I revoke access? =

Open TechThrill MCP > Tokens and revoke the token or the OAuth connection. The client gets a 401 on its next request and cannot refresh. Revoked tokens can then be deleted. Deactivating the plugin removes the endpoint.

= What happens on multisite? =

Activate the plugin per site. Tokens and activity logs are per site, while the files on disk are shared by every site in the network, so a file-scoped token on one site can touch files that belong to others.

= What is removed when I delete the plugin? =

Both database tables (tokens and activity log), the settings, the plugin's transients, and the scheduled cleanup event. On multisite only the current site's tables and options are removed; other sites in the network keep theirs until the plugin is deleted from each of them.

== Security ==

Nothing is exposed until you create a token. Tokens are stored only as SHA-256 hashes; the plain token is displayed once when you create it and cannot be recovered. Each token and each OAuth connection has scopes (`wp-cli`, `db:read`, `db:write`, `files:read`, `files:write`) and an optional expiry, and can be revoked at any time. Safe mode is off by default (changed 2026-10-15 at the owner's request); turn it on under TechThrill MCP > Settings and it refuses destructive operations. It is recommended on for production sites, and sites that already stored the setting keep it. Every tool call, including refusals, is recorded in the audit log under TechThrill MCP > Activity and pruned after the retention period. The Connect page shows a warning when the site is not served over HTTPS; tokens are sent in a header, so use HTTPS on any public site.

Treat the `wp-cli` scope as full access to the site: WP-CLI can read `wp-config.php` and install code. The `files:write` scope alone can also execute code, by editing theme or plugin files. Safe mode and protected paths guard against accidents, not against a malicious token holder, so only give these scopes to clients you trust as an administrator.

For its own route only, this plugin bypasses other plugins' REST API lockdowns (for example "disable REST API for logged-out users"), because MCP clients do not send WordPress cookies. A valid bearer token is still required on that route, and the rest of the REST API is left as it is.

Long WP-CLI runs may be cut short by the host's PHP `max_execution_time` or by a proxy or load balancer timeout, whatever the plugin's own WP-CLI timeout setting says.

On multisite, deleting the plugin removes only the current site's tables.

== Changelog ==

= 1.2.1 =
* Connect page: new "Connect an AI agent with a prompt" card. Copy the prompt into Claude Code, Codex or the Cursor agent; it shows you a link and a short code, you choose the permissions and approve, and the agent receives a 30-day token once. The prompt contains no token. A normal chat window that cannot run commands is told to stop and say it needs an agent or a connector.

= 1.2.0 =
* One-paste connect: the plugin is now a small OAuth 2.1 authorization server for its own MCP endpoint. Paste only the endpoint URL into Claude, ChatGPT (developer mode), Claude Code or Codex, approve one consent screen and you are connected. No token to copy.
* The consent screen pre-selects read-only permissions (`db:read`, `files:read`); you choose what to grant.
* OAuth grants appear on the Tokens page as `OAuth: <client>` and can be revoked there. Access tokens last one hour; refresh tokens are single use and last 30 days.
* Connect page: new "Connect with one paste" card with steps for Claude, ChatGPT, Claude Code and Codex, plus Cursor and VS Code install links that carry no secret. The bearer-token form, Quick connect and Custom connection moved under "Advanced: connect with a bearer token".
* Requires pretty permalinks and HTTPS for the one-paste flow; the bearer-token flow is unchanged and works everywhere.
* New database columns on the tokens table are added automatically on upgrade.

= 1.1.1 =
* Connect page: new Quick connect card. Choose Read only, Files only or Full access, press one button, and get the ready-to-paste Claude Code and Codex setup. The previous form is now called Custom connection.

= 1.1.0 =
* New `file_copy` tool: copies a file byte for byte (images and other binary files), or a directory tree with `recursive`. Atomic, jailed, checks every destination against safe mode and the protected paths before writing, and skips symlinks.
* `file_read` now points to `file_copy` when it refuses a binary file.
* Safe mode is now off by default on fresh installs and is shown as a toggle switch in Settings. It is recommended on for production sites. Existing installs keep their stored setting. The Connect page shows a warning while it is off.

= 1.0.1 =
* Pin the Composer platform to PHP 7.4 so the development dependencies install on PHP 7.4; no runtime changes.

= 1.0.0 =
* Initial release: MCP endpoint, bearer tokens with scopes, WP-CLI, database and file tools, safe mode, rate limiting, activity log and admin pages.
