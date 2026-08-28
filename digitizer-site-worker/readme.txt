=== SiteAgent for Aura ===
Contributors: benkalsky
Tags: ai, automation, maintenance, updates, wordpress management
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.12.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let AI update and maintain WordPress with Aura's guardrails: optional per-action approval, an audit trail, and auto-rollback on safe batch updates.

== Description ==

**SiteAgent** turns every WordPress site into one an AI agent can safely operate — update, maintain, audit, and fix. Once the site is connected to Aura and its approval key is provisioned, mutating actions are gated behind human approval and recorded in a full audit trail; safe batch updates and block edits are snapshotted so they can be rolled back. It's the on-site half of [Aura](https://my-aura.app), the governed control room agencies use to run whole fleets of client sites alongside their servers, CDN, and DNS.

Plugin and core updates are the riskiest thing you do on a live client site. SiteAgent makes them safer: safe batch updates run behind health checks and **roll back automatically** if the site breaks, and every plugin in that path is zip-snapshotted first. With Aura's approval key in place, an agent can't silently push a change — mutating actions wait for a human to approve them. Think of it as the undo button for AI on your clients' sites.

Install this plugin on any WordPress site to connect it to Aura — no SSH, no wp-admin juggling, no manual logins.

= What You Can Do =

* **Monitor site health** — See WordPress version, PHP version, installed plugins & themes, database info, and disk usage in real time.
* **Update plugins, themes & core remotely** — Push updates to any connected site from the Aura dashboard, no wp-admin login.
* **Safe batch updates with auto-rollback** — Run chunked updates with health checks; if an update breaks the site, the plugin restores the previous version automatically.
* **Per-plugin rollback** — Every update is zip-snapshotted first; restore any plugin to its last good state on demand.
* **Bulk translation & database upgrades** — Update all language packs and run WordPress database migrations remotely.
* **One-click connect (magic link)** — Connect a site to Aura straight from wp-admin — no manual token copy/paste.
* **AI-agent ready (27 MCP tools)** — Exposes machine-readable, JSON-schema tools for AI-driven management, including SEO/accessibility/performance/broken-link auditors, on-site SEO-meta read/write (Rank Math, Yoast, SEOPress), and Gutenberg block read/edit. Read tools run on demand; mutating tools are approval-gated through Aura, and every call is audited.
* **Zero frontend impact** — The plugin only registers REST API endpoints. No scripts, no styles, no database queries on visitor-facing page loads.

= How It Works =

After activation, click **Connect to Aura** on the **Settings → SiteAgent** page for a one-click magic-link connection, or copy the Site Token shown once and paste it into your Aura dashboard manually. From that point, Aura communicates with your site over a signed, authenticated REST API to pull health data and push updates.

= Security =

Defence-in-depth protects every request:

1. **WordPress Application Password** — Standard WordPress auth with capability checks (`manage_options` / `update_*`). Only authorized administrators can trigger actions.
2. **Hashed Site Token** — A per-site token sent via the `X-Aura-Token` header. Only a SHA-256 **hash** is stored (never the raw token), compared timing-safely. Tokens from older versions migrate to a hash automatically.
3. **Brute-force throttling** — Repeated bad-token attempts from an IP are blocked.
4. **Signed magic-link connect** — The onboarding callback is HMAC-signed with a one-time secret and timestamp, so the token exchange can't be hijacked or replayed.
5. **IP / Domain allowlist** (optional) — Restrict API access to your Aura instance, with Cloudflare and reverse-proxy header support.

You can rotate the token anytime from **Settings → SiteAgent → Regenerate Token**.

= REST API Endpoints =

Core endpoints under `/wp-json/aura/v1/`:

* `GET /status` — Full site health report
* `GET /updates` — Check available updates (core, plugins, themes, translations)
* `POST /update/core` / `/update/plugin` / `/update/theme` / `/update/translations` — Apply updates
* `POST /update/database` — Run WordPress database upgrades
* `POST /connect` — Magic-link token exchange (public, HMAC-signed, 10-minute expiry)

Version 2 endpoints under `/wp-json/aura/v2/`:

* `GET /health` — HTTP, PHP fatal, white-screen and DB connectivity checks
* `POST /update/batch` — Chunked batch updates with auto-rollback on health failure
* `POST /rollback/{plugin}` — Restore a plugin from its most recent backup

MCP tools under `/wp-json/aura/mcp/`:

* `POST /tools/list` / `POST /tools/execute` — Enumerate and run AI-agent tools
* `GET /context` — Full site context for AI decision-making

= AI Agent Tools (MCP) =

SiteAgent ships **27 built-in tools** for AI agents. Read tools return information and run on demand; write tools change the site and are queued for human approval through Aura — an agent can never silently mutate a production site.

Read tools:

