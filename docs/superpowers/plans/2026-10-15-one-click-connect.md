# TechThrill MCP One-Click Connect Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Status:** implemented on branch `work` for release 1.2.0; see "As built" at the end for the differences from this plan.

**Goal:** Make the plugin a small OAuth 2.1 authorization server for its own MCP endpoint so a user pastes only the endpoint URL into Claude, ChatGPT developer mode, Claude Code or Codex, approves one consent screen and is connected. The bearer-token form stays as an Advanced fallback. Target release 1.2.0.

**Architecture:** New namespace `TechThrill\MCP\OAuth` under `includes/OAuth/`. Pure classes (PKCE, redirect allowlist, metadata, client registration, authorization validation, code store, token endpoint) take their dependencies through constructors so PHPUnit runs without WordPress. OAuth tokens reuse the existing tokens table and `ttm_` bearer format, so `Authenticator`, `Scopes`, rate limiting and the audit log need no changes. Thin adapters (`RestRoutes`, `WellKnown`, `ConsentPage`) wire the pure classes to WordPress.

**Tech Stack:** PHP 7.4+ syntax, WordPress 6.0+, PHPUnit 9, PHPCS with WordPress Coding Standards (unchanged).

**Spec:** `docs/superpowers/specs/2026-10-15-one-click-connect-design.md`. Base plan: `docs/superpowers/plans/2026-10-15-techthrill-mcp.md`.

## Global Constraints

