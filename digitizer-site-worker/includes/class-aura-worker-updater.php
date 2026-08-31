<?php
/**
 * Update handler for SiteAgent.
 *
 * Handles WordPress core, plugin, theme, translation, and database updates
 * using WordPress internal Upgrader classes.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Worker_Updater {

	/**
	 * Load required WordPress upgrade files.
	 */
	private function load_upgrade_dependencies() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
	}

	/**
	 * Get available updates for everything.
	 *
	 * Uses cached transients by default (lightweight).
	 * Pass ?refresh=1 to force a fresh check (requires more memory).
	 *
	 * @param bool $force_refresh Whether to force fresh update checks.
	 * @return array Update information.
	 */
	public function get_available_updates( $force_refresh = false ) {
		// Temporarily increase memory for update checks.
		wp_raise_memory_limit( 'admin' );

		// Load required admin files for update functions.
		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( $force_refresh ) {
			wp_version_check();
			wp_update_plugins();
			wp_update_themes();
		}

		$result = array(
			'core'         => $this->get_core_updates(),
			'plugins'      => $this->get_plugin_updates(),
			'themes'       => $this->get_theme_updates(),
			'translations' => $this->get_translation_updates(),
			'cached'       => ! $force_refresh,
		);

		return $result;
	}

	/**
	 * Get core update info.
	 *
	 * @return array|null Core update data or null.
	 */
	private function get_core_updates() {
		$updates = get_core_updates();

		if ( empty( $updates ) || ! is_array( $updates ) || is_wp_error( $updates ) ) {
			return null;
		}

		$update = $updates[0];
		if ( 'latest' === $update->response ) {
			return null;
		}

		return array(
			'current' => get_bloginfo( 'version' ),
			'new'     => $update->version,
			'locale'  => $update->locale,
		);
	}

	/**
	 * Get plugin updates.
	 *
	 * @return array List of plugins with available updates.
	 */
	private function get_plugin_updates() {
		$update_plugins = get_site_transient( 'update_plugins' );
		$updates        = array();

		if ( ! empty( $update_plugins->response ) ) {
			$all_plugins = get_plugins();

			foreach ( $update_plugins->response as $plugin_file => $plugin_data ) {
				$current_data = isset( $all_plugins[ $plugin_file ] ) ? $all_plugins[ $plugin_file ] : array();

				$updates[] = array(
					'file'        => $plugin_file,
					'slug'        => isset( $plugin_data->slug ) ? $plugin_data->slug : dirname( $plugin_file ),
					'name'        => isset( $current_data['Name'] ) ? $current_data['Name'] : '',
					'current'     => isset( $current_data['Version'] ) ? $current_data['Version'] : '',
					'new'         => isset( $plugin_data->new_version ) ? $plugin_data->new_version : '',
					'auto_update' => wp_is_auto_update_enabled_for_type( 'plugin' ),
				);
			}
		}

		return $updates;
	}

	/**
	 * Get theme updates.
	 *
	 * @return array List of themes with available updates.
	 */
	private function get_theme_updates() {
		$update_themes = get_site_transient( 'update_themes' );
		$updates       = array();

		if ( ! empty( $update_themes->response ) ) {
			foreach ( $update_themes->response as $theme_slug => $theme_data ) {
				$theme = wp_get_theme( $theme_slug );

				$updates[] = array(
					'slug'    => $theme_slug,
					'name'    => $theme->get( 'Name' ),
					'current' => $theme->get( 'Version' ),
					'new'     => isset( $theme_data['new_version'] ) ? $theme_data['new_version'] : '',
				);
			}
		}

		return $updates;
	}

	/**
	 * Get translation updates.
	 *
	 * @return int Number of translation updates available.
	 */
	private function get_translation_updates() {
		$translations = wp_get_translation_updates();
		return count( $translations );
	}

	/**
	 * Self-update SiteAgent from a zip URL (e.g. GitHub release asset).
	 *
	 * Downloads the zip, overwrites the current plugin files, and reactivates.
	 *
	 * @param string $zip_url         URL to the plugin zip file.
	 * @param string $expected_sha256 Optional hex SHA-256 the downloaded zip must
	 *                                match before install (gateway-bound digest).
	 * @return array Result with success status, message, and version info.
	 */
	public function self_update( $zip_url, $expected_sha256 = '' ) {
		$this->load_upgrade_dependencies();

		// Load the recovery classes BEFORE the install, so their code is in
		// memory from the OLD build. After `install()` this plugin's directory
		// has been replaced; a class first required afterwards would be read
		// from the new files — which are exactly what we may be about to decide
		// are broken. `batch_update_plugins()` takes the same precaution.
		require_once plugin_dir_path( __FILE__ ) . 'class-aura-worker-health.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-aura-worker-rollback.php';

		$old_version = AURA_WORKER_VERSION;
		$plugin_file = 'digitizer-site-worker/digitizer-site-worker.php';
		$plugin_slug = 'digitizer-site-worker';

		$rollback = new Aura_Worker_Rollback();

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );

		// Integrity: when the gateway bound an expected sha256, download the zip
		// first, verify its bytes, then install from the LOCAL file. The grant
		// covers the URL; this covers the bytes — a tampered download (e.g. a
		// compromised CDN edge) is refused before install. No digest → install
		// straight from the URL (back-compat).
		$install_source  = $zip_url;
		$tmp             = '';
		$expected_sha256 = strtolower( trim( (string) $expected_sha256 ) );
		if ( '' !== $expected_sha256 ) {
			if ( ! function_exists( 'download_url' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			$tmp = download_url( $zip_url );
			if ( is_wp_error( $tmp ) ) {
				return array(
					'success' => false,
					'error'   => $tmp->get_error_message(),
				);
			}
			$verified = $this->verify_zip_integrity( $tmp, $expected_sha256 );
			if ( is_wp_error( $verified ) ) {
				wp_delete_file( $tmp );
				return array(
					'success' => false,
					'error'   => $verified->get_error_message(),
				);
			}
			$install_source = $tmp;
		}

		// Back up this plugin's own directory so a bad build can be undone.
		// Taken HERE, not at the top: everything above can still refuse the
		// update (a bad digest, a failed download), and a backup made for an
		// install that never runs is just a zip nobody asked for.
		//
		// A backup that CANNOT be made does not refuse the update. Refusing
		// would be safer for this one site and would silently make every site
		// without ZipArchive — or with an unwritable backup dir — permanently
		// un-updatable: a gate that can never pass, which is the defect Aura
		// #472 spent months not noticing. The result reports `backed_up`, so the
		// caller records which updates had no way back instead of the plugin
		// quietly deciding that for it.
		$backup      = $rollback->backup_plugin( $plugin_slug );
		$backup_path = ! empty( $backup['success'] ) ? $backup['backup_path'] : null;

		// Where the error log ends BEFORE the install, and WHICH file that was.
		// The verdict reads only past this point, so a fatal already in the log
		// cannot be attributed to this update (Codex round-1 P2); and it reads
		// the SAME file, so an offset taken on debug.log is never applied to a
		// PHP log the new build creates (Codex round-4 P1).
		$log_snapshot = $this->error_log_snapshot();

		// Ask the next boot of this plugin to announce itself. Random, so a
		// beacon left by any earlier boot cannot satisfy this verdict.
		$boot_nonce = bin2hex( random_bytes( 16 ) );
		update_option( 'aura_worker_boot_nonce', $boot_nonce, false );

		// Install from the verified local file (or the URL when no digest given).
		$result = $upgrader->install( $install_source, array( 'overwrite_package' => true ) );

		if ( '' !== $tmp && file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}

		// A FAILED install is the case that most needs the backup: `install()`
		// with `overwrite_package` deletes before it writes, so a failure
		// partway can leave this plugin's directory incomplete. Restoring here
		// is what makes a failed self-update survivable rather than terminal.
		if ( is_wp_error( $result ) ) {
			return $this->self_update_install_failed(
				$rollback,
				$plugin_slug,
				$plugin_file,
				$backup_path,
				$old_version,
				$result->get_error_message()
			);
		}

		if ( false === $result ) {
			$messages = $skin->get_upgrade_messages();
			$last_msg = ! empty( $messages ) ? end( $messages ) : '';
			return $this->self_update_install_failed(
				$rollback,
				$plugin_slug,
				$plugin_file,
				$backup_path,
				$old_version,
				__( 'Self-update failed — filesystem error.', 'digitizer-site-worker' ),
				$last_msg
			);
		}

		// Ensure the plugin is activated after overwrite.
		if ( ! is_plugin_active( $plugin_file ) ) {
			activate_plugin( $plugin_file );
		}

		// Clear plugin cache so WordPress reads the fresh file header.
		wp_clean_plugins_cache( true );
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		// Read new version from the updated file header.
		$new_data    = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );
		$new_version = $new_data['Version'] ?? 'unknown';

		// Did THIS build come up? See `verify_self_update()` — the aggregate
		// site health check cannot answer that question (Codex round-1).
		$health_result = $this->verify_self_update( $new_version, $boot_nonce, $log_snapshot );
		// Whatever happened, the request for a beacon is spent.
		delete_option( 'aura_worker_boot_nonce' );
		$healthy       = ! empty( $health_result['healthy'] );

		if ( ! $healthy && null !== $backup_path ) {
			// Restoring works even though the on-disk plugin is broken: this
			// method, Aura_Worker_Rollback and ZipArchive are all already in
			// memory from the pre-install require above.
			$rb       = $this->attempt_rollback( $rollback, $plugin_slug, $plugin_file, $backup_path, $old_version );
			$restored = $rb['restored'];
			// The message has to agree with the fields (Codex round-2 P2). A
			// dashboard that shows only `error` was told the site had recovered
			// while `rolled_back` said otherwise — and the site may still be
			// carrying the broken build.
			$message = $restored
				? sprintf(
					/* translators: %s: version that was rolled back to */
					__( 'SiteAgent update failed its health check and was rolled back to %s.', 'digitizer-site-worker' ),
					$old_version
				)
				: __( 'SiteAgent update failed its health check AND could not be rolled back — the site may still be running the broken build.', 'digitizer-site-worker' );
			return array(
				'success'       => false,
				'error'         => $message,
				'old_version'   => $old_version,
				'new_version'   => $new_version,
				'backed_up'     => true,
				'health_checked'=> true,
				'healthy'       => false,
				'health'        => $health_result,
				'rolled_back'   => $restored,
				'restore_error' => $rb['error'],
			);
		}

		if ( ! $healthy ) {
			// Unhealthy with nothing to restore. Say so plainly instead of
			// reporting a success the site cannot support — the operator needs
			// to know this one needs hands.
			return array(
				'success'       => false,
				'error'         => __( 'SiteAgent update failed its health check and no backup was available to roll back.', 'digitizer-site-worker' ),
				'old_version'   => $old_version,
				'new_version'   => $new_version,
				'backed_up'     => false,
				'health_checked'=> true,
				'healthy'       => false,
				'health'        => $health_result,
				'rolled_back'   => false,
			);
		}

		// The update stuck, so older copies of this plugin are no longer the
		// thing anyone would restore. Bounded, not emptied: the most recent few
		// stay, because "the update succeeded" and "the new build is good" are
		// not the same claim on a site nobody has looked at yet.
		$rollback->cleanup_old_backups( 3 );

		return array(
			'success'      => true,
			'message'      => sprintf(
				/* translators: %1$s: old version, %2$s: new version */
				__( 'SiteAgent updated from %1$s to %2$s.', 'digitizer-site-worker' ),
				$old_version,
				$new_version
			),
			'old_version'  => $old_version,
			'new_version'  => $new_version,
			'backed_up'    => null !== $backup_path,
			'health_checked'=> true,
			'healthy'      => true,
			// `verified` is the beacon, specifically: a success with `verified:
			// false` is an update that stood because the evidence was
			// INCONCLUSIVE, not because the build was seen to boot. Aura's
			// update log should be able to tell those apart.
			'verified'     => ! empty( $health_result['verified'] ),
			'health'       => $health_result,
			'rolled_back'  => false,
		);
	}

	/**
	 * The version WordPress reads from a plugin's own file header, right now.
	 * The one post-condition a rollback can be held to: the old build is back
	 * only if the file on disk says so.
	 *
	 * @param string $plugin_file Plugin file relative to WP_PLUGIN_DIR.
	 * @return string|null
	 */
	private function installed_version( $plugin_file ) {
		$path = WP_PLUGIN_DIR . '/' . $plugin_file;
		if ( ! file_exists( $path ) ) {
			return null;
		}
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( false );
		}
		$data = get_plugin_data( $path, false, false );
		return isset( $data['Version'] ) ? (string) $data['Version'] : null;
	}

	/**
	 * Current size of the PHP error log, or null when there is none to read.
	 * Paired with `new_fatals_since()` so a verdict can consider only what this
	 * update wrote.
	 *
	 * @return int|null
	 */
	private function error_log_snapshot() {
		$log = $this->error_log_path();
		if ( null === $log ) {
			return null;
		}
		clearstatcache( true, $log );
		return array( 'path' => $log, 'size' => (int) filesize( $log ) );
	}

	/** The log both halves read. Mirrors Aura_Worker_Health's resolution order. */
	private function error_log_path() {
		$log = ini_get( 'error_log' );
		if ( empty( $log ) || ! file_exists( $log ) ) {
			$log = WP_CONTENT_DIR . '/debug.log';
		}
		return file_exists( $log ) ? $log : null;
	}

	/**
	 * Fatal/parse errors written to the log AFTER $offset bytes.
	 *
	 * @param int|null $offset Size of the log before the install.
	 * @return int Count of new fatals; 0 when there is nothing to read.
	 */
	private function new_fatals_since( $snapshot ) {
		$current = $this->error_log_path();
		$total   = 0;

		// The file we measured before the install, read from where it ended.
		if ( is_array( $snapshot ) && file_exists( $snapshot['path'] ) ) {
			$total += $this->fatals_in( $snapshot['path'], (int) $snapshot['size'] );
		}
		// A file that did not exist before and does now — the configured PHP
		// log the new build CREATED by fatalling into it (Codex round-2 P1),
		// possibly a different file from the one measured (round-4 P1). Read
		// from byte zero: everything in it is after the install.
		if ( null !== $current && ( ! is_array( $snapshot ) || $current !== $snapshot['path'] ) ) {
			$total += $this->fatals_in( $current, 0 );
		}
		return $total;
	}

	/**
	 * Fatal/parse errors in one log file from byte $from to its end.
	 *
	 * @param string $log  Absolute path.
	 * @param int    $from Byte offset to start at.
	 * @return int
	 */
	private function fatals_in( $log, $from ) {
		clearstatcache( true, $log );
		$size = (int) filesize( $log );
		// A log that SHRANK below its offset was rotated under us; there is no
		// longer a window this update owns, so claim nothing rather than read
		// someone else's.
		if ( $size <= $from ) {
			return 0;
		}
		$fp = fopen( $log, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $fp ) {
			return 0;
		}

		// Scan the WHOLE window, in chunks — not its first 64 KiB (Codex
		// round-3). The install and three loopback probes can emit that much in
		// notices and deprecations before the bootstrap fatal, and reading only
		// the beginning missed exactly the entry this exists to find. A
		// bootstrap failure also makes all three REST probes fail identically,
		// so REST reads as inconclusive and the missed fatal is the only
		// evidence left.
		//
		// `$overlap` carries the tail of each chunk into the next so a phrase
		// straddling a boundary is still matched, then its own matches are
		// subtracted so nothing is counted twice.
		fseek( $fp, $from );
		$remaining = $size - $from;
		$overlap   = '';
		$fatals    = 0;
		while ( $remaining > 0 ) {
			$chunk = fread( $fp, (int) min( $remaining, 65536 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$remaining -= strlen( $chunk );
			$window     = $overlap . $chunk;
			$fatals    += $this->count_attributed_fatals( $window );
			if ( '' !== $overlap ) {
				$fatals -= $this->count_attributed_fatals( $overlap );
			}
			// Wide enough that a fatal line and the path it names never straddle
			// a boundary without being wholly inside one window.
			$overlap = substr( $window, -4096 );
		}
		fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return max( 0, $fatals );
	}

	/**
	 * Write the boot beacon — the fact `verify_self_update()` reads.
	 *
	 * Called as the LAST line of `aura_worker_init()`, so it runs only if
	 * loading this plugin and `Aura_Worker::init()` both completed. Writes only
	 * when the updater has asked (left a nonce), so an ordinary request does no
	 * database write; echoes the nonce so a beacon from an earlier boot cannot
	 * satisfy a later verdict.
	 *
	 * Lives here, not in the entry file, so it can be unit-tested: the entry
	 * file defines constants and cannot be required twice.
	 *
	 * @param string $version The version of the build that is now running.
	 * @return bool Whether a beacon was written.
	 */
	public static function write_boot_beacon( $version ) {
		$nonce = get_option( 'aura_worker_boot_nonce', '' );
		if ( ! is_string( $nonce ) || '' === $nonce ) {
			return false;
		}
		$written = update_option(
			'aura_worker_boot',
			array( 'version' => (string) $version, 'nonce' => $nonce ),
			false
		);
		delete_option( 'aura_worker_boot_nonce' );
		return (bool) $written;
	}

	/**
	 * Fatal/parse-error LINES in a piece of log text that name this plugin's
	 * directory. A fatal names the file it died in, so "was it ours?" is read
	 * off the line rather than inferred from when it was written.
	 *
	 * @param string $text Log text.
	 * @return int
	 */
	private function count_attributed_fatals( $text ) {
		$n = 0;
		foreach ( preg_split( '/\r?\n/', (string) $text ) as $line ) {
			if ( preg_match( '/PHP (Fatal|Parse) error/i', $line )
				&& false !== stripos( $line, '/digitizer-site-worker/' ) ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * Did the build that was just installed actually come up?
	 *
	 * Answered from a FACT the new build writes, not inferred from the outside.
	 * Four review rounds found a case where every inferential signal lies: the
	 * home page served from a full-page cache without starting PHP; 401/403
	 * meaning "registered" on one site and "REST closed to everyone" on
	 * another; a 500 differing from a 404 control; a log tail that was too
	 * short, or the wrong file. Each patch converged on nothing because the
	 * question — "did this code boot?" — has no answer in status codes.
	 *
	 * So: leave a nonce, make ONE uncacheable request so a fresh PHP process
	 * loads the new files, then read `aura_worker_boot`. The new build's own
	 * `aura_worker_boot_beacon()` writes it as the LAST line of init, echoing
	 * the nonce. Beacon carrying our nonce and the new version ⇒ it booted.
	 *
	 * What can still be wrong, stated rather than hidden:
	 *  - The request never reached PHP at all (transport failure: the site
	 *    cannot connect to itself). No beacon then proves nothing, and rolling
	 *    back on it would make every update fail on such a site for ever — the
	 *    unsatisfiable-gate shape Aura #472 spent months in. Reported as
	 *    `inconclusive`, no rollback, `verified: false`.
	 *  - A cache answering a REST URL that carries a random query argument and
	 *    no-cache headers. Accepted as the residual; REST is not page-cached in
	 *    any mainstream setup.
	 *
	 * The error log is secondary evidence only: new fatals written since the
	 * install still fail the verdict, but their absence proves nothing.
	 *
	 * @param string     $new_version  Version read from the installed header.
	 * @param string     $nonce        The nonce left in `aura_worker_boot_nonce`.
	 * @param array|null $log_snapshot From `error_log_snapshot()`, before install.
	 * @return array { healthy: bool, inconclusive: bool, checks: array }
	 */
	private function verify_self_update( $new_version, $nonce, $log_snapshot ) {
		$probe = wp_remote_get(
			add_query_arg( array( 'aura_probe' => $nonce ), rest_url( 'aura/v1/status' ) ),
			array(
				'timeout'     => 15,
				'sslverify'   => false,
				'redirection' => 0,
				'headers'     => array( 'Cache-Control' => 'no-cache', 'Pragma' => 'no-cache' ),
			)
		);
		$reached = ! is_wp_error( $probe );

		// Read the fact UNCACHED: the option cache in this process may still hold
		// the pre-install state.
		wp_cache_delete( 'aura_worker_boot', 'options' );
		$beacon = get_option( 'aura_worker_boot' );
		$booted = is_array( $beacon )
			&& isset( $beacon['nonce'], $beacon['version'] )
			&& hash_equals( $nonce, (string) $beacon['nonce'] )
			&& (string) $beacon['version'] === (string) $new_version;

		$new_fatals = $this->new_fatals_since( $log_snapshot );

		$checks = array(
			'loopback'   => array(
				'status' => $reached ? 'pass' : 'fail',
				'detail' => $reached
					? 'HTTP ' . (int) wp_remote_retrieve_response_code( $probe )
					: $probe->get_error_message(),
			),
			'boot_beacon' => array(
				'status' => $booted ? 'pass' : 'fail',
				'detail' => $booted
					? 'build ' . $new_version . ' reported boot'
					: ( is_array( $beacon ) ? 'stale beacon' : 'no beacon written' ),
			),
			'php_errors' => array(
				'status' => $new_fatals ? 'fail' : 'pass',
				'detail' => $new_fatals
					? $new_fatals . ' new fatal error(s) in this plugin since the install'
					: 'No new fatal errors attributed to this plugin',
			),
		);

		// The decision rests on POSITIVE facts only, in both directions.
		//
		// Health: the beacon. Breakage: a new fatal that NAMES THIS PLUGIN'S
		// DIRECTORY — PHP fatal lines carry the file path, so attribution is a
		// fact in the message, not a guess from timing. An unrelated plugin's
		// fatal landing in the shared log between snapshot and check cannot
		// override a valid beacon (Codex round-5 P2).
		//
		// What is deliberately NOT a fact: "we got an HTTP response". A CDN, WAF
		// or reverse proxy can answer a 301 or a 403 challenge before WordPress
		// ever runs, and no beacon can be written then; treating that response
		// as proof PHP was reached rolled back healthy builds on every site
		// fronted that way — for ever (Codex round-5 P1). So `$reached` is
		// reported and decides nothing.
		//
		// No beacon AND no attributed fatal is therefore INCONCLUSIVE: the
		// update stands, `verified: false`. That includes a build that fatals
		// on load on a site with error logging off — stated here rather than
		// hidden. It is the pre-#78 exposure, now visible in Aura's update log
		// as unverified and bounded by its 5-per-night cap; the alternative,
		// rolling back on absence of evidence, makes every edge-fronted site
		// permanently un-updatable, which is the worse failure and the one that
		// hides.
		$broken       = $new_fatals > 0;
		$inconclusive = ! $booted && ! $broken;
		$healthy      = ! $broken && ( $booted || $inconclusive );

		return array(
			'healthy'      => $healthy,
			'inconclusive' => $inconclusive,
			'verified'     => $booted,
			'checks'       => $checks,
		);
	}

	/**
	 * Put the previous build back and PROVE it is back.
	 *
	 * The single place a rollback is decided (Codex round-4 P1: the two exits
	 * had drifted — one checked the header, one trusted the step result). Fifteen
	 * findings on this branch were one class, a success value resting on
	 * evidence that could not support it, so this returns success only on the
	 * post-condition: the plugin's own header reads the version we came from.
	 *
	 * @return array { restored: bool, error: string|null }
	 */
	private function attempt_rollback( $rollback, $plugin_slug, $plugin_file, $backup_path, $old_version ) {
		if ( null === $backup_path ) {
			return array( 'restored' => false, 'error' => null );
		}
		$restore = $rollback->restore_plugin( $plugin_slug, $backup_path );
		if ( empty( $restore['success'] ) ) {
			return array( 'restored' => false, 'error' => (string) ( $restore['error'] ?? 'restore failed' ) );
		}
		if ( $old_version !== $this->installed_version( $plugin_file ) ) {
			return array(
				'restored' => false,
				'error'    => 'restore completed but the plugin header does not read ' . $old_version,
			);
		}
		return array( 'restored' => true, 'error' => null );
	}

	/**
	 * Shared exit for a self-update whose install did not complete: put the
	 * previous build back when there is one, and report what happened either
	 * way. Deliberately one function — the two failure shapes above differ only
	 * in their message, and duplicating the restore would let the two drift
	 * until one of them silently stopped restoring.
	 *
	 * @param Aura_Worker_Rollback $rollback    Loaded before the install.
	 * @param string               $plugin_slug This plugin's directory name.
	 * @param string|null          $backup_path Backup zip, or null if none was made.
	 * @param string               $error       Message describing the failure.
	 * @param string               $detail      Optional upgrader detail.
	 * @return array
	 */
	private function self_update_install_failed( $rollback, $plugin_slug, $plugin_file, $backup_path, $old_version, $error, $detail = '' ) {
		$rb            = $this->attempt_rollback( $rollback, $plugin_slug, $plugin_file, $backup_path, $old_version );
		$restored      = $rb['restored'];
		$restore_error = $rb['error'];

		// The message must carry the recovery outcome too (Codex round-4 P2): a
		// consumer showing only `error` was told about the upgrader failure and
		// not that the plugin may now be missing.
		if ( null !== $backup_path && ! $restored ) {
			$error .= ' ' . __( 'The previous build could NOT be restored — the plugin may be missing or incomplete.', 'digitizer-site-worker' );
		}

		$out = array(
			'success'       => false,
			'error'         => $error,
			'backed_up'     => null !== $backup_path,
			'health_checked'=> false,
			'rolled_back'   => $restored,
			'restore_error' => $restore_error,
		);
		if ( '' !== $detail ) {
			$out['detail'] = $detail;
		}
		return $out;
	}

	/**
	 * Verify a downloaded zip's bytes against the gateway-bound SHA-256.
	 *
	 * @param string $file            Path to the downloaded zip.
	 * @param string $expected_sha256 Expected lower-case hex SHA-256.
	 * @return true|WP_Error True when the file matches; WP_Error otherwise.
	 */
	private function verify_zip_integrity( $file, $expected_sha256 ) {
		$expected = strtolower( trim( (string) $expected_sha256 ) );
		if ( ! preg_match( '/^[0-9a-f]{64}$/', $expected ) ) {
			return new WP_Error(
				'aura_self_update_bad_digest',
				__( 'Self-update integrity check failed: malformed expected digest.', 'digitizer-site-worker' )
			);
		}
		$actual = hash_file( 'sha256', $file );
		if ( ! is_string( $actual ) || ! hash_equals( $expected, strtolower( $actual ) ) ) {
			return new WP_Error(
				'aura_self_update_integrity',
				__( 'Self-update integrity check failed: downloaded package does not match the expected SHA-256.', 'digitizer-site-worker' )
			);
		}
		return true;
	}

	/**
	 * Update a specific plugin.
	 *
	 * @param string $plugin_file Plugin file path (e.g., "akismet/akismet.php").
	 * @return array Result with success status and message.
	 */
	public function update_plugin( $plugin_file ) {
		$this->load_upgrade_dependencies();

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( $plugin_file );

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'error'   => $result->get_error_message(),
			);
		}

		if ( false === $result ) {
			return array(
				'success' => false,
				'error'   => __( 'Update failed. The plugin may not have an update available.', 'digitizer-site-worker' ),
			);
		}

		if ( null === $result ) {
			return array(
				'success' => false,
				'error'   => __( 'No update available for this plugin.', 'digitizer-site-worker' ),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Plugin updated successfully.', 'digitizer-site-worker' ),
		);
	}

	/**
	 * Update a specific theme.
	 *
	 * @param string $theme_slug Theme stylesheet slug.
	 * @return array Result with success status and message.
	 */
	public function update_theme( $theme_slug ) {
		$this->load_upgrade_dependencies();

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Theme_Upgrader( $skin );
		$result   = $upgrader->upgrade( $theme_slug );

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'error'   => $result->get_error_message(),
			);
		}

		if ( false === $result ) {
			return array(
				'success' => false,
				'error'   => __( 'Update failed. The theme may not have an update available.', 'digitizer-site-worker' ),
			);
		}

		if ( null === $result ) {
			return array(
				'success' => false,
				'error'   => __( 'No update available for this theme.', 'digitizer-site-worker' ),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Theme updated successfully.', 'digitizer-site-worker' ),
		);
	}

	/**
	 * Update WordPress core.
	 *
	 * @return array Result with success status and message.
	 */
	public function update_core() {
		$this->load_upgrade_dependencies();

		$updates = get_core_updates();

		if ( empty( $updates ) || ! is_array( $updates ) || 'latest' === $updates[0]->response ) {
			return array(
				'success' => true,
				'message' => __( 'WordPress is already up to date.', 'digitizer-site-worker' ),
			);
		}

		$update   = $updates[0];
		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Core_Upgrader( $skin );
		$result   = $upgrader->upgrade( $update );

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'error'   => $result->get_error_message(),
			);
		}

		if ( false === $result ) {
			return array(
				'success' => false,
				'error'   => __( 'Core update failed (filesystem error).', 'digitizer-site-worker' ),
			);
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %s: WordPress version */
				__( 'WordPress updated to %s.', 'digitizer-site-worker' ),
				$update->version
			),
		);
	}

	/**
	 * Update all translations.
	 *
	 * @return array Result with success status and message.
	 */
	public function update_translations() {
		$this->load_upgrade_dependencies();

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Language_Pack_Upgrader( $skin );
		$result   = $upgrader->bulk_upgrade();

		if ( false === $result ) {
			return array(
				'success' => false,
				'error'   => __( 'Translation update failed.', 'digitizer-site-worker' ),
			);
		}

		$updated_count = is_array( $result ) ? count( array_filter( $result ) ) : 0;

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of translations updated */
				__( '%d translation(s) updated.', 'digitizer-site-worker' ),
				$updated_count
			),
		);
	}

	/**
	 * Update a single plugin using Plugin_Upgrader.
	 *
	 * @param string $plugin_file Plugin file path (e.g., "akismet/akismet.php").
	 * @return array { success: bool, error?: string }
	 */
	private function update_single_plugin( $plugin_file ) {
		$this->load_upgrade_dependencies();

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( $plugin_file );

		if ( is_wp_error( $result ) ) {
			return array( 'success' => false, 'error' => $result->get_error_message() );
		}

		if ( false === $result || null === $result ) {
			return array( 'success' => false, 'error' => __( 'Update failed or no update available.', 'digitizer-site-worker' ) );
		}

		return array( 'success' => true );
	}

	/**
	 * Update plugins in chunks with optional backup and health-check auto-rollback.
	 *
	 * For each plugin:
	 *   1. Backup (if $create_backup is true) using Aura_Worker_Rollback.
	 *   2. Update using Plugin_Upgrader.
	 *   3. Health check using Aura_Worker_Health.
	 *   4. If health check fails → auto-rollback from backup.
	 *   5. Record result (updated / failed / rolled_back / skipped).
	 *
	 * Between chunks: wp_cache_flush() and gc_collect_cycles().
	 * After all chunks: cleanup old backups.
	 *
	 * @param array $plugins       List of plugin file paths (e.g. ["akismet/akismet.php"]).
	 * @param int   $chunk_size    Number of plugins to process per chunk (default 5).
	 * @param bool  $create_backup Whether to create a backup before each update (default true).
	 * @return array { results: array, summary: array }
	 */
	public function batch_update_plugins( $plugins, $chunk_size = 5, $create_backup = true ) {
		require_once plugin_dir_path( __FILE__ ) . 'class-aura-worker-health.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-aura-worker-rollback.php';

		$results  = array();
		$rollback = new Aura_Worker_Rollback();
		$health   = new Aura_Worker_Health();
		$chunks   = array_chunk( $plugins, max( 1, (int) $chunk_size ) );

		foreach ( $chunks as $chunk ) {
			foreach ( $chunk as $plugin_file ) {
				$slug        = dirname( $plugin_file );
				$backup_path = null;
				$entry       = array(
					'plugin'  => $plugin_file,
					'status'  => 'skipped',
					'detail'  => '',
				);

				// 1. Backup.
				if ( $create_backup ) {
					$backup_result = $rollback->backup_plugin( $slug );
					if ( $backup_result['success'] ) {
						$backup_path = $backup_result['backup_path'];
					} else {
						$entry['status'] = 'failed';
						$entry['detail'] = 'Backup failed: ' . $backup_result['error'];
						$results[]       = $entry;
						continue;
					}
				}

				// 2. Update.
				$update_result = $this->update_single_plugin( $plugin_file );
				if ( ! $update_result['success'] ) {
					$entry['status'] = 'failed';
					$entry['detail'] = $update_result['error'];
					$results[]       = $entry;
					continue;
				}

				// 3. Health check.
				$health_result = $health->run_health_check();
				if ( ! $health_result['healthy'] ) {
					// 4. Auto-rollback.
					if ( $backup_path ) {
						$restore_result  = $rollback->restore_plugin( $slug, $backup_path );
						$entry['status'] = 'rolled_back';
						$entry['detail'] = 'Health check failed; rollback ' . ( $restore_result['success'] ? 'succeeded' : 'failed: ' . $restore_result['error'] );
					} else {
						$entry['status'] = 'failed';
						$entry['detail'] = 'Health check failed; no backup available for rollback';
					}
					$results[] = $entry;
					continue;
				}

				// 5. Success.
				$entry['status'] = 'updated';
				$entry['detail'] = 'Update and health check passed';
				$results[]       = $entry;
			}

			// Between chunks: flush caches and run garbage collection.
			wp_cache_flush();
			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
		}

		// Cleanup old backups after all chunks.
		$rollback->cleanup_old_backups();

		// Build summary.
		$summary = array(
			'total'       => count( $plugins ),
			'updated'     => 0,
			'failed'      => 0,
			'rolled_back' => 0,
			'skipped'     => 0,
		);
		foreach ( $results as $r ) {
			if ( isset( $summary[ $r['status'] ] ) ) {
				$summary[ $r['status'] ]++;
			}
		}

		return array(
			'results' => $results,
			'summary' => $summary,
		);
	}

	/**
	 * Get the plugin migration registry.
	 *
	 * Maps known plugin slugs to their detection, pending-check, and
	 * migration callables. Third-party plugins can register their own
	 * entries via the `aura_worker_migration_registry` filter.
	 *
	 * @return array Keyed array of migration entries.
	 */
	private function get_migration_registry() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$registry = array(
			'elementor'     => array(
				'label'   => 'Elementor',
				'detect'  => function () {
					return defined( 'ELEMENTOR_VERSION' ) && is_plugin_active( 'elementor/elementor.php' );
				},
				'pending' => function () {
					if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
						return false;
					}
					$db_ver = get_option( 'elementor_version', '0' );
					return version_compare( $db_ver, ELEMENTOR_VERSION, '<' );
				},
				'run'     => function () {
					if ( ! class_exists( '\Elementor\Plugin' ) ) {
						return;
					}
					\Elementor\Plugin::instance()->files_manager->clear_cache();

					$upgrade = \Elementor\Plugin::instance()->upgrade ?? null;
					if ( ! $upgrade ) {
						return;
					}

					// Run upgrade callbacks directly instead of using
					// do_upgrade() which dispatches a background runner via
					// loopback HTTP — that blocks in REST API context and
					// fails when DISABLE_WP_CRON is set.
					$callbacks = $upgrade->get_upgrade_callbacks();
					foreach ( $callbacks as $callback ) {
						if ( is_callable( $callback ) ) {
							call_user_func( $callback, $upgrade );
						}
					}

					$upgrade->on_runner_complete( true );
				},
			),
			'elementor-pro' => array(
				'label'   => 'Elementor Pro',
				'detect'  => function () {
					return defined( 'ELEMENTOR_PRO_VERSION' ) && is_plugin_active( 'elementor-pro/elementor-pro.php' );
				},
				'pending' => function () {
					if ( ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
						return false;
					}
					$db_ver = get_option( 'elementor_pro_version', '0' );
					return version_compare( $db_ver, ELEMENTOR_PRO_VERSION, '<' );
				},
				'run'     => function () {
					if ( ! class_exists( '\ElementorPro\Plugin' ) ) {
						return;
					}

					$upgrade = \ElementorPro\Plugin::instance()->upgrade ?? null;
					if ( ! $upgrade ) {
						return;
					}

					$callbacks = $upgrade->get_upgrade_callbacks();
					foreach ( $callbacks as $callback ) {
						if ( is_callable( $callback ) ) {
							call_user_func( $callback, $upgrade );
						}
					}

					$upgrade->on_runner_complete( true );
				},
			),
			'woocommerce'   => array(
				'label'   => 'WooCommerce',
				'detect'  => function () {
					return defined( 'WC_VERSION' ) && is_plugin_active( 'woocommerce/woocommerce.php' );
				},
				'pending' => function () {
					if ( ! defined( 'WC_VERSION' ) ) {
						return false;
					}
					$db_ver = get_option( 'woocommerce_db_version', '0' );
					return version_compare( $db_ver, WC_VERSION, '<' );
				},
				'run'     => function () {
					if ( class_exists( 'WC_Install' ) ) {
						\WC_Install::install();
					}
				},
			),
			'jet-engine'    => array(
				'label'   => 'JetEngine (Crocoblock)',
				'detect'  => function () {
					return defined( 'JET_ENGINE_VERSION' ) && is_plugin_active( 'jet-engine/jet-engine.php' );
				},
				'pending' => function () {
					if ( ! defined( 'JET_ENGINE_VERSION' ) ) {
						return false;
					}
					$db_ver = get_option( 'jet_engine_db_version', '0' );
					return version_compare( $db_ver, JET_ENGINE_VERSION, '<' );
				},
				'run'     => function () {
					if ( function_exists( 'jet_engine' ) && isset( jet_engine()->update_db_updater ) ) {
						jet_engine()->update_db_updater->update_db();
					}
				},
			),
		);

		/**
		 * Filter the plugin migration registry.
		 *
		 * Allows third-party plugins to register their own database
		 * migration handlers without modifying SiteAgent core.
		 *
		 * @param array $registry Keyed array of migration entries.
		 */
		return apply_filters( 'aura_worker_migration_registry', $registry );
	}

	/**
	 * Get database migration status for all detected plugins.
	 *
	 * Returns which plugins are installed and whether they have
	 * pending database migrations.
	 *
	 * @return array Keyed array of { label, pending } per plugin.
	 */
	public function get_database_status() {
		$registry   = $this->get_migration_registry();
		$migrations = array();

		foreach ( $registry as $key => $entry ) {
			if ( call_user_func( $entry['detect'] ) ) {
				$migrations[ $key ] = array(
					'label'   => $entry['label'],
					'pending' => (bool) call_user_func( $entry['pending'] ),
				);
			}
		}

		return $migrations;
	}

	/**
	 * Run database upgrade.
	 *
	 * When $plugin is null, runs WordPress core dbDelta (wp_upgrade).
	 * When $plugin is a registry key, runs that plugin's migration.
	 *
	 * @param string|null $plugin Optional plugin key from migration registry.
	 * @return array Result with success status.
	 */
	public function update_database( $plugin = null ) {
		// Extend execution time for potentially long migrations.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged,WordPress.PHP.NoSilencedErrors.Discouraged -- Required for long-running DB migrations.
		}

		// Plugin-specific migration.
		if ( $plugin ) {
			$registry = $this->get_migration_registry();

			if ( ! isset( $registry[ $plugin ] ) ) {
				return array(
					'success' => false,
					'error'   => __( 'Unknown plugin migration key.', 'digitizer-site-worker' ),
				);
			}

			$entry = $registry[ $plugin ];

			if ( ! call_user_func( $entry['detect'] ) ) {
				return array(
					'success' => false,
					'error'   => sprintf(
						/* translators: %s: Plugin label */
						__( '%s is not installed or active.', 'digitizer-site-worker' ),
						$entry['label']
					),
				);
			}

			$is_async = ! empty( $entry['async'] );

			try {
				call_user_func( $entry['run'] );
			} catch ( \Throwable $e ) {
				return array(
					'success' => false,
					'error'   => sprintf(
						/* translators: %1$s: Plugin label, %2$s: Error message */
						__( '%1$s migration failed: %2$s', 'digitizer-site-worker' ),
						$entry['label'],
						$e->getMessage()
					),
				);
			}

			if ( $is_async ) {
				return array(
					'success' => true,
					'async'   => true,
					'message' => sprintf(
						/* translators: %s: Plugin label */
						__( '%s database migration triggered. It will complete in the background — poll database-status to check progress.', 'digitizer-site-worker' ),
						$entry['label']
					),
				);
			}

			return array(
				'success' => true,
				'message' => sprintf(
					/* translators: %s: Plugin label */
					__( '%s database migration completed.', 'digitizer-site-worker' ),
					$entry['label']
				),
			);
		}

		// Core WordPress database upgrade (default).
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$db_version_before = get_option( 'db_version' );

		// wp_upgrade() has no return value, so wrap it and verify the result by
		// comparing the stored db_version against the target $wp_db_version.
		try {
			wp_upgrade();
		} catch ( \Throwable $e ) {
			return array(
				'success'   => false,
				'error'     => sprintf(
					/* translators: %s: Error message */
					__( 'Database upgrade failed: %s', 'digitizer-site-worker' ),
					$e->getMessage()
				),
				'db_before' => $db_version_before,
			);
		}

		$db_version_after = get_option( 'db_version' );

		// $wp_db_version is the target schema version WordPress expects.
		$target = isset( $GLOBALS['wp_db_version'] ) ? (int) $GLOBALS['wp_db_version'] : null;
		if ( null !== $target && (int) $db_version_after !== $target ) {
			return array(
				'success'   => false,
				'error'     => __( 'Database upgrade did not reach the expected version.', 'digitizer-site-worker' ),
				'db_before' => $db_version_before,
				'db_after'  => $db_version_after,
				'db_target' => $target,
			);
		}

		return array(
			'success'   => true,
			'message'   => __( 'Database tables updated.', 'digitizer-site-worker' ),
			'db_before' => $db_version_before,
			'db_after'  => $db_version_after,
			'changed'   => $db_version_before !== $db_version_after,
		);
	}
}
