<?php
/**
 * Plugin Name:       SiteAgent for Aura
 * Plugin URI:        https://my-aura.app/siteagent
 * Description:       Remote site management agent for Aura dashboard. Enables secure updates, health monitoring, and maintenance operations via REST API.
 * Version:           2.11.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Digitizer
 * Author URI:        https://www.digitizer.studio
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       digitizer-site-worker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AURA_WORKER_VERSION', '2.11.0' );
define( 'AURA_WORKER_FILE', __FILE__ );
define( 'AURA_WORKER_DIR', plugin_dir_path( __FILE__ ) );

// Load dependencies.
require_once AURA_WORKER_DIR . 'includes/class-aura-worker.php';
require_once AURA_WORKER_DIR . 'includes/class-aura-worker-api.php';
require_once AURA_WORKER_DIR . 'includes/class-aura-worker-updater.php';
require_once AURA_WORKER_DIR . 'includes/class-aura-worker-security.php';
require_once AURA_WORKER_DIR . 'includes/class-aura-worker-health.php';
require_once AURA_WORKER_DIR . 'includes/class-aura-worker-rollback.php';
require_once AURA_WORKER_DIR . 'includes/class-aura-worker-snapshots.php';
require_once AURA_WORKER_DIR . 'includes/class-aura-worker-grant.php';
require_once AURA_WORKER_DIR . 'includes/class-aura-worker-mcp.php';
require_once AURA_WORKER_DIR . 'includes/class-aura-worker-call-context.php';
require_once AURA_WORKER_DIR . 'includes/class-aura-worker-rules.php';
require_once AURA_WORKER_DIR . 'includes/class-aura-worker-abilities.php';
require_once AURA_WORKER_DIR . 'includes/class-aura-worker-magic-link.php';

/**
 * Initialize the plugin.
 */
function aura_worker_init() {
	$plugin = new Aura_Worker();
	$plugin->init();
}
add_action( 'plugins_loaded', 'aura_worker_init' );


/**
 * Activation hook.
 */
function aura_worker_activate() {
	// Store activation timestamp.
	update_option( 'aura_worker_activated', time() );
	// Belt and braces for the deactivation release below: a claim that outlived
	// a crash during deactivation would otherwise still block every connect.
	Aura_Worker_Magic_Link::forget_site_claim();
	update_option( 'aura_worker_version', AURA_WORKER_VERSION );

	// Generate a unique site token if not exists. Only the SHA-256 hash is
	// stored; the raw value is revealed once via a transient on the settings
	// page so the admin can copy it into the Aura dashboard.
	if ( ! get_option( 'aura_worker_site_token' ) ) {
		require_once AURA_WORKER_DIR . 'includes/class-aura-worker-security.php';
		$raw = wp_generate_password( 48, false );
		update_option( 'aura_worker_site_token', Aura_Worker_Security::hash_token( $raw ) );
		set_transient( 'aura_worker_token_reveal', $raw, 30 * MINUTE_IN_SECONDS );
	}
}
register_activation_hook( __FILE__, 'aura_worker_activate' );

/**
 * Deactivation hook.
 */
function aura_worker_deactivate() {
	// Options are removed on uninstall, not here — with ONE exception. The
	// site-wide connect claim (2.11.0) has no timed takeover: a claim is only
	// ever released by the handler that took it, so nothing can start a second
	// install beside a handler that might still resume. The cost is that a
	// handler killed mid-connect (OOM, fatal, a killed worker) leaves a claim
	// that refuses every later connect, and the site has no clock that may
	// clear it. Deactivating the plugin is the operator's explicit release:
	// no connect handler survives it, so removing the claim here opens no
	// race. Reconnect after reactivating.
	Aura_Worker_Magic_Link::forget_site_claim();
}
register_deactivation_hook( __FILE__, 'aura_worker_deactivate' );