- PHP 7.4 syntax only: no enums, no readonly, no `match`, no named arguments, no union types. Typed properties and arrow functions are fine.
- Namespace `TechThrill\MCP\OAuth`, files under `includes/OAuth/`, tests under `tests/Unit/OAuth/`. Text domain `techthrill-mcp`. Option prefix `techthrill_mcp_`.
- Every string shown to a human goes through `__()`/`esc_html__()`.
- Never put a token, refresh token or client secret in a URL, a log line or an error message. The only secret that may appear in a URL is the single-use authorization code. Only SHA-256 hashes are stored.
- All clients are public clients: `token_endpoint_auth_method` is `none`, PKCE `S256` is mandatory, `plain` is rejected.
- Redirects go only to a URI that equals one of the client's registered URIs byte for byte and passes `RedirectPolicy`. Unknown client or URI means an HTML error page, never a redirect.
- Constants: authorization code life 60 s; access token life 3600 s; refresh token life 2592000 s (30 days); DCR limit 10 per IP per hour; client cap 200; unused client life 30 days; CIMD document cap 10 240 bytes, timeout 5 s, cache 3600 s.
- Default consent scopes: `db:read` and `files:read` only.
- Pure classes get the clock as `?callable $clock = null` (defaults to `time`), like `RateLimiter` and `TokenManager`.
- Commit after every task with a conventional message. Run `composer test` and `composer lint` before each commit. Commit messages end with a blank line and `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- Do not tag a release in this plan. Version bump to 1.2.0 happens in Task 16.

## Review Focus

1. A failed or replayed `/token` call must never leave a usable code: Task 10 and Task 12 test that the code is consumed before PKCE is checked and that a second use fails.
2. A redirect URI that differs by a trailing slash, a different port on a non-loopback host, a different path on `claude.ai`, a fragment or user info must be rejected at registration and at `/authorize` — Tasks 4, 8, 11.
3. The consent POST must re-validate everything from the hidden fields and never trust the first render — Task 11 tests a tampered `redirect_uri`.
4. A refresh token used twice must fail the second time, and a refresh token presented with another client's ID must fail — Task 2 and Task 12.
5. A 401 on the MCP route must carry `WWW-Authenticate: Bearer resource_metadata="..."`; a 200 never does — Task 6.
6. Old bearer tokens and the Advanced flow must be unaffected: rows with `kind` NULL behave exactly as before — Tasks 1, 2 and 14.

---

### Task 1: Schema and repository support for OAuth tokens

**Files:**
- Modify: `includes/Activator.php`, `includes/Auth/TokenRepository.php`, `includes/Auth/WpTokenRepository.php`, `tests/Support/InMemoryTokenRepository.php`
- Test: `tests/Unit/Auth/TokenRepositoryContractTest.php` (new; runs the shared assertions against `InMemoryTokenRepository`)

**Interfaces:**
- Produces: four nullable columns on `{prefix}techthrill_mcp_tokens`: `kind varchar(10) NULL`, `client_id varchar(255) NULL`, `refresh_hash char(64) NULL` with `KEY refresh_hash`, `refresh_expires_at datetime NULL`. `TokenRepository::findByRefreshHash( string $hash ): ?array`. `WpTokenRepository::insert/update` accept the new keys; `normalize()` returns them as `null` when empty.
- Consumes: existing `TokenRepository` methods.

- [ ] **Step 1: Write failing tests**

```php
public function test_insert_and_find_by_refresh_hash(): void            // row with refresh_hash found; unknown hash -> null
public function test_rows_without_oauth_columns_still_work(): void      // insert old-style row, find(), findByLookup() unchanged, kind null
public function test_update_replaces_refresh_hash_in_place(): void      // old hash no longer found, new hash found, same id
```

- [ ] **Step 2: Run tests** — Expected: FAIL (`findByRefreshHash` undefined).

- [ ] **Step 3: Implement.** Add the columns to the `dbDelta` statement in `Activator::activate` (two spaces before `(id)` as `dbDelta` requires; keep `KEY lookup (lookup)` and add `KEY refresh_hash (refresh_hash)`). `Activator::maybeUpgrade` already re-runs activation when the stored version differs, so no extra migration code. `WpTokenRepository::findByRefreshHash` is a prepared `SELECT ... WHERE refresh_hash = %s LIMIT 1`; `formats()` keeps `%d` for `user_id` and `%s` for the rest, and `update()` must pass `null` through for the nullable columns.

- [ ] **Step 4: Run tests** — Expected: PASS.

- [ ] **Step 5: Commit** — `feat(oauth): token table columns for OAuth grants`

---

### Task 2: OAuth tokens in `TokenManager`

**Files:**
- Modify: `includes/Auth/TokenManager.php`, `includes/Admin/TokenStatus.php`
- Test: `tests/Unit/Auth/TokenManagerTest.php` (add), `tests/Unit/Admin/TokensStatusTest.php` (add)

**Interfaces:**
- Produces: constants `TokenManager::ACCESS_TTL = 3600`, `TokenManager::REFRESH_TTL = 2592000`, `TokenManager::REFRESH_PREFIX = 'ttr_'`.
  `createOAuth( string $clientId, string $clientName, array $scopes, int $userId ): array` returns `{ id:int, access_token:string, refresh_token:string, expires_in:int, scopes:string[] }`. The row has `kind` `oauth`, `name` `OAuth: <client name>` (cut to 100 characters), `expires_at` now plus `ACCESS_TTL`, `refresh_hash` SHA-256 of the refresh token and `refresh_expires_at` now plus `REFRESH_TTL`.
  `refreshOAuth( string $refreshToken, string $clientId, ?array $narrowScopes = null ): ?array` returns the same shape, or `null` when the token is unknown, malformed, expired, belongs to a revoked row or to another client. It replaces `lookup`, `hash`, `expires_at`, `refresh_hash` and `refresh_expires_at` on the existing row; `narrowScopes` may only remove scopes (intersection with the current scopes, never empty).
  `TokenStatus::of()` for `kind` `oauth` rows: Revoked if `revoked_at`; Expired if `refresh_expires_at` has passed; otherwise Active (an expired access token alone does not matter). Other rows are unchanged.
- Consumes: Task 1.

- [ ] **Step 1: Write failing tests**

```php
public function test_create_oauth_returns_two_tokens_and_stores_only_hashes(): void   // refresh starts 'ttr_'; row contains no plain value; kind 'oauth'; name 'OAuth: Claude'
public function test_access_token_verifies_like_any_bearer_token(): void              // verify(access_token) returns the row; expires in 3600 s with injected clock
public function test_refresh_rotates_both_tokens_in_place(): void                     // same id; old access token and old refresh token both fail; new pair works
public function test_refresh_with_wrong_client_or_unknown_token_returns_null(): void
public function test_refresh_after_expiry_or_revocation_returns_null(): void
public function test_refresh_can_only_narrow_scopes(): void                           // ['db:read'] of ['db:read','files:read'] ok; adding 'db:write' ignored; empty result -> null
public function test_bearer_rows_with_null_kind_are_unaffected(): void
public function test_status_for_oauth_rows(): void                                    // expired access + valid refresh -> active; refresh past -> expired; revoked
```

- [ ] **Step 2: Run tests** — Expected: FAIL.

- [ ] **Step 3: Implement** with `random_bytes`-based generation reused from `randomString` (refresh tokens are `ttr_` plus 40 characters, like access tokens). Reuse `hash()` for the refresh hash. Do not touch `create()` or `verify()`.

- [ ] **Step 4: Run tests** — Expected: PASS.

- [ ] **Step 5: Commit** — `feat(oauth): create and rotate OAuth tokens in TokenManager`

---

### Task 3: OAuth error type and PKCE

**Files:**
- Create: `includes/OAuth/OAuthException.php`, `includes/OAuth/Pkce.php`
- Test: `tests/Unit/OAuth/PkceTest.php`

**Interfaces:**
- Produces: `class OAuthException extends \RuntimeException { __construct( string $error, string $description, int $status = 400 ); error(): string; description(): string; status(): int }`.
  `final class Pkce { static isValidChallenge( string $c ): bool /* 43 chars of base64url */; static isValidVerifier( string $v ): bool /* 43 to 128 of A-Za-z0-9-._~ */; static challengeFor( string $verifier ): string; static verify( string $verifier, string $challenge ): bool /* hash_equals */ }`.

- [ ] **Step 1: Write failing tests** — include the RFC 7636 Appendix B vector (`dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk` verifies to `E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM`).

```php
public function test_rfc7636_vector_verifies(): void
public function test_wrong_verifier_fails(): void
public function test_challenge_must_be_43_base64url_chars(): void      // 42 and 44 chars, '+' and '=' rejected
public function test_verifier_length_and_alphabet(): void              // 42 rejected, 43 and 128 accepted, 129 rejected, space rejected
public function test_exception_exposes_error_description_and_status(): void
```

- [ ] **Step 2: Run tests** — Expected: FAIL.

- [ ] **Step 3: Implement.** `challengeFor` is `rtrim( strtr( base64_encode( hash( 'sha256', $v, true ) ), '+/', '-_' ), '=' )`.

- [ ] **Step 4: Run tests** — Expected: PASS.

- [ ] **Step 5: Commit** — `feat(oauth): PKCE S256 helpers and OAuth exception`

---

### Task 4: Redirect URI allowlist

**Files:**
- Create: `includes/OAuth/RedirectPolicy.php`
- Test: `tests/Unit/OAuth/RedirectPolicyTest.php`

**Interfaces:**
- Produces: `final class RedirectPolicy { static isAllowed( string $uri ): bool; static host( string $uri ): string /* for the consent page, '' when unparsable */ }`.
- Rules (spec §4.3): no fragment, no user info; exactly `https://claude.ai/api/mcp/auth_callback`; `https://chatgpt.com/connector/oauth/` plus one or more of `A-Za-z0-9_-` and nothing after; `http://localhost` or `http://127.0.0.1` with any port and any path.

