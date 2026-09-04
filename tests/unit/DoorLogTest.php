<?php
/**
 * The door log: one option row per entry, seq allocated by the row's own
 * INSERT above an ack floor that only rises, retained until Aura acks.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class DoorLogTest extends TestCase {

	protected function setUp(): void {
		sa_reset_state();
		require_once dirname( __DIR__, 2 ) . '/digitizer-site-worker/includes/class-aura-worker-door-log.php';
	}

	private function entry( array $over = array() ): array {
		return $over + array( 'ability' => 'elementor/publish-document', 'actor' => array( 'user_id' => 3, 'login' => 'bot' ), 'touches' => array( array( 'type' => 'page', 'id' => '7' ) ), 'verdict' => 'allow' );
	}

	public function test_epoch_is_minted_once_and_survives(): void {
		$a = Aura_Worker_Door_Log::epoch();
		$b = Aura_Worker_Door_Log::epoch();
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $a );
		$this->assertSame( $a, $b );
		$this->assertSame( $a, $GLOBALS['_options']['aura_worker_door_epoch'] );
	}

	/**
	 * Ruling P37: a reservation acked out from under this writer is handed
	 * back, and a number above the new floor is taken instead.
	 *
	 * The window: this writer computes N and pauses before its INSERT. Another
	 * writer inserts AND settles N, and `/door/ack` raises the floor to N and
	 * deletes that row. The conditional INSERT then succeeds by RECREATING N —
	 * at or below the floor, so the row is admitted, its callback runs, and
	 * `log_after()` and `count_unacked()` (which both walk from the floor)
	 * ignore it for ever. A governed write with no record.
	 */
	public function test_a_seq_acked_away_between_the_insert_and_the_floor_check_is_handed_back(): void {
		$epoch = Aura_Worker_Door_Log::epoch();

		// The racer, landing the instant this writer's row for seq 1 exists:
		// it settles that row and acks it, so the floor rises to 1 and the row
		// is deleted — leaving the reservation this writer thinks it holds
		// pointing at a number below the floor.
		$GLOBALS['_sa_after_insert_unique']['aura_worker_door_log_1'] = static function () use ( $epoch ) {
			Aura_Worker_Door_Log::settle( 1, array( 'result' => 'ok' ) );
			Aura_Worker_Door_Log::ack( $epoch, 1 );
		};

		$seq = Aura_Worker_Door_Log::open_pending( $this->entry() );

		$this->assertSame( 1, Aura_Worker_Door_Log::floor(), 'the ack moved the floor under the reservation' );
		$this->assertSame( 2, $seq, 'so the number was handed back and re-allocated above it' );
		$this->assertGreaterThan( Aura_Worker_Door_Log::floor(), $seq );
		$this->assertArrayNotHasKey( 'aura_worker_door_log_1', $GLOBALS['_options'], 'and no row was left recreated below the floor' );
		$this->assertArrayNotHasKey( 'aura_worker_door_log_1', $GLOBALS['_rows'] );

		// The re-allocated row is the one Aura actually receives.
		Aura_Worker_Door_Log::admit( $seq );
		Aura_Worker_Door_Log::settle( $seq, array( 'result' => 'ok' ) );
		$this->assertSame( array( 2 ), array_column( Aura_Worker_Door_Log::log_after( 0 ), 'seq' ) );
		$this->assertSame( 1, Aura_Worker_Door_Log::count_unacked() );
	}

	/** The give-back is FENCED: a racer's fresh row under that name survives. */
	public function test_the_handed_back_number_is_deleted_only_while_it_carries_this_writers_bytes(): void {
		$epoch = Aura_Worker_Door_Log::epoch();
		$GLOBALS['_sa_after_insert_unique']['aura_worker_door_log_1'] = static function () use ( $epoch ) {
			Aura_Worker_Door_Log::settle( 1, array( 'result' => 'ok' ) );
			Aura_Worker_Door_Log::ack( $epoch, 1 );
			// …and a third request reserves the (now free) name 1 before this
			// writer gets to its fenced delete. A bare delete_option() here
			// would destroy that reservation.
			$GLOBALS['_options']['aura_worker_door_log_1'] = array( 'seq' => 1, 'result' => 'pending', 'admitted' => false, 'ability' => 'someone/else' );
			$GLOBALS['_rows']['aura_worker_door_log_1']    = maybe_serialize( $GLOBALS['_options']['aura_worker_door_log_1'] );
		};

		$seq = Aura_Worker_Door_Log::open_pending( $this->entry() );

		$this->assertGreaterThan( 1, $seq );
		$this->assertSame( 'someone/else', $GLOBALS['_options']['aura_worker_door_log_1']['ability'], "the racer's row is untouched" );
	}

	/**
	 * Ruling P53: `ack()` reopens the door only on a READABLE count.
	 *
	 * An unreadable one cast to 0 and deleted FULL_MARKER over a backlog that
	 * was still full — the door open again with nothing having been acked.
	 */
	public function test_an_ack_with_an_unreadable_count_keeps_the_closure_marker(): void {
		$epoch = Aura_Worker_Door_Log::epoch();
		$seq   = Aura_Worker_Door_Log::open_pending( $this->entry() );
		Aura_Worker_Door_Log::admit( $seq );
		Aura_Worker_Door_Log::settle( $seq, array( 'result' => 'ok' ) );
		Aura_Worker_Door_Log::close();
		$GLOBALS['_sa_door_unacked_error'] = true;

		Aura_Worker_Door_Log::ack( $epoch, $seq );

		$GLOBALS['_sa_door_unacked_error'] = false;
		$this->assertTrue( Aura_Worker_Door_Log::is_closed(), 'the marker survives a count nobody could read' );
	}

	/** …and a readable count under the bound still reopens it. */
	public function test_an_ack_with_a_readable_count_still_reopens_the_door(): void {
		$epoch = Aura_Worker_Door_Log::epoch();
		$seq   = Aura_Worker_Door_Log::open_pending( $this->entry() );
		Aura_Worker_Door_Log::admit( $seq );
		Aura_Worker_Door_Log::settle( $seq, array( 'result' => 'ok' ) );
		Aura_Worker_Door_Log::close();

		Aura_Worker_Door_Log::ack( $epoch, $seq );

		$this->assertFalse( Aura_Worker_Door_Log::is_closed() );
	}

	/** count_unacked() answers NULL rather than a false zero. */
	public function test_an_unreadable_backlog_counts_as_null_not_zero(): void {
		$seq = Aura_Worker_Door_Log::open_pending( $this->entry() );
		Aura_Worker_Door_Log::admit( $seq );
		$this->assertSame( 1, Aura_Worker_Door_Log::count_unacked() );

		$GLOBALS['_sa_door_unacked_error'] = true;
		$this->assertNull( Aura_Worker_Door_Log::count_unacked() );
		$GLOBALS['_sa_door_unacked_error'] = false;
	}

	public function test_seq_is_allocated_by_the_insert_and_is_contiguous(): void {
		$s1 = Aura_Worker_Door_Log::open_pending( $this->entry() );
		$s2 = Aura_Worker_Door_Log::open_pending( $this->entry() );
		$this->assertSame( 1, $s1 );
		$this->assertSame( 2, $s2 );
		$row = $GLOBALS['_options']['aura_worker_door_log_1'];
		$this->assertSame( 'pending', $row['result'] );
		$this->assertFalse( $row['admitted'] );
		$this->assertArrayNotHasKey( 'aura_worker_door_seq', $GLOBALS['_options'], 'no counter option exists' );
	}

	public function test_a_collision_on_insert_retries_with_the_next_number(): void {
		// Another writer took seq 1 before this call even started.
		$GLOBALS['_options']['aura_worker_door_log_1'] = array( 'seq' => 1, 'result' => 'ok', 'admitted' => true );
		// A second writer takes seq 2 in the window between this call's own
		// existence check (there is none — insert_unique() is a real
		// conditional INSERT, `INSERT ... WHERE NOT EXISTS`) and its
		// statement actually running: the same seam
		// ConnectProvisionTest::test_two_claims_for_one_magic_link_admit_exactly_one
		// uses to race claim_magic_link(), which issues the identical SQL shape.
		$GLOBALS['_sa_before_swap'] = static function () {
			if ( ! isset( $GLOBALS['_options']['aura_worker_door_log_2'] ) && empty( $GLOBALS['_collided'] ) ) {
				$GLOBALS['_collided']                           = true;
				$racer                                          = array( 'seq' => 2, 'result' => 'ok', 'admitted' => true );
				$GLOBALS['_options']['aura_worker_door_log_2']  = $racer;
				$GLOBALS['_rows']['aura_worker_door_log_2']     = maybe_serialize( $racer );
			}
		};
		$this->assertSame( 3, Aura_Worker_Door_Log::open_pending( $this->entry() ) );
	}

	public function test_insert_unique_is_a_real_mutex_true_once_then_false(): void {
		$this->assertTrue( Aura_Worker_Door_Log::insert_unique( 'aura_worker_door_test_row', array( 'v' => 1 ) ) );
		$this->assertSame( array( 'v' => 1 ), get_option( 'aura_worker_door_test_row' ), 'the value landed and the cache was evicted' );
		$this->assertFalse( Aura_Worker_Door_Log::insert_unique( 'aura_worker_door_test_row', array( 'v' => 2 ) ), 'a second insert of the same name never overwrites' );
		$this->assertSame( array( 'v' => 1 ), get_option( 'aura_worker_door_test_row' ), 'the first value survives the refused second insert' );
	}

	public function test_an_ack_never_deletes_the_floor_marker_or_counter_rows(): void {
		Aura_Worker_Door_Log::open_pending( $this->entry() );
		Aura_Worker_Door_Log::admit( 1 );
		Aura_Worker_Door_Log::settle( 1, array( 'result' => 'ok' ) );
		Aura_Worker_Door_Log::close();
		Aura_Worker_Door_Log::bump_refused();
		Aura_Worker_Door_Log::ack( Aura_Worker_Door_Log::epoch(), 1 );
		$this->assertSame( 1, (int) $GLOBALS['_options']['aura_worker_door_log_acked'], 'the floor survived the ack — its suffix is not a number' );
		$this->assertSame( 2, Aura_Worker_Door_Log::open_pending( $this->entry() ) );
	}

	public function test_an_emptied_log_resumes_above_the_ack_floor_and_the_floor_only_rises(): void {
		Aura_Worker_Door_Log::open_pending( $this->entry() );
		Aura_Worker_Door_Log::admit( 1 );
		Aura_Worker_Door_Log::settle( 1, array( 'result' => 'ok' ) );
		$epoch = Aura_Worker_Door_Log::epoch();
		$this->assertSame( array( 'acked' => 1, 'floor' => 1 ), Aura_Worker_Door_Log::ack( $epoch, 1 ) );
		$this->assertArrayNotHasKey( 'aura_worker_door_log_1', $GLOBALS['_options'] );
		$this->assertSame( 2, Aura_Worker_Door_Log::open_pending( $this->entry() ), 'numbering resumes above the floor, never at 1' );
		// A stale ack (0) after a later one changes nothing.
		$this->assertSame( array( 'acked' => 0, 'floor' => 1 ), Aura_Worker_Door_Log::ack( $epoch, 0 ) );
		$this->assertSame( 1, (int) $GLOBALS['_options']['aura_worker_door_log_acked'] );
	}

	public function test_an_ack_for_another_epoch_is_ignored(): void {
		Aura_Worker_Door_Log::open_pending( $this->entry() );
		Aura_Worker_Door_Log::admit( 1 );
		Aura_Worker_Door_Log::settle( 1, array( 'result' => 'ok' ) );
		$this->assertSame( array( 'acked' => 0, 'floor' => 0 ), Aura_Worker_Door_Log::ack( 'not-this-epoch', 1 ) );
		$this->assertArrayHasKey( 'aura_worker_door_log_1', $GLOBALS['_options'] );
	}

	public function test_log_after_serves_terminal_entries_only_and_stops_at_a_pending_one(): void {
		foreach ( array( 1, 2, 3, 4 ) as $n ) {
			Aura_Worker_Door_Log::open_pending( $this->entry() );
			Aura_Worker_Door_Log::admit( $n );
		}
		Aura_Worker_Door_Log::settle( 1, array( 'result' => 'ok' ) );
		Aura_Worker_Door_Log::settle( 2, array( 'result' => 'refused' ) );
		// 3 stays pending; 4 is terminal but behind it.
		Aura_Worker_Door_Log::settle( 4, array( 'result' => 'ok' ) );
		$page = Aura_Worker_Door_Log::log_after( 0 );
		$this->assertSame( array( 1, 2 ), array_column( $page, 'seq' ) );
		$this->assertSame( array(), Aura_Worker_Door_Log::log_after( 2 ), 'nothing past the pending entry is served' );
	}

	public function test_an_unadmitted_pending_row_is_never_served_but_a_terminal_one_always_is(): void {
		Aura_Worker_Door_Log::open_pending( $this->entry() ); // seq 1, admitted false, pending
		$this->assertSame( array(), Aura_Worker_Door_Log::log_after( 0 ) );
		Aura_Worker_Door_Log::settle( 1, array( 'result' => 'ok' ) ); // settle admits
		$this->assertSame( array( 1 ), array_column( Aura_Worker_Door_Log::log_after( 0 ), 'seq' ) );
	}

	public function test_settle_is_a_compare_and_set_that_reports_a_vanished_row(): void {
		Aura_Worker_Door_Log::open_pending( $this->entry() );
		unset( $GLOBALS['_options']['aura_worker_door_log_1'], $GLOBALS['_rows']['aura_worker_door_log_1'] );
		$this->assertFalse( Aura_Worker_Door_Log::settle( 1, array( 'result' => 'ok' ) ) );
		$this->assertArrayNotHasKey( 'aura_worker_door_log_1', $GLOBALS['_options'], 'a settle never recreates a row' );
	}

	public function test_closure_marker_has_one_owner_and_refusals_count_without_rows(): void {
		$this->assertFalse( Aura_Worker_Door_Log::is_closed() );
		Aura_Worker_Door_Log::close();
		Aura_Worker_Door_Log::close();
		$this->assertTrue( Aura_Worker_Door_Log::is_closed() );
		Aura_Worker_Door_Log::bump_refused();
		Aura_Worker_Door_Log::bump_refused();
		$this->assertSame( 2, (int) $GLOBALS['_options']['aura_worker_door_log_full_refused'] );
		$this->assertSame( 0, Aura_Worker_Door_Log::count_unacked(), 'no log row was written for a refusal' );
	}

	public function test_ack_reopens_the_door_once_under_the_bound(): void {
		Aura_Worker_Door_Log::close();
		Aura_Worker_Door_Log::open_pending( $this->entry() );
		Aura_Worker_Door_Log::admit( 1 );
		Aura_Worker_Door_Log::settle( 1, array( 'result' => 'ok' ) );
		Aura_Worker_Door_Log::ack( Aura_Worker_Door_Log::epoch(), 1 );
		$this->assertFalse( Aura_Worker_Door_Log::is_closed() );
	}

	public function test_rotate_epoch_mints_a_new_epoch_clears_closure_and_keeps_the_floor_and_every_row(): void {
		$before = Aura_Worker_Door_Log::epoch();
		$seq    = Aura_Worker_Door_Log::open_pending( $this->entry() );
		Aura_Worker_Door_Log::admit( $seq );
		Aura_Worker_Door_Log::settle( $seq, array( 'result' => 'ok' ) );
		Aura_Worker_Door_Log::ack( $before, $seq );
		Aura_Worker_Door_Log::close();
		Aura_Worker_Door_Log::bump_refused();
		$seq2 = Aura_Worker_Door_Log::open_pending( $this->entry() );

		$out   = Aura_Worker_Door_Log::rotate_epoch( $before );
		$after = $out['epoch'];

		$this->assertTrue( $out['rotated'] );
		$this->assertNotSame( $before, $after );
		$this->assertSame( $after, Aura_Worker_Door_Log::epoch() );
		$this->assertSame( 1, Aura_Worker_Door_Log::floor(), 'the ack floor is RETAINED — dropping it manufactures the hole log_after() stops at' );
		$this->assertFalse( Aura_Worker_Door_Log::is_closed() );
		$this->assertNull( Aura_Worker_Door_Log::full_report() );
		$this->assertNotNull( Aura_Worker_Door_Log::get( $seq2 ), 'a row survives the rotation' );
	}

	public function test_a_rotation_after_an_ack_still_serves_the_rows_that_are_left(): void {
		$epoch = Aura_Worker_Door_Log::epoch();
		for ( $i = 1; $i <= 3; $i++ ) {
			Aura_Worker_Door_Log::open_pending( $this->entry() );
			Aura_Worker_Door_Log::admit( $i );
			Aura_Worker_Door_Log::settle( $i, array( 'result' => 'ok' ) );
		}
		Aura_Worker_Door_Log::ack( $epoch, 2 ); // rows 1 and 2 are gone; the floor is 2

		Aura_Worker_Door_Log::rotate_epoch( $epoch );

		$this->assertSame( 2, Aura_Worker_Door_Log::floor() );
		$this->assertSame( array( 3 ), array_column( Aura_Worker_Door_Log::log_after( 0 ), 'seq' ), 'log_after() walks from the floor, so it never meets the hole the ack left' );
	}

	/**
	 * Ruling P95: a seq above the top acknowledges NOTHING — it is not clamped.
	 *
	 * Clamping is exactly wrong after an options-table rewind: between the
	 * rewind and `/status` detecting it, an in-flight ack from the pre-rewind
	 * log still carries the CURRENT epoch, and if a new write has already
	 * reused the next number, clamping that old, higher cursor raises the floor
	 * straight through the new row and deletes an entry Aura never received.
	 */
	public function test_an_ack_above_every_row_acknowledges_nothing(): void {
		$epoch = Aura_Worker_Door_Log::epoch();
		Aura_Worker_Door_Log::open_pending( $this->entry() );
		Aura_Worker_Door_Log::admit( 1 );
		Aura_Worker_Door_Log::settle( 1, array( 'result' => 'ok' ) );

		$out = Aura_Worker_Door_Log::ack( $epoch, PHP_INT_MAX );

		$this->assertSame( 0, $out['acked'], 'a cursor this log does not have acks nothing' );
		$this->assertSame( 0, $out['floor'], 'and the floor is untouched' );
		$this->assertTrue( $out['stale'], 'Aura is told to re-read rather than assume it landed' );
		$this->assertNotNull( Aura_Worker_Door_Log::get( 1 ), 'the row Aura never received is still here' );
		// …and the numbering is still sane afterwards: the overflow the clamp
		// guarded against cannot happen, because the floor is only ever raised
		// to a cursor at or below the top.
		$this->assertSame( 2, Aura_Worker_Door_Log::open_pending( $this->entry() ) );
		$this->assertArrayHasKey( 'aura_worker_door_log_2', $GLOBALS['_options'] );
	}

	/** …and an ack AT the top still works exactly as it did. */
	public function test_an_ack_at_the_top_of_the_log_still_acknowledges(): void {
		$epoch = Aura_Worker_Door_Log::epoch();
		for ( $i = 1; $i <= 2; $i++ ) {
			Aura_Worker_Door_Log::open_pending( $this->entry() );
			Aura_Worker_Door_Log::admit( $i );
			Aura_Worker_Door_Log::settle( $i, array( 'result' => 'ok' ) );
		}

		$out = Aura_Worker_Door_Log::ack( $epoch, 2 );

		$this->assertSame( 2, $out['acked'] );
		$this->assertSame( 2, $out['floor'] );
		$this->assertArrayNotHasKey( 'stale', $out );
		$this->assertNull( Aura_Worker_Door_Log::get( 2 ) );
	}

	public function test_an_ack_below_the_floor_still_deletes_every_row_the_floor_covers(): void {
		$epoch = Aura_Worker_Door_Log::epoch();
		for ( $i = 1; $i <= 3; $i++ ) {
			Aura_Worker_Door_Log::open_pending( $this->entry() );
			Aura_Worker_Door_Log::admit( $i );
			Aura_Worker_Door_Log::settle( $i, array( 'result' => 'ok' ) );
		}
		Aura_Worker_Door_Log::ack( $epoch, 3 );
		// Rows 1..3 were deleted; re-seed one BELOW the floor, the shape a
		// half-applied delete (or a restored backup) leaves behind.
		$row = array( 'seq' => 2, 'result' => 'ok', 'admitted' => true, 'at' => gmdate( 'c' ) );
		$GLOBALS['_options']['aura_worker_door_log_2'] = $row;
		$GLOBALS['_rows']['aura_worker_door_log_2']    = maybe_serialize( $row );
		unset( $GLOBALS['_notoptions']['aura_worker_door_log_2'] );

		$out = Aura_Worker_Door_Log::ack( $epoch, 1 ); // a stale ack, below the floor

		$this->assertSame( 3, $out['floor'], 'the floor only rises' );
		$this->assertArrayNotHasKey( 'aura_worker_door_log_2', $GLOBALS['_rows'], 'the purge is bounded by the FLOOR, so nothing under it is orphaned' );
	}

	public function test_patch_pending_writes_fields_onto_a_still_pending_row_without_touching_result(): void {
		$seq = Aura_Worker_Door_Log::open_pending( $this->entry() );
		$this->assertTrue( Aura_Worker_Door_Log::patch_pending( $seq, array( 'snapshot_id' => 'snap-1', 'result' => 'ok' ) ) );
		$row = Aura_Worker_Door_Log::get( $seq );
		$this->assertSame( 'snap-1', $row['snapshot_id'] );
		$this->assertSame( 'pending', $row['result'], "'result' in \$fields is dropped — patch_pending() never terminates a row" );
	}

	public function test_patch_pending_refuses_once_the_row_has_settled(): void {
		$seq = Aura_Worker_Door_Log::open_pending( $this->entry() );
		Aura_Worker_Door_Log::admit( $seq );
		Aura_Worker_Door_Log::settle( $seq, array( 'result' => 'ok' ) );
		$this->assertFalse( Aura_Worker_Door_Log::patch_pending( $seq, array( 'watermark' => 5 ) ) );
		$this->assertArrayNotHasKey( 'watermark', Aura_Worker_Door_Log::get( $seq ) );
	}

	/** open_pending() always forces `at` to now, so backdate the row directly afterwards — the way a real hour-old row looks. */
	private function backdate( int $seq, int $seconds_ago ): void {
		$name                          = Aura_Worker_Door_Log::PREFIX . $seq;
		$row                           = $GLOBALS['_options'][ $name ];
		$row['at']                     = gmdate( 'c', time() - $seconds_ago );
		$GLOBALS['_options'][ $name ]  = $row;
		$GLOBALS['_rows'][ $name ]     = maybe_serialize( $row );
	}

	/* ---- settle() is pending-only: the first terminal writer wins ---- */

	/**
	 * Ruling P27: a seq never changes meaning. `/status` can read a row as
	 * stale while the request that owns it is still finishing, so both sides
	 * hold a terminal verdict for the same number — and Aura may already have
	 * consumed the first.
	 */
	public function test_settle_refuses_a_row_that_is_already_terminal(): void {
		$seq = Aura_Worker_Door_Log::open_pending( $this->entry() );
		$this->assertTrue( Aura_Worker_Door_Log::settle( $seq, array( 'result' => 'ok', 'snapshot_id' => 'snap_1' ) ) );
		$first = Aura_Worker_Door_Log::get( $seq );

		$this->assertFalse( Aura_Worker_Door_Log::settle( $seq, array( 'result' => 'interrupted' ) ) );
		$this->assertFalse( Aura_Worker_Door_Log::discard( $seq ), 'and a discard is a settle like any other' );

		$this->assertSame( $first, Aura_Worker_Door_Log::get( $seq ), 'the row is untouched, byte for byte' );
		$this->assertTrue( Aura_Worker_Door_Log::is_terminal( $seq ) );
	}

	public function test_is_terminal_answers_false_for_a_pending_row_and_a_missing_one(): void {
		$seq = Aura_Worker_Door_Log::open_pending( $this->entry() );
		$this->assertFalse( Aura_Worker_Door_Log::is_terminal( $seq ) );
		$this->assertFalse( Aura_Worker_Door_Log::is_terminal( 9999 ) );
	}

	/** Evidence may still be ADDED to a terminal row — its result may not. */
	public function test_annotate_adds_evidence_without_touching_the_result(): void {
		$seq = Aura_Worker_Door_Log::open_pending( $this->entry() );
		Aura_Worker_Door_Log::settle( $seq, array( 'result' => 'failed', 'reason' => 'snapshot_failed' ) );

		$this->assertTrue( Aura_Worker_Door_Log::annotate( $seq, array( 'reason' => 'exception_then_compensated', 'result' => 'ok' ) ) );

		$row = Aura_Worker_Door_Log::get( $seq );
		$this->assertSame( 'failed', $row['result'], 'the result a terminal writer set is final' );
		$this->assertSame( 'exception_then_compensated', $row['reason'] );
	}

	public function test_annotate_refuses_a_pending_row(): void {
		$seq = Aura_Worker_Door_Log::open_pending( $this->entry() );
		$this->assertFalse( Aura_Worker_Door_Log::annotate( $seq, array( 'reason' => 'x' ) ), 'a pending row is settled, not annotated' );
	}

	/* ---- rotate_epoch(): a compare-and-swap on the epoch it replaces ---- */

	/**
	 * Two separately granted rotations for the SAME rewind. Both pass the
	 * current-epoch check before either writes, so the delete has to be
	 * fenced on the bytes it was asked to replace — an unconditional one
	 * deleted the epoch the winner had just minted and rotated a second
	 * time, invalidating an ack that was already in flight against it.
	 */
	public function test_a_second_rotation_racing_the_first_rotates_nothing(): void {
		$before = Aura_Worker_Door_Log::epoch();
		// The other request wins the race in the window between this call's
		// read and its fenced DELETE.
		$GLOBALS['_sa_before_fenced_delete'][ Aura_Worker_Door_Log::EPOCH ] = static function () {
			$GLOBALS['_options'][ Aura_Worker_Door_Log::EPOCH ] = 'the-other-requests-epoch';
			$GLOBALS['_rows'][ Aura_Worker_Door_Log::EPOCH ]    = 'the-other-requests-epoch';
		};

		$out = Aura_Worker_Door_Log::rotate_epoch( $before );

		$this->assertFalse( $out['rotated'], 'somebody else rotated first' );
		$this->assertSame( 'the-other-requests-epoch', $out['epoch'], 'and the epoch answered is theirs, not a third one' );
		$this->assertSame( 'the-other-requests-epoch', get_option( Aura_Worker_Door_Log::EPOCH ) );
	}

	public function test_rotate_epoch_refuses_an_epoch_this_site_has_moved_past(): void {
		$before = Aura_Worker_Door_Log::epoch();

		$out = Aura_Worker_Door_Log::rotate_epoch( 'an-epoch-from-another-life' );

		$this->assertFalse( $out['rotated'] );
		$this->assertSame( $before, $out['epoch'] );
		$this->assertSame( $before, get_option( Aura_Worker_Door_Log::EPOCH ) );
	}

	public function test_stale_pending_finds_only_pending_rows_older_than_the_cutoff(): void {
		$old = Aura_Worker_Door_Log::open_pending( $this->entry() );
		$this->backdate( $old, 3600 );
		Aura_Worker_Door_Log::open_pending( $this->entry() ); // fresh: excluded
		$done = Aura_Worker_Door_Log::open_pending( $this->entry() );
		$this->backdate( $done, 3600 );
		Aura_Worker_Door_Log::admit( $done );
		Aura_Worker_Door_Log::settle( $done, array( 'result' => 'ok' ) ); // terminal: excluded

		$stale = Aura_Worker_Door_Log::stale_pending( 60000 ); // 60s

		$this->assertSame( array( $old ), array_column( $stale, 'seq' ), 'the fresh pending row and the settled row are both excluded' );
	}

	public function test_stale_pending_reads_the_rows_above_the_floor_in_one_statement(): void {
		// It used to walk floor()+1 .. highest_row_seq() with one get_option()
		// per number — on EVERY /status poll, on a site whose ack is behind by
		// a thousand entries. The option cache answers those reads without
		// ever reaching $wpdb, so the cost is invisible in $_db_queries: the
		// SHAPE is what this pins.
		for ( $i = 0; $i < 5; $i++ ) {
			$seq = Aura_Worker_Door_Log::open_pending( $this->entry() );
			$this->backdate( $seq, 3600 );
		}
		$GLOBALS['_db_queries'] = array();

		$stale = Aura_Worker_Door_Log::stale_pending( 60000 );

		$this->assertSame( array( 1, 2, 3, 4, 5 ), array_column( $stale, 'seq' ) );
		$this->assertCount( 1, $GLOBALS['_db_queries'], 'one statement, whatever the log holds' );
		$this->assertStringContainsString( 'SELECT option_name, option_value', $GLOBALS['_db_queries'][0] );
		$this->assertStringContainsString( 'AS UNSIGNED) > 0', $GLOBALS['_db_queries'][0], 'bounded by the ack floor in SQL' );
	}

	public function test_stale_pending_starts_above_the_ack_floor_and_skips_a_hole(): void {
		$epoch = Aura_Worker_Door_Log::epoch();
		for ( $i = 1; $i <= 3; $i++ ) {
			Aura_Worker_Door_Log::open_pending( $this->entry() );
			Aura_Worker_Door_Log::admit( $i );
			Aura_Worker_Door_Log::settle( $i, array( 'result' => 'ok' ) );
		}
		Aura_Worker_Door_Log::ack( $epoch, 2 ); // floor 2; rows 1 and 2 gone
		$four = Aura_Worker_Door_Log::open_pending( $this->entry() );
		$this->backdate( $four, 3600 );
		// A number with no row at all, between the floor and the top.
		unset( $GLOBALS['_options'][ Aura_Worker_Door_Log::PREFIX . 3 ], $GLOBALS['_rows'][ Aura_Worker_Door_Log::PREFIX . 3 ] );

		$this->assertSame( array( $four ), array_column( Aura_Worker_Door_Log::stale_pending( 60000 ), 'seq' ) );
	}
}
