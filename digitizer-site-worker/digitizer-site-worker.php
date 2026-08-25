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
	// Deactivation revokes the Application Password Aura minted, and KEEPS its
	// owner/uuid whenever the revocation did not land. Reaching activation with
	// that record still present therefore means exactly one thing: a revocation
	// that failed. Finish it here (round-11), or a transient failure would
	// leave an administrator credential valid indefinitely — the documentation
	// promises reactivation as the cure, so it has to be one. A site that was
	// never deactivated by this hook has nothing recorded and this is a no-op.
	$aura_deferred_password = ( null !== get_option( Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION, null ) );
	if ( ! Aura_Worker_Magic_Link::revoke_managed_password() ) {
		// translators: internal log line, not shown to the user.
		error_log( 'SiteAgent: an Aura Application Password left over from a failed deactivation could not be revoked; revoke it by hand in Users → Profile → Application Passwords.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	} elseif ( $aura_deferred_password ) {
		// A revocation deferred from deactivation lands HERE, so the flag
		// deactivation could not set belongs here too (round-20) — without it
		// the settings screen reports an intact connection over a credential
		// this call has just revoked.
		update_option( Aura_Worker_Magic_Link::RECONNECT_NEEDED_OPTION, 1, false );
	}
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
	// Options are removed on uninstall, not here — with two exceptions, both
	// about what "disconnected" has to mean.
	//
	// 1. The Application Password minted for the dashboard (2.11.0) is an
	//    administrator-level WordPress credential. Unregistering SiteAgent's
	//    routes does not touch it: core's REST API and every other REST/MCP
	//    plugin keep accepting it, so a deactivated plugin would still leave
	//    Aura able to act on the site (round-8). Best-effort — deactivation
	//    must not fail — and revoke_managed_password() keeps its owner/uuid
	//    record whenever the revocation did not land, so a reactivation or the
	//    uninstall can finish the job.
	// 2. The site-wide connect claim, which has no timed takeover: a claim a
	//    killed handler left behind refuses every later connect, and this is
	//    the operator's release for it. Clearing it is safe on its own terms
	//    because every write the claim protects re-checks that it still holds
	//    the claim (Aura_Worker_Magic_Link::holds_site_claim()), so a request
	//    that resumes after the release writes nothing.
	$aura_had_password = ( null !== get_option( Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION, null ) );
	if ( ! Aura_Worker_Magic_Link::revoke_managed_password() ) {
		// translators: internal log line, not shown to the user.
		error_log( 'SiteAgent: the Aura Application Password could not be revoked while deactivating; revoke it by hand in Users → Profile → Application Passwords.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	} elseif ( $aura_had_password ) {
		// The token binding survives deactivation, so the settings screen would
		// go on reporting an intact connection while the credential the builder
		// tools authenticate with is gone, with no way to restore it from here
		// (round-19). Flag it; the next successful connect clears the flag as
		// it mints the replacement.
		update_option( Aura_Worker_Magic_Link::RECONNECT_NEEDED_OPTION, 1, false );
	}
	Aura_Worker_Magic_Link::forget_site_claim();
}
register_deactivation_hook( __FILE__, 'aura_worker_deactivate' );
