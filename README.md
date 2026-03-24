<p align="center">
  <img src="assets/aura_icon.png" alt="Aura" width="200" />
</p>
<p align="center">
  <img src="assets/aura_logotype.png" alt="Aura" width="160" />
</p>

<h3 align="center">Digitizer Site Worker for Aura</h3>

<p align="center">
  Lightweight WordPress plugin that connects your sites to the<br/>
  <a href="https://my-aura.app"><strong>Aura Infrastructure Hub</strong></a>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/WordPress-6.2%2B-21759b?logo=wordpress" alt="WordPress" />
  <img src="https://img.shields.io/badge/PHP-7.4%2B-777bb4?logo=php" alt="PHP" />
  <img src="https://img.shields.io/badge/License-GPLv2-blue" alt="License" />
  <img src="https://img.shields.io/badge/Version-1.3.0-green" alt="Version" />
</p>

---

## What is AuraWorker?

Digitizer Site Worker is a remote management agent that runs on your WordPress sites and communicates with the [Aura Infrastructure Hub](https://my-aura.app). It exposes secure REST API endpoints that allow Aura to monitor site health, apply updates, and perform maintenance — all from a single centralized interface.

> **Aura** is a full-stack, multi-provider infrastructure dashboard that unifies server, application, DNS, and CDN management across **Cloudways**, **Hostinger VPS**, **Cloudflare**, and **Bunny.net**. The plugin extends that reach directly into your WordPress installations.

---

## Features

| Capability | Description |
|------------|-------------|
| **Site Health** | WordPress & PHP version, installed plugins/themes, database info, disk usage, server software |
| **Plugin Updates** | Update individual plugins remotely |
| **Theme Updates** | Update themes remotely |
| **Core Updates** | Upgrade WordPress core to the latest version |
| **Translation Updates** | Bulk update all translation packs |
| **Database Updates** | Run `wp_upgrade` / `dbDelta` after core updates |

### Zero Frontend Impact

Digitizer Site Worker only loads its REST API routes. It adds nothing to your site's frontend — no scripts, no styles, no database queries on page load.

---

## Security

Three layers of authentication protect every endpoint:

| Layer | Mechanism | Details |
|-------|-----------|---------|
| **1. WordPress Auth** | Application Password | Standard REST API authentication with capability checks (`manage_options` / `update_plugins`) |
| **2. Site Token** | `X-Aura-Token` header | Auto-generated 32-character token unique to each site |
| **3. IP Whitelist** | Optional | Restrict API access to specific IP addresses (supports Cloudflare and proxy headers) |

All three layers must pass before any request is processed.

---

## Installation

### WP CLI

```bash
wp plugin install https://github.com/Digitizers/AuraWorker/releases/latest/download/digitizer-site-worker-v1.3.0.zip --activate
```

### Manual Upload

1. Download the latest release (`digitizer-site-worker-v1.3.0.zip`)
2. Upload the `digitizer-site-worker` folder to `/wp-content/plugins/`
3. Activate the plugin via **Plugins** in your WordPress admin
4. Navigate to **Tools &rarr; Digitizer Site Worker** to view your site token

### Connect to Aura

1. In the Aura dashboard, add your WordPress site as a resource
2. Enter the site URL and the **Site Token** from the AuraWorker settings page
3. Configure a WordPress **Application Password** for API authentication
4. (Optional) Add the Aura server's IP to the **IP Whitelist** for extra security

---

## REST API

All endpoints are registered under `/wp-json/aura/v1/`.

### Read Endpoints

| Method | Endpoint | Capability | Description |
|--------|----------|------------|-------------|
| `GET` | `/status` | `manage_options` | Full site health report |
| `GET` | `/updates` | `manage_options` | Available updates (core, plugins, themes, translations) |

### Write Endpoints

| Method | Endpoint | Capability | Description |
|--------|----------|------------|-------------|
| `POST` | `/update/core` | `update_plugins` | Update WordPress core |
| `POST` | `/update/plugin` | `update_plugins` | Update a specific plugin |
| `POST` | `/update/theme` | `update_plugins` | Update a specific theme |
| `POST` | `/update/translations` | `update_plugins` | Bulk update translations |
| `POST` | `/update/database` | `update_plugins` | Run database upgrades |

---

## Pending WordPress.org Approval

Pending approval on WordPress.org as `digitizer-site-worker`.

---

## Architecture

```
AuraWorker/
├── digitizer-site-worker.php                # Plugin entry point
├── uninstall.php                            # Cleanup on uninstall
├── readme.txt                               # WordPress.org readme
└── includes/
    ├── class-aura-worker.php                # Main orchestrator — routes, settings page
    ├── class-aura-worker-api.php            # REST API handlers (status, updates)
    ├── class-aura-worker-updater.php        # Update operations (core, plugins, themes)
    └── class-aura-worker-security.php       # Three-layer authentication
```

---

## Part of the Aura Ecosystem

<table>
  <tr>
    <td width="50%" valign="top">
      <h4><a href="https://my-aura.app">Aura &mdash; Infrastructure Hub</a></h4>
      <p>The central dashboard that manages servers, applications, DNS zones, and CDN pull zones across Cloudways, Hostinger VPS, Cloudflare, and Bunny.net.</p>
      <p><sub>Next.js &middot; TypeScript &middot; Prisma &middot; PostgreSQL</sub></p>
    </td>
    <td width="50%" valign="top">
      <h4>Digitizer Site Worker &mdash; WordPress Agent</h4>
      <p>This plugin. Installed on each WordPress site to enable remote health monitoring, updates, and maintenance from the Aura dashboard.</p>
      <p><sub>PHP &middot; WordPress REST API</sub></p>
    </td>
  </tr>
</table>

---

## Requirements

- WordPress 6.2+
- PHP 7.4+
- WordPress REST API enabled
- Application Password support (WordPress 5.6+)

---

## FAQ

**Does this plugin slow down my site?**
No. AuraWorker only registers REST API endpoints. It has zero impact on frontend performance.

**What happens if I deactivate the plugin?**
The Aura dashboard will no longer be able to communicate with the site. No data is lost — reactivate to reconnect.

**Is it safe to use on production sites?**
Yes. All endpoints require three layers of authentication. No actions can be performed without valid credentials.

---

## Changelog

### 1.2.0
- Security: Fix IP whitelist bypass — now uses only `REMOTE_ADDR`
- Security: Standardize capability checks to `manage_options` for all endpoints
- Security: Protect site token from form overwrite
- Security: Add input validation for plugin/theme parameters
- Security: PHP 8 compatibility for token comparison
- Fix: `update_core()` missing error checks and wrong sprintf type
- Fix: `update_plugin()`/`update_theme()` treated null return as success
- Fix: `update_translations()` silent failure on error
- Fix: Raw SQL in status endpoint now uses `$wpdb->prepare()`
- Fix: Disk usage iterator optimization
- Cleanup: Removed redundant code and unnecessary `flush_rewrite_rules()`

### 1.1.0
- Memory optimization for update operations (auto-scales to 256MB)
- Transient caching for update checks
- Settings page moved to Tools menu
- Rebranded from "AuraWP" to "AuraWorker"

### 1.0.0
- Initial release
- Site health status endpoint
- Plugin, theme, core, translation, and database update endpoints
- Three-layer security model
- WordPress admin settings page

---

## License

GPLv2 or later — [License](https://www.gnu.org/licenses/gpl-2.0.html)

---

Built with care by [Digitizer](https://www.digitizer.studio) for the [Aura](https://my-aura.app) ecosystem
