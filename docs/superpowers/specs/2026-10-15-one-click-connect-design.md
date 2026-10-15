# TechThrill MCP – One-Click Connect Design Spec

**Date:** 2026-10-15
**Status:** Approved design, pending implementation plan
**Audience:** Plugin maintainers; the user-facing result ships in release 1.2.0
**Extends:** `docs/superpowers/specs/2026-10-15-techthrill-mcp-design.md` (the base design lists "OAuth login flow" as out of scope for v1; this spec lifts that exclusion)

## 1. Purpose

Today connecting a client means creating a bearer token on the Connect page and
pasting a command or JSON that contains the secret. That works for Claude Code
and Codex but not for the clients most site owners actually use: the Claude app
(Customize > Connectors > Add custom connector) and ChatGPT developer mode only
ask for a URL and then run an OAuth login.

The plugin becomes a small OAuth 2.1 authorization server for its own MCP
endpoint. The user pastes only the endpoint URL into the client, the client
sends the user's browser to the site, the logged-in administrator approves a
consent screen, and the client receives short-lived tokens. No secret is ever
copied or pasted.

Success looks like:

- In Claude (web, desktop, mobile), ChatGPT developer mode, Claude Code and
  Codex, adding the endpoint URL and approving one consent screen is the whole
  setup.
- The administrator chooses the permissions on that screen. The default is
  read-only (`db:read`, `files:read`).
- Every grant appears on the Tokens tab as `OAuth: <client>` and can be revoked
  there with the same effect as revoking a bearer token.
- The bearer-token flow keeps working unchanged as an Advanced fallback.

Non-goals (out of scope for 1.2.0):

- Anthropic-held credentials (the "connector holds a secret for you" model).
- Enterprise managed authentication (SSO or admin-provisioned connectors).
- Per-chat grants. Connectors are account-level in Claude and are toggled per
  chat from the **+** menu; a connect link cannot grant a single chat (§2.3).
- Token introspection and RFC 7009 revocation endpoints. Revocation happens on
  the Tokens tab.
- Confidential clients and `client_secret` authentication. All clients are
  public and use PKCE.

## 2. Research findings

These facts were checked against the vendor documentation (sources in §11) and
drive every decision below.

### 2.1 What each client needs

| Client | How the user connects | Redirect URI the plugin must accept |
|---|---|---|
| Claude hosted apps (claude.ai, desktop, mobile) | Customize > Connectors > Add custom connector, paste URL | `https://claude.ai/api/mcp/auth_callback` |
| ChatGPT | Developer mode on, add connector with URL | `https://chatgpt.com/connector/oauth/{callback_id}` |
| Claude Code | `claude mcp add --transport http NAME URL`, then `/mcp` or `claude mcp login NAME` | loopback, `http://localhost:PORT/...` |
| Codex | `codex mcp add NAME --url URL`, then `codex mcp login NAME` | loopback, `http://localhost:PORT/...` |
| Cursor, VS Code | one-click install link that carries the URL only | loopback (verify, §10) |

### 2.2 Requirements the Claude documentation states

- A `401` response on the MCP endpoint is the trigger. A `WWW-Authenticate`
  header on a `200` response is ignored.
- PKCE with `S256` is required.
- The `/token` endpoint must accept form-encoded bodies, and refresh tokens must
  be supported.
- Only the first entry of `authorization_servers` in the protected-resource
  document is used.
- Client ID Metadata Documents (CIMD) are used only when the authorization-server
  metadata advertises `client_id_metadata_document_supported: true` and lists
  `none` in `token_endpoint_auth_methods_supported`. Dynamic Client Registration
  (DCR) remains the fallback for clients that do not use CIMD.
- Anthropic connects from `160.79.104.0/21`. A site on `localhost` or a private
  network is unreachable from claude.ai, so it can only be used with Claude Code
  or Codex running on the same machine.

### 2.3 What is not possible

- No deep link adds a connector to a Claude account, so there is no
  "click here to connect" for Claude. The owner's first idea, a link that grants
  one specific chat, is impossible because connectors are account-level and are
  enabled per chat from the **+** menu.
- The second idea, a link that copies a ready-made prompt, is unnecessary once
  OAuth exists: the user pastes a URL and approves one screen.
- Cursor and VS Code do have install deep links; they are used in §6.3.

## 3. Architecture

### 3.1 Overview

