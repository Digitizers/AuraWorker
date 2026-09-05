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

if ( ! class_exists( 'Aura_Worker_Door_Write_Failed', false ) ) {
	/**
	 * Thrown by Aura_Worker_Door_Log::must_succeed() (Ruling S84, Codex
	 * round-35 P1 on #88) — see that method's own docblock. Caught in
	 * EXACTLY one place, versioned()'s own $writes() invocation, and
	 * turned into the SAME `{ rollback: true }` shape a $writes()
	 * callback already signals by RETURNING it (Ruling S12) — a
	 * write-unit abort by exception is indistinguishable, from
	 * versioned()'s own point of view, from one signalled the ordinary
	 * way. Never caught anywhere else: a callback that wants a
	 * DIFFERENT outcome on a specific failure (rotate_epoch_write()'s
	 * own fence-miss branches, which treat "0 rows" as a meaningful
	 * BUSINESS answer rather than a failure) simply never calls
	 * must_succeed() on that particular statement's result — this
	 * exception exists for the OTHER case: a statement whose failure has
	 * no honest business meaning at all, only "abort".
	 */
	class Aura_Worker_Door_Write_Failed extends RuntimeException {}
}

class Aura_Worker_Door_Log {

	const PREFIX       = 'aura_worker_door_log_';
	const FLOOR        = 'aura_worker_door_log_acked';
	const EPOCH        = 'aura_worker_door_epoch';
	/**
	 * A FIXED namespace for `rotate_epoch()`'s own derived rotation target
	 * (Ruling S78, Codex round-32 P1 on #88) — an arbitrary but CONSTANT
	 * UUID, never regenerated, so `derive_rotation_target()` is a pure,
	 * repeatable function of its own two inputs across every call this
	 * plugin ever makes. Its own bytes carry no meaning beyond "this
	 * plugin's own rotate-target derivation" — see that method's own
	 * docblock for why the target must be deterministic at all.
	 */
	const ROTATE_TARGET_NAMESPACE = '6f0a2b0e-6d43-5f1a-9c2e-2a9e8f7c4d31';
	/**
	 * Ruling S86 (Codex round-37 P1 on #88), the S80 pattern applied to
	 * `open_pending()`'s own seq allocation: a FIXED namespace for the
	 * derived RESERVATION identity — see `RESERVATION_PREFIX`'s own
	 * docblock for the whole mechanism. Distinct from
	 * `ROTATE_TARGET_NAMESPACE`/`Aura_Worker_Door_Holds::HOLD_REF_NAMESPACE`
	 * (each a different derivation, a different purpose) — RFC 4122 §4.3
	 * guarantees no two of these three ever collide with each other's
	 * inputs by construction.
	 */
	const LOG_RESERVATION_NAMESPACE = '9c1e5a2d-4f7b-5e3a-8d6c-1b0f2a9e7c4d';
	/**
	 * One option row per RESERVED seq allocation (Ruling S86, Codex
	 * round-37 P1 on #88) — `{ seq: int }`, named by a caller's derived
	 * reservation identity (see `open_pending()`'s own docblock for the
	 * bug this closes: an ambiguous pending-row INSERT used to leave a
	 * retry with nothing to recognise its own prior attempt by, so it
	 * allocated a SECOND seq behind the first, unadmitted one — blocking
	 * `log_after()` for every later entry until the reconciler's
	 * CLAIM_STALE_MS sweep). A retry supplying the SAME idempotency
	 * material (`aura_ref` + a hash of `touches`, the identical
	 * derivation `Aura_Worker_Door_Holds::HOLD_REF_NAMESPACE` uses for a
	 * hold's own ref) regenerates the SAME reservation name, finds this
	 * row, and — once it verifies the seq it names is a REAL row, never
	 * blindly — recognises its own reservation instead of allocating
	 * again. `insert_unique()`-written (a real conditional INSERT), so
	 * two concurrent attempts under the SAME identity cannot both "win"
	 * a DIFFERENT seq into it.
	 */
	const RESERVATION_PREFIX = 'aura_worker_door_log_rsv_';

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
	/**
	 * @var bool Set by log_after() (Ruling S36, Codex round-15 P1 on #88):
	 * did its walk stop on a row it could not PROVE readable, rather than a
	 * genuine hole? Reset at the start of every log_after() call — this is
	 * a per-call outcome, not a cross-request memo — and read by
	 * status_fragment() immediately afterwards, before anything else can
	 * run a second log_after() and overwrite it.
	 */
	private static $log_walk_unreadable = false;
	/**
	 * @var bool Set by floor_raw() (Ruling S38, Codex round-16 P1 on #88):
	 * did ANY floor_raw() call THIS ATTEMPT fail to prove its read, rather
	 * than finding the floor genuinely at 0? Accumulates (OR) across every
	 * floor_raw() call within one status_fragment() attempt — detect_rewind()
	 * and build_status_fragment_state() (via log_after() and its own
	 * 'log_floor' field) each call it once per attempt — and is reset once,
	 * at the top of that attempt, by reset_floor_unreadable_for_attempt().
	 */
	private static $floor_unreadable_this_attempt = false;
	/**
	 * @var bool Set by is_closed_raw() (Ruling S39, Codex round-16 P2 on
	 * #88): did the MOST RECENT call fail to prove its read, rather than
	 * finding the closure marker genuinely absent? Reset at the start of
	 * every such read.
	 */
	private static $closure_read_unreadable = false;
	/**
	 * @var bool Set by full_report_raw() (Ruling S42, Codex round-17 P2 on
	 * #88): did the MOST RECENT call fail to prove ANY part of its read —
	 * whether the log is closed at all, or (once closed) either the
	 * since/refused rows? Reset at the start of every such call.
	 */
	private static $full_report_unreadable = false;
	/**
	 * @var bool Set by row_from_db() (Ruling S37, Codex round-15 class
	 * sweep on #88): did the MOST RECENT call fail to prove its read,
	 * rather than finding the row genuinely absent? Reset at the start of
	 * every such read — checked by patch() immediately afterwards.
	 */
	private static $row_from_db_unreadable = false;
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
	/**
	 * Ruling S83 (Codex round-34 P1 on #88): the ceiling
	 * `restamp_observation_forward()` (and the `/status` route's own
	 * `door_observation_seen` REST arg validator) refuses ABOVE — never
	 * PHP_INT_MAX itself, which `$seen + 1` can overflow past on a
	 * 64-bit build (integer arithmetic overflowing to a FLOAT, which
	 * then floats straight through a `%d` placeholder in
	 * `$wpdb->prepare()` uncontrolled), and never merely "large", which
	 * would leave headroom too thin to reason about by inspection.
	 *
	 * 2^62 (4,611,686,018,427,387,904) — half of PHP_INT_MAX's own range
	 * on a 64-bit build (2^63 - 1), so `$seen + 1` can never approach,
	 * let alone reach, PHP_INT_MAX no matter how this constant is used.
	 * Checked against the OTHER floor `restamp_observation_forward()`
	 * already applies — the µs wall-clock value
	 * `bump_door_version_write()`'s own clock floor produces, currently
	 * ~1.7e15 (17 digits) and growing by roughly one digit every 3200
	 * years — this ceiling (19 digits) sits more than three decimal
	 * orders of magnitude above it: no legitimate clock-derived value
	 * this millennium is anywhere near this cap, so no real
	 * `door_observation_seen` Aura could ever legitimately send is
	 * refused by it.
	 *
	 * THIS CONSTANT NAMES THE FLOOR OF THE CHECK, NEVER THE CEILING OF
	 * THE INPUT (Ruling S88, Codex round-38 P2 on #88). One
	 * `door_observation_seen` request costs TWO increments past
	 * whatever `$seen` it names — `restamp_observation_forward()`'s own
	 * `GREATEST( …, $seen + 1, … )` (+1), THEN `versioned()`'s own
	 * generic version bump on the SAME mutating unit, which runs
	 * UNCONDITIONALLY after ANY unit that reports `mutated: true`
	 * (`GREATEST( current + 1, clock )`, and `current` is now what the
	 * restamp just wrote — a SECOND +1). The `/status` route's own REST
	 * arg `validate_callback` — the place this arithmetic must be
	 * documented beside, per this ruling, since it is the ONE caller
	 * this constant gates — therefore refuses at `MAX_OBSERVATION_SEEN
	 * - 1`, not at `MAX_OBSERVATION_SEEN` itself: accepting the
	 * legal-looking `MAX_OBSERVATION_SEEN - 1` let the SAME two
	 * increments carry the SERVED observation to
	 * `MAX_OBSERVATION_SEEN + 1` — a value the validator would then
	 * refuse FOREVER, since `MAX_OBSERVATION_SEEN` is that check's own
	 * ceiling. Reserving the top TWO integers — the validator accepts
	 * only `seen <= MAX_OBSERVATION_SEEN - 2` — leaves exactly enough
	 * headroom that the largest legal input (`MAX_OBSERVATION_SEEN - 2`)
	 * can absorb BOTH increments (restamp: `MAX_OBSERVATION_SEEN - 1`;
	 * the bump: `MAX_OBSERVATION_SEEN`) without the served value ever
	 * exceeding this constant itself.
	 */
	const MAX_OBSERVATION_SEEN = 4611686018427387904; // 2^62

