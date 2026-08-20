<p align="center">
  <img src="assets/aura_icon.png" alt="Aura" width="120" />
</p>
<p align="center">
  <img src="assets/aura_logotype.png" alt="Aura" width="140" />
</p>

<h3 align="center">SiteAgent for Aura</h3>

<p align="center">
  Official WordPress agent for <a href="https://my-aura.app"><strong>Aura</strong></a>
</p>

<p align="center">
  <a href="https://wordpress.org/plugins/digitizer-site-worker/">
    <img src="https://img.shields.io/badge/WordPress.org-Plugin-blue?logo=wordpress" alt="WordPress.org" />
  </a>
  <img src="https://img.shields.io/badge/WordPress-6.2%E2%80%937.1-21759b?logo=wordpress" alt="WordPress" />
  <img src="https://img.shields.io/badge/PHP-7.4%2B-777bb4?logo=php" alt="PHP" />
  <img src="https://img.shields.io/badge/Stable-2.9.1-green" alt="Stable" />
</p>

---

## What is SiteAgent?

SiteAgent is the official remote management agent for the [Aura Infrastructure Hub](https://my-aura.app). It connects your WordPress sites to your Aura dashboard for seamless remote management, monitoring, and updates from a single centralized interface.

---

## Features

| Capability | Description |
|------------|-------------|
| **Site Health** | Real-time monitoring of WordPress & PHP versions, plugins, themes, and server health. |
| **One-Click Updates** | Update WordPress core, plugins, and themes remotely from the Aura dashboard. |
| **Safe Update Engine** | Chunked batch updates with health checks and automatic rollback on failure. |
| **Per-Plugin Rollback** | Zip backups in `wp-content/aura-backups/` with one-shot restore. |
| **MCP Tools Layer** | `/aura/mcp/` REST namespace exposing AI-agent-friendly tools with JSON schemas. |
| **Magic Link Onboarding** | One-click connection from wp-admin to the Aura dashboard — HMAC-signed, no token copy/paste. |
| **Maintenance** | Run database upgrades and translation updates across all sites. |
| **Hardened Security** | Hashed site tokens, brute-force throttling, signed magic-link connect, and optional IP/domain allowlists. |
| **Developer API** | Fully exposed via secure REST API endpoints. |

### Zero Frontend Impact

SiteAgent is built for performance. It only registers REST API routes and has **zero impact** on your site's frontend performance — no extra scripts, styles, or queries on page load.

---

## Installation

### Via WordPress.org (Recommended)

1. Go to **Plugins > Add New** in your WordPress admin.
2. Search for **SiteAgent**.
3. Click **Install Now** and then **Activate**.

### Via WP-CLI

```bash
wp plugin install digitizer-site-worker --activate
```

### Manual upload

Download the zip from the [latest release](https://github.com/Digitizers/SiteAgent/releases) and upload via **Plugins → Add New → Upload Plugin**.

> The display name is **SiteAgent for Aura**; the WordPress.org slug remains `digitizer-site-worker`.

---

## Security

Layered authentication protects every request:

1. **WordPress Auth:** Application Password with capability checks (`manage_options` / `update_*`).
2. **Site Token:** Per-site token in the `X-Aura-Token` header, **stored as a SHA-256 hash** (never plaintext) and compared timing-safely. Legacy plaintext tokens migrate automatically on first use.
3. **Brute-force throttle:** Per-IP failed-attempt limit returns HTTP 429.
4. **IP / Domain allowlist:** Optional restriction to your Aura instance.

Onboarding via magic link is **HMAC-signed**: the `/connect` callback carries a signature derived from a one-time secret the site issued, plus a timestamp replay window — so the token exchange can't be hijacked or replayed. Rotate the token anytime from **Settings → SiteAgent → Regenerate Token**.

---

## REST API

### v1 namespace — `/wp-json/aura/v1/`

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/status` | Full site health report |
| `GET` | `/updates` | Check available core, plugin, and theme updates |
| `POST` | `/update/core` | Upgrade WordPress core |
| `POST` | `/update/plugin` | Update a specific plugin |
| `POST` | `/update/theme` | Update a specific theme |
| `POST` | `/update/translations` | Bulk update translation packs |
| `POST` | `/update/database` | Run WordPress database upgrades |
| `POST` | `/connect` | Magic-link token exchange (public, HMAC-signed, 10-min expiring) |

### v2 namespace — `/wp-json/aura/v2/`

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/health` | HTTP, PHP fatals, white-screen, and DB connectivity checks |
| `POST` | `/update/batch` | Chunked batch updates with auto-rollback on health failure |
| `POST` | `/rollback/{plugin}` | Restore a plugin from its most recent zip backup |

### MCP namespace — `/wp-json/aura/mcp/`

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/tools/list` | Enumerate available tools with JSON schemas |
| `POST` | `/tools/execute` | Execute a tool with validated parameters |
| `GET` | `/context` | Full site context for AI decision-making |

**Built-in MCP tools (26):**

| Tool | Kind | Purpose |
|------|------|---------|
| `get_site_context` | read | WP/PHP/theme/plugins/disk/performance snapshot + detected issues |
| `get_database_info` | read | DB size, largest tables, autoload weight, expired transients |
| `scan_security` | read | scored posture (file-edit, debug, SSL, default admin/prefix, registration, PHP) |
| `scan_seo` | read | scored SEO posture (indexability, permalinks, sitemap, title) + sampled content audit |
| `scan_a11y` | read | scored accessibility audit (image alt text, link text, headings, document language) |
| `perf_check` | read | scored performance posture (object cache, OPcache, page cache, PHP, autoload, plugins, memory) |
| `scan_broken_links` | read | link triage (empty/anchor, dev/staging hosts, unresolved internal links) — no outbound HTTP |
| `get_seo_meta` | read | a post/page's SEO title, description, focus keyword (Rank Math / Yoast / SEOPress) |
| `list_page_blocks` | read | a page's Gutenberg block structure (names, attributes, nesting) |
| `list_users` | read | users + roles + post counts, admins flagged (never returns secrets) |
| `check_health` | read | live health gate — HTTP, PHP fatals, white-screen, DB |
| `scan_error_log` | read | tail + severity-group the error log, surface recent fatals |
| `check_vulnerabilities` | read | plugin update-currency vs WordPress.org (version check — not a vulnerability/CVE feed; wp.org-hosted plugins only) |
| `check_core_checksums` | read | core-file integrity vs the official wp.org checksum manifest (HTTPS-only) — modified/missing/unexpected files incl. root implants |
| `scan_executable_files` | read | uploads-tree observations: PHP/executables, .htaccess overrides, symlinks (reported, never followed) |
| `audit_admin_accounts` | read | privileged-account facts: admins + recency, caps outside role, app-password counts, multisite super admins |
| `audit_cron` | read | bounded WP-Cron inventory + fact-flags (sub-60s schedules, callbacks unresolved in this context) |
| `audit_mcp_exposure` | read | other MCP servers registered on this site, and how many abilities pass the discovery rule such a server applies — a property of the abilities, not proof any server serves them; a registry-resolving server (Angie's) picks up mutating ones outside SiteAgent's approval path |
| `set_seo_meta` | write | set a post/page's SEO title / description / focus keyword (approval-gated; only fields you pass change) |
| `update_plugin_safely` | write | backup → update → health check → auto-rollback |
| `clear_caches` | write | flush object/opcode caches + detected page-cache plugins |
| `cleanup_transients` | write | remove expired transients (autoload hygiene) |
| `cleanup_orphaned_assets` | write | find/remove unused media (dry-run by default; live delete approval-gated) |
| `backup_plugins` | write | zip-snapshot one or all active plugins (rollback safety net) |
| `update_page_block` | write | update a Gutenberg block's content/attributes (snapshot-first, reversible) |
| `create_page_from_blocks` | write | create a new page from a Gutenberg block spec (draft-first) |

These plug straight into **Aura's Fleet MCP Gateway**: read tools run on demand, write tools are gated behind human approval, every call is audited. Every tool declares explicit risk annotations (`read_only`, `destructive`, `requires_approval`); the gateway's verb-based classifier is only the fallback for tools that haven't declared them.

---

## Changelog

### 2.9.1

- **Second-door hardening for the Abilities surface.** SiteAgent's tools have been dual-registered as WordPress abilities since 2.5.0, and `wp_register_ability` publishes to the **site**, not to a server — so any co-installed MCP server enumerating `wp_get_abilities()` could serve them, writes included. Grant enforcement lives in SiteAgent's REST handlers, which that path never touches, so a mutating tool could run through another plugin's MCP server with no approval, no snapshot binding and no audit entry. Two guards now close it: grant-requiring tools declare a discovery type co-installed servers do not serve, and a grant-requiring ability on any transport but SiteAgent's own is refused without a valid grant bound to that exact call. Gateway-minted grants work unchanged; read-only tools stay discoverable.

### 2.9.0

- **Security-audit read surface (5 tools).** `check_core_checksums` (core files vs the official wp.org manifest), `scan_executable_files` (PHP/executables, `.htaccess` overrides and symlinks under uploads), `audit_admin_accounts`, `audit_cron`, and `audit_mcp_exposure`. All read-only; all report bounded coverage and stop at a cap rather than growing without limit, and an empty result under `truncated: true` means "nothing found before the cap", never "clean".
- **`audit_mcp_exposure`** answers the question that appears once a site runs more than one AI assistant: which other MCP servers are registered here, and how many abilities pass the discovery rule such a server applies. Abilities are registered site-wide, not to the plugin that declared them, so a server resolving targets from that registry (Angie's does) picks up mutating ones that never went through SiteAgent's approval path. The counts describe the abilities, not what any server currently serves.
- Compatibility: declared tested up to WordPress 7.1.

### 2.8.2

- **Snapshot restore hardening.** Stored payloads are read back with `unserialize()` restricted to `allowed_classes => false`, so a tampered payload file cannot instantiate arbitrary PHP objects on the restore path; restores fail closed on any object-bearing or malformed payload.
- Fixes: the self-updater deletes its temp download with `wp_delete_file()`; the SEO auditor reads core's sitemap state through the sitemaps server instead of re-firing the `wp_sitemaps_enabled` filter.
- CI now gates PRs on PHPCS and the official WordPress Plugin Check.

### 2.8.1

- Docs: the listing describes the optional **SiteAgent Power Pack** companion plugin and its governance model — deliberately not part of the WordPress.org build. No code changes.

### 2.8.0

- Internal snapshot-engine primitives for reversible meta and multi-post writes (`snapshot_meta`, `snapshot_posts`), groundwork for governed Elementor and bulk-post editing; not yet exposed over the remote snapshot API.
- Fix: SEO-meta writes return a distinct "Failed to write SEO meta" error instead of the misleading "Nothing to update".

### 2.7.1

- **Self-update zip integrity (H3 Part C).** When the Aura gateway binds a release SHA-256 into the self-update grant, SiteAgent downloads the zip, verifies its bytes with `hash_file('sha256', …)` against the grant-bound digest, and refuses to install on a mismatch — closing the "grant covers the URL, not the bytes" gap (e.g. a tampered CDN edge). The digest is part of the signed grant, so it can't be swapped. Sites/releases without a digest install as before (back-compat); the gateway binds the digest only for sites already on 2.7.1+, so the rollout that ships 2.7.1 itself is unaffected.

### 2.7.0

- **Approval gate extended to the REST write endpoints (H3).** G-grants previously covered only the MCP `tools/execute` path; the direct REST writes (`/v1/update/{core,plugin,theme,translations,database}`, `/v1/self-update`, `/v2/update/batch`, `/v2/rollback/{slug}`, `/v2/snapshot`, `/v2/snapshot/restore`) ran as admin off a valid `X-Aura-Token` with no grant check. Each now calls `Aura_Worker_Grant::require_for()` — when a gateway pubkey is provisioned, the write requires a fresh single-use Ed25519 grant bound to the exact action name and parameters (batch binds `chunk_size` + `create_backup` too, so an approved batch can't be replayed with backups off). A leaked Site Token can no longer trigger a code update or rollback. Sites without a provisioned key keep working token-only until they reconnect.
- **Self-update source allowlist.** `self-update` now installs only from the official `Digitizers/SiteAgent` GitHub release downloads, over HTTPS — bounding even an approved self-update to a trusted source (WordPress follows GitHub's CDN redirect internally, so only the `github.com` release path needs allowlisting). Overridable via the `aura_worker_self_update_allowed_hosts` filter.
- Requires the Aura gateway to mint grants for these endpoints (Aura H3 P2); until that ships, publish only to sites whose gateway attaches update grants.

### 2.6.1

- **Tool self-declaration hardening** (no new tools — set stays at **21**): the six mutating tools (`update_plugin_safely`, `cleanup_orphaned_assets`, `backup_plugins`, `cleanup_transients`, `clear_caches`, `set_seo_meta`) now explicitly declare themselves non-read-only and approval-required instead of inheriting neutral defaults, so any consumer that trusts a tool's own annotations gates them correctly. Live behaviour is unchanged — grant enforcement and the gateway's verb policy already treated them as writes.
- `cleanup_orphaned_assets` now advertises a preview: its dry-run (find orphans, delete nothing) is exposed through the preview API, so the orphaned-media sample can be inspected without approval before the destructive delete.

### 2.6.0

- **Signed approval grants (G-grants):** every mutating MCP tool reached over the Aura gateway (`X-Aura-Token`) path now requires a single-use, Ed25519-signed grant that binds the exact tool, parameters, site, and a short validity window — so a stolen site token can only ever run **read** tools, never a write or a power op. The plugin stores only the gateway's **public** key, so even a fully compromised site can't mint its own grants. The key is provisioned over the HMAC-signed magic-link `/connect` callback, and enforcement activates only once it's present, so existing deployments are unaffected until they reconnect.

### 2.5.0

- **WordPress Abilities API bridge:** SiteAgent tools are dual-registered as WP abilities when the core Abilities API is present, so the official MCP adapter and standard MCP clients can discover them (the `aura/mcp` namespace is unchanged).
- Hardening (external review) across the abilities registration, snapshot engine (fail-closed writes, uncollidable absent-option sentinel), and Gutenberg update path (refuses `inner_html` on a block with nested children).

### 2.4.0

- **Gutenberg (block editor) tools** — `list_page_blocks` (read), `update_page_block` (approval-gated, snapshot-first, reversible), `create_page_from_blocks` (draft-first), bringing the built-in set to **21**. The snapshot engine gains a "post" kind so block edits are reversible. Ends the Elementor-only gap — Gutenberg is core WP.

### 2.3.0

- **Token-only connection** (no new tools — set stays at **18**): a valid Aura Site Token authorizes management on its own. After connecting, the plugin runs requests as the connecting administrator (`current_user_can()` passes without an Application Password). Existing app-password connections are unaffected; deploy order vs. the Aura dashboard does not matter.

### 2.2.4

- **Connect-to-Aura host fix** (no new tools — set stays at **18**): the "Connect to Aura" magic-link onboarding now targets the Aura app host (`app.my-aura.app`) instead of the marketing domain (`my-aura.app`), which has no onboarding API, so one-click connect works out of the box. Sites that set the `AURA_DASHBOARD_URL` constant are unaffected.

### 2.2.3

- **Auditor accuracy fixes** (no new tools — set stays at **18**): `set_seo_meta` now invalidates Yoast's cached indexable so SEO changes show on the frontend immediately; `perf_check` counts all WP 6.6+ autoload values (`yes`/`on`/`auto-on`/`auto`); `scan_broken_links` reports true totals instead of the capped sample count; `scan_seo` scores missing excerpts; `scan_a11y` checks the rendered `<html lang>` rather than the configured locale.

### 2.2.2

- **On-site SEO-meta tools** — two agent tools that read and write a post/page's SEO meta directly via the active SEO plugin's own meta keys (Rank Math, Yoast, SEOPress), bringing the built-in set to **18**. `get_seo_meta` (read) returns title / description / focus keyword; `set_seo_meta` (write, approval-gated) sets any subset. Because they run on-site rather than via the SEO plugin's REST endpoint, they work even where a WAF blocks those endpoints.

### 2.2.1

- **Performance & broken-link auditors** — two more read-only agent tools (`perf_check`, `scan_broken_links`), bringing the built-in set to **16**. `perf_check` scores caching layers, PHP, autoload weight, plugin count, memory, and expired transients. `scan_broken_links` triages links over a content sample **without any outbound HTTP** (empty/anchor links, dev/staging hosts, internal links that don't resolve locally). Both auto-register and run as read tools.

### 2.2.0

- **SEO & accessibility auditors** — two new read-only agent tools (`scan_seo`, `scan_a11y`), bringing the built-in set to **14**. Both are scored, no-AI-cost structural audits over a sampled set of published content (indexability/permalinks/sitemap/title + missing excerpts/featured images/thin content for SEO; image alt text, non-descriptive link text, heading structure, document language for accessibility). Auto-register via the tool loader; governed by Aura's risk policy as read tools.
- Cheap, fleet-friendly: run across every site through Aura's Fleet MCP Gateway to spot SEO/a11y regressions at scale.

### 2.1.0

- **MCP ops toolset expansion** — eight new agent tools (`get_database_info`, `scan_security`, `list_users`, `check_health`, `scan_error_log`, `clear_caches`, `cleanup_transients`, `backup_plugins`), bringing the built-in set to **12**. Each auto-registers via the tool loader and is governed by Aura's risk/approval policy.
- `check_health` + `backup_plugins` reuse the existing health-check and rollback engines — building blocks for health-gated fleet-wide safe updates.
- Read tools run on demand; cache/transient/backup tools are mutating and approval-gated.

### 2.0.0 *(stable — live on WordPress.org)*

- **v2 Update Engine:** health checks, per-plugin rollback, chunked batch updates, auto-rollback on failure.
- **MCP Tools Layer:** `/aura/mcp/` namespace with `tools/list`, `tools/execute`, `context`, plus four built-in tools.
- **Magic Link Onboarding:** one-click, **HMAC-signed** connection from wp-admin to the Aura dashboard.
- **Security hardening:** SHA-256 hashed site token (auto-migrates legacy tokens), per-IP brute-force throttle, Regenerate Token UI, timestamp replay protection on `/connect`.
- **Reliability:** core database upgrade now reports real failures instead of always succeeding.
- **Compliance:** WordPress.org Plugin Check fixes — `WP_Filesystem`, `wp_json_encode()`, `gmdate()`, `wp_delete_file()`. Tested up to WordPress 7.0.

### 1.3.5

- Security: timing-safe token comparison, optional IP whitelisting, Cloudflare/reverse-proxy header support.

### 1.3.4

- **Branding Update:** New official icons and banners for WordPress.org.
- **Improved UX:** Updated documentation and installation guides.

### 1.3.3

- **Official WordPress.org Launch:** Now available in the official plugin repository.
- GitHub Release: [v1.3.3](https://github.com/Digitizers/SiteAgent/releases/tag/v1.3.3)

### 1.3.0

- Rebranded from "AuraWorker" to "Digitizer Site Worker for Aura"
- New slug: `digitizer-site-worker`

---

Built with ❤️ by [Digitizer](https://www.digitizer.studio) for the [Aura](https://my-aura.app) ecosystem
