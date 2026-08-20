<?php
/**
 * Plugin Name:       SiteAgent for Aura
 * Plugin URI:        https://my-aura.app/siteagent
 * Description:       Remote site management agent for Aura dashboard. Enables secure updates, health monitoring, and maintenance operations via REST API.
 * Version:           2.9.0
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

define( 'AURA_WORKER_VERSION', '2.9.0' );
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
	// Nothing to clean up on deactivation; options are removed on uninstall.
}
register_deactivation_hook( __FILE__, 'aura_worker_deactivate' );
