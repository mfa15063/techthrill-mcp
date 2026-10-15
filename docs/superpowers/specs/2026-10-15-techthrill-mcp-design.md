# TechThrill MCP – Design Spec

**Date:** 2026-10-15
**Status:** Approved design, pending implementation plan
**Audience:** Public release (clients and the WordPress community)

## 1. Purpose

TechThrill MCP is a WordPress plugin that turns a site into an MCP (Model Context
Protocol) server. After installing it, a site administrator creates a connection
token on an admin page and pastes one command into Claude Code or ChatGPT Codex.
From then on the AI agent can run WP-CLI commands, query and modify the
database, and read and write files on the site, so the admin can describe a task
in plain language and the agent carries it out.

Success looks like:

- Install, create a token, paste one command, and `claude` can call `site_info`
  within two minutes on a typical managed host.
- The agent can complete real tasks end to end: install a plugin, fix a broken
  theme file, run a database cleanup, export a post list.
- A site owner can hand a scoped token to a contractor and see every call that
  token made.
- The plugin is safe to install by default: it does nothing until a token exists,
  and safe mode, which an admin can turn on, blocks the most destructive
  operations. (Revised 2026-10-15 at the owner's request: safe mode is off by
  default and recommended on for production; existing installs keep their
  stored setting.)

Out of scope for v1 (the OAuth login flow was added in 1.2.0; see
`docs/superpowers/specs/2026-10-15-one-click-connect-design.md`): hosted relay service, OAuth login flow, server-sent events
streaming, multisite network-wide tokens, WP-CLI emulation when PHP cannot
spawn processes, integration test suite with wp-env.

## 2. Architecture

### 2.1 Overview

The plugin is self-contained. It implements the MCP protocol itself over the
Streamable HTTP transport using the WordPress REST API. There is no runtime
dependency on Composer packages, Node, or any other plugin.

```
Claude Code / Codex  --HTTPS POST JSON-RPC-->  /wp-json/techthrill-mcp/v1/mcp
                                                       |
                                               Authenticator (bearer token)
                                                       |
                                               RateLimiter
                                                       |
                                               Mcp\Server (JSON-RPC dispatch)
                                                       |
                                     +-----------------+-----------------+
                                     |                 |                 |
                                 Tools\WpCli      Tools\Db*         Tools\File*
                                     |                 |                 |
                                CommandParser     SqlClassifier      PathJail
                                 + SafeMode        + SafeMode        + SafeMode
                                     |                 |                 |
                                     +-----------------+-----------------+
                                                       |
                                                   AuditLog
```

### 2.2 Transport

- Route: `POST /wp-json/techthrill-mcp/v1/mcp`, registered with
  `register_rest_route`, `permission_callback` delegates to `Authenticator`.
- Request body: a single JSON-RPC 2.0 request object. Batched arrays are
  rejected with error `-32600` in v1.
- Response: a single JSON object with `Content-Type: application/json`.
  Notifications (requests with no `id`) return HTTP 202 with an empty body.
- `GET` on the route returns HTTP 405 with a JSON body explaining it is an MCP
  endpoint and linking to the plugin docs.
- Headers: the plugin accepts `MCP-Protocol-Version` and echoes it back. It
  advertises protocol version `2025-06-18` and also accepts `2025-03-26`.
- Sessions: v1 is stateless. The server ignores `Mcp-Session-Id` and does not
  issue one. Every request is authenticated independently.
- REST nonce and cookie auth are bypassed for this route because the bearer
  token is the only credential. The route sets `show_in_index` to false.

### 2.3 MCP methods