- [ ] **Step 1: Write failing tests**

```php
public function test_allowed_uris(): void              // claude callback; chatgpt callback id; http://localhost:53682/callback; http://127.0.0.1:8080/x; http://localhost/ (no port)
public function test_rejected_uris(): void             // claude.ai with another path or trailing slash; http claude.ai; chatgpt.com/connector/oauth/ (empty id); chatgpt id with '/' or '?'; chatgpt.com.evil.com; http://localhost.evil.com; http://127.0.0.1.evil.com; https://localhost; http://[::1]/; custom scheme cursor://x; javascript:; fragment; user info 'http://u@localhost/'
public function test_host_for_display(): void
```

- [ ] **Step 2: Run tests** — Expected: FAIL.

- [ ] **Step 3: Implement** with `parse_url` plus anchored regular expressions on the whole string; reject any URI containing control characters or whitespace before parsing.

- [ ] **Step 4: Run tests** — Expected: PASS.

- [ ] **Step 5: Commit** — `feat(oauth): redirect URI allowlist`

---

### Task 5: Discovery documents

**Files:**
- Create: `includes/OAuth/Metadata.php`
- Test: `tests/Unit/OAuth/MetadataTest.php`

**Interfaces:**
- Produces: `final class Metadata { __construct( string $issuer, string $resource, string $authorizationEndpoint, string $tokenEndpoint, string $registrationEndpoint, string $resourceMetadataUrl ); protectedResource(): array; authorizationServer(): array; challenge( bool $invalidToken ): string; static wellKnownPath( string $document, string $url ): string; resourceMetadataUrl(): string; resource(): string; issuer(): string }`.
- `protectedResource()`: `resource`, `authorization_servers` (exactly `[ issuer ]`), `scopes_supported` (the five plugin scopes), `bearer_methods_supported` `["header"]`, `resource_name` `"TechThrill MCP"`.
- `authorizationServer()`: exactly the fields in spec §4.1, with `token_endpoint_auth_methods_supported` `["none"]`, `code_challenge_methods_supported` `["S256"]` and `client_id_metadata_document_supported` `true`.
- `challenge( false )` is `Bearer resource_metadata="<url>"`; `challenge( true )` is `Bearer error="invalid_token", resource_metadata="<url>"`.
- `wellKnownPath( 'oauth-authorization-server', 'https://x.com/blog/wp-json/techthrill-mcp/v1' )` returns `/.well-known/oauth-authorization-server/blog/wp-json/techthrill-mcp/v1`; `oauth-protected-resource` likewise; a URL with no path returns the bare well-known path.

- [ ] **Step 1: Write failing tests**

```php
public function test_protected_resource_lists_exactly_one_authorization_server(): void
public function test_authorization_server_advertises_cimd_s256_and_none_auth(): void
public function test_challenge_header_values(): void
public function test_well_known_path_for_root_and_subdirectory_installs(): void
public function test_scopes_supported_are_the_five_plugin_scopes(): void
```

- [ ] **Step 2: Run tests** — Expected: FAIL.

- [ ] **Step 3: Implement.** Take the scope list from `Scopes`; if `Scopes` has no public list, add `Scopes::all(): array` with a test in `ScopesTest`.

- [ ] **Step 4: Run tests** — Expected: PASS.

- [ ] **Step 5: Commit** — `feat(oauth): discovery documents and WWW-Authenticate value`

---

### Task 6: `WWW-Authenticate` on 401

**Files:**
- Modify: `includes/Auth/AuthResult.php`, `includes/Auth/Authenticator.php`, `includes/Mcp/RestController.php`
- Test: `tests/Unit/Auth/AuthenticatorTest.php` (add), `tests/Unit/Mcp/RestControllerTest.php` (add)

**Interfaces:**
- Produces: `AuthResult::reason(): string` returning `missing` (no or malformed header), `invalid` (unknown, expired or revoked token, or owner no longer an administrator) or `''` for other results. `RestController::__construct` gains a fourth optional parameter `?callable $challenge = null` with the signature `function ( bool $invalidToken ): string`. When set, every `401` response from the MCP route carries `WWW-Authenticate: <challenge>`; `429` and `200` responses never do.
- Consumes: Task 5's `Metadata::challenge` (wired in Task 13).

- [ ] **Step 1: Write failing tests**

```php
public function test_missing_header_result_has_reason_missing(): void
public function test_revoked_expired_and_unknown_tokens_have_reason_invalid(): void
public function test_401_response_has_www_authenticate_with_resource_metadata(): void         // no token
public function test_401_for_a_bad_token_adds_error_invalid_token(): void
public function test_rate_limited_and_successful_responses_have_no_challenge_header(): void
public function test_no_challenge_callable_keeps_current_behaviour(): void
```

- [ ] **Step 2: Run tests** — Expected: FAIL.

- [ ] **Step 3: Implement.** Keep the JSON-RPC body and status exactly as they are today; only add the header.

- [ ] **Step 4: Run tests** — Expected: PASS.

- [ ] **Step 5: Commit** — `feat(oauth): advertise resource metadata on 401 responses`

---

### Task 7: Client store and attempt limiter

**Files:**
- Create: `includes/OAuth/ClientStore.php`, `includes/OAuth/OptionClientStore.php`, `includes/OAuth/AttemptLimiter.php`, `tests/Support/InMemoryClientStore.php`
- Test: `tests/Unit/OAuth/AttemptLimiterTest.php`, `tests/Unit/OAuth/OptionClientStoreTest.php`

