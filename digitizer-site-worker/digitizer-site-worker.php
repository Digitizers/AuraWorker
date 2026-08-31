<?php
/**
 * Plugin Name:       SiteAgent for Aura
 * Plugin URI:        https://my-aura.app/siteagent
 * Description:       Remote site management agent for Aura dashboard. Enables secure updates, health monitoring, and maintenance operations via REST API.
 * Version:           2.13.0
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

define( 'AURA_WORKER_VERSION', '2.13.0' );
define( 'AURA_WORKER_FILE', __FILE__ );
define( 'AURA_WORKER_DIR', plugin_dir_path( __FILE__ ) );

// The shutdown guard is armed BEFORE the first include (#78, Codex round-11):
// the beacon file below is itself an include, and an archive that omitted or
// broke it would otherwise die before the handler existed — the one failure
// the handler is for, unrecorded. So the guard delegates to the beacon file
// when it loaded, and carries a MINIMAL inline copy of the decision for when
// it did not. That copy is the only beacon logic the unit suite cannot reach
// (this file cannot be required twice); it is kept to the fewest lines that
// still record "a fatal in one of our files ended this request".
register_shutdown_function(
	function () {
		if ( function_exists( 'aura_worker_shutdown_beacon' ) ) {
			aura_worker_shutdown_beacon();
			return;
		}
		// Fallback: the beacon file never loaded.
		$e = function_exists( 'error_get_last' ) ? error_get_last() : null;
		if ( ! is_array( $e ) || ! isset( $e['type'], $e['file'] ) || ! function_exists( 'get_option' ) ) {
			return;
		}
		$fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
		$file  = str_replace( '\\', '/', (string) $e['file'] );
		$dir   = rtrim( str_replace( '\\', '/', AURA_WORKER_DIR ), '/' ) . '/';
		$nonce = get_option( 'aura_worker_boot_nonce', '' );
		if ( 0 === ( (int) $e['type'] & $fatal ) || 0 !== stripos( $file, $dir ) || ! is_string( $nonce ) || '' === $nonce ) {
			return;
		}
		update_option(
			// Mirrors aura_worker_fatal_record_key(): one record per version.
			'aura_worker_boot_fatal_' . preg_replace( '/[^A-Za-z0-9._-]/', '_', AURA_WORKER_VERSION ),
			array( 'version' => AURA_WORKER_VERSION, 'nonce' => $nonce, 'file' => basename( $file ), 'message' => substr( (string) ( $e['message'] ?? '' ), 0, 200 ) ),
			false
		);
	}
);

// The boot beacon, before every other include. See includes/boot-beacon.php.
require_once AURA_WORKER_DIR . 'includes/boot-beacon.php';

// Load dependencies.
// The one rule the marker's credential list is read by — a pure function file
// with no side effects, so uninstall.php can require it without loading the
// plugin (#434).
require_once AURA_WORKER_DIR . 'includes/credential-rules.php';
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
require_once AURA_WORKER_DIR . 'includes/class-aura-worker-unbind.php';

/**
 * Initialize the plugin.
 */
function aura_worker_init() {
	aura_worker_maybe_upgrade();
	$plugin = new Aura_Worker();
	$plugin->init();
}

/**
 * Run the one-time repairs a version change needs, then record the version.
 *
 * 2.12.0: a record written by an earlier version carries no `site_ref`, so the
 * matcher cannot tell a rule scoped to a sibling site from one scoped to this
 * one and enforces both. Aura will not re-push a document it has already
 * confirmed, so the repair has to happen here, offline, from the envelope the
 * record already stores.
 *
 * The marker advances ONLY when the repair is complete (or there was nothing
 * to repair). A transient verification or write failure on the first
 * post-upgrade request would otherwise stamp the version done, the repair
 * would never run again, and — Aura never resending a confirmed envelope —
 * every scoped rule would stay client-wide on this site indefinitely.
 */
function aura_worker_maybe_upgrade() {
	if ( get_option( 'aura_worker_version' ) === AURA_WORKER_VERSION ) {
		return;
	}
	if ( class_exists( 'Aura_Worker_Rules' ) && ! Aura_Worker_Rules::backfill_from_stored_envelope() ) {
		return; // the NEXT request retries; the marker stays behind on purpose
	}
	update_option( 'aura_worker_version', AURA_WORKER_VERSION );
}
add_action( 'plugins_loaded', 'aura_worker_init' );


/**
 * Activation hook.
 */
function aura_worker_activate( $network_wide = false ) {
	aura_worker_for_each_site( 'aura_worker_activate_site', (bool) $network_wide );
}
register_activation_hook( __FILE__, 'aura_worker_activate' );

/**
 * Activation setup for ONE site.
 */