All new code lives in `includes/OAuth/` under the namespace
`TechThrill\MCP\OAuth`. Pure classes take their dependencies (clock, stores,
HTTP function, `TokenManager`) through constructors so PHPUnit tests run without
WordPress, like the rest of the plugin. Thin WordPress adapters register routes
and hooks.

```
Client                         Browser (administrator)              Site
  |  POST /mcp (no token)  -------------------------------------->  401 + WWW-Authenticate
  |  GET protected-resource  ------------------------------------>  {authorization_servers:[issuer]}
  |  GET /.well-known/oauth-authorization-server/<path>  -------->  metadata
  |  (CIMD fetch by the site, or POST /oauth/register)
  |  open authorization_endpoint ---> login (if needed) --------->  consent page
  |                                     approve, choose scopes  --> 302 redirect_uri?code=..&state=..
  |  POST /oauth/token (code + PKCE verifier)  ------------------>  access + refresh token
  |  POST /mcp  Authorization: Bearer ttm_...  ------------------->  existing Authenticator
```

### 3.2 Components

| Component | Responsibility |
|---|---|
| `OAuth\Metadata` | Builds the protected-resource document, the authorization-server document and the `WWW-Authenticate` value. |
| `OAuth\WellKnown` | `init` hook that serves the two documents at the root `/.well-known/...` paths. |
| `OAuth\RedirectPolicy` | The redirect URI allowlist (§4.3). |
| `OAuth\Pkce` | `S256` challenge and verifier checks. |
| `OAuth\ClientStore` (`OptionClientStore`) | Registered and cached clients, with pruning. |
| `OAuth\ClientRegistrar` | DCR for public clients, rate limited. |
| `OAuth\ClientMetadataFetcher` | CIMD fetch and validation, SSRF-safe. |
| `OAuth\AuthorizeValidator`, `OAuth\Authorizer` | Parse and validate `/authorize` requests; build the approval and denial redirects. |
| `OAuth\AuthorizationCodeStore` | Single-use codes with a 60 second life. |
| `OAuth\TokenEndpoint` | The two grants, error responses and PKCE checks. |
| `OAuth\AttemptLimiter` | Per-IP limits on registration and token calls. |
| `OAuth\RestRoutes`, `OAuth\ConsentPage` | WordPress adapters: REST routes and the `admin-post.php` consent page. |
| `Auth\TokenManager` (extended) | Creates and rotates OAuth tokens in the existing tokens table. |

### 3.3 Why a consent page on `admin-post.php`

Browser navigations carry the WordPress login cookie but not a REST nonce, so
the authorization endpoint cannot be a REST route. It is
`admin-post.php?action=techthrill_mcp_authorize`. A logged-out visitor is sent to
`wp-login.php` with `redirect_to` set to the same URL and returns to the consent
page after logging in. Approval is a nonce-protected POST.

## 4. Protocol design

### 4.1 Discovery

- `POST /wp-json/techthrill-mcp/v1/mcp` without a valid token answers `401`
  with the existing JSON-RPC error body and
  `WWW-Authenticate: Bearer resource_metadata="<url>"`. When a token is present
  but invalid, expired or revoked the header also carries
  `error="invalid_token"` so clients refresh or reconnect.
- `<url>` is the REST route
  `/wp-json/techthrill-mcp/v1/oauth/protected-resource`. It returns
  `{ resource, authorization_servers: [issuer], scopes_supported,
  bearer_methods_supported: ["header"], resource_name }`. Exactly one
  authorization server is listed.
- The issuer is `rest_url( 'techthrill-mcp/v1' )`. Authorization-server metadata
  is served at the REST route `/oauth/authorization-server` and, for clients
  that follow RFC 8414, at the root path
  `/.well-known/oauth-authorization-server/<issuer path>`. The protected-resource
  document is likewise served at
  `/.well-known/oauth-protected-resource/<resource path>`. `WellKnown` answers
  both from an `init` hook that matches the request path before WordPress
  routing, so it works on subdirectory installs.
- Authorization-server document: `issuer`, `authorization_endpoint`,
  `token_endpoint`, `registration_endpoint`, `response_types_supported:
  ["code"]`, `grant_types_supported: ["authorization_code", "refresh_token"]`,
  `code_challenge_methods_supported: ["S256"]`,
  `token_endpoint_auth_methods_supported: ["none"]`, `scopes_supported` (the five
  plugin scopes), `client_id_metadata_document_supported: true`.
