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
		$this->assertSame( 'aura_governor_error', $out->get_error_code() );
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

	public function test_at_the_bound_the_request_backs_out_settling_discarded_and_closes(): void {
		$this->registerAll();
		$this->installRuleset( array( array( 'key' => 'rule/a', 'effect' => 'allow', 'target' => array( 'type' => 'page', 'id' => '7' ), 'reason' => 'ok' ) ) );
		for ( $i = 1; $i <= Aura_Worker_Door_Log::MAX_UNACKED; $i++ ) {
			$GLOBALS['_options'][ 'aura_worker_door_log_' . $i ] = array( 'seq' => $i, 'result' => 'ok', 'admitted' => true );
			$GLOBALS['_rows'][ 'aura_worker_door_log_' . $i ]    = maybe_serialize( $GLOBALS['_options'][ 'aura_worker_door_log_' . $i ] );
		}
		$out = wp_get_ability( 'elementor/publish-document' )->execute( array( 'post_id' => 7 ) );
		$this->assertSame( 'aura_log_full', $out->get_error_code() );
		$row = Aura_Worker_Door_Log::get( Aura_Worker_Door_Log::MAX_UNACKED + 1 );
		$this->assertSame( 'discarded', $row['result'], 'the row is settled, never deleted — no hole in seq' );
		$this->assertTrue( $row['admitted'], 'and admitted, so log_after serves past it' );
		$this->assertTrue( Aura_Worker_Door_Log::is_closed() );
		$this->assertArrayNotHasKey( 'elementor/publish-document', $this->ran );
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
	 * A throw AFTER the row was admitted settles that row rather than leaving
	 * it pending: `log_after` stops at a pending row, so every later entry
	 * would wait for the reconciler to call a KNOWN failure "interrupted".
	 */
	public function test_a_throw_after_admission_settles_the_row_failed_and_may_have_run(): void {
		$this->registerAll();
		$this->installRuleset( array( array( 'key' => 'rule/a', 'effect' => 'allow', 'target' => array( 'type' => 'page', 'id' => '7' ), 'reason' => 'ok' ) ) );
		Aura_Worker_Elementor_Door::set_snapshotter_for_tests( static function () { throw new RuntimeException( 'boom' ); } );
		$out = wp_get_ability( 'elementor/publish-document' )->execute( array( 'post_id' => 7 ) );
		$this->assertSame( 'aura_governor_error', $out->get_error_code() );
		$this->assertStringContainsString( 'may have run', $out->get_error_message() );
		$row = Aura_Worker_Door_Log::get( 1 );
		$this->assertSame( 'failed', $row['result'] );
		$this->assertSame( 'exception', $row['reason'] );
		$this->assertTrue( $row['may_have_run'] );
		$this->assertCount( 1, Aura_Worker_Door_Log::log_after( 0 ), 'settled, so the log is served past it' );
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
