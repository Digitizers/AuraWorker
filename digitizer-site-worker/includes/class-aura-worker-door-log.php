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
	/** The BINDING generation (Rulings P51/P58): minted like the epoch, rotated ONLY by a rebind. */
	const BINDING      = 'aura_worker_door_binding';
	/* The binding record's STATE (Ruling P61) — a lazy mint is never a stated identity. */
	const BINDING_UNSET   = 'unset';
	const BINDING_BOUND   = 'bound';
	const BINDING_UNBOUND = 'unbound';

	/** @var array|null live_identity()'s per-request cache */
	private static $live_identity = null;

	/** @var bool Whether this request has already tried to adopt an `unset` record (Ruling P73). */
	private static $binding_adopt_tried = false;
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
		// EVICTED ON BOTH OUTCOMES (Ruling P72). A LOST insert means somebody
		// else's row is under this name RIGHT NOW — and this request has very
		// likely just cached the name as absent (`notoptions`) on the read that
		// decided to mint. Leaving that negative in place made the loser read
		// `null` for a row that demonstrably exists, and a lazy mint then
		// answered '' for a generation the winner had already installed.
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		return ( 1 === (int) $rows && '' === (string) $wpdb->last_error );
	}

	/**
	 * Re-read a name from the DATABASE after a lost mint (Ruling P72).
	 *
	 * The caches are gone by the time this runs (insert_unique() evicts them
	 * either way), but a cache eviction is not a read: the value has to come
	 * from the row the winner wrote, and `get_option()` on a busy site can
	 * still be served a negative another code path re-primed. So this asks
	 * `$wpdb` directly, with `last_error` cleared first.
	 *
	 * NULL means UNREADABLE — the row is genuinely absent, or the read failed.
	 * Neither is a value a writer may stamp: an empty stamp used to read as
	 * "queued before the generation existed", i.e. permanently current, so a
	 * replacement client could claim and run the previous client's mutation.
	 *
	 * @param string $name Option name.
	 * @return mixed|null The unserialised value, or null when unreadable.
	 */
	private static function reread_after_mint( $name ) {
		global $wpdb;
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		$wpdb->last_error = '';
		$raw              = self::raw_option( $name );
		if ( null === $raw || '' !== (string) $wpdb->last_error ) {
			return null;
		}
		return maybe_unserialize( $raw );
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
		if ( self::insert_unique( self::EPOCH, wp_generate_uuid4() ) ) {
			$cur = get_option( self::EPOCH, '' );
			return is_string( $cur ) ? $cur : '';
		}
		// LOST the mint (Ruling P72): the winner's row exists, and this
		// request's own negative cache must not be what it reads back.
		$cur = self::reread_after_mint( self::EPOCH );
		return is_string( $cur ) ? $cur : '';
	}

	/**
	 * The BINDING generation: which Aura binding this site's door belongs to
	 * (Ruling P51).
	 *
	 * Minted lazily, exactly like the epoch, and rotated by ONE thing — a
	 * rebind (a changed-binding connect, or an unbind). That is the whole
	 * difference, and the reason it exists. The epoch
	 * is the LOG's identity and Aura may legitimately rotate it through the
	 * grant-gated `POST /aura/v1/door/rotate` (Ruling P20) whenever a rewind is
	 * detected; a replay in flight would then have seen its fence move for a
	 * reason that has nothing to do with the binding, be told
	 * `binding_changed`, and lose an approval the reconciler only reclaims ten
	 * minutes later. A rotation never touches this value, so the fence answers
	 * the question it is actually asking.
	 *
	 * @return string
	 */
	public static function binding() {
		$rec = self::binding_record();
		$gen = (string) ( isset( $rec['gen'] ) ? $rec['gen'] : '' );
		// NULL, NOT '' (Ruling P72). A real record always carries a generation,
		// so an empty one means the mint could not be established — the row
		// could not be read, or the lost-insert re-read failed. Stamping ''
		// on a hold, a claim or a log row wrote something every reader treated
		// as permanently current: after a rebind the replacement client could
		// claim and execute the previous client's stored mutation. A writer
		// that cannot name its binding refuses instead.
		return '' === $gen ? null : $gen;
	}

	/**
	 * The whole binding record — `{ gen, client, dashboard }` (Ruling P59).
	 *
	 * The generation carries the IDENTITY it belongs to, which is what makes
	 * the rotation idempotent: a connect states the identity it is installing
	 * and the rotation is a no-op when the record already names it. That, and
	 * nothing else, is why a rotation that failed can simply be retried by the
	 * next connect rather than needing to be undone.
	 *
	 * The record carries a STATE, and that is what tells a lazy mint apart from
	 * a real one (Ruling P61). A site that 2.16 meets already connected has no
	 * record, so the first reader mints one — and a null identity on that
	 * placeholder used to be indistinguishable from "this site is unbound", so
	 * a later unbind saw its own target already in place, rotated nothing, and
	 * callbacks waiting at the generation fence walked through after the site
	 * had been unbound. `unset` is never equal to anything: it always rotates.
	 *
	 * A record written before this rule has no `state` and reads as `unset`,
	 * which is exactly right — nobody ever stated whose it was.
	 *
	 * @return array{ gen: string, state: string, client: string|null, dashboard: string|null }
	 */
	public static function binding_record() {
		$cur = get_option( self::BINDING, null );
		if ( is_array( $cur ) && isset( $cur['gen'] ) && '' !== (string) $cur['gen'] ) {
			return self::adopt_if_unset( self::normalise_binding( $cur ) );
		}
		$won = self::insert_unique(
			self::BINDING,
			array(
				'gen'       => wp_generate_uuid4(),
				'state'     => self::BINDING_UNSET,
				'client'    => null,
				'dashboard' => null,
			)
		);
		// A LOST mint reads the WINNER's row from the database, never this
		// request's negative cache (Ruling P72). An unreadable answer is
		// reported as an empty generation, which `binding()` turns into null
		// and every writer refuses to stamp.
		$cur = $won ? get_option( self::BINDING, null ) : self::reread_after_mint( self::BINDING );
		return is_array( $cur ) && isset( $cur['gen'] )
			? self::adopt_if_unset( self::normalise_binding( $cur ) )
			: array( 'gen' => '', 'state' => self::BINDING_UNSET, 'client' => null, 'dashboard' => null );
	}

	/**
	 * ADOPT an `unset` record for the identity this site is demonstrably live
	 * under (Ruling P73).
	 *
	 * A site 2.16 meets already connected has no record, so the first reader
	 * mints a placeholder — `unset`, naming nobody. Rows queued and written
	 * afterwards carry that placeholder's generation, and `unset` used to be a
	 * BYPASS: the generation was live without the identity ever being compared.
	 *
	 * That bypass is a window, and a replacement connect walks straight
	 * through it. A connect publishes the new token, the client sentinel and
	 * the dashboard URL BEFORE it rotates the generation, so between those two
	 * writes a request authenticating with the NEW credentials met an `unset`
	 * record, was told the departed binding's generation was live, and could
	 * claim and execute the previous client's stored mutation.
	 *
	 * Adoption closes it by making the record STATE what everything else can
	 * already see. It is not a rotation: the generation is unchanged, so the
	 * site's own rows stay current and no approval is stranded. From that
	 * moment the record says `bound` and P62's identity comparison applies to
	 * every reader — so the same replacement connect's identity writes make
	 * the departed rows foreign the instant they land, rotation or no
	 * rotation.
	 *
	 * A site with NO live identity — never connected — keeps `unset`, and
	 * nothing is bound there to be protected.
	 *
	 * Once per request: a CAS that loses re-reads and uses whatever won.
	 *
	 * @param array      $rec  The normalised record.
	 * @param array|null $live { client, dashboard } — the fence supplies its own
	 *                         uncached read; everyone else uses the per-request one.
	 * @return array The record as it now stands.
	 */
	private static function adopt_if_unset( array $rec, $live = null ) {
		if ( self::BINDING_UNSET !== $rec['state'] || '' === (string) $rec['gen'] || self::$binding_adopt_tried ) {
			return $rec;
		}
		$live = is_array( $live ) ? $live : self::live_identity();
		$client    = isset( $live['client'] ) && '' !== (string) $live['client'] ? (string) $live['client'] : null;
		$dashboard = isset( $live['dashboard'] ) && '' !== (string) $live['dashboard'] ? (string) $live['dashboard'] : null;
		if ( null === $client && null === $dashboard ) {
			return $rec; // never connected: there is nothing to adopt it for
		}
		self::$binding_adopt_tried = true;

		global $wpdb;
		$raw = self::raw_option( self::BINDING );
		$cur = null === $raw ? null : maybe_unserialize( $raw );
		if ( ! is_array( $cur ) || ! isset( $cur['gen'] ) ) {
			return $rec;
		}
		$cur = self::normalise_binding( $cur );
		if ( self::BINDING_UNSET !== $cur['state'] ) {
			return $cur; // somebody stated it first — theirs stands
		}
		$next = array(
			'gen'       => $cur['gen'], // THE SAME generation: this is not a rotation
			'state'     => self::BINDING_BOUND,
			'client'    => $client,
			'dashboard' => $dashboard,
		);
		$wpdb->last_error = '';
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				maybe_serialize( $next ),
				self::BINDING,
				$raw
			)
		);
		wp_cache_delete( self::BINDING, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		// Win or lose, the answer is whatever the row says NOW.
		$after = self::raw_option( self::BINDING );
		$after = null === $after ? null : maybe_unserialize( $after );
		return is_array( $after ) && isset( $after['gen'] ) ? self::normalise_binding( $after ) : $next;
	}

	/**
	 * Fill in what an older record does not carry: no `state` means nobody
	 * ever stated whose this door is (Ruling P61).
	 *
	 * @param array $rec Raw record.
	 * @return array
	 */
	private static function normalise_binding( array $rec ) {
		$state = isset( $rec['state'] ) ? (string) $rec['state'] : '';
		if ( ! in_array( $state, array( self::BINDING_BOUND, self::BINDING_UNBOUND ), true ) ) {
			$state = self::BINDING_UNSET;
		}
		return array(
			'gen'       => (string) ( isset( $rec['gen'] ) ? $rec['gen'] : '' ),
			'state'     => $state,
			'client'    => isset( $rec['client'] ) && null !== $rec['client'] ? (string) $rec['client'] : null,
			'dashboard' => isset( $rec['dashboard'] ) && null !== $rec['dashboard'] ? (string) $rec['dashboard'] : null,
		);
	}

	/**
	 * The identity this site is bound to RIGHT NOW, as the connect stores it
	 * (Ruling P62) — read once per request.
	 *
	 * `client` comes from the ruleset store's binding sentinel through
	 * `Aura_Worker_Rules::bound_client()` (which proves it against the site's
	 * current token), and `dashboard` from the `aura_worker_dashboard_url`
	 * option. Those are the two things a connect writes to say whose site this
	 * is, and they are written BEFORE the generation rotates — so a departed
	 * binding's rows stop being current the moment a changed-client connect
	 * answers, whether or not its rotation landed.
	 *
	 * @return array{ client: string|null, dashboard: string|null }
	 */
	public static function live_identity() {
		if ( null !== self::$live_identity ) {
			return self::$live_identity;
		}
		$client    = class_exists( 'Aura_Worker_Rules' ) ? (string) Aura_Worker_Rules::bound_client() : '';
		$dashboard = (string) get_option( 'aura_worker_dashboard_url', '' );
		self::$live_identity = array(
			'client'    => '' === $client ? null : $client,
			'dashboard' => '' === $dashboard ? null : $dashboard,
		);
		return self::$live_identity;
	}

	/** Test seam / per-request cache reset for live_identity(). */
	public static function forget_live_identity() {
		self::$live_identity       = null;
		self::$binding_adopt_tried = false;
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
	 * WHY THE FLOOR IS RE-CHECKED **AFTER** THE INSERT (Ruling P37). The
	 * INSERT is the reservation — before it, this writer owns nothing, and a
	 * floor read a moment earlier says nothing about the number it is about to
	 * take. Between computing N and inserting it, another writer can insert
	 * AND settle N, and `/door/ack` can raise the floor to N and delete that
	 * row; the conditional INSERT then succeeds by RECREATING N at or below
	 * the floor. Such a row is admitted and its callback runs, but
	 * `log_after()` and `count_unacked()` both start above the floor, so Aura
	 * never sees it and it is never acked — a governed write with no record,
	 * for ever. Only a reservation can be compared against a floor that moved,
	 * so the order is: insert, re-read the floor, and give the row back if it
	 * lost.
	 *
	 * The give-back is a DELETE fenced on the exact bytes this call wrote —
	 * never `delete_option()`, which would remove whatever now stands under
	 * that name, including a racer's fresh reservation. Same shape as the
	 * hold-queue lock's and the creation mutex's releases.
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
		// WHOSE ENTRY THIS IS, BEFORE ANY NUMBER IS TAKEN (Ruling P72). A row
		// stamped with an empty binding read as "written before the generation
		// existed" and was therefore current for ever — so a write admitted
		// during a first-reader race stayed runnable after a rebind. Nothing
		// has been reserved yet, so refusing here is retryable and free.
		$binding = self::binding();
		if ( null === $binding ) {
			return new WP_Error( 'aura_log_failed', 'This site could not establish which Aura binding this call belongs to; it was not run.', array( 'status' => 503 ) );
		}
		for ( $try = 0; $try < self::ALLOC_TRIES; $try++ ) {
			$seq = max( self::highest_row_seq(), self::floor() ) + 1;
			$row = array_merge(
				$entry,
				array(
					'seq'      => $seq,
					'at'       => gmdate( 'c' ),
					// WHICH BINDING wrote this entry (Ruling P58). The log is
					// the SITE's audit trail and is served whatever the current
					// binding is, so a reader has to be able to see that an
					// entry belongs to a client that has since gone.
					'binding'  => $binding,
					'result'   => 'pending',
					'admitted' => false,
				)
			);
			if ( self::insert_unique( self::PREFIX . $seq, $row ) ) {
				// The ack raises the floor with raw SQL, so this request's
				// option cache can still hold the value from before it.
				wp_cache_delete( self::FLOOR, 'options' );
				if ( $seq > self::floor() ) {
					return $seq;
				}
				// Acked out from under this reservation: hand the number back
				// and allocate above the floor that moved.
				self::delete_row_fenced( self::PREFIX . $seq, $row );
				continue;
			}
			// Unique-name collision: a concurrent writer took this number.
		}
		return new WP_Error( 'aura_log_failed', 'The door log could not record this call; it was not run.', array( 'status' => 503 ) );
	}

	/**
	 * Delete a row ONLY while it still carries the bytes this request wrote.
	 *
	 * The predicate is the value, byte for byte, exactly as the hold-queue
	 * lock and the creation mutex release themselves. A bare `delete_option()`
	 * would remove whatever stands under that name now — including the
	 * reservation a racer took after this one lost its number.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  The value this request inserted.
	 * @return bool One row removed.
	 */
	private static function delete_row_fenced( $option, $value ) {
		global $wpdb;
		$gone = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$option,
				maybe_serialize( $value )
			)
		);
		wp_cache_delete( $option, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return 1 === (int) $gone;
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

	/**
	 * The fence's own read: the binding record and the live identity, taken
	 * from the DATABASE past every cache (Ruling P64).
	 *
	 * The caches are the bug. By the time a replay reaches its fence it has
	 * already been through `get_held()`, which populated BOTH WordPress's
	 * option cache for the binding record and `live_identity()`'s per-request
	 * static — and a rebind completing in ANOTHER PHP process invalidates
	 * neither of them, because both live in this process's memory. The fence
	 * would then compare the claim against a generation and an identity that
	 * were current when this request started and have not been since.
	 *
	 * So this reads three rows itself: the binding record, the ruleset store
	 * (for the binding sentinel `bound_client()` proves against the site's
	 * current token — its own readers are already uncached, so they are reused
	 * as they are), and `aura_worker_dashboard_url`. The per-request cache is
	 * refreshed from what it read, so the rest of the request agrees with the
	 * fence rather than with its own older answer.
	 *
	 * FAILS CLOSED: a read error anywhere answers `ok: false`, and the fence
	 * refuses. A door that cannot prove whose it is does not run a mutation.
	 *
	 * @return array{ ok: bool, gen: string, state: string, client: string|null, dashboard: string|null, live_client: string|null, live_dashboard: string|null }
	 */
	public static function fence_identity() {
		$fail = array(
			'ok'             => false,
			'gen'            => '',
			'state'          => self::BINDING_UNSET,
			'client'         => null,
			'dashboard'      => null,
			'live_client'    => null,
			'live_dashboard' => null,
		);
		if ( ! class_exists( 'Aura_Worker_Rules' ) ) {
			return $fail;
		}
		global $wpdb;
		$wpdb->last_error = '';
		$raw              = self::raw_option( self::BINDING );
		if ( '' !== (string) $wpdb->last_error ) {
			return $fail;
		}
		$rec = null === $raw ? null : maybe_unserialize( $raw );
		$rec = is_array( $rec ) && isset( $rec['gen'] ) ? self::normalise_binding( $rec ) : null;
		if ( null === $rec ) {
			// No record at all. Not a failure — a site nobody has stated
			// anything about — but the fence has nothing to compare, so it is
			// reported as an `unset` record with an empty generation, which
			// matches no stamped row and lets an unstamped one through.
			$rec = array( 'gen' => '', 'state' => self::BINDING_UNSET, 'client' => null, 'dashboard' => null );
		}

		$stored = Aura_Worker_Rules::stored_uncached();
		if ( is_wp_error( $stored ) ) {
			return $fail;
		}
		$client = is_array( $stored ) ? (string) Aura_Worker_Rules::bound_client( $stored ) : '';

		$dash = Aura_Worker_Rules::read_option_uncached( 'aura_worker_dashboard_url' );
		if ( is_wp_error( $dash ) ) {
			return $fail;
		}
		$dash = null === $dash ? '' : (string) maybe_unserialize( $dash );

		$live = array(
			'client'    => '' === $client ? null : $client,
			'dashboard' => '' === $dash ? null : $dash,
		);
		self::$live_identity = $live; // the rest of the request agrees with the fence

		// The fence sees an ADOPTED record too (Ruling P73), stated against the
		// identity it just read from the database rather than the one this
		// request cached — otherwise the `unset` bypass survives exactly where
		// it matters most.
		$rec = self::adopt_if_unset( $rec, $live );

		return array(
			'ok'             => true,
			'gen'            => $rec['gen'],
			'state'          => $rec['state'],
			'client'         => $rec['client'],
			'dashboard'      => $rec['dashboard'],
			'live_client'    => $live['client'],
			'live_dashboard' => $live['dashboard'],
		);
	}

	/**
	 * Is a stated identity PROVABLE — i.e. does it name a client (Ruling P66)?
	 *
	 * The callback URL a legacy dashboard signs carries no `client` line, so
	 * the identity such a connect states is nothing but a dashboard base URL —
	 * and two distinct Aura customers routinely share one. Comparing those two
	 * identities for equality answered "same binding" for what is in fact a
	 * different customer's site: reconnecting with a new token left the old
	 * generation current, and the replacement client could then see, approve
	 * and replay the departed client's held mutations.
	 *
	 * A dashboard URL alone is not an identity. It cannot be proven the same,
	 * so it is never treated as the same.
	 *
	 * @param string|null $client The record's or the target's client.
	 * @return bool
	 */
	private static function identity_is_provable( $client ) {
		return null !== $client && '' !== (string) $client;
	}

	/**
	 * Does this RECORD still describe the identity the site is live under?
	 *
	 * The general rule is Ruling P66's: an unprovable identity never equals
	 * another, so a rotation never short-circuits on one. THIS comparison
	 * carries the one deliberate exception — a `bound` record naming no client
	 * is current while the LIVE client is also absent and the dashboards match.
	 *
	 * Why the exception is safe, and wanted. Safe because a clientless connect
	 * now always rotates, so the generation a row is stamped with is that
	 * ONE connect's: a second clientless connect — the case the finding is
	 * about — mints a new generation and the earlier connect's rows fail the
	 * comparison before this predicate is ever reached. Wanted because without
	 * it a clientless site's own queue would be foreign to itself the moment
	 * it was written, and such a site could never serve a hold at all.
	 *
	 * @param string|null $rec_client    Record's client.
	 * @param string|null $rec_dashboard Record's dashboard.
	 * @param string|null $client        Live client.
	 * @param string|null $dashboard     Live dashboard.
	 * @return bool
	 */
	private static function identity_still_describes( $rec_client, $rec_dashboard, $client, $dashboard ) {
		return $rec_client === $client && $rec_dashboard === $dashboard;
	}

	/**
	 * `generation_is_live()` asked of the DATABASE, for the fences (Ruling
	 * P64). A read it cannot trust answers false: the mutation does not run.
	 *
	 * @param string $gen The generation stamped on a row or a claim.
	 * @return bool
	 */
	public static function generation_is_live_uncached( $gen ) {
		$f = self::fence_identity();
		if ( ! $f['ok'] ) {
			return false;
		}
		if ( '' === (string) $gen || (string) $gen !== $f['gen'] ) {
			return false; // an EMPTY stamp is never current (Ruling P72)
		}
		// NO `unset` BYPASS (Ruling P73). An `unset` record can only survive on
		// a site with no live identity, where the comparison below is null
		// against null and answers true anyway. Everywhere else the record has
		// been adopted, so the identity is compared — which is the whole point.
		return self::identity_still_describes( $f['client'], $f['dashboard'], $f['live_client'], $f['live_dashboard'] );
	}

	/**
	 * Is this generation the one the site is LIVE under (Ruling P62)?
	 *
	 * The generation must be current AND its record must still describe the
	 * identity the site is actually bound to now. The second half is what
	 * covers a rotation that did not land: the connect writes its identity
	 * options before it rotates, so by the time it answers — success or
	 * `aura_door_failed` — the record still names the DEPARTED client while the
	 * site is live under the new one, and every row stamped with it is foreign.
	 *
	 * An `unset` record makes no claim about whose the door is, so it cannot
	 * contradict the live identity: rows stamped by it stay current, which is
	 * the pre-P62 behaviour and the right one for a site nobody has stated
	 * anything about.
	 *
	 * @param string $gen The generation stamped on a row.
	 * @return bool
	 */
	public static function generation_is_live( $gen ) {
		$rec = self::binding_record();
		if ( '' === (string) $gen || (string) $gen !== (string) $rec['gen'] ) {
			return false; // an EMPTY stamp is never current (Ruling P72)
		}
		// NO `unset` BYPASS (Ruling P73) — see generation_is_live_uncached().
		$live = self::live_identity();
		return self::identity_still_describes( $rec['client'], $rec['dashboard'], $live['client'], $live['dashboard'] );
	}

	/**
	 * The current generation, read RAW from the database and NEVER minted
	 * (Rulings P51/P59).
	 *
	 * `binding()` mints when the record is absent, which is right for a writer
	 * stamping a row and wrong for a FENCE: on a site whose record had gone
	 * missing it would quietly manufacture agreement. A fence wants the
	 * database's answer or nothing.
	 *
	 * @return string '' when there is no record.
	 */
	public static function binding_raw() {
		$raw = self::raw_option( self::BINDING );
		$rec = null === $raw ? null : maybe_unserialize( $raw );
		return is_array( $rec ) && isset( $rec['gen'] ) ? (string) $rec['gen'] : '';
	}

	/**
	 * Move the binding to a new IDENTITY, minting a generation (Rulings
	 * P58/P59).
	 *
	 * This is what a rebind does, in place of deleting the door. Nothing is
	 * removed: every held, claimed and log row keeps its own `binding`, and
	 * from this moment the ones stamped with the old generation are another
	 * client's — invisible to the queue's readers, swept when the reconciler
	 * next runs, and still readable in the log, which is the SITE's audit trail
	 * rather than any one binding's.
	 *
	 * IDEMPOTENT and VERIFIED. Idempotent because the record names the identity
	 * it belongs to, so a connect that changes nothing rotates nothing — which
	 * is what lets a FAILED rotation simply be retried by the next connect
	 * instead of needing to be undone. Verified because the swap is a
	 * compare-and-swap on the bytes just read and exactly one row must change:
	 * a transient failure used to be indistinguishable from a rotation that
	 * happened, and a changed-client connect would complete with the departed
	 * client's holds still current (F1).
	 *
	 * CLAIM-CONDITIONAL when a fence is supplied (Ruling P68). An unbind's
	 * Phase B can run long enough for `SITE_CLAIM_TAKEOVER_AFTER` to elapse and
	 * a replacement connect to seize the site claim; a stale cleanup resuming
	 * afterwards would rotate the WINNER's binding to `unbound`, and every hold
	 * the new client queued would go invisible while its governed callbacks
	 * failed the binding fence until somebody reconnected. Every other Phase-B
	 * step is joined to the claim row; so is this one now — and joined in the
	 * SAME statement as the compare-and-swap, so there is no window between
	 * checking the claim and acting on it.
	 *
	 * @param array  $identity { client: string|null, dashboard: string|null }.
	 * @param string $claim    Site-claim option name ('' ⇒ unconditional).
	 * @param string $fence    The caller's claim fence ('' ⇒ unconditional).
	 * @return bool The record now names this identity.
	 */
	public static function rotate_binding( array $identity, $claim = '', $fence = '' ) {
		global $wpdb;
		$claim   = (string) $claim;
		$fence   = (string) $fence;
		$claimed = ( '' !== $claim && '' !== $fence );
		if ( ( '' !== $claim ) !== ( '' !== $fence ) ) {
			return false; // half a condition is not one
		}
		$client    = isset( $identity['client'] ) && '' !== (string) $identity['client'] ? (string) $identity['client'] : null;
		$dashboard = isset( $identity['dashboard'] ) && '' !== (string) $identity['dashboard'] ? (string) $identity['dashboard'] : null;

		$raw = self::raw_option( self::BINDING );
		$rec = null === $raw ? null : maybe_unserialize( $raw );
		$rec = is_array( $rec ) && isset( $rec['gen'] ) ? $rec : null;

		// The TARGET state: an unbind states nobody, everything else states a
		// binding (a keyless connect is still `bound` — it names a dashboard).
		$target = ( null === $client && null === $dashboard ) ? self::BINDING_UNBOUND : self::BINDING_BOUND;

		// ALREADY THIS IDENTITY ⇒ nothing to do (Ruling P59). This is what
		// makes the rotation safe to retry: every connect calls it, and only a
		// connect that actually CHANGES the binding moves the generation.
		//
		// STATE FIRST (Ruling P61). A lazily-minted `unset` record has a null
		// identity and is NOT an unbound site — it is a site nobody has stated
		// anything about, typically one 2.16 met already connected. Treating it
		// as equal to an unbind meant the unbind rotated nothing and callbacks
		// waiting at the generation fence walked through after the site had
		// been unbound. `unset` always rotates.
		//
		// A CLIENTLESS CONNECT ALWAYS ROTATES (Ruling P66). The legacy connect
		// callback signs no `client` line, so both sides of this comparison
		// state nothing but a dashboard base URL — which two different Aura
		// customers commonly share. Treating those as the same binding meant a
		// site reconnected to a DIFFERENT customer on the same dashboard kept
		// the departed client's generation current, and the replacement could
		// receive and approve mutations that were never theirs. An identity
		// that cannot be proven the same is not the same.
		//
		// THE COST, stated plainly: a legacy dashboard re-saving the same
		// site's token rotates the generation every time, which retires that
		// site's outstanding holds and moves its epoch even though nothing
		// about the site changed. An unnecessary rotation costs a queue; a
		// missed one hands one customer's writes to another.
		//
		// An UNBIND is exempt, and is the only exemption: `unbound` states
		// nobody at all, which is fully determined and cannot be confused with
		// another customer's claim on the same dashboard. Its idempotence is
		// load-bearing — the unbind step is retried until every kind reports
		// clean, and a second pass must not keep minting generations.
		$provable = self::BINDING_UNBOUND === $target || self::identity_is_provable( $client );
		if ( null !== $rec && $provable ) {
			$state = self::normalise_binding( $rec );
			if ( self::BINDING_UNSET !== $state['state']
				&& $state['state'] === $target
				&& ( self::BINDING_UNBOUND === $target || self::identity_is_provable( $state['client'] ) )
				&& $state['client'] === $client
				&& $state['dashboard'] === $dashboard
			) {
				return true;
			}
		}

		$was  = self::epoch(); // read BEFORE the swap, so the rotate below fences on it
		$next = array(
			'gen'       => wp_generate_uuid4(),
			'state'     => $target,
			'client'    => $client,
			'dashboard' => $dashboard,
		);

		$like = $claimed ? $wpdb->esc_like( $fence . '|' ) . '%' : '';
		if ( null === $rec ) {
			// No record at all: a real conditional INSERT, so a concurrent
			// minter cannot be overwritten blind. Under a claim it is the same
			// INSERT with the claim row as its source, so a caller whose claim
			// was taken over mints nothing either.
			if ( $claimed ) {
				$wpdb->last_error = '';
				$rows             = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
						"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) SELECT %s, %s, 'yes' FROM {$wpdb->options} c WHERE c.option_name = %s AND c.option_value LIKE %s AND NOT EXISTS ( SELECT 1 FROM {$wpdb->options} WHERE option_name = %s )",
						self::BINDING,
						maybe_serialize( $next ),
						$claim,
						$like,
						self::BINDING
					)
				);
				$done = ( 1 === (int) $rows && '' === (string) $wpdb->last_error );
			} else {
				$done = self::insert_unique( self::BINDING, $next );
			}
		} elseif ( $claimed ) {
			// The compare-and-swap AND the claim check, in one statement
			// (Ruling P68): a claim seized by a replacement connect between the
			// two would otherwise let a stale cleanup rotate the winner's
			// record out from under it.
			$wpdb->last_error = '';
			$rows             = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"UPDATE {$wpdb->options} o JOIN {$wpdb->options} c ON c.option_name = %s AND c.option_value LIKE %s SET o.option_value = %s WHERE o.option_name = %s AND o.option_value = %s",
					$claim,
					$like,
					maybe_serialize( $next ),
					self::BINDING,
					$raw
				)
			);
			$done = ( 1 === (int) $rows && '' === (string) $wpdb->last_error );
		} else {
			// COMPARE-AND-SWAP on the bytes just read (F1): a transient failure
			// must not read as a rotation that happened, or a changed-client
			// connect would complete with the departed client's holds still
			// current and replayable by the replacement.
			$wpdb->last_error = '';
			$rows             = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
					maybe_serialize( $next ),
					self::BINDING,
					$raw
				)
			);
			$done = ( 1 === (int) $rows && '' === (string) $wpdb->last_error );
		}
		wp_cache_delete( self::BINDING, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		self::forget_live_identity();
		if ( ! $done ) {
			return false;
		}
		// The epoch follows, so the new binding starts at cursor 0. Its own
		// fenced rotate answering 0 rows is a LOST RACE somebody else already
		// settled — the epoch moved either way, which is all this needs.
		self::rotate_epoch( $was );
		return true;
	}

	/**
	 * One option's raw, still-serialised bytes from the DATABASE — never this
	 * request's cache. The predicate a compare-and-swap fences on.
	 *
	 * @param string $name Option name.
	 * @return string|null
	 */
	private static function raw_option( $name ) {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );
		return null === $raw ? null : (string) $raw;
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

	/**
	 * One log row, read for a FENCE (Ruling P74).
	 *
	 * `get()` goes through `get_option()`, which answers null for a missing row
	 * and for a read that failed alike — and the fence used to read that null
	 * as "no stamp, carry on". A rebind landing while the call was admitted
	 * then let the old request enter its mutation at precisely the moment
	 * nothing could prove which binding owned it.
	 *
	 * Three answers, because there are three facts: the row, NULL for a row
	 * that is genuinely absent, and FALSE for a read that failed. The fence
	 * refuses on both of the last two, and tells them apart because only one
	 * of them has anything to record.
	 *
	 * @param int $seq Log seq.
	 * @return array|null|false
	 */
	public static function row_for_fence( $seq ) {
		global $wpdb;
		$wpdb->last_error = '';
		$raw              = self::raw_option( self::PREFIX . (int) $seq );
		if ( '' !== (string) $wpdb->last_error ) {
			return false;
		}
		if ( null === $raw ) {
			return null;
		}
		$val = maybe_unserialize( $raw );
		return is_array( $val ) ? $val : false;
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

	/**
	 * How many rows Aura has not acknowledged — or NULL when that cannot be
	 * read (Ruling P53).
	 *
	 * `get_var()` answers null for a broken statement exactly as it does for a
	 * real zero, and `(int) null` is 0 — so a COUNT that failed while ordinary
	 * option writes still worked reported an EMPTY log. Every admission check
	 * compares this against MAX_UNACKED, so a false zero admitted writes past
	 * the bound for as long as the failure lasted, and `ack()` deleted the
	 * closure marker over a backlog that was still full.
	 *
	 * Null is therefore its own answer, and every caller has to decide what to
	 * do about not knowing. None of them may treat it as zero.
	 *
	 * @return int|null
	 */
	public static function count_unacked() {
		global $wpdb;
		$like             = $wpdb->esc_like( self::PREFIX ) . '%';
		$wpdb->last_error = '';
		$n                = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name REGEXP %s AND CAST(SUBSTRING(option_name, %d) AS UNSIGNED) > %d",
				$like,
				self::ROW_REGEXP,
				strlen( self::PREFIX ) + 1,
				self::floor()
			)
		);
		if ( null === $n || false === $n || '' !== (string) $wpdb->last_error ) {
			return null;
		}
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
		// Reopened only on a READABLE count under the bound (Ruling P53). An
		// unreadable one used to cast to 0 and delete the marker over a
		// backlog that was still full — the door open again with nothing
		// having been acked.
		$unacked = self::count_unacked();
		if ( self::is_closed() && null !== $unacked && $unacked < self::MAX_UNACKED ) {
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