**Interfaces:**
- Produces: `interface ClientStore { find( string $clientId ): ?array; save( array $client ): void; delete( string $clientId ): void; all(): array }`. A client record is `{ client_id:string, client_name:string, redirect_uris:string[], source:'dcr'|'cimd', created_at:int, last_used_at:?int, fetched_at:?int }`.
  `OptionClientStore` keeps the array in the non-autoloaded option `techthrill_mcp_oauth_clients` through injected `$get`/`$update` callables (defaults `get_option`/`update_option`), so tests need no WordPress.
  `final class AttemptLimiter { __construct( RateLimitStore $store, ?callable $clock = null ); allow( string $bucket, string $subject, int $max, int $windowSeconds ): bool; retryAfter( string $bucket, string $subject, int $windowSeconds ): int }` using the existing `Auth\RateLimitStore`. The key is `techthrill_mcp_oa_` plus a SHA-256 of bucket and subject, so an IP address is never stored in clear.

- [ ] **Step 1: Write failing tests**

```php
public function test_allows_up_to_max_then_blocks_within_window(): void
public function test_window_slides_with_the_clock(): void
public function test_buckets_and_subjects_are_independent(): void
public function test_retry_after_is_seconds_until_the_oldest_hit_expires(): void
public function test_store_round_trip_find_save_delete_all(): void
```

- [ ] **Step 2: Run tests** — Expected: FAIL.

- [ ] **Step 3: Implement** using the sliding-window logic of `RateLimiter` as the model.

- [ ] **Step 4: Run tests** — Expected: PASS.

- [ ] **Step 5: Commit** — `feat(oauth): client store and per-IP attempt limiter`

---

### Task 8: Dynamic Client Registration

**Files:**
- Create: `includes/OAuth/ClientRegistrar.php`
- Test: `tests/Unit/OAuth/ClientRegistrarTest.php`

**Interfaces:**
- Produces: `final class ClientRegistrar { const MAX_CLIENTS = 200; const MAX_REDIRECT_URIS = 5; const UNUSED_TTL = 2592000; const LIMIT = 10; const WINDOW = 3600; __construct( ClientStore $store, AttemptLimiter $limiter, ?callable $clock = null ); register( array $metadata, string $ip ): array; prune(): int }`.
  `register()` returns `{ client_id, client_name, redirect_uris, grant_types, response_types:['code'], token_endpoint_auth_method:'none', client_id_issued_at }` and stores a record with `source` `dcr`. It throws `OAuthException( 'invalid_client_metadata', ..., 400 )` for: no `redirect_uris`, more than 5, any URI failing `RedirectPolicy`, `token_endpoint_auth_method` other than `none`, `grant_types` containing anything but `authorization_code` and `refresh_token`, `response_types` other than `['code']`, a non-string `client_name`; and `OAuthException( 'temporarily_unavailable', ..., 429 )` when the IP limit is hit. `client_name` is stripped of tags and control characters and cut to 100 characters, defaulting to `MCP client`. The generated ID is `ttc_` plus 24 random characters.
  `prune()` deletes `dcr` clients that never completed a grant (`last_used_at` null) and are older than `UNUSED_TTL`, then trims to `MAX_CLIENTS` removing the oldest unused first, and returns how many were deleted.

- [ ] **Step 1: Write failing tests**

```php
public function test_registers_a_public_client_with_allowlisted_uris(): void
public function test_rejects_uris_outside_the_allowlist_and_empty_or_too_many(): void
public function test_rejects_confidential_auth_methods_and_unsupported_grants(): void
public function test_client_name_is_sanitized_and_defaulted(): void
public function test_rate_limit_blocks_the_eleventh_registration_from_one_ip(): void
public function test_prune_removes_old_unused_clients_and_keeps_used_ones(): void
public function test_prune_enforces_the_cap_oldest_unused_first(): void
```

- [ ] **Step 2: Run tests** — Expected: FAIL.

- [ ] **Step 3: Implement.** Call `prune()` at the end of a successful `register()`.

- [ ] **Step 4: Run tests** — Expected: PASS.

- [ ] **Step 5: Commit** — `feat(oauth): dynamic client registration for public clients`

---

### Task 9: Client ID Metadata Documents and the client resolver

**Files:**
- Create: `includes/OAuth/ClientMetadataFetcher.php`, `includes/OAuth/ClientResolver.php`
- Test: `tests/Unit/OAuth/ClientMetadataFetcherTest.php`, `tests/Unit/OAuth/ClientResolverTest.php`

**Interfaces:**
- Produces: `final class ClientMetadataFetcher { const MAX_BYTES = 10240; const CACHE_TTL = 3600; __construct( callable $http, ClientStore $store, ?callable $clock = null ); isMetadataUrl( string $clientId ): bool; resolve( string $clientId ): array }`.
  `$http` has the signature `function ( string $url ): ?array` and returns `{ status:int, content_type:string, body:string }` or `null` on transport failure. `isMetadataUrl` is true for an `https://` URL with a host that is not an IP literal and a non-empty path, no fragment and no user info. `resolve()` returns the client record (`source` `cimd`), using the cached record when `fetched_at` is younger than `CACHE_TTL`, and otherwise fetching and validating: status 200, content type starting `application/json`, body at most `MAX_BYTES`, valid JSON whose `client_id` equals the URL, `redirect_uris` non-empty with every URI passing `RedirectPolicy`, `token_endpoint_auth_method` absent or `none`. Any failure throws `OAuthException( 'invalid_client', ..., 401 )`.
  `final class ClientResolver { __construct( ClientStore $store, ClientMetadataFetcher $fetcher ); find( string $clientId ): ?array; markUsed( string $clientId ): void }`: a metadata URL goes to the fetcher (returning `null` on failure), anything else to the store; `markUsed` sets `last_used_at`.