function aura_worker_activate_site() {
	// Store activation timestamp.
	update_option( 'aura_worker_activated', time() );
	// Two different jobs, and only one of them may evict (round-39).
	//
	// The revocation runs only on a site taken cleanly — exactly as deactivation
	// does — because revoking beneath a live handler has it return the plaintext
	// of a password already deleted. The stuck-claim repair, which is why an
	// operator reactivates at all, does evict; it just does not revoke anything,
	// and an evicted handler's own writes are all conditional on the claim it no
	// longer holds, so it revokes what it created and answers 409.
	$aura_fence = Aura_Worker_Magic_Link::seize_site();
	$aura_free  = ( '' !== $aura_fence );
	if ( ! $aura_free ) {
		$aura_fence = Aura_Worker_Magic_Link::repair_site_claim();
	}
	// Deactivation revokes the Application Password Aura minted, and KEEPS its
	// record whenever the revocation did not land. Reaching activation with that
	// record still present therefore means exactly one thing: a revocation that
	// failed. Finish it here (round-11), or a transient failure would leave an
	// administrator credential valid indefinitely — the documentation promises
	// reactivation as the cure, so it has to be one. A site that was never
	// deactivated by this hook has nothing recorded and this is a no-op.
	if ( ! $aura_free ) {
		// The site was held: the claim has been repaired, but nothing is revoked
		// here. The record survives for the next connect or uninstall.
		// translators: internal log line, not shown to the user.
		error_log( 'SiteAgent: a connect held the site while the plugin was activating, so a deferred Aura Application Password revocation was skipped.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	} elseif ( ! Aura_Worker_Magic_Link::revoke_managed_password( $aura_fence ) ) {
		// translators: internal log line, not shown to the user.
		error_log( 'SiteAgent: an Aura Application Password left over from a failed deactivation could not be revoked; revoke it by hand in Users → Profile → Application Passwords.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
	Aura_Worker_Magic_Link::release_site( $aura_fence );
	// Through the SAME decision as a request-time upgrade (round-1 P2). A site
	// updated while the plugin was inactive reaches this hook with the marker
	// still behind and `plugins_loaded` already past: stamping the version
	// here unconditionally — as this line used to — would retire the repair
	// before it ever ran, and every later request would return early from
	// aura_worker_maybe_upgrade() with the record still missing its identity.
	// One function writes the marker, and only behind a completed repair.
	aura_worker_maybe_upgrade();

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

/**
 * Run a per-site cleanup on every site of the network, or on this one alone.
 *
 * A network-activated plugin's activation and deactivation hooks fire ONCE, in
 * whichever blog context the request happens to be in (round-28). Every subsite
 * has its own options table and therefore its own Application Password record,
 * so without this the credentials of every OTHER subsite survive — administrator
 * credentials, on sites whose plugin is gone.
 *
 * @param callable $per_site           What to do in one blog's context.
 * @param bool     $network_wide Whether this is a network-wide operation.
 */
function aura_worker_for_each_site( callable $per_site, $network_wide = false ) {
	if ( $network_wide && is_multisite() && function_exists( 'get_sites' ) && function_exists( 'switch_to_blog' ) ) {
		foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $aura_blog_id ) {
			switch_to_blog( (int) $aura_blog_id );
			$per_site();
			restore_current_blog();
		}
		return;
	}
	$per_site();
}

/**
 * Deactivation hook.
 *
 * @param bool $network_deactivating Whether the plugin is being deactivated network-wide.
 */
function aura_worker_deactivate( $network_deactivating = false ) {
	aura_worker_for_each_site( 'aura_worker_deactivate_site', (bool) $network_deactivating );
}
register_deactivation_hook( __FILE__, 'aura_worker_deactivate' );

/**
 * Deactivation cleanup for ONE site.
 */
function aura_worker_deactivate_site() {
	// The site is SEIZED first (round-34), not merely unlocked. Two things have
	// to be true while this hook revokes: a connect paused between its mint and
	// its ownership check must fail that check — it would otherwise hand the
	// dashboard the plaintext of a password just revoked — and no NEW callback
	// may take the site and mint a replacement beside the revocation. Evicting
	// without claiming did the first and invited the second.
	$aura_fence = Aura_Worker_Magic_Link::seize_site();
	if ( '' === $aura_fence ) {
		// Without the fence the revocation is exactly the race it exists to
		// prevent (round-35): it could read the old record, let the callback
		// that won the site mint and record a replacement, and then delete the
		// replacement's record while revoking only the old UUID. So it does not
		// run. The record survives, and the next activation, connect or
		// uninstall finishes what this one could not.
		// translators: internal log line, not shown to the user.
		error_log( 'SiteAgent: a connect held the site while the plugin was deactivating, so the Aura Application Password was not revoked; reactivating or uninstalling will revoke it.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		return;
	}
	// Options are removed on uninstall, not here — with one exception. The
	// Application Password minted for the dashboard (2.11.0) is an
	// administrator-level WordPress credential. Unregistering SiteAgent's
	// routes does not touch it: core's REST API and every other REST/MCP
	// plugin keep accepting it, so a deactivated plugin would still leave Aura
	// able to act on the site (round-8). Best-effort — deactivation must not
	// fail — and revoke_managed_password() keeps its record whenever the
	// revocation did not land, so a reactivation or the uninstall can finish
	// the job.
	if ( ! Aura_Worker_Magic_Link::revoke_managed_password( $aura_fence ) ) {
		// translators: internal log line, not shown to the user.
		error_log( 'SiteAgent: the Aura Application Password could not be revoked while deactivating; revoke it by hand in Users → Profile → Application Passwords.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
	// Released, so the site is not left locked against every future connect.
	Aura_Worker_Magic_Link::release_site( $aura_fence );
}
