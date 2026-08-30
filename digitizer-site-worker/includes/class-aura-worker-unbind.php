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

	/** The error code read() answers with for a marker row that exists but cannot be trusted. */
	const MALFORMED_CODE = 'aura_unbind_marker_malformed';

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
	 * is_set(), which answers TRUE for an unreadable marker, or
	 * is_set_strict(), which surfaces the error so a caller can tell the two
	 * apart.
	 *
	 * Round-2 I3: an array in this row is an ATTEMPTED marker, and one that
	 * does not satisfy the shape is MALFORMED, not absent. `isset()` is false
	 * for a key that is present and null, so the old check read a marker with
	 * a null `site_ref` as "no marker at all" — the one fail-OPEN in this
	 * file: the mutation boundaries would stop refusing, `/status` would stop
	 * reporting `unbound` and the fast path would stop answering the retry, so
	 * the site would silently read as bound again while Aura believed it
	 * disconnected. Presence is therefore `array_key_exists()` plus a type
	 * check, and a malformed marker is answered as the same kind of unknown a
	 * failed read is — "unbound, unreadable" — so every caller fails CLOSED.
	 * Task 8's bare body, which takes `site_ref`/`client` from a request, is
	 * exactly the writer that can produce one.
	 *
	 * @return array|null|WP_Error The marker; null when the row holds no
	 *                             marker at all; WP_Error when the uncached
	 *                             read failed OR the stored marker is
	 *                             malformed.
	 */
	public static function read() {
		$raw = Aura_Worker_Rules::read_option_uncached( self::OPTION );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		$m = is_string( $raw ) ? maybe_unserialize( $raw ) : $raw;
		if ( ! is_array( $m ) ) {
			if ( null === $raw ) {
				// No ROW. Genuinely absent: nobody ever wrote a marker here.
				return null;
			}
			// A row that exists and does not decode to a marker — a value
			// truncated in the column, a hand-edited row, anything. This
			// option name is the marker's alone, so SOMETHING wrote here, and
			// answering null would say the site is bound: the mutation
			// boundaries would reopen and accept_under_claim() would take an
			// ordinary ruleset for a binding Aura has already disconnected.
			// Undecodable is the same unknown a failed read is (Codex
			// round-4 P1).
			return self::malformed();
		}
		return self::validated( $m );
	}

	/**
	 * The marker row as a raw array — whatever it holds — or null when the row
	 * is absent, unreadable or not an array. Used by read() and by
	 * status_fragment(), which must still report what it CAN read out of a
	 * marker read() has rejected (round-3 I4).
	 *
	 * @return array|null
	 */
	private static function marker_array(): ?array {
		$raw = Aura_Worker_Rules::read_option_uncached( self::OPTION );
		if ( is_wp_error( $raw ) ) {
			return null;
		}
		$m = is_string( $raw ) ? maybe_unserialize( $raw ) : $raw;
		return is_array( $m ) ? $m : null;
	}

	/**
	 * Validate and normalise a marker array.
	 *
	 * @param array $m The raw marker.
	 * @return array|WP_Error
	 */
	private static function validated( array $m ) {
		if ( ! self::field_is( $m, 'at', 'string' )
			|| ! self::field_is( $m, 'site', 'string' )
			|| ! self::field_is( $m, 'site_ref', 'string' )
			|| ! self::field_is( $m, 'client', 'string' )
			|| ! self::field_is( $m, 'seq', 'int' ) ) {
			return self::malformed();
		}
		// THE credential list, asked of the one rule that reads it
		// (includes/unbind-credential-list.php). A list this build cannot read
		// proves nothing, and a marker whose credentials cannot be read is
		// malformed — not one that held none.
		$uuids = aura_worker_credential_list( $m['app_password_uuids'] ?? null );
		if ( null === $uuids ) {
			return self::malformed();
		}
		$m['app_password_uuids'] = $uuids;
		// Owners are normalised to a POSITIVE int or an explicit null meaning
		// "this site does not know whose password this is" (round-3). Anything
		// else — 0, '', a string, an object, a marker written by an earlier
		// build — is that same unknown, never a user id: Phase B's single
		// lookup is authoritative ONLY against an owner the site actually
		// recorded, so a value that names nobody must not be passed off as one.
		$users = isset( $m['app_password_users'] ) && is_array( $m['app_password_users'] ) ? $m['app_password_users'] : array();
		$m['app_password_users'] = array();
		foreach ( $users as $uuid => $owner ) {
			$owner                                       = is_int( $owner ) || ( is_string( $owner ) && ctype_digit( $owner ) ) ? (int) $owner : 0;
			$m['app_password_users'][ (string) $uuid ] = $owner > 0 ? $owner : null;
		}
		return $m;
	}

	/**
	 * Is that marker field present AND of the type the marker writes? The TYPE
	 * check is what closes round-2 I3: a field that is present and null is a
	 * CORRUPTED marker, not a missing one, and both answer false here so the
	 * caller can tell them apart from "no marker at all". `array_key_exists()`
	 * rather than `isset()` says the presence question literally — either
	 * avoids the undefined-key warning, and with the type check in place the
	 * two cannot differ in outcome (re-review round 2).
	 *
	 * @param array  $m    The candidate marker.
	 * @param string $key  Field name.
	 * @param string $type 'string' or 'int'.
	 * @return bool
	 */
	private static function field_is( array $m, string $key, string $type ): bool {
		if ( ! array_key_exists( $key, $m ) ) {
			return false;
		}
		return 'int' === $type ? is_int( $m[ $key ] ) : is_string( $m[ $key ] );
	}

	/**
	 * The answer for a marker that exists but cannot be trusted. Deliberately
	 * a WP_Error, not null: every caller already fails CLOSED on a WP_Error
	 * from read() — is_set() reports unbound, is_set_strict() surfaces it,
	 * status_fragment() answers null, cleanup()/maybe_finish() do nothing and
	 * leftovers() names every step — which is exactly the handling a corrupted
	 * marker needs.
	 *
	 * The message names the way out, and since fix round 1 that promise can
	 * actually be kept: "Remove remaining Aura data" REPAIRS a damaged row
	 * (repair_malformed_marker()) and then tears the site down through the
	 * ordinary path. It used to point at a control that would refuse.
	 *
	 * @return WP_Error
	 */
	private static function malformed(): WP_Error {
		return new WP_Error(
			'aura_unbind_marker_malformed',
			__( 'This site is disconnected and its disconnect record is damaged; use “Remove remaining Aura data” on the site\'s SiteAgent settings screen to rebuild the record and clear the connection.', 'digitizer-site-worker' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Is this site currently unbound? Answers TRUE when the marker cannot be
	 * READ: an unreadable marker is not a clean site.
	 *
	 * Which way that "fails" depends entirely on what the caller does next,
	 * and both readings are in this codebase, so neither word is used here.
	 * At a refusal boundary it is fail-CLOSED and is the right choice — a
	 * transient DB error refuses the mutation instead of re-opening every
	 * write on a disconnected site — which is why
	 * Aura_Worker_Security::refuse_if_unbound() (#434 Task 5) uses exactly
	 * this method. For a WITNESS — a screen, or /status — the same answer
	 * would claim a certainty the read did not have, which is why
	 * status_fragment() reads the tri-state read() directly instead.
	 *
	 * Use is_set_strict() only when the caller genuinely acts DIFFERENTLY on
	 * an unreadable marker than on a present one. If both branches end in the
	 * same behaviour, this method is the honest one.
	 *
	 * @return bool
	 */
	public static function is_set(): bool {
		$m = self::read();
		return is_wp_error( $m ) || null !== $m;
	}

	/**
	 * The strict form of is_set(): surfaces a database read failure as a
	 * WP_Error instead of collapsing it into the boolean, for the callers that
	 * must tell an unreadable marker from a present one.
	 *
	 * Its one production caller is Aura_Worker_Rules::accept_under_claim(),
	 * at step 0 of a ruleset push: an unreadable marker there returns the
	 * retryable store failure and writes nothing, a present one takes the
	 * unbind fast path, and an absent one carries on as an ordinary push —
	 * three different answers, which is what earns the third state.
	 *
	 * The write boundary does NOT use it (#434 Task 5): there an unreadable
	 * and a present marker both end in the same refusal, so the extra branch
	 * could not change an outcome — see is_set() above.
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
	 * The fields `/status` reports — never the whole marker, which carries the
	 * app-password UUIDs and the connecting user. `/status` is a witness, not
	 * a gate: a DB read failure (not "no marker") is reported the same as "no
	 * marker" here — status must not claim certainty it does not have.
	 *
	 * A MALFORMED marker is the one unknown that must still be reported
	 * (round-3 I4). Aura's PATCH and manual-connect preflight keys on the
	 * PRESENCE of `unbound`, so a site that refuses every local boundary while
	 * `/status` says nothing lets Aura write a binding to a site that will
	 * refuse everything. The row exists, therefore the site is unbound, and
	 * the witness says so — reporting only the fields it can actually read and
	 * omitting the rest. That keeps "never claim certainty about the VALUES"
	 * while no longer denying the STATE. An empty object is a correct answer.
	 *
	 * @return array{at?:string,site_ref?:string}|null Null only when there is
	 *                                                 no marker, or the read
	 *                                                 itself failed.
	 */
	public static function status_fragment(): ?array {
		$m = self::read();
		if ( is_array( $m ) ) {
			return array(
				'at'       => (string) $m['at'],
				'site_ref' => (string) $m['site_ref'],
			);
		}
		if ( ! is_wp_error( $m ) || self::MALFORMED_CODE !== $m->get_error_code() ) {
			return null; // absent, or a read this request could not complete
		}
		$raw  = self::marker_array();
		$part = array();
		if ( null !== $raw ) {
			foreach ( array( 'at', 'site_ref' ) as $field ) {
				if ( self::field_is( $raw, $field, 'string' ) ) {
					$part[ $field ] = (string) $raw[ $field ];
				}
			}
		}
		return $part;
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
	 * The way back, first half: settle the departed binding's Phase-B debt
	 * BEFORE a rebind replaces the site token (#434 Task 7).
	 *
	 * A rebind is the ONLY thing that clears the marker, and the two calls that
	 * do it bracket the token install — this one before it,
	 * release_marker_after_rebind() after the whole replacement binding is
	 * established. The ORDER is the safety property, in both directions:
	 *
	 *  - The marker must OUTLIVE the old token. Every write of a rebind can
	 *    fail, and a rebind that fails half-way has installed nothing this site
	 *    can be governed by; while the marker stands, the old token AND the
	 *    half-installed new one are refused at every boundary. Only a rebind
	 *    that got all the way through may take the refusal away.
	 *  - The debt must be settled BEFORE the token is replaced. Writing the new
	 *    token permanently disarms maybe_finish() — the sweep bails on the hash
	 *    mismatch a replacement token creates — so an Application Password UUID
	 *    that Phase A recorded in the marker and Phase B never revoked would be
	 *    stranded: a live `manage_options` credential for a departed dashboard
	 *    with nothing left on this site that would ever go looking for it. The
	 *    409 below is what stops that: a site that still owes something is not
	 *    reconnected at all.
	 *
	 * Steps (1)-(4) only, never the token: `$final` stays false, because the
	 * token is not this method's to delete — the rebind is about to REPLACE it,
	 * and the flow's own write-and-verify (or the rotation's compare-and-swap)
	 * needs to find it there.
	 *
	 * An unreadable or malformed marker refuses too, and fail-CLOSED is right —
	 * the alternative reconnects a site whose previous dashboard may still hold
	 * a live credential — but it refuses with its OWN code and its own story
	 * (round-1 MINOR-1). `aura_unbind_incomplete` says "the previous binding
	 * could not be fully removed", and at a site that was never unbound at all
	 * that is a claim about an event that never happened: any blip in the marker
	 * read would send the operator of a fresh site hunting a disconnect. What is
	 * true in every one of these cases — a database read that would not
	 * complete, a marker row this build cannot parse, a site that may or may not
	 * be unbound because nobody could look — is exactly one thing: the record
	 * could not be READ. So that is what `aura_unbind_unreadable` says, and it
	 * carries no `leftover` list, because "everything is owed" there means
	 * "nothing could be checked" and naming four steps would repeat the same
	 * wrong story in detail.
	 *
	 * @since 2.13.0
	 *
	 * @param string $fence The caller's site-claim fence — both flows already
	 *                      hold the claim, and this consumes it rather than
	 *                      taking a second one.
	 * @return true|WP_Error True when nothing is owed (including "this site was
	 *                       never unbound"); a 409 `aura_unbind_incomplete`
	 *                       naming what is left; or a 409
	 *                       `aura_unbind_unreadable` when the marker row could
	 *                       not be read at all.
	 */
	public static function finish_before_rebind( string $fence ) {
		// is_set_strict(), not is_set(): this caller genuinely acts DIFFERENTLY
		// on an unreadable marker than on a present one — same refusal, but a
		// different account of why — which is the only thing that earns the
		// third state (see is_set()'s docblock).
		$marked = self::is_set_strict();
		if ( is_wp_error( $marked ) ) {
			return new WP_Error(
				'aura_unbind_unreadable',
				__( 'This site\'s Aura disconnect record could not be read, so it cannot be reconnected until it can.', 'digitizer-site-worker' ),
				array( 'status' => 409 )
			);
		}
		if ( ! $marked ) {
			return true; // not an unbound site: a rebind here has nothing to settle
		}
		// The return value is deliberately ignored: cleanup( false, … ) answers
		// false for "the token is still here", which is exactly what this
		// caller WANTS. leftovers() is the evidence, and it is the same
		// evidence step (5) is gated on.
		self::cleanup( false, $fence );
		$left = self::leftovers();
		if ( array() !== $left ) {
			return new WP_Error(
				'aura_unbind_incomplete',
				__( 'The previous Aura binding could not be fully removed from this site, so it cannot be reconnected yet.', 'digitizer-site-worker' ),
				array(
					'status'   => 409,
					'leftover' => $left,
				)
			);
		}
		return true;
	}

	/**
	 * The way back, second half: the marker goes — and the site starts
	 * accepting mutations again — ONLY as the last step of a rebind that
	 * succeeded end to end (#434 Task 7).
	 *
	 * Call this after the replacement token is installed AND read back, the
	 * binding written, the dashboard URL and gateway key stored and the
	 * Application Password settled, under the SAME claim those writes ran
	 * under. Every earlier exit of either flow must return WITHOUT calling it,
	 * so a half-established replacement binding is still refused everywhere.
	 *
	 * Nothing is re-proven here about Phase B, and that is not an omission:
	 * leftovers() is about the four things Phase B removes, and by this point
	 * the rebind has legitimately WRITTEN two of them again (the connect user,
	 * the dashboard URL). Asking it a second time would always answer "owed"
	 * and could never mean anything. finish_before_rebind() is the gate, and it
	 * is the only place the question can honestly be asked.
	 *
	 * A false answer is a store failure the flow must report retryably (500):
	 * the replacement token is in place and no longer matches the marker's
	 * `site`, so maybe_finish() will not touch the new binding and the fast
	 * path cannot be reached — the site simply goes on refusing until the next
	 * connect or rotation, whose own finish_before_rebind() finds nothing owed
	 * and clears it.
	 *
	 * @since 2.13.0
	 *
	 * @param string $fence The caller's site-claim fence.
	 * @return bool True once the marker is confirmed gone (or was never there).
	 */
	public static function release_marker_after_rebind( string $fence ): bool {
		if ( ! self::is_set() ) {
			return true;
		}
		return self::delete_under_claim( $fence );
	}

	/**
	 * The ONE route an unbound site must still answer: the ruleset envelope.
	 *
	 * The unbind envelope — and every retry of it — arrives on /aura/v2/rules,
	 * which Task 3 answers from the marker fast path. A site that refused this
	 * route could not be told anything, including that it is unbound.
	 *
	 * Anchored at BOTH ends (round-1 MINOR-3). Right-anchored alone, the
	 * exemption also matched '/aura/v1/anything/aura/v2/rules' — unreachable
	 * today, because the only registered capture excludes slashes, but an
	 * exemption that widens the day someone writes (?P<path>.+) is not an
	 * exemption anybody chose.
	 *
	 * Lives here, as ONE definition, because there are now two boundaries that
	 * must agree about it — Aura_Worker_Security::refuse_if_unbound() at
	 * SiteAgent's own routes, and Aura_Worker_Rules::guard_core_any() at
	 * core's (#434 Task 6). Two copies of a security exemption are two things
	 * that can drift apart, and only one of them would be noticed.
	 *
	 * @since 2.13.0
	 *
	 * @param string $route The REST route, as $request->get_route() gives it.
	 * @return bool
	 */
	public static function is_rules_route( string $route ): bool {
		return 1 === preg_match( '#^/aura/v2/rules$#', $route );
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
	 * The user this marker RECORDED as the owner of that Application Password,
	 * or null when the site does not know (round-3).
	 *
	 * Phase B does not search for an owner and does not guess one. An
	 * Application Password lives in exactly one user's meta, so an owner the
	 * marker recorded is decisive: the password is either in that user's list
	 * (revoke it there) or it is gone. Three rounds of review found the same
	 * failure each time — a skipped entry, a lookup against a guessed owner,
	 * and a closing set enumerated by ROLE while the mint authorises by
	 * CAPABILITY — all of them attempts to prove a NEGATIVE ("nobody holds
	 * this") from evidence that cannot carry it. Aura cannot prove a negative
	 * about a user it never recorded, so it no longer tries: the resolution
	 * happens at WRITE time, in Phase A, where the request knows who
	 * authenticated and what the managed record says, and an owner that could
	 * not be resolved there is stored as an explicit null.
	 *
	 * An unknown owner therefore blocks the teardown for good — leftovers()
	 * names `app_passwords`, `cleanup_complete` stays false, and step (5)
	 * never runs — until the operator clears it (Task 9's removal panel).
	 * That is the whole point: a false "clean" here deletes the site token
	 * while a live `manage_options` REST credential remains, with no token
	 * left for any retry and nothing for Task 7's rebind to refuse on.
	 *
	 * @param array  $m    The marker.
	 * @param string $uuid The password's uuid.
	 * @return int|null The recorded owner, or null when unknown.
	 */
	private static function password_owner( array $m, string $uuid ): ?int {
		// read() has already normalised every entry to a positive int or null;
		// a uuid with no entry at all is the same unknown.
		return isset( $m['app_password_users'][ $uuid ] ) ? (int) $m['app_password_users'][ $uuid ] : null;
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
	 * REPAIR A MALFORMED MARKER SO THE TEARDOWN CAN RUN (#434 Task 9, fix round 1).
	 *
	 * A marker row this build cannot parse is the worst state in the whole
	 * design: `departed_binding_request()` refuses EVERY agent REST write from
	 * ANY Application Password — Aura's and the site owner's alike — both
	 * rebinds refuse (Task 7), and `cleanup()` does nothing at all for a marker
	 * it cannot read, so nothing on the site can ever change that. The site's
	 * whole API-automation surface is dead, permanently.
	 *
	 * The obvious exit — let the operator delete the marker — is the failure
	 * family this project has made six times, because read() answers ONE
	 * WP_Error for two different worlds: a row that is genuinely malformed,
	 * and a healthy row whose single uncached read hit a database blip. A
	 * teardown gated on `is_wp_error()` would delete a perfectly good marker,
	 * with real outstanding debts, on a transient failure. So this gates on
	 * MALFORMED_CODE specifically — the codes ARE separable, a failed read
	 * answers Aura_Worker_Rules::option_raw()'s store error — and it reads
	 * TWICE, because one read that says "malformed" is one read.
	 *
	 * And it does not delete anything. It REBUILDS the row into a marker the
	 * existing, already-reviewed teardown can run against unchanged:
	 *
	 *   - `site` = the live token hash, so maybe_finish() goes on recognising
	 *     this site and the marker still names the credential Aura holds;
	 *   - `seq` 0 and empty `site_ref`/`client` — the fields that only ever
	 *     matched a retry of the same tombstone, moot once the token is gone,
	 *     and the only things fabricated here;
	 *   - the credential list rebuilt from the SITE
	 *     (Aura_Worker_Magic_Link::minted_passwords()), never invented: an
	 *     unprovable sweep refuses the whole repair;
	 *   - MERGED WITH what the damaged row itself still legibly holds
	 *     (round-2 N1). "The row is malformed" is not "the row holds nothing
	 *     usable", any more than an unresolvable owner was evidence of absence
	 *     in Task 4: validated() rejects a row on its five SCALARS, and
	 *     `app_password_uuids` / `app_password_users` are not type-checked at
	 *     all, so a damaged row very often still carries a perfectly readable
	 *     credential list. That list is the ONLY record of the authenticating
	 *     uuid Phase A appends (Aura_Worker_Rules::new_marker()) for a password
	 *     Aura never minted — the manual credential the name sweep cannot see —
	 *     and rebuilding from the sweep alone would drop it, complete the
	 *     teardown, lift the refusal and leave it live, while telling the
	 *     operator it worked. marker_array() exists for exactly this reading,
	 *     and the merge adds no inference: an entry whose SHAPE cannot be
	 *     trusted is dropped, a recovered uuid carries NO owner from the
	 *     damaged row, and it then goes through resolve_unknown_owners() like
	 *     any other unattributed credential.
	 *
	 * What it CANNOT recover is a uuid list that is itself unreadable — the key
	 * absent, holding a scalar, or its entries not scalars. Then a manually
	 * supplied credential really is unrecorded by anything on this site, and
	 * the teardown says so to the operator before removing anything.
	 *
	 * @since 2.13.0
	 *
	 * @param string $fence The caller's site-claim fence.
	 * @return bool True once the repaired marker is stored and reads back
	 *              well-formed.
	 */
	public static function repair_malformed_marker( string $fence ): bool {
		if ( '' === $fence || ! Aura_Worker_Magic_Link::holds_site_claim( $fence ) ) {
			return false;
		}
		// Twice, and both must say MALFORMED. A transient read failure answers
		// a different code and repairs nothing.
		//
		// The second read NARROWS the window in which a concurrent well-formed
		// write could be overwritten; it does not close it, and the comment
		// here used to claim it did (round-2 LOW-4). What actually excludes
		// that interleaving is the SITE CLAIM, which covers the whole window:
		// every writer of this option in the plugin goes through
		// write_under_claim(), which is claim-conditional in SQL, and the
		// operator holds the fence from before the first read until after the
		// write — so a concurrent Phase A cannot land at all, and if the claim
		// were taken away (the 120s stale takeover, an activation eviction)
		// this repair's own write refuses. What is left is the residual
		// holds_site_claim() already names plugin-wide: a handler descheduled
		// between its check and its write. The second read is belt-and-braces
		// over that, not the guard.
		if ( ! self::is_malformed() || ! self::is_malformed() ) {
			return false;
		}
		$token = Aura_Worker_Rules::site_token_uncached();
		if ( is_wp_error( $token ) ) {
			return false; // the repaired marker must name the token this site holds
		}
		$found = Aura_Worker_Magic_Link::minted_passwords();
		if ( null === $found ) {
			return false; // a credential list nobody could prove is not a list
		}
		$found = self::merged_with_damaged_row( $found );
		if ( null === $found ) {
			return false; // the row cannot say which credentials this binding held
		}
		$who = Aura_Worker_Rules::read_option_uncached( 'aura_worker_connect_user_id' );
		$who = is_wp_error( $who ) ? 0 : (int) maybe_unserialize( (string) $who );
		$ok  = self::write_under_claim(
			array(
				'at'                 => gmdate( 'c' ),
				'site'               => (string) $token,
				'site_ref'           => '',
				'client'             => '',
				'seq'                => 0,
				'connect_user_id'    => $who > 0 ? $who : 0,
				'app_password_uuids' => array_keys( $found ),
				'app_password_users' => $found,
			),
			$fence
		);
		// write_under_claim() proves the row names this site at this seq, which
		// is exactly what a repair changes — but not that the result PARSES.
		// The whole point is a marker the teardown can read, so read it.
		return $ok && is_array( self::read() );
	}

	/**
	 * Add what the damaged row still legibly says about credentials to what the
	 * sweep found (round-2 N1) — a strict superset, never a replacement.
	 *
	 * The sweep is a superset of what Aura MINTED and cannot see anything else;
	 * the row is the only record of the authenticating uuid Phase A appended
	 * for a password it did not mint. Neither is complete alone, so both are
	 * used — but they are not used for the same KIND of fact:
	 *
	 *  - a uuid entry that is not a scalar names nothing this code can act on
	 *    and is dropped rather than coerced (`array` to string is a warning and
	 *    a lie; an object is worse);
	 *  - a uuid the sweep already attributed keeps the sweep's OWNER, which was
	 *    read from that user's own list moments ago;
	 *  - a uuid the sweep did NOT attribute carries NO OWNER AT ALL, whatever
	 *    the row says (round-3). This is the rule that matters, and it is Task
	 *    4's C2 in new clothes: `resolve_unknown_owners()` skips an ATTRIBUTED
	 *    uuid — Phase B's single lookup stands — so an owner that is wrong but
	 *    syntactically valid means that lookup asks the wrong user's list, is
	 *    told "not there", and reports a live credential revoked. The row this
	 *    owner comes from is one this code has ALREADY DECLARED CORRUPTED;
	 *    treating an attribution from it as knowledge is exactly the inference
	 *    three Criticals were spent removing. The explicit null Phase A itself
	 *    writes is both honest and STRICTLY STRONGER here: the resolution then
	 *    proves absence across the whole table and deletes the credential from
	 *    every list holding it, where one lookup against an unverified owner
	 *    proves nothing at all. It costs one statement per recovered uuid, on a
	 *    single operator click.
	 *
	 * @since 2.13.0
	 *
	 * @param array<string,int|null> $found uuid => owner, from the sweep.
	 * @return array<string,int|null>|null The merged list, or null when the row
	 *                                     cannot say what this binding held.
	 */
	private static function merged_with_damaged_row( array $found ): ?array {
		$raw   = self::marker_array();
		$uuids = null === $raw ? null : aura_worker_credential_list( $raw['app_password_uuids'] ?? null );
		if ( null === $uuids ) {
			// AN ILLEGIBLE LIST IS NOT AN EMPTY ONE (Codex round-5 P1). The
			// sweep is a superset of what SiteAgent MINTED — it looks for the
			// name mint_app_password() stamps — and the credential this rule
			// protects is precisely the one it cannot see: a password an
			// operator connected by hand, or one Aura's PATCH installed,
			// carrying a name of somebody else's choosing. The row was its only
			// record. Repairing from the sweep alone would hand the teardown a
			// list that looks complete, let it report success, and delete the
			// token and the marker beside a live administrator credential —
			// the same inference three rounds have been spent removing, one
			// layer further in.
			//
			// Enumerating EVERY Application Password on the site instead is
			// worse, not better: it would sweep the site owner's unrelated
			// credentials into the marker for the teardown to revoke. So the
			// repair refuses, the marker stays malformed, and the site keeps
			// refusing — which is the honest state of a site whose credential
			// list has been destroyed.
			return null;
		}
		foreach ( $uuids as $uuid ) {
			if ( array_key_exists( $uuid, $found ) ) {
				continue;
			}
			// Null, never the row's `app_password_users` entry: see above.
			$found[ $uuid ] = null;
		}
		return $found;
	}

	/**
	 * Is the marker row present and unparseable — as opposed to unreadable?
	 * The distinction the repair turns on, asked in one place so both reads
	 * ask it the same way.
	 *
	 * @return bool
	 */
	private static function is_malformed(): bool {
		$m = self::read();
		return is_wp_error( $m ) && self::MALFORMED_CODE === $m->get_error_code();
	}

	/**
	 * THE OPERATOR'S RESOLUTION OF AN OWNER PHASE A COULD NOT NAME (#434 Task 9).
	 *
	 * Phase A records a positive owner or an explicit null, and Phase B does one
	 * authoritative lookup against a recorded owner and NO lookup at all against
	 * a null one — it cannot delete from a user it cannot name, and it will not
	 * guess. So a null owner owes `app_passwords` in leftovers() unconditionally
	 * and forever: cleanup() can never reach step (5), the token is never
	 * deleted, the marker is never cleared, and Aura's tombstone pends for good.
	 * By design (Task 4's ruling) — with the operator as the way out, and this
	 * is the operator's half.
	 *
	 * It does NOT re-introduce the search that ruling deleted. It records no
	 * guessed owner in the marker; `app_password_users` keeps meaning "a user
	 * this site was TOLD about". For each unattributed uuid it does the only
	 * two things that are actually provable:
	 *
	 *   1. ask the whole usermeta table, in one statement, which lists carry
	 *      that uuid (Aura_Worker_Magic_Link::password_holders()), and delete
	 *      it from each — the credential is what matters, not who holds it;
	 *   2. ask again. Only an answer that PROVED no list on this site carries
	 *      the uuid retires it from the marker, and the marker is rewritten
	 *      under the claim and re-read before any of that is believed.
	 *
	 * Retiring a proven-absent uuid is safe in the one direction that matters:
	 * `Aura_Worker_Rules::departed_binding_request()` refuses a request that
	 * authenticates with a uuid the marker names, and a uuid no list carries
	 * cannot authenticate anything. Everything else — a statement that proved
	 * nothing, a delete that did not land, a marker rewrite that did not verify
	 * — leaves the marker exactly as it was and the uuid reported as owed.
	 *
	 * This is also the one place SiteAgent removes an Application Password it
	 * cannot attribute, which is what closes the XML-RPC door Task 6 recorded:
	 * `Aura_Worker_Rules::is_agent_rest_request()` requires `REST_REQUEST`, so
	 * a departed Application Password still reaches `pre_delete_post` over
	 * XML-RPC — live only on this unknown-owner path, because Phase B revokes
	 * every attributed password first. Removing the credential closes it; no
	 * guard outside REST is widened.
	 *
	 * @since 2.13.0
	 *
	 * @param string $fence The caller's site-claim fence.
	 * @return string[] The uuids still unattributed after this call — empty
	 *                  when there were none, or when every one was settled.
	 */
	public static function resolve_unknown_owners( string $fence ): array {
		$m = self::read();
		if ( ! is_array( $m ) || '' === $fence || ! Aura_Worker_Magic_Link::holds_site_claim( $fence ) ) {
			// No marker this call can read is no LIST of uuids either, so there
			// is nothing here to report about. The caller decides separately
			// what an unreadable marker means — see ajax_remove_aura_data(),
			// which refuses before ever getting here.
			return array();
		}
		$uuids   = $m['app_password_uuids'];
		$users   = $m['app_password_users'];
		$left    = array();
		$retired = array();
		foreach ( $uuids as $uuid ) {
			$uuid = (string) $uuid;
			if ( null !== self::password_owner( $m, $uuid ) ) {
				continue; // attributed: Phase B's single lookup stands
			}
			$holders = Aura_Worker_Magic_Link::password_holders( $uuid );
			if ( is_array( $holders ) && array() !== $holders && class_exists( 'WP_Application_Passwords' ) ) {
				foreach ( $holders as $holder ) {
					WP_Application_Passwords::delete_application_password( (int) $holder, $uuid );
				}
				// The SECOND statement is the proof; the delete's return value
				// is not (it is false for a failed meta write as well as for
				// "not there"), exactly as Phase B's step (1) reasons.
				$holders = Aura_Worker_Magic_Link::password_holders( $uuid );
			}
			if ( array() === $holders ) {
				$retired[] = $uuid;
				continue;
			}
			$left[] = $uuid;
		}
		if ( array() === $retired ) {
			return $left;
		}
		$updated                       = $m;
		$updated['app_password_uuids'] = array_values( array_diff( $uuids, $retired ) );
		$updated['app_password_users'] = array_diff_key( $users, array_flip( $retired ) );
		self::write_under_claim( $updated, $fence );
		// write_under_claim() proves "the row names my site at my seq", which a
		// retirement does not change — so it would pass whether or not this
		// edit landed. Read the list back instead, and treat anything that is
		// still there (including a marker that has become unreadable under us)
		// as still owed.
		$back  = self::read();
		$still = is_array( $back ) ? $back['app_password_uuids'] : $uuids;
		foreach ( $retired as $uuid ) {
			if ( in_array( $uuid, $still, true ) ) {
				$left[] = $uuid;
			}
		}
		return $left;
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
			$uuid  = (string) $uuid;
			$owner = self::password_owner( $m, $uuid );
			// Owed when the owner is unknown — no proof is attempted, and an
			// unknown is never reported clean (rounds 1-3) — and owed when the
			// recorded owner still holds it, AND owed when that owner's list
			// could not be read at all (round 4, I5). Only STATE_GONE, "the
			// owner this site recorded was asked and no longer has it", is
			// evidence of absence — the same rule option_absent() applies to a
			// row, applied to a credential.
			if ( null === $owner || Aura_Worker_Magic_Link::STATE_GONE !== Aura_Worker_Magic_Link::password_state( $owner, $uuid ) ) {
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
				$uuid  = (string) $uuid;
				$owner = self::password_owner( $m, $uuid );
				if ( null === $owner ) {
					// Nothing can be deleted from a user the site cannot name,
					// and nothing may be guessed. Not a silent skip: leftovers()
					// names 'app_passwords' for the same entry, so the gate
					// below refuses step (5) and the site keeps its token, its
					// marker and therefore its ability to be repaired
					// (rounds 1-3).
					continue;
				}
				if ( ! Aura_Worker_Magic_Link::password_gone( $owner, $uuid ) ) {
					// Includes "the list could not be read" (I5): attempting a
					// delete that may be unnecessary costs one write and is
					// idempotent, whereas SKIPPING one that was necessary
					// leaves the credential live. The return value is not the
					// proof either way (it is false for a failed user-meta
					// write as well as for "not there"), so leftovers() looks
					// again below.
					WP_Application_Passwords::delete_application_password( $owner, $uuid );
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
		//
		// leftovers() reports on the four things steps (1)-(4) remove and can
		// never name the token itself, so a `final: true` request whose delete
		// does not land answers `cleanup_complete: false` with an EMPTY
		// leftovers list — the shape the spec otherwise reserves for
		// `final: false` (#434 M11, recorded deliberately, not fixed). It is
		// safe in both directions that matter: `final` is set only on the
		// tombstone that CARRIES the token (Aura sets it when no other pending
		// tombstone shares the same siteTokenHash), and the drain's
		// retire-on-empty exception is scoped to a tombstone that is NOT the
		// carrier, so the two cannot meet; and the residual is a SURVIVING
		// token, which is the fail-safe direction — no credential outlives
		// anything. The other early returns above answer non-empty.
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
		// THE THROTTLE IS ASKED FIRST (final round MINOR-2). is_set() is a
		// deliberately UNCACHED raw read — one guaranteed query — and asking it
		// ahead of the throttle meant the throttle did not throttle the
		// expensive half: an unbound site paid that query on every single page
		// load, not once per FINISH_THROTTLE. The two orders decide identically,
		// because the sweep runs only when the marker is set AND the transient is
		// absent, and the transient is written only on the path where both hold;
		// so no finish that is due can be skipped, and nothing is thrown away by
		// asking the cheap question first.
		if ( false !== get_transient( self::FINISH_TRANSIENT ) ) {
			return;
		}
		if ( ! self::is_set() ) {
			// Deliberately BEFORE set_transient(): a site with no marker must not
			// arm the throttle, or a Phase A landing a moment later would wait
			// out FINISH_THROTTLE before its first self-heal.
			return;
		}
		set_transient( self::FINISH_TRANSIENT, 1, self::FINISH_THROTTLE );
		$fence = Aura_Worker_Magic_Link::claim_site();
		if ( '' === $fence ) {
			return; // a connect (or another sweep) holds the site; next time
		}
		try {
			// is_set() above answers TRUE for an unreadable marker, which is
			// the right answer for a REFUSAL and the wrong one for a sweep.
			// Re-read here and stop on anything that is not a marker: an
			// unreadable one must not send this cleanup after a binding it
			// cannot identify.
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
