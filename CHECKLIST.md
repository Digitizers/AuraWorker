# AuraWP — WordPress.org Submission Checklist

Pre-submission verification for publishing AuraWP v1.2.0 to the WordPress.org plugin directory.

---

## Code Verification

- [ ] Text domain is `aurawp` in all `__()`, `_e()`, `esc_html__()`, `esc_html_e()` calls
- [ ] Text domain in plugin header matches: `Text Domain: aurawp`
- [ ] No remaining references to old text domain `aura-worker` in PHP strings
- [ ] Version alignment: plugin header `Version: 1.2.0` = `AURA_WORKER_VERSION` constant = `Stable tag: 1.2.0` in readme.txt
- [ ] All files have `if ( ! defined( 'ABSPATH' ) ) { exit; }` guard
- [ ] `uninstall.php` cleans up all `aura_worker_*` options
- [ ] `wp_add_privacy_policy_content()` is hooked and working
- [ ] No `flush_rewrite_rules()` calls present
- [ ] All SQL uses `$wpdb->prepare()`
- [ ] All endpoints require `manage_options` capability
- [ ] IP whitelist uses `REMOTE_ADDR` only (no proxy headers)
- [ ] Token comparison uses `hash_equals()`
- [ ] Plugin activates and deactivates cleanly on WordPress 6.2+
- [ ] Plugin works on PHP 7.4, 8.0, 8.1, 8.2, 8.3

## readme.txt

- [ ] `Contributors:` matches exact WordPress.org username (case-sensitive)
- [ ] `Tested up to:` matches latest stable WordPress version
- [ ] `Stable tag:` matches plugin header `Version:`
- [ ] `Requires at least:` and `Requires PHP:` match plugin header
- [ ] Short description is under 150 characters
- [ ] External service disclosure present with links to Terms and Privacy
- [ ] Screenshots section has descriptions
- [ ] Changelog section is populated
- [ ] Validate at: https://wordpress.org/plugins/developers/readme-validator/

## External Pages (Aura Dashboard)

- [ ] Terms of Service page live at https://my-aura.app/terms
- [ ] Privacy Policy page live at https://my-aura.app/privacy
- [ ] Both pages accessible without login

## Plugin Assets (for SVN `/assets/` directory)

- [ ] `banner-772x250.png` — 772 × 250 px (required)
- [ ] `banner-1544x500.png` — 1544 × 500 px (retina, recommended)
- [ ] `icon-128x128.png` — 128 × 128 px (required)
- [ ] `icon-256x256.png` — 256 × 256 px (retina, recommended)
- [ ] `screenshot-1.png` — Settings page
- [ ] `screenshot-2.png` — Connection test section

## Build & Test

- [ ] Run Plugin Check (PCP) plugin — all checks pass
- [ ] Test fresh install on clean WordPress site
- [ ] Test activation → settings page → site token generated
- [ ] Test REST endpoints respond with valid auth
- [ ] Test REST endpoints reject without auth
- [ ] Test IP whitelist blocks non-whitelisted IPs
- [ ] Test domain whitelist blocks non-whitelisted origins
- [ ] Build clean ZIP (exclude `.git`, `.github`, `CLAUDE.md`, `README.md`, `CHECKLIST.md`, `.DS_Store`, `assets/`)

## WordPress.org Account

- [ ] Account created at wordpress.org
- [ ] Two-Factor Authentication enabled
- [ ] `plugins@wordpress.org` whitelisted in email

## Submission

- [ ] Upload ZIP at https://wordpress.org/plugins/developers/add/
- [ ] Write brief plugin overview in submission form
- [ ] Monitor email for reviewer response

## Post-Approval (SVN)

- [ ] Check out SVN repo: `svn co https://plugins.svn.wordpress.org/aurawp`
- [ ] Copy plugin files to `trunk/` (flat structure, no dev files)
- [ ] Copy asset PNGs to `assets/` with `svn:mime-type image/png`
- [ ] Initial commit: `svn ci -m "Initial release v1.2.0"`
- [ ] Tag release: `svn cp trunk tags/1.2.0 && svn ci -m "Tagging v1.2.0"`

## Remaining To-Do (manual tasks)

- [ ] Export assets to PNG — Convert SVGs to PNG (banners 772x250, 1544x500; icons 128x128, 256x256) and export HTML screenshot mockups to PNG  
- [ ] Create WordPress.org account with 2FA enabled
- [ ] Validate readme.txt at wordpress.org/plugins/developers/readme-validator/
- [ ] Run Plugin Check (PCP) on a local WordPress install
- [ ] Test fresh install — activate, settings page, token generation, REST endpoints with/without auth
- [ ] Deploy Aura dashboard — so /terms and /privacy are live at my-aura.app
- [ ] Build clean ZIP — exclude .git, CLAUDE.md, README.md, CHECKLIST.md, assets/, .DS_Store
- [ ] Submit at wordpress.org/plugins/developers/add/