- The production `$http` (Task 13) wraps `wp_safe_remote_get` with `redirection` 0, `timeout` 5, `limit_response_size` `MAX_BYTES + 1`.

- [ ] **Step 1: Write failing tests**

```php
public function test_accepts_a_valid_document_and_caches_it(): void              // second resolve within TTL makes no HTTP call
public function test_refetches_after_the_cache_expires(): void
public function test_rejects_when_client_id_in_document_differs_from_url(): void
public function test_rejects_non_200_non_json_oversize_and_invalid_json(): void
public function test_rejects_documents_with_a_redirect_uri_outside_the_allowlist(): void
public function test_rejects_transport_failure(): void
public function test_is_metadata_url_rules(): void                                // http, IPv4 and IPv6 hosts, no path, fragment, userinfo all false
public function test_resolver_routes_urls_to_the_fetcher_and_ids_to_the_store(): void
public function test_resolver_returns_null_for_unknown_ids_and_failed_fetches(): void
public function test_mark_used_sets_last_used_at(): void
```

- [ ] **Step 2: Run tests** — Expected: FAIL.

- [ ] **Step 3: Implement.**

- [ ] **Step 4: Run tests** — Expected: PASS.

- [ ] **Step 5: Commit** — `feat(oauth): client ID metadata documents and client resolver`

---

### Task 10: Authorization code store

**Files:**
- Create: `includes/OAuth/AuthorizationCodeStore.php`, `includes/OAuth/OptionAuthorizationCodeStore.php`, `tests/Support/InMemoryAuthorizationCodeStore.php`
- Test: `tests/Unit/OAuth/AuthorizationCodeStoreTest.php`

**Interfaces:**
- Produces: `interface AuthorizationCodeStore { issue( array $payload, int $ttl = 60 ): string; consume( string $code ): ?array }`. The payload keys are `client_id`, `redirect_uri`, `code_challenge`, `resource`, `scopes` (string[]) and `user_id`.
  `OptionAuthorizationCodeStore( ?callable $clock = null, ?callable $options = null )` stores each code under a non-autoloaded option named `techthrill_mcp_oauth_code_` plus the SHA-256 of the code, holding the payload and `expires_at`. `consume()` reads the option, then calls `delete_option` and proceeds only when the delete reports success, so two concurrent callers cannot both use a code. It returns `null` for unknown, expired or already used codes. `prune(): int` deletes expired code options. The plain code is 43 random base64url characters and is never stored.

- [ ] **Step 1: Write failing tests**

```php
public function test_issue_then_consume_returns_the_payload(): void
public function test_second_consume_returns_null(): void
public function test_expired_code_returns_null_and_is_removed(): void
public function test_unknown_code_returns_null(): void
public function test_plain_code_is_not_stored(): void                    // inspect the option name and value
public function test_prune_removes_only_expired_codes(): void
public function test_losing_the_delete_race_returns_null(): void         // injected options layer whose delete returns false
```

- [ ] **Step 2: Run tests** — Expected: FAIL.

- [ ] **Step 3: Implement.**

- [ ] **Step 4: Run tests** — Expected: PASS.

- [ ] **Step 5: Commit** — `feat(oauth): single-use authorization codes`

---

### Task 11: Authorization request validation and the approval flow

**Files:**
- Create: `includes/OAuth/AuthorizationRequest.php`, `includes/OAuth/AuthorizeException.php`, `includes/OAuth/AuthorizeValidator.php`, `includes/OAuth/Authorizer.php`
- Test: `tests/Unit/OAuth/AuthorizeValidatorTest.php`, `tests/Unit/OAuth/AuthorizerTest.php`

**Interfaces:**
- Produces: `final class AuthorizationRequest { client(): array; redirectUri(): string; state(): string; codeChallenge(): string; requestedScopes(): array; resource(): string; params(): array /* the validated, normalised parameters, used for the consent form's hidden fields */ }`.
  `final class AuthorizeException extends OAuthException { __construct( string $error, string $description, ?string $redirectUri = null, string $state = '' ); redirectUri(): ?string; state(): string }`. A `null` redirect URI means "render an error page, do not redirect".
  `final class AuthorizeValidator { __construct( ClientResolver $clients, string $resourceUrl ); validate( array $params ): AuthorizationRequest }` implements spec §4.4 steps 1 and 2: unknown client or non-matching redirect URI throws with a `null` redirect URI; `response_type` other than `code`, a missing or malformed `code_challenge`, `code_challenge_method` other than `S256` throw `invalid_request`; a `resource` that is present and different from the endpoint throws `invalid_target`; a `scope` with an unknown name throws `invalid_scope`. Errors after the redirect URI is trusted carry it and the `state`.
  `final class Authorizer { const DEFAULT_SCOPES = array( 'db:read', 'files:read' ); __construct( AuthorizationCodeStore $codes ); approve( AuthorizationRequest $request, int $userId, array $scopes ): string; deny( AuthorizationRequest $request ): string; errorRedirect( AuthorizeException $e ): ?string }`. `approve()` sanitises `$scopes` through `Scopes::sanitize`, throws `OAuthException( 'invalid_scope', ... )` when none are left, issues a code and returns `redirect_uri` with `code` and `state` appended (keeping any existing query string); `deny()` returns the redirect with `error=access_denied` and `state`; `errorRedirect()` returns `null` when the exception has no redirect URI.

- [ ] **Step 1: Write failing tests**

