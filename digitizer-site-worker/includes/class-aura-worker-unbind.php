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
	 * Wire the init-hooked Phase B sweep: a site whose unbind was interrupted
	 * (the network died between Aura's tombstone and the site's answer, the
	 * request was killed mid-cleanup) finishes steps (1)-(4) on its own next
	 * page load, throttled, without Aura having to reach it again.
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
	 * The user a marker's Application Password belongs to, or 0 when the
	 * marker cannot say. ONE resolution for the revoke loop and for the
	 * accounting that gates the token delete — they must never disagree about
	 * whether a credential is identifiable (round-1 C1).
	 *
	 * `??` does NOT fall through an integer 0, and 0 is exactly what the
	 * marker records for a managed row whose `user_id` half never landed
	 * (`class-aura-worker-rules.php`, the `(int) ( $managed['user_id'] ?? 0 )`
	 * copy; the half-written record `Aura_Worker_Magic_Link::
	 * tracking_is_incomplete()` exists to detect). So each source is tested
	 * for a POSITIVE id in turn, and "the marker names owner 0" falls through
	 * to `connect_user_id` like a missing key does.
	 *
	 * @param array  $m    The marker.
	 * @param string $uuid The password's uuid.
	 * @return int The owner's user id, or 0 when it cannot be resolved.
	 */
	private static function password_owner( array $m, string $uuid ): int {
		$user = isset( $m['app_password_users'][ $uuid ] ) ? (int) $m['app_password_users'][ $uuid ] : 0;
		if ( $user <= 0 ) {
			$user = isset( $m['connect_user_id'] ) ? (int) $m['connect_user_id'] : 0;
		}
		return $user > 0 ? $user : 0;
	}

	/**
	 * Is that option's row gone from the database? ONE "is it removed" read for
	 * every step of Phase B, and it FAILS CLOSED: read_option_uncached()
	 * answers null for an absent row and a WP_Error for a read that could not
	 * be completed, and only the first of those is evidence of anything. A
	 * database blip read as "absent" would let cleanup() report a step
	 * complete — and, at step (5), report a token deleted that is still there.
	 *
	 * @param string $name Option name.
	 * @return bool True only when the row is proven absent.
	 */
	private static function option_absent( string $name ): bool {
		$raw = Aura_Worker_Rules::read_option_uncached( $name );
		return ! is_wp_error( $raw ) && null === $raw;
	}

	/**
	 * Which of steps (1)-(4) are still owed — the same evidence cleanup() uses
	 * to decide whether it may reach step (5), exposed so a caller (and Task
	 * 9's teardown) can see what a site still holds.
	 *
	 * Fails CLOSED on an unreadable marker: with the marker unreadable nothing
	 * can be proven gone, so every step is named rather than none. The empty
	 * array means "proven nothing is owed", and must never mean "could not
	 * look".
	 *
	 * @return string[] Any of 'app_passwords', 'options', 'ruleset', 'grant_pubkey'.
	 */
	public static function leftovers(): array {
		$m = self::read();
		if ( is_wp_error( $m ) ) {
			return array( 'app_passwords', 'options', 'ruleset', 'grant_pubkey' );
		}
		if ( null === $m ) {
			return array(); // no marker: this site is not mid-unbind and owes nothing
		}
		$left = array();
		foreach ( $m['app_password_uuids'] as $uuid ) {
			$uuid = (string) $uuid;
			$user = self::password_owner( $m, $uuid );
			// An UNRESOLVABLE owner is not evidence of absence (round-1 C1).
			// "I cannot identify whose password this is" is the same kind of
			// answer as the unreadable marker above — unknown, not clean — and
			// reporting it as clean would let step (5) delete the token while a
			// live administrator credential remains, with no token left for any
			// retry to be matched against.
			if ( 0 === $user || ! Aura_Worker_Magic_Link::password_gone( $user, $uuid ) ) {
				$left[] = 'app_passwords';
				break;
			}
		}
		if ( ! self::option_absent( 'aura_worker_dashboard_url' ) || ! self::option_absent( 'aura_worker_connect_user_id' ) ) {
			$left[] = 'options';
		}
		// The ROW, not Aura_Worker_Rules::current() and not stored_uncached():
		// current() maps the connect's seq-0 sentinel to null and BOTH map a
		// present-but-malformed row to null, so a store that still holds
		// something would read as already cleared (round-1 M3). option_absent()
		// asks the only question step (3) is actually about — is the row gone —
		// and fails closed on a read error.
		if ( ! self::option_absent( Aura_Worker_Rules::OPTION ) ) {
			$left[] = 'ruleset';
		}
		if ( ! self::option_absent( 'aura_worker_grant_pubkey' ) ) {
			$left[] = 'grant_pubkey';
		}
		return $left;
	}

	/**
	 * Phase B — the cleanup, in ONE fixed order, idempotent, every step proven
	 * rather than assumed (spec §2.3):
	 *
	 *   (1) revoke every Application Password the marker names — the managed
	 *       one and any that authenticated an unbind — each by (user, uuid),
	 *       proven gone by re-reading the owner's list;
	 *   (2) delete `aura_worker_dashboard_url` and `aura_worker_connect_user_id`;
	 *   (3) clear the ruleset store, sentinel included;
	 *   (4) delete the gateway public key;
	 *   (5) delete the site token — LAST, and only under all three of: this is
	 *       an Aura request that said `final: true` ($final; maybe_finish()
	 *       never passes it), steps (1)-(4) are proven complete on THIS
	 *       request (leftovers() empty), and the marker was readable.
	 *
	 * The order is the point. The token is what a retry of this same tombstone
	 * authenticates with and what the marker fast path matches on, so it must
	 * outlive every step that can still fail: a site that loses its token with
	 * work outstanding can never be told about the remainder again. Everything
	 * before it is removable twice with the same result, so an interrupted
	 * cleanup is simply re-run — by the retry, or by maybe_finish().
	 *
	 * The return value is the `cleanup_complete` Aura is told, and it is
	 * evidence, never optimism: true means "nothing this call was allowed to
	 * remove is left", which for a `final` call includes an uncached read
	 * proving the token row is gone.
	 *
	 * @internal The `aura_worker_unbind_step` action fired below is an INTERNAL
	 *           test seam that reports which step was ENTERED (not that it
	 *           succeeded). It is not a supported extension point: this is an
	 *           irreversible path running under the site claim, and a listener
	 *           that throws aborts the teardown. Nothing outside this plugin's
	 *           own test suite may hook it, and its name, arguments and firing
	 *           points may change without notice.
	 *
	 * @param bool   $final Whether this request may delete the site token.
	 * @param string $fence The caller's site-claim fence.
	 * @return bool True once every step this call was allowed to take is
	 *              verified complete.
	 */
	public static function cleanup( bool $final, string $fence ): bool {
		$m = self::read();
		// is_array(), never `! $m`: a WP_Error is truthy. An unreadable marker
		// is neither "no marker" nor a marker to act on — Phase B does nothing
		// at all rather than delete credentials for a binding it cannot name.
		if ( ! is_array( $m ) ) {
			return false;
		}
		if ( '' === $fence || ! Aura_Worker_Magic_Link::holds_site_claim( $fence ) ) {
			return false;
		}
		$claim = Aura_Worker_Magic_Link::SITE_CLAIM;

		// (1) The credentials first: everything after this is bookkeeping, and
		// an administrator-level REST credential is the one thing that must
		// not outlive the binding by even a failed step.
		do_action( 'aura_worker_unbind_step', 'revoke' );
		if ( class_exists( 'WP_Application_Passwords' ) ) {
			foreach ( $m['app_password_uuids'] as $uuid ) {
				$uuid = (string) $uuid;
				$user = self::password_owner( $m, $uuid );
				if ( 0 === $user ) {
					// Nothing can be deleted without an owner — but this is not
					// a silent skip: leftovers() names 'app_passwords' for the
					// same entry, so the gate below refuses step (5) and the
					// site keeps its token, its marker and therefore its ability
					// to be retried and repaired (round-1 C1).
					continue;
				}
				if ( ! Aura_Worker_Magic_Link::password_gone( $user, $uuid ) ) {
					// The return value is not the proof (it is false for a
					// failed user-meta write as well as for "not there");
					// leftovers() re-reads the owner's list below.
					WP_Application_Passwords::delete_application_password( $user, $uuid );
				}
			}
		}

		// (2) The options that name the departed dashboard and its connector.
		do_action( 'aura_worker_unbind_step', 'options' );
		Aura_Worker_Rules::delete_option_if_claimed( 'aura_worker_dashboard_url', $claim, $fence );
		Aura_Worker_Rules::delete_option_if_claimed( 'aura_worker_connect_user_id', $claim, $fence );

		// (3) The ruleset store — the departed client's block and warn policy.
		// Conditioned on the ROW, the same question leftovers() asks: a row
		// that no longer parses as a record is still a row, and step (3) is
		// what removes it (round-1 M3).
		do_action( 'aura_worker_unbind_step', 'ruleset' );
		if ( ! self::option_absent( Aura_Worker_Rules::OPTION ) ) {
			Aura_Worker_Magic_Link::clear_ruleset_verified( $claim, $fence );
		}

		// (4) The gateway key. After this the site can verify nothing Aura
		// signs — which is why the fast path (rules.php) answers a retry on
		// the TOKEN alone, before any signature work.
		do_action( 'aura_worker_unbind_step', 'grant' );
		Aura_Worker_Rules::delete_option_if_claimed( 'aura_worker_grant_pubkey', $claim, $fence );

		if ( array() !== self::leftovers() ) {
			return false; // something is still owed; the token stays, and so does the retry path
		}
		if ( ! $final ) {
			return false; // a sibling tombstone may still need this token to identify itself
		}

		// (5) The token. Irreversible: nothing afterwards can authenticate as
		// this binding, and no retry can be matched to this marker.
		do_action( 'aura_worker_unbind_step', 'token' );
		Aura_Worker_Rules::delete_option_if_claimed( 'aura_worker_site_token', $claim, $fence );
		return self::option_absent( 'aura_worker_site_token' );
	}

	/**
	 * The init-hooked sweep that finishes an interrupted Phase B on the site's
	 * own next page load. Steps (1)-(4) only — NEVER the token: deleting it is
	 * Aura's decision, carried on a `final: true` request, because only Aura
	 * knows whether a sibling tombstone still needs this token to identify
	 * itself. The site alone may never make that call.
	 *
	 * Throttled by FINISH_TRANSIENT (FINISH_THROTTLE seconds) so a busy site
	 * does not attempt it on every request; the transient is set BEFORE the
	 * work, so a request that dies mid-cleanup does not have the next one
	 * retry immediately behind it.
	 *
	 * Under the site claim, like every other lifecycle operation — and a site
	 * a connect currently holds is simply left alone until next time.
	 *
	 * @return void
	 */
	public static function maybe_finish(): void {
		if ( ! self::is_set() ) {
			return;
		}
		if ( false !== get_transient( self::FINISH_TRANSIENT ) ) {
			return;
		}
		set_transient( self::FINISH_TRANSIENT, 1, self::FINISH_THROTTLE );
		$fence = Aura_Worker_Magic_Link::claim_site();
		if ( '' === $fence ) {
			return; // a connect (or another sweep) holds the site; next time
		}
		try {
			// is_set() above fails OPEN on a database error — it is the
			// display-side convenience. Re-read here and stop on anything that
			// is not a marker: an unreadable one must not send this sweep into
			// a cleanup for a binding it cannot identify.
			$m = self::read();
			if ( ! is_array( $m ) ) {
				return;
			}
			$token = Aura_Worker_Rules::site_token_uncached();
			if ( is_wp_error( $token ) ) {
				return; // a token that could not be read is not a token that matches
			}
			$token = (string) $token;
			// An ABSENT token is the expected state after step (5) — the
			// cleanup simply has (1)-(4) left to prove. A token that is
			// PRESENT and hashes to something else is a reconnect that has
			// already rebound this site: the marker is not this binding, and
			// nothing of the new one may be touched.
			if ( '' !== $token && ! hash_equals( (string) $m['site'], $token ) ) {
				return;
			}
			self::cleanup( false, $fence );
		} finally {
			Aura_Worker_Magic_Link::release_site( $fence );
		}
	}
}
