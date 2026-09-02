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

	public function test_rotate_epoch_mints_a_new_epoch_clears_floor_and_closure_but_keeps_every_row(): void {
		$before = Aura_Worker_Door_Log::epoch();
		$seq    = Aura_Worker_Door_Log::open_pending( $this->entry() );
		Aura_Worker_Door_Log::admit( $seq );
		Aura_Worker_Door_Log::settle( $seq, array( 'result' => 'ok' ) );
		Aura_Worker_Door_Log::ack( $before, $seq );
		Aura_Worker_Door_Log::close();
		Aura_Worker_Door_Log::bump_refused();
		$seq2 = Aura_Worker_Door_Log::open_pending( $this->entry() );

		$after = Aura_Worker_Door_Log::rotate_epoch();

		$this->assertNotSame( $before, $after );
		$this->assertSame( $after, Aura_Worker_Door_Log::epoch() );
		$this->assertSame( 0, Aura_Worker_Door_Log::floor() );
		$this->assertFalse( Aura_Worker_Door_Log::is_closed() );
		$this->assertNull( Aura_Worker_Door_Log::full_report() );
		$this->assertNotNull( Aura_Worker_Door_Log::get( $seq2 ), 'a row survives the rotation' );
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
}