| Method | Behaviour |
|---|---|
| `initialize` | Returns `protocolVersion`, `capabilities: { tools: { listChanged: false } }`, `serverInfo: { name: "techthrill-mcp", version }`, and `instructions` text that tells the agent to call `site_info` first and explains safe mode. |
| `notifications/initialized` | Accepted, no-op. |
| `ping` | Returns `{}`. |
| `tools/list` | Returns every registered tool whose required scope the token holds. Tools outside the token's scopes are not listed at all. |
| `tools/call` | Validates `name` and `arguments` against the tool's JSON schema, runs the tool, returns `{ content: [{ type: "text", text }], isError }`. |
| anything else | JSON-RPC error `-32601 Method not found`. |

Tool results are returned as a single text content block. Structured data is
JSON-encoded inside that block with `JSON_PRETTY_PRINT` for small results and
compact encoding above 50 KB. Tool failures (bad path, SQL error, blocked by
safe mode) return `isError: true` with a human-readable message that states the
reason and, when relevant, that the site admin can change the setting. Protocol
failures (unknown tool, schema mismatch) return JSON-RPC errors.

### 2.4 Code layout

```
techthrill-mcp/
  techthrill-mcp.php            Plugin header, constants, autoloader, boot
  uninstall.php                 Drops both tables and all options
  includes/
    Plugin.php                  Singleton: registers hooks, builds tool registry
    Activator.php               dbDelta for tables, default options, cron
    Autoloader.php              PSR-4 for the TechThrill\MCP namespace
    Mcp/
      Server.php                JSON-RPC dispatch, initialize/tools handlers
      RestController.php        register_rest_route, request/response glue
      JsonRpcError.php          Error codes and envelope helpers
      SchemaValidator.php       Minimal JSON-schema validation for tool args
    Auth/
      TokenManager.php          create, list, revoke, hash, lookup
      Authenticator.php         Bearer parsing, constant-time verify, scopes
      RateLimiter.php           Per-token sliding window via transients
    Tools/
      ToolInterface.php
      AbstractTool.php          Shared helpers: ok(), fail(), json()
      ToolRegistry.php          Holds tools, filters by scope
      SiteInfo.php
      WpCli.php
      DbQuery.php
      DbExecute.php
      DbSchema.php
      FileList.php
      FileRead.php
      FileSearch.php
      FileWrite.php
      FileEdit.php
      FileDelete.php
    Safety/
      PathJail.php              Resolve + confine paths under ABSPATH
      SqlClassifier.php         Statement type, WHERE detection
      CommandParser.php         Shell-style tokenizer, flag stripping, blocklist
      SafeMode.php              Central policy: is this action allowed?
      HostCapabilities.php      Detect exec, wp binary, HTTPS
    Logging/
      AuditLog.php              Insert, query, prune
    Admin/
      Menu.php                  Menu registration, tab routing, asset enqueue
      Pages/Connect.php
      Pages/Tokens.php
      Pages/Activity.php
      Pages/Settings.php
      assets/admin.css
      assets/admin.js           Copy-to-clipboard, scope presets
  languages/techthrill-mcp.pot
  tests/
    bootstrap.php               Loads autoloader + WP function stubs
    Unit/...                    PHPUnit tests for pure-logic classes
  readme.txt                    wordpress.org format
  README.md                     Developer readme
  composer.json                 dev-only: phpunit, phpcs with WPCS
  phpunit.xml.dist
  phpcs.xml.dist
  build.sh                      Produces dist/techthrill-mcp.zip
```

Namespace `TechThrill\MCP`. Text domain `techthrill-mcp`. Option prefix
`techthrill_mcp_`. Table prefix `{$wpdb->prefix}techthrill_mcp_`.

## 3. Authentication and tokens

### 3.1 Token format and storage

- Plain token: `ttm_` + 40 characters from `[A-Za-z0-9]`, generated with
  `random_bytes`.
- Stored: only `hash = hash('sha256', $token)`. The plain token is shown exactly
  once on the Connect page after creation.
- Lookup: by the first 12 characters of the token (`lookup` column, indexed),
  then `hash_equals` against the stored hash. This keeps verification
  constant-time without a full-table scan.

Table `{prefix}techthrill_mcp_tokens`:

| Column | Type | Notes |
|---|---|---|
| id | bigint unsigned PK | |
| name | varchar(100) | Admin-chosen label |
| lookup | char(12) | Indexed |
| hash | char(64) | SHA-256 hex |
| scopes | varchar(255) | Comma-separated scope list |
| user_id | bigint unsigned | Creating admin |
| created_at | datetime | UTC |
| expires_at | datetime null | UTC |
| last_used_at | datetime null | UTC |
| revoked_at | datetime null | UTC |

### 3.2 Request authentication

1. Read `Authorization: Bearer <token>`. Missing or malformed → HTTP 401 with
   JSON-RPC error `-32001 Unauthorized`.
2. Find by lookup, verify hash. Unknown → 401.
3. Reject if `revoked_at` is set or `expires_at` is in the past → 401.
4. Load the owning user. If the user no longer exists or lacks
   `manage_options`, treat the token as revoked → 401.
5. `wp_set_current_user($user_id)` so WordPress capability checks inside tools
   and WP-CLI behave as that admin.
6. Rate limit check → HTTP 429 with error `-32002 Rate limited`.
7. Update `last_used_at` at most once per minute to avoid a write per request.

Scopes: `wp-cli`, `db:read`, `db:write`, `files:read`, `files:write`.
`db:write` implies `db:read`; `files:write` implies `files:read`. `site_info`
needs no scope.

### 3.3 Rate limiting

Sliding window of 60 seconds per token id, default 120 requests, stored in a
transient keyed `techthrill_mcp_rl_{token_id}`. Configurable in Settings. A
value of 0 disables limiting.

## 4. Tools

Every tool declares `name`, `description`, `inputSchema` (JSON Schema draft
2020-12 subset: `object`, `string`, `integer`, `boolean`, `array` of strings,
`enum`, `required`, `default`), and `scope`. `SchemaValidator` enforces
required keys and primitive types; anything more elaborate is checked inside
`execute`.

### 4.1 `site_info` (no scope)

Returns: site URL, home URL, WordPress version, PHP version, MySQL version,
`ABSPATH`, `WP_CONTENT_DIR`, table prefix, multisite flag, active theme (name,
version, path), active plugins (name, version, path), locale, timezone,
`safe_mode` flag, `host_capabilities` (exec available, wp binary path or null,
HTTPS), and the scopes of the calling token. The `initialize` instructions tell
the agent to call this first.

### 4.2 `wp_cli` (scope `wp-cli`)

Arguments: `command` (string, required, without the leading `wp`),
`timeout` (integer, 5–300, default 60).

Behaviour:

1. `HostCapabilities` must report exec available and a `wp` binary. Otherwise
   fail with a message naming which is missing.
2. `CommandParser` tokenizes the string with shell-style quoting (single,
   double, backslash). No shell is involved; the argument array goes to
   `proc_open` directly.
3. Strip `--ssh`, `--http`, `--url`, `--path`, `--user`, `--skip-plugins`,
   `--skip-themes`, `--require`, `--exec` (and `=value` forms). Then append
   `--path=<ABSPATH>`, `--user=<token user id>`, `--no-color`, `--skip-packages`.
4. Blocklist (always): `shell`, `db cli`, `db query` (use `db_query` instead),
   `eval`, `eval-file`, `cli update`, `package install`, `package update`.
   Safe-mode blocklist: `db reset`, `db drop`, `db clean`, `db import`,
   `site empty`, `core download`, `core update-db`, `plugin uninstall`,
   `plugin delete`, `theme delete`, `user delete`, `post delete --force`,
   `site delete`; safe mode also refuses every `db export` (a dump written
   into the site, ABSPATH when no file is given) unless the first file
   argument is `-` (standard output) and neither `--result-file` nor `--tab`
   is present. The safe-mode list passes through
   the `techthrill_mcp_blocked_cli_commands` filter.
