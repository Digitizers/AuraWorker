<?php
/**
 * The reconciler — what a died request left behind, settled at the head of
 * every `/status` — and the `door` fragment Aura drains (spec §3.10).
 *
 * Every case here is a real row: a hold taken and claimed through
 * Aura_Worker_Door_Holds, a log entry opened and admitted through
 * Aura_Worker_Door_Log, then backdated the way ten minutes of wall clock
 * would have. Nothing is mocked, and the reconciler is the production one.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class DoorReconcilerTest extends TestCase {

	/** @var Aura_Worker_API */
	private $api;

	protected function setUp(): void {
		sa_reset_state();
		$this->rrmdir( WP_CONTENT_DIR );
		mkdir( WP_CONTENT_DIR, 0755, true );
		foreach ( array( 'class-aura-worker-door-log', 'class-aura-worker-door-holds', 'class-elementor-door-governor' ) as $f ) {
			require_once dirname( __DIR__, 2 ) . '/digitizer-site-worker/includes/' . $f . '.php';
		}
		Aura_Worker_Elementor_Door::reset_for_tests();
		Aura_Worker_Elementor_Door::init();
		$GLOBALS['_sa_force_door']   = true; // stands in for Elementor's MCP module class
		$GLOBALS['_current_user_id'] = 3;
		$GLOBALS['_user_logins'][3]  = 'bot';
		do_action( 'wp_abilities_api_init' ); // the coverage check: nothing registered, nothing uncovered
		$this->api                   = new Aura_Worker_API( new Aura_Worker_Security() );
	}

	protected function tearDown(): void {
		$dir = WP_CONTENT_DIR . '/aura-backups/snapshots';
		if ( is_dir( $dir ) ) {
			@chmod( $dir, 0777 );
		}
		$this->rrmdir( WP_CONTENT_DIR );
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( array_diff( scandir( $dir ), array( '.', '..' ) ) as $item ) {
			$path = $dir . '/' . $item;
			is_dir( $path ) ? $this->rrmdir( $path ) : @unlink( $path );
		}
		@rmdir( $dir );
	}

	/* ------------------------------------------------------------------ */
	/* Fixtures                                                            */
	/* ------------------------------------------------------------------ */

	/** Ten minutes and a bit ago, in the stamp format every row carries. */
	private function longAgo(): string {
		return gmdate( 'c', time() - 1200 );
	}

	/** A real held row. */
	private function hold( string $ability = 'elementor/publish-document' ): string {
		$ref = Aura_Worker_Door_Holds::hold(
			array(
				'ability' => $ability,
				'input'   => array( 'id' => 7, 'secret' => 'not-in-the-listing' ),
				'touches' => array( array( 'type' => 'page', 'id' => '7' ) ),
				'actor'   => array( 'user_id' => 3, 'login' => 'bot' ),
				'verdict' => 'none',
			)
		);
		$this->assertIsString( $ref );
		return $ref;
	}

	/** Claim it, and (unless $fresh) backdate the claim past CLAIM_STALE_MS. */
	private function claim( string $ref, array $over = array(), bool $fresh = false ): void {
		$this->assertIsArray( Aura_Worker_Door_Holds::claim( $ref ) );
		$patch = $over;
		if ( ! $fresh ) {
			$patch['claimed_at'] = $this->longAgo();
		}
		$this->patchOption( Aura_Worker_Door_Holds::CLAIMED . $ref, $patch );
	}

	/** Merge fields into an option row, in the database and the cache alike. */
	private function patchOption( string $name, array $fields ): void {
		$row = get_option( $name, array() );
		$this->assertIsArray( $row, "option {$name} is missing" );
		$row                        = array_merge( $row, $fields );
		$GLOBALS['_options'][ $name ] = $row;
		$GLOBALS['_rows'][ $name ]    = maybe_serialize( $row );
		unset( $GLOBALS['_notoptions'][ $name ] );
	}

	/** A log entry, opened for real; $fields are patched onto it. */
	private function entry( array $fields = array(), bool $admit = true, bool $stale = true ): int {
		$seq = Aura_Worker_Door_Log::open_pending(
			array(
				'ability' => 'elementor/publish-document',
				'actor'   => array( 'user_id' => 3, 'login' => 'bot' ),
				'touches' => array( array( 'type' => 'page', 'id' => '7' ) ),
				'verdict' => 'none',
			)
		);
		$this->assertIsInt( $seq );
		if ( $admit ) {
			$this->assertTrue( Aura_Worker_Door_Log::admit( $seq ) );
		}
		if ( $stale ) {
			$fields['at'] = $this->longAgo();
		}
		if ( ! empty( $fields ) ) {
			$this->patchOption( Aura_Worker_Door_Log::PREFIX . $seq, $fields );
		}
		return $seq;
	}

	private function row( int $seq ): array {
		$row = Aura_Worker_Door_Log::get( $seq );
		$this->assertIsArray( $row, "door log row {$seq} is missing" );
		return $row;
	}

	/**
	 * A post that was already there, with every field a capture reads — and a
	 * `post_modified_gmt` the watermark diff's time bound is compared
	 * against (2.16.0, Codex round-1 P2: `post_date_gmt` is WordPress's zero
	 * date on an unpublished post, so it cannot be the bound). `$modified`
	 * mirrors `$gmt` when not given, the way a freshly-made, unedited post
	 * would; pass it explicitly to seed a post edited after it was made.
	 */
	private function seedPost( int $id, string $type = 'page', int $author = 3, ?string $gmt = null, string $status = 'publish', ?string $modified = null ): void {
		$gmt                      = null === $gmt ? gmdate( 'Y-m-d H:i:s', time() - 1200 ) : $gmt;
		$modified                 = null === $modified ? $gmt : $modified;
		$GLOBALS['_posts'][ $id ] = (object) array(
			'ID'                => $id,
			'post_type'         => $type,
			'post_status'       => $status,
			'post_title'        => 'p-' . $id,
			'post_name'         => 'p-' . $id,
			'post_parent'       => 0,
			'post_content'      => '',
			'post_excerpt'      => '',
			'menu_order'        => 0,
			'post_author'       => $author,
			'post_date'         => $gmt,
			'post_date_gmt'     => $gmt,
			'post_modified'     => $modified,
			'post_modified_gmt' => $modified,
			'comment_status'    => 'closed',
			'ping_status'       => 'closed',
		);
	}

	/**
	 * Run $fn with the snapshot store unwritable, so every persist() fails.
	 * The E_WARNING file_put_contents() raises is the DISK failing, not a
	 * defect under test; silenced so the suite's output stays pristine.
	 */
	private function withUnwritableSnapshots( callable $fn ) {
		$dir = WP_CONTENT_DIR . '/aura-backups/snapshots';
		if ( ! is_dir( $dir ) ) {
			new Aura_Worker_Snapshots(); // the constructor creates it
		}
		chmod( $dir, 0555 );
		if ( false !== @file_put_contents( $dir . '/probe', 'x' ) ) {
			@unlink( $dir . '/probe' );
			chmod( $dir, 0777 );
			$this->markTestSkipped( 'filesystem does not enforce the mode (running as root?)' );
		}
		set_error_handler( static function () { return true; }, E_WARNING );
		try {
			return $fn();
		} finally {
			restore_error_handler();
			chmod( $dir, 0777 );
		}
	}

	private function fragment( int $after = 0, string $epoch = '' ): array {
		$out = Aura_Worker_Elementor_Door::status_fragment( $after, $epoch );
		$this->assertIsArray( $out );
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* (a) a stale claim with no terminal_seq                              */
	/* ------------------------------------------------------------------ */

	public function test_a_stale_claim_with_no_terminal_seq_is_written_interrupted_and_released(): void {
		$ref = $this->hold();
		$this->claim( $ref );

		$out = Aura_Worker_Elementor_Door::reconcile();

		$this->assertSame( 1, $out['interrupted'] );
		$this->assertSame( 1, $out['settled_claims'] );
		$row = $this->row( 1 );
		$this->assertSame( 'interrupted', $row['result'] );
		$this->assertSame( $ref, $row['ref'] );
		$this->assertSame( 'elementor/publish-document', $row['ability'] );
		$this->assertTrue( $row['admitted'], 'the entry is served, so it is admitted' );
		$this->assertNull( Aura_Worker_Door_Holds::get_claimed( $ref ), 'the claim is released once its evidence is durable' );
		$this->assertNull( Aura_Worker_Door_Holds::get_held( $ref ) );
	}

	/**
	 * Ruling P54: ONE predicate, two views of it.
	 *
	 * `/status` reported `interrupted[]` from `stale_claims()` — age alone —
	 * while the reconciler skipped anything holding an execution lease. A
	 * long-running replay was therefore listed as interrupted on every poll
	 * while the reconciler was correctly leaving it alone. It is now reported
	 * as `running`, which is what it is.
	 */
	public function test_a_leased_claim_past_the_bound_is_running_not_interrupted(): void {
		$ref = $this->hold();
		$this->claim( $ref ); // backdated past CLAIM_STALE_MS
		$GLOBALS['_sa_named_locks'][ Aura_Worker_Door_Holds::lease_name( $ref ) ] = true;

		$out  = Aura_Worker_Elementor_Door::reconcile();
		$frag = $this->fragment();

		$this->assertSame( 0, $out['settled_claims'], 'the reconciler leaves a running replay alone' );
		$this->assertSame( array(), $frag['interrupted'], 'and `/status` no longer calls it interrupted' );
		$this->assertSame( array( $ref ), array_column( $frag['running'], 'ref' ) );
		$this->assertNotSame( '', $frag['running'][0]['claimed_at'] );
		$this->assertNotNull( Aura_Worker_Door_Holds::get_claimed( $ref ), 'the claim still stands' );
	}

	/**
	 * Ruling S52 (Codex round-20 P2 on #88): `running` and `interrupted`
	 * used to come from TWO separate calls -- running_claims() then
	 * stale_unleased_claims() -- each its OWN fresh scan of the claimed
	 * queue and its OWN fresh lease check per row. A lease released
	 * between those two calls put the SAME ref on BOTH sides at once,
	 * certified under a perfectly ordinary observation -- nothing about a
	 * lease changing bumps the door version, so version_bracketed() never
	 * saw this as torn. status_fragment() now reads
	 * Aura_Worker_Door_Holds::partition_stale_claims() exactly ONCE per
	 * attempt, so a single read's own lease check can never disagree with
	 * itself.
	 */
	public function test_a_lease_change_never_leaves_the_same_claim_running_and_interrupted_at_once(): void {
		$ref = $this->hold();
		$this->assertIsArray( Aura_Worker_Door_Holds::claim( $ref ) ); // fresh — NOT backdated
		$lease = Aura_Worker_Door_Holds::lease_name( $ref );
		$GLOBALS['_sa_named_locks'][ $lease ] = true;

		$young = $this->fragment();
		$this->assertSame( array(), $young['running'], 'the fixture assumption this test is built on — too young to be running yet' );

		// The clock advances past CLAIM_STALE_MS — nothing else mutates.
		$this->patchOption( Aura_Worker_Door_Holds::CLAIMED . $ref, array( 'claimed_at' => $this->longAgo() ) );
		// Ruling S52's own regression: the lease self-releases the INSTANT
		// after its FIRST read this poll — modelling exactly the race two
		// SEPARATE scans (the pre-S52 running_claims()/
		// stale_unleased_claims() call pair) used to open.
		$GLOBALS['_sa_lease_release_after_check'][ $lease ] = true;

		$crossed = $this->fragment();

		$running_refs     = array_column( $crossed['running'], 'ref' );
		$interrupted_refs = array_column( $crossed['interrupted'], 'ref' );
		$this->assertFalse(
			in_array( $ref, $running_refs, true ) && in_array( $ref, $interrupted_refs, true ),
			'ONE scan, ONE lease check per row (Ruling S52): the same ref must never be reported on BOTH sides from a single read'
		);
	}

	/**
	 * Ruling S45 (Codex round-18 P2 on #88): a claim enters `running`
	 * SOLELY by its own `claimed_at` crossing CLAIM_STALE_MS — no
	 * mutation, no version bump of its own — so the fragment used to
	 * attach the SAME observation it served when the claim was still
	 * young, and Aura's strictly-greater comparison hid the transition
	 * entirely. The running set's own IDENTITY is now folded into the
	 * persisted computed tuple: the FIRST serve that observes the
	 * crossing persists it through versioned() before the bracket, so
	 * `running` is served under a witness strictly greater than before.
	 */
	public function test_a_claim_crossing_the_stale_bound_with_no_mutation_still_bumps_the_observation(): void {
		$ref = $this->hold();
		$this->assertIsArray( Aura_Worker_Door_Holds::claim( $ref ) ); // fresh — NOT backdated
		$GLOBALS['_sa_named_locks'][ Aura_Worker_Door_Holds::lease_name( $ref ) ] = true;

		$young = $this->fragment();
		$this->assertSame( array(), $young['running'], 'the fixture assumption this test is built on — too young to be running yet' );
		$v1 = $young['observation'];
		$this->assertIsInt( $v1 );

		// The clock advances past CLAIM_STALE_MS — nothing else mutates:
		// no write, no version bump from any OTHER source.
		$this->patchOption( Aura_Worker_Door_Holds::CLAIMED . $ref, array( 'claimed_at' => $this->longAgo() ) );

		$crossed = $this->fragment();
		$this->assertSame( array( $ref ), array_column( $crossed['running'], 'ref' ), 'the crossing is now observed' );
		$this->assertNotNull( $crossed['observation'], 'a single retry (this attempt\'s own persist) resolves this — never "torn twice"' );
		$this->assertGreaterThan( $v1, $crossed['observation'], 'served under a witness STRICTLY greater than the young claim\'s own poll' );

		// A second serve of the SAME crossing is now a steady state —
		// nothing about the running set has changed since the last
		// serve — so nothing bumps again.
		$again = $this->fragment();
		$this->assertSame( $crossed['observation'], $again['observation'], 'the crossing is already recorded — a repeat serve does not bump again' );
		$this->assertSame( array( $ref ), array_column( $again['running'], 'ref' ) );
	}

	/**
	 * The other half of Ruling S45: LEAVING `running` (the lease releases,
	 * or the claim is finally settled) is a transition too.
	 */
	public function test_a_claim_leaving_running_also_bumps_the_observation(): void {
		$ref = $this->hold();
		$this->claim( $ref ); // backdated past CLAIM_STALE_MS
		$GLOBALS['_sa_named_locks'][ Aura_Worker_Door_Holds::lease_name( $ref ) ] = true;

		$running_frag = $this->fragment();
		$this->assertSame( array( $ref ), array_column( $running_frag['running'], 'ref' ), 'the fixture assumption this test is built on' );
		$v1 = $running_frag['observation'];
		$this->assertIsInt( $v1 );

		// The lease releases — nothing about the claimed row itself
		// changes, only whether a live connection still holds its lock.
		unset( $GLOBALS['_sa_named_locks'][ Aura_Worker_Door_Holds::lease_name( $ref ) ] );

		$left = $this->fragment();
		$this->assertSame( array(), $left['running'], 'no longer running — it is interrupted now instead' );
		$this->assertSame( array( $ref ), array_column( $left['interrupted'], 'ref' ) );
		$this->assertGreaterThan( $v1, $left['observation'], 'leaving running is ALSO a transition — served under a strictly greater witness' );
	}

	/**
	 * Ruling S46 (Codex round-19, S45 class): the SAME transition-fold as
	 * `running`, for `held`/`held_count`/`queue_full` — a held row ages
	 * out SOLELY by its own `expires_at` passing, no mutation of its own
	 * (nothing deletes the row until hold()'s own NEXT purge_expired()
	 * sweep). status_fragment() persists the crossing (its own `held`
	 * field, live, and the identity that protects the witness);
	 * governor_block()'s `held_count`/`queue_full` — which cannot write
	 * anything themselves (Ruling S27) — benefit from that SAME persisted
	 * witness once it has landed.
	 */
	public function test_a_held_row_aging_out_with_no_mutation_still_bumps_the_observation(): void {
		$ref = $this->hold();
		$this->assertSame( array( $ref ), array_column( $this->fragment()['held'], 'ref' ), 'the fixture assumption this test is built on' );
		$v1 = Aura_Worker_Elementor_Door::governor_block()['observation'];
		$this->assertIsInt( $v1 );
		$this->assertSame( 1, Aura_Worker_Elementor_Door::governor_block()['held_count'] );

		// The clock advances past the hold's own 7-day TTL — nothing else
		// mutates; the row is not deleted (only hold()'s own sweep does
		// that, and nothing calls hold() here).
		$this->patchOption( Aura_Worker_Door_Holds::HELD . $ref, array( 'expires_at' => gmdate( 'c', time() - 1 ) ) );
		// held_rows() memoises its read for the WHOLE process (Ruling
		// P71) — correct across two DIFFERENT reading requests, but this
		// direct option-table patch (unlike a real write through
		// Aura_Worker_Door_Holds' own methods) never calls forget_held()
		// itself, so the FIRST status_fragment() call above's own memo
		// would otherwise still answer the pre-patch snapshot here.
		Aura_Worker_Door_Holds::forget_held();

		$crossed = $this->fragment();
		$this->assertSame( array(), $crossed['held'], 'the aged-out row no longer appears in the listing' );
		$this->assertNotNull( $crossed['observation'], 'a single retry resolves this — never "torn twice"' );
		$this->assertGreaterThan( $v1, $crossed['observation'], 'served under a witness STRICTLY greater than the pre-expiry poll' );

		$block = Aura_Worker_Elementor_Door::governor_block();
		$this->assertSame( 0, $block['held_count'], 'derived from the SAME persisted crossing — never a separate re-count' );
		$this->assertFalse( $block['queue_full'] );
		$this->assertGreaterThan( $v1, $block['observation'], 'the audit benefits from the poll\'s own persisted witness — it cannot write one itself (Ruling S27)' );

		// A second serve of the SAME crossing is a steady state.
		$again = $this->fragment();
		$this->assertSame( $crossed['observation'], $again['observation'], 'the crossing is already recorded — a repeat serve does not bump again' );
		$this->assertSame( array(), $again['held'] );
	}

	/**
	 * Ruling S66 (Codex round-25 P1 on #88): `version_bracketed()` reset
	 * every builder memo only from attempt 1 onward — never attempt 0. The
	 * `/status` route calls `reconcile()` BEFORE `status_fragment()`, and
	 * `reconcile()`'s own `Aura_Worker_Door_Holds::sweep()` call populates
	 * the held queue's process-wide `held_rows()` memo (Ruling P71) as a
	 * side effect, even when nothing is stale enough for it to delete.
	 *
	 * A hold that lands afterwards — from a genuinely concurrent request,
	 * whose write this process's own memo can never observe — bumps the
	 * door version but leaves that memo exactly as the sweep found it.
	 * Attempt 0's own before/after version reads then AGREE (nothing
	 * changes DURING the bracket; the mutation landed before it ever
	 * opened), so the torn-read check that catches every OTHER kind of
	 * race is blind to this one, and `status_fragment()` served a queue
	 * one hold short of what its own `observation` already claimed to
	 * reflect. The fix: `$reset_memos()` now runs at the top of EVERY
	 * attempt, attempt 0 included, so the bracket's `$builder()` never
	 * reads a memo older than the bracket itself.
	 */
	public function test_a_hold_landing_between_reconcile_and_the_bracket_is_not_served_stale(): void {
		$ref1 = $this->hold();

		// Establish a persisted baseline FIRST (held identity: [$ref1]) so
		// the measurement below is a steady-state poll, never the routine
		// version bump every FIRST-ever sync_computed_state() call makes to
		// persist its own baseline (Ruling S22) — that bump would retry into
		// attempt 1 on its own and mask the bug this test targets.
		$this->assertSame( array( $ref1 ), array_column( $this->fragment()['held'], 'ref' ), 'the fixture assumption this test is built on' );

		// Mirrors the real /status route: reconcile() runs next. Its own
		// sweep() call reads (and memoises) the held queue as a side
		// effect, even though nothing here is stale enough for it to
		// delete anything.
		Aura_Worker_Elementor_Door::reconcile();

		// A second hold, landing the way a genuinely CONCURRENT request's
		// would — written straight into the fixture store, never through
		// Aura_Worker_Door_Holds' own write path, so this process's
		// held_rows() memo (already populated by the reconcile() call
		// above) is never told. `bump_door_version()` models the version
		// bump that request's own transaction would have carried
		// atomically with its insert.
		$row1    = get_option( Aura_Worker_Door_Holds::HELD . $ref1, array() );
		$binding = is_array( $row1 ) && isset( $row1['binding'] ) ? $row1['binding'] : null;
		$this->assertNotNull( $binding, 'the fixture assumption this test is built on' );
		$ref2 = 'door_' . wp_generate_uuid4();
		$now  = time();
		$this->patchOption(
			Aura_Worker_Door_Holds::HELD . $ref2,
			array(
				'ref'        => $ref2,
				'binding'    => $binding,
				'ability'    => 'elementor/publish-document',
				'input'      => array(),
				'touches'    => array(),
				'actor'      => array( 'user_id' => 3, 'login' => 'bot' ),
				'verdict'    => 'none',
				'rule'       => null,
				'created_at' => gmdate( 'c', $now ),
				'expires_at' => gmdate( 'c', $now + 999999 ),
			)
		);
		$this->assertIsInt( Aura_Worker_Door_Log::bump_door_version(), 'the fixture assumption this test is built on' );

		// Attempt 0 must reset the memo BEFORE its own `$builder()` runs —
		// never serve the pre-mutation snapshot reconcile() left behind.
		$fragment = $this->fragment();
		$this->assertNotNull( $fragment['observation'] );
		$refs = array_column( $fragment['held'], 'ref' );
		sort( $refs, SORT_STRING );
		$expected = array( $ref1, $ref2 );
		sort( $expected, SORT_STRING );
		$this->assertSame( $expected, $refs, 'the new hold is served on the FIRST attempt — never the stale pre-reconcile memo' );
	}

	/**
	 * Ruling S67 (Codex round-25 P2 on #88): `count_unacked()` used to
	 * filter its own COUNT against `self::floor()` — `get_option()`'s
	 * cached read — rather than the proven `floor_raw()` a version bracket
	 * already takes for its own `log_floor` field. A request that cached
	 * the floor early (its own `reconcile()`, or an earlier poll) never
	 * sees a DIFFERENT request's `ack()` move it, the same class of race
	 * Ruling S66 closed for the held queue: `wp_cache_delete()` invalidates
	 * only the PROCESS that calls it, never a sibling's already-cached
	 * copy (WordPress's default object cache is per-request).
	 *
	 * Ruling S68 (Codex round-25 P1 on #88) later closed the sibling bug
	 * this test originally exploited to construct its fixture: `ack_write()`
	 * itself used to read the SAME `self::floor()` to decide whether its
	 * own purge should run, so a poisoned cache used to leave the
	 * just-acked row physically un-purged as a side effect. Now that
	 * `ack_write()` reads the floor RAW too, its purge is unconditional on
	 * this process's cache — see
	 * test_ack_write_purges_the_acked_row_even_with_a_stale_floor_cache()
	 * below for that guarantee on its own. This test now isolates
	 * `count_unacked()`'s OWN read-side behaviour instead, constructing
	 * directly the state a race could still leave: the raw floor moved,
	 * but the row below it has not (yet) been purged from THIS read's point
	 * of view — the raw floor read is what stops it being recounted, not
	 * a purge this test no longer depends on.
	 */
	public function test_an_acked_row_survives_a_stale_floor_cache_and_is_not_recounted(): void {
		$seq1 = $this->entry(); // will be "acked" below
		$seq2 = $this->entry(); // stays pending throughout

		// Establish a persisted baseline first (Ruling S22's own first-ever
		// bump, exactly the reason test_a_hold_landing_between_reconcile_
		// and_the_bracket_is_not_served_stale() above establishes one too),
		// so the measurement below is a steady-state poll.
		$this->assertSame( 2, $this->fragment()['log_unacked'], 'the fixture assumption this test is built on' );

		// Mirrors the real /status route: reconcile() runs next.
		Aura_Worker_Elementor_Door::reconcile();

		// The raw floor moves to $seq1, written straight into the fixture's
		// "database" — the shape a real ack()'s raw UPDATE takes, never
		// through update_option() — while $seq1's own row is deliberately
		// LEFT physically present: this test isolates count_unacked()'s own
		// floor selection from ack_write()'s separately-tested purge
		// guarantee, so a row genuinely below the true floor but not yet
		// purged (a purge still in flight elsewhere, a restore, or simply
		// this construction) is the thing a raw floor read must still
		// exclude on its own, without relying on the row being gone.
		$GLOBALS['_rows'][ Aura_Worker_Door_Log::FLOOR ] = (string) $seq1;
		// This process's OWN get_option() cache stays at the PRE-move
		// floor — modelling a read this request already made (reconcile()'s,
		// or an earlier poll's) before the floor moved elsewhere.
		$GLOBALS['_sa_option_cache'][ Aura_Worker_Door_Log::FLOOR ] = 0;

		// The raw floor genuinely moved...
		$this->assertSame( $seq1, Aura_Worker_Door_Log::floor_raw(), 'the fixture assumption this test is built on' );
		// ...while this process's OWN cached floor() did not...
		$this->assertSame( 0, Aura_Worker_Door_Log::floor(), 'the fixture assumption this test is built on' );
		// ...and $seq1's row is still physically there for a naive scan to
		// find, exactly as constructed above.
		$this->assertIsArray( Aura_Worker_Door_Log::get( $seq1 ), 'the fixture assumption this test is built on' );

		// The bracket must count against the PROVEN floor — never the
		// poisoned one — so only $seq2 (still genuinely pending) is
		// unacked, not $seq1 (below the true floor, merely un-purged).
		$fragment = $this->fragment();
		$this->assertNotNull( $fragment['observation'] );
		$this->assertSame( 1, $fragment['log_unacked'], 'the acked row is not recounted from a stale floor read' );

		// governor_block() shares the same fix.
		$this->assertSame( 1, Aura_Worker_Elementor_Door::governor_block()['log_unacked'] );
	}

	/**
	 * Ruling S68 (Codex round-25 P1 on #88 — the S31 class applied to the
	 * WRITE side): `ack_write()` used to read `self::floor()` — the SAME
	 * `get_option()`-cached accessor Ruling S67 fixed on the reporting
	 * side — to decide whether its OWN purge should run. A request whose
	 * cache had gone stale (this same test's own trick, before Ruling S68)
	 * saw its just-raised floor as still zero, skipped the purge entirely,
	 * and left the acked row physically present for a sibling request to
	 * recount — exactly the fixture test_an_acked_row_survives_a_stale_
	 * floor_cache_and_is_not_recounted() above now has to construct BY
	 * HAND, because this method no longer produces it as a side effect.
	 * Every floor read inside ack_write() is raw now, so a poisoned cache
	 * changes nothing about what it does: the purge runs, and the row is
	 * gone.
	 */
	public function test_ack_write_purges_the_acked_row_even_with_a_stale_floor_cache(): void {
		$seq1 = $this->entry();

		// A prior read (this test stands in for reconcile()'s own, or an
		// earlier poll's) cached the floor before it was ever raised.
		$GLOBALS['_sa_option_cache'][ Aura_Worker_Door_Log::FLOOR ] = 0;

		$epoch = Aura_Worker_Door_Log::epoch();
		$this->assertIsString( $epoch );
		$ack = Aura_Worker_Door_Log::ack( $epoch, $seq1 );
		$this->assertSame( 1, $ack['acked'] ?? null, 'the purge ran despite the poisoned cache' );
		$this->assertSame( $seq1, $ack['floor'] ?? null, 'the reported floor is the proven one, not the poisoned one' );

		// The raw floor moved...
		$this->assertSame( $seq1, Aura_Worker_Door_Log::floor_raw() );
		// ...this process's cached floor() is STILL poisoned (nothing here
		// ever clears `_sa_option_cache` — see DoorLogTest.php's own
		// precedent for this seam) — proving the purge below did not
		// merely happen to run because the cache was secretly fresh...
		$this->assertSame( 0, Aura_Worker_Door_Log::floor() );
		// ...and yet the row is PHYSICALLY GONE: ack_write()'s purge ran
		// against the proven raw floor, never the poisoned one.
		$this->assertNull( Aura_Worker_Door_Log::get( $seq1 ), 'the acked row is purged even though this process\'s floor cache never learned the floor moved' );
	}

	/**
	 * Ruling S61 (Codex round-23 P1 on #88): a wp_options RESTORE that
	 * happens to keep the epoch, the door version, and every field
	 * sync_computed_state()'s tuple already tracked (active/seam/door,
	 * rewind_top, running/interrupted, held) passes the steady-state fast
	 * path unnoticed even though it flipped ONE log row's own state --
	 * seq N settled back to `pending`, exactly the shape a restore to a
	 * snapshot predating that ack would produce while Aura's own cursor
	 * still sits at N. The persisted tuple now folds in a log-shape
	 * identity (top, floor, pending_count, terminal_count_above_floor,
	 * terminal_top) that this flip provably changes, so the FIRST serve
	 * that sees it is a real, versioned transition -- the SAME
	 * clock-floored bump mechanism Rulings S29/S45 already established.
	 */
	public function test_a_row_flipped_terminal_to_pending_with_no_bump_still_advances_the_observation(): void {
		$seq = $this->entry( array(), true, false ); // admitted, NOT backdated -- irrelevant to this test either way
		Aura_Worker_Door_Log::settle( $seq, array( 'result' => 'ok' ) );

		$before = $this->fragment();
		$v1     = $before['observation'];
		$this->assertIsInt( $v1, 'the fixture assumption this test is built on' );

		// The restore: seq $seq flips back to pending, raw -- no
		// versioned() unit runs, no bump of any kind.
		$this->patchOption( Aura_Worker_Door_Log::PREFIX . $seq, array( 'result' => 'pending' ) );

		$after = $this->fragment();
		$this->assertNotNull( $after['observation'], 'a single retry resolves this -- never "torn twice"' );
		$this->assertGreaterThan( $v1, $after['observation'], 'the restored row-state is a REAL transition, served under a witness strictly greater than the pre-restore poll' );

		// A second serve of the SAME (now steady) shape does not bump again.
		$again = $this->fragment();
		$this->assertSame( $after['observation'], $again['observation'], 'the flip is already recorded -- a repeat serve does not bump again' );
	}

	/**
	 * Ruling S64 (Codex round-24 P2 on #88): a restore that changes a
	 * TERMINAL row's own VERDICT (`ok` back to `failed`, say) while its
	 * seq, the floor, and every COUNT Ruling S61's own log-shape identity
	 * tracks stay unchanged -- the row is terminal before and after,
	 * still above the floor, so pending_count/terminal_count_above_floor/
	 * terminal_top all pass unnoticed. The new fingerprint (a sha1 over
	 * each row's own seq|status|content, sorted) is what catches this:
	 * the row's own CONTENT changed even though nothing about ITS SHAPE
	 * did.
	 */
	public function test_a_terminal_rows_verdict_flipped_with_no_bump_still_advances_the_observation(): void {
		$seq = $this->entry( array(), true, false );
		Aura_Worker_Door_Log::settle( $seq, array( 'result' => 'ok' ) );

		$before = $this->fragment();
		$v1     = $before['observation'];
		$this->assertIsInt( $v1, 'the fixture assumption this test is built on' );

		// The restore: seq $seq's own verdict flips from ok to failed,
		// raw -- no versioned() unit runs, no bump of any kind. Still
		// terminal, still the SAME seq, still above the SAME floor: every
		// count Ruling S61 tracks is unchanged by this flip alone.
		$this->patchOption( Aura_Worker_Door_Log::PREFIX . $seq, array( 'result' => 'failed' ) );

		$after = $this->fragment();
		$this->assertNotNull( $after['observation'], 'a single retry resolves this -- never "torn twice"' );
		$this->assertGreaterThan( $v1, $after['observation'], 'the flipped verdict is a REAL transition, served under a witness strictly greater than the pre-restore poll' );

		// A second serve of the SAME (now steady) shape does not bump again.
		$again = $this->fragment();
		$this->assertSame( $after['observation'], $again['observation'], 'the flip is already recorded -- a repeat serve does not bump again' );
	}

	/**
	 * Ruling S46 (Codex round-19, S45 class): the SAME transition-fold as
	 * `running`, for `interrupted` — a claim crosses into "stale,
	 * unleased" SOLELY by its own `claimed_at` ageing past CLAIM_STALE_MS,
	 * no mutation of its own.
	 */
	public function test_an_unleased_claim_crossing_the_stale_bound_with_no_mutation_still_bumps_the_observation(): void {
		$ref = $this->hold();
		$this->assertIsArray( Aura_Worker_Door_Holds::claim( $ref ) ); // fresh — NOT backdated

		$young = $this->fragment();
		$this->assertSame( array(), $young['interrupted'], 'the fixture assumption this test is built on — too young to be interrupted yet' );
		$v1 = $young['observation'];
		$this->assertIsInt( $v1 );

		// The clock advances past CLAIM_STALE_MS — nothing else mutates.
		$this->patchOption( Aura_Worker_Door_Holds::CLAIMED . $ref, array( 'claimed_at' => $this->longAgo() ) );

		$crossed = $this->fragment();
		$this->assertSame( array( $ref ), array_column( $crossed['interrupted'], 'ref' ), 'the crossing is now observed' );
		$this->assertNotNull( $crossed['observation'], 'a single retry (this attempt\'s own persist) resolves this — never "torn twice"' );
		$this->assertGreaterThan( $v1, $crossed['observation'], 'served under a witness STRICTLY greater than the young claim\'s own poll' );

		// A second serve of the SAME crossing is a steady state.
		$again = $this->fragment();
		$this->assertSame( $crossed['observation'], $again['observation'], 'the crossing is already recorded — a repeat serve does not bump again' );
		$this->assertSame( array( $ref ), array_column( $again['interrupted'], 'ref' ) );
	}

	/** An UNLEASED claim past the bound is interrupted, exactly as before. */
	public function test_an_unleased_claim_past_the_bound_is_still_interrupted(): void {
		$ref = $this->hold();
		$this->claim( $ref );

		$frag = $this->fragment();

		$this->assertSame( array( $ref ), array_column( $frag['interrupted'], 'ref' ) );
		$this->assertSame( array(), $frag['running'] );
	}

	/** A claim younger than the bound is in neither list. */
	public function test_a_fresh_claim_is_neither_interrupted_nor_running(): void {
		$ref = $this->hold();
		$this->claim( $ref, array(), true ); // fresh

		$frag = $this->fragment();

		$this->assertSame( array(), $frag['interrupted'] );
		$this->assertSame( array(), $frag['running'] );
	}

	/**
	 * Ruling S44 (Codex round-18 P2 on #88): partition_stale_claims() cast
	 * a failed claimed-queue read to `(array) null` — the SAME empty set a
	 * genuinely quiet queue answers — so neither stale_unleased_claims()
	 * nor running_claims() ever saw the difference, and BOTH of
	 * status_fragment()'s bracketing version reads agreed on a version
	 * that CERTIFIED an empty `interrupted`/`running`, over a queue that
	 * actually holds a stale, unleased claim.
	 */
	public function test_a_failing_claimed_queue_read_withholds_observation_and_never_certifies_empty_arrays(): void {
		$ref = $this->hold();
		$this->claim( $ref ); // backdated past CLAIM_STALE_MS — genuinely interrupted-eligible
		$this->assertSame( array( $ref ), array_column( $this->fragment()['interrupted'], 'ref' ), 'the fixture assumption this test is built on' );

		$key = $GLOBALS['wpdb']->esc_like( Aura_Worker_Door_Holds::CLAIMED );
		$GLOBALS['_sa_rows_read_error'][ $key ] = true;

		$frag = $this->fragment();

		$GLOBALS['_sa_rows_read_error'] = array();
		$this->assertNull( $frag['observation'], 'an unreadable claimed queue must not be paired with a confident observation' );
		$this->assertNull( $frag['interrupted'], 'never a certified-empty array over a queue this call could not read' );
		$this->assertNull( $frag['running'], 'the SAME uncertainty applies to both — one failed read feeds both lists' );
	}

	public function test_a_claim_younger_than_the_stale_bound_is_left_alone(): void {
		$ref = $this->hold();
		$this->claim( $ref, array(), true );

		$out = Aura_Worker_Elementor_Door::reconcile();

		$this->assertSame( 0, $out['interrupted'] );
		$this->assertSame( 0, $out['settled_claims'] );
		$this->assertNotNull( Aura_Worker_Door_Holds::get_claimed( $ref ), 'a replay in flight is not a replay that died' );
		$this->assertNull( Aura_Worker_Door_Log::get( 1 ), 'nothing was written' );
	}

	/* ------------------------------------------------------------------ */
	/* (b) a terminal_seq naming a terminal entry                          */
	/* ------------------------------------------------------------------ */

	public function test_a_claim_naming_a_terminal_entry_is_released_with_nothing_written(): void {
		$seq = $this->entry( array(), true, false );
		Aura_Worker_Door_Log::settle( $seq, array( 'result' => 'ok' ) );
		$ref = $this->hold();
		$this->claim( $ref, array( 'terminal_seq' => $seq ) );

		$out = Aura_Worker_Elementor_Door::reconcile();

		$this->assertSame( 0, $out['interrupted'] );
		$this->assertSame( 1, $out['settled_claims'] );
		$this->assertSame( 'ok', $this->row( $seq )['result'], 'the run finished; only the release was lost' );
		$this->assertNull( Aura_Worker_Door_Log::get( $seq + 1 ), 'no second entry for a call that already answered' );
		$this->assertNull( Aura_Worker_Door_Holds::get_claimed( $ref ) );
	}

	/* ------------------------------------------------------------------ */
	/* (c) a terminal_seq at or below the floor — the entry was acked      */
	/* ------------------------------------------------------------------ */

	public function test_a_claim_naming_an_acked_entry_is_released_with_nothing_written(): void {
		$seq = $this->entry( array(), true, false );
		Aura_Worker_Door_Log::settle( $seq, array( 'result' => 'ok' ) );
		Aura_Worker_Door_Log::ack( Aura_Worker_Door_Log::epoch(), $seq );
		$this->assertSame( $seq, Aura_Worker_Door_Log::floor() );
		$this->assertNull( Aura_Worker_Door_Log::get( $seq ), 'the row is gone; the floor is the evidence' );
		$ref = $this->hold();
		$this->claim( $ref, array( 'terminal_seq' => $seq ) );

		$out = Aura_Worker_Elementor_Door::reconcile();

		$this->assertSame( 0, $out['interrupted'] );
		$this->assertSame( 1, $out['settled_claims'] );
		$this->assertNull( Aura_Worker_Door_Holds::get_claimed( $ref ) );
		$this->assertSame( array(), Aura_Worker_Door_Log::log_after( 0 ), 'nothing was written above the floor' );
	}

	/* ------------------------------------------------------------------ */
	/* (d) a terminal_seq naming a pending entry                           */
	/* ------------------------------------------------------------------ */

	public function test_a_claim_naming_a_pending_entry_settles_that_entry_interrupted(): void {
		// The row's own `at` is FRESH: only the claim is stale, so this
		// settlement can have come from nowhere but the claim.
		$seq = $this->entry( array( 'snapshot_id' => 'snap_x' ), true, false );
		$ref = $this->hold();
		$this->claim( $ref, array( 'terminal_seq' => $seq ) );

		$out = Aura_Worker_Elementor_Door::reconcile();

		$this->assertSame( 1, $out['interrupted'] );
		$this->assertSame( 1, $out['settled_claims'] );
		$row = $this->row( $seq );
		$this->assertSame( 'interrupted', $row['result'] );
		$this->assertSame( 'snap_x', $row['snapshot_id'], 'the rollback point the dead request took stays on the entry' );
		$this->assertNull( Aura_Worker_Door_Log::get( $seq + 1 ), 'the entry it already had is settled, never a second one' );
		$this->assertNull( Aura_Worker_Door_Holds::get_claimed( $ref ) );
	}

	/* ------------------------------------------------------------------ */
	/* (e) an un-admitted stale row                                        */
	/* ------------------------------------------------------------------ */

	public function test_an_unadmitted_stale_row_is_discarded_and_still_served(): void {
		$seq = $this->entry( array(), false );

		$out = Aura_Worker_Elementor_Door::reconcile();

		$this->assertSame( 1, $out['discarded'] );
		$this->assertSame( 0, $out['interrupted'] );
		$row = $this->row( $seq );
		$this->assertSame( 'discarded', $row['result'] );
		$this->assertTrue( $row['admitted'], 'a discarded row is served, or every later entry waits behind it' );
		$this->assertSame( array( $seq ), array_column( Aura_Worker_Door_Log::log_after( 0 ), 'seq' ) );
	}

	/**
	 * Ruling S37/S38 (Codex round-15 class sweep on #88): stale_pending()'s
	 * own `get_results()` failing at the driver used to answer `array()` —
	 * the exact same shape as a scan that genuinely found nothing stale —
	 * so a failed scan and a healthy empty one were reported identically:
	 * `reconcile()` iterated zero rows either way and moved on. The stale
	 * row this scan would have found is left exactly as it was; the next
	 * clean sweep discards it.
	 */
	public function test_a_failed_stale_pending_scan_skips_the_pass_rather_than_discarding_nothing(): void {
		$seq = $this->entry( array(), false ); // un-admitted, stale — stale_pending() would normally find this
		$GLOBALS['_sa_stale_pending_read_error'] = true;

		$out = Aura_Worker_Elementor_Door::reconcile();

		$GLOBALS['_sa_stale_pending_read_error'] = false;
		$this->assertSame( 0, $out['discarded'], 'the scan never ran — nothing was concluded about it' );
		$this->assertSame( 0, $out['interrupted'] );
		$row = $this->row( $seq );
		$this->assertSame( 'pending', $row['result'], 'the row is untouched, exactly as a skipped pass leaves it' );

		// A healthy sweep right afterwards still finds and discards it.
		$out2 = Aura_Worker_Elementor_Door::reconcile();
		$this->assertSame( 1, $out2['discarded'] );
	}

	/* ------------------------------------------------------------------ */
	/* (f) a stale creation                                                */
	/* ------------------------------------------------------------------ */

	/** The row a died creation leaves: watermark, expected types, hooked ids. */
	private function staleCreation(): int {
		$this->seedPost( 10 );
		$this->seedPost( 11 );
		$this->seedPost( 12 );
		$seq = $this->entry(
			array(
				'ability'          => 'elementor/create-page',
				'post_watermark'   => 10,
				'expected_types'   => array( 'page' ),
				'created_post_ids' => array( 11 ),
				'started_at'       => $this->longAgo(),
			)
		);
		return $seq;
	}

	public function test_a_stale_creation_is_finished_from_the_rows_own_watermark(): void {
		$seq = $this->staleCreation();

		$out = Aura_Worker_Elementor_Door::reconcile();

		$this->assertSame( 1, $out['interrupted'] );
		$row = $this->row( $seq );
		$this->assertSame( 'interrupted', $row['result'] );
		$this->assertSame( array( 11 ), $row['created_post_ids'], 'only what the insert hook witnessed is this call\'s' );
		$this->assertSame( array( 12 ), $row['observed_by_watermark'] );
		$this->assertSame( array( 12 ), $row['unproven'], 'the diff\'s suspicion is recorded, never made restorable' );
		$this->assertSame( 1, $row['hook_missed'] );
		$this->assertNotEmpty( $row['snapshot_id'] );
		$rec = ( new Aura_Worker_Snapshots() )->get( $row['snapshot_id'] );
		$this->assertSame( 'creation', $rec['door_kind'] );
		$this->assertSame( array( 11 ), $rec['created_post_ids'], 'a restore of this envelope trashes 11 alone' );
	}

	/**
	 * Ruling P45: the pending-only settle IS the claim, so only one reconciler
	 * recovers a stale creation.
	 *
	 * Recovering first meant two `/status` polls could both run
	 * `finish_stale_creation()` — two envelopes for one creation, and worse, a
	 * loser whose snapshot failed calling compensate() and TRASHING the posts
	 * the winner had just made restorable.
	 */
	public function test_two_reconcilers_on_one_stale_creation_produce_exactly_one_envelope(): void {
		$seq = $this->staleCreation();
		// A SECOND `/status` poll landing inside the first one's settle — both
		// read the same pending row, which is the whole race. Fires once:
		// sa_before_swap() does not clear its own seam.
		$GLOBALS['_sa_before_swap'] = static function () {
			$GLOBALS['_sa_before_swap'] = null;
			// A racer, so it runs as if on its OWN connection (Ruling S8) —
			// reconcile()'s own writes each open their own versioned() unit,
			// which would nest inside this poll's still-open one otherwise.
			sa_on_another_connection( static function () {
				Aura_Worker_Elementor_Door::reconcile();
			} );
		};

		$out = Aura_Worker_Elementor_Door::reconcile();

		$this->assertSame( 0, $out['interrupted'], 'the nested poll settled it first; this one settles nothing' );
		$row = $this->row( $seq );
		$this->assertSame( 'interrupted', $row['result'] );
		$this->assertSame( array( 11 ), $row['created_post_ids'] );
		$this->assertArrayNotHasKey( 'compensated', $row, 'nothing was trashed' );
		$this->assertNotEmpty( $row['snapshot_id'], 'the winner enveloped the creation' );
		$this->assertNotNull( get_post( 11 ), 'and the created post still stands' );
		$this->assertCount(
			1,
			array_filter(
				(array) glob( WP_CONTENT_DIR . '/aura-backups/snapshots/snap_*.json' ),
				static function ( $f ) {
					$rec = json_decode( (string) file_get_contents( (string) $f ), true );
					return 'creation' === (string) ( $rec['door_kind'] ?? '' );
				}
			),
			'exactly one creation envelope'
		);
	}

	/**
	 * The live-owner case: the request that owns the row settles it while this
	 * poll is mid-sweep. The reconciler must neither envelope nor compensate —
	 * the owner's verdict stands and the site is the owner's to describe.
	 */
	public function test_an_owner_that_settles_first_leaves_the_reconciler_nothing_to_do(): void {
		$seq = $this->staleCreation();
		// The owner finishing late, between this poll's staleness read and its
		// settle.
		// Fires ONCE: sa_before_swap() does not clear its own seam, and the
		// settle below is itself a swap.
		$GLOBALS['_sa_before_swap'] = static function () use ( $seq ) {
			$GLOBALS['_sa_before_swap'] = null;
			// A racer, so it runs as if on its OWN connection (Ruling S8) —
			// see the test above.
			sa_on_another_connection( static function () use ( $seq ) {
				Aura_Worker_Door_Log::settle( $seq, array( 'result' => 'ok', 'created_post_ids' => array( 11 ) ) );
			} );
		};

		$out = Aura_Worker_Elementor_Door::reconcile();

		$this->assertSame( 0, $out['interrupted'], 'the owner won' );
		$row = $this->row( $seq );
		$this->assertSame( 'ok', $row['result'], "and its verdict stands" );
		$this->assertArrayNotHasKey( 'compensated', $row, 'the loser trashed nothing' );
		$this->assertNotNull( get_post( 11 ) );
		$this->assertSame(
			array(),
			array_filter(
				(array) glob( WP_CONTENT_DIR . '/aura-backups/snapshots/snap_*.json' ),
				static function ( $f ) {
					$rec = json_decode( (string) file_get_contents( (string) $f ), true );
					return 'creation' === (string) ( $rec['door_kind'] ?? '' );
				}
			),
			'and never enveloped a creation it does not own'
		);
	}

	/**
	 * Ruling P56: an ordinary governed write gets the same execution lease a
	 * replay does, so age is never mistaken for death here either.
	 *
	 * A creation that legitimately runs longer than CLAIM_STALE_MS was
	 * recovered underneath itself: `finish_stale_creation()` enveloped the
	 * posts it was still creating — or, when that snapshot failed,
	 * COMPENSATED and trashed them — while the callback was mid-flight, and
	 * the live request could then no longer record its real outcome.
	 */
	public function test_a_stale_creation_whose_seq_lease_is_held_is_left_alone(): void {
		$seq = $this->staleCreation();
		$GLOBALS['_sa_named_locks'][ Aura_Worker_Door_Holds::seq_lease_name( $seq ) ] = true;

		$out = Aura_Worker_Elementor_Door::reconcile();

		$this->assertSame( 0, $out['interrupted'], 'running, not dead' );
		$row = $this->row( $seq );
		$this->assertSame( 'pending', $row['result'], 'its own request still owns the outcome' );
		$this->assertArrayNotHasKey( 'snapshot_id', $row, 'nothing was enveloped' );
		$this->assertArrayNotHasKey( 'compensated', $row, 'and nothing was trashed' );
		$this->assertNotNull( get_post( 11 ) );
	}

	/** The same row with no lease held is settled and recovered, exactly as before. */
	public function test_a_stale_creation_with_no_seq_lease_is_still_recovered(): void {
		$seq = $this->staleCreation();

		$out = Aura_Worker_Elementor_Door::reconcile();

		$this->assertSame( 1, $out['interrupted'] );
		$row = $this->row( $seq );
		$this->assertSame( 'interrupted', $row['result'] );
		$this->assertSame( array( 11 ), $row['created_post_ids'] );
	}

	/**
	 * A server that cannot answer IS_USED_LOCK leaves it UNKNOWN, so the row
	 * counts as running under the 24-hour hard cap — and is recovered past it.
	 */
	public function test_an_unanswerable_seq_lease_is_running_under_the_hard_cap( ): void {
		$seq                             = $this->staleCreation();
		$GLOBALS['_sa_named_lock_error'] = true;

		$this->assertSame( 0, Aura_Worker_Elementor_Door::reconcile()['interrupted'], 'unknown ⇒ running' );

		$this->patchOption( Aura_Worker_Door_Log::PREFIX . $seq, array( 'at' => gmdate( 'c', time() - Aura_Worker_Door_Holds::LEASE_HARD_CAP_S - 60 ) ) );

		$this->assertSame( 1, Aura_Worker_Elementor_Door::reconcile()['interrupted'], 'and recovered past the cap' );
		$GLOBALS['_sa_named_lock_error'] = false;
	}

	public function test_a_stale_creation_whose_envelope_cannot_be_stored_is_compensated(): void {
		$seq = $this->staleCreation();

		$out = $this->withUnwritableSnapshots(
			static function () {
				return Aura_Worker_Elementor_Door::reconcile();
			}
		);

		$this->assertSame( 1, $out['interrupted'] );
		$row = $this->row( $seq );
		$this->assertSame( 'interrupted', $row['result'] );
		$this->assertSame( 'snapshot_failed', $row['reason'] );
		$this->assertSame( array( 11 ), $row['created_post_ids'], 'only what the insert hook witnessed is this call\'s' );
		$this->assertSame( array( 12 ), $row['observed_by_watermark'], '12 is recorded — recording is not attributing' );
		$this->assertSame( array( 11 ), $row['compensated'], 'only what the insert hook witnessed is trashed' );
		$this->assertSame( array(), $row['uncompensated'] );
		$this->assertSame( array( 12 ), $row['unproven'], 'a diff-only id is the watermark\'s suspicion, and is left alone' );
		$this->assertSame( 'trash', $row['compensated_by'] );
		$this->assertArrayNotHasKey( 'snapshot_id', $row );
		$this->assertSame( 'trash', get_post( 11 )->post_status );
		$this->assertSame( 'publish', get_post( 12 )->post_status, 'a page the same user may have made by hand survives' );
	}

	public function test_a_post_created_after_the_stale_window_is_not_attributed_at_all(): void {
		$this->seedPost( 10 );
		$this->seedPost( 11 );
		// The same actor, the same type, above the mark — but made an hour
		// after this creation was already stale. It is not this call's.
		$this->seedPost( 13, 'page', 3, gmdate( 'Y-m-d H:i:s', time() + 3600 ) );
		$seq = $this->entry(
			array(
				'ability'          => 'elementor/create-page',
				'post_watermark'   => 10,
				'expected_types'   => array( 'page' ),
				'created_post_ids' => array( 11 ),
				'started_at'       => $this->longAgo(),
			)
		);

		Aura_Worker_Elementor_Door::reconcile();

		$row = $this->row( $seq );
		$this->assertSame( array( 11 ), $row['created_post_ids'] );
		$this->assertArrayNotHasKey( 'observed_by_watermark', $row );
		$this->assertSame( 'publish', get_post( 13 )->post_status );
	}

	public function test_a_draft_modified_after_the_stale_window_is_not_attributed_by_its_zero_post_date(): void {
		$this->seedPost( 10 );
		$this->seedPost( 11 );
		// A draft's post_date_gmt is WordPress's zero date — it always
		// satisfies "<= $until", so the OLD bound (post_date_gmt) would
		// attribute this to the stale call no matter when it was really
		// made. Only post_modified_gmt places it in time: modified an hour
		// after this creation was already stale — the same actor, the same
		// expected type, above the mark, but not this call's (Codex round-1
		// P2).
		$until = time() - 1200 + (int) floor( Aura_Worker_Elementor_Door::CLAIM_STALE_MS / 1000 );
		$this->seedPost( 13, 'page', 3, '0000-00-00 00:00:00', 'draft', gmdate( 'Y-m-d H:i:s', $until + 3600 ) );
		$seq = $this->entry(
			array(
				'ability'          => 'elementor/create-page',
				'post_watermark'   => 10,
				'expected_types'   => array( 'page' ),
				'created_post_ids' => array( 11 ),
				'started_at'       => $this->longAgo(),
			)
		);

		// Under the store-unwritable compensation path too: only the
		// hooked witness (11) is ever compensated, but if the draft were
		// wrongly attributed it would have shown up as `unproven` — never
		// this call's, and never risked.
		$out = $this->withUnwritableSnapshots(
			static function () {
				return Aura_Worker_Elementor_Door::reconcile();
			}
		);

		$this->assertSame( 1, $out['interrupted'] );
		$row = $this->row( $seq );
		$this->assertSame( array( 11 ), $row['created_post_ids'], 'the draft is not this call\'s — only the hooked id is' );
		$this->assertArrayNotHasKey( 'observed_by_watermark', $row );
		$this->assertArrayNotHasKey( 'unproven', $row );
		$this->assertSame( array( 11 ), $row['compensated'] );
		$this->assertSame( 'trash', get_post( 11 )->post_status );
		$this->assertSame( 'draft', get_post( 13 )->post_status, 'an unrelated draft made after the window survives' );
	}

	/* ------------------------------------------------------------------ */
	/* (g) the hold sweep                                                  */
	/* ------------------------------------------------------------------ */

	public function test_the_sweep_expires_a_hold_and_spares_a_held_row_a_live_replay_is_still_moving(): void {
		// A FRESH claimed twin is a replay mid-move: claim() inserted the twin
		// and its own delete of the held row has not landed yet. Deleting the
		// held row here made that delete report 0 rows, which claim() reads as
		// "a reject won" — it then backs out by deleting the claimed twin too,
		// and the approval is gone for ever. So the sweep leaves it; the
		// twin's own age is what makes it anybody else's business.
		$claimed = $this->hold();
		$this->claim( $claimed, array(), true ); // fresh
		$GLOBALS['_options'][ Aura_Worker_Door_Holds::HELD . $claimed ] = array( 'ref' => $claimed, 'expires_at' => gmdate( 'c', time() + 600 ), 'binding' => Aura_Worker_Door_Log::binding() );
		$GLOBALS['_rows'][ Aura_Worker_Door_Holds::HELD . $claimed ]    = maybe_serialize( $GLOBALS['_options'][ Aura_Worker_Door_Holds::HELD . $claimed ] );
		$expired = $this->hold();
		$live    = $this->hold();
		// Expired only AFTER the last hold(): since Ruling P21 hold() itself
		// purges expired unclaimed rows under its lock, so a hold taken after
		// this patch would sweep the row before the reconciler ever saw it.
		$this->patchOption( Aura_Worker_Door_Holds::HELD . $expired, array( 'expires_at' => gmdate( 'c', time() - 60 ) ) );

		$out = Aura_Worker_Elementor_Door::reconcile();

		$this->assertSame( 1, $out['swept'], 'only the expired hold' );
		$this->assertNotNull( Aura_Worker_Door_Holds::get_held( $claimed ), 'the replay\'s own delete is still coming' );
		$this->assertNotNull( Aura_Worker_Door_Holds::get_claimed( $claimed ), 'the claim itself is not the sweep\'s to remove' );
		$this->assertNull( Aura_Worker_Door_Holds::get_held( $expired ) );
		$this->assertNotNull( Aura_Worker_Door_Holds::get_held( $live ) );
	}

	public function test_the_sweep_finishes_the_delete_of_a_replay_that_died_mid_move(): void {
		// Past CLAIM_STALE_MS the claim is nobody's live work any more, and
		// the held row it left behind IS the sweep's to remove.
		$ref = $this->hold();
		$this->claim( $ref ); // backdated past CLAIM_STALE_MS
		$GLOBALS['_options'][ Aura_Worker_Door_Holds::HELD . $ref ] = array( 'ref' => $ref, 'expires_at' => gmdate( 'c', time() + 600 ) );
		$GLOBALS['_rows'][ Aura_Worker_Door_Holds::HELD . $ref ]    = maybe_serialize( $GLOBALS['_options'][ Aura_Worker_Door_Holds::HELD . $ref ] );

		$this->assertSame( 1, Aura_Worker_Door_Holds::sweep( time(), Aura_Worker_Elementor_Door::CLAIM_STALE_MS ) );
		$this->assertNull( Aura_Worker_Door_Holds::get_held( $ref ) );
		$this->assertNotNull( Aura_Worker_Door_Holds::get_claimed( $ref ), 'the claim is the reconciler\'s, not the sweep\'s' );
	}

	/* ------------------------------------------------------------------ */
	/* (h) the creation mutex                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Ruling S37 (Codex round-15 class sweep on #88): a driver failure on
	 * the mutex's own read must not be read as "no mutex" (which the OLD
	 * code already handled the same as this — nothing here changes THAT
	 * answer) but the sweep must also never act on a read it cannot prove,
	 * so a genuinely stale mutex is left exactly as it was rather than
	 * evaluated against a value this call cannot trust.
	 */
	public function test_an_unreadable_creation_mutex_is_left_alone_this_pass(): void {
		Aura_Worker_Door_Log::insert_unique( Aura_Worker_Elementor_Door::CREATING, array( 'seq' => 9, 'started_at' => $this->longAgo() ) );
		$GLOBALS['_sa_option_read_fail'][ Aura_Worker_Elementor_Door::CREATING ] = true;

		Aura_Worker_Elementor_Door::reconcile();

		$GLOBALS['_sa_option_read_fail'] = array();
		$this->assertIsArray( get_option( Aura_Worker_Elementor_Door::CREATING, null ), 'left untouched — this pass could not prove anything about it' );

		// A healthy sweep right afterwards still clears it.
		Aura_Worker_Elementor_Door::reconcile();
		$this->assertFalse( get_option( Aura_Worker_Elementor_Door::CREATING, false ) );
	}

	public function test_a_stale_creation_mutex_is_cleared_and_a_live_one_is_kept(): void {
		Aura_Worker_Door_Log::insert_unique( Aura_Worker_Elementor_Door::CREATING, array( 'seq' => 9, 'started_at' => gmdate( 'c' ) ) );
		Aura_Worker_Elementor_Door::reconcile();
		$this->assertIsArray( get_option( Aura_Worker_Elementor_Door::CREATING, null ), 'a creation still inside its ten minutes owns the site' );

		$this->patchOption( Aura_Worker_Elementor_Door::CREATING, array( 'started_at' => $this->longAgo() ) );
		Aura_Worker_Elementor_Door::reconcile();
		$this->assertFalse( get_option( Aura_Worker_Elementor_Door::CREATING, false ), 'a mutex older than the stale bound is nobody\'s' );
	}

	/**
	 * Ruling P63 (F3): the creation mutex honours its own seq lease.
	 *
	 * The reconciler's ROW loop already skipped a pending row whose lease was
	 * held (Ruling P56), but this separate age-only cleanup did not — so an
	 * Elementor creation running longer than CLAIM_STALE_MS kept its row and
	 * LOST its mutex, and a second creation could acquire it and run beside the
	 * first. The mutex exists to make that impossible.
	 */
	public function test_a_mutex_whose_seq_lease_is_held_survives_however_old_it_is(): void {
		Aura_Worker_Door_Log::insert_unique( Aura_Worker_Elementor_Door::CREATING, array( 'seq' => 9, 'started_at' => $this->longAgo() ) );
		$GLOBALS['_sa_named_locks'][ Aura_Worker_Door_Holds::seq_lease_name( 9 ) ] = true;

		Aura_Worker_Elementor_Door::reconcile();

		$this->assertIsArray( get_option( Aura_Worker_Elementor_Door::CREATING, null ), 'running, not stale' );
	}

	/**
	 * Ruling P70 (F3): a row admitted on an engine WITHOUT named locks is
	 * bounded by the hard cap, not by the ten-minute age rule.
	 *
	 * Such a row never had a lease to lose, and asking the engine again is not
	 * a safe substitute: a site that gains locks between admission and this
	 * sweep answers "free" for a lock that was never taken, and the reconciler
	 * settles a write that is still running. The stamp on the row is what makes
	 * it bounded rather than blind.
	 */
	public function test_a_row_stamped_lease_unsupported_is_bounded_by_the_hard_cap(): void {
		$seq = $this->entry( array( 'lease' => Aura_Worker_Door_Holds::LEASE_UNSUPPORTED ) );

		// Past CLAIM_STALE_MS — and left alone, even though the engine now
		// answers IS_USED_LOCK perfectly well and calls the lock free.
		$out = Aura_Worker_Elementor_Door::reconcile();
		$this->assertSame( 0, $out['interrupted'], 'not the age rule' );
		$this->assertSame( 'pending', $this->row( $seq )['result'] ?? 'pending' );

		$this->patchOption( Aura_Worker_Door_Log::PREFIX . $seq, array( 'at' => gmdate( 'c', time() - Aura_Worker_Door_Holds::LEASE_HARD_CAP_S - 60 ) ) );
		$out = Aura_Worker_Elementor_Door::reconcile();

		$this->assertSame( 1, $out['interrupted'], 'and settled past the hard cap' );
		$this->assertSame( 'interrupted', $this->row( $seq )['result'] );
	}

	/**
	 * Ruling P77 (F2): an unreadable log top is not a top of zero.
	 *
	 * `get_var()` answers null for "no rows" and for a broken statement alike,
	 * and the `(int)` cast made both a valid-looking 0 — so any legitimate
	 * `door_after` above the ack floor read as a REWIND. Aura answers a rewind
	 * by rotating the epoch, which invalidates an in-flight ack and
	 * resynchronises the whole log, with nothing having been rewound.
	 */
	public function test_an_unreadable_log_top_reports_no_rewind_and_says_so(): void {
		$seq = $this->entry( array(), true, false );
		Aura_Worker_Door_Log::settle( $seq, array( 'result' => 'ok' ) );
		$epoch = Aura_Worker_Door_Log::epoch();
		$GLOBALS['_sa_door_top_error'] = true;

		// A cursor well above anything this site has: the shape that used to
		// read as a rewind the moment the top could not be established.
		$frag = Aura_Worker_Elementor_Door::status_fragment( 999, $epoch );

		$GLOBALS['_sa_door_top_error'] = false;
		$this->assertNull( $frag['rewind'], 'nothing was established, so nothing is reported' );
		$this->assertTrue( $frag['log_top_unreadable'], 'and Aura is told why' );
	}

	/** …and a readable top still detects a real rewind. */
	public function test_a_readable_top_still_detects_a_rewind(): void {
		$epoch = Aura_Worker_Door_Log::epoch();

		$frag = Aura_Worker_Elementor_Door::status_fragment( 999, $epoch );

		$this->assertSame( true, $frag['rewind']['detected'] );
		$this->assertFalse( $frag['log_top_unreadable'] );
	}

	/** A write cannot allocate a seq it cannot bound: retryable 503. */
	public function test_a_write_refuses_retryably_when_the_log_top_cannot_be_read(): void {
		$GLOBALS['_sa_door_top_error'] = true;

		$out = Aura_Worker_Door_Log::open_pending( array( 'ability' => 'elementor/publish-document' ) );

		$GLOBALS['_sa_door_top_error'] = false;
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aura_log_failed', $out->get_error_code() );
		$this->assertSame( 503, $out->get_error_data()['status'] );
	}

	/** …and an ack clamps nothing and writes nothing. */
	public function test_an_ack_acks_nothing_when_the_log_top_cannot_be_read(): void {
		$seq = $this->entry( array(), true, false );
		Aura_Worker_Door_Log::settle( $seq, array( 'result' => 'ok' ) );
		$floor = Aura_Worker_Door_Log::floor();
		$GLOBALS['_sa_door_top_error'] = true;

		$out = Aura_Worker_Door_Log::ack( Aura_Worker_Door_Log::epoch(), $seq );

		$GLOBALS['_sa_door_top_error'] = false;
		$this->assertSame( array( 'acked' => 0, 'floor' => $floor ), $out );
		$this->assertSame( $floor, Aura_Worker_Door_Log::floor(), 'the floor never moved' );
		$this->assertNotNull( Aura_Worker_Door_Log::get( $seq ), 'and the row is still there' );
	}

	/**
	 * Ruling P90 (F1): an ack that crosses a rotation advances nothing.
	 *
	 * The epoch check at the top of `ack()` reads the epoch and lets go of it,
	 * so a `/door/rotate` or a rebind installing a new one in between still had
	 * this ack advance the SHARED floor. After a rewind that is destructive: an
	 * old, high cursor from epoch A is clamped against epoch B's freshly
	 * written rows, and the purge then removes entries Aura has never seen.
	 */
	public function test_an_ack_that_crosses_a_rotation_raises_nothing_and_deletes_nothing(): void {
		$a = Aura_Worker_Door_Log::epoch();
		$seq = $this->entry( array(), true, false );
		Aura_Worker_Door_Log::settle( $seq, array( 'result' => 'ok' ) );
		$floor = Aura_Worker_Door_Log::floor();
		// The rotation lands between this ack's own epoch check and its floor
		// raise — the window the join closes.
		$GLOBALS['_sa_before_ack_floor_raise'] = static function () {
			$GLOBALS['_options'][ Aura_Worker_Door_Log::EPOCH ] = 'epoch-b';
			$GLOBALS['_rows'][ Aura_Worker_Door_Log::EPOCH ]    = 'epoch-b';
		};

		$out = Aura_Worker_Door_Log::ack( $a, $seq );

		$GLOBALS['_sa_before_ack_floor_raise'] = null;
		$this->assertSame( 0, $out['acked'] );
		$this->assertSame( $floor, Aura_Worker_Door_Log::floor(), 'the shared floor never moved' );
		$this->assertNotNull( Aura_Worker_Door_Log::get( $seq ), "and the new epoch's rows are still there" );
	}

	/** …and an ordinary ack under a steady epoch is unchanged. */
	public function test_an_ordinary_ack_raises_the_floor_and_purges(): void {
		$epoch = Aura_Worker_Door_Log::epoch();
		$seq   = $this->entry( array(), true, false );
		Aura_Worker_Door_Log::settle( $seq, array( 'result' => 'ok' ) );

		$out = Aura_Worker_Door_Log::ack( $epoch, $seq );

		$this->assertSame( 1, $out['acked'] );
		$this->assertSame( $seq, $out['floor'] );
		$this->assertNull( Aura_Worker_Door_Log::get( $seq ), 'the acked row is gone' );
	}

	/**
	 * Ruling P86 (F2): an entry the reconciler cannot READ retains the claim.
	 *
	 * `get()` answers null for a row that is absent and for a read that failed
	 * alike, and the fall-through treats null as "no evidence": it minted a
	 * SECOND `interrupted` entry for a ref that already had one, and released
	 * the claim on the strength of a read that proved nothing.
	 */
	public function test_a_claim_whose_entry_cannot_be_read_is_retained(): void {
		$seq = $this->entry( array(), true, false ); // pending, above the floor
		$ref = $this->hold();
		$this->claim( $ref, array( 'terminal_seq' => $seq ) );
		// The row is unreadable BOTH ways: this request's cache answers null for
		// it (what `get()` sees) and the raw read fails at the driver (what the
		// tri-state read sees). Only the second can tell absent from broken.
		$GLOBALS['_sa_option_cache'][ Aura_Worker_Door_Log::PREFIX . $seq ]     = null;
		$GLOBALS['_sa_option_read_fail'][ Aura_Worker_Door_Log::PREFIX . $seq ] = true;

		$out = Aura_Worker_Elementor_Door::reconcile();

		$GLOBALS['_sa_option_read_fail'] = array();
		$GLOBALS['_sa_option_cache']     = array();
		$this->assertSame( 0, $out['interrupted'], 'no second entry for a ref that already has one' );
		$this->assertSame( 0, $out['settled_claims'] );
		$this->assertNotNull( Aura_Worker_Door_Holds::get_claimed( $ref ), 'the claim is retained' );
		$this->assertNull( Aura_Worker_Door_Log::get( $seq + 1 ), 'and nothing was written' );
		$this->assertSame( 'pending', $this->row( $seq )['result'] );
	}

	/**
	 * Ruling P84 (F2): a lease is evidence of life for LEASE_HARD_CAP_S, and
	 * no longer.
	 *
	 * A named lock lives as long as the database CONNECTION that took it, and
	 * a persistent connection outlives the PHP request that borrowed it — so a
	 * request killed mid-callback can leave `IS_USED_LOCK` reporting a holder
	 * with nobody behind it. A held lock used to mean "running, whatever its
	 * age": the claim kept its queue slot, the pending row was never settled
	 * and the creation mutex was never cleared, permanently.
	 */
	public function test_a_lock_held_past_the_hard_cap_no_longer_protects_a_claim(): void {
		$ref = $this->hold();
		$this->claim( $ref );
		$GLOBALS['_sa_named_locks'][ Aura_Worker_Door_Holds::lease_name( $ref ) ] = true;
		$this->patchOption( Aura_Worker_Door_Holds::CLAIMED . $ref, array( 'claimed_at' => gmdate( 'c', time() - Aura_Worker_Door_Holds::LEASE_HARD_CAP_S - 60 ) ) );

		$out = Aura_Worker_Elementor_Door::reconcile();

		$this->assertSame( 1, $out['settled_claims'], 'a stranded lock does not hold a slot for ever' );
		$this->assertNull( Aura_Worker_Door_Holds::get_claimed( $ref ) );
	}

	/** …and inside the cap a held lock still means running. */
	public function test_a_lock_held_inside_the_hard_cap_still_protects_a_claim(): void {
		$ref = $this->hold();
		$this->claim( $ref ); // backdated past CLAIM_STALE_MS, well inside the cap
		$GLOBALS['_sa_named_locks'][ Aura_Worker_Door_Holds::lease_name( $ref ) ] = true;

		$out = Aura_Worker_Elementor_Door::reconcile();

		$this->assertSame( 0, $out['settled_claims'] );
		$this->assertNotNull( Aura_Worker_Door_Holds::get_claimed( $ref ), 'a replay that is genuinely running is left alone' );
	}

	/** The same rule for a pending ROW and for the creation mutex it owns. */
	public function test_a_lock_held_past_the_hard_cap_no_longer_protects_a_row_or_its_mutex(): void {
		$seq = $this->entry( array( 'at' => gmdate( 'c', time() - Aura_Worker_Door_Holds::LEASE_HARD_CAP_S - 60 ) ), true, false );
		Aura_Worker_Door_Log::insert_unique( Aura_Worker_Elementor_Door::CREATING, array( 'seq' => $seq, 'started_at' => gmdate( 'c', time() - Aura_Worker_Door_Holds::LEASE_HARD_CAP_S - 60 ) ) );
		$GLOBALS['_sa_named_locks'][ Aura_Worker_Door_Holds::seq_lease_name( $seq ) ] = true;

		$out = Aura_Worker_Elementor_Door::reconcile();

		$this->assertSame( 1, $out['interrupted'], 'the row is settled' );
		$this->assertSame( 'interrupted', $this->row( $seq )['result'] );
		$this->assertFalse( get_option( Aura_Worker_Elementor_Door::CREATING, false ), 'and the mutex is freed' );
	}

	/** The same mutex with no lease held is cleared by age, exactly as before. */
	public function test_an_old_mutex_with_no_seq_lease_is_still_cleared(): void {
		Aura_Worker_Door_Log::insert_unique( Aura_Worker_Elementor_Door::CREATING, array( 'seq' => 9, 'started_at' => $this->longAgo() ) );

		Aura_Worker_Elementor_Door::reconcile();

		$this->assertFalse( get_option( Aura_Worker_Elementor_Door::CREATING, false ) );
	}

	/** A server that cannot answer IS_USED_LOCK keeps it under the hard cap. */
	public function test_an_unanswerable_mutex_lease_keeps_it_under_the_hard_cap(): void {
		Aura_Worker_Door_Log::insert_unique( Aura_Worker_Elementor_Door::CREATING, array( 'seq' => 9, 'started_at' => $this->longAgo() ) );
		$GLOBALS['_sa_named_lock_error'] = true;

		Aura_Worker_Elementor_Door::reconcile();

		$this->assertIsArray( get_option( Aura_Worker_Elementor_Door::CREATING, null ), 'unknown ⇒ running' );

		$this->patchOption( Aura_Worker_Elementor_Door::CREATING, array( 'started_at' => gmdate( 'c', time() - Aura_Worker_Door_Holds::LEASE_HARD_CAP_S - 60 ) ) );
		Aura_Worker_Elementor_Door::reconcile();

		$GLOBALS['_sa_named_lock_error'] = false;
		$this->assertFalse( get_option( Aura_Worker_Elementor_Door::CREATING, false ), 'and cleared past the cap' );
	}

	public function test_the_stale_mutex_clear_never_removes_a_creation_that_took_the_row_after_it_read(): void {
		// The reconciler reads the mutex, judges it stale, and deletes. A
		// creation starting in between takes the row for itself — and an
		// unconditional delete_option() there closes a live creation's mutex,
		// letting a second creation run beside it. The delete is fenced on the
		// bytes the read returned, so it matches nothing (Ruling P5).
		Aura_Worker_Door_Log::insert_unique( Aura_Worker_Elementor_Door::CREATING, array( 'seq' => 9, 'started_at' => gmdate( 'c' ) ) );
		$this->patchOption( Aura_Worker_Elementor_Door::CREATING, array( 'started_at' => $this->longAgo() ) );
		$fresh = array( 'seq' => 10, 'started_at' => gmdate( 'c' ) );
		$GLOBALS['_sa_before_fenced_delete'][ Aura_Worker_Elementor_Door::CREATING ] = static function () use ( $fresh ) {
			$GLOBALS['_options'][ Aura_Worker_Elementor_Door::CREATING ] = $fresh;
			$GLOBALS['_rows'][ Aura_Worker_Elementor_Door::CREATING ]    = maybe_serialize( $fresh );
		};

		Aura_Worker_Elementor_Door::reconcile();

		$this->assertSame( $fresh, get_option( Aura_Worker_Elementor_Door::CREATING, null ), 'the fresh creation still owns the site' );
		$this->assertSame( maybe_serialize( $fresh ), $GLOBALS['_rows'][ Aura_Worker_Elementor_Door::CREATING ] ?? null );
	}

	/* ------------------------------------------------------------------ */
	/* (i) the fragment's shape, and reconcile() running before it         */
	/* ------------------------------------------------------------------ */

	public function test_the_fragment_reports_the_held_queue_without_inputs_and_the_log_up_to_a_pending_entry(): void {
		$ref = $this->hold();
		$one = $this->entry( array(), true, false );
		Aura_Worker_Door_Log::settle( $one, array( 'result' => 'ok' ) );
		$this->entry( array(), true, false );          // seq 2 stays pending
		$three = $this->entry( array(), true, false ); // terminal, but behind it
		Aura_Worker_Door_Log::settle( $three, array( 'result' => 'ok' ) );

		$frag = $this->fragment();

		$this->assertSame( Aura_Worker_Door_Log::epoch(), $frag['epoch'] );
		$this->assertSame( 'open', $frag['door'] );
		$this->assertSame( array( $ref ), array_column( $frag['held'], 'ref' ) );
		$this->assertArrayNotHasKey( 'input', $frag['held'][0], 'the operator sees what the call touches, never its payload' );
		$this->assertSame( array( 1 ), array_column( $frag['log'], 'seq' ), 'the log stops at the pending entry' );
		$this->assertSame( 0, $frag['log_floor'] );
		$this->assertSame( 3, $frag['log_unacked'] );
		$this->assertNull( $frag['log_full'], 'an open log has no closure report' );
		$this->assertSame( array(), $frag['interrupted'] );
	}

	public function test_get_status_reconciles_before_it_builds_the_fragment(): void {
		$ref = $this->hold();
		$this->claim( $ref );

		$data = $this->api->get_status( new WP_REST_Request( 'GET', '/aura/v1/status' ) )->get_data();

		$this->assertIsObject( $data['door'], 'an OBJECT on the wire, whatever it contains' );
		$door = (array) $data['door'];
		$this->assertArrayHasKey( 'log', $door );
		$this->assertSame( array( 'interrupted' ), array_column( $door['log'], 'result' ), 'the same response that reports the door has already settled it' );
		$this->assertSame( $ref, $door['log'][0]['ref'] );
		$this->assertSame( array(), $door['interrupted'], 'settled and released, so nothing is still hanging' );
	}

	public function test_a_site_with_no_door_reports_no_fragment_at_all(): void {
		unset( $GLOBALS['_sa_force_door'] );
		Aura_Worker_Elementor_Door::reset_for_tests();
		$this->assertNull( Aura_Worker_Elementor_Door::status_fragment( 0, '' ) );
		$data = $this->api->get_status( new WP_REST_Request( 'GET', '/aura/v1/status' ) )->get_data();
		$this->assertArrayNotHasKey( 'door', $data, 'Aura keys on the fragment being absent' );
	}

	/* ------------------------------------------------------------------ */
	/* Ruling P28: persisted door state outlives Elementor                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Elementor deactivated, or its MCP module turned off, between one
	 * request and the next: active() is false from here on, and the whole
	 * fragment used to vanish with it.
	 */
	private function elementorGoesAway(): void {
		unset( $GLOBALS['_sa_force_door'] );
		Aura_Worker_Elementor_Door::reset_for_tests(); // the presence memo is per-request
		$this->assertFalse( Aura_Worker_Elementor_Door::active() );
	}

	/**
	 * Ruling P28: what the door persisted is reported whether or not
	 * Elementor is still there. Dropping the fragment on the next request hid
	 * outstanding approvals and terminal results from Aura for as long as the
	 * plugin stayed off.
	 */
	public function test_a_door_whose_elementor_went_away_still_reports_its_state(): void {
		$ref = $this->hold();
		$one = $this->entry( array(), true, false );
		Aura_Worker_Door_Log::settle( $one, array( 'result' => 'ok' ) );
		$epoch = Aura_Worker_Door_Log::epoch(); // the site was polled while the door was live

		$this->elementorGoesAway();

		$frag = Aura_Worker_Elementor_Door::status_fragment( 0, $epoch );
		$this->assertIsArray( $frag, 'persisted door state is enough to report' );
		$this->assertFalse( $frag['active'], 'and the fragment says Elementor is gone' );
		$this->assertSame( $epoch, $frag['epoch'] );
		$this->assertSame( 'unchecked', $frag['seam'], 'nothing verified a seam that is not there' );
		$this->assertSame( 'closed', $frag['door'] );
		$this->assertSame( array( $ref ), array_column( $frag['held'], 'ref' ), 'the holds are still awaiting Aura' );
		$this->assertSame( array( $one ), array_column( $frag['log'], 'seq' ), 'and the log is still served' );
	}

	/** The audit's block, same rule: the FULL block, with `active: false`. */
	public function test_the_audit_block_still_reports_a_door_whose_elementor_went_away(): void {
		$this->hold();
		$epoch = Aura_Worker_Door_Log::epoch();

		$this->elementorGoesAway();

		$block = Aura_Worker_Elementor_Door::governor_block();
		$this->assertFalse( $block['active'] );
		$this->assertSame( $epoch, $block['epoch'] );
		$this->assertSame( 1, $block['held_count'] );
		$this->assertSame( 'closed', $block['door'] );
		$this->assertArrayHasKey( 'log_unacked', $block, 'the full block, not { active: false } alone' );
	}

	/**
	 * The other half of the same bug: the gate that dropped the fragment also
	 * skipped reconcile(), so stale claims and pending rows waited for
	 * Elementor to come back. `/status` is the only clock this site has.
	 */
	public function test_get_status_still_reconciles_when_elementor_went_away(): void {
		$seq = $this->entry( array(), false ); // stale, never admitted
		Aura_Worker_Door_Log::epoch();

		$this->elementorGoesAway();

		$data = $this->api->get_status( new WP_REST_Request( 'GET', '/aura/v1/status' ) )->get_data();

		$this->assertIsObject( $data['door'] );
		$door = (array) $data['door'];
		$this->assertFalse( $door['active'] );
		$this->assertSame( 'discarded', $this->row( $seq )['result'], 'settled in the very response that reports it' );
		$this->assertSame( array( $seq ), array_column( $door['log'], 'seq' ) );
	}

	/**
	 * Ruling P30: no Elementor is itself a closed door.
	 *
	 * `verify_coverage()`'s inactive branch leaves the seam `ok` on purpose —
	 * there is nothing uncovered, and closing the transport would 503 a route
	 * that does not exist (Ruling P6). But an `ok` seam alone then reported
	 * `door: open` on a site where no governed write could possibly run, in
	 * all three readers at once.
	 */
	public function test_a_door_whose_elementor_went_away_is_closed_however_healthy_its_seam(): void {
		$this->hold();
		Aura_Worker_Door_Log::epoch();

		$this->elementorGoesAway();
		do_action( 'wp_abilities_api_init' ); // verify_coverage()'s inactive branch: seam `ok`

		$this->assertSame( 'ok', Aura_Worker_Elementor_Door::seam(), 'nothing is broken — Elementor is simply gone' );
		$this->assertFalse( Aura_Worker_Door_Log::is_closed(), 'and the log is nowhere near full' );
		$this->assertSame( 'closed', Aura_Worker_Elementor_Door::door_state() );
		$this->assertSame( 'closed', Aura_Worker_Elementor_Door::status_fragment( 0, '' )['door'], 'the fragment agrees' );
		$this->assertSame( 'closed', Aura_Worker_Elementor_Door::governor_block()['door'], 'and the audit block' );
	}

	/** The same three readers on a live, healthy door: still `open`. */
	public function test_a_live_door_with_a_verified_seam_and_an_open_log_is_open(): void {
		do_action( 'wp_abilities_api_init' );

		$this->assertTrue( Aura_Worker_Elementor_Door::active() );
		$this->assertSame( 'ok', Aura_Worker_Elementor_Door::seam() );
		$this->assertSame( 'open', Aura_Worker_Elementor_Door::door_state() );
		$this->assertSame( 'open', $this->fragment()['door'] );
		$this->assertSame( 'open', Aura_Worker_Elementor_Door::governor_block()['door'] );
	}

	/**
	 * Ruling P35: the epoch is minted when door state is CREATED.
	 *
	 * `present()` reads the epoch option as the single witness that this site
	 * has a door, and only `status_fragment()` used to mint one — so a write
	 * (or a hold) followed by Elementor being disabled BEFORE the first
	 * `/status` poll left rows nothing would ever report or reconcile. The
	 * fragment was omitted and reconcile() skipped, indefinitely.
	 */
	public function test_a_log_row_written_before_the_first_poll_mints_the_epoch(): void {
		delete_option( Aura_Worker_Door_Log::EPOCH );
		unset( $GLOBALS['_options'][ Aura_Worker_Door_Log::EPOCH ], $GLOBALS['_rows'][ Aura_Worker_Door_Log::EPOCH ] );
		$this->assertSame( '', (string) get_option( Aura_Worker_Door_Log::EPOCH, '' ), 'nothing has minted one yet' );

		$seq = $this->entry( array(), false ); // a row, and no /status poll

		$epoch = (string) get_option( Aura_Worker_Door_Log::EPOCH, '' );
		$this->assertNotSame( '', $epoch, 'the row minted it' );

		$this->elementorGoesAway();

		$this->assertTrue( Aura_Worker_Elementor_Door::present() );
		$this->assertIsArray( Aura_Worker_Elementor_Door::status_fragment( 0, '' ) );
		$data = $this->api->get_status( new WP_REST_Request( 'GET', '/aura/v1/status' ) )->get_data();
		$this->assertIsObject( $data['door'] );
		$this->assertSame( 'discarded', $this->row( $seq )['result'], 'and the reconciler settled it' );
	}

	/** The same for a HOLD: the queue is door state too. */
	public function test_a_hold_taken_before_the_first_poll_mints_the_epoch(): void {
		delete_option( Aura_Worker_Door_Log::EPOCH );
		unset( $GLOBALS['_options'][ Aura_Worker_Door_Log::EPOCH ], $GLOBALS['_rows'][ Aura_Worker_Door_Log::EPOCH ] );

		$ref = $this->hold();

		$this->assertNotSame( '', (string) get_option( Aura_Worker_Door_Log::EPOCH, '' ) );

		$this->elementorGoesAway();

		$this->assertTrue( Aura_Worker_Elementor_Door::present() );
		$frag = Aura_Worker_Elementor_Door::status_fragment( 0, '' );
		$this->assertIsArray( $frag );
		$this->assertSame( array( $ref ), array_column( $frag['held'], 'ref' ), 'the queue is reported, not stranded' );
	}

	/** And a site that never had a door still reports nothing at all (Ruling P6). */
	public function test_a_site_with_no_door_and_nothing_persisted_reports_nothing(): void {
		$this->elementorGoesAway();

		$this->assertSame( '', (string) get_option( Aura_Worker_Door_Log::EPOCH, '' ), 'nothing was ever minted' );
		$this->assertNull( Aura_Worker_Elementor_Door::status_fragment( 0, '' ) );
		$this->assertSame( array( 'active' => false ), Aura_Worker_Elementor_Door::governor_block() );
		$data = $this->api->get_status( new WP_REST_Request( 'GET', '/aura/v1/status' ) )->get_data();
		$this->assertArrayNotHasKey( 'door', $data, 'Aura keys on the fragment being absent' );
	}

	/**
	 * Ruling P24: a full log IS a closed door. Every governed write answers
	 * `aura_log_full` once the log reaches MAX_UNACKED, and governor_block()
	 * and the ack response both say `closed` — the status fragment went on
	 * reporting `open` because its own field only ever described the seam.
	 */
	/**
	 * Ruling S39 (Codex round-16 P2 on #88): is_closed_raw() answering
	 * `false` for both "genuinely not closed" and "the read could not be
	 * proven" used to feed door_state() a fabricated "open" on a log that
	 * is actually FULL — sync_computed_state() would then compare that
	 * fabrication against the persisted (correctly `closed`) tuple, see
	 * what looks like a real transition, persist the fabrication, and bump
	 * the observation for it. Neither may happen: the read failure must
	 * leave the persisted `closed` value standing and withhold the witness
	 * for this one poll.
	 */
	public function test_a_suppressed_closure_marker_read_never_fabricates_open_over_a_persisted_closed(): void {
		$this->assertSame( 'open', $this->fragment()['door'], 'nothing is wrong yet' );

		Aura_Worker_Door_Log::close();
		$frag2 = $this->fragment();
		$this->assertSame( 'closed', $frag2['door'], 'the fixture assumption this test is built on — closed is genuinely PERSISTED now' );
		$version_before = Aura_Worker_Door_Log::door_version_raw();

		$GLOBALS['_sa_option_read_fail'][ Aura_Worker_Door_Log::FULL_MARKER ] = true;
		$frag3 = $this->fragment();
		$GLOBALS['_sa_option_read_fail'] = array();

		$this->assertNull( $frag3['observation'], 'an unreadable closure marker must not be paired with a confident observation' );
		$this->assertSame( 'closed', $frag3['door'], 'the PERSISTED value stands — never the fabricated "open" a false is_closed_raw() would compute live' );
		$this->assertSame( $version_before, Aura_Worker_Door_Log::door_version_raw(), 'no CAS write landed — the version never moved' );
	}

	/**
	 * Ruling S42 (Codex round-17 P2 on #88): full_report_raw() answering a
	 * fabricated `''`/`0` for a since/refused read that could not be
	 * proven — indistinguishable from a log that genuinely closed with no
	 * refusals yet — reported that fabrication under whatever observation
	 * the poll still claimed. The unreadable field now reports null
	 * instead, independently of its sibling (which is still reported when
	 * it read fine), and observation is withheld for the poll.
	 */
	public function test_a_suppressed_refusal_counter_read_on_a_closed_log_never_reports_a_false_zero(): void {
		Aura_Worker_Door_Log::close();
		Aura_Worker_Door_Log::bump_refused(); // a real refusal, so a false 0 would be a real lie
		$frag1 = $this->fragment();
		$this->assertSame( 1, $frag1['log_full']['refused'], 'the fixture assumption this test is built on' );

		$GLOBALS['_sa_option_read_fail'][ Aura_Worker_Door_Log::FULL_COUNTER ] = true;
		$frag2 = $this->fragment();
		$GLOBALS['_sa_option_read_fail'] = array();

		$this->assertNull( $frag2['observation'], 'an unreadable refusal counter must not be paired with a confident observation' );
		$this->assertNotNull( $frag2['log_full'], 'the log is still genuinely closed — is_closed_raw() itself read fine' );
		$this->assertNull( $frag2['log_full']['refused'], 'never a false 0 over a real refusal this call could not read' );
		$this->assertIsString( $frag2['log_full']['since'], 'the SIBLING field, which read fine, is still reported' );
	}

	public function test_a_closed_log_is_reported_as_a_closed_door(): void {
		$this->assertSame( 'open', $this->fragment()['door'], 'nothing is wrong yet' );

		Aura_Worker_Door_Log::close();

		$frag = $this->fragment();
		$this->assertSame( 'closed', $frag['door'] );
		$this->assertNotNull( $frag['log_full'], 'and it says why' );
		$this->assertSame( 'ok', $frag['seam'], 'the seam itself is healthy — the LOG is what closed the door' );
		$this->assertSame( 'closed', Aura_Worker_Elementor_Door::governor_block()['door'], 'the audit agrees' );

		delete_option( Aura_Worker_Door_Log::FULL_MARKER ); // as an ack under the bound reopens it
		$this->assertSame( 'open', $this->fragment()['door'] );
	}

	/**
	 * Ruling P27, the reconciler's half: the live request settled this entry
	 * between the staleness read and the write. The entry EXISTS, so the
	 * claim is finished — keeping it would strand the hold until the next
	 * sweep, every poll, for ever.
	 */
	public function test_a_stale_claim_whose_entry_turns_terminal_under_it_is_released(): void {
		$ref = $this->hold();
		$seq = $this->entry( array( 'ran' => true ), true, true );
		$this->claim( $ref, array( 'terminal_seq' => $seq ) );
		// The owning request settles it in the window between the
		// reconciler's staleness read and its own write.
		$GLOBALS['_sa_before_swap'] = static function () use ( $seq ) {
			if ( false === strpos( (string) $GLOBALS['wpdb']->last_query, Aura_Worker_Door_Log::PREFIX . $seq ) ) {
				return;
			}
			$row = $GLOBALS['_options'][ Aura_Worker_Door_Log::PREFIX . $seq ];
			if ( 'pending' !== ( $row['result'] ?? '' ) ) {
				return;
			}
			$row['result']                                              = 'ok';
			$row['settled_at']                                          = gmdate( 'c' );
			$GLOBALS['_options'][ Aura_Worker_Door_Log::PREFIX . $seq ] = $row;
			$GLOBALS['_rows'][ Aura_Worker_Door_Log::PREFIX . $seq ]    = maybe_serialize( $row );
		};

		$out = Aura_Worker_Elementor_Door::reconcile();

		$this->assertSame( 0, $out['interrupted'], 'nothing was written over the owner\'s verdict' );
		$this->assertSame( 1, $out['settled_claims'] );
		$this->assertSame( 'ok', $this->row( $seq )['result'] );
		$this->assertNull( Aura_Worker_Door_Holds::get_claimed( $ref ), 'the claim is released, not stranded' );
	}

	/**
	 * The other half of Ruling P25: the row a failed terminal-only settle
	 * leaves is DISCARDED, never `interrupted` — nothing ran under it.
	 */
	public function test_a_terminal_only_entry_whose_settle_fails_is_discarded_not_interrupted(): void {
		$GLOBALS['_sa_option_cas_fail']['aura_worker_door_log_1'] = static function ( $value ) {
			return false !== strpos( (string) $value, 'settled_at' );
		};

		$written = $this->recordTerminalOnly( 'held', array( 'ref' => 'door_x' ) );

		$this->assertFalse( $written, 'the caller is told its evidence is not durable' );
		$row = $this->row( 1 );
		$this->assertSame( 'pending', $row['result'] );
		$this->assertFalse( $row['admitted'], 'the settle is what admits it, and it did not land' );
		$this->assertSame( 1, $this->counter( 'log_ungoverned' ) );

		unset( $GLOBALS['_sa_option_cas_fail']['aura_worker_door_log_1'] );
		$this->patchOption( Aura_Worker_Door_Log::PREFIX . 1, array( 'at' => $this->longAgo() ) );

		$out = Aura_Worker_Elementor_Door::reconcile();

		$this->assertSame( 1, $out['discarded'] );
		$this->assertSame( 0, $out['interrupted'], 'nothing ever ran under this number' );
		$this->assertSame( 'discarded', $this->row( 1 )['result'] );
		$this->assertSame( array( 1 ), array_column( Aura_Worker_Door_Log::log_after( 0 ), 'seq' ), 'and a terminal row is served, so the log is not blocked' );
	}

	/** The governor's private terminal-only writer — the unit under test here. */
	private function recordTerminalOnly( string $result, array $extra ): bool {
		$m = new ReflectionMethod( Aura_Worker_Elementor_Door::class, 'record_terminal_only' );
		if ( PHP_VERSION_ID < 80100 ) {
			$m->setAccessible( true );
		}
		return (bool) $m->invoke( null, 'elementor/publish-document', array( 'user_id' => 3, 'login' => 'bot' ), array( array( 'type' => 'page', 'id' => '7' ) ), $result, $extra );
	}

	/** The rolling counter bump_counter() writes. */
	private function counter( string $name ): int {
		return (int) get_option( 'aura_worker_door_c_' . $name . '_h' . (int) floor( time() / HOUR_IN_SECONDS ), 0 );
	}

	/* ------------------------------------------------------------------ */
	/* (j) a rewound log is REPORTED; /status never rotates (Ruling P20)   */
	/* ------------------------------------------------------------------ */

	/**
	 * `/status` is a read. It used to rotate the epoch on an impossible
	 * cursor, which handed anyone holding the site token a way to invalidate
	 * every ack Aura was about to send — repeat it between the poll and
	 * `/door/ack` and the unacked rows climb to MAX_UNACKED and close the
	 * write door, with no grant anywhere.
	 */
	public function test_a_cursor_above_every_row_reports_a_rewind_and_rotates_nothing(): void {
		$one = $this->entry( array(), true, false );
		Aura_Worker_Door_Log::settle( $one, array( 'result' => 'ok' ) );
		Aura_Worker_Door_Log::close();
		Aura_Worker_Door_Log::bump_refused();
		$before = Aura_Worker_Door_Log::epoch();

		$frag = $this->fragment( 40, $before );

		$this->assertSame( array( 'detected' => true, 'top' => 1 ), $frag['rewind'], 'reported, with the top Aura should have been at' );
		$this->assertSame( $before, $frag['epoch'], 'the epoch is UNCHANGED' );
		$this->assertSame( $before, get_option( Aura_Worker_Door_Log::EPOCH ), 'and nothing was written' );
		$this->assertNotFalse( get_option( Aura_Worker_Door_Log::FULL_MARKER, false ), 'a read clears no closure state either' );
		$this->assertNotFalse( get_option( Aura_Worker_Door_Log::FULL_COUNTER, false ) );
		$this->assertSame( array( 1 ), array_column( $frag['log'], 'seq' ), 'the impossible cursor is ignored and the log served from 0' );
	}

	public function test_a_rotation_on_a_site_that_has_acked_keeps_the_floor_and_goes_on_serving(): void {
		$epoch = Aura_Worker_Door_Log::epoch();
		for ( $i = 1; $i <= 3; $i++ ) {
			$seq = $this->entry( array(), true, false );
			Aura_Worker_Door_Log::settle( $seq, array( 'result' => 'ok' ) );
		}
		Aura_Worker_Door_Log::ack( $epoch, 2 ); // rows 1 and 2 deleted, floor 2

		$rotation = Aura_Worker_Door_Log::rotate_epoch( $epoch ); // Aura's decision, through /door/rotate
		$new      = $rotation['epoch'];

		$this->assertTrue( $rotation['rotated'] );
		$this->assertNotSame( $epoch, $new );
		$frag = $this->fragment( 0, $new );
		$this->assertSame( 2, $frag['log_floor'], 'the ack floor survived the rotation' );
		$this->assertSame( array( 3 ), array_column( $frag['log'], 'seq' ), 'row 3 is served — the deleted 1 and 2 are below the floor, not a hole' );

		$fourth = $this->entry( array(), true, false );
		Aura_Worker_Door_Log::settle( $fourth, array( 'result' => 'ok' ) );
		$this->assertSame( array( 3, 4 ), array_column( $this->fragment( 0, $new )['log'], 'seq' ), 'the log did not wedge' );
	}

	public function test_a_cursor_above_the_floor_but_at_an_existing_row_is_no_rewind(): void {
		$one = $this->entry( array(), true, false );
		Aura_Worker_Door_Log::settle( $one, array( 'result' => 'ok' ) );
		$before = Aura_Worker_Door_Log::epoch();

		$frag = $this->fragment( 1, $before ); // a failed ack: the row is still there

		$this->assertNull( $frag['rewind'] );
		$this->assertSame( $before, $frag['epoch'] );
		$this->assertSame( array(), $frag['log'], 'served from the cursor, which is where Aura says it is' );
	}

	public function test_a_cursor_from_another_epoch_is_ignored_and_is_no_rewind(): void {
		$one = $this->entry( array(), true, false );
		Aura_Worker_Door_Log::settle( $one, array( 'result' => 'ok' ) );
		$before = Aura_Worker_Door_Log::epoch();

		$frag = $this->fragment( 40, 'a-different-epoch' );

		$this->assertNull( $frag['rewind'], 'a cursor from another epoch says nothing about this log — not even that it rewound' );
		$this->assertSame( $before, $frag['epoch'] );
		$this->assertSame( array( 1 ), array_column( $frag['log'], 'seq' ), 'served from 0' );
	}

	/**
	 * Ruling S29 (Codex round-13 P1 on #88): `wp_options` restored to a
	 * snapshot whose persisted computed tuple `{ active, seam, door }`
	 * still matches today's LIVE values takes `sync_computed_state()`'s
	 * steady-state fast path — nothing versions, so both of
	 * `status_fragment()`'s bracket reads answer the restored, LOWER
	 * version, and the fragment (including `rewind.detected`) is REJECTED
	 * by Aura's strictly-greater comparison: recovery stalls until some
	 * UNRELATED mutation happens to advance the version again.
	 *
	 * `rewind_top` — the top the fragment builder observed WHEN a rewind is
	 * detected — is folded into that same tuple, so the FIRST serve that
	 * detects one looks like a real transition and is versioned through the
	 * SAME fenced CAS, which bumps via `Aura_Worker_Door_Log::versioned()`'s
	 * ordinary CLOCK-FLOORED bump (Ruling S4) — a restore rolls the STORED
	 * counter back but never the WALL CLOCK, so this one bump already lands
	 * above the restored value.
	 */
	public function test_a_detected_rewind_persists_as_a_transition_and_bumps_above_the_restored_version(): void {
		$one = $this->entry( array(), true, false ); // seq 1, admitted and settled below
		Aura_Worker_Door_Log::settle( $one, array( 'result' => 'ok' ) );
		$epoch = Aura_Worker_Door_Log::epoch();

		// A steady poll first — no rewind — to establish a persisted
		// computed tuple (rewind_top: null) at some version X.
		$first = $this->fragment( 0, $epoch );
		$this->assertNull( $first['rewind'] );
		$x = $first['observation'];
		$this->assertIsInt( $x, 'a real witness to restore "back to"' );

		// Simulate the restore: the door version reads X again — the SAME
		// value this call just saw — while the persisted computed tuple is
		// UNTOUCHED (still matches today's live active/seam/door): exactly
		// the condition that took the OLD code's steady-state fast path
		// and skipped the bump entirely.
		$GLOBALS['_rows'][ Aura_Worker_Door_Log::OBSERVATION ]    = (string) $x;
		$GLOBALS['_options'][ Aura_Worker_Door_Log::OBSERVATION ] = $x;

		// Aura's cursor is now above the site's real top (seq 1) — a rewind.
		$second = $this->fragment( 40, $epoch );

		$this->assertSame( array( 'detected' => true, 'top' => 1 ), $second['rewind'] );
		$this->assertIsInt( $second['observation'] );
		$this->assertGreaterThan( $x, $second['observation'], 'the rewind is a transition and must bump ABOVE the restored value, clock-floored' );

		// A second serve of the SAME rewind condition is now a steady
		// state: the tuple (rewind_top included) already matches what was
		// just persisted, so nothing bumps again.
		$third = $this->fragment( 40, $epoch );
		$this->assertSame( array( 'detected' => true, 'top' => 1 ), $third['rewind'], 'still reported — Aura has not rotated' );
		$this->assertSame( $second['observation'], $third['observation'], 'the rewind is already recorded — a repeat detection does not bump again' );
	}

	/**
	 * Ruling S31 (Codex round-14 P1 on #88): on the default non-persistent
	 * object cache, a `pending` log row this PROCESS already read through
	 * `Aura_Worker_Door_Log::get()` stays cached for the rest of the
	 * request — a DIFFERENT request settling that same row and bumping the
	 * observation does not, and cannot, evict THIS process's own copy.
	 * `status_fragment()`'s retry reset (Ruling S20) only ever cleared the
	 * held-row memo and `$active`, so a poll built after such a settle
	 * could still agree its bracket reads landed on the NEW version while
	 * serving the STALE cached row underneath it — the version comparison
	 * only proves the version did not move mid-build, never that
	 * everything read to build the fragment was fresh. Every read the
	 * builder performs is RAW now (never routed through `get_option()`),
	 * so there is no cache for a stale value to survive in.
	 */
	public function test_the_builder_never_serves_a_row_this_process_already_cached_as_pending(): void {
		$seq   = $this->entry(); // admitted, still pending
		$epoch = Aura_Worker_Door_Log::epoch();
		$name  = Aura_Worker_Door_Log::PREFIX . $seq;

		// Prime THIS process's own object cache with the row's PENDING
		// state — modelling an earlier get() read in this same request
		// that a non-persistent object cache would keep answering for the
		// rest of it, whatever any OTHER process does afterwards.
		$pending_row = Aura_Worker_Door_Log::get( $seq );
		$this->assertSame( 'pending', $pending_row['result'] );
		$GLOBALS['_sa_option_cache'][ $name ] = $pending_row;

		$before = Aura_Worker_Door_Log::door_version_raw();

		// A DIFFERENT, faster request: settles the row for real, straight
		// into the "database", and bumps the version. Its own
		// wp_cache_delete() calls (inside a real settle()) would only ever
		// evict ITS OWN process's cache — never this one's — so this is
		// modelled as a raw write, exactly as a separate process's commit
		// would land from this process's point of view.
		$terminal_row = array_merge( $pending_row, array( 'result' => 'ok' ) );
		$GLOBALS['_rows'][ $name ]    = maybe_serialize( $terminal_row );
		$GLOBALS['_options'][ $name ] = $terminal_row;
		Aura_Worker_Door_Log::bump_door_version();

		$GLOBALS['_db_queries'] = array();
		$frag                   = $this->fragment( 0, $epoch );

		$this->assertNotSame( $before, $frag['observation'], 'the racer really did bump the version — otherwise this test proves nothing' );
		$this->assertCount( 1, $frag['log'] );
		$this->assertSame( 'ok', $frag['log'][0]['result'], 'the TERMINAL state is served, never the pending row this process cached earlier' );
		// Ruling S36 (Codex round-15 P1 on #88): get_raw() now reads through
		// the SAME proven nonce-probe raw_option_read() uses (Ruling S1),
		// not a bare `SELECT option_value ...` — the query shape changed,
		// the guarantee (a real statement, never this process's own cache)
		// did not.
		$found = false;
		foreach ( $GLOBALS['_db_queries'] as $q ) {
			if ( preg_match( "/^SELECT '[^']*' AS probe, \(SELECT option_value FROM \S+ WHERE option_name = '" . preg_quote( $name, '/' ) . "' LIMIT 1\) AS v\$/", (string) $q ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'the row was read RAW — a real, proven statement, never satisfied from this process\'s own cache' );
	}

	/**
	 * Ruling S33 (Codex round-15 P1 on #88): `status_fragment()`'s version
	 * bracket used to open AFTER `detect_rewind()` had already run — so a
	 * rotation landing WHILE that method (or the reads immediately after
	 * it) executed fell entirely outside the window the before/after
	 * version compare protects. Both reads would agree on the NEW version
	 * while the fragment served epoch/site/rewind values `detect_rewind()`
	 * read against the OLD one. The bracket now opens before
	 * `detect_rewind()` runs at all, so this exact race is now INSIDE it:
	 * the mismatch forces a retry, and the retry re-reads a fresh epoch.
	 */
	public function test_a_rotation_during_detect_rewind_is_caught_and_the_retry_serves_the_new_epoch(): void {
		$old_epoch = Aura_Worker_Door_Log::epoch();
		$new_epoch = 'rotated-epoch-' . wp_generate_uuid4();

		// floor_raw() is the LAST raw read detect_rewind() performs before
		// returning — firing the racer here models a rotation landing right
		// as detect_rewind() finishes, the exact window this ruling closes.
		$GLOBALS['_sa_after_option_read'] = static function ( string $name ) use ( $new_epoch ) {
			if ( Aura_Worker_Door_Log::FLOOR !== $name ) {
				return;
			}
			$GLOBALS['_sa_after_option_read'] = null; // fires once

			$epoch_name                            = Aura_Worker_Door_Log::EPOCH;
			$GLOBALS['_rows'][ $epoch_name ]        = $new_epoch;
			$GLOBALS['_options'][ $epoch_name ]     = $new_epoch;
			Aura_Worker_Door_Log::bump_door_version();
		};

		$frag = $this->fragment( 0, $old_epoch );

		$GLOBALS['_sa_after_option_read'] = null;

		$this->assertNotNull( $frag['observation'], 'a single retry resolves this — never "torn twice"' );
		$this->assertSame(
			$new_epoch,
			$frag['epoch'],
			'the served fragment carries the POST-rotation epoch — the retry re-ran detect_rewind() inside the (correctly widened) bracket, never the stale value the first pass read'
		);
		$this->assertNotSame( $old_epoch, $frag['epoch'] );
	}

	/**
	 * Ruling S36 (Codex round-15 P1 on #88): a transient SELECT failure on
	 * ONE row mid-`log_after()`-walk used to read exactly like a hole —
	 * `get_raw()` answered `null` either way — so the walk silently stopped
	 * short while the bracket's before/after version reads still agreed,
	 * serving a TRUNCATED log under a witness that claimed to be current.
	 * `get_raw()` now proves the row unreadable (`false`, never `null`) and
	 * the fragment withholds `observation` for that poll instead — the
	 * rows read before the failure are still served, exactly as a site
	 * with no raw reads at all would have served them.
	 */
	public function test_an_unreadable_row_mid_walk_withholds_observation_but_still_serves_the_rows_read_so_far(): void {
		$before_reason = Aura_Worker_Door_Log::observation_unsupported_reason();

		$one = $this->entry( array(), true, false );
		Aura_Worker_Door_Log::settle( $one, array( 'result' => 'ok' ) );
		$two = $this->entry( array(), true, false );
		Aura_Worker_Door_Log::settle( $two, array( 'result' => 'ok' ) );
		$three = $this->entry( array(), true, false );
		Aura_Worker_Door_Log::settle( $three, array( 'result' => 'ok' ) );

		// The THIRD row's own SELECT never proves readable — a transient
		// driver failure, never a genuine hole (the row is right there).
		$GLOBALS['_sa_option_read_fail'][ Aura_Worker_Door_Log::PREFIX . $three ] = true;

		$frag = $this->fragment( 0, Aura_Worker_Door_Log::epoch() );

		$this->assertNull( $frag['observation'], 'a log walk that could not finish must not vouch for what it read' );
		$this->assertCount( 2, $frag['log'], 'the rows read BEFORE the failure are still served — never an emptier page than a site with no raw reads at all would serve' );
		$this->assertSame( array( $one, $two ), array_column( $frag['log'], 'seq' ) );
		$this->assertSame(
			$before_reason,
			Aura_Worker_Door_Log::observation_unsupported_reason(),
			'a per-row read failure is not an engine-level reason — that field is untouched by this'
		);
	}

	/**
	 * Ruling S38 (Codex round-16 P1 on #88): floor_raw() answering `null`
	 * for both "no floor" and "the read could not be proven" used to
	 * collapse a transient failure to 0 — log_after() then started ITS
	 * walk at row 1, mistook the first already-acked (purged) row for a
	 * hole, and returned no terminal rows at all, while `observation`
	 * still carried the current witness as if that empty page were
	 * proven complete.
	 */
	public function test_a_failing_floor_read_withholds_observation_and_never_misreads_purged_rows_as_a_hole(): void {
		$one = $this->entry( array(), true, false );
		Aura_Worker_Door_Log::settle( $one, array( 'result' => 'ok' ) );
		Aura_Worker_Door_Log::ack( Aura_Worker_Door_Log::epoch(), $one ); // floor moves past row 1
		$two = $this->entry( array(), true, false );
		Aura_Worker_Door_Log::settle( $two, array( 'result' => 'ok' ) );
		$this->assertSame( array( $two ), array_column( $this->fragment( 0, Aura_Worker_Door_Log::epoch() )['log'], 'seq' ), 'the fixture assumption this test is built on — floor_raw() is genuinely > 0' );

		$GLOBALS['_sa_option_read_fail'][ Aura_Worker_Door_Log::FLOOR ] = true;

		$frag = $this->fragment( 0, Aura_Worker_Door_Log::epoch() );

		$GLOBALS['_sa_option_read_fail'] = array();
		$this->assertNull( $frag['observation'], 'an unreadable floor must not be paired with a confident observation' );
		$this->assertNull( $frag['rewind'], 'the floor failure must not be misread as evidence of a rewind' );
	}

	/**
	 * Ruling S41 (Codex round-17 P1 on #88): detect_rewind() consumed
	 * floor_raw()'s fabricated 0 in its own `max($max, floor_raw())`
	 * BEFORE ever checking whether that floor was proven readable — so a
	 * perfectly healthy cursor sitting AT the real (merely unreadable)
	 * floor, with nothing retained above it, looked like it landed above
	 * a falsely-lowered top: `rewind.detected` served, `after` reset to
	 * 0, on a log that was never rewound. Withholding `observation`
	 * afterwards (Ruling S38) does not un-serve an already-served
	 * `rewind.detected` — the verdict itself must never be manufactured
	 * from an unreadable floor in the first place.
	 */
	public function test_a_failing_floor_read_never_manufactures_a_rewind_verdict(): void {
		$seq = $this->entry( array(), true, false );
		Aura_Worker_Door_Log::settle( $seq, array( 'result' => 'ok' ) );
		Aura_Worker_Door_Log::ack( Aura_Worker_Door_Log::epoch(), $seq ); // floor moves to $seq; the row is purged
		$this->assertSame( 0, Aura_Worker_Door_Log::highest_row_seq(), 'the fixture assumption this test is built on — nothing is retained above the floor' );

		$GLOBALS['_sa_option_read_fail'][ Aura_Worker_Door_Log::FLOOR ] = true;

		// A cursor sitting exactly AT the real floor — the ordinary,
		// healthy case a site with nothing pending answers every poll.
		$frag = $this->fragment( $seq, Aura_Worker_Door_Log::epoch() );

		$GLOBALS['_sa_option_read_fail'] = array();
		$this->assertNull( $frag['rewind'], 'never a fabricated rewind over an unreadable floor' );
		$this->assertNull( $frag['observation'], 'withheld for this poll (Ruling S38)' );
	}

	/**
	 * Ruling S37 sweep, part 2 (Codex round-17 on #88): an unreadable
	 * epoch collapsed to '' in epoch_raw(), which detect_rewind() then
	 * compared against Aura's own remembered epoch — almost always a
	 * mismatch, resetting `after` to 0 exactly as a genuine epoch change
	 * does. A perfectly healthy cursor then had the log walk re-serve
	 * rows Aura had already acknowledged, and the epoch this call could
	 * not prove was reported as a definite `''`.
	 */
	public function test_a_failing_epoch_read_never_resets_the_cursor_or_reports_a_fabricated_epoch(): void {
		$epoch = Aura_Worker_Door_Log::epoch();
		$one   = $this->entry( array(), true, false );
		Aura_Worker_Door_Log::settle( $one, array( 'result' => 'ok' ) );
		$two = $this->entry( array(), true, false );
		Aura_Worker_Door_Log::settle( $two, array( 'result' => 'ok' ) );
		$this->assertSame( array( $two ), array_column( $this->fragment( $one, $epoch )['log'], 'seq' ), 'the fixture assumption this test is built on — a healthy read never re-serves row 1' );

		$GLOBALS['_sa_option_read_fail'][ Aura_Worker_Door_Log::EPOCH ] = true;

		$frag = $this->fragment( $one, $epoch );

		$GLOBALS['_sa_option_read_fail'] = array();
		$this->assertNull( $frag['rewind'], 'never a fabricated rewind over an unreadable epoch' );
		$this->assertNull( $frag['observation'], 'withheld for this poll' );
		$this->assertSame( array( $two ), array_column( $frag['log'], 'seq' ), 'the cursor was served UNCHANGED — never reset to 0 over an epoch this call could not prove had changed' );
	}

	public function test_get_status_reads_the_cursor_and_the_epoch_from_the_request(): void {
		$one = $this->entry( array(), true, false );
		Aura_Worker_Door_Log::settle( $one, array( 'result' => 'ok' ) );
		$two = $this->entry( array(), true, false );
		Aura_Worker_Door_Log::settle( $two, array( 'result' => 'ok' ) );

		$req = new WP_REST_Request( 'GET', '/aura/v1/status' );
		$req->set_param( 'door_after', '1' );
		$req->set_param( 'door_epoch', Aura_Worker_Door_Log::epoch() );
		$door = (array) $this->api->get_status( $req )->get_data()['door'];

		$this->assertSame( array( 2 ), array_column( $door['log'], 'seq' ) );
	}

	/* ------------------------------------------------------------------ */
	/* (k) a claim whose entry cannot be written is KEPT                   */
	/* ------------------------------------------------------------------ */

	public function test_a_stale_claim_whose_entry_cannot_be_written_is_kept_and_reported_every_poll(): void {
		$ref = $this->hold();
		$this->claim( $ref );
		Aura_Worker_Door_Log::close(); // a closed log takes no row at all

		$out = Aura_Worker_Elementor_Door::reconcile();

		$this->assertSame( 0, $out['interrupted'] );
		$this->assertSame( 0, $out['settled_claims'] );
		$this->assertNotNull( Aura_Worker_Door_Holds::get_claimed( $ref ), 'the claim is the only evidence a replay may have mutated the site' );
		$frag = $this->fragment();
		$this->assertSame( array( $ref ), array_column( $frag['interrupted'], 'ref' ) );
		$this->assertNotSame( '', $frag['interrupted'][0]['claimed_at'] );

		// The log reopens; the next poll writes the entry and lets the claim go.
		delete_option( Aura_Worker_Door_Log::FULL_MARKER );
		$out = Aura_Worker_Elementor_Door::reconcile();

		$this->assertSame( 1, $out['interrupted'] );
		$this->assertSame( 1, $out['settled_claims'] );
		$this->assertSame( $ref, $this->row( 1 )['ref'] );
		$this->assertNull( Aura_Worker_Door_Holds::get_claimed( $ref ) );
		$this->assertSame( array(), $this->fragment()['interrupted'] );
	}

	/* ------------------------------------------------------------------ */
	/* (l) the ack floor is in the fragment                                */
	/* ------------------------------------------------------------------ */

	public function test_the_fragment_carries_the_ack_floor(): void {
		$one = $this->entry( array(), true, false );
		Aura_Worker_Door_Log::settle( $one, array( 'result' => 'ok' ) );
		Aura_Worker_Door_Log::ack( Aura_Worker_Door_Log::epoch(), $one );

		$frag = $this->fragment();

		$this->assertSame( 1, $frag['log_floor'], 'Aura reads its own committed prefix back off the site' );
		$this->assertSame( 0, $frag['log_unacked'] );
	}

	/* ------------------------------------------------------------------ */
	/* Retention                                                           */
	/* ------------------------------------------------------------------ */

	public function test_the_reconciler_prunes_only_door_envelopes_older_than_thirty_days(): void {
		$snaps = new Aura_Worker_Snapshots();
		$this->seedPost( 21 );
		$old = $snaps->snapshot_creation( array( 21 ), 'page', array( 'seq' => 1 ) );
		$this->assertTrue( $old['success'] );
		$new = $snaps->snapshot_creation( array( 21 ), 'page', array( 'seq' => 2 ) );
		$this->assertTrue( $new['success'] );
		$this->ageSnapshot( $old['snapshot']['id'], 40 );

		$out = Aura_Worker_Elementor_Door::reconcile();

		$this->assertSame( 1, $out['pruned'] );
		$this->assertNull( $snaps->get( $old['snapshot']['id'] ) );
		$this->assertIsArray( $snaps->get( $new['snapshot']['id'] ) );
		$this->assertNotSame( '', (string) get_option( Aura_Worker_Elementor_Door::PRUNED_AT, '' ), 'the run is stamped' );
	}

	public function test_retention_runs_at_most_once_every_six_hours(): void {
		$snaps = new Aura_Worker_Snapshots();
		$this->seedPost( 21 );
		$first = $snaps->snapshot_creation( array( 21 ), 'page', array( 'seq' => 1 ) );
		$this->ageSnapshot( $first['snapshot']['id'], 40 );

		$this->assertSame( 1, Aura_Worker_Elementor_Door::reconcile()['pruned'] );
		$stamp = (string) get_option( Aura_Worker_Elementor_Door::PRUNED_AT, '' );

		// A second envelope, just as expired, and another poll a minute later:
		// `/status` is the hottest endpoint this site has, and the sweep reads
		// every envelope on disk.
		$second = $snaps->snapshot_creation( array( 21 ), 'page', array( 'seq' => 2 ) );
		$this->ageSnapshot( $second['snapshot']['id'], 40 );

		$this->assertSame( 0, Aura_Worker_Elementor_Door::reconcile()['pruned'], 'the gate skipped, so nothing was swept and the counter says so' );
		$this->assertIsArray( $snaps->get( $second['snapshot']['id'] ), 'the sweep did not run' );
		$this->assertSame( $stamp, (string) get_option( Aura_Worker_Elementor_Door::PRUNED_AT, '' ), 'a skipped run does not re-stamp' );

		// Six hours on.
		update_option( Aura_Worker_Elementor_Door::PRUNED_AT, gmdate( 'c', time() - Aura_Worker_Elementor_Door::PRUNE_INTERVAL_S - 60 ) );

		$this->assertSame( 1, Aura_Worker_Elementor_Door::reconcile()['pruned'] );
		$this->assertNull( $snaps->get( $second['snapshot']['id'] ) );
	}

	public function test_the_reconciler_prunes_door_counter_buckets_past_the_thirty_day_window(): void {
		// bump_counter() copied Aura_Worker_Rules::bump()'s atomic upsert but
		// not its sweep, so every hour of every counter was kept for ever —
		// 720 rows per name is the WINDOW, not the total.
		$now     = time();
		$hour    = (int) floor( $now / HOUR_IN_SECONDS );
		$edge    = (int) floor( ( $now - 30 * DAY_IN_SECONDS ) / HOUR_IN_SECONDS ); // still inside
		$outside = $edge - 1;
		$this->seedBucket( 'log_ungoverned', $hour, 3 );
		$this->seedBucket( 'log_ungoverned', $edge, 5 );
		$this->seedBucket( 'log_ungoverned', $outside, 100 );
		$this->seedBucket( 'unobserved', $outside - 500, 7 );

		$out = Aura_Worker_Elementor_Door::reconcile();

		$this->assertSame( 2, $out['pruned_counters'] );
		$this->assertArrayNotHasKey( 'aura_worker_door_c_log_ungoverned_h' . $outside, $GLOBALS['_rows'] );
		$this->assertArrayNotHasKey( 'aura_worker_door_c_unobserved_h' . ( $outside - 500 ), $GLOBALS['_rows'] );
		$this->assertArrayHasKey( 'aura_worker_door_c_log_ungoverned_h' . $edge, $GLOBALS['_rows'], 'the oldest bucket the window still covers survives' );
		$this->assertSame( 8, Aura_Worker_Elementor_Door::count_30d( 'log_ungoverned', $now ), 'and the survivors still sum' );
	}

	/**
	 * Ruling S19 (Codex round-7 P2 on #88): `prune_counters()` deleted its
	 * expired buckets with a plain `delete_option()` loop, never through
	 * `versioned()` — unlike `bump_counter()`'s own upsert (Ruling S9) and
	 * `Aura_Worker_Door_Log::bump_refused()`, which both advance the door
	 * version in the SAME transaction as their write. A `/status` poll
	 * landing right after a prune therefore saw fewer `*_30d` rows under an
	 * UNCHANGED observation — the exact hole Ruling S6 closed for every
	 * other door mutation, left open for this one. `prune_counters()` now
	 * runs its whole pass — every expired bucket's delete — as ONE
	 * `versioned()` unit.
	 */
	public function test_a_prune_that_deletes_a_bucket_advances_the_door_version(): void {
		$outside = (int) floor( ( time() - 31 * DAY_IN_SECONDS ) / HOUR_IN_SECONDS );
		$this->seedBucket( 'log_ungoverned', $outside, 100 );
		$before = Aura_Worker_Door_Log::door_version_raw();

		$out = Aura_Worker_Elementor_Door::reconcile();

		$this->assertSame( 1, $out['pruned_counters'] );
		$after = Aura_Worker_Door_Log::door_version_raw();
		$this->assertNotNull( $after );
		if ( null !== $before ) {
			$this->assertGreaterThan( $before, $after, 'a pruning deletion is a door mutation like any other and must advance the version' );
		}
	}

	public function test_the_counter_prune_shares_the_retention_gate(): void {
		// Bounded like the envelope sweep, and by the same stamp: `/status` is
		// the hottest endpoint this site has (Ruling P9(a)).
		$outside = (int) floor( ( time() - 31 * DAY_IN_SECONDS ) / HOUR_IN_SECONDS );
		Aura_Worker_Elementor_Door::reconcile(); // stamps PRUNED_AT
		$this->seedBucket( 'log_ungoverned', $outside, 100 );

		$this->assertSame( 0, Aura_Worker_Elementor_Door::reconcile()['pruned_counters'], 'the gate skipped' );
		$this->assertArrayHasKey( 'aura_worker_door_c_log_ungoverned_h' . $outside, $GLOBALS['_rows'] );

		update_option( Aura_Worker_Elementor_Door::PRUNED_AT, gmdate( 'c', time() - Aura_Worker_Elementor_Door::PRUNE_INTERVAL_S - 60 ) );

		$this->assertSame( 1, Aura_Worker_Elementor_Door::reconcile()['pruned_counters'] );
		$this->assertArrayNotHasKey( 'aura_worker_door_c_log_ungoverned_h' . $outside, $GLOBALS['_rows'] );
	}

	/** One counter bucket, in the "database" get_col() reads and the cache alike. */
	/**
	 * Ruling S37 (Codex round-15 class sweep on #88): count_30d()'s own
	 * `get_results()` failing at the driver used to answer 0 — the exact
	 * same shape as "no events in the last 30 days", a real, meaningful
	 * fact that this window's failure gets wrongly credited with. It now
	 * joins `governor_block()`'s `log_unacked`/`held_count` in answering
	 * null instead.
	 */
	public function test_a_failed_count_30d_scan_answers_null_never_a_false_zero(): void {
		$now  = time();
		$hour = (int) floor( $now / HOUR_IN_SECONDS );
		$this->seedBucket( 'log_ungoverned', $hour, 42 );
		$this->assertSame( 42, Aura_Worker_Elementor_Door::count_30d( 'log_ungoverned', $now ), 'the fixture assumption this test is built on' );

		$key = $GLOBALS['wpdb']->esc_like( Aura_Worker_Elementor_Door::COUNTER_PREFIX . 'log_ungoverned_h' );
		$GLOBALS['_sa_rows_read_error'][ $key ] = true;

		$this->assertNull( Aura_Worker_Elementor_Door::count_30d( 'log_ungoverned', $now ), 'never a false zero over 42 real events this call could not read' );

		$GLOBALS['_sa_rows_read_error'] = array();
		$this->assertSame( 42, Aura_Worker_Elementor_Door::count_30d( 'log_ungoverned', $now ), 'a healthy read afterwards still sums correctly' );
	}

	/**
	 * Ruling S37 (Codex round-15 class sweep on #88): prune_counters()'s
	 * own `get_col()` failing at the driver used to answer an empty list —
	 * indistinguishable from "nothing is expired" — so a failed listing
	 * deleted nothing while claiming the pass ran clean. It now skips the
	 * pass instead, leaving genuinely expired buckets for the next one.
	 */
	public function test_a_failed_prune_counters_listing_skips_rather_than_pruning_nothing(): void {
		$now     = time();
		$outside = (int) floor( ( $now - 30 * DAY_IN_SECONDS ) / HOUR_IN_SECONDS ) - 1;
		$this->seedBucket( 'log_ungoverned', $outside, 100 ); // genuinely expired

		$GLOBALS['_sa_wpdb_error'] = 'get_col failed';
		$out                        = Aura_Worker_Elementor_Door::reconcile();
		$GLOBALS['_sa_wpdb_error'] = '';

		$this->assertSame( 0, $out['pruned_counters'], 'the listing never ran — nothing was concluded about it' );
		$this->assertArrayHasKey( 'aura_worker_door_c_log_ungoverned_h' . $outside, $GLOBALS['_rows'], 'the expired bucket is untouched — the next sweep gets it' );

		// A healthy sweep right afterwards still prunes it (past the
		// PRUNE_INTERVAL_S gate, which the failed pass above already
		// crossed once).
		update_option( Aura_Worker_Elementor_Door::PRUNED_AT, gmdate( 'c', time() - Aura_Worker_Elementor_Door::PRUNE_INTERVAL_S - 60 ) );
		$out2 = Aura_Worker_Elementor_Door::reconcile();
		$this->assertSame( 1, $out2['pruned_counters'] );
	}

	private function seedBucket( string $name, int $hour, int $value ): void {
		$option                          = 'aura_worker_door_c_' . $name . '_h' . $hour;
		$GLOBALS['_rows'][ $option ]    = (string) $value;
		$GLOBALS['_options'][ $option ] = (string) $value;
	}

	/** Rewrite an envelope's stamp $days into the past, on disk. */
	private function ageSnapshot( string $id, int $days ): void {
		$file = WP_CONTENT_DIR . '/aura-backups/snapshots/' . $id . '.json';
		$this->assertFileExists( $file );
		$rec                = json_decode( (string) file_get_contents( $file ), true );
		$rec['created_gmt'] = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		file_put_contents( $file, wp_json_encode( $rec ) );
	}
}