```php
// AuthorizeValidatorTest
public function test_valid_request_returns_a_request_object(): void
public function test_unknown_client_and_unregistered_redirect_uri_do_not_redirect(): void
public function test_redirect_uri_must_match_byte_for_byte(): void                       // trailing slash, case, different port
public function test_missing_plain_or_malformed_challenge_is_invalid_request(): void
public function test_response_type_must_be_code(): void
public function test_resource_must_equal_the_endpoint_when_present(): void
public function test_unknown_scope_is_invalid_scope_but_known_scopes_pass(): void
public function test_errors_after_trust_carry_redirect_uri_and_state(): void
public function test_cimd_client_ids_are_resolved(): void
// AuthorizerTest
public function test_approve_returns_redirect_with_code_and_state_and_stores_the_payload(): void
public function test_approve_keeps_an_existing_query_string_on_the_redirect_uri(): void
public function test_approve_with_no_valid_scope_throws(): void
public function test_deny_redirects_with_access_denied_and_state(): void
public function test_default_scopes_are_read_only(): void
```

- [ ] **Step 2: Run tests** — Expected: FAIL.

- [ ] **Step 3: Implement.** Build redirect URLs with `http_build_query` using `PHP_QUERY_RFC3986`.

- [ ] **Step 4: Run tests** — Expected: PASS.

- [ ] **Step 5: Commit** — `feat(oauth): authorization request validation and approval redirects`

---

### Task 12: The token endpoint

**Files:**
- Create: `includes/OAuth/TokenEndpoint.php`
- Test: `tests/Unit/OAuth/TokenEndpointTest.php`

**Interfaces:**
- Produces: `final class TokenEndpoint { __construct( ClientResolver $clients, AuthorizationCodeStore $codes, TokenManager $tokens, AttemptLimiter $limiter, string $resourceUrl ); handle( array $form, string $ip ): array }` returning `{ status:int, headers:array, body:array }`. Every response has headers `Cache-Control: no-store` and `Pragma: no-cache`. Success is `200` with `{ access_token, token_type:'Bearer', expires_in:3600, refresh_token, scope }` (scopes space separated). Errors follow spec §4.5.
- `authorization_code` order of checks: rate limit (`token` bucket, 30 per minute per IP) -> `grant_type` -> required fields -> consume the code (burned even if later checks fail) -> client and redirect URI equal the stored values -> PKCE verifier format then `Pkce::verify` -> `resource` -> `createOAuth` -> `ClientResolver::markUsed`. `refresh_token`: client must resolve, then `refreshOAuth( ..., optional narrowed scope )`; `null` becomes `invalid_grant`.

- [ ] **Step 1: Write failing tests**

```php
public function test_authorization_code_exchange_returns_tokens_and_the_granted_scopes(): void
public function test_the_code_cannot_be_used_twice(): void
public function test_wrong_verifier_fails_and_still_burns_the_code(): void
public function test_wrong_client_or_redirect_uri_is_invalid_grant(): void
public function test_expired_code_is_invalid_grant(): void
public function test_resource_must_match_when_sent(): void
public function test_malformed_verifier_is_invalid_request(): void
public function test_missing_fields_and_unknown_grant_type_errors(): void
public function test_refresh_rotates_and_the_old_refresh_token_fails(): void
public function test_refresh_with_another_clients_id_fails(): void
public function test_refresh_scope_can_only_narrow(): void
public function test_rate_limit_returns_429_with_retry_after(): void
public function test_every_response_is_no_store(): void
public function test_issued_access_token_authenticates_through_the_existing_authenticator(): void   // integration with TokenManager + Authenticator
```

- [ ] **Step 2: Run tests** — Expected: FAIL.

- [ ] **Step 3: Implement.** Error bodies contain `error` and `error_description` only; never echo a submitted code, verifier or token.

- [ ] **Step 4: Run tests** — Expected: PASS.

- [ ] **Step 5: Commit** — `feat(oauth): token endpoint for authorization_code and refresh_token`

---

### Task 13: WordPress adapters and wiring

**Files:**
- Create: `includes/OAuth/Module.php`, `includes/OAuth/RestRoutes.php`, `includes/OAuth/WellKnown.php`, `includes/OAuth/ConsentPage.php`
- Modify: `includes/Plugin.php`, `includes/Mcp/RestController.php` (pass the challenge callable), `uninstall.php`
- Test: `tests/Unit/OAuth/WellKnownTest.php`, `tests/Unit/OAuth/RestRoutesTest.php`, `tests/Unit/OAuth/ConsentPageTest.php` using `tests/Support/wp-rest-stubs.php`