5. Run with `proc_open`, cwd `ABSPATH`, env limited to `PATH`, `HOME`,
   `WP_CLI_DISABLE_AUTO_CHECK_UPDATE=1`, `WP_CLI_CACHE_DIR` under uploads.
   Kill the process on timeout.
6. Output: `{ exit_code, stdout, stderr, duration_ms }`. stdout and stderr are
   each truncated to 200 KB with a `truncated` flag.

`wp` binary resolution order: Settings override → `which wp` /
`where wp.bat` → `{uploads}/techthrill-mcp/wp-cli.phar` run via a CLI PHP binary
(PHP_BINARY only when the current SAPI is CLI; otherwise a `php` found on PATH
or the `php_path` setting). The Connect page offers to download the phar from
`https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar`
and verifies its SHA-512 against the published `.sha512` file.

### 4.3 `db_query` (scope `db:read`)

Arguments: `sql` (string, required), `limit` (integer, 1–500, default 100).

- `SqlClassifier` must return `select`, `show`, `describe`, or `explain`. Any
  other statement type, or multiple statements, fails with a message pointing
  to `db_execute`.
- `{prefix}` is replaced with `$wpdb->prefix` before execution.
- If a `SELECT` has no `LIMIT`, the tool appends `LIMIT {limit+1}` to detect
  truncation. Returns `{ columns, rows, row_count, truncated }`.
- Runs `$wpdb->get_results($sql, ARRAY_A)` and surfaces `$wpdb->last_error`.

### 4.4 `db_execute` (scope `db:write`)

Arguments: `sql` (string, required), `confirm_destructive` (boolean, default
false).

- `SqlClassifier` must return `insert`, `update`, `delete`, `replace`,
  `create`, `alter`, `drop`, `truncate`, or `rename`. Single statement only.
- Safe mode on: `drop`, `truncate`, and `delete`/`update` without `WHERE` are
  refused outright. Safe mode off: the same statements require
  `confirm_destructive: true`, otherwise the tool returns an error asking the
  agent to confirm with the user and re-send with the flag.
- Returns `{ affected_rows, insert_id }`.

### 4.5 `db_schema` (scope `db:read`)

Arguments: `table` (string, optional).

- No `table`: list all tables in the database with engine and approximate row
  count from `information_schema`.
- With `table`: columns (name, type, null, key, default, extra) and indexes.

### 4.6 File tools

All paths are relative to `ABSPATH` with forward slashes; `PathJail` resolves
them. Leading `/` or `./` is tolerated and normalised.

- `file_list` (`files:read`): `path` (default `.`), `recursive` (bool, default
  false), `max_entries` (default 500). Returns entries with type, size, mtime,
  and for files the extension. Hidden entries are included.
- `file_read` (`files:read`): `path`, `offset` (line, default 1), `limit`
  (lines, default 2000). Returns `{ content, total_lines, truncated }`. Files
  over 5 MB are refused. Binary files (null byte in the first 8 KB) are refused
  with their size and mime type reported instead, plus a pointer to `file_copy`.
- `file_search` (`files:read`): `pattern` (regex, PCRE), `path` (default `.`),
  `glob` (filename filter such as `*.php`), `max_results` (default 200,
  max 1000), `case_sensitive` (default true). Returns `{ matches: [{ path,
  line, text }], truncated }`. Skips `.git`, `node_modules`, `vendor`, and
  files over 2 MB; the skip list passes through the
  `techthrill_mcp_search_ignore` filter.
- `file_write` (`files:write`): `path`, `content`, `create_dirs` (default
  true). Writes atomically via a temp file and `rename`. Preserves the existing
  file's permissions when overwriting.
- `file_edit` (`files:write`): `path`, `old_string`, `new_string`,
  `replace_all` (default false). Fails if `old_string` is not found, or is found
  more than once while `replace_all` is false. Reports how many replacements
  were made.
