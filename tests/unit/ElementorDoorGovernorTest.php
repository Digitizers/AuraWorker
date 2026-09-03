<?php
/**
 * The Elementor door governor: one seam, hold by default, allow by rule,
 * block refuses, coverage verified on the FINAL callback, transport closed
 * when it cannot be (spec §2, §3.1–§3.4, §3.9).
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class ElementorDoorGovernorTest extends TestCase {

	/** @var array<string,int> how many times each inner callback ran */
	private $ran = array();

	/** @var array<string,Closure> the callback each slug was REGISTERED with */
	private $inner = array();

	protected function setUp(): void {
		sa_reset_state();
		foreach ( array( 'class-aura-worker-door-log', 'class-aura-worker-door-holds', 'class-elementor-door-governor' ) as $f ) {
			require_once dirname( __DIR__, 2 ) . '/digitizer-site-worker/includes/' . $f . '.php';
		}
		Aura_Worker_Elementor_Door::reset_for_tests();
		Aura_Worker_Elementor_Door::init();
		$GLOBALS['_current_user_id'] = 3;
		$GLOBALS['_user_logins'][3]  = 'bot';
		$GLOBALS['_posts'][7]        = (object) array( 'ID' => 7, 'post_type' => 'page', 'post_status' => 'draft', 'post_content' => '' );
		$this->ran                   = array();
		$this->inner                 = array();
		// The snapshot seam is stubbed: this file is about judgement and the
		// log, not about what an envelope holds (Task 6 tests that).
		Aura_Worker_Elementor_Door::set_snapshotter_for_tests(
			static function () {
				return array( 'success' => true, 'snapshot' => array( 'id' => 'snap_test' ) );
			}
		);
	}

	/** Register every 4.3 ability through the filter, with a counting inner callback. */
	private function registerAll(): void {
		$all = array_merge( Aura_Worker_Elementor_Door::READ_ALLOWLIST, array_keys( Aura_Worker_Elementor_Door::WRITE_TABLE ) );
		foreach ( $all as $slug ) {
			$this->register( $slug );
		}
		do_action( 'wp_abilities_api_init' );
	}

	private function register( string $slug, array $meta = array() ): void {
		$ran                   = &$this->ran;
		$inner                 = static function ( $input ) use ( &$ran, $slug ) {
			$ran[ $slug ] = ( $ran[ $slug ] ?? 0 ) + 1;
			return array( 'ok' => true, 'input' => $input );
		};
		$this->inner[ $slug ] = $inner;
		sa_register_ability(
			$slug,
			array(
				'execute_callback'    => $inner,
				'permission_callback' => '__return_true',
				'meta'                => $meta,
			)
		);
	}

	/**
	 * The stored ruleset record, written straight to the option — exactly as
	 * RulesEnforcementTest::install() does. Its verification is
	 * RulesetStoreTest's concern, not this file's.
	 */
	private function installRuleset( array $rules ): void {
		$GLOBALS['_options'][ Aura_Worker_Rules::OPTION ] = array(
			'envelope'    => 'x.y',
			'seq'         => 5,
			'issued_at'   => '2026-09-02T00:00:00Z',
			'received_at' => time(),
			'rules'       => $rules,
		);
	}

	public function test_reads_are_not_wrapped_and_writes_are(): void {
		$this->registerAll();
		$read  = new ReflectionProperty( WP_Ability::class, 'execute_callback' );
		if ( PHP_VERSION_ID < 80100 ) {
			$read->setAccessible( true ); // a no-op since 8.1, deprecated in 8.5
		}
		// IDENTITY, not class: the brief's `assertNotInstanceOf( Closure )`
		// cannot distinguish anything here, because the callback this test
		// registers is itself a Closure — an unwrapped read would fail it and
		// so would a wrapped one. What "not wrapped" means is that the
		// registry still holds the callback Elementor registered.
		$this->assertSame( $this->inner['elementor/list-posts'], $read->getValue( wp_get_ability( 'elementor/list-posts' ) ), 'a read passes the allowlist untouched' );
		$this->assertNotSame( $this->inner['elementor/publish-document'], $read->getValue( wp_get_ability( 'elementor/publish-document' ) ) );
		$this->assertInstanceOf( Closure::class, $read->getValue( wp_get_ability( 'elementor/publish-document' ) ) );
		$this->assertNotSame( $this->inner['elementor/create-page'], $read->getValue( wp_get_ability( 'elementor/create-page' ) ), 'destructive => false is not evidence: the five unguarded writes are wrapped' );
		$this->assertInstanceOf( Closure::class, $read->getValue( wp_get_ability( 'elementor/create-page' ) ) );
	}

	public function test_an_unnamed_slug_is_wrapped_and_refused(): void {
		$this->registerAll();
		$this->register( 'elementor/future-thing' );
		$out = wp_get_ability( 'elementor/future-thing' )->execute( array() );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aura_ability_unmapped', $out->get_error_code() );
		$this->assertArrayNotHasKey( 'elementor/future-thing', $this->ran );
		$log = Aura_Worker_Door_Log::log_after( 0 );
		$this->assertSame( 'refused', $log[0]['result'] );
		$this->assertSame( 'unknown_ability', $log[0]['reason'] );
	}

	public function test_no_rule_holds_the_call_and_refuses_the_client_with_a_ref(): void {
		$this->registerAll();
		$this->installRuleset( array() );
		$out = wp_get_ability( 'elementor/publish-document' )->execute( array( 'post_id' => 7 ) );
		$this->assertSame( 'aura_held_for_approval', $out->get_error_code() );
		$this->assertSame( 409, $out->get_error_data()['status'] );
		$ref = $out->get_error_data()['ref'];
		$this->assertStringContainsString( "ref $ref", $out->get_error_message() );
		$this->assertStringContainsString( 'Do not retry', $out->get_error_message() );
		$this->assertArrayNotHasKey( 'elementor/publish-document', $this->ran );
		$this->assertNotNull( Aura_Worker_Door_Holds::get_held( $ref ) );
		$log = Aura_Worker_Door_Log::log_after( 0 );
		$this->assertSame( 'held', $log[0]['result'] );
		$this->assertSame( $ref, $log[0]['ref'] );
	}

	public function test_warn_holds_with_the_rule_captured_at_hold_time(): void {
		$this->registerAll();
		$this->installRuleset( array( array( 'key' => 'rule/w', 'effect' => 'warn', 'target' => array( 'type' => 'page', 'id' => '7' ), 'reason' => 'careful' ) ) );
		$out  = wp_get_ability( 'elementor/publish-document' )->execute( array( 'post_id' => 7 ) );
		$held = Aura_Worker_Door_Holds::get_held( $out->get_error_data()['ref'] );
		$this->assertSame( 'warn', $held['verdict'] );
		$this->assertSame( 'rule/w', $held['rule']['key'] );
		$this->assertSame( 'careful', $held['rule']['reason'] );
		$this->assertNotSame( '', $held['rule']['ruleHash'] );
	}

	public function test_allow_snapshots_records_pending_then_runs_then_settles_ok(): void {
		$this->registerAll();
		$this->installRuleset( array( array( 'key' => 'rule/a', 'effect' => 'allow', 'target' => array( 'type' => 'page', 'id' => '7' ), 'reason' => 'ok' ) ) );
		$order = array();
		Aura_Worker_Elementor_Door::set_snapshotter_for_tests(
			static function () use ( &$order ) {
				$order[] = 'snapshot';
				return array( 'success' => true, 'snapshot' => array( 'id' => 'snap_1' ) );
			}
		);
		add_action( 'sa_test_inner_ran', static function () use ( &$order ) { $order[] = 'inner'; } );
		$out = wp_get_ability( 'elementor/publish-document' )->execute( array( 'post_id' => 7 ) );
		$this->assertSame( array( 'ok' => true, 'input' => array( 'post_id' => 7 ) ), $out, 'the inner result is returned unchanged' );
		$this->assertSame( array( 'snapshot', 'inner' ), $order, 'the target is captured before the write' );
		$log = Aura_Worker_Door_Log::log_after( 0 );
		$this->assertCount( 1, $log );
		$this->assertSame( 'ok', $log[0]['result'] );
		$this->assertSame( 'allow', $log[0]['verdict'] );
		$this->assertSame( 'rule/a', $log[0]['rule_key'] );
		$this->assertSame( 'snap_1', $log[0]['snapshot_id'] );
		$this->assertTrue( $log[0]['admitted'] );
	}

	public function test_a_snapshot_failure_refuses_before_the_inner_callback(): void {
		$this->registerAll();
		$this->installRuleset( array( array( 'key' => 'rule/a', 'effect' => 'allow', 'target' => array( 'type' => 'page', 'id' => '7' ), 'reason' => 'ok' ) ) );
		Aura_Worker_Elementor_Door::set_snapshotter_for_tests( static function () { return array( 'success' => false, 'error' => 'disk full' ); } );
		$out = wp_get_ability( 'elementor/publish-document' )->execute( array( 'post_id' => 7 ) );
		$this->assertSame( 'aura_snapshot_failed', $out->get_error_code() );
		$this->assertArrayNotHasKey( 'elementor/publish-document', $this->ran );
		$this->assertSame( 'refused', Aura_Worker_Door_Log::log_after( 0 )[0]['result'] );
	}

	public function test_block_refuses_and_records_the_block(): void {
		$this->registerAll();
		$this->installRuleset( array( array( 'key' => 'rule/b', 'effect' => 'block', 'target' => array( 'type' => 'design_system' ), 'reason' => 'frozen' ) ) );
		$out = wp_get_ability( 'elementor/manage-classes' )->execute( array( 'items' => array() ) );
		$this->assertSame( 'aura_rule_blocked', $out->get_error_code() );
		$this->assertArrayNotHasKey( 'elementor/manage-classes', $this->ran );
		// record_block() ran: the audit's own accessor, not a bucket guess.
		$this->assertSame( 1, Aura_Worker_Rules::count_24h( Aura_Worker_Rules::BLOCKED_COUNTER ) );
	}

	public function test_a_page_write_with_no_usable_id_is_refused_unattributed(): void {
		$this->registerAll();
		$this->installRuleset( array( array( 'key' => 'rule/a', 'effect' => 'allow', 'target' => array( 'type' => 'design_system' ), 'reason' => 'ok' ) ) );
		$out = wp_get_ability( 'elementor/manage-elements' )->execute( array( 'post_id' => 'seven' ) );
		$this->assertSame( 'aura_target_unattributed', $out->get_error_code() );
		$this->assertArrayNotHasKey( 'elementor/manage-elements', $this->ran );
	}

	public function test_no_user_is_refused_before_judgement(): void {
		$this->registerAll();
		$GLOBALS['_current_user_id'] = 0;
		$out                         = wp_get_ability( 'elementor/publish-document' )->execute( array( 'post_id' => 7 ) );
		$this->assertSame( 'aura_actor_unidentified', $out->get_error_code() );
	}

	public function test_an_unreadable_ruleset_holds_with_rules_unavailable(): void {
		$this->registerAll();
		$GLOBALS['_options'][ Aura_Worker_Rules::OPTION ] = 'garbage';
		$out                                              = wp_get_ability( 'elementor/publish-document' )->execute( array( 'post_id' => 7 ) );
		$this->assertSame( 'aura_held_for_approval', $out->get_error_code() );
		$held = Aura_Worker_Door_Holds::get_held( $out->get_error_data()['ref'] );
		$this->assertSame( 'rules_unavailable', $held['verdict'] );
	}

	public function test_a_governor_throw_refuses_never_opens(): void {
		$this->registerAll();
		$this->installRuleset( array( array( 'key' => 'rule/a', 'effect' => 'allow', 'target' => array( 'type' => 'page', 'id' => '7' ), 'reason' => 'ok' ) ) );
		Aura_Worker_Elementor_Door::set_snapshotter_for_tests( static function () { throw new RuntimeException( 'boom' ); } );
		$out = wp_get_ability( 'elementor/publish-document' )->execute( array( 'post_id' => 7 ) );
		$this->assertSame( 'aura_log_failed', $out->get_error_code() ); // a SETUP throw (Ruling P33)
		$this->assertArrayNotHasKey( 'elementor/publish-document', $this->ran );
	}

	public function test_a_closed_log_refuses_writes_without_a_row_and_counts(): void {
		$this->registerAll();
		$this->installRuleset( array( array( 'key' => 'rule/a', 'effect' => 'allow', 'target' => array( 'type' => 'page', 'id' => '7' ), 'reason' => 'ok' ) ) );
		Aura_Worker_Door_Log::close();
		$out = wp_get_ability( 'elementor/publish-document' )->execute( array( 'post_id' => 7 ) );
		$this->assertSame( 'aura_log_full', $out->get_error_code() );
		$this->assertSame( 0, Aura_Worker_Door_Log::count_unacked() );
		$this->assertSame( 1, Aura_Worker_Door_Log::full_report()['refused'] );
	}

	/** MAX_UNACKED terminal rows, so the very next entry overflows the bound. */
	private function fillTheLog(): void {
		for ( $i = 1; $i <= Aura_Worker_Door_Log::MAX_UNACKED; $i++ ) {
			$GLOBALS['_options'][ 'aura_worker_door_log_' . $i ] = array( 'seq' => $i, 'result' => 'ok', 'admitted' => true );
			$GLOBALS['_rows'][ 'aura_worker_door_log_' . $i ]    = maybe_serialize( $GLOBALS['_options'][ 'aura_worker_door_log_' . $i ] );
		}
	}

	public function test_at_the_bound_the_request_backs_out_settling_discarded_and_closes(): void {
		$this->registerAll();
		$this->installRuleset( array( array( 'key' => 'rule/a', 'effect' => 'allow', 'target' => array( 'type' => 'page', 'id' => '7' ), 'reason' => 'ok' ) ) );
		$this->fillTheLog();
		$out = wp_get_ability( 'elementor/publish-document' )->execute( array( 'post_id' => 7 ) );
		$this->assertSame( 'aura_log_full', $out->get_error_code() );
		$row = Aura_Worker_Door_Log::get( Aura_Worker_Door_Log::MAX_UNACKED + 1 );
		$this->assertSame( 'discarded', $row['result'], 'the row is settled, never deleted — no hole in seq' );
		$this->assertTrue( $row['admitted'], 'and admitted, so log_after serves past it' );
		$this->assertTrue( Aura_Worker_Door_Log::is_closed() );
		$this->assertArrayNotHasKey( 'elementor/publish-document', $this->ran );
	}

	/**
	 * Ruling P31: the closure marker is installed BEFORE the overflow row is
	 * settled — at every overflow site.
	 *
	 * The discard is what makes that row terminal, and a terminal row is
	 * served to the next `/status` poll. Settling it first opened a window in
	 * which a concurrent ack could consume the row while the log still looked
	 * OPEN — see the test below for what that costs.
	 */
	public function test_the_closure_marker_is_installed_before_the_overflow_row_is_settled(): void {
		$this->registerAll();
		$this->installRuleset( array( array( 'key' => 'rule/a', 'effect' => 'allow', 'target' => array( 'type' => 'page', 'id' => '7' ), 'reason' => 'ok' ) ) );
		$this->fillTheLog();
		$seq                    = Aura_Worker_Door_Log::MAX_UNACKED + 1;
		$GLOBALS['_db_queries'] = array();

		$out = wp_get_ability( 'elementor/publish-document' )->execute( array( 'post_id' => 7 ) );

		$this->assertSame( 'aura_log_full', $out->get_error_code() );
		$marker  = -1;
		$discard = -1;
		foreach ( $GLOBALS['_db_queries'] as $i => $query ) {
			if ( -1 === $marker && false !== strpos( (string) $query, Aura_Worker_Door_Log::FULL_MARKER ) ) {
				$marker = $i;
			}
			if ( -1 === $discard && false !== strpos( (string) $query, "'aura_worker_door_log_{$seq}'" ) && false !== strpos( (string) $query, 'discarded' ) ) {
				$discard = $i;
			}
		}
		$this->assertGreaterThan( -1, $marker, 'the closure marker was installed' );
		$this->assertGreaterThan( -1, $discard, 'and the overflow row was settled discarded' );
		$this->assertLessThan( $discard, $marker, 'in that order: an ack that consumes the row must already see the marker' );
	}

	/**
	 * The consequence, driven for real: a poll consumes the overflow row and
	 * acks it the instant it turns terminal.
	 *
	 * Settled first, the ack observed an OPEN log, so its reopen check never
	 * ran — and the marker the writer installed a moment later shut the door
	 * with the acknowledged row already deleted. Nothing could create another
	 * entry, so nothing could ever trigger another ack: closed for ever, on a
	 * log holding zero unacked rows.
	 */
	public function test_an_ack_racing_the_overflow_row_still_reopens_the_log(): void {
		$this->registerAll();
		$this->installRuleset( array( array( 'key' => 'rule/a', 'effect' => 'allow', 'target' => array( 'type' => 'page', 'id' => '7' ), 'reason' => 'ok' ) ) );
		$this->fillTheLog();
		$epoch  = Aura_Worker_Door_Log::epoch();
		$seq    = Aura_Worker_Door_Log::MAX_UNACKED + 1;
		$racing = false;
		// Aura's poll + ack, landing the moment the overflow row becomes
		// terminal — the CAS that settles it has just been applied.
		$GLOBALS['_sa_after_swap'] = static function ( $name ) use ( $epoch, $seq, &$racing ) {
			if ( 'aura_worker_door_log_' . $seq !== (string) $name ) {
				return;
			}
			$racing = true;
			Aura_Worker_Door_Log::ack( $epoch, $seq );
		};

		$out = wp_get_ability( 'elementor/publish-document' )->execute( array( 'post_id' => 7 ) );

		$this->assertSame( 'aura_log_full', $out->get_error_code() );
		$this->assertTrue( $racing, 'the ack really did land on the overflow row' );
		$this->assertNull( Aura_Worker_Door_Log::get( $seq ), 'which deleted it' );
		$this->assertSame( 0, Aura_Worker_Door_Log::count_unacked(), 'nothing is unacked any more' );
		$this->assertFalse( Aura_Worker_Door_Log::is_closed(), 'so the ack saw the marker and its reopen check ran' );
	}

	/**
	 * The same order at the SECOND overflow site: record_terminal_only(),
	 * which is where a HELD call's entry is written. The rule is about the
	 * reservation, not the line.
	 */
	public function test_the_closure_marker_precedes_the_overflow_row_for_a_held_entry_too(): void {
		$this->registerAll();
		$this->installRuleset( array() ); // no rule: the call is HELD, not run
		$this->fillTheLog();
		$seq                    = Aura_Worker_Door_Log::MAX_UNACKED + 1;
		$GLOBALS['_db_queries'] = array();

		$out = wp_get_ability( 'elementor/publish-document' )->execute( array( 'post_id' => 7 ) );

		$this->assertSame( 'aura_held_for_approval', $out->get_error_code(), 'the hold stands; only its log entry overflowed' );
		$this->assertTrue( Aura_Worker_Door_Log::is_closed() );
		$marker  = -1;
		$discard = -1;
		foreach ( $GLOBALS['_db_queries'] as $i => $query ) {
			if ( -1 === $marker && false !== strpos( (string) $query, Aura_Worker_Door_Log::FULL_MARKER ) ) {
				$marker = $i;
			}
			if ( -1 === $discard && false !== strpos( (string) $query, "'aura_worker_door_log_{$seq}'" ) && false !== strpos( (string) $query, 'discarded' ) ) {
				$discard = $i;
			}
		}
		$this->assertGreaterThan( -1, $marker );
		$this->assertGreaterThan( -1, $discard );
		$this->assertLessThan( $discard, $marker );
	}

	/**
	 * Ruling P53: a backlog that cannot be counted admits nothing.
	 *
	 * `get_var()` answers null for a broken statement exactly as for a real
	 * zero, and `(int) null` is 0 — so a COUNT that failed while ordinary
	 * option writes still worked reported an EMPTY log, and every admission
	 * check waved writes past MAX_UNACKED for as long as the failure lasted.
	 */
	public function test_an_unreadable_backlog_refuses_the_write_without_closing_the_door(): void {
		$this->registerAll();
		$this->installRuleset( array( array( 'key' => 'rule/a', 'effect' => 'allow', 'target' => array( 'type' => 'page', 'id' => '7' ), 'reason' => 'ok' ) ) );
		$this->fillTheLog(); // already at the bound, though the count cannot say so
		$GLOBALS['_sa_door_unacked_error'] = true;

		$out = wp_get_ability( 'elementor/publish-document' )->execute( array( 'post_id' => 7 ) );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aura_log_failed', $out->get_error_code(), 'retryable: nothing ran' );
		$this->assertSame( 503, $out->get_error_data()['status'] );
		$this->assertArrayNotHasKey( 'elementor/publish-document', $this->ran );
		$row = Aura_Worker_Door_Log::get( Aura_Worker_Door_Log::MAX_UNACKED + 1 );
		$this->assertSame( 'discarded', $row['result'], 'the reservation is handed back (P29 shape)' );
		$this->assertTrue( $row['admitted'] );
		$GLOBALS['_sa_door_unacked_error'] = false;
		$this->assertFalse( Aura_Worker_Door_Log::is_closed(), 'a database blip is not an overflow' );
		$this->assertNull( Aura_Worker_Door_Log::full_report() );
	}

	/** The same rule for a HELD entry's admission (record_terminal_only). */
	public function test_an_unreadable_backlog_refuses_a_held_entry_without_closing_the_door(): void {
		$this->registerAll();
		$this->installRuleset( array() ); // no rule: the call is held
		$GLOBALS['_sa_door_unacked_error'] = true;

		$out = wp_get_ability( 'elementor/publish-document' )->execute( array( 'post_id' => 7 ) );

		$this->assertSame( 'aura_held_for_approval', $out->get_error_code(), 'the hold itself still stands' );
		$ref = (string) $out->get_error_data()['ref'];
		$this->assertFalse( Aura_Worker_Door_Holds::get_held( $ref )['log_entry'], 'and says its entry was not written' );
		$GLOBALS['_sa_door_unacked_error'] = false;
		$this->assertSame( 'discarded', Aura_Worker_Door_Log::get( 1 )['result'] );
		$this->assertFalse( Aura_Worker_Door_Log::is_closed() );
		$this->assertSame( 1, (int) get_option( 'aura_worker_door_c_log_ungoverned_h' . (int) floor( time() / HOUR_IN_SECONDS ), 0 ) );
	}

	/** And the fragment says "unknown" rather than a false zero. */
	public function test_the_fragment_reports_an_unreadable_backlog_as_null(): void {
		$GLOBALS['_sa_force_door']         = true;
		Aura_Worker_Elementor_Door::reset_for_tests();
		$GLOBALS['_sa_door_unacked_error'] = true;

		$frag = Aura_Worker_Elementor_Door::status_fragment( 0, '' );

		$this->assertIsArray( $frag );
		$this->assertNull( $frag['log_unacked'] );
		$this->assertNull( Aura_Worker_Elementor_Door::governor_block()['log_unacked'], 'the audit block too' );
		$GLOBALS['_sa_door_unacked_error'] = false;
	}

	/** Ruling P56: the seq lease is held across the write and released after. */
	public function test_the_seq_lease_is_held_across_a_governed_write_and_released_after(): void {
		$this->registerAll();
		$this->installRuleset( array( array( 'key' => 'rule/a', 'effect' => 'allow', 'target' => array( 'type' => 'page', 'id' => '7' ), 'reason' => 'ok' ) ) );
		$held = null;
		add_action(
			'sa_test_inner_ran',
			static function () use ( &$held ) {
				$held = ! empty( $GLOBALS['_sa_named_locks'][ Aura_Worker_Door_Holds::seq_lease_name( 1 ) ] );
			}
		);

		wp_get_ability( 'elementor/publish-document' )->execute( array( 'post_id' => 7 ) );

		$this->assertTrue( $held, 'held across the callback' );
		$this->assertArrayNotHasKey( Aura_Worker_Door_Holds::seq_lease_name( 1 ), $GLOBALS['_sa_named_locks'], 'and released after' );
	}

	/** …and released when the callback throws. */
	public function test_the_seq_lease_is_released_when_the_callback_throws(): void {
		$this->installRuleset( array( array( 'key' => 'rule/a', 'effect' => 'allow', 'target' => array( 'type' => 'page', 'id' => '7' ), 'reason' => 'ok' ) ) );
		sa_register_ability(
			'elementor/publish-document',
			array(
				'execute_callback'    => static function () {
					throw new RuntimeException( 'boom' );
				},
				'permission_callback' => '__return_true',
				'meta'                => array(),
			)
		);
		do_action( 'wp_abilities_api_init' );

		wp_get_ability( 'elementor/publish-document' )->execute( array( 'post_id' => 7 ) );

		$this->assertArrayNotHasKey( Aura_Worker_Door_Holds::seq_lease_name( 1 ), $GLOBALS['_sa_named_locks'] );
	}

	/**
	 * Ruling P60: the binding fence covers EVERY governed write.
	 *
	 * A direct Elementor MCP request authenticates with the departing
	 * binding's Application Password, and a changed-client connect or an
	 * unbind can rotate the generation between this row's admission and its
	 * callback. The replay-only fence never saw it, so the old request ran the
	 * mutation for a client that no longer governs the site.
	 */
	public function test_a_direct_write_refuses_when_the_binding_rotates_before_the_callback(): void {
		$this->registerAll();
		$this->installRuleset( array( array( 'key' => 'rule/a', 'effect' => 'allow', 'target' => array( 'type' => 'page', 'id' => '7' ), 'reason' => 'ok' ) ) );
		$inner_ran = 0;
		add_action(
			'sa_test_inner_ran',
			static function () use ( &$inner_ran ) {
				++$inner_ran;
			}
		);
		// The rebind lands the instant this call's row exists — after
		// open_pending() stamped it with the CURRENT generation, and before the
		// fence a few statements later. That is the window: admitted under one
		// binding, about to run under another.
		$GLOBALS['_sa_after_insert_unique']['aura_worker_door_log_1'] = static function () {
			Aura_Worker_Door_Log::rotate_binding( array( 'client' => 'c2', 'dashboard' => 'https://new.example' ) );
		};

		$out = wp_get_ability( 'elementor/publish-document' )->execute( array( 'post_id' => 7 ) );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aura_binding_changed', $out->get_error_code() );
		$this->assertSame( 409, $out->get_error_data()['status'] );
		$this->assertSame( 0, $inner_ran, 'the write path was never entered' );
		$this->assertArrayNotHasKey( 'elementor/publish-document', $this->ran );
		$row = Aura_Worker_Door_Log::get( 1 );
		$this->assertSame( 'refused', $row['result'] );
		$this->assertSame( 'binding_changed', $row['reason'] );
		$this->assertFalse( $row['may_have_run'] );
	}

	/** An unchanged generation runs exactly as before. */
	public function test_a_direct_write_under_an_unchanged_binding_still_runs(): void {
		$this->registerAll();
		$this->installRuleset( array( array( 'key' => 'rule/a', 'effect' => 'allow', 'target' => array( 'type' => 'page', 'id' => '7' ), 'reason' => 'ok' ) ) );
		$gen = Aura_Worker_Door_Log::binding();

		$out = wp_get_ability( 'elementor/publish-document' )->execute( array( 'post_id' => 7 ) );

		$this->assertSame( array( 'ok' => true, 'input' => array( 'post_id' => 7 ) ), $out );
		$this->assertSame( 1, $this->ran['elementor/publish-document'] );
		$this->assertSame( $gen, Aura_Worker_Door_Log::binding() );
		$this->assertSame( 'ok', Aura_Worker_Door_Log::get( 1 )['result'] );
	}

	public function test_coverage_failure_closes_both_transports_reads_included(): void {
		$this->registerAll();
		// A later filter replaced the wrapper AFTER wrap_args ran.
		$obj  = wp_get_ability( 'elementor/publish-document' );
		$prop = new ReflectionProperty( WP_Ability::class, 'execute_callback' );
		if ( PHP_VERSION_ID < 80100 ) {
			$prop->setAccessible( true ); // a no-op since 8.1, deprecated in 8.5
		}
		$prop->setValue( $obj, '__return_true' );
		do_action( 'wp_abilities_api_init' );
		$this->assertSame( 'unavailable', Aura_Worker_Elementor_Door::seam() );
		foreach ( array( '/elementor/mcp', '/wp-abilities/v1/abilities/elementor/list-posts/run' ) as $route ) {
			$req = new WP_REST_Request( 'POST', $route );
			$res = Aura_Worker_Elementor_Door::close_transport( null, array(), $req );
			$this->assertInstanceOf( WP_Error::class, $res, $route );
			$this->assertSame( 'aura_door_ungoverned', $res->get_error_code() );
			$this->assertSame( 503, $res->get_error_data()['status'] );
		}
		$req = new WP_REST_Request( 'POST', '/aura/mcp/tools/execute' );
		$this->assertNull( Aura_Worker_Elementor_Door::close_transport( null, array(), $req ), 'SiteAgent\'s own routes are untouched' );
	}

	public function test_a_build_without_the_stored_callback_property_is_a_coverage_failure(): void {
		$this->registerAll();
		Aura_Worker_Elementor_Door::set_callback_reader_for_tests( static function () { throw new ReflectionException( 'no such property' ); } );
		do_action( 'wp_abilities_api_init' );
		$this->assertSame( 'unavailable', Aura_Worker_Elementor_Door::seam() );
	}

	public function test_judgement_is_memoised_per_request(): void {
		$this->registerAll();
		$this->installRuleset( array() );
		$a = wp_get_ability( 'elementor/publish-document' )->execute( array( 'post_id' => 7 ) );
		$b = wp_get_ability( 'elementor/publish-document' )->execute( array( 'post_id' => 7 ) );
		$this->assertSame( $a->get_error_data()['ref'], $b->get_error_data()['ref'], 'the same call in one request is one hold' );
		$this->assertCount( 1, Aura_Worker_Door_Log::log_after( 0 ), 'and one log entry' );
	}

	/* --------------------------------------------------------------- */
	/* The rest of the fail-closed table (§3.9) and the actor            */
	/* --------------------------------------------------------------- */

	/**
	 * §3.2: the credential that authenticated the request names the actor,
	 * and that name is what an operator reads in Aura's queue. Driven
	 * through the REAL capture hook (`application_password_did_authenticate`)
	 * so an unregistered listener cannot leave this green.
	 */
	public function test_the_app_password_name_reaches_the_hold_and_the_log(): void {
		Aura_Worker_Security::init();
		$GLOBALS['_app_passwords'][3] = array( array( 'uuid' => 'uuid-door', 'name' => 'Elementor MCP (studio)', 'created' => time() ) );
		sa_authenticate_app_password( 3, 'uuid-door' );
		$this->registerAll();
		$this->installRuleset( array() );
		$out  = wp_get_ability( 'elementor/publish-document' )->execute( array( 'post_id' => 7 ) );
		$held = Aura_Worker_Door_Holds::get_held( $out->get_error_data()['ref'] );
		$this->assertSame( 'Elementor MCP (studio)', $held['actor']['app_password_name'] );
		$this->assertSame( 'uuid-door', $held['actor']['app_password_uuid'] );
		$this->assertSame( 3, $held['actor']['user_id'] );
		$log = Aura_Worker_Door_Log::log_after( 0 );
		$this->assertSame( 'Elementor MCP (studio)', $log[0]['actor']['app_password_name'] );
	}

	/**
	 * Ruling P25: a terminal-only entry is admitted BY its settle.
	 *
	 * The row used to be admitted first, so a failed settle left an admitted
	 * pending row — `log_after()` stops at it, and the reconciler later called
	 * a call that never ran `interrupted`. Un-admitted, the same failure
	 * leaves a row the reconciler discards, which is the honest state.
	 */
	public function test_a_held_entry_whose_settle_fails_leaves_an_unadmitted_row(): void {
		$this->registerAll();
		$this->installRuleset( array() );
		$GLOBALS['_sa_option_cas_fail']['aura_worker_door_log_1'] = static function ( $value ) {
			return false !== strpos( (string) $value, 'settled_at' );
		};

		$out = wp_get_ability( 'elementor/publish-document' )->execute( array( 'post_id' => 7 ) );

		$this->assertSame( 'aura_held_for_approval', $out->get_error_code(), 'the hold row is the durable fact; the log row is evidence' );
		$ref = (string) $out->get_error_data()['ref'];
		$this->assertNotNull( Aura_Worker_Door_Holds::get_held( $ref ) );
		$this->assertFalse( Aura_Worker_Door_Holds::get_held( $ref )['log_entry'], 'and the hold row says its entry was not written' );
		$row = Aura_Worker_Door_Log::get( 1 );
		$this->assertSame( 'pending', $row['result'] );
		$this->assertFalse( $row['admitted'], 'never admitted, so the reconciler discards it rather than interrupting it' );
		$this->assertSame( 1, (int) get_option( 'aura_worker_door_c_log_ungoverned_h' . (int) floor( time() / HOUR_IN_SECONDS ), 0 ) );
		$this->assertSame( array(), Aura_Worker_Door_Log::log_after( 0 ), 'an un-admitted row is not served' );
	}

	/** §3.9: a site that cannot store a hold refuses — nothing runs ungoverned. */
	public function test_a_hold_that_cannot_be_stored_refuses(): void {
		$this->registerAll();
		$this->installRuleset( array() );
		$GLOBALS['_sa_insert_unique_fail'] = true; // every insert_unique but the hold lock
		$out                               = wp_get_ability( 'elementor/publish-document' )->execute( array( 'post_id' => 7 ) );
		$this->assertSame( 'aura_hold_failed', $out->get_error_code() );
		$this->assertArrayNotHasKey( 'elementor/publish-document', $this->ran );
		$this->assertSame( array(), Aura_Worker_Door_Holds::listing() );
	}

	/**
	 * Codex round-10 P2: a transiently failed admission SETTLES its
	 * reservation before backing out.
	 *
	 * The row was left `pending` with `admitted: false` — and `log_after()`
	 * stops at exactly that — so every later terminal entry stayed hidden
	 * from Aura until the ten-minute reconciler eventually discarded it, for
	 * a callback that provably never ran. `discarded` is the honest result
	 * now, written the same moment the call is refused.
	 */
	public function test_a_failed_admission_discards_its_reservation_rather_than_blocking_the_log(): void {
		$this->registerAll();
		$this->installRuleset( array( array( 'key' => 'rule/a', 'effect' => 'allow', 'target' => array( 'type' => 'page', 'id' => '7' ), 'reason' => 'ok' ) ) );
		// ONLY the admission fails; the discard that follows it carries
		// `settled_at`, so it lands.
		$GLOBALS['_sa_option_cas_fail']['aura_worker_door_log_1'] = static function ( $value ) {
			return false === strpos( (string) $value, 'settled_at' );
		};

		$out = wp_get_ability( 'elementor/publish-document' )->execute( array( 'post_id' => 7 ) );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aura_log_failed', $out->get_error_code() );
		$this->assertSame( 503, $out->get_error_data()['status'] );
		$this->assertArrayNotHasKey( 'elementor/publish-document', $this->ran, 'the callback provably never ran' );
		$row = Aura_Worker_Door_Log::get( 1 );
		$this->assertSame( 'discarded', $row['result'] );
		$this->assertTrue( $row['admitted'], 'discard() goes through settle(), which admits in the same write' );

		// And the next call's terminal entry is served — it used to wait
		// behind the pending row for ten minutes.
		wp_get_ability( 'elementor/publish-document' )->execute( array( 'post_id' => 7 ) );

		$this->assertSame( 1, $this->ran['elementor/publish-document'] );
		$this->assertSame( array( 1, 2 ), array_column( Aura_Worker_Door_Log::log_after( 0 ), 'seq' ) );
		$this->assertSame( array( 'discarded', 'ok' ), array_column( Aura_Worker_Door_Log::log_after( 0 ), 'result' ) );
	}

	/** §3.9-a: a pending entry that cannot be written refuses the call. */
	public function test_a_pending_entry_that_cannot_be_written_refuses_before_the_write(): void {
		$this->registerAll();
		$this->installRuleset( array( array( 'key' => 'rule/a', 'effect' => 'allow', 'target' => array( 'type' => 'page', 'id' => '7' ), 'reason' => 'ok' ) ) );
		$GLOBALS['_sa_insert_unique_fail'] = true;
		$out                               = wp_get_ability( 'elementor/publish-document' )->execute( array( 'post_id' => 7 ) );
		$this->assertSame( 'aura_log_failed', $out->get_error_code() );
		$this->assertArrayNotHasKey( 'elementor/publish-document', $this->ran );
		$this->assertSame( array(), Aura_Worker_Door_Log::log_after( 0 ) );
	}

	/**
	 * §3.9-a, the other half: the snapshot was taken and the row could not be
	 * told. The call is refused — and the row SETTLES `refused`, carrying the
	 * id of the envelope that was taken, rather than staying pending for the
	 * reconciler (Ruling P14). `log_after` stops at a pending row, and a
	 * replay reads one as `interrupted` and keeps its claim.
	 */
	public function test_a_snapshot_id_that_cannot_be_recorded_refuses_and_settles_the_row(): void {
		$this->registerAll();
		$this->installRuleset( array( array( 'key' => 'rule/a', 'effect' => 'allow', 'target' => array( 'type' => 'page', 'id' => '7' ), 'reason' => 'ok' ) ) );
		Aura_Worker_Elementor_Door::set_snapshotter_for_tests(
			static function () {
				return array( 'success' => true, 'snapshot' => array( 'id' => 'snap_1' ) );
			}
		);
		$GLOBALS['_sa_option_cas_fail']['aura_worker_door_log_1'] = static function ( $value ) {
			return false !== strpos( (string) $value, 'snapshot_id' )
				&& false === strpos( (string) $value, 'snapshot_id_unrecorded' );
		};

		$out = wp_get_ability( 'elementor/publish-document' )->execute( array( 'post_id' => 7 ) );

		$this->assertSame( 'aura_log_failed', $out->get_error_code() );
		$this->assertArrayNotHasKey( 'elementor/publish-document', $this->ran, 'nothing ran' );
		$row = Aura_Worker_Door_Log::get( 1 );
		$this->assertSame( 'refused', $row['result'] );
		$this->assertSame( 'snapshot_id_unrecorded', $row['reason'] );
		$this->assertSame( 'snap_1', $row['snapshot_id'], 'the envelope that was taken stays traceable' );
		$this->assertCount( 1, Aura_Worker_Door_Log::log_after( 0 ), 'settled, so the log is served past it' );
	}

	/**
	 * The write RAN and the log could not say so (Ruling P16). Returning the
	 * callback's result would tell the caller it succeeded while the row sits
	 * pending — blocking every later entry, and eventually reported
	 * `interrupted` for a mutation that completed. The honest answer is that
	 * it may have run.
	 */
	public function test_a_terminal_settle_that_fails_after_the_callback_answers_may_have_run(): void {
		$this->registerAll();
		$this->installRuleset( array( array( 'key' => 'rule/a', 'effect' => 'allow', 'target' => array( 'type' => 'page', 'id' => '7' ), 'reason' => 'ok' ) ) );
		// Only the terminal settle fails; the admission and the snapshot patch land.
		$GLOBALS['_sa_option_cas_fail']['aura_worker_door_log_1'] = static function ( $value ) {
			return false !== strpos( (string) $value, 'settled_at' );
		};

		$out = wp_get_ability( 'elementor/publish-document' )->execute( array( 'post_id' => 7 ) );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aura_log_failed', $out->get_error_code() );
		$this->assertSame( 503, $out->get_error_data()['status'] );
		$this->assertTrue( $out->get_error_data()['may_have_run'] );
		$this->assertSame( 1, $out->get_error_data()['seq'] );
		$this->assertSame( 1, $this->ran['elementor/publish-document'], 'it did run — once' );
		$row = Aura_Worker_Door_Log::get( 1 );
		$this->assertSame( 'pending', $row['result'], 'left for the reconciler: the outcome is unknown to the log' );
		$this->assertSame( 1, (int) get_option( 'aura_worker_door_c_log_ungoverned_h' . (int) floor( time() / HOUR_IN_SECONDS ), 0 ) );
	}

	/**
	 * Ruling P27, the live half: `/status` read this row as stale while the
	 * callback was still running and settled it `interrupted`. The request
	 * that owns it must NOT overwrite that with `ok` — Aura may already have
	 * consumed it — so it answers the P16 way instead: the write ran, and
	 * this site did not record its outcome.
	 */
	public function test_a_row_the_reconciler_already_settled_is_not_overwritten_by_the_live_request(): void {
		$this->registerAll();
		$this->installRuleset( array( array( 'key' => 'rule/a', 'effect' => 'allow', 'target' => array( 'type' => 'page', 'id' => '7' ), 'reason' => 'ok' ) ) );
		// Another request's `/status` poll, in the window between the
		// callback returning and this request settling its own row.
		add_action(
			'sa_test_inner_ran',
			static function () {
				Aura_Worker_Door_Log::settle( 1, array( 'result' => 'interrupted' ) );
			}
		);

		$out = wp_get_ability( 'elementor/publish-document' )->execute( array( 'post_id' => 7 ) );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aura_log_failed', $out->get_error_code() );
		$this->assertTrue( $out->get_error_data()['may_have_run'] );
		$this->assertSame( 1, $out->get_error_data()['seq'] );
		$row = Aura_Worker_Door_Log::get( 1 );
		$this->assertSame( 'interrupted', $row['result'], 'the first terminal writer wins; a seq never changes meaning' );
		$this->assertSame(
			0,
			(int) get_option( 'aura_worker_door_c_log_ungoverned_h' . (int) floor( time() / HOUR_IN_SECONDS ), 0 ),
			'the call WAS recorded — just not by us'
		);
	}

	/**
	 * A throw after admission but BEFORE the callback was entered settles the
	 * row — `log_after` stops at a pending row — and settles it honestly
	 * (Ruling P33): `refused` / `setup_failed`, may_have_run FALSE.
	 *
	 * `seq > 0` used to stand in for "it ran", which it never was: a seq means
	 * ADMITTED, and the snapshot, the mutex, the watermark and the `ran`
	 * witness all sit between admission and the callback. Calling a setup
	 * failure `failed` cost a replay its approval for a write that provably
	 * never happened.
	 */
	public function test_a_setup_throw_after_admission_is_refused_and_did_not_run(): void {
		$this->registerAll();
		$this->installRuleset( array( array( 'key' => 'rule/a', 'effect' => 'allow', 'target' => array( 'type' => 'page', 'id' => '7' ), 'reason' => 'ok' ) ) );
		Aura_Worker_Elementor_Door::set_snapshotter_for_tests( static function () { throw new RuntimeException( 'boom' ); } );

		$out = wp_get_ability( 'elementor/publish-document' )->execute( array( 'post_id' => 7 ) );

		$this->assertSame( 'aura_log_failed', $out->get_error_code() );
		$this->assertSame( 503, $out->get_error_data()['status'] );
		$this->assertFalse( $out->get_error_data()['may_have_run'] );
		$this->assertStringContainsString( 'it was not run', $out->get_error_message() );
		$this->assertArrayNotHasKey( 'elementor/publish-document', $this->ran );
		$row = Aura_Worker_Door_Log::get( 1 );
		$this->assertSame( 'refused', $row['result'] );
		$this->assertSame( 'setup_failed', $row['reason'] );
		$this->assertFalse( $row['may_have_run'] );
		$this->assertArrayNotHasKey( 'ran', $row, 'the witness was never written' );
		$this->assertCount( 1, Aura_Worker_Door_Log::log_after( 0 ), 'settled, so the log is served past it' );
	}

	/**
	 * The other side of Ruling P33: the callback WAS entered and threw from
	 * inside. That is unchanged — `failed`, `exception`, may_have_run TRUE.
	 */
	public function test_a_throw_from_inside_the_callback_still_failed_and_may_have_run(): void {
		$this->installRuleset( array( array( 'key' => 'rule/a', 'effect' => 'allow', 'target' => array( 'type' => 'page', 'id' => '7' ), 'reason' => 'ok' ) ) );
		sa_register_ability(
			'elementor/publish-document',
			array(
				'execute_callback'    => static function () {
					throw new RuntimeException( 'boom' );
				},
				'permission_callback' => '__return_true',
				'meta'                => array(),
			)
		);
		do_action( 'wp_abilities_api_init' );

		$out = wp_get_ability( 'elementor/publish-document' )->execute( array( 'post_id' => 7 ) );

		$this->assertSame( 'aura_governor_error', $out->get_error_code() );
		$this->assertStringContainsString( 'may have run', $out->get_error_message() );
		$row = Aura_Worker_Door_Log::get( 1 );
		$this->assertSame( 'failed', $row['result'] );
		$this->assertSame( 'exception', $row['reason'] );
		$this->assertTrue( $row['may_have_run'] );
	}

	/* --------------------------------------------------------------- */
	/* Presence: wired always, decided lazily (Ruling P6)                */
	/* --------------------------------------------------------------- */

	/**
	 * The load-order hazard the early return used to open: both plugins
	 * bootstrap on `plugins_loaded` at 10 and `digitizer-site-worker` sorts
	 * before `elementor`, so the module class can be absent at init() on a
	 * site that very much has a door. Wiring must not depend on it.
	 *
	 * setUp() has already called init() with no module class and an empty
	 * ability registry — this is that site.
	 */
	public function test_init_wires_every_hook_even_with_no_elementor_present(): void {
		// has_filter() throughout — in core has_action() IS has_filter(), and
		// only this one answers with the PRIORITY, which is half of what is
		// being pinned here.
		$this->assertSame( PHP_INT_MAX, has_filter( 'wp_register_ability_args', array( 'Aura_Worker_Elementor_Door', 'wrap_args' ) ) );
		$this->assertSame( PHP_INT_MAX, has_filter( 'wp_abilities_api_init', array( 'Aura_Worker_Elementor_Door', 'verify_coverage' ) ) );
		$this->assertSame( 2, has_filter( 'rest_request_before_callbacks', array( 'Aura_Worker_Elementor_Door', 'close_transport' ) ), 'after open_frame at 1, before guard_core_any at 5' );
		$this->assertSame( 1, has_filter( 'wp_insert_post', array( 'Aura_Worker_Elementor_Door', 'observe_insert' ) ) );
		$this->assertSame( 1, has_filter( 'elementor/global_classes/cleanup', array( 'Aura_Worker_Elementor_Door', 'capture_class_cleanup' ) ) );
	}

	/** No Elementor: no door — so nothing is governed, and nothing is closed. */
	public function test_a_site_without_elementor_reports_no_door_and_is_not_closed(): void {
		$this->assertFalse( Aura_Worker_Elementor_Door::active() );
		$this->assertNull( Aura_Worker_Elementor_Door::status_fragment(), 'Aura keys on the fragment being absent' );
		do_action( 'wp_abilities_api_init' );
		$this->assertSame( 'ok', Aura_Worker_Elementor_Door::seam(), 'nothing to cover is not a coverage failure' );
		$req = new WP_REST_Request( 'POST', '/elementor/mcp' );
		$this->assertNull( Aura_Worker_Elementor_Door::close_transport( null, array(), $req ), 'a door that does not exist is not closed' );
	}

	/**
	 * The module class absent when init() ran, an `elementor/*` write
	 * registered afterwards: the registry is the second witness, the ability
	 * is wrapped all the same, and the door reports itself.
	 */
	public function test_an_elementor_ability_registered_after_init_is_governed(): void {
		$this->assertFalse( Aura_Worker_Elementor_Door::active(), 'a false answer must not be memoised — this is the load-order case' );
		$this->register( 'elementor/publish-document' );
		$this->assertTrue( Aura_Worker_Elementor_Door::active() );
		$read = new ReflectionProperty( WP_Ability::class, 'execute_callback' );
		if ( PHP_VERSION_ID < 80100 ) {
			$read->setAccessible( true );
		}
		$this->assertNotSame( $this->inner['elementor/publish-document'], $read->getValue( wp_get_ability( 'elementor/publish-document' ) ), 'wrapped despite the module class being absent at init()' );
		do_action( 'wp_abilities_api_init' );
		$this->assertSame( 'ok', Aura_Worker_Elementor_Door::seam() );
		$this->assertSame( 'open', Aura_Worker_Elementor_Door::status_fragment()['door'] );
	}

	/** The other witness: Elementor's module class, stood in for by the seam. */
	public function test_the_module_alone_makes_the_governor_active(): void {
		$this->assertFalse( Aura_Worker_Elementor_Door::active() );
		$GLOBALS['_sa_force_door'] = true; // stands in for class_exists( MODULE_CLASS )
		$this->assertTrue( Aura_Worker_Elementor_Door::active(), 'the module is a door even before it registers an ability' );
		do_action( 'wp_abilities_api_init' );
		$this->assertSame( 'ok', Aura_Worker_Elementor_Door::seam(), 'a module that has registered nothing yet leaves nothing uncovered' );
		$this->assertIsArray( Aura_Worker_Elementor_Door::status_fragment() );
	}

	/** The `/status` fragment Task 9 fills: the epoch, the seam and the door. */
	public function test_status_fragment_reports_the_epoch_seam_and_door(): void {
		$this->registerAll();
		$open = Aura_Worker_Elementor_Door::status_fragment();
		$this->assertSame( Aura_Worker_Door_Log::epoch(), $open['epoch'] );
		$this->assertSame( 'ok', $open['seam'] );
		$this->assertSame( 'open', $open['door'] );
		Aura_Worker_Elementor_Door::set_callback_reader_for_tests( static function () { throw new ReflectionException( 'no such property' ); } );
		do_action( 'wp_abilities_api_init' );
		$this->assertSame( 'closed', Aura_Worker_Elementor_Door::status_fragment()['door'] );
	}
}
