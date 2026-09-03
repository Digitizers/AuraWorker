<?php
/**
 * The Elementor door's log: one option row per entry, retained until Aura
 * acknowledges it (spec §3.8, §3.10).
 *
 * Every number is allocated by the row's own INSERT above an ack floor that
 * only rises — there is no counter option to leak, and a number exists only
 * once its row does, so a contiguous-prefix ack never meets a hole. Nothing
 * but an ack deletes a row; a request that backs out settles its row
 * `discarded` instead.
 *
 * @package Aura_Worker
 * @since 2.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Worker_Door_Log {

	const PREFIX       = 'aura_worker_door_log_';
	const FLOOR        = 'aura_worker_door_log_acked';
	const EPOCH        = 'aura_worker_door_epoch';
	const FULL_MARKER  = 'aura_worker_door_log_full_since';
	const FULL_COUNTER = 'aura_worker_door_log_full_refused';
	const MAX_UNACKED  = 2000;
	const PAGE         = 100;
	/** How many INSERT collisions to ride through before giving up. */
	const ALLOC_TRIES  = 8;
	/** A log ROW: the prefix followed by digits only — never the floor, marker or counter options that share the prefix. */
	const ROW_REGEXP   = '^aura_worker_door_log_[0-9]+$';

	const TERMINAL = array( 'ok', 'refused', 'failed', 'interrupted', 'discarded', 'held' );

	/**
	 * Creates an option row only when none exists — a real mutex, unlike
	 * add_option(), whose INSERT … ON DUPLICATE KEY UPDATE lets a racer that
	 * passed the cached existence check overwrite and still return true.
	 * The shape is the one Aura_Worker_Magic_Link::claim_magic_link() uses
	 * (class-aura-worker-magic-link.php), and every door-side "INSERT" goes
	 * through this one primitive so seq allocation, the epoch and the
	 * closure marker cannot lose a race to a silent overwrite.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value; serialized like an option.
	 * @return bool True only when exactly one row was inserted.
	 */
	public static function insert_unique( $name, $value ) {
		global $wpdb;
		$rows = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) SELECT %s, %s, %s FROM DUAL WHERE NOT EXISTS ( SELECT 1 FROM {$wpdb->options} WHERE option_name = %s )",
				$name,
				maybe_serialize( $value ),
				'no',
				$name
			)
		);
		if ( 1 === (int) $rows && '' === (string) $wpdb->last_error ) {
			wp_cache_delete( $name, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			return true;
		}
		return false;
	}

	/**
	 * The site's log epoch — minted once, new only when the option store is
	 * gone (a reinstall). A credential re-save or a reconnect never moves it.
	 *
	 * @return string
	 */
	public static function epoch() {
		$cur = get_option( self::EPOCH, '' );
		if ( is_string( $cur ) && '' !== $cur ) {
			return $cur;
		}
		self::insert_unique( self::EPOCH, wp_generate_uuid4() ); // a concurrent mint loses the INSERT and reads the winner's
		$cur = get_option( self::EPOCH, '' );
		return is_string( $cur ) ? $cur : '';
	}

	/**
	 * Allocate the next seq by inserting the row that owns it.
	 *
	 * Entry fields: `ability`, `actor` (WHOSE call this is — under a replay
	 * the actor stored on the hold, verbatim, never the approver's identity;
	 * Ruling P36), `touches`, `verdict`, `rule_key`, and — on a replay —
	 * `ref`, `ruleset_seq` and `approved_by` (WHO approved it: the replay
	 * request's own actor, or null when it carries no identifiable user).
	 * Later writers add `snapshot_id`, `ran`, `result`, `reason`, `error`,
	 * `may_have_run`, the creation and collateral evidence, and `settled_at`.
	 *
	 * @param array $entry Fields (ability, actor, touches, verdict, …).
	 * @return int|WP_Error seq, or `aura_log_failed`.
	 */
	public static function open_pending( array $entry ) {
		// The epoch is minted the moment door state first EXISTS, not the
		// first time somebody reports it (Ruling P35). `present()` reads the
		// epoch option as the single witness that this site has a door, and
		// until now only `status_fragment()` minted one — so a write or a hold
		// followed by Elementor being disabled BEFORE the first `/status`
		// poll left rows nothing would ever report or reconcile. Minting here
		// costs one conditional INSERT on the first row of a site's life and
		// nothing after it.
		self::epoch();
		for ( $try = 0; $try < self::ALLOC_TRIES; $try++ ) {
			$seq = max( self::highest_row_seq(), self::floor() ) + 1;
			$row = array_merge(
				$entry,
				array(
					'seq'      => $seq,
					'at'       => gmdate( 'c' ),
					'result'   => 'pending',
					'admitted' => false,
				)
			);
			if ( self::insert_unique( self::PREFIX . $seq, $row ) ) {
				return $seq;
			}
			// Unique-name collision: a concurrent writer took this number.
		}
		return new WP_Error( 'aura_log_failed', 'The door log could not record this call; it was not run.', array( 'status' => 503 ) );
	}

	/** @param int $seq Seq. @return bool */
	public static function admit( $seq ) {
		return self::patch( (int) $seq, array( 'admitted' => true ) );
	}

	/**
	 * Settle a row terminally — ONCE. PENDING-ONLY (Ruling P27).
	 *
	 * A seq must never change meaning. `/status` can read a row as stale
	 * while the request that owns it is still finishing, so both sides end up
	 * holding a terminal verdict for the same number: the owner would
	 * overwrite the reconciler's `interrupted` with `ok`, or the reconciler
	 * would overwrite an `ok` with the verdict its earlier read implied — and
	 * Aura may already have consumed the first. The first terminal writer
	 * wins; everyone after it is told `false` and writes nothing.
	 *
	 * A caller that needs to ADD evidence to a row somebody else settled uses
	 * annotate(), which cannot touch the result.
	 *
	 * @param int   $seq      Seq.
	 * @param array $terminal Must carry `result` in TERMINAL.
	 * @return bool The row is terminal BECAUSE OF THIS CALL. Use is_terminal()
	 *              to tell "somebody settled it first" from "the write failed".
	 */
	public static function settle( $seq, array $terminal ) {
		if ( ! isset( $terminal['result'] ) || ! in_array( $terminal['result'], self::TERMINAL, true ) ) {
			return false;
		}
		$option = self::PREFIX . (int) $seq;
		$before = self::row_from_db( $option );
		if ( null === $before || 'pending' !== ( isset( $before['result'] ) ? $before['result'] : '' ) ) {
			return false;
		}
		$terminal['settled_at'] = gmdate( 'c' );
		// A terminal row is SERVED, so it is admitted in the same write
		// (Codex round-6 P1 on #499): an `admit()` that failed a moment before
		// a `settle()` that succeeded would otherwise leave a terminal row
		// `log_after` stops at and the reconciler never revisits.
		$terminal['admitted'] = true;
		return self::write_option_where( $option, array_merge( $before, $terminal ), $before );
	}

	/**
	 * Has this row reached a terminal result?
	 *
	 * Read from the DATABASE, not the option cache: the writer that beat this
	 * one is another request. Terminal is final, so the answer cannot go
	 * stale in the direction that matters.
	 *
	 * @param int $seq Seq.
	 * @return bool
	 */
	public static function is_terminal( $seq ) {
		$row = self::row_from_db( self::PREFIX . (int) $seq );
		return null !== $row && 'pending' !== ( isset( $row['result'] ) ? $row['result'] : 'pending' );
	}

	/**
	 * Add evidence to a row that is ALREADY terminal, without touching what
	 * it says happened.
	 *
	 * The first terminal writer decides the RESULT (Ruling P27); a later
	 * writer in the same request may still explain it — the throw that
	 * followed a compensation, say — but never change it. `result` and
	 * `settled_at` are dropped from the fields for exactly that reason.
	 *
	 * @param int   $seq    Seq.
	 * @param array $fields Evidence.
	 * @return bool
	 */
	public static function annotate( $seq, array $fields ) {
		unset( $fields['result'], $fields['settled_at'] );
		if ( empty( $fields ) ) {
			return false;
		}
		$option = self::PREFIX . (int) $seq;
		$before = self::row_from_db( $option );
		if ( null === $before || 'pending' === ( isset( $before['result'] ) ? $before['result'] : 'pending' ) ) {
			return false; // a pending row is settled, not annotated
		}
		return self::write_option_where( $option, array_merge( $before, $fields ), $before );
	}

	/**
	 * A discarded row is ADMITTED in the same write (Codex round-5 P1 on
	 * #499): `log_after` stops at an un-admitted row, and a discard that left
	 * `admitted: false` would hide every later entry for ever.
	 *
	 * @param int $seq Seq. @return bool
	 */
	public static function discard( $seq ) {
		return self::settle( $seq, array( 'result' => 'discarded' ) ); // settle() admits
	}

	/**
	 * Patch a row that is still pending — the durable in-flight writes
	 * (snapshot id, watermark, created ids, collateral ids). Never changes
	 * `result`; never touches a row that already settled.
	 *
	 * @param int   $seq    Seq.
	 * @param array $fields Fields (without `result`).
	 * @return bool
	 */
	public static function patch_pending( $seq, array $fields ) {
		unset( $fields['result'] );
		$option = self::PREFIX . (int) $seq;
		$before = self::row_from_db( $option );
		if ( null === $before || 'pending' !== ( $before['result'] ?? '' ) ) {
			return false;
		}
		return self::write_option_where( $option, array_merge( $before, $fields ), $before );
	}

	/** @param int $seq Seq. @return array|null */
	public static function get( $seq ) {
		$row = get_option( self::PREFIX . (int) $seq, null );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Conditional in-place update: compare-and-set on the bytes read a moment
	 * ago, so a row another writer deleted is never recreated and a row it
	 * changed is never overwritten blind.
	 *
	 * @param int   $seq    Seq.
	 * @param array $fields Fields to merge.
	 * @return bool ONE row changed.
	 */
	private static function patch( $seq, array $fields ) {
		$option = self::PREFIX . $seq;
		$before = self::row_from_db( $option );
		if ( null === $before ) {
			return false;
		}
		$after = array_merge( $before, $fields );
		return self::write_option_where( $option, $after, $before );
	}

	/**
	 * `UPDATE … SET option_value = %s WHERE option_name = %s AND option_value = %s`.
	 * Public: the holds store and the governor use the same primitive.
	 *
	 * @param string $option Option name.
	 * @param array  $after  New value.
	 * @param array  $before The value the caller read (the predicate).
	 * @return bool
	 */
	public static function write_option_where( $option, array $after, array $before ) {
		global $wpdb;
		$wpdb->last_error = '';
		$n                = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				maybe_serialize( $after ),
				$option,
				maybe_serialize( $before )
			)
		);
		wp_cache_delete( $option, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return 1 === (int) $n && '' === (string) $wpdb->last_error;
	}

	/**
	 * The row as the DATABASE holds it — never this request's option cache.
	 *
	 * @param string $option Option name.
	 * @return array|null
	 */
	private static function row_from_db( $option ) {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option ) );
		if ( null === $raw ) {
			return null;
		}
		$val = maybe_unserialize( $raw );
		return is_array( $val ) ? $val : null;
	}

	/** @return int */
	public static function floor() {
		return (int) get_option( self::FLOOR, 0 );
	}

	/**
	 * The highest seq that has a row.
	 *
	 * @return int
	 */
	public static function highest_row_seq() {
		global $wpdb;
		$like = $wpdb->esc_like( self::PREFIX ) . '%';
		// The NUMERIC rows only: the floor, the closure marker and the refusal
		// counter share this prefix and a non-numeric suffix casts to 0
		// (Codex round-4 P1 on #499 — an ack that deleted the floor would
		// restart numbering at 1 under Aura's cursor).
		$n = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(CAST(SUBSTRING(option_name, %d) AS UNSIGNED)) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name REGEXP %s",
				strlen( self::PREFIX ) + 1,
				$like,
				self::ROW_REGEXP
			)
		);
		return (int) $n;
	}

	/** @return int */
	public static function count_unacked() {
		global $wpdb;
		$like = $wpdb->esc_like( self::PREFIX ) . '%';
		$n    = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name REGEXP %s AND CAST(SUBSTRING(option_name, %d) AS UNSIGNED) > %d",
				$like,
				self::ROW_REGEXP,
				strlen( self::PREFIX ) + 1,
				self::floor()
			)
		);
		return (int) $n;
	}

	/** @return bool */
	public static function is_closed() {
		return false !== get_option( self::FULL_MARKER, false );
	}

	/** One owner: the INSERT. */
	public static function close() {
		self::insert_unique( self::FULL_MARKER, gmdate( 'c' ) );
	}

	/** Atomic increment, no row per refusal. */
	public static function bump_refused() {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no') ON DUPLICATE KEY UPDATE option_value = option_value + 1",
				self::FULL_COUNTER
			)
		);
		wp_cache_delete( self::FULL_COUNTER, 'options' );
	}

	/**
	 * @return array{ since: string|null, refused: int }|null
	 */
	public static function full_report() {
		if ( ! self::is_closed() ) {
			return null;
		}
		return array(
			'since'   => (string) get_option( self::FULL_MARKER, '' ),
			'refused' => (int) get_option( self::FULL_COUNTER, 0 ),
		);
	}

	/**
	 * Aura committed everything up to $seq: raise the floor (upward only,
	 * BEFORE the delete), delete the rows, reopen if under the bound.
	 *
	 * $seq is CLAMPED to the top of the log — the highest seq that exists,
	 * or the floor when the ack emptied it, which is exactly the ceiling
	 * open_pending() allocates above. An ack is a caller's assertion about
	 * numbers this site issued, and a nonsense one (PHP_INT_MAX, a cursor
	 * from a different site) must not become the site's own floor: the next
	 * open_pending() would compute PHP_INT_MAX + 1 as a FLOAT, mint option
	 * names like `aura_worker_door_log_9.2233720368548E+18` that the row
	 * REGEXP never matches again, and the log would never allocate a
	 * readable number afterwards.
	 *
	 * The purge is bounded by the FLOOR the raise settled on, never by $seq:
	 * a stale ack below a floor that is already higher would otherwise leave
	 * the rows in `($seq, $floor]` behind — under the floor, so never served,
	 * never acked and never deleted.
	 *
	 * @param string $epoch The epoch Aura is acking.
	 * @param int    $seq   Highest seq of its contiguous committed prefix.
	 * @return array{ acked: int, floor: int }
	 */
	public static function ack( $epoch, $seq ) {
		global $wpdb;
		$seq = (int) $seq;
		if ( ! is_string( $epoch ) || $epoch !== self::epoch() ) {
			return array( 'acked' => 0, 'floor' => self::floor() );
		}
		$top = max( self::highest_row_seq(), self::floor() );
		if ( $seq > $top ) {
			$seq = $top;
		}
		if ( $seq < 1 ) {
			return array( 'acked' => 0, 'floor' => self::floor() );
		}
		// Floor: INSERT if absent, else raise only when lower. The floor as it
		// stood BEFORE the raise bounds the cache invalidation below to the
		// newly acked range — never 1..seq on a site with a long history
		// (Codex round-5 P2).
		self::insert_unique( self::FLOOR, 0 );
		$prev_floor_before_raise = self::floor();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d",
				(string) $seq,
				self::FLOOR,
				$seq
			)
		);
		wp_cache_delete( self::FLOOR, 'options' );
		$floor = self::floor();
		$acked = 0;
		if ( $floor > 0 ) {
			$prev_floor = $prev_floor_before_raise; // read BEFORE the raise, below
			$like  = $wpdb->esc_like( self::PREFIX ) . '%';
			$acked = (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name REGEXP %s AND CAST(SUBSTRING(option_name, %d) AS UNSIGNED) <= %d",
					$like,
					self::ROW_REGEXP,
					strlen( self::PREFIX ) + 1,
					$floor
				)
			);
			for ( $i = $prev_floor + 1; $i <= $floor; $i++ ) {
				wp_cache_delete( self::PREFIX . $i, 'options' );
			}
		}
		if ( self::is_closed() && self::count_unacked() < self::MAX_UNACKED ) {
			delete_option( self::FULL_MARKER );
			delete_option( self::FULL_COUNTER );
		}
		return array( 'acked' => $acked, 'floor' => $floor );
	}

	/**
	 * Terminal entries with seq > $after, ascending, stopping at the first
	 * pending or un-admitted row — the site-side contiguous prefix.
	 *
	 * @param int $after Aura's cursor.
	 * @param int $limit Page size.
	 * @return array[]
	 */
	public static function log_after( $after, $limit = self::PAGE ) {
		$after = max( (int) $after, self::floor() );
		$out   = array();
		$seq   = $after;
		$top   = self::highest_row_seq();
		while ( count( $out ) < $limit && $seq < $top ) {
			$seq++;
			$row = self::get( $seq );
			if ( null === $row ) {
				break; // a number with no row is a hole — cannot happen by construction; stop rather than skip
			}
			if ( empty( $row['admitted'] ) || 'pending' === ( $row['result'] ?? 'pending' ) ) {
				break;
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Mints a new epoch, clears the closure state, and leaves every log row
	 * — and the ACK FLOOR — in place: the recovery from a rewound log, which
	 * `/status` REPORTS (`rewind.detected`) and Aura DECIDES, through the
	 * grant-gated `POST /aura/v1/door/rotate` (Ruling P20). It is never a
	 * side effect of a read: a rotation invalidates every ack in flight, so
	 * an unauthenticated-by-grant caller who could trigger one could starve
	 * the log to MAX_UNACKED and close the write door.
	 *
	 * The floor is RETAINED, and that is the whole of the rule. It is not
	 * Aura's cursor, which the new epoch invalidates anyway; it is this
	 * site's record of which numbers no longer have rows. Deleting it made
	 * `log_after(0)` walk from 1 on any site that had ever acked, where it
	 * met the first deleted number, read it as a hole, and stopped — for
	 * ever. The log then served `[]` on every poll while `count_unacked()`
	 * climbed to MAX_UNACKED and closed the door (Ruling P2').
	 *
	 * A COMPARE-AND-SWAP on the epoch it was asked to replace (Ruling P23).
	 * Two separately granted rotations answering the SAME rewind both pass
	 * the caller's current-epoch check before either writes; an
	 * unconditional delete then removed the epoch the winner had just
	 * minted and rotated a second time — invalidating an ack already in
	 * flight against the winner's, which is the very starvation rotation
	 * exists to end. The delete is FENCED on `$expected`, the same shape
	 * every other mutex delete in the door uses, and a 0-row delete means
	 * somebody else got there first: the epoch now in force is answered,
	 * and nothing is written.
	 *
	 * The closure state is cleared only by the winner — a loser that
	 * cleared it would be reopening a log the winner's own rotation has
	 * already accounted for.
	 *
	 * @param string $expected The epoch the caller means to replace.
	 * @return array{ rotated: bool, epoch: string } `epoch` is the one now in force either way.
	 */
	public static function rotate_epoch( $expected ) {
		global $wpdb;
		$expected = (string) $expected;
		if ( '' === $expected ) {
			return array(
				'rotated' => false,
				'epoch'   => self::epoch(),
			);
		}
		$gone = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::EPOCH, $expected ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		wp_cache_delete( self::EPOCH, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		if ( 1 !== (int) $gone ) {
			return array(
				'rotated' => false,
				'epoch'   => self::epoch(),
			);
		}
		delete_option( self::FULL_MARKER );
		delete_option( self::FULL_COUNTER );
		return array(
			'rotated' => true,
			'epoch'   => self::epoch(),
		);
	}

	/**
	 * Rows still pending (or never admitted) whose `at` is older than $ms.
	 *
	 * ONE statement: count_unacked()'s predicate — the numeric rows above the
	 * ack floor — with the rows themselves returned instead of counted, then
	 * `result` and age filtered in PHP. It walked `floor()+1 … highest_row_seq()`
	 * with one get_option() per number, and this runs at the head of EVERY
	 * `/status` poll: on a site whose ack is a thousand entries behind, that
	 * was a thousand option reads per poll on the site's hottest endpoint,
	 * and a gap between the floor and the top cost a read for each number
	 * that has no row at all.
	 *
	 * Ordered by seq ascending, as the walk was — the reconciler settles in
	 * that order and its counters are reported in it.
	 *
	 * @param int $ms Age in milliseconds.
	 * @return array[]
	 */
	public static function stale_pending( $ms ) {
		global $wpdb;
		$cut  = time() - (int) floor( $ms / 1000 );
		$like = $wpdb->esc_like( self::PREFIX ) . '%';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name REGEXP %s AND CAST(SUBSTRING(option_name, %d) AS UNSIGNED) > %d",
				$like,
				self::ROW_REGEXP,
				strlen( self::PREFIX ) + 1,
				self::floor()
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			if ( ! isset( $r['option_name'], $r['option_value'] ) ) {
				continue;
			}
			$row = maybe_unserialize( $r['option_value'] );
			if ( ! is_array( $row ) || 'pending' !== ( $row['result'] ?? '' ) ) {
				continue;
			}
			if ( strtotime( (string) ( $row['at'] ?? '' ) ) > $cut ) {
				continue;
			}
			// Keyed by the NAME's suffix, not by the row's own `seq` field: the
			// name is what the number is, and a row whose stored `seq` somehow
			// disagreed must still sort where its row actually lives.
			$out[ (int) substr( (string) $r['option_name'], strlen( self::PREFIX ) ) ] = $row;
		}
		ksort( $out, SORT_NUMERIC );
		return array_values( $out );
	}
}
