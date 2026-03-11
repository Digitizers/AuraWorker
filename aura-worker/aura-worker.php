<?php
/**
 * Plugin Name:       AuraWorker
 * Plugin URI:        https://my-aura.app
 * Description:       Remote site management agent for Aura dashboard. Enables secure updates, health monitoring, and maintenance operations via REST API.
 * Version:           1.2.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Digitizer
 * Author URI:        https://www.digitizer.studio
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       aura-worker
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AURA_WORKER_VERSION', '1.2.0' );
define( 'AURA_WORKER_FILE', __FILE__ );
define( 'AURA_WORKER_DIR', plugin_dir_path( __FILE__ ) );

// Load dependencies.
require_once AURA_WORKER_DIR . 'includes/class-aura-worker.php';
require_once AURA_WORKER_DIR . 'includes/class-aura-worker-api.php';
require_once AURA_WORKER_DIR . 'includes/class-aura-worker-updater.php';
require_once AURA_WORKER_DIR . 'includes/class-aura-worker-security.php';

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

	// Generate a unique site token if not exists.
	if ( ! get_option( 'aura_worker_site_token' ) ) {
		update_option( 'aura_worker_site_token', wp_generate_password( 32, false ) );
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