	/**
	 * The DURABLE commit witness `versioned()` writes inside its own
	 * transaction, before the version bump (Ruling S30, Codex round-13 P1;
	 * PER-TRANSACTION as of Ruling S32, Codex round-14 P1 — both on #88) —
	 * see that method's own docblock for the reconnect window the
	 * session-variable nonce (Ruling S16) alone cannot close, and for why
	 * a single SHARED row (Ruling S30's own original shape) was itself
	 * unsafe. The full option name is this PREFIX followed by the unit's
	 * own nonce — `self::LAST_TX_PREFIX . $tx_nonce` — never written or
	 * read by name alone.
	 */
	const LAST_TX_PREFIX = 'aura_worker_door_tx_';
	/**
	 * How long a leaked per-transaction witness row (Ruling S32) may sit
	 * before `versioned()`'s own bounded janitor sweeps it up — a process
	 * that died between its own COMMIT and its own best-effort delete of
	 * that SAME row is the only way one outlives the unit that wrote it.
	 */
	const LAST_TX_MAX_AGE_S = DAY_IN_SECONDS;
	/** How many leaked witness rows the janitor removes per call — bounded, since this runs inside every mutating unit. */
	const LAST_TX_JANITOR_LIMIT = 50;
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
	 * @return bool|null True only when exactly one row was inserted (and,
	 *              for a non-exempt name, its version bump also landed);
	 *              false for a PROVEN miss (a real name collision, or
	 *              versioned() proving the commit did not land); null —
	 *              UNKNOWN, never the same as false (Ruling S51, Codex
	 *              round-20 P1 on #88) — when versioned() could not prove
	 *              whether this insert's own commit landed or not. A
	 *              caller that treats a plain false as "try a different
	 *              name" or "this one is already taken by someone else"
	 *              must not read null that way — see each such caller for
	 *              its own retryable answer.
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
		if ( null === $outcome['committed'] ) {
			return null; // Ruling S51: UNKNOWN, not a proven miss.
		}
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
		// Ruling S86 (Codex round-37 P1 on #88): the S80 pattern applied
		// here — see RESERVATION_PREFIX's own docblock for the whole
		// mechanism, and this method's own updated docblock (below the
		// class-level constants) for the bug it closes. Derived from the
		// SAME two ingredients Aura_Worker_Door_Holds::hold() derives a
		// hold's own ref from: `aura_ref` (Aura's own correlation id for
		// the intercepted request, or any other identifier of the
		// request itself) and a hash of `touches`.
		$touches        = is_array( $entry['touches'] ?? null ) ? $entry['touches'] : array();
		$aura_ref       = isset( $entry['aura_ref'] ) ? (string) $entry['aura_ref'] : '';
		$reservation_id = '' !== $aura_ref
			? self::derive_rotation_target( self::LOG_RESERVATION_NAMESPACE, $aura_ref . '|' . sha1( wp_json_encode( $touches ) ) )
			: '';
		if ( '' !== $reservation_id ) {
			$reserved = self::find_reserved_seq( $reservation_id );
			if ( 'unreadable' === $reserved ) {
				return new WP_Error(
					'aura_log_failed',
					'This site could not prove whether the previous attempt landed; retry.',
					array( 'status' => 503, 'retry_after' => 5, 'may_have_run' => true )
				);
			}
			if ( is_int( $reserved ) ) {
				// PRESENT, and its row PROVEN still real (find_reserved_seq()
				// never trusts a reservation whose row is gone): this
				// request's own prior — possibly ambiguous — attempt
				// already landed. Recognised, not re-allocated.
				return $reserved;
			}
			// Proven absent (or the reservation named a row that no
			// longer exists — already acked/purged, or never actually
			// landed): fall through and allocate fresh, below.
		}
		// BELT-AND-BRACES, NEVER "ELSE" (Ruling S86): a caller may supply
		// `reserved_seq` ALONGSIDE `aura_ref` — echoing back what an
		// EARLIER ambiguous answer from THIS SAME call handed it — and
		// this check runs regardless of whether the block above found
		// anything. It matters even when `aura_ref` IS given: the
		// reservation-index write above is itself best-effort (a SEPARATE
		// statement from the pending row's own INSERT, so it can fail or
		// go ambiguous independently of it) — `reserved_seq` names the
		// row DIRECTLY, with no dependency on that second write having
		// landed at all. It is ALSO the sound fallback for a caller with
		// NO idempotency material to derive a reservation from in the
		// first place (see this method's own docblock for why this is
		// the answer, not S80's own unrecoverable random-ref fallback):
		// `reserved_seq` is a PLAIN INTEGER this method already hands
		// back in EVERY ambiguous answer below, regardless of whether
		// `aura_ref` was ever given — a caller need only echo it back on
		// its own retry, no derivation required.
		if ( isset( $entry['reserved_seq'] ) && (int) $entry['reserved_seq'] > 0 ) {
			$reserved_seq = (int) $entry['reserved_seq'];
			$row_raw      = self::raw_option_for( self::PREFIX . $reserved_seq );
			if ( self::raw_option_was_unreadable() ) {
				return new WP_Error(
					'aura_log_failed',
					'This site could not prove whether the previous attempt landed; retry.',
					array( 'status' => 503, 'retry_after' => 5, 'may_have_run' => true )
				);
			}
			// STILL PENDING, not merely present (Ruling S87 — the SAME
			// reasoning find_reserved_seq() applies just below): a row
			// this echoed seq once named that has since been admitted
			// and settled is a genuinely NEW attempt's business, never
			// this one's to recognise.
			$row = null !== $row_raw ? maybe_unserialize( $row_raw ) : null;
			if ( is_array( $row ) && 'pending' === ( $row['result'] ?? null ) ) {
				return $reserved_seq;
			}
			// Proven absent, or moved past pending: fall through and allocate fresh.
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
			if ( '' !== $reservation_id ) {
				// Stamped on the row itself too (forensics/verification —
				// never read back by find_reserved_seq(), which only ever
				// trusts the DEDICATED reservation-index row's own
				// existence).
				$row['reservation'] = $reservation_id;
			}
			$won = self::insert_unique( self::PREFIX . $seq, $row );
			if ( null === $won ) {
				// Ruling S63 (Codex round-24 P1 on #88): STOP — never
				// allocate a SECOND seq behind an ambiguous first one.
				// insert_unique() now answers null for "committed but the
				// witness could not be proven" (Ruling S51), and this
				// loop used to test it as a plain boolean: `if (null)` is
				// falsy, so this fell straight into "collision, try the
				// next number" — allocating $seq+1 while THIS row may
				// already exist, pending, at $seq, permanently splitting
				// the log's own contiguous numbering. Only a PROVEN
				// `false` (insert_unique_write()'s own conditional INSERT
				// finding the name already taken) is a genuine collision
				// worth retrying past. Ambiguous is retryable, but not by
				// looping here: the row may already be exactly what this
				// call wanted at $seq, and the reconciler's own
				// stale-pending sweep (or a healthy retry of this SAME
				// call, once state can be re-read) is what finds it
				// either way — never a second, sibling row.
				//
				// Ruling S86 (Codex round-37 P1 on #88): best-effort
				// registration of the reservation index BEFORE answering
				// — whether or not THIS ambiguous insert actually landed,
				// so a retry (with the SAME `aura_ref`, or echoing back
				// `reserved_seq` from THIS error) can find $seq directly,
				// never re-deriving a fresh one behind it. Never trusted
				// blind: find_reserved_seq() re-verifies the row itself
				// exists before ever recognising a reservation.
				if ( '' !== $reservation_id ) {
					self::insert_unique( self::RESERVATION_PREFIX . $reservation_id, array( 'seq' => $seq ) );
				}
				return new WP_Error(
					'aura_log_failed',
					'This site could not prove whether the previous attempt landed; retry.',
					array(
						'status'       => 503,
						'retry_after'  => 5,
						'may_have_run' => true,
						'reserved_seq' => $seq,
						'reservation'  => $reservation_id, // '' when the caller gave no aura_ref
					)
				);
			}
			if ( $won ) {
				// The ack raises the floor with raw SQL, so this request's
				// option cache can still hold the value from before it.
				wp_cache_delete( self::FLOOR, 'options' );
				if ( $seq > self::floor() ) {
					if ( '' !== $reservation_id ) {
						// Registered on a CLEAN win too (Ruling S86): a
						// response lost in transit between this write and
						// Aura is the identical ambiguity from Aura's own
						// point of view, and a retry deserves the SAME
						// recognition either way.
						self::insert_unique( self::RESERVATION_PREFIX . $reservation_id, array( 'seq' => $seq ) );
					}
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

	/**
	 * Ruling S86 (Codex round-37 P1 on #88): does a reservation identity
	 * already name a REAL row — never trusted on the reservation-index
	 * row's own say-so alone, since that row can outlive the pending row
	 * it once named (already acked/purged since) or point at a seq an
	 * ambiguous insert never actually landed at.
	 *
	 * STILL PENDING, NOT MERELY PRESENT (Ruling S87, Codex round-38 P1 on
	 * #88): a caller can legitimately reach `open_pending()` MORE THAN
	 * ONCE with the SAME derived identity across genuinely SEPARATE
	 * attempts — a replay whose FIRST try was settled terminally (a
	 * snapshot failure, an interrupted claim) and is later retried fresh
	 * derives the SAME `aura_ref` (the hold's own ref never changes)
	 * both times. Recognising a reservation whose row has ALREADY moved
	 * past `pending` handed the retry back the OLD, already-terminal
	 * seq — `admit()`/`settle()` on it then correctly refuse (PENDING-ONLY,
	 * Ruling P27), silently swallowing what should have been a brand
	 * new attempt. Only a row STILL `pending` (never admitted, never
	 * settled) is the SAME logical attempt an ambiguous insert leaves
	 * behind — which is the ONLY case this mechanism exists to
	 * recognise; anything further along is a genuinely new attempt
	 * against an old identity, and allocates fresh exactly like a
	 * proven-absent reservation would.
	 *
	 * @param string $reservation_id A derived reservation identity (see
	 *                                `RESERVATION_PREFIX`'s own docblock).
	 * @return int|string|null The seq, PROVEN still real AND still
	 *                          pending; `'unreadable'` when any raw read
	 *                          could not be proven (the caller answers
	 *                          its own retryable 503); `null` when this
	 *                          identity has no reservation on record, or
	 *                          the one it named no longer points at a
	 *                          real, still-pending row — either way, the
	 *                          caller allocates fresh.
	 */
	private static function find_reserved_seq( $reservation_id ) {
		$raw = self::raw_option_for( self::RESERVATION_PREFIX . $reservation_id );
		if ( self::raw_option_was_unreadable() ) {
			return 'unreadable';
		}
		if ( null === $raw ) {
			return null;
		}
		$rec = maybe_unserialize( $raw );
		$seq = is_array( $rec ) && isset( $rec['seq'] ) ? (int) $rec['seq'] : 0;
		if ( $seq < 1 ) {
			return null;
		}
		$row_raw = self::raw_option_for( self::PREFIX . $seq );
		if ( self::raw_option_was_unreadable() ) {
			return 'unreadable';
		}
		if ( null === $row_raw ) {
			return null; // stale: the reservation outlived (or never landed at) the row it names
		}
		$row = maybe_unserialize( $row_raw );
		if ( ! is_array( $row ) || 'pending' !== ( $row['result'] ?? null ) ) {
			return null; // moved on: a genuinely new attempt, not the ambiguous one this mechanism recognises
		}
		return $seq;
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
	 * The SAME proven raw read `epoch_raw()`/`binding_raw()` use above,
	 * exposed generically (Ruling S48, Codex round-19 P2 on #88) for a
	 * caller OUTSIDE this class that owns its own option name and needs
	 * the identical present/absent/unreadable tri-state —
	 * `Aura_Worker_Elementor_Door::persisted_computed_state()`'s own
	 * COMPUTED tuple, which used to read through plain `get_option()`
	 * (indistinguishable "absent" vs "unreadable") and so could serve a
	 * fragment's `active`/`seam`/`door` from this REQUEST's live
	 * computation — the exact Ruling S28 race — paired with an
	 * `observation` witness for a version this read never actually
	 * proved anything about.
	 *
	 * @param string $name Option name.
	 * @return string|null Raw serialised bytes; null for EITHER a
	 *                      genuinely absent row or an unproven read — call
	 *                      `raw_option_was_unreadable()` immediately
	 *                      afterwards to tell them apart.
	 */
	public static function raw_option_for( $name ) {
		return self::raw_option( $name );
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
	 * @var bool Set by raw_option() (Ruling S37/S38/S39, Codex round-15
	 * class sweep + round-16 on #88): did the MOST RECENT call fail to
	 * prove its read, rather than finding the option genuinely absent?
	 * Reset at the start of every such read — a per-call outcome, meant to
	 * be checked by the caller IMMEDIATELY afterwards, before anything else
	 * in this same request can issue a second raw_option() read and
	 * overwrite it.
	 */
	private static $raw_option_unreadable = false;

	/**
	 * One option's raw, still-serialised bytes from the DATABASE — never this
	 * request's cache. The predicate a compare-and-swap fences on.
	 *
	 * UNPROVEN collapses into the same `null` a genuinely absent row answers
	 * — this method's own array|null CONTRACT is unchanged, for every caller
	 * that already fails closed on null exactly as it should (a CAS whose
	 * predicate cannot be read simply does not match, and refuses to write,
	 * whether the row is absent or merely unreadable). `fence_identity()`
	 * and `row_for_fence()` still read `raw_option_read()` directly when
	 * they need the two told apart at the CALL SITE.
	 *
	 * Some callers, though, do not fail closed on this null — they read it
	 * as a POSITIVE fact (`floor_raw()`'s "no floor" collapsing an
	 * unreadable floor to 0; `is_closed_raw()`'s "not closed" collapsing an
	 * unreadable marker to "open") and that fact then feeds a DEFINITIVE
	 * report or gets PERSISTED (Rulings S38/S39, Codex round-16 P1/P2 on
	 * #88). `raw_option_was_unreadable()` is the signal those specific
	 * callers consult, ALONGSIDE this method's unchanged return, to refuse
	 * that step instead.
	 *
	 * @param string $name Option name.
	 * @return string|null
	 */
	private static function raw_option( $name ) {
		$read                          = self::raw_option_read( $name );
		self::$raw_option_unreadable = ! $read['ok'];
		return $read['value'];
	}

	/**
	 * Whether the MOST RECENT `raw_option()` call could not prove its read
	 * (Ruling S37/S38/S39, Codex round-15 class sweep + round-16 on #88).
	 * The caller must read this immediately after — before anything else in
	 * this same request can issue a second `raw_option()` read and
	 * overwrite it.
	 *
	 * @return bool
	 */
	public static function raw_option_was_unreadable() {
		return self::$raw_option_unreadable;
	}

	/** @param int $seq Seq. @return array|null */
	public static function get( $seq ) {
		$row = get_option( self::PREFIX . (int) $seq, null );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * One log row, RAW — never this request's own object cache (Ruling S31,
	 * Codex round-14 P1 on #88). `get()` above goes through `get_option()`,
	 * which on the default non-persistent cache answers whatever THIS
	 * request already cached — a `pending` row read on an earlier attempt,
	 * say — even after a DIFFERENT request has since settled it and moved
	 * the door version. `log_after()` uses this, never `get()`, for exactly
	 * that reason.
	 *
	 * THREE ANSWERS (Ruling S36, Codex round-15 P1 on #88), the same shape
	 * `row_for_fence()` already uses and for the same reason: the row
	 * (array), NULL for a seq that genuinely has no row (a hole — only an
	 * ack deletes one, and it raises the floor first, so this cannot happen
	 * by construction while walking forward from the floor), and FALSE for
	 * a read that could not be PROVEN (Ruling S1's nonce probe) — a
	 * transient SELECT failure, indistinguishable from a hole to a caller
	 * that only checks `null`. `log_after()`'s walk used to treat both the
	 * same way — silently truncating the log under a witness that still
	 * claimed to be current — which is exactly the bug this closes.
	 *
	 * @param int $seq Seq.
	 * @return array|null|false
	 */
	public static function get_raw( $seq ) {
		$read = self::raw_option_read( self::PREFIX . (int) $seq );
		if ( ! $read['ok'] ) {
			return false;
		}
		$val = null === $read['value'] ? null : maybe_unserialize( $read['value'] );
		return is_array( $val ) ? $val : null;
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
			// Ruling S37 (Codex round-15 class sweep on #88): whether this
			// is a genuinely absent row or a read that could not be proven
			// (row_from_db_was_unreadable() distinguishes them), the answer
			// is the SAME refusal either way — false, which admit() (this
			// method's only caller) already turns into the retryable
			// `aura_log_failed` 503 rather than a silent no-op. Documented
			// here so the two cases are never conflated by a FUTURE caller
			// that might otherwise treat "nothing to patch" as fine to
			// ignore.
			return false;
		}
		$after = array_merge( $before, $fields );
		return self::write_option_where( $option, $after, $before );
	}

	/**
	 * Whether the MOST RECENT `row_from_db()` call (via `patch()`) could
	 * not prove its read, rather than finding the row genuinely absent
	 * (Ruling S37, Codex round-15 class sweep on #88).
	 *
	 * @return bool
	 */
	public static function row_from_db_was_unreadable() {
		return self::$row_from_db_unreadable;
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
		self::$row_from_db_unreadable = false;
		$wpdb->last_error              = '';
		$raw                            = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option ) );
		if ( '' !== (string) $wpdb->last_error ) {
			self::$row_from_db_unreadable = true;
			return null;
		}
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
	 * The ack floor as the DATABASE holds it, RAW — never this request's own
	 * object cache (Ruling S31, Codex round-14 P1 on #88). `floor()` above
	 * goes through `get_option()`, which — on the default non-persistent
	 * cache — answers whatever THIS request already cached from an EARLIER
	 * read, even after a DIFFERENT request has since moved the floor. A
	 * caller building state that must be provably paired with a specific
	 * door version (status_fragment()'s own bracket) cannot risk that: see
	 * `raw_option()`'s own docblock for the proof this shares.
	 *
	 * @return int
	 */
	public static function floor_raw() {
		$raw = self::raw_option( self::FLOOR );
		if ( self::raw_option_was_unreadable() ) {
			// Ruling S38 (Codex round-16 P1 on #88): a transient failure
			// here used to convert a POSITIVE ack floor to 0 — never
			// "fails closed", the opposite: log_after() then started its
			// walk from row 1, mistook the first already-acked (purged)
			// row for a hole, and returned no terminal rows at all, while
			// still reporting the CURRENT observation as if that empty
			// page were proven complete. Sticky per attempt — see the
			// property's own docblock.
			self::$floor_unreadable_this_attempt = true;
		}
		return null === $raw ? 0 : (int) $raw;
	}

	/**
	 * Whether ANY `floor_raw()` call this attempt could not prove its read
	 * (Ruling S38, Codex round-16 P1 on #88). Checked by `status_fragment()`
	 * once, immediately after `build_status_fragment_state()` returns —
	 * exactly like `log_walk_was_unreadable()`.
	 *
	 * @return bool
	 */
	public static function floor_was_unreadable_this_attempt() {
		return self::$floor_unreadable_this_attempt;
	}

	/**
	 * Reset at the top of EVERY `status_fragment()` attempt — both the
	 * first and any retry — so a failure from a PREVIOUS attempt (already
	 * accounted for by that attempt's own `observation: null`) never leaks
	 * into this one, and a failure from a PREVIOUS, unrelated request never
	 * lingers into this one either.
	 */
	public static function reset_floor_unreadable_for_attempt() {
		self::$floor_unreadable_this_attempt = false;
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

	/** @var bool Set by log_shape_raw() (Ruling S61, Codex round-23 P1 on #88): did the MOST RECENT call fail to prove its read? */
	private static $log_shape_unreadable = false;

	/**
	 * Whether the MOST RECENT `log_shape_raw()` call could not prove its
	 * read (Ruling S61). Checked by the caller IMMEDIATELY after — before
	 * anything else in this same request can issue a second
	 * `log_shape_raw()` read and overwrite it.
	 *
	 * @return bool
	 */
	public static function log_shape_was_unreadable() {
		return self::$log_shape_unreadable;
	}

	/**
	 * A LOG-SHAPE identity — `{ top, floor, pending_count,
	 * terminal_count_above_floor, terminal_top }` — from ONE round trip
	 * (Ruling S61, Codex round-23 P1 on #88).
	 *
	 * THE BUG THIS CLOSES: `sync_computed_state()`'s persisted tuple
	 * tracked `{ active, seam, door, rewind_top, running, interrupted,
	 * held }` — none of which a `wp_options` RESTORE that happens to
	 * preserve the epoch, the door version, and every one of THOSE fields
	 * would touch. A restore that flips ONE row's own state — seq N
	 * settled back to `pending`, say, while Aura's own cursor already sits
	 * at N — passes `sync_computed_state()`'s steady-state fast path
	 * unnoticed: nothing versions, the restored (STALE) door version
	 * stands, and Aura's strictly-greater comparison rejects every
	 * fragment describing the log's real, current shape for as long as
	 * some UNRELATED mutation does not happen to advance the version on
	 * its own. Folding this shape into the SAME tuple makes the FIRST
	 * serve that sees the restored state a real, detected transition,
	 * versioned through the exact mechanism Rulings S29/S45 already
	 * established for `rewind_top`/`running`/`interrupted`: the ordinary
	 * CLOCK-FLOORED bump (Ruling S4), landing above every pre-restore
	 * value because a restore rolls the STORED counter back but never the
	 * wall clock.
	 *
	 * ONE query, not one per row: the row set matching this door's
	 * PREFIX, fetched whole and aggregated in PHP — the log is BOUNDED by
	 * MAX_UNACKED admission (Ruling P82), never an unbounded scan, and
	 * this is the SAME "fetch bounded rows raw, parse in PHP" shape
	 * `log_after()`/`full_report_raw()`/`stale_pending()` already use,
	 * never a fragile SQL-text match against a PHP-serialized blob.
	 *
	 * `pending_count`/`terminal_count_above_floor`/`terminal_top` are
	 * DERIVED from each row's own `result` field (`'pending'` or a
	 * terminal verdict) — never from `top`/`floor` alone, which a restore
	 * can hold constant while still flipping what a specific row between
	 * them says.
	 *
	 * `fingerprint` (Ruling S64, Codex round-24 P2 on #88) closes the
	 * remaining gap: a restore that flips a TERMINAL row's own verdict
	 * (`ok` back to `failed`, say) with the seq, the floor and every
	 * count above all UNCHANGED passes `top`/`floor`/`pending_count`/
	 * `terminal_count_above_floor`/`terminal_top` unnoticed — none of
	 * them look at a row's content past its `result` field's PENDING/
	 * TERMINAL distinction. `fingerprint` is a sha1 over the SORTED
	 * `seq|status|sha1(full row JSON)` tuple of every row above the
	 * floor, pending or terminal alike, from THIS SAME per-row read —
	 * never a second query — so any row's own content changing, whatever
	 * field, changes this even when nothing else in the shape does.
	 *
	 * @return array{ top: int, floor: int, pending_count: int, terminal_count_above_floor: int, terminal_top: int|null, fingerprint: string }|null
	 *         Null when `top`, the floor, or the row read itself could not
	 *         be proven — check `log_shape_was_unreadable()` immediately
	 *         after.
	 */
	public static function log_shape_raw() {
		global $wpdb;
		self::$log_shape_unreadable = false;
		$top = self::highest_row_seq();
		if ( null === $top ) {
			self::$log_shape_unreadable = true;
			return null;
		}
		$floor = self::floor_raw();
		if ( self::floor_was_unreadable_this_attempt() ) {
			self::$log_shape_unreadable = true;
			return null;
		}
		$like              = $wpdb->esc_like( self::PREFIX ) . '%';
		$wpdb->last_error  = '';
		// LIKE alone, never a second REGEXP clause: the numeric-suffix
		// filter below (the SAME defensive read count_unacked()/
		// highest_row_seq() apply to this shared prefix) already excludes
		// FLOOR/FULL_MARKER/FULL_COUNTER, which share this prefix but
		// never end in digits — a second SQL-side filter would only
		// narrow what MySQL itself returns, never what this method
		// trusts, and this is the exact "names AND values for a prefix"
		// shape the rest of this codebase's own raw bulk reads already
		// use.
		$rows              = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			self::$log_shape_unreadable = true;
			return null;
		}
		$pending_count              = 0;
		$terminal_count_above_floor = 0;
		$terminal_top               = null;
		// Ruling S64 (Codex round-24 P2 on #88): a per-row FINGERPRINT
		// tuple, collected from this SAME loop — never a second query —
		// for every row above the floor, pending or terminal alike. The
		// counts above answer "how many, and which top", which a restore
		// that flips a TERMINAL row's own verdict (e.g. `ok` back to
		// `failed`) with the seq, the floor and every count UNCHANGED
		// sails straight through: same top, same floor, same
		// pending/terminal counts, same terminal_top — nothing here
		// would notice. The fingerprint is what does: `seq|status|sha1(
		// full row JSON)` per row, sorted (never insertion order, which
		// this SQL query does not guarantee), then hashed as ONE string —
		// any row's own CONTENT changing, whatever field, changes this
		// even when nothing else in the shape does.
		$tuples = array();
		foreach ( $rows as $r ) {
			// ROW_REGEXP already scoped the SQL match to a purely numeric
			// suffix, but the trailing digits are still pulled explicitly
			// (never assumed) — the same defensive read
			// count_unacked()/highest_row_seq() apply to this shared
			// prefix.
			if ( ! preg_match( '/(\d+)$/', (string) $r['option_name'], $m ) ) {
				continue;
			}
			$seq = (int) $m[1];
			$row = maybe_unserialize( $r['option_value'] );
			if ( ! is_array( $row ) ) {
				continue;
			}
			$result = isset( $row['result'] ) ? (string) $row['result'] : 'pending';
			if ( $seq > $floor ) {
				$tuples[] = $seq . '|' . $result . '|' . sha1( (string) wp_json_encode( $row ) );
			}
			if ( 'pending' === $result ) {
				++$pending_count;
				continue;
			}
			if ( $seq > $floor ) {
				++$terminal_count_above_floor;
				$terminal_top = null === $terminal_top ? $seq : max( $terminal_top, $seq );
			}
		}
		sort( $tuples, SORT_STRING );
		return array(
			'top'                        => $top,
			'floor'                      => $floor,
			'pending_count'              => $pending_count,
			'terminal_count_above_floor' => $terminal_count_above_floor,
			'terminal_top'               => $terminal_top,
			'fingerprint'                => sha1( implode( "\n", $tuples ) ),
		);
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
	 * `$floor` (Ruling S67, Codex round-25 P2 on #88): the ack floor to
	 * filter above, when the caller already has one it can PROVE. A caller
	 * inside a `status_fragment()`/`governor_block()` version bracket must
	 * pass its own `floor_raw()` read (registering that read's unreadable
	 * state itself, exactly as it does for every other raw field the
	 * bracket reports) — never this method's own default, which falls
	 * back to `floor()`'s get_option()-cached value. A concurrent `ack()`
	 * that moves the floor between `reconcile()` and the bracket opening
	 * left that cache stale, the same shape of race Ruling S66 closed for
	 * the held queue: the count then filtered above a floor already known
	 * to be behind, silently over-counting rows the log itself considers
	 * already acked. Null (the default) is correct for every OTHER
	 * caller — outside a bracket, `floor()`'s cache is this request's best
	 * available answer and a raw read buys nothing worth its own query.
	 *
	 * @param int|null $floor The proven ack floor to filter above, or null
	 *                        to fall back to `floor()`.
	 * @return int|null
	 */
	public static function count_unacked( $floor = null ) {
		global $wpdb;
		$like             = $wpdb->esc_like( self::PREFIX ) . '%';
		$wpdb->last_error = '';
		$n                = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name REGEXP %s AND CAST(SUBSTRING(option_name, %d) AS UNSIGNED) > %d",
				$like,
				self::ROW_REGEXP,
				strlen( self::PREFIX ) + 1,
				null === $floor ? self::floor() : (int) $floor
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

	/**
	 * Whether the closure marker exists, RAW — never this request's own
	 * object cache (Ruling S31, Codex round-14 P1 on #88). Same reasoning as
	 * `floor_raw()`'s own docblock: used by `door_state()`, whose callers
	 * (the fragment builder's live fallback, `sync_computed_state()`'s own
	 * comparison) must not read a `false` this request cached before some
	 * OTHER request closed the log, or a `true` cached before it reopened.
	 *
	 * @return bool
	 */
	public static function is_closed_raw() {
		$marker                       = self::raw_option( self::FULL_MARKER );
		self::$closure_read_unreadable = self::raw_option_was_unreadable();
		return null !== $marker;
	}

	/**
	 * Whether the MOST RECENT `is_closed_raw()` call could not prove its
	 * read, rather than finding the marker genuinely absent (Ruling S39,
	 * Codex round-16 P2 on #88): an unreadable marker used to answer
	 * `false` here — read as "not closed" by `door_state()` — a fabricated
	 * "open" a full log's `sync_computed_state()` would then persist and
	 * bump the observation for. Checked by `door_state()`'s own callers
	 * immediately after calling it, before anything else in this same
	 * request can issue a second `is_closed_raw()` read and overwrite it.
	 *
	 * @return bool
	 */
	public static function closure_read_was_unreadable() {
		return self::$closure_read_unreadable;
	}

	/**
	 * One owner: the INSERT.
	 *
	 * AN UNPROVEN CONFIRMING READ IS NOT "NOT CLOSED" — it is audited here,
	 * not changed (Ruling S37 sweep, part 2, Codex round-17 on #88).
	 * `insert_unique()` answers false to two different events — a genuine
	 * write failure, or losing the race to a concurrent closer whose own
	 * marker is already there — and this confirming read is what tells
	 * them apart. When THAT read is itself unproven
	 * (`raw_option_was_unreadable()`), this still answers `false`, exactly
	 * as a genuinely-absent marker does: EVERY caller already treats
	 * `false` as retryable (no `bump_refused()`, no `aura_log_full`, a
	 * plain `aura_log_failed`/`aura_log_unreadable` 503 instead — see
	 * their own call sites), never as a proven "still open" that would
	 * license a write the log might already be closed to. The two cases
	 * are NOT collapsed into a false definitive answer, only into the
	 * same SAFE one: a closure nobody can prove either way is retried,
	 * never assumed either way.
	 */
	public static function close() {
		if ( self::insert_unique( self::FULL_MARKER, gmdate( 'c' ) ) ) {
			return true;
		}
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
				// Ruling S84 (Codex round-35 P1 on #88): this statement's
				// own return used to be ignored entirely — a failed
				// upsert (a deadlock, a driver error) still reported
				// `mutated: true` unconditionally below, telling
				// versioned() to COMMIT and bump the door version for a
				// counter that never actually changed.
				self::must_succeed(
					$wpdb->query(
						$wpdb->prepare(
							"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no') ON DUPLICATE KEY UPDATE option_value = option_value + 1",
							self::FULL_COUNTER
						)
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

	/**
	 * @var bool|null Cached once per request (Ruling S13), but ONLY once
	 * the probe has actually answered `true` or `false` (Ruling S47, Codex
	 * round-19 P1 on #88) - an unreadable probe is never cached, so the
	 * NEXT read tries again rather than freezing this request's transient
	 * miss as its permanent answer.
	 */
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
	 * bump into one round trip.
	 *
	 * UNREADABLE IS ITS OWN ANSWER, NEVER `false` (Ruling S47, Codex
	 * round-19 P1 on #88, superseding this ruling's own earlier
	 * fail-closed-to-false reading). A failed probe used to be cached as
	 * NON-transactional, on the theory that a table this method cannot
	 * PROVE supports rollback should be treated as one that does not — but
	 * `versioned()` reads `false` as "take the autocommit branch: no
	 * transaction, no rollback, `$writes()` lands the instant it runs" —
	 * exactly the WRONG branch for a real InnoDB table hit by a transient
	 * `SHOW TABLE STATUS` failure, letting a concurrent `/status` poll
	 * certify state a half-finished mutation had not actually made
	 * durable. This method now returns `null` for an unreadable probe — a
	 * THIRD state `versioned()` answers retryable for, before `$writes()`
	 * ever runs — and never caches it, so the next mutation tries the
	 * probe fresh rather than inheriting this one's miss.
	 *
	 * EXACT MATCH, NEVER `LIKE` (Ruling S23, Codex round-9 P2 on #88).
	 * `SHOW TABLE STATUS LIKE '%s'` treats the pattern as a real MySQL LIKE
	 * expression, in which `_` is a single-character WILDCARD and `%` is a
	 * multi-character one — both of which appear in ordinary table names
	 * (`wp_options` carries an unescaped `_`). Unescaped, that LIKE could
	 * match a DIFFERENT table whose name merely has the same length with
	 * any character standing in for the underscore (`wpXoptions`), and this
	 * method would report — and cache for the whole request — THAT table's
	 * engine instead of `wp_options`'s own. `WHERE Name = %s` is a plain
	 * equality comparison: no metacharacters, no possibility of matching
	 * anything but the exact table this method means to ask about.
	 *
	 * @return bool
	 */
	private static function engine_is_transactional() {
		if ( null !== self::$engine_transactional ) {
			return self::$engine_transactional;
		}
		global $wpdb;
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $wpdb->options ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		if ( '' !== (string) $wpdb->last_error || ! is_object( $row ) || ! isset( $row->Engine ) ) {
			// UNREADABLE (Ruling S47): a driver failure, or a row this
			// server did not return at all — neither is the fact "this
			// table is not InnoDB", so this answers unknown and caches
			// NOTHING, leaving the next call free to probe again.
			return null;
		}
		self::$engine_transactional = ( 'INNODB' === strtoupper( (string) $row->Engine ) );
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
	 * Ruling S82 (Codex round-33 P2 on #88): forces `self::OBSERVATION`
	 * strictly past a value AURA has already accepted, when this site's
	 * OWN copy of it has fallen behind — see
	 * `Aura_Worker_Elementor_Door::status_fragment()`'s own
	 * `$observation_seen` parameter for why this exists at all. The
	 * identity baseline `sync_served_identities()` guards lives in the
	 * SAME `wp_options` snapshot as the content it describes, so a
	 * whole-DB restore that leaves the site's user-visible content
	 * unchanged (a "top-preserving" restore) rewinds BOTH together — the
	 * site's own copy of `OBSERVATION` looks perfectly self-consistent
	 * with its own (also-restored) content, so nothing INTERNAL to the
	 * site's own CAS-based checks (`sync_computed_state()`,
	 * `sync_served_identities()`) ever fires again, and the version this
	 * site reports is frozen at the restored value forever. Only AURA's
	 * own, externally-held record of what it already accepted can name
	 * this — hence the parameter comes FROM the caller, never inferred
	 * here.
	 *
	 * SAME CLOCK FLOOR as `bump_door_version_write()` (Ruling S4/S7),
	 * with one more term: `GREATEST( current + 1, $seen + 1, clock )`.
	 * The wall clock alone almost always exceeds `$seen` (a value AURA
	 * accepted at some strictly EARLIER moment) — but Ruling S4's own
	 * docblock already accepts a backward NTP correction as a fault this
	 * counter cannot see around; flooring on `$seen + 1` explicitly means
	 * this restamp lands strictly past it even then, rather than
	 * depending on clock monotonicity alone.
	 *
	 * WRAPPED IN `versioned()` — never a bare write — so a caller gets
	 * the SAME committed/false/null tri-state every other door mutation
	 * already answers through it. This is a real state transition
	 * (AURA's witness demonstrably moved this site's own clock forward),
	 * never exempt bookkeeping — unlike `bump_door_version()` itself,
	 * which callers already invoke from INSIDE their own open
	 * transaction and therefore cannot re-wrap.
	 *
	 * REFUSES AT OR ABOVE `MAX_OBSERVATION_SEEN` (Ruling S83, Codex
	 * round-34 P1 on #88), belt-and-braces: the `/status` route's own
	 * `door_observation_seen` REST arg `validate_callback` already
	 * refuses anything above that cap with a `400` before this method is
	 * ever reached in production — but this is a PUBLIC method, and the
	 * cap is checked HERE too, before `$seen + 1` is ever computed, so no
	 * caller (present or future) can reach the unchecked arithmetic that
	 * let `$seen + 1` overflow a 64-bit int into a float and float
	 * straight through this query's `%d` placeholders uncontrolled.
	 * Refuses PLAINLY — no write attempted, no `versioned()` unit even
	 * opened — with the exact same `{ committed: false }` shape a caller
	 * already treats identically to "nothing happened" on any other
	 * refusal path.
	 *
	 * @param int $seen A non-negative observation AURA has already
	 *                   accepted for the door epoch this call concerns —
	 *                   validated non-negative (and, since Ruling S83,
	 *                   capped) by the caller (the REST arg's own
	 *                   `validate_callback`); clamped to zero here
	 *                   regardless, defensively.
	 * @return array{ committed: bool|null } versioned()'s own outcome
	 *              shape — the caller (`Aura_Worker_Elementor_Door`'s own
	 *              `maybe_restamp_observation_forward()`) does not act
	 *              differently on any of the three outcomes: an
	 *              ambiguous, refused or failed restamp simply means the
	 *              version this poll serves may still be stale, exactly
	 *              as if this call had never been made — the NEXT poll
	 *              carrying the same `$observation_seen` tries again.
	 */
	public static function restamp_observation_forward( $seen ) {
		$seen = max( 0, (int) $seen );
		if ( $seen >= self::MAX_OBSERVATION_SEEN ) {
			return array( 'committed' => false );
		}
		return self::versioned(
			function () use ( $seen ) {
				global $wpdb;
				$wpdb->last_error = '';
				// Built as TEXT, never assembled as one PHP int (Ruling
				// S7) — see bump_door_version_write()'s own docblock for
				// why.
				list( $frac, $sec ) = explode( ' ', microtime( false ), 2 );
				$usec  = min( 999999, (int) ( ( (float) $frac ) * 1000000 ) );
				$clock = sprintf( '%d%06d', (int) $sec, $usec );
				$ok    = $wpdb->query(
					$wpdb->prepare(
						"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, GREATEST(1, %d, %s), 'no') ON DUPLICATE KEY UPDATE option_value = GREATEST(CAST(option_value AS UNSIGNED) + 1, %d, %s)",
						self::OBSERVATION,
						$seen + 1,
						$clock,
						$seen + 1,
						$clock
					)
				);
				wp_cache_delete( self::OBSERVATION, 'options' );
				$won = ( false !== $ok && '' === (string) $wpdb->last_error );
				return array(
					'mutated' => $won,
					'result'  => $won,
					// Ruling S11: repeated by versioned() after commit.
					'evict'   => array( self::OBSERVATION, 'notoptions' ),
				);
			}
		);
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
	 * THE COMMIT WITNESS MUST ALSO BE DURABLE — AND PER TRANSACTION
	 * (Rulings S30/S32, Codex rounds 13/14 P1 on #88). Ruling S16's
	 * session-variable nonce has a gap of its own: if `COMMIT` genuinely
	 * lands on THIS session but the connection then drops before this
	 * method's own `SELECT @aura_door_tx` can run, the session variable is
	 * gone — indistinguishable from the case Ruling S16 exists to catch,
	 * where `COMMIT` ran on a fresh session that never opened this
	 * transaction at all. Both answer NULL or a mismatch, so a mutation
	 * that fully committed could be reported `committed: false`. A plain
	 * option row does not have this problem: unlike a session variable, it
	 * survives the reconnect that follows a landed `COMMIT`, because it
	 * lives in the very table that `COMMIT` just made durable.
	 *
	 * Ruling S30 wrote that row under ONE SHARED name for every unit —
	 * which is itself unsafe: transaction A commits, reconnects before its
	 * own session check, and transaction B (a different, unrelated unit)
	 * commits afterwards and OVERWRITES that same shared row with B's OWN
	 * nonce before A's fallback ever reads it — A's fallback then reads
	 * B's nonce, a mismatch, and reports `committed: false` for a mutation
	 * that was fully durable. Ruling S32 makes the witness PER
	 * TRANSACTION instead: the option NAME itself carries the nonce
	 * (`self::LAST_TX_PREFIX . $tx_nonce`), so no two units — however they
	 * interleave — can ever collide on the same row. Existence of THAT
	 * exact row (value: the write's own unix timestamp, never compared)
	 * is the whole proof; nothing else could have written a row under a
	 * name only this call's own nonce determines. Once the session check
	 * or this fallback has answered — either way, best effort, OUTSIDE
	 * the transaction, since it has already committed or rolled back by
	 * then — the unit deletes its own row; a BOUNDED janitor inside this
	 * same method sweeps up whatever a died process left behind (a
	 * disconnect between COMMIT and that delete), never more than
	 * `self::LAST_TX_JANITOR_LIMIT` rows older than
	 * `self::LAST_TX_MAX_AGE_S`, so this table never accumulates rows
	 * across restarts of the same fault.
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
	 * THE SAVEPOINT IS VERIFIED BEFORE ANY CALLBACK WRITE, NOT ONLY AT THE
	 * END (Ruling S21, Codex round-8 P1; repositioned by Ruling S25, Codex
	 * round-11 P1 — both on #88). Ruling S17's `RELEASE SAVEPOINT` catches a
	 * reconnect only at the CLOSE of the unit — but a reconnect landing
	 * ANYWHERE between `START TRANSACTION` and the last statement before
	 * `$writes()` lands `SAVEPOINT` (or the nonce `SET` right after it) on a
	 * fresh autocommit session, where either opens and instantly closes its
	 * own one-statement transaction and is discarded the moment that
	 * statement completes. Nothing about issuing either fails — so without
	 * a check HERE, `$writes()` and the version bump would both run
	 * un-transacted, autocommitting each statement individually, before the
	 * LATER `RELEASE` finally caught the problem — too late to stop either
	 * from having already landed outside any transaction. `ROLLBACK TO
	 * SAVEPOINT aura_door_tx`, issued immediately after the nonce `SET` —
	 * Ruling S21 originally placed it right after `SAVEPOINT`, BEFORE the
	 * `SET`, which left the `SET` itself as a reconnect-prone statement this
	 * check never covered; Ruling S25 moved it to run AFTER the `SET`
	 * instead, the true last reconnect-prone statement before `$writes()` —
	 * closes this: on the real session it is a genuine no-op (nothing has
	 * been written yet, and `ROLLBACK TO SAVEPOINT` — unlike `RELEASE
	 * SAVEPOINT` — does not remove the savepoint, so it stays valid for
	 * Ruling S17's own `RELEASE` later), but on a session where the
	 * savepoint never really took — whether the reconnect happened before
	 * `SAVEPOINT`, during it, or during the `SET` — MySQL answers error
	 * 1305 there instead. Caught before `$writes()` is ever called, so a
	 * failure here means NOTHING ran at all — safer than every other
	 * failure path, which must undo work already done — and the caller may
	 * retry exactly as if `$writes()` had never been invoked.
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
	 * @return array{ committed: bool|null, result?: mixed, observation?: int|null }
	 *         `committed` is `false` — a PROVEN negative — when the savepoint
	 *         could not be PROVEN open before $writes() ever ran (Ruling
	 *         S21), $writes() asked for a rollback, a mutation's version
	 *         bump then failed its own WRITE, the savepoint could not be
	 *         RELEASED at the close of the unit (Ruling S17), or the final
	 *         COMMIT's own durable witness was read back and PROVEN absent
	 *         (Ruling S32) — every one of those rolls the WHOLE unit back
	 *         (on a transactional engine only — see Ruling S13 above),
	 *         including every statement $writes() itself ran (nothing, for
	 *         the S21 case, which is what makes it safe to retry outright)
	 *         AND every cache entry it evicted and re-read before this
	 *         method decided to fail (Ruling S18).
	 *
	 *         `committed` is `null` — UNKNOWN, never the same fact as
	 *         `false` (Ruling S51, Codex round-20 P1 on #88) — when the
	 *         durable witness's own read could not be proven either way: the
	 *         COMMIT's own statement or the session-nonce read-back
	 *         (Ruling S16) already looked clean enough to reach that
	 *         fallback, but this method genuinely cannot tell whether the
	 *         mutation landed. The witness row is left UNTOUCHED in this
	 *         case — deleting it would erase the only evidence a later,
	 *         healthier read (the janitor, or a caller's own retry) could
	 *         still use. A caller MUST treat `null` as retryable, exactly
	 *         like `false` (never proceed as if it were `true`), but MUST
	 *         NOT report it as a definite refusal the way a proven `false`
	 *         may be (`claim()` answering `not_held()` — "this approval is
	 *         gone for good" — on what was actually an unproven maybe is
	 *         the shape of bug this ruling closes).
	 *
	 *         `result` is ABSENT whenever `committed` is not `true` (Ruling
	 *         S15): never read it without checking `committed === true`
	 *         first. `observation` is likewise absent then, and otherwise
	 *         the witness THIS call produced — null whenever it could not
	 *         be proven, which can happen even while `committed` is `true`
	 *         (Ruling S10): a reconnect between the COMMIT and the
	 *         read-back, or a non-transactional engine, or 32-bit PHP.
	 */

	/**
	 * Read `wpdb::$reconnect_retries` through a scope-bound closure
	 * (Ruling S56, Codex round-22 P1 on #88) rather than a plain property
	 * access — see `versioned()`'s own docblock at its call site for why
	 * a direct `$wpdb->reconnect_retries` touch cannot be trusted against
	 * every possible `$wpdb`. `Closure::bind()` reads the property from
	 * INSIDE the object's own class scope, which can always see a
	 * protected member REGARDLESS of whether that specific object's
	 * class happens to define matching `__get()`/`__set()` magic methods
	 * — the one thing a plain external property access depends on that
	 * this method does not.
	 *
	 * @param object $wpdb The live `$wpdb` (or a drop-in standing in for
	 *                      it).
	 * @return int|null Null when the property does not exist on this
	 *                   object AT ALL — the "guard unavailable" case
	 *                   `reconnect_guard_available()` reports.
	 */
	private static function reconnect_retries_get( $wpdb ) {
		if ( ! is_object( $wpdb ) || ! property_exists( $wpdb, 'reconnect_retries' ) ) {
			return null;
		}
		$reader = \Closure::bind(
			function () {
				return $this->reconnect_retries;
			},
			$wpdb,
			get_class( $wpdb )
		);
		return $reader();
	}

	/**
	 * The write half of `reconnect_retries_get()` — same reasoning, same
	 * guard.
	 *
	 * @param object $wpdb  The live `$wpdb`.
	 * @param int    $value The value to set.
	 * @return bool True when the write actually ran (the property
	 *              exists); false when there was nothing to write to.
	 */
	private static function reconnect_retries_set( $wpdb, $value ) {
		if ( ! is_object( $wpdb ) || ! property_exists( $wpdb, 'reconnect_retries' ) ) {
			return false;
		}
		$writer = \Closure::bind(
			function ( $n ) {
				$this->reconnect_retries = $n;
			},
			$wpdb,
			get_class( $wpdb )
		);
		$writer( $value );
		return true;
	}

	/**
	 * Whether Ruling S56's reconnect-PREVENTION guard can actually be
	 * applied to the LIVE `$wpdb` — false only for a `db.php` drop-in
	 * that REPLACES wpdb outright without declaring `reconnect_retries`
	 * at all. A SUBCLASS (HyperDB and the like — extending wpdb rather
	 * than replacing it) inherits the declared property, so
	 * `property_exists()` still finds it and the scope-bound closure in
	 * `reconnect_retries_get()`/`_set()` still reaches it exactly like
	 * it reaches stock core's own; this is false only for an object that
	 * is not a `wpdb` (sub)class at all. Checked fresh every call —
	 * `property_exists()` costs nothing, and a drop-in cannot change
	 * mid-request — never cached, so the fragment/audit always reports
	 * the CURRENT truth rather than a memo from an earlier attempt.
	 *
	 * `false` here is NOT merely informational (Ruling S65, Codex
	 * round-25 P1 on #88, overturning Ruling S56's own original
	 * "detection alone" design): `versioned()` FAILS CLOSED when this is
	 * false, refusing every write on this site — before `$writes()` ever
	 * runs — rather than proceeding on the post-$writes() session-nonce
	 * re-check alone, which only detects a reconnect AFTER a mutation
	 * may already have landed twice. `door_write_unsupported_reason()`
	 * is what `status_fragment()`/`governor_block()` report this
	 * through, so it is visible, never silent, and never confused with
	 * `observation_unsupported_reason()`'s own, unrelated READ-side
	 * reasons.
	 *
	 * @return bool
	 */
	public static function reconnect_guard_available() {
		global $wpdb;
		return is_object( $wpdb ) && property_exists( $wpdb, 'reconnect_retries' );
	}

	/**
	 * Why WRITES are unsupported on this site right now, if they are
	 * (Ruling S65, Codex round-25 P1 on #88) — the SAME shape
	 * `observation_unsupported_reason()` already established for READS,
	 * for the write side. `'reconnect_guard_unavailable'` is the one
	 * reason this reports today: `versioned()` fails EVERY write on this
	 * site closed (refusing before `$writes()` ever runs) for as long as
	 * `reconnect_guard_available()` cannot be applied to the live
	 * `$wpdb` — see that method's own docblock for exactly which `db.php`
	 * shape this is (a full replacement, never a subclass). Surfaced
	 * through `status_fragment()`/`governor_block()`'s own
	 * `door_write_unsupported` field so Aura's audit can name it, rather
	 * than silently retrying writes that will keep failing until the
	 * drop-in itself is fixed.
	 *
	 * @return string|null
	 */
	public static function door_write_unsupported_reason() {
		return self::reconnect_guard_available() ? null : 'reconnect_guard_unavailable';
	}

	/**
	 * Ruling S84 (Codex round-35 P1 on #88), generalising Ruling S54: EVERY
	 * raw `$wpdb->query()` a `versioned()` write unit issues — directly in
	 * its own `$writes()` callback, or in a helper that callback calls,
	 * exclusively FROM inside a `versioned()` unit — is routed through
	 * this ONE checkpoint immediately after the statement that produced
	 * its `$result`, before that result is used for anything else this
	 * same unit does.
	 *
	 * THE BUG THIS CLOSES (`ack_write()`'s own purge DELETE,
	 * class-aura-worker-door-log.php:3571 as this ruling names it): a
	 * `$wpdb->query()` failure — a deadlock aborting the WHOLE InnoDB
	 * transaction, not merely this one statement — returns `false`.
	 * `(int) false` is `0`, silently indistinguishable from a genuine,
	 * harmless "zero rows matched" outcome once cast. A callback that
	 * only checks the CAST value (`0 === $rows`) reads a deadlock exactly
	 * like an ordinary no-op and carries on: `ack_write()`'s own
	 * `count_unacked( $floor )` used the ALREADY-raised (and, on a
	 * deadlock, subsequently ROLLED BACK by MySQL itself) floor to decide
	 * the log was under capacity, and issued `delete_option( FULL_MARKER )`
	 * — a REAL, independent statement — on what is by then an aborted
	 * transaction's connection, back to autocommit. `versioned()` still
	 * correctly answers `committed: false` once it notices (the SAVEPOINT/
	 * nonce checks below already catch a deadlocked session), but by then
	 * the door has already been reopened for real, durably, and nothing in
	 * `committed: false` undoes it.
	 *
	 * NEVER APPLIED to a statement whose "failure" (0 rows, or `false` with
	 * a clean `last_error`) IS itself a meaningful business answer a
	 * callback already branches on correctly — `rotate_epoch_write()`'s own
	 * fence-miss checks (`1 !== (int) $gone`) are a good example: `false`
	 * casts to `0`, which is `!== 1` exactly like a genuine fence miss, and
	 * that branch does nothing destructive on either reading (it returns a
	 * fresh READ, never a further write). Calling this there would be
	 * harmless but redundant; it is not required for those statements to
	 * be safe. It IS required wherever a LATER statement or decision in the
	 * SAME callback trusts an earlier one's cast return without checking
	 * `$wpdb->last_error` too — which this file's own grep table (see the
	 * PR body's "Review rounds" section for Ruling S84) confirms is
	 * everywhere the actual bug lived.
	 *
	 * @throws Aura_Worker_Door_Write_Failed When `$result` is `false`, or
	 *         `$wpdb->last_error` is non-empty despite a non-`false`
	 *         `$result` (a partial/warning outcome a caller must not treat
	 *         as clean).
	 * @param mixed $result Whatever `$wpdb->query()` (or, in principle, its
	 *                       `insert()`/`update()`/`delete()`/`replace()`
	 *                       siblings — this codebase issues every write as
	 *                       a raw `query()`, never those, but the check is
	 *                       identical either way) just returned.
	 * @return mixed `$result`, UNCHANGED, when it passed — so a caller
	 *              chains this around the statement itself
	 *              (`$rows = (int) self::must_succeed( $wpdb->query( … ) );`)
	 *              with no restructuring of its own existing logic.
	 */
	public static function must_succeed( $result ) {
		global $wpdb;
		if ( false === $result || '' !== (string) $wpdb->last_error ) {
			throw new Aura_Worker_Door_Write_Failed( 'A door-log write statement failed inside a versioned() unit.' );
		}
		return $result;
	}

	public static function versioned( callable $writes ) {
		global $wpdb;
		$transactional = self::engine_is_transactional();
		if ( null === $transactional ) {
			// Ruling S47 (Codex round-19 P1 on #88): UNREADABLE is not the
			// same fact as "non-transactional" and must not silently take
			// the autocommit branch below — retryable, and nothing here
			// has written anything yet, so there is nothing to roll back.
			// Same shape every other retryable branch in this method
			// already answers (Ruling S15): `committed: false`, no
			// `result`.
			return array(
				'committed' => false,
			);
		}
		$tx_nonce = null;
		// Ruling S50 (Codex round-20 P1 on #88): no statement of this
		// unit — not $writes()'s own queries, not START TRANSACTION,
		// SAVEPOINT, the nonce SET, the bump, RELEASE or COMMIT — may
		// run on a RECONNECTED session. A dropped connection mid-
		// statement otherwise has wpdb::check_connection() transparently
		// reconnect and REPLAY that exact query on a fresh, autocommit
		// session — invisible to $writes(), which sees an ordinary
		// success, but the statement has now landed independently and
		// permanently, before this method's own SAVEPOINT/COMMIT
		// machinery (Rulings S16/S17/S21/S25) ever gets a say — exactly
		// the un-transacted-autocommit hazard Ruling S13 already names
		// for a non-transactional ENGINE, reopened here by a transient
		// CONNECTION drop on a genuinely transactional one. Setting
		// `reconnect_retries` to 0 for this unit's own duration makes
		// check_connection() attempt zero reconnects instead: the
		// dropped statement's own query() call simply returns false,
		// which every existing failure path below (the savepoint check,
		// the bump's own write, RELEASE, COMMIT) already treats as a
		// retryable `committed: false` — the SAME shape, not a new one.
		// Saved and restored on EVERY exit from here down, including a
		// rethrown exception (the `finally` below), so a caller
		// elsewhere in this same request is never left running with
		// reconnects disabled by a unit that already finished.
		//
		// Ruling S56 (Codex round-22 P1 on #88): `wpdb::$reconnect_retries`
		// is PROTECTED in WordPress core (verified against core
		// 7.0/7.0.4/7.1's own wp-includes/class-wpdb.php) — a plain
		// `$wpdb->reconnect_retries = 0` from THIS class's own scope is
		// not something every `$wpdb` can be trusted to tolerate. Stock
		// wpdb happens to define matching `__get()`/`__set()` magic
		// methods for backward compatibility that do not block this
		// specific property, so a direct assignment against genuine core
		// wpdb does not fatal in practice — but a CUSTOM `db.php`
		// drop-in that REPLACES wpdb outright (not a subclass — a
		// subclass inherits the magics) need not define either, and a
		// direct property touch there throws `Error: Cannot access
		// protected property`. `reconnect_retries_get()`/`_set()` read
		// and write through a scope-bound closure instead — the same
		// mechanism regardless of whether the object's own magic methods
		// happen to cover this property — and answer/report `null`/false
		// when the property does not exist on the object AT ALL, which
		// is the one case nothing here can safely paper over.
		$prior_reconnect_retries   = self::reconnect_retries_get( $wpdb );
		$reconnect_guard_available = ( null !== $prior_reconnect_retries );
		// Ruling S65 (Codex round-25 P1 on #88): FAIL CLOSED, not "proceed
		// on detection alone" (Ruling S56's own original design, now
		// overturned). Detection runs the post-$writes() session-nonce
		// re-check AFTER the statement already ran — but a replacement
		// $wpdb still capable of transparently reconnecting (it lacks
		// only the PROPERTY this class uses to turn that off, not the
		// reconnect behaviour itself) can autocommit the retried
		// statement on a fresh, un-transacted session the instant the
		// connection drops, exactly the S50 hazard, before the nonce
		// check ever gets a chance to notice — noticing AFTER a mutation
		// already landed a second time is not the same fact as
		// preventing it from landing twice at all. When the guard cannot
		// be applied, this unit refuses BEFORE $writes() is ever
		// invoked: nothing has run, so there is nothing to roll back,
		// and the caller's own existing retryable path (the same
		// `committed: false` shape every other early refusal here
		// already answers) is what it gets instead.
		//
		// ONLY A FULL `db.php` REPLACEMENT IS AFFECTED. `wpdb`'s own
		// PROTECTED `$reconnect_retries` (Ruling S56) is a DECLARED
		// property every SUBCLASS inherits — HyperDB and the like extend
		// `wpdb` rather than replacing it, so `property_exists()` still
		// finds it and the scope-bound closure in
		// `reconnect_retries_get()`/`_set()` still reaches it exactly
		// like it reaches stock core's own. This path is reached only by
		// an object that is not a `wpdb` (sub)class at all — a `db.php`
		// that reimplements the whole interface from scratch without
		// ever declaring this specific property.
		if ( ! $reconnect_guard_available ) {
			return array(
				'committed' => false,
			);
		}
		self::reconnect_retries_set( $wpdb, 0 );
		try {
			if ( $transactional ) {
				$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				// Ruling S17 (Codex round-7 P1 on #88): a REAL transactional
				// savepoint, before anything else — see this method's own
				// docblock for why the nonce below is not enough on its own.
				$wpdb->query( 'SAVEPOINT aura_door_tx' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				// Ruling S16 (Codex round-6 P1 on #88): the proof the final
				// COMMIT below checks before this method ever reports
				// `committed: true`. See that COMMIT's own comment for what it
				// proves and why.
				$tx_nonce = wp_generate_uuid4();
				$wpdb->query( $wpdb->prepare( 'SET @aura_door_tx = %s', $tx_nonce ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				// Ruling S25 (Codex round-11 P1 on #88): VERIFY the savepoint
				// AFTER this SET, not before it (Ruling S21's own original
				// position) — the SET is ITSELF the last reconnect-prone
				// statement before `$writes()` runs. A reconnect landing WHILE
				// this exact SET is being issued lets `wpdb` transparently
				// retry it on a fresh AUTOCOMMIT session — one that never held
				// the savepoint above — and the retried SET still assigns the
				// nonce there, so a check placed only BEFORE the SET (as Ruling
				// S21 had it) never sees this window at all: the callback
				// writes and the bump would then run un-transacted, each
				// autocommitting individually on a real server, before the
				// LATER `RELEASE SAVEPOINT` (Ruling S17) finally caught the
				// problem — too late to stop any of it from having already
				// landed. `ROLLBACK TO SAVEPOINT`, run immediately after the
				// SET and before `$writes()`, closes this the same way Ruling
				// S21 closed the window before it: a genuine no-op on the real
				// session (nothing written yet, and the savepoint survives for
				// Ruling S17's own RELEASE later), but MySQL error 1305 on a
				// session where the savepoint never really took — whether the
				// reconnect landed before SAVEPOINT, during it, or during this
				// SET makes no difference to this ONE check, which is why a
				// single verification positioned AFTER the last such statement
				// replaces Ruling S21's earlier, narrower one.
				$wpdb->query( 'ROLLBACK TO SAVEPOINT aura_door_tx' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				if ( '' !== (string) $wpdb->last_error ) {
					$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					return array(
						'committed' => false,
					);
				}
			}
			try {
				$outcome = $writes();
			} catch ( Aura_Worker_Door_Write_Failed $e ) {
				// Ruling S84 (Codex round-35 P1 on #88): must_succeed()'s
				// own signal — a statement inside THIS unit failed and
				// there is no honest business meaning to give that,
				// only "abort". Converted to the SAME `{ rollback: true }`
				// shape a $writes() callback already signals by
				// RETURNING it (Ruling S12) — the `if ( ! empty(
				// $outcome['rollback'] ) )` branch just below this try/
				// catch is what actually issues ROLLBACK and answers
				// `committed: false`; this catch's only job is to reach
				// it rather than let the exception fall through to the
				// generic handler below, which re-throws instead.
				$outcome = array( 'rollback' => true );
			} catch ( \Throwable $e ) {
				if ( $transactional ) {
					$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				}
				throw $e;
			}
			$outcome = is_array( $outcome ) ? $outcome : array();
			// Ruling S18 (Codex round-7 P1 on #88): the evict list $writes()
			// itself declared — extracted here, before EITHER the new S50
			// nonce check below or the `rollback` branch just after it, so
			// both can repeat it on their own rollback path exactly like
			// every other failure branch in this method already does.
			$evict = isset( $outcome['evict'] ) && is_array( $outcome['evict'] ) ? $outcome['evict'] : array();
			if ( $transactional ) {
				// Ruling S50, belt-and-braces: `reconnect_retries = 0` above
				// is what actually PREVENTS a reconnect from replaying one
				// of $writes()'s own statements on a fresh session — this
				// is the independent PROOF that none slipped through
				// anyway. The session nonce Ruling S16 set right after
				// SAVEPOINT does not survive a reconnect; reading it back
				// HERE, immediately after $writes() returns and BEFORE the
				// version bump, catches a reconnected session at the
				// earliest possible point — before the bump's own write
				// can land on that same compromised session — rather than
				// only much later, at COMMIT, by which time $writes() may
				// already have run twice.
				$post_writes_nonce = $wpdb->get_var( 'SELECT @aura_door_tx' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				if ( ! is_string( $post_writes_nonce ) || $post_writes_nonce !== $tx_nonce ) {
					$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					self::evict_after_rollback( $evict ); // Ruling S18
					return array(
						'committed' => false,
					);
				}
			}
			if ( ! empty( $outcome['rollback'] ) ) {
				// Ruling S12: $writes() itself demands the unit fail.
				if ( $transactional ) {
					$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					return array(
						'committed' => false,
						'result'    => array_key_exists( 'result', $outcome ) ? $outcome['result'] : null,
					);
				}
				// Ruling S85 (Codex round-36 P2 on #88) OVERTURNS Ruling
				// S13's own answer here. S13 reasoned "nothing CAN be
				// rolled back, so the most honest answer is that the unit
				// committed" — true as far as it goes, but "committed"
				// implies a CLEAN, TRUSTED mutation, and a demanded
				// rollback (Ruling S12) is proof of the opposite: an
				// EARLIER statement in this SAME callback — ack_write()'s
				// own floor-raise UPDATE, ahead of its purge DELETE, is
				// the concrete case this ruling names — may already have
				// autocommitted before the statement that triggered this
				// demand ever ran, since there is no transaction here to
				// have grouped them. `committed: true` paired with NO
				// `result` (every $writes() callback that ever demands
				// rollback either omits `result` entirely or sets it to a
				// pre-failure placeholder never meant to be served) is
				// what let `ack()`/`rotate_epoch()` — both of which gate
				// their OWN `return $outcome['result'];` behind `true ===
				// $outcome['committed']`, exactly as Ruling S15 requires —
				// serve `null` as if it were their normal success shape,
				// which their own REST handlers then index
				// (`ack_door_log()`'s `array_key_exists( 'committed', $result )`
				// on a `null` `$result` is a TypeError, not a warning).
				//
				// UNKNOWN, not proven either way, is the honest fact
				// (Ruling S13's own "no witness is possible on this
				// engine" already applies here too — nothing can tell a
				// partial autocommit apart from a no-op that never
				// touched anything). `committed: null`, with NO `result`
				// key, is the EXACT shape Ruling S51 already established
				// for the durable-witness-unreadable case a few lines
				// below this one — every consumer already audited for
				// THIS finding (ack, rotate_epoch, insert_unique's own
				// hold/close/epoch-mint callers, write_option_where,
				// delete_versioned, release, rotate_binding,
				// sync_computed_state) already treats `committed !== true`
				// as "do not touch `result`", so reusing that SAME shape
				// here needs no change to any of them — only to this one
				// branch that was lying about which shape it was.
				return array(
					'committed' => null,
				);
			}
			$mutated = ! empty( $outcome['mutated'] );
			$result  = array_key_exists( 'result', $outcome ) ? $outcome['result'] : null;
			// $evict was already extracted above (Ruling S50), before the
			// nonce re-check that needed it too.
			if ( ! $mutated ) {
				if ( $transactional ) {
					$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				}
				return array(
					'committed' => true,
					'result'    => $result,
				);
			}
			if ( $transactional ) {
				// Ruling S30/S32 (Codex rounds 13/14 P1 on #88): a DURABLE
				// commit witness, PER TRANSACTION, written INSIDE this
				// transaction, BEFORE the version bump — see the post-COMMIT
				// check below for the reconnect window this closes that the
				// session-variable nonce (Ruling S16) cannot, and this
				// method's own docblock for why the row is named BY this
				// unit's own nonce rather than shared with every other unit.
				// `option_value` is never compared — the row's mere EXISTENCE
				// under this exact name is the proof — so it carries the
				// write's own unix timestamp, which is all the later janitor
				// sweep needs to judge a leaked row's age.
				//
				// GATES THE UNIT (Ruling S54, Codex round-21 P2 on #88). This
				// INSERT's own return and last_error used to be ignored —
				// and bump_door_version_write(), the very next statement,
				// clears $wpdb->last_error at its own first line, erasing
				// any trace of THIS statement having failed. A unit could
				// therefore COMMIT for real (the state write and the bump
				// both landing) without its own witness ever existing — and
				// if that same commit's ack was ALSO separately lost, the
				// post-COMMIT fallback (Ruling S30 below) would read for a
				// witness that was never written, find nothing, and report
				// `committed: false` for a mutation that, in fact, had
				// already landed: a PROVEN negative for what was actually a
				// true positive, the mirror image of the false positive
				// Ruling S51 closed on the read side. Checked and gated
				// HERE, before the bump ever runs, exactly like the
				// SAVEPOINT check above: a witness this unit cannot prove
				// it wrote is a unit that must not commit at all.
				$witness_insert_ok = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
						"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
						self::LAST_TX_PREFIX . $tx_nonce,
						(string) time()
					)
				);
				if ( false === $witness_insert_ok || '' !== (string) $wpdb->last_error ) {
					$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					self::evict_after_rollback( $evict ); // Ruling S18
					return array(
						'committed' => false,
					);
				}
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
				$commit_return     = $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery — Ruling S10: COMMIT before the read-back
				$commit_last_error = (string) $wpdb->last_error;
				// Ruling S40 (Codex round-17 P1 on #88): capture COMMIT's OWN
				// return and last_error IMMEDIATELY — before any other
				// statement runs, including the session-nonce and
				// durable-witness reads below, either of which would reset
				// $wpdb->last_error before this COMMIT's own outcome could
				// ever be consulted. Rulings S16/S30/S32/S34 decided
				// `committed` from a SEPARATE `SELECT @aura_door_tx`
				// read-back ALONE: a matching session nonce proved the
				// connection never dropped, and that alone reported
				// committed:true — even across a COMMIT that had already
				// reported failure on a connection that never moved. A
				// matching nonce proves session CONTINUITY; it does not prove
				// COMMIT SUCCESS, and the two are not the same fact — this
				// gate now runs FIRST, and only a COMMIT that already looks
				// clean gets to the nonce check at all.
				$tx_witness = self::LAST_TX_PREFIX . $tx_nonce;
				$commit_ok  = ( false !== $commit_return && '' === $commit_last_error );
				if ( $commit_ok ) {
					// Ruling S16 (Codex round-6 P1 on #88), preserved: a CLEAN
					// return/last_error only proves the STATEMENT succeeded —
					// not WHICH session it ran on. A reconnect landing exactly
					// during this COMMIT call lands on a fresh, autocommit
					// session with no transaction open, so the COMMIT it
					// silently retries there is a harmless no-op that ALSO
					// returns true with no error — passing the check above
					// while the original session's transaction was rolled
					// back the instant the connection dropped. Session (user)
					// variables do not survive that reconnect, so reading the
					// nonce back and comparing tells the two apart.
					$session_nonce = $wpdb->get_var( 'SELECT @aura_door_tx' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$commit_ok     = ( is_string( $session_nonce ) && $session_nonce === $tx_nonce );
				}
				// Ruling S51 (Codex round-20 P1 on #88): TRI-STATE from here —
				// `$commit_tristate` is `true`/`false` exactly like the
				// boolean `$commit_ok` above whenever the nonce check already
				// settled it, but the DURABLE-WITNESS fallback below can also
				// answer a THIRD state: `null`, unknown, when the read of the
				// witness itself could not be proven. `is_string( $durable )`
				// used to answer `false` for EITHER "genuinely absent" (this
				// commit did not land) OR "the SELECT failed" (this method
				// has no idea) — collapsing "definitely did not happen" and
				// "cannot tell" into the SAME `committed: false` a caller
				// reads as a proven negative. `claim()` is the sharpest
				// example: reading THAT `false` as "already claimed by
				// someone else" told Aura a still-open approval was gone for
				// good, when the truth was this site could not prove whether
				// its own claim attempt landed.
				$commit_tristate = $commit_ok;
				if ( ! $commit_ok ) {
					// Ruling S40: an EXPLICIT ROLLBACK, best effort, BEFORE the
					// durable witness is ever read. Without it, a COMMIT that
					// failed on a connection that never dropped can leave the
					// transaction still OPEN on this very session — and a bare
					// SELECT on that same, still-open connection reads back its
					// OWN uncommitted witness row (ordinary read-your-own-writes
					// visibility within an open transaction), reporting a row
					// that was never made durable as if it already were. A
					// no-op on a session whose COMMIT genuinely landed (nothing
					// is left open to roll back); ends the transaction after one
					// that did not, so this session can no longer see anything
					// it never durably wrote.
					$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					// Ruling S53 (Codex round-21 P1 on #88): RECONNECTS
					// RE-ENABLED, here, before the witness read — never
					// earlier. Ruling S50 zeroed `reconnect_retries` for
					// every statement $writes() itself could still issue,
					// because a reconnect THERE would replay a write on a
					// fresh, un-transacted session. Nothing below this
					// line writes anything: $writes() already ran, and the
					// transaction it ran inside has already committed or
					// (via the ROLLBACK just above) been undone. A
					// connection lost exactly on the way back from a real
					// COMMIT — the window this durable witness exists to
					// resolve — otherwise left `reconnect_retries` at 0
					// from S50 still in force, so THIS read could not
					// reconnect either, and answered unreadable for a
					// commit that had, in fact, already landed. Restoring
					// before the read, not only in the method's own
					// `finally`, is what lets it actually find the
					// witness a healthy reconnect would reveal.
					if ( $reconnect_guard_available ) {
						self::reconnect_retries_set( $wpdb, $prior_reconnect_retries );
					}
					// Ruling S30/S32 (Codex rounds 13/14 P1 on #88): the DURABLE
					// witness, written BEFORE the bump inside this same
					// transaction — a plain option row survives what neither a
					// session variable nor an open-but-doomed transaction's own
					// local view can be trusted for. Named BY this unit's own
					// nonce (S32), so its mere EXISTENCE — never a value
					// comparison — is the whole proof; a row a DIFFERENT,
					// unrelated unit wrote can never share this exact name.
					// Read RAW, and only now — AFTER the ROLLBACK above closed
					// this session's own open transaction, if it had one — so
					// this SELECT can only ever see what is genuinely durable.
					//
					// PROVEN, not a plain get_var() (Ruling S51): the earlier
					// `is_string( $wpdb->get_var(...) )` answered `false` for
					// BOTH a proven-absent row and a driver failure that
					// proved NOTHING — `raw_option_read()`'s nonce-probed
					// idiom is what tells the two apart, exactly as it
					// already does for every other option this class reads
					// raw.
					$witness_read = self::raw_option_read( $tx_witness );
					if ( ! $witness_read['ok'] ) {
						// UNREADABLE: this method cannot tell whether the
						// commit landed. Never `false` — a proven negative
						// this is not.
						$commit_tristate = null;
					} else {
						$commit_tristate = is_string( $witness_read['value'] );
					}
				}
				// Ruling S32: both branches above are now settled — clean up
				// THIS unit's own witness row, best effort, OUTSIDE the
				// transaction (which has already committed or rolled back by
				// now, so this DELETE is its own separate, auto-committed
				// statement). Only reached once a real COMMIT has actually
				// been issued: the SAVEPOINT-release and bump-write failure
				// branches above return before ever getting here, and in both
				// of those the witness row this unit wrote was rolled back
				// along with everything else, so there is nothing of THIS
				// unit's own to delete.
				//
				// NEVER ON UNKNOWN (Ruling S51). Deleting a witness this
				// method could not prove existed or not would erase the
				// ONLY evidence a later read (the janitor, or Aura's own
				// retry landing on a session that CAN read it) could still
				// use to tell a real commit from a real rollback — turning
				// a transient read failure into a permanent, unrecoverable
				// "nobody will ever know" for this one unit's outcome.
				if ( null !== $commit_tristate ) {
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $tx_witness ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				}
				// A BOUNDED JANITOR for whatever a DIED process left behind — a
				// disconnect landing between ITS OWN COMMIT and ITS OWN delete
				// above is the only way a witness row outlives the unit that
				// wrote it. Runs on every mutating unit, so the bound
				// (`self::LAST_TX_JANITOR_LIMIT` rows, older than
				// `self::LAST_TX_MAX_AGE_S`) matters: this table must never be
				// left to accumulate across restarts of the same fault, but
				// nor may this sweep itself become a full-table scan on every
				// single door mutation.
				$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
						"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d LIMIT %d",
						$wpdb->esc_like( self::LAST_TX_PREFIX ) . '%',
						time() - self::LAST_TX_MAX_AGE_S,
						self::LAST_TX_JANITOR_LIMIT
					)
				);
				if ( true !== $commit_tristate ) {
					// Ruling S15: no callback result — see the bump-failure
					// branch above for why. Neither the session variable nor
					// the durable witness could prove this COMMIT landed on a
					// session that ever held this transaction; nothing here is
					// trusted to have happened.
					//
					// `$commit_tristate` carries through UNCHANGED here
					// (Ruling S51): `false` when the witness read PROVED this
					// commit did not land, `null` when that read itself could
					// not be proven either way — the caller must not read
					// `null` as the same fact `false` is. Every eviction the
					// callback performed is still repeated (Ruling S18)
					// regardless of which of the two this is: a rollback (or
					// an unproven commit) means nothing here may be trusted
					// to have landed, so a stale, uncommitted value must not
					// be left in any cache either way.
					self::evict_after_rollback( $evict ); // Ruling S18
					return array(
						'committed' => $commit_tristate,
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
		} finally {
			if ( $reconnect_guard_available ) {
				self::reconnect_retries_set( $wpdb, $prior_reconnect_retries );
			}
		}
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
		$transactional = self::engine_is_transactional();
		if ( false === $transactional ) {
			return 'engine';
		}
		if ( null === $transactional ) {
			// Ruling S47: an unreadable probe is a TRANSIENT miss, not the
			// permanent "upgrade the host" fact this field exists to name
			// — see this method's own docblock above.
			return null;
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
	 * The SAME report, RAW — never this request's own object cache (Ruling
	 * S31, Codex round-14 P1 on #88). See `floor_raw()`'s own docblock for
	 * the reasoning; used by the fragment builder in place of
	 * `full_report()`.
	 *
	 * UNREADABLE IS NOT "NOT CLOSED", AND IS NOT A FALSE ZERO (Ruling S42,
	 * Codex round-17 P2 on #88). Three separate raw reads feed this
	 * report — is_closed_raw() itself, then (once closed) the marker and
	 * the refusal counter — and each used to fold a failure into a value
	 * that looks exactly like a real one: an unreadable marker made a
	 * CLOSED log report as though it were open (null, the same answer a
	 * genuinely open door gives), and an unreadable since/refused reported
	 * '' / 0 under whatever observation the poll still claimed. Each
	 * failure now propagates instead — full_report_raw_was_unreadable(),
	 * checked by the caller immediately afterwards — and the two fields
	 * are reported independently: null for whichever one could not be
	 * proven, the real value for the other.
	 *
	 * @return array{ since: string|null, refused: int|null }|null
	 */
	public static function full_report_raw() {
		self::$full_report_unreadable = false;
		$closed                       = self::is_closed_raw();
		if ( self::closure_read_was_unreadable() ) {
			self::$full_report_unreadable = true;
			return null; // unknown whether the log is even closed — never reported as "open"
		}
		if ( ! $closed ) {
			return null; // genuinely not closed
		}
		$since            = self::raw_option( self::FULL_MARKER );
		$since_unreadable = self::raw_option_was_unreadable();
		$refused            = self::raw_option( self::FULL_COUNTER );
		$refused_unreadable = self::raw_option_was_unreadable();
		if ( $since_unreadable || $refused_unreadable ) {
			self::$full_report_unreadable = true;
		}
		return array(
			'since'   => $since_unreadable ? null : (string) ( $since ?? '' ),
			'refused' => $refused_unreadable ? null : (int) ( $refused ?? 0 ),
		);
	}

	/**
	 * Whether the MOST RECENT `full_report_raw()` call could not prove
	 * some part of its read (Ruling S42, Codex round-17 P2 on #88). The
	 * caller must read this immediately after — before anything else in
	 * this same request can call `full_report_raw()` again and overwrite
	 * it.
	 *
	 * @return bool
	 */
	public static function full_report_raw_was_unreadable() {
		return self::$full_report_unreadable;
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
		if ( true !== $outcome['committed'] ) {
			// Ruling S15 (Codex round-6 P2 on #88): a rolled-back unit
			// carries no `result` — `ack_write()`'s own `acked`/`floor`
			// were computed BEFORE the bump's write failed (or the COMMIT
			// could not be proven, Ruling S16) and undone with everything
			// else. Reading `self::floor()` now answers the floor as the
			// rollback actually left it — NOT advanced — never the value
			// this ack thought it had raised.
			//
			// `committed` carries the tri-state THROUGH, unchanged (Ruling
			// S51, Codex round-20 P1 on #88): `false` when the durable
			// witness PROVED this ack did not land, `null` when even that
			// could not be proven. `ack_door_log()` (class-aura-worker-api.php)
			// already answers a retryable 503 for either — `!$result['committed']`
			// is `true` for both `false` and `null` — so this is a
			// pass-through for honesty, not a behaviour change.
			return array(
				'acked'     => 0,
				'floor'     => self::floor(),
				'committed' => $outcome['committed'],
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
		// Ruling S68 (Codex round-25 P1 on #88 — the S31 class applied to
		// the WRITE side): EVERY floor read in this unit is now RAW, never
		// `self::floor()` — WordPress's default object cache is per-request,
		// so a DIFFERENT request's own ack_write() evicting ITS cache after
		// raising the floor never reaches THIS request's already-cached
		// copy. That gap is not academic: it is the exact mechanism the
		// S67 regression test poisons to make THIS SAME METHOD skip its own
		// purge below (#88 round-25) — a stale floor here does not merely
		// risk a reporting glitch, it decides how much of the log this call
		// destructively deletes. An unreadable raw read aborts the WHOLE
		// unit (Ruling S12's `rollback`) rather than proceed on a floor
		// nothing here can prove — at THIS point nothing has been written
		// yet, so the abort is free.
		self::reset_floor_unreadable_for_attempt();
		$prev_floor_before_raise = self::floor_raw();
		if ( self::floor_was_unreadable_this_attempt() ) {
			return array( 'rollback' => true );
		}
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
		// Ruling S84 (Codex round-35 P1 on #88): must_succeed() checks
		// BOTH the return value AND $wpdb->last_error before the (int)
		// cast below ever runs — a deadlock aborting the whole
		// transaction here must never be read as "zero rows, ordinary
		// idempotent repeat" by the branch just below.
		$raised = (int) self::must_succeed(
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"UPDATE {$wpdb->options} f JOIN ( SELECT option_value AS e FROM {$wpdb->options} WHERE option_name = %s ) x SET f.option_value = %s WHERE f.option_name = %s AND x.e = %s AND CAST(f.option_value AS UNSIGNED) < %d",
					self::EPOCH,
					(string) $seq,
					self::FLOOR,
					(string) $epoch,
					$seq
				)
			)
		);
		wp_cache_delete( self::FLOOR, 'options' );
		if ( $raised < 1 && (string) $epoch !== self::epoch_raw() ) {
			// Zero rows AND the epoch has moved: this ack crossed a rotation
			// and owns nothing here. Zero rows with the epoch UNCHANGED is the
			// ordinary idempotent repeat — the floor is already at or above
			// this cursor — and still falls through to the delete, which is how
			// a previous ack's unfinished purge is completed.
			self::reset_floor_unreadable_for_attempt();
			$floor_at_bailout = self::floor_raw();
			if ( self::floor_was_unreadable_this_attempt() ) {
				return array( 'rollback' => true );
			}
			return array(
				'mutated' => false,
				'result'  => array(
					'acked' => 0,
					'floor' => $floor_at_bailout,
				),
				'evict'   => $evict,
			);
		}
		self::reset_floor_unreadable_for_attempt();
		$floor = self::floor_raw();
		if ( self::floor_was_unreadable_this_attempt() ) {
			return array( 'rollback' => true );
		}
		$acked = 0;
		if ( $floor > 0 ) {
			$prev_floor = $prev_floor_before_raise; // read BEFORE the raise, below
			$like  = $wpdb->esc_like( self::PREFIX ) . '%';
			// Joined to the epoch row as well (Ruling P90): the purge is the
			// destructive half, and it must not run under an epoch this ack
			// was never for — including one installed between the raise above
			// and this statement.
			// Ruling S84 (Codex round-35 P1 on #88): THE named bug —
			// this DELETE's own `false` used to cast straight to `0` and
			// let count_unacked() below decide the log was under
			// capacity using a floor this same deadlock had already
			// rolled back, reopening the door on an autocommit session
			// versioned()'s own later commit-witness check cannot undo.
			$acked = (int) self::must_succeed(
				$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
						"DELETE f FROM {$wpdb->options} f JOIN ( SELECT option_value AS e FROM {$wpdb->options} WHERE option_name = %s ) x WHERE f.option_name LIKE %s AND f.option_name REGEXP %s AND x.e = %s AND CAST(SUBSTRING(f.option_name, %d) AS UNSIGNED) <= %d",
						self::EPOCH,
						$like,
						self::ROW_REGEXP,
						(string) $epoch,
						strlen( self::PREFIX ) + 1,
						$floor
					)
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
		//
		// Ruling S68: `count_unacked()` now takes the PROVEN `$floor` this
		// unit already read above — never a second, independent
		// `self::floor()` call (count_unacked()'s own get_option()-cached
		// default) that could disagree with it. `is_closed()` is likewise
		// replaced with `is_closed_raw()`: a cached FULL_MARKER read deciding
		// whether to reopen the log is the SAME class of bug as the floor
		// reads above, just on the other option this method's reopen
		// decision reads.
		$closed = self::is_closed_raw();
		if ( self::closure_read_was_unreadable() ) {
			return array( 'rollback' => true );
		}
		$unacked  = self::count_unacked( $floor );
		$reopened = false;
		if ( $closed && null !== $unacked && $unacked < self::MAX_UNACKED ) {
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
	 * EVERY READ HERE IS RAW (Ruling S31, Codex round-14 P1 on #88) — this
	 * is `status_fragment()`'s own reader, called ONCE per poll from inside
	 * its version bracket, and its ONLY production caller. `floor()`/`get()`
	 * go through `get_option()`, which — on the default non-persistent
	 * object cache — can answer whatever THIS request already cached on an
	 * EARLIER attempt (a `pending` row, a since-superseded floor) even after
	 * a DIFFERENT request has settled that row and bumped the door version:
	 * the bracket's own before/after reads would then agree on the NEW
	 * version while this method served the STALE row underneath it, with no
	 * torn read to catch the mismatch — the version comparison only proves
	 * the VERSION didn't change mid-build, never that everything read to
	 * build the fragment was fresh. `floor_raw()`/`get_raw()` bypass that
	 * cache the same way `raw_option_read()` (Ruling S1) always has;
	 * `highest_row_seq()` is unaffected — it was never routed through
	 * `get_option()` to begin with.
	 *
	 * Ruling S36 (Codex round-15 P1 on #88): a row `get_raw()` could not
	 * PROVE readable stops the walk exactly like a hole does — it cannot be
	 * skipped past, the same as any other hole — but it is not one, and
	 * `self::$log_walk_unreadable` records the difference for
	 * `status_fragment()` to read immediately afterwards
	 * (`log_walk_was_unreadable()`): the rows collected so far are still
	 * served (a transient failure must not turn into an emptier page than
	 * a 2.16.1 site — one with no raw reads at all — would have served),
	 * but the poll's `observation` is forced null rather than vouching for
	 * a log this call knows it could not finish reading.
	 *
	 * @param int $after Aura's cursor.
	 * @param int $limit Page size.
	 * @return array[]
	 */
	public static function log_after( $after, $limit = self::PAGE ) {
		self::$log_walk_unreadable = false;
		$after = max( (int) $after, self::floor_raw() );
		$out   = array();
		$seq   = $after;
		// An unreadable top does not bound the walk (Ruling P77) — the hole
		// check below and $limit already do, and stopping at a top of 0 would
		// serve an empty page for a log that is simply unreadable at the top.
		$top       = self::highest_row_seq();
		$unbounded = ( null === $top );
		while ( count( $out ) < $limit && ( $unbounded || $seq < $top ) ) {
			$seq++;
			$row = self::get_raw( $seq );
			if ( false === $row ) {
				// A transient SELECT failure, PROVEN unreadable rather than
				// proven absent (Ruling S36) — never treated as the hole
				// below, which would silently truncate the log under a
				// witness that still claimed to be current.
				self::$log_walk_unreadable = true;
				break;
			}
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
	 * Whether the MOST RECENT `log_after()` call stopped on a row it could
	 * not prove readable, rather than a genuine hole (Ruling S36, Codex
	 * round-15 P1 on #88). `status_fragment()`'s own caller reads this
	 * immediately after `build_status_fragment_state()` returns — before
	 * anything else in this same request can call `log_after()` again and
	 * overwrite it.
	 *
	 * @return bool
	 */
	public static function log_walk_was_unreadable() {
		return self::$log_walk_unreadable;
	}

	/**
	 * `rotate_epoch()`'s own rotation TARGET, DERIVED rather than randomly
	 * minted (Ruling S78, Codex round-32 P1 on #88) — a version-5 UUID
	 * (RFC 4122), so it stays a valid epoch string, over
	 * `self::ROTATE_TARGET_NAMESPACE` and `$name`.
	 *
	 * THE BUG THIS CLOSES: `wp_generate_uuid4()` (Ruling S62's own choice)
	 * mints a FRESH, unrepeatable value every call — so a retry of the
	 * SAME logical rotation (the caller naming the SAME `$expected`, after
	 * an ambiguous commit whose OWN verifying re-read ALSO failed, Ruling
	 * S77) minted a DIFFERENT target than the first attempt did. The
	 * retry's own fenced delete then found the epoch already replaced —
	 * by ITS OWN prior attempt — and had no way to tell that apart from a
	 * genuinely UNRELATED racer's rotation: both look identical, a fence
	 * miss against a target that is not this call's own. `restamp_binding_epoch()`
	 * never ran, and the binding record was left naming an epoch the site
	 * had already left (Ruling P91's own "half-done rebind").
	 *
	 * A DETERMINISTIC target closes it: the SAME `$expected` (and the SAME
	 * binding generation) always derives the SAME target, so a retry
	 * recomputes exactly what its own prior attempt already minted and
	 * recognises `epoch_raw() === $target` as ITS OWN landed rotation
	 * (`rotate_epoch_write()`'s own fence-miss branches, below) — no
	 * different from Ruling S62's own idempotent-completion check, just
	 * reachable on a SECOND call instead of needing the first call's own
	 * verify to succeed. A LATER, genuinely different rotation starts from
	 * a NEW `$expected` (the epoch this call is now asked to replace is
	 * not the one the earlier rotation replaced), so it derives a
	 * DIFFERENT target — nothing collides across two real, sequential
	 * rotations. A genuinely UNRELATED racer's own epoch — an arbitrary
	 * value, or a DIFFERENT caller's own derived target for a DIFFERENT
	 * `$expected` — is vanishingly unlikely to equal THIS derivation
	 * (a full SHA-1 space), so the two long-standing tests this ruling
	 * does not touch (a racer's own winning rotation, a caller naming an
	 * epoch that was never real) still answer `rotated: false`.
	 *
	 * @param string $namespace A fixed UUID (`self::ROTATE_TARGET_NAMESPACE`).
	 * @param string $name      The value this target is derived FROM —
	 *                          `$expected . '|' . $binding_generation`.
	 * @return string A version-5 UUID string.
	 */
	private static function derive_rotation_target( $namespace, $name ) {
		$nhex = str_replace( array( '-', '{', '}' ), '', $namespace );
		$nbytes = '';
		for ( $i = 0; $i < 32; $i += 2 ) {
			$nbytes .= chr( hexdec( substr( $nhex, $i, 2 ) ) );
		}
		$hash = sha1( $nbytes . $name );
		return sprintf(
			'%08s-%04s-%04x-%02x%02s-%012s',
			substr( $hash, 0, 8 ),
			substr( $hash, 8, 4 ),
			// Version 5, in the high nibble of the time-hi field.
			( hexdec( substr( $hash, 12, 4 ) ) & 0x0fff ) | 0x5000,
			// Variant 1 (RFC 4122), in the top bits of the clock-seq-hi octet.
			( hexdec( substr( $hash, 16, 2 ) ) & 0x3f ) | 0x80,
			substr( $hash, 18, 2 ),
			substr( $hash, 20, 12 )
		);
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
	 * @return array{ rotated: bool|null, epoch: string|null } `epoch` is
	 *         the one now in force either way — except the Ruling S77
	 *         (Codex round-31 P2 on #88) case: `rotated: null` when even
	 *         the verifying re-read could not be proven, genuinely
	 *         UNKNOWN rather than a guessed `false`, paired with
	 *         `epoch: null` rather than the raw reader's own `''`
	 *         unreadable sentinel. The caller answers its own retryable
	 *         503 (`may_have_run`) for that case, never `rotated: false`.
	 */
	public static function rotate_epoch( $expected, $claim = '', $fence = '' ) {
		// PRIMED BEFORE THE TRANSACTION OPENS (Ruling S8): the epoch must
		// already exist by the time rotate_epoch_write() runs, because that
		// method reads it back with epoch_raw() — never the MINTING epoch(),
		// which would nest a second transaction (see rotate_epoch_write()'s
		// own docblock). Idempotent either way: an epoch that already exists
		// is untouched, exactly like every other caller of epoch().
		self::epoch();
		// Ruling S62 (Codex round-23 P2 on #88): computed HERE, before
		// versioned() ever runs — the ONE fixed TARGET this call's own
		// attempt means to reach, threaded into rotate_epoch_write() so an
		// AMBIGUOUS commit can be completed idempotently below by asking
		// "did MY OWN mint land", never "did the epoch merely change" (a
		// concurrent, unrelated rotation winning the SAME race must still
		// answer `rotated: false` here — see the two long-standing tests
		// this ruling does not touch: a racer's own epoch winning the
		// fence, and a caller naming an epoch that was never real to begin
		// with, both still report `rotated: false`, exactly as before).
		//
		// Ruling S78 (Codex round-32 P1 on #88): DERIVED, never a fresh
		// random `wp_generate_uuid4()` mint — see `derive_rotation_target()`'s
		// own docblock for the retry-after-ambiguous bug a random target
		// could never let a second call recognise as its own. Keyed on
		// `$expected` (which real, sequential rotation this is) and the
		// binding generation (so a rebind installing a brand-new binding
		// mid-sequence still derives a fresh target, never reusing one a
		// PREVIOUS binding's own rotation already reached).
		//
		// Ruling S81 (Codex round-33 P1 on #88): `binding_raw()` answers
		// '' for BOTH a genuinely unbound site and an UNREADABLE read
		// (the same sentinel `epoch_raw()`/every other raw_option()-backed
		// read already shares — Ruling S57's own lesson, one door down).
		// Feeding that sentinel into the derivation unseen mints a target
		// from the WRONG generation whenever this read fails: a retry,
		// landing after the read recovers, derives a DIFFERENT target
		// against the REAL generation and can never recognise the first
		// attempt's own (possibly landed) write as its own — the exact
		// S78 bug, reopened by an unproven input instead of a random
		// mint. Checked IMMEDIATELY after the read it describes (Ruling
		// S57/S58's own discipline): an unreadable binding here is
		// retryable ambiguity, not a target this call has any business
		// deriving at all — the SAME `rotated: null` shape Ruling S77
		// already gives an unreadable VERIFY, now given to an unreadable
		// INPUT before versioned() ever runs.
		$binding_for_target = self::binding_raw();
		if ( self::raw_option_was_unreadable() ) {
			return array(
				'rotated' => null,
				'epoch'   => null,
			);
		}
		$new_epoch = self::derive_rotation_target(
			self::ROTATE_TARGET_NAMESPACE,
			(string) $expected . '|' . $binding_for_target
		);
		$outcome   = self::versioned(
			function () use ( $expected, $claim, $fence, $new_epoch ) {
				return self::rotate_epoch_write( $expected, $claim, $fence, $new_epoch );
			}
		);
		if ( true === $outcome['committed'] ) {
			// UNCHANGED from before this ruling: a CLEAN commit already
			// proves whatever rotate_epoch_write() itself decided — true
			// when its own fenced write actually landed, false when its
			// own fence was lost to a DIFFERENT rotation (Ruling S15: safe
			// to trust `$outcome['result']` only when `committed` is
			// exactly `true`).
			return $outcome['result'];
		}
		// Ruling S62: `committed` is `false` (a PROVEN rollback — the
		// savepoint was lost, or the bump's own write failed, before this
		// call's mint could ever matter) or `null` (Ruling S51: UNKNOWN —
		// the durable witness itself could not be read either way). A
		// proven `false` answers exactly as before (nothing landed,
		// `rotated: false`); the genuinely new case is `null`: re-reading
		// the epoch raw and comparing it to the exact `$new_epoch` THIS
		// call minted — never merely "not $expected", which a concurrent
		// racer's own winning rotation, or a caller naming an epoch that
		// was never real, would ALSO satisfy without this call's own
		// write having landed at all — is what tells "my own ambiguous
		// write actually committed" apart from either of those. Equal ⇒
		// `rotated: true`, completing the rotation idempotently so the
		// caller's `restamp_binding_epoch()` runs and the binding record
		// stops naming an epoch this site no longer has (Ruling P91's own
		// "half-done rebind"); anything else (still `$expected`, or a
		// DIFFERENT value some other rotation produced) ⇒ `rotated:
		// false`, this call's own mint demonstrably did not land.
		//
		// Ruling S77 (Codex round-31 P2 on #88): that "anything else"
		// used to include a THIRD case this comparison cannot tell apart
		// from a proven miss — the verifying epoch_raw() read ITSELF
		// failing (`$now === ''`, the same sentinel an unreadable row and
		// a genuinely absent one both answer). An ambiguous COMMIT
		// (`committed: null`) paired with an UNPROVEN verify is not
		// evidence this call's mint did not land — it is simply two
		// unproven facts in a row — and answering a definitive `rotated:
		// false` on top of them told a caller "nothing happened" when the
		// honest answer is "unknown, and unknown a second time": a retry
		// with the SAME `$expected` then loses the fence against
		// whichever epoch is ACTUALLY current (this call's own, if it
		// really did land) and ALSO reports `false` — `restamp_binding_epoch()`
		// never runs, and the binding record is left naming an epoch this
		// site may already have left (Ruling P91's own "half-done
		// rebind", the exact case this whole idempotent-completion path
		// exists to close). Preserved as `rotated: null` instead: the
		// caller answers its own retryable 503 (`may_have_run`), never a
		// guessed `false`, and the NEXT retry — reading a NOW-healthy
		// epoch_raw() that happens to equal this call's own `$new_epoch`
		// — completes the idempotent match and restamps, exactly as a
		// healthy verify would have on the first try.
		$now = self::epoch_raw();
		if ( null === $outcome['committed'] && $now === $new_epoch ) {
			return array(
				'rotated' => true,
				'epoch'   => $now,
			);
		}
		if ( null === $outcome['committed'] && self::raw_option_was_unreadable() ) {
			return array(
				'rotated' => null,
				'epoch'   => null,
			);
		}
		return array(
			'rotated' => false,
			'epoch'   => $now,
		);
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
	 * @param string      $expected  The epoch the caller means to replace.
	 * @param string      $claim     Optional site-claim option name.
	 * @param string      $fence     Optional claim fence.
	 * @param string|null $new_epoch The replacement to mint, PRE-CHOSEN by
	 *                                the caller (Ruling S62, Codex round-23
	 *                                P2 on #88) — `rotate_epoch()` passes
	 *                                its own pre-minted value so an
	 *                                AMBIGUOUS commit can be completed
	 *                                idempotently by comparing the epoch
	 *                                raw against this EXACT target, never
	 *                                merely "changed". Null (every OTHER
	 *                                caller — `rotate_binding()` — and
	 *                                every pre-S62 behaviour) mints one
	 *                                internally, unchanged.
	 * @return array{ mutated: bool, result: array{ rotated: bool, epoch: string } }
	 */
	private static function rotate_epoch_write( $expected, $claim = '', $fence = '', $new_epoch = null ) {
		global $wpdb;
		$expected  = (string) $expected;
		$claim     = (string) $claim;
		$fence     = (string) $fence;
		$new_epoch = null === $new_epoch ? wp_generate_uuid4() : (string) $new_epoch;
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
			// Ruling S84 (Codex round-35 P1 on #88): must_succeed() before
			// the (int) cast — a real driver failure here must abort the
			// unit, never be silently read as an ordinary fence miss (the
			// branch just below, which the exact `1 !==` match already
			// catches `false`→`0` into, but without ever NAMING
			// last_error, which this generalises the rule to require).
			$gone = (int) self::must_succeed(
				$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
						"DELETE o FROM {$wpdb->options} o JOIN {$wpdb->options} c ON c.option_name = %s AND c.option_value LIKE %s WHERE o.option_name = %s AND o.option_value = %s",
						$claim,
						$wpdb->esc_like( $fence . '|' ) . '%',
						self::EPOCH,
						$expected
					)
				)
			);
			wp_cache_delete( self::EPOCH, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			if ( 1 !== $gone ) {
				// Ruling S78 (Codex round-32 P1 on #88): a fence miss whose
				// CURRENT epoch already equals THIS call's own $new_epoch
				// is not a lost race to some OTHER rotation — it is this
				// SAME logical rotation's own prior attempt (possibly
				// ambiguous) having already landed. See
				// derive_rotation_target()'s own docblock for why only a
				// retry naming the SAME inputs can ever reach this.
				$now_claimed = self::epoch_raw(); // never epoch() — see the docblock above
				return array(
					'mutated' => false,
					'result'  => array(
						'rotated' => ( $now_claimed === $new_epoch ),
						'epoch'   => $now_claimed,
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
			if ( ! self::insert_unique_write( self::EPOCH, $new_epoch ) ) {
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
		// Ruling S84 (Codex round-35 P1 on #88): the SAME must_succeed()
		// this file's own class docblock table names for the claim-
		// conditioned branch above -- this is the branch rotate_epoch()'s
		// own /door/rotate route actually reaches.
		$gone = (int) self::must_succeed(
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::EPOCH, $expected ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		);
		wp_cache_delete( self::EPOCH, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		if ( 1 !== $gone ) {
			// Ruling S78 (Codex round-32 P1 on #88): the SAME recognition
			// as the claim-conditioned branch above — this is the branch
			// `rotate_epoch()`'s own `/door/rotate` route actually reaches
			// (it passes no claim/fence), so THIS is the one a retry after
			// an ambiguous-and-unverifiable commit (Ruling S77) needs.
			$now = self::epoch_raw();
			return array(
				'mutated' => false,
				'result'  => array(
					'rotated' => ( $now === $new_epoch ),
					'epoch'   => $now,
				),
			);
		}
		delete_option( self::FULL_MARKER );
		delete_option( self::FULL_COUNTER );
		// See the mint note on the claim-conditioned branch above.
		if ( ! self::insert_unique_write( self::EPOCH, $new_epoch ) ) {
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
	 * UNREADABLE IS NOT EMPTY (Ruling S37, Codex round-15 class sweep on
	 * #88): `get_results()` answers its cleared `$last_result` — an empty
	 * array — for a statement that failed at the driver, indistinguishable
	 * from "nothing here is stale". The reconciler must not read a failed
	 * scan as a clean one: `null` here means the caller skips THIS pass
	 * entirely, rather than concluding no rows need recovering when the
	 * scan never actually ran.
	 *
	 * @param int $ms Age in milliseconds.
	 * @return array[]|null Null when the scan itself could not be read.
	 */
	public static function stale_pending( $ms ) {
		global $wpdb;
		$cut  = time() - (int) floor( $ms / 1000 );
		$like = $wpdb->esc_like( self::PREFIX ) . '%';
		$wpdb->last_error = '';
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
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return null;
		}
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
