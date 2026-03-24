# Digitizer Site Worker v2.0 — Technical Specification

**Project:** Digitizer Site Worker for Aura  
**Current Version:** 1.3.0  
**Target Version:** 2.0.0  
**Date:** March 24, 2026  
**Author:** Digitizer Development Team

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Architecture](#2-architecture)
3. [Authentication & Security](#3-authentication--security)
4. [Site Health & Monitoring](#4-site-health--monitoring)
5. [Update Management](#5-update-management)
6. [Performance & Cache](#6-performance--cache)
7. [Security Monitoring](#7-security-monitoring)
8. [Database Operations](#8-database-operations)
9. [File System](#9-file-system)
10. [Backup Integration](#10-backup-integration)
11. [REST API Design](#11-rest-api-design)
12. [File Structure](#12-file-structure)
13. [Implementation Phases](#13-implementation-phases)
14. [What We DON'T Build](#14-what-we-dont-build)

---

## 1. Executive Summary

### 1.1 What v2 Brings vs v1.3

**Current State (v1.3.0):**
- ~500 lines of code across 4 files
- Basic token authentication
- Simple REST endpoints for health & updates
- No activity logging, no streaming, no integrations

**v2.0 Vision:**
- **Modular Wings Architecture** (inspired by Cloudways WP Manager)
- **Defense-in-depth security** (RSA signatures + HMAC + token + IP whitelist)
- **Full audit trail** (activity logging for compliance)
- **Smart data sync** (only send changed data to save bandwidth)
- **Premium plugin support** (detect & update commercial plugins)
- **Streaming API** (handle large databases & file transfers)
- **Security integrations** (Wordfence, iThemes)
- **Cache integrations** (WP Rocket, LiteSpeed, W3TC, etc.)
- **Professional stability** (production-ready for 50+ sites)

### 1.2 Key Architectural Changes

| Aspect | v1.3 | v2.0 |
|--------|------|------|
| **Code Structure** | Monolithic (4 files) | Wings pattern (modular) |
| **Authentication** | Token only | RSA + HMAC + Token + IP |
| **Logging** | None | Full activity log engine |
| **Data Transfer** | Standard JSON | Streaming for large data |
| **Integrations** | None | Wordfence, UpdraftPlus, cache plugins |
| **Premium Plugins** | Basic | Full detection & updates |
| **Sync Efficiency** | Always full | Smart flags (changed data only) |

### 1.3 Timeline Estimate

**Total Duration:** 8 weeks (4 phases)

- **Phase 1** (Weeks 1-2): Auth upgrade + Info wing + Sync flags
- **Phase 2** (Weeks 3-4): Manage wing + Cache wing + Logger
- **Phase 3** (Weeks 5-6): Security wing + DB wing + FS wing
- **Phase 4** (Weeks 7-8): Backup wing + Monitor wing + Polish

---

## 2. Architecture

### 2.1 The Wings Pattern

**Concept:** Each "wing" is an independent module responsible for a specific domain of functionality.

**Benefits:**
- **Separation of Concerns** — Each wing handles one thing well
- **Easy Testing** — Test wings in isolation
- **Independent Versioning** — Wings can evolve separately
- **Lazy Loading** — Only load the wings you need per request
- **Security Boundary** — Unauthenticated requests never load sensitive wings

**Inspired by:** Cloudways WP Manager's modular architecture (31 files, 7,227 lines vs MainWP Child's 150+ files, 25,000+ lines)

### 2.2 Wing Modules

| Wing | Purpose | Priority | Complexity |
|------|---------|----------|------------|
| **info** | Site stats, WP version, PHP version, plugins, themes, health check | P1 | Low |
| **manage** | Plugin/theme/core updates, user management | P1 | Medium |
| **security** | Wordfence/iThemes integration, file integrity | P2 | Medium |
| **cache** | Clear all cache types (WP Rocket, LiteSpeed, etc.) | P2 | Low |
| **db** | Database optimization, cleanup, export | P3 | Medium |
| **fs** | File operations (read configs, list files, disk usage) | P3 | High |
| **monitor** | Activity log queries, metrics | P3 | Low |
| **backup** | UpdraftPlus/BackupBuddy integration | P4 | Medium |

### 2.3 Request Flow

```
1. HTTP Request → /wp-json/digitizer-worker/v1/{wing}/{method}
   ↓
2. Authentication (class-auth.php)
   │  ├─ Verify signature (RSA)
   │  ├─ Verify HMAC (params)
   │  ├─ Verify token (header)
   │  └─ Check IP whitelist
   ↓
3. Routing (class-worker.php)
   │  └─ Load appropriate wing class
   ↓
4. Execution (wings/class-wing-{name}.php)
   │  └─ Call requested method
   ↓
5. Logging (class-logger.php)
   │  └─ Record action & result
   ↓
6. Response (JSON)
   └─ Return data or error
```

### 2.4 Code Example: Wing Base Class

```php
// includes/wings/class-wing-base.php
abstract class Digitizer_Wing_Base {
    const WING_VERSION = '1.0';
    
    protected $request;
    protected $logger;
    
    public function __construct($request) {
        $this->request = $request;
        $this->logger = Digitizer_Logger::get_instance();
    }
    
    /**
     * Main entry point for the wing.
     * Routes to specific methods based on request.
     */
    abstract public function process();
    
    /**
     * Get wing metadata.
     */
    public function get_info() {
        return array(
            'wing' => static::WING_NAME,
            'version' => static::WING_VERSION,
            'methods' => $this->get_methods()
        );
    }
    
    /**
     * Get list of available methods.
     */
    abstract protected function get_methods();
}
```

**Concrete Example:**

```php
// includes/wings/class-wing-info.php
class Digitizer_Wing_Info extends Digitizer_Wing_Base {
    const WING_NAME = 'info';
    const WING_VERSION = '1.0';
    
    public function process() {
        $method = $this->request->get_param('method');
        
        if (!method_exists($this, $method)) {
            return new WP_Error('invalid_method', 'Method not found');
        }
        
        return $this->$method();
    }
    
    protected function get_methods() {
        return array('get_stats', 'get_health', 'get_plugins', 'get_themes');
    }
    
    public function get_stats() {
        $sync_flags = $this->request->get_param('sync_flags') ?: array();
        
        $data = array(
            'version' => DIGITIZER_WORKER_VERSION,
            'timestamp' => time()
        );
        
        // Core info (always included)
        $data['core'] = $this->get_core_info();
        
        // Conditional sync based on flags
        if (empty($sync_flags) || !empty($sync_flags['plugins'])) {
            $data['plugins'] = $this->get_plugins();
        }
        
        if (empty($sync_flags) || !empty($sync_flags['health'])) {
            $data['health'] = $this->get_health();
        }
        
        $this->logger->log('info_stats_collected', array('flags' => array_keys($sync_flags)));
        
        return $data;
    }
    
    private function get_core_info() {
        global $wpdb;
        
        return array(
            'wp_version' => get_bloginfo('version'),
            'php_version' => phpversion(),
            'mysql_version' => $wpdb->db_version(),
            'site_url' => get_site_url(),
            'https' => is_ssl(),
            'multisite' => is_multisite(),
            'language' => get_locale()
        );
    }
    
    public function get_health() {
        if (!class_exists('WP_Site_Health')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
        }
        
        $health = WP_Site_Health::get_instance();
        $tests = $health->get_tests();
        
        $results = array(
            'status' => 'good',
            'score' => 0,
            'issues' => array(
                'critical' => 0,
                'recommended' => 0
            )
        );
        
        // Run direct tests
        foreach ($tests['direct'] as $test) {
            $result = $health->get_test($test);
            
            if ($result['status'] === 'critical') {
                $results['issues']['critical']++;
            } elseif ($result['status'] === 'recommended') {
                $results['issues']['recommended']++;
            }
        }
        
        // Calculate score
        $results['score'] = 100 - ($results['issues']['critical'] * 10) 
                                 - ($results['issues']['recommended'] * 2);
        
        if ($results['issues']['critical'] > 0) {
            $results['status'] = 'critical';
        } elseif ($results['issues']['recommended'] > 0) {
            $results['status'] = 'recommended';
        }
        
        return $results;
    }
}
```

**Key Pattern:** Each wing is self-contained, testable, and follows a consistent interface.

---

## 3. Authentication & Security

**Goal:** Defense in depth — multiple independent layers that must all pass.

### 3.1 Four-Layer Security Model

| Layer | Mechanism | Purpose | Bypass Prevention |
|-------|-----------|---------|-------------------|
| **1. Signature** | RSA-2048 (OpenSSL) | Verify request from trusted source | Public key stored locally |
| **2. HMAC** | SHA-256 HMAC | Protect params from tampering | Secret never leaves dashboard |
| **3. Token** | 32-char random | Site-specific identifier | Stored in options, not in code |
| **4. IP Whitelist** | REMOTE_ADDR | Network-level access control | Optional, configurable |

**Rationale:**
- **RSA Signature** prevents impersonation (inspired by MainWP + Cloudways)
- **HMAC** prevents parameter tampering even if signature leaks (Cloudways best practice)
- **Token** provides fast pre-check (existing v1.3 feature, keep it)
- **IP Whitelist** adds network boundary (existing v1.3 feature, keep it)

### 3.2 Registration Flow (First Connection)

**Step 1: Dashboard Initiates Registration**

```http
POST /wp-json/digitizer-worker/v1/register
Content-Type: application/json
X-Aura-Token: {existing_site_token}

{
  "pubkey": "{base64_encoded_rsa_public_key}",
  "dashboard_url": "https://my-aura.app",
  "user": "admin_username"
}
```

**Step 2: Plugin Validates & Stores**

```php
// includes/class-auth.php
public function register_dashboard($request) {
    // 1. Verify current token (prevents unauthorized registration)
    if (!$this->verify_token($request->get_header('X-Aura-Token'))) {
        return new WP_Error('invalid_token', 'Token mismatch', array('status' => 403));
    }
    
    // 2. Verify user is admin
    $user = get_user_by('login', $request->get_param('user'));
    if (!$user || !user_can($user, 'manage_options')) {
        return new WP_Error('invalid_user', 'User must be administrator');
    }
    
    // 3. Verify SSL (unless in dev mode)
    if (!is_ssl() && !defined('DIGITIZER_DEV_MODE')) {
        return new WP_Error('ssl_required', 'HTTPS is required for registration');
    }
    
    // 4. Store public key
    update_option('digitizer_worker_pubkey', base64_encode($request->get_param('pubkey')), 'yes');
    
    // 5. Store dashboard URL (encrypted)
    Digitizer_Keys_Manager::update_encrypted_option(
        'digitizer_worker_dashboard_url',
        $request->get_param('dashboard_url')
    );
    
    // 6. Generate verification token (single-use)
    $verify_token = $this->generate_verify_token($user->ID);
    
    // 7. Log registration
    $this->logger->log('dashboard_registered', array(
        'user' => $user->user_login,
        'dashboard_url' => $request->get_param('dashboard_url')
    ));
    
    return array(
        'status' => 'registered',
        'verify_token' => $verify_token,
        'site_token' => get_option('aura_worker_site_token')
    );
}

private function generate_verify_token($user_id) {
    $key = wp_generate_password(32, false);
    $secure = wp_generate_password(32, false);
    $hash_key = hash_hmac('sha256', $key, 'digitizer-verify');
    
    $tokens = get_user_meta($user_id, 'digitizer_verify_tokens', true) ?: array();
    $tokens[] = array(
        'hash_key' => $hash_key,
        'secure' => $secure,
        'created' => time(),
        'uses_left' => 5 // FIFO queue, max 5 attempts
    );
    
    // Keep only last 5 tokens
    if (count($tokens) > 5) {
        array_shift($tokens);
    }
    
    update_user_meta($user_id, 'digitizer_verify_tokens', $tokens);
    
    return $key . '-' . $secure;
}
```

**Step 3: Dashboard Verifies Registration**

```http
POST /wp-json/digitizer-worker/v1/verify
Content-Type: application/json

{
  "verify_token": "{key}-{secure}",
  "signature": "{base64_rsa_signature}"
}
```

**Verification token is single-use** (5 attempts max, then expires).

### 3.3 Request Authentication (All Subsequent Requests)

**Every request must include:**

```http
POST /wp-json/digitizer-worker/v1/{wing}/{method}
X-Aura-Token: {site_token}
X-Signature: {base64_rsa_signature}
X-Sign-Algo: sha256
X-Timestamp: {unix_timestamp}
Content-Type: application/json

{
  "params": "{base64_json_params}",
  "params_mac": "{sha256_hmac_of_params}"
}
```

**Authentication Code:**

```php
// includes/class-auth.php
public function authenticate_request($request) {
    // Layer 1: Token check (fast rejection)
    $token = $request->get_header('X-Aura-Token');
    if (!$this->verify_token($token)) {
        return new WP_Error('invalid_token', 'Invalid site token', array('status' => 403));
    }
    
    // Layer 2: IP whitelist (if configured)
    if (!$this->verify_ip()) {
        return new WP_Error('ip_blocked', 'IP not whitelisted', array('status' => 403));
    }
    
    // Layer 3: Timestamp (prevent replay)
    $timestamp = $request->get_header('X-Timestamp');
    if (!$this->verify_timestamp($timestamp)) {
        return new WP_Error('invalid_timestamp', 'Request expired or replayed', array('status' => 403));
    }
    
    // Layer 4: RSA Signature
    $signature = $request->get_header('X-Signature');
    $algo = $request->get_header('X-Sign-Algo') ?: 'sha256';
    
    if (!$this->verify_signature($signature, $timestamp, $algo)) {
        return new WP_Error('invalid_signature', 'Signature verification failed', array('status' => 403));
    }
    
    // Layer 5: HMAC (params protection)
    $params_json = $request->get_param('params');
    $params_mac = $request->get_param('params_mac');
    
    if (!$this->verify_params_hmac($params_json, $params_mac)) {
        return new WP_Error('invalid_params_mac', 'Params HMAC verification failed', array('status' => 403));
    }
    
    // All layers passed
    return true;
}

private function verify_signature($signature, $timestamp, $algo) {
    $pubkey = get_option('digitizer_worker_pubkey');
    if (!$pubkey) {
        return false;
    }
    
    // Build message to verify
    $message = $this->build_signature_message($timestamp);
    
    // Map algo string to OpenSSL constant
    $openssl_algo = ($algo === 'sha256') ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1;
    
    // Verify
    $result = openssl_verify(
        $message,
        base64_decode($signature),
        base64_decode($pubkey),
        $openssl_algo
    );
    
    return ($result === 1);
}

private function verify_params_hmac($params_json, $params_mac) {
    $secret = get_option('digitizer_worker_secret'); // Shared secret, set during registration
    
    $calculated_mac = hash_hmac('sha256', $params_json, $secret);
    
    // Constant-time comparison (prevent timing attacks)
    return hash_equals($params_mac, $calculated_mac);
}

private function verify_timestamp($timestamp) {
    $now = time();
    $last_recv = get_option('digitizer_last_recv_time', 0);
    
    // Allow 5 minutes clock skew
    if ($timestamp < $now - 300 || $timestamp > $now + 300) {
        return false;
    }
    
    // Prevent replay (timestamp must be greater than last received)
    if ($timestamp < $last_recv - 300) {
        return false;
    }
    
    // Update last received time
    update_option('digitizer_last_recv_time', $timestamp);
    
    return true;
}
```

**Why this approach?**
- **Token** is cheap to verify (no crypto) — reject bad requests fast
- **IP whitelist** is optional but powerful (network boundary)
- **Timestamp** prevents replay attacks
- **RSA** proves authenticity (dashboard has private key)
- **HMAC** protects params even if signature is compromised

### 3.4 Callable Functions Whitelist

**Pattern from MainWP:** Only explicitly whitelisted methods can be called.

```php
// includes/class-callable.php
class Digitizer_Callable {
    private $registry = array();
    
    public function __construct() {
        $this->register_wing('info', array(
            'get_stats',
            'get_health',
            'get_plugins',
            'get_themes'
        ));
        
        $this->register_wing('manage', array(
            'update_core',
            'update_plugin',
            'update_theme',
            'update_translations',
            'plugin_action',
            'theme_action',
            'add_user',
            'delete_user'
        ));
        
        $this->register_wing('cache', array(
            'clear_all',
            'clear_plugin',
            'clear_object_cache'
        ));
        
        // ... more wings
    }
    
    private function register_wing($wing_name, $methods) {
        foreach ($methods as $method) {
            $this->registry[$wing_name . '/' . $method] = true;
        }
    }
    
    public function is_callable($wing, $method) {
        $key = $wing . '/' . $method;
        return isset($this->registry[$key]);
    }
}
```

**Usage in routing:**

```php
$callable = new Digitizer_Callable();

if (!$callable->is_callable($wing, $method)) {
    return new WP_Error('method_not_allowed', 'Method not in whitelist');
}
```

**Security benefit:** Even if auth is bypassed, only whitelisted methods can execute.

---

## 4. Site Health & Monitoring

**Goal:** Provide comprehensive, accurate, and efficient site diagnostics.

### 4.1 WordPress Site Health API Integration

**Why:** WordPress 5.2+ has a built-in Site Health API. Don't reinvent the wheel.

**What it checks:**
- Database connectivity & version
- File permissions
- HTTPS status
- Loopback requests
- REST API availability
- PHP version & extensions
- Debug mode status
- Active/inactive plugins
- Theme integrity

**Implementation:**

```php
// includes/wings/class-wing-info.php
public function get_health() {
    if (!class_exists('WP_Site_Health')) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
    }
    
    $health = WP_Site_Health::get_instance();
    $tests = $health->get_tests();
    
    $results = array(
        'status' => 'good',
        'score' => 0,
        'issues' => array(
            'critical' => 0,
            'recommended' => 0
        ),
        'tests' => array()
    );
    
    // Run direct tests (synchronous)
    foreach ($tests['direct'] as $test_name => $test_callback) {
        $test_result = $health->get_test($test_name);
        
        $results['tests'][$test_name] = array(
            'label' => $test_result['label'],
            'status' => $test_result['status'],
            'badge' => $test_result['badge']['label'] ?? '',
            'description' => wp_strip_all_tags($test_result['description'] ?? '')
        );
        
        // Count issues
        if ($test_result['status'] === 'critical') {
            $results['issues']['critical']++;
        } elseif ($test_result['status'] === 'recommended') {
            $results['issues']['recommended']++;
        }
    }
    
    // Calculate overall score (100 = perfect)
    $results['score'] = 100 
        - ($results['issues']['critical'] * 10) 
        - ($results['issues']['recommended'] * 2);
    
    // Overall status
    if ($results['issues']['critical'] > 0) {
        $results['status'] = 'critical';
    } elseif ($results['issues']['recommended'] > 0) {
        $results['status'] = 'recommended';
    }
    
    return $results;
}
```

**Async tests (optional for v2.1):**
Site Health also has async tests (via AJAX). We can skip these for v2.0 and add them later if needed.

### 4.2 Sync Flags — Only Send Changed Data

**Problem:** Sending full site stats on every request wastes bandwidth and time.

**Solution:** Dashboard sends a `sync_flags` object indicating what data it needs.

**Example Request:**

```json
{
  "params": "{base64({
    \"sync_flags\": {
      \"core\": true,
      \"plugins\": true,
      \"health\": false,
      \"db_size\": false
    }
  })}",
  "params_mac": "{hmac}"
}
```

**Implementation:**

```php
public function get_stats() {
    $sync_flags = $this->request->get_param('sync_flags') ?: array();
    
    $data = array(
        'version' => DIGITIZER_WORKER_VERSION,
        'timestamp' => time()
    );
    
    // Core info (always included)
    $data['core'] = $this->get_core_info();
    
    // Conditional sync
    if ($this->should_sync('plugins', $sync_flags)) {
        $data['plugins'] = $this->get_plugins();
    }
    
    if ($this->should_sync('themes', $sync_flags)) {
        $data['themes'] = $this->get_themes();
    }
    
    if ($this->should_sync('health', $sync_flags)) {
        $data['health'] = $this->get_health();
    }
    
    if ($this->should_sync('db_size', $sync_flags)) {
        $data['db_size'] = $this->get_db_size();
    }
    
    if ($this->should_sync('disk_usage', $sync_flags)) {
        $data['disk_usage'] = $this->get_disk_usage();
    }
    
    return $data;
}

private function should_sync($key, $flags) {
    // If no flags, sync everything
    if (empty($flags)) {
        return true;
    }
    
    // If key is in flags and true, sync it
    return !empty($flags[$key]);
}
```

**Bandwidth Savings:**
- Without flags: ~500KB per request (all plugins, themes, health data)
- With flags: ~50KB (only core + requested data)
- **90% reduction** for routine health checks

**Inspired by:** MainWP Child's `syncData` parameter.

### 4.3 Premium Plugin Detection

**Problem:** WordPress update API doesn't know about premium plugins (they're not in the official repo).

**Solution:** Detect premium plugins and check their custom update endpoints.

**Detection Heuristics:**

```php
// includes/wings/class-wing-info.php
private function detect_premium_plugins($plugins) {
    $premium = array();
    
    foreach ($plugins as $plugin_file => $plugin_data) {
        // Heuristic 1: No "plugin URI" in WordPress.org format
        if (isset($plugin_data['PluginURI']) && 
            !preg_match('/wordpress\.org/', $plugin_data['PluginURI'])) {
            $premium[$plugin_file] = $this->check_premium_update($plugin_file, $plugin_data);
        }
        
        // Heuristic 2: Has "Update URI" (WordPress 5.8+)
        if (isset($plugin_data['UpdateURI']) && !empty($plugin_data['UpdateURI'])) {
            $premium[$plugin_file] = $this->check_premium_update($plugin_file, $plugin_data);
        }
        
        // Heuristic 3: Known premium plugin patterns
        if ($this->is_known_premium($plugin_file)) {
            $premium[$plugin_file] = $this->check_premium_update($plugin_file, $plugin_data);
        }
    }
    
    return $premium;
}

private function is_known_premium($plugin_file) {
    $premium_patterns = array(
        'elementor-pro/',
        'gravityforms/',
        'wp-rocket/',
        'advanced-custom-fields-pro/',
        'wpml-',
        'woocommerce-subscriptions/',
        'memberpress/'
    );
    
    foreach ($premium_patterns as $pattern) {
        if (strpos($plugin_file, $pattern) === 0) {
            return true;
        }
    }
    
    return false;
}

private function check_premium_update($plugin_file, $plugin_data) {
    // Trigger WordPress to check for updates
    wp_update_plugins();
    
    $update_info = get_site_transient('update_plugins');
    
    if (isset($update_info->response[$plugin_file])) {
        $update = $update_info->response[$plugin_file];
        
        return array(
            'has_update' => true,
            'current_version' => $plugin_data['Version'],
            'new_version' => $update->new_version ?? null,
            'package' => $update->package ?? null,
            'update_uri' => $plugin_data['UpdateURI'] ?? null
        );
    }
    
    return array('has_update' => false);
}
```

**Common Premium Plugin Systems:**
- **EDD (Easy Digital Downloads)** — Most Freemius-based plugins
- **Freemius SDK** — Direct API
- **Envato API** — ThemeForest/CodeCanyon
- **Custom endpoints** — Each vendor has their own

**Limitation:** We can **detect** premium plugins and **check** for updates, but we **cannot download updates** without license keys. That's stored in Aura dashboard, not the plugin.

**Update Flow (with license):**

```
1. Aura dashboard has license key
2. Aura sends update request with license in params
3. Plugin uses license to fetch update package from vendor API
4. Plugin installs update
5. Plugin reports success/failure
```

**Inspired by:** MainWP Child's premium plugin detection (lines 520-580 in stats.php).

---

## 5. Update Management

**Goal:** Safe, reliable updates for core, plugins, themes, and translations.

### 5.1 WordPress Core Updates

**Requirements:**
- Verify update is available
- Download core package
- Run upgrade
- Run database migrations
- Log result
- Rollback on failure (optional for v2.1)

**Implementation:**

```php
// includes/wings/class-wing-manage.php
public function update_core() {
    $params = $this->request->get_params();
    
    // Check if update is available
    wp_version_check();
    $updates = get_core_updates();
    
    if (!$updates || $updates[0]->response !== 'upgrade') {
        return new WP_Error('no_update', 'No core update available');
    }
    
    $update = $updates[0];
    
    // Load upgrader
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    
    // Custom skin to capture output
    $skin = new Digitizer_Upgrader_Skin();
    $upgrader = new Core_Upgrader($skin);
    
    // Increase memory limit
    wp_raise_memory_limit('admin');
    
    // Perform upgrade
    $result = $upgrader->upgrade($update);
    
    // Log
    $this->logger->log('core_updated', array(
        'from_version' => get_bloginfo('version'),
        'to_version' => $update->current,
        'result' => is_wp_error($result) ? 'error' : 'success',
        'feedback' => $skin->get_feedback()
    ));
    
    if (is_wp_error($result)) {
        return $result;
    }
    
    // Run database upgrade
    $this->update_database();
    
    return array(
        'success' => true,
        'from_version' => $GLOBALS['wp_version'],
        'to_version' => $update->current,
        'feedback' => $skin->get_feedback()
    );
}
```

### 5.2 Plugin Updates

```php
public function update_plugin() {
    $plugin = $this->request->get_param('plugin'); // e.g., "woocommerce/woocommerce.php"
    
    if (empty($plugin)) {
        return new WP_Error('missing_plugin', 'Plugin parameter required');
    }
    
    // Check if update is available
    wp_update_plugins();
    $updates = get_plugin_updates();
    
    if (!isset($updates[$plugin])) {
        return new WP_Error('no_update', 'No update available for this plugin');
    }
    
    // Load upgrader
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    
    $skin = new Digitizer_Upgrader_Skin();
    $upgrader = new Plugin_Upgrader($skin);
    
    // Perform upgrade
    $result = $upgrader->upgrade($plugin);
    
    // Log
    $this->logger->log('plugin_updated', array(
        'plugin' => $plugin,
        'from_version' => $updates[$plugin]->Version ?? 'unknown',
        'to_version' => $updates[$plugin]->update->new_version ?? 'unknown',
        'result' => is_wp_error($result) ? 'error' : 'success',
        'feedback' => $skin->get_feedback()
    ));
    
    if (is_wp_error($result) || !$result) {
        return new WP_Error('update_failed', 'Plugin update failed');
    }
    
    return array(
        'success' => true,
        'plugin' => $plugin,
        'feedback' => $skin->get_feedback()
    );
}
```

### 5.3 Theme Updates

```php
public function update_theme() {
    $theme = $this->request->get_param('theme'); // e.g., "twentytwentyfour"
    
    if (empty($theme)) {
        return new WP_Error('missing_theme', 'Theme parameter required');
    }
    
    // Check if update is available
    wp_update_themes();
    $updates = get_theme_updates();
    
    if (!isset($updates[$theme])) {
        return new WP_Error('no_update', 'No update available for this theme');
    }
    
    // Load upgrader
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    
    $skin = new Digitizer_Upgrader_Skin();
    $upgrader = new Theme_Upgrader($skin);
    
    // Perform upgrade
    $result = $upgrader->upgrade($theme);
    
    // Log
    $this->logger->log('theme_updated', array(
        'theme' => $theme,
        'result' => is_wp_error($result) ? 'error' : 'success',
        'feedback' => $skin->get_feedback()
    ));
    
    if (is_wp_error($result) || !$result) {
        return new WP_Error('update_failed', 'Theme update failed');
    }
    
    return array(
        'success' => true,
        'theme' => $theme,
        'feedback' => $skin->get_feedback()
    );
}
```

### 5.4 Translation Updates

```php
public function update_translations() {
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    
    $skin = new Digitizer_Upgrader_Skin();
    $upgrader = new Language_Pack_Upgrader($skin);
    
    // Get available translation updates
    $translations = wp_get_translation_updates();
    
    if (empty($translations)) {
        return array('success' => true, 'updated' => 0);
    }
    
    // Bulk upgrade
    $results = $upgrader->bulk_upgrade($translations);
    
    $updated = 0;
    foreach ($results as $result) {
        if ($result && !is_wp_error($result)) {
            $updated++;
        }
    }
    
    // Log
    $this->logger->log('translations_updated', array(
        'count' => $updated,
        'total' => count($translations)
    ));
    
    return array(
        'success' => true,
        'updated' => $updated,
        'total' => count($translations),
        'feedback' => $skin->get_feedback()
    );
}
```

### 5.5 Database Updates

**Context:** After WordPress core update, database schema may need upgrade.

```php
public function update_database() {
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    
    // Run WordPress core DB upgrade
    wp_upgrade();
    
    // Run dbDelta for schema changes
    dbDelta();
    
    // Log
    $this->logger->log('database_updated', array(
        'wp_version' => get_bloginfo('version'),
        'db_version' => get_option('db_version')
    ));
    
    return array('success' => true);
}
```

**Note:** Some plugins (WooCommerce, Elementor) have their own DB upgrade routines. We'll handle those in integrations.

### 5.6 Self-Update from GitHub

**Current v1.3 feature — keep and improve.**

```php
// includes/class-updater.php
public function check_for_update() {
    $transient = get_site_transient('digitizer_worker_update_check');
    
    if ($transient !== false) {
        return $transient; // Cached
    }
    
    // Fetch latest release from GitHub
    $response = wp_remote_get('https://api.github.com/repos/Digitizers/AuraWorker/releases/latest', array(
        'headers' => array(
            'User-Agent' => 'Digitizer-Site-Worker/' . DIGITIZER_WORKER_VERSION
        )
    ));
    
    if (is_wp_error($response)) {
        return false;
    }
    
    $release = json_decode(wp_remote_retrieve_body($response), true);
    
    if (!isset($release['tag_name'])) {
        return false;
    }
    
    $latest_version = ltrim($release['tag_name'], 'v');
    
    $update_data = array(
        'current_version' => DIGITIZER_WORKER_VERSION,
        'latest_version' => $latest_version,
        'has_update' => version_compare(DIGITIZER_WORKER_VERSION, $latest_version, '<'),
        'download_url' => $release['assets'][0]['browser_download_url'] ?? null,
        'release_notes' => $release['body'] ?? ''
    );
    
    // Cache for 12 hours
    set_site_transient('digitizer_worker_update_check', $update_data, 12 * HOUR_IN_SECONDS);
    
    return $update_data;
}

public function self_update() {
    $update_data = $this->check_for_update();
    
    if (!$update_data['has_update']) {
        return new WP_Error('no_update', 'Plugin is up to date');
    }
    
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    
    $skin = new Digitizer_Upgrader_Skin();
    $upgrader = new Plugin_Upgrader($skin);
    
    // Download and install
    $result = $upgrader->install($update_data['download_url'], array(
        'overwrite_package' => true
    ));
    
    if (is_wp_error($result)) {
        return $result;
    }
    
    // Reactivate plugin
    activate_plugin(plugin_basename(DIGITIZER_WORKER_FILE));
    
    return array(
        'success' => true,
        'from_version' => $update_data['current_version'],
        'to_version' => $update_data['latest_version']
    );
}
```

**Future Enhancement (v2.1):** Verify GitHub release signature for security.

---

## 6. Performance & Cache

**Goal:** Clear all types of cache with a single API call.

### 6.1 Supported Cache Plugins

| Plugin | Method | Priority |
|--------|--------|----------|
| **WP Rocket** | `rocket_clean_domain()` | High |
| **LiteSpeed Cache** | `LiteSpeed_Cache_API::purge_all()` | High |
| **W3 Total Cache** | `w3tc_flush_all()` | Medium |
| **WP Super Cache** | `wp_cache_clear_cache()` | Medium |
| **WP Fastest Cache** | `WpFastestCache::deleteCache()` | Medium |
| **Redis/Memcached** | `wp_cache_flush()` | High |
| **Transients** | Custom query | Always |

### 6.2 Implementation

```php
// includes/wings/class-wing-cache.php
class Digitizer_Wing_Cache extends Digitizer_Wing_Base {
    const WING_NAME = 'cache';
    const WING_VERSION = '1.0';
    
    public function process() {
        $method = $this->request->get_param('method');
        
        if ($method === 'clear_all') {
            return $this->clear_all();
        }
        
        return new WP_Error('invalid_method', 'Unknown cache method');
    }
    
    public function clear_all() {
        $cleared = array();
        
        // WP Rocket
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
            $cleared[] = 'WP Rocket';
        }
        
        // LiteSpeed Cache
        if (class_exists('LiteSpeed_Cache_API')) {
            LiteSpeed_Cache_API::purge_all();
            $cleared[] = 'LiteSpeed Cache';
        }
        
        // W3 Total Cache
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
            $cleared[] = 'W3 Total Cache';
        }
        
        // WP Super Cache
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
            $cleared[] = 'WP Super Cache';
        }
        
        // WP Fastest Cache
        if (class_exists('WpFastestCache')) {
            $wpfc = new WpFastestCache();
            $wpfc->deleteCache();
            $cleared[] = 'WP Fastest Cache';
        }
        
        // Redis/Memcached object cache
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
            $cleared[] = 'Object Cache';
        }
        
        // WordPress transients
        $this->clear_transients();
        $cleared[] = 'Transients';
        
        // Log
        $this->logger->log('cache_cleared', array('cleared' => $cleared));
        
        return array(
            'success' => true,
            'cleared' => $cleared,
            'count' => count($cleared)
        );
    }
    
    private function clear_transients() {
        global $wpdb;
        
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_site_transient_%'");
    }
    
    protected function get_methods() {
        return array('clear_all');
    }
}
```

**Future Enhancement (v2.1):** Cloudflare cache purge via API (requires CF API key in Aura dashboard).

---

## 7. Security Monitoring

**Goal:** Integrate with Wordfence and iThemes Security for centralized security status.

### 7.1 Wordfence Integration

**What we'll expose:**
- Scan status & results
- WAF status & config
- Blocked IPs
- License status (free vs premium)

```php
// includes/wings/class-wing-security.php
class Digitizer_Wing_Security extends Digitizer_Wing_Base {
    const WING_NAME = 'security';
    const WING_VERSION = '1.0';
    
    public function process() {
        $method = $this->request->get_param('method');
        
        switch ($method) {
            case 'get_status':
                return $this->get_security_status();
            case 'trigger_scan':
                return $this->trigger_scan();
            default:
                return new WP_Error('invalid_method', 'Unknown security method');
        }
    }
    
    public function get_security_status() {
        $wordfence = $this->get_wordfence_status();
        $ithemes = $this->get_ithemes_status();
        
        return array(
            'active_plugin' => $wordfence ? 'wordfence' : ($ithemes ? 'ithemes' : 'none'),
            'wordfence' => $wordfence,
            'ithemes' => $ithemes
        );
    }
    
    private function get_wordfence_status() {
        if (!class_exists('wordfence')) {
            return null;
        }
        
        return array(
            'license' => wfConfig::get('isPaid') ? 'premium' : 'free',
            'version' => WORDFENCE_VERSION,
            'firewall' => array(
                'enabled' => wfWAF::getInstance()->isEnabled(),
                'mode' => wfConfig::get('firewallEnabled'),
                'learning_mode' => wfConfig::get('learningModeGracePeriodEnabled')
            ),
            'scan' => array(
                'last_scan' => wfConfig::get('lastScanCompleted'),
                'issues' => $this->get_wordfence_issues()
            ),
            'blocked_ips' => count(wfBlock::getBlockedIPs())
        );
    }
    
    private function get_wordfence_issues() {
        $issues = wfIssues::getIssues();
        
        return array(
            'critical' => count($issues['new']['critical']),
            'high' => count($issues['new']['high']),
            'medium' => count($issues['new']['medium']),
            'low' => count($issues['new']['low'])
        );
    }
    
    public function trigger_scan() {
        if (!class_exists('wordfence')) {
            return new WP_Error('wordfence_inactive', 'Wordfence not active');
        }
        
        $scanner = new wfScanner();
        $scanner->startScan();
        
        $this->logger->log('security_scan_triggered', array('plugin' => 'wordfence'));
        
        return array(
            'success' => true,
            'scan_id' => $scanner->getScanID()
        );
    }
    
    private function get_ithemes_status() {
        if (!class_exists('ITSEC_Dashboard')) {
            return null;
        }
        
        $dashboard = new ITSEC_Dashboard();
        
        return array(
            'lockouts' => $dashboard->get_lockouts(),
            'brute_force' => $dashboard->get_brute_force_attacks(),
            'file_changes' => $dashboard->get_file_change_count(),
            'vulnerabilities' => $dashboard->get_vulnerable_count(),
            'banned_users' => $dashboard->get_banned_user_count()
        );
    }
    
    protected function get_methods() {
        return array('get_status', 'trigger_scan');
    }
}
```

**Limitation:** We can't configure Wordfence/iThemes settings (that's too risky). Only **read** status and **trigger** scans.

---

## 8. Database Operations

**Goal:** Optimize, clean, and export database data.

### 8.1 Database Optimization

```php
// includes/wings/class-wing-db.php
class Digitizer_Wing_DB extends Digitizer_Wing_Base {
    const WING_NAME = 'db';
    const WING_VERSION = '1.0';
    
    public function process() {
        $method = $this->request->get_param('method');
        
        switch ($method) {
            case 'optimize':
                return $this->optimize_tables();
            case 'cleanup':
                return $this->cleanup();
            case 'get_size':
                return $this->get_size();
            default:
                return new WP_Error('invalid_method', 'Unknown DB method');
        }
    }
    
    public function optimize_tables() {
        global $wpdb;
        
        $tables = $wpdb->get_results('SHOW TABLES', ARRAY_N);
        $optimized = 0;
        
        foreach ($tables as $table) {
            $table_name = $table[0];
            $result = $wpdb->query("OPTIMIZE TABLE `{$table_name}`");
            
            if ($result !== false) {
                $optimized++;
            }
        }
        
        $this->logger->log('db_optimized', array('tables' => $optimized));
        
        return array(
            'success' => true,
            'optimized' => $optimized,
            'total' => count($tables)
        );
    }
    
    public function cleanup() {
        global $wpdb;
        
        $cleaned = array();
        
        // Transients
        $transients = $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'"
        );
        $cleaned['transients'] = $transients;
        
        // Post revisions (keep last 5 per post)
        $revisions = $wpdb->query(
            "DELETE FROM {$wpdb->posts} WHERE post_type = 'revision' 
             AND post_parent IN (
                SELECT ID FROM (
                    SELECT p.ID, COUNT(r.ID) as rev_count
                    FROM {$wpdb->posts} p
                    LEFT JOIN {$wpdb->posts} r ON p.ID = r.post_parent AND r.post_type = 'revision'
                    GROUP BY p.ID
                    HAVING rev_count > 5
                ) AS subquery
             )"
        );
        $cleaned['revisions'] = $revisions;
        
        // Spam comments
        $spam = $wpdb->query(
            "DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam'"
        );
        $cleaned['spam_comments'] = $spam;
        
        // Trashed comments
        $trashed = $wpdb->query(
            "DELETE FROM {$wpdb->comments} WHERE comment_approved = 'trash'"
        );
        $cleaned['trashed_comments'] = $trashed;
        
        $this->logger->log('db_cleanup', $cleaned);
        
        return array(
            'success' => true,
            'cleaned' => $cleaned
        );
    }
    
    public function get_size() {
        global $wpdb;
        
        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT SUM(data_length + index_length) AS size 
                 FROM information_schema.TABLES 
                 WHERE table_schema = %s",
                DB_NAME
            )
        );
        
        return array(
            'size_bytes' => (int) $result->size,
            'size_mb' => round($result->size / 1024 / 1024, 2)
        );
    }
    
    protected function get_methods() {
        return array('optimize', 'cleanup', 'get_size');
    }
}
```

**Future Enhancement (v2.1):** Streaming database export (for backups).

---

## 9. File System

**Goal:** Read critical config files and report disk usage.

### 9.1 Safe File Operations

**What we'll expose:**
- Read `wp-config.php` (sanitized — no DB credentials)
- Read `.htaccess`
- Read error logs
- Disk usage by directory

**What we WON'T expose:**
- File editor (too risky)
- File upload/write (security risk)
- Directory traversal outside ABSPATH

```php
// includes/wings/class-wing-fs.php
class Digitizer_Wing_FS extends Digitizer_Wing_Base {
    const WING_NAME = 'fs';
    const WING_VERSION = '1.0';
    
    private $allowed_files = array(
        'wp-config.php',
        '.htaccess',
        'debug.log',
        'error_log'
    );
    
    public function process() {
        $method = $this->request->get_param('method');
        
        switch ($method) {
            case 'read_file':
                return $this->read_file();
            case 'get_disk_usage':
                return $this->get_disk_usage();
            default:
                return new WP_Error('invalid_method', 'Unknown FS method');
        }
    }
    
    public function read_file() {
        $file = $this->request->get_param('file');
        
        if (!in_array($file, $this->allowed_files, true)) {
            return new WP_Error('file_not_allowed', 'File not in whitelist');
        }
        
        $path = ABSPATH . $file;
        
        if (!file_exists($path)) {
            return new WP_Error('file_not_found', 'File does not exist');
        }
        
        $content = file_get_contents($path);
        
        // Sanitize wp-config.php
        if ($file === 'wp-config.php') {
            $content = $this->sanitize_wp_config($content);
        }
        
        return array(
            'file' => $file,
            'content' => $content,
            'size' => filesize($path),
            'mtime' => filemtime($path)
        );
    }
    
    private function sanitize_wp_config($content) {
        // Remove DB credentials
        $content = preg_replace(
            "/(define\s*\(\s*['\"]DB_(NAME|USER|PASSWORD|HOST)['\"]\s*,\s*['\"]).*?(['\"]\s*\);)/i",
            '$1***REDACTED***$3',
            $content
        );
        
        // Remove auth keys & salts
        $content = preg_replace(
            "/(define\s*\(\s*['\"].*?_(KEY|SALT)['\"]\s*,\s*['\"]).*?(['\"]\s*\);)/i",
            '$1***REDACTED***$3',
            $content
        );
        
        return $content;
    }
    
    public function get_disk_usage() {
        $usage = array();
        
        $dirs = array(
            'wp-content/uploads' => WP_CONTENT_DIR . '/uploads',
            'wp-content/plugins' => WP_CONTENT_DIR . '/plugins',
            'wp-content/themes' => WP_CONTENT_DIR . '/themes',
            'total' => ABSPATH
        );
        
        foreach ($dirs as $label => $path) {
            if (is_dir($path)) {
                $usage[$label] = $this->get_dir_size($path);
            }
        }
        
        return $usage;
    }
    
    private function get_dir_size($path) {
        $size = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            $size += $file->getSize();
        }
        
        return array(
            'bytes' => $size,
            'mb' => round($size / 1024 / 1024, 2),
            'gb' => round($size / 1024 / 1024 / 1024, 2)
        );
    }
    
    protected function get_methods() {
        return array('read_file', 'get_disk_usage');
    }
}
```

**Security:** Whitelist approach — only specific files can be read. No directory traversal.

---

## 10. Backup Integration

**Goal:** Trigger and monitor backups via existing plugins (UpdraftPlus, BackupBuddy).

**We do NOT build our own backup engine.** We integrate with existing solutions.

### 10.1 UpdraftPlus Integration

```php
// includes/wings/class-wing-backup.php
class Digitizer_Wing_Backup extends Digitizer_Wing_Base {
    const WING_NAME = 'backup';
    const WING_VERSION = '1.0';
    
    public function process() {
        $method = $this->request->get_param('method');
        
        switch ($method) {
            case 'get_status':
                return $this->get_backup_status();
            case 'trigger':
                return $this->trigger_backup();
            case 'list':
                return $this->list_backups();
            default:
                return new WP_Error('invalid_method', 'Unknown backup method');
        }
    }
    
    public function get_backup_status() {
        if (!$this->is_updraftplus_active()) {
            return array('plugin' => 'none', 'status' => 'no_backup_plugin');
        }
        
        global $updraftplus;
        
        $history = $updraftplus->get_backup_history();
        $last_backup = array_shift($history);
        
        return array(
            'plugin' => 'updraftplus',
            'last_backup' => $last_backup ? $last_backup['backup_time'] : null,
            'status' => $last_backup ? 'available' : 'none'
        );
    }
    
    public function trigger_backup() {
        if (!$this->is_updraftplus_active()) {
            return new WP_Error('plugin_inactive', 'UpdraftPlus not active');
        }
        
        global $updraftplus;
        
        // Trigger manual backup
        $result = $updraftplus->backup_now('manual');
        
        $this->logger->log('backup_triggered', array('plugin' => 'updraftplus'));
        
        return array(
            'success' => true,
            'message' => 'Backup started'
        );
    }
    
    public function list_backups() {
        if (!$this->is_updraftplus_active()) {
            return new WP_Error('plugin_inactive', 'UpdraftPlus not active');
        }
        
        global $updraftplus;
        
        $history = $updraftplus->get_backup_history();
        
        $backups = array();
        foreach ($history as $timestamp => $backup) {
            $backups[] = array(
                'timestamp' => $timestamp,
                'date' => date('Y-m-d H:i:s', $timestamp),
                'nonce' => $backup['nonce'] ?? null,
                'contains' => array_keys($backup)
            );
        }
        
        return array('backups' => $backups);
    }
    
    private function is_updraftplus_active() {
        return class_exists('UpdraftPlus') && isset($GLOBALS['updraftplus']);
    }
    
    protected function get_methods() {
        return array('get_status', 'trigger', 'list');
    }
}
```

**Future Enhancement:** Support for BackupBuddy, All-in-One WP Migration.

---

## 11. REST API Design

### 11.1 Endpoint Structure

**Base:** `/wp-json/digitizer-worker/v1/`

**Wings Pattern:**

```
/digitizer-worker/v1/{wing}/{method}
```

**Examples:**

```
GET  /digitizer-worker/v1/info/get_stats
GET  /digitizer-worker/v1/info/get_health
POST /digitizer-worker/v1/manage/update_core
POST /digitizer-worker/v1/manage/update_plugin
POST /digitizer-worker/v1/cache/clear_all
POST /digitizer-worker/v1/security/trigger_scan
GET  /digitizer-worker/v1/db/get_size
POST /digitizer-worker/v1/db/optimize
GET  /digitizer-worker/v1/fs/read_file
POST /digitizer-worker/v1/backup/trigger
```

### 11.2 Registration

```php
// includes/class-worker.php
public function register_routes() {
    $wings = array('info', 'manage', 'cache', 'security', 'db', 'fs', 'backup', 'monitor');
    
    foreach ($wings as $wing) {
        register_rest_route('digitizer-worker/v1', "/{$wing}/(?P<method>[a-z_]+)", array(
            'methods' => array('GET', 'POST'),
            'callback' => array($this, 'route_wing_request'),
            'permission_callback' => array($this, 'authenticate'),
            'args' => array(
                'wing' => array(
                    'required' => true,
                    'validate_callback' => function($param) use ($wings) {
                        return in_array($param, $wings, true);
                    }
                ),
                'method' => array(
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field'
                )
            )
        ));
    }
}

public function route_wing_request($request) {
    $wing = $request->get_param('wing');
    
    // Load wing class
    $class_name = 'Digitizer_Wing_' . ucfirst($wing);
    $file_path = DIGITIZER_WORKER_DIR . "includes/wings/class-wing-{$wing}.php";
    
    if (!file_exists($file_path)) {
        return new WP_Error('wing_not_found', 'Wing class not found', array('status' => 500));
    }
    
    require_once $file_path;
    
    if (!class_exists($class_name)) {
        return new WP_Error('wing_class_missing', 'Wing class does not exist', array('status' => 500));
    }
    
    // Instantiate wing
    $wing_instance = new $class_name($request);
    
    // Process request
    $result = $wing_instance->process();
    
    // Return result
    if (is_wp_error($result)) {
        return $result;
    }
    
    return new WP_REST_Response($result, 200);
}
```

### 11.3 Response Format

**Success:**

```json
{
  "success": true,
  "data": {
    "version": "2.0.0",
    "timestamp": 1711276800,
    "core": {
      "wp_version": "6.5",
      "php_version": "8.2.0"
    }
  }
}
```

**Error:**

```json
{
  "code": "invalid_signature",
  "message": "Signature verification failed",
  "data": {
    "status": 403
  }
}
```

---

## 12. File Structure

```
digitizer-site-worker/
├── digitizer-site-worker.php           # Main plugin file
├── uninstall.php                       # Cleanup on uninstall
├── readme.txt                          # WordPress.org readme
├── includes/
│   ├── class-worker.php                # Main orchestrator — routes, settings page
│   ├── class-auth.php                  # Authentication (RSA + HMAC + Token + IP)
│   ├── class-logger.php                # Activity logging engine
│   ├── class-callable.php              # Function whitelist registry
│   ├── class-keys-manager.php          # Encrypted options storage
│   ├── class-upgrader-skin.php         # Custom upgrader feedback collector
│   └── wings/
│       ├── class-wing-base.php         # Base wing abstract class
│       ├── class-wing-info.php         # Site info, health, stats
│       ├── class-wing-manage.php       # Plugin/theme/core updates
│       ├── class-wing-cache.php        # Cache clearing
│       ├── class-wing-security.php     # Wordfence/iThemes integration
│       ├── class-wing-db.php           # Database operations
│       ├── class-wing-fs.php           # File system operations
│       ├── class-wing-backup.php       # Backup integration
│       └── class-wing-monitor.php      # Activity log queries
└── assets/
    ├── aura_icon.png
    └── aura_logotype.png
```

**Total estimated lines:** ~3,000-4,000 (compact like Cloudways, not bloated like MainWP).

---

## 13. Implementation Phases

### Phase 1 (Weeks 1-2): Foundation & Authentication

**Goals:**
- Wings architecture in place
- RSA + HMAC + Token + IP authentication working
- Info wing with sync flags
- Activity logger operational

**Deliverables:**
- `class-worker.php` (routing)
- `class-auth.php` (4-layer security)
- `class-logger.php` (activity logging)
- `class-wing-info.php` (stats + health)
- Unit tests for auth

**Testing:**
- Register dashboard from Aura
- Verify all 4 auth layers reject bad requests
- Sync flags reduce payload size

---

### Phase 2 (Weeks 3-4): Updates & Cache

**Goals:**
- Update management (core, plugins, themes, translations)
- Cache clearing (all major plugins)
- Custom upgrader skin

**Deliverables:**
- `class-wing-manage.php` (updates)
- `class-wing-cache.php` (cache ops)
- `class-upgrader-skin.php` (feedback collection)
- Premium plugin detection

**Testing:**
- Update WordPress core on staging site
- Update premium plugin (Elementor Pro, WooCommerce)
- Clear all cache types
- Verify feedback is captured

---

### Phase 3 (Weeks 5-6): Security, DB, FS

**Goals:**
- Wordfence/iThemes integration
- Database operations
- File system reading

**Deliverables:**
- `class-wing-security.php` (Wordfence + iThemes)
- `class-wing-db.php` (optimize, cleanup, size)
- `class-wing-fs.php` (read configs, disk usage)

**Testing:**
- Trigger Wordfence scan remotely
- Optimize database on large site
- Read sanitized wp-config.php
- Disk usage calculation

---

### Phase 4 (Weeks 7-8): Backup, Monitor, Polish

**Goals:**
- UpdraftPlus integration
- Activity log queries
- Final polish & documentation

**Deliverables:**
- `class-wing-backup.php` (UpdraftPlus)
- `class-wing-monitor.php` (log queries)
- Full unit test coverage
- Updated README & docs

**Testing:**
- Trigger backup on production site
- Query activity logs via API
- Full integration test (all wings)
- Performance benchmarking

**Release:**
- Tag v2.0.0 on GitHub
- Update Aura dashboard integration
- Beta rollout to 10 sites
- Monitor for 1 week
- Full production rollout

---

## 14. What We DON'T Build

### ❌ Own Backup Engine

**Why not:** UpdraftPlus, BackupBuddy, All-in-One WP Migration already exist and are battle-tested.

**What we do:** Integrate with their APIs to trigger/monitor backups.

---

### ❌ File Editor

**Why not:** Massive security risk. If our auth is compromised, attacker could edit any file.

**Alternative:** SSH access for file editing.

---

### ❌ Direct DB Query Execution

**Why not:** SQL injection risk. Too dangerous to allow arbitrary queries.

**What we do:** Predefined operations (optimize, cleanup, size) only.

---

### ❌ Multi-Site Network Management

**Why not:** Out of scope for v2.0. Maybe v3.0.

**Current:** Single-site only.

---

### ❌ Premium Plugin License Management

**Why not:** Each premium plugin has its own license system. Too complex to unify.

**What we do:** Detect premium plugins, report update availability. Aura dashboard stores licenses and provides them in update requests.

---

### ❌ Clone/Staging Functionality

**Why not:** Requires server-level operations (database dump, file rsync). We don't have that access on shared hosting.

**Alternative:** Aura dashboard can trigger staging via Cloudways/Hostinger APIs.

---

### ❌ File Integrity Scanning

**Why not:** Wordfence already does this perfectly.

**What we do:** Integrate with Wordfence to report scan results.

---

### ❌ Uptime Monitoring

**Why not:** Pingdom, UptimeRobot, Better Uptime are specialized for this.

**What we do:** Provide a `/ping` endpoint that returns a simple 200 OK. External monitoring tools can poll it.

---

## Appendix A: Code Conventions

### Naming

- **Classes:** `Digitizer_Wing_Name` (PascalCase with prefix)
- **Methods:** `get_site_stats()` (snake_case)
- **Files:** `class-wing-name.php` (lowercase with hyphens)
- **Constants:** `DIGITIZER_WORKER_VERSION` (UPPER_SNAKE_CASE)

### WordPress Standards

- Follow [WordPress PHP Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)
- Use `wp_send_json_success()` and `wp_send_json_error()` for JSON responses
- Use `WP_Error` for error handling
- Use `$wpdb->prepare()` for all SQL queries
- Use `sanitize_*()` and `esc_*()` functions

### Security

- Validate all inputs
- Sanitize all outputs
- Use nonces for form submissions (settings page)
- Check capabilities before operations
- Never trust `$_POST` or `$_GET` directly — use `$request->get_param()`

### Documentation

- PHPDoc blocks for all classes and public methods
- Inline comments for complex logic
- Code examples in SKILL.md and README.md

---

## Appendix B: Testing Strategy

### Unit Tests (PHPUnit)

- Auth layer (signature, HMAC, token, IP)
- Each wing's methods
- Logger functionality
- Keys manager (encryption)

### Integration Tests

- Full request flow (auth → routing → wing → response)
- Database operations on test database
- File operations in isolated directory
- Cache clearing (mocked cache plugins)

### Manual Testing Checklist

- [ ] Register dashboard from Aura
- [ ] Get site stats
- [ ] Update WordPress core
- [ ] Update plugin
- [ ] Update theme
- [ ] Clear all cache
- [ ] Trigger Wordfence scan
- [ ] Optimize database
- [ ] Read wp-config.php (verify sanitization)
- [ ] Trigger UpdraftPlus backup
- [ ] Query activity logs

### Performance Testing

- Benchmark sync flags vs full stats (expect 80-90% reduction)
- Benchmark disk usage calculation on large site
- Benchmark database optimization on large database
- Benchmark authentication overhead (<50ms)

---

## Appendix C: Migration from v1.3 to v2.0

### Database Changes

- New table: `wp_digitizer_activity_log`
- New options:
  - `digitizer_worker_pubkey`
  - `digitizer_worker_secret`
  - `digitizer_worker_dashboard_url` (encrypted)
  - `digitizer_last_recv_time`

### Breaking Changes

- Endpoint structure changed from `/aura/v1/` to `/digitizer-worker/v1/{wing}/{method}`
- Authentication requires signature + HMAC (not just token)
- Response format standardized

### Migration Script

```php
// Runs on plugin activation
function digitizer_worker_v2_migration() {
    $current_version = get_option('digitizer_worker_version');
    
    if (version_compare($current_version, '2.0.0', '<')) {
        // Create activity log table
        Digitizer_Logger::create_table();
        
        // Migrate old token to new format
        $old_token = get_option('aura_worker_site_token');
        if ($old_token) {
            update_option('digitizer_worker_site_token', $old_token);
            delete_option('aura_worker_site_token');
        }
        
        // Set version
        update_option('digitizer_worker_version', '2.0.0');
    }
}
```

---

## Appendix D: Comparison to Competitors

| Feature | Digitizer Worker v2 | MainWP Child | Cloudways WP Manager | InfiniteWP Client |
|---------|---------------------|--------------|----------------------|-------------------|
| **Architecture** | Wings (modular) | Monolithic | Wings (modular) | Monolithic |
| **Code Size** | ~3,500 lines | 25,000+ lines | 7,227 lines | ~15,000 lines |
| **Auth Layers** | 4 (RSA+HMAC+Token+IP) | 2 (RSA+Token) | 3 (RSA+HMAC+IP) | 2 (Key+IP) |
| **Activity Logging** | Full engine | Basic | Full engine | Basic |
| **Sync Flags** | ✅ | ✅ | ✅ | ❌ |
| **Premium Plugins** | ✅ Detect + Update | ✅ Full support | ⚠️ Detect only | ⚠️ Detect only |
| **Streaming API** | Planned v2.1 | ❌ | ✅ | ❌ |
| **Wordfence Integration** | ✅ | ✅ | ❌ (Malcare) | ❌ |
| **Cache Clearing** | ✅ Multi-plugin | ✅ Multi-plugin | ✅ Multi-plugin | ✅ Limited |
| **Self-Update** | ✅ GitHub | ✅ WordPress.org | ✅ BlogVault | ✅ Own server |
| **Open Source** | ✅ GPL | ✅ GPL | ✅ GPL | ❌ Proprietary |

**Winner:** Digitizer Worker v2.0 balances Cloudways' clean architecture with MainWP's feature completeness.

---

**End of Specification**  
**Next Step:** Review with team → Approve → Start Phase 1 development

---

**Document Version:** 1.0  
**Last Updated:** March 24, 2026  
**Prepared by:** Digitizer Development Team  
**Status:** Draft for Review

---

## 15. Phase 5: Aura MCP Server (Week 9-10)

### Concept
Build an MCP (Model Context Protocol) server that connects AI assistants (Claude Code, Cursor, OpenClaw) directly to the Aura dashboard, enabling natural language WordPress site management.

**Reference:** MainWP MCP Server (https://github.com/mainwp/mainwp-mcp)

### What It Enables
- "Update all plugins on production sites" → executes across 50 sites
- "Show me sites with outdated WordPress" → queries all instances
- "Run security scan on digitizer.co.il" → triggers Wordfence scan
- "What's the uptime for our hosting clients?" → aggregates SLA data

### Architecture
```
User (natural language)
  → OpenClaw / Claude Code / Cursor
    → Aura MCP Server (@digitizer/aura-mcp)
      → Aura Dashboard API
        → Digitizer Site Worker (on each WordPress site)
```

### MCP Tools
- `list_sites` - List all managed WordPress sites
- `get_site_health` - Health status of a specific site
- `update_plugins` - Update plugins (single site or bulk)
- `update_themes` - Update themes
- `update_core` - Update WordPress core
- `clear_cache` - Clear cache on a site
- `run_security_scan` - Trigger security scan
- `get_costs` - Cost data from DeepClaw
- `get_uptime` - Uptime SLA data
- `bulk_action` - Execute action across multiple sites

### Stack
- TypeScript, Node.js
- MCP SDK (@modelcontextprotocol/sdk)
- npm package: @digitizer/aura-mcp
- Auth: Aura API token

### Differentiator
This makes Aura **AI-native from day one** - the first WordPress site management platform built for AI assistants, not just humans clicking buttons.
