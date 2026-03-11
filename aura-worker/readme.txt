=== AuraWorker ===
Contributors: benkalsky
Tags: management, maintenance, updates, remote, dashboard
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.3.0-beta.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Remote site management agent for Aura dashboard. Secure updates, health monitoring, and maintenance operations.

== Description ==

AuraWorker is a lightweight WordPress plugin that enables remote site management through the Aura dashboard. It provides secure REST API endpoints for:

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

1. Upload the `AuraWorker` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu
3. Go to Tools > AuraWorker to find your site token
4. Add the site token to your Aura dashboard

== Frequently Asked Questions ==

= Is this plugin safe? =

Yes. All endpoints require three layers of authentication. No actions can be performed without valid credentials.

= Does this plugin slow down my site? =

No. The plugin only loads its REST API endpoints. It has zero impact on frontend performance.

= What happens if I deactivate the plugin? =

Your Aura dashboard will no longer be able to communicate with this site. No data is lost.

== Screenshots ==

1. AuraWorker settings page — configure your site token, IP whitelist, and domain whitelist
2. Connection test section — verify your API endpoint and plugin version

== Changelog ==

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
