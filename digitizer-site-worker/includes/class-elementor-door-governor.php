<?php
/**
 * The Elementor MCP door governor (SiteAgent 2.16.0, spec
 * docs/superpowers/specs/2026-09-02-elementor-door-governance-design.md).
 *
 * Elementor >= 4.3 serves its abilities from /elementor/mcp and through
 * core's /wp-abilities/v1 run route; both execute the callback registered
 * with wp_register_ability(). This class wraps that callback for every
 * elementor/* slug outside a named read allowlist (the LAST filter on the
 * args), verifies after registration that the callback finally stored is
 * its own closure — and closes both transports when it cannot — and judges
 * every call against the site's stored ruleset: block refuses, allow runs
 * with a snapshot, everything else is HELD for Aura's approval.
 *
 * @package Aura_Worker
 * @since 2.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Worker_Elementor_Door {

	const MODULE_CLASS   = '\Elementor\Modules\Mcp\Module';
	const CLAIM_STALE_MS = 600000;

	/**
	 * Envelope retention runs at most this often (Ruling P9(a)). The sweep
	 * reads every snapshot record on disk, and `/status` is the hottest
	 * endpoint this site has: on a fleet polled every minute that is the same
	 * directory decoded 1,440 times a day to delete nothing.
	 */
	const PRUNE_INTERVAL_S = 21600;

	/**
	 * When retention last ran. A STAMP, not a mutex: two overlapping prunes
	 * delete the same already-expired envelopes and are harmless, so this is
	 * an ordinary update_option() rather than an insert_unique().
	 */
	const PRUNED_AT = 'aura_worker_door_pruned_at';

	/**
	 * Wrapper refusals a replay must not spend the approval on (Ruling P7).
	 * Every one of them is issued BEFORE the inner callback runs and says
	 * "this site could not do it just now", never "this call is refused": a
	 * snapshot that failed, a log row that could not be written, a creation
	 * mutex another request holds, a hold queue that is busy.
	 */
	const RETRYABLE_CODES = array( 'aura_snapshot_failed', 'aura_log_failed', 'aura_log_full', 'aura_creation_busy', 'aura_hold_busy' );

	const CPT_GLOBAL_CLASS  = 'e_global_class';
	const CPT_DEFAULT_STYLE = 'e_default_style';
	const CPT_COMPONENT     = 'elementor_component';

	/**
	 * The creation mutex's option name. Task 7 owns taking and releasing it;
	 * the name is here because execute()'s exception path already has to
	 * release it, and a constant defined in one place cannot drift from the
	 * name the mutex actually takes.
	 */
	const CREATING = 'aura_worker_door_creating';

	/** Kit meta the design system lives in (4.3.0-beta1, R3). */
	const KIT_META_KEYS = array(
		'_elementor_global_variables',
		'_elementor_global_classes_order',
		'_elementor_global_classes_order_preview',
		'_elementor_global_classes_labels',
		'_elementor_global_classes_labels_preview',
		'_elementor_default_styles_post_ids',
		'_elementor_page_settings',
	);

	/** Meta captured on a page / component document. */
	const PAGE_META_KEYS = array( '_elementor_data', '_elementor_page_settings', '_elementor_css' );

	/**
	 * The ONLY exemption from the wrapper. Sixteen names: nine read tools and
	 * seven resources (R5). Annotations are not evidence — five current writes
	 * declare destructive => false.
	 */
	const READ_ALLOWLIST = array(
		'elementor/get-default-styles',
		'elementor/get-page-structure',
		'elementor/get-widget-schema',
		'elementor/list-assets',
		'elementor/list-components',
		'elementor/list-posts',
		'elementor/list-resources',
		'elementor/list-widget-schemas',
		'elementor/read-resource',
		'elementor/global-classes-resource',
		'elementor/global-variables-resource',
		'elementor/interactions-schema-resource',
		'elementor/manage-global-variable-guide',
		'elementor/style-best-practices',
		'elementor/wordpress-best-practices',
		'elementor/list-dynamic-tags',
	);

	/**
	 * Ability => target kind. `page` reads input.post_id (or document_id) and
	 * resolves page|post from the post type; `component` reads input.id (a
	 * missing id is a creation); the rest carry no id.
	 */
	const WRITE_TABLE = array(
		'elementor/publish-document'       => 'page',
		'elementor/manage-elements'        => 'page',
		'elementor/update-page-settings'   => 'page',
		'elementor/build-composition'      => 'page',
		'elementor/create-preview-link'    => 'page',
		'elementor/manage-component'       => 'component',
		'elementor/manage-default-styles'  => 'design_system',
		'elementor/manage-classes'         => 'design_system',
		'elementor/reorder-classes'        => 'design_system',
		'elementor/manage-global-variable' => 'design_system',
		'elementor/create-page'            => 'page_create',
	);

	/** @var array<string,Closure> the closure installed per slug, for the coverage check */
	private static $wrapped = array();
	/** @var string ok|unavailable|unchecked */
	private static $seam = 'unchecked';
	/** @var string */
	private static $seam_reason = '';
	/** @var array<string,array> per-request judgement memo */
	private static $memo = array();
	/** @var array<string,WP_Error> per-request hold memo: one call, one hold */
	private static $held = array();
	/** @var callable|null test seam over snapshot_for() */
	private static $snapshotter = null;
	/** @var callable|null test seam over the stored-callback read */
	private static $callback_reader = null;
	/** @var array|null the ruleset pinned for a replay (Task 8) */
	private static $pinned_ruleset = null;
	/** @var array|null the ack a replay carries (Task 8) */
	private static $replay_ack = null;
	/** @var bool|null memoised presence — see active() */
	private static $active = null;

	/* ------------------------------------------------------------------ */
	/* Wiring                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Wire the seam UNCONDITIONALLY.
	 *
	 * This used to return early unless Elementor's MCP module class already
	 * existed — and that was the one fail-OPEN path in a fail-closed design
	 * (Ruling P6). Both plugins bootstrap on `plugins_loaded` at priority 10,
	 * and same-priority callbacks fire in plugin-inclusion order, which is
	 * alphabetical: `digitizer-site-worker` runs BEFORE `elementor`. On such
	 * a site the class did not exist yet, so no wrapper was installed,
	 * `verify_coverage` never ran, `close_transport` was never hooked — and
	 * /elementor/mcp served every write ungoverned while `status_fragment()`
	 * reported the door closed.
	 *
	 * Every hook below is inert on a site without Elementor: `wrap_args`
	 * touches only `elementor/*` slugs, `verify_coverage` has nothing to
	 * verify, `close_transport` matches only the two door route prefixes,
	 * `observe_insert` is scoped to a governed request in flight, and the
	 * cleanup action is fired by Elementor alone. Presence is decided LAZILY
	 * instead — see active().
	 */
	public static function init() {
		add_filter( 'wp_register_ability_args', array( __CLASS__, 'wrap_args' ), PHP_INT_MAX, 2 );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'verify_coverage' ), PHP_INT_MAX );
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'close_transport' ), 2, 3 );
		add_action( 'wp_insert_post', array( __CLASS__, 'observe_insert' ), 1, 3 );
		add_action( 'elementor/global_classes/cleanup', array( __CLASS__, 'capture_class_cleanup' ), 1, 2 );
	}

	/** Test seam. */
	public static function reset_for_tests() {
		self::$wrapped             = array();
		self::$seam                = 'unchecked';
		self::$seam_reason         = '';
		self::$memo                = array();
		self::$held                = array();
		self::$snapshotter         = null;
		self::$callback_reader     = null;
		self::$pinned_ruleset      = null;
		self::$replay_ack          = null;
		self::$request             = null;
		self::$active              = null;
		// $GLOBALS['_sa_force_door'] — active()'s test override, standing in
		// for the module class this suite cannot define — is reset by
		// sa_reset_state(), not written here: production code reads that
		// seam, it never defines it.
	}

	/** @param callable $fn Test seam. */
	public static function set_snapshotter_for_tests( $fn ) {
		self::$snapshotter = $fn;
	}

	/** @param callable $fn Test seam. */
	public static function set_callback_reader_for_tests( $fn ) {
		self::$callback_reader = $fn;
	}

	/** @return string */
	public static function seam() {
		return self::$seam;
	}

	/**
	 * Is there an Elementor door on this site at all?
	 *
	 * Asked at `wp_abilities_api_init` and at request time — never at
	 * `plugins_loaded`, where the answer is a race (Ruling P6). Two
	 * witnesses, because either can be the one that is ready:
	 *
	 * 1. Elementor's MCP module class, looked up WITHOUT autoloading
	 *    (`class_exists( …, false )`): by the time anything asks, Elementor's
	 *    own autoloader has run and the class is loaded if the module is on.
	 * 2. The Abilities registry holding any `elementor/*` id — the module is
	 *    what registers them, so one of them IS the module, whatever the
	 *    class name does next release.
	 *
	 * Only a POSITIVE answer is memoised: within one request the registry
	 * only grows and an autoloader only becomes available, so `true` cannot
	 * turn back into `false` — but a `false` read before Elementor registered
	 * must not be frozen, which is the very load order this exists for.
	 *
	 * @return bool
	 */
	public static function active() {
		if ( true === self::$active ) {
			return true;
		}
		// The suite cannot define Elementor's module class; this stands in
		// for witness 1. Read only — never written by production code.
		if ( ! empty( $GLOBALS['_sa_force_door'] ) ) {
			self::$active = true;
			return true;
		}
		if ( class_exists( self::MODULE_CLASS, false ) ) {
			self::$active = true;
			return true;
		}
		if ( function_exists( 'wp_get_abilities' ) ) {
			foreach ( wp_get_abilities() as $ability ) {
				$name = is_object( $ability ) && method_exists( $ability, 'get_name' ) ? (string) $ability->get_name() : '';
				if ( 0 === strpos( $name, 'elementor/' ) ) {
					self::$active = true;
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * What `/status` says about the door (spec §3.10) — and what Aura drains.
	 *
	 * ABSENT on a site with no door: Aura keys on the fragment's presence to
	 * decide whether this site is governed at all, so a site without
	 * Elementor must not report a door — open or closed.
	 *
	 * `$after` is Aura's cursor and `$epoch` the epoch that cursor belongs to.
	 * A cursor from ANOTHER epoch says nothing about this log, so it is
	 * ignored outright and the log is served from 0 (Codex round-7 P1) —
	 * which is also what an absent `door_epoch` gets.
	 *
	 * A cursor from THIS epoch that is above every row AND above the ack
	 * floor is the one thing that cannot happen while the site's option store
	 * is intact: a failed ack leaves the rows, a successful one raises the
	 * floor. It means the options table was restored from a backup under the
	 * same epoch, so the epoch is ROTATED (Codex round-6 P1 on #499) and the
	 * NEW one answered — Aura's own epoch check then re-fetches from 0 and
	 * re-ingests whatever the backup holds.
	 *
	 * @param int    $after Aura's cursor.
	 * @param string $epoch The epoch that cursor belongs to; '' ⇒ served from 0.
	 * @return array|null { epoch, seam, door, held, interrupted, log, log_floor, log_unacked, log_full }
	 */
	public static function status_fragment( $after = 0, $epoch = '' ) {
		if ( ! self::active() ) {
			return null;
		}
		$after = (int) $after;
		$site  = Aura_Worker_Door_Log::epoch();
		if ( (string) $epoch !== $site ) {
			$after = 0;
		} elseif ( $after > max( Aura_Worker_Door_Log::highest_row_seq(), Aura_Worker_Door_Log::floor() ) ) {
			$site  = Aura_Worker_Door_Log::rotate_epoch();
			$after = 0;
		}
		$interrupted = array();
		foreach ( Aura_Worker_Door_Holds::stale_claims( self::CLAIM_STALE_MS ) as $ref => $claim ) {
			// Whatever reconcile() could not settle a moment ago — a claim
			// whose `interrupted` entry could not be written is reported here
			// every poll until it can be.
			$interrupted[] = array(
				'ref'        => (string) $ref,
				'claimed_at' => (string) ( isset( $claim['claimed_at'] ) ? $claim['claimed_at'] : '' ),
			);
		}
		return array(
			'epoch'       => $site,
			'seam'        => self::$seam,
			'door'        => 'ok' === self::$seam ? 'open' : 'closed',
			'held'        => Aura_Worker_Door_Holds::listing(),
			'interrupted' => $interrupted,
			'log'         => Aura_Worker_Door_Log::log_after( $after ),
			'log_floor'   => Aura_Worker_Door_Log::floor(),
			'log_unacked' => Aura_Worker_Door_Log::count_unacked(),
			'log_full'    => Aura_Worker_Door_Log::full_report(),
		);
	}

	/* ------------------------------------------------------------------ */
	/* The reconciler                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Settle what a died request left behind, at the head of every `/status`
	 * (spec §3.10). PHP requests die — a timeout, a fatal, a pool recycle —
	 * and every one of them can leave the door mid-transaction: a pending log
	 * row, a claimed hold nobody will release, the creation mutex, an
	 * envelope nothing points at.
	 *
	 * The ORDER is the guarantee:
	 *
	 * 1. The hold sweep first (TTL, and a held row whose claimed twin exists).
	 * 2. Stale CLAIMS, before stale rows — a claim's own `terminal_seq` is
	 *    better evidence about its row than the row's age is, and settling
	 *    the row first would leave the claim to be settled from an entry it
	 *    no longer explains.
	 * 3. Stale pending ROWS: the requests that never held anything.
	 * 4. The creation mutex, by age alone.
	 * 5. Retention: door envelopes older than 30 days (Ruling R6) — at most
	 *    once every PRUNE_INTERVAL_S, because the sweep reads every envelope
	 *    on disk and this runs on the site's hottest endpoint (Ruling P9(a)).
	 *
	 * A claim is released ONLY once its evidence is durable. Anything else
	 * loses the one record that a replay may have mutated the site.
	 *
	 * @param int|null $now Unix time; defaults to now.
	 * @return array{ interrupted: int, discarded: int, settled_claims: int, swept: int, pruned: int }
	 */
	public static function reconcile( $now = null ) {
		$now = null === $now ? time() : (int) $now;
		$out = array(
			'interrupted'    => 0,
			'discarded'      => 0,
			'settled_claims' => 0,
			'swept'          => 0,
			'pruned'         => 0,
		);

		$out['swept'] = (int) Aura_Worker_Door_Holds::sweep( $now );

		foreach ( Aura_Worker_Door_Holds::stale_claims( self::CLAIM_STALE_MS ) as $ref => $claim ) {
			self::settle_stale_claim( (string) $ref, (array) $claim, $out );
		}

		foreach ( Aura_Worker_Door_Log::stale_pending( self::CLAIM_STALE_MS ) as $row ) {
			$seq = (int) ( isset( $row['seq'] ) ? $row['seq'] : 0 );
			if ( $seq <= 0 ) {
				continue;
			}
			if ( empty( $row['admitted'] ) ) {
				// Never admitted: the request died between open_pending() and
				// admit(), so nothing was ever run under this number. It keeps
				// its seq — Aura's cursor is contiguous — and is served as
				// `discarded`.
				if ( Aura_Worker_Door_Log::discard( $seq ) ) {
					$out['discarded']++;
				}
				continue;
			}
			if ( self::settle_interrupted( $row ) ) {
				$out['interrupted']++;
			}
		}

		self::clear_stale_creation_mutex( $now );

		// Retention, at most every PRUNE_INTERVAL_S (Ruling P9(a)). `pruned`
		// is 0 when the gate skips — nothing was swept, and saying otherwise
		// would make the counter a lie.
		$last = strtotime( (string) get_option( self::PRUNED_AT, '' ) );
		if ( false === $last || $last <= $now - self::PRUNE_INTERVAL_S ) {
			$out['pruned'] = (int) ( new Aura_Worker_Snapshots() )->prune_older_than( 30, Aura_Worker_Snapshots::DOOR_KINDS );
			update_option( self::PRUNED_AT, gmdate( 'c', $now ), false );
		}

		return $out;
	}

	/**
	 * One stale claim, settled from its own evidence.
	 *
	 * `terminal_seq` is stamped AT ADMISSION, before the snapshot and before
	 * the callback (Ruling P8), so what it names says exactly what happened:
	 *
	 * - a TERMINAL entry, or a seq at or below the ack floor (the entry was
	 *   acked and its row deleted) ⇒ the run finished and only the release
	 *   was lost. Release; write nothing.
	 * - a PENDING entry ⇒ the run died mid-way. Settle that entry
	 *   `interrupted` — finishing its creation if it had one — and release
	 *   only if that settle landed.
	 * - no `terminal_seq` at all ⇒ the request died before admission (or the
	 *   stamp itself failed). Write one `interrupted` entry naming the ref,
	 *   through the same admission every entry gets, and release only if it
	 *   was durably recorded.
	 *
	 * @param string $ref   Hold ref.
	 * @param array  $claim The claimed row.
	 * @param array  $out   Counters, by reference.
	 */
	private static function settle_stale_claim( $ref, array $claim, array &$out ) {
		$seq = (int) ( isset( $claim['terminal_seq'] ) ? $claim['terminal_seq'] : 0 );
		if ( $seq > 0 ) {
			if ( $seq <= Aura_Worker_Door_Log::floor() ) {
				Aura_Worker_Door_Holds::release( $ref );
				$out['settled_claims']++;
				return;
			}
			$row = Aura_Worker_Door_Log::get( $seq );
			if ( null !== $row && 'pending' !== ( isset( $row['result'] ) ? $row['result'] : 'pending' ) ) {
				Aura_Worker_Door_Holds::release( $ref );
				$out['settled_claims']++;
				return;
			}
			if ( null !== $row ) {
				if ( self::settle_interrupted( $row ) ) {
					$out['interrupted']++;
					Aura_Worker_Door_Holds::release( $ref );
					$out['settled_claims']++;
				}
				return;
			}
			// A seq above the floor with no row cannot happen by construction
			// (only an ack deletes a row, and it raises the floor first), so
			// this claim's evidence is not readable. Fall through and write
			// one, rather than release on the strength of a number nothing
			// backs.
		}
		if ( self::record_terminal_only(
			(string) ( isset( $claim['ability'] ) ? $claim['ability'] : '' ),
			isset( $claim['actor'] ) && is_array( $claim['actor'] ) ? $claim['actor'] : array(),
			isset( $claim['touches'] ) && is_array( $claim['touches'] ) ? $claim['touches'] : array(),
			'interrupted',
			array( 'ref' => $ref )
		) ) {
			$out['interrupted']++;
			Aura_Worker_Door_Holds::release( $ref );
			$out['settled_claims']++;
		}
		// Not written — a closed log, a failed insert. The claim STAYS: it is
		// the only evidence a replay may have mutated the site, and
		// status_fragment() reports it in `interrupted[]` every poll until the
		// entry can be written (Codex round-7 P1).
	}

	/**
	 * Settle one admitted, pending row `interrupted`.
	 *
	 * Whatever the dead request already patched onto the row — the snapshot
	 * id, the collateral ids — is carried by settle()'s own merge; only a
	 * CREATION needs finishing here, and it is finished from the ROW's own
	 * fields (`post_watermark`, `expected_types`, the stored actor), never
	 * from `self::$request`, which belongs to whatever request is running now.
	 *
	 * @param array $row The log row.
	 * @return bool The row settled.
	 */
	private static function settle_interrupted( array $row ) {
		$seq    = (int) ( isset( $row['seq'] ) ? $row['seq'] : 0 );
		$fields = array( 'result' => 'interrupted' );
		if ( isset( $row['post_watermark'] ) ) {
			// A watermark is only ever stamped by a creation that got past the
			// mutex, so its presence IS "this row was creating".
			$fields = array_merge( $fields, self::finish_stale_creation( $seq, $row ) );
		}
		return Aura_Worker_Door_Log::settle( $seq, $fields );
	}

	/**
	 * The creation half of an interrupted row: union the two witnesses (the
	 * ids the insert hook recorded, and the watermark diff), store the
	 * `creation` envelope, and compensate when it cannot be stored — the
	 * posts exist either way, and one that cannot be made restorable is
	 * undone instead.
	 *
	 * The result stays `interrupted`: what the compensation says is HOW the
	 * site was left, not what happened to the call.
	 *
	 * @param int   $seq Log seq.
	 * @param array $row The log row.
	 * @return array Fields to settle with.
	 */
	private static function finish_stale_creation( $seq, array $row ) {
		$types = array();
		foreach ( (array) ( isset( $row['expected_types'] ) ? $row['expected_types'] : array() ) as $type ) {
			if ( is_string( $type ) && '' !== $type ) {
				$types[] = $type;
			}
		}
		$actor    = isset( $row['actor'] ) && is_array( $row['actor'] ) ? $row['actor'] : array();
		$actor_id = (int) ( isset( $actor['user_id'] ) ? $actor['user_id'] : 0 );
		// The window this call could possibly have written in: from its
		// watermark until the moment it was already stale (Ruling P9(b)).
		// `started_at` is stamped with the watermark; `at` is the row's own
		// birth and is always there. Neither readable ⇒ NO diff: an unbounded
		// one would claim posts made by hand hours later.
		$from  = strtotime( (string) ( isset( $row['started_at'] ) ? $row['started_at'] : '' ) );
		if ( false === $from ) {
			$from = strtotime( (string) ( isset( $row['at'] ) ? $row['at'] : '' ) );
		}
		$until = false === $from ? null : gmdate( 'Y-m-d H:i:s', $from + (int) floor( self::CLAIM_STALE_MS / 1000 ) );
		$diff     = ( empty( $types ) || null === $until ) ? array() : self::watermark_diff( (int) $row['post_watermark'], $types, $actor_id, $until );
		$hooked   = array_map( 'intval', (array) ( isset( $row['created_post_ids'] ) ? $row['created_post_ids'] : array() ) );
		$missed   = array_values( array_diff( $diff, $hooked ) );
		$created  = array_values( array_unique( array_merge( $hooked, $missed ) ) );
		$fields   = array( 'created_post_ids' => $created );
		if ( ! empty( $missed ) ) {
			$fields['observed_by_watermark'] = $missed;
			$fields['hook_missed']           = count( $missed );
			self::bump_counter( 'hook_missed' );
		}
		if ( empty( $created ) ) {
			return $fields; // nothing was inserted: nothing to undo
		}
		$snaps = new Aura_Worker_Snapshots();
		$env   = $snaps->snapshot_creation(
			$created,
			(string) ( isset( $types[0] ) ? $types[0] : '' ),
			array(
				'seq'         => $seq,
				'ability'     => (string) ( isset( $row['ability'] ) ? $row['ability'] : '' ),
				'interrupted' => true,
			)
		);
		if ( ! empty( $env['success'] ) ) {
			$fields['snapshot_id'] = (string) $env['snapshot']['id'];
			return $fields;
		}
		// Compensate only what the INSERT HOOK witnessed (Ruling P9(b)). A
		// diff-only id is the watermark's suspicion, not a witnessed creation
		// — good enough to record and to put in an envelope, nowhere near
		// good enough to trash somebody's page on. They are listed under
		// their own key, never folded into `uncompensated`, which means
		// "this call made it and it could not be undone".
		$fields = array_merge( $fields, array( 'reason' => 'snapshot_failed' ), self::compensate( $hooked ) );
		if ( ! empty( $missed ) ) {
			$fields['unproven'] = $missed;
		}
		return $fields;
	}

	/**
	 * Undo what a creation left behind when its envelope could not be stored.
	 *
	 * VERIFIED per id — wp_trash_post() can return a truthy value on a hook
	 * or database failure, so a post that stayed live is reported as
	 * uncompensated, never as undone.
	 *
	 * @param int[] $created Post ids.
	 * @return array{ compensated: int[], uncompensated: int[], compensated_by: string }
	 */
	private static function compensate( array $created ) {
		$how  = ( defined( 'EMPTY_TRASH_DAYS' ) && 0 === (int) EMPTY_TRASH_DAYS ) ? 'delete' : 'trash';
		$done = array();
		$left = array();
		foreach ( $created as $pid ) {
			wp_trash_post( $pid );
			$post = get_post( $pid );
			if ( ! $post || 'trash' === $post->post_status ) {
				$done[] = $pid;
			} else {
				$left[] = $pid;
			}
		}
		return array(
			'compensated'    => $done,
			'uncompensated'  => $left,
			'compensated_by' => $how,
		);
	}

	/**
	 * The creation mutex is one row per SITE, released by its owner alone —
	 * so a request that died holding it would close creations for ever. It is
	 * cleared by AGE, which is what `started_at` is for, and a stamp that
	 * cannot be read is not evidence of freshness either.
	 *
	 * @param int $now Unix time.
	 */
	private static function clear_stale_creation_mutex( $now ) {
		$mutex = get_option( self::CREATING, null );
		if ( ! is_array( $mutex ) ) {
			return;
		}
		$started = strtotime( (string) ( isset( $mutex['started_at'] ) ? $mutex['started_at'] : '' ) );
		if ( false === $started || $started <= $now - (int) floor( self::CLAIM_STALE_MS / 1000 ) ) {
			delete_option( self::CREATING );
		}
	}

	/* ------------------------------------------------------------------ */
	/* The seam                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Wrap execute_callback for every elementor/* slug outside the allowlist.
	 *
	 * @param array  $args Ability args.
	 * @param string $name Ability name.
	 * @return array
	 */
	public static function wrap_args( $args, $name ) {
		$name = (string) $name;
		if ( 0 !== strpos( $name, 'elementor/' ) || in_array( $name, self::READ_ALLOWLIST, true ) ) {
			return $args;
		}
		if ( ! is_array( $args ) ) {
			return $args;
		}
		$inner = isset( $args['execute_callback'] ) ? $args['execute_callback'] : null;
		$slug  = $name;
		$wrap  = static function ( $input = array() ) use ( $inner, $slug ) {
			return Aura_Worker_Elementor_Door::execute( $slug, $inner, $input );
		};
		self::$wrapped[ $name ]   = $wrap;
		$args['execute_callback'] = $wrap;
		return $args;
	}

	/**
	 * After Elementor registered: is every non-read elementor/* ability
	 * FINALLY registered with our closure? Read back from the registry, by
	 * Reflection on the stored property (R2) — never by re-applying the
	 * filter, which would inspect a fresh wrapper instead of what is stored.
	 */
	public static function verify_coverage() {
		if ( ! self::active() ) {
			// No Elementor, no door, nothing to govern — and emphatically NOT
			// a coverage failure: closing the transport here would 503 a
			// route that does not exist on this site (Ruling P6).
			self::$seam        = 'ok';
			self::$seam_reason = 'inactive: no Elementor MCP module on this site';
			return;
		}
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			self::$seam        = 'unavailable';
			self::$seam_reason = 'abilities api absent';
			return;
		}
		try {
			foreach ( wp_get_abilities() as $ability ) {
				$name = is_object( $ability ) && method_exists( $ability, 'get_name' ) ? (string) $ability->get_name() : '';
				if ( 0 !== strpos( $name, 'elementor/' ) || in_array( $name, self::READ_ALLOWLIST, true ) ) {
					continue;
				}
				$stored = self::stored_callback( $ability );
				if ( ! isset( self::$wrapped[ $name ] ) || $stored !== self::$wrapped[ $name ] ) {
					self::$seam        = 'unavailable';
					self::$seam_reason = 'uncovered: ' . $name;
					return;
				}
			}
		} catch ( \Throwable $e ) {
			self::$seam        = 'unavailable';
			self::$seam_reason = 'stored callback unreadable: ' . $e->getMessage();
			return;
		}
		self::$seam        = 'ok';
		self::$seam_reason = '';
	}

	/**
	 * @param object $ability WP_Ability.
	 * @return mixed the STORED execute callback.
	 * @throws ReflectionException When the property is not there (a coverage failure).
	 */
	private static function stored_callback( $ability ) {
		if ( null !== self::$callback_reader ) {
			return call_user_func( self::$callback_reader, $ability );
		}
		$prop = new ReflectionProperty( get_class( $ability ), 'execute_callback' );
		if ( PHP_VERSION_ID < 80100 ) {
			// Required on the plugin's 7.4 floor; a no-op since 8.1 and
			// deprecated in 8.5, so it is only called where it does something.
			$prop->setAccessible( true );
		}
		return $prop->getValue( $ability );
	}

	/**
	 * @param string $route REST route.
	 * @return bool
	 */
	public static function route_is_door( $route ) {
		$route = (string) $route;
		return (bool) preg_match( '#^/elementor/mcp(/|$)#', $route ) || (bool) preg_match( '#^/wp-abilities/v1/abilities/elementor/#', $route );
	}

	/**
	 * The transport blocker: independent of the wrapper, so a build that
	 * bypasses the wrapper still meets a closed door — reads included.
	 *
	 * @param mixed           $response Short-circuit value.
	 * @param array           $handler  Route handler.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function close_transport( $response, $handler, $request ) {
		if ( null !== $response || ! $request || ! method_exists( $request, 'get_route' ) ) {
			return $response;
		}
		if ( ! self::route_is_door( $request->get_route() ) ) {
			return $response;
		}
		if ( ! self::active() ) {
			return $response; // a door that does not exist is not closed
		}
		if ( 'ok' === self::$seam ) {
			return $response;
		}
		return new WP_Error(
			'aura_door_ungoverned',
			__( 'Aura governs this door and cannot verify its coverage on this build; the door is closed until SiteAgent is updated', 'digitizer-site-worker' ),
			array(
				'status' => 503,
				'reason' => self::$seam_reason,
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Actor, touches, judgement                                           */
	/* ------------------------------------------------------------------ */

	/** @return array|WP_Error */
	public static function actor() {
		$user = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		$uid  = $user ? (int) ( isset( $user->ID ) ? $user->ID : 0 ) : 0;
		if ( $uid <= 0 ) {
			return new WP_Error( 'aura_actor_unidentified', 'This call carries no authenticated user; Aura cannot attribute it.', array( 'status' => 403 ) );
		}
		// The UUID the plugin already captured on `application_password_did_authenticate`
		// (class-aura-worker-security.php:118, 251) — the one place that knows
		// which credential authenticated this request (Codex round-4 P2).
		$uuid = class_exists( 'Aura_Worker_Security' ) ? (string) Aura_Worker_Security::authenticating_app_password_uuid() : '';
		$name = null;
		if ( '' !== $uuid && class_exists( 'WP_Application_Passwords' ) ) {
			$pw   = WP_Application_Passwords::get_user_application_password( $uid, $uuid );
			$name = is_array( $pw ) && isset( $pw['name'] ) ? (string) $pw['name'] : null;
		}
		$route = class_exists( 'Aura_Worker_Call_Context' ) ? (string) Aura_Worker_Call_Context::rest_route() : '';
		return array(
			'user_id'           => $uid,
			'login'             => (string) ( isset( $user->user_login ) ? $user->user_login : '' ),
			'app_password_name' => $name,
			'app_password_uuid' => '' === $uuid ? null : $uuid,
			'via'               => 0 === strpos( $route, '/wp-abilities/' ) ? 'rest' : 'mcp',
		);
	}

	/**
	 * @param string $slug  Ability.
	 * @param array  $input Input.
	 * @return array|WP_Error touches, or aura_target_unattributed.
	 */
	public static function touches_for( $slug, array $input ) {
		$kind = isset( self::WRITE_TABLE[ $slug ] ) ? self::WRITE_TABLE[ $slug ] : null;
		switch ( $kind ) {
			case 'page':
				$id = self::page_id_from( $input );
				if ( null === $id ) {
					return new WP_Error( 'aura_target_unattributed', 'This write names no page Aura can snapshot; it was not run.', array( 'status' => 403 ) );
				}
				$type = get_post_type( $id );
				return array(
					array(
						'type' => 'page' === $type ? 'page' : 'post',
						'id'   => (string) $id,
					),
				);
			case 'component':
				return array(
					array(
						'type' => 'design_system',
						'id'   => '*',
					),
				);
			case 'design_system':
				return array(
					array(
						'type' => 'design_system',
						'id'   => '*',
					),
				);
			case 'page_create':
				return array(
					array(
						'type' => 'page_create',
						'id'   => '*',
					),
				);
			default:
				return new WP_Error( 'aura_ability_unmapped', sprintf( 'SiteAgent does not yet govern the ability "%s"; it was not run.', $slug ), array( 'status' => 403 ) );
		}
	}

	/**
	 * @param array $input Input.
	 * @return int|null
	 */
	private static function page_id_from( array $input ) {
		foreach ( array( 'post_id', 'document_id' ) as $k ) {
			if ( isset( $input[ $k ] ) && is_numeric( $input[ $k ] ) && (int) $input[ $k ] > 0 && (string) (int) $input[ $k ] === (string) $input[ $k ] ) {
				return get_post( (int) $input[ $k ] ) ? (int) $input[ $k ] : null;
			}
		}
		return null;
	}

	/**
	 * The verdict, once per request per (ability, input).
	 *
	 * @param string $slug    Ability.
	 * @param array  $touches Touches.
	 * @param array  $input   Input (memo key only).
	 * @return array { effect: block|hold|allow, rule: array|null, verdict: none|warn|rules_unavailable|block|allow }
	 */
	public static function govern( $slug, array $touches, array $input ) {
		$key = self::memo_key( $slug, $input );
		if ( isset( self::$memo[ $key ] ) ) {
			return self::$memo[ $key ];
		}
		$rec = null !== self::$pinned_ruleset ? self::$pinned_ruleset : Aura_Worker_Rules::current();
		if ( null === $rec || ! isset( $rec['rules'] ) || ! is_array( $rec['rules'] ) ) {
			$out = array(
				'effect'  => 'hold',
				'rule'    => null,
				'verdict' => 'rules_unavailable',
			);
		} else {
			$rule = Aura_Worker_Rules::match( $touches, $rec['rules'], null, Aura_Worker_Rules::site_ref() );
			if ( null === $rule ) {
				$out = array(
					'effect'  => 'hold',
					'rule'    => null,
					'verdict' => 'none',
				);
			} elseif ( 'block' === $rule['effect'] ) {
				$out = array(
					'effect'  => 'block',
					'rule'    => $rule,
					'verdict' => 'block',
				);
			} elseif ( 'warn' === $rule['effect'] ) {
				$out = array(
					'effect'  => 'hold',
					'rule'    => $rule,
					'verdict' => 'warn',
				);
			} else {
				$out = array(
					'effect'  => 'allow',
					'rule'    => $rule,
					'verdict' => 'allow',
				);
			}
		}
		self::$memo[ $key ] = $out;
		return $out;
	}

	/**
	 * One call, one key: the same (ability, input) inside one request is the
	 * same call, whether it is being judged or held.
	 *
	 * @param string $slug  Ability.
	 * @param array  $input Input.
	 * @return string
	 */
	private static function memo_key( $slug, array $input ) {
		return $slug . '|' . hash( 'sha256', (string) wp_json_encode( $input ) );
	}

	/**
	 * @param array $rule Rule.
	 * @return array { key, ruleHash, reason }
	 */
	public static function rule_evidence( array $rule ) {
		return array(
			'key'      => (string) ( isset( $rule['key'] ) ? $rule['key'] : '' ),
			'ruleHash' => hash( 'sha256', (string) wp_json_encode( $rule ) ),
			'reason'   => (string) ( isset( $rule['reason'] ) ? $rule['reason'] : '' ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* The wrapper                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * The execute wrapper installed on every governed ability.
	 *
	 * @param string        $slug  Ability.
	 * @param callable|null $inner The callback Elementor registered.
	 * @param mixed         $input Input.
	 * @return mixed The inner result unchanged, or a WP_Error refusal.
	 */
	public static function execute( $slug, $inner, $input ) {
		$input = is_array( $input ) ? $input : array();
		try {
			return self::govern_and_run( $slug, $inner, $input );
		} catch ( \Throwable $e ) {
			// A broken governor must not become an open door — and a throw
			// AFTER the row was admitted (the callback itself, a snapshot, a
			// collateral capture) must not leave that row pending: `log_after`
			// stops at a pending row, so every later entry would wait ten
			// minutes for the reconciler to call a KNOWN failure "interrupted"
			// (Codex round-3 P1 on #499). Settle it `failed` with whatever
			// evidence the row already carries (created ids, collateral ids
			// were patched in as they happened), release the creation mutex,
			// and say honestly that the call may have run.
			$seq = isset( self::$request['seq'] ) ? (int) self::$request['seq'] : 0;
			$ran = $seq > 0;
			if ( $ran ) {
				$fields = array(
					'result'       => 'failed',
					'reason'       => 'exception',
					'error'        => $e->getMessage(),
					'may_have_run' => true,
				);
				if ( ! empty( self::$request['creating'] ) && empty( self::$request['creation_done'] ) ) {
					// The observer already put every inserted id on the row; the
					// envelope (or the compensation) must still happen — a row
					// settled here is one the reconciler will never revisit
					// (Codex round-4 P1). finish_creation() releases the mutex.
					try {
						$creation = self::finish_creation( $seq, null, true );
						if ( ! is_wp_error( $creation ) ) {
							$fields = array_merge( $fields, $creation );
						} else {
							$fields['reason'] = 'exception_then_compensated';
						}
					} catch ( \Throwable $creation_error ) {
						self::release_creation_mutex();
						$fields['creation_error'] = $creation_error->getMessage();
					}
				} elseif ( ! empty( self::$request['creation_fields'] ) ) {
					// A creation that ALREADY finished, and then something after
					// it threw (round 1): it is not finished twice. A second
					// finish_creation() would write a duplicate envelope and, if
					// the store failed that time, TRASH the very posts the first
					// envelope had just made restorable — with no mutex held,
					// and ending in a delete_option() that would release another
					// request's. The evidence the first one produced is carried
					// onto this settle instead.
					$fields = array_merge( $fields, self::$request['creation_fields'] );
				}
				Aura_Worker_Door_Log::settle( $seq, $fields );
				self::$request = null;
			}
			return new WP_Error(
				'aura_governor_error',
				( $ran ? 'Aura\'s governor failed during this call; it may have run — check the site. ' : 'Aura\'s governor failed on this call; it was not run. ' ) . $e->getMessage(),
				array( 'status' => 503 )
			);
		}
	}

	/**
	 * @param string        $slug  Ability.
	 * @param callable|null $inner Inner.
	 * @param array         $input Input.
	 * @return mixed
	 */
	private static function govern_and_run( $slug, $inner, array $input ) {
		$actor = self::actor();
		if ( is_wp_error( $actor ) ) {
			return $actor;
		}
		$touches = self::touches_for( $slug, $input );
		if ( is_wp_error( $touches ) ) {
			// Unmapped / unattributed: refused before judgement, one terminal entry, nothing held.
			$why = 'aura_ability_unmapped' === $touches->get_error_code() ? 'unknown_ability' : 'unattributed_target';
			self::bump_counter( 'unknown_ability' );
			self::record_terminal_only( $slug, $actor, array(), 'refused', array( 'reason' => $why ) );
			return $touches;
		}
		$verdict = self::govern( $slug, $touches, $input );

		if ( 'block' === $verdict['effect'] ) {
			Aura_Worker_Rules::record_block( $slug, $verdict['rule'] );
			self::record_terminal_only(
				$slug,
				$actor,
				$touches,
				'refused',
				array(
					'verdict'  => 'block',
					'rule_key' => isset( $verdict['rule']['key'] ) ? $verdict['rule']['key'] : '',
				)
			);
			$blocked = Aura_Worker_Rules::blocked_result( $slug, $verdict['rule'] );
			return new WP_Error( 'aura_rule_blocked', $blocked['error'], array( 'status' => 403 ) );
		}

		if ( 'hold' === $verdict['effect'] && null === self::$replay_ack ) {
			return self::hold_call( $slug, $input, $touches, $actor, $verdict );
		}

		// allow (or a replay whose approval is spent): closed log?
		if ( Aura_Worker_Door_Log::is_closed() ) {
			Aura_Worker_Door_Log::bump_refused();
			return self::log_full_error();
		}
		// A replay's verdict is what the OPERATOR decided, not what the rules
		// said: `none` / `rules_unavailable` reached the write because Aura
		// approved it, and the entry says `approved` rather than repeating a
		// judgement that by itself would have held the call. A warn is still
		// a warn — it was acknowledged, not overridden.
		$entry = array(
			'ability'  => $slug,
			'actor'    => $actor,
			'touches'  => $touches,
			'verdict'  => null !== self::$replay_ack ? ( 'warn' === $verdict['verdict'] ? 'warn' : 'approved' ) : $verdict['verdict'],
			'rule_key' => isset( $verdict['rule']['key'] ) ? (string) $verdict['rule']['key'] : null,
		);
		if ( null !== self::$replay_ack ) {
			$entry['ref']         = self::$replay_ack['ref'];
			$entry['ruleset_seq'] = isset( self::$pinned_ruleset['seq'] ) ? (int) self::$pinned_ruleset['seq'] : null;
		}
		$seq = Aura_Worker_Door_Log::open_pending( $entry );
		if ( is_wp_error( $seq ) ) {
			return $seq;
		}
		// Admission: the row is the reservation. Count, back out above the bound.
		if ( Aura_Worker_Door_Log::count_unacked() > Aura_Worker_Door_Log::MAX_UNACKED ) {
			Aura_Worker_Door_Log::discard( $seq );
			Aura_Worker_Door_Log::close();
			Aura_Worker_Door_Log::bump_refused();
			return self::log_full_error();
		}
		if ( ! Aura_Worker_Door_Log::admit( $seq ) ) {
			return new WP_Error( 'aura_log_failed', 'The door log could not record this call; it was not run.', array( 'status' => 503 ) );
		}
		if ( null !== self::$replay_ack ) {
			// The LINK between the claimed row and this entry, written BEFORE
			// the snapshot and before the callback (Ruling P8). It used to be
			// stamped after the write, which made an unstamped row ambiguous:
			// `nothing ran` and `the creation ran and its envelope failed`
			// looked identical, and the second was replayed — creating the
			// page twice. Stamped here, `no terminal_seq` means exactly one
			// thing: this call was never admitted to the write path.
			//
			// A stamp that FAILS refuses the call rather than running it
			// unlinked: the claimed row would carry no seq, and the only
			// honest reading of that afterwards is "nothing ran" — which a
			// write that DID run would turn into a second run. `aura_log_failed`
			// is retryable and nothing has happened yet, so the hold goes back.
			if ( ! Aura_Worker_Door_Holds::stamp_terminal_seq( self::$replay_ack['ref'], $seq ) ) {
				Aura_Worker_Door_Log::settle(
					$seq,
					array(
						'result' => 'refused',
						'reason' => 'terminal_seq_unstamped',
					)
				);
				return new WP_Error( 'aura_log_failed', 'The door log could not link this approval to its entry; it was not run.', array( 'status' => 503 ) );
			}
		}
		$kind = isset( self::WRITE_TABLE[ $slug ] ) ? self::WRITE_TABLE[ $slug ] : null;
		// `create-page` always creates; a `manage-component` naming no id is
		// creating one too, and takes the same path — mutex, watermark, and a
		// creation envelope AFTER, because there is nothing to capture before.
		$creating      = self::is_creating( $slug, $input );
		self::$request = array(
			'seq'        => $seq,
			'slug'       => $slug,
			'kind'       => $kind,
			'creating'   => $creating,
			// The witnesses' shared frame: which types this creation may
			// insert, and whose inserts they are. Both are read by the hook
			// observer and by the watermark diff after it.
			'expected'   => self::expected_types( $slug, $input ),
			'actor_id'   => (int) $actor['user_id'],
			'created'    => array(),
			'suspected'  => array(),
			'collateral' => array(),
		);

		// Snapshot before the write (a creation captures after).
		$snapshot_id = null;
		if ( ! $creating ) {
			$snap = self::snapshot_for( $slug, $touches, $input );
			if ( empty( $snap['success'] ) ) {
				Aura_Worker_Door_Log::settle(
					$seq,
					array_merge(
						array(
							'result' => 'refused',
							'reason' => 'snapshot_failed',
							'error'  => (string) ( isset( $snap['error'] ) ? $snap['error'] : '' ),
						),
						// A DESIGNATED refusal carries its code ON THE ROW: a
						// replay answers from the entry, never from the return
						// value, so the code has to be there to be answered with.
						empty( $snap['code'] ) ? array() : array( 'code' => (string) $snap['code'] )
					)
				);
				self::$request = null;
				$why = (string) ( isset( $snap['error'] ) ? $snap['error'] : '' );
				if ( ! empty( $snap['code'] ) ) {
					// A DESIGNATED refusal (today: `aura_target_unattributed`, a
					// component write naming a post that is not a component). The
					// target can never become snapshottable, so this is not the
					// 503 a caller should retry — it is a 403 under its own code,
					// the same answer touches_for() gives an unattributable page.
					return new WP_Error(
						(string) $snap['code'],
						'This write names no target Aura can snapshot; it was not run. ' . $why,
						array( 'status' => 403 )
					);
				}
				return new WP_Error( 'aura_snapshot_failed', 'Aura could not snapshot this target before the write; it was not run. ' . $why, array( 'status' => 503 ) );
			}
			$snapshot_id = (string) $snap['snapshot']['id'];
			if ( ! Aura_Worker_Door_Log::patch_pending( $seq, array( 'snapshot_id' => $snapshot_id ) ) ) { // the id lands on the row BEFORE the write, durably
				// The row stays PENDING on purpose — a snapshot was taken and
				// the row does not know it, which is the reconciler's case,
				// not a terminal one this request can honestly write.
				self::$request = null;
				return new WP_Error( 'aura_log_failed', 'The door log could not record the snapshot before the write; it was not run.', array( 'status' => 503 ) );
			}
		} else {
			$mutex = self::take_creation_mutex( $seq );
			if ( is_wp_error( $mutex ) ) {
				Aura_Worker_Door_Log::settle(
					$seq,
					array(
						'result' => 'refused',
						'reason' => 'creation_busy',
					)
				);
				self::$request = null;
				return $mutex;
			}
			if ( ! self::stamp_watermark( $seq ) ) {
				// Without a durable watermark an interrupted creation could
				// not be found; refuse before Elementor runs.
				self::release_creation_mutex();
				Aura_Worker_Door_Log::settle(
					$seq,
					array(
						'result' => 'refused',
						'reason' => 'watermark_failed',
					)
				);
				self::$request = null;
				return new WP_Error( 'aura_log_failed', 'The door log could not record the creation watermark; it was not run.', array( 'status' => 503 ) );
			}
		}

		if ( 'warn' === $verdict['verdict'] ) {
			Aura_Worker_Rules::record_warn( $slug, $verdict['rule'] );
		}

		if ( null !== self::$replay_ack && ! Aura_Worker_Door_Log::patch_pending( $seq, array( 'ran' => true ) ) ) {
			// The witness a replay is judged by (Ruling P8): whether the
			// callback ran is a fact about the SITE, and the row has to hold
			// it before the callback can, or a refusal afterwards is
			// indistinguishable from one before. Not durable, not run — the
			// same rule the creation watermark follows a few lines above.
			if ( $creating ) {
				self::release_creation_mutex();
			}
			Aura_Worker_Door_Log::settle(
				$seq,
				array(
					'result' => 'refused',
					'reason' => 'ran_witness_failed',
				)
			);
			self::$request = null;
			return new WP_Error( 'aura_log_failed', 'The door log could not record that this call was about to run; it was not run.', array( 'status' => 503 ) );
		}

		$result = is_callable( $inner ) ? call_user_func( $inner, $input ) : new WP_Error( 'ability_invalid_execute_callback', 'no callback' );
		do_action( 'sa_test_inner_ran', $slug ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- test seam only (ordering: snapshot before the write); no listener in production.

		$failed   = is_wp_error( $result ) || ( is_array( $result ) && 'error' === ( isset( $result['status'] ) ? $result['status'] : '' ) );
		$terminal = array( 'result' => $failed ? 'failed' : 'ok' );
		if ( $failed ) {
			$terminal['error'] = is_wp_error( $result ) ? $result->get_error_message() : 'ability reported an error';
		}
		if ( null !== $snapshot_id ) {
			$terminal['snapshot_id'] = $snapshot_id;
		}
		if ( $creating ) {
			$creation = self::finish_creation( $seq, $result, $failed ); // Task 7
			// Finished — recorded BEFORE stamp_terminal_seq() or settle() can
			// throw, because execute()'s catch keys on it to decide whether the
			// creation still needs finishing (round 1).
			self::$request['creation_done'] = true;
			if ( is_wp_error( $creation ) ) {
				return $creation; // compensated + settled inside
			}
			self::$request['creation_fields'] = $creation; // what a later throw settles with
			$terminal                         = array_merge( $terminal, $creation );
		}
		if ( ! empty( self::$request['collateral'] ) ) {
			$terminal['collateral_snapshot_ids'] = self::$request['collateral'];
		}
		Aura_Worker_Door_Log::settle( $seq, $terminal );
		self::$request = null;
		return $result;
	}

	/**
	 * Hold: store the call, log it, refuse the client with the ref.
	 *
	 * Memoised on the same key as the verdict: one call inside one request is
	 * ONE hold. An MCP client that re-sends the same call in the same request,
	 * or an ability re-entered through the second transport, must not leave
	 * the operator two approval entries for one write.
	 *
	 * @param string $slug    Ability.
	 * @param array  $input   Input.
	 * @param array  $touches Touches.
	 * @param array  $actor   Actor.
	 * @param array  $verdict Verdict.
	 * @return WP_Error
	 */
	private static function hold_call( $slug, array $input, array $touches, array $actor, array $verdict ) {
		$key = self::memo_key( $slug, $input );
		if ( isset( self::$held[ $key ] ) ) {
			return self::$held[ $key ];
		}
		$ref = Aura_Worker_Door_Holds::hold(
			array(
				'ability' => $slug,
				'input'   => $input,
				'touches' => $touches,
				'actor'   => $actor,
				'verdict' => $verdict['verdict'],
				'rule'    => null !== $verdict['rule'] ? self::rule_evidence( $verdict['rule'] ) : null,
			)
		);
		if ( is_wp_error( $ref ) ) {
			return $ref; // a queue that is full or busy is retryable; never memoised
		}
		if ( 'warn' === $verdict['verdict'] ) {
			Aura_Worker_Rules::record_warn( $slug, $verdict['rule'] );
		}
		self::record_terminal_only(
			$slug,
			$actor,
			$touches,
			'held',
			array(
				'ref'      => $ref,
				'verdict'  => $verdict['verdict'],
				'rule_key' => isset( $verdict['rule']['key'] ) ? $verdict['rule']['key'] : null,
			)
		);
		$refusal            = new WP_Error(
			'aura_held_for_approval',
			sprintf( 'This write is queued for approval in Aura (ref %s). Do not retry; it will run if approved.', $ref ),
			array(
				'status' => 409,
				'ref'    => $ref,
			)
		);
		self::$held[ $key ] = $refusal;
		return $refusal;
	}

	/**
	 * One terminal entry for a call that never ran (held / refused): the row
	 * is inserted, admitted and settled in one go so it is served.
	 *
	 * @param string $slug    Ability.
	 * @param array  $actor   Actor.
	 * @param array  $touches Touches.
	 * @param string $result  held|refused|interrupted.
	 * @param array  $extra   Extra fields.
	 * @return bool The entry is DURABLY recorded. The reconciler keeps a stale
	 *              claim when it is not; every other caller's refusal already
	 *              stands whatever this answers.
	 */
	private static function record_terminal_only( $slug, array $actor, array $touches, $result, array $extra ) {
		// The same admission as a write (Codex round-2 P2): a closed log takes
		// no row for a refusal either — it is counted, not stored — and a row
		// that lands above the bound is settled `discarded`. The refusal
		// itself already stands; what is lost is its record, which the
		// audit's `log_ungoverned_30d` counts.
		if ( Aura_Worker_Door_Log::is_closed() ) {
			Aura_Worker_Door_Log::bump_refused();
			self::bump_counter( 'log_ungoverned' );
			return false;
		}
		$seq = Aura_Worker_Door_Log::open_pending(
			array_merge(
				array(
					'ability' => $slug,
					'actor'   => $actor,
					'touches' => $touches,
				),
				$extra
			)
		);
		if ( is_wp_error( $seq ) ) {
			self::bump_counter( 'log_ungoverned' );
			return false;
		}
		if ( Aura_Worker_Door_Log::count_unacked() > Aura_Worker_Door_Log::MAX_UNACKED ) {
			Aura_Worker_Door_Log::discard( $seq );
			Aura_Worker_Door_Log::close();
			self::bump_counter( 'log_ungoverned' );
			return false;
		}
		Aura_Worker_Door_Log::admit( $seq );
		// settle() admits too, so the entry is served whether or not the
		// admit above landed; its own answer is the whole of "durable".
		return Aura_Worker_Door_Log::settle( $seq, array_merge( array( 'result' => $result ), $extra ) );
	}
	/* ------------------------------------------------------------------ */
	/* Replay: Aura's approval of a held call                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Run a held write now that Aura has approved it (spec §3.7).
	 *
	 * The ORDER is the guarantee, and every step of it is load-bearing:
	 *
	 * 1. The hold is read first; a ref with no held row — or one whose
	 *    claimed twin exists (in flight, or interrupted and waiting for the
	 *    reconciler) — is `not_held`, never a second run.
	 * 2. The ruleset is read ONCE and pinned. Everything after this judges
	 *    and records against that one copy, so a push landing mid-write
	 *    cannot make the entry describe a policy the call was never judged
	 *    on.
	 * 3. The call is RE-JUDGED before anything is claimed: a `block`
	 *    delivered while the call sat in the queue refuses it and rejects
	 *    the hold, and a `warn` must be acknowledged by (key, ruleHash) —
	 *    an approver who saw a different rule has not approved this one.
	 * 4. The ability's own permission callback is re-checked AS THE STORED
	 *    ACTOR: a user demoted or deleted during the hold's seven days must
	 *    not get a mutation through somebody else's approval.
	 * 5. Only then is the hold claimed, by moving it — and the answer comes
	 *    from the TERMINAL LOG ENTRY, not from the fact that the callback
	 *    returned. Aura marks the action from this answer.
	 *
	 * @param string     $ref Hold ref.
	 * @param array|null $ack { key, ruleHash } the approver acknowledged.
	 * @return array
	 */
	public static function replay( $ref, $ack ) {
		$held = Aura_Worker_Door_Holds::get_held( $ref );
		if ( null === $held || null !== Aura_Worker_Door_Holds::get_claimed( $ref ) ) {
			return array(
				'ok'     => false,
				'reason' => 'not_held',
			);
		}
		$slug                 = (string) $held['ability'];
		$input                = (array) $held['input'];
		$rec                  = Aura_Worker_Rules::current();
		self::$pinned_ruleset = $rec;
		self::$memo           = array();
		$prev_user            = get_current_user_id();
		try {
			$verdict = self::govern( $slug, (array) $held['touches'], $input );
			if ( 'block' === $verdict['effect'] ) {
				Aura_Worker_Rules::record_block( $slug, $verdict['rule'] );
				self::record_terminal_only(
					$slug,
					(array) $held['actor'],
					(array) $held['touches'],
					'refused',
					array(
						'ref'      => $ref,
						'reason'   => 'refused_by_current_rule',
						'rule_key' => isset( $verdict['rule']['key'] ) ? (string) $verdict['rule']['key'] : '',
					)
				);
				Aura_Worker_Door_Holds::reject( $ref );
				return array(
					'ok'       => false,
					'reason'   => 'refused_by_current_rule',
					'rule_key' => isset( $verdict['rule']['key'] ) ? (string) $verdict['rule']['key'] : '',
				);
			}
			if ( 'warn' === $verdict['verdict'] ) {
				$ev = self::rule_evidence( $verdict['rule'] );
				if ( null === $ack || ! isset( $ack['key'], $ack['ruleHash'] ) || $ack['key'] !== $ev['key'] || $ack['ruleHash'] !== $ev['ruleHash'] ) {
					// The hold carries the rule the operator must acknowledge
					// NEXT — refreshed in place, so a stale approval cannot be
					// replayed against a rule nobody has seen.
					Aura_Worker_Door_Holds::refresh_rule( $ref, $ev );
					return array(
						'ok'     => false,
						'reason' => 'warn_changed',
						'rule'   => $ev,
					);
				}
			}
			$claimed = Aura_Worker_Door_Holds::claim( $ref );
			if ( is_wp_error( $claimed ) ) {
				// `not_held` from claim() is a LOST RACE (a reject or the
				// sweep took the row), not a rejection of this replay: Aura
				// retries it, and finds out what happened from the hold list.
				return array(
					'ok'     => false,
					'reason' => 'not_held',
				);
			}
			wp_set_current_user( (int) $held['actor']['user_id'] );
			$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $slug ) : null;
			if ( ! $ability ) {
				// Elementor was deactivated (or the ability renamed) during the
				// hold's seven days. That is a REFUSAL with a record, not
				// `not_held` — which means "retry", and this one never will
				// succeed until the plugin comes back.
				self::record_terminal_only(
					$slug,
					(array) $held['actor'],
					(array) $held['touches'],
					'refused',
					array(
						'ref'    => $ref,
						'reason' => 'ability_missing',
					)
				);
				Aura_Worker_Door_Holds::release( $ref );
				return array(
					'ok'     => false,
					'reason' => 'refused_by_missing_ability',
				);
			}
			// The ability's OWN permission callback, as the stored actor,
			// before anything runs. `WP_Ability::check_permissions()` is
			// public on WP 7.1's class.
			$allowed = method_exists( $ability, 'check_permissions' ) ? $ability->check_permissions( $input ) : false;
			if ( true !== $allowed ) {
				self::record_terminal_only(
					$slug,
					(array) $held['actor'],
					(array) $held['touches'],
					'refused',
					array(
						'ref'    => $ref,
						'reason' => 'refused_by_permission',
						'error'  => is_wp_error( $allowed ) ? $allowed->get_error_message() : 'the actor no longer has permission for this ability',
					)
				);
				Aura_Worker_Door_Holds::release( $ref );
				return array(
					'ok'     => false,
					'reason' => 'refused_by_permission',
				);
			}
			self::$replay_ack = array(
				'ref' => $ref,
				'ack' => $ack,
			);
			$result = $ability->execute( $input );
			$stamp  = Aura_Worker_Door_Holds::get_claimed( $ref );
			$seq    = (int) ( isset( $stamp['terminal_seq'] ) ? $stamp['terminal_seq'] : 0 );
			$entry  = $seq > 0 ? Aura_Worker_Door_Log::get( $seq ) : null;
			$code   = is_wp_error( $result ) ? $result->get_error_code() : '';

			// THE DISCRIMINATOR IS NEVER THE ERROR CODE — it is whether the
			// callback ran, and the row says so (Ruling P8). `terminal_seq` is
			// stamped at admission, so `no seq` means the write path was never
			// entered; `ran` is patched immediately before the callback, so an
			// entry without it is a call the site provably did not perform.
			// The code decides only whether a call that did NOT run is worth
			// retrying.
			if ( 0 === $seq ) {
				// Never admitted: a closed log, a log row that could not be
				// written, a target that stopped being attributable while the
				// call waited. Nothing ran, so nothing needs a rollback.
				if ( is_wp_error( $result ) ) {
					return in_array( $code, self::RETRYABLE_CODES, true )
						? self::give_back( $ref, $code, $result->get_error_message() )
						: self::spend_refusal( $ref, $code, $result->get_error_message() );
				}
				// A result with no admission cannot happen — unless the claimed
				// row itself was taken from under this request, in which case
				// the seq is not missing, it is unreadable, and the write may
				// well have happened.
				return array(
					'ok'     => false,
					'reason' => 'interrupted',
					'ref'    => $ref,
				);
			}

			// From here the answer comes from the ENTRY and nothing else. One
			// that is missing or still pending is not an answer: the callback
			// may have run, a return value is not evidence, and the claimed
			// row is the only witness left — leave it for the reconciler.
			$outcome = is_array( $entry ) ? (string) ( isset( $entry['result'] ) ? $entry['result'] : '' ) : '';
			if ( ! in_array( $outcome, Aura_Worker_Door_Log::TERMINAL, true ) ) {
				return array(
					'ok'     => false,
					'reason' => 'interrupted',
					'ref'    => $ref,
				);
			}
			$ran = ! empty( $entry['ran'] );
			if ( ! $ran && in_array( $code, self::RETRYABLE_CODES, true ) ) {
				// Admitted, refused before the callback, and worth retrying —
				// the snapshot that failed, the creation mutex, the watermark.
				// The approval is not spent on a site that could not act.
				return self::give_back( $ref, $code, $result->get_error_message() );
			}
			if ( 'ok' === $outcome ) {
				Aura_Worker_Door_Holds::release( $ref );
				return array(
					'ok'               => true,
					'result'           => $result,
					'snapshot_id'      => isset( $entry['snapshot_id'] ) ? $entry['snapshot_id'] : null,
					'created_post_ids' => isset( $entry['created_post_ids'] ) ? $entry['created_post_ids'] : array(),
				);
			}
			if ( 'refused' === $outcome ) {
				$why = (string) ( isset( $entry['code'] ) ? $entry['code'] : ( isset( $entry['reason'] ) ? $entry['reason'] : '' ) );
				if ( 'block' === ( isset( $entry['verdict'] ) ? $entry['verdict'] : '' ) || 'refused_by_current_rule' === $why ) {
					Aura_Worker_Door_Holds::reject( $ref );
					return array(
						'ok'       => false,
						'reason'   => 'refused_by_current_rule',
						'rule_key' => (string) ( isset( $entry['rule_key'] ) ? $entry['rule_key'] : '' ),
					);
				}
				return self::spend_refusal( $ref, $why, (string) ( isset( $entry['error'] ) ? $entry['error'] : '' ) );
			}
			// `failed`: it ran (or it was refused under a code no retry can
			// help), and the entry says what came of it — including what a
			// creation left behind.
			Aura_Worker_Door_Holds::release( $ref );
			$out = array(
				'ok'     => false,
				'reason' => 'failed',
				'error'  => (string) ( isset( $entry['error'] ) ? $entry['error'] : ( is_wp_error( $result ) ? $result->get_error_message() : 'ability reported an error' ) ),
				'code'   => '' === $code ? 'ability_error' : $code,
			);
			foreach ( array( 'snapshot_id', 'created_post_ids', 'compensated', 'uncompensated' ) as $field ) {
				if ( isset( $entry[ $field ] ) ) {
					$out[ $field ] = $entry[ $field ];
				}
			}
			return $out;
		} finally {
			self::$replay_ack     = null;
			self::$pinned_ruleset = null;
			self::$memo           = array();
			wp_set_current_user( (int) $prev_user );
		}
	}

	/**
	 * The approval is NOT spent: put the row back where it came from and tell
	 * Aura to try again (Ruling P7/P8). Only ever called for a call the log
	 * shows did not run.
	 *
	 * @param string $ref     Ref.
	 * @param string $code    The refusal's code.
	 * @param string $message The refusal's message.
	 * @return array
	 */
	private static function give_back( $ref, $code, $message ) {
		$out = array(
			'ok'     => false,
			'reason' => 'retry_later',
			'code'   => $code,
			'error'  => $message,
		);
		if ( ! Aura_Worker_Door_Holds::unclaim( $ref ) && null !== Aura_Worker_Door_Holds::get_claimed( $ref ) ) {
			// The hold could not be put back and the claimed row still stands:
			// it is the only record of this attempt, and the reconciler owns
			// it from here. Say so — Aura must not expect this ref to answer a
			// second approval.
			$out['claim_retained'] = true;
		}
		return $out;
	}

	/**
	 * A DEFINITIVE refusal of a call that never ran: retrying it changes
	 * nothing (the ability is unmapped, the target stopped being one Aura can
	 * snapshot), so the hold is spent rather than parked for a reconciler that
	 * would only write a second, false `interrupted`.
	 *
	 * @param string $ref     Ref.
	 * @param string $code    The refusal's code.
	 * @param string $message The refusal's message.
	 * @return array
	 */
	private static function spend_refusal( $ref, $code, $message ) {
		Aura_Worker_Door_Holds::release( $ref );
		return array(
			'ok'     => false,
			'reason' => 'refused',
			'code'   => $code,
			'error'  => $message,
		);
	}

	/**
	 * Rolling 30-day counters in the Aura_Worker_Rules bucket style
	 * (`aura_worker_door_c_<name>_h<hour>`), read by governor_block() (Task 11).
	 *
	 * The statement, and the two cache evictions after it, are the rules
	 * counters' shape exactly (class-aura-worker-rules.php `bump()`): one
	 * atomic create-or-increment, then `notoptions` evicted because a reader
	 * that missed this bucket before its first bump listed the name in core's
	 * negative cache and would otherwise go on reading it as absent.
	 *
	 * @param string $name log_ungoverned|unobserved|hook_missed|unknown_ability.
	 */
	private static function bump_counter( $name ) {
		global $wpdb;
		$option = 'aura_worker_door_c_' . $name . '_h' . (int) floor( time() / HOUR_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no') ON DUPLICATE KEY UPDATE option_value = option_value + 1", $option ) );
		wp_cache_delete( $option, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * The last 30 days of one bump_counter() name, in ONE query — the shape
	 * `Aura_Worker_Rules::sweep_options()`'s value-parsed branch already reads
	 * by (name, value) pairs under a single LIKE, with the cutoff applied in
	 * PHP rather than in SQL. That, not `Aura_Worker_Rules::count_24h()`'s own
	 * 24 `get_option()` calls, is the shape to follow here: `bump_counter()`'s
	 * hour suffix is NOT zero-padded (unlike `Aura_Worker_Rules::bucket_name()`),
	 * so a string bound could never order it, and 30 days is 720 buckets — 720
	 * `get_option()` calls is the exact cost this method exists to avoid.
	 *
	 * `ctype_digit()` on the suffix, the same defensive read
	 * `Aura_Worker_Door_Log::ROW_REGEXP` applies to log rows sharing a prefix
	 * with non-numeric options: nothing today shares this prefix with a
	 * non-numeric suffix, but a row that somehow did must be skipped rather
	 * than miscounted.
	 *
	 * @param string   $name log_ungoverned|unobserved|hook_missed|unknown_ability.
	 * @param int|null $now  Unix time; injected for tests.
	 * @return int
	 */
	public static function count_30d( $name, $now = null ) {
		global $wpdb;
		$now    = null === $now ? time() : (int) $now;
		$oldest = (int) floor( ( $now - 30 * DAY_IN_SECONDS ) / HOUR_IN_SECONDS );
		$prefix = 'aura_worker_door_c_' . $name . '_h';
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( $prefix ) . '%'
			),
			ARRAY_A
		);
		$sum = 0;
		foreach ( (array) $rows as $row ) {
			if ( ! isset( $row['option_name'], $row['option_value'] ) ) {
				continue;
			}
			$suffix = substr( (string) $row['option_name'], strlen( $prefix ) );
			if ( '' === $suffix || ! ctype_digit( $suffix ) ) {
				continue; // not one of THIS name's hour buckets
			}
			if ( (int) $suffix >= $oldest ) {
				$sum += (int) $row['option_value'];
			}
		}
		return $sum;
	}

	/**
	 * The `elementor.governor` block of `audit_mcp_exposure` (Task 11): what
	 * THIS site's door log and hold queue say, for the fleet rollup — a site
	 * whose log is full, whose hold queue is full, or whose seam never
	 * verified, without polling `/status`.
	 *
	 * `{ active: false }` ALONE when this site carries no door (Ruling P6):
	 * the caller already gates the whole `elementor` block on manage_options,
	 * and there is nothing else honest to report about a door that is not
	 * there. `seam` is reported exactly as `verify_coverage()` last left it —
	 * `unchecked` when that has not run in this request is an honest answer,
	 * not a gap; the audit never forces a coverage check of its own.
	 *
	 * @return array
	 */
	public static function governor_block() {
		if ( ! self::active() ) {
			return array( 'active' => false );
		}
		$epoch = Aura_Worker_Door_Log::epoch();
		$held  = Aura_Worker_Door_Holds::count(); // read once — held_count and queue_full are the same fact
		return array(
			'active'              => true,
			'epoch'               => '' === $epoch ? null : $epoch,
			'seam'                => self::$seam,
			'door'                => Aura_Worker_Door_Log::is_closed() ? 'closed' : 'open',
			'held_count'          => $held,
			'log_unacked'         => Aura_Worker_Door_Log::count_unacked(),
			'log_ungoverned_30d'  => self::count_30d( 'log_ungoverned' ),
			'unobserved_30d'      => self::count_30d( 'unobserved' ),
			'hook_missed_30d'     => self::count_30d( 'hook_missed' ),
			'unknown_ability_30d' => self::count_30d( 'unknown_ability' ),
			'queue_full'          => $held >= Aura_Worker_Door_Holds::CAP,
			'log_full'            => Aura_Worker_Door_Log::full_report(),
		);
	}

	/** @return WP_Error */
	private static function log_full_error() {
		return new WP_Error( 'aura_log_full', __( "Aura has not acknowledged this site's door log; the door is closed to writes until it does", 'digitizer-site-worker' ), array( 'status' => 503 ) );
	}

	/* ------------------------------------------------------------------ */
	/* Seams the later tasks fill                                          */
	/* ------------------------------------------------------------------ */

	/** @var array|null the request in flight: seq, slug, kind, created[], suspected[], collateral[] */
	private static $request = null;

	/**
	 * Capture the target before a write.
	 *
	 * One envelope per call, whatever the target's shape: a page or component
	 * is its own post + PAGE_META_KEYS; the design system is the kit post,
	 * EVERY `e_global_class` and EVERY `e_default_style` post, and the kit +
	 * class/style meta keys, in ONE `posts` envelope carrying `cpts` — so its
	 * restore also deletes a class or style the write ADDED (Ruling R3).
	 *
	 * @param string $slug    Ability.
	 * @param array  $touches Touches.
	 * @param array  $input   Input.
	 * @return array { success, snapshot?, error?, code?, creation? }
	 */
	private static function snapshot_for( $slug, array $touches, array $input ) {
		if ( null !== self::$snapshotter ) {
			return call_user_func( self::$snapshotter, $slug, $touches, $input );
		}
		$snaps = new Aura_Worker_Snapshots();
		$door  = array(
			'seq'     => isset( self::$request['seq'] ) ? self::$request['seq'] : null,
			'ability' => $slug,
		);
		switch ( isset( self::WRITE_TABLE[ $slug ] ) ? self::WRITE_TABLE[ $slug ] : '' ) {
			case 'page':
				return $snaps->snapshot_posts(
					array( (int) $touches[0]['id'] ),
					self::PAGE_META_KEYS,
					array( 'kind_label' => 'page', 'door' => $door )
				);
			case 'component':
				$id = isset( $input['id'] ) && is_numeric( $input['id'] ) ? (int) $input['id'] : 0;
				if ( $id > 0 && self::CPT_COMPONENT !== get_post_type( $id ) ) {
					// Never snapshot it as something else: an envelope that named
					// the wrong shape would restore the wrong thing.
					return array(
						'success' => false,
						'code'    => 'aura_target_unattributed',
						'error'   => 'not a component post: ' . $id,
					);
				}
				if ( $id > 0 ) {
					return $snaps->snapshot_posts(
						array( $id ),
						self::PAGE_META_KEYS,
						array( 'kind_label' => 'component', 'door' => $door )
					);
				}
				// A manage-component that names no id is CREATING one: nothing
				// exists to capture yet, so the creation path captures after.
				return array( 'success' => true, 'snapshot' => array( 'id' => null ), 'creation' => true );
			case 'design_system':
				$ids  = array_merge(
					array( self::kit_id() ),
					get_posts( array( 'post_type' => self::CPT_GLOBAL_CLASS, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ),
					get_posts( array( 'post_type' => self::CPT_DEFAULT_STYLE, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) )
				);
				$keys = array_merge(
					self::KIT_META_KEYS,
					array(
						'_elementor_global_class_id',
						'_elementor_global_class_data',
						'_elementor_global_class_data_preview',
						'_elementor_global_class_edited',
						'_elementor_default_style_tag',
						'_elementor_default_style_data',
						'_elementor_version',
					)
				);
				return $snaps->snapshot_posts(
					array_values( array_unique( array_map( 'intval', $ids ) ) ),
					$keys,
					array(
						'cpts'       => array( self::CPT_GLOBAL_CLASS, self::CPT_DEFAULT_STYLE ),
						'kind_label' => 'design_system',
						'door'       => $door,
					)
				);
		}
		return array( 'success' => false, 'error' => 'no snapshot kind for ' . $slug );
	}

	/**
	 * The active kit's post id — 0 when unknown, which makes the capture fail,
	 * which refuses the write. A design-system envelope without the kit is not
	 * a rollback point.
	 *
	 * @return int
	 */
	private static function kit_id() {
		if ( isset( $GLOBALS['_sa_kit_id'] ) ) {
			return (int) $GLOBALS['_sa_kit_id']; // test seam; never written by production code
		}
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->kits_manager ) ) {
			return (int) \Elementor\Plugin::$instance->kits_manager->get_active_id();
		}
		return 0;
	}

	/**
	 * Is this call CREATING its target? A `create-page` always is; a
	 * `manage-component` that names no id is too — both have nothing to
	 * capture before the write and take the creation path instead.
	 *
	 * @param string $slug  Ability.
	 * @param array  $input Input.
	 * @return bool
	 */
	private static function is_creating( $slug, array $input ) {
		$kind = isset( self::WRITE_TABLE[ $slug ] ) ? self::WRITE_TABLE[ $slug ] : null;
		if ( 'page_create' === $kind ) {
			return true;
		}
		return 'component' === $kind && ! ( isset( $input['id'] ) && is_numeric( $input['id'] ) && (int) $input['id'] > 0 );
	}

	/**
	 * The post types a creation may legitimately insert — the set both
	 * witnesses judge against: the insert hook files anything else under
	 * `other_inserts`, and the watermark diff never looks outside it.
	 *
	 * @param string $slug  Ability.
	 * @param array  $input Input.
	 * @return string[]
	 */
	private static function expected_types( $slug, array $input ) {
		if ( 'elementor/manage-component' === $slug ) {
			return array( self::CPT_COMPONENT );
		}
		$t = isset( $input['post_type'] ) && is_string( $input['post_type'] ) && '' !== $input['post_type'] ? sanitize_key( $input['post_type'] ) : 'page';
		return array( '' === $t ? 'page' : $t );
	}

	/* ------------------------------------------------------------------ */
	/* A restore is itself a governed write                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Before a door envelope is restored: capture the CURRENT state the same
	 * way, so the restore is itself a Change that can be undone.
	 *
	 * @param array $record The envelope being restored.
	 * @return array { success, snapshot?, error? }
	 */
	public static function pre_restore_capture( array $record ) {
		$snaps = new Aura_Worker_Snapshots();
		$door  = array( 'restore_of' => (string) ( isset( $record['id'] ) ? $record['id'] : '' ) );
		switch ( (string) ( isset( $record['door_kind'] ) ? $record['door_kind'] : '' ) ) {
			case 'design_system':
				// The CURRENT set, not the old envelope's targets: a class or
				// style added since would be deleted by the restore's set
				// semantics, and only a capture that enumerated it can bring it
				// back.
				return self::snapshot_for(
					'elementor/manage-classes',
					array( array( 'type' => 'design_system', 'id' => '*' ) ),
					array()
				);
			case 'page':
			case 'component':
			case 'creation_restore':
				$opts = array( 'kind_label' => (string) $record['door_kind'], 'door' => $door );
				if ( ! empty( $record['cpts'] ) ) {
					$opts['cpts'] = $record['cpts'];
				}
				// A record missing its targets captures nothing, and a capture of
				// nothing refuses the restore — which is the right answer for an
				// envelope that cannot say what it covered.
				return $snaps->snapshot_posts(
					(array) ( isset( $record['targets'] ) ? $record['targets'] : array() ),
					(array) ( isset( $record['keys'] ) ? $record['keys'] : array() ),
					$opts
				);
			case 'creation':
				// Undoing a creation trashes the created posts; capturing them
				// first is what lets the trash itself be undone.
				return $snaps->snapshot_posts(
					(array) ( isset( $record['created_post_ids'] ) ? $record['created_post_ids'] : array() ),
					self::PAGE_META_KEYS,
					array( 'kind_label' => 'creation_restore', 'door' => $door )
				);
		}
		return array( 'success' => true, 'snapshot' => null ); // not a door envelope: nothing to log
	}

	/**
	 * Reserve the door entry for a restore BEFORE anything is captured or
	 * written — the same admission every governed write gets. Logging after
	 * the fact would let a restore run unrecorded when the log was closed or
	 * the insert failed.
	 *
	 * @param array  $record   The envelope about to be restored.
	 * @param string $aura_ref Aura's correlation id for this restore.
	 * @return int|WP_Error seq, or aura_log_full / aura_log_failed.
	 */
	public static function open_restore_entry( array $record, $aura_ref = '' ) {
		if ( Aura_Worker_Door_Log::is_closed() ) {
			Aura_Worker_Door_Log::bump_refused();
			return self::log_full_error();
		}
		$actor = self::actor();
		$seq   = Aura_Worker_Door_Log::open_pending(
			array(
				'ability'    => 'aura/restore',
				'actor'      => is_wp_error( $actor ) ? array( 'user_id' => 0, 'login' => 'aura', 'via' => 'rest' ) : $actor,
				'touches'    => array(),
				'verdict'    => 'allow',
				'restore_of' => (string) ( isset( $record['id'] ) ? $record['id'] : '' ),
				// Aura's own correlation id for this restore (its AgentAction's
				// doorRef), echoed on the entry so ingestion patches THAT row
				// instead of minting a second one.
				'ref'        => '' === (string) $aura_ref ? null : preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $aura_ref ),
			)
		);
		if ( is_wp_error( $seq ) ) {
			return $seq;
		}
		// Admission: the row is the reservation. Count, back out above the bound.
		if ( Aura_Worker_Door_Log::count_unacked() > Aura_Worker_Door_Log::MAX_UNACKED ) {
			Aura_Worker_Door_Log::discard( $seq );
			Aura_Worker_Door_Log::close();
			Aura_Worker_Door_Log::bump_refused();
			return self::log_full_error();
		}
		if ( ! Aura_Worker_Door_Log::admit( $seq ) ) {
			return new WP_Error( 'aura_log_failed', 'The door log could not record this restore; it was not run.', array( 'status' => 503 ) );
		}
		return $seq;
	}

	/**
	 * Settle the reserved restore entry.
	 *
	 * @param int        $seq     The reserved entry.
	 * @param array|null $pre     Pre-restore envelope (or null).
	 * @param array      $outcome restore()'s result.
	 */
	public static function settle_restore_entry( $seq, $pre, array $outcome ) {
		Aura_Worker_Door_Log::settle(
			(int) $seq,
			array(
				'result'      => empty( $outcome['success'] ) ? 'failed' : 'ok',
				'snapshot_id' => is_array( $pre ) ? (string) $pre['id'] : null,
				'trashed'     => isset( $outcome['trashed'] ) ? $outcome['trashed'] : null,
				'error'       => isset( $outcome['error'] ) ? $outcome['error'] : null,
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Creation: the two witnesses, the mutex, and compensation            */
	/* ------------------------------------------------------------------ */

	/**
	 * WITNESS 1 — core's own insert hook, at priority 1.
	 *
	 * Every id is written to the pending row AS IT HAPPENS, one write per
	 * insert, before the callback that made it can return: a request that dies
	 * mid-write still leaves Aura the ids it has to undo. An `$update` is not a
	 * creation, and an insert outside the expected set is recorded apart, in
	 * `other_inserts` — never handed to a `creation` envelope, whose restore
	 * would trash it.
	 *
	 * @param int    $post_id Post id.
	 * @param object $post    Post.
	 * @param bool   $update  Whether this was an update.
	 */
	public static function observe_insert( $post_id, $post, $update ) {
		if ( null === self::$request || empty( self::$request['creating'] ) || $update ) {
			return;
		}
		$id   = (int) $post_id;
		$type = is_object( $post ) ? (string) ( $post->post_type ?? '' ) : (string) get_post_type( $id );
		$seq  = (int) self::$request['seq'];
		$row  = Aura_Worker_Door_Log::get( $seq );
		if ( null === $row ) {
			return;
		}
		if ( in_array( $type, (array) self::$request['expected'], true ) ) {
			self::$request['created'][] = $id;
			$ids                        = array_values( array_unique( array_merge( (array) ( $row['created_post_ids'] ?? array() ), array( $id ) ) ) );
			Aura_Worker_Door_Log::patch_pending( $seq, array( 'created_post_ids' => $ids ) );
		} else {
			$others = array_values( array_unique( array_merge( (array) ( $row['other_inserts'] ?? array() ), array( $id ) ) ) );
			Aura_Worker_Door_Log::patch_pending( $seq, array( 'other_inserts' => $others ) );
		}
	}

	/**
	 * Elementor deleting a global class REWRITES every page that used it, and
	 * those pages are not this call's target — they are its collateral. They
	 * are captured HERE, at priority 1, so the envelope exists (and its id is
	 * on the row) before Elementor's own handler at priority 10 touches them.
	 *
	 * A capture that fails THROWS: the governor's catch turns that into
	 * `aura_governor_error`, which refuses the whole call before the rewrite —
	 * the class posts themselves are still restorable from the design_system
	 * envelope taken before the write.
	 *
	 * @param array $deleted_class_ids Class ids removed.
	 * @param array $affected_post_ids Posts they were on.
	 * @throws RuntimeException When the capture, or the record of it, fails.
	 */
	public static function capture_class_cleanup( $deleted_class_ids, $affected_post_ids ) {
		if ( null === self::$request || 'design_system' !== ( self::$request['kind'] ?? '' ) ) {
			return;
		}
		$ids = array_values( array_filter( array_map( 'intval', (array) $affected_post_ids ) ) );
		if ( empty( $ids ) ) {
			return;
		}
		$snaps = new Aura_Worker_Snapshots();
		$env   = $snaps->snapshot_posts(
			$ids,
			array( '_elementor_data' ),
			array(
				'kind_label' => 'page',
				'door'       => array(
					'seq'           => self::$request['seq'],
					'collateral_of' => self::$request['seq'],
				),
			)
		);
		if ( empty( $env['success'] ) ) {
			throw new RuntimeException( esc_html( 'collateral capture failed: ' . (string) ( $env['error'] ?? '' ) ) ); // the wrapper's catch refuses the call before Elementor's cleanup runs
		}
		self::$request['collateral'][] = (string) $env['snapshot']['id'];
		// Durable BEFORE Elementor's cleanup handler (priority 10) rewrites the
		// pages: an interrupted request must still name the envelope that can
		// undo them.
		if ( ! Aura_Worker_Door_Log::patch_pending( (int) self::$request['seq'], array( 'collateral_snapshot_ids' => self::$request['collateral'] ) ) ) {
			throw new RuntimeException( 'collateral id could not be recorded' );
		}
	}

	/**
	 * One creation at a time per site — because the watermark diff cannot tell
	 * two concurrent creations' posts apart.
	 *
	 * Taken with insert_unique(), NOT add_option(): core's add_option() ends in
	 * an `INSERT … ON DUPLICATE KEY UPDATE`, which is an upsert, so both racers
	 * would "take" it (Ruling P5). The reconciler clears a mutex older than
	 * CLAIM_STALE_MS, which is what `started_at` is for.
	 *
	 * @param int $seq Log seq.
	 * @return true|WP_Error `aura_creation_busy` (503) when another creation holds it.
	 */
	private static function take_creation_mutex( $seq ) {
		$taken = Aura_Worker_Door_Log::insert_unique(
			self::CREATING,
			array(
				'seq'        => (int) $seq,
				'started_at' => gmdate( 'c' ),
			)
		);
		if ( $taken ) {
			// OWNERSHIP: only the request that inserted the row may delete it.
			self::$request['mutex_held'] = true;
		}
		if ( ! $taken ) {
			return new WP_Error(
				'aura_creation_busy',
				'Another creation is in progress on this site; retry in 30 seconds.',
				array(
					'status'      => 503,
					'retry_after' => 30,
				)
			);
		}
		return true;
	}

	/**
	 * WITNESS 2 — the high-water mark of wp_posts before the write, so an
	 * insert core's hook never fired for can still be found afterwards.
	 *
	 * @param int $seq Log seq.
	 * @return bool the watermark is on the row; false ⇒ the caller refuses the
	 *              write: a creation whose watermark is not durable could not
	 *              be reconstructed by anyone.
	 */
	private static function stamp_watermark( $seq ) {
		global $wpdb;
		$max = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		self::$request['watermark'] = $max;
		return Aura_Worker_Door_Log::patch_pending(
			$seq,
			array(
				'post_watermark'   => $max,
				'started_at'       => gmdate( 'c' ),
				'created_post_ids' => array(),
				'expected_types'   => self::$request['expected'],
			)
		);
	}

	/**
	 * WITNESS 2, read: every post above the mark, of an expected type,
	 * authored by the actor. Both filters are load-bearing — a post of
	 * another type, or another author's, is not this call's.
	 *
	 * Shared by finish_creation() (which diffs against the mark on
	 * `self::$request`) and the reconciler (which diffs against the mark on
	 * the ROW, minutes or hours later, in a request that never made it).
	 *
	 * `$until` bounds it in TIME, and only the reconciler passes it (Ruling
	 * P9(b)). The live path diffs across ONE request, so id and author are
	 * enough; the stale path diffs across everything between the watermark
	 * and the first `/status` poll that noticed — ten minutes at best, days on
	 * an unpolled site — and without an upper bound every page the same
	 * WordPress user made by hand in that window is attributed to a call that
	 * never made them.
	 *
	 * @param int         $mark     The watermark: the highest post id before the write.
	 * @param string[]    $types    Post types the creation may legitimately have inserted.
	 * @param int         $actor_id The actor the creation ran as.
	 * @param string|null $until    GMT datetime; ids created after it are not this call's.
	 * @return int[]
	 */
	private static function watermark_diff( $mark, array $types, $actor_id, $until = null ) {
		global $wpdb;
		if ( empty( $types ) ) {
			return array();
		}
		$in   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$sql  = "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ($in) AND post_author = %d";
		$args = array_merge( array( (int) $mark ), $types, array( (int) $actor_id ) );
		if ( null !== $until ) {
			$sql   .= ' AND post_date_gmt <= %s';
			$args[] = (string) $until;
		}
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * After the inner callback of a creating ability: union the two witnesses,
	 * store the `creation` envelope, and — when it cannot be stored —
	 * compensate, because the write already happened.
	 *
	 * @param int   $seq    Log seq.
	 * @param mixed $result Inner result.
	 * @param bool  $failed Whether the ability reported failure.
	 * @return array|WP_Error terminal fields, or aura_snapshot_failed (compensated).
	 */
	private static function finish_creation( $seq, $result, $failed ) {
		$types    = (array) self::$request['expected'];
		$actor_id = (int) ( self::$request['actor_id'] ?? 0 );
		// NO watermark means this request never reached the write — the mutex
		// or the stamp itself failed, and execute()'s catch still finishes the
		// creation. There is no mark to diff against, and treating that as
		// "everything above id 0" would attribute every page this user ever
		// made to the call — and then TRASH them if the envelope could not be
		// stored. A stamped 0 (an empty posts table) is a real mark and does
		// diff; only its ABSENCE means "nothing to compare".
		$diff   = array();
		if ( isset( self::$request['watermark'] ) ) {
			$diff = self::watermark_diff( (int) self::$request['watermark'], $types, $actor_id );
		}
		$hooked   = array_map( 'intval', (array) self::$request['created'] );
		$missed   = array_values( array_diff( $diff, $hooked ) );
		$created  = array_values( array_unique( array_merge( $hooked, $missed ) ) );
		$fields   = array( 'created_post_ids' => $created );
		if ( ! empty( $missed ) ) {
			$fields['observed_by_watermark'] = $missed;
			$fields['hook_missed']           = count( $missed );
			self::bump_counter( 'hook_missed' );
		}
		if ( empty( $created ) && ! $failed ) {
			self::bump_counter( 'unobserved' ); // a success that inserted nothing the witnesses could see
		}
		$named = is_array( $result ) && isset( $result['id'] ) && is_numeric( $result['id'] ) ? (int) $result['id'] : 0;
		if ( $named > 0 && ! in_array( $named, $created, true ) ) {
			$fields['unattributed_result'] = $named;
		}
		try {
			if ( empty( $created ) ) {
				return $fields; // nothing was inserted: nothing to undo; the mutex is released in the finally
			}
			// A callback that inserted and THEN reported failure still left
			// posts behind: they get an envelope like any creation, or are
			// compensated when the envelope cannot be stored — `$failed`
			// decides the entry's result, never whether the rollback exists.
			$snaps = new Aura_Worker_Snapshots();
			$env   = $snaps->snapshot_creation(
				$created,
				(string) $types[0],
				array(
					'seq'     => $seq,
					'ability' => self::$request['slug'],
				)
			);
			if ( empty( $env['success'] ) ) {
				// Compensate: the write happened and cannot be made restorable.
				$comp = self::compensate( $created );
				$left = $comp['uncompensated'];
				Aura_Worker_Door_Log::settle(
					$seq,
					array_merge(
						$fields,
						array(
							'result' => 'failed',
							'reason' => 'snapshot_failed',
						),
						$comp
					)
				);
				$msg = empty( $left )
					? 'Aura could not record a rollback for what this call created; the creation was undone. '
					: sprintf( 'Aura could not record a rollback for what this call created, and could not undo post(s) %s — check the site. ', implode( ', ', $left ) );
				return new WP_Error(
					'aura_snapshot_failed',
					$msg . (string) ( $env['error'] ?? '' ),
					array(
						'status'        => 503,
						'uncompensated' => $left,
					)
				);
			}
			$fields['snapshot_id'] = (string) $env['snapshot']['id'];
			return $fields;
		} finally {
			self::release_creation_mutex();
		}
	}

	/**
	 * Release the creation mutex — but ONLY if this request is the one that
	 * took it. A request whose mutex insert never landed (it lost the race, or
	 * the statement threw) must not delete the row: that row is another
	 * creation's, and deleting it would let a third call in beside it
	 * (round 1).
	 */
	private static function release_creation_mutex() {
		if ( null === self::$request || empty( self::$request['mutex_held'] ) ) {
			return;
		}
		delete_option( self::CREATING );
		self::$request['mutex_held'] = false;
	}
}
