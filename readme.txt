=== Aura Worker ===
Contributors: digitizer
Tags: management, maintenance, updates, remote, dashboard
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Remote site management agent for Aura dashboard. Secure updates, health monitoring, and maintenance operations via REST API.

== Description ==

Aura Worker is a lightweight WordPress plugin that enables remote site management through the Aura dashboard. It provides secure REST API endpoints for:

* **Site Health Status** - WordPress version, PHP version, plugins, themes, database info, disk usage
* **Plugin Updates** - Update individual plugins remotely
* **Theme Updates** - Update themes remotely
* **Core Updates** - Update WordPress core remotely
* **Translation Updates** - Bulk update all translations
* **Database Updates** - Run database table upgrades after core updates

= Security =

Three layers of authentication protect all API endpoints:

1. **WordPress Application Password** - Standard WordPress REST API authentication
2. **Aura Site Token** - Unique token verified via X-Aura-Token header
3. **IP Whitelist** - Optional IP restriction for additional security

= Requirements =

* WordPress 6.2 or higher
* PHP 7.4 or higher
* WordPress REST API enabled
* Application Password support (WordPress 5.6+)

== Installation ==

1. Upload the `AuraWP` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu
3. Go to Tools > Aura Worker to find your site token
4. Add the site token to your Aura dashboard

== Frequently Asked Questions ==

= Is this plugin safe? =

Yes. All endpoints require three layers of authentication. No actions can be performed without valid credentials.

= Does this plugin slow down my site? =

No. The plugin only loads its REST API endpoints. It has zero impact on frontend performance.

= What happens if I deactivate the plugin? =

Your Aura dashboard will no longer be able to communicate with this site. No data is lost.

== Changelog ==

= 1.0.0 =
* Initial release
* Site health status endpoint
* Plugin, theme, and core update endpoints
* Translation and database update endpoints
* Three-layer security (Application Password + Site Token + IP Whitelist)
* Settings page under Tools menu
