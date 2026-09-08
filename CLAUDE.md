# CLAUDE.md — SiteAgent for Aura

This file provides context and conventions for AI assistants working in this repository.

---

## Project Overview

**SiteAgent for Aura** is a WordPress plugin that acts as a remote site management agent for the [Aura Infrastructure Hub](https://my-aura.app). It exposes secure REST API endpoints that allow Aura to monitor site health, apply updates (core, plugins, themes, translations), and perform database maintenance.

- **Language:** PHP 7.4+
- **Platform:** WordPress 6.2+
- **Auth:** Three-layer (WordPress Application Password + Aura Site Token + optional IP Whitelist)
- **REST Namespace:** `aura/v1`
- **License:** GPLv2 or later
- **Text Domain:** `digitizer-site-worker`
- **WordPress.org Slug:** `digitizer-site-worker`

---

## Repository Structure

```
digitizer-site-worker/                                      # Repo root (development)
├── CLAUDE.md                                # AI assistant instructions (dev only)
├── README.md                                # GitHub readme (dev only)
├── LICENSE                                  # GPLv2 license text (dev only)
├── assets/                                  # WordPress.org plugin page assets (NOT shipped)
│   ├── aura_logotype.png                    # Logo asset
│   ├── banner-772x250.svg                   # Standard banner
│   ├── banner-1544x500.svg                  # Retina banner
│   ├── icon-128x128.svg                     # Standard icon
│   └── icon-256x256.svg                     # Retina icon
└── digitizer-site-worker/                                  # ← Clean plugin folder (zip this for installation)
    ├── digitizer-site-worker.php                      # Plugin entry point, activation/deactivation hooks
    ├── uninstall.php                        # Cleanup on uninstall (removes all options)
    ├── readme.txt                           # WordPress.org plugin readme
    └── includes/
        ├── class-aura-worker.php            # Main orchestrator — admin menu, settings, wiring
        ├── class-aura-worker-api.php        # REST API route registration and handlers
        ├── class-aura-worker-updater.php    # Update operations (core, plugins, themes, translations, DB)
        ├── class-aura-worker-security.php   # Three-layer authentication and permission callbacks
        ├── class-aura-worker-health.php     # Site health report (PHP/DB/disk, error-log tail)
        ├── class-aura-worker-rollback.php   # Zip backup + restore of plugin directories
        ├── class-aura-worker-magic-link.php # Short-lived one-time admin login links
        ├── class-aura-worker-mcp.php        # MCP server + tool registration
        ├── class-aura-worker-tools.php      # MCP tool base + registry
        └── tools/                           # Individual MCP tools (site-context, update-plugin-safely, ...)
```

To create an installable ZIP: `cd` to the repo root and run `zip -r digitizer-site-worker.zip digitizer-site-worker/`.

---

## Architecture

### Class Responsibilities

| Class | File | Role |
|-------|------|------|
| `Aura_Worker` | `includes/class-aura-worker.php` | Orchestrator — creates Security and API instances, registers admin menu and settings |
| `Aura_Worker_API` | `includes/class-aura-worker-api.php` | Registers all REST routes under `aura/v1`, handles request/response logic |
| `Aura_Worker_Updater` | `includes/class-aura-worker-updater.php` | Wraps WordPress Upgrader classes for core/plugin/theme/translation/DB updates |
| `Aura_Worker_Security` | `includes/class-aura-worker-security.php` | Implements IP whitelist, domain whitelist, site token verification, and capability checks |
| `Aura_Worker_Health` | `includes/class-aura-worker-health.php` | Builds site health report (PHP/DB/disk, recent error-log tail) |
| `Aura_Worker_Rollback` | `includes/class-aura-worker-rollback.php` | Zip backup + restore of plugin directories |
| `Aura_Worker_Magic_Link` | `includes/class-aura-worker-magic-link.php` | Short-lived one-time admin login links |
| `Aura_Worker_MCP` | `includes/class-aura-worker-mcp.php` | MCP server endpoint + tool registration |
| `Aura_Worker_Tools` | `includes/class-aura-worker-tools.php` | MCP tool base class (`Aura_Tool_Base`) + registry; individual tools live in `includes/tools/` |
| `Aura_Worker_Unbind` | `includes/class-aura-worker-unbind.php` | The site-unbind marker (`aura_worker_unbound`) + Phase B cleanup: `read`/`is_set`/`is_set_strict`, `write_under_claim`, `delete_under_claim`, `refusal`, `status_fragment`, `leftovers`, `cleanup`, `maybe_finish` |

### Initialization Flow

1. `digitizer-site-worker.php` defines `AURA_WORKER_*` constants and loads all class files
2. `aura_worker_init()` runs on `plugins_loaded` — creates `Aura_Worker` and calls `init()`
3. `init()` creates `Aura_Worker_Security`, passes it to `Aura_Worker_API`
4. `Aura_Worker_API` internally creates its own `Aura_Worker_Updater` instance
5. REST routes are registered on `rest_api_init`
6. Admin settings page is registered on `admin_menu` / `admin_init` (admin only)

### Security Layers

Every REST request passes through three checks in order:

1. **IP Whitelist** (`check_ip_whitelist`) — If IPs are configured in settings, the client IP must match. Uses `REMOTE_ADDR` only (proxy headers are not trusted).
2. **Domain Whitelist** (`check_domain_whitelist`) — If domains are configured, the request's `Origin` or `Referer` header must match.
3. **Aura Site Token** (`check_aura_token`) — `X-Aura-Token` header is SHA-256 hashed and compared with `hash_equals()` against the stored hash (timing-safe). The raw token is never stored. Per-IP brute-force throttling blocks after `MAX_TOKEN_FAILURES` failures within `TOKEN_FAILURE_WINDOW`. Legacy plaintext tokens are migrated to a hash on first successful auth.
4. **WordPress Capability** — All endpoints require `manage_options` (or the relevant `update_*` capability).
5. **Unbind refusal (2.13.0)** — `Aura_Worker_Security::refuse_if_unbound()` runs on every *mutating* permission callback, **after** `validate_request()` (a caller who cannot prove it holds the token learns nothing about the binding). While the marker `aura_worker_unbound` is set, every mutation answers `403 aura_site_unbound`; reads keep working, and `POST /aura/v2/rules` is exempt so the site can still be told things — including that it is unbound. The marker is read **uncached** and an unreadable marker counts as unbound (`is_set()` is TRUE on `WP_Error`), so a database blip cannot re-open writes on a disconnected site. `Aura_Worker_Grant::verify()` and the core-REST seam (`rest_request_before_callbacks`) apply the same refusal — the latter identifies *the departed binding* by the marker's Application Password UUIDs or the site-token run-as path, never by whatever credentials are currently live.

### Magic-Link Connect Signing

The public `POST /connect` endpoint is protected by an HMAC handshake instead of being open:

1. When an admin clicks **Connect to Aura**, the plugin mints a one-time `connect_secret`, stores it in the `aura_magic_<id>` transient, and sends it to the dashboard alongside `magic_id` / `site_url`.
2. The dashboard issues the site token and calls `/connect` with `{ magic_id, token, dashboard_url, timestamp, signature }` plus the optional `grant_pubkey` and `client`. The signed message is the newline-joined `magic_id`, `token`, `dashboard_url`, `timestamp`, followed by two optional lines — each appended **iff its parameter is non-empty**, in this order:
   - the gateway Ed25519 public key, as a **bare** line (the shipped 2.x format, so 4- and 5-line callbacks keep validating unchanged);
   - the Aura client id, as `client:<id>` — **labelled**, so a public key moved into the `client` field recomputes to a different signature.
3. The plugin re-derives the signature (`Aura_Worker_Magic_Link::sign_connect_payload()`), rejects stale timestamps (±5 min) and bad signatures, then stores only the **hash** of the token. The dashboard keeps the raw copy.
4. **One handler per magic link (2.10.2).** Before anything else, the handler claims `aura_magic_claim_<magic_id>` with a **conditional INSERT** (`INSERT … SELECT … WHERE NOT EXISTS`, decided by `wp_options`' unique key on `option_name` — not `add_option()`, which upserts and so cannot serialise two callbacks); a second request for the same magic link is answered `409 aura_connect_in_progress`. There is no timed takeover — the claim is released by its holder on every exit (each refusal, the store-failure 500, and success after the transient is consumed), and a dead handler's orphan row is swept by age after an hour in `Aura_Worker_Rules::note_expired()`.
5. **A connect never runs over a live foreign binding (2.16.0, Ruling P75).** After the site claim is taken and the departed binding's Phase-B debt is settled, and **before any write**, the handler reads the door's binding record. If it says `bound` to a **different** client — or to a client that cannot be proven the same, which includes a clientless connect onto a bound site — the answer is `409 aura_site_bound` ("This site is bound to another Aura client; unbind it first") and **nothing** is written: no token, dashboard, client sentinel, grant key or connect user. A rebind is therefore an **unbind followed by a connect**: the unbind rotates the generation to `unbound`, retiring the departed client's hold queue and log cursor, and only then may another client connect. A site that is `unbound`, or that nobody has ever bound, binds normally; the same client re-saving is the connect it always was.
6. **The writes are verified, not assumed (2.10.2).** The stored token row is read back from the database; a client-bearing connect then writes a seq-0 **binding sentinel** into `aura_worker_ruleset` (client + the hash of the token just installed) in place of clearing it, and verifies that row too. If either did not land the connect answers `500 aura_connect_store_failed` — the magic link is left unconsumed so the same variant can be retried. A connect that names no client clears the ruleset store exactly as before.

---

## REST API Endpoints

All routes are under `/wp-json/aura/v1/`.

### Read Endpoints (require `manage_options`)

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/status` | Full site health: WP/PHP/MySQL versions, plugins, themes, disk usage, DB info |
| `GET` | `/updates` | Available updates for core, plugins, themes, translations. Add `?refresh=1` to force fresh check |

### Write Endpoints (require `manage_options`)

| Method | Endpoint | Parameters | Description |
|--------|----------|------------|-------------|
| `POST` | `/update/core` | — | Update WordPress core |
| `POST` | `/update/plugin` | `plugin` (required, string) | Update a specific plugin by file path (e.g. `akismet/akismet.php`) |
| `POST` | `/update/theme` | `theme` (required, string) | Update a specific theme by slug |
| `POST` | `/update/translations` | — | Bulk update all translations |
| `POST` | `/update/database` | — | Run `wp_upgrade()` / `dbDelta()` |

### `aura/v2` — operator ruleset and unbind

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/rules` | Aura's signed operator ruleset. Carrying `unbind: true` it ends this site's binding instead (#434) |

**Unbind (2.13.0, #434).** Phase A writes the marker under the site claim; Phase B revokes
the managed Application Password(s), clears the ruleset store, gateway key and connect
bookkeeping, and deletes the site token **last**, only on `final: true`. Idempotent, one
fixed order, continues past a failed step; an interrupted unbind finishes itself from
`init` (`maybe_finish()`, throttled 300 s, under the claim, never deleting a token that
differs from the marker's). Two request forms: the **enveloped** (signed) one, and the
**bare** `{ unbind, client, site_ref, seq, final }` body accepted only by a site with no
usable gateway key (a keyed site answers `400 aura_ruleset_rejected`). Both answer
`{ success, seq, unbound, cleanup_complete, leftovers[] }` — `leftovers` names what is
still owed (`app_passwords`, `options`, `ruleset`, `grant_pubkey`); empty means only the
shared token was outstanding, and an *absent* list means "something may be owed"
(`Aura_Worker_API::unbind_response()` defaults to the full fail-closed list).

Both rebind paths — the magic-link `/connect` callback and **Regenerate Token** — run
`finish_before_rebind()` (Phase B steps 1–4) *before* installing a new token, and delete
the marker only after the replacement binding is installed and read back; a failed swap
leaves the marker still refusing the old binding. The settings screen shows
"Disconnected by Aura at …" and offers **Remove remaining Aura data**
(`wp_ajax_aura_worker_remove_aura_data`), which **repairs** a malformed marker under the
claim rather than deleting it, then tears the site down through the ordinary path.

**Error codes (2.13.0).** `aura_site_unbound` (403), `aura_site_busy` (503, retryable),
`aura_ruleset_client_mismatch` (409 — now checked on the bare form too),
`aura_unbind_store_failed` (500), `aura_unbind_marker_malformed` (500). From the admin
teardown: `aura_unbind_incomplete` (409, with `leftover[]`/`unattributed[]`),
`aura_unbind_unreadable` (409 — the record could not be *read*, distinct from an
incomplete cleanup: nothing is claimed or changed), `aura_unbind_unrepairable` (409),
`aura_not_unbound` (409), `aura_unbind_marker_stuck` (500). Pre-existing ruleset codes are
unchanged: `aura_ruleset_rejected` (400), `no_gateway_key` (412 — skipped for the bare
form and for an already-unbound site), `aura_ruleset_wrong_site` (403),
`aura_ruleset_stale` (409), `aura_ruleset_contended` (503), `aura_ruleset_store_failed` (500).

**`GET /status` fragments (2.13.0).** `unbound: { at, site_ref }` while the marker is set,
and `app_password_probe_unproven: { count, at, owner }` (bounded, saturating) when a probe
could not prove an Application Password gone. Both are always JSON **objects** — the key's
presence is the signal, and the shape must not change with its contents.

---

## WordPress Options

| Option Key | Description |
|------------|-------------|
| `aura_worker_site_token` | 32-char alphanumeric token for API auth |
| `aura_worker_allowed_ips` | Newline-separated IP whitelist (empty = allow all) |
| `aura_worker_allowed_domains` | Newline-separated domain whitelist (empty = allow all) |
| `aura_worker_dashboard_url` | Aura dashboard base URL (magic-link / callback target) |
| `aura_worker_activated` | Activation timestamp |
| `aura_worker_version` | Plugin version at activation |
| `aura_worker_ruleset` | The signed operator ruleset this site holds (seq, client, site_ref) |
| `aura_worker_grant_pubkey` | The gateway's Ed25519 public key; empty = an unkeyed (manual) site |
| `aura_worker_unbound` | **2.13.0** — the unbind marker: `{ at, site, site_ref, client, seq, app_password_uuids[], app_password_users{} }`. Autoload `no`, read uncached; its presence refuses every mutation |
| `aura_worker_app_password_probe_unproven` | **2.13.0** — bounded `{ count, at, owner }`: a probe that could not prove an Application Password gone |

All options are cleaned up in `uninstall.php`.

---

## Code Conventions

### PHP Style

- Follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)
- Tabs for indentation (not spaces)
- Yoda conditions are acceptable but not required
- All files must start with `if ( ! defined( 'ABSPATH' ) ) { exit; }` guard
- Use WordPress i18n functions (`__()`, `esc_html_e()`) with text domain `digitizer-site-worker`

### Naming

| Kind | Convention | Example |
|------|-----------|---------|
| Classes | `Aura_Worker_*` prefix | `Aura_Worker_Security` |
| MCP tool classes | `Aura_Tool_*` (extend `Aura_Tool_Base`) | `Aura_Tool_Site_Context` |
| Files | `class-aura-worker-*.php` | `class-aura-worker-api.php` |
| Functions (global) | `aura_worker_*` prefix | `aura_worker_activate` |
| Options | `aura_worker_*` prefix | `aura_worker_site_token` |
| Constants | `AURA_WORKER_*` | `AURA_WORKER_VERSION` |
| REST namespace | `aura/v1` | — |
| Settings group | `aura_worker_settings` | — |

### Security Rules

- **Never store secrets in plaintext** — site tokens and credentials should be hashed before storage
- **Always use `$wpdb->prepare()`** for any SQL query with dynamic values, even trusted ones like `$wpdb->prefix`
- **Always use `sanitize_text_field()`** or appropriate sanitizer on user input
- **Always use `esc_attr()`, `esc_html()`, `esc_url()`** for output escaping
- **Use `hash_equals()`** for all token/secret comparisons (timing-safe)
- **Validate WordPress Upgrader return values thoroughly** — `Plugin_Upgrader::upgrade()` can return `true`, `false`, `null`, `WP_Error`, or an array depending on the outcome. Always check for `is_wp_error()`, `false === $result`, and `null === $result` before assuming success.
- **Use `wp_unslash()` before `sanitize_*()`** on `$_SERVER` values

### Error Handling

- Return structured arrays from updater methods: `array( 'success' => bool, 'message' => string )` or `array( 'success' => false, 'error' => string )`
- REST handlers wrap results in `WP_REST_Response` with appropriate HTTP status codes (200, 404, 500)
- Use `WP_Error` objects in security/permission callbacks — WordPress REST API will convert these to proper error responses

### Dependency Loading

- Use `require_once` for WordPress admin includes (they are not always loaded in REST context)
- Always check `function_exists()` before requiring admin files (e.g., `get_plugins`, `get_core_updates`)
- The `load_upgrade_dependencies()` method in the Updater class centralizes all upgrade-related includes

---

## Known Issues

Most issues from the initial code review were fixed in v1.2.0 and v2.0.0. Remaining items:

1. ~~**Token stored in plaintext**~~ — Resolved in v2.0.0. Tokens are stored as a SHA-256 hash; the raw value is shown once via a reveal transient at generation/regeneration. Legacy plaintext tokens migrate on first successful auth.
2. ~~**No token rotation UI**~~ — Resolved in v2.0.0. **Regenerate Token** button on the settings page (`ajax_regenerate_token`) rotates the token and disconnects the dashboard until reconnected.
3. ~~**No rate limiting on token validation**~~ — Resolved in v2.0.0. Per-IP transient-based failed-attempt throttling (`MAX_TOKEN_FAILURES` / `TOKEN_FAILURE_WINDOW`) returns HTTP 429 once exceeded.
4. ~~**`update_database()` always returns success**~~ — Resolved in v2.0.0. The core path wraps `wp_upgrade()` in try/catch and verifies `db_version` reached the target `$wp_db_version`, returning `success => false` otherwise.

---

## Testing

PHPUnit, against hand-written WordPress stubs in `tests/bootstrap.php` (not WP_Mock — nothing
from WordPress is loaded). `tests/unit/*Test.php`, one class per subject; `sa_reset_state()` in
`setUp()`. Run `vendor/bin/phpunit --testdox` (CI: PHP 7.4 / 8.1 / 8.2), one class with
`--filter <ClassName>`, lint with `composer lint`.

- Anything that reads a global constant or a class the suite cannot unload goes behind a
  `protected` seam and an anonymous (or named) subclass overrides it — `pick_version()` /
  `angie_state()` / `elementor_env()` in `class-tool-audit-mcp-exposure.php` are the pattern.
- The `$wpdb` stub matches statements by regex and throws on an unrecognised
  `_application_passwords` shape, so a reformatted production query fails loudly instead of
  proving nothing. A new statement shape is taught to the stub in the same PR.

---

## Releasing

A version bump touches **four places, always together** — 2.14.0 shipped with the
first three and not the fourth, and the GitHub readme's changelog silently stopped
at 2.13.0 (found by a human, not a check):

1. `digitizer-site-worker/digitizer-site-worker.php` — the `Version:` header
2. `AURA_WORKER_VERSION` constant (same file)
3. `digitizer-site-worker/readme.txt` — `Stable tag:` **and** a `== Changelog ==` entry
4. `README.md` — a matching entry under `## Changelog` **and** the `Stable-x.y.z`
   shields badge at the top. GitHub is a release surface too, and nothing
   automates any of it: no workflow edits `README.md` — the 2.14.0 badge bump
   was a human commit (`434fdd4`).

**`readme.txt`'s Changelog is capped; `README.md`'s is the full history.**
WordPress.org truncates a `== Changelog ==` section over 5,000 words and reports
it only in a warning on the plugin page that is visible to committers alone —
nothing in the repo, in CI, or in the release output. 2.16.2 shipped at 5,195
words across 36 entries, and wp.org published the Changelog with the older half
silently missing. `readme.txt` now keeps the recent releases and ends with a
`= <version> and earlier =` stub pointing at `README.md#changelog`; when the
budget is next exceeded, move the OLDEST entries out rather than compressing the
newest. `.github/scripts/check-readme-limits.php` fails the `Readme limits` lint
job well below wp.org's ceiling, so it is caught while there is still room.

Publishing a **stable GitHub release** IS the WordPress.org deploy: `release.yml`
builds and attaches the zip, `deploy.yml` pushes to wp.org SVN.

## Relationship to Aura

SiteAgent for Aura is the WordPress-side companion to the [Aura Infrastructure Hub](https://my-aura.app) (Next.js dashboard). Aura manages cloud resources across Cloudways, Hostinger VPS, Cloudflare, and Bunny.net. SiteAgent for Aura extends that reach into individual WordPress installations, allowing Aura to monitor and update sites remotely.

The communication flow is:
```
Aura Dashboard → HTTP REST → WordPress (SiteAgent for Aura plugin)
                  ↑
          Application Password + X-Aura-Token header
```