- **Fallback:** some hosts block `/.well-known` (an nginx rule that denies dot
  paths, a security plugin). The REST routes above still work for clients that
  follow the `resource_metadata` URL, and the bearer token form on the Connect
  page works everywhere. The Connect status card tests the root
  `/.well-known` URL and shows a warning row when it is not reachable.
- OAuth needs pretty permalinks. With plain permalinks `rest_url()` contains a
  query string, which is not a valid issuer; the Connect page then hides the
  one-paste card and explains why.
- Discovery is advertised only over HTTPS, or on a loopback host for local
  testing.

### 4.2 Client identification

Two mechanisms, both for public clients only:

- **Client ID Metadata Documents.** When `client_id` is an `https://` URL with a
  path, the plugin fetches it with `wp_safe_remote_get`, accepting only a `200`
  `application/json` body of at most 10 KB, with no redirects, a 5 second
  timeout, a non-IP host, and a document whose `client_id` equals the URL it was
  fetched from. `client_name` and `redirect_uris` come from the document, and
  every redirect URI must pass the allowlist. The result is cached for one hour.
- **Dynamic Client Registration.** `POST /oauth/register` with a JSON body
  accepts `client_name`, `redirect_uris` (1 to 5, each on the allowlist),
  `grant_types` (subset of the two supported), `response_types: ["code"]` and
  `token_endpoint_auth_method: "none"`. Anything else is rejected with
  `invalid_client_metadata`. The response is `201` with a generated
  `client_id` (`ttc_` plus 24 random characters) and the registered metadata; no
  secret is issued.

Registration is limited to 10 per IP per hour. Registered clients that never
completed a grant are deleted after 30 days, and the store is capped at 200
clients (oldest unused first), pruned on registration and by the daily cleanup
event.

### 4.3 Redirect URI allowlist

A redirect URI is acceptable when it has no fragment and no user info and
matches one of:

| Rule | Match |
|---|---|
| Claude hosted apps | exactly `https://claude.ai/api/mcp/auth_callback` |
| ChatGPT | `https://chatgpt.com/connector/oauth/` followed by one or more of `A-Z a-z 0-9 _ -` and nothing else |
| Loopback | scheme `http`, host exactly `localhost` or `127.0.0.1`, any port, any path |

At `/authorize` the `redirect_uri` must equal one of the client's registered
URIs byte for byte. Nothing else is ever redirected to.

### 4.4 `/authorize`

Parameters: `response_type=code`, `client_id`, `redirect_uri`, `code_challenge`,
`code_challenge_method=S256`, `state`, optional `scope`, optional `resource`.

Validation order and failure handling:

1. Unknown client, unknown or non-matching `redirect_uri`: render an error page.
   Never redirect, because the redirect target is not trusted.
2. Everything after that redirects to the (trusted) URI with `error` and
   `state`: `invalid_request` (missing or malformed `code_challenge`, method
   other than `S256`, `response_type` other than `code`), `invalid_target`
   (`resource` present and not equal to the endpoint URL), `invalid_scope`
   (unknown scope names), `access_denied` (the administrator pressed Deny).
3. The visitor must be logged in and hold `manage_options`. A logged-in user
   without it sees an error page, not a consent screen.

The consent page shows the client name, the host of the redirect URI, what
"approve" means (the client will act as the signed-in administrator), the five
scopes as checkboxes with `db:read` and `files:read` checked and the others
unchecked, whatever the client requested, and Approve and Deny buttons. It sends
`X-Frame-Options: DENY`, `Content-Security-Policy: frame-ancestors 'none'` and
`Cache-Control: no-store`. Approval requires the nonce and at least one scope.
The POST re-runs the full validation from the hidden request fields; nothing
from the first render is trusted.

On approval the plugin issues a single-use authorization code and redirects to
`redirect_uri?code=<code>&state=<state>`. The code is stored hashed with the
client ID, redirect URI, PKCE challenge, resource, granted scopes, user ID and a
60 second expiry. The code is the only secret that ever appears in a URL.

### 4.5 `/token`

`POST /oauth/token`, `Content-Type: application/x-www-form-urlencoded`. Public
clients, no client authentication. Responses carry `Cache-Control: no-store` and
`Pragma: no-cache`. Errors are `{ "error": "...", "error_description": "..." }`
as in RFC 6749: `invalid_request`, `invalid_client`, `invalid_grant`,
`unsupported_grant_type`, `invalid_scope`, with `400` (or `401` for
`invalid_client`) and `429` for the rate limit.

