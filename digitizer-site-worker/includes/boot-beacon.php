<?php
/**
 * The boot beacon — the ONE place `aura_worker_boot` is written.
 *
 * `Aura_Worker_Updater::self_update()` needs a fact about the build it just
 * installed: did it come up, or did it die? Every way of inferring either from
 * outside — status codes, error-log tails, control paths — had a case where the
 * signal lied (SiteAgent #78, ten review rounds; the error-log scanner alone
 * produced findings in eight of them). So the build reports on itself, in both
 * directions, correlated by a nonce the updater arms:
 *
 *   boot beacon   — `aura_worker_write_boot_beacon()`, hooked LAST on
 *                   `rest_api_init` from `Aura_Worker::init()`: the build loaded,
 *                   initialised and registered every route on the probe request.
 *   fatal beacon  — `aura_worker_shutdown_beacon()`, registered as the FIRST
 *                   statement of the entry file: the dying process, on its way
 *                   out, records that a fatal in one of THIS plugin's files ended
 *                   the request. No log is parsed, so nothing about rotation,
 *                   paths, separators or chunking can be wrong.
 *
 * This file is a pure-function file with no side effects on load, required
 * before every other include, so that a parse error in ANY later include is
 * still caught: by then the shutdown handler is already armed. What it cannot
 * catch is a parse error in the entry file itself — nothing in it runs — and
 * that case yields no beacon at all, which the verdict reports as inconclusive.
 *
 * Two RECORDS, not one (Codex round-11): the boot writer owns
 * `aura_worker_boot`, the fatal writer owns `aura_worker_boot_fatal`, and each
 * write is unconditional. Precedence between them is decided at READ time, in
 * the verdict — a fatal outranks a boot, and either counts only when it names
 * the version under verdict — so there is no read-then-write between two
 * requests for one option to race on, and a straggling request still running
 * the OLD build cannot launder or override the new build's record: its version
 * says which build it was.
 *
 * The nonce is spent by the UPDATER after its verdict, never by a writer here:
 * a boot and a later fatal on the same request must both be able to see it.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The nonce the updater armed, or '' when no verdict is pending.
 *
 * @return string
 */
function aura_worker_boot_nonce() {
	$nonce = get_option( 'aura_worker_boot_nonce', '' );
	return is_string( $nonce ) ? $nonce : '';
}

/**
 * Record that the build booted. Writes only while a verdict is pending.
 * Unconditional otherwise — precedence against a fatal is the verdict's job.
 *
 * @param string $version The version of the build that is running.
 * @return bool Whether a beacon was written.
 */
function aura_worker_write_boot_beacon( $version ) {
	$nonce = aura_worker_boot_nonce();
	if ( '' === $nonce ) {
		return false;
	}
	return (bool) update_option(
		'aura_worker_boot',
		array( 'version' => (string) $version, 'nonce' => $nonce ),
		false
	);
}

/**
 * Whether a PHP error record is a fatal that ended the request in one of this
 * plugin's files. Pure; the shutdown handler feeds it `error_get_last()`.
 *
 * Attribution is by the FILE PHP names, normalised for separators, against
 * this plugin's directory — a fact from the engine, not a line in a log.
 *
 * @param array|null $error      From `error_get_last()`.
 * @param string     $plugin_dir This plugin's directory (AURA_WORKER_DIR).
 * @return bool
 */
function aura_worker_is_own_fatal( $error, $plugin_dir ) {
	if ( ! is_array( $error ) || ! isset( $error['type'], $error['file'] ) ) {
		return false;
	}
	$fatal_types = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
	if ( 0 === ( (int) $error['type'] & $fatal_types ) ) {
		return false;
	}
	$file = rtrim( str_replace( '\\', '/', (string) $error['file'] ), '/' );
	$dir  = rtrim( str_replace( '\\', '/', (string) $plugin_dir ), '/' ) . '/';
	return 0 === stripos( $file, $dir );
}

/**
 * Record that a fatal in this plugin ended the request. Writes only while a
 * verdict is pending, to its OWN record, so no boot write can replace it.
 *
 * @param array|null $error      From `error_get_last()`.
 * @param string     $version    The version of the build that was running.
 * @param string     $plugin_dir This plugin's directory.
 * @return bool Whether a fatal beacon was written.
 */
function aura_worker_record_fatal_beacon( $error, $version, $plugin_dir ) {
	$nonce = aura_worker_boot_nonce();
	if ( '' === $nonce || ! aura_worker_is_own_fatal( $error, $plugin_dir ) ) {
		return false;
	}
	return (bool) update_option(
		'aura_worker_boot_fatal',
		array(
			'version' => (string) $version,
			'nonce'   => $nonce,
			'file'    => basename( (string) $error['file'] ),
			'message' => substr( (string) ( $error['message'] ?? '' ), 0, 200 ),
		),
		false
	);
}

/**
 * Shutdown handler. Registered first thing in the entry file; runs on every
 * request's exit and does nothing unless a fatal in this plugin ended it while
 * a verdict was pending.
 */
function aura_worker_shutdown_beacon() {
	if ( ! function_exists( 'error_get_last' ) || ! function_exists( 'get_option' ) ) {
		return;
	}
	aura_worker_record_fatal_beacon(
		error_get_last(),
		defined( 'AURA_WORKER_VERSION' ) ? AURA_WORKER_VERSION : '',
		defined( 'AURA_WORKER_DIR' ) ? AURA_WORKER_DIR : dirname( __DIR__ ) . '/'
	);
}
