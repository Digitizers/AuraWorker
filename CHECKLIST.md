# AuraWorker — WordPress.org Submission Checklist

Pre-submission verification for publishing AuraWorker v1.2.0 to the WordPress.org plugin directory.

---

## Code Verification

- [x] Text domain is `aura-worker` in all `__()`, `_e()`, `esc_html__()`, `esc_html_e()` calls
- [x] Text domain in plugin header matches: `Text Domain: aura-worker`
- [x] No remaining references to old text domain `aurawp` in PHP strings
- [x] Version alignment: plugin header `Version: 1.2.0` = `AURA_WORKER_VERSION` constant = `Stable tag: 1.2.0` in readme.txt
- [x] All files have `if ( ! defined( 'ABSPATH' ) ) { exit; }` guard
- [x] `uninstall.php` cleans up all `aura_worker_*` options
- [x] `wp_add_privacy_policy_content()` is hooked and working
- [x] No `flush_rewrite_rules()` calls present
- [x] All SQL uses `$wpdb->prepare()`
- [x] All endpoints require `manage_options` capability
- [x] IP whitelist uses `REMOTE_ADDR` only (no proxy headers)
- [x] Token comparison uses `hash_equals()`
- [ ] Plugin activates and deactivates cleanly on WordPress 6.2+
- [ ] Plugin works on PHP 7.4, 8.0, 8.1, 8.2, 8.3

## readme.txt

- [x] `Contributors:` matches exact WordPress.org username — `benkalsky`
- [x] `Tested up to:` matches latest stable WordPress version
- [x] `Stable tag:` matches plugin header `Version:`
- [x] `Requires at least:` and `Requires PHP:` match plugin header
- [x] Short description is under 150 characters
- [x] External service disclosure present with links to Terms and Privacy
- [x] Screenshots section has descriptions
- [x] Changelog section is populated
- [ ] Re-validate at: https://wordpress.org/plugins/developers/readme-validator/

## Plugin Check (PCP)

- [x] Multiline `__()` string collapsed to single literal
- [x] `Update URI` header removed (not allowed on WordPress.org)
- [x] `Domain Path` header removed (no languages directory)
- [x] `@ini_set()` replaced with `wp_raise_memory_limit('admin')`
- [x] `phpcs:ignore` added for `SHOW TABLES` direct query
- [x] Renamed from AuraWP/aurawp to AuraWorker/aura-worker (trademark fix)
- [ ] Re-run PCP — confirm 0 errors, 0 warnings

## External Pages (Aura Dashboard)

- [x] Terms of Service page live at https://my-aura.app/terms
- [x] Privacy Policy page live at https://my-aura.app/privacy
- [x] Both pages accessible without login

## Plugin Assets (for SVN `/assets/` directory)

- [x] `banner-772x250.png` — 772 × 250 px (required)
- [x] `banner-1544x500.png` — 1544 × 500 px (retina, recommended)
- [x] `icon-128x128.png` — 128 × 128 px (required)
- [x] `icon-256x256.png` — 256 × 256 px (retina, recommended)
- [ ] `screenshot-1.png` — Settings page
- [ ] `screenshot-2.png` — Connection test section

## Build & Test

- [x] Build clean ZIP — GitHub Action attaches `aura-worker.zip` to release automatically
- [ ] Test fresh install on clean WordPress site
- [ ] Test activation → settings page → site token generated
- [ ] Test REST endpoints respond with valid auth
- [ ] Test REST endpoints reject without auth
- [ ] Test IP whitelist blocks non-whitelisted IPs
- [ ] Test domain whitelist blocks non-whitelisted origins

## WordPress.org Account

- [x] Account created at wordpress.org
- [x] Two-Factor Authentication enabled
- [ ] `plugins@wordpress.org` whitelisted in email

## Submission

- [ ] Upload ZIP at https://wordpress.org/plugins/developers/add/
- [ ] Write brief plugin overview in submission form
- [ ] Monitor email for reviewer response

## Post-Approval (SVN)

- [ ] Check out SVN repo: `svn co https://plugins.svn.wordpress.org/aura-worker`
- [ ] Copy plugin files to `trunk/` (flat structure, no dev files)
- [ ] Copy asset PNGs to `assets/` with `svn:mime-type image/png`
- [ ] Initial commit: `svn ci -m "Initial release v1.2.0"`
- [ ] Tag release: `svn cp trunk tags/1.2.0 && svn ci -m "Tagging v1.2.0"`
