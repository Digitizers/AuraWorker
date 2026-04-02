<p align="center">
  <img src="assets/aura_icon.png" alt="Aura" width="160" />
</p>

<h3 align="center">Digitizer Site Worker for Aura</h3>

<p align="center">
  Official WordPress agent for <a href="https://my-aura.app"><strong>Aura</strong></a>
</p>

<p align="center">
  <a href="https://wordpress.org/plugins/digitizer-site-worker/">
    <img src="https://img.shields.io/badge/WordPress.org-Plugin-blue?logo=wordpress" alt="WordPress.org" />
  </a>
  <img src="https://img.shields.io/badge/WordPress-6.2%2B-21759b?logo=wordpress" alt="WordPress" />
  <img src="https://img.shields.io/badge/PHP-7.4%2B-777bb4?logo=php" alt="PHP" />
  <img src="https://img.shields.io/badge/Version-1.3.4-green" alt="Version" />
</p>

---

## What is Digitizer Site Worker?

Digitizer Site Worker is the official remote management agent for the [Aura Infrastructure Hub](https://my-aura.app). It connects your WordPress sites to your Aura dashboard for seamless remote management, monitoring, and updates from a single centralized interface.

---

## Features

| Capability | Description |
|------------|-------------|
| **Site Health** | Real-time monitoring of WordPress & PHP versions, plugins, themes, and server health. |
| **One-Click Updates** | Update WordPress core, plugins, and themes remotely from the Aura dashboard. |
| **Maintenance** | Run database upgrades and translation updates across all sites. |
| **Enterprise Security** | Protected by three layers of authentication (WordPress Passwords, Site Tokens, IP Whitelist). |
| **Developer API** | Fully exposed via secure REST API endpoints. |

### Zero Frontend Impact

Digitizer Site Worker is built for performance. It only registers REST API routes and has **zero impact** on your site's frontend performance — no extra scripts, styles, or queries on page load.

---

## Installation

### Via WordPress.org (Recommended)
1. Go to **Plugins > Add New** in your WordPress admin.
2. Search for **Digitizer Site Worker**.
3. Click **Install Now** and then **Activate**.

### Via WP-CLI
```bash
wp plugin install digitizer-site-worker --activate
```

---

## Security

Three layers of authentication protect every request:

1. **WordPress Auth:** Application Password with capability checks (`manage_options`).
2. **Site Token:** Unique 32-character token required in the `X-Aura-Token` header.
3. **IP Whitelist:** Optional restriction to allow requests only from your Aura instance.

---

## REST API

All endpoints live under `/wp-json/aura/v1/`.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/status` | Full site health report |
| `GET` | `/updates` | Check available core, plugin, and theme updates |
| `POST` | `/update/core` | Upgrade WordPress core |
| `POST` | `/update/plugin` | Update a specific plugin |
| `POST` | `/update/theme` | Update a specific theme |
| `POST` | `/update/translations` | Bulk update translation packs |
| `POST` | `/update/database` | Run WordPress database upgrades |

---

## Changelog

### 1.3.4
- **Branding Update:** New official icons and banners for WordPress.org.
- **Improved UX:** Updated documentation and installation guides.

### 1.3.3
- **Official WordPress.org Launch:** Now available in the official plugin repository.
- GitHub Release: [v1.3.3](https://github.com/Digitizers/AuraWorker/releases/tag/v1.3.3)

### 1.3.0
- Rebranded from "AuraWorker" to "Digitizer Site Worker for Aura"
- New slug: `digitizer-site-worker`

---

Built with ❤️ by [Digitizer](https://digitizer.co.il) for the [Aura](https://my-aura.app) ecosystem
