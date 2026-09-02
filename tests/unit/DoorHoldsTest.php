<?php
/**
 * Held door writes: one option row per hold, claimed by MOVING it, and every
 * race between claim, reject and expiry resolved so that at most one side
 * proceeds and nothing runs twice (spec §3.6, §3.7, round-9/round-16).
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class DoorHoldsTest extends TestCase {

	protected function setUp(): void {
		sa_reset_state();
		require_once dirname( __DIR__, 2 ) . '/digitizer-site-worker/includes/class-aura-worker-door-log.php';
		require_once dirname( __DIR__, 2 ) . '/digitizer-site-worker/includes/class-aura-worker-door-holds.php';
	}

	private function call( array $over = array() ): array {
		return $over + array(
			'ability' => 'elementor/publish-document',
			'input'   => array( 'post_id' => 7 ),
			'touches' => array( array( 'type' => 'page', 'id' => '7' ) ),
			'actor'   => array( 'user_id' => 3, 'login' => 'bot', 'app_password_name' => 'Elementor MCP (Claude)', 'app_password_uuid' => 'u', 'via' => 'mcp' ),
			'verdict' => 'none',
			'rule'    => null,
		);
	}

	public function test_a_hold_is_one_row_named_by_its_ref_with_a_7_day_ttl(): void {
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		$this->assertMatchesRegularExpression( '/^door_[0-9a-f-]{36}$/', $ref );
		$row = $GLOBALS['_options'][ 'aura_worker_door_held_' . $ref ];
		$this->assertSame( 7, $row['input']['post_id'] );
		$this->assertSame( 'none', $row['verdict'] );
		$this->assertEqualsWithDelta( time() + 7 * 86400, strtotime( $row['expires_at'] ), 5 );
	}

	public function test_a_claimed_ref_still_holds_a_slot(): void {
		for ( $i = 0; $i < 49; $i++ ) {
			Aura_Worker_Door_Holds::hold( $this->call() );
		}
		$ref = Aura_Worker_Door_Holds::hold( $this->call() ); // 50th
		Aura_Worker_Door_Holds::claim( $ref );                  // moved to CLAIMED
		$this->assertSame( 50, Aura_Worker_Door_Holds::count() );
		$this->assertSame( 'aura_hold_queue_full', Aura_Worker_Door_Holds::hold( $this->call() )->get_error_code() );
	}

	public function test_hold_serialises_count_and_insert_under_the_lock(): void {
		add_option( Aura_Worker_Door_Holds::LOCK, time(), '', 'no' ); // a fresh lock: another request is inside hold()
		$err = Aura_Worker_Door_Holds::hold( $this->call() );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'aura_hold_busy', $err->get_error_code() );
		$this->assertSame( 5, $err->get_error_data()['retry_after'] );
		$this->assertSame( 0, Aura_Worker_Door_Holds::count() );

		update_option( Aura_Worker_Door_Holds::LOCK, time() - Aura_Worker_Door_Holds::LOCK_S - 1 ); // a crashed holder
		$this->assertIsString( Aura_Worker_Door_Holds::hold( $this->call() ) );
		$this->assertFalse( get_option( Aura_Worker_Door_Holds::LOCK ) ); // released after the hold
		$this->assertSame( 1, Aura_Worker_Door_Holds::count() );

		$GLOBALS['_sa_insert_unique_fail'] = true; // the row insert fails inside the lock…
		$this->assertSame( 'aura_hold_failed', Aura_Worker_Door_Holds::hold( $this->call() )->get_error_code() );
		unset( $GLOBALS['_sa_insert_unique_fail'] );
		$this->assertFalse( get_option( Aura_Worker_Door_Holds::LOCK ) ); // …and the lock is still released
	}

	public function test_two_racers_on_a_stale_lock_only_one_of_them_takes_it(): void {
		$stale = time() - Aura_Worker_Door_Holds::LOCK_S - 1;
		add_option( Aura_Worker_Door_Holds::LOCK, $stale, '', 'no' );
		// Racer B replaces the crashed holder's lock with its OWN fresh one
		// in the exact window between this call's read of the stale bytes
		// and its fenced delete keyed on them — the race round-1's fix
		// closes. A distinguishable timestamp (far in the future) so the
		// assertion below cannot be satisfied by A's own writes.
		$racers_lock = time() + 100000;
		$GLOBALS['_sa_before_swap'] = static function () use ( $racers_lock ) {
			$GLOBALS['_sa_before_swap']                       = null; // fires once
			$GLOBALS['_options'][ Aura_Worker_Door_Holds::LOCK ] = $racers_lock;
			$GLOBALS['_rows'][ Aura_Worker_Door_Holds::LOCK ]    = (string) $racers_lock;
		};
		$err = Aura_Worker_Door_Holds::hold( $this->call() );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'aura_hold_busy', $err->get_error_code(), 'A never got past the fenced delete: its own insert_unique() keeps meeting a lock it does not own' );
		$this->assertSame( $racers_lock, $GLOBALS['_options'][ Aura_Worker_Door_Holds::LOCK ], "the racer's lock was never overwritten by A" );
		$this->assertSame( 0, Aura_Worker_Door_Holds::count(), 'no held row leaked while the lock could not be taken' );
	}

	public function test_the_fenced_lock_delete_never_removes_a_value_it_did_not_read(): void {
		global $wpdb;
		$stale = time() - Aura_Worker_Door_Holds::LOCK_S - 1;
		add_option( Aura_Worker_Door_Holds::LOCK, $stale, '', 'no' ); // what a reader would see
		// Something replaces the lock's value between that read and the
		// delete fenced on it — the exact window round-1's fix closes,
		// exercised directly against the fenced-DELETE SQL shape itself
		// rather than through take_lock()'s retry loop.
		$fresh = time();
		$GLOBALS['_sa_before_swap'] = static function () use ( $fresh ) {
			$GLOBALS['_sa_before_swap']                       = null;
			$GLOBALS['_options'][ Aura_Worker_Door_Holds::LOCK ] = $fresh;
			$GLOBALS['_rows'][ Aura_Worker_Door_Holds::LOCK ]    = (string) $fresh;
		};
		$gone = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", Aura_Worker_Door_Holds::LOCK, (string) $stale ) );
		$this->assertSame( 0, (int) $gone, 'the delete was fenced on stale bytes that no longer match' );
		$this->assertSame( $fresh, $GLOBALS['_options'][ Aura_Worker_Door_Holds::LOCK ], 'the value that replaced it survives' );
	}

	public function test_the_51st_hold_is_refused_queue_full(): void {
		for ( $i = 0; $i < 50; $i++ ) {
			$this->assertIsString( Aura_Worker_Door_Holds::hold( $this->call() ) );
		}
		$err = Aura_Worker_Door_Holds::hold( $this->call() );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'aura_hold_queue_full', $err->get_error_code() );
	}

	public function test_a_hold_whose_insert_fails_is_refused_hold_failed(): void {
		$GLOBALS['_sa_insert_unique_fail'] = true; // bootstrap seam: insert_unique() returns false without writing
		$err                                = Aura_Worker_Door_Holds::hold( $this->call() );
		$this->assertSame( 'aura_hold_failed', $err->get_error_code() );
	}

	public function test_claim_moves_the_row_and_a_second_claim_answers_not_held(): void {
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		$c   = Aura_Worker_Door_Holds::claim( $ref );
		$this->assertIsArray( $c );
		$this->assertArrayHasKey( 'claimed_at', $c );
		$this->assertArrayNotHasKey( 'aura_worker_door_held_' . $ref, $GLOBALS['_options'] );
		$this->assertArrayHasKey( 'aura_worker_door_claimed_' . $ref, $GLOBALS['_options'] );
		$again = Aura_Worker_Door_Holds::claim( $ref );
		$this->assertSame( 'not_held', $again->get_error_code() );
	}

	public function test_a_claim_that_finds_the_held_row_gone_backs_out(): void {
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		// A reject wins the race between the replay's read and its claim.
		$GLOBALS['_sa_before_swap'] = static function () use ( $ref ) {
			unset( $GLOBALS['_options'][ 'aura_worker_door_held_' . $ref ], $GLOBALS['_rows'][ 'aura_worker_door_held_' . $ref ] );
		};
		$err = Aura_Worker_Door_Holds::claim( $ref );
		$this->assertSame( 'not_held', $err->get_error_code() );
		$this->assertArrayNotHasKey( 'aura_worker_door_claimed_' . $ref, $GLOBALS['_options'], 'the claim backed out' );
	}

	public function test_reject_refuses_to_delete_a_held_row_that_has_a_claimed_twin(): void {
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		$GLOBALS['_options'][ 'aura_worker_door_claimed_' . $ref ] = $GLOBALS['_options'][ 'aura_worker_door_held_' . $ref ]; // an orphaned pair
		$this->assertSame( 'already_claimed', Aura_Worker_Door_Holds::reject( $ref ) );
		$this->assertArrayHasKey( 'aura_worker_door_held_' . $ref, $GLOBALS['_options'] );
		$this->assertSame( 'not_held', Aura_Worker_Door_Holds::reject( 'door_nope' ) );
	}

	public function test_refresh_rule_never_recreates_a_deleted_row(): void {
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		$this->assertTrue( Aura_Worker_Door_Holds::refresh_rule( $ref, array( 'key' => 'rule/w', 'ruleHash' => 'h', 'reason' => 'r' ) ) );
		$this->assertSame( 'rule/w', $GLOBALS['_options'][ 'aura_worker_door_held_' . $ref ]['rule']['key'] );
		Aura_Worker_Door_Holds::reject( $ref );
		$this->assertFalse( Aura_Worker_Door_Holds::refresh_rule( $ref, array( 'key' => 'rule/w2', 'ruleHash' => 'h2', 'reason' => 'r' ) ) );
		$this->assertArrayNotHasKey( 'aura_worker_door_held_' . $ref, $GLOBALS['_options'] );
	}

	public function test_listing_hides_a_held_row_with_a_claimed_twin_and_never_carries_the_input(): void {
		$a = Aura_Worker_Door_Holds::hold( $this->call() );
		$b = Aura_Worker_Door_Holds::hold( $this->call( array( 'verdict' => 'warn', 'rule' => array( 'key' => 'rule/w', 'ruleHash' => 'h', 'reason' => 'careful' ) ) ) );
		Aura_Worker_Door_Holds::claim( $a );
		$GLOBALS['_options'][ 'aura_worker_door_held_' . $a ] = $GLOBALS['_options'][ 'aura_worker_door_claimed_' . $a ]; // orphan pair for a
		$list = Aura_Worker_Door_Holds::listing();
		$this->assertSame( array( $b ), array_column( $list, 'ref' ) );
		$this->assertArrayNotHasKey( 'input', $list[0] );
		$this->assertSame( 'warn', $list[0]['verdict'] );
		$this->assertSame( 'careful', $list[0]['rule']['reason'] );
	}

	public function test_listing_excludes_an_expired_but_unclaimed_hold(): void {
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		// Same $_rows caveat as sweep()'s test below: rows() reads the database.
		$GLOBALS['_options'][ 'aura_worker_door_held_' . $ref ]['expires_at'] = gmdate( 'c', time() - 10 );
		$GLOBALS['_rows'][ 'aura_worker_door_held_' . $ref ] = maybe_serialize( $GLOBALS['_options'][ 'aura_worker_door_held_' . $ref ] );
		$this->assertSame( array(), Aura_Worker_Door_Holds::listing() );
	}

	public function test_sweep_expires_holds_and_removes_orphaned_held_twins(): void {
		$a = Aura_Worker_Door_Holds::hold( $this->call() );
		$b = Aura_Worker_Door_Holds::hold( $this->call() );
		// rows() reads the DATABASE ($_rows), not this request's option cache —
		// a mutation seeded only into $_options would be invisible to it, so
		// both are written here (the same reason DoorLogTest::backdate() does).
		$GLOBALS['_options'][ 'aura_worker_door_held_' . $a ]['expires_at'] = gmdate( 'c', time() - 10 );
		$GLOBALS['_rows'][ 'aura_worker_door_held_' . $a ] = maybe_serialize( $GLOBALS['_options'][ 'aura_worker_door_held_' . $a ] );
		Aura_Worker_Door_Holds::claim( $b );
		$GLOBALS['_options'][ 'aura_worker_door_held_' . $b ] = $GLOBALS['_options'][ 'aura_worker_door_claimed_' . $b ];
		$GLOBALS['_rows'][ 'aura_worker_door_held_' . $b ]    = maybe_serialize( $GLOBALS['_options'][ 'aura_worker_door_held_' . $b ] );
		Aura_Worker_Door_Holds::sweep( time() );
		$this->assertArrayNotHasKey( 'aura_worker_door_held_' . $a, $GLOBALS['_options'] );
		$this->assertArrayNotHasKey( 'aura_worker_door_held_' . $b, $GLOBALS['_options'] );
		$this->assertArrayHasKey( 'aura_worker_door_claimed_' . $b, $GLOBALS['_options'] );
	}
}