- **`authorization_code`**: requires `code`, `redirect_uri`, `client_id`,
  `code_verifier` (43 to 128 characters of `A-Z a-z 0-9 - . _ ~`) and, when sent,
  a `resource` equal to the endpoint URL. The code is consumed atomically
  before any other check, so a failed attempt still burns it. Checks: client and
  redirect URI equal the stored values, the code has not expired, and
  `base64url( sha256( code_verifier ) )` equals the stored challenge
  (`hash_equals`). Success returns `{ access_token, token_type: "Bearer",
  expires_in: 3600, refresh_token, scope }`.
- **`refresh_token`**: requires `refresh_token` and `client_id`; an optional
  `scope` may only narrow the grant. The refresh token is single use: success
  rotates both tokens in place and returns a new pair. Presenting an old
  refresh token fails with `invalid_grant`.

### 4.6 Token storage

OAuth tokens reuse the existing tokens table and the existing bearer format, so
`Authenticator`, `Scopes`, rate limiting, the admin-still-an-administrator check
and the audit log work with no changes.

New nullable columns on `{prefix}techthrill_mcp_tokens`, added by `dbDelta`
through the existing `Activator::maybeUpgrade` path:

| Column | Type | Meaning |
|---|---|---|
| `kind` | `varchar(10) NULL` | `NULL` or `bearer` for tokens created on the Connect page, `oauth` for grants |
| `client_id` | `varchar(255) NULL` | OAuth client the grant belongs to |
| `refresh_hash` | `char(64) NULL`, indexed | SHA-256 of the current refresh token (`ttr_` plus random) |
| `refresh_expires_at` | `datetime NULL` | Refresh token expiry, 30 days from issue |

The approved design lists the first three; `refresh_expires_at` is a fourth
column added because `expires_at` has to carry the one-hour access expiry and
the refresh lifetime needs its own field.

One row exists per grant. On refresh the row's `lookup`, `hash`, `expires_at`,
`refresh_hash` and `refresh_expires_at` are replaced in place, so the row ID,
name, scopes, user and creation time stay stable and the Tokens tab shows one
line per connected client. Revoking the row (`revoked_at`) ends both tokens.
Access tokens last one hour; refresh tokens 30 days. The `name` is
`OAuth: <client name>`.

Tokens and codes are never written to logs, error messages or URLs. Only
SHA-256 hashes are stored.

### 4.7 Scopes

Same five scopes, same meaning. The consent screen defaults to read-only
(`db:read`, `files:read`) and the administrator widens it deliberately. Scope
names in `scope` request parameters are validated but only pre-describe what the
client wants; the checkboxes decide. The token response reports the granted
scopes.

## 5. Security

| Threat | Mitigation |
|---|---|
| Authorization code interception | PKCE `S256` is mandatory; `plain` and missing challenges are rejected. Codes live 60 seconds and are single use. |
| Open redirect, redirect to an attacker | Exact match against registered URIs; registration only accepts allowlisted URIs; no redirect on client or URI errors. |
| Phishing consent ("which app is this?") | The page shows the client name and the redirect host, and warns that the client will act as the signed-in administrator. Client names are plain text. |
| Clickjacking | `frame-ancestors 'none'` and `X-Frame-Options: DENY`. |
| CSRF on approval | WordPress nonce on the POST. |
| Over-broad grants | Read-only defaults; the administrator picks scopes. |
| SSRF through CIMD | `wp_safe_remote_get`, HTTPS only, no redirects, no IP-literal hosts, 10 KB cap, 5 second timeout, cached. |
| Registration or token flooding | Per-IP limits, client cap and pruning. |
| Stolen refresh token | Single use with rotation; 30 day life; revocation on the Tokens tab. |
| A demoted administrator | The existing Authenticator check refuses the token when the owner no longer has `manage_options`. |
| Secret leakage | No secret in any URL except the authorization code; no token or code in any log or error. |

Anyone with `manage_options` can approve, exactly as anyone with that capability
can create a bearer token today. Safe mode, the path jail and the SQL classifier
apply to OAuth tokens the same way, because they are enforced in the tools.

## 6. Admin UI

### 6.1 Connect page

The top card, **Connect with one paste**, shows:

- the endpoint URL in a read-only field with a Copy button;
- numbered steps per client, in tabs or an accordion:
  - **Claude:** Customize > Connectors > Add custom connector, paste the URL,
    approve in the browser, then enable it from the **+** menu in a chat.
  - **ChatGPT:** turn on Developer mode, add a connector, paste the URL and
    approve in the browser (the exact menu names are taken from the ChatGPT
    documentation at implementation time).
  - **Claude Code:** `claude mcp add --transport http NAME URL`, then `/mcp` or
    `claude mcp login NAME`.
  - **Codex:** `codex mcp add NAME --url URL`, then `codex mcp login NAME`.
  - **Cursor** and **VS Code:** install links (§6.3).
- warnings, each as a row on the existing status card: not HTTPS, plain
  permalinks, `/.well-known` unreachable, a loopback or private-network site
  ("Claude's hosted apps and ChatGPT cannot reach this site; use Claude Code or
  Codex on this machine").

The existing token form, snippets and one-time token box move under a collapsed
**Advanced: connect with a bearer token** section, unchanged.

### 6.2 Tokens page

OAuth rows show `OAuth: <client>` with a type badge (Bearer or OAuth). The status
column for an OAuth row is Active while the refresh token is valid and the row
is not revoked (an expired access token alone is normal), Expired once the
refresh token has expired, and Revoked. Revoke and Delete work as for bearer
tokens.

### 6.3 Install links

Both links contain only the endpoint URL and a name, never a secret.

- Cursor: `cursor://anysphere.cursor-deeplink/mcp/install?name=<name>&config=<base64( json )>`
  where the JSON is `{"url":"<endpoint>"}`.
- VS Code: `vscode:mcp/install?<urlencode( json )>` where the JSON is
  `{"name":"<name>","type":"http","url":"<endpoint>"}`.

## 7. Compatibility and migration

- Fresh installs and upgrades both reach the new columns through `dbDelta`;
  existing rows read as bearer tokens.
- No setting is added. OAuth is always available when the site is served over
  HTTPS with pretty permalinks; tokens still require an administrator's
  explicit approval on every grant.
- Multisite: per site, like tokens.
- PHP 7.4 syntax throughout; no new runtime dependencies.

## 8. Error handling

| Situation | Response |
|---|---|
| MCP call without or with a bad token | HTTP 401, JSON-RPC `-32001`, `WWW-Authenticate` header |
| `/authorize` with an unknown client or redirect URI | HTML error page, no redirect |
| Any other `/authorize` error | Redirect to the client with `error` and `state` |
| `/token` error | JSON error body, 400 / 401 / 429 |
| `/register` invalid metadata | 400 `invalid_client_metadata` |
| Rate limit | 429 with `Retry-After` |
| Metadata for an HTTP (non-loopback) site | Documents are served but the Connect card warns; clients that require HTTPS will refuse |

## 9. Testing

- Unit tests (no WordPress) for PKCE, the redirect allowlist, metadata documents,
  client registration and pruning, CIMD validation with a fake HTTP function, the
  authorization validator and authorizer, the code store, both grants including
  replay, expiry and PKCE failures, and the token manager's OAuth methods.
- Adapter classes are thin and covered by the existing REST stub harness where
  practical, and by the manual checklist otherwise.
- `composer test` and `composer lint` stay green on PHP 7.4, 8.1 and 8.3.
- Manual verification per client is in the implementation plan, Task 16.

## 10. Open items to verify before release

- Redirect URIs used by Cursor and VS Code during OAuth. If either uses a
  non-loopback URI, add it to `RedirectPolicy` in a point release.
- That ChatGPT developer mode accepts the CIMD and DCR combination as
  documented, and that the Claude app prefers CIMD and falls back to DCR as
  documented.
- The `/.well-known` handler on nginx and on a subdirectory install.

## 11. Sources

- Claude Code MCP: https://code.claude.com/docs/en/mcp
- Claude connector authentication: https://claude.com/docs/connectors/building/authentication
- Claude custom connectors: https://claude.com/docs/connectors/custom/add-unlisted
- MCP authorization specification: https://modelcontextprotocol.io/specification/latest/basic/authorization
- ChatGPT MCP and developer mode: https://learn.chatgpt.com/docs/extend/mcp
- OpenAI Apps SDK authentication: https://developers.openai.com/apps-sdk/build/auth
- Cursor install links: https://cursor.com/docs/mcp/install-links
- VS Code MCP servers: https://code.visualstudio.com/docs/agent-customization/mcp-servers
