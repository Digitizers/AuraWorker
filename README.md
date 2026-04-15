<p align="center">
  <img src="assets/aura_icon.png" alt="Aura" width="160" />
</p>
<p align="center">
  <img src="assets/aura_logotype.png" alt="Aura" width="140" />
</p>

<h3 align="center">Digitizer Site Worker for Aura</h3>

<p align="center">
  Official WordPress agent for <a href="https://my-aura.app"><strong>Aura</strong></a>
</p>

<p align="center">
  <a href="https://wordpress.org/plugins/digitizer-site-worker/">
    <img src="https://img.shields.io/badge/WordPress.org-Plugin-blue?logo=wordpress" alt="WordPress.org" />
  </a>
  <img src="https://img.shields.io/badge/WordPress-6.2%E2%80%936.9-21759b?logo=wordpress" alt="WordPress" />
  <img src="https://img.shields.io/badge/PHP-7.4%2B-777bb4?logo=php" alt="PHP" />
  <img src="https://img.shields.io/badge/Stable-1.3.5-green" alt="Stable" />
  <img src="https://img.shields.io/badge/Beta-2.0.0--beta.2-orange" alt="Beta" />
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
| **Safe Update Engine** *(v2)* | Chunked batch updates with health checks and automatic rollback on failure. |
| **Per-Plugin Rollback** *(v2)* | Zip backups in `wp-content/aura-backups/` with one-shot restore. |
| **MCP Tools Layer** *(v2)* | `/aura/mcp/` REST namespace exposing AI-agent-friendly tools with JSON schemas. |
| **Magic Link Onboarding** *(v2)* | One-click connection from wp-admin to the Aura dashboard — no token copy/paste. |
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

### Beta channel (v2.0.0)

The v2 update engine and MCP tools are currently in beta. To install:

```bash
wp plugin install https://github.com/Digitizers/AuraWorker/releases/download/v2.0.0-beta.2/digitizer-site-worker-2.0.0-beta.2.zip --activate
```

Or download the zip from the [latest pre-release](https://github.com/Digitizers/AuraWorker/releases) and upload via **Plugins → Add New → Upload Plugin**.

---

## Security

Three layers of authentication protect every request:

1. **WordPress Auth:** Application Password with capability checks (`manage_options`).
2. **Site Token:** Unique 32-character token required in the `X-Aura-Token` header.
3. **IP Whitelist:** Optional restriction to allow requests only from your Aura instance.

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
| `POST` | `/connect` | Magic-link token exchange (public, 10-min expiring) |

### v2 namespace — `/wp-json/aura/v2/` *(2.0.0 beta)*

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/health` | HTTP, PHP fatals, white-screen, and DB connectivity checks |
| `POST` | `/update/batch` | Chunked batch updates with auto-rollback on health failure |
| `POST` | `/rollback/{plugin}` | Restore a plugin from its most recent zip backup |

### MCP namespace — `/wp-json/aura/mcp/` *(2.0.0 beta)*

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/tools/list` | Enumerate available tools with JSON schemas |
| `POST` | `/tools/execute` | Execute a tool with validated parameters |
| `GET` | `/context` | Full site context for AI decision-making |

Built-in MCP tools: `get_site_context`, `update_plugin_safely`, `cleanup_orphaned_assets`, `check_vulnerabilities`.

---

## Changelog

### 2.0.0-beta.2 *(pre-release)*
- WordPress.org Plugin Check compliance: `WP_Filesystem` for directory deletion, `gmdate()`, `wp_delete_file()`, justified ignores for tail-only log reads.
- `readme.txt` synced to plugin header (title, Stable tag 2.0.0, Tested up to 6.9).

### 2.0.0-beta.1 *(pre-release)*
- **v2 Update Engine:** health checks, per-plugin rollback, chunked batch updates, auto-rollback on failure.
- **MCP Tools Layer:** `/aura/mcp/` namespace with `tools/list`, `tools/execute`, `context`, plus four built-in tools.
- **Magic Link Onboarding:** one-click connection from wp-admin to the Aura dashboard.

### 1.3.5
- Security: timing-safe token comparison, optional IP whitelisting, Cloudflare/reverse-proxy header support.

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