* `get_site_context` — WordPress/PHP/theme/plugin/disk/performance snapshot with detected issues
* `get_database_info` — Database size, largest tables, autoloaded-options weight, expired transients
* `scan_security` — Scored security posture (file-edit lockdown, debug exposure, SSL, default admin/prefix, open registration, PHP version)
* `scan_seo` — SEO posture (search-engine visibility, permalinks, XML sitemap, site title) plus a sampled content audit (thin content, missing excerpts/featured images)
* `scan_a11y` — Accessibility audit over sampled content (images missing alt text, non-descriptive link text, heading structure, document language)
* `perf_check` — Performance posture (persistent object cache, OPcache, page-cache plugin, PHP version, autoload weight, active plugin count, memory limit)
* `scan_broken_links` — Link triage over a content sample with no outbound HTTP (empty/anchor-only links, dev/staging hosts, unresolved internal links)
* `list_users` — Users with roles and post counts, administrators flagged (never returns secrets)
* `check_health` — Live health gate: HTTP status, PHP fatals, white-screen, database connectivity
* `scan_error_log` — Tails and severity-groups the error log, surfacing recent fatals
* `check_vulnerabilities` — Plugin update-currency check against WordPress.org (a version check, not a vulnerability/CVE feed; covers wp.org-hosted plugins)
* `check_core_checksums` — Core-file integrity against the official WordPress.org checksum manifest (modified/missing/unexpected files, fetched over HTTPS only)
* `scan_executable_files` — Uploads-directory observations: PHP/executable files, .htaccess overrides, and symlinks (reported, never followed)
* `audit_admin_accounts` — Privileged-account facts: administrators with recency, admin capabilities outside the role, application-password counts, multisite super admins
* `audit_cron` — Bounded WP-Cron inventory with sub-60-second-schedule and unresolved-callback fact-flags
* `audit_mcp_exposure` — Which other MCP servers are registered on this site, and how many abilities pass the discovery rule such a server applies (abilities are registered site-wide, not per-plugin, so a server resolving targets from that registry picks up mutating ones outside SiteAgent's approval path). The counts describe the abilities, not what any server currently serves. Reports only; changes nothing
* `audit_rules` — Whether a signed operator ruleset is present and how old it is, 24h block/warn counts, expired-but-listed rules, and the enforcement points in this build. Reports only; changes nothing
* `get_seo_meta` — Read a post/page's SEO title, description, and focus keyword from the active SEO plugin (Rank Math, Yoast, or SEOPress)
* `list_page_blocks` — Read a page's Gutenberg block structure (block names, attributes, nesting)

Write tools (approval-gated):

* `update_plugin_safely` — Backup, update, health-check, auto-rollback on failure
* `clear_caches` — Flush object/opcode caches and detected page-cache plugins
* `cleanup_transients` — Remove expired transients to reduce autoload bloat
* `cleanup_orphaned_assets` — Find and remove unused media (dry-run by default)
* `backup_plugins` — Zip-snapshot one or all active plugins as a rollback safety net
* `set_seo_meta` — Write a post/page's SEO title / description / focus keyword on the active SEO plugin (Rank Math, Yoast, or SEOPress) — on-site, so it works even when a WAF blocks the plugin's own REST endpoint
* `update_page_block` — Update a Gutenberg block's content or attributes (snapshot-first, reversible)
* `create_page_from_blocks` — Create a new page from a Gutenberg block spec (draft-first)

Tools are classified by verb so the Aura Fleet gateway applies the right risk and approval policy automatically.

= Pro: the SiteAgent Power Pack =

Everything above is free and ships in this plugin. Agencies that need an agent to *fix* a site — not just report on it — can add the **SiteAgent Power Pack**, a separate companion plugin that registers higher-capability tools through this plugin's own tool registry.

It is **not** included in this download and is **not** distributed on WordPress.org — the tools it adds execute code, so they don't belong in a hosted repository. It comes with the Aura **Agency** and **Studio** plans, or as **SiteAgent Pro**.

What it adds:

* `read_file` — read a text file from inside wp-content (jailed; refuses wp-config.php).
* `db_query` — a single read-only SQL statement (SELECT / SHOW / EXPLAIN), row-capped.
* `write_file` — write a file inside wp-content, snapshot-first so it can be rolled back.
* `run_wp_cli` — run an allowlisted WP-CLI command, with no shell and no metacharacters.
* `execute_php` — run a PHP snippet against the full WordPress API.

These are governed harder than anything in the free set, deliberately:

1. **Off until you arm them.** The write and code tools do nothing until the site owner sets an explicit constant in `wp-config.php` for each one. Installing the Power Pack alone enables no writes and no code execution.
2. **Human approval, cryptographically enforced.** Once the site holds Aura's approval key (provisioned when you connect the site), each of these calls requires a single-use, signed grant bound to that exact tool and its exact parameters — one only the Aura dashboard can mint, after a human approves the action. A leaked Site Token cannot run them. (Until a site has that key, the gate is dormant — so connect the site from Aura before arming anything.)
3. **Reversible where it can be.** File writes are snapshotted first, so there's a previous state to restore.

The safety model is governance, not a sandbox: `execute_php` is powerful by design. The controls are the constant you set, the human who approves the call, and the audit trail — not a promise that arbitrary code is safe.

Learn more at [my-aura.app/siteagent](https://my-aura.app/siteagent).

= About Aura =

Aura is a full-stack operations dashboard by [Digitizer](https://digitizer.studio) that brings servers, applications, DNS zones, and CDN pull zones from Cloudways, Hostinger VPS, Cloudflare, and Bunny.net into a single unified interface.

SiteAgent extends that reach into every WordPress installation — so you can manage your entire infrastructure, including WordPress sites, from one place.

= Free to Use =

The plugin is completely free and open source (GPLv2+). You need a free or paid Aura account to connect your sites. [Sign up at my-aura.app](https://my-aura.app).

= Links =

* [Aura Dashboard](https://my-aura.app)
* [Documentation](https://my-aura.app/siteagent)
* [GitHub Repository](https://github.com/Digitizers/SiteAgent)
* [Digitizer](https://digitizer.studio)

== Installation ==

= Via WordPress Admin (Recommended) =

1. Go to **Plugins → Add New** in your WordPress admin.
2. Search for **SiteAgent**.
3. Click **Install Now**, then **Activate**.
4. Navigate to **Settings → SiteAgent**.
5. Click **Connect to Aura** for one-click magic-link onboarding — or copy the Site Token (shown once) and paste it into your Aura dashboard manually.

= Via WP-CLI =

`wp plugin install digitizer-site-worker --activate`

= Manual Upload =

1. Download the plugin ZIP from WordPress.org.
2. Go to **Plugins → Add New → Upload Plugin**.
3. Upload the ZIP and click **Install Now**, then **Activate**.
4. Navigate to **Settings → SiteAgent** to connect or get your Site Token.

== Frequently Asked Questions ==

= Do I need an Aura account? =

Yes, you need an Aura account to connect your WordPress sites. Aura offers a free tier that includes up to 3 WordPress sites. [Sign up at my-aura.app](https://my-aura.app).

= Is this plugin safe to use? =

Yes. The plugin uses defence-in-depth: WordPress Application Passwords (the same standard mechanism used by the block editor), a per-site token stored only as a SHA-256 hash and verified timing-safely, per-IP brute-force throttling, an HMAC-signed onboarding handshake, and an optional IP/domain allowlist. No data is transmitted unless a request is made by your Aura instance.

= How do I enable the approval gate for write actions? =

SiteAgent can require a per-action, cryptographically signed approval before it runs a state-changing **MCP tool** (cleanups, cache flushes, SEO writes, safe plugin updates run through the tool interface). Once enabled, each such write must carry a single-use signature that only the Aura dashboard can mint, after a human approves the action — so a leaked Site Token cannot run those tools on its own.

This gate turns on automatically once the site holds Aura's approval key, which is provisioned securely during connection. **If you installed or updated the plugin but have not reconnected the site since, the gate is dormant** and the site runs in the standard token-only mode. To activate it, simply **reconnect the site from your Aura dashboard** — no reinstall is needed.

Note: the approval gate currently covers the MCP tool path. Core, plugin, and theme updates performed over the plugin's direct REST update endpoints are still authorized by the Site Token alone (the standard site-management model), so treat the Site Token as a sensitive credential regardless. Grant coverage for those update endpoints is on the roadmap.

= Does it slow down my site? =

No. The plugin registers only REST API endpoints. It does not load any code, scripts, or database queries on frontend page loads. Your visitors experience zero impact.

= What WordPress versions are supported? =

WordPress 6.2 or higher is required. This is needed for full Application Password support. The plugin has been tested up to WordPress 7.1.

= What PHP versions are supported? =

PHP 7.4 or higher. PHP 8.0+ is recommended.

= Can I restrict which IP addresses can access the API? =

Yes. The plugin supports an optional IP whitelist. If configured, only requests from the specified IP addresses will be accepted. Cloudflare and reverse proxy headers (`CF-Connecting-IP`, `X-Forwarded-For`, `X-Real-IP`) are fully supported for IP detection.

= Does this work with WordPress multisite? =

The plugin is designed for single WordPress installations. Multisite support is not currently available but is on the roadmap.

= Where is the Site Token stored? =

Only a SHA-256 **hash** of the Site Token is stored, in the WordPress option `aura_worker_site_token` — the raw token is never persisted. It is generated on first activation and shown once so you can copy it; the Aura dashboard keeps the only raw copy. Tokens created by older versions are upgraded to a hash automatically on first use.

= Can I regenerate the Site Token? =

Yes. Use **Regenerate Token** on the **Settings → SiteAgent** page. The new token is shown once. Regenerating invalidates the old token and disconnects the site from Aura until you reconnect with the new one.

= How do I disconnect a site from Aura? =

Simply deactivate or delete the plugin, or remove the site from your Aura dashboard. If you deactivate the plugin, the REST API endpoints are unregistered and Aura can no longer communicate with the site.

= Does Aura store my wp-admin credentials? =

No. Aura uses WordPress Application Passwords, not your main admin password. Application Passwords are scoped specifically for REST API access and can be revoked at any time from **Users → Your Profile** in wp-admin.

= Is the plugin open source? =

Yes. SiteAgent is open source under the GPLv2 or later license. The source code is available on [GitHub](https://github.com/Digitizers/SiteAgent).

== Screenshots ==

1. Settings → SiteAgent in wp-admin: site token status, optional IP / domain allowlists, one-click Connect to Aura, and a live connection test.
2. Aura → Apps → Connection tab: connection status, plugin version, release channels (stable / beta), re-test, disconnect, and credential rotation.
3. Health tab: WordPress / PHP / MySQL versions, server info, settings, active theme, and the full plugin inventory with status.
4. Updates tab: SiteAgent rollout control (release channel, auto-update, policy and risk state), update status, and per-plugin database migration status.
5. Fleet AI: the catalog of agent tools every connected site exposes — each tagged Read or Power — runnable across the whole fleet from one control plane.
6. SiteAgent Power Pack: the companion plugin's write and code tools, each off until armed in wp-config.php and approval-gated through Aura.
7. Connections: provider connections (Cloudways, Cloudflare, Bunny, Hostinger, Vultr, xCloud) with resource counts, status, and credential-rotation reminders.

== Changelog ==

= 2.12.0 =
* Feature: **a rule can now apply to some of a client's sites instead of all
  of them.** Aura's signed ruleset names the site each document was issued
  for, SiteAgent stores that identity, and a rule that lists the sites it
  applies to is enforced only where it belongs. Rules that name no sites are
  client-wide exactly as before.
* Safety: a site that cannot prove its own identity — an older record, a
  document issued before this field existed — enforces EVERY rule rather than
  skipping the ones it cannot place. Scoping only ever narrows on proof.
* Upgrade: the identity is recovered offline from the ruleset already stored,
  by re-verifying its signature locally. No new network traffic, and a site
  whose ruleset has not changed since the upgrade is repaired on its next
  request rather than waiting for the next push.

= 2.11.0 =
* Feature: **the magic-link connect now mints an Application Password for
  the dashboard.** A magic-link connection could run SiteAgent's own tools
  but not the builder tools (Elementor MCP and friends) that authenticate
  with WordPress Basic auth — only a manual connect, which never provisions
  the gateway key, could. The signed /connect callback now also mints an
  Application Password named "Aura SiteAgent" for the administrator who
  created the link and returns it once, in the callback's response, so the
  dashboard stores it encrypted beside the site token. Every connect rotates
  it (earlier ones under that name are deleted first). Where Application
  Passwords are unavailable (no HTTPS, disabled by a filter, no admin user)
  the connect succeeds token-only as before and names the reason.
* The whole install — token, client binding, gateway key, Application
  Password — runs under one site-wide connect claim, so two callbacks for the
  same site cannot split the credentials the dashboard ends up holding.
  "Regenerate Token" takes the same claim (it is refused while a connect is
  installing). The claim is never taken over by age: a callback the dashboard
  timed out on may still be running. Every write the install makes — the site
  token, the client binding, the dashboard URL, the gateway key — is issued by
  a statement conditional on holding the claim, so a claim that goes away
  mid-request costs that request its connect (retryable) rather than letting it
  overwrite the install that replaced it. If a
  killed request ever leaves a claim behind, every later connect is refused
  until an administrator deactivates and reactivates the plugin, which
  releases it.
  "Regenerate Token" issues its cleanup the same way, and skips revoking the
  Application Password when it no longer owns the claim — the password on the
  site then belongs to whichever connect replaced it. It still reveals the
  token it stored: refusing to would revoke the old token while showing no
  replacement.
* Deactivating the plugin now also revokes the Application Password Aura
  minted. Unregistering SiteAgent's routes does not stop an
  administrator-level credential from authenticating to WordPress core and to
  other REST/MCP plugins, so "deactivated" would not have meant
  "disconnected". If the revocation fails the owner and UUID are kept, and
  reactivating finishes the job (activation retries a revocation deactivation
  could not land), as does uninstalling. The site token binding survives
  deactivation, so the settings screen keeps showing the dashboard it is
  connected to. The Connect button is always reachable there, and the line above
  it says what the site actually holds: a working credential, one that was never
  delivered, none at all, or a site that cannot issue one (token-only).

= 2.10.3 =
* Fix (security): **"Regenerate Token" revealed a new site token without ever
  storing it.** The option was registered as a read-only setting, and the
  callback enforcing that ran on every write — not only on the settings form —
  so the handler's write was discarded while the one-time reveal still
  appeared. Two consequences: an admin rotating a leaked token was told it was
  revoked when the old token stayed valid, and a site disconnected from the
  dashboard could not be reconnected, because no token the screen displayed
  ever authenticated. The token is no longer registered as a setting (it is
  display-only, so nothing submits it), and regeneration now stores the new
  hash with a single compare-and-swap, out of reach of any option filter — a
  token is revealed only when that one statement reports it wrote the row, and
  a site whose row is missing or empty can be given its first token the same
  way.

= 2.10.2 =
* Fix: a site moved from one Aura client to another while the old client's
  last push was still in flight could end up holding the old client's ruleset
  and refuse the new client's rules until it was reconnected. The connect
  callback now names the client the site belongs to (a signed, optional field
  — older dashboards keep working unchanged) and writes that binding into the
  ruleset store itself, so a ruleset for any other client is refused from then
  on, whatever was in flight.

= 2.10.1 =
* Fix: `audit_rules` could report zero blocked/warned events for the current
  hour. Reading the counters before the hour's first refusal put the bucket in
  WordPress's negative option cache, and the refusal's atomic insert did not
  clear it — so the count stayed at zero for the rest of the request, and on a
  site with a persistent object cache until the cache was flushed. Enforcement
  was never affected; only what the audit reported.
* Fix: two rulesets pushed to a site at the same moment, before it held any,
  could answer the loser with 500 instead of the ordinary "a newer ruleset is
  already installed" decision. Aura retries a 500, so no policy was lost; the
  site now classifies the database's duplicate-key or deadlock answer as the
  lost race it is.

= 2.10.0 =
* New: operator rules, enforced on the site. A rule is an Aura memory entry
  (`rule/<slug>`) naming a resource — the whole site, a page or post by ID, or a
  plugin by slug — with an effect of `block` or `warn` and an optional expiry.
  Aura signs the client's whole ruleset with the same key that signs approval
  grants and pushes it to every connected site (`POST /aura/v2/rules`), the site
  verifies it and keeps only a newer one (a replayed older ruleset is refused
  even when validly signed). No ruleset means no policy — nothing is refused. A
  site that has never been reconnected since signed approvals shipped holds no
  gateway key and cannot verify a ruleset; it says so, and Aura tells you to
  reconnect it.
* Enforcement runs on every path a write can take: inside the tool executor
  before anything runs or is snapshotted; explicitly on the legacy REST update
  routes; and at WordPress core's own REST API for posts and pages — so a rule on
  a page holds against Aura's content tools, an assistant with an application
  password, or another plugin's MCP server alike. A `block` refuses the call and
  names the rule; **a rule outranks an approval** — a granted call is still
  refused, and the message says to release the rule first. A `warn` runs and
  attaches the warning. Previews are never blocked; they now report what a call
  touches and which rule would decide it.
* New: `audit_rules` (read-only) — ruleset presence and age, whether the site
  can verify one, 24h block/warn counts, expired-but-listed rules, and the
  enforcement points in this build.
* Every mutating tool now declares what a call touches; one that does not is
  caught by every rule rather than by none.

= 2.9.1 =
* Security (hardening): SiteAgent's tools have been dual-registered as WordPress
  abilities since 2.5.0, and an ability is published to the SITE rather than to
  one server — so any co-installed MCP server that enumerates the site's
  abilities could serve them, including the ones that write. The approval-grant
  enforcement lives in SiteAgent's own REST handlers, which that route never
  touches, so a mutating tool could run through another plugin's MCP server with
  no approval, no snapshot binding, and no audit entry.
  Two guards close it. Tools that require a grant now declare a discovery type
  co-installed servers do not serve, and a grant-requiring ability arriving on
  any transport other than SiteAgent's own is refused unless it carries a valid
  approval grant bound to that exact call.
  Nothing changes for the Aura gateway path or for wp-admin: a grant the gateway
  minted is accepted here unchanged. Read-only tools stay discoverable to other
  MCP clients, which is what the dual registration is for. Approval-bound reads
  (`db_query`) follow the write rule, as they already did on the gateway path.

= 2.9.0 =
* New: a read-only security-audit surface — five tools that report what is on
  the site without changing any of it. `check_core_checksums` compares WordPress
  core files against the official manifest, `scan_executable_files` looks for
  executable files and `.htaccess` overrides under uploads, `audit_admin_accounts`
  and `audit_cron` inventory administrator accounts and scheduled events.
* New: `audit_mcp_exposure` answers a question that only appears once a site runs
  more than one AI assistant — which OTHER MCP servers are registered here, and
  how many of this site's abilities pass the discovery rule such a server
  applies. Abilities are registered site-wide, not to the plugin that declared
  them, so a server resolving targets from that registry (as Angie's does) picks
  up mutating ones that never went through this plugin's approval and audit
  path. The counts are a property of the abilities, not proof that any server
  currently serves them — a server with an explicit tool list reaches only what
  it lists. The tool reports; it changes nothing.
* All five report bounded coverage — `truncated` and `cap`, alongside a pair of
  counts naming what was in scope and what was reached (`total_seen` /
  `returned`, or `files_expected` / `files_checked` for `check_core_checksums`,
  which counts files rather than rows). They stop at their caps rather than
  growing without limit on a large site, and an empty result under
  `truncated: true` means "nothing found before the cap" — it is never reported
  as "clean".
* Compatibility: declared tested up to WordPress 7.1.

= 2.8.2 =
* Security (hardening): the snapshot/rollback engine now reads its stored
  payloads back with `unserialize()` restricted to `allowed_classes => false`,
  so a tampered payload file can no longer instantiate arbitrary PHP objects on
  the restore path (object-injection defense-in-depth). Restores fail closed on
  any object-bearing or malformed payload. The restore paths are unchanged for
  the scalar and array data the engine actually stores.
* Fix: the self-updater deletes its temporary download with `wp_delete_file()`,
  and the SEO auditor reads WordPress core's sitemap state through the sitemaps
  server instead of re-firing the `wp_sitemaps_enabled` filter.
* Internal: PRs are now gated in CI on PHPCS (security/correctness sniffs) and
  the official WordPress Plugin Check, so regressions in the above are caught
  before release. No effect on the shipped plugin.

= 2.8.1 =
* Docs: the listing now describes the optional **SiteAgent Power Pack** companion
  plugin — the governed power tools (file read/write, read-only SQL, allowlisted
  WP-CLI, PHP execution) that are deliberately NOT part of this WordPress.org
  build. It states plainly that the write and code tools stay disabled until the
  site owner arms each one in wp-config.php, that the site must be connected to
  Aura first (that provisions the approval key which makes grant enforcement
  active), and that the model is governance — not a sandbox. No code changes.

= 2.8.0 =
* Internal: Snapshot-engine primitives for reversible meta and multi-post writes —
  `snapshot_meta` (post-meta capture with a `meta` restore kind) and
  `snapshot_posts` (multi-post capture with a `posts` restore kind that recreates
  a deleted post under its original id). These are groundwork for upcoming
  governed Elementor and bulk-post editing; they are not yet exposed over the
  remote snapshot API in this release (which still handles `file` and `option`).
* Fix: SEO-meta writes now return a distinct "Failed to write SEO meta" error when
  a write fails despite input, instead of the misleading "Nothing to update".
* Docs: Listing repositioned around governed, reversible AI, with the approval and
  rollback guarantees scoped to the paths that actually enforce and snapshot.

= 2.7.1 =
* Self-update integrity: when the Aura gateway supplies the release's SHA-256,
  SiteAgent now downloads the zip, verifies its bytes against that digest, and
  refuses to install on a mismatch — so an approved self-update is bound to the
  exact package, not just the URL. Sites without a supplied digest behave as
  before.

= 2.7.0 =
* Approval gate now covers the direct REST write endpoints, not just MCP tools.
  When a site has provisioned Aura's approval key, plugin/theme/core/translation
  updates, batch updates, database migrations, rollbacks, self-update, and
  snapshot create/restore each
  require a fresh single-use signed grant bound to the exact action and
  parameters — so a leaked Site Token can no longer trigger a code update on its
  own. Sites without the key keep working as before (token-only) until they
  reconnect.
* Self-update source allowlist: SiteAgent will only install a self-update zip
  from the official GitHub repository (Digitizers/SiteAgent release downloads),
  over HTTPS. Overridable via the aura_worker_self_update_allowed_hosts filter.

= 2.6.1 =
* Tool self-declaration hardening: six mutating tools (update_plugin_safely,
  cleanup_orphaned_assets, backup_plugins, cleanup_transients, clear_caches,
  set_seo_meta) now explicitly declare themselves non-read-only and
  approval-required instead of inheriting neutral defaults, so any consumer that
  trusts a tool's own annotations gates them correctly. Grant enforcement and
  the Aura gateway's verb-based policy already treated them as writes, so live
  behaviour is unchanged.
* cleanup_orphaned_assets now advertises a preview: its dry-run (find orphans,
  delete nothing) is exposed through the preview API, so the orphaned-media
  sample and count can be inspected without approval before the destructive
  delete — which still requires approval.

= 2.6.0 =
* Signed approval grants (G-grants): every mutating (non read-only) MCP tool
  reached over the Aura gateway (X-Aura-Token) path now requires a single-use,
  Ed25519-signed grant that binds the exact tool, parameters, site, and a short
  validity window — so a stolen site token can only ever run READ tools, never a
  write or a power op. The plugin stores only the gateway's PUBLIC key, so even a
  fully compromised site can't mint its own grants; only the gateway can, and
  only for a human-approved action. The gateway public key is provisioned over
  the HMAC-signed magic-link /connect callback, and enforcement activates only
  once it is present, so existing deployments are unaffected until they
  reconnect. The WordPress Abilities / Application-Password path
  (capability-gated) is unchanged.

= 2.5.0 =
* WordPress Abilities API bridge: SiteAgent tools are now dual-registered as WP
  abilities when the core Abilities API is present, so the official MCP adapter
  and standard MCP clients can discover them (aura/mcp namespace unchanged).
* Hardening (external review): register the abilities category before the
  abilities (else a real Abilities API rejects them); default a missing input to
  {} for parameterless abilities; snapshot engine fails closed when a payload/
  metadata write fails, uses an uncollidable "absent option" sentinel, and post
  restore refuses a missing payload instead of wiping the page; Gutenberg
  update refuses inner_html on a block with nested children and surfaces the
  inner_html change in its preview; the snapshot REST file endpoint jails targets
  to wp-content and refuses wp-config.php; AURA_WORKER_VERSION synced.

= 2.4.0 =
* Gutenberg (block editor) tools: list_page_blocks (read), update_page_block
  (approval-gated, snapshot-first, reversible), create_page_from_blocks
  (draft-first). Ends the Elementor-only gap — Gutenberg is core WP.
* Snapshot engine gains a "post" kind (snapshot_post) so block edits are
  reversible.

= 2.3.0 =
* **Token-only connection** — a valid Aura Site Token now authorizes management on its own. After connecting (magic link or Regenerate Token), the plugin runs requests as the connecting administrator, so Aura no longer needs a WordPress Application Password. Existing app-password connections keep working unchanged. No new tools — the set stays at **18**.
* **Forensics hook** — fires `do_action( 'aura_worker_token_run_as', $user_id, $route )` whenever a request is authorized by token alone and run as an admin, so site owners can distinguish token-run-as from interactive admin actions in their audit log. The admin fallback is now deterministic (lowest-ID administrator).

= 2.2.4 =
* Fix: "Connect to Aura" (magic-link onboarding) now targets the Aura app host (`app.my-aura.app`) instead of the marketing domain, so one-click connect works out of the box. (Sites that set the `AURA_DASHBOARD_URL` constant are unaffected.)

= 2.2.3 =
* Fix: `set_seo_meta` on Yoast — after writing the meta, the cached Yoast indexable is now invalidated so the frontend serves the new SEO title/description immediately instead of the stale value (previously required a manual save/reindex).
* Fix: `perf_check` autoload weight — counts all WP 6.6+ autoload values (`yes`, `on`, `auto-on`, `auto`) instead of only `yes`, so the figure is no longer under-reported on newer cores.
* Fix: `scan_broken_links` — the reported counts now reflect the true number of matches; previously they were capped at the 10-item sample limit. Samples remain capped.
* Fix: `scan_seo` — missing excerpts now count toward the score (an `excerpts` finding is reported) instead of being tallied but ignored.
* Fix: `scan_a11y` document language — verified against the rendered `<html lang>` attribute of the home page rather than the configured locale, so a theme that omits `language_attributes()` is correctly flagged.

= 2.2.2 =
* Feature: On-site SEO-meta tools — two agent tools that read and write a post/page's SEO meta directly on the active SEO plugin (Rank Math, Yoast, or SEOPress):
  * `get_seo_meta` (read) — returns the SEO title, description, and focus keyword.
  * `set_seo_meta` (write, approval-gated) — sets any of title / description / focus keyword; only the fields you pass change.
* Because these run on-site via the plugin's own meta keys (not the SEO plugin's REST endpoint), they work even on sites where a firewall/WAF blocks those endpoints. Built-in tool set is now 18.

= 2.2.1 =
* Feature: Performance & broken-link auditors — two more read-only agent tools, scored/structured and no-AI-cost:
  * `perf_check` (read) — performance posture (persistent object cache, OPcache, page-cache plugin, PHP version, autoload weight, active plugin count, PHP memory limit, expired transients).
  * `scan_broken_links` (read) — link triage over a content sample with NO outbound HTTP: empty/anchor-only links, links to dev/staging hosts, and internal links that don't resolve locally.
* Built-in tool set is now 16.

= 2.2.0 =
* Feature: SEO & accessibility auditors — two new read-only agent tools, scored and no-AI-cost, governed by Aura's risk policy:
  * `scan_seo` (read) — SEO posture (search-engine visibility, permalink structure, XML sitemap, site title) plus a sampled content audit (missing excerpts/featured images, thin content).
  * `scan_a11y` (read) — accessibility audit over sampled content (images missing alt text, non-descriptive link text, missing heading structure, document language attribute).
* Both run fleet-wide through Aura's Fleet MCP Gateway to catch SEO/accessibility regressions across many sites at once.

= 2.1.0 =
* Feature: MCP ops toolset expansion — new agent tools governed by Aura's approval/risk policy:
  * `get_database_info` (read) — database size, largest tables, autoload weight, expired transient count.
  * `scan_security` (read) — scored security posture (file-edit lockdown, debug exposure, SSL, default admin/prefix, open registration, PHP version).
  * `list_users` (read) — users with roles and post counts, admins flagged; never returns secrets.
  * `check_health` (read) — live health gate (home-page HTTP, PHP fatals, white-screen, DB) for wrapping updates.
  * `scan_error_log` (read) — tails and severity-groups the PHP/WordPress error log, surfacing recent fatals.
  * `clear_caches` (write) — flush object cache, opcache, and detected page-cache plugins (W3TC, WP Super Cache, WP Rocket, LiteSpeed, Autoptimize).
  * `cleanup_transients` (write) — remove expired transients to reduce autoload bloat.
  * `backup_plugins` (write) — zip-snapshot one or all active plugins (rollback safety net) before mutating actions.

= 2.0.2 =
* Fix: Removed an arrow character from screenshot caption #1 that WordPress.org wrapped in emoji markup inside the image `alt` attribute, breaking the plugin page's HTML.

= 2.0.1 =
* Docs: readme rewritten for the 2.0 feature set (safe batch updates, rollback, magic-link, MCP), corrected security description, and added v2/MCP endpoint reference.
* Docs: fixed admin menu location — the settings page lives under **Settings → SiteAgent**.

= 2.0.0 =
* Feature: Site health checks — read recent error-log tail, surface PHP/DB/disk status in the health report.
* Feature: Plugin rollback & backup — zip-snapshot a plugin before updating and restore on demand if an update breaks the site.
* Feature: Magic-link admin access — generate a short-lived one-time login link from Aura for support sessions.
* Feature: MCP tools — expose site context, safe plugin updates, asset cleanup, and vulnerability checks to AI agents.
* Security: Site token is now stored hashed (SHA-256) instead of plaintext; existing tokens migrate automatically on first use.
* Security: Brute-force throttling on token authentication (per-IP failure limit).
* Security: Signed magic-link connect — the dashboard callback is HMAC-verified with a one-time secret and replay-protected by timestamp.
* Feature: Regenerate Token button under Settings → SiteAgent.
* Fix: Core database upgrade now reports real failures instead of always returning success (verifies db_version reached the target).
* Improvement: Tested with WordPress 7.0.
* Compliance: WordPress.org Plugin Check fixes — WP_Filesystem usage (no direct file_put_contents), gmdate(), wp_delete_file().

= 1.3.5 =
* Security: Enhanced authentication with timing-safe token comparison.
* Feature: Added optional IP whitelisting for restricted API access.
* Improvement: Support for Cloudflare and reverse proxy headers in IP detection.
* Fix: Improved compatibility with WordPress 6.7.

= 1.3.0 =
* Performance: Optimized REST API endpoints for faster health reports.
* UI: Updated admin interface under Tools for better clarity.

= 1.0.0 =
* Initial release.
* REST API endpoints for site health, available updates, core/plugin/theme/translation/database updates.
* Auto-generated Site Token.
* Admin page under Tools → SiteAgent.
* Zero frontend performance impact.

== Upgrade Notice ==

= 2.10.3 =
* Fix (security): **"Regenerate Token" revealed a new site token without ever
  storing it.** The option was registered as a read-only setting, and the
  callback enforcing that ran on every write — not only on the settings form —
  so the handler's write was discarded while the one-time reveal still
  appeared. Two consequences: an admin rotating a leaked token was told it was
  revoked when the old token stayed valid, and a site disconnected from the
  dashboard could not be reconnected, because no token the screen displayed
  ever authenticated. The token is no longer registered as a setting (it is
  display-only, so nothing submits it), and regeneration now stores the new
  hash with a single compare-and-swap, out of reach of any option filter — a
  token is revealed only when that one statement reports it wrote the row, and
  a site whose row is missing or empty can be given its first token the same
  way.

= 2.10.2 =
* Fix: a site moved from one Aura client to another while the old client's
  last push was still in flight could end up holding the old client's ruleset
  and refuse the new client's rules until it was reconnected. The connect
  callback now names the client the site belongs to (a signed, optional field
  — older dashboards keep working unchanged) and writes that binding into the
  ruleset store itself, so a ruleset for any other client is refused from then
  on, whatever was in flight.

= 2.10.1 =
Fixes audit_rules under-reporting the current hour's block/warn counts on
sites with a persistent object cache, and a spurious 500 when two first
rulesets race. No change to enforcement. Recommended.

= 2.10.0 =
Operator rules: write "do not touch checkout" once in Aura and every connected
site refuses the matching change — even an approved one — until the rule is
released. No ruleset, no change in behaviour. Recommended for every site.

= 2.9.1 =
Security hardening: tools that change your site can no longer be run through
another plugin's MCP server without an approval grant. Recommended for every
site, and especially any running a second AI assistant alongside SiteAgent. No
action required; the Aura connection and read-only tools are unaffected.

= 2.9.0 =
Adds five read-only audit tools, including one that reports which other MCP
servers are registered on the site and how many of your abilities are
discoverable to one — worth running if anything else here exposes an AI
assistant. Nothing existing changes behaviour; no action required.

= 2.8.2 =
Security hardening: snapshot restores now reject tampered payloads instead of
unserializing arbitrary objects. Recommended for all users. No action required.

= 2.8.1 =
Documentation only — the listing now describes the optional Power Pack companion
plugin and its governance model. No code changes; no action required.

= 2.8.0 =
Internal snapshot-engine primitives (groundwork for reversible Elementor and
bulk-post editing, not yet exposed over the API) and a clearer SEO-meta
write-failure error. No action required; existing connections keep working.

= 2.3.0 =
Token-only connection: the Aura Site Token alone now authorizes site management. Existing connections keep working — no action required.

= 2.2.4 =
Fixes one-click "Connect to Aura": the magic-link onboarding now targets the Aura app host (`app.my-aura.app`) instead of the marketing domain, so connect works out of the box. Sites that set the `AURA_DASHBOARD_URL` constant are unaffected.

= 2.2.3 =
Accuracy fixes for the auditor tools: `set_seo_meta` refreshes Yoast's cache, `perf_check` counts all WP 6.6+ autoload values, `scan_broken_links` reports true totals, `scan_seo` scores missing excerpts, and `scan_a11y` checks page language. No content changes.

= 2.2.2 =
Adds on-site SEO-meta tools (`get_seo_meta` / `set_seo_meta`) for Rank Math, Yoast, and SEOPress — read and update a page's SEO title, description, and focus keyword, even where a WAF blocks the SEO plugin's REST endpoint. Writes are approval-gated through Aura.

= 2.2.1 =
Adds two read-only auditor tools — `perf_check` and `scan_broken_links` — for performance and link triage across your fleet. No changes to your site; `scan_broken_links` performs no outbound HTTP.

= 2.2.0 =
Adds two read-only auditor tools — `scan_seo` and `scan_a11y` — for SEO and accessibility checks across your fleet. No changes to your site; run on demand through Aura.

= 2.1.0 =
Adds five new MCP agent tools (database info, security scan, user list, cache flush, transient cleanup). Read tools run on demand; cache/transient tools are mutating and gated by Aura's approval policy.

= 2.0.2 =
Fixes the plugin page screenshot caption rendering on WordPress.org. No code changes.

= 2.0.1 =
Documentation update — corrected feature list, security description, endpoint reference, and admin menu location. No code changes.

= 2.0.0 =
Major update: plugin rollback/backup, site health checks, magic-link admin access, and MCP tools. Tested with WordPress 7.0. Recommended for all users.

= 1.3.5 =
Enhanced security with timing-safe comparison and IP whitelisting. Recommended for all users.
