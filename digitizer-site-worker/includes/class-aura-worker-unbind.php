<?php
/**
 * The unbind marker (#434, spec §2.3). ONE option, written under the site
 * claim by Phase A of an unbind; every mutation boundary reads it uncached
 * and refuses while it is set. Phase B (cleanup) lives here too (Task 4).
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Aura_Worker_Unbind {

	/** The marker option. Autoload 'no' — read only by the request that needs it, never on every page load. */
	const OPTION = 'aura_worker_unbound';

	/** Throttle gate for Phase B cleanup (Task 4). */
	const FINISH_TRANSIENT = 'aura_worker_unbind_finish';

	/** Minimum seconds between Phase-B attempts (Task 4). */
	const FINISH_THROTTLE = 300;

	/**
	 * Wire the init-hooked Phase B sweep. The body is filled in Task 4 —
	 * this task only introduces the hook registration.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_finish' ) );
	}

	/**
	 * The marker, straight from the database — never the option cache. Whether
	 * a site is unbound is a security decision, and a stale cached copy could
	 * let a mutation through a moment after Phase A wrote the refusal.
	 *
	 * Mirrors `Aura_Worker_Rules::stored_uncached()` (round-16, Codex): a
	 * `WP_Error` from the raw read is a genuine database failure, not "no
	 * marker" — `$wpdb->get_var()` answers null for BOTH, and collapsing the
	 * two would let a transient DB blip read as "site is bound" instead of
	 * the retryable failure it is. Callers that need a plain boolean use
	 * is_set() (fails OPEN, i.e. treats the error as "unbound" — documented
	 * there) or is_set_strict() (surfaces the error so a caller can fail
	 * CLOSED).
	 *
	 * @return array|null|WP_Error The marker; null when absent or the stored
	 *                             value is not a valid marker; WP_Error when
	 *                             the uncached read itself failed.
	 */
	public static function read() {
		$raw = Aura_Worker_Rules::read_option_uncached( self::OPTION );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		$m = is_string( $raw ) ? maybe_unserialize( $raw ) : $raw;
		if ( ! is_array( $m ) || ! isset( $m['at'], $m['site'], $m['site_ref'], $m['client'], $m['seq'] ) ) {
			return null;
		}
		$m['app_password_uuids'] = isset( $m['app_password_uuids'] ) && is_array( $m['app_password_uuids'] )
			? array_values( array_map( 'strval', $m['app_password_uuids'] ) )
			: array();
		$m['app_password_users'] = isset( $m['app_password_users'] ) && is_array( $m['app_password_users'] )
			? $m['app_password_users']
			: array();
		return $m;
	}

	/**
	 * Is this site currently unbound? FAILS OPEN on a database read error —
	 * "unknown" is treated as "unbound" — because this is the plain-boolean
	 * convenience for callers that only want a display/witness answer (e.g.
	 * admin UI), not a security gate. A mutation boundary MUST NOT use this
	 * method; use is_set_strict() and fail CLOSED on its WP_Error instead.
	 *
	 * @return bool
	 */
	public static function is_set(): bool {
		$m = self::read();
		return is_wp_error( $m ) || null !== $m;
	}

	/**
	 * The strict form of is_set() for enforcement boundaries (Tasks 5/6):
	 * surfaces a database read failure as a WP_Error instead of collapsing it
	 * to a boolean, so the caller can refuse the mutation (self::refusal())
	 * rather than silently letting it through while the marker is unreadable.
	 *
	 * @return bool|WP_Error True/false once the read genuinely succeeded, or
	 *                       the WP_Error from a failed uncached read.
	 */
	public static function is_set_strict() {
		$m = self::read();
		if ( is_wp_error( $m ) ) {
			return $m;
		}
		return null !== $m;
	}

	/**
	 * The two fields `/status` reports — never the whole marker, which carries
	 * the app-password UUIDs and the connecting user. `/status` is a witness,
	 * not a gate: a WP_Error from read() (a DB failure, not "no marker") is
	 * reported the same as "no marker" here — status must not claim certainty
	 * it does not have.
	 *
	 * @return array{at:string,site_ref:string}|null
	 */
	public static function status_fragment(): ?array {
		$m = self::read();
		if ( ! is_array( $m ) ) {
			return null;
		}
		return array(
			'at'       => (string) $m['at'],
			'site_ref' => (string) $m['site_ref'],
		);
	}

	/**
	 * Write the marker only while $fence holds the site claim; verified by an
	 * uncached read-back — the same discipline every other claim-conditional
	 * install write in this plugin follows. write_option_if_claimed() already
	 * has its own conditional INSERT for a row that doesn't exist yet (it
	 * receives 'no' as $autoload below), so no separate add_option() pre-step
	 * is needed here.
	 *
	 * @param array  $marker The marker to store.
	 * @param string $fence  The caller's site-claim fence.
	 * @return bool True once the write is confirmed by an uncached read-back.
	 */
	public static function write_under_claim( array $marker, string $fence ): bool {
		if ( '' === $fence || ! Aura_Worker_Magic_Link::holds_site_claim( $fence ) ) {
			return false;
		}
		$ok = Aura_Worker_Rules::write_option_if_claimed( self::OPTION, $marker, Aura_Worker_Magic_Link::SITE_CLAIM, $fence, 'no' );
		if ( ! $ok ) {
			return false;
		}
		// Verify against a fresh raw read, not self::read(): a WP_Error here
		// (a DB blip exactly at verification time) must be treated as
		// "unverified" -> false, not fall through read()'s WP_Error handling
		// and be misreported as "marker absent" or "marker present".
		$raw = Aura_Worker_Rules::read_option_uncached( self::OPTION );
		if ( ! is_string( $raw ) ) {
			return false;
		}
		$back = maybe_unserialize( $raw );
		// Compare identity (site) + freshness (seq) only, deliberately not the
		// whole marker: proof that THIS write landed is "the row now names my
		// site at my seq", not a byte-for-byte match of every field.
		return is_array( $back ) && isset( $back['site'], $back['seq'] )
			&& $back['site'] === $marker['site']
			&& (int) $back['seq'] === (int) $marker['seq'];
	}

	/**
	 * Delete the marker only while $fence holds the site claim; verified by an
	 * uncached read.
	 *
	 * @param string $fence The caller's site-claim fence.
	 * @return bool True once the marker is confirmed absent.
	 */
	public static function delete_under_claim( string $fence ): bool {
		if ( '' === $fence || ! Aura_Worker_Magic_Link::holds_site_claim( $fence ) ) {
			return false;
		}
		Aura_Worker_Rules::delete_option_if_claimed( self::OPTION, Aura_Worker_Magic_Link::SITE_CLAIM, $fence );
		return null === Aura_Worker_Rules::read_option_uncached( self::OPTION );
	}

	/**
	 * The refusal every mutation boundary answers with while the marker is set
	 * (wired to those boundaries in a later task).
	 *
	 * @return WP_Error
	 */
	public static function refusal(): WP_Error {
		return new WP_Error(
			'aura_site_unbound',
			__( 'This site was disconnected by Aura; reconnect it from the site\'s SiteAgent settings.', 'digitizer-site-worker' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Phase B cleanup — revoke the marker's Application Passwords, delete the
	 * dashboard url and connect user, clear the ruleset store, delete the
	 * gateway key, and (only when $final and every earlier step verified)
	 * delete the site token.
	 *
	 * STUB. Task 4 implements it; it returns false here so Phase A's response
	 * reports `cleanup_complete: false` honestly rather than claiming work
	 * that has not happened.
	 *
	 * @param bool   $final Whether this request may delete the site token.
	 * @param string $fence The caller's site-claim fence.
	 * @return bool True once every step is verified complete.
	 */
	public static function cleanup( bool $final, string $fence ): bool {
		unset( $final, $fence );
		return false; // Task 4
	}

	/**
	 * Phase B cleanup (Task 4). Empty for now — init() only registers the hook
	 * this task; nothing runs from it yet.
	 */
	public static function maybe_finish(): void {}
}