- `file_copy` (`files:write`): `from`, `to`, `overwrite` (default false),
  `create_dirs` (default true), `recursive` (default false). Copies byte for
  byte, so binary files such as images work (`file_read` refuses them and
  `file_write` carries only a JSON string). The source is resolved with
  `PathJail::resolve` and the destination with `resolveForCreate`; every
  destination path goes through `SafeMode::checkPathWrite`. A source of
  `wp-config.php` needs the `db:read` scope as well. Each file is limited to
  50 MB (`MAX_FILE_BYTES` = 52428800). Files are copied atomically through a
  temp file in the destination directory and `rename`, so no `.tmp` file is
  left behind. An existing destination needs `overwrite`. A directory needs
  `recursive`; a pre-flight pass validates every destination (jail, safe mode,
  file against directory conflicts, size) before anything is written, so a
  refused copy writes nothing. A directory is never copied into itself, the site
  root is never a destination, an existing destination directory is merged into
  (nothing is deleted), and symlinks inside the tree are skipped and counted in
  `skipped_links`. Returns `{ from, to, type, files, bytes, overwritten }` (plus
  `skipped_links` for a directory).
- `file_delete` (`files:write`): `path`, `recursive` (default false). Directory
  deletion requires safe mode off and `recursive: true`.

### 4.7 Protected paths

- `wp-config.php`: readable with `files:read` only if the token also has
  `db:read` (it contains DB credentials). Never writable or deletable through
  file tools.
- `wp-cli.yml`, `wp-cli.local.yml` (site root): never writable or deletable
  through file tools (they can make WP-CLI load arbitrary PHP).
- `.htaccess`, `wp-content/uploads/techthrill-mcp/`: writes refused in safe
  mode.
- The plugin's own directory: writable only with safe mode off, so an agent
  cannot accidentally disable its own connection.
- The list passes through the `techthrill_mcp_protected_paths` filter.

## 5. Safety layer

### 5.1 `SafeMode`

