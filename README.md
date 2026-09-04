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
  <img src="https://img.shields.io/badge/Stable-2.16.2-green" alt="Stable" />
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
| `POST` | `/snapshot` / `/snapshot/restore` / `GET` `/snapshots` | Content snapshots taken before a reversible write |
| `POST` | `/rules` | Receive Aura's signed operator ruleset — and, carrying `unbind`, end this site's binding (see below) |

### Site unbind — `POST /aura/v2/rules` with `unbind: true`

Aura ends a binding in two phases. **Phase A** records a disconnect marker
(`aura_worker_unbound`, autoload `no`) under the site claim; **Phase B** revokes the
Application Password(s) Aura minted, clears the ruleset store, the gateway public key and
the connect bookkeeping, and deletes the site token **last** — and only when Aura's
document says `final: true`. Phase B is idempotent, runs in one fixed order, continues past
a step that failed, and an interrupted unbind finishes itself on the site's next page load
(throttled to once per 5 minutes).

While the marker is set, **every mutation is refused** with `403 aura_site_unbound` —
SiteAgent's own write routes, MCP write tools, grant verification, and WordPress core REST
writes made *as the departed binding* (identified by the marker's Application Password
UUIDs, or by the site-token run-as path — never by whatever credentials happen to be live).
Reads stay reachable, and so do `POST /aura/v1/connect` (the way back), `POST /aura/mcp/tools/preview`
and `POST /aura/v2/rules` itself.

Two request forms:

| Form | Body | Accepted when |
|------|------|---------------|
| Enveloped | the signed ruleset document, with `unbind: true` (and `final`) | the site holds a usable gateway public key |
| Bare | `{ "unbind": true, "client", "site_ref", "seq", "final" }`, authenticated by the site token alone | the site holds **no** gateway key (a manual connect). A keyed site answers `400 aura_ruleset_rejected` |

The answer (both forms):

```json
{ "success": true, "seq": 7, "unbound": true, "cleanup_complete": false,
  "leftovers": ["app_passwords"] }
```

`leftovers` names what this site still owes — `app_passwords`, `options`, `ruleset`,
`grant_pubkey`. **Empty** means only the shared site token was outstanding; **non-empty**
names a credential or store the site could not *prove* released; an answer carrying **no**
`leftovers` at all must be read as "something may be owed" (the transport's own default is
the full fail-closed list).

Reconnecting settles the debt first: both the magic-link `/connect` callback and
**Regenerate Token** run Phase B steps 1–4 *before* installing a new token, and delete the
marker only after the replacement binding is installed and read back — a failed swap leaves
the marker still refusing the old binding.

**A connect never runs over another client's live binding (2.16.0).** Moving a site from one
Aura client to another is an **unbind followed by a connect**, never a single re-connect: a
`/connect` callback that meets a site already bound to a different client answers
`409 aura_site_bound` ("This site is bound to another Aura client; unbind it first") and
writes nothing at all — no token, dashboard, client sentinel, grant key or connect user. A
callback that names **no** client is refused the same way on a bound site, since a dashboard
base URL is shared by every site on it and proves no identity. The same client re-saving, a
site that has been unbound, and a site nobody has ever bound all connect exactly as before.

`GET /aura/v1/status` reports, each as a JSON object whose *presence* is the signal:

| Key | Shape | Meaning |
|-----|-------|---------|
| `unbound` | `{ at, site_ref }` | a disconnect is outstanding (fields may be absent if the record is damaged) |
| `app_password_probe_unproven` | `{ count, at, owner }` | bounded, saturating count of probes that could not prove an Application Password gone — the usual reason an unbind never completes |

### Ruleset / unbind error codes

| Code | Status | Meaning |
|------|--------|---------|
| `aura_ruleset_rejected` | 400 | the document (or bare unbind body) is malformed or unsupported |
| `no_gateway_key` | 412 | this site cannot verify anything — reconnect it. Skipped for the bare unbind form and for an already-unbound site, which must stay able to finish |
| `aura_ruleset_wrong_site` | 403 | not issued for this site |
| `aura_ruleset_stale` | 409 | `seq` is not newer than what the site already holds |
| `aura_ruleset_client_mismatch` | 409 | issued for a different Aura client than the one this site is bound to — checked on the bare unbind form too |
| `aura_ruleset_contended` | 503 | another writer won the swap; retry |
| `aura_ruleset_store_failed` | 500 | the store could not be written or read back |
| `aura_site_busy` | 503 | another Aura operation holds the site claim; retry |
| `aura_site_unbound` | 403 | this site was disconnected by Aura; reconnect it |
| `aura_unbind_store_failed` | 500 | the disconnect record, or a write the reconnect depends on, could not be stored |
| `aura_unbind_marker_malformed` | 500 | the disconnect record exists but does not parse — repairable from the settings screen |
| `aura_unbind_incomplete` | 409 | something is still owed; the answer lists `leftover[]` and any `unattributed[]` Application Password UUIDs |
| `aura_unbind_unreadable` | 409 | the disconnect record could not be **read** — distinct from an incomplete cleanup; nothing was claimed or changed |
| `aura_unbind_unrepairable` | 409 | a damaged record could not be rebuilt |
| `aura_not_unbound` | 409 | there is no disconnect to clear |
| `aura_unbind_marker_stuck` | 500 | everything Aura installed was removed, but the record itself would not delete, so the site still refuses changes |