**Interfaces:**
- Produces: `final class Module { __construct( Plugin $plugin ); boot(): void; metadata(): Metadata; isAvailable(): bool; prune(): void }`. `isAvailable()` is true when pretty permalinks are on and the site is HTTPS (or a loopback host); when false, discovery routes still answer but the Connect page hides the one-paste card.
  `RestRoutes::register()` on `rest_api_init` adds under `techthrill-mcp/v1`: `GET /oauth/protected-resource`, `GET /oauth/authorization-server`, `POST /oauth/register` (JSON body -> `ClientRegistrar`, `201`), `POST /oauth/token` (form body -> `TokenEndpoint`), all with `permission_callback` `__return_true`. Each response is emitted through the same cookie-auth bypass and `show_in_index` false pattern as `RestController`, and `Access-Control-Allow-Origin` is not sent.
  `WellKnown::serve()` hooked to `init` at priority 1: matches the request path against `Metadata::wellKnownPath()` for both documents (GET only, trailing slash tolerated), sends the JSON with `Content-Type: application/json`, `Cache-Control: public, max-age=300` and exits; any other path returns without effect.
  `ConsentPage` registers `admin_post_techthrill_mcp_authorize` and `admin_post_nopriv_techthrill_mcp_authorize`. GET: logged-out users are redirected to `wp_login_url( current URL )`; a logged-in user without `manage_options` sees an error page; otherwise validate with `AuthorizeValidator` and render the consent screen (spec §4.4: client name, redirect host, five scope checkboxes with `db:read`/`files:read` pre-checked, Approve and Deny, hidden copy of the request parameters, nonce `techthrill_mcp_oauth_consent`, headers `X-Frame-Options: DENY`, `Content-Security-Policy: frame-ancestors 'none'`, `Cache-Control: no-store`). POST: verify the nonce and capability, re-run `AuthorizeValidator` on the hidden fields, then `Authorizer::approve` or `deny` and `wp_redirect` (never `wp_safe_redirect`, the host is external and already validated).
  `Module::prune()` runs `ClientRegistrar::prune()` and `OptionAuthorizationCodeStore::prune()`; `Plugin` calls it from the existing daily prune callback.
  `uninstall.php` deletes the option `techthrill_mcp_oauth_clients` and every option whose name starts with `techthrill_mcp_oauth_code_`.

- [ ] **Step 1: Write failing tests**

```php
// WellKnownTest
public function test_serves_authorization_server_metadata_at_the_root_well_known_path(): void
public function test_serves_protected_resource_metadata_for_the_mcp_path(): void
public function test_works_for_a_subdirectory_install(): void
public function test_ignores_other_paths_and_non_get_methods(): void
// RestRoutesTest
public function test_registers_the_four_routes(): void
public function test_register_endpoint_returns_201_for_valid_metadata_and_400_for_invalid(): void
public function test_token_endpoint_reads_form_encoded_bodies(): void
public function test_token_responses_are_not_cacheable(): void
// ConsentPageTest
public function test_logged_out_visitor_is_sent_to_login_with_redirect_back(): void
public function test_non_admin_sees_an_error_and_no_consent_form(): void
public function test_consent_screen_defaults_to_read_only_checkboxes_and_sends_frame_headers(): void
public function test_post_without_a_valid_nonce_is_rejected(): void
public function test_post_revalidates_hidden_fields(): void                  // tampered redirect_uri -> error page, no redirect
public function test_approve_redirects_with_a_code_and_deny_with_access_denied(): void
public function test_unknown_client_renders_an_error_page_and_never_redirects(): void
```

- [ ] **Step 2: Run tests** — Expected: FAIL.

- [ ] **Step 3: Implement.** Production `$http` for the fetcher is a closure around `wp_safe_remote_get`. The client IP for limiting comes from `REMOTE_ADDR` only (never `X-Forwarded-For`). `RestController` receives `array( $module->metadata(), 'challenge' )`.

- [ ] **Step 4: Run tests** — Expected: PASS. Also run `composer lint`.

- [ ] **Step 5: Commit** — `feat(oauth): REST routes, well-known documents and consent page`

---

### Task 14: Tokens page for OAuth grants

**Files:**
- Modify: `includes/Admin/TokensListTable.php`, `includes/Admin/Pages/Tokens.php`, `includes/Admin/assets/admin.css`
- Test: `tests/Unit/Admin/TokensStatusTest.php` (already extended in Task 2); add `tests/Unit/Admin/TokenKindLabelTest.php`

**Interfaces:**
- Produces: `TokenStatus::kindLabel( array $row ): string` returning `OAuth` for `kind` `oauth` and `Bearer` otherwise. The list table shows the name (`OAuth: <client>` for grants), a Type column with the label, and Status from `TokenStatus::of`. For an OAuth row the "Expires" column shows the refresh expiry. Revoke and Delete behave as for bearer tokens. A short help line under the table explains that OAuth grants renew automatically until revoked or until the refresh token expires after 30 days of disuse.
- Consumes: Task 2.

- [ ] **Step 1: Write failing tests**

```php
public function test_kind_label_for_oauth_bearer_and_null(): void
```

- [ ] **Step 2: Run tests** — Expected: FAIL.

- [ ] **Step 3: Implement.** Escape every client name on output (`esc_html`).

- [ ] **Step 4: Run tests** — Expected: PASS. Run `composer lint`.

- [ ] **Step 5: Commit** — `feat(oauth): show and revoke OAuth grants on the Tokens page`

---

### Task 15: Connect page, install links and the Advanced fallback

**Files:**
- Modify: `includes/Admin/Pages/Connect.php`, `includes/Admin/Snippets.php`, `includes/Admin/assets/admin.css`, `includes/Admin/assets/admin.js`
- Test: `tests/Unit/Admin/SnippetsTest.php` (add)

**Interfaces:**
- Produces: `Snippets::cursorLink( string $name, string $url ): string` returning `cursor://anysphere.cursor-deeplink/mcp/install?name=<rawurlencode name>&config=<base64 of {"url":"<url>"}>`; `Snippets::vscodeLink( string $name, string $url ): string` returning `vscode:mcp/install?` plus `rawurlencode` of `{"name":"<name>","type":"http","url":"<url>"}`; `Snippets::claudeCodeOAuth( string $slug, string $url ): string` returning `claude mcp add --transport http <slug> <url>`; `Snippets::codexOAuth( string $slug, string $url ): string` returning `codex mcp add <slug> --url <url>`. None takes or contains a token.
  The Connect page top card **Connect with one paste** (spec §6.1): endpoint URL field with Copy button, per-client steps, install links, and the status rows for HTTPS, plain permalinks, unreachable `/.well-known` (a one-off `wp_remote_get` of the root metadata URL cached in a transient for an hour, like the REST self-check) and loopback or private-network sites. The existing token form, snippets and new-token box move into a collapsed `<details>` titled **Advanced: connect with a bearer token**, with no behaviour change. When `Module::isAvailable()` is false the card shows what to fix instead of the steps and the Advanced section opens by default.

