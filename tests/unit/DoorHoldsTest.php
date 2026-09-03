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

	/** The LIKE prefix the held read carries, as the statement escapes it. */
	private function heldReadKey(): string {
		return $GLOBALS['wpdb']->esc_like( Aura_Worker_Door_Holds::HELD );
	}

	/**
	 * Ruling P57: an unreadable queue is not an empty one.
	 *
	 * `rows()` cast a failed `get_results()` to `array()`, so `count()` read
	 * the queue as below capacity and `hold()` admitted past CAP — again and
	 * again, for as long as the read kept failing. The bounded approval queue
	 * was bounded only while the database was healthy.
	 */
	public function test_a_hold_refuses_retryably_when_the_queue_cannot_be_read(): void {
		$GLOBALS['_sa_rows_read_error'][ $this->heldReadKey() ] = true;

		$out = Aura_Worker_Door_Holds::hold( $this->call() );

		$GLOBALS['_sa_rows_read_error'] = array();
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aura_hold_failed', $out->get_error_code(), 'retryable, and NOT queue_full' );
		$this->assertSame( 503, $out->get_error_data()['status'] );
		$this->assertSame( array(), Aura_Worker_Door_Holds::listing(), 'nothing was inserted' );
		$this->assertSame( 0, Aura_Worker_Door_Holds::count() );
	}

	/** count() answers null rather than a false zero. */
	public function test_an_unreadable_queue_counts_as_null_not_zero(): void {
		Aura_Worker_Door_Holds::hold( $this->call() );
		$this->assertSame( 1, Aura_Worker_Door_Holds::count() );

		$GLOBALS['_sa_rows_read_error'][ $this->heldReadKey() ] = true;
		$this->assertNull( Aura_Worker_Door_Holds::count() );
		$this->assertTrue( Aura_Worker_Door_Holds::queue_unreadable() );
		$GLOBALS['_sa_rows_read_error'] = array();
	}

	/** A sweep with an unreadable read deletes nothing. */
	public function test_a_sweep_with_an_unreadable_queue_deletes_nothing(): void {
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		$GLOBALS['_options'][ 'aura_worker_door_held_' . $ref ]['expires_at'] = gmdate( 'c', time() - 10 );
		$GLOBALS['_rows'][ 'aura_worker_door_held_' . $ref ] = maybe_serialize( $GLOBALS['_options'][ 'aura_worker_door_held_' . $ref ] );
		$GLOBALS['_sa_rows_read_error'][ $this->heldReadKey() ] = true;

		$this->assertSame( 0, Aura_Worker_Door_Holds::sweep( time(), self::CLAIM_STALE_MS ) );

		$GLOBALS['_sa_rows_read_error'] = array();
		$this->assertArrayHasKey( 'aura_worker_door_held_' . $ref, $GLOBALS['_rows'], 'never delete on a guess' );
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
		// Racer B wins the SAME window A's own fenced delete is about to
		// close: it fires INSIDE that delete's own SQL branch (never
		// _sa_before_swap, which also fires on take_lock()'s very first
		// insert_unique() attempt — before staleness is even judged — and so
		// cannot tell a fenced delete from an unconditional one; round-2
		// finding). B deletes the stale row and installs its OWN fresh lock,
		// exactly as a real racer's insert_unique() would leave things.
		$racers_lock = time();
		$GLOBALS['_sa_before_fenced_delete'][ Aura_Worker_Door_Holds::LOCK ] = static function () use ( $racers_lock ) {
			unset( $GLOBALS['_options'][ Aura_Worker_Door_Holds::LOCK ], $GLOBALS['_rows'][ Aura_Worker_Door_Holds::LOCK ] );
			$GLOBALS['_options'][ Aura_Worker_Door_Holds::LOCK ] = $racers_lock;
			$GLOBALS['_rows'][ Aura_Worker_Door_Holds::LOCK ]    = (string) $racers_lock;
		};
		$err = Aura_Worker_Door_Holds::hold( $this->call() );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'aura_hold_busy', $err->get_error_code(), 'A never gets past the fenced delete: its own insert_unique() keeps meeting a lock it does not own' );
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
		$GLOBALS['_sa_before_fenced_delete'][ Aura_Worker_Door_Holds::LOCK ] = static function () use ( $fresh ) {
			$GLOBALS['_options'][ Aura_Worker_Door_Holds::LOCK ] = $fresh;
			$GLOBALS['_rows'][ Aura_Worker_Door_Holds::LOCK ]    = (string) $fresh;
		};
		$gone = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", Aura_Worker_Door_Holds::LOCK, (string) $stale ) );
		$this->assertSame( 0, (int) $gone, 'the delete was fenced on stale bytes that no longer match' );
		$this->assertSame( $fresh, $GLOBALS['_options'][ Aura_Worker_Door_Holds::LOCK ], 'the value that replaced it survives' );
	}

	public function test_a_holders_release_never_deletes_a_lock_that_replaced_its_own(): void {
		// A holds the lock, stalls past LOCK_S, and a racer's fenced delete
		// replaces the row with ITS lock. A's `finally` then runs. An
		// unconditional delete_option() there removes the racer's brand-new
		// lock — and B goes on running hold_locked() with no mutex at all,
		// which is the serialization the lock exists to provide.
		//
		// The window is opened inside the HELD row's own INSERT (the shared
		// _sa_before_swap seam, which also fires on take_lock()'s insert — the
		// guard below is what keeps this firing only once the lock is taken).
		$racers_lock = time() . '|racer';
		$GLOBALS['_sa_before_swap'] = static function () use ( $racers_lock ) {
			if ( ! isset( $GLOBALS['_rows'][ Aura_Worker_Door_Holds::LOCK ] ) ) {
				return; // take_lock()'s own insert, before there is a lock at all
			}
			$GLOBALS['_options'][ Aura_Worker_Door_Holds::LOCK ] = $racers_lock;
			$GLOBALS['_rows'][ Aura_Worker_Door_Holds::LOCK ]    = $racers_lock;
		};

		$this->assertIsString( Aura_Worker_Door_Holds::hold( $this->call() ) );

		$this->assertSame( $racers_lock, $GLOBALS['_rows'][ Aura_Worker_Door_Holds::LOCK ] ?? null, "the release was fenced on the bytes this request inserted, so the racer's lock stands" );
	}

	public function test_a_holder_releases_the_lock_it_actually_inserted(): void {
		$this->assertIsString( Aura_Worker_Door_Holds::hold( $this->call() ) );
		$this->assertArrayNotHasKey( Aura_Worker_Door_Holds::LOCK, $GLOBALS['_rows'], 'the ordinary path still releases' );
		$this->assertFalse( get_option( Aura_Worker_Door_Holds::LOCK, false ) );
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

	public function test_unclaim_moves_the_row_back_to_held_with_its_original_fields(): void {
		$ref    = Aura_Worker_Door_Holds::hold( $this->call() );
		$before = $GLOBALS['_options'][ 'aura_worker_door_held_' . $ref ];
		Aura_Worker_Door_Holds::claim( $ref );
		Aura_Worker_Door_Holds::stamp_terminal_seq( $ref, 12 );

		$this->assertTrue( Aura_Worker_Door_Holds::unclaim( $ref ) );

		$back = Aura_Worker_Door_Holds::get_held( $ref );
		$this->assertIsArray( $back );
		$this->assertArrayHasKey( 'restored_at', $back, 'the row says an unclaim put it back (Ruling P41)' );
		unset( $back['restored_at'] );
		$this->assertSame( $before, $back, 'everything else is the row that was held' );
		$this->assertNull( Aura_Worker_Door_Holds::get_claimed( $ref ), 'and the twin is gone' );
		$this->assertSame( 1, Aura_Worker_Door_Holds::count() );
		// The approval was not spent: the ref can be claimed again.
		$this->assertIsArray( Aura_Worker_Door_Holds::claim( $ref ) );
	}

	/**
	 * Ruling P47: the claim MOVE takes the hold lock, like admission.
	 *
	 * Only hold() used to take it, so a claim could complete inside the window
	 * a changed-client connect's (or an unbind's) wipe was deleting rows in —
	 * and the replay ran on into the callback, recreating door state while
	 * executing the DEPARTED client's stored mutation under the replacement
	 * binding.
	 */
	public function test_a_claim_that_cannot_take_the_lock_is_a_lost_race_and_moves_nothing(): void {
		$ref   = Aura_Worker_Door_Holds::hold( $this->call() );
		$token = time() . '|ffffffff-ffff-4fff-8fff-ffffffffffff';
		$GLOBALS['_options'][ Aura_Worker_Door_Holds::LOCK ] = $token;
		$GLOBALS['_rows'][ Aura_Worker_Door_Holds::LOCK ]    = maybe_serialize( $token );

		$out = Aura_Worker_Door_Holds::claim( $ref );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'not_held', $out->get_error_code() );
		$this->assertNotNull( Aura_Worker_Door_Holds::get_held( $ref ), 'the held row is untouched' );
		$this->assertNull( Aura_Worker_Door_Holds::get_claimed( $ref ), 'and no twin was inserted' );
		$this->assertSame( $token, get_option( Aura_Worker_Door_Holds::LOCK ), "the holder's lock is untouched" );

		// …and it works the moment the holder is done.
		delete_option( Aura_Worker_Door_Holds::LOCK );
		unset( $GLOBALS['_rows'][ Aura_Worker_Door_Holds::LOCK ] );
		$this->assertIsArray( Aura_Worker_Door_Holds::claim( $ref ) );
		$this->assertFalse( get_option( Aura_Worker_Door_Holds::LOCK ), 'and released the lock it took' );
	}

	/** unclaim() takes it too — it INSERTS a held row, which a wipe must not meet. */
	public function test_an_unclaim_that_cannot_take_the_lock_restores_nothing(): void {
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		Aura_Worker_Door_Holds::claim( $ref );
		$token = time() . '|ffffffff-ffff-4fff-8fff-ffffffffffff';
		$GLOBALS['_options'][ Aura_Worker_Door_Holds::LOCK ] = $token;
		$GLOBALS['_rows'][ Aura_Worker_Door_Holds::LOCK ]    = maybe_serialize( $token );

		$this->assertFalse( Aura_Worker_Door_Holds::unclaim( $ref ) );

		$this->assertNull( Aura_Worker_Door_Holds::get_held( $ref ) );
		$this->assertNotNull( Aura_Worker_Door_Holds::get_claimed( $ref ), 'the claimed row still stands' );
	}

	/** The claimed row records the BINDING it was claimed under (Ruling P51). */
	public function test_a_claim_records_the_binding_it_was_taken_under(): void {
		$ref     = Aura_Worker_Door_Holds::hold( $this->call() );
		$binding = Aura_Worker_Door_Log::binding();

		Aura_Worker_Door_Holds::claim( $ref );

		$this->assertSame( $binding, Aura_Worker_Door_Holds::claimed_binding( $ref ) );
		$this->assertSame( $binding, Aura_Worker_Door_Holds::get_claimed( $ref )['binding'] );
		$this->assertArrayNotHasKey( 'epoch', Aura_Worker_Door_Holds::get_claimed( $ref ), 'the log epoch is not the fence' );
		// …and it does not follow the row back to the queue.
		Aura_Worker_Door_Holds::unclaim( $ref );
		$this->assertArrayNotHasKey( 'binding', Aura_Worker_Door_Holds::get_held( $ref ) );
		$this->assertNull( Aura_Worker_Door_Holds::claimed_binding( $ref ), 'and a ref with no claimed row has none' );
	}

	/** The binding generation is minted once and survives, exactly like the epoch. */
	public function test_the_binding_generation_is_minted_once_and_is_not_the_epoch(): void {
		$a = Aura_Worker_Door_Log::binding();
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $a );
		$this->assertSame( $a, Aura_Worker_Door_Log::binding() );
		$this->assertNotSame( Aura_Worker_Door_Log::epoch(), $a, 'two independent generations' );
	}

	public function test_unclaim_keeps_the_claimed_row_when_the_held_name_is_taken(): void {
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		Aura_Worker_Door_Holds::claim( $ref );
		// Something already put a row back under this ref (or the insert
		// simply fails): the claimed row must stand, because it is the only
		// record of the attempt.
		$GLOBALS['_sa_insert_unique_fail'] = true;

		$this->assertFalse( Aura_Worker_Door_Holds::unclaim( $ref ) );
		$this->assertNotNull( Aura_Worker_Door_Holds::get_claimed( $ref ) );
		$this->assertNull( Aura_Worker_Door_Holds::get_held( $ref ) );
	}

	public function test_unclaim_of_a_ref_with_no_claimed_row_is_false(): void {
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		$this->assertFalse( Aura_Worker_Door_Holds::unclaim( $ref ) );
		$this->assertFalse( Aura_Worker_Door_Holds::unclaim( 'door_nope' ) );
		$this->assertNotNull( Aura_Worker_Door_Holds::get_held( $ref ), 'the held row is untouched' );
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
		$this->reseedHeldTwin( $b );
		// …and the claim is OLD, which is the case the sweep owns: a claim
		// still inside CLAIM_STALE_MS is a replay mid-move (see below).
		$this->backdateClaim( $b, self::CLAIM_STALE_MS_S + 60 );
		Aura_Worker_Door_Holds::sweep( time(), self::CLAIM_STALE_MS );
		$this->assertArrayNotHasKey( 'aura_worker_door_held_' . $a, $GLOBALS['_options'] );
		$this->assertArrayNotHasKey( 'aura_worker_door_held_' . $b, $GLOBALS['_options'] );
		$this->assertArrayHasKey( 'aura_worker_door_claimed_' . $b, $GLOBALS['_options'] );
	}

	public function test_sweep_leaves_a_held_row_whose_claimed_twin_is_still_in_flight(): void {
		// claim() inserts the claimed twin and THEN deletes the held row. A
		// sweep landing in that window used to delete the held row itself;
		// claim()'s own delete then removed 0 rows, read that as "a reject won"
		// and backed out by deleting the claimed twin — both rows gone, the
		// operator's approval lost for ever, `not_held` on every later replay.
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		Aura_Worker_Door_Holds::claim( $ref );
		$this->reseedHeldTwin( $ref ); // the window: both rows exist

		$gone = Aura_Worker_Door_Holds::sweep( time(), self::CLAIM_STALE_MS );

		$this->assertSame( 0, $gone );
		$this->assertArrayHasKey( 'aura_worker_door_held_' . $ref, $GLOBALS['_rows'], 'a fresh claim is a replay mid-move; its own delete is still coming' );
		$this->assertArrayHasKey( 'aura_worker_door_claimed_' . $ref, $GLOBALS['_rows'] );
	}

	/**
	 * Ruling P41: a sweep that meets an unclaim mid-move FINISHES it.
	 *
	 * unclaim() inserts the held row and only then deletes the claimed twin.
	 * A replay still going past CLAIM_STALE_MS makes that twin look stale, so
	 * a concurrent `/status` sweep saw the transient pair, applied claim()'s
	 * rule and deleted the hold that had just been restored. unclaim()'s own
	 * delete then succeeded, give_back() answered `retry_later` — and the ref
	 * was held by nothing at all. The operator's approval was gone.
	 */
	public function test_a_sweep_racing_an_unclaim_finishes_the_move_instead_of_deleting_the_hold(): void {
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		Aura_Worker_Door_Holds::claim( $ref );
		// The replay is still running well past the stale bound.
		$this->backdateClaim( $ref, self::CLAIM_STALE_MS_S + 60 );
		$swept = null;
		// The window: the sweep lands after unclaim()'s held INSERT and before
		// its claimed DELETE.
		$GLOBALS['_sa_after_insert_unique'][ Aura_Worker_Door_Holds::HELD . $ref ] = static function () use ( &$swept ) {
			$swept = Aura_Worker_Door_Holds::sweep( time(), self::CLAIM_STALE_MS );
		};

		$restored = Aura_Worker_Door_Holds::unclaim( $ref );

		$this->assertSame( 0, $swept, 'nothing was swept — a move was finished' );
		$this->assertFalse( $restored, 'unclaim reports what it can see: the sweep had already deleted its twin' );
		$held = Aura_Worker_Door_Holds::get_held( $ref );
		$this->assertIsArray( $held, 'and the hold is BACK, which is what a retry depends on' );
		$this->assertArrayHasKey( 'restored_at', $held );
		$this->assertNull( Aura_Worker_Door_Holds::get_claimed( $ref ), 'the claimed row is gone, deleted by whichever side got there first' );
		// The approval was not spent: the ref can be claimed again.
		$this->assertIsArray( Aura_Worker_Door_Holds::claim( $ref ) );
	}

	/**
	 * The other half of that comparison: a held row restored BEFORE the claim
	 * beside it is claim()'s move, not an unclaim's, so today's rule stands.
	 */
	public function test_a_stale_claim_taken_after_a_restore_still_sweeps_the_held_row(): void {
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		Aura_Worker_Door_Holds::claim( $ref );
		$this->assertTrue( Aura_Worker_Door_Holds::unclaim( $ref ) ); // stamps restored_at
		Aura_Worker_Door_Holds::claim( $ref );                        // …and it is claimed again
		$this->reseedHeldTwin( $ref );                                // claim()'s own window
		$this->patchRow( 'aura_worker_door_held_' . $ref, array( 'restored_at' => gmdate( 'c', time() - 3600 ) ) );
		$this->backdateClaim( $ref, self::CLAIM_STALE_MS_S + 60 );

		$this->assertSame( 1, Aura_Worker_Door_Holds::sweep( time(), self::CLAIM_STALE_MS ), 'the restore is older than the claim: this is claim()\'s move' );
		$this->assertArrayNotHasKey( 'aura_worker_door_held_' . $ref, $GLOBALS['_rows'] );
		$this->assertArrayHasKey( 'aura_worker_door_claimed_' . $ref, $GLOBALS['_rows'] );
	}

	public function test_sweep_still_removes_a_held_twin_whose_claim_is_unstamped(): void {
		// A stamp that cannot be read is not evidence of freshness — the same
		// rule the creation mutex is cleared by.
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		Aura_Worker_Door_Holds::claim( $ref );
		$this->reseedHeldTwin( $ref );
		$this->patchRow( 'aura_worker_door_claimed_' . $ref, array( 'claimed_at' => '' ) );

		$this->assertSame( 1, Aura_Worker_Door_Holds::sweep( time(), self::CLAIM_STALE_MS ) );
		$this->assertArrayNotHasKey( 'aura_worker_door_held_' . $ref, $GLOBALS['_rows'] );
	}

	/* ------------------------------------------------------------------ */
	/* An expired hold is not held (Ruling P18)                            */
	/* ------------------------------------------------------------------ */

	/**
	 * listing() hides an expired hold and sweep() deletes it — but both run
	 * on a `/status` poll, and an unpolled site can sit past a hold's seven
	 * days with the row still there. A ref an approver kept must not still
	 * execute (Ruling P18).
	 */
	public function test_get_held_answers_null_for_an_expired_row_and_removes_it(): void {
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		$this->patchRow( 'aura_worker_door_held_' . $ref, array( 'expires_at' => gmdate( 'c', time() - 1 ) ) );

		$this->assertNull( Aura_Worker_Door_Holds::get_held( $ref ) );
		$this->assertArrayNotHasKey( 'aura_worker_door_held_' . $ref, $GLOBALS['_rows'], 'and it is gone, exactly as the sweep would have left it' );
	}

	public function test_get_held_leaves_an_expired_row_whose_claimed_twin_is_in_flight(): void {
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		Aura_Worker_Door_Holds::claim( $ref );
		$this->reseedHeldTwin( $ref );
		$this->patchRow( 'aura_worker_door_held_' . $ref, array( 'expires_at' => gmdate( 'c', time() - 1 ) ) );

		$this->assertNull( Aura_Worker_Door_Holds::get_held( $ref ) );
		$this->assertArrayHasKey( 'aura_worker_door_held_' . $ref, $GLOBALS['_rows'], 'the claim owns this ref; the sweep decides that row, not a reader' );
	}

	public function test_claim_refuses_an_expired_row_and_creates_no_twin(): void {
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		$this->patchRow( 'aura_worker_door_held_' . $ref, array( 'expires_at' => gmdate( 'c', time() - 1 ) ) );

		$out = Aura_Worker_Door_Holds::claim( $ref );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'not_held', $out->get_error_code() );
		$this->assertArrayNotHasKey( 'aura_worker_door_claimed_' . $ref, $GLOBALS['_rows'], 'nothing was claimed' );
	}

	public function test_a_hold_that_expires_a_second_from_now_is_still_held(): void {
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		$this->patchRow( 'aura_worker_door_held_' . $ref, array( 'expires_at' => gmdate( 'c', time() + 1 ) ) );

		$this->assertNotNull( Aura_Worker_Door_Holds::get_held( $ref ) );
		$this->assertIsArray( Aura_Worker_Door_Holds::claim( $ref ) );
	}

	/* ------------------------------------------------------------------ */
	/* An expired hold does not hold a queue slot (Ruling P21)             */
	/* ------------------------------------------------------------------ */

	/** Hold $n calls and expire every one of them, without sweeping. */
	private function expiredHolds( int $n ): array {
		$refs = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$refs[] = $ref = Aura_Worker_Door_Holds::hold( $this->call() );
			$this->patchRow( 'aura_worker_door_held_' . $ref, array( 'expires_at' => gmdate( 'c', time() - 10 ) ) );
		}
		return $refs;
	}

	/**
	 * The cap counted rows the queue no longer honours: listing() hides an
	 * expired hold and get_held() refuses it, but count() still charged a
	 * slot for it. On a site whose `/status` is not being polled, fifty
	 * expired approvals closed the door to every new Elementor write.
	 */
	public function test_expired_holds_do_not_fill_the_queue_and_are_purged_under_the_lock(): void {
		$refs = $this->expiredHolds( Aura_Worker_Door_Holds::CAP );

		$ref = Aura_Worker_Door_Holds::hold( $this->call() );

		$this->assertIsString( $ref, 'the queue was full of nothing' );
		foreach ( $refs as $dead ) {
			$this->assertArrayNotHasKey( 'aura_worker_door_held_' . $dead, $GLOBALS['_rows'], 'the expired rows are gone' );
		}
		$this->assertSame( 1, Aura_Worker_Door_Holds::count() );
	}

	public function test_one_expired_row_beside_a_full_house_of_live_ones_admits_one_more(): void {
		for ( $i = 0; $i < Aura_Worker_Door_Holds::CAP - 1; $i++ ) {
			Aura_Worker_Door_Holds::hold( $this->call() );
		}
		$this->expiredHolds( 1 );

		$this->assertIsString( Aura_Worker_Door_Holds::hold( $this->call() ) );
		$this->assertSame( Aura_Worker_Door_Holds::CAP, Aura_Worker_Door_Holds::count() );
	}

	public function test_a_full_house_of_LIVE_holds_still_refuses(): void {
		for ( $i = 0; $i < Aura_Worker_Door_Holds::CAP; $i++ ) {
			Aura_Worker_Door_Holds::hold( $this->call() );
		}

		$out = Aura_Worker_Door_Holds::hold( $this->call() );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aura_hold_queue_full', $out->get_error_code() );
	}

	/**
	 * A claim in flight still holds its slot, expired or not: the row is the
	 * replay's, and the sweep — not the cap check — decides when it goes.
	 */
	public function test_an_expired_held_row_with_a_claimed_twin_keeps_its_slot(): void {
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		Aura_Worker_Door_Holds::claim( $ref );
		$this->reseedHeldTwin( $ref );
		$this->patchRow( 'aura_worker_door_held_' . $ref, array( 'expires_at' => gmdate( 'c', time() - 10 ) ) );

		$this->assertSame( 1, Aura_Worker_Door_Holds::count(), 'the claimed ref still counts' );
		Aura_Worker_Door_Holds::hold( $this->call() );
		$this->assertArrayHasKey( 'aura_worker_door_held_' . $ref, $GLOBALS['_rows'], 'and its held twin was not purged out from under the replay' );
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	/** The reconciler's stale-claim bound, as the governor declares it. */
	private const CLAIM_STALE_MS   = 600000;
	private const CLAIM_STALE_MS_S = 600;

	/** Put the held row back beside its claimed twin — claim()'s own window. */
	private function reseedHeldTwin( string $ref ): void {
		$row = $GLOBALS['_options'][ 'aura_worker_door_claimed_' . $ref ];
		$GLOBALS['_options'][ 'aura_worker_door_held_' . $ref ] = $row;
		$GLOBALS['_rows'][ 'aura_worker_door_held_' . $ref ]    = maybe_serialize( $row );
		unset( $GLOBALS['_notoptions'][ 'aura_worker_door_held_' . $ref ] );
	}

	private function backdateClaim( string $ref, int $seconds_ago ): void {
		$this->patchRow( 'aura_worker_door_claimed_' . $ref, array( 'claimed_at' => gmdate( 'c', time() - $seconds_ago ) ) );
	}

	/** Merge fields into a row, in the "database" ($_rows) and the cache alike. */
	private function patchRow( string $name, array $fields ): void {
		$row                          = array_merge( (array) $GLOBALS['_options'][ $name ], $fields );
		$GLOBALS['_options'][ $name ] = $row;
		$GLOBALS['_rows'][ $name ]    = maybe_serialize( $row );
	}
}