`aura_unbind_*` codes from the admin teardown are returned by the `aura_worker_remove_aura_data`
admin-ajax action, not by a REST route.

### MCP namespace — `/wp-json/aura/mcp/`

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/tools/list` | Enumerate available tools with JSON schemas |
| `POST` | `/tools/execute` | Execute a tool with validated parameters |
| `GET` | `/context` | Full site context for AI decision-making |

**Built-in MCP tools (29):**

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
| `audit_rules` | read | operator-ruleset presence + age, 24h block/warn counts, expired-but-listed rules, enforcement points in this build |
| `snapshot_get` | read | retrieve a stored snapshot of page content (reversible write metadata) |
| `elementor_replay_ability` | write | executes an approved held Elementor write (destructive, requires Aura approval) — claims the hold, re-judges it against the current ruleset, and runs the original mutation as the user who asked |
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

### 2.16.2

- `/status`'s door fragment carries `observation`, a per-site door-version witness bumped atomically by every door-state mutation (never by a mere poll) and clock-floored so a restored backup can never reissue a value it already served, so Aura can order overlapping polls by the site's own witness instead of request timestamps; `elementor.governor` reports the current value. The observation witness needs 64-bit PHP; on a 32-bit build it always reports `null` and ordering falls back to Aura's own request order for that site.

### 2.16.1

- `/status`'s door fragment and `audit_mcp_exposure`'s `elementor.governor` block carry `binding`, the site's current binding generation, so Aura can label a departed client's door-log entries without inferring the generation from the rows. Read raw, never minted; `null` when the record cannot be read.

### 2.16.0

- Elementor MCP door governance: every write through Elementor >= 4.3's official MCP server is held for approval in Aura unless an operator `allow` rule covers it; `block` refuses; every write that runs is snapshotted first on the site and recorded in a per-site door log Aura drains. New tools `elementor_replay_ability`, `snapshot_get`; new routes `/aura/v1/door/reject`, `/aura/v1/door/ack` (which answers `stale: true`, having written nothing, when the cursor names rows this log does not have — a log rewound out from under Aura); `/status` carries `door`; `audit_mcp_exposure` carries `elementor.governor`. Rules gain the `allow` effect and the `design_system` / `page_create` targets.

### 2.15.0

- audit_mcp_exposure reports an `elementor` block: Elementor >= 4.3's official MCP module state, every `elementor_mcp_consent` row, every `Elementor MCP…` Application Password across all users (full detail), and the other Application Passwords of edit_posts users as counts. Every list is bounded (50 / 50 / 200) with a truncation flag beside it; no usermeta value over 256 KB is decoded; a scan that fails is reported as `{ error }` in its place, never as an empty list. Read-only.
- aura_worker_app_password_list() accepts an optional byte bound, enforced in the same statement that returns the value.

### 2.14.0

**Feature — a self-update can undo itself.** Before installing, SiteAgent archives
its current build — best-effort: a site without ZipArchive or with an unwritable
backup directory still updates, and the result says `backed_up: false` so the
caller knows this one had no way back. After installing, SiteAgent asks the new
build to prove it came up. The proof is written by the build itself: a boot beacon
recorded the first time the new code serves a request, and a fatal beacon recorded
by a shutdown guard when the new code dies while loading. A build whose own records
say it broke is rolled back to the archived one, and compiled copies are asked out
of the opcode cache (best-effort — a host that restricts `opcache_invalidate()` may
serve the old compiled code until its cache revalidates). The result reports
exactly what happened (`backed_up`, `verified`, `rolled_back`). When neither record
appears, the update stands and says it was not verified, never guessed.

**Feature — one SiteAgent mutation at a time.** Every path that can replace
SiteAgent's own files — the self-update, the generic plugin update, a batch entry,
the rollback endpoint — runs under a single per-site claim: taken by a conditional
insert, seizable only after its holder has gone silent for ten minutes (a request
that dies never releases), and released only by its owner. The self-update
additionally renews the claim between its phases, so a slow step costs the lease
nothing once the next phase begins. An overlapping request is answered
"in progress" and touches nothing.

**Hardening.** Backups follow a symlink only while its target stays inside the
plugin folder — a link pointing out of the tree makes the backup report itself
incomplete instead of copying unrelated files into an archive. Restores never
delete through a symlink, root or child, and refuse to run at all when a link
cannot be removed. A package carrying the version already running is refused,
because its boot records could not be told apart from the old build's. Backup
filenames carry a per-operation suffix, so two backups started in the same second
never overwrite each other.

### 2.13.0

**Feature — two-phase site unbind.** Aura can now disconnect a site in two phases,
and the site starts refusing changes the moment it is told to. A clear carrying
`unbind: true` is recorded under the site claim, and from then on every mutation —
SiteAgent's own write endpoints, MCP write tools, grant-signed calls, and any
WordPress REST write made with the Application Password or the site token of the
departing connection — answers `403 aura_site_unbound` until the site is reconnected.
Reads keep working, `/status` keeps answering, and `POST /aura/v2/rules` stays
reachable so the disconnect can be finished or retried. The cleanup that follows is
proven rather than assumed: the Application Password(s) Aura minted are revoked, the
ruleset store, gateway public key and connect bookkeeping are cleared, and the site
token is deleted **last**, only when Aura says `final: true`. Every step is idempotent,
runs in one fixed order, and continues past a step that failed; an interrupted unbind
finishes itself on the site's next page load, throttled to once every five minutes, so
a site Aura can no longer reach still converges.

**Feature — the answer says what is still owed.** Both the enveloped and the bare
unbind answers, and the `/rules` response, now carry `leftovers: string[]` beside
`cleanup_complete`: an empty list means only the shared site token was outstanding, a
non-empty one names a credential or store this site could not prove released
(`app_passwords`, `options`, `ruleset`, `grant_pubkey`), and an answer carrying no
list at all is read as "something may be owed" — the transport's default fails closed.

**Feature — unkeyed sites, and the settings screen.** A manually connected site (no
gateway public key, so it can verify nothing) accepts the bare
`{ "unbind": true, "client", "site_ref", "seq", "final" }` body on `POST /aura/v2/rules`,
authenticated by the site token alone; a keyed site refuses the bare form and requires
the signed envelope. The settings screen shows "Disconnected by Aura at …" and offers
**Remove remaining Aura data**, an admin-initiated teardown that runs the same proven
cleanup and clears the disconnect record only once everything it names — the site token
included — is gone.

**Safety.** Every ruleset push now runs under the same site-wide claim the connect flow
uses, so a push, an unbind and a reconnect can no longer interleave; a busy site answers
`503 aura_site_busy` (retryable), and a claim stranded by a killed request is taken over
after 120 s instead of blocking the site. Both reconnect paths — the magic-link connect
callback and **Regenerate Token** — settle the previous binding's outstanding cleanup
*before* installing a new token, and release the marker only after the replacement
binding is installed and read back, so a failed swap leaves the marker still refusing the
old binding rather than quietly reviving it.

**Fix.** A disconnect record that cannot be parsed is now **repaired** — rebuilt from the
site's own state, under the claim — and then torn down through the ordinary path. It was
previously a permanent dead end: the site refused every change and no control could clear
it. A record that could not be *read* is reported separately (`aura_unbind_unreadable`)
rather than being mistaken for an incomplete cleanup, and nothing is repaired on a
database blip.

**Diagnostics.** `/status` reports `unbound: { at, site_ref }` while the marker is set —
always a JSON object, even when no field is readable — and
`app_password_probe_unproven: { count, at, owner }` (bounded, saturating count) when a
probe could not prove an Application Password gone, which is the usual reason an unbind
never completes.

**New error codes:** `aura_site_unbound` (403), `aura_site_busy` (503),
`aura_unbind_incomplete` (409, with `leftover[]` / `unattributed[]`),
`aura_unbind_unreadable` (409), `aura_unbind_unrepairable` (409), `aura_not_unbound`
(409), `aura_unbind_marker_stuck` (500), `aura_unbind_marker_malformed` (500),
`aura_unbind_store_failed` (500), and `aura_ruleset_client_mismatch` (409), now checked
by the bare unkeyed form too.

**Compatibility:** a 2.13 site that is never sent an unbind behaves exactly as 2.12 did.

### 2.12.0

**Feature:** a rule can apply to some of a client's sites instead of all of them. Aura's
signed ruleset names the site each document was issued for, SiteAgent stores that
identity, and a rule that lists the sites it applies to is enforced only where it
belongs; rules that name no sites stay client-wide. A site that cannot prove its own
identity — an older record, a document issued before the field existed — enforces *every*
rule rather than skipping the ones it cannot place: scoping only ever narrows on proof.
The identity is recovered offline from the ruleset already stored, by re-verifying its
signature locally, so no new network traffic is needed and a site whose ruleset has not
changed is repaired on its next request.

### 2.11.0

**Feature:** the magic-link connect mints an Application Password ("Aura SiteAgent")
for the administrator who created the link and returns it once in the signed callback's
response, so a magic-link connection can run the builder tools that authenticate with
WordPress Basic auth. Every connect rotates it; where Application Passwords are
unavailable the connect stays token-only and names the reason. The whole install runs
under one site-wide connect claim — "Regenerate Token" takes it too — and that claim is
never taken over by age, because a callback the dashboard timed out on may still be
running. Every write the install makes — site token, client binding, dashboard URL,
gateway key — is issued by a statement conditional on holding the claim, so a claim that
disappears mid-request costs that request its connect rather than letting it overwrite
the install that replaced it. A claim left behind by a killed request is released by deactivating and
reactivating the plugin — which also revokes the Application Password, since
unregistering the routes would otherwise leave an administrator credential that
WordPress core and other REST/MCP plugins still accept. The settings screen's
Connect button is always reachable, and the line above it is derived from the
credential the site actually holds, so no failure path can hide the way back.

### 2.10.3

**Fix (security):** "Regenerate Token" revealed a new site token without storing it —
the option was a registered read-only setting, and the callback enforcing that ran on
every write, discarding the handler's. The old token stayed valid (so rotating a leaked
token did nothing) and a disconnected site could not be reconnected. The token is no
longer a registered setting, and regeneration stores the new hash with a single
compare-and-swap that no option filter can reach — a token is revealed only when that
statement reports it wrote the row.

### 2.10.2

- **Fix: the connect binds the site to its client — inside the ruleset store.** Aura clears a site it is about to forget by pushing an empty ruleset from the old client. If that push — or a late real ruleset from the old client — was still in flight when the same site was re-homed to a new client, it could land in the store the connect had just emptied and bind the site back to the old client; the new client's documents were then answered `client_mismatch` until someone reconnected (Aura#378 Ruling C1; #65). The signed `/connect` callback now carries the client as an optional sixth field, and the connect writes a seq-0 binding record into `aura_worker_ruleset` in place of clearing it — the one value `POST /aura/v2/rules` reads, decides against and compare-and-swaps — so an in-flight document for another client loses its swap and is refused, with no second option to interleave and nothing for the per-request option cache to serve stale. The binding names the token its connect installed; one written by a connect whose token a concurrent connect then overwrote is stale and replaceable, never a lock-out. A connect without the field (an older dashboard) clears as before. Known residual, accepted by the owner: two connects for the same site racing each other while an old client's push is in flight can leave the site bound to that client until the next push, which heals it; Aura's fleet audit shows the site as `ruleset_wrong_client` meanwhile.

### 2.10.1

- **Fix: `audit_rules` under-reported the current hour.** Reading the block/warn counters before the hour's first refusal listed the bucket in WordPress's negative option cache (`notoptions`), and the refusal's atomic insert did not clear it — so the count stayed at zero for the rest of the request, and on a site with a persistent object cache until the cache was flushed. Enforcement was never affected; only what the audit reported.
- **Fix: a first-ruleset race could answer 500.** Two rulesets pushed at the same moment to a site holding none could have the loser's conditional INSERT refused by the unique index (or deadlocked) rather than reporting zero rows; that was classified as a store failure. The database's duplicate-key / deadlock answer is now the lost race it is, and the loser re-decides against the winner's row like any other lost race.

### 2.10.0

- **Operator rules, enforced on the site.** A rule is an Aura memory entry (`rule/<slug>`) naming a resource — the whole site, a page or post by ID, or a plugin by slug — with an effect of `block` or `warn` and an optional expiry. Aura signs the client's whole ruleset with the same key that signs approval grants and pushes it to every connected site (`POST /aura/v2/rules`); the site verifies it and keeps only a newer one (a replayed older ruleset is refused even when validly signed). No ruleset means no policy. Enforcement runs on every path a write can take — inside the tool executor, on the legacy REST update routes, and at WordPress core's own REST API for posts and pages — so a rule holds against Aura's content tools, an assistant with an application password, or another plugin's MCP server alike. **A rule outranks an approval grant**; a `warn` runs and attaches the warning, and previews report what a call touches and which rule would decide it.
- **New: `audit_rules` (read-only).** Ruleset presence and age, whether the site can verify one, 24h block/warn counts, expired-but-listed rules, and the enforcement points in this build.

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
