=== Digitizer Site Worker for Aura ===
Contributors: benkalsky
Tags: management, maintenance, updates, remote, dashboard
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.3.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Remote site management agent for Aura dashboard. Secure updates, health monitoring, and maintenance operations.

== Description ==

Digitizer Site Worker for Aura is a lightweight WordPress plugin that enables remote site management through the Aura dashboard. It provides secure REST API endpoints for:

* **Site Health Status** - WordPress version, PHP version, plugins, themes, database info, disk usage
* **Plugin Updates** - Update individual plugins remotely
* **Theme Updates** - Update themes remotely
* **Core Updates** - Update WordPress core remotely
* **Translation Updates** - Bulk update all translations
* **Database Updates** - Run WordPress core database upgrades and plugin-specific migrations (Elementor, WooCommerce, Crocoblock)

= Security =

Three layers of authentication protect all API endpoints:

1. **WordPress Application Password** - Standard WordPress REST API authentication
2. **Aura Site Token** - Unique token verified via X-Aura-Token header
3. **IP Whitelist** - Optional IP restriction for additional security
4. **Domain Whitelist** - Optional origin domain restriction

= External Service =

This plugin connects to the [Aura dashboard](https://my-aura.app/) to enable remote site management.
When connected, the Aura dashboard sends authenticated REST API requests to your site to check health status and perform updates.
The plugin itself does not send data outbound — it only responds to authenticated incoming requests.

* [Aura Terms of Service](https://my-aura.app/terms)
* [Aura Privacy Policy](https://my-aura.app/privacy)

= Requirements =

* WordPress 6.2 or higher
* PHP 7.4 or higher
* WordPress REST API enabled
* Application Password support (WordPress 5.6+)

== Installation ==

1. Upload the `digitizer-site-worker` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu
3. Go to Tools > Digitizer Site Worker to find your site token
4. Add the site token to your Aura dashboard

== Frequently Asked Questions ==

= Is this plugin safe? =

Yes. All endpoints require three layers of authentication. No actions can be performed without valid credentials.

= Does this plugin slow down my site? =

No. The plugin only loads its REST API endpoints. It has zero impact on frontend performance.

= What happens if I deactivate the plugin? =

Your Aura dashboard will no longer be able to communicate with this site. No data is lost.

== Screenshots ==

1. Digitizer Site Worker settings page — configure your site token, IP whitelist, and domain whitelist.
2. Connection test section — verify your API endpoint and plugin version.
3. Security settings — manage IP and domain whitelists for enhanced protection.
4. Activity log — monitor remote update and maintenance operations.

== Changelog ==

= 1.3.3 =
* Initial release to WordPress.org
* CI/CD deploy automation
* Tested up to WordPress 7.0

= 1.3.2 =
* Fix: Clear plugin cache after self-update to ensure correct version is reported
* Uses wp_clean_plugins_cache() + wp_cache_flush() before reading new version

= 1.3.1 =
* Security: Use specific WordPress capabilities for REST API permission callbacks
  - update/plugin → update_plugins
  - update/core → update_core
  - update/theme → update_themes
  - update/translations → update_core
  - update/database → update_core
  - self-update → update_plugins
* Addresses WordPress.org plugin review feedback (Review ID: R digitizer-site-worker/benkalsky/22Mar26)

= 1.3.0-beta.6 =
* Fix: Run Elementor upgrade callbacks directly and synchronously, bypassing the background runner that relies on loopback HTTP/WP-Cron — works reliably regardless of DISABLE_WP_CRON setting

= 1.3.0-beta.5 =
* Fix: Elementor migrations now deferred to WP-Cron instead of running inline — prevents REST API timeout from loopback HTTP blocking

= 1.3.0-beta.4 =
* Feature: Self-update endpoint (POST /self-update) — update AuraWorker from a GitHub release zip URL
* Feature: URL validation restricts self-update to official Digitizers/AuraWorker GitHub releases

= 1.3.0-beta.3 =
* Fix: Elementor/Elementor Pro migrations now run asynchronously via Elementor's batched background task system
* Fix: Removed premature version option update that marked migrations as complete before background tasks finished

= 1.3.0-beta.2 =
* Fix: Database migrations could timeout — added set_time_limit(120) for long-running migrations
* Fix: Catch \Throwable instead of \Exception to handle PHP fatal errors during migrations

= 1.3.0-beta.1 =
* Feature: Plugin-specific database migration support (Elementor, Elementor Pro, WooCommerce, JetEngine/Crocoblock)
* Feature: New GET /database-status endpoint — returns pending migration status for detected plugins
* Feature: POST /update/database now accepts optional `plugin` parameter for targeted migrations
* Feature: `aura_worker_migration_registry` filter for third-party plugin migration registration

= 1.2.0 =
* Security: Fix IP whitelist bypass via spoofable proxy headers — now uses REMOTE_ADDR only
* Security: Standardize capability checks to manage_options for all endpoints
* Security: Protect site token from form overwrite via sanitize_callback
* Security: Cast token header to string for PHP 8 compatibility
* Security: Add validate_callback with regex for plugin/theme parameters
* Fix: update_core() missing false check — filesystem failures silently reported success
* Fix: update_core() sprintf received array instead of version string
* Fix: update_core() missing is_array guard before accessing updates array
* Fix: update_plugin() and update_theme() treated null return as success
* Fix: update_translations() dead is_wp_error check — false return reported as success
* Fix: Raw SQL interpolation in get_status() — now uses $wpdb->prepare()
* Fix: Disk usage iterator changed from SELF_FIRST to LEAVES_ONLY
* Cleanup: Removed duplicate require_once in update_core()
* Cleanup: Removed unnecessary flush_rewrite_rules() calls

= 1.0.0 =
* Initial release
* Site health status endpoint
* Plugin, theme, and core update endpoints
* Translation and database update endpoints
* Three-layer security (Application Password + Site Token + IP Whitelist)
* Settings page under Tools menu
