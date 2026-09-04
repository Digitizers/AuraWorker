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

	/** @var bool Whether this request has already tried to adopt an `unset` record (Ruling P73). */
	private static $binding_adopt_tried = false;
	/** @var int Monotonic counter feeding raw_option_read()'s per-call proof nonce (Ruling S1). */
	private static $raw_read_seq = 0;
	const FULL_MARKER  = 'aura_worker_door_log_full_since';
	const FULL_COUNTER = 'aura_worker_door_log_full_refused';
	/**
	 * The site-issued, monotonic DOOR VERSION (Ruling A65, 2.16.2; the wire
	 * key stays `observation`). Aura orders overlapping `/status` polls of
	 * the same site to decide which door observation is newest, and a
	 * client-side timestamp cannot prove that order — an earlier-started
	 * request can still reach the site later than one started after it.
	 *
	 * A VERSION, not a serve counter (Ruling S6, Codex round-3 P1 on #88).
	 * Bumping on every SERVE let two overlapping requests interleave AROUND
	 * it: request A finishes reading state and pauses right before it takes
	 * its witness; a door mutation lands; request B builds and serves the
	 * NEW state under witness N; A resumes, takes N+1, and serves its OLDER
	 * snapshot under the higher number — Aura would treat A's stale read as
	 * newer than B's. So this counter is bumped by every door-state
	 * MUTATION instead (`bump_door_version()`, called from the choke points
	 * documented on `insert_unique()` and `write_option_where()`, from
	 * `ack()`, from `rotate_epoch()`, from `rotate_binding()`, and from
	 * `Aura_Worker_Door_Holds::forget_held()`), and `status_fragment()`
	 * only READS it (`door_version_raw()`) — once before building the
	 * fragment and once after; the two must agree for the version to be
	 * reported at all. See `status_fragment()`'s own docblock for that
	 * read protocol.
	 *
	 * NEVER reset by rotation, rebind or unbind — it orders mutations
	 * across all of them, which a counter scoped to one binding generation
	 * could not do.
	 */
	const OBSERVATION  = 'aura_worker_door_observation';
	const MAX_UNACKED  = 2000;
	const PAGE         = 100;
	/** How many INSERT collisions to ride through before giving up. */
	const ALLOC_TRIES  = 8;
	/** A log ROW: the prefix followed by digits only — never the floor, marker or counter options that share the prefix. */
	const ROW_REGEXP   = '^aura_worker_door_log_[0-9]+$';

	const TERMINAL = array( 'ok', 'refused', 'failed', 'interrupted', 'discarded', 'held' );

	/**
	 * Names `insert_unique()` mints that carry no reported door state — pure
	 * internal bookkeeping, never read back into `status_fragment()` or
	 * `governor_block()` (Ruling S6). Excluded from the door-version bump
	 * below, or every ack() and every hold acquire/release would advance a
	 * version number that exists to let a POLLER detect a change it could
	 * actually SEE.
	 *
	 * - `self::FLOOR`: the ack floor's own first INSERT is a bookkeeping
	 *   seed (value 0, meaning "nothing acked yet") — identical, as far as
	 *   any reader is concerned, to the floor being absent. The floor's
	 *   later RAISES go through a different statement in `ack()`, which
	 *   bumps for itself.
	 * - `'aura_worker_door_hold_lock'` (mirrors
	 *   `Aura_Worker_Door_Holds::LOCK`, named as a literal rather than a
	 *   cross-class constant reference): a mutex acquisition, not door state
	 *   — the hold or claim it protects bumps once IT lands, through
	 *   `Aura_Worker_Door_Holds::forget_held()`.
	 * - `'aura_worker_door_creating'` (mirrors
	 *   `Aura_Worker_Elementor_Door::CREATING`, same reasoning): the
	 *   creation mutex a governed write takes while it runs — not door
	 *   state either, and the log row its creation eventually settles is
	 *   what bumps, through `write_option_where()`.
	 */
	const VERSION_EXEMPT_INSERTS = array( self::FLOOR, 'aura_worker_door_hold_lock', 'aura_worker_door_creating' );

	/**
	 * Creates an option row only when none exists — a real mutex, unlike
	 * add_option(), whose INSERT … ON DUPLICATE KEY UPDATE lets a racer that
	 * passed the cached existence check overwrite and still return true.
	 * The shape is the one Aura_Worker_Magic_Link::claim_magic_link() uses
	 * (class-aura-worker-magic-link.php), and every door-side "INSERT" goes
	 * through this one primitive so seq allocation, the epoch and the
	 * closure marker cannot lose a race to a silent overwrite.
	 *
	 * A CHOKE POINT FOR THE DOOR VERSION (Ruling S6, Codex round-3 P1 on
	 * #88; made TRANSACTIONAL with the write by Ruling S8, Codex round-4 P1):
	 * a NEW row is door state that did not exist a moment ago — a log row
	 * (`open_pending()`), a held or claimed row (`Aura_Worker_Door_Holds`'
	 * `hold()`/`claim()`/`unclaim()`), the epoch, the closure marker, a
	 * binding record's first mint — so every successful insert bumps the
	 * version in the SAME transaction as the insert itself (`versioned()`),
	 * except the two names in `VERSION_EXEMPT_INSERTS` above, which skip
	 * `versioned()` entirely — pure internal bookkeeping never worth a
	 * transaction's overhead. Bumping here, ONCE, covers every one of those
	 * callers without instrumenting each of them separately.
	 *
	 * NEVER CALL THIS FROM INSIDE AN ALREADY-OPEN TRANSACTION (see
	 * `versioned()`'s own docblock) — `rotate_binding()` needs an insert
	 * shaped exactly like this one's, from inside its OWN transaction, and
	 * uses a private write-only twin (`bindingless_insert()`) rather than
	 * this method for exactly that reason.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value; serialized like an option.
	 * @return bool True only when exactly one row was inserted (and, for a
	 *              non-exempt name, its version bump also landed).
	 */
	public static function insert_unique( $name, $value ) {
		if ( in_array( $name, self::VERSION_EXEMPT_INSERTS, true ) ) {
			return self::insert_unique_write( $name, $value );
		}
		$outcome = self::versioned(
			function () use ( $name, $value ) {
				$won = self::insert_unique_write( $name, $value );
				return array(
					'mutated' => $won,
					'result'  => $won,
					// Ruling S11 (Codex round-5 P1 on #88): repeated by
					// versioned() AFTER commit, in case a concurrent
					// request repopulated the cache from the pre-commit
					// snapshot before the commit landed.
					'evict'   => array( $name, 'notoptions', 'alloptions' ),
				);
			}
		);
		return $outcome['committed'] && $outcome['result'];
	}

	/**
	 * The insert alone, with no version bump and no transaction of its own —
	 * `insert_unique()`'s body before Ruling S8, kept as the primitive every
	 * versioned wrapper (this class's own `insert_unique()`, and
	 * `rotate_binding()`'s inline mint) actually issues.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value; serialized like an option.
	 * @return bool True only when exactly one row was inserted.
	 */
	private static function insert_unique_write( $name, $value ) {
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
		// NO EVICTION HERE (Ruling P88 follow-up). A raw `$wpdb` read bypasses
		// every cache by construction, so flushing them first buys this reader
		// nothing — and `insert_unique()`, which is the WRITE that just ran,
		// has already evicted the name and `notoptions` for the callers that
		// come after. Evicting `alloptions` on a site with a persistent object
		// cache would discard every autoloaded option, per mint.
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
		// THE AUTHENTICATED GENERATION WINS (Ruling P76). A request that passed
		// authentication under binding A must have its rows stamped A, even if
		// an unbind completed while it was between the permission callback and
		// here — otherwise the row carries the CURRENT generation, the fence
		// compares it with itself, and a write whose credentials were revoked
		// before it started is waved through. A context with no capture
		// (WP-CLI, cron, direct PHP) falls through to the record as it stands,
		// which is what every writer did before.
		if ( class_exists( 'Aura_Worker_Call_Context' ) ) {
			$authed = Aura_Worker_Call_Context::authenticated_binding();
			if ( Aura_Worker_Call_Context::BINDING_UNREADABLE === $authed ) {
				// This request authenticated and its binding could not be
				// established (Ruling P79). Falling back to the record as it
				// stands is the bug: an unbind minting or rotating it in
				// between would be compared with itself. Null refuses every
				// admission — nothing written, nothing run.
				return null;
			}
			if ( null !== $authed && '' !== (string) $authed ) {
				return (string) $authed;
			}
		}
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
	 * THE STATE IS WHAT THE CONNECT ASKS (Ruling P75). A connect meeting a
	 * record that says `bound` to a DIFFERENT client refuses outright
	 * (`aura_site_bound`) and writes nothing, so the identity on this record
	 * cannot change while the generation stands: a rebind is an unbind — which
	 * rotates to `unbound` — followed by a connect. That is why every
	 * currentness test is now generation equality alone; nothing has to
	 * re-compare the identity, because nothing can move it.
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
			// The epoch this record was written WITH (Ruling P81). Absent on a
			// record written before that rule, which reads as '' and therefore
			// as "differs" — so the next same-identity connect repairs it.
			'epoch'     => isset( $rec['epoch'] ) ? (string) $rec['epoch'] : '',
		);
	}

	/**
	 * The identity this site is bound to RIGHT NOW, as the connect stores it.
	 *
	 * `client` comes from the ruleset store's binding sentinel through
	 * `Aura_Worker_Rules::bound_client()` (which proves it against the site's
	 * current token), and `dashboard` from the `aura_worker_dashboard_url`
	 * option. Those are the two things a connect writes to say whose site this
	 * is.
	 *
	 * NO LONGER CACHED (Ruling P75). It used to be read once per request and
	 * held in a static, because every currentness test compared against it. It
	 * has ONE reader now — the adoption of an `unset` record, which itself runs
	 * at most once per request — so a cache would be a second copy of a value
	 * nothing else asks for. Currentness is generation equality alone.
	 *
	 * @return array{ client: string|null, dashboard: string|null }
	 */
	public static function live_identity() {
		$client    = class_exists( 'Aura_Worker_Rules' ) ? (string) Aura_Worker_Rules::bound_client() : '';
		$dashboard = (string) get_option( 'aura_worker_dashboard_url', '' );
		return array(
			'client'    => '' === $client ? null : $client,
			'dashboard' => '' === $dashboard ? null : $dashboard,
		);
	}

	/**
	 * Forget what this request has already decided about the binding.
	 *
	 * Since Ruling P75 that is one thing: whether the `unset` record has been
	 * offered for adoption. A rotation calls it (the record it would adopt is
	 * gone), and so does the test harness between cases.
	 *
	 * @return void
	 */
	public static function forget_live_identity() {
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
		//
		// AND ITS ANSWER IS A CONDITION OF THE WRITE (Ruling P96). The mint's
		// result was ignored, so a transient failure to insert or read the
		// epoch left it empty while the row insert below went on to succeed —
		// and if Elementor was disabled before anything else minted one,
		// `present()` saw neither an active module nor its sole persisted
		// witness. `/status` then omitted the outstanding row for ever and no
		// reconciler ever swept it. Door state is never stored without the
		// witness that makes it findable.
		if ( '' === (string) self::epoch() ) {
			return new WP_Error( 'aura_log_failed', 'This site could not establish its door log epoch; the call was not run.', array( 'status' => 503 ) );
		}
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
			// CANNOT READ THE TOP, CANNOT ALLOCATE (Ruling P77). A null top used
			// to cast to 0, so the next seq was computed from the floor alone —
			// and on a site whose floor read was ALSO stale that hands out a
			// number a row already owns. Retryable: nothing has been reserved.
			$top = self::highest_row_seq();
			if ( null === $top ) {
				return new WP_Error( 'aura_log_failed', 'The door log could not be read; this call was not run.', array( 'status' => 503 ) );
			}
			$seq = max( $top, self::floor() ) + 1;
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
	 * The fence's own read: the binding GENERATION, taken from the DATABASE
	 * past every cache (Ruling P64).
	 *
	 * The caches are the bug it was written for. By the time a replay reaches
	 * its fence it has already been through `get_held()`, which populated
	 * WordPress's option cache for the binding record — and a rebind completing
	 * in ANOTHER PHP process invalidates it, because it lives in this process's
	 * memory. The fence would then compare the claim against a generation that
	 * was current when this request started and has not been since.
	 *
	 * ONE ROW (Ruling P75). It used to read the live identity as well and
	 * compare it with the record's, because a connect could publish a new
	 * identity over a live binding and the generation would not move until the
	 * rotation landed. A connect cannot do that any more — it refuses
	 * (`aura_site_bound`) — so the identity cannot change under a live binding,
	 * and generation equality is the whole test.
	 *
	 * FAILS CLOSED: a read error answers `ok: false`, and the fence refuses. A
	 * door that cannot prove whose it is does not run a mutation.
	 *
	 * @return array{ ok: bool, gen: string }
	 */
	public static function fence_identity() {
		// PROVEN, not just error-free (Ruling S1, Codex round-1 P2 on #87): a
		// `query` filter that blanks the statement, or an unready handle,
		// leaves `last_error` untouched and hands back the PREVIOUS
		// statement's answer — checking the error string alone read that as a
		// successful read of THIS one. `raw_option_read()`'s `ok` is the
		// freshness proof; a false one is exactly as unreadable as a driver
		// error.
		$read = self::raw_option_read( self::BINDING );
		if ( ! $read['ok'] ) {
			return array( 'ok' => false, 'gen' => '' );
		}
		$rec = null === $read['value'] ? null : maybe_unserialize( $read['value'] );
		// No record at all is not a failure — it is a site nobody has stated
		// anything about — but it matches no stamped row, and an EMPTY stamp is
		// never current either (Ruling P72).
		$gen = is_array( $rec ) && isset( $rec['gen'] ) ? (string) $rec['gen'] : '';
		return array( 'ok' => true, 'gen' => $gen );
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
		// GENERATION EQUALITY IS THE WHOLE TEST (Ruling P75) — and an EMPTY
		// stamp is never current (Ruling P72).
		return '' !== (string) $gen && (string) $gen === $f['gen'];
	}

	/**
	 * Is this generation the CURRENT one (Ruling P75)?
	 *
	 * It used to ask a second question as well — whether the record still
	 * described the identity the site is live under — because a connect could
	 * publish a new client over a live binding while the generation stayed put
	 * until its rotation landed. A connect refuses that outright now
	 * (`aura_site_bound`), so a rebind is an unbind followed by a connect and
	 * no identity can move under a live generation. What is left is the
	 * comparison the generation exists for.
	 *
	 * @param string $gen The generation stamped on a row.
	 * @return bool
	 */
	public static function generation_is_live( $gen ) {
		$rec = self::binding_record();
		// GENERATION EQUALITY IS THE WHOLE TEST (Ruling P75) — and an EMPTY
		// stamp is never current (Ruling P72).
		return '' !== (string) $gen && (string) $gen === (string) $rec['gen'];
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
	/**
	 * Re-stamp the binding record's EPOCH witness, and nothing else (Ruling
	 * P91).
	 *
	 * The grant-gated `/door/rotate` moves the log cursor legitimately and
	 * touches no binding — but the record still names the epoch it was written
	 * with, and P81's repair reads a disagreement as a half-done rebind. So the
	 * next same-identity connect performed a FULL rebind: a new generation for
	 * an identity that never changed, holds queued since the rotation gone
	 * foreign, in-flight writes failing their fence. A rewind cost the site its
	 * queue.
	 *
	 * This is the other half of that rule: a rotation that legitimately moves
	 * the cursor says so on the record. The compare-and-swap is fenced on the
	 * bytes just read and changes ONLY `epoch` — never the generation, the
	 * state or the identity — so it cannot hand the site to anybody, which is
	 * why it needs no site claim (the route holds none: it is Aura moving the
	 * cursor it owns, not a rebind).
	 *
	 * JOINED TO THE EPOCH ROW (Ruling P92). Fencing on the record's bytes
	 * alone was not enough: this rotation can pause after minting B while a
	 * concurrent rotation or rebind installs C and stamps the record with it,
	 * and the resumed call would then overwrite C's witness with a stale B —
	 * manufacturing exactly the disagreement it exists to prevent, and costing
	 * the site its queue on the next connect. The stamp lands only while the
	 * LIVE epoch is still the one being stamped.
	 *
	 * Zero rows has two meanings and they are told apart by re-reading the
	 * epoch: if it is no longer ours, a LATER rotation owns the witness now and
	 * there is nothing left for this call to do — true, nothing owed. If it is
	 * still ours, the record's bytes moved underneath, so re-read and try once
	 * more (the winner may be a rebind that stamped it already). Still failing
	 * with the epoch ours is the genuinely stale case — not fatal, since P81's
	 * repair on the next connect is the documented fallback, so the caller is
	 * told rather than refused.
	 *
	 * @param string $epoch The epoch the record should now name.
	 * @return bool The record names it, or a later rotation owns it.
	 */
	public static function restamp_binding_epoch( $epoch ) {
		global $wpdb;
		$epoch = (string) $epoch;
		if ( '' === $epoch ) {
			return false;
		}
		for ( $try = 0; $try < 2; $try++ ) {
			if ( $epoch !== self::epoch_raw() ) {
				return true; // a later rotation owns the witness; this call owes nothing
			}
			$raw = self::raw_option( self::BINDING );
			$rec = null === $raw ? null : maybe_unserialize( $raw );
			if ( ! is_array( $rec ) || ! isset( $rec['gen'] ) ) {
				return false; // no record to stamp; the next reader mints one with the current epoch
			}
			if ( isset( $rec['epoch'] ) && $epoch === (string) $rec['epoch'] ) {
				return true;
			}
			$next             = $rec;
			$next['epoch']    = $epoch;
			$wpdb->last_error = '';
			$rows             = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"UPDATE {$wpdb->options} r JOIN ( SELECT option_value AS e FROM {$wpdb->options} WHERE option_name = %s ) x SET r.option_value = %s WHERE r.option_name = %s AND r.option_value = %s AND x.e = %s",
					self::EPOCH,
					maybe_serialize( $next ),
					self::BINDING,
					$raw,
					$epoch
				)
			);
			wp_cache_delete( self::BINDING, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			if ( 1 === (int) $rows && '' === (string) $wpdb->last_error ) {
				return true;
			}
		}
		// Still not stamped, and the epoch is still ours: genuinely stale.
		return $epoch !== self::epoch_raw();
	}

	/**
	 * The log epoch as the DATABASE has it, never minted (Ruling P81).
	 *
	 * `epoch()` mints when the row is absent, which is right for a reader and
	 * wrong for the comparison that decides whether an earlier rebind finished:
	 * a minted epoch would agree with nothing and read as a repair that is not
	 * needed.
	 *
	 * @return string '' when there is no epoch row.
	 */
	public static function epoch_raw() {
		$raw = self::raw_option( self::EPOCH );
		$val = null === $raw ? null : maybe_unserialize( $raw );
		return is_string( $val ) ? $val : '';
	}

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
	 * CLAIM-CONDITIONED, ALWAYS (Ruling P78). The claim key and fence are
	 * REQUIRED — there is no unconditional form, because every caller that has
	 * one is in the middle of a lifecycle operation that another request can
	 * take the site away from mid-flight, and the one caller that forgot to
	 * pass them (the connect) is exactly how the winner's generation got
	 * rotated by a stale handler.
	 *
	 * TWO checks, not one. `holds_site_claim()` immediately before the write
	 * so a caller that has already lost the site does not even try, and the
	 * claim row JOINED INTO the statement so the answer cannot go stale
	 * between asking and acting.
	 *
	 * CLAIM-CONDITIONAL (Ruling P68). An unbind's
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
	 * @param string $claim    Site-claim option name. REQUIRED.
	 * @param string $fence    The caller's claim fence. REQUIRED.
	 * @return bool The record now names this identity.
	 */
	public static function rotate_binding( array $identity, $claim, $fence ) {
		global $wpdb;
		$claim = (string) $claim;
		$fence = (string) $fence;
		if ( '' === $claim || '' === $fence ) {
			return false; // a rotation with nothing holding the site is not one
		}
		// The claim as it stands NOW, before anything is read or written. The
		// join below is what makes the answer binding; this is what stops a
		// caller that already knows it lost the site from doing the work.
		if ( class_exists( 'Aura_Worker_Magic_Link' ) && ! Aura_Worker_Magic_Link::holds_site_claim( $fence ) ) {
			return false;
		}
		$claimed = true;
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
				// …AND THE EPOCH IT WAS WRITTEN WITH IS STILL THE SITE'S
				// (Ruling P81). A rebind whose record CAS failed after the
				// epoch had already rotated leaves the two disagreeing, and the
				// identity-equal shortcut used to declare that finished: the new
				// binding stayed on the PREVIOUS epoch, and a previously
				// authenticated ack carrying it could advance the floor over
				// the new binding's own entries. When they differ the rotation
				// runs again — a retry repairs it, which is the whole point of
				// the shortcut being idempotent rather than merely fast.
				//
				// A record with no `epoch` predates this rule and reads as
				// differing exactly once, which repairs it too.
				&& '' !== (string) $state['epoch']
				&& (string) $state['epoch'] === self::epoch_raw()
			) {
				return true;
			}
		}

		// THE EPOCH MOVES FIRST, AND ITS SUCCESS IS PART OF THE REBIND (Ruling
		// P81). It used to follow the record's compare-and-swap with its answer
		// ignored: a failed rotation left the NEW binding sitting on the
		// PREVIOUS epoch and the rebind still reported success. Nothing
		// repaired it either — the next connect states the same identity, the
		// shortcut above declared it done — so an ack authenticated under the
		// old binding, carrying that same epoch, could advance the floor over
		// the new binding's log entries.
		//
		// Rotating first makes the failure harmless: nothing else has changed,
		// so the record still names the old binding and the caller's retry
		// simply does the whole thing again.
		$was = self::epoch(); // read BEFORE the rotation, which fences on it
		// The claim, again, immediately before the epoch moves (Ruling P83).
		// The read above and the identity comparison take time, and a handler
		// that has lost the site in the meantime must not move the winner's
		// cursor — the join below is the binding answer, this is the one that
		// stops the work being done at all.
		if ( class_exists( 'Aura_Worker_Magic_Link' ) && ! Aura_Worker_Magic_Link::holds_site_claim( $fence ) ) {
			return false;
		}
		// A CHOKE POINT FOR THE DOOR VERSION (Ruling S6, Codex round-3 P1 —
		// "the binding rotation" the ruling names explicitly; made
		// TRANSACTIONAL by Ruling S8, Codex round-4 P1 on #88). The epoch
		// rotation and the binding record's own write are ONE mutation, so
		// they run in ONE `versioned()` unit — never two, which used to leave
		// a window where a reader could see the new epoch paired with the
		// OLD binding record under a version that had only counted the
		// first of the two writes. `rotate_epoch_write()` — the WRITE-ONLY
		// core, never the public `rotate_epoch()` — is called from inside
		// this SAME transaction; calling the public one would nest a second
		// `START TRANSACTION`, which `versioned()`'s own docblock explains
		// MySQL has no way to honour.
		$outcome = self::versioned(
			function () use ( $was, $claim, $fence, $target, $client, $dashboard, $rec, $raw, $claimed ) {
				global $wpdb;
				$rot = self::rotate_epoch_write( $was, $claim, $fence );
				if ( ! empty( $rot['rollback'] ) ) {
					// Ruling S12: rotate_epoch_write() itself hit a failed
					// replacement insert and asked for the WHOLE unit to
					// roll back — propagated here rather than swallowed,
					// or this closure's own 'mutated' => false would tell
					// versioned() to COMMIT, landing the epoch's DELETE
					// with no replacement row after all.
					return array(
						'rollback' => true,
						'result'   => false,
					);
				}
				$new_epoch = (string) ( $rot['result']['epoch'] ?? '' );
				if ( empty( $rot['result']['rotated'] ) || '' === $new_epoch || $new_epoch === $was ) {
					return array(
						'mutated' => false,
						'result'  => false,
					); // the record is untouched; the retry starts over
				}
				$next = array(
					'gen'       => wp_generate_uuid4(),
					'state'     => $target,
					'client'    => $client,
					'dashboard' => $dashboard,
					// WHICH EPOCH THIS BINDING BELONGS TO: the witness the
					// shortcut above compares, so a half-done rebind is
					// visible to the retry.
					'epoch'     => $new_epoch,
				);

				$like = $claimed ? $wpdb->esc_like( $fence . '|' ) . '%' : '';
				if ( null === $rec ) {
					// No record at all: a real conditional INSERT, so a
					// concurrent minter cannot be overwritten blind. Under a
					// claim it is the same INSERT with the claim row as its
					// source, so a caller whose claim was taken over mints
					// nothing either.
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
						// Dead today ($claimed is always true above), kept for
						// completeness. insert_unique_write() — NEVER the
						// public insert_unique() — since this already runs
						// inside versioned()'s own transaction.
						$done = self::insert_unique_write( self::BINDING, $next );
					}
				} elseif ( $claimed ) {
					// The compare-and-swap AND the claim check, in one
					// statement (Ruling P68): a claim seized by a replacement
					// connect between the two would otherwise let a stale
					// cleanup rotate the winner's record out from under it.
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
					// COMPARE-AND-SWAP on the bytes just read (F1): a
					// transient failure must not read as a rotation that
					// happened, or a changed-client connect would complete
					// with the departed client's holds still current and
					// replayable by the replacement.
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
				if ( ! $done ) {
					// Ruling S14 (Codex round-6 P1 on #88): by this point the
					// epoch rotation above has ALREADY succeeded — every
					// earlier return in this closure covers `$rot`'s own
					// failure or no-op, so reaching here means `rotate_epoch_write()`
					// really did replace the epoch. Answering `mutated =>
					// false` told versioned() nothing happened and to COMMIT
					// — which would durably publish the NEW epoch while the
					// binding record still named the OLD one, discarding
					// every acknowledgement in flight against it with no
					// binding rotation to show for the churn. The whole unit
					// rolls back instead — the epoch rotation included — so a
					// failed record write leaves both the epoch and the
					// binding exactly as they were, safe for the caller's
					// retry to redo in full.
					return array(
						'rollback' => true,
						'result'   => false,
					);
				}
				return array(
					'mutated' => true,
					'result'  => true,
					// Ruling S11: repeated by versioned() after commit.
					'evict'   => array( self::BINDING, 'notoptions', 'alloptions' ),
				);
			}
		);
		wp_cache_delete( self::BINDING, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		self::forget_live_identity();
		// The epoch has already moved, so a failed record write leaves the site
		// on a fresh cursor with the OLD binding — visible to the retry as an
		// epoch the record does not name, and repaired by it. A bump's own
		// WRITE failing rolls the WHOLE unit back (Ruling S8) — the epoch
		// rotation included — so `committed: false` here means neither
		// happened, exactly like the record write failing outright always
		// meant.
		return $outcome['committed'] && $outcome['result'];
	}

	/**
	 * One option's raw, still-serialised bytes from the DATABASE, PROVEN to
	 * have been read (Ruling S1, Codex round-1 P2 on #87).
	 *
	 * wpdb::get_var()/get_row() extract their answer from `$last_result`,
	 * populated by whichever statement ran LAST — and wpdb::query() has two
	 * early returns before its flush() that leave `$last_result` exactly as
	 * the previous statement left it: an unready handle, and a `query` filter
	 * that blanks the SQL. Neither touches `last_error`, so a caller that
	 * only checks the error string is empty cannot tell a proven read from a
	 * statement that never ran at all — this is what let
	 * `binding_raw()`/`epoch_raw()` answer an earlier generation instead of
	 * `''` when the statement between two reads was suppressed.
	 *
	 * `$wpdb->last_query` is NOT the proof (unlike the two-step read this
	 * replaced): comparing it to "the SQL just issued" is blind to the one
	 * case that matters most — the SAME option read twice in a row, where the
	 * suppressed call's prepared string is BYTE-IDENTICAL to the proven
	 * call's, so the comparison passes on a stale answer. Nor is
	 * `$wpdb->ready` — a third-party `db.php` drop-in is free to never set
	 * it, which would strand every caller here on "unproven" forever.
	 *
	 * The proof travels IN BAND instead, the same shape
	 * `aura_worker_app_password_list()` already established
	 * (includes/credential-rules.php): a per-call nonce selected alongside
	 * the value in ONE statement, via `get_row()` — only OUR OWN statement
	 * can put THIS call's nonce in the row that comes back. A stale row
	 * carries an EARLIER call's nonce (or none), so the comparison alone
	 * tells a proven read from an unproven one, whatever `last_query` or
	 * `ready` say and however many times the same option is read in a row.
	 *
	 * @param string $name Option name.
	 * @return array{ ok: bool, value: string|null } `ok` false ⇒ UNPROVEN — a
	 *         stale answer or a driver failure, never trustworthy; `value` is
	 *         the row's content only when `ok` is true (null there means the
	 *         row is genuinely absent).
	 */
	private static function raw_option_read( $name ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->options ) ) {
			return array( 'ok' => false, 'value' => null );
		}
		$wpdb->last_error = '';
		++self::$raw_read_seq;
		// wp_generate_uuid4(), not wp_generate_password(): the latter is
		// PLUGGABLE and filtered through `random_password`, and a constant
		// nonce is no nonce at all — the same reasoning credential-rules.php
		// documents for the app-password proof. The monotonic counter is the
		// belt: nothing outside this function can make two of its calls
		// agree even if a filter pinned the randomiser.
		$nonce = self::$raw_read_seq . '-' . wp_generate_uuid4();
		$sql   = $wpdb->prepare(
			"SELECT %s AS probe, (SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1) AS v",
			$nonce,
			$name
		);
		if ( ! is_string( $sql ) || '' === $sql ) {
			return array( 'ok' => false, 'value' => null ); // prepare() refused: nothing was issued
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql );
		$ok  = is_object( $row ) && isset( $row->probe ) && $nonce === (string) $row->probe && '' === (string) $wpdb->last_error;
		return array(
			'ok'    => $ok,
			'value' => ( $ok && isset( $row->v ) && null !== $row->v ) ? (string) $row->v : null,
		);
	}

	/**
	 * One option's raw, still-serialised bytes from the DATABASE — never this
	 * request's cache. The predicate a compare-and-swap fences on.
	 *
	 * UNPROVEN collapses into the same `null` a genuinely absent row answers
	 * (Ruling S1) — every caller here already treats null as "cannot
	 * establish" and fails closed on it; only `fence_identity()` and
	 * `row_for_fence()` need the two told apart, and read `raw_option_read()`
	 * directly for that.
	 *
	 * @param string $name Option name.
	 * @return string|null
	 */
	private static function raw_option( $name ) {
		return self::raw_option_read( $name )['value'];
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
	 * THE OTHER CHOKE POINT FOR THE DOOR VERSION (Ruling S6, Codex round-3 P1
	 * on #88; made TRANSACTIONAL with the write by Ruling S8, Codex round-4
	 * P1): every row this touches is an EXISTING piece of door state
	 * changing shape — `admit()`/`settle()`/`annotate()`/`patch_pending()`
	 * on a log row, and `Aura_Worker_Door_Holds`'
	 * `refresh_rule()`/`refresh_touches()`/`stamp_terminal_seq()` on a held
	 * or claimed one — so a successful write bumps the version in the SAME
	 * transaction as the update itself (`versioned()`). Some of those Holds
	 * callers also go on to call `forget_held()`, which is now a pure cache
	 * invalidation and no longer bumps a second time (see its own docblock)
	 * — one write, one bump, one transaction.
	 *
	 * NEVER CALL THIS FROM INSIDE AN ALREADY-OPEN TRANSACTION (see
	 * `versioned()`'s own docblock).
	 *
	 * @param string $option Option name.
	 * @param array  $after  New value.
	 * @param array  $before The value the caller read (the predicate).
	 * @return bool
	 */
	public static function write_option_where( $option, array $after, array $before ) {
		$outcome = self::versioned(
			function () use ( $option, $after, $before ) {
				$won = self::write_option_where_write( $option, $after, $before );
				return array(
					'mutated' => $won,
					'result'  => $won,
					// Ruling S11: repeated by versioned() after commit.
					'evict'   => array( $option, 'notoptions' ),
				);
			}
		);
		return $outcome['committed'] && $outcome['result'];
	}

	/**
	 * The update alone, with no version bump and no transaction of its own.
	 *
	 * @param string $option Option name.
	 * @param array  $after  New value.
	 * @param array  $before The value the caller read (the predicate).
	 * @return bool
	 */
	private static function write_option_where_write( $option, array $after, array $before ) {
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
		// PROVEN, not just error-free (Ruling S1, Codex round-1 P2 on #87):
		// see raw_option_read()'s docblock. A false `ok` is the same
		// "unreadable" this fence already answers `false` for on a driver
		// error — a stale answer must not be mistaken for the row's current
		// content, or for the row being genuinely absent.
		$read = self::raw_option_read( self::PREFIX . (int) $seq );
		if ( ! $read['ok'] ) {
			return false;
		}
		if ( null === $read['value'] ) {
			return null;
		}
		$val = maybe_unserialize( $read['value'] );
		return is_array( $val ) ? $val : false;
	}

	/** @return int */
	public static function floor() {
		return (int) get_option( self::FLOOR, 0 );
	}

	/**
	 * The highest seq that has a row — or NULL when that cannot be established
	 * (Ruling P77).
	 *
	 * `get_var()` answers null both for "no rows" and for a statement that
	 * failed at the driver, and `(int)` turned both into 0 — a valid-looking
	 * top. A legitimate `door_after` above the ack floor then read as a REWIND:
	 * `/status` reported one, Aura rotated a healthy epoch, invalidated an
	 * in-flight ack and resynchronised the whole log, with nothing having been
	 * rewound at all. `open_pending()` allocated from a top of 0, and `ack()`
	 * clamped a real cursor down to the floor.
	 *
	 * Not knowing is not zero. An empty log IS zero — a null with no
	 * `last_error` — and that is the only thing that answers 0.
	 *
	 * @return int|null
	 */
	public static function highest_row_seq() {
		global $wpdb;
		$wpdb->last_error = '';
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
		if ( false === $n || '' !== (string) $wpdb->last_error ) {
			return null;
		}
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
		if ( self::insert_unique( self::FULL_MARKER, gmdate( 'c' ) ) ) {
			return true;
		}
		// A LOST insert is a closure too — somebody else's marker is under that
		// name — but a FAILED one is not (Ruling P82), and `insert_unique()`
		// answers false to both. Ask the row: the marker is either there or it
		// is not, and a closure nobody can prove is not one.
		return null !== self::raw_option( self::FULL_MARKER );
	}

	/**
	 * Atomic increment, no row per refusal — versioned with its own upsert
	 * (Ruling S9, Codex round-4 P2 on #88): `log_full.refused` is reported
	 * in both `status_fragment()` and `governor_block()`, so a refusal that
	 * changes it must advance the version in the SAME transaction, or a
	 * later poll can see the new count under an unchanged observation.
	 */
	public static function bump_refused() {
		self::versioned(
			function () {
				global $wpdb;
				$wpdb->query(
					$wpdb->prepare(
						"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no') ON DUPLICATE KEY UPDATE option_value = option_value + 1",
						self::FULL_COUNTER
					)
				);
				wp_cache_delete( self::FULL_COUNTER, 'options' );
				return array(
					'mutated' => true, // an upsert counter always changes something
					'result'  => null,
					// Ruling S11: repeated by versioned() after commit.
					'evict'   => array( self::FULL_COUNTER ),
				);
			}
		);
	}

	/** @var int|null Test seam: stands in for PHP_INT_SIZE in bump_door_version()'s Ruling S7 check. Never read by production code. */
	private static $int_size_override_for_tests = null;

	/** @param int|null $bytes Test seam. */
	public static function set_int_size_for_tests( $bytes ) {
		self::$int_size_override_for_tests = $bytes;
	}

	/** @var bool|null Cached once per request (Ruling S13): is wp_options a transactional (InnoDB) table? */
	private static $engine_transactional = null;

	/** @param bool|null $value Test seam: overrides engine_is_transactional()'s answer. Never set by production code. */
	public static function set_engine_transactional_for_tests( $value ) {
		self::$engine_transactional = $value;
	}

	/**
	 * Is `wp_options` a TRANSACTIONAL table (Ruling S13, Codex round-5 P2 on
	 * #88)? `START TRANSACTION`/`ROLLBACK` are silent no-ops on a
	 * non-transactional engine such as MyISAM — the writes inside `versioned()`
	 * would still land, immediately and unconditionally, and a "rollback"
	 * would fail to undo them without saying so. Checked ONCE per request via
	 * `SHOW TABLE STATUS` and cached — a real second query every mutation
	 * would cost more than the whole point of batching state and the version
	 * bump into one round trip. An UNREADABLE answer counts as
	 * NON-transactional: a table this method cannot PROVE supports rollback
	 * is treated as one that does not, the fail-closed direction — assuming
	 * the opposite is exactly what would silently lose writes on an engine
	 * that cannot honour them.
	 *
	 * @return bool
	 */
	private static function engine_is_transactional() {
		if ( null !== self::$engine_transactional ) {
			return self::$engine_transactional;
		}
		global $wpdb;
		$wpdb->last_error = '';
		$row    = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->options ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$engine = ( is_object( $row ) && isset( $row->Engine ) ) ? strtoupper( (string) $row->Engine ) : '';
		self::$engine_transactional = ( '' === (string) $wpdb->last_error && 'INNODB' === $engine );
		return self::$engine_transactional;
	}

	/**
	 * Bump the per-site DOOR VERSION atomically and hand back the value THIS
	 * call produced — a counter Aura orders overlapping `/status` polls by
	 * (Ruling A65), and since Ruling S6 (Codex round-3 P1 on #88) a WRITE
	 * primitive rather than a per-serve one: see `insert_unique()` and
	 * `write_option_where()`'s docblocks for the choke points that call this,
	 * and `status_fragment()`'s for how it is READ. The same upsert shape
	 * `bump_refused()` uses, so two overlapping mutations cannot both land on
	 * the same number and neither can silently lose the other's increment.
	 *
	 * THE INCREMENT-PLUS-READ MUST BE ONE ATOMIC OPERATION TOO (Ruling S2,
	 * Codex round-1 P1 on #88). A separate `raw_option_read()` after the
	 * upsert is a re-read of the SHARED row: request A can land its upsert,
	 * request B can land its own upsert on top, and A's read then observes
	 * B's value — both calls can answer the same number, and neither is
	 * proven to be the one THIS call actually produced. `get_option()` would
	 * be worse still, answering this process's cache rather than what either
	 * statement committed.
	 *
	 * So the value is captured IN THE SAME STATEMENT, via MySQL's
	 * `LAST_INSERT_ID(expr)` — an old, portable trick (MySQL 5.7, MySQL 8.0
	 * and MariaDB all document and support it identically) for minting a
	 * multi-writer-safe sequence from a non-AUTO_INCREMENT column: the upsert
	 * assigns the new value AND sets it as this CONNECTION's session-level
	 * `LAST_INSERT_ID()` in the same round trip, and the very next statement
	 * on the SAME connection — `SELECT LAST_INSERT_ID()`, no WHERE, no table
	 * — reads that session variable back. It is immune to the race above by
	 * construction: `LAST_INSERT_ID()` is scoped to the connection that set
	 * it, so another request's later upsert on another connection cannot
	 * touch what THIS connection's session remembers, whatever it does to
	 * the shared row in between.
	 *
	 * CLOCK-FLOORED (Ruling S4, Codex round-2 P1 on #88). A plain increment
	 * is not enough: `wp_options` can be restored from a backup — a snapshot
	 * predating this row's later bumps — and a counter restored to a lower
	 * value resumes REISSUING numbers it already served before the backup
	 * was taken, which Aura's ordering depends on being unique. So every
	 * assignment is `GREATEST( current + 1, <wall-clock microseconds> )`:
	 * a restore can roll the STORED value back, but it cannot roll the
	 * CLOCK back, so the very next bump after a restore resumes above every
	 * value this site issued before it, never repeating one. The only way
	 * this still repeats a value is the wall clock itself stepping
	 * backwards (an NTP correction, a manually adjusted server clock) —
	 * accepted: that is a fault in the host's own time, not in this
	 * counter, and no counter can order events across it.
	 * `GREATEST(1, …)` in the VALUES clause covers the first-ever insert the
	 * same way a plain `LAST_INSERT_ID(1)` would have covered it, and also
	 * means a fresh site's FIRST witness is the clock value, not a bare `1`
	 * — a value a restored backup could trivially reissue is never handed
	 * out even once. `CAST(option_value AS UNSIGNED)` is required because
	 * the column is a serialised TEXT value: MySQL's implicit string-to-int
	 * coercion in an unadorned `option_value + 1` is not guaranteed against
	 * every stored representation the way an explicit CAST is.
	 *
	 * 32-BIT PHP CANNOT REPRESENT THE CLOCK — OR THE ANSWER — AS AN INT
	 * (Ruling S7, Codex round-3 P2 on #88). Today's microsecond timestamp
	 * (~1.7e15) is already past a 32-bit build's `PHP_INT_MAX` (~2.1e9), so
	 * `(int) floor( microtime( true ) * 1000000 )` would silently overflow
	 * or clamp there, corrupting the very value the clock floor exists to
	 * make trustworthy. The WRITE side never risks it: `microtime( false )`'s
	 * two pieces — whole seconds (~1.7e9) and the microsecond fraction
	 * (0-999999) — each fit comfortably in a 32-bit int on their own, and
	 * `sprintf( '%d%06d', … )` concatenates their DECIMAL DIGITS into the
	 * same 16-digit value as text, bound with `%s` rather than `%d` — never
	 * assembled as a single PHP int at all. MySQL then parses that string in
	 * its OWN 64-bit integer domain when it evaluates `GREATEST`/`CAST`,
	 * regardless of the PHP client's word size, so the counter itself always
	 * advances correctly. Only the READ-BACK is bounded: `(int) $id` on a
	 * value this large would be exactly the corruption above, so a 32-bit
	 * build answers `observation: null` UNCONDITIONALLY — no witness on such
	 * a build, ever, and Aura's ordering falls back to its own request order
	 * for that site (documented in readme.txt).
	 *
	 * A bump whose statement failed, or a read-back that could not be
	 * proven, answers null — "no witness this serve", never a stale or
	 * guessed number a caller could mistake for this call's own. So does a
	 * read-back that is not a POSITIVE integer (Ruling S5, Codex round-2 P2
	 * on #88): the upsert can commit and the connection can then drop before
	 * this SELECT runs, and WordPress can transparently reconnect and run it
	 * on a FRESH session, where `LAST_INSERT_ID()` answers `0` — a value
	 * that is `is_numeric()` and would otherwise pass as a witness this
	 * connection never actually produced.
	 *
	 * @return int|null
	 */
	public static function bump_door_version() {
		if ( ! self::bump_door_version_write() ) {
			return null;
		}
		return self::bump_door_version_read_back();
	}

	/**
	 * Just the upsert — split out from `bump_door_version()` so `versioned()`
	 * (Ruling S8, Codex round-4 P1 on #88) can gate a transaction's COMMIT on
	 * whether this WRITE landed, without that decision depending on the
	 * read-back's own separate provability (Ruling S2), which is a different
	 * question and must never roll back a mutation that genuinely happened.
	 *
	 * @return bool True only when the upsert statement itself succeeded.
	 */
	private static function bump_door_version_write() {
		global $wpdb;
		$wpdb->last_error = '';
		// Built as TEXT, never assembled as one PHP int (Ruling S7): see
		// bump_door_version()'s docblock. `microtime( false )` returns
		// "0.usec sec" — the fractional-seconds half first, the
		// whole-seconds half second.
		list( $frac, $sec ) = explode( ' ', microtime( false ), 2 );
		// TRUNCATED, never rounded: round() can carry 999999.9997 up to
		// 1000000 — SEVEN digits, which would overrun the %06d field and
		// corrupt the clock's fixed 6-digit microsecond width instead of
		// incrementing the second it belongs to. (int) cast truncates.
		$usec  = min( 999999, (int) ( ( (float) $frac ) * 1000000 ) );
		$clock = sprintf( '%d%06d', (int) $sec, $usec );
		$ok    = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, LAST_INSERT_ID(GREATEST(1, %s)), 'no') ON DUPLICATE KEY UPDATE option_value = LAST_INSERT_ID(GREATEST(CAST(option_value AS UNSIGNED) + 1, %s))",
				self::OBSERVATION,
				$clock,
				$clock
			)
		);
		wp_cache_delete( self::OBSERVATION, 'options' );
		return false !== $ok && '' === (string) $wpdb->last_error;
	}

	/**
	 * Just the read-back — the connection-scoped proof of what the LAST
	 * upsert on THIS connection assigned (Ruling S2). NEVER gates a
	 * transaction's commit (Ruling S8): a mutation that wrote successfully
	 * must land whether or not its own witness can be proven back, so this
	 * is always called AFTER the decision to commit, best-effort.
	 *
	 * @return int|null
	 */
	private static function bump_door_version_read_back() {
		if ( ! self::engine_is_transactional() ) {
			// Ruling S13: no witness at all on a non-transactional engine —
			// nothing here can prove state and the bump moved together, or
			// that either moved at all if something failed partway.
			return null;
		}
		global $wpdb;
		$wpdb->last_error = '';
		$id                = $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
		if ( null === $id || '' !== (string) $wpdb->last_error || ! is_numeric( $id ) ) {
			// The upsert may well have landed — but a connection that cannot
			// prove what IT assigned must not hand back a guess, and never
			// the shared row's current value (Ruling S2).
			return null;
		}
		$int_size = null !== self::$int_size_override_for_tests ? self::$int_size_override_for_tests : PHP_INT_SIZE;
		if ( $int_size < 8 ) {
			// Ruling S7: this build cannot represent the answer as an int
			// without corrupting it. The counter still advanced — MySQL did
			// the arithmetic — but nothing here may claim to have witnessed
			// what it now holds.
			return null;
		}
		$id = (int) $id;
		if ( $id <= 0 ) {
			// A reconnect-onto-a-fresh-session `0` (Ruling S5) — or, in
			// principle, a negative value nothing here could produce. Either
			// way this connection proved nothing about what it assigned.
			return null;
		}
		return $id;
	}

	/**
	 * Run `$writes()` and, if it reports a real mutation, bump the door
	 * version — ALL IN ONE TRANSACTION (Ruling S8, Codex round-4 P1 on #88).
	 *
	 * WHY A TRANSACTION AT ALL. The mutation-based scheme (Ruling S6) still
	 * committed the state write and its version bump as two SEPARATE
	 * statements: a `/status` poll landing between them read the version
	 * BEFORE the bump alongside state that already included the new write —
	 * both of `status_fragment()`'s bracketing reads would agree on the OLD
	 * version, so the poll would serve the new state under a version Aura's
	 * strictly-greater comparison treats as unchanged. And if the bump then
	 * failed outright, that new state stayed invisible until some UNRELATED
	 * later mutation finally advanced the version past it. One transaction
	 * closes both holes: no reader can observe the state write without the
	 * bump, or the bump without the state write, because until COMMIT
	 * neither is visible to any other connection at all.
	 *
	 * THIS MUST NEVER BE CALLED FROM INSIDE AN ALREADY-OPEN TRANSACTION.
	 * MySQL has no nested transactions — issuing a second `START TRANSACTION`
	 * implicitly COMMITS the first, silently closing out whatever the outer
	 * caller had not finished and losing the atomicity this method exists to
	 * provide. `git grep -n "START TRANSACTION\|\bBEGIN\b"` across the
	 * plugin before this ruling found NO existing transaction use anywhere —
	 * this is the first — so every choke point below routes through this ONE
	 * method rather than opening its own, and none of them call each other
	 * while already inside one (`rotate_binding()` used to call the PUBLIC
	 * `rotate_epoch()`, itself now `versioned()`-wrapped; it now calls a
	 * private write-only variant instead, inside its OWN single transaction —
	 * see `rotate_epoch_write()`).
	 *
	 * THE READ-BACK RUNS AFTER COMMIT, NEVER BEFORE (Ruling S10, Codex
	 * round-5 P1 on #88). If the connection dropped between the version
	 * upsert and `SELECT LAST_INSERT_ID()`, WordPress could transparently
	 * reconnect for the SELECT — and a fresh connection rolls back whatever
	 * the OLD one had left uncommitted, so the state write and the bump
	 * would be lost, while this method still returned `committed: true`
	 * because the COMMIT that followed ran on the new connection over
	 * nothing. Committing FIRST closes that: a reconnect after the COMMIT
	 * lands on a session that never assigned anything, `SELECT
	 * LAST_INSERT_ID()` on it answers `0` (Ruling S5 ⇒ null), but the
	 * mutation is ALREADY durable, so `committed: true` stays honest even
	 * when the witness could not be proven.
	 *
	 * EVERY EVICTION IS REPEATED AFTER COMMIT (Ruling S11, Codex round-5 P1
	 * on #88). `$writes()` still evicts the option cache the moment it
	 * writes, exactly as before — later reads in the SAME request (this
	 * process's own cache) must see the fresh value immediately, commit or
	 * not. But a CONCURRENT request can repopulate that cache entry from the
	 * pre-commit database snapshot in the window between that eviction and
	 * this COMMIT, and nothing evicted it again afterwards — so `$writes()`
	 * additionally returns the option names it touched (`evict`), and this
	 * method deletes each one from the cache a second time once the write is
	 * durable, closing that window. `self::OBSERVATION` is always included,
	 * since the bump touches it on every call.
	 *
	 * NON-TRANSACTIONAL ENGINES GET NO ATOMICITY AND NO WITNESS (Ruling S13,
	 * Codex round-5 P2 on #88). `START TRANSACTION`/`ROLLBACK` are silent
	 * no-ops on a table like MyISAM — the writes land immediately and
	 * unconditionally regardless of what this method does, and a "rollback"
	 * would not undo them. `engine_is_transactional()` is checked first; when
	 * it is false, no transaction is opened or rolled back — `$writes()` runs
	 * exactly as it always would have (today's pre-2.16.2 behaviour), the
	 * version bump is still ATTEMPTED (it is one upsert, harmless on any
	 * engine), but its read-back is never even attempted, because
	 * `door_version_raw()`/`bump_door_version_read_back()` both refuse to
	 * report a witness for such a site regardless (see their own docblocks)
	 * — nothing here could prove state and version moved together, or that
	 * either moved at all if something failed partway.
	 *
	 * A ROLLED-BACK UNIT REPORTS NO `result` AT ALL (Ruling S15, Codex
	 * round-6 P2 on #88). Both paths that answer `committed: false` — the
	 * bump's own write failing, and `$writes()` itself asking to roll back —
	 * used to hand the CALLER's success-shaped `$result` straight back
	 * regardless: `ack()` and `rotate_epoch()` both returned
	 * `$outcome['result']` unconditionally, so a bump failure after a real
	 * ack or rotation reported it as having happened, when the ROLLBACK just
	 * undid it. `committed: false` now carries no `result` key at all —
	 * every caller MUST check `committed` first and build its own failure
	 * answer (typically by re-reading the now-unwound state) rather than
	 * ever trust `result` without having checked it.
	 *
	 * THE FINAL COMMIT IS PROVEN, NOT ASSUMED (Ruling S16, Codex round-6 P1
	 * on #88). A connection can drop AFTER the version bump's write but
	 * WHILE this method's own `COMMIT` is being issued; WordPress can
	 * transparently reconnect and run that `COMMIT` on a brand-new session —
	 * one with no transaction open at all, on which `COMMIT` is a harmless
	 * no-op that still returns success — while the OLD session's real,
	 * uncommitted transaction was rolled back by MySQL itself the instant
	 * the connection was lost. A `COMMIT` that merely returns without error
	 * cannot tell those two cases apart. A per-unit session (user) variable
	 * closes the gap: set as the FIRST statement after `START TRANSACTION`
	 * (so it exists on this session before `$writes()` runs anything), it
	 * does not survive a reconnect — session variables are scoped to the
	 * connection — so reading it back immediately after `COMMIT` and
	 * comparing it to the value this call set proves the COMMIT that just
	 * ran was issued on the SAME session that opened the transaction. NULL
	 * or any other value means a reconnect happened somewhere in between,
	 * the real transaction is gone, and this method answers `committed:
	 * false` — accurately: nothing landed.
	 *
	 * THE SESSION ITSELF IS ESTABLISHED WITH A SAVEPOINT, NOT JUST NAMED
	 * (Ruling S17, Codex round-7 P1 on #88). Ruling S16's nonce closed the
	 * gap at the END of the transaction, but a reconnect can also land
	 * WHILE the nonce's own `SET` is being issued: `wpdb` can transparently
	 * retry that `SET` on a fresh AUTOCOMMIT session, and the retried
	 * statement still assigns the SAME nonce this method compares against
	 * later — so the final check above would pass even though no
	 * transaction was ever open while `$writes()` and the bump ran.
	 * `SAVEPOINT aura_door_tx` is issued immediately after `START
	 * TRANSACTION`, BEFORE the nonce — a real transactional savepoint,
	 * which needs no special privilege and, unlike the nonce, cannot be
	 * silently re-created on a different session: `RELEASE SAVEPOINT
	 * aura_door_tx`, issued right before the final `COMMIT`, either
	 * confirms THIS savepoint (and therefore this transaction) is still
	 * open, or fails with MySQL error 1305 ("SAVEPOINT … does not exist")
	 * when a reconnect anywhere between `START TRANSACTION` and here left
	 * nothing to release — an autocommit session that ran `SAVEPOINT`
	 * outside any explicit transaction discards it the instant that
	 * single statement completes. A failed `RELEASE` fails the whole unit
	 * exactly like a failed bump: `ROLLBACK`, `committed: false`.
	 *
	 * A ROLLBACK REPEATS THE CALLBACK'S EVICTIONS TOO (Ruling S18, Codex
	 * round-7 P1 on #88). `$writes()` can — and `ack_write()` does —
	 * evict an option's cache entry and then RE-READ it before this method
	 * ever decides whether to commit, which re-caches whatever the
	 * UNCOMMITTED write just produced (`ack_write()`'s floor raise, read
	 * back via `self::floor()` to compute the response, before the bump
	 * that might still fail). Ruling S11 already repeats every listed
	 * eviction after a successful COMMIT for exactly this shape of gap;
	 * ROLLBACK left it unaddressed on the failure side; a caller re-reading
	 * state right after `committed: false` could still get the
	 * never-landed value back from cache. Every rollback this method can
	 * still reach after `$writes()` ran — the bump's own write failing, a
	 * failed savepoint release, and a COMMIT whose session could not be
	 * proven — now repeats `$evict` before returning, the same list and the
	 * same loop the success path already uses.
	 *
	 * @param callable $writes Returns array{ mutated: bool, result: mixed,
	 *                         evict?: string[] } or array{ rollback: true,
	 *                         result: mixed }. `mutated` false ⇒ an
	 *                         idempotent no-op, a lost race, a refusal —
	 *                         nothing to version, and nothing to roll back
	 *                         either (the transaction still closes, via
	 *                         COMMIT, since $writes() may legitimately have
	 *                         written and decided "not mutated" on its own
	 *                         evidence — none of today's callers do, but the
	 *                         contract does not assume otherwise). `rollback`
	 *                         true (Ruling S12) ⇒ $writes() itself proved a
	 *                         LATER statement could not complete what an
	 *                         EARLIER one in the same call started, and the
	 *                         whole unit must fail — never mistaken for
	 *                         "not mutated", which would COMMIT the partial
	 *                         work. `evict` lists every option name $writes()
	 *                         wrote, for the post-commit repeat (Ruling S11).
	 *                         `result` is handed back to the CALLER of
	 *                         versioned().
	 * @return array{ committed: bool, result?: mixed, observation?: int|null }
	 *         `committed` is false when $writes() asked for a rollback,
	 *         reported a mutation whose version bump then failed its own
	 *         WRITE, when the savepoint that proves the session held the
	 *         transaction the whole time could not be released (Ruling S17),
	 *         or when the final COMMIT could not be PROVEN to have run on
	 *         the session that opened the transaction (Ruling S16) — every
	 *         one of those rolls the WHOLE unit back (on a transactional
	 *         engine only — see Ruling S13 above), including every
	 *         statement $writes() itself ran AND every cache entry it
	 *         evicted and re-read before this method decided to fail
	 *         (Ruling S18), so the caller must treat this exactly like
	 *         $writes() failing outright — and `result` is
	 *         ABSENT whenever `committed` is false (Ruling S15): never read
	 *         it without checking `committed` first. `observation` is
	 *         likewise absent on failure, and otherwise the witness THIS call
	 *         produced — null whenever it could not be proven, which can
	 *         happen even while `committed` is true (Ruling S10): a reconnect
	 *         between the COMMIT and the read-back, or a non-transactional
	 *         engine, or 32-bit PHP.
	 */
	public static function versioned( callable $writes ) {
		global $wpdb;
		$transactional = self::engine_is_transactional();
		$tx_nonce      = null;
		if ( $transactional ) {
			$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			// Ruling S17 (Codex round-7 P1 on #88): a REAL transactional
			// savepoint, before anything else — see this method's own
			// docblock for why the nonce below is not enough on its own.
			$wpdb->query( 'SAVEPOINT aura_door_tx' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			// Ruling S16 (Codex round-6 P1 on #88): the FIRST statement after
			// the savepoint, so it is set on THIS session before anything
			// else runs — the proof the final COMMIT below checks before this
			// method ever reports `committed: true`. See that COMMIT's own
			// comment for what it proves and why.
			$tx_nonce = wp_generate_uuid4();
			$wpdb->query( $wpdb->prepare( 'SET @aura_door_tx = %s', $tx_nonce ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		}
		try {
			$outcome = $writes();
		} catch ( \Throwable $e ) {
			if ( $transactional ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			}
			throw $e;
		}
		$outcome = is_array( $outcome ) ? $outcome : array();
		if ( ! empty( $outcome['rollback'] ) ) {
			// Ruling S12: $writes() itself demands the unit fail.
			if ( $transactional ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				return array(
					'committed' => false,
					'result'    => array_key_exists( 'result', $outcome ) ? $outcome['result'] : null,
				);
			}
			// Ruling S13: nothing CAN be rolled back here — whatever
			// $writes() already ran landed the moment it ran. The most
			// honest answer left is that the unit "committed", exactly as
			// every other statement on this engine always has; $writes()'s
			// own `result` already reflects what it could prove happened.
			return array(
				'committed' => true,
				'result'    => array_key_exists( 'result', $outcome ) ? $outcome['result'] : null,
			);
		}
		$mutated = ! empty( $outcome['mutated'] );
		$result  = array_key_exists( 'result', $outcome ) ? $outcome['result'] : null;
		$evict   = isset( $outcome['evict'] ) && is_array( $outcome['evict'] ) ? $outcome['evict'] : array();
		if ( ! $mutated ) {
			if ( $transactional ) {
				$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			}
			return array(
				'committed' => true,
				'result'    => $result,
			);
		}
		$bump_ok = self::bump_door_version_write();
		if ( ! $bump_ok && $transactional ) {
			// Ruling S8: the bump's own WRITE failed, so the mutation must
			// not land either — every statement $writes() ran is undone.
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			// Ruling S15 (Codex round-6 P2 on #88): `$result` above is the
			// SUCCESS-shaped value $writes() already computed BEFORE the
			// bump's write failed — ack_write()'s `acked`/`floor`,
			// rotate_epoch_write()'s `rotated: true`/`epoch`, and so on.
			// Handing it back here, after the ROLLBACK that just undid
			// every statement behind it, told `ack()`/`rotate_epoch()` (both
			// of which returned `$outcome['result']` unconditionally) that
			// their write had gone through when nothing did. A rolled-back
			// unit reports NOTHING it did — no `result` at all — so every
			// caller must check `committed` first and build its own failure
			// answer rather than trust a `result` that might be stale.
			self::evict_after_rollback( $evict ); // Ruling S18
			return array(
				'committed' => false,
			);
		}
		// Ruling S13: on a non-transactional engine the state write already
		// landed, durably, whatever the bump did — there is no rollback to
		// perform, so execution simply continues to the shared tail below.
		if ( $transactional ) {
			// Ruling S17 (Codex round-7 P1 on #88): RELEASE the savepoint
			// BEFORE the COMMIT it is meant to protect. A reconnect ANYWHERE
			// between START TRANSACTION and here — including one that let a
			// retried `SET` land on a fresh autocommit session carrying the
			// SAME nonce Ruling S16 checks below — leaves no savepoint for
			// this statement to find: an autocommit session that ran
			// SAVEPOINT outside any explicit transaction discards it the
			// instant that single statement completes, so MySQL answers
			// error 1305 ("SAVEPOINT … does not exist") here instead. That
			// failure is treated exactly like the bump's own write failing.
			$wpdb->query( 'RELEASE SAVEPOINT aura_door_tx' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			if ( '' !== (string) $wpdb->last_error ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				self::evict_after_rollback( $evict ); // Ruling S18
				return array(
					'committed' => false,
				);
			}
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery — Ruling S10: COMMIT before the read-back
			// Ruling S16 (Codex round-6 P1 on #88): PROVE this COMMIT ran on
			// the SAME session that opened the transaction and set the nonce
			// above — not a fresh session WordPress transparently reconnected
			// onto after the real one dropped mid-flight. A reconnect at any
			// point between the SET and here lands on a session with no
			// transaction open at all, so the COMMIT just issued is a
			// harmless no-op that STILL returns success; trusting that
			// success is exactly the gap this closes. Session (user)
			// variables do not survive a reconnect — MySQL scopes them to
			// the connection — so reading the nonce back and comparing tells
			// the two apart without needing COMMIT's own return value to be
			// honest about which session it ran on (its return/last_error
			// are still consulted below, for the ordinary case where the
			// statement itself simply fails). The RELEASE above already
			// catches most of this window; this check remains for the
			// narrower one still open between it and the COMMIT itself.
			$commit_ok = ( '' === (string) $wpdb->last_error );
			if ( $commit_ok ) {
				$session_nonce = $wpdb->get_var( 'SELECT @aura_door_tx' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$commit_ok     = ( is_string( $session_nonce ) && $session_nonce === $tx_nonce );
			}
			if ( ! $commit_ok ) {
				// Ruling S15: no callback result — see the bump-failure
				// branch above for why. The mutation this call thought it
				// just committed was rolled back by MySQL itself the moment
				// the original session was lost; nothing here landed.
				self::evict_after_rollback( $evict ); // Ruling S18
				return array(
					'committed' => false,
				);
			}
		}
		// Ruling S11: repeat every eviction $writes() performed, now that
		// the write is durable.
		foreach ( $evict as $name ) {
			wp_cache_delete( $name, 'options' );
		}
		wp_cache_delete( self::OBSERVATION, 'options' ); // the bump's own row
		$observation = null;
		if ( $transactional && $bump_ok ) {
			// Ruling S10: best-effort, deliberately AFTER commit. Ruling
			// S13: skipped on a non-transactional engine — the witness is
			// disabled there regardless (see this method's own docblock),
			// so reading it back would only prove what nothing may report.
			$observation = self::bump_door_version_read_back();
		}
		return array(
			'committed'   => true,
			'result'      => $result,
			// The witness THIS call produced, for a caller that wants it
			// (none of today's choke points read it — the door version is
			// reported separately, on demand, via door_version_raw()) and
			// for tests: null whenever the read-back could not be proven —
			// including a reconnect landing between the COMMIT above and
			// the read-back (Ruling S10) — even though `committed` is true.
			'observation' => $observation,
		);
	}

	/**
	 * Repeat `$writes()`'s listed evictions on a ROLLBACK (Ruling S18, Codex
	 * round-7 P1 on #88) — the failure-side twin of the post-COMMIT repeat
	 * Ruling S11 already performs. `$writes()` can evict an option's cache
	 * entry and then re-read it BEFORE this method decides whether to
	 * commit — `ack_write()` does exactly this, re-reading the floor via
	 * `self::floor()` right after raising it, to compute the response it
	 * hands back — which re-caches the UNCOMMITTED value. If the version
	 * bump then fails and the whole unit rolls back, that cache entry is
	 * never touched again on the failure path, so a caller re-reading state
	 * immediately after `committed: false` could still read back a value
	 * that was never actually written. Called from every rollback this
	 * method can reach once `$writes()` has run: the bump's own write
	 * failing, a failed savepoint release, and a COMMIT whose session could
	 * not be proven (Rulings S8, S17, S16).
	 *
	 * @param string[] $evict Option names $writes() reported touching.
	 */
	private static function evict_after_rollback( array $evict ) {
		foreach ( $evict as $name ) {
			wp_cache_delete( $name, 'options' );
		}
		wp_cache_delete( self::OBSERVATION, 'options' ); // the bump's own row
	}

	/**
	 * The door version as the DATABASE holds it, READ-ONLY (Ruling A65) —
	 * for `governor_block()`'s on-demand AUDIT read and for
	 * `status_fragment()`'s own before/after check (Ruling S6): neither may
	 * itself advance the very counter Aura uses to order polls.
	 *
	 * PROVEN, never minted: an absent or unreadable row answers null, not
	 * zero, which a caller could not tell apart from "this site has never
	 * had a door mutation".
	 *
	 * @return int|null
	 */
	/**
	 * Why the observation witness is PERMANENTLY unsupported for this site,
	 * if it is (Ruling S13, Codex round-5 P2 on #88) — unifying Ruling S7's
	 * 32-bit reason into the same key, since both describe the identical
	 * shape of fact: "this build/host can never report a witness", not a
	 * transient miss. `governor_block()` carries it as
	 * `observation_unsupported` beside `observation` itself, which is null
	 * either way; a transient null (an unproven read, a torn poll) is NOT
	 * unsupported and answers null here too — this is only for a reason a
	 * caller could act on (upgrade PHP, migrate the table to InnoDB).
	 *
	 * @return string|null 'engine'|'php32'|null
	 */
	public static function observation_unsupported_reason() {
		if ( ! self::engine_is_transactional() ) {
			return 'engine';
		}
		$int_size = null !== self::$int_size_override_for_tests ? self::$int_size_override_for_tests : PHP_INT_SIZE;
		if ( $int_size < 8 ) {
			return 'php32';
		}
		return null;
	}

	public static function door_version_raw() {
		if ( ! self::engine_is_transactional() ) {
			return null; // Ruling S13 — see versioned()'s own docblock
		}
		$read = self::raw_option_read( self::OBSERVATION );
		if ( ! $read['ok'] ) {
			return null;
		}
		if ( null === $read['value'] || ! is_numeric( $read['value'] ) ) {
			return null;
		}
		$int_size = null !== self::$int_size_override_for_tests ? self::$int_size_override_for_tests : PHP_INT_SIZE;
		if ( $int_size < 8 ) {
			return null; // Ruling S7 — see bump_door_version()
		}
		return (int) $read['value'];
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
	 * A SEQ ABOVE THE TOP ACKNOWLEDGES NOTHING (Ruling P95). It used to be
	 * CLAMPED down to the top, which is exactly wrong after an options-table
	 * rewind: between the rewind and `/status` detecting it, an in-flight ack
	 * from the pre-rewind log still carries the CURRENT epoch, and if a new
	 * write has already reused the next number, clamping that old, higher
	 * cursor raises the floor straight through the new row and deletes an entry
	 * Aura never received. Such an ack is stale by construction — it names rows
	 * this log does not have — and it is answered `stale: true` with nothing
	 * written at all. The overflow the clamp guarded against cannot happen
	 * without it: the floor is only ever raised to a cursor at or below the
	 * top.
	 *
	 * @param string $epoch The epoch Aura is acking.
	 * @param int    $seq   Highest seq of its contiguous committed prefix.
	 * @return array{ acked: int, floor: int, stale?: bool }
	 */
	public static function ack( $epoch, $seq ) {
		$seq = (int) $seq;
		if ( ! is_string( $epoch ) || $epoch !== self::epoch() ) {
			return array( 'acked' => 0, 'floor' => self::floor() );
		}
		// AN UNREADABLE TOP CLAMPS NOTHING (Ruling P77). It used to cast to 0,
		// so `$top` fell back to the floor and a legitimate cursor above it was
		// clamped down — acking rows Aura had not acked and deleting them. The
		// ack simply does not happen; Aura repeats it.
		$max = self::highest_row_seq();
		if ( null === $max ) {
			return array( 'acked' => 0, 'floor' => self::floor() );
		}
		$top = max( $max, self::floor() );
		if ( $seq > $top ) {
			// Above everything this log has: a cursor from a log that was
			// rewound out from under Aura (Ruling P95). Nothing is written —
			// not the floor's insert, not its raise, not the purge — and the
			// answer says why, so Aura re-reads rather than assuming its ack
			// landed.
			return array(
				'acked' => 0,
				'floor' => self::floor(),
				'stale' => true,
			);
		}
		if ( $seq < 1 ) {
			return array( 'acked' => 0, 'floor' => self::floor() );
		}
		// A CHOKE POINT FOR THE DOOR VERSION (Ruling S6, Codex round-3 P1;
		// made TRANSACTIONAL by Ruling S8, Codex round-4 P1 on #88): `ack()`
		// mutates through hand-rolled SQL, not through
		// `write_option_where()`/`insert_unique()`, so its writes and its
		// bump run inside ONE `versioned()` unit — see `ack_write()`, which
		// reports `mutated` ONLY when something actually changed (the floor
		// rose, rows were purged, or the log reopened), never on the
		// ordinary idempotent repeat.
		$outcome = self::versioned(
			function () use ( $epoch, $seq ) {
				return self::ack_write( $epoch, $seq );
			}
		);
		if ( ! $outcome['committed'] ) {
			// Ruling S15 (Codex round-6 P2 on #88): a rolled-back unit
			// carries no `result` — `ack_write()`'s own `acked`/`floor`
			// were computed BEFORE the bump's write failed (or the COMMIT
			// could not be proven, Ruling S16) and undone with everything
			// else. Reading `self::floor()` now answers the floor as the
			// rollback actually left it, never the value this ack thought
			// it had raised.
			return array(
				'acked'     => 0,
				'floor'     => self::floor(),
				'committed' => false,
			);
		}
		return $outcome['result'];
	}

	/**
	 * `ack()`'s writes, with no transaction of its own — called from inside
	 * the one `versioned()` opens.
	 *
	 * @param string $epoch Already proven current by the caller.
	 * @param int    $seq   Already bounds-checked by the caller.
	 * @return array{ mutated: bool, result: array }
	 */
	private static function ack_write( $epoch, $seq ) {
		global $wpdb;
		// Ruling S11 (Codex round-5 P1 on #88): every name this call evicts,
		// repeated by versioned() after commit.
		$evict = array( self::FLOOR );
		// Floor: INSERT if absent, else raise only when lower. The floor as it
		// stood BEFORE the raise bounds the cache invalidation below to the
		// newly acked range — never 1..seq on a site with a long history
		// (Codex round-5 P2). FLOOR is version-exempt (see
		// VERSION_EXEMPT_INSERTS), so this does not open a nested transaction.
		self::insert_unique( self::FLOOR, 0 );
		$prev_floor_before_raise = self::floor();
		// JOINED TO THE EPOCH ROW (Ruling P90). The check in ack() reads the
		// epoch and then lets go of it, so a `/door/rotate` or a rebind
		// installing a new epoch in between still had this ack advance the
		// SHARED floor — and after a rewind that is destructive: an old,
		// high cursor from epoch A is clamped against epoch B's freshly
		// written rows, and the delete below then removes entries Aura has
		// never seen.
		//
		// The epoch row is a condition of the statement itself, so the ack
		// linearises before or after a rotation and never across one.
		$raised = (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE {$wpdb->options} f JOIN ( SELECT option_value AS e FROM {$wpdb->options} WHERE option_name = %s ) x SET f.option_value = %s WHERE f.option_name = %s AND x.e = %s AND CAST(f.option_value AS UNSIGNED) < %d",
				self::EPOCH,
				(string) $seq,
				self::FLOOR,
				(string) $epoch,
				$seq
			)
		);
		wp_cache_delete( self::FLOOR, 'options' );
		if ( $raised < 1 && (string) $epoch !== self::epoch_raw() ) {
			// Zero rows AND the epoch has moved: this ack crossed a rotation
			// and owns nothing here. Zero rows with the epoch UNCHANGED is the
			// ordinary idempotent repeat — the floor is already at or above
			// this cursor — and still falls through to the delete, which is how
			// a previous ack's unfinished purge is completed.
			return array(
				'mutated' => false,
				'result'  => array(
					'acked' => 0,
					'floor' => self::floor(),
				),
				'evict'   => $evict,
			);
		}
		$floor = self::floor();
		$acked = 0;
		if ( $floor > 0 ) {
			$prev_floor = $prev_floor_before_raise; // read BEFORE the raise, below
			$like  = $wpdb->esc_like( self::PREFIX ) . '%';
			// Joined to the epoch row as well (Ruling P90): the purge is the
			// destructive half, and it must not run under an epoch this ack
			// was never for — including one installed between the raise above
			// and this statement.
			$acked = (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"DELETE f FROM {$wpdb->options} f JOIN ( SELECT option_value AS e FROM {$wpdb->options} WHERE option_name = %s ) x WHERE f.option_name LIKE %s AND f.option_name REGEXP %s AND x.e = %s AND CAST(SUBSTRING(f.option_name, %d) AS UNSIGNED) <= %d",
					self::EPOCH,
					$like,
					self::ROW_REGEXP,
					(string) $epoch,
					strlen( self::PREFIX ) + 1,
					$floor
				)
			);
			for ( $i = $prev_floor + 1; $i <= $floor; $i++ ) {
				wp_cache_delete( self::PREFIX . $i, 'options' );
				$evict[] = self::PREFIX . $i;
			}
		}
		// Reopened only on a READABLE count under the bound (Ruling P53). An
		// unreadable one used to cast to 0 and delete the marker over a
		// backlog that was still full — the door open again with nothing
		// having been acked.
		$unacked  = self::count_unacked();
		$reopened = false;
		if ( self::is_closed() && null !== $unacked && $unacked < self::MAX_UNACKED ) {
			delete_option( self::FULL_MARKER );
			delete_option( self::FULL_COUNTER );
			$reopened = true;
			$evict[]  = self::FULL_MARKER;
			$evict[]  = self::FULL_COUNTER;
		}
		return array(
			'mutated' => ( $raised >= 1 || $acked > 0 || $reopened ),
			'result'  => array(
				'acked' => $acked,
				'floor' => $floor,
			),
			'evict'   => $evict,
		);
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
		// An unreadable top does not bound the walk (Ruling P77) — the hole
		// check below and $limit already do, and stopping at a top of 0 would
		// serve an empty page for a log that is simply unreadable at the top.
		$top       = self::highest_row_seq();
		$unbounded = ( null === $top );
		while ( count( $out ) < $limit && ( $unbounded || $seq < $top ) ) {
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
	public static function rotate_epoch( $expected, $claim = '', $fence = '' ) {
		// PRIMED BEFORE THE TRANSACTION OPENS (Ruling S8): the epoch must
		// already exist by the time rotate_epoch_write() runs, because that
		// method reads it back with epoch_raw() — never the MINTING epoch(),
		// which would nest a second transaction (see rotate_epoch_write()'s
		// own docblock). Idempotent either way: an epoch that already exists
		// is untouched, exactly like every other caller of epoch().
		self::epoch();
		$outcome = self::versioned(
			function () use ( $expected, $claim, $fence ) {
				return self::rotate_epoch_write( $expected, $claim, $fence );
			}
		);
		if ( ! $outcome['committed'] ) {
			// Ruling S15 (Codex round-6 P2 on #88): a rolled-back unit
			// carries no `result` — `rotate_epoch_write()`'s own
			// `rotated: true`/`epoch` were computed BEFORE the bump's write
			// failed (or the COMMIT could not be proven, Ruling S16) and
			// undone along with everything else. `epoch_raw()` reads the
			// row exactly as the rollback left it — the one that was never
			// actually replaced — rather than trusting a stale guess.
			return array(
				'rotated' => false,
				'epoch'   => self::epoch_raw(),
			);
		}
		return $outcome['result'];
	}

	/**
	 * `rotate_epoch()`'s writes, with no transaction of its own — called
	 * from inside the one `versioned()` opens, and ALSO called directly by
	 * `rotate_binding()` (Ruling S8, Codex round-4 P1 on #88): a binding
	 * rotation's epoch rotation and its own record write must land in the
	 * SAME transaction, and `rotate_binding()` is itself `versioned()`-
	 * wrapped, so it cannot call the PUBLIC `rotate_epoch()` — that would
	 * nest a second `START TRANSACTION` inside the first, which MySQL has no
	 * concept of (a nested one implicitly commits the outer). This method is
	 * the shared write-only core both wrappers call into.
	 *
	 * @param string $expected The epoch the caller means to replace.
	 * @param string $claim    Optional site-claim option name.
	 * @param string $fence    Optional claim fence.
	 * @return array{ mutated: bool, result: array{ rotated: bool, epoch: string } }
	 */
	private static function rotate_epoch_write( $expected, $claim = '', $fence = '' ) {
		global $wpdb;
		$expected = (string) $expected;
		$claim    = (string) $claim;
		$fence    = (string) $fence;
		if ( '' === $expected ) {
			return array(
				'mutated' => false,
				'result'  => array(
					'rotated' => false,
					// epoch_raw() here, NEVER the minting epoch() (Ruling S8):
					// this runs INSIDE versioned()'s open transaction, and
					// epoch()'s lazy mint is itself a versioned insert_unique()
					// call — nesting a second START TRANSACTION, which MySQL
					// has no way to honour. Both PUBLIC callers of this method
					// (rotate_epoch(), rotate_binding()) already prime the
					// epoch with a real epoch() read BEFORE opening their
					// transaction, so it exists here in every real case; '' is
					// the honest answer on the one a caller skipped that.
					'epoch'   => self::epoch_raw(),
				),
			);
		}
		// CLAIM-CONDITIONED WHEN A REBIND ASKS (Ruling P83). A connect or unbind
		// handler that passed `holds_site_claim()` and then stalled until
		// another handler took the site over could resume here and rotate the
		// WINNER's epoch — invalidating its in-flight acks and leaving its
		// record naming a cursor the site has left — before the record's own
		// claim-joined write finally rejected it. The claim row is joined into
		// the same statement as the value fence, so there is no window between
		// asking and acting.
		//
		// The grant-gated `/door/rotate` route passes none: it is Aura moving
		// the cursor it owns, not a rebind, and holds no site claim.
		if ( '' !== $claim && '' !== $fence ) {
			$gone = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"DELETE o FROM {$wpdb->options} o JOIN {$wpdb->options} c ON c.option_name = %s AND c.option_value LIKE %s WHERE o.option_name = %s AND o.option_value = %s",
					$claim,
					$wpdb->esc_like( $fence . '|' ) . '%',
					self::EPOCH,
					$expected
				)
			);
			wp_cache_delete( self::EPOCH, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			if ( 1 !== (int) $gone ) {
				return array(
					'mutated' => false,
					'result'  => array(
						'rotated' => false,
						'epoch'   => self::epoch_raw(), // never epoch() — see the docblock above
					),
				);
			}
			delete_option( self::FULL_MARKER );
			delete_option( self::FULL_COUNTER );
			// THE NEW EPOCH IS MINTED HERE, INLINE (Ruling S8). The DELETE
			// above only removed the old row — `self::epoch()`'s own
			// lazy-mint used to supply the replacement on the caller's next
			// read, but that mint is a versioned insert_unique() call and
			// would nest a transaction. insert_unique_write() is the SAME
			// mint, write-only, sharing this one.
			if ( ! self::insert_unique_write( self::EPOCH, wp_generate_uuid4() ) ) {
				// Ruling S12 (Codex round-5 P2 on #88): the replacement
				// insert failed — a lost race, a driver error. Left
				// unchecked, the transaction would still bump the version
				// and commit with NO epoch row at all, reporting
				// `rotated: true` with an empty one. The whole unit rolls
				// back instead — the DELETE above included — and the epoch
				// this call still names is the one that was actually never
				// replaced.
				return array(
					'rollback' => true,
					'result'   => array(
						'rotated' => false,
						'epoch'   => $expected,
					),
				);
			}
			return array(
				'mutated' => true,
				'result'  => array(
					'rotated' => true,
					'epoch'   => self::epoch_raw(),
				),
				// Ruling S11: repeated by versioned() after commit.
				'evict'   => array( self::EPOCH, 'notoptions', 'alloptions', self::FULL_MARKER, self::FULL_COUNTER ),
			);
		}
		$gone = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::EPOCH, $expected ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		wp_cache_delete( self::EPOCH, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		if ( 1 !== (int) $gone ) {
			return array(
				'mutated' => false,
				'result'  => array(
					'rotated' => false,
					'epoch'   => self::epoch_raw(),
				),
			);
		}
		delete_option( self::FULL_MARKER );
		delete_option( self::FULL_COUNTER );
		// See the mint note on the claim-conditioned branch above.
		if ( ! self::insert_unique_write( self::EPOCH, wp_generate_uuid4() ) ) {
			// Ruling S12 — see the claim-conditioned branch above.
			return array(
				'rollback' => true,
				'result'   => array(
					'rotated' => false,
					'epoch'   => $expected,
				),
			);
		}
		return array(
			'mutated' => true,
			'result'  => array(
				'rotated' => true,
				'epoch'   => self::epoch_raw(),
			),
			// Ruling S11: repeated by versioned() after commit.
			'evict'   => array( self::EPOCH, 'notoptions', 'alloptions', self::FULL_MARKER, self::FULL_COUNTER ),
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
