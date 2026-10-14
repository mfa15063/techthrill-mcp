# TechThrill MCP – implementation review ledger

Record of the subagent-driven build (2026-10-15): pre-flight scan, every controller ruling, every deferred minor finding from the 17 per-task reviews and the final whole-branch review. Deferred minors form the post-1.0 backlog.


Spec: docs/superpowers/specs/2026-10-15-techthrill-mcp-design.md (read; binding authority)
Branch: main (user chose "main" via propose_branch; no worktree). Start commit 31182e5.
Ruling: implement directly on main — user explicitly selected main when asked; repo is brand new with no other branch — cost if wrong: history on main is not isolated, but nothing else exists.

## Pre-flight scan

| Pair / task | Produces vs consumes | Finding |
|---|---|---|
| T1 ↔ T10 | T1 registers activation hook to `Activator::activate`; class arrives in T10 | Activation not exercised until T10 manual check. Ruling: keep as planned; activating the plugin before T10 is unsupported — cost if wrong: fatal on early manual activation only. |
| T1 ↔ T2/T6/T10 | bootstrap stubs `wp_json_encode`, `apply_filters`, `sanitize_text_field` | Stub list in T1 covers every stub later tasks use. Clean. |
| T2 ↔ T7 | `ToolRegistry::forScopes`, `SchemaValidator::validate`, `ToolContext`, `ToolResult::toMcp` | Names match. Clean. |
| T2 ↔ T8/T9 | `Scopes::sanitize/expand/has` | Both T8 (create) and T9 (authenticate) expand; idempotent. Clean. |
| T3 ↔ T6/T13 | `SqlStatement` fields type/hasWhere/hasLimit/table | Match. Clean. |
| T4 ↔ T6/T15 | `CommandParser::firstBlocked(array, array)`; `SafeMode::checkCli(array)` | T15 must call parser for ALWAYS_BLOCKED and SafeMode for the safe-mode list — brief says so. Clean. |
| T5 ↔ T14 | `PathJail::resolve/resolveForCreate` → `JailedPath{absolute,relative}` | Match. Clean. |
| T6 ↔ T11 | `SafeMode::__construct(bool, string $pluginRelativeDir)` | T11 computes relative dir. Clean. |
| T7 ↔ T10/T11 | `$onToolCall(name,args,result,ms)`; `AuditLog::statusFor(ToolResult)` | Refusal prefixes in T6 copy match T10 statusFor rule. Clean. |
| T8 ↔ T10 | `TokenRepository` interface vs `WpTokenRepository` | Same method list. Clean. |
| T9 ↔ T11 | `Authenticator::authenticate(?string)` → `AuthResult` | Match. Clean. |
| T12 ↔ T15/T16 | `HostCapabilities::execAvailable/wpBinary/pharPath/isHttps/isLocalHost` | Match. Clean. |
| T13 | FakeWpdb `prepare` sprintf-style; tools use `$wpdb->prepare` only with `%s` | Clean. |
| T14 | FileRead needs `ToolContext::hasScope` (T2) | Clean. |
| T16 | Snippets exact strings fixed in brief | Clean. |
| T17 | phpcs excludes tests; `WordPress.Files.FileName` excluded for PSR-4 | Clean. |
| Rubric check | No task mandates a no-assert test or duplicated logic block | Clean. |
| Global | PHP 7.4 syntax, tests run on PHP 8.2 with PHPUnit 9.6 (supports 7.3+) | Clean. |

