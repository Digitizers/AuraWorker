# CLAUDE.md — Digitizer Site Worker for Aura

This file provides context and conventions for AI assistants working in this repository.

---

## Project Overview

**Digitizer Site Worker for Aura** is a WordPress plugin that acts as a remote site management agent for the [Aura Infrastructure Hub](https://my-aura.app). It exposes secure REST API endpoints that allow Aura to monitor site health, apply updates (core, plugins, themes, translations), and perform database maintenance.

- **Language:** PHP 7.4+
- **Platform:** WordPress 6.2+
- **Auth:** Three-layer (WordPress Application Password + Aura Site Token + optional IP Whitelist)
- **REST Namespace:** `aura/v1`
- **License:** GPLv2 or later
- **Text Domain:** `digitizer-site-worker`
- **WordPress.org Slug:** `digitizer-site-worker`

---

## Repository Structure

```
AuraWorker/                                      # Repo root (development)
├── CLAUDE.md                                # AI assistant instructions (dev only)
├── CHECKLIST.md                             # Development checklist (dev only)
├── README.md                                # GitHub readme (dev only)
├── LICENSE                                  # GPLv2 license text (dev only)
├── assets/                                  # WordPress.org plugin page assets (NOT shipped)
│   ├── aura_logotype.png                    # Logo asset
│   ├── banner-772x250.svg                   # Standard banner
│   ├── banner-1544x500.svg                  # Retina banner
│   ├── icon-128x128.svg                     # Standard icon
│   └── icon-256x256.svg                     # Retina icon
└── digitizer-site-worker/                                  # ← Clean plugin folder (zip this for installation)
    ├── digitizer-site-worker.php                      # Plugin entry point, activation/deactivation hooks
    ├── uninstall.php                        # Cleanup on uninstall (removes all options)
    ├── readme.txt                           # WordPress.org plugin readme
    └── includes/
        ├── class-aura-worker.php            # Main orchestrator — admin menu, settings, wiring
        ├── class-aura-worker-api.php        # REST API route registration and handlers
        ├── class-aura-worker-updater.php    # Update operations (core, plugins, themes, translations, DB)
        └── class-aura-worker-security.php   # Three-layer authentication and permission callbacks
```

To create an installable ZIP: `cd` to the repo root and run `zip -r aura-worker.zip aura-worker/`.

---

## Architecture

### Class Responsibilities

| Class | File | Role |
|-------|------|------|
| `Digitizer_Site_Worker` | `digitizer-site-worker/includes/class-digitizer-site-worker.php` | Orchestrator — creates Security and API instances, registers admin menu and settings |
| `Digitizer_Site_Worker_API` | `digitizer-site-worker/includes/class-digitizer-site-worker-api.php` | Registers all REST routes under `aura/v1`, handles request/response logic |
| `Digitizer_Site_Worker_Updater` | `digitizer-site-worker/includes/class-digitizer-site-worker-updater.php` | Wraps WordPress Upgrader classes for core/plugin/theme/translation/DB updates |
| `Digitizer_Site_Worker_Security` | `digitizer-site-worker/includes/class-digitizer-site-worker-security.php` | Implements IP whitelist, site token verification, and capability checks |

### Initialization Flow

1. `digitizer-site-worker.php` defines constants and loads all class files
2. `digitizer_site_worker_init()` runs on `plugins_loaded` — creates `Digitizer_Site_Worker` and calls `init()`
3. `init()` creates `Digitizer_Site_Worker_Security`, passes it to `Digitizer_Site_Worker_API`
4. `Digitizer_Site_Worker_API` internally creates its own `Digitizer_Site_Worker_Updater` instance
5. REST routes are registered on `rest_api_init`
6. Admin settings page is registered on `admin_menu` / `admin_init` (admin only)

### Security Layers

Every REST request passes through three checks in order:

1. **IP Whitelist** (`check_ip_whitelist`) — If IPs are configured in settings, the client IP must match. Uses `REMOTE_ADDR` only (proxy headers are not trusted).
2. **Domain Whitelist** (`check_domain_whitelist`) — If domains are configured, the request's `Origin` or `Referer` header must match.
3. **Aura Site Token** (`check_aura_token`) — `X-Aura-Token` header must match the stored token. Comparison uses `hash_equals()` for timing safety.
4. **WordPress Capability** — All endpoints require `manage_options`.

---

## REST API Endpoints

All routes are under `/wp-json/aura/v1/`.

### Read Endpoints (require `manage_options`)

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/status` | Full site health: WP/PHP/MySQL versions, plugins, themes, disk usage, DB info |
| `GET` | `/updates` | Available updates for core, plugins, themes, translations. Add `?refresh=1` to force fresh check |

### Write Endpoints (require `manage_options`)

| Method | Endpoint | Parameters | Description |
|--------|----------|------------|-------------|
| `POST` | `/update/core` | — | Update WordPress core |
| `POST` | `/update/plugin` | `plugin` (required, string) | Update a specific plugin by file path (e.g. `akismet/akismet.php`) |
| `POST` | `/update/theme` | `theme` (required, string) | Update a specific theme by slug |
| `POST` | `/update/translations` | — | Bulk update all translations |
| `POST` | `/update/database` | — | Run `wp_upgrade()` / `dbDelta()` |

---

## WordPress Options

| Option Key | Description |
|------------|-------------|
| `digitizer_site_worker_site_token` | 32-char alphanumeric token for API auth |
| `digitizer_site_worker_allowed_ips` | Newline-separated IP whitelist (empty = allow all) |
| `digitizer_site_worker_allowed_domains` | Newline-separated domain whitelist (empty = allow all) |
| `digitizer_site_worker_activated` | Activation timestamp |
| `digitizer_site_worker_version` | Plugin version at activation |

All options are cleaned up in `uninstall.php`.

---

## Code Conventions

### PHP Style

- Follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)
- Tabs for indentation (not spaces)
- Yoda conditions are acceptable but not required
- All files must start with `if ( ! defined( 'ABSPATH' ) ) { exit; }` guard
- Use WordPress i18n functions (`__()`, `esc_html_e()`) with text domain `digitizer-site-worker`

### Naming

| Kind | Convention | Example |
|------|-----------|---------|
| Classes | `Digitizer_Site_Worker_*` prefix | `Digitizer_Site_Worker_Security` |
| Files | `class-digitizer-site-worker-*.php` | `class-digitizer-site-worker-api.php` |
| Functions (global) | `digitizer_site_worker_*` prefix | `digitizer_site_worker_activate` |
| Options | `digitizer_site_worker_*` prefix | `digitizer_site_worker_site_token` |
| Constants | `DIGITIZER_SITE_WORKER_*` | `DIGITIZER_SITE_WORKER_VERSION` |
| REST namespace | `aura/v1` | — |
| Settings group | `aura_worker_settings` | — |

### Security Rules

- **Never store secrets in plaintext** — site tokens and credentials should be hashed before storage
- **Always use `$wpdb->prepare()`** for any SQL query with dynamic values, even trusted ones like `$wpdb->prefix`
- **Always use `sanitize_text_field()`** or appropriate sanitizer on user input
- **Always use `esc_attr()`, `esc_html()`, `esc_url()`** for output escaping
- **Use `hash_equals()`** for all token/secret comparisons (timing-safe)
- **Validate WordPress Upgrader return values thoroughly** — `Plugin_Upgrader::upgrade()` can return `true`, `false`, `null`, `WP_Error`, or an array depending on the outcome. Always check for `is_wp_error()`, `false === $result`, and `null === $result` before assuming success.
- **Use `wp_unslash()` before `sanitize_*()`** on `$_SERVER` values

### Error Handling

- Return structured arrays from updater methods: `array( 'success' => bool, 'message' => string )` or `array( 'success' => false, 'error' => string )`
- REST handlers wrap results in `WP_REST_Response` with appropriate HTTP status codes (200, 404, 500)
- Use `WP_Error` objects in security/permission callbacks — WordPress REST API will convert these to proper error responses

### Dependency Loading

- Use `require_once` for WordPress admin includes (they are not always loaded in REST context)
- Always check `function_exists()` before requiring admin files (e.g., `get_plugins`, `get_core_updates`)
- The `load_upgrade_dependencies()` method in the Updater class centralizes all upgrade-related includes

---

## Known Issues

Most issues from the initial code review were fixed in v1.2.0. Remaining items:

1. **Token stored in plaintext** — The site token is stored as-is in `wp_options`. Ideally should store a SHA-256 hash and only show the raw token once at generation. Low risk since the token is already protected by the settings page capability check.
2. **No token rotation UI** — There is no "Regenerate Token" button. The only way to rotate is to delete the option and re-activate the plugin.
3. **No rate limiting on token validation** — An attacker can brute-force the `X-Aura-Token` header without throttling. Consider adding transient-based failed-attempt tracking.
4. **`update_database()` always returns success** — `wp_upgrade()` has no error channel; SQL failures are silently swallowed.

---

## Testing

There are currently no automated tests. When adding tests:

- Use [WP_Mock](https://github.com/10up/wp_mock) or WordPress's `WP_UnitTestCase` for unit/integration tests
- Test each updater method with mock return values (`true`, `false`, `null`, `WP_Error`)
- Test security layers independently (IP check, token check, capability check)
- Test REST endpoint registration and response shapes

---

## Relationship to Aura

Digitizer Site Worker for Aura is the WordPress-side companion to the [Aura Infrastructure Hub](https://my-aura.app) (Next.js dashboard). Aura manages cloud resources across Cloudways, Hostinger VPS, Cloudflare, and Bunny.net. Digitizer Site Worker for Aura extends that reach into individual WordPress installations, allowing Aura to monitor and update sites remotely.

The communication flow is:
```
Aura Dashboard → HTTP REST → WordPress (Digitizer Site Worker for Aura plugin)
                  ↑
          Application Password + X-Aura-Token header
```
