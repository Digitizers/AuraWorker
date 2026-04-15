=== Digitizer Site Worker for Aura ===
Contributors: digitizer
Tags: wordpress management, remote updates, site monitoring, maintenance, dashboard
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WordPress site to the Aura dashboard for remote monitoring, plugin & theme updates, and maintenance — all from one place.

== Description ==

**Digitizer Site Worker** is the bridge between your WordPress sites and the [Aura infrastructure dashboard](https://aura.digitizer.studio) — a unified control center for teams managing multiple WordPress sites alongside servers, CDN, and DNS.

Install this plugin on any WordPress site to unlock remote management capabilities directly from Aura — no SSH, no wp-admin juggling, no manual logins.

= What You Can Do =

* **Monitor site health** — See WordPress version, PHP version, installed plugins & themes, database info, and disk usage in real time.
* **Update plugins remotely** — Push plugin updates to any connected site from the Aura dashboard.
* **Update themes remotely** — Keep themes current across all your sites with a single click.
* **Update WordPress core** — Upgrade to the latest WordPress version without touching wp-admin.
* **Bulk translation updates** — Update all language packs in one operation.
* **Run database upgrades** — Execute WordPress database migrations remotely after updates.
* **Zero frontend impact** — The plugin only registers REST API endpoints. No scripts, no styles, no database queries on visitor-facing page loads.

= How It Works =

After activation, the plugin registers a set of secure REST API endpoints under `/wp-json/aura/v1/`. You copy the auto-generated Site Token from **Tools → Digitizer Site Worker** and paste it into your Aura dashboard. From that point, Aura can communicate with your site to pull health data and push updates.

= Security =

Three layers of authentication protect every request:

1. **WordPress Application Password** — Standard WordPress auth with capability checks (`manage_options` / `update_plugins`). Only authorized administrators can trigger actions.
2. **Site Token** — A unique 32-character token sent via the `X-Aura-Token` header and verified with timing-safe comparison on every request.
3. **IP Whitelist** (optional) — Restrict API access to your Aura instance's IP address only, with full support for Cloudflare and reverse proxy headers.

= REST API Endpoints =

All endpoints live under `/wp-json/aura/v1/`:

* `GET /status` — Full site health report
* `GET /updates` — Check available updates (core, plugins, themes, translations)
* `POST /update/core` — Update WordPress core
* `POST /update/plugin` — Update a specific plugin
* `POST /update/theme` — Update a specific theme
* `POST /update/translations` — Bulk update all translation packs
* `POST /update/database` — Run WordPress database upgrades

= About Aura =

Aura is a full-stack operations dashboard by [Digitizer](https://digitizer.studio) that brings servers, applications, DNS zones, and CDN pull zones from Cloudways, Hostinger VPS, Cloudflare, and Bunny.net into a single unified interface.

Digitizer Site Worker extends that reach into every WordPress installation — so you can manage your entire infrastructure, including WordPress sites, from one place.

= Free to Use =

The plugin is completely free and open source (GPLv2+). You need a free or paid Aura account to connect your sites. [Sign up at aura.digitizer.studio](https://aura.digitizer.studio).

= Links =

* [Aura Dashboard](https://aura.digitizer.studio)
* [Documentation](https://aura.digitizer.studio/auraworker)
* [GitHub Repository](https://github.com/Digitizers/AuraWorker)
* [Digitizer](https://digitizer.studio)

== Installation ==

= Via WordPress Admin (Recommended) =

1. Go to **Plugins → Add New** in your WordPress admin.
2. Search for **Digitizer Site Worker**.
3. Click **Install Now**, then **Activate**.
4. Navigate to **Tools → Digitizer Site Worker**.
5. Copy the generated **Site Token**.
6. In your Aura dashboard, add a new WordPress site and paste the token.

= Via WP-CLI =

`wp plugin install digitizer-site-worker --activate`

= Manual Upload =

1. Download the plugin ZIP from WordPress.org.
2. Go to **Plugins → Add New → Upload Plugin**.
3. Upload the ZIP and click **Install Now**, then **Activate**.
4. Navigate to **Tools → Digitizer Site Worker** to get your Site Token.

== Frequently Asked Questions ==

= Do I need an Aura account? =

Yes, you need an Aura account to connect your WordPress sites. Aura offers a free tier that includes up to 3 WordPress sites. [Sign up at aura.digitizer.studio](https://aura.digitizer.studio).

= Is this plugin safe to use? =

Yes. The plugin uses three authentication layers: WordPress Application Passwords (the same standard mechanism used by Gutenberg and the block editor), a unique per-site token verified with timing-safe comparison, and an optional IP whitelist. No data is transmitted unless a request is made by your Aura instance.

= Does it slow down my site? =

No. The plugin registers only REST API endpoints. It does not load any code, scripts, or database queries on frontend page loads. Your visitors experience zero impact.

= What WordPress versions are supported? =

WordPress 6.2 or higher is required. This is needed for full Application Password support. The plugin has been tested up to WordPress 6.9.

= What PHP versions are supported? =

PHP 7.4 or higher. PHP 8.0+ is recommended.

= Can I restrict which IP addresses can access the API? =

Yes. The plugin supports an optional IP whitelist. If configured, only requests from the specified IP addresses will be accepted. Cloudflare and reverse proxy headers (`CF-Connecting-IP`, `X-Forwarded-For`, `X-Real-IP`) are fully supported for IP detection.

= Does this work with WordPress multisite? =

The plugin is designed for single WordPress installations. Multisite support is not currently available but is on the roadmap.

= Where is the Site Token stored? =

The Site Token is stored as a WordPress option (`aura_site_token`) in your database. It is generated automatically on first activation using `wp_generate_password(32, false)` and is unique to each installation.

= Can I regenerate the Site Token? =

Yes. You can regenerate a new token from **Tools → Digitizer Site Worker**. After regenerating, update the token in your Aura dashboard to maintain the connection.

= How do I disconnect a site from Aura? =

Simply deactivate or delete the plugin, or remove the site from your Aura dashboard. If you deactivate the plugin, the REST API endpoints are unregistered and Aura can no longer communicate with the site.

= Does Aura store my wp-admin credentials? =

No. Aura uses WordPress Application Passwords, not your main admin password. Application Passwords are scoped specifically for REST API access and can be revoked at any time from **Users → Your Profile** in wp-admin.

= Is the plugin open source? =

Yes. Digitizer Site Worker is open source under the GPLv2 or later license. The source code is available on [GitHub](https://github.com/Digitizers/AuraWorker).

== Screenshots ==

1. The Digitizer Site Worker settings page in WordPress admin (Tools → Digitizer Site Worker) showing the Site Token and connection status.
2. The Aura dashboard showing connected WordPress sites with health status, WordPress version, PHP version, and available updates.
3. Remote plugin update in progress from the Aura dashboard — select a plugin and update it with a single click.

== Changelog ==

= 2.0.0 =
* Feature: Site health checks, rollback/backup of plugins, magic-link admin access, MCP tools.
* Improvement: Tested with WordPress 6.9.
* Compliance: WordPress.org Plugin Check fixes (WP_Filesystem usage, gmdate, wp_delete_file).

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
* Admin page under Tools → Digitizer Site Worker.
* Zero frontend performance impact.

== Upgrade Notice ==

= 1.3.5 =
Enhanced security with timing-safe comparison and IP whitelisting. Recommended for all users.