- [ ] **Step 1: Write failing tests**

```php
public function test_cursor_link_encodes_name_and_config(): void              // decode the config and compare with {"url":...}
public function test_vscode_link_is_url_encoded_json(): void
public function test_oauth_snippets_contain_no_token_or_header(): void
public function test_links_have_no_secret_material(): void                    // no 'ttm_', 'Bearer' or 'Authorization'
```

- [ ] **Step 2: Run tests** — Expected: FAIL.

- [ ] **Step 3: Implement.** Escape the URL with `esc_attr`/`esc_url` as appropriate; `esc_url` must allow the `cursor` and `vscode` protocols through the `$protocols` argument of `esc_url`.

- [ ] **Step 4: Run tests** — Expected: PASS. Run `composer lint`.

- [ ] **Step 5: Commit** — `feat(oauth): one-paste Connect card with Cursor and VS Code install links`

---

### Task 16: Documentation, version 1.2.0 and release gate

**Files:**
- Modify: `README.md`, `readme.txt`, `techthrill-mcp.php`, `tests/Unit/AutoloaderTest.php`, `docs/superpowers/specs/2026-10-15-techthrill-mcp-design.md` (remove "OAuth login flow" from the out-of-scope list and link this spec)

**Interfaces:**
- Version `1.2.0` in the plugin header, `TECHTHRILL_MCP_VERSION`, `readme.txt` stable tag and `AutoloaderTest`. Changelog entry `= 1.2.0 =` describing the one-paste connect flow, read-only consent default, OAuth grants on the Tokens page, Cursor and VS Code links and the Advanced bearer fallback. README sections: "Connect with one paste" per client, "Advanced: bearer token", security notes (what the consent screen grants, revocation, the `160.79.104.0/21` and localhost limits, `/.well-known` fallback), and the manual verification list below added to the checklist.

- [ ] **Step 1: Update docs and version** as above.

- [ ] **Step 2: Run `composer test` and `composer lint`** — Expected: green.

- [ ] **Step 3: Manual verification** on a public HTTPS site with pretty permalinks (and once on a local site for Claude Code and Codex):
  1. `curl -i -X POST <endpoint>` returns `401` with `WWW-Authenticate: Bearer resource_metadata="..."`; the protected-resource URL returns a document with exactly one authorization server; `/.well-known/oauth-authorization-server/<path>` returns the metadata, or the Connect card warns when the host blocks it.
  2. Claude web: Customize > Connectors > Add custom connector, paste the URL, approve with only the default read-only scopes, enable the connector from the **+** menu and call `site_info`; confirm a write tool is absent from the tool list, then re-connect with `files:write` ticked and confirm `file_write` appears.
  3. ChatGPT with Developer mode: add the connector with the URL, approve, call `site_info`.
  4. `claude mcp add --transport http NAME URL`, then `/mcp` or `claude mcp login NAME`; confirm it connects and refreshes after the access token's hour (shorten `ACCESS_TTL` temporarily to check).
  5. `codex mcp add NAME --url URL`, then `codex mcp login NAME`; confirm the connection.
  6. Cursor and VS Code install links open the editor with the server pre-filled; note the redirect URI each uses during login and confirm the allowlist accepts it (spec §10).
  7. Tokens page shows `OAuth: <client>` rows; Revoke one and confirm the client gets a `401` on its next call and cannot refresh.
  8. Negative checks: open `/authorize` with a redirect URI that is not registered (error page, no redirect), with `code_challenge_method=plain` (redirected error), reuse a code (fails), and deny on the consent screen (client sees `access_denied`).
  9. The Advanced bearer flow still works with the existing Claude Code and Codex snippets.

- [ ] **Step 4: Release gate.** Push the branch, confirm GitHub Actions is green on PHP 7.4, 8.1 and 8.3, merge to `main`, confirm CI on `main`. Tagging and publishing 1.2.0 is a separate decision and is not part of this plan.

- [ ] **Step 5: Commit** — `release: one-click connect, version 1.2.0`

---

## As built (differences from the plan)

- **Refresh token format:** `ttr_` plus 40 alphanumeric characters (the same generator as access tokens), not 43.
- **Option access:** codes use a small `OptionsGateway` interface (`WpOptionsGateway` in production, `InMemoryOptionsGateway` in tests) instead of injected callables, because pruning needs to list options by prefix.
- **Lazy object graph:** `Module` builds everything on first use from hooks, because `rest_url()` cannot be called at `plugins_loaded` (the rewrite object does not exist yet). The 401 challenge is resolved the same way.
- **Extra pure classes:** `Random`, `ClientName`, `Availability` (permalink, HTTPS and local-site checks for the Connect card) and `ConsentFlow` (every branch of the consent page, so only the WordPress input and output stay untested by unit tests).
- **Task 11:** `AuthorizeValidator` also refuses a `state` longer than 1024 characters. `Authorizer` takes only the code store.
- **Task 13:** the REST cookie-auth bypass applies to every `/techthrill-mcp/v1/oauth/` route, so REST lockdown plugins cannot block the token endpoint.
- **Task 15:** the tab script gained an optional `data-ttm-group` so two tab sets can share a page.
- **Not done by tests:** the manual verification in Task 16 (real Claude, ChatGPT, Claude Code, Codex, Cursor and VS Code logins, nginx and subdirectory installs) has to be run on a real public HTTPS site.
