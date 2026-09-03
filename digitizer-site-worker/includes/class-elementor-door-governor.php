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
	/** The rolling counter buckets' shared prefix: `<prefix><name>_h<hour>`. */
	const COUNTER_PREFIX = 'aura_worker_door_c_';

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
	 * ignored and the log is served from the beginning — the same answer
	 * which is also what an absent `door_epoch` gets.
	 *
	 * A cursor from THIS epoch that is above every row AND above the ack
	 * floor is impossible: the log was rewound (a restore, a `wp_options`
	 * roll-back) under the same epoch. It is REPORTED — `rewind.detected`
	 * with the `top` Aura should have been at — and the cursor is ignored so
	 * the rows are served from 0. It is NOT acted on here.
	 *
	 * THIS ENDPOINT NEVER MUTATES (Ruling P20). It used to rotate the epoch
	 * itself, which handed anyone holding the site token a way to invalidate
	 * every ack Aura was about to send: read the epoch, ask for an oversized
	 * `door_after` between Aura's poll and its `/door/ack`, and the ack then
	 * names an epoch the site has moved past and is ignored — repeat, and the
	 * unacked rows climb to MAX_UNACKED and close the write door, with no
	 * grant anywhere. Rotation is Aura's decision, taken on `rewind.detected`
	 * through the grant-gated `POST /aura/v1/door/rotate`.
	 *
	 * @param int    $after Aura's cursor.
	 * @param string $epoch The epoch that cursor belongs to; '' ⇒ served from 0.
	 * @return array|null { epoch, seam, door, held, interrupted, rewind, log, log_floor, log_unacked, log_full }
	 */
	public static function status_fragment( $after = 0, $epoch = '' ) {
		if ( ! self::active() ) {
			return null;
		}
		$after  = (int) $after;
		$site   = Aura_Worker_Door_Log::epoch();
		$rewind = null;
		if ( (string) $epoch !== $site ) {
			$after = 0;
		} else {
			$top = max( Aura_Worker_Door_Log::highest_row_seq(), Aura_Worker_Door_Log::floor() );
			if ( $after > $top ) {
				$rewind = array(
					'detected' => true,
					'top'      => (int) $top,
				);
				$after  = 0; // ignored, never acted on: the read reports, Aura decides
			}
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
			'door'        => self::door_state(),
			'held'        => Aura_Worker_Door_Holds::listing(),
			'interrupted' => $interrupted,
			// The log was rewound under this epoch (or it was not: null).
			// Aura answers a detection by calling POST /aura/v1/door/rotate
			// with a grant, then re-fetching under the new epoch.
			'rewind'      => $rewind,
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
	 * 5. Retention: door envelopes older than 30 days (Ruling R6), and the
	 *    counter buckets past the same window — at most once every
	 *    PRUNE_INTERVAL_S, because both sweeps read every row/file there is
	 *    and this runs on the site's hottest endpoint (Ruling P9(a)).
	 *
	 * A claim is released ONLY once its evidence is durable. Anything else
	 * loses the one record that a replay may have mutated the site.
	 *
	 * @param int|null $now Unix time; defaults to now.
	 * @return array{ interrupted: int, discarded: int, settled_claims: int, swept: int, pruned: int, pruned_counters: int }
	 */
	public static function reconcile( $now = null ) {
		$now = null === $now ? time() : (int) $now;
		$out = array(
			'interrupted'     => 0,
			'discarded'       => 0,
			'settled_claims'  => 0,
			'swept'           => 0,
			'pruned'          => 0,
			'pruned_counters' => 0,
		);

		$out['swept'] = (int) Aura_Worker_Door_Holds::sweep( $now, self::CLAIM_STALE_MS );

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
			$out['pruned']          = (int) ( new Aura_Worker_Snapshots() )->prune_older_than( 30, Aura_Worker_Snapshots::DOOR_KINDS );
			$out['pruned_counters'] = self::prune_counters( $now );
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
					return;
				}
				// The settle did not land. If the entry is TERMINAL now, the
				// owning request settled it in the window between the read
				// above and this write (Ruling P27) — its verdict stands, and
				// the entry Aura needs exists, so this claim is FINISHED. The
				// branch above answers the same case when the row was already
				// terminal at the read; this one answers it when it turned
				// terminal underneath. Keeping the claim would strand the hold
				// on every poll for ever.
				if ( Aura_Worker_Door_Log::is_terminal( $seq ) ) {
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
	 * The creation half of an interrupted row: partition the two witnesses
	 * (the ids the insert hook recorded, and the watermark diff), store the
	 * `creation` envelope over the proven ones, and compensate those when it
	 * cannot be stored — the posts exist either way, and one that cannot be
	 * made restorable is undone instead.
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
		// The same partition as the live path, through the same helper: a
		// dead request left no result to name anything, so only witness 1
		// proves. (Ruling P11.)
		$part     = self::partition_created( $hooked, $diff, 0 );
		$created  = $part['proven'];
		$missed   = array_values( array_diff( $diff, $hooked ) );
		$fields   = array( 'created_post_ids' => $created );
		if ( ! empty( $missed ) ) {
			$fields['observed_by_watermark'] = $missed;
			$fields['hook_missed']           = count( $missed );
			self::bump_counter( 'hook_missed' );
		}
		if ( ! empty( $part['unproven'] ) ) {
			$fields['unproven'] = $part['unproven'];
		}
		if ( empty( $created ) ) {
			return $fields; // nothing proven was inserted: nothing to undo
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
		// Compensate what this call PROVABLY made — here, exactly what the
		// insert hook witnessed (Ruling P9(b), P11). A diff-only id is the
		// watermark's suspicion, nowhere near good enough to trash somebody's
		// page on; it is already listed under `unproven`, never folded into
		// `uncompensated`, which means "this call made it and it could not be
		// undone".
		$fields = array_merge( $fields, array( 'reason' => 'snapshot_failed' ), self::compensate( $created ) );
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
	 * The clear is a DELETE FENCED on the bytes this call read — the shape
	 * Aura_Worker_Door_Holds uses at both ends of its own mutex's life, and
	 * the rule for every mutex delete that is not the owner's own release
	 * (Ruling P5). Judging staleness and then deleting unconditionally is two
	 * statements with a window between them, and a creation starting in that
	 * window takes the row for itself: an unconditional delete then closes a
	 * LIVE creation's mutex, and a second creation runs beside it. The row is
	 * read RAW, never through get_option(), because the fence compares bytes.
	 *
	 * @param int $now Unix time.
	 */
	private static function clear_stale_creation_mutex( $now ) {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::CREATING ) );
		if ( null === $raw ) {
			return;
		}
		$mutex = maybe_unserialize( $raw );
		if ( ! is_array( $mutex ) ) {
			return;
		}
		$started = strtotime( (string) ( isset( $mutex['started_at'] ) ? $mutex['started_at'] : '' ) );
		if ( false === $started || $started <= $now - (int) floor( self::CLAIM_STALE_MS / 1000 ) ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::CREATING, (string) $raw ) );
			wp_cache_delete( self::CREATING, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
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
		} catch ( Aura_Worker_Door_Witness_Exception $e ) {
			// A creation aborted BY the governor because the row could not
			// witness what was just created (Ruling P26). Not a governor
			// failure and not a rule refusal: the write happened, and the
			// whole point of unwinding here is that the insert hook still
			// holds the id finish_creation() needs. It runs from that
			// witness — envelope stored ⇒ the post is restorable; store
			// failed ⇒ compensation trashes it — and the entry says which.
			$seq = isset( self::$request['seq'] ) ? (int) self::$request['seq'] : 0;
			// The IN-MEMORY witness, read before anything clears it: in this
			// branch the row is precisely what could not be read or written,
			// so the answer must not be built from one.
			$created = isset( self::$request['created'] ) ? array_values( array_map( 'intval', (array) self::$request['created'] ) ) : array();
			$fields  = array(
				'result'       => 'failed',
				'reason'       => 'witness_unrecorded',
				'error'        => $e->getMessage(),
				'may_have_run' => true,
			);
			if ( $seq > 0 ) {
				if ( ! empty( self::$request['creating'] ) && empty( self::$request['creation_done'] ) ) {
					try {
						$creation = self::finish_creation( $seq, null, true );
						if ( ! is_wp_error( $creation ) ) {
							$fields = array_merge( $fields, $creation );
						} else {
							// finish_creation() already settled its own
							// compensation onto the row, so the settle below
							// is refused (Ruling P27) and annotate() carries
							// this reason onto the verdict that stands.
							$fields['reason'] = 'witness_unrecorded_then_compensated';
						}
					} catch ( \Throwable $creation_error ) {
						self::release_creation_mutex();
						$fields['creation_error'] = $creation_error->getMessage();
					}
				}
				if ( ! Aura_Worker_Door_Log::settle( $seq, $fields ) ) {
					// Already terminal — finish_creation()'s own compensation
					// settle, or another request's. The result it wrote is
					// final; this evidence is added beside it.
					Aura_Worker_Door_Log::annotate( $seq, $fields );
				}
				self::$request = null;
			}
			return new WP_Error(
				'aura_log_failed',
				'A page was created and this site could not record which one; the creation was rolled back or made restorable — check the site before retrying. ' . $e->getMessage(),
				array(
					'status'           => 503,
					'may_have_run'     => true,
					'seq'              => $seq,
					'created_post_ids' => $created,
					'snapshot_id'      => isset( $fields['snapshot_id'] ) ? $fields['snapshot_id'] : null,
				)
			);
		} catch ( Aura_Worker_Door_Blocked_Exception $e ) {
			// A DELIBERATE refusal, not a governor failure (Ruling P22): a
			// rule that only became applicable once the write was underway —
			// today, a class deletion whose collateral pages a rule protects.
			// It settles `refused` under its own reason and answers 403, the
			// same code and envelope a block before the call gets.
			//
			// `may_have_run` is TRUE all the same, and that is not a
			// contradiction: Elementor deletes the class row inside its own
			// callback and only THEN fires the cleanup action this refusal
			// throws from, so the site did change. What the refusal bought is
			// the rewrite of the protected pages, which had not happened yet.
			// The pre-write envelope is named so the operator can undo the
			// half that did.
			$seq  = isset( self::$request['seq'] ) ? (int) self::$request['seq'] : 0;
			$snap = isset( self::$request['snapshot_id'] ) ? (string) self::$request['snapshot_id'] : '';
			if ( $seq > 0 ) {
				Aura_Worker_Door_Log::settle(
					$seq,
					array(
						'result'             => 'refused',
						'reason'             => 'collateral_blocked',
						'verdict'            => 'block',
						'rule_key'           => $e->rule_key(),
						'rule'               => self::rule_evidence( $e->rule() ),
						'collateral_blocked' => $e->ids(),
						'may_have_run'       => true,
						'snapshot_id'        => '' === $snap ? null : $snap,
					)
				);
				self::$request = null;
			}
			$blocked = Aura_Worker_Rules::blocked_result( $slug, $e->rule() );
			return new WP_Error(
				'aura_rule_blocked',
				$blocked['error'],
				array(
					'status'          => 403,
					'rule'            => $blocked['rule'],
					'may_have_run'    => true,
					'restorable_from' => '' === $snap ? null : $snap,
				)
			);
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
				if ( ! Aura_Worker_Door_Log::settle( $seq, $fields ) ) {
					// Already terminal — finish_creation()'s own compensation
					// settle, or another request's. The result stands; the
					// throw is recorded beside it (Ruling P27).
					Aura_Worker_Door_Log::annotate( $seq, $fields );
				}
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
			$snapshot_id                  = (string) $snap['snapshot']['id'];
			self::$request['snapshot_id'] = $snapshot_id; // what a mid-write refusal can point the operator back to
			if ( ! Aura_Worker_Door_Log::patch_pending( $seq, array( 'snapshot_id' => $snapshot_id ) ) ) { // the id lands on the row BEFORE the write, durably
				// SETTLE it `refused` — this request KNOWS the callback never
				// ran, and a terminal row is the only way to say so (Ruling
				// P14). Left pending, the row was the reconciler's case: a
				// replay reads a pending entry as `interrupted`, keeps the
				// claim, and ten minutes later the reconciler releases it
				// rather than making it approvable again — a transient log
				// write permanently discarding an approved call that
				// provably never ran. Refused + no `ran` + a retryable code
				// is exactly the shape replay() gives the hold back for.
				//
				// The envelope WAS taken, so its id goes on the terminal row:
				// the capture is real, on disk, and has to stay traceable
				// even though the write it was taken for never happened.
				//
				// A settle that fails too leaves the row pending, which is
				// the old behaviour and still correct — the reconciler is the
				// backstop for a row nothing could write to.
				Aura_Worker_Door_Log::settle(
					$seq,
					array(
						'result'      => 'refused',
						'reason'      => 'snapshot_id_unrecorded',
						'snapshot_id' => $snapshot_id,
					)
				);
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
				// FINISHED — compensated and settled inside. The request is
				// cleared like every other terminal branch: left standing, it
				// still said "a creation is in flight", so the global insert
				// observer attributed the next post of the expected type in
				// this same PHP request to a call that had already ended, and
				// threw over it (round-9).
				self::$request = null;
				return $creation;
			}
			self::$request['creation_fields'] = $creation; // what a later throw settles with
			$terminal                         = array_merge( $terminal, $creation );
		}
		if ( ! empty( self::$request['collateral'] ) ) {
			$terminal['collateral_snapshot_ids'] = self::$request['collateral'];
		}
		if ( ! Aura_Worker_Door_Log::settle( $seq, $terminal ) ) {
			// THE WRITE RAN AND THE LOG DOES NOT SAY SO (Ruling P16).
			// Returning the callback's result here told the caller it
			// succeeded while the row sat pending — `log_after` stops at a
			// pending row, so every later entry waited behind it, and the
			// reconciler eventually reported `interrupted` for a mutation
			// that had completed. The row is LEFT as it is on purpose: a
			// pending one carries `ran` under a replay, and `interrupted` is
			// the honest terminal state for an outcome the log never learned.
			// What changes is the ANSWER — a caller must not be told a write
			// succeeded on the strength of a record that does not exist.
			//
			// SOMEBODY ELSE MAY HAVE SETTLED IT FIRST (Ruling P27) — `/status` read
			// this row as stale while the callback was still running. The
			// answer is the same either way: the write ran and THIS site did
			// not record its outcome, so the caller is told `may_have_run`
			// rather than handed a success over a row that says otherwise.
			// Only the COUNTER differs: an entry that exists was governed,
			// just not by us, so `log_ungoverned` stays where it is.
			if ( ! Aura_Worker_Door_Log::is_terminal( $seq ) ) {
				self::bump_counter( 'log_ungoverned' );
			}
			self::$request = null;
			return new WP_Error(
				'aura_log_failed',
				'The write ran but this site could not record its outcome; check the site before retrying.',
				array(
					'status'       => 503,
					'may_have_run' => true,
					'seq'          => $seq,
				)
			);
		}
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
		// The HOLD is the durable fact — the operator can act on it, and a
		// replay reads it, whatever the log managed to record — so a hold is
		// never refused because its evidence could not be written. The miss
		// is noted ON the hold row instead, so an entry that never appears is
		// explained rather than merely absent (Ruling P25). `log_ungoverned`
		// is bumped inside record_terminal_only(), where every other one is.
		$logged = self::record_terminal_only(
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
		if ( ! $logged ) {
			Aura_Worker_Door_Holds::note_unlogged( $ref );
		}
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
		// NOT admitted here (Ruling P25): settle() admits in the same write,
		// so a successful settle yields exactly the admitted terminal row it
		// always did — and a FAILED one leaves an UN-ADMITTED pending row,
		// which the reconciler discards. Admitting first made that same
		// failure leave an admitted pending row: `log_after()` stops at one,
		// so every later entry waited behind it, and the reconciler
		// eventually called a call that never ran `interrupted`. Discarded is
		// the honest state for an entry whose call never happened.
		if ( ! Aura_Worker_Door_Log::settle( $seq, array_merge( array( 'result' => $result ), $extra ) ) ) {
			self::bump_counter( 'log_ungoverned' );
			return false;
		}
		return true;
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
		$option = self::COUNTER_PREFIX . $name . '_h' . (int) floor( time() / HOUR_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no') ON DUPLICATE KEY UPDATE option_value = option_value + 1", $option ) );
		wp_cache_delete( $option, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Delete every counter bucket past the 30-day window count_30d() reads —
	 * the sweep bump_counter() copied Aura_Worker_Rules::bump()'s atomic
	 * upsert WITHOUT. Without it a bucket is kept for ever: 720 rows per
	 * counter name is the WINDOW, not the total, and a site that has bumped
	 * a counter every hour for a year carries eight thousand dead options
	 * that nothing will ever read again.
	 *
	 * Pruned in PHP over the same (name) listing count_30d() already scans,
	 * NOT by Aura_Worker_Rules::sweep_options()'s string bound. That sweep
	 * deletes `option_name < '<prefix>h<hour>'`, which orders names
	 * lexicographically — correct only because Aura_Worker_Rules::bucket_name()
	 * zero-pads its hour. The door's suffix is not padded (count_30d()'s own
	 * comment says so), so a string bound would order `_h9` after `_h100000`
	 * and delete the wrong rows. Zero-padding the door's suffix instead would
	 * mean two name formats on every existing site and a migration to
	 * reconcile them; comparing the parsed integer costs one preg_match per
	 * row and needs neither.
	 *
	 * Bounded by the caller: this runs inside reconcile()'s PRUNE_INTERVAL_S
	 * gate, under the same PRUNED_AT stamp as the envelope sweep, because it
	 * reads every counter row there is and `/status` is the site's hottest
	 * endpoint (Ruling P9(a)).
	 *
	 * @param int $now Unix time.
	 * @return int How many buckets were deleted.
	 */
	private static function prune_counters( $now ) {
		global $wpdb;
		$oldest = (int) floor( ( (int) $now - 30 * DAY_IN_SECONDS ) / HOUR_IN_SECONDS );
		$names  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( self::COUNTER_PREFIX ) . '%'
			)
		);
		$gone = 0;
		foreach ( (array) $names as $name ) {
			// The hour suffix, whatever counter name sits between it and the
			// prefix. A row under this prefix that carries no numeric hour is
			// not one of these buckets and is left alone — the same defensive
			// read count_30d() applies with ctype_digit().
			if ( ! preg_match( '/_h([0-9]+)$/', (string) $name, $m ) ) {
				continue;
			}
			if ( (int) $m[1] < $oldest ) {
				delete_option( (string) $name );
				$gone++;
			}
		}
		return $gone;
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
		$prefix = self::COUNTER_PREFIX . $name . '_h';
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
	 * Is the door open? ONE definition, for every reader (Ruling P24).
	 *
	 * Two independent things close it, and each was being reported by only
	 * one caller. The SEAM: coverage that could not be verified means writes
	 * may be reaching Elementor ungoverned, so the transport is refused. The
	 * LOG: at MAX_UNACKED every governed write is answered `aura_log_full`,
	 * which is a closed door by any honest reading — and the status fragment
	 * went on reporting `open` whenever coverage was healthy, contradicting
	 * both governor_block() and the ack response on the very poll an
	 * operator would be looking at to find out why writes were failing.
	 *
	 * Public because the ack route answers with it too: three readers, one
	 * answer.
	 *
	 * @return string `open` or `closed`.
	 */
	public static function door_state() {
		return ( 'ok' === self::$seam && ! Aura_Worker_Door_Log::is_closed() ) ? 'open' : 'closed';
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
			'door'                => self::door_state(),
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
				//
				// The creating ABILITY is carried onto the capture: a
				// component creation is governed as `design_system:*`, and
				// the envelope that undoes the undo of it must be judged on
				// that too (round-3 P1). It is the only marker that survives
				// — a `posts` capture records no `post_type` of its own.
				$carried = $door;
				if ( isset( $record['door']['ability'] ) && is_string( $record['door']['ability'] ) ) {
					$carried['ability'] = (string) $record['door']['ability'];
				}
				return $snaps->snapshot_posts(
					(array) ( isset( $record['created_post_ids'] ) ? $record['created_post_ids'] : array() ),
					self::PAGE_META_KEYS,
					array( 'kind_label' => 'creation_restore', 'door' => $carried )
				);
		}
		return array( 'success' => true, 'snapshot' => null ); // not a door envelope: nothing to log
	}

	/**
	 * What a restore of this envelope will TOUCH — read off the envelope
	 * itself, in the vocabulary every other write is judged in (Ruling P12).
	 *
	 * A restore is a WRITE: it rewrites the pages an envelope covers, or
	 * trashes the pages a `creation` envelope names. The REST handler's own
	 * guard declares `site:*`, which only a freeze can match — a rule
	 * protecting page 7, or the design system, says nothing about `site:*`
	 * and could never refuse a restore that rewrites exactly that.
	 *
	 * SYMMETRY WITH THE WRITE: a restore declares what the write that made
	 * the envelope declared. A `component` write is judged on
	 * `design_system:*` (touches_for()'s `component` case), so its restore
	 * declares that too, BESIDE the component post itself — a rule that
	 * stopped the write must be able to stop the undo of it, and the rule
	 * might name either. That covers a `manage-component` call naming no id
	 * as well: it CREATES the component, so its envelope is a `creation`
	 * whose restore trashes it, and `is_component_envelope()` recognises it
	 * (round-3 P1). The remaining kinds already line up: a `page` write names
	 * its page, a `design_system` write names the category, and a `creation`
	 * of a page trashes exactly the pages it made.
	 *
	 * An envelope that names NO target derives no touches, and the matcher
	 * reads an empty declaration as its `unknown` sentinel — every live rule
	 * applies. That is the conservative direction, and the right one: an
	 * envelope that cannot say what it covers has not shown it is safe.
	 *
	 * @param array $record The envelope about to be restored.
	 * @return array[] Touch declarations.
	 */
	private static function restore_touches( array $record ) {
		$kind = (string) ( isset( $record['door_kind'] ) ? $record['door_kind'] : '' );
		if ( 'design_system' === $kind ) {
			return array( array( 'type' => 'design_system', 'id' => '*' ) );
		}
		if ( 'creation' === $kind ) {
			// Undoing a creation TRASHES these ids: a rule protecting one of
			// them protects it from this restore too.
			$ids = (array) ( isset( $record['created_post_ids'] ) ? $record['created_post_ids'] : array() );
		} elseif ( in_array( $kind, array( 'page', 'component', 'creation_restore' ), true ) ) {
			$ids = (array) ( isset( $record['targets'] ) ? $record['targets'] : array() );
		} else {
			return array();
		}
		$touches = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				// `page` and `post` are one id seen from two directions, and
				// the matcher treats them as such — one type is enough.
				$touches[] = array( 'type' => 'page', 'id' => (string) $id );
			}
		}
		if ( 'component' === $kind
			|| ( in_array( $kind, array( 'creation', 'creation_restore' ), true ) && self::is_component_envelope( $record ) ) ) {
			$touches[] = array( 'type' => 'design_system', 'id' => '*' );
		}
		return $touches;
	}

	/**
	 * Does this envelope cover an Elementor COMPONENT — the thing a
	 * `manage-component` call writes, and which the door governs as
	 * `design_system:*`?
	 *
	 * Read off the envelope, never off live state: the post may be trashed,
	 * gone, or a different type by now, and none of that changes what the
	 * write was judged on. `post_type` is the primary witness —
	 * snapshot_creation() stores it, and it is the type of the very rows the
	 * restore would trash. `door.ability` is the fallback, for an envelope
	 * whose type was not recorded; pre_restore_capture() carries that ability
	 * onto the `creation_restore` capture it takes, so the second-order undo
	 * is judged the same way as the first.
	 *
	 * @param array $record The envelope.
	 * @return bool
	 */
	private static function is_component_envelope( array $record ) {
		if ( isset( $record['post_type'] ) && self::CPT_COMPONENT === (string) $record['post_type'] ) {
			return true;
		}
		$door = isset( $record['door'] ) && is_array( $record['door'] ) ? $record['door'] : array();
		return isset( $door['ability'] ) && 'elementor/manage-component' === (string) $door['ability'];
	}

	/**
	 * Reserve the door entry for a restore BEFORE anything is captured or
	 * written — the same admission every governed write gets. Logging after
	 * the fact would let a restore run unrecorded when the log was closed or
	 * the insert failed.
	 *
	 * And JUDGE it first, on the envelope's own touches, against the current
	 * ruleset — the same call `govern()` makes for every other write (Ruling
	 * P12). The entry used to hard-code an empty touch set and an `allow`
	 * verdict, so a `block` rule protecting page 7 could not stop a restore
	 * from rolling page 7 back. A `block` refuses here, before the entry is
	 * reserved and before anything is captured; `warn`, `allow`, `none` and
	 * `rules_unavailable` all proceed — a restore is an UNDO, and the only
	 * verdict that should stop one is a rule that names its target — and the
	 * entry records what was really judged.
	 *
	 * @param array  $record   The envelope about to be restored.
	 * @param string $aura_ref Aura's correlation id for this restore.
	 * @return int|WP_Error seq, or aura_rule_blocked / aura_log_full / aura_log_failed.
	 */
	public static function open_restore_entry( array $record, $aura_ref = '' ) {
		$actor = self::actor();
		$actor = is_wp_error( $actor ) ? array( 'user_id' => 0, 'login' => 'aura', 'via' => 'rest' ) : $actor;
		// Aura's own correlation id for this restore (its AgentAction's
		// doorRef), echoed on the entry so ingestion patches THAT row
		// instead of minting a second one. A refusal carries it too.
		$restore_of = (string) ( isset( $record['id'] ) ? $record['id'] : '' );
		$ref        = '' === (string) $aura_ref ? null : preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $aura_ref );
		$touches    = self::restore_touches( $record );
		$verdict    = self::govern( 'aura/restore', $touches, array( 'restore_of' => $restore_of ) );
		if ( 'block' === $verdict['effect'] ) {
			Aura_Worker_Rules::record_block( 'aura/restore', $verdict['rule'] );
			self::record_terminal_only(
				'aura/restore',
				$actor,
				$touches,
				'refused',
				array(
					'verdict'    => 'block',
					'rule_key'   => isset( $verdict['rule']['key'] ) ? (string) $verdict['rule']['key'] : '',
					'restore_of' => $restore_of,
					'ref'        => $ref,
				)
			);
			$blocked = Aura_Worker_Rules::blocked_result( 'aura/restore', $verdict['rule'] );
			return new WP_Error(
				'aura_rule_blocked',
				$blocked['error'],
				array(
					'status' => 403,
					'rule'   => $blocked['rule'],
				)
			);
		}
		if ( Aura_Worker_Door_Log::is_closed() ) {
			Aura_Worker_Door_Log::bump_refused();
			return self::log_full_error();
		}
		$seq = Aura_Worker_Door_Log::open_pending(
			array(
				'ability'    => 'aura/restore',
				'actor'      => $actor,
				'touches'    => $touches,
				'verdict'    => $verdict['verdict'],
				'rule_key'   => isset( $verdict['rule']['key'] ) ? (string) $verdict['rule']['key'] : null,
				'rule'       => null !== $verdict['rule'] ? self::rule_evidence( $verdict['rule'] ) : null,
				'restore_of' => $restore_of,
				'ref'        => $ref,
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
	 * ANSWERS whether the row is terminal (Ruling P19). The caller decides
	 * what to tell the client — a restore whose outcome the log never learned
	 * is not a 200 — and the counter is bumped HERE, beside every other
	 * `log_ungoverned` bump, so the audit counts an unrecorded restore the
	 * same way it counts an unrecorded write.
	 *
	 * @param int        $seq     The reserved entry.
	 * @param array|null $pre     Pre-restore envelope (or null).
	 * @param array      $outcome restore()'s result.
	 * @return bool The entry is terminal. False ⇒ it is still pending, and
	 *              the reconciler will call it `interrupted`.
	 */
	public static function settle_restore_entry( $seq, $pre, array $outcome ) {
		$settled = Aura_Worker_Door_Log::settle(
			(int) $seq,
			array(
				'result'      => empty( $outcome['success'] ) ? 'failed' : 'ok',
				'snapshot_id' => is_array( $pre ) ? (string) $pre['id'] : null,
				'trashed'     => isset( $outcome['trashed'] ) ? $outcome['trashed'] : null,
				'error'       => isset( $outcome['error'] ) ? $outcome['error'] : null,
			)
		);
		if ( ! $settled ) {
			self::bump_counter( 'log_ungoverned' );
		}
		return $settled;
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
		$id       = (int) $post_id;
		$type     = is_object( $post ) ? (string) ( $post->post_type ?? '' ) : (string) get_post_type( $id );
		$seq      = (int) self::$request['seq'];
		$expected = in_array( $type, (array) self::$request['expected'], true );
		if ( $expected ) {
			// THE IN-MEMORY WITNESS COMES FIRST — before any read that can
			// fail. It used to be recorded after the row read, so a read that
			// transiently answered nothing returned here with the id written
			// NOWHERE: a fatal a moment later left the reconciler only the
			// watermark's suspicion, which is unproven, and the post got
			// neither an envelope nor compensation.
			self::$request['created'][] = $id;
		}
		$row = Aura_Worker_Door_Log::get( $seq );
		if ( null === $row ) {
			if ( $expected ) {
				// An unreadable row is exactly as bad as an unwritable one
				// (Ruling P26): either way the row does not know about a post
				// that exists. Abort while the hook still holds the id.
				throw new Aura_Worker_Door_Witness_Exception( $id, esc_html( sprintf( 'the door log row could not be read to record created post %d', $id ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the message is escaped; the id is structured evidence the catch reads, never rendered.
			}
			return; // an unexpected insert records nothing a rollback needs
		}
		if ( $expected ) {
			$ids = array_values( array_unique( array_merge( (array) ( $row['created_post_ids'] ?? array() ), array( $id ) ) ) );
			if ( ! Aura_Worker_Door_Log::patch_pending( $seq, array( 'created_post_ids' => $ids ) ) ) {
				// THE POST EXISTS AND THE ROW CANNOT BE TOLD (Ruling P26).
				// Carrying on would leave this id in request memory alone: a
				// timeout or fatal before finish_creation() then leaves the
				// reconciler nothing but the watermark, whose suspicion
				// partition_created() treats as UNPROVEN — so the post would
				// get neither an envelope nor compensation, and nobody could
				// undo it. Abort while the hook still holds the id: the throw
				// unwinds Elementor's callback and execute() finishes the
				// creation from the in-memory witness, which either makes the
				// post restorable or trashes it.
				throw new Aura_Worker_Door_Witness_Exception( $id, esc_html( sprintf( 'the door log could not record created post %d', $id ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the message is escaped; the id is structured evidence the catch reads, never rendered.
			}
		} else {
			$others = array_values( array_unique( array_merge( (array) ( $row['other_inserts'] ?? array() ), array( $id ) ) ) );
			Aura_Worker_Door_Log::patch_pending( $seq, array( 'other_inserts' => $others ) );
		}
	}

	/**
	 * Judge the pages a class deletion is about to rewrite, against the
	 * ruleset THIS request is running under (Ruling P22).
	 *
	 * The source is `govern()`'s own: the pinned copy when a replay pinned
	 * one, else the current record — so a ruleset pushed mid-write cannot
	 * make the collateral verdict disagree with the verdict the call was
	 * admitted on. Each page is matched ALONE, because the answer has to name
	 * which pages it is about; `match()` returns one rule for a whole set.
	 *
	 * A `block` throws — the call is refused before Elementor's own handler
	 * (priority 10) rewrites anything, and `execute()` turns it into the same
	 * 403 a block before the call gets. A `warn` is recorded on the entry and
	 * proceeds. No ruleset at all judges nothing: a site that cannot say what
	 * is protected does not get to invent a refusal here, and the call was
	 * already admitted on that same silence.
	 *
	 * @param int[] $ids The pages Elementor named.
	 * @throws Aura_Worker_Door_Blocked_Exception When a block rule names one of them.
	 */
	private static function judge_collateral( array $ids ) {
		$rec   = null !== self::$pinned_ruleset ? self::$pinned_ruleset : Aura_Worker_Rules::current();
		$rules = ( is_array( $rec ) && isset( $rec['rules'] ) && is_array( $rec['rules'] ) ) ? $rec['rules'] : array();
		if ( empty( $rules ) ) {
			return;
		}
		$site    = Aura_Worker_Rules::site_ref();
		$blocked = array();
		$warned  = array();
		$b_rule  = null;
		$w_rule  = null;
		foreach ( $ids as $id ) {
			$rule = Aura_Worker_Rules::match( array( array( 'type' => 'page', 'id' => (string) $id ) ), $rules, null, $site );
			if ( null === $rule ) {
				continue;
			}
			if ( 'block' === $rule['effect'] ) {
				$blocked[] = (int) $id;
				$b_rule    = null === $b_rule ? $rule : $b_rule;
			} elseif ( 'warn' === $rule['effect'] ) {
				$warned[] = (int) $id;
				$w_rule   = null === $w_rule ? $rule : $w_rule;
			}
		}
		if ( ! empty( $blocked ) ) {
			Aura_Worker_Rules::record_block( (string) self::$request['slug'], $b_rule );
			$why = sprintf(
				'deleting this class would rewrite page(s) %s, which %s protects',
				implode( ', ', $blocked ),
				(string) ( isset( $b_rule['key'] ) ? $b_rule['key'] : 'a rule' )
			);
			throw new Aura_Worker_Door_Blocked_Exception( $b_rule, $blocked, esc_html( $why ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the message is escaped; the rule and the id list are structured evidence the catch reads, never rendered.
		}
		if ( ! empty( $warned ) ) {
			Aura_Worker_Rules::record_warn( (string) self::$request['slug'], $w_rule );
			Aura_Worker_Door_Log::patch_pending(
				(int) self::$request['seq'],
				array(
					'collateral_warned' => $warned,
					'collateral_rule'   => self::rule_evidence( $w_rule ),
				)
			);
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
		// JUDGE BEFORE CAPTURING (Ruling P22). The call was admitted on
		// `design_system:*` — the only thing it could declare, because the
		// pages a class deletion rewrites are not knowable until Elementor
		// says so, HERE, one priority before it rewrites them. A rule
		// protecting one of those pages therefore never saw this call, and
		// the ids were used for nothing but a rollback snapshot: the
		// protected page was changed and the block only helped afterwards.
		self::judge_collateral( $ids );
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
		$row   = array(
			'seq'        => (int) $seq,
			'started_at' => gmdate( 'c' ),
		);
		$taken = Aura_Worker_Door_Log::insert_unique( self::CREATING, $row );
		if ( $taken ) {
			// OWNERSHIP: only the request that inserted the row may delete it,
			// and only THAT row — the release fences on these exact bytes
			// (Ruling P17), so they are kept here rather than rebuilt later
			// from a timestamp that has moved on.
			self::$request['mutex_held']  = true;
			self::$request['mutex_bytes'] = (string) maybe_serialize( $row );
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
	 * The bound is `post_modified_gmt`, not `post_date_gmt`. WordPress stamps
	 * `post_date_gmt` only for a published post — a draft, pending, or
	 * auto-draft row keeps it at the zero date (`0000-00-00 00:00:00`), which
	 * always satisfies `<= $until`. Bounding on that column would leave
	 * ordinary drafts unbounded: a same-type draft the actor created AFTER
	 * the window closed would still be attributed to a stale call, and a
	 * restore of that call's `creation` snapshot could trash it.
	 * `post_modified_gmt` is populated on every insert, drafts included, so it
	 * bounds every row the same way. The cost is symmetric with the benefit,
	 * not free: a post created inside the window and only edited later falls
	 * OUTSIDE `$until` and is excluded from the diff — the conservative
	 * direction, since an unattributed post is left in place, never trashed.
	 *
	 * @param int         $mark     The watermark: the highest post id before the write.
	 * @param string[]    $types    Post types the creation may legitimately have inserted.
	 * @param int         $actor_id The actor the creation ran as.
	 * @param string|null $until    GMT datetime; ids whose `post_modified_gmt` is after it are not this call's.
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
			$sql   .= ' AND post_modified_gmt <= %s';
			$args[] = (string) $until;
		}
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * THE PARTITION both creation paths share (Ruling P11): what this call
	 * PROVABLY created, and what only the watermark suspects.
	 *
	 * The diff cannot tell this call's own unhooked insert from ANOTHER
	 * request's — a post of the same type, by the same user, landing above
	 * the mark while the governed callback runs is indistinguishable from
	 * one the callback made. So a watermark-only id is evidence and nothing
	 * more: it is recorded on the entry (`observed_by_watermark`, the
	 * `hook_missed` counter, `unproven`), and kept OUT of
	 * `created_post_ids` — the set a `creation` envelope's restore trashes,
	 * and the set compensation trashes when that envelope cannot be stored.
	 * Recording is not attributing.
	 *
	 * An id the insert hook recorded is proven: core told us, inside the
	 * write, that this request made it. An id the callback's RESULT names is
	 * proven only when the diff ALSO saw it — two independent witnesses
	 * agreeing. A result-named id NEITHER witness saw stays what it always
	 * was, `unattributed_result`: recorded, never restorable.
	 *
	 * @param int[] $hooked    Ids witness 1 (the insert hook) recorded.
	 * @param int[] $diff      Ids witness 2 (the watermark diff) found.
	 * @param int   $result_id The id the inner result names, or 0.
	 * @return array{ proven: int[], unproven: int[] }
	 */
	private static function partition_created( array $hooked, array $diff, $result_id ) {
		$hooked = array_values( array_unique( array_map( 'intval', $hooked ) ) );
		$diff   = array_values( array_unique( array_map( 'intval', $diff ) ) );
		$proven = $hooked;
		$named  = (int) $result_id;
		if ( $named > 0 && in_array( $named, $diff, true ) && ! in_array( $named, $proven, true ) ) {
			$proven[] = $named;
		}
		return array(
			'proven'   => $proven,
			'unproven' => array_values( array_diff( $diff, $proven ) ),
		);
	}

	/**
	 * After the inner callback of a creating ability: partition the two
	 * witnesses (Ruling P11), store the `creation` envelope over what this
	 * call provably made, and — when it cannot be stored — compensate that
	 * same set, because the write already happened.
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
		$named    = is_array( $result ) && isset( $result['id'] ) && is_numeric( $result['id'] ) ? (int) $result['id'] : 0;
		$part     = self::partition_created( $hooked, $diff, $named );
		$created  = $part['proven'];
		$missed   = array_values( array_diff( $diff, $hooked ) ); // what witness 1 did not see, proven or not
		$fields   = array( 'created_post_ids' => $created );
		if ( ! empty( $missed ) ) {
			$fields['observed_by_watermark'] = $missed;
			$fields['hook_missed']           = count( $missed );
			self::bump_counter( 'hook_missed' );
		}
		if ( ! empty( $part['unproven'] ) ) {
			// Recorded so an operator can see what the watermark saw beside
			// this call — never restorable, never compensated.
			$fields['unproven'] = $part['unproven'];
		}
		if ( empty( $created ) && empty( $missed ) && ! $failed ) {
			self::bump_counter( 'unobserved' ); // a success that inserted nothing the witnesses could see
		}
		if ( $named > 0 && ! in_array( $named, $created, true ) ) {
			$fields['unattributed_result'] = $named;
		}
		try {
			if ( empty( $created ) ) {
				// Nothing PROVEN was inserted: nothing to put in an envelope
				// and nothing to undo, whatever the diff suspects beside it.
				// The mutex is released in the finally.
				return $fields;
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
	 * Release the creation mutex — but ONLY the row this request inserted.
	 *
	 * Two conditions, and both are load-bearing. `mutex_held` says this
	 * request took A mutex: one whose insert never landed (it lost the race,
	 * or the statement threw) must not delete anything, because the row it
	 * would delete is another creation's (round 1). And the DELETE is FENCED
	 * on the exact bytes it inserted (Ruling P17), because holding the mutex
	 * once is not the same as holding it now: a creation still running past
	 * CLAIM_STALE_MS has its row cleared by the reconciler
	 * (clear_stale_creation_mutex()) and a second creation takes a
	 * replacement. This request's flag is still set, so an unconditional
	 * delete_option() on its way out would remove the SECOND request's mutex
	 * and let a third creation run beside it. The fence names one row —
	 * seq + started_at — so a replacement, whose seq differs, is never it.
	 *
	 * The same shape as Aura_Worker_Door_Holds::release_lock() and
	 * clear_stale_creation_mutex(): a fenced DELETE, then both option caches
	 * evicted, because the compare is on bytes the cache does not hold.
	 */
	private static function release_creation_mutex() {
		if ( null === self::$request || empty( self::$request['mutex_held'] ) ) {
			return;
		}
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				self::CREATING,
				(string) ( isset( self::$request['mutex_bytes'] ) ? self::$request['mutex_bytes'] : '' )
			)
		);
		wp_cache_delete( self::CREATING, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		self::$request['mutex_held'] = false;
	}
}
