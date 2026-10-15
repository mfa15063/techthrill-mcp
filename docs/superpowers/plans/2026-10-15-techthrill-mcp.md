# TechThrill MCP Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the TechThrill MCP WordPress plugin: a self-contained MCP server over HTTP that lets Claude Code and Codex run WP-CLI, query the database, and edit files on a site through scoped bearer tokens.

**Architecture:** One plugin, no runtime dependencies. A REST route feeds JSON-RPC requests to `Mcp\Server`, which dispatches to tool classes. Pure-logic classes under `Safety/`, `Mcp/`, and `Auth/` take their dependencies (clock, storage, process runner, `$wpdb`) through constructors so PHPUnit tests run without WordPress. Thin WordPress adapters (`RestController`, `Wp*Repository`, `Admin/*`) wire the pure classes to hooks.

**Tech Stack:** PHP 7.4+ syntax (tested on PHP 8.2), WordPress 6.0+, PHPUnit 9 via Composer (dev only), PHPCS with WordPress Coding Standards.

**Spec:** `docs/superpowers/specs/2026-10-15-techthrill-mcp-design.md`

## Global Constraints

- PHP 7.4 syntax only: no enums, no readonly, no `match`, no named arguments, no union types. Typed properties and arrow functions are fine.
- Namespace `TechThrill\MCP`, PSR-4 rooted at `includes/`. Text domain `techthrill-mcp`. Option prefix `techthrill_mcp_`. Tables `{$wpdb->prefix}techthrill_mcp_tokens` and `{$wpdb->prefix}techthrill_mcp_log`.
- Version constant `TECHTHRILL_MCP_VERSION = '1.0.0'` is the single source of truth.
- No `eval`, `extract`, dynamic `include`, or shell invocation (`exec`, `shell_exec`, `system`, `passthru`); the only process spawn is `proc_open` with an argv array.
- Every string shown to a human goes through `__()`/`esc_html__()`; every tool error message is written so the agent can relay it verbatim.
- Scopes: `wp-cli`, `db:read`, `db:write`, `files:read`, `files:write`. `db:write` implies `db:read`; `files:write` implies `files:read`.
- Safe mode defaults off (changed 2026-10-15 at the owner's request; it was on in the first release) and is recommended on for production; existing installs keep their stored setting. Rate limit default 120/min. WP-CLI timeout default 180 s (changed from 60 in 1.2.3; 5–300). Log retention default 30 days.
- MCP protocol version advertised `2025-06-18`, also accepted `2025-03-26`.
- Commit after every task with a conventional message. Run `composer test` before each commit from Task 2 onward.
- No GitHub account or remote is required. The CI workflow in Task 17 is inert until the repo is pushed somewhere; the WP-CLI phar download uses a public URL with no authentication.
- Tooling on this machine: PHP 8.2 and Composer are installed; WP-CLI, Docker and Node are not. Manual checks use a WordPress install under `C:\xampp\htdocs`.

## Review Focus

1. A path like `wp-content/../wp-config.php` or `wp-content\..\..\x` must be rejected before any filesystem call, on Windows and POSIX — Task 5 tests both separators and encoded `..`.
2. SQL whose string literal contains `;` or `WHERE` (`UPDATE wp_options SET option_value = 'a;b' WHERE ...`) must be classified as a single statement with a WHERE — Task 3 tests literals.
3. A `wp_cli` command with an unterminated quote or a `--url=` flag hidden inside `--url=http://evil` must fail or be stripped, never reach `proc_open` — Task 4 tests both.
4. A token that is valid but belongs to a user who lost `manage_options` must be refused with 401, not silently granted — Task 9 tests the user check callback.
5. `file_edit` where `old_string` equals `new_string`, or `old_string` is empty, must fail rather than report success — Task 14 tests both.

---

### Task 1: Plugin scaffold and test harness

**Files:**
- Create: `techthrill-mcp.php`, `uninstall.php` (stub that only guards `WP_UNINSTALL_PLUGIN`), `includes/Autoloader.php`, `includes/Plugin.php`, `composer.json`, `phpunit.xml.dist`, `tests/bootstrap.php`, `tests/Unit/AutoloaderTest.php`, `README.md`

**Interfaces:**
- Produces: `TechThrill\MCP\Autoloader::register(string $baseDir): void`; `TechThrill\MCP\Plugin::instance(): Plugin` with `boot(): void` (empty hook registration for now); constants `TECHTHRILL_MCP_VERSION`, `TECHTHRILL_MCP_FILE`, `TECHTHRILL_MCP_DIR`, `TECHTHRILL_MCP_URL`.
- Produces for tests: `tests/bootstrap.php` defines stubs when missing: `__($t)`, `esc_html__($t)`, `esc_html($t)`, `wp_json_encode($d, $f = 0)`, `apply_filters($hook, $value)` returning `$value`, `trailingslashit($p)`, `wp_normalize_path($p)`, `sanitize_text_field($s)`, constant `ABSPATH` pointing at `tests/fixtures/wp-root/` (create with a `wp-config.php` containing one comment line and an empty `wp-content/` dir).

- [ ] **Step 1: Write `composer.json`**

```json
{
  "name": "techthrill/techthrill-mcp",
  "description": "MCP server for WordPress: connect Claude Code or Codex to a site.",
  "type": "wordpress-plugin",
  "license": "GPL-2.0-or-later",
  "require": { "php": ">=7.4" },
  "require-dev": {
    "phpunit/phpunit": "^9.6",
    "squizlabs/php_codesniffer": "^3.9",
    "wp-coding-standards/wpcs": "^3.0",
    "phpcompatibility/phpcompatibility-wp": "^2.1",
    "dealerdirect/phpcodesniffer-composer-installer": "^1.0"
  },
  "autoload-dev": { "psr-4": { "TechThrill\\MCP\\Tests\\": "tests/" } },
  "scripts": { "test": "phpunit", "lint": "phpcs" },
  "config": { "allow-plugins": { "dealerdirect/phpcodesniffer-composer-installer": true } }
}
```

- [ ] **Step 2: Write `phpunit.xml.dist`** with `bootstrap="tests/bootstrap.php"`, one test suite `Unit` at `tests/Unit`, `colors="true"`.

- [ ] **Step 3: Write the failing test `tests/Unit/AutoloaderTest.php`**

```php
public function test_autoloads_plugin_class(): void {
    $this->assertTrue(class_exists(\TechThrill\MCP\Plugin::class));
    $this->assertSame('1.0.0', TECHTHRILL_MCP_VERSION);
}
```

- [ ] **Step 4: Run `composer install` then `composer test`** — Expected: FAIL, class not found.

- [ ] **Step 5: Implement `includes/Autoloader.php`** — `register(string $baseDir)` calls `spl_autoload_register` with a closure that maps `TechThrill\MCP\Foo\Bar` to `$baseDir/includes/Foo/Bar.php` and `require`s it when the file exists.

- [ ] **Step 6: Implement `techthrill-mcp.php`** — standard plugin header (Plugin Name: TechThrill MCP, Version 1.0.0, Requires at least 6.0, Requires PHP 7.4, License GPL-2.0-or-later, Text Domain techthrill-mcp), `defined('ABSPATH') || exit`, define the four constants, require the autoloader, call `Autoloader::register(__DIR__)`, then `Plugin::instance()->boot()` on `plugins_loaded`. Register activation hook pointing at `[Activator::class, 'activate']` (class arrives in Task 10).

- [ ] **Step 7: Implement `includes/Plugin.php`** — singleton with `boot()` that is empty except a comment "wired in later tasks".

- [ ] **Step 8: Write `tests/bootstrap.php`** — require `vendor/autoload.php`, define `TECHTHRILL_MCP_VERSION` etc. by requiring `techthrill-mcp.php` after defining `ABSPATH` to the fixture dir and stubbing `add_action`, `register_activation_hook`, `plugin_dir_url`, `plugin_dir_path` as no-ops/identity, then define the remaining stubs listed in Interfaces.

- [ ] **Step 9: Run `composer test`** — Expected: PASS, 1 test.

- [ ] **Step 10: Write `README.md`** with: purpose paragraph, development setup (`composer install`, `composer test`, `composer lint`), and an empty "Manual verification checklist" heading (filled in Task 17).

- [ ] **Step 11: Commit** — `chore: scaffold plugin, autoloader and phpunit harness`

---

### Task 2: Schema validator and tool contracts

**Files:**
- Create: `includes/Tools/ToolInterface.php`, `includes/Tools/ToolResult.php`, `includes/Tools/ToolContext.php`, `includes/Tools/AbstractTool.php`, `includes/Tools/ToolRegistry.php`, `includes/Auth/Scopes.php`, `includes/Mcp/SchemaValidator.php`, `includes/Mcp/ValidationException.php`
- Test: `tests/Unit/Mcp/SchemaValidatorTest.php`, `tests/Unit/Tools/ToolRegistryTest.php`, `tests/Unit/Auth/ScopesTest.php`

**Interfaces:**
- Produces:
  - `interface ToolInterface { name(): string; description(): string; inputSchema(): array; scope(): ?string; execute(array $args, ToolContext $ctx): ToolResult; }`
  - `final class ToolContext { public function __construct(int $tokenId, int $userId, array $scopes); getters tokenId(), userId(), scopes(), hasScope(string): bool }`
  - `final class ToolResult { static ok(string $text): self; static error(string $text): self; isError(): bool; text(): string; toMcp(): array /* ['content'=>[['type'=>'text','text'=>...]], 'isError'=>bool] */ }`
  - `abstract class AbstractTool implements ToolInterface { protected function ok($data): ToolResult /* arrays json-encoded, pretty under 50 KB */; protected function fail(string $msg): ToolResult; }`
  - `final class ToolRegistry { register(ToolInterface $t): void; get(string $name): ?ToolInterface; forScopes(array $scopes): ToolInterface[] /* sorted by name, filtered via Scopes::has */ ; all(): array }`
  - `final class Scopes { const ALL = ['wp-cli','db:read','db:write','files:read','files:write']; static expand(array $scopes): array; static has(array $scopes, ?string $scope): bool /* null scope always true */; static sanitize(array $input): array /* keep known, expand */ }`
  - `final class SchemaValidator { validate(array $schema, array $args): array; }` throws `ValidationException` (message names the key). Supports `type` of `object|string|integer|boolean|array`, `required`, `enum`, `default`, `minimum`, `maximum`, `items.type` for arrays.

- [ ] **Step 1: Write failing tests**

```php
// ScopesTest
public function test_write_implies_read(): void {
    $this->assertEqualsCanonicalizing(['db:write','db:read'], Scopes::expand(['db:write']));
}
public function test_null_scope_always_allowed(): void {
    $this->assertTrue(Scopes::has([], null));
    $this->assertFalse(Scopes::has(['files:read'], 'files:write'));
}
// SchemaValidatorTest
public function test_missing_required_key_throws(): void   // expects ValidationException with message containing "sql"
public function test_defaults_applied(): void              // schema {limit: integer default 100} -> ['limit'=>100]
public function test_wrong_type_throws(): void             // limit => "ten"
public function test_enum_enforced(): void
public function test_integer_bounds(): void                // minimum 5, maximum 300, value 301 throws
public function test_unknown_keys_dropped(): void
// ToolRegistryTest (uses an anonymous class tool with scope 'db:read')
public function test_for_scopes_filters_and_sorts(): void
public function test_get_unknown_returns_null(): void
```

- [ ] **Step 2: Run `composer test`** — Expected: FAIL, classes missing.

- [ ] **Step 3: Implement the eight classes** per the Interfaces block. `AbstractTool::ok` uses `wp_json_encode` with `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES` when the compact encoding is under 51200 bytes, else compact.

- [ ] **Step 4: Run `composer test`** — Expected: PASS.

- [ ] **Step 5: Commit** — `feat: tool contracts, scopes and schema validator`

---

### Task 3: SQL classifier

**Files:**
- Create: `includes/Safety/SqlClassifier.php`, `includes/Safety/SqlStatement.php`, `includes/Safety/SqlException.php`
- Test: `tests/Unit/Safety/SqlClassifierTest.php`

**Interfaces:**
- Produces: `final class SqlClassifier { classify(string $sql): SqlStatement; }` throws `SqlException` on empty input or more than one statement.
- `final class SqlStatement { public string $type /* select|show|describe|explain|insert|update|delete|replace|create|alter|drop|truncate|rename|other */; public bool $hasWhere; public bool $hasLimit; public ?string $table /* for update/delete/insert/truncate/drop */; public string $sql /* trimmed, trailing ; removed */; isRead(): bool; isWrite(): bool }`

- [ ] **Step 1: Write failing tests** — one test per row:

| Input | type | hasWhere | hasLimit | table |
|---|---|---|---|---|
| `SELECT * FROM wp_posts` | select | false | false | null |
| `  select 1 limit 5;` | select | false | true | null |
| `-- c\nWITH x AS (SELECT 1) SELECT * FROM x` | select | false | false | null |
| `/* c */ SHOW TABLES` | show | | | |
| `DESC wp_posts` and `DESCRIBE wp_posts` | describe | | | |
| `UPDATE wp_options SET v='a;b WHERE' WHERE id=1` | update | true | | wp_options |
| `UPDATE wp_options SET v='x'` | update | false | | wp_options |
| `DELETE FROM `wp_posts` WHERE id IN (SELECT id FROM t WHERE x=1)` | delete | true | | wp_posts |
| `DELETE FROM wp_posts` | delete | false | | wp_posts |
| `TRUNCATE TABLE wp_terms` | truncate | | | wp_terms |
| `DROP TABLE IF EXISTS wp_x` | drop | | | wp_x |
| `INSERT INTO wp_x (a) VALUES ('1;2')` | insert | | | wp_x |
| `SELECT 1; SELECT 2` | throws SqlException | | | |
| `` | throws SqlException | | | |
| `GRANT ALL ON *.* TO x` | other | | | |

`isRead()` true for select/show/describe/explain; `isWrite()` true for insert/update/delete/replace/create/alter/drop/truncate/rename.

- [ ] **Step 2: Run tests** — Expected: FAIL.

- [ ] **Step 3: Implement** — algorithm: strip leading `--`/`#` line comments and `/* */` block comments; walk characters tracking single/double/backtick quoting (with backslash escapes and doubled quotes) to find top-level `;` and keywords; a `;` followed only by whitespace is a trailer, any other `;` means multi-statement. `hasWhere` requires a top-level `WHERE` token at parenthesis depth 0 outside quotes. Table is the first identifier after `UPDATE`, `DELETE FROM`, `INSERT INTO`, `TRUNCATE [TABLE]`, `DROP TABLE [IF EXISTS]`, with backticks removed.

- [ ] **Step 4: Run tests** — Expected: PASS.

- [ ] **Step 5: Commit** — `feat: sql classifier`

---

### Task 4: Command parser

**Files:**
- Create: `includes/Safety/CommandParser.php`, `includes/Safety/CommandException.php`
- Test: `tests/Unit/Safety/CommandParserTest.php`

**Interfaces:**
- Produces: `final class CommandParser { const ALWAYS_BLOCKED = ['shell','db cli','db query','eval','eval-file','cli update','package install','package update']; const STRIPPED_FLAGS = ['ssh','http','url','path','user','skip-plugins','skip-themes','require','exec']; tokenize(string $command): array; stripFlags(array $argv): array; firstBlocked(array $argv, array $blocklist): ?string; }`

- [ ] **Step 1: Write failing tests**

```php
public function test_tokenize_quotes(): void {
    $this->assertSame(['post','create','--post_title=Hello World','--porcelain'],
        $p->tokenize('post create --post_title="Hello World" --porcelain'));
    $this->assertSame(["it's"], $p->tokenize("'it'\"'\"'s'"));   // POSIX quote joining
    $this->assertSame(['a b'], $p->tokenize('a\ b'));
}
public function test_unterminated_quote_throws(): void   // "post create --title=\"x"
public function test_leading_wp_removed(): void          // "wp plugin list" -> ['plugin','list']
public function test_strip_flags_all_forms(): void {
    $in = ['plugin','list','--url=http://evil','--path','/x','--ssh=host','--skip-plugins','--format=json'];
    $this->assertSame(['plugin','list','--format=json'], $p->stripFlags($in));
}
public function test_blocklist_matches_positional_prefix_only(): void {
    $this->assertSame('db cli', $p->firstBlocked(['db','cli','--foo'], CommandParser::ALWAYS_BLOCKED));
    $this->assertNull($p->firstBlocked(['db','export'], CommandParser::ALWAYS_BLOCKED));
    $this->assertNull($p->firstBlocked(['post','list','--s=shell'], CommandParser::ALWAYS_BLOCKED));
}
public function test_blocklist_entry_with_flag(): void   // 'post delete --force' matches ['post','delete','1','--force']
```

- [ ] **Step 2: Run tests** — Expected: FAIL.

- [ ] **Step 3: Implement** — `tokenize` is a character walk with states unquoted/single/double, backslash escapes outside single quotes, words split on unquoted whitespace, leading `wp` dropped. `stripFlags` drops `--name`, `--name=value`, and `--name value` (value consumed only when the next token does not start with `--`) for names in `STRIPPED_FLAGS`. `firstBlocked` splits each blocklist entry on spaces; positional words (tokens not starting with `-`) must match the entry's positional words in order from the front, and any entry flag words must appear anywhere in argv.

- [ ] **Step 4: Run tests** — Expected: PASS.

- [ ] **Step 5: Commit** — `feat: wp-cli command parser with flag stripping and blocklist`

---

### Task 5: Path jail

**Files:**
- Create: `includes/Safety/PathJail.php`, `includes/Safety/JailedPath.php`, `includes/Safety/PathException.php`
- Test: `tests/Unit/Safety/PathJailTest.php` (uses `sys_get_temp_dir()` with a per-test root, cleaned in `tearDown`)

**Interfaces:**
- Produces: `final class PathJail { __construct(string $root); resolve(string $relative): JailedPath /* must exist */; resolveForCreate(string $relative): JailedPath /* nearest existing ancestor must be inside */; normalize(string $relative): string /* public for tests */ }`, `final class JailedPath { public string $absolute; public string $relative /* forward slashes, no leading slash, '.' for root */ }`.

- [ ] **Step 1: Write failing tests**

```php
public function test_normalizes_separators_and_dot_segments(): void {
    $this->assertSame('wp-content/themes', $jail->normalize('\\wp-content\\.\\themes\\'));
    $this->assertSame('.', $jail->normalize('/'));
}
public function test_rejects_dotdot_anywhere(): void   // 'wp-content/../wp-config.php', '..\\x', '%2e%2e/x' (after rawurldecode) each throw PathException
public function test_resolve_existing_file(): void
public function test_resolve_missing_file_throws(): void
public function test_symlink_escape_rejected(): void    // mark skipped on Windows when symlink() fails
public function test_resolve_for_create_uses_ancestor(): void   // 'wp-content/new/dir/file.php' when only wp-content exists -> absolute under root
public function test_absolute_input_rejected(): void    // 'C:/Windows/x' and '/etc/passwd' throw
```

- [ ] **Step 2: Run tests** — Expected: FAIL.

- [ ] **Step 3: Implement** — `normalize`: `rawurldecode`, backslashes to `/`, collapse repeated `/`, split, drop `.` and empty, throw on `..` or on a drive letter / leading `/` that remains after stripping a single leading slash. `resolve`: `realpath($root.'/'.$rel)` must equal root or start with `realpath($root).DIRECTORY_SEPARATOR`. `resolveForCreate`: walk up from the requested path until an existing ancestor is found, `realpath` it, apply the same check, then append the remaining segments.

- [ ] **Step 4: Run tests** — Expected: PASS (symlink test may be skipped on Windows).

- [ ] **Step 5: Commit** — `feat: path jail`

---

### Task 6: Safe mode policy

**Files:**
- Create: `includes/Safety/SafeMode.php`, `includes/Safety/Refusal.php`
- Test: `tests/Unit/Safety/SafeModeTest.php`

**Interfaces:**
- Consumes: `SqlStatement` (Task 3), `CommandParser::firstBlocked` (Task 4).
- Produces: `final class Refusal { __construct(string $reason); reason(): string }`;
  `final class SafeMode { const SAFE_MODE_BLOCKED_CLI = ['db reset','db drop','db clean','site empty','core download','core update-db','plugin uninstall','theme delete','user delete','post delete --force','site delete']; const PROTECTED_NEVER_WRITE = ['wp-config.php']; const PROTECTED_SAFE_MODE = ['.htaccess','wp-content/uploads/techthrill-mcp']; __construct(bool $enabled, string $pluginRelativeDir /* e.g. 'wp-content/plugins/techthrill-mcp' */); isEnabled(): bool; checkSql(SqlStatement $s, bool $confirmed): ?Refusal; checkCli(array $argv): ?Refusal; checkPathWrite(string $relative): ?Refusal; checkDirectoryDelete(): ?Refusal; }`
- Refusal reason copy (exact): safe-mode refusals start with `Refused by safe mode: ` and end with ` The site administrator can disable safe mode under TechThrill MCP → Settings if this is intended.`; confirmation refusals start with `Confirmation required: ` and end with ` Ask the user to confirm, then call again with confirm_destructive set to true.`; the wp-config refusal is `wp-config.php cannot be modified or deleted through TechThrill MCP.`

- [ ] **Step 1: Write failing tests**

```php
public function test_sql_drop_refused_in_safe_mode(): void          // reason starts with 'Refused by safe mode:' and contains table name
public function test_sql_delete_without_where_refused(): void
public function test_sql_delete_with_where_allowed(): void          // null
public function test_sql_drop_needs_confirmation_when_safe_mode_off(): void   // confirmed=false -> 'Confirmation required:'; confirmed=true -> null
public function test_cli_safe_mode_list_applied_only_when_enabled(): void    // ['db','reset'] refused when on, null when off
public function test_path_wp_config_never_writable(): void          // both modes
public function test_path_htaccess_and_plugin_dir_safe_mode_only(): void
public function test_directory_delete_safe_mode_only(): void
public function test_filters_applied(): void   // bootstrap stub returns value unchanged; assert the lists pass through apply_filters names 'techthrill_mcp_blocked_cli_commands' and 'techthrill_mcp_protected_paths' (redefine stub in test to record hook names)
```

- [ ] **Step 2: Run tests** — Expected: FAIL.

- [ ] **Step 3: Implement** per Interfaces. `checkPathWrite` matches when the relative path equals an entry or starts with entry + `/`; the plugin dir is treated like `PROTECTED_SAFE_MODE`.

- [ ] **Step 4: Run tests** — Expected: PASS.

- [ ] **Step 5: Commit** — `feat: safe mode policy`

---

### Task 7: JSON-RPC server

**Files:**
- Create: `includes/Mcp/Server.php`, `includes/Mcp/JsonRpcError.php`, `includes/Mcp/HandleResult.php`
- Test: `tests/Unit/Mcp/ServerTest.php` (uses two anonymous tools: `echo_tool` with no scope returning its `text` arg, `db_tool` with scope `db:read`)

**Interfaces:**
- Consumes: `ToolRegistry`, `SchemaValidator`, `ToolContext`, `ToolResult` (Task 2).
- Produces: `final class JsonRpcError { const PARSE=-32700, INVALID_REQUEST=-32600, METHOD_NOT_FOUND=-32601, INVALID_PARAMS=-32602, INTERNAL=-32603, UNAUTHORIZED=-32001, RATE_LIMITED=-32002; static envelope($id, int $code, string $message, $data=null): array }`;
  `final class HandleResult { public int $status; public ?array $body; }`;
  `final class Server { const PROTOCOL = '2025-06-18'; const ACCEPTED = ['2025-06-18','2025-03-26']; const INSTRUCTIONS = <exact text below>; __construct(ToolRegistry $registry, SchemaValidator $validator, string $version, ?callable $onToolCall = null /* fn(string $tool, array $args, ToolResult $r, int $ms) */); handle($decoded, ToolContext $ctx): HandleResult; }`
- `INSTRUCTIONS` exact copy: `This is a WordPress site served by TechThrill MCP. Call site_info first to learn the WordPress version, paths, table prefix, host capabilities and which scopes your token has. Safe mode may refuse destructive operations; when a tool reports a refusal, tell the user what was refused and why instead of retrying.`

- [ ] **Step 1: Write failing tests**

```php
public function test_non_object_body_is_invalid_request(): void     // handle('x') -> status 400, error -32600, id null
public function test_batch_rejected(): void                          // handle([[...]]) -> 400, -32600
public function test_initialize_shape(): void                        // result.protocolVersion '2025-06-18', capabilities.tools.listChanged false, serverInfo.name 'techthrill-mcp', instructions === Server::INSTRUCTIONS
public function test_initialize_echoes_accepted_older_version(): void // params.protocolVersion '2025-03-26' -> result.protocolVersion '2025-03-26'; unknown version -> '2025-06-18'
public function test_notification_returns_202_no_body(): void       // method 'notifications/initialized', no id
public function test_ping(): void                                    // result {}
public function test_tools_list_filtered_by_scope(): void            // ctx scopes [] -> only echo_tool; ['db:read'] -> both; each entry has name, description, inputSchema
public function test_tools_call_routes_and_wraps(): void             // result.content[0].text === 'hi', isError false
public function test_tools_call_unknown_tool(): void                 // -32602
public function test_tools_call_scope_denied(): void                 // db_tool with [] scopes -> -32602 message contains 'scope'
public function test_tools_call_validation_error(): void             // missing 'text' -> -32602
public function test_tool_exception_becomes_internal_error(): void   // tool throws RuntimeException -> -32603, message 'Internal error' (data only when WP_DEBUG defined true)
public function test_unknown_method(): void                          // -32601
public function test_on_tool_call_invoked(): void                    // callback receives name, args, result, duration >= 0
```

- [ ] **Step 2: Run tests** — Expected: FAIL.

- [ ] **Step 3: Implement** — status 400 for parse/invalid request, 202 for notifications, 200 otherwise. `tools/call` measures duration with `microtime(true)` and invokes `$onToolCall` for both results and refusals (not for protocol errors).

- [ ] **Step 4: Run tests** — Expected: PASS.

- [ ] **Step 5: Commit** — `feat: json-rpc mcp server`

---

### Task 8: Token manager and rate limiter

**Files:**
- Create: `includes/Auth/TokenRepository.php` (interface), `includes/Auth/TokenManager.php`, `includes/Auth/RateLimitStore.php` (interface), `includes/Auth/RateLimiter.php`
- Test: `tests/Unit/Auth/TokenManagerTest.php`, `tests/Unit/Auth/RateLimiterTest.php`, `tests/Support/InMemoryTokenRepository.php`, `tests/Support/InMemoryRateLimitStore.php`

**Interfaces:**
- Produces:
  - `interface TokenRepository { insert(array $row): int; findByLookup(string $lookup): array /* rows */; find(int $id): ?array; update(int $id, array $fields): void; all(): array; delete(int $id): void; }` Row keys match the spec table columns; datetimes are `Y-m-d H:i:s` UTC strings.
  - `final class TokenManager { const PREFIX='ttm_'; const LENGTH=40; __construct(TokenRepository $repo, ?callable $clock=null /* returns int unix time */); create(string $name, array $scopes, int $userId, ?string $expiresAt): array /* ['id'=>int,'token'=>string] */; verify(string $plain): ?array /* row or null if unknown/revoked/expired */; touch(int $id): void /* sets last_used_at if older than 60 s */; revoke(int $id): void; static hash(string $plain): string; static lookup(string $plain): string /* first 12 chars */; static isWellFormed(string $plain): bool; }`
  - `interface RateLimitStore { get(string $key): array; set(string $key, array $value, int $ttl): void; }`
  - `final class RateLimiter { __construct(int $limitPerMinute, RateLimitStore $store, ?callable $clock=null); allow(int $tokenId): bool; retryAfter(int $tokenId): int /* seconds, >=1 */; }`

- [ ] **Step 1: Write failing tests**

```php
// TokenManagerTest
public function test_create_returns_well_formed_token_and_stores_hash_only(): void
    // token matches /^ttm_[A-Za-z0-9]{40}$/; stored row has hash === TokenManager::hash($token), lookup === substr($token,0,12), no plain token anywhere in the row
public function test_verify_round_trip(): void
public function test_verify_rejects_unknown_revoked_expired_and_malformed(): void   // four assertions; expiry uses clock = expires_at + 1
public function test_scopes_are_sanitized_and_expanded_on_create(): void          // ['db:write','bogus'] -> 'db:write,db:read'
public function test_touch_only_after_sixty_seconds(): void
// RateLimiterTest
public function test_allows_up_to_limit_then_blocks(): void          // limit 3: allow x3 true, 4th false
public function test_window_slides(): void                           // advance clock 61 s -> allowed again
public function test_zero_limit_disables(): void
public function test_retry_after_positive(): void
```

- [ ] **Step 2: Run tests** — Expected: FAIL.

- [ ] **Step 3: Implement** — `create` generates 40 chars by mapping `random_bytes` through the 62-char alphabet (rejection sampling to avoid bias). `verify` loops `findByLookup` rows and uses `hash_equals`. `RateLimiter` stores an array of unix timestamps under key `techthrill_mcp_rl_{id}`, prunes entries older than 60 s, compares count to limit, TTL 60.

- [ ] **Step 4: Run tests** — Expected: PASS.

- [ ] **Step 5: Commit** — `feat: token manager and rate limiter`

---

### Task 9: Authenticator

**Files:**
- Create: `includes/Auth/Authenticator.php`, `includes/Auth/AuthResult.php`
- Test: `tests/Unit/Auth/AuthenticatorTest.php`

**Interfaces:**
- Consumes: `TokenManager`, `RateLimiter`, `Scopes`, `JsonRpcError` codes.
- Produces: `final class AuthResult { static ok(array $row, array $scopes): self; static fail(int $status, int $code, string $message): self; isOk(): bool; row(): array; scopes(): array; status(): int; code(): int; message(): string; }`;
  `final class Authenticator { __construct(TokenManager $tokens, RateLimiter $limiter, callable $userIsAdmin /* fn(int $userId): bool */); authenticate(?string $authorizationHeader): AuthResult; }`

- [ ] **Step 1: Write failing tests**

```php
public function test_missing_header_401(): void            // status 401, code -32001
public function test_basic_scheme_401(): void              // 'Basic abc'
public function test_unknown_token_401(): void
public function test_demoted_user_401(): void              // userIsAdmin returns false -> 401 with message containing 'no longer'
public function test_rate_limited_429(): void              // limiter limit 1, second call -> status 429, code -32002
public function test_success_returns_expanded_scopes_and_touches(): void   // row scopes 'files:write' -> scopes contain files:read; repo row last_used_at set
```

- [ ] **Step 2: Run tests** — Expected: FAIL.

- [ ] **Step 3: Implement** — order exactly as spec §3.2: header → verify → user check → rate limit → touch. Bearer match is case-insensitive on the scheme, trims whitespace.

- [ ] **Step 4: Run tests** — Expected: PASS.

- [ ] **Step 5: Commit** — `feat: bearer authenticator`

---

### Task 10: Database schema, settings, repositories and audit log

**Files:**
- Create: `includes/Activator.php`, `includes/Settings.php`, `includes/Auth/WpTokenRepository.php`, `includes/Auth/TransientRateLimitStore.php`, `includes/Logging/AuditLog.php`, `includes/Logging/ArgumentSummarizer.php`
- Modify: `uninstall.php` (drop both tables, delete options `techthrill_mcp_settings`, `techthrill_mcp_db_version`, clear cron `techthrill_mcp_prune_log`)
- Test: `tests/Unit/Logging/ArgumentSummarizerTest.php`, `tests/Unit/SettingsTest.php`

**Interfaces:**
- Produces:
  - `final class Activator { static activate(): void /* dbDelta both tables per spec §3.1 and §5.5, set db_version option, schedule daily 'techthrill_mcp_prune_log' */; static maybeUpgrade(): void /* on plugins_loaded, re-run when db_version < TECHTHRILL_MCP_VERSION */ }`
  - `final class Settings { const OPTION='techthrill_mcp_settings'; const DEFAULTS=['safe_mode'=>1,'rate_limit'=>120,'cli_timeout'=>60,'cli_path'=>'','log_retention_days'=>30]; __construct(?array $override=null /* tests */); get(string $key); all(): array; static sanitize(array $input): array /* ints clamped: rate_limit 0–10000, cli_timeout 5–300, retention 1–365; cli_path sanitize_text_field; safe_mode 0|1 */ }`
  - `final class WpTokenRepository implements TokenRepository` using `$wpdb` with `prepare`.
  - `final class TransientRateLimitStore implements RateLimitStore` via `get_transient`/`set_transient`.
  - `final class AuditLog { __construct(\wpdb $db); record(int $tokenId, string $tool, string $summary, string $status /* ok|error|refused */, int $durationMs): void; query(array $filters /* token_id?, tool? */, int $page, int $perPage): array /* ['rows'=>[], 'total'=>int] */; prune(int $days): int; clear(): void; distinctTools(): array }`
  - `final class ArgumentSummarizer { static summarize(array $args): string /* key=value pairs joined by ' ', values truncated to 80 chars each with '…', 'content' and 'new_string' values replaced by '<N chars>', whole string cut at 500 */ }`
  - Status rule for `AuditLog::record` callers: `refused` when the result text starts with `Refused by safe mode:` or `Confirmation required:`; `error` for any other `isError`; else `ok`. Put this in `AuditLog::statusFor(ToolResult $r): string`.

- [ ] **Step 1: Write failing tests**

```php
// ArgumentSummarizerTest
public function test_truncates_and_masks_content(): void   // ['path'=>'a.php','content'=>str_repeat('x',1000)] -> 'path=a.php content=<1000 chars>'
public function test_hard_cap_500(): void
// SettingsTest
public function test_defaults_and_override(): void
public function test_sanitize_clamps(): void              // rate_limit -5 -> 0, cli_timeout 1000 -> 300, safe_mode 'yes' -> 1
```

- [ ] **Step 2: Run tests** — Expected: FAIL.

- [ ] **Step 3: Implement all files.** `AuditLog::statusFor` is static and pure; test it in `ArgumentSummarizerTest` too (three cases).

- [ ] **Step 4: Run tests** — Expected: PASS.

- [ ] **Step 5: Manual check** — copy the plugin into a local WordPress (`C:\xampp\htdocs\<site>\wp-content\plugins\techthrill-mcp` or a symlink), activate, and confirm both tables exist in phpMyAdmin with the spec's columns. Deactivate and delete, confirm tables are gone.

- [ ] **Step 6: Commit** — `feat: tables, settings, repositories, audit log and uninstall`

---

### Task 11: REST controller and plugin wiring

**Files:**
- Create: `includes/Mcp/RestController.php`
- Modify: `includes/Plugin.php` (build the object graph, register hooks)

**Interfaces:**
- Consumes: everything from Tasks 2–10.
- Produces: `final class RestController { const NAMESPACE='techthrill-mcp/v1'; const ROUTE='/mcp'; __construct(Authenticator $auth, Server $server, AuditLog $log); register(): void /* on rest_api_init */; handle(\WP_REST_Request $r): \WP_REST_Response; }`; `Plugin::registry(): ToolRegistry`, `Plugin::settings(): Settings`, `Plugin::safeMode(): SafeMode`, `Plugin::pathJail(): PathJail` for later tasks; `Plugin::endpointUrl(): string` returns `rest_url('techthrill-mcp/v1/mcp')`.

- [ ] **Step 1: Implement `RestController`** — `register_rest_route` with methods `POST` and `GET`, `permission_callback` `__return_true` (auth happens inside so the 401 body is JSON-RPC shaped), `show_in_index false`. `handle`: GET → 405 with `{"error":"This is an MCP endpoint. Connect with an MCP client using a bearer token. See https://techthrill.com/mcp"}`. POST → run `Authenticator`; on failure respond with its status and `JsonRpcError::envelope(null, code, message)`, adding `Retry-After` on 429. On success: `json_decode($r->get_body(), true)`; `null` with `json_last_error()` → 400 parse error. Build `ToolContext`, call `Server::handle`, map `HandleResult` to `WP_REST_Response` with header `MCP-Protocol-Version` echoed from the request when present.
- Add filter `rest_authentication_errors` returning `true` only for this route so cookie/nonce checks do not interfere.

- [ ] **Step 2: Wire `Plugin::boot()`** — instantiate `Settings`, `SafeMode` (enabled from settings, plugin dir relative = `str_replace(wp_normalize_path(ABSPATH), '', wp_normalize_path(TECHTHRILL_MCP_DIR))` trimmed of slashes), `PathJail(ABSPATH)`, `WpTokenRepository`, `TokenManager`, `TransientRateLimitStore`, `RateLimiter`, `Authenticator` (user check: `user_can($id, 'manage_options')`), `ToolRegistry` passed through `apply_filters('techthrill_mcp_tools', $registry)`, `AuditLog`, `Server` with `$onToolCall` writing to the log using `ArgumentSummarizer` and `AuditLog::statusFor`, `RestController::register` on `rest_api_init`, `Activator::maybeUpgrade` on `plugins_loaded`, and the cron hook `techthrill_mcp_prune_log` → `AuditLog::prune(settings log_retention_days)`.

- [ ] **Step 3: Manual verification** — with the plugin active and a token row inserted by hand (use `php -r` to print `hash('sha256', 'ttm_'.str_repeat('a',40))` and insert a row with scopes `db:read`), run:

```bash
curl -s -X POST http://localhost/<site>/wp-json/techthrill-mcp/v1/mcp -H "Authorization: Bearer ttm_aaaa...40" -H "Content-Type: application/json" -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}'
```
Expected: 200 with `serverInfo.name` `techthrill-mcp`. Without the header: 401 with code -32001. `tools/list`: empty `tools` array (no tools yet). GET: 405.

- [ ] **Step 4: Run `composer test`** — Expected: PASS.

- [ ] **Step 5: Commit** — `feat: rest controller and plugin wiring`

---

### Task 12: Host capabilities and `site_info`

**Files:**
- Create: `includes/Safety/HostCapabilities.php`, `includes/Tools/SiteInfo.php`
- Modify: `includes/Plugin.php` (register the tool)
- Test: `tests/Unit/Safety/HostCapabilitiesTest.php`

**Interfaces:**
- Produces: `final class HostCapabilities { __construct(Settings $settings, string $uploadsDir); execAvailable(): bool /* proc_open exists and not in disable_functions */; wpBinary(): ?array /* argv prefix: [path] or [PHP_BINARY, phar] or null */; pharPath(): string /* {uploads}/techthrill-mcp/wp-cli.phar */; isHttps(): bool /* is_ssl() or host in localhost/.local/.test treated as fine via isLocalHost() */; isLocalHost(): bool; toArray(): array /* exec_available, wp_binary (string|null), https, local_host */ }` with resolution order: settings `cli_path` → `wp` found via `PATH` scan for `wp`, `wp.bat`, `wp.cmd` → phar if file exists.
- `final class SiteInfo extends AbstractTool` name `site_info`, scope null, returns the spec §4.1 keys exactly: `site_url, home_url, wp_version, php_version, mysql_version, abspath, content_dir, table_prefix, multisite, theme{name,version,path}, plugins[{name,version,path}], locale, timezone, safe_mode, host_capabilities, token_scopes`.

- [ ] **Step 1: Write failing tests** (for the pure parts of `HostCapabilities`: inject `$pathEnv` and `$disableFunctions` strings through optional constructor args)

```php
public function test_exec_unavailable_when_disabled(): void   // disable_functions 'exec,proc_open' -> false
public function test_settings_path_wins(): void
public function test_phar_fallback(): void                     // temp uploads dir with empty wp-cli.phar -> [PHP_BINARY, pharPath]
public function test_local_host_detection(): void              // 'example.test', 'localhost', 'site.local' true; 'example.com' false
```

- [ ] **Step 2: Run tests** — Expected: FAIL.

- [ ] **Step 3: Implement both classes and register `SiteInfo` in `Plugin::boot()`.** `SiteInfo` reads `get_plugins()` (load `wp-admin/includes/plugin.php` if needed) filtered by `is_plugin_active`.

- [ ] **Step 4: Run tests; manual: `tools/call site_info` via curl** — Expected: PASS; JSON with all keys, `host_capabilities.exec_available` true on XAMPP.

- [ ] **Step 5: Commit** — `feat: host capabilities and site_info tool`

---

### Task 13: Database tools

**Files:**
- Create: `includes/Tools/DbQuery.php`, `includes/Tools/DbExecute.php`, `includes/Tools/DbSchema.php`
- Modify: `includes/Plugin.php` (register)
- Test: `tests/Unit/Tools/DbToolsTest.php`, `tests/Support/FakeWpdb.php` (`public $prefix='wp_'; public $last_error=''; public $rows_affected=0; public $insert_id=0; public array $queries=[]; $results` queue; methods `get_results($sql,$output)`, `query($sql)`, `get_var`, `prepare` (sprintf-style), `esc_like`)

**Interfaces:**
- Consumes: `SqlClassifier`, `SafeMode`, `AbstractTool`.
- Produces: tools `db_query` (scope `db:read`; args `sql` required, `limit` integer 1–500 default 100), `db_execute` (scope `db:write`; args `sql` required, `confirm_destructive` boolean default false), `db_schema` (scope `db:read`; arg `table` string optional). Constructors: `__construct(\wpdb $db, SqlClassifier $classifier)` and for `DbExecute` additionally `SafeMode $safeMode`.
- Error copy: non-read statement in `db_query` → `db_query only runs SELECT, SHOW, DESCRIBE and EXPLAIN. Use db_execute for INSERT, UPDATE, DELETE and schema changes.`; non-write statement in `db_execute` → `db_execute only runs INSERT, UPDATE, DELETE, REPLACE, CREATE, ALTER, DROP, TRUNCATE and RENAME. Use db_query to read data.`; `$wpdb->last_error` non-empty → `Database error: {last_error}`.

- [ ] **Step 1: Write failing tests**

```php
public function test_query_rejects_write_statement(): void
public function test_query_replaces_prefix_and_appends_limit(): void   // 'SELECT * FROM {prefix}posts' limit 2 -> last query 'SELECT * FROM wp_posts LIMIT 3'; 3 fake rows -> row_count 2, truncated true
public function test_query_keeps_existing_limit(): void
public function test_query_surfaces_db_error(): void
public function test_execute_rejects_read_statement(): void
public function test_execute_refused_by_safe_mode(): void             // DELETE without WHERE, safe mode on -> isError, text starts 'Refused by safe mode:'
public function test_execute_returns_affected_rows(): void             // {affected_rows: 3, insert_id: 0}
public function test_schema_lists_tables_and_describes(): void         // no table -> information_schema query; with table -> SHOW FULL COLUMNS and SHOW INDEX
```

- [ ] **Step 2: Run tests** — Expected: FAIL.

- [ ] **Step 3: Implement the three tools and register them.** `db_schema` with a table name validates `/^[A-Za-z0-9_]+$/` and quotes with backticks.

- [ ] **Step 4: Run tests; manual: run `SELECT ID, post_title FROM {prefix}posts` and an `UPDATE ... WHERE` via curl** — Expected: PASS; real rows returned.

- [ ] **Step 5: Commit** — `feat: database tools`

---

### Task 14: File tools

**Files:**
- Create: `includes/Tools/FileList.php`, `includes/Tools/FileRead.php`, `includes/Tools/FileSearch.php`, `includes/Tools/FileWrite.php`, `includes/Tools/FileEdit.php`, `includes/Tools/FileDelete.php`
- Modify: `includes/Plugin.php` (register)
- Test: `tests/Unit/Tools/FileToolsTest.php` (temp root per test with `wp-config.php`, `wp-content/themes/t/style.css` containing `a\nb\nc\n`, a 6 MB file, a file with a null byte)

**Interfaces:**
- Consumes: `PathJail`, `SafeMode`, `ToolContext::hasScope`.
- Produces: all six tools with constructor `__construct(PathJail $jail, SafeMode $safeMode)`; arguments and limits exactly as spec §4.6; `file_search` default ignore list `['.git','node_modules','vendor']` passed through `apply_filters('techthrill_mcp_search_ignore', ...)`.
- Error copy: wp-config read without `db:read` → `Reading wp-config.php requires the db:read scope because it contains database credentials.`; binary → `{path} is a binary file ({size} bytes, {mime}); file_read only returns text files.`; over 5 MB → `{path} is {size} bytes; file_read is limited to 5 MB.`; `file_edit` zero matches → `old_string was not found in {path}.`; multiple → `old_string matches {n} places in {path}; make it unique or set replace_all to true.`; identical strings → `old_string and new_string are identical.`; empty old → `old_string must not be empty.`

- [ ] **Step 1: Write failing tests**

```php
public function test_list_root_and_recursive_limit(): void
public function test_read_with_offset_and_limit_reports_total(): void   // offset 2 limit 1 -> content 'b', total_lines 3, truncated true
public function test_read_wp_config_requires_db_read_scope(): void
public function test_read_binary_and_oversize_refused(): void
public function test_search_regex_with_glob_and_ignore(): void          // pattern 'b', glob '*.css' -> one match {path, line 2, text 'b'}; file under node_modules ignored
public function test_write_atomic_and_creates_dirs(): void              // new nested path; content round-trips; no leftover .tmp files
public function test_write_refuses_wp_config_and_plugin_dir_in_safe_mode(): void
public function test_edit_single_multiple_replace_all_identical_empty(): void   // five assertions per the error copy
public function test_delete_file_and_dir_rules(): void                   // dir without recursive -> error; dir with recursive and safe mode on -> refusal; safe mode off + recursive -> removed
```

- [ ] **Step 2: Run tests** — Expected: FAIL.

- [ ] **Step 3: Implement** — `file_search` uses `RecursiveDirectoryIterator` with `SKIP_DOTS`, `fnmatch` for glob, `preg_match` per line with `@` guarded and an explicit invalid-regex error; `file_write` writes to `{dir}/.{name}.{uniqid}.tmp` then `rename`, copying `fileperms` of an existing target.

- [ ] **Step 4: Run tests; manual: read and edit the active theme's `style.css` via curl, confirm change on disk** — Expected: PASS.

- [ ] **Step 5: Commit** — `feat: file tools`

---

### Task 15: WP-CLI tool

**Files:**
- Create: `includes/Tools/ProcessRunner.php` (interface), `includes/Tools/ProcessResult.php`, `includes/Tools/ProcOpenRunner.php`, `includes/Tools/WpCli.php`
- Modify: `includes/Plugin.php` (register)
- Test: `tests/Unit/Tools/WpCliTest.php` with `tests/Support/FakeProcessRunner.php` (records argv/cwd/env/timeout, returns a preset result)

**Interfaces:**
- Consumes: `CommandParser`, `SafeMode`, `HostCapabilities`, `Settings`.
- Produces: `interface ProcessRunner { run(array $argv, string $cwd, array $env, int $timeoutSeconds): ProcessResult; }`; `final class ProcessResult { public int $exitCode; public string $stdout; public string $stderr; public int $durationMs; public bool $timedOut; }`; `final class ProcOpenRunner implements ProcessRunner` (non-blocking pipes, polls every 50 ms, `proc_terminate` on timeout, each stream capped at 204800 bytes); tool `wp_cli` (scope `wp-cli`; args `command` required, `timeout` integer 5–300 default from settings) with `__construct(CommandParser $parser, SafeMode $safeMode, HostCapabilities $host, ProcessRunner $runner, Settings $settings, string $cacheDir)`.
- Output keys: `exit_code, stdout, stderr, duration_ms, timed_out, stdout_truncated, stderr_truncated`.
- Error copy: exec disabled → `This host does not allow PHP to start processes (proc_open is disabled), so WP-CLI cannot run. Database and file tools still work.`; no binary → `WP-CLI was not found on this host. The site administrator can install it from TechThrill MCP → Connect.`; blocked → `The command "{entry}" is not available through TechThrill MCP.`

- [ ] **Step 1: Write failing tests**

```php
public function test_argv_assembled_with_forced_flags(): void   // 'plugin list --url=x --format=json' with binary ['/usr/bin/wp'] -> argv ['/usr/bin/wp','plugin','list','--format=json','--path=<root>','--user=7','--no-color','--skip-packages']; env has only PATH, HOME, WP_CLI_DISABLE_AUTO_CHECK_UPDATE=1, WP_CLI_CACHE_DIR=<cacheDir>
public function test_always_blocked(): void                      // 'eval "1"' -> error text contains 'eval'
public function test_safe_mode_blocked(): void                   // 'db reset --yes' safe mode on -> 'Refused by safe mode:'
public function test_exec_unavailable_message(): void
public function test_no_binary_message(): void
public function test_output_shape_and_timeout_flag(): void
public function test_unterminated_quote_is_tool_error_not_exception(): void
```

- [ ] **Step 2: Run tests** — Expected: FAIL.

- [ ] **Step 3: Implement all four files; register in `Plugin::boot()` with `cacheDir = {uploads}/techthrill-mcp/cache`.**

- [ ] **Step 4: Run tests; manual: download wp-cli.phar by hand into the uploads folder, call `wp_cli` with `core version` via curl** — Expected: PASS; exit_code 0, stdout has the version.

- [ ] **Step 5: Commit** — `feat: wp-cli tool`

---

### Task 16: Admin UI

**Files:**
- Create: `includes/Admin/Menu.php`, `includes/Admin/Snippets.php`, `includes/Admin/Pages/Connect.php`, `includes/Admin/Pages/Tokens.php`, `includes/Admin/TokensListTable.php`, `includes/Admin/Pages/Activity.php`, `includes/Admin/ActivityListTable.php`, `includes/Admin/Pages/Settings.php`, `includes/Admin/PharInstaller.php`, `includes/Admin/assets/admin.css`, `includes/Admin/assets/admin.js`
- Modify: `includes/Plugin.php` (boot admin when `is_admin()`)
- Test: `tests/Unit/Admin/SnippetsTest.php`

**Interfaces:**
- Consumes: `TokenManager`, `TokenRepository::all`, `AuditLog::query/clear/distinctTools`, `Settings`, `HostCapabilities`, `Plugin::endpointUrl`.
- Produces: `final class Snippets { static slug(string $siteName): string /* lowercase, [a-z0-9-], max 30, fallback 'wordpress' */; static claudeCode(string $slug, string $url, string $token): string; static codex(string $slug, string $url, string $token): string; static json(string $slug, string $url, string $token): string; }` producing exactly:
  - Claude: `claude mcp add --transport http {slug} {url} --header "Authorization: Bearer {token}"`
  - Codex: `export {ENV}={token}` newline newline `[mcp_servers.{slug}]` newline `url = "{url}"` newline `bearer_token_env_var = "{ENV}"` where `ENV` is `TECHTHRILL_MCP_` + slug uppercased with `-` → `_` + `_TOKEN`
  - JSON: pretty `{"mcpServers":{"{slug}":{"type":"http","url":"{url}","headers":{"Authorization":"Bearer {token}"}}}}`
- `final class PharInstaller { download(): true|\WP_Error /* wp_remote_get to the phar URL and its .sha512, verify hash_file('sha512'), write to HostCapabilities::pharPath() */ }`
- Menu: `add_menu_page('TechThrill MCP', 'TechThrill MCP', 'manage_options', 'techthrill-mcp', [Menu,'render'], 'dashicons-rest-api', 81)`; tabs `connect|tokens|activity|settings`; assets enqueued only on this page.

- [ ] **Step 1: Write failing tests for `Snippets`** — one test per method asserting the exact strings above with slug `my-site`, url `https://example.com/wp-json/techthrill-mcp/v1/mcp`, token `ttm_x`; plus `slug('My Site! 2026')` → `my-site-2026`, `slug('')` → `wordpress`.

- [ ] **Step 2: Run tests** — Expected: FAIL.

- [ ] **Step 3: Implement `Snippets`, run tests** — Expected: PASS.

- [ ] **Step 4: Implement Connect page** — status card (four rows per spec §6.1; REST reachability via `wp_remote_post` to the endpoint expecting 401; a "Download WP-CLI" button posting to `admin-post.php?action=techthrill_mcp_install_phar` with nonce, shown only when exec works and no binary found; HTTPS warning when `!isHttps() && !isLocalHost()`). Create form: name (required), scope checkboxes with preset buttons "Full access" (all), "Read only" (`db:read`,`files:read`), "Files only" (`files:read`,`files:write`), expiry select `never|7|30|90|custom` plus date input. Handle POST with nonce `techthrill_mcp_create_token`, create via `TokenManager`, store the plain token in a transient keyed by user id for 60 s, redirect, and render the one-time token box plus the three snippet tabs on the next load, then delete the transient.

- [ ] **Step 5: Implement Tokens page** — `TokensListTable extends WP_List_Table` with columns name, scopes (badges), created by (display name), created, expires, last used, status; row actions Revoke (nonce, `admin-post.php?action=techthrill_mcp_revoke_token&id=`) and Delete for revoked/expired rows.

- [ ] **Step 6: Implement Activity page** — `ActivityListTable` over `AuditLog::query`, 50 per page, dropdown filters for token and tool, status badge colours (ok green, error red, refused orange), "Clear log" button with nonce.

- [ ] **Step 7: Implement Settings page** — Settings API: `register_setting('techthrill_mcp', Settings::OPTION, ['sanitize_callback'=>[Settings::class,'sanitize']])`, one section, five fields per spec §6.4 with descriptions; safe-mode description lists what it blocks in one sentence.

- [ ] **Step 8: Implement `admin.js`** (copy buttons using `navigator.clipboard` with a textarea fallback, preset buttons toggling checkboxes, custom expiry date toggle) and `admin.css` (status rows, token box monospace, badges).

- [ ] **Step 9: Manual verification** — in the local site: create a token with each preset; copy the Claude Code command and run it on this machine; in Claude Code run `/mcp` and confirm the server is connected; call `site_info`; revoke the token and confirm Claude Code gets a 401; check Activity shows the calls; change safe mode and rate limit and confirm `site_info` reflects it.

- [ ] **Step 10: Run `composer test`; commit** — `feat: admin ui with connect, tokens, activity and settings`

---

### Task 17: Release packaging, lint, CI and docs

**Files:**
- Create: `readme.txt`, `phpcs.xml.dist`, `build.sh`, `.github/workflows/ci.yml`, `languages/techthrill-mcp.pot`
- Modify: `README.md` (manual verification checklist from spec §8.2, connection instructions for Claude Code and Codex, filter reference), `composer.json` (add `"build": "bash build.sh"`)

- [ ] **Step 1: Write `phpcs.xml.dist`** — rulesets `WordPress` and `PHPCompatibilityWP` with `testVersion 7.4-`, text domain `techthrill-mcp`, prefixes `techthrill_mcp`, `TechThrill\MCP`, `TECHTHRILL_MCP`; exclude `vendor`, `tests`, `dist`; exclude rule `WordPress.Files.FileName` (PSR-4 names).

- [ ] **Step 2: Run `composer lint`; fix every reported issue** — Expected: no errors. Warnings about `proc_open` and direct DB queries are expected and get `// phpcs:ignore` with a reason.

- [ ] **Step 3: Write `readme.txt`** — wordpress.org header (Contributors, Tags: mcp, claude, codex, ai, wp-cli; Requires at least 6.0; Tested up to 6.9; Requires PHP 7.4; Stable tag 1.0.0; License GPLv2 or later), Description (what it does, security model in plain words, that nothing is exposed until a token is created), Installation, FAQ (does it work without WP-CLI; is it safe; how to revoke; multisite: activate per site, tokens and logs are per site while files are shared), Changelog 1.0.0.

- [ ] **Step 4: Write `build.sh`** — `rsync`-free: `rm -rf dist && mkdir -p dist/techthrill-mcp`, copy `techthrill-mcp.php uninstall.php readme.txt includes languages`, `cd dist && zip -r techthrill-mcp.zip techthrill-mcp`. Run it and confirm the zip contains no `tests/`, `docs/`, or `composer.*`.

- [ ] **Step 5: Write `.github/workflows/ci.yml`** — matrix PHP 7.4, 8.1, 8.3 on ubuntu-latest; `shivammathur/setup-php`, `composer install --no-interaction`, `composer lint`, `composer test`.

- [ ] **Step 6: Generate `languages/techthrill-mcp.pot`** — download `wp-cli.phar` to the scratch folder if needed and run `php wp-cli.phar i18n make-pot . languages/techthrill-mcp.pot --domain=techthrill-mcp`; or, if that is impractical, write the header-only `.pot` and note it in README.

- [ ] **Step 7: Fill the README checklist and install docs; run the full manual checklist once against the local site, including the Codex connection.**

- [ ] **Step 8: Run `composer test` and `composer lint`; commit** — `chore: readme, phpcs, build script and ci`

- [ ] **Step 9: Tag** — `git tag v1.0.0`