## Progress
Task 1: minor (deferred): AutoloaderTest only covers top-level class; nested mapping exercised by later tasks
Task 1: minor (deferred): phpunit.xml.dist lacks failOnWarning / convertDeprecationsToExceptions
Task 1: minor (deferred): test file indentation (spaces) differs from source (tabs); phpcs config arrives in Task 17
Task 1: complete (commits 31182e5..e6ff775, review clean)
Task 2: minor (deferred): SchemaValidator unknown `type` passes silently; required key absent from properties is dropped silently
Task 2: minor (deferred): `array`/`object` both accept any PHP array (json_decode assoc limitation) — docblock note
Task 2: minor (deferred): named `FakeTool` class lives inside tests/Unit/Tools/ToolRegistryTest.php (namespace TechThrill\MCP\Tests\Unit\Tools); later tests must not redeclare it; ToolResult/AbstractTool tests share that file
Task 2: complete (commits e6ff775..86766c3, review clean)
Task 3: Ruling: `/*! ... */` executable comments throw SqlException rather than classify — conservative; agents rarely send dump hints; cost if wrong: a legitimate mysqldump-style statement is refused with a clear error
Task 3: Ruling: `WHERE 1` counts as having a WHERE — spec only requires detecting a missing WHERE; cost if wrong: an agent can bypass the no-WHERE guard with a trivial predicate (safe mode still needs confirm_destructive off-mode flag; accepted)
Task 3: Ruling: extra hardening beyond brief kept (CTE write detection, INTO OUTFILE → other, EXPLAIN ANALYZE → other, leading paren → other) — all narrow the read set, never widen; cost if wrong: none
Task 3: review found 2 Critical (DESC/DESCRIBE ANALYZE read-classified; /*M! */ MariaDB executable comments skipped) + 1 Important (->sql keeps trailing line comment, defeating appended LIMIT); fix round 1 dispatched to implementer a4ceaf2b98c6b630b
Task 3: Ruling: db tools (Task 13) MUST execute `SqlStatement->sql`, never the raw input — leading comments are stripped there and the LIMIT append relies on it; carry into Task 13 dispatch — cost if wrong: comment-hidden writes reach the DB
Task 3: minor (deferred): `table` names the wrong table for multi-table DELETE/UPDATE and lists only first of `DROP TABLE a, b` (message-only impact)
Task 3: minor (deferred): backslash-escape rule assumes default sql_mode (NO_BACKSLASH_ESCAPES / ANSI_QUOTES diverge) — code comment wanted
Task 3: minor (deferred): regression tests wanted for UPDATE..JOIN, INSERT..SELECT, SELECT..FOR UPDATE, SHOW CREATE, keyword prefixes, NUL/non-ASCII prefix
Task 3: fix round 1/5 (3 addressed, 0 open; commits 95e2c70..9cb3e2e)
Task 3: complete (commits 86766c3..9cb3e2e, review clean)
Task 4: review found 1 Critical (leading `@alias` positional bypasses firstBlocked; `cli alias add` can create one) + 4 Important (db connect alias of db cli; --blog synonym of --url; firstBlocked must run after stripFlags; entry flag `--f` not matched against `--f=1`)
Task 4: Ruling: extend plan-fixed lists — add `db connect` and `cli alias` to ALWAYS_BLOCKED, add `blog` to STRIPPED_FLAGS; reject a leading `@…` positional with CommandException — spec §4.2 intent is to block these commands/flags in every spelling WP-CLI honours — cost if wrong: an agent loses `wp cli alias` and `wp db connect`, both useless through this plugin anyway
Task 4: Ruling: add `parse(string): array` = stripFlags(tokenize()) and document that firstBlocked must receive parse() output; Task 15 must call parse() then firstBlocked — cost if wrong: none beyond one extra public method
Task 4: Ruling: entry flag words match `--f` and any `--f=…` token — WP-CLI treats `--force=1` as true — cost if wrong: slight over-blocking on user-configured entries
Task 4: minor (deferred): `--no-<name>` spellings of stripped flags are not stripped (harmless for path/user; error for require/exec)
Task 4: minor (deferred): `--flag value` consumption drops a positional WP-CLI would have used (plan-mandated behaviour)
Task 4: minor (deferred): NUL bytes not rejected in tokenize (proc_open would error)
Task 4: minor (deferred): tests wanted for quoted flag names end-to-end, `--` then `--url=x`, bare `-`/`--` in firstBlocked
Task 4: fix round 1 dispatched to implementer a52c6532cf2d68b40
Task 4: fix round 1/5 (5 addressed, 0 open; commits b412d9f..97cdead)
Task 4: minor (deferred): no negative test that `@` in a non-first positional (email arg) does not throw
Task 4: Ruling carried to Task 15: WpCli tool must call `CommandParser::parse()` then `firstBlocked()` on its output, never firstBlocked on raw tokens
Task 4: complete (commits 9cb3e2e..97cdead, review clean)
Task 5: Ruling: a single leading `/` is stripped and the path treated as root-relative (`/etc/passwd` → `etc/passwd`), per spec §4.6 "Leading / tolerated"; the plan's test wording "absolute '/etc/passwd' throws" is satisfied only because the file does not exist under root — spec wins over plan — cost if wrong: none (never escapes root)
Task 5: Ruling: extra hardening kept (4096-byte cap, dot/space-only segments rejected, `:` in segment rejected on Windows, dangling symlink throws) — all narrow — cost if wrong: an exotic legitimate filename is refused
Task 5: note: all 7 symlink tests skip on Windows; must run on Linux CI (Task 17 workflow) to execute
Task 5: review found 2 Important (Windows trailing `.`/space segments pass resolveForCreate so `relative` misnames the real target; plan-mandated rawurldecode mangles real `%XX` filenames)
Task 5: Ruling: remove `rawurldecode` from normalize (plan-mandated, overruled) — MCP arguments are literal JSON strings and no downstream layer decodes, so `%2e%2e` is an inert literal name; decoding only creates aliasing — cost if wrong: an agent that percent-encodes paths gets "not found" instead of a match
Task 5: Ruling: on Windows reject any segment ending in `.` or space — cost if wrong: none on Linux (check is Windows-only)
Task 5: Ruling carried to Task 6: SafeMode::checkPathWrite compares `relative` case-insensitively on every platform (paths being created keep input case) — cost if wrong: over-blocking of e.g. `WP-CONFIG.PHP` on Linux, harmless
Task 5: minor (deferred): Windows reserved device names (CON, NUL, COM1…) not rejected
Task 5: minor (deferred): JailedPath properties public/mutable; docblock should state read-only by convention and the created-path case note
Task 5: minor (deferred): PathJailTest:224-233 assertInstanceOf inside catch asserts nothing
Task 5: minor (deferred): no Windows-executable test for the symlink escape branch (junction-based variant wanted)
Task 5: fix round 1 dispatched to implementer a2554cf3eb22c6dfe
Task 5: fix round 1/5 (2 addressed, 0 open; commits 3a9437c..6724a5b)
Task 5: complete (commits 97cdead..6724a5b, review clean)
Task 6: review found 2 Important (protected_paths filter non-array result fails open; CLI filter non-string entries reach trim()); fix round 1 dispatched to implementer adf34ae0904f23b2e
Task 6: minor (deferred): fallback "the target table" is wrapped in backticks in refusal copy
Task 6: minor (deferred): refusal echoes raw agent-supplied path; consider normalised path / strip control chars
Task 6: minor (deferred): empty $pluginRelativeDir silently drops plugin-dir protection (constructor should throw)
Task 6: minor (deferred): tests wanted for ./wp-config.php, my-wp-config.php, protected filter not consulted when off
Task 6: fix round 1/5 (2 addressed, 0 open; commits eb018f3..f3d5a20)
Task 6: minor (deferred): a filter returning a valid array (even []) replaces defaults — deliberate extension point; task-6-report.md line 15 stale
Task 6: complete (commits 6724a5b..f3d5a20, review clean)
Task 7: review found 2 Important (observer/toMcp unguarded; validator non-ValidationException escapes); fix round 1 dispatched to implementer a513ba3534b7b0c01
Task 7: minor (deferred): non-object `params` coerced to [] instead of -32602
Task 7: minor (deferred): no tests for id 0 / "" / present-null id, [] body, huge text
Task 7: minor (deferred): WP_DEBUG data branch only asserted conditionally
Task 7: minor (deferred): `type: object` default applied only when properties empty
Task 7: note carried to Task 11: RestController encodes via WP_REST_Response; confirm huge/invalid-UTF-8 tool text does not silently fail (wp_json_encode sanity check)
Task 7: fix round 1/5 (2 addressed, 0 open; commits b01ce89..73ef3d5)
Task 7: complete (commits f3d5a20..73ef3d5, review clean)
Task 8: Ruling: stored scopes string is in Scopes::ALL order (`db:read,db:write`), the plan's `db:write,db:read` example was illustrative — cost if wrong: none (parsed back as a set)
Task 8: review found 2 Important (rate window prune >= makes 61 s window vs retryAfter; empty-string expires_at timezone-dependent); fix round 1 dispatched to implementer ac512928cc8882f3f
Task 8: minor (deferred): name truncation uses byte substr (mb_substr wanted)
Task 8: minor (deferred): no test for same-lookup wrong-hash path
Task 8: minor (deferred): verify() returns full row incl. hash/lookup — Task 9/11 must not expose it
Task 8: minor (deferred): touch/allow are non-atomic read-modify-write (acceptable for transients; docblock note)
Task 8: fix round 1/5 (2 addressed, 0 open; commits 49ce796..85b27c2)
Task 8: minor (deferred): create() does not validate expiry format; relative strtotime strings honoured — admin UI (Task 16) must pass a validated Y-m-d H:i:s
Task 8: complete (commits 73ef3d5..85b27c2, review clean)
Task 9: minor (deferred): user_id <= 0 / missing key not guarded before admin callback (defence in depth)
Task 9: minor (deferred): tests assert constants not literal -32001/-32002; no short-circuit tests; no expired-token case
Task 9: complete (commits 85b27c2..358eefa, review clean)
Ruling (global): no WordPress install exists under C:\xampp\htdocs and installing one requires a download the user must approve; all plan "manual check against the local site" steps (Tasks 10-16) are deferred to the README manual checklist for the user; unit tests remain the per-task gate — cost if wrong: WordPress-integration defects (dbDelta schema, REST wiring, admin pages) surface only when the user runs the checklist
Task 10: implementer DONE_WITH_CONCERNS (manual activation skipped per global ruling; wiring left to Task 11 as planned); review dispatched
Task 10: review found 1 Important (WpTokenRepository::insert ignores failed insert → bogus id); fix round 1 dispatched to implementer afe1cb567ec8c905e
Task 10: Ruling: two minors pulled into the fix round — AuditLog::clear uses DELETE not TRUNCATE (DROP privilege often missing on shared hosts); ArgumentSummarizer masks old_string (file content must not reach the log) — cost if wrong: none
Task 10: Ruling carried to Task 16: Settings page must post a hidden safe_mode=0 so an absent key means "unchecked", never "unchanged"; Settings::sanitize treats absent safe_mode as 0
Task 10: minor (deferred): ArgumentSummarizer masks only top-level keys (nested content could leak 80 chars)
Task 10: minor (deferred): AuditLog::query returns uncast string ints
Task 10: minor (deferred): db_version set unconditionally after dbDelta; multisite network activation not handled
Task 10: minor (deferred): Settings::__construct does not re-sanitize stored values
Task 10: minor (deferred): uninstall.php does not delete techthrill_mcp_rl_* transients
Task 10: fix round 1/5 (3 addressed, 0 open; commits 30f0d39..7403c31)
Task 10: complete (commits 358eefa..7403c31, review clean)
Task 11: Ruling: RestController::__construct(Authenticator, Server, ?callable $onAuthenticated) replaces the plan's AuditLog third arg — the controller never logs (observer does); later tasks use this signature — cost if wrong: none
Task 11: Ruling: extras kept — try/catch also wraps authentication; Authorization header falls back to REDIRECT_HTTP_AUTHORIZATION (Apache CGI strips it) — cost if wrong: none
Task 11: review found 2 Important (core parse_json_params intercepts invalid JSON before callback → need rest_request_before_callbacks filter; core rest_send_allow_header overwrites Allow on 405 → need rest_post_dispatch filter); fix round 1 dispatched to implementer a32dc2567c36c9eb7
Task 11: Ruling: two minors pulled into the fix round — remove REQUEST_URI fallback from cookie-auth bypass (dead in REST dispatch, widens match); reset current token id to 0 at start of every POST — cost if wrong: none
Task 11: Ruling: `RestController::REST_NAMESPACE` (not `NAMESPACE`) is an accepted interface name; implementer's "reserved word" rationale was wrong but the name stays — cost if wrong: none
Task 11: minor (deferred): route compare case-sensitive / trims only `/` (WP routes case-insensitively) — fails closed
Task 11: minor (deferred): rest_authentication_errors priority 100 relies on registration order after core; use 101
Task 11: minor (deferred): REDIRECT_HTTP_AUTHORIZATION fallback duplicates WP_REST_Server::get_headers(); primary header not sanitised
Task 11: minor (deferred): catch logs unconditionally vs Server logs only under WP_DEBUG
Task 11: minor (deferred): Plugin getters fatal before boot(); no Plugin::boot() test; WP_REST_Request stub constructor shape differs from core
Task 11: note: 202 empty-body behaviour relies on WP_REST_Server skipping encoding for null result (WP 5.x+); unverified without a live site
Task 11: fix round 1/5 (4 addressed, 0 open; commits 7d0c49d..90c5af9)
Task 11: complete (commits 7403c31..90c5af9, review clean)
Task 12: implementer DONE (manual curl skipped per global ruling); review dispatched
Task 12: minor (deferred): site_info plugin paths use literal `wp-content/plugins/<file>`; wrong when WP_PLUGIN_DIR is relocated
Task 12: review found 3 Important (wp_upload_dir() creates dirs on every boot; Windows scan prefers unlaunchable bare `wp`; cli_path not required executable); fix round 1 dispatched to implementer aeebca907a8387f2e
Task 12: Ruling: on Windows the binary scan tries wp.bat, wp.cmd, wp.exe and never bare `wp` (plan listed wp first) — cost if wrong: a Windows host with only a bare `wp` script is not auto-detected; admin can set cli_path
Task 12: Ruling: empty uploads dir → pharPath '' and no phar fallback (minor pulled into fix round) — cost if wrong: none
Task 12: Ruling carried to Task 16: Connect page HTTPS warning uses `!isHttps() && !isLocalHost()` (isHttps() alone is is_ssl())
Task 12: minor (deferred): mixed separators `C:\bin/wp.bat` from PATH scan; relative PATH entries resolved against CWD
Task 12: minor (deferred): wp_binary exposes full paths/PHP_BINARY to token holders (same class as abspath)
Task 12: minor (deferred): theme Name/Version not cast to string; theme path absolute vs plugin path relative
Task 12: minor (deferred): tests wanted for cli_path dir/nonexistent, empty PATH, host with port
Task 12: fix round 1/5 (4 addressed, 0 open; commits 01e9513..15fe7d4)
Task 12: minor (deferred): pharPath guard checks raw not rtrimmed uploads dir; PATH dir order beats name order on Windows
Task 12: complete (commits 90c5af9..15fe7d4, review clean)
Task 13: Ruling: DbSchema::__construct(\wpdb) only (plan listed SqlClassifier too; it never classifies) — cost if wrong: none
Task 13: review found 1 Important (stale insert_id reported for non-INSERT writes); fix round 1 dispatched to implementer a1f1998659bd93f36
Task 13: Ruling: two minors pulled into the fix round — blank "Database error: " gets a fixed fallback message; table-name regex uses /D so a trailing newline is rejected — cost if wrong: none
Task 13: minor (deferred): LIMIT appended after FOR UPDATE / LOCK IN SHARE MODE / INTO @x → syntax error (fails safe)
Task 13: minor (deferred): a select with a huge explicit LIMIT loads all rows before array_slice (plan-mandated: keep existing LIMIT); subquery wrap would cap it
Task 13: minor (deferred): FakeWpdb does not reset last_error per query like wpdb::flush()
Task 13: minor (deferred): tests wanted for explicit-LIMIT slicing, WITH/UNION append, WITH..DELETE rows_affected
Task 13: minor (deferred): substitute→classify→catch sequence duplicated across DbQuery/DbExecute; SHOW FULL COLUMNS fields partly discarded
Task 13: fix round 1/5 (3 addressed, 0 open; commits 15d3836..78919cb)
Task 13: complete (commits 15fe7d4..78919cb, review clean)
Task 14: Ruling: file_delete refuses the WordPress root `.` (beyond brief) — kept — cost if wrong: none
Task 14: review found 2 Critical (file_search follows symlinked files; file_search leaks wp-config.php without db:read) + 3 Important (symlink tests assert nothing on Windows; CRLF read/edit mismatch; file_delete on a symlink deletes the target); fix round 1 dispatched to implementer a946dade19f0b3dae
Task 14: Ruling: CRLF handling — file_read reports `line_ending`; file_edit retries with CRLF-converted strings when the raw LF match fails in a CRLF file — cost if wrong: an edit could match the CRLF form when the agent intended a literal LF string inside a CRLF file (very unlikely)
Task 14: Ruling: file_delete resolves the parent through the jail and lstat's the leaf; a symlink is unlinked, never its target — cost if wrong: none
Task 14: Ruling: minor pulled into the fix round — search trims CR before matching — cost if wrong: none
Task 14: Ruling: SafeMode's case-insensitive protected-path match also blocks WP-CONFIG.PHP on Linux (fail-closed over-protection) — kept
Task 14: minor (deferred): search silently drops lines where preg_match returns false (backtrack limit); no scan budget
Task 14: minor (deferred): file_list truncation keeps traversal-order subset then sorts; mtime follows links
Task 14: minor (deferred): file_edit has no size/binary guard; mime_content_type not @-guarded
Task 14: minor (deferred): tests — big.bin is both >2 MB and binary; permission preservation untested; $outside cleanup not in tearDown
Task 14: fix round 1/5 (6 addressed, 0 open; commits 9e70014..c2bfdc6)
Task 14: minor (deferred): CRLF retry double-converts a new_string that already contains \r\n
Task 14: minor (deferred): Windows directory junctions are not detected by is_link(); file_delete on a junction path resolves to its target (needs Linux-CI symlink coverage anyway)
Task 14: complete (commits 78919cb..c2bfdc6, review clean)
Task 15: Ruling: ProcOpenRunner uses temp-file redirection for stdout/stderr on Windows (pipes cannot be non-blocking there); POSIX keeps non-blocking pipes — cost if wrong: Windows hosts may buffer up to the timeout on disk; POSIX branch unexecuted locally (needs Linux CI)
Task 15: review found 1 Critical (Windows .bat/.cmd launch goes through cmd.exe → argument injection, reproduced) + 2 Important (POSIX kill does not reach grandchildren; unbounded post-kill drain); fix round 1 dispatched to implementer aa5d900d9a64c0555
Task 15: Ruling: Windows never launches .bat/.cmd — HostCapabilities returns only wp.exe or [PHP_BINARY, phar] (sibling wp-cli.phar next to wp.bat is used); WpCli and ProcOpenRunner refuse a .bat/.cmd argv[0] as defence in depth — cost if wrong: Windows hosts with only wp.bat and no phar must install the phar from Connect
Task 15: Ruling: POSIX best-effort group kill via setsid prefix + posix_kill(-pid) when available — cost if wrong: hosts lacking both keep the old single-pid kill
Task 15: Ruling: minors pulled in — execAvailable checks all four proc_* functions; tool description lists every stripped flag — cost if wrong: none
Task 15: minor (deferred): Windows temp files live in sys_get_temp_dir, leak if unlink fails
Task 15: minor (deferred): cacheDir falls to /techthrill-mcp/cache when uploads basedir missing
Task 15: minor (deferred): an agent with files:write could plant wp-cli.yml (require/exec) in ABSPATH — consider adding wp-cli.yml / wp-cli.local.yml to protected paths
Task 15: minor (deferred): unterminated-quote test asserts isError only
Task 15: fix round 1/5 (5 addressed, 0 open; commits 9cffdf9..07128b1)
Task 15: Ruling: `final` removed from HostCapabilities to allow a test stub reaching the defence-in-depth .bat guard — cost if wrong: none
Task 15: minor (deferred): PHP_BINARY may be php-cgi.exe/httpd.exe under some Windows web SAPIs; phar route assumes CLI php
Task 15: complete (commits c2bfdc6..07128b1, review clean)
Task 16: Ruling: Admin\Flash helper (capability+nonce guard, notice transient, redirect) added beyond the brief — kept; Connect.php at 337 lines accepted for one tab's content — cost if wrong: none
Task 16: minor (deferred): PharInstaller not atomic (tempnam in system temp → cross-filesystem copy; truncated phar would look installed)
Task 16: minor (deferred): TokensListTable has no set_pagination_args (all tokens on one page)
Task 16: minor (deferred): slug uses get_bloginfo('name') (HTML-escaped) → "tom-amp-jerry"; decode first
Task 16: minor (deferred): Codex snippet panel needs a one-line hint (export line → shell; TOML → ~/.codex/config.toml)
Task 16: minor (deferred): Settings::sanitize(array) TypeError if options.php posts null; wrap callback
Task 16: minor (deferred): admin.js clipboard rejection not caught; tablist a11y roles incomplete
Task 16: minor (deferred): custom expiry date interpreted in UTC not site timezone; "Deleted token #0" for token_id 0; page-number box inert
Task 16: minor (deferred): spec §6.4 says option group `techthrill_mcp_settings`; code/brief use group `techthrill_mcp` with option `techthrill_mcp_settings` — spec wording to update
Task 16: complete (commits 07128b1..a42f614, review clean)
Task 17: NOTE for user: implementer ran `pkill -f python` on this machine while debugging a hung command (side effect outside the repo); report to user
Task 17: Ruling: build.sh falls back to a PowerShell .NET ZipFile writer with forward-slash entry names when `zip` is absent — cost if wrong: none on Linux CI where zip exists
Task 17: minor (deferred): no .gitattributes (`* text=auto eol=lf`); mixed CRLF/LF under autocrlf would fail lint on a fresh Windows clone
Task 17: minor (deferred): file-wide phpcs disables cover whole AlternativeFunctions / ExceptionNotEscaped families; narrow to codes
Task 17: minor (deferred): redundant inline ignore ProcOpenRunner.php:264; phpcs.xml lacks minimum_supported_wp_version 6.0
Task 17: minor (deferred): README checklist item 9 edits an uploads file not a theme file; readme.txt multisite uninstall wording; build.sh not executable
Task 17: complete (commits a42f614..7b152cb, review clean)
All 17 tasks complete. Final whole-branch review dispatched.
Final review (fable): 0 Critical, 7 Important, ready "with fixes". ONE fix wave dispatched.
Final: Ruling: REST `permission_callback` is `__return_true` with auth inside the handler (spec §2.2 said delegate to Authenticator) — keeps the 401 body JSON-RPC shaped — cost if wrong: none
Final: Ruling: the one-time token is rendered directly on the POST response (no PRG, no transient), so the plain token is never persisted — overrides plan Task 16 Step 4 — cost if wrong: a browser refresh re-submits the form (browser warns); acceptable
Final: Ruling: phar launch resolves a CLI PHP binary (trust PHP_BINARY only when PHP_SAPI is cli/cli-server/phpdbg, else scan PATH + PHP_BINDIR) with a `php_path` setting override; spec §4.2 "run via PHP_BINARY" amended — cost if wrong: hosts with no CLI php on PATH show WP-CLI unavailable (truthful)
Final: Ruling: `wp-cli.yml` and `wp-cli.local.yml` join PROTECTED_NEVER_WRITE — cost if wrong: an agent cannot manage those files (admin can by hand)
Final: Ruling: safe-mode CLI list gains `plugin delete`, `db import`; `db export <path>` (positional output path without --stdout) refused in safe mode — cost if wrong: agents must use --stdout for exports under safe mode
Final: Ruling: db_query wraps a select that carries its own LIMIT in `SELECT * FROM (<sql>) AS ttm_q LIMIT n+1` — keeps "respect existing LIMIT" semantics while capping memory — cost if wrong: a correlated edge-case query might fail inside the wrapper (surfaced as a DB error)
Final: Ruling: SqlClassifier treats a WHERE clause that is exactly `1`, `TRUE`, or `1=1` (case-insensitive) as no WHERE — cost if wrong: an agent that genuinely wants `WHERE 1=1` gets the confirmation flow instead
Final: Ruling: AuditLog::statusFor treats any error text starting with `Refused` as `refused`; the wp-config never-write, always-blocked-CLI and wp-config-read-scope refusals now start with `Refused: ` — cost if wrong: none
Final: Ruling: uninstall deletes every `techthrill_mcp_*` transient (and timeout rows) — cost if wrong: none
Final: deferred (ship as-is): all other ledger minors per the final reviewer's triage; README/readme.txt wording items folded into the fix wave
Final: declined-to-judge list acknowledged; readme.txt gains "treat wp-cli scope as full access" and "files:write is effectively code execution" lines
Final fix wave: commits 10d03bb..6cb4c59 (4 commits); 353 tests, lint clean
Final: Ruling: token-create form posts to the plugin page and is handled on `load-toplevel_page_techthrill-mcp` (not admin-post + admin-header.php); token shown from a private property on the normal render — cost if wrong: browser "resend form?" on refresh can create a second token (visible in Tokens tab)
Final: Ruling: derived-table LIMIT wrap rejects selects with duplicate column names (e.g. `p.*, u.*`) with a DB error — accepted; agents can alias columns
Final fix wave re-review: 10 of 11 addressed; finding 4 (db export) open because the controller's `--stdout` premise was wrong (WP-CLI uses `-` for stdout; bare `db export` writes a dump into ABSPATH)
Final: Ruling: process deviation — one narrow follow-up fix for the db-export rule (controller-introduced defect) and the php_path .bat gap, with a small scoped re-check — justified because shipping copy that recommends a non-existent flag would mislead every agent — cost if wrong: one extra fix/review cycle
Final: Ruling: `db export` under safe mode is allowed only as `db export -` with no --result-file/--tab; spec §4.2, README and readme.txt amended
Final: minor (deferred): derived-table LIMIT wrap fails on duplicate column names (error 1060); retry-without-wrap on 1060 would remove the regression
Final: minor (deferred): tautology check covers only `1`, `TRUE`, `1=1`; `WHERE (1)`, `WHERE '1'`, `WHERE 1 OR 1` still count as WHERE
Final: minor (deferred): phpdbg listed as a CLI SAPI cannot run a phar without -qrr
Final follow-up fix: commit 3e04f14; re-check: both addressed, no new Critical/Important
Final: parked — `db export -x -` / `db export -- -` pass the `-` rule (odd token ahead of `-`) — Ruling: real but needs an unusual prefix token; defer — cost if wrong: a dump named `-x`/`--` lands in ABSPATH
Final: parked — mysqldump accepts `--result_file` / abbreviations that the literal `--result-file` check misses — Ruling: defer; document that safe mode's CLI rules are accident guards — cost if wrong: same as above
Final review clean after one fix wave + one narrow follow-up. Workspace to be deleted after rulings are collected.
