<?php
/**
 * Held Elementor-door writes (spec §3.6, §3.7).
 *
 * One option row per hold, never one array: a real conditional INSERT
 * (Aura_Worker_Door_Log::insert_unique(), never add_option() — WordPress
 * core's add_option() is an INSERT … ON DUPLICATE KEY UPDATE behind a cached
 * existence check, so a racer that passed the check can still overwrite and
 * get `true` back; it is not a mutex) on the options table's unique name, so
 * two concurrent holds cannot read-modify-write each other away. A replay
 * CLAIMS a hold by moving it — INSERT the claimed twin, then DELETE the held
 * row, and the delete must remove exactly one row or the claim backs out.
 * Reject and the TTL sweep delete a held row only when it has no claimed
 * twin.
 *
 * @package Aura_Worker
 * @since 2.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Worker_Door_Holds {

	/**
	 * @var bool Set by from_db()/raw_bytes() (Ruling S37, Codex round-15
	 * class sweep on #88): did the MOST RECENT read fail to prove itself
	 * (a driver error), rather than the option genuinely being absent?
	 * Reset at the start of every such read — a per-call outcome, checked
	 * by the caller immediately afterwards, before anything else in this
	 * same request can issue a second read and overwrite it.
	 */
	private static $option_read_unreadable = false;

	/**
	 * @var bool Set by partition_stale_claims() (Ruling S44, Codex
	 * round-18 P2 on #88): did the MOST RECENT call fail to prove its
	 * read of the claimed queue, rather than finding it genuinely empty?
	 * Sticky across every partition_stale_claims() call THIS ATTEMPT
	 * (stale_unleased_claims() and running_claims() each call it once) —
	 * reset once, at the top of the attempt, by
	 * reset_claimed_queue_unreadable_for_attempt().
	 */
	private static $claimed_queue_unreadable_this_attempt = false;

	const HELD    = 'aura_worker_door_held_';
	const CLAIMED = 'aura_worker_door_claimed_';
	const TTL_S   = 604800;
	const CAP     = 50;
	const LOCK    = 'aura_worker_door_hold_lock';
	/**
	 * A replay's EXECUTION LEASE lives exactly as long as its database
	 * connection, so it cannot outlive the request that took it (Ruling P52).
	 * The hard cap bounds the one case a named lock cannot: a lease stranded
	 * on a persistent connection.
	 */
	const LEASE_PREFIX     = 'aura_door_replay_';
	/** The same lease, over a log SEQ: every governed write, not only a replay (Ruling P56). */
	const SEQ_LEASE_PREFIX = 'aura_door_seq_';
	const LEASE_HARD_CAP_S = 86400;

	/**
	 * What `get_lock()` answers on an engine that has no named locks at all
	 * (Ruling P70). Distinct from null, which is a FAILURE: a failure refuses
	 * the write, while an engine that simply cannot lease is proceeded with
	 * under the hard cap — bounded, never blind.
	 */
	const LEASE_UNSUPPORTED = 'unsupported';

	/** @var bool|null Per-request memo: does this engine have named locks? */
	private static $locks_supported = null;

	/** @var array<string,array>|null The held read this request already made. */
	private static $held_rows = null;

	/** @var bool Whether $held_rows holds an answer (null is one). */
	private static $held_read = false;
	const LOCK_S  = 30;

	/**
	 * @param array $call { ability, input, touches, actor, verdict, rule }.
	 * @return string|WP_Error ref, or aura_hold_queue_full / aura_hold_failed.
	 */
	public static function hold( array $call ) {
		// Count-then-insert is ONE transition under a short mutex: ten requests
		// arriving at a count of 49 would otherwise all pass the check and leave
		// 59 rows (Codex round-9 P2 on #499). insert_unique() on the lock name is
		// a real conditional INSERT on the options table's unique key, the same
		// primitive the creation mutex uses.
		//
		// The release is FENCED on the exact bytes this request inserted, and
		// is not a delete_option(). A holder that overran LOCK_S has already
		// had its row fence-replaced by a racer's fresh lock; an
		// unconditional release then deletes THAT — and the racer goes on
		// running hold_locked() with no mutex at all, which is the whole of
		// what the lock provides. The same reasoning as take_lock()'s own
		// fenced delete, applied to the other end of the lock's life.
		$token = self::take_lock();
		if ( false === $token ) {
			return new WP_Error( 'aura_hold_busy', 'This site is admitting another call for approval; retry.', array( 'status' => 503, 'retry_after' => 5 ) );
		}
		try {
			return self::hold_locked( $call );
		} finally {
			self::release_lock( $token );
		}
	}

	/**
	 * Delete the lock row ONLY while it still carries the bytes this request
	 * inserted — never a row a racer installed after this holder went stale.
	 *
	 * @param string $token The value take_lock() inserted.
	 */
	private static function release_lock( $token ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK, (string) $token ) );
		wp_cache_delete( self::LOCK, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Takes the hold-queue mutex; a lock older than LOCK_S is a crashed
	 * holder and is replaced — but the replacement is a DELETE fenced on the
	 * exact bytes this call read as stale, never an unconditional
	 * delete_option(). Two racers both meeting the same crashed lock would
	 * otherwise interleave as: A deletes, A inserts (wins), B deletes — B's
	 * unconditional delete has no idea A's row is not the stale one it saw a
	 * moment ago, so it removes A's brand-new lock, B inserts (wins too), and
	 * A's own `finally` release later deletes OUT FROM UNDER B. The cap check
	 * in hold_locked() would then run for both at once — unserialized despite
	 * the mutex (round-1 finding on task 4's review). Fencing the delete on
	 * the read bytes means only the racer whose delete actually hits the
	 * stale row gets to insert next; a racer that loses the delete just loops
	 * and meets whichever lock is there afterwards.
	 *
	 * The value inserted carries a uuid beside the timestamp so the release
	 * fence names THIS lock and no other: the staleness read below is an
	 * `(int)` cast, which reads the timestamp and ignores the rest.
	 *
	 * @return string|false The value inserted (the release's fence), or false.
	 */
	private static function take_lock() {
		global $wpdb;
		for ( $i = 0; $i < 3; $i++ ) {
			$token = time() . '|' . wp_generate_uuid4();
			if ( Aura_Worker_Door_Log::insert_unique( self::LOCK, $token ) ) {
				return $token;
			}
			wp_cache_delete( self::LOCK, 'options' );
			$raw = self::raw_bytes( self::LOCK );
			if ( self::read_was_unreadable() ) {
				// Ruling S37 (Codex round-15 class sweep on #88): ambiguous —
				// this read cannot prove the lock vanished, only that it
				// could not be read. Treated exactly like a lock that is
				// there and not yet stale: back off and let the next
				// iteration re-read, never race to take over a lock this
				// call never actually proved gone.
				usleep( 50000 );
				continue;
			}
			if ( null === $raw ) {
				continue; // the row vanished between the failed insert and this read
			}
			$held = (int) $raw;
			if ( $held && time() - $held > self::LOCK_S ) {
				$wpdb->last_error = '';
				$gone = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK, $raw ) );
				wp_cache_delete( self::LOCK, 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				if ( 1 === (int) $gone ) {
					continue; // whoever's insert_unique() wins next owns it
				}
				// Lost the delete race — some other caller's fence matched
				// instead (or already won and released). Fall through to the
				// same backoff a live lock gets; the next iteration re-reads
				// whatever is there now.
			}
			usleep( 50000 );
		}
		return false;
	}

	/** The body of hold(), run only while the lock is held. */
	private static function hold_locked( array $call ) {
		// An expired hold is not an approval anybody can act on — listing()
		// hides it and get_held() refuses it — so it must not charge a queue
		// slot either (Ruling P21). Purged HERE, under the lock and before
		// the cap is read: the sweep only runs from a `/status` poll, and a
		// site that has not been polled since fifty holds ran out of time
		// answered `aura_hold_queue_full` to every new Elementor write while
		// there was nothing live in the queue at all.
		self::purge_expired();
		$queued = self::count();
		if ( null === $queued ) {
			// CANNOT COUNT, CANNOT ADMIT (Ruling P57). Not `aura_hold_queue_full`
			// — the queue may be empty, and saying it is full would send the
			// operator looking for approvals that are not there. Retryable, and
			// nothing is inserted.
			return new WP_Error( 'aura_hold_failed', __( 'Aura could not read this site\'s approval queue; the call was not run — retry.', 'digitizer-site-worker' ), array( 'status' => 503 ) );
		}
		if ( $queued >= self::CAP ) {
			return new WP_Error( 'aura_hold_queue_full', 'Aura\'s approval queue for this site is full (50 held calls); ask the operator to act on it.', array( 'status' => 503 ) );
		}
		$ref = 'door_' . wp_generate_uuid4();
		// WHOSE QUEUE THIS ROW JOINS, before anything is written (Ruling P72).
		// An empty stamp used to read as "queued before the generation
		// existed" — permanently current — so a hold taken during a
		// first-reader race stayed claimable by whichever client came next.
		// Nothing has been written yet: refusing is retryable and free.
		$binding = Aura_Worker_Door_Log::binding();
		if ( null === $binding ) {
			return new WP_Error( 'aura_hold_failed', 'This site could not establish which Aura binding this call belongs to; it was not held.', array( 'status' => 503, 'retry_after' => 5 ) );
		}
		$now = time();
		$row = array(
			'ref'        => $ref,
			// WHICH BINDING queued this call (Ruling P58). A hold is a stored
			// WordPress action waiting for somebody to approve it, and only the
			// client that queued it may ever be shown or given it — so the
			// generation is written here and every reader compares it.
			'binding'    => $binding,
			'ability'    => (string) $call['ability'],
			'input'      => is_array( $call['input'] ?? null ) ? $call['input'] : array(),
			'touches'    => is_array( $call['touches'] ?? null ) ? $call['touches'] : array(),
			'actor'      => is_array( $call['actor'] ?? null ) ? $call['actor'] : array(),
			'verdict'    => in_array( $call['verdict'] ?? '', array( 'none', 'warn', 'rules_unavailable' ), true ) ? $call['verdict'] : 'none',
			'rule'       => is_array( $call['rule'] ?? null ) ? self::rule_fields( $call['rule'] ) : null,
			'created_at' => gmdate( 'c', $now ),
			'expires_at' => gmdate( 'c', $now + self::TTL_S ),
		);
		// Door state is about to exist, so the site's epoch must (Ruling P35):
		// `present()` reads the epoch option as the single witness that this
		// site has a door, and a hold taken before the first `/status` poll —
		// with Elementor disabled in between — would otherwise be a queue
		// nothing reports and no reconciler ever sweeps. Before the insert, so
		// the witness can never lag the state it witnesses.
		//
		// AND ITS ANSWER IS A CONDITION OF THE HOLD (Ruling P96). The mint's
		// result was ignored, so a transient failure left the epoch empty while
		// this insert went on to succeed — a queued approval that `present()`
		// could not witness, `/status` never reported, and no reconciler ever
		// swept. Retryable, and nothing is written.
		if ( '' === (string) Aura_Worker_Door_Log::epoch() ) {
			return new WP_Error( 'aura_hold_failed', 'This site could not establish its door log epoch; the call was not held.', array( 'status' => 503, 'retry_after' => 5 ) );
		}
		$queued = Aura_Worker_Door_Log::insert_unique( self::HELD . $ref, $row );
		self::forget_held();
		if ( null === $queued ) {
			// Ruling S51 (Codex round-20 P1 on #88): $ref is a fresh random
			// UUID EVERY call (unlike claim()'s CLAIMED insert, which is
			// keyed by the caller-supplied $ref) — a caller retrying on an
			// ordinary `false` from below inserts a brand-new row and is
			// safe. Retrying blind on an UNPROVEN outcome is not: if this
			// attempt's own insert actually landed, a retry queues a SECOND,
			// duplicate approval for the same call under a different ref.
			// `may_have_run` is what tells the caller not to just retry —
			// check whether this call was already admitted first.
			return self::retry_may_have_run();
		}
		if ( ! $queued ) {
			return new WP_Error( 'aura_hold_failed', 'This site could not store the call for approval; it was not run.', array( 'status' => 503 ) );
		}
		return $ref;
	}

	/** @param array $rule Rule. @return array { key, ruleHash, reason } */
	private static function rule_fields( array $rule ) {
		return array(
			'key'      => (string) ( $rule['key'] ?? '' ),
			'ruleHash' => (string) ( $rule['ruleHash'] ?? '' ),
			'reason'   => (string) ( $rule['reason'] ?? '' ),
		);
	}

	/**
	 * Has this hold's seven days run out?
	 *
	 * The SAME expression listing() and sweep() judge by, so the three cannot
	 * disagree about one row — including about a row whose `expires_at` is
	 * missing or unreadable, which all three read as expired: a hold that
	 * cannot say when it ends is not evidence that it has not.
	 *
	 * @param array $row Held row.
	 * @param int   $now Unix time.
	 * @return bool
	 */
	private static function is_expired( array $row, $now ) {
		return strtotime( (string) ( isset( $row['expires_at'] ) ? $row['expires_at'] : '' ) ) <= (int) $now;
	}

	/**
	 * The held row for this ref, or null — and an EXPIRED row is not held
	 * (Ruling P18).
	 *
	 * listing() hides an expired hold and sweep() deletes it, but both run
	 * from a `/status` poll: on a site that has not been polled since the
	 * hold's seven days ran out, the row is still there, and a reader that
	 * returned it let an approval using a previously retained ref execute a
	 * mutation after the hold had ostensibly expired.
	 *
	 * The row is deleted here the way sweep() would — but only when it has NO
	 * claimed twin. A claimed twin means a replay owns this ref and its own
	 * delete is still coming; deleting the held row underneath it is exactly
	 * the race sweep() refuses to enter (round-9), and a reader has even less
	 * business entering it.
	 *
	 * @param string $ref Ref.
	 * @return array|null
	 */
	public static function get_held( $ref ) {
		$ref = self::clean( $ref );
		$row = get_option( self::HELD . $ref, null );
		if ( ! is_array( $row ) ) {
			return null;
		}
		if ( ! self::row_is_current( $row ) ) {
			// Another binding's hold: absent to every reader, and left for the
			// sweep to remove (Ruling P58). Not deleted here — a reader has no
			// business deleting, and the sweep already owns that decision.
			return null;
		}
		if ( self::is_expired( $row, time() ) ) {
			if ( null === self::get_claimed( $ref ) ) {
				self::delete_versioned( self::HELD . $ref ); // Ruling S8
			}
			return null;
		}
		return $row;
	}

	/**
	 * Take an EXPIRED held row: delete it and hand it back (Ruling P43).
	 *
	 * `get_held()` answers null for an expired row and deletes it silently, so
	 * a caller cannot tell "this ref was never held" from "the operator's
	 * seven days ran out". Those are different answers to Aura — the second
	 * says the approval LAPSED — and the mirror has to learn which.
	 *
	 * The deadline is never extended: the operator's seven days are the
	 * operator's, and a replay that arrives late is late. So this deletes
	 * rather than refreshes, FENCED on the bytes it just read, and returns the
	 * row so the caller can record what lapsed.
	 *
	 * Nothing is taken while a claimed twin exists — a replay owns that ref
	 * and its own move is still coming, the same rule get_held() and sweep()
	 * follow.
	 *
	 * @param string $ref Ref.
	 * @return array|null The expired row, now deleted; null when it is not
	 *                    there, not expired, or claimed.
	 */
	public static function take_expired( $ref ) {
		$ref = self::clean( $ref );
		$row = self::from_db( self::HELD . $ref );
		if ( null === $row || ! self::is_expired( $row, time() ) || null !== self::get_claimed( $ref ) ) {
			return null;
		}
		$bytes = self::raw_bytes( self::HELD . $ref );
		if ( null === $bytes ) {
			return null;
		}
		// Ruling S8: versioned like every other fenced delete here.
		return self::delete_versioned( self::HELD . $ref, $bytes ) ? $row : null;
	}

	/**
	 * The BINDING generation this ref was CLAIMED under, read from the
	 * database (Rulings P47/P51) — never this request's option cache, because
	 * the writer that could have taken the row away is another request.
	 *
	 * @param string $ref Ref.
	 * @return string|null null when the claimed row is gone; '' when it
	 *                     carries no binding (claimed before this rule existed).
	 */
	public static function claimed_binding( $ref ) {
		$row = self::from_db( self::CLAIMED . self::clean( $ref ) );
		return null === $row ? null : (string) ( isset( $row['binding'] ) ? $row['binding'] : '' );
	}

	/** @param string $ref Ref. @return array|null */
	public static function get_claimed( $ref ) {
		$row = get_option( self::CLAIMED . self::clean( $ref ), null );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Update the warn rule on a held row IN PLACE — a conditional UPDATE on
	 * the existing row, never `update_option()`, which would recreate a row a
	 * reject or the sweep deleted meanwhile (round-16 P1).
	 *
	 * @param string $ref  Ref.
	 * @param array  $rule Rule.
	 * @return bool
	 */
	public static function refresh_rule( $ref, array $rule ) {
		$option = self::HELD . self::clean( $ref );
		$before = self::from_db( $option );
		if ( null === $before ) {
			return false;
		}
		$after            = $before;
		$after['verdict'] = 'warn';
		$after['rule']    = self::rule_fields( $rule );
		$written = Aura_Worker_Door_Log::write_option_where( $option, $after, $before );
		self::forget_held();
		return $written;
	}

	/**
	 * Update the TOUCHES on a held row IN PLACE (Ruling P34).
	 *
	 * What a call touches is not fixed for the life of a hold: a
	 * `manage-classes` deletion's collateral pages come from Elementor's
	 * class→posts index, and that index moves while the hold waits. The
	 * operator must be shown what would run NOW, not what would have run when
	 * the call was first refused — so a replay that recomputes the touches
	 * writes them back here before it answers.
	 *
	 * Same fenced compare-and-swap as refresh_rule(): a conditional UPDATE on
	 * the row as it stands, never `update_option()`, which would recreate a
	 * row a reject or the sweep deleted meanwhile.
	 *
	 * @param string $ref     Ref.
	 * @param array  $touches Touches, as touches_for() returns them.
	 * @return bool
	 */
	public static function refresh_touches( $ref, array $touches ) {
		$option = self::HELD . self::clean( $ref );
		$before = self::from_db( $option );
		if ( null === $before ) {
			return false;
		}
		$after            = $before;
		$after['touches'] = $touches;
		$written = Aura_Worker_Door_Log::write_option_where( $option, $after, $before );
		self::forget_held();
		return $written;
	}

	/**
	 * Mark a held row whose door-log entry could not be written (Ruling P25).
	 *
	 * A conditional UPDATE on the row as it stands, never update_option():
	 * the same rule refresh_rule() follows, so a hold a reject or the sweep
	 * removed meanwhile is not recreated by a note about it. Best effort by
	 * design — the hold itself already stands, and this only explains why no
	 * entry accompanies it.
	 *
	 * @param string $ref Ref.
	 * @return bool
	 */
	public static function note_unlogged( $ref ) {
		$option = self::HELD . self::clean( $ref );
		$before = self::from_db( $option );
		if ( null === $before ) {
			return false;
		}
		$after              = $before;
		$after['log_entry'] = false;
		$written = Aura_Worker_Door_Log::write_option_where( $option, $after, $before );
		self::forget_held();
		return $written;
	}

	/**
	 * Claim by MOVE: insert the claimed twin, then delete the held row and
	 * require that delete to remove one row.
	 *
	 * @param string $ref Ref.
	 * @return array|WP_Error The claimed entry, or `not_held`.
	 */
	public static function claim( $ref ) {
		$ref  = self::clean( $ref );
		$held = self::from_db( self::HELD . $ref );
		if ( self::read_was_unreadable() ) {
			// Ruling S37 (Codex round-15 class sweep on #88): a driver
			// failure here used to read exactly like the hold being gone —
			// `not_held()`'s 404, which Aura reads as "this approval no
			// longer exists" and never asks about again. The hold is
			// untouched (nothing above this point has written anything),
			// so the honest answer is retryable, not a refusal.
			return new WP_Error( 'aura_hold_failed', 'This site could not read its approval queue; the claim was not attempted — retry.', array( 'status' => 503, 'retry_after' => 5 ) );
		}
		if ( null === $held ) {
			return self::not_held();
		}
		if ( ! self::row_is_current( $held ) ) {
			return self::not_held(); // another binding's approval is not this one's to run (Ruling P58)
		}
		if ( self::is_expired( $held, time() ) ) {
			// Refused BEFORE the twin is inserted (Ruling P18): claiming an
			// expired hold would move it out of reach of the sweep and hand
			// the caller a row to execute. The row is left alone — the sweep
			// owns its deletion, and get_held() has already answered null to
			// anyone who asked.
			return self::not_held();
		}
		$claimed               = $held;
		$claimed['claimed_at'] = gmdate( 'c' );
		// The BINDING this claim belongs to (Rulings P51/P58). The generation
		// moves only when a changed-binding connect or an unbind MINTS a new
		// one, so a value stamped here can never equal the current generation
		// across a rebind — which is exactly what the wrapper re-reads before
		// the callback to prove the site is still the one that approved this
		// call. Deliberately NOT the log epoch: Aura may rotate that
		// legitimately through `/door/rotate` on a rewind, and a rotation is
		// not a rebind.
		if ( ! isset( $claimed['binding'] ) || '' === (string) $claimed['binding'] ) {
			// A hold carries its binding from hold() (Ruling P58); this only
			// fills it in for a row that somehow carries none, so the P51 fence
			// has something to compare. It must never fill in '' (Ruling P72) —
			// that is the value the fence treats as a mismatch, and writing it
			// here would strand the approval rather than claim it.
			$fill = Aura_Worker_Door_Log::binding();
			if ( null === $fill ) {
				return new WP_Error( 'aura_hold_failed', 'This site could not establish which Aura binding this approval belongs to; it was not claimed.', array( 'status' => 503, 'retry_after' => 5 ) );
			}
			$claimed['binding'] = $fill;
		}
		// Ruling S51 (Codex round-20 P1 on #88): UNKNOWN (this insert's own
		// commit could not be proven) is never the same fact as a PROVEN
		// miss — reading it as "already claimed by someone else"
		// (not_held(), a permanent 404) told Aura a still-open approval was
		// gone for good, when this site genuinely does not know whether it
		// just claimed the row or not. Retryable, and it says so.
		$claimed_won = Aura_Worker_Door_Log::insert_unique( self::CLAIMED . $ref, $claimed );
		if ( null === $claimed_won ) {
			return self::retry_may_have_run();
		}
		if ( ! $claimed_won ) {
			return self::not_held(); // already claimed
		}
		// Ruling S8: versioned like every other fenced delete here — see
		// delete_versioned(). No BYTE fence: ownership is already
		// established by the CLAIMED insert's own uniqueness above, so a
		// plain by-name delete (delete_option()'s own shape) is exactly
		// what a real fence would additionally buy nothing over.
		$held_gone = self::delete_versioned( self::HELD . $ref );
		if ( null === $held_gone ) {
			// Ruling S51: the CLAIMED insert just above is CONFIRMED
			// durable — backing it out on an UNPROVEN delete here would
			// compound one uncertainty with a second one, on top of a row
			// this call already knows it owns. Leave the claim in place
			// (harmless: an admitted claim nothing then runs is exactly
			// what the reconciler's stale-claim sweep already exists to
			// settle) and tell the truth instead of guessing either way.
			return self::retry_may_have_run();
		}
		if ( ! $held_gone ) {
			// A reject or the sweep won the race: the entry we claimed from
			// no longer exists. Back out; nothing runs.
			self::delete_versioned( self::CLAIMED . $ref );
			return self::not_held();
		}
		return $claimed;
	}

	/**
	 * The inverse of claim(): move the row BACK to held.
	 *
	 * A replay claims before it runs, and the wrapper can still refuse the
	 * call AFTER that — a snapshot it could not take, a log row it could not
	 * write, a creation mutex another request holds. Those refusals are
	 * retryable and the callback never ran, so the operator's approval must
	 * not be spent on them (Ruling P7): the row goes back where it came from
	 * and the same ref can be approved again.
	 *
	 * Symmetrical with claim(), and for the same reasons: INSERT the held row
	 * first (a real conditional INSERT — a held row already there means
	 * somebody else owns this ref now, and the claimed row stays put), then
	 * DELETE the claimed twin and require exactly one row. `claimed_at` and
	 * `terminal_seq` are dropped.
	 *
	 * The restored row carries `restored_at` and is therefore NOT
	 * byte-identical to the row the hold was originally stored as (Ruling
	 * P41). Nothing depends on that identity: every reader takes the row as it
	 * finds it, and every writer that fences does so on the bytes it has just
	 * read — claim() copies the CURRENT held row into its twin and deletes the
	 * held row BY NAME, not by value; refresh_rule(), refresh_touches(),
	 * note_unlogged() and stamp_terminal_seq() all read with from_db() and
	 * compare-and-swap on that. What `restored_at` buys is the one thing the
	 * sweep could not otherwise know: WHICH move is in flight when it meets a
	 * held row and a claimed twin together — see sweep().
	 *
	 * `expires_at` is copied UNCHANGED (Ruling P43): the operator's seven days
	 * are the operator's, and returning an approval to the queue is not a
	 * reason to extend them. A row that lapsed while the replay ran comes back
	 * expired, and give_back() takes it away again with `expired` rather than
	 * promising a retry nothing could honour.
	 *
	 * The lost-delete case does NOT back the insert out, which is where this
	 * differs from claim(): claim() backs out because a held row deleted
	 * underneath it means a reject decided the call must not run, while here
	 * the target state (held present, claimed absent) is exactly what a
	 * vanished claimed row leaves behind. It answers false regardless — the
	 * caller reports what it can actually see rather than what it intended.
	 *
	 * @param string $ref Ref.
	 * @return bool The row is held again, by this call.
	 */
	public static function unclaim( $ref ) {
		$ref     = self::clean( $ref );
		$claimed = self::from_db( self::CLAIMED . $ref );
		if ( null === $claimed ) {
			return false;
		}
		$held = $claimed;
		// `binding` STAYS (Ruling P58): it is the hold's own, written when the
		// call was queued, and the row goes back to the queue it came from.
		unset( $held['claimed_at'], $held['terminal_seq'] );
		// The witness the sweep reads to tell this move from claim()'s
		// (Ruling P41). Stamped BEFORE the insert, so a row that exists always
		// carries it.
		$held['restored_at'] = gmdate( 'c' );
		$back = Aura_Worker_Door_Log::insert_unique( self::HELD . $ref, $held );
		self::forget_held();
		if ( ! $back ) {
			return false; // a held row is already there — the claimed row stands
		}
		// Ruling S8: versioned like claim()'s own delete — no byte fence,
		// ownership already established by the HELD insert's own uniqueness.
		return self::delete_versioned( self::CLAIMED . $ref );
	}

	/** @param string $ref Ref. @param int $seq Seq. @return bool */
	public static function stamp_terminal_seq( $ref, $seq ) {
		$option = self::CLAIMED . self::clean( $ref );
		$before = self::from_db( $option );
		if ( null === $before ) {
			return false;
		}
		$after                 = $before;
		$after['terminal_seq'] = (int) $seq;
		$written = Aura_Worker_Door_Log::write_option_where( $option, $after, $before );
		self::forget_held();
		return $written;
	}

	/**
	 * Record on the CLAIM that this site's engine has no named locks (Ruling
	 * P70), so the reconciler bounds it by the hard cap rather than by the
	 * ten-minute age rule — and rather than by asking an engine that might
	 * answer differently later.
	 *
	 * Best effort: a stamp that does not land leaves the row exactly as it was,
	 * and `claim_is_alive()` falls back to asking the engine, which is the
	 * pre-P70 behaviour.
	 *
	 * @param string $ref Hold ref.
	 * @return bool
	 */
	public static function mark_claim_unleasable( $ref ) {
		$option = self::CLAIMED . self::clean( $ref );
		$before = self::from_db( $option );
		if ( null === $before ) {
			return false;
		}
		$after          = $before;
		$after['lease'] = self::LEASE_UNSUPPORTED;
		$written = Aura_Worker_Door_Log::write_option_where( $option, $after, $before );
		self::forget_held();
		return $written;
	}

	/**
	 * Delete the claimed row and any held twin — versioned as ONE unit
	 * (Ruling S8, Codex round-4 P1 on #88): both deletes are a SINGLE
	 * logical release, and either one alone is enough to bump — a ref with
	 * only a claimed row, only a held row, or both, all release cleanly.
	 *
	 * RETURNS whether it actually committed (Ruling S35, Codex round-15 P1
	 * on #88). Every caller MUST check it: an approval is spent, or a claim
	 * counted settled, only once THIS call is known to have landed — never
	 * on the strength of having merely invoked it. A caller that ignored
	 * this treated `committed:false` (a lost SAVEPOINT, an unreadable
	 * session nonce with no durable witness, a failed version bump) exactly
	 * like success: a "definitive" refusal or success was reported to Aura
	 * while the row it claimed to have released sat there, still claimed,
	 * replayable behind an answer that said it never would be again — or,
	 * on the success path, a claimed row stranded with no way back into the
	 * queue after Aura was told the approval was fully spent.
	 *
	 * @param string $ref Ref.
	 * @return bool True once the release is durably committed.
	 */
	public static function release( $ref ) {
		$ref     = self::clean( $ref );
		$outcome = Aura_Worker_Door_Log::versioned(
			function () use ( $ref ) {
				$claimed_gone = (bool) delete_option( self::CLAIMED . $ref );
				$held_gone    = (bool) delete_option( self::HELD . $ref );
				return array(
					'mutated' => $claimed_gone || $held_gone,
					'result'  => null,
					// Ruling S11 (Codex round-5 P1 on #88): repeated by
					// versioned() after commit.
					'evict'   => array( self::CLAIMED . $ref, self::HELD . $ref, 'notoptions' ),
				);
			}
		);
		self::forget_held();
		return ! empty( $outcome['committed'] );
	}

	/**
	 * @param string $ref Ref.
	 * @return string rejected|already_claimed|not_held
	 */
	public static function reject( $ref ) {
		$ref = self::clean( $ref );
		if ( null !== self::get_claimed( $ref ) ) {
			return 'already_claimed';
		}
		if ( null === self::from_db( self::HELD . $ref ) ) {
			return 'not_held';
		}
		// The delete must remove ONE row, exactly as claim() demands of its
		// own delete (Codex round-8 P1): a replay that claimed between the
		// read above and this statement already moved the row, and a reject
		// reported on top of it would let Aura mark rejected an action whose
		// mutation is running. Ruling S8: versioned like every other fenced
		// delete here.
		return self::delete_versioned( self::HELD . $ref ) ? 'rejected' : 'already_claimed';
	}

	/**
	 * What `/status` reports: no inputs, no claimed twins, nothing expired.
	 *
	 * @return array[]
	 */
	public static function listing() {
		$out  = array();
		$now  = time();
		$held = self::held_rows();
		if ( null === $held ) {
			// Nothing to LIST is not the same as nothing HELD (Ruling P57), and
			// the caller must not read an empty list as an empty queue —
			// status_fragment() carries `held_unreadable: true` beside it.
			return array();
		}
		foreach ( $held as $ref => $row ) {
			if ( ! self::row_is_current( $row ) ) {
				continue; // another binding's queue (Ruling P58)
			}
			if ( null !== self::get_claimed( $ref ) ) {
				continue;
			}
			if ( strtotime( (string) ( $row['expires_at'] ?? '' ) ) <= $now ) {
				continue;
			}
			$out[] = array(
				'ref'        => $ref,
				'ability'    => $row['ability'] ?? '',
				'actor'      => $row['actor'] ?? array(),
				'touches'    => $row['touches'] ?? array(),
				'verdict'    => $row['verdict'] ?? 'none',
				'rule'       => $row['rule'] ?? null,
				'created_at' => $row['created_at'] ?? '',
			);
		}
		return $out;
	}

	/**
	 * Expire holds; remove a held row whose claimed twin exists (§3.7) — but
	 * only once that twin is STALE.
	 *
	 * claim() moves a hold by inserting the claimed twin and THEN deleting
	 * the held row, and requires that delete to remove exactly one row. Both
	 * rows therefore exist for a moment on every single claim. A sweep
	 * landing in that window deleted the held row itself; claim()'s own
	 * delete then removed 0 rows, read that as "a reject or the sweep decided
	 * this must not run", and backed out by deleting the claimed twin. Both
	 * rows gone: the operator's approval lost for ever, `not_held` on every
	 * later replay, and no record that anything was ever approved.
	 *
	 * So a held row whose twin was claimed less than $claim_stale_ms ago is
	 * left alone — it belongs to a replay that is mid-move. Past that bound
	 * the twin is the reconciler's (settle_stale_claim()), and this sweep
	 * finishing the replay's delete is exactly right. A twin whose
	 * `claimed_at` cannot be read is not evidence of freshness either, and is
	 * swept: the same rule the creation mutex is cleared by.
	 *
	 * @param int $now            Unix time.
	 * @param int $claim_stale_ms How old a claim must be before its held twin
	 *                            is the sweep's to remove (the reconciler's
	 *                            Aura_Worker_Elementor_Door::CLAIM_STALE_MS).
	 * @return int How many held rows were removed — the reconciler reports it.
	 */
	public static function sweep( $now, $claim_stale_ms ) {
		$gone = 0;
		$cut  = (int) $now - (int) floor( (int) $claim_stale_ms / 1000 );
		$held = self::held_rows();
		if ( null === $held ) {
			return 0; // unreadable ⇒ nothing to sweep (Ruling P57); never delete on a guess
		}
		// THE GENERATION THIS SWEEP JUDGES BY, READ ONCE AND RAW (Ruling P85).
		// The cached reader is populated at authentication, so a `/status`
		// request that authenticated under generation A and then paused while
		// an unbind and a connect installed B would call B's brand-new hold
		// foreign and DELETE it. A read may be a moment old; a delete may not.
		//
		// '' is "cannot establish" — the row is absent, or the read failed —
		// and the sweep deletes nothing on a guess, the same shape an
		// unreadable queue takes above.
		$current = Aura_Worker_Door_Log::binding_raw();
		if ( '' === $current ) {
			return 0;
		}
		foreach ( $held as $ref => $row ) {
			if ( ! self::row_is_current_against( $row, $current ) && null === self::get_claimed( $ref ) ) {
				// A departed binding's hold, with no replay mid-move (Ruling
				// P58). Fenced on the bytes just read, like every other
				// conditional delete here.
				if ( self::delete_held_fenced( $ref, $row ) ) {
					$gone++;
				}
				continue;
			}
			$claimed = self::get_claimed( $ref );
			if ( null !== $claimed ) {
				$at = strtotime( (string) ( isset( $claimed['claimed_at'] ) ? $claimed['claimed_at'] : '' ) );
				if ( false !== $at && $at > $cut ) {
					continue; // a replay mid-move: its own delete is still coming
				}
				// A stale claim beside a held row is a move that stopped
				// halfway, and there are TWO moves it could be (Ruling P41).
				// claim() inserts the claimed row then deletes the held one;
				// unclaim() inserts the held row then deletes the claimed one.
				// Assuming the first deleted the hold a replay was in the
				// middle of RESTORING: the sweep removed the hold, unclaim()'s
				// own claimed delete then succeeded, give_back() answered
				// `retry_later` — and the ref was held by nothing. The
				// operator's approval was gone.
				//
				// `restored_at` says which. Finish the unclaim instead, and do
				// not count it: nothing was swept, a move was completed.
				if ( self::finish_unclaim_in_flight( $ref, $row, $at ) ) {
					continue;
				}
				self::delete_versioned( self::HELD . $ref ); // the replay's own delete, retried — Ruling S8
				$gone++;
				continue;
			}
			if ( strtotime( (string) ( $row['expires_at'] ?? '' ) ) <= (int) $now ) {
				self::delete_versioned( self::HELD . $ref ); // Ruling S8
				$gone++;
			}
		}
		return $gone;
	}

	/**
	 * Is the move in flight an UNCLAIM, and if so, finish it (Ruling P41).
	 *
	 * The held row wins the comparison when it is the YOUNGER of the pair: a
	 * `restored_at` at or after the twin's `claimed_at` means the hold was put
	 * back after that claim was taken, so what is unfinished is the claimed
	 * row's deletion. A `claimed_at` that cannot be read is not evidence of
	 * anything, so a present `restored_at` decides — the same reading the
	 * freshness check above gives an unstamped claim.
	 *
	 * A held row with no `restored_at` was never restored by an unclaim, so
	 * the move can only be claim()'s and the caller's original rule stands.
	 *
	 * The delete is FENCED on the exact bytes read a statement earlier, like
	 * every other conditional delete in this class: a claimed row a racer
	 * replaced in between is a different row and must not be removed by this
	 * one. The held row is never touched here.
	 *
	 * @param string $ref        Ref.
	 * @param array  $held       The held row, as the sweep read it.
	 * @param int|false $claimed_at The twin's `claimed_at`, or false when unreadable.
	 * @return bool The move was an unclaim (finished, or already finished).
	 */
	private static function finish_unclaim_in_flight( $ref, array $held, $claimed_at ) {
		$restored = strtotime( (string) ( isset( $held['restored_at'] ) ? $held['restored_at'] : '' ) );
		if ( false === $restored ) {
			return false; // no unclaim ever restored this row: it is claim()'s move
		}
		if ( false !== $claimed_at && $restored < $claimed_at ) {
			return false; // restored BEFORE this claim was taken: the claim is the later move
		}
		$bytes = self::raw_bytes( self::CLAIMED . $ref );
		if ( null === $bytes ) {
			return true; // the unclaim's own delete already landed
		}
		// Ruling S8: versioned like every other fenced delete here — whether
		// or not it actually removes a row (a racer may already have), so
		// this always reports true regardless, exactly as before.
		self::delete_versioned( self::CLAIMED . $ref, $bytes );
		return true;
	}

	/**
	 * The MySQL named lock that IS this replay's execution lease (Ruling P52).
	 *
	 * Scoped by blog so two sites on one network never share a lease, and the
	 * ref is HASHED because MySQL caps a lock name at 64 characters and a
	 * `door_<uuid>` ref plus the prefix and blog id would overrun it.
	 *
	 * @param string $ref Hold ref.
	 * @return string
	 */
	public static function lease_name( $ref ) {
		return self::lease_name_for( self::LEASE_PREFIX, self::clean( $ref ) );
	}

	/**
	 * The same name builder for ANY leased subject (Ruling P56): a replay is
	 * named by its hold ref, an ordinary governed write by its log seq.
	 *
	 * @param string $prefix `aura_door_replay_` or `aura_door_seq_`.
	 * @param string $subject Ref or seq.
	 * @return string
	 */
	public static function lease_name_for( $prefix, $subject ) {
		$blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		return (string) $prefix . $blog . '_' . md5( (string) $subject );
	}

	/**
	 * The execution lease over one log SEQ — every governed write, not only a
	 * replay (Ruling P56).
	 *
	 * @param int $seq Log seq.
	 * @return string
	 */
	public static function seq_lease_name( $seq ) {
		return self::lease_name_for( self::SEQ_LEASE_PREFIX, (string) (int) $seq );
	}

	/**
	 * Take the seq lease, without waiting.
	 *
	 * @param int $seq Log seq.
	 * @return int|string|null 1 taken, 0 held elsewhere, self::LEASE_UNSUPPORTED
	 *                         on an engine without named locks, null on a
	 *                         FAILURE — which the caller refuses (Ruling P70).
	 */
	public static function take_seq_lease( $seq ) {
		return self::get_lock( self::seq_lease_name( $seq ) );
	}

	/**
	 * @param int $seq Log seq.
	 * @return void
	 */
	public static function release_seq_lease( $seq ) {
		self::release_lock_named( self::seq_lease_name( $seq ) );
	}

	/**
	 * @param int $seq Log seq.
	 * @return bool|null TRUE held, FALSE free, NULL unknown.
	 */
	public static function seq_lease_is_held( $seq ) {
		return self::lock_is_used( self::seq_lease_name( $seq ) );
	}

	/**
	 * Take the lease, without waiting.
	 *
	 * @param string $ref Hold ref.
	 * @return int|string|null 1 taken, 0 held by another connection,
	 *                         self::LEASE_UNSUPPORTED on an engine without
	 *                         named locks, null on a FAILURE (Ruling P70).
	 */
	public static function take_lease( $ref ) {
		return self::get_lock( self::lease_name( $ref ) );
	}

	/**
	 * GET_LOCK over one name. ONE implementation for every lease.
	 *
	 * @param string $name Lock name.
	 * @return int|null 1 taken, 0 held elsewhere, null unavailable.
	 */
	private static function get_lock( $name ) {
		global $wpdb;
		if ( false === self::$locks_supported ) {
			return self::LEASE_UNSUPPORTED; // asked once per request, answered from the memo
		}
		$wpdb->last_error = '';
		$got              = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );
		$err              = (string) $wpdb->last_error;
		if ( '' !== $err && self::error_says_no_locks( $err ) ) {
			self::$locks_supported = false;
			return self::LEASE_UNSUPPORTED;
		}
		if ( null === $got || '' !== $err ) {
			return null; // a FAILURE, not an engine without locks: the caller refuses
		}
		self::$locks_supported = true;
		return (int) $got;
	}

	/**
	 * Does this error say the ENGINE has no named locks, rather than that this
	 * statement failed (Ruling P70)?
	 *
	 * The two are opposite answers — an engine without locks is proceeded with
	 * under the hard cap, a failed statement refuses the write — and only the
	 * message tells them apart. MySQL-compatible engines that ship without
	 * `GET_LOCK` (SQLite integrations, some proxies and forks) answer with a
	 * missing-function error; anything else is a failure, which is the safe
	 * default because it refuses.
	 *
	 * @param string $err `$wpdb->last_error`.
	 * @return bool
	 */
	private static function error_says_no_locks( $err ) {
		$err = strtolower( (string) $err );
		return ( false !== strpos( $err, 'does not exist' ) && false !== strpos( $err, 'function' ) )
			|| false !== strpos( $err, 'no such function' )
			|| false !== strpos( $err, 'unknown function' )
			|| false !== strpos( $err, 'not supported' );
	}

	/** Test seam: forget whether this engine has named locks. */
	public static function forget_lock_support() {
		self::$locks_supported = null;
	}

	/**
	 * @param string $name Lock name.
	 * @return void
	 */
	private static function release_lock_named( $name ) {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}

	/**
	 * @param string $name Lock name.
	 * @return bool|null TRUE held, FALSE free, NULL unknown.
	 */
	private static function lock_is_used( $name ) {
		global $wpdb;
		if ( false === self::$locks_supported ) {
			return null; // no locks here: unknown, and the hard cap bounds it
		}
		$wpdb->last_error = '';
		$who              = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $name ) );
		$err              = (string) $wpdb->last_error;
		if ( '' !== $err ) {
			if ( self::error_says_no_locks( $err ) ) {
				self::$locks_supported = false;
			}
			return null;
		}
		return null !== $who;
	}

	/**
	 * Release it. Best effort — the connection closing releases it anyway,
	 * which is the property this whole mechanism rests on.
	 *
	 * @param string $ref Hold ref.
	 * @return void
	 */
	public static function release_lease( $ref ) {
		self::release_lock_named( self::lease_name( $ref ) );
	}

	/**
	 * Is somebody holding this ref's lease right now?
	 *
	 * IS_USED_LOCK answers the holding connection's id, or NULL when the lock
	 * is free — and NULL is also what a broken statement answers, so the error
	 * flag is what tells them apart.
	 *
	 * @param string $ref Hold ref.
	 * @return bool|null TRUE held, FALSE free, NULL unknown (unreadable or unsupported).
	 */
	public static function lease_is_held( $ref ) {
		return self::lock_is_used( self::lease_name( $ref ) );
	}

	/**
	 * @param int $ms Age.
	 * @return array[] claimed rows older than $ms, keyed by ref.
	 */
	public static function stale_claims( $ms ) {
		$cut = time() - (int) floor( $ms / 1000 );
		$out = array();
		foreach ( (array) self::rows( self::CLAIMED ) as $ref => $row ) { // null ⇒ nothing stale (Ruling P57)
			if ( strtotime( (string) ( $row['claimed_at'] ?? '' ) ) <= $cut ) {
				$out[ $ref ] = $row;
			}
		}
		return $out;
	}

	/**
	 * Is the request that took this claim still alive? THE predicate — one
	 * definition, and everything that asks about a claim's liveness asks it
	 * (Ruling P54).
	 *
	 * The EXECUTION LEASE decides first (Ruling P52): a named lock that is held
	 * belongs to a live database connection, so the request is running however
	 * long it has been at it. Only when no lease is held does age decide. An
	 * answer the server could not give is UNKNOWN and falls back to the hard
	 * cap, which also bounds a lease stranded on a persistent connection.
	 *
	 * @param string $ref Hold ref.
	 * @param array  $row The claimed row.
	 * @param int    $cut Claims stamped at or before this are old.
	 * @param int    $cap Claims stamped at or before this are old even when the lease is unknown.
	 * @return bool
	 */
	private static function claim_is_alive( $ref, array $row, $cut, $cap ) {
		$at    = strtotime( (string) ( isset( $row['claimed_at'] ) ? $row['claimed_at'] : '' ) );
		$lease = self::lease_is_held( (string) $ref );
		if ( true === $lease ) {
			// HELD, BUT NOT FOR EVER (Ruling P84). A named lock lives as long
			// as the database CONNECTION that took it, and a persistent
			// connection outlives the PHP request that borrowed it — so a
			// request killed mid-callback can leave its lock reported held
			// with nobody behind it. `true` used to mean running whatever the
			// age, so such a claim held a queue slot and was never reconciled,
			// permanently. The hard cap bounds it: a lease is evidence of life
			// for LEASE_HARD_CAP_S, and after that only the row's age speaks.
			return false === $at || $at > $cap;
		}
		if ( self::LEASE_UNSUPPORTED === ( isset( $row['lease'] ) ? (string) $row['lease'] : '' ) ) {
			// Claimed on an engine that HAS no named locks (Ruling P70), so
			// this row never had a lease to lose. The hard cap bounds it —
			// never the ten-minute age rule, which would settle a callback
			// still running, and never "forever", which is the blindness the
			// stamp exists to avoid. Read from the ROW, not from asking the
			// engine again: a site that gains locks between the claim and this
			// sweep would otherwise be told the never-taken lock is free.
			return false === $at || $at > $cap;
		}
		if ( null === $lease ) {
			return false === $at || $at > $cap; // unknown ⇒ alive under the hard cap
		}
		return false === $at || $at > $cut; // no lease: the age rule
	}

	/**
	 * The age-stale claims, split into the ones nobody is running any more and
	 * the ones somebody still is (Ruling P54).
	 *
	 * Together they are exactly what `stale_claims()` returns, which is the
	 * point: one predicate decides which side a row falls on, so the
	 * reconciler cannot settle a claim `/status` is calling running, and
	 * `/status` cannot call one interrupted that the reconciler is leaving
	 * alone. A claim younger than the bound is in neither — it is simply not
	 * old enough to be anybody's business yet.
	 *
	 * @param int $ms Age bound in milliseconds.
	 * @return array{ stale: array[], running: array[] } Both keyed by ref.
	 */
	private static function partition_stale_claims( $ms ) {
		$cut  = time() - (int) floor( (int) $ms / 1000 );
		$cap  = time() - self::LEASE_HARD_CAP_S;
		$out  = array( 'stale' => array(), 'running' => array() );
		$rows = self::rows( self::CLAIMED );
		if ( null === $rows ) {
			// Ruling S44 (Codex round-18 P2 on #88): a transient failure
			// here used to cast to `(array) null` — the SAME empty set a
			// genuinely quiet claimed queue answers — and neither
			// stale_unleased_claims() nor running_claims() (both callers
			// of this method) ever saw the difference. Sticky for the
			// rest of this attempt: see the property's own docblock.
			self::$claimed_queue_unreadable_this_attempt = true;
		}
		foreach ( (array) $rows as $ref => $row ) { // null ⇒ nothing stale (Ruling P57) — see also claimed_queue_was_unreadable_this_attempt()
			if ( ! ( strtotime( (string) ( isset( $row['claimed_at'] ) ? $row['claimed_at'] : '' ) ) <= $cut ) ) {
				continue; // young: not stale, and not "running" either — just in progress
			}
			$side              = self::claim_is_alive( (string) $ref, (array) $row, $cut, $cap ) ? 'running' : 'stale';
			$out[ $side ][ $ref ] = $row;
		}
		return $out;
	}

	/**
	 * Whether ANY `partition_stale_claims()` call this attempt (via
	 * `stale_unleased_claims()`/`running_claims()`) failed to prove its
	 * read of the claimed queue (Ruling S44, Codex round-18 P2 on #88).
	 * Checked by the caller once, immediately after both have run.
	 *
	 * @return bool
	 */
	public static function claimed_queue_was_unreadable_this_attempt() {
		return self::$claimed_queue_unreadable_this_attempt;
	}

	/**
	 * Reset at the top of EVERY `status_fragment()` attempt — both the
	 * first and any retry — mirroring
	 * `Aura_Worker_Door_Log::reset_floor_unreadable_for_attempt()` (Ruling
	 * S38): a failure from a PREVIOUS attempt or a previous request must
	 * never leak into this one.
	 */
	public static function reset_claimed_queue_unreadable_for_attempt() {
		self::$claimed_queue_unreadable_this_attempt = false;
	}

	/**
	 * Claims old enough to settle AND not held by a live request (Ruling P54).
	 *
	 * This is what the reconciler acts on and what `/status` reports as
	 * `interrupted`. `stale_claims()` — age alone — is kept for callers that
	 * genuinely mean "old", and is deliberately NOT what either of those two
	 * uses any more: an approved callback may legitimately run for longer than
	 * the bound, and settling or reporting it as interrupted was wrong in both
	 * places for the same reason.
	 *
	 * @param int $ms Age bound in milliseconds.
	 * @return array[] Keyed by ref.
	 */
	public static function stale_unleased_claims( $ms ) {
		$parts = self::partition_stale_claims( $ms );
		return $parts['stale'];
	}

	/**
	 * Claims past the age bound whose request is demonstrably STILL RUNNING —
	 * reported so the operator sees them, labelled honestly (Ruling P54).
	 *
	 * @param int $ms Age bound in milliseconds.
	 * @return array[] Keyed by ref.
	 */
	public static function running_claims( $ms ) {
		$parts = self::partition_stale_claims( $ms );
		return $parts['running'];
	}

	/**
	 * Delete every held row that has run out of time and has NO claimed twin
	 * — the sweep's own rule, judged by the sweep's own predicate.
	 *
	 * A twin means a replay owns that ref and its own delete is still
	 * coming; removing the held row underneath it is the race sweep() and
	 * get_held() both refuse to enter (round-9). Called under the hold lock,
	 * so no hold() is racing this one.
	 *
	 * @return int How many were deleted.
	 */
	private static function purge_expired() {
		$gone = 0;
		$now  = time();
		$held = self::held_rows();
		if ( null === $held ) {
			return 0; // unreadable ⇒ nothing to purge (Ruling P57)
		}
		foreach ( $held as $ref => $row ) {
			if ( self::is_expired( $row, $now ) && null === self::get_claimed( $ref ) ) {
				self::delete_versioned( self::HELD . $ref ); // Ruling S8
				$gone++;
			}
		}
		return $gone;
	}

	/**
	 * Slots in use: every ref with a CLAIMED row, plus every ref whose held
	 * row is still live, counted once.
	 *
	 * A claim MOVES the row, so counting only held rows let interrupted
	 * replays accumulate beside another fifty holds (Codex round-8 P2) — and
	 * counting EVERY held row charged a slot for holds the queue no longer
	 * honours (Ruling P21). A claimed ref counts however old it is: the row
	 * is a replay's, and only the sweep decides when it goes.
	 *
	 * NULL when either read failed (Ruling P57): a queue whose size cannot be
	 * established is not a queue with room in it.
	 *
	 * @return int|null
	 */
	public static function count() {
		$claimed       = self::rows( self::CLAIMED );
		// Ruling S46 (Codex round-19, S45 class): the SAME non-expired
		// held identity status_fragment()'s own transition fold uses
		// (held_identity()'s own docblock) — never a second, independent
		// expiry scan that could in principle disagree with it.
		$held_identity = self::held_identity();
		if ( null === $claimed || null === $held_identity ) {
			return null;
		}
		$refs = array();
		foreach ( $claimed as $ref => $row ) {
			if ( self::row_is_current( $row ) ) {
				$refs[] = $ref;
			}
		}
		// Only THIS binding's rows charge a slot (Ruling P58) — already
		// true of $held_identity, which only ever names rows
		// row_is_current() itself accepted.
		foreach ( $held_identity as $ref ) {
			if ( ! in_array( $ref, $refs, true ) ) {
				$refs[] = $ref;
			}
		}
		return count( array_unique( $refs ) );
	}

	/**
	 * Delete one held row while it still carries the bytes just read.
	 *
	 * @param string $ref Ref.
	 * @param array  $row The row as it was read.
	 * @return bool One row removed.
	 */
	private static function delete_held_fenced( $ref, array $row ) {
		$option = self::HELD . self::clean( $ref );
		// Ruling S8: versioned like every other fenced delete here.
		return self::delete_versioned( $option, maybe_serialize( $row ) );
	}

	/**
	 * Is this row THIS binding's (Ruling P58)?
	 *
	 * A hold is a stored WordPress action with the actor to run it as, so only
	 * the client that queued it may be shown it or given it. Rather than
	 * DELETING a departed client's queue — six review rounds' worth of races
	 * between that delete and a replay already running — every row records the
	 * generation it was queued under and every reader compares it. A row from
	 * another generation is simply invisible: not listed, not claimable, not
	 * counted against the cap, and swept when the reconciler next runs.
	 *
	 * ONE question (Ruling P75): is the row's generation the current one? It
	 * used to be two — the generation AND whether its record still described
	 * the identity the site was live under — because a connect could publish a
	 * new client over a live binding and the generation would not move until
	 * the rotation landed. A connect cannot do that any more: meeting a live
	 * foreign binding it refuses (`aura_site_bound`) and writes nothing. A
	 * rebind is an unbind, which rotates to `unbound`, followed by a connect —
	 * so the identity cannot change while a generation stands, and generation
	 * equality is the whole test.
	 *
	 * A row with NO binding is NOT ours (Ruling P72): nothing predates the
	 * stamp, so an empty one can only have come from a lazy-mint race.
	 *
	 * @param array $row The held or claimed row.
	 * @return bool
	 */
	public static function row_is_current( array $row ) {
		return self::row_is_current_against( $row, null );
	}

	/**
	 * The same question, asked against a generation the CALLER established —
	 * for a decision that DELETES (Ruling P85).
	 *
	 * `row_is_current()` reads the binding record through the option cache, and
	 * that cache is populated at AUTHENTICATION. A `/status` request that
	 * authenticated under generation A and then paused while an unbind and a
	 * connect installed B would judge B's brand-new hold against A, call it
	 * foreign, and delete it — permanently, and for the client that had just
	 * queued it.
	 *
	 * A read is allowed to be a moment old; a DELETE is not. So the sweep reads
	 * the generation itself, once, straight from the database, and passes it
	 * here. Null means "use the cached reader", which is what every
	 * non-destructive caller wants.
	 *
	 * @param array       $row     The held or claimed row.
	 * @param string|null $current The generation to judge against, or null for
	 *                             the cached reader.
	 * @return bool
	 */
	public static function row_is_current_against( array $row, $current ) {
		$was = (string) ( isset( $row['binding'] ) ? $row['binding'] : '' );
		if ( '' === $was ) {
			// NEVER CURRENT (Ruling P72). No hold predates the generation —
			// 2.16 introduces the door and the stamp together — so an empty
			// stamp can only have come from a lazy-mint race that read its own
			// negative cache. Treating it as legacy made it current for ever,
			// and after a rebind the replacement client could claim and run the
			// previous client's stored mutation. The sweep removes such a row
			// like any other foreign one.
			return false;
		}
		if ( null !== $current ) {
			return '' !== (string) $current && $was === (string) $current;
		}
		return Aura_Worker_Door_Log::generation_is_live( $was );
	}

	/**
	 * Could the held queue not be read on this request (Ruling P57)?
	 *
	 * `listing()` answers `[]` for an unreadable queue, which is the only
	 * shape it can answer — but an empty list and an empty QUEUE are different
	 * facts, so `/status` reports this beside it and Aura is never told the
	 * queue is empty when nobody knows.
	 *
	 * @return bool
	 */
	public static function queue_unreadable() {
		return null === self::held_rows();
	}

	/**
	 * The CURRENT-binding, NOT-EXPIRED held set's own IDENTITY — sorted
	 * refs, from the SAME memoised held_rows() listing()/count() already
	 * share (Ruling P71) — for status_fragment()'s own transition fold
	 * (Ruling S46, Codex round-19, S45 class): a hold ageing past its own
	 * `expires_at` mutates nothing and bumps no version on its own, so a
	 * poll served right after the crossing used to carry the SAME
	 * observation it served right before — Aura's strictly-greater
	 * comparison then hid the row leaving `held`/`held_count` entirely.
	 *
	 * Deliberately the BROADER set `count()`'s own HELD half filters to —
	 * never `listing()`'s narrower one, which additionally excludes a
	 * claimed twin — because a ref's CLAIMED status changes only through a
	 * real, already-versioned mutation (claim()'s own versioned() bump);
	 * expiry is the one fact here that changes silently, and it is the
	 * SAME fact whether or not the row happens to be claimed too.
	 *
	 * @return string[]|null Null when the held queue could not be read.
	 */
	public static function held_identity() {
		$held = self::held_rows();
		if ( null === $held ) {
			return null;
		}
		$now  = time();
		$refs = array();
		foreach ( $held as $ref => $row ) {
			if ( ! self::row_is_current( $row ) ) {
				continue;
			}
			if ( self::is_expired( $row, $now ) ) {
				continue;
			}
			$refs[] = (string) $ref;
		}
		sort( $refs, SORT_STRING );
		return $refs;
	}

	/**
	 * Every row under a prefix, from the database, keyed by ref — or NULL when
	 * that could not be read (Ruling P57).
	 *
	 * @param string $prefix HELD or CLAIMED.
	 * @return array<string,array>|null
	 */
	/**
	 * The held queue, read ONCE per request (Ruling P71).
	 *
	 * `listing()` and `queue_unreadable()` each issued their own query, so a
	 * read that failed for one and succeeded for the other answered `held: []`
	 * beside `held_unreadable: false` — the exact pair Ruling P57 exists to
	 * make impossible, and Aura reads it as "the queue is empty". `count()`'s
	 * held half is the third reader with the same exposure.
	 *
	 * So all three derive from ONE result, memoised for the request and
	 * dropped by every held write this process makes. A null memo is an ANSWER
	 * (the read failed) and is remembered as one: re-reading on the next
	 * caller is what let the two disagree.
	 *
	 * @return array<string,array>|null
	 */
	private static function held_rows() {
		if ( ! self::$held_read ) {
			self::$held_rows = self::rows( self::HELD );
			self::$held_read = true;
		}
		return self::$held_rows;
	}

	/**
	 * Drop the memo — called by every held write in this process, so a reader
	 * after a write never sees the queue as it was before it.
	 *
	 * PURE CACHE INVALIDATION AS OF RULING S8 (Codex round-4 P1 on #88). It
	 * used to ALSO bump the door version here (Ruling S6) — a single choke
	 * point catching every held/claimed write, deletes included, since every
	 * one of them already called this uniformly. That bump was a SEPARATE
	 * statement from whatever write preceded it, which is exactly the gap
	 * S8 closes: a poll landing between the write and this call could see
	 * the new state under the OLD version. The fenced deletes this class
	 * issues now bump from INSIDE the same transaction as their own DELETE,
	 * through `delete_versioned()` below; the insert/update mutations bump
	 * from inside `Aura_Worker_Door_Log::insert_unique()`/
	 * `write_option_where()` the same way. This method is called from
	 * BOTH kinds of site and must stay safe to call from inside an open
	 * transaction OR outside one — which pure cache invalidation trivially
	 * is, and a bump here no longer would be.
	 *
	 * @return void
	 */
	public static function forget_held() {
		self::$held_rows = null;
		self::$held_read = false;
	}

	/**
	 * A single fenced DELETE (or an unconditional `delete_option()`),
	 * versioned in one transaction with the door-version bump (Ruling S8,
	 * Codex round-4 P1 on #88) — the "fenced deletes" choke point Ruling S6
	 * named but could not close on its own, since a raw DELETE bypasses
	 * both `Aura_Worker_Door_Log::insert_unique()` and `write_option_where()`.
	 *
	 * @param string      $name  Option name.
	 * @param string|null $fence The exact serialised bytes the row must
	 *                           still hold — a real fenced delete, the shape
	 *                           every other mutex/row delete in the door
	 *                           uses. Null for an unconditional
	 *                           `delete_option()`, used only where no race
	 *                           is possible (see call sites).
	 * @return bool|null One row removed; null — UNKNOWN, never the same as
	 *         false (Ruling S51, Codex round-20 P1 on #88) — when
	 *         versioned() could not prove whether this delete's own commit
	 *         landed. Most callers here already only ever ACT on a strict
	 *         `true`, so a caller that does not explicitly check for null
	 *         still fails exactly as safely as it did for a proven `false`
	 *         (a falsy value either way) — it simply does not get told the
	 *         difference. `claim()` is the one caller for whom that
	 *         difference matters and checks for it explicitly.
	 */
	private static function delete_versioned( $name, $fence = null ) {
		$outcome = Aura_Worker_Door_Log::versioned(
			function () use ( $name, $fence ) {
				global $wpdb;
				$wpdb->last_error = '';
				// NEVER delete_option() for the no-fence form (Ruling S8):
				// its return does not reliably say whether a row existed to
				// remove — this class's own stub models it as always true,
				// and even real WordPress core's version is not something
				// this method's callers (claim()/reject()/etc.) may depend
				// on for "did this actually remove one row", which several
				// of them fence their own next step on. A raw
				// DELETE ... WHERE option_name = %s is the exact shape
				// those callers always issued, fence or not.
				if ( null === $fence ) {
					$gone = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $name )
					);
				} else {
					$gone = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$wpdb->prepare(
							"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
							$name,
							$fence
						)
					);
				}
				wp_cache_delete( $name, 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				$won = 1 === (int) $gone;
				return array(
					'mutated' => $won,
					'result'  => $won,
					// Ruling S11 (Codex round-5 P1 on #88): repeated by
					// versioned() after commit.
					'evict'   => array( $name, 'notoptions' ),
				);
			}
		);
		self::forget_held(); // cache-only now (see its own docblock) — safe whether or not the delete above landed
		if ( null === $outcome['committed'] ) {
			return null; // Ruling S51: UNKNOWN, not a proven miss.
		}
		return $outcome['committed'] && $outcome['result'];
	}

	private static function rows( $prefix ) {
		global $wpdb;
		$like = $wpdb->esc_like( $prefix ) . '%';
		// ARRAY_A, matching Aura_Worker_Rules::sweep_options()'s identical
		// SELECT — $wpdb::OBJECT is the default and would otherwise hand back
		// stdClass rows this class never asks for.
		$wpdb->last_error = '';
		$rows             = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $like ), ARRAY_A );
		// AN UNREADABLE SET IS NOT AN EMPTY ONE (Ruling P57). wpdb answers its
		// CLEARED last_result — an empty array — for a statement that failed,
		// so the error flag is the only tell, and casting it to `array()` read
		// the whole queue as absent: count() saw a queue below capacity and
		// hold() admitted past CAP, over and over, for as long as the read
		// kept failing.
		if ( null === $rows || false === $rows || '' !== (string) $wpdb->last_error ) {
			return null;
		}
		$out = array();
		foreach ( (array) $rows as $r ) {
			$val = maybe_unserialize( $r['option_value'] );
			if ( is_array( $val ) ) {
				$out[ substr( $r['option_name'], strlen( $prefix ) ) ] = $val;
			}
		}
		return $out;
	}

	/**
	 * UNREADABLE IS NOT ABSENT (Ruling S37, Codex round-15 class sweep on
	 * #88): a driver failure and a genuinely missing row both used to
	 * answer `null` here — indistinguishable to a caller like `claim()`,
	 * which decided "not held" (a WP_Error the operator's approval never
	 * comes back from) on either. The array|null contract stays exactly
	 * as it was for every existing caller; `self::$option_read_unreadable`
	 * carries the distinction ALONGSIDE it for the callers that must act
	 * on ambiguity differently from absence — see `read_was_unreadable()`.
	 *
	 * @param string $option Option. @return array|null the DATABASE's row.
	 */
	private static function from_db( $option ) {
		global $wpdb;
		self::$option_read_unreadable = false;
		$wpdb->last_error              = '';
		$raw                            = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option ) );
		if ( '' !== (string) $wpdb->last_error ) {
			self::$option_read_unreadable = true;
			return null;
		}
		if ( null === $raw ) {
			return null;
		}
		$val = maybe_unserialize( $raw );
		return is_array( $val ) ? $val : null;
	}

	/**
	 * Whether the MOST RECENT `from_db()`/`raw_bytes()` call could not
	 * prove its read, rather than finding the row genuinely absent (Ruling
	 * S37, Codex round-15 class sweep on #88). The caller must read this
	 * immediately after — before anything else in this same request can
	 * issue a second read and overwrite it.
	 *
	 * @return bool
	 */
	public static function read_was_unreadable() {
		return self::$option_read_unreadable;
	}

	/**
	 * The DATABASE's raw, still-serialized bytes for one option — never this
	 * request's option cache, and never unserialized. take_lock() fences its
	 * stale-lock delete on exactly these bytes (compared byte-for-byte, the
	 * same way write_option_where()'s UPDATE compares its `$before`), so the
	 * fence can only match the row this call actually read, never a fresher
	 * one a racer already installed.
	 *
	 * UNREADABLE IS NOT ABSENT (Ruling S37, Codex round-15 class sweep on
	 * #88): shares `self::$option_read_unreadable`/`read_was_unreadable()`
	 * with `from_db()` — see that method's own docblock. `take_lock()`
	 * consults it before ever treating a null answer here as "the row
	 * vanished".
	 *
	 * @param string $option Option.
	 * @return string|null
	 */
	private static function raw_bytes( $option ) {
		global $wpdb;
		self::$option_read_unreadable = false;
		$wpdb->last_error              = '';
		$raw                            = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option ) );
		if ( '' !== (string) $wpdb->last_error ) {
			self::$option_read_unreadable = true;
			return null;
		}
		return null === $raw ? null : (string) $raw;
	}

	/** @param string $ref Ref. @return string */
	private static function clean( $ref ) {
		return preg_replace( '/[^a-z0-9_-]/', '', (string) $ref );
	}

	/** @return WP_Error */
	private static function not_held() {
		return new WP_Error( 'not_held', 'No held call with that reference.', array( 'status' => 404 ) );
	}

	/**
	 * Ruling S51 (Codex round-20 P1 on #88): the answer for an insert whose
	 * OWN commit could not be proven one way or the other — never `not_held()`
	 * (a proven, permanent 404 an unproven maybe is not) and never a bare
	 * retry (which drops the one fact Aura needs: this site's own PREVIOUS
	 * attempt may already have landed, so retrying blind risks a caller
	 * acting twice on what it believes is a fresh admission). `may_have_run`
	 * in the error data is the flag every such caller carries this signal
	 * through with.
	 *
	 * @return WP_Error
	 */
	private static function retry_may_have_run() {
		return new WP_Error(
			'aura_hold_failed',
			'This site could not prove whether the previous attempt landed; retry.',
			array( 'status' => 503, 'retry_after' => 5, 'may_have_run' => true )
		);
	}
}
