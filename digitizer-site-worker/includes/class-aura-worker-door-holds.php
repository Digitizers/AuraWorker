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

	const HELD    = 'aura_worker_door_held_';
	const CLAIMED = 'aura_worker_door_claimed_';
	const TTL_S   = 604800;
	const CAP     = 50;
	const LOCK    = 'aura_worker_door_hold_lock';
	const LOCK_S  = 30;
	/** Extra seconds a wipe waits past LOCK_S, by when any holder is stale (Ruling P46). */
	const WIPE_GRACE_S = 3;
	/** How long a wipe sleeps between attempts on a busy lock. */
	const WIPE_WAIT_US = 500000;

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
		if ( self::count() >= self::CAP ) {
			return new WP_Error( 'aura_hold_queue_full', 'Aura\'s approval queue for this site is full (50 held calls); ask the operator to act on it.', array( 'status' => 503 ) );
		}
		$ref = 'door_' . wp_generate_uuid4();
		$now = time();
		$row = array(
			'ref'        => $ref,
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
		Aura_Worker_Door_Log::epoch();
		if ( ! Aura_Worker_Door_Log::insert_unique( self::HELD . $ref, $row ) ) {
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
		if ( self::is_expired( $row, time() ) ) {
			if ( null === self::get_claimed( $ref ) ) {
				delete_option( self::HELD . $ref );
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
		global $wpdb;
		$gone = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				self::HELD . $ref,
				$bytes
			)
		);
		wp_cache_delete( self::HELD . $ref, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return 1 === (int) $gone ? $row : null;
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
		return Aura_Worker_Door_Log::write_option_where( $option, $after, $before );
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
		return Aura_Worker_Door_Log::write_option_where( $option, $after, $before );
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
		return Aura_Worker_Door_Log::write_option_where( $option, $after, $before );
	}

	/**
	 * Claim by MOVE: insert the claimed twin, then delete the held row and
	 * require that delete to remove one row.
	 *
	 * @param string $ref Ref.
	 * @return array|WP_Error The claimed entry, or `not_held`.
	 */
	public static function claim( $ref ) {
		// UNDER THE HOLD LOCK (Ruling P47). Only admission used to take this
		// mutex, so a claim could complete its move in the window a
		// changed-client connect's (or an unbind's) wipe was deleting rows in —
		// and the replay it belongs to then ran on into the callback,
		// recreating door state while executing the DEPARTED client's stored
		// mutation under the replacement binding. A lock that cannot be taken
		// answers the lost-race `not_held` this method already has: Aura
		// retries, and finds out what happened from the hold list.
		$token = self::take_lock();
		if ( false === $token ) {
			return self::not_held();
		}
		try {
			return self::claim_locked( $ref );
		} finally {
			self::release_lock( $token );
		}
	}

	/** The body of claim(), run only while the lock is held. */
	private static function claim_locked( $ref ) {
		global $wpdb;
		$ref  = self::clean( $ref );
		$held = self::from_db( self::HELD . $ref );
		if ( null === $held ) {
			return self::not_held();
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
		// The BINDING this claim belongs to (Rulings P47/P51). ONLY a wipe
		// deletes this value, and the next binding mints a fresh one, so it
		// can never be equal across a rebind — which is exactly what the
		// wrapper re-reads before the callback to prove the site is still the
		// one that approved this call. Deliberately NOT the log epoch: Aura
		// may rotate that legitimately through `/door/rotate` on a rewind, and
		// a rotation is not a rebind.
		$claimed['binding']    = Aura_Worker_Door_Log::binding();
		if ( ! Aura_Worker_Door_Log::insert_unique( self::CLAIMED . $ref, $claimed ) ) {
			return self::not_held(); // already claimed
		}
		$wpdb->last_error = '';
		$gone             = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::HELD . $ref ) );
		wp_cache_delete( self::HELD . $ref, 'options' );
		if ( 1 !== (int) $gone ) {
			// A reject or the sweep won the race: the entry we claimed from
			// no longer exists. Back out; nothing runs.
			delete_option( self::CLAIMED . $ref );
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
		// UNDER THE LOCK as well (Ruling P47), for symmetry with the wipe
		// rather than with claim(): unclaim INSERTS a held row, and a wipe that
		// has already run its held-prefix delete would otherwise find one
		// afterwards — the same shape as the claim race, in the other
		// direction. A lock it cannot take answers false, which give_back()
		// already reads correctly: the hold is not back, so the approval is
		// reported as not retryable rather than promised.
		$token = self::take_lock();
		if ( false === $token ) {
			return false;
		}
		try {
			return self::unclaim_locked( $ref );
		} finally {
			self::release_lock( $token );
		}
	}

	/** The body of unclaim(), run only while the lock is held. */
	private static function unclaim_locked( $ref ) {
		global $wpdb;
		$ref     = self::clean( $ref );
		$claimed = self::from_db( self::CLAIMED . $ref );
		if ( null === $claimed ) {
			return false;
		}
		$held = $claimed;
		unset( $held['claimed_at'], $held['terminal_seq'], $held['binding'] );
		// The witness the sweep reads to tell this move from claim()'s
		// (Ruling P41). Stamped BEFORE the insert, so a row that exists always
		// carries it.
		$held['restored_at'] = gmdate( 'c' );
		if ( ! Aura_Worker_Door_Log::insert_unique( self::HELD . $ref, $held ) ) {
			return false; // a held row is already there — the claimed row stands
		}
		$wpdb->last_error = '';
		$gone             = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::CLAIMED . $ref ) );
		wp_cache_delete( self::CLAIMED . $ref, 'options' );
		return 1 === (int) $gone;
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
		return Aura_Worker_Door_Log::write_option_where( $option, $after, $before );
	}

	/** Delete the claimed row and any held twin. @param string $ref Ref. */
	public static function release( $ref ) {
		$ref = self::clean( $ref );
		delete_option( self::CLAIMED . $ref );
		delete_option( self::HELD . $ref );
	}

	/**
	 * @param string $ref Ref.
	 * @return string rejected|already_claimed|not_held
	 */
	public static function reject( $ref ) {
		global $wpdb;
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
		// mutation is running.
		$wpdb->last_error = '';
		$gone             = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::HELD . $ref ) );
		wp_cache_delete( self::HELD . $ref, 'options' );
		return 1 === (int) $gone ? 'rejected' : 'already_claimed';
	}

	/**
	 * What `/status` reports: no inputs, no claimed twins, nothing expired.
	 *
	 * @return array[]
	 */
	public static function listing() {
		$out = array();
		$now = time();
		foreach ( self::rows( self::HELD ) as $ref => $row ) {
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
		foreach ( self::rows( self::HELD ) as $ref => $row ) {
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
				delete_option( self::HELD . $ref ); // the replay's own delete, retried
				$gone++;
				continue;
			}
			if ( strtotime( (string) ( $row['expires_at'] ?? '' ) ) <= (int) $now ) {
				delete_option( self::HELD . $ref );
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
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				self::CLAIMED . $ref,
				$bytes
			)
		);
		wp_cache_delete( self::CLAIMED . $ref, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return true;
	}

	/**
	 * Remove this binding's whole QUEUE on unbind, and the log with it
	 * (Ruling P44).
	 *
	 * A hold is a stored WordPress action — an ability, its input, and the
	 * ACTOR to run it as — waiting for somebody to approve it. It outlived the
	 * binding that created it: `Aura_Worker_Unbind::cleanup()` removed the
	 * dashboard options, the ruleset, the grant key and the token, but not
	 * this. A site later connected to a DIFFERENT Aura client was served the
	 * departed client's holds through `/status` and could approve one through
	 * `elementor_replay_ability`, running the old client's input as the old
	 * client's actor. So an unbind takes the queue.
	 *
	 * Under the hold LOCK, because a hold() racing the wipe would otherwise
	 * insert into a queue that is being emptied — the same mutex hold() itself
	 * serialises on. The lock row is released, never deleted, by
	 * release_lock()'s own fence.
	 *
	 * KEPT deliberately: the snapshot envelopes (this site's own content
	 * history, blog-scoped and grant-gated, restorable by whoever the site is
	 * bound to next) and the door's 30-day counter buckets (this site's audit
	 * history). Neither is transactional state of the departed binding.
	 *
	 * @param string $claim The site-claim option name.
	 * @param string $fence This caller's claim fence.
	 * @return bool TRUE only when every delete ran under a lock this call held.
	 */
	public static function wipe( $claim, $fence ) {
		global $wpdb;
		// ONLY UNDER A LOCK THIS CALL ACTUALLY TOOK (Ruling P46). take_lock()
		// answers false when another request owns the mutex, and entering the
		// deletes anyway was worse than not wiping at all: the old binding's
		// hold() resumes inside hold_locked() and INSERTS its held row after
		// the prefix deletes have run, so a changed-client reconnect finished
		// with a departed client's stored mutation sitting in the new
		// binding's queue, visible through `/status` and replayable.
		$token = self::take_wipe_lock();
		if ( false === $token ) {
			return false; // nothing deleted; the caller decides what that means
		}
		try {
			Aura_Worker_Rules::delete_options_like_if_claimed( $wpdb->esc_like( self::HELD ) . '%', $claim, $fence );
			Aura_Worker_Rules::delete_options_like_if_claimed( $wpdb->esc_like( self::CLAIMED ) . '%', $claim, $fence );
			// The log is emptied inside the same lock: a hold admitted between
			// the two would otherwise leave an entry for a hold that is gone.
			Aura_Worker_Door_Log::wipe( $claim, $fence );
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			return true;
		} finally {
			self::release_lock( $token );
		}
	}

	/**
	 * take_lock() with the patience a wipe can afford (Ruling P46).
	 *
	 * hold()'s own three tries are right for a WRITE that a caller can simply
	 * retry — but a wipe that gives up silently leaves the departed client's
	 * approvals in the new binding's queue, so it waits instead. The budget is
	 * LOCK_S plus a small grace: any holder older than LOCK_S is stale by
	 * take_lock()'s own rule and gets taken over, so a live holder is the only
	 * thing that can outlast this, and a live holder finishes in milliseconds.
	 *
	 * The suite must not sleep for half a minute to prove a busy lock, so the
	 * wait is skippable through `$GLOBALS['_sa_door_wipe_no_wait']` — read
	 * only, never written by production code, and the tests' bootstrap turns
	 * it ON by default so no test can sleep by accident.
	 *
	 * @return string|false The lock's fence value, or false.
	 */
	private static function take_wipe_lock() {
		$deadline = microtime( true ) + (float) ( self::LOCK_S + self::WIPE_GRACE_S );
		while ( true ) {
			$token = self::take_lock();
			if ( false !== $token ) {
				return $token;
			}
			if ( ! empty( $GLOBALS['_sa_door_wipe_no_wait'] ) || microtime( true ) >= $deadline ) {
				return false;
			}
			usleep( self::WIPE_WAIT_US );
		}
	}

	/**
	 * @param int $ms Age.
	 * @return array[] claimed rows older than $ms, keyed by ref.
	 */
	public static function stale_claims( $ms ) {
		$cut = time() - (int) floor( $ms / 1000 );
		$out = array();
		foreach ( self::rows( self::CLAIMED ) as $ref => $row ) {
			if ( strtotime( (string) ( $row['claimed_at'] ?? '' ) ) <= $cut ) {
				$out[ $ref ] = $row;
			}
		}
		return $out;
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
		foreach ( self::rows( self::HELD ) as $ref => $row ) {
			if ( self::is_expired( $row, $now ) && null === self::get_claimed( $ref ) ) {
				delete_option( self::HELD . $ref );
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
	 * @return int
	 */
	public static function count() {
		$now  = time();
		$refs = array_keys( self::rows( self::CLAIMED ) );
		foreach ( self::rows( self::HELD ) as $ref => $row ) {
			if ( ! self::is_expired( $row, $now ) || in_array( $ref, $refs, true ) ) {
				$refs[] = $ref;
			}
		}
		return count( array_unique( $refs ) );
	}

	/**
	 * Every row under a prefix, from the database, keyed by ref.
	 *
	 * @param string $prefix HELD or CLAIMED.
	 * @return array<string,array>
	 */
	private static function rows( $prefix ) {
		global $wpdb;
		$like = $wpdb->esc_like( $prefix ) . '%';
		// ARRAY_A, matching Aura_Worker_Rules::sweep_options()'s identical
		// SELECT — $wpdb::OBJECT is the default and would otherwise hand back
		// stdClass rows this class never asks for.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $like ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$val = maybe_unserialize( $r['option_value'] );
			if ( is_array( $val ) ) {
				$out[ substr( $r['option_name'], strlen( $prefix ) ) ] = $val;
			}
		}
		return $out;
	}

	/** @param string $option Option. @return array|null the DATABASE's row. */
	private static function from_db( $option ) {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option ) );
		if ( null === $raw ) {
			return null;
		}
		$val = maybe_unserialize( $raw );
		return is_array( $val ) ? $val : null;
	}

	/**
	 * The DATABASE's raw, still-serialized bytes for one option — never this
	 * request's option cache, and never unserialized. take_lock() fences its
	 * stale-lock delete on exactly these bytes (compared byte-for-byte, the
	 * same way write_option_where()'s UPDATE compares its `$before`), so the
	 * fence can only match the row this call actually read, never a fresher
	 * one a racer already installed.
	 *
	 * @param string $option Option.
	 * @return string|null
	 */
	private static function raw_bytes( $option ) {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option ) );
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
}
