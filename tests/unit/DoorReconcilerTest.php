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
		$GLOBALS['_options'][ Aura_Worker_Door_Holds::HELD . $claimed ] = array( 'ref' => $claimed, 'expires_at' => gmdate( 'c', time() + 600 ) );
		$GLOBALS['_rows'][ Aura_Worker_Door_Holds::HELD . $claimed ]    = maybe_serialize( $GLOBALS['_options'][ Aura_Worker_Door_Holds::HELD . $claimed ] );
		$expired = $this->hold();
		$this->patchOption( Aura_Worker_Door_Holds::HELD . $expired, array( 'expires_at' => gmdate( 'c', time() - 60 ) ) );
		$live = $this->hold();

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

	public function test_a_stale_creation_mutex_is_cleared_and_a_live_one_is_kept(): void {
		Aura_Worker_Door_Log::insert_unique( Aura_Worker_Elementor_Door::CREATING, array( 'seq' => 9, 'started_at' => gmdate( 'c' ) ) );
		Aura_Worker_Elementor_Door::reconcile();
		$this->assertIsArray( get_option( Aura_Worker_Elementor_Door::CREATING, null ), 'a creation still inside its ten minutes owns the site' );

		$this->patchOption( Aura_Worker_Elementor_Door::CREATING, array( 'started_at' => $this->longAgo() ) );
		Aura_Worker_Elementor_Door::reconcile();
		$this->assertFalse( get_option( Aura_Worker_Elementor_Door::CREATING, false ), 'a mutex older than the stale bound is nobody\'s' );
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
	/* (j) a rewound log rotates its epoch                                 */
	/* ------------------------------------------------------------------ */

	public function test_a_cursor_above_every_row_and_the_floor_rotates_the_epoch(): void {
		$one = $this->entry( array(), true, false );
		Aura_Worker_Door_Log::settle( $one, array( 'result' => 'ok' ) );
		Aura_Worker_Door_Log::close();
		Aura_Worker_Door_Log::bump_refused();
		$before = Aura_Worker_Door_Log::epoch();

		$frag = $this->fragment( 40, $before );

		$this->assertNotSame( $before, $frag['epoch'], 'a new epoch, so Aura re-fetches from 0' );
		$this->assertSame( Aura_Worker_Door_Log::epoch(), $frag['epoch'] );
		$this->assertSame( 0, $frag['log_floor'], 'nothing was ever acked here, so the retained floor is still 0' );
		$this->assertFalse( get_option( Aura_Worker_Door_Log::FULL_MARKER, false ) );
		$this->assertFalse( get_option( Aura_Worker_Door_Log::FULL_COUNTER, false ) );
		$this->assertSame( array( 1 ), array_column( $frag['log'], 'seq' ), 'the rewound rows are re-served from 0' );
	}

	public function test_a_rotation_on_a_site_that_has_acked_keeps_the_floor_and_goes_on_serving(): void {
		$epoch = Aura_Worker_Door_Log::epoch();
		for ( $i = 1; $i <= 3; $i++ ) {
			$seq = $this->entry( array(), true, false );
			Aura_Worker_Door_Log::settle( $seq, array( 'result' => 'ok' ) );
		}
		Aura_Worker_Door_Log::ack( $epoch, 2 ); // rows 1 and 2 deleted, floor 2

		$rotated = $this->fragment( 9, $epoch ); // a cursor above every row: the log rotates

		$this->assertNotSame( $epoch, $rotated['epoch'] );
		$new = $rotated['epoch'];

		$frag = $this->fragment( 0, $new );
		$this->assertSame( 2, $frag['log_floor'], 'the ack floor survived the rotation' );
		$this->assertSame( array( 3 ), array_column( $frag['log'], 'seq' ), 'row 3 is served — the deleted 1 and 2 are below the floor, not a hole' );

		$fourth = $this->entry( array(), true, false );
		Aura_Worker_Door_Log::settle( $fourth, array( 'result' => 'ok' ) );
		$this->assertSame( array( 3, 4 ), array_column( $this->fragment( 0, $new )['log'], 'seq' ), 'the log did not wedge' );
	}

	public function test_a_cursor_above_the_floor_but_at_an_existing_row_does_not_rotate(): void {
		$one = $this->entry( array(), true, false );
		Aura_Worker_Door_Log::settle( $one, array( 'result' => 'ok' ) );
		$before = Aura_Worker_Door_Log::epoch();

		$frag = $this->fragment( 1, $before ); // a failed ack: the row is still there

		$this->assertSame( $before, $frag['epoch'] );
		$this->assertSame( array(), $frag['log'], 'served from the cursor, which is where Aura says it is' );
	}

	public function test_a_cursor_from_another_epoch_is_ignored_and_never_rotates(): void {
		$one = $this->entry( array(), true, false );
		Aura_Worker_Door_Log::settle( $one, array( 'result' => 'ok' ) );
		$before = Aura_Worker_Door_Log::epoch();

		$frag = $this->fragment( 40, 'a-different-epoch' );

		$this->assertSame( $before, $frag['epoch'], 'a cursor from another epoch says nothing about this log' );
		$this->assertSame( array( 1 ), array_column( $frag['log'], 'seq' ), 'served from 0' );
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
