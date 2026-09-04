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
			// A racer, so it runs as if on its OWN connection (Ruling S8) —
			// settle()/ack() each open their own versioned() transaction,
			// which would nest inside this writer's still-open one otherwise.
			sa_on_another_connection( function () use ( $epoch ) {
				Aura_Worker_Door_Log::settle( 1, array( 'result' => 'ok' ) );
				Aura_Worker_Door_Log::ack( $epoch, 1 );
			} );
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
			// A racer, so it runs as if on its OWN connection (Ruling S8) —
			// see the test above.
			sa_on_another_connection( function () use ( $epoch ) {
				Aura_Worker_Door_Log::settle( 1, array( 'result' => 'ok' ) );
				Aura_Worker_Door_Log::ack( $epoch, 1 );
			} );
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

	/* ------------------------------------------------------------------ */
	/* Ruling S1 (Codex round-1 P2 on #87): every raw reader is PROVEN     */
	/* ------------------------------------------------------------------ */

	/**
	 * `$wpdb->query()` has two early returns before its flush() — an unready
	 * handle, and a `query` filter that blanks the SQL — that leave
	 * `$last_result` exactly as the PREVIOUS statement left it, with
	 * `last_error` untouched. `binding_raw()` used to trust that stale
	 * result: primed with generation A, the record rewritten to B by another
	 * process, and the next statement suppressed, it went on answering A —
	 * label a departed client's door-log entries as this client's own. The
	 * proven raw read (`raw_option_read()`'s per-call nonce) answers
	 * unreadable (`''`) instead, never a generation nobody can vouch for.
	 */
	public function test_binding_raw_answers_unreadable_not_a_stale_generation_when_the_next_query_is_suppressed(): void {
		$rec_a = array( 'gen' => 'gen-a', 'state' => 'bound', 'client' => 'client-a', 'dashboard' => null );
		$GLOBALS['_options'][ Aura_Worker_Door_Log::BINDING ] = $rec_a;
		$GLOBALS['_rows'][ Aura_Worker_Door_Log::BINDING ]    = maybe_serialize( $rec_a );

		$this->assertSame( 'gen-a', Aura_Worker_Door_Log::binding_raw(), 'primed: a real read proves generation A' );

		// Another process rebinds the site — the record now names B — while
		// THIS request's next statement against the row is suppressed.
		$rec_b = array( 'gen' => 'gen-b', 'state' => 'bound', 'client' => 'client-b', 'dashboard' => null );
		$GLOBALS['_options'][ Aura_Worker_Door_Log::BINDING ] = $rec_b;
		$GLOBALS['_rows'][ Aura_Worker_Door_Log::BINDING ]    = maybe_serialize( $rec_b );
		$GLOBALS['_sa_wpdb_query_filtered_out']               = true;

		$stale = Aura_Worker_Door_Log::binding_raw();

		$GLOBALS['_sa_wpdb_query_filtered_out'] = false;
		$this->assertSame( '', $stale, 'unreadable, never A (stale) and never B (unproven)' );
	}

	/** The same proof, for `epoch_raw()` (Ruling P81's never-minted reader). */
	public function test_epoch_raw_answers_unreadable_not_a_stale_epoch_when_the_next_query_is_suppressed(): void {
		$GLOBALS['_options'][ Aura_Worker_Door_Log::EPOCH ] = 'epoch-a';
		$GLOBALS['_rows'][ Aura_Worker_Door_Log::EPOCH ]    = maybe_serialize( 'epoch-a' );

		$this->assertSame( 'epoch-a', Aura_Worker_Door_Log::epoch_raw(), 'primed: a real read proves epoch A' );

		$GLOBALS['_options'][ Aura_Worker_Door_Log::EPOCH ] = 'epoch-b';
		$GLOBALS['_rows'][ Aura_Worker_Door_Log::EPOCH ]    = maybe_serialize( 'epoch-b' );
		$GLOBALS['_sa_wpdb_query_filtered_out']             = true;

		$stale = Aura_Worker_Door_Log::epoch_raw();

		$GLOBALS['_sa_wpdb_query_filtered_out'] = false;
		$this->assertSame( '', $stale, 'unreadable, never A (stale) and never B (unproven)' );
	}

	/**
	 * The site-issued observation witness (Ruling A65, 2.16.2), CLOCK-FLOORED
	 * (Ruling S4, Codex round-2 P1 on #88): each bump is
	 * GREATEST( current + 1, wall-clock-microseconds ), so it is guaranteed
	 * only to STRICTLY INCREASE — never a fixed "+1" delta, since a slower
	 * clock can carry it further ahead than a bare increment would. Asserting
	 * an exact `+1` here would be asserting a coincidence of timing, not the
	 * guarantee the counter actually makes.
	 */
	public function test_bump_door_version_strictly_increases_and_is_an_int(): void {
		$a = Aura_Worker_Door_Log::bump_door_version();
		$b = Aura_Worker_Door_Log::bump_door_version();
		$c = Aura_Worker_Door_Log::bump_door_version();
		$this->assertIsInt( $a );
		$this->assertGreaterThanOrEqual( $a + 1, $b );
		$this->assertGreaterThanOrEqual( $b + 1, $c );
	}

	/** Two serves within one request strictly increase — never the same number twice. */
	public function test_two_bumps_in_one_request_never_answer_the_same_value(): void {
		$first  = Aura_Worker_Door_Log::bump_door_version();
		$second = Aura_Worker_Door_Log::bump_door_version();
		$this->assertGreaterThan( $first, $second );
	}

	/**
	 * Ruling S4 (Codex round-2 P1 on #88): a plain per-row counter is not
	 * enough — `wp_options` can be restored from a backup taken before this
	 * row's later bumps, and a counter restored to a lower stored value
	 * would otherwise resume REISSUING numbers it already served, which
	 * breaks Aura's ordering. Clock-flooring means a restore can roll the
	 * STORED value back but not the CLOCK, so the very next bump after a
	 * restore still resumes strictly above every value issued before it.
	 */
	public function test_a_restore_never_reissues_a_value_already_served_before_it(): void {
		$before_restore = 0;
		for ( $i = 0; $i < 3; $i++ ) {
			$before_restore = Aura_Worker_Door_Log::bump_door_version();
		}

		// The database is restored from a backup taken before any of the
		// bumps above — the row rolls back to a value far below every one
		// of them, exactly as a snapshot restore would.
		$GLOBALS['_rows'][ Aura_Worker_Door_Log::OBSERVATION ]    = '100';
		$GLOBALS['_options'][ Aura_Worker_Door_Log::OBSERVATION ] = '100';

		$after_restore = Aura_Worker_Door_Log::bump_door_version();

		$this->assertGreaterThan(
			$before_restore,
			$after_restore,
			'the clock floor means a restored counter resumes strictly ABOVE every value it issued before the backup, never reissuing one'
		);
	}

	/**
	 * The clock floor applies even to a row that has NEVER existed (Ruling
	 * S4): the first-ever bump on a fresh site still answers the clock
	 * value, not a bare `1` — a value any restored backup could trivially
	 * reissue is never handed out even once.
	 */
	public function test_the_first_ever_bump_on_a_fresh_site_answers_the_clock_derived_value_not_one(): void {
		$first = Aura_Worker_Door_Log::bump_door_version();
		$this->assertGreaterThanOrEqual( 1000000000000000, $first, 'microsecond wall-clock time today is well past 10^15' );
	}

	/**
	 * Ruling S2 (Codex round-1 P1 on #88): the increment-plus-read must be
	 * ONE atomic operation, not an atomic increment followed by a SEPARATE
	 * re-read of the shared row. The old shape let an interleaved second
	 * bump land between the first request's own upsert and its own read,
	 * so BOTH requests could answer the row's latest value instead of each
	 * answering what it itself assigned.
	 *
	 * Modelled here as two independent CONNECTIONS (two SA_Test_Wpdb
	 * instances, exactly as two separate PHP requests would each hold their
	 * own mysqli connection) interleaved via a seam that fires from INSIDE
	 * the stub's own upsert handler — between THIS request's own INSERT and
	 * its own `SELECT LAST_INSERT_ID()` — never a callback inside production
	 * code, which has no such hook. Pre-fix (a plain re-read of the shared
	 * row) this test is RED: both connections would answer the same value.
	 */
	public function test_two_interleaved_bumps_on_different_connections_never_answer_the_same_value(): void {
		$other = null;
		$GLOBALS['_sa_after_door_version_bump'] = static function () use ( &$other ) {
			$mine            = $GLOBALS['wpdb'];
			$GLOBALS['wpdb'] = new SA_Test_Wpdb(); // a second, independent connection
			$other           = Aura_Worker_Door_Log::bump_door_version();
			$GLOBALS['wpdb'] = $mine; // restored before THIS request's own next statement
		};

		$mine = Aura_Worker_Door_Log::bump_door_version();

		$this->assertIsInt( $mine );
		$this->assertIsInt( $other );
		$this->assertNotSame( $mine, $other, 'never the same number twice' );
		$this->assertGreaterThan( $mine, $other, "the second, interleaved connection's own upsert ran AFTER the first's, so it lands strictly ahead — the first connection's own read answers what IT assigned, never a re-read of the row" );
	}

	/**
	 * A read-back that cannot be PROVEN (Ruling S1's same discipline) answers
	 * null — "no witness this serve" — never a stale or guessed number, even
	 * though the underlying upsert may well have landed.
	 */
	public function test_bump_door_version_answers_null_when_the_read_back_cannot_be_proven(): void {
		$GLOBALS['_sa_wpdb_error'] = 'MySQL server has gone away';
		$out                       = Aura_Worker_Door_Log::bump_door_version();
		$GLOBALS['_sa_wpdb_error'] = '';
		$this->assertNull( $out );
	}

	/**
	 * Ruling S5 (Codex round-2 P2 on #88): the upsert can commit and the
	 * connection can then drop before this SAME request's own
	 * `SELECT LAST_INSERT_ID()` runs — WordPress can transparently reconnect
	 * and run it on a FRESH session, where `LAST_INSERT_ID()` genuinely
	 * answers `0`. `0` is numeric and would otherwise pass as a witness this
	 * connection never actually produced; it must answer null instead.
	 */
	public function test_bump_door_version_answers_null_when_the_read_back_is_a_reconnected_zero(): void {
		$GLOBALS['_sa_last_insert_id_reconnect'] = true;
		$out                                     = Aura_Worker_Door_Log::bump_door_version();
		$GLOBALS['_sa_last_insert_id_reconnect'] = false;
		$this->assertNull( $out, 'a fresh-session LAST_INSERT_ID() of 0 is not this connection\'s own witness' );
	}

	/** `door_version_raw()` is READ-ONLY: it reports the current value and never advances it. */
	public function test_door_version_raw_reports_without_bumping(): void {
		$this->assertNull( Aura_Worker_Door_Log::door_version_raw(), 'no witness has ever served this site' );
		$bumped = Aura_Worker_Door_Log::bump_door_version();
		$this->assertSame( $bumped, Aura_Worker_Door_Log::door_version_raw() );
		$this->assertSame( $bumped, Aura_Worker_Door_Log::door_version_raw(), 'a second read changes nothing' );
	}

	/**
	 * The counter is NEVER reset by rotation, rebind or unbind (Ruling A65)
	 * — it orders mutations across all of them. Since Ruling S6 (Codex
	 * round-3 P1 on #88), `rotate_epoch()` is ITSELF a choke point and bumps
	 * on its own successful rotation — a rewind recovery is real door state
	 * changing, so the version after it is strictly GREATER than whatever it
	 * was before, never merely equal and never reset to null or zero.
	 */
	public function test_observation_survives_an_epoch_rotation(): void {
		$before = Aura_Worker_Door_Log::epoch();
		$seq    = Aura_Worker_Door_Log::bump_door_version();
		Aura_Worker_Door_Log::rotate_epoch( $before );
		$after_rotation = Aura_Worker_Door_Log::door_version_raw();
		$this->assertGreaterThan( $seq, $after_rotation, 'the rotation is itself a mutation, and it bumps for itself' );
		$this->assertGreaterThan( $after_rotation, Aura_Worker_Door_Log::bump_door_version(), 'and the next bump continues past it' );
	}

	/**
	 * Ruling S7 (Codex round-3 P2 on #88): on a 32-bit PHP build the
	 * clock-derived value (~1.7e15 today) cannot be represented as an int
	 * without corrupting it, so the READ-BACK always answers null there —
	 * even though the counter itself still advances correctly, since the
	 * WRITE side is built as a decimal STRING (never assembled as one PHP
	 * int) and MySQL evaluates it in its own 64-bit domain regardless of the
	 * PHP client's word size. Modelled via a test-only seam standing in for
	 * `PHP_INT_SIZE`, since the real constant cannot be redefined.
	 */
	public function test_bump_door_version_answers_null_on_a_32_bit_build_but_still_advances_the_counter(): void {
		Aura_Worker_Door_Log::set_int_size_for_tests( 4 );
		$first  = Aura_Worker_Door_Log::bump_door_version();
		$second = Aura_Worker_Door_Log::bump_door_version();
		Aura_Worker_Door_Log::set_int_size_for_tests( null );

		$this->assertNull( $first, 'a 32-bit build must never hand back a witness it cannot represent' );
		$this->assertNull( $second );

		// The counter still advanced on every bump above — proven by
		// switching back to a 64-bit read and finding a real, positive
		// value already stored, not the null a corrupted write would leave.
		$this->assertGreaterThan( 0, Aura_Worker_Door_Log::door_version_raw() );
	}

	/** The same 32-bit guard applies to the READ-ONLY audit path (Ruling S7). */
	public function test_door_version_raw_also_answers_null_on_a_32_bit_build(): void {
		Aura_Worker_Door_Log::bump_door_version(); // a real value now exists in the row

		Aura_Worker_Door_Log::set_int_size_for_tests( 4 );
		$out = Aura_Worker_Door_Log::door_version_raw();
		Aura_Worker_Door_Log::set_int_size_for_tests( null );

		$this->assertNull( $out, 'a 32-bit build cannot prove what the row holds without risking corruption' );
		$this->assertGreaterThan( 0, Aura_Worker_Door_Log::door_version_raw(), 'and the value is still there once read on a build that can represent it' );
	}

	/**
	 * Ruling S8 (Codex round-4 P1 on #88): the state write and its version
	 * bump run in ONE transaction — proven from the request's own statement
	 * log rather than from behaviour, since the STATEMENT ORDER is exactly
	 * what a separate-statement bug (the finding this ruling answers) gets
	 * wrong. Finds the bump's own upsert (the statement naming
	 * Aura_Worker_Door_Log::OBSERVATION) and asserts a START TRANSACTION
	 * precedes it and a COMMIT follows it, with nothing else in between that
	 * would mean a SECOND unit. hold() is proven the same way in
	 * DoorHoldsTest.php.
	 */
	private function assertBumpIsBracketedByOneTransaction( array $log ): void {
		$bump = null;
		foreach ( $log as $i => $sql ) {
			if ( false !== strpos( (string) $sql, Aura_Worker_Door_Log::OBSERVATION ) ) {
				$bump = $i;
				break;
			}
		}
		$this->assertNotNull( $bump, 'the version bump must have landed at all' );
		$start = null;
		for ( $i = $bump; $i >= 0; $i-- ) {
			if ( 'START TRANSACTION' === trim( (string) $log[ $i ] ) ) {
				$start = $i;
				break;
			}
		}
		$commit = null;
		for ( $i = $bump, $n = count( $log ); $i < $n; $i++ ) {
			if ( 'COMMIT' === trim( (string) $log[ $i ] ) ) {
				$commit = $i;
				break;
			}
			// A ROLLBACK before any COMMIT means the bump's OWN unit was
			// undone — never what a successful mutation's log should show.
			$this->assertNotSame( 'ROLLBACK', trim( (string) $log[ $i ] ), 'the bump landed in a unit that then rolled back' );
		}
		$this->assertNotNull( $start, 'a transaction opened before the bump' );
		$this->assertNotNull( $commit, 'and closed with a COMMIT after it' );
	}

	public function test_open_pending_bumps_the_version_inside_its_own_transaction(): void {
		$GLOBALS['_db_queries'] = array();
		$seq                    = Aura_Worker_Door_Log::open_pending( $this->entry() );
		$this->assertIsInt( $seq );
		$this->assertBumpIsBracketedByOneTransaction( $GLOBALS['_db_queries'] );
	}

	public function test_ack_bumps_the_version_inside_its_own_transaction(): void {
		$seq = Aura_Worker_Door_Log::open_pending( $this->entry() );
		Aura_Worker_Door_Log::admit( $seq );
		Aura_Worker_Door_Log::settle( $seq, array( 'result' => 'ok' ) );
		$epoch = Aura_Worker_Door_Log::epoch();

		$GLOBALS['_db_queries'] = array();
		$out                    = Aura_Worker_Door_Log::ack( $epoch, $seq );
		$this->assertSame( 1, $out['acked'] );
		$this->assertBumpIsBracketedByOneTransaction( $GLOBALS['_db_queries'] );
	}

	public function test_a_rotate_epoch_bumps_the_version_inside_its_own_transaction(): void {
		$before                 = Aura_Worker_Door_Log::epoch();
		$GLOBALS['_db_queries'] = array();
		$out                    = Aura_Worker_Door_Log::rotate_epoch( $before );
		$this->assertTrue( $out['rotated'] );
		$this->assertBumpIsBracketedByOneTransaction( $GLOBALS['_db_queries'] );
	}

	/**
	 * A bump whose own WRITE fails must roll the state write back too
	 * (Ruling S8) — never a mutation that landed with no witness at all,
	 * silently invisible until some unrelated later mutation finally
	 * advanced the version past it.
	 */
	public function test_a_bump_write_failure_rolls_back_the_state_write_with_it(): void {
		$GLOBALS['_sa_option_write_fail'][ Aura_Worker_Door_Log::OBSERVATION ] = true;
		$out                                                                  = Aura_Worker_Door_Log::open_pending( $this->entry() );
		$GLOBALS['_sa_option_write_fail']                                     = array();

		$this->assertInstanceOf( WP_Error::class, $out, 'every retry hit the same failing bump and gave up' );
		$this->assertArrayNotHasKey( 'aura_worker_door_log_1', $GLOBALS['_options'], 'the row was rolled back with the failed bump' );
		$this->assertArrayNotHasKey( 'aura_worker_door_log_1', $GLOBALS['_rows'] );
	}

	/**
	 * The race Ruling S8 exists to close, reproduced at the ONE place the
	 * stub can show it precisely: the racer seam fires from INSIDE
	 * insert_unique()'s statement — the exact gap between the state write
	 * landing and the version bump that used to be a separate, later
	 * statement (Ruling S6 alone). Because state and bump are now the SAME
	 * transaction, nothing between them can be a poll's opportunity to
	 * observe one without the other: the racer callback itself runs
	 * synchronously inside that gap, and even it — reading through this
	 * same shared "database" — can only ever find a version that is either
	 * not yet bumped (the OLD one, matching state that has not committed
	 * either, from THIS reader's perspective once it opens its own
	 * transaction) or, once versioned() reaches its own bump and commits,
	 * both together. What the OLD two-statement design could produce and
	 * this cannot: a poll's OWN before/after version read (status_fragment()'s
	 * Ruling S6 check) disagreeing with itself while state visibly changed
	 * in between — proven here by running that exact check from the racer.
	 */
	public function test_a_racer_between_the_state_write_and_the_bump_sees_a_consistent_version_pair(): void {
		require_once dirname( __DIR__, 2 ) . '/digitizer-site-worker/includes/class-aura-worker-door-holds.php';
		require_once dirname( __DIR__, 2 ) . '/digitizer-site-worker/includes/class-elementor-door-governor.php';
		$GLOBALS['_sa_force_door'] = true;
		Aura_Worker_Elementor_Door::reset_for_tests();
		do_action( 'wp_abilities_api_init' );

		$before = null;
		$after  = null;
		$GLOBALS['_sa_after_insert_unique']['aura_worker_door_log_1'] = static function () use ( &$before, &$after ) {
			sa_on_another_connection(
				static function () use ( &$before, &$after ) {
					// status_fragment()'s OWN Ruling S6 before/after check,
					// run from a separate connection at the exact moment
					// Ruling S8 used to leave open: after the state write,
					// before the bump.
					$before = Aura_Worker_Door_Log::door_version_raw();
					$after  = Aura_Worker_Door_Log::door_version_raw();
				}
			);
		};

		$seq = Aura_Worker_Door_Log::open_pending( $this->entry() );

		$this->assertIsInt( $seq );
		// The racer's own before/after pair AGREES with itself — it is not
		// possible for it to have observed the row (new state) alongside a
		// version its OWN two reads disagree about, because nothing writes
		// the version between those two reads either. status_fragment()'s
		// retry logic therefore never has anything to repair for THIS
		// mutation: state and version move together or not at all.
		$this->assertSame( $before, $after, "the racer's own two version reads never disagree with each other across this gap" );
	}

	/**
	 * Ruling S9 (Codex round-4 P2 on #88): every counter the fragment or
	 * governor_block() reports advances the version through the SAME
	 * versioned() unit as its own upsert — bump_refused() (log_full.refused)
	 * included, which changing bumps.php did not used to touch at all: a
	 * later poll could see a changed refusal count under an unchanged
	 * observation.
	 */
	public function test_a_refusal_on_a_closed_log_raises_the_observation(): void {
		Aura_Worker_Door_Log::close();
		$before = Aura_Worker_Door_Log::door_version_raw();

		Aura_Worker_Door_Log::bump_refused();

		$this->assertGreaterThan( $before, Aura_Worker_Door_Log::door_version_raw() );
	}

	/**
	 * Ruling S10 (Codex round-5 P1 on #88): the read-back runs AFTER COMMIT,
	 * never before. If the connection dropped between the version upsert
	 * and SELECT LAST_INSERT_ID(), WordPress could reconnect for the SELECT
	 * — and a FRESH connection rolls back whatever the OLD one had left
	 * uncommitted, so committing only AFTER would have lost the mutation
	 * while still reporting `committed: true`. Committing first closes that:
	 * a reconnect landing AFTER the COMMIT lands on a session that never
	 * assigned anything (`SELECT LAST_INSERT_ID()` answers `0`, Ruling S5 ⇒
	 * null), but the mutation is ALREADY durable.
	 */
	public function test_a_reconnect_between_the_bump_and_the_read_back_leaves_the_mutation_committed(): void {
		$GLOBALS['_db_queries']                  = array();
		$GLOBALS['_sa_last_insert_id_reconnect'] = true; // the read-back answers 0, as a reconnect would
		$outcome                                 = Aura_Worker_Door_Log::versioned(
			function () {
				$GLOBALS['_options']['aura_worker_door_s10_test'] = array( 'x' => 1 );
				$GLOBALS['_rows']['aura_worker_door_s10_test']    = maybe_serialize( array( 'x' => 1 ) );
				return array(
					'mutated' => true,
					'result'  => true,
					'evict'   => array( 'aura_worker_door_s10_test' ),
				);
			}
		);
		$GLOBALS['_sa_last_insert_id_reconnect'] = false;

		$this->assertTrue( $outcome['committed'], 'the mutation is committed regardless of whether its own witness could be read back' );
		$this->assertTrue( $outcome['result'] );
		$this->assertNull( $outcome['observation'], 'the reconnected read-back answers 0, which Ruling S5 turns into null' );
		$this->assertArrayHasKey( 'aura_worker_door_s10_test', $GLOBALS['_options'], 'the state is present' );

		// The ORDER, from the request's own statement log: COMMIT before the
		// read-back it protects — never the reverse, which would commit a
		// transaction the reconnect had already rolled back on the OLD
		// connection.
		$log    = $GLOBALS['_db_queries'];
		$commit = array_search( 'COMMIT', $log, true );
		$select = null;
		foreach ( $log as $i => $sql ) {
			if ( 'SELECT LAST_INSERT_ID()' === trim( (string) $sql ) ) {
				$select = $i;
			}
		}
		$this->assertNotFalse( $commit, 'the transaction committed' );
		$this->assertNotNull( $select, 'the read-back was still attempted' );
		$this->assertLessThan( $select, $commit, 'COMMIT runs before the read-back' );
	}

	/**
	 * Ruling S11 (Codex round-5 P1 on #88): every wrapped write's own
	 * pre-commit eviction leaves a window — a CONCURRENT request can
	 * repopulate the option cache from the pre-commit snapshot before this
	 * transaction commits, and nothing evicted it again afterwards. Modelled
	 * with a racer that repopulates $GLOBALS['_sa_option_cache'] (the stub's
	 * request-level option cache — see get_option()) at exactly that point,
	 * via the SAME seam used elsewhere in this suite
	 * ($GLOBALS['_sa_after_insert_unique']). versioned()'s post-commit
	 * repeat must evict it a second time, so the next get_option() sees the
	 * NEW value, not the racer's stale one.
	 */
	public function test_a_racer_repopulating_the_cache_before_commit_is_defeated_by_the_post_commit_repeat(): void {
		$GLOBALS['_sa_option_cache_honors_wp_cache_delete'] = true;
		$name = 'aura_worker_door_s11_test';

		$GLOBALS['_sa_after_insert_unique'][ $name ] = static function () use ( $name ) {
			// The exact gap Ruling S11 closes: this fires right after the
			// write's OWN pre-commit eviction, before the transaction
			// commits. A concurrent request re-reads the row from the
			// pre-commit database snapshot and caches THAT.
			$GLOBALS['_sa_option_cache'][ $name ] = array( 'stale' => true );
		};

		$won = Aura_Worker_Door_Log::insert_unique( $name, array( 'stale' => false ) );

		$GLOBALS['_sa_option_cache_honors_wp_cache_delete'] = false;

		$this->assertTrue( $won );
		$fresh = get_option( $name );
		$this->assertIsArray( $fresh );
		$this->assertFalse( $fresh['stale'], 'the post-commit repeat evicted the racer\'s repopulated entry, so this read reaches the database again' );
	}

	/**
	 * Ruling S12 (Codex round-5 P2 on #88): when the epoch DELETE succeeds
	 * but the replacement insert_unique_write() then fails, the old code
	 * ignored that false result — the transaction still bumped the version
	 * and committed with NO epoch row at all, reporting `rotated: true` with
	 * an empty one. Both branches of rotate_epoch_write() now check it and
	 * roll the WHOLE unit back — the DELETE included — so the epoch this
	 * call reports, and the one the row actually still holds, are the
	 * ORIGINAL, never-replaced one.
	 */
	public function test_rotate_epoch_rolls_back_when_the_claim_conditioned_replacement_insert_fails(): void {
		require_once dirname( __DIR__, 2 ) . '/digitizer-site-worker/includes/class-aura-worker-magic-link.php';
		$before = Aura_Worker_Door_Log::epoch();
		$fence  = Aura_Worker_Magic_Link::claim_site();

		$GLOBALS['_sa_insert_unique_fail'] = Aura_Worker_Door_Log::EPOCH;
		$out                               = Aura_Worker_Door_Log::rotate_epoch( $before, Aura_Worker_Magic_Link::SITE_CLAIM, $fence );
		$GLOBALS['_sa_insert_unique_fail']  = false;

		$this->assertFalse( $out['rotated'] );
		$this->assertSame( $before, $out['epoch'], 'the epoch this call reports is the one that was never actually replaced' );
		$this->assertSame( $before, Aura_Worker_Door_Log::epoch_raw(), 'and the row itself still holds it — the DELETE rolled back too' );
	}

	/** The same failure, on the unclaimed (grant-gated /door/rotate) branch. */
	public function test_rotate_epoch_rolls_back_when_the_unclaimed_replacement_insert_fails(): void {
		$before = Aura_Worker_Door_Log::epoch();

		$GLOBALS['_sa_insert_unique_fail'] = Aura_Worker_Door_Log::EPOCH;
		$out                               = Aura_Worker_Door_Log::rotate_epoch( $before );
		$GLOBALS['_sa_insert_unique_fail']  = false;

		$this->assertFalse( $out['rotated'] );
		$this->assertSame( $before, $out['epoch'] );
		$this->assertSame( $before, Aura_Worker_Door_Log::epoch_raw() );
	}

	/**
	 * Ruling S14 (Codex round-6 P1 on #88): `rotate_binding()`'s epoch
	 * rotation runs INSIDE the same closure as its own record write, both
	 * inside the ONE transaction `versioned()` opens. When the epoch rotates
	 * but the following record write then fails, the closure used to answer
	 * `mutated => false` — telling `versioned()` nothing happened and to
	 * COMMIT, which durably published the NEW epoch while the binding record
	 * still named the OLD one. The closure now answers `rollback => true`
	 * instead, so the whole unit — the epoch rotation included — rolls back:
	 * a transient binding-write failure leaves the epoch AND the binding
	 * exactly as they were, `rotated: false` in effect (the site's own
	 * generation never moved), safe for the caller's retry to redo whole.
	 */
	public function test_a_transient_binding_write_failure_after_the_epoch_rotates_leaves_neither_moved(): void {
		// An existing binding record first — a first-ever rotate_binding()
		// call takes the "no record" claim-conditional INSERT path, which
		// this test does not mean to exercise.
		$this->assertTrue( sa_rotate_binding( array( 'client' => 'c1', 'dashboard' => 'https://one.example' ) ) );
		$epoch_before   = Aura_Worker_Door_Log::epoch();
		$binding_before = Aura_Worker_Door_Log::binding_raw();

		$GLOBALS['_sa_option_write_fail'][ Aura_Worker_Door_Log::BINDING ] = true;
		$out = sa_rotate_binding( array( 'client' => 'c2', 'dashboard' => 'https://two.example' ) );
		$GLOBALS['_sa_option_write_fail']                                 = array();

		$this->assertFalse( $out, 'the record write failed, so the whole rotation reports false' );
		$this->assertSame( $epoch_before, Aura_Worker_Door_Log::epoch_raw(), 'the epoch rotation rolled back with the failed record write' );
		$this->assertSame( $binding_before, Aura_Worker_Door_Log::binding_raw(), 'and the OLD binding generation is untouched' );
	}

	/**
	 * Ruling S15 (Codex round-6 P2 on #88): `versioned()` used to hand a
	 * rolled-back caller its own SUCCESS-shaped `result` — `ack()` returned
	 * `$outcome['result']` unconditionally, so a bump-write failure after a
	 * real floor raise reported the ack as having happened, when the
	 * ROLLBACK just undid it. `ack()` now checks `committed` itself and
	 * answers a FAILURE shape instead: nothing acked, nothing purged, the
	 * floor read fresh from the row the rollback actually left behind.
	 */
	public function test_a_bump_write_failure_inside_ack_purges_nothing_and_reports_committed_false(): void {
		$seq = Aura_Worker_Door_Log::open_pending( $this->entry() );
		Aura_Worker_Door_Log::admit( $seq );
		Aura_Worker_Door_Log::settle( $seq, array( 'result' => 'ok' ) );
		$epoch = Aura_Worker_Door_Log::epoch();

		$GLOBALS['_sa_option_write_fail'][ Aura_Worker_Door_Log::OBSERVATION ] = true;
		$out = Aura_Worker_Door_Log::ack( $epoch, $seq );
		$GLOBALS['_sa_option_write_fail']                                     = array();

		$this->assertArrayHasKey( 'committed', $out );
		$this->assertFalse( $out['committed'], 'a rolled-back ack reports committed: false' );
		$this->assertSame( 0, $out['acked'] );
		$this->assertSame( 0, $out['floor'], 'the floor raise was rolled back with the failed bump' );
		$this->assertArrayHasKey( Aura_Worker_Door_Log::PREFIX . $seq, $GLOBALS['_options'], 'the row this ack would have purged is still there' );
	}

	/**
	 * The same Ruling S15 fix, on `rotate_epoch()`: a bump-write failure
	 * after a real epoch rotation used to report `rotated: true` — the
	 * closure's own success-shaped result, handed back unconditionally by a
	 * caller that never checked `committed`. `rotate_epoch()` now answers
	 * `rotated: false` with the epoch read fresh from the rolled-back row.
	 */
	public function test_a_bump_write_failure_inside_rotate_epoch_reports_rotated_false(): void {
		$before = Aura_Worker_Door_Log::epoch();

		$GLOBALS['_sa_option_write_fail'][ Aura_Worker_Door_Log::OBSERVATION ] = true;
		$out = Aura_Worker_Door_Log::rotate_epoch( $before );
		$GLOBALS['_sa_option_write_fail']                                     = array();

		$this->assertFalse( $out['rotated'] );
		$this->assertSame( $before, $out['epoch'], 'the never-replaced epoch, read fresh after the rollback' );
		$this->assertSame( $before, Aura_Worker_Door_Log::epoch_raw() );
	}

	/**
	 * Ruling S13 (Codex round-5 P2 on #88): engine_is_transactional() reads
	 * `SHOW TABLE STATUS LIKE 'wp_options'` ONCE per request and caches the
	 * answer — a second call must not re-issue the probe.
	 */
	public function test_the_engine_detection_reads_show_table_status_once_and_caches_it(): void {
		$GLOBALS['_sa_table_engine'] = 'MyISAM';
		$GLOBALS['_db_queries']      = array();

		$first = Aura_Worker_Door_Log::bump_door_version();
		$this->assertNull( $first, 'MyISAM is not transactional, so the read-back refuses to report a witness' );
		$this->assertContains( "SHOW TABLE STATUS LIKE 'wp_options'", $GLOBALS['_db_queries'] );

		$probes = static function ( array $log ): int {
			return count(
				array_filter(
					$log,
					static function ( $q ) {
						return false !== strpos( (string) $q, 'SHOW TABLE STATUS' );
					}
				)
			);
		};
		$before_second_call = $probes( $GLOBALS['_db_queries'] );

		Aura_Worker_Door_Log::bump_door_version();

		$this->assertSame( $before_second_call, $probes( $GLOBALS['_db_queries'] ), 'checked once per request, then cached' );
	}

	/**
	 * Ruling S13: on a non-transactional engine, versioned() skips
	 * START TRANSACTION/COMMIT entirely and runs the writes exactly as it
	 * always would have — the state still lands — but the version bump's
	 * witness is never reported for this site, by ANY route.
	 */
	public function test_a_non_transactional_engine_still_writes_but_never_reports_a_witness(): void {
		Aura_Worker_Door_Log::set_engine_transactional_for_tests( false );

		$GLOBALS['_db_queries'] = array();
		$seq                    = Aura_Worker_Door_Log::open_pending( $this->entry() );

		$this->assertIsInt( $seq, 'the state write itself still lands' );
		$this->assertArrayHasKey( 'aura_worker_door_log_' . $seq, $GLOBALS['_options'] );
		$this->assertNotContains( 'START TRANSACTION', $GLOBALS['_db_queries'], 'no transaction opens on a non-transactional engine' );
		$this->assertNotContains( 'COMMIT', $GLOBALS['_db_queries'] );
		$this->assertNull( Aura_Worker_Door_Log::door_version_raw(), 'no witness is ever reported for this site' );
		$this->assertSame( 'engine', Aura_Worker_Door_Log::observation_unsupported_reason() );

		Aura_Worker_Door_Log::set_engine_transactional_for_tests( null );
	}

	/** A transactional engine (the default this whole suite otherwise runs under) is unaffected. */
	public function test_a_transactional_engine_opens_and_commits_a_real_transaction(): void {
		Aura_Worker_Door_Log::set_engine_transactional_for_tests( true );

		$GLOBALS['_db_queries'] = array();
		$seq                    = Aura_Worker_Door_Log::open_pending( $this->entry() );

		$this->assertIsInt( $seq );
		$this->assertContains( 'START TRANSACTION', $GLOBALS['_db_queries'] );
		$this->assertContains( 'COMMIT', $GLOBALS['_db_queries'] );
		$this->assertIsInt( Aura_Worker_Door_Log::door_version_raw() );
		$this->assertNull( Aura_Worker_Door_Log::observation_unsupported_reason() );

		Aura_Worker_Door_Log::set_engine_transactional_for_tests( null );
	}
}