Single policy class with methods such as `allowsSql(string $type, bool
$hasWhere)`, `allowsCliCommand(array $argv)`, `allowsPathWrite(string
$relative)`, `allowsDirectoryDelete()`. Reads the `techthrill_mcp_safe_mode`
option (default `0`, off; changed 2026-10-15 from `1` at the owner's request; a stored value is never overwritten). Every refusal returns a `Refusal` value object with a
`reason` string that is passed to the agent verbatim, for example:

> Refused by safe mode: DELETE without a WHERE clause would affect every row in
> `wp_posts`. The site administrator can disable safe mode under TechThrill MCP →
> Settings if this is intended.

### 5.2 `PathJail`

- Input is normalised: backslashes to slashes, collapse `//`, strip `./`,
  reject any `..` segment outright before touching the filesystem.
- For existing paths: `realpath()` must start with `realpath(ABSPATH)` plus a
  separator. Symlink targets outside the root are rejected.
- For new paths (write, mkdir): the nearest existing ancestor must pass the
  same check.
- Returns the absolute path and the clean relative path used in logs.

### 5.3 `SqlClassifier`

- Strips leading comments and whitespace, splits on `;` outside string
  literals, and refuses more than one statement.
- Reads the first keyword (`WITH ... SELECT` counts as select).
- `hasWhere()` scans for a top-level `WHERE` outside string literals and
  parentheses.
- Pure PHP, no database access, fully unit-tested.

### 5.4 `CommandParser`

- Tokenizer implements POSIX-style word splitting with quotes and backslash
  escapes. Unterminated quotes are an error.
- Matches blocklist entries against the leading positional words, so
  `db cli` blocks `wp db cli --foo` but not `wp db export`.
- Flag stripping handles `--flag`, `--flag=value`, and `--flag value` forms
  for the known flag set.

### 5.5 Audit log

Table `{prefix}techthrill_mcp_log`:

| Column | Type |
|---|---|
| id | bigint unsigned PK |
| token_id | bigint unsigned, indexed |
| tool | varchar(50) |
| summary | varchar(500) – first 500 chars of a one-line argument summary |
| status | enum('ok','error','refused') |
| duration_ms | int unsigned |
| created_at | datetime, indexed |

Every `tools/call` writes one row after execution, including refusals.
`initialize` and `tools/list` are not logged. A daily cron event
`techthrill_mcp_prune_log` deletes rows older than the retention setting
(default 30 days). Argument summaries never include file contents or full SQL
beyond 500 characters.

### 5.6 Other hardening

- HTTPS: the Connect page shows a red warning when `is_ssl()` is false and the
  host is not `localhost`/`.local`/`.test`. Tokens still work over HTTP so local
  development is possible.
- All tables use `dbDelta`; `uninstall.php` drops them and deletes options.
- Admin pages check `manage_options` and verify nonces on every form.
- No `extract`, no `eval`, no dynamic includes anywhere in the plugin.
- `wp_cli` never passes through a shell; `escapeshellarg` is not needed and not
  used.

## 6. Admin UI

Menu: top-level "TechThrill MCP" with a plug icon (`dashicons-rest-api`),
capability `manage_options`. Four tabs via `?page=techthrill-mcp&tab=`.

### 6.1 Connect

- **Status card:** HTTPS (ok/warn), REST API reachable (self-request to the
  route expecting 401), PHP exec (`proc_open` available and not in
  `disable_functions`), WP-CLI (found at path / phar installed / "Download
  WP-CLI" button / "not possible on this host").
- **Create connection form:** name, scope checkboxes with three presets
  ("Full access", "Read only", "Files only"), optional expiry (never, 7 days,
  30 days, 90 days, custom date).
- **After creation:** the token shown once in a monospace box with a copy
  button, plus three tabs of ready-to-copy snippets:
  - Claude Code: `claude mcp add --transport http <slug> <url> --header "Authorization: Bearer <token>"`
  - Codex: a `[mcp_servers.<slug>]` block for `~/.codex/config.toml` with
    `url` and `bearer_token_env_var`, plus the `export` line for the env var.
  - Generic JSON: `{ "mcpServers": { "<slug>": { "type": "http", "url": ..., "headers": { "Authorization": "Bearer ..." } } } }`
  `<slug>` is derived from the site name, lowercase, hyphenated.

### 6.2 Tokens

`WP_List_Table` with name, scopes (as badges), created by, created, expires,
last used, status (active / expired / revoked), and a Revoke action with a
confirm dialog. Revoked tokens stay listed for the log's sake and can be
deleted permanently from the list.

### 6.3 Activity

`WP_List_Table` over the log, newest first, 50 per page, with filters for
token and tool and a status badge. A "Clear log" button with a nonce.

### 6.4 Settings

- Safe mode (toggle switch, default off, recommended on for production sites, with a short explanation of what it blocks). Changed 2026-10-15 at the owner's request; it was on by default in the first release. Existing installs keep their stored setting, and the Connect page shows a warning row while it is off.
- Rate limit per minute (number, default 120, 0 = off)
- WP-CLI timeout default (number, default 60)
- WP-CLI binary path override (text, optional)
- Log retention days (number, default 30)

Settings use the Settings API with a single option group
`techthrill_mcp_settings` stored as one array option.

## 7. Error handling

| Situation | Response |
|---|---|
| Invalid JSON body | HTTP 400, JSON-RPC `-32700 Parse error` |
| Not a JSON-RPC 2.0 object, or a batch | HTTP 400, `-32600 Invalid request` |
| Unknown method | HTTP 200, `-32601 Method not found` |
| Unknown tool or bad arguments | HTTP 200, `-32602 Invalid params` with details |
| Missing/invalid token | HTTP 401, `-32001 Unauthorized` |
| Rate limited | HTTP 429, `-32002 Rate limited`, `Retry-After` header |
| Tool runtime failure or refusal | HTTP 200, JSON-RPC result with `isError: true` |
| Unexpected exception | HTTP 200, `-32603 Internal error`; details only when `WP_DEBUG` is on, otherwise logged with `error_log` |

Every error message meant for the agent is written so the agent can relay it
to the user without extra context.

## 8. Testing

### 8.1 Unit tests (PHPUnit, no WordPress)

`tests/bootstrap.php` loads the autoloader and defines stubs for the handful of
WordPress functions pure classes touch (`wp_json_encode`, `__`, `esc_html`).
Classes under test and the behaviours that matter:

- `Mcp\Server`: initialize response shape, tools/list filtered by scope,
  tools/call routing, every JSON-RPC error path, notification handling.
- `Mcp\SchemaValidator`: required keys, type checks, enum, defaults applied.
- `Auth\TokenManager`: token format, hash and lookup derivation, expiry and
  revocation logic (storage behind an interface with an in-memory fake).
- `Auth\RateLimiter`: window arithmetic with an injected clock.
- `Safety\PathJail`: traversal attempts, symlink escape (using a temp dir),
  Windows and POSIX separators, new-file ancestor check.
- `Safety\SqlClassifier`: every statement type, comments, string literals
  containing `;` or `WHERE`, CTEs, multi-statement rejection.
- `Safety\CommandParser`: quoting rules, flag stripping in all three forms,
  blocklist matching on positional words only.
- `Safety\SafeMode`: each rule on and off.
- `Tools\FileEdit`: zero, one, many matches; `replace_all`.

Target: every class under `Safety/`, `Mcp/`, and `Auth/` has tests before its
implementation is considered done (TDD).

### 8.2 Manual verification checklist

Documented in `README.md` and run against a local WordPress before each
release: install and activate, create a token with each preset, connect from
Claude Code and from Codex, call `site_info`, run `wp plugin list`, run a
`SELECT` and an `UPDATE`, read and edit a theme file, trigger a safe-mode
refusal, revoke the token and confirm 401, check the Activity tab, uninstall
and confirm tables are gone.

### 8.3 Static analysis

`phpcs` with WordPress Coding Standards and `PHPCompatibilityWP` for PHP 7.4+,
run by `composer lint`. The plan includes a GitHub Actions workflow that runs
lint and unit tests on PHP 7.4, 8.1, and 8.3.

## 9. Compatibility and packaging

- PHP 7.4 or newer, WordPress 6.0 or newer, MySQL 5.7+ / MariaDB 10.3+.
- Single site fully supported. On multisite the plugin can be activated per
  site; tokens and logs are per site and `ABSPATH` is shared, which the
  readme documents.
- License GPL-2.0-or-later. `readme.txt` in wordpress.org format with
  "Tested up to" kept current.
- `build.sh` copies the plugin into `dist/techthrill-mcp/` excluding `tests/`,
  `docs/`, `composer.*`, `phpunit.xml.dist`, `phpcs.xml.dist`, `.git*`, and
  `build.sh`, then zips it.
- Version constant `TECHTHRILL_MCP_VERSION` is the single source of truth and
  is used for a `db_version` option that gates future table migrations.
- Internationalised with `__()`/`esc_html__()` and a generated `.pot`.

## 10. Open questions resolved

- **Why not SSE streaming?** Both Claude Code and Codex work with plain JSON
  responses on Streamable HTTP; streaming adds complexity on PHP hosts with
  output buffering and offers nothing for short tool calls. Revisit if long
  WP-CLI runs need progress output.
- **Why forbid `wp db query` and `wp eval`?** The dedicated `db_*` tools give
  safe-mode protection and structured results; `eval` bypasses every guard in
  this plugin. Advanced users can lift the block with the filter.
- **Why bind tokens to a user?** It gives WP-CLI and capability checks a real
  identity, makes revocation automatic when staff leave, and keeps the audit
  trail meaningful.
