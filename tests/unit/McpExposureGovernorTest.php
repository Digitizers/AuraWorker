<?php
/**
 * audit_mcp_exposure — the `elementor.governor` block (Task 11, SiteAgent
 * 2.16.0).
 *
 * The block is a fleet-wide read of what THIS site's door log and hold
 * queue already say: whether the seam verified, whether the log or the
 * hold queue is full, and the four rolling-30-day event counters Tasks 5
 * and 7 already bump. Everything here drives the REAL tool's execute() and
 * the REAL governor — nothing is stubbed — because the point of the block
 * is that it reads exactly what the door itself would answer.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

require_once SA_PLUGIN_DIR . '/includes/credential-rules.php';
require_once SA_PLUGIN_DIR . '/includes/tools/class-tool-audit-mcp-exposure.php';
require_once SA_PLUGIN_DIR . '/includes/class-aura-worker-door-log.php';
require_once SA_PLUGIN_DIR . '/includes/class-aura-worker-door-holds.php';
require_once SA_PLUGIN_DIR . '/includes/class-elementor-door-governor.php';

/**
 * A $wpdb stand-in that fails only the governor's own reads — every
 * door-log / door-hold option name carries the `aura_worker_door_` prefix,
 * which nothing else this tool reads shares — so a throw here proves the
 * OTHER elementor subtrees (mcp_module, consent, app_passwords, coverage)
 * are unaffected, never that the whole tool exploded.
 */
final class SA_Governor_Throwing_Wpdb extends SA_Test_Wpdb {
	public function get_var( $query = null, $x = 0, $y = 0 ) {
		if ( false !== strpos( (string) $query, 'aura_worker_door_' ) ) {
			throw new RuntimeException( 'governor query exploded' );
		}
		return parent::get_var( $query, $x, $y );
	}

	public function get_results( $query, $output = OBJECT ) {
		if ( false !== strpos( (string) $query, 'aura_worker_door_' ) ) {
			throw new RuntimeException( 'governor query exploded' );
		}
		return parent::get_results( $query, $output );
	}
}

final class McpExposureGovernorTest extends TestCase {

	private Aura_Tool_Audit_Mcp_Exposure $tool;

	protected function setUp(): void {
		sa_reset_state();
		Aura_Worker_Elementor_Door::reset_for_tests();
		$this->tool = new Aura_Tool_Audit_Mcp_Exposure();
	}

	/**
	 * The throwing $wpdb this file installs is a GLOBAL, and sa_reset_state()
	 * does not replace it — so it leaked into every later test in the run that
	 * issues an `aura_worker_door_` query. Nothing did until the unbind grew a
	 * door step (Ruling P44), which then exploded on a stand-in this file left
	 * behind. Put back a working one after every test here.
	 */
	protected function tearDown(): void {
		$GLOBALS['wpdb'] = new SA_Test_Wpdb();
	}

	private function block(): array {
		$result = $this->tool->execute( array() );
		$this->assertArrayHasKey( 'elementor', $result );
		$this->assertArrayHasKey( 'governor', $result['elementor'] );
		return $result['elementor']['governor'];
	}

	/** Registers one governed ability and runs verify_coverage() — the door reports seam: ok. */
	private function bringUpTheDoor(): void {
		Aura_Worker_Elementor_Door::init();
		sa_register_ability(
			'elementor/create-page',
			array(
				'execute_callback'    => static function () {
					return array();
				},
				'permission_callback' => '__return_true',
			)
		);
		do_action( 'wp_abilities_api_init' ); // runs verify_coverage()
	}

	// --- Ruling P6: lazy presence -------------------------------------------

	public function test_active_false_alone_when_the_door_never_initialised(): void {
		// sa_reset_state()'s default: no elementor/* ability registered,
		// _sa_force_door false — active() is false.
		$this->assertSame( array( 'active' => false ), $this->block() );
	}

	// --- every key, when the door initialised -------------------------------

	public function test_every_key_appears_with_active_true_when_the_door_initialised(): void {
		$this->bringUpTheDoor();
		$b = $this->block();
		$this->assertSame(
			array(
				'active',
				'epoch',
				'binding',
				'observation',
				'observation_unsupported',
				'seam',
				'door',
				'held_count',
				'log_unacked',
				'log_ungoverned_30d',
				'unobserved_30d',
				'hook_missed_30d',
				'unknown_ability_30d',
				'queue_full',
				'log_full',
			),
			array_keys( $b )
		);
		$this->assertTrue( $b['active'] );
		$this->assertIsString( $b['epoch'] );
		$this->assertNotSame( '', $b['epoch'] );
		$this->assertNull( $b['binding'], 'nothing has bound this site, and a read never mints one (Ruling A5b)' );
		// THIS call is the one that mints the epoch (asserted non-empty just
		// above), and minting a virgin site's epoch is ITSELF a door-state
		// mutation (Ruling S6: insert_unique() bumps on any new door-prefixed
		// row) — so a fresh site's first governor_block() legitimately reports
		// a real version, not null. door_version_raw() reads whatever THIS
		// call just wrote; the audit itself never bumps beyond that.
		$this->assertIsInt( $b['observation'], "this call's own epoch mint is a real mutation (Ruling S6), not the audit itself bumping anything" );
		$this->assertSame( Aura_Worker_Door_Log::door_version_raw(), $b['observation'], 'a second read of the row changes nothing' );
		$this->assertNull( $b['observation_unsupported'], 'a normal test run is on a transactional engine and 64-bit PHP, so nothing is unsupported (Ruling S13)' );
		$this->assertSame( 'ok', $b['seam'], 'verify_coverage() ran and every registered elementor/* ability is wrapped' );
		$this->assertSame( 'open', $b['door'] );
		$this->assertSame( 0, $b['held_count'] );
		$this->assertSame( 0, $b['log_unacked'] );
		$this->assertSame( 0, $b['log_ungoverned_30d'] );
		$this->assertSame( 0, $b['unobserved_30d'] );
		$this->assertSame( 0, $b['hook_missed_30d'] );
		$this->assertSame( 0, $b['unknown_ability_30d'] );
		$this->assertFalse( $b['queue_full'] );
		$this->assertNull( $b['log_full'] );
	}

	public function test_seam_is_unchecked_before_verify_coverage_has_run_in_this_request(): void {
		// The door is active (an elementor/* ability is registered) but
		// wp_abilities_api_init never fired, so verify_coverage() has not
		// run — the audit reports honestly rather than forcing one.
		Aura_Worker_Elementor_Door::init();
		sa_register_ability( 'elementor/create-page', array( 'execute_callback' => '__return_true', 'permission_callback' => '__return_true' ) );
		$b = $this->block();
		$this->assertTrue( $b['active'] );
		$this->assertSame( 'unchecked', $b['seam'], 'and the audit still says WHY' );
		// A seam that is not `ok` closes the transport (close_transport()
		// answers every door request 503), so the door is shut — one
		// definition for every reader, Ruling P24.
		$this->assertSame( 'closed', $b['door'] );
	}

	public function test_door_is_closed_when_the_log_is_full_and_log_full_reports_it(): void {
		$this->bringUpTheDoor();
		Aura_Worker_Door_Log::close();
		Aura_Worker_Door_Log::bump_refused();
		Aura_Worker_Door_Log::bump_refused();
		$b = $this->block();
		$this->assertSame( 'closed', $b['door'] );
		$this->assertIsArray( $b['log_full'] );
		$this->assertIsString( $b['log_full']['since'] );
		$this->assertNotSame( '', $b['log_full']['since'] );
		$this->assertSame( 2, $b['log_full']['refused'] );
	}

	public function test_held_count_and_queue_full_read_the_hold_store(): void {
		$this->bringUpTheDoor();
		$this->installOpenRuleset();
		$GLOBALS['_current_user_id'] = 3;
		$GLOBALS['_user_logins'][3]  = 'bot';
		$GLOBALS['_posts'][7]        = (object) array( 'ID' => 7, 'post_type' => 'page', 'post_status' => 'draft' );
		wp_get_ability( 'elementor/publish-document' )->execute( array( 'post_id' => 7 ) );
		$b = $this->block();
		$this->assertSame( 1, $b['held_count'] );
		$this->assertFalse( $b['queue_full'] );
	}

	private function installOpenRuleset(): void {
		$GLOBALS['_options'][ Aura_Worker_Rules::OPTION ] = array(
			'envelope'    => 'x.y',
			'seq'         => 1,
			'issued_at'   => '2026-09-02T00:00:00Z',
			'received_at' => time(),
			'rules'       => array(),
		);
		sa_register_ability(
			'elementor/publish-document',
			array(
				'execute_callback'    => static function () {
					return array();
				},
				'permission_callback' => '__return_true',
			)
		);
	}

	// --- the _30d counters read what Tasks 5/7 already bump -----------------

	public function test_count_30d_sums_the_window_in_one_query_and_excludes_older_buckets_and_other_names(): void {
		$now      = strtotime( '2026-09-02T12:00:00Z' );
		$hour_now = (int) floor( $now / HOUR_IN_SECONDS );
		$edge     = (int) floor( ( $now - 30 * DAY_IN_SECONDS ) / HOUR_IN_SECONDS ); // still inside the window
		$outside  = $edge - 1; // one hour older than the window

		foreach (
			array(
				$hour_now => 3,
				$hour_now - 1 => 2,
				$edge      => 5,
				$outside   => 100,
			) as $h => $v
		) {
			$name                          = 'aura_worker_door_c_log_ungoverned_h' . $h;
			$GLOBALS['_rows'][ $name ]    = (string) $v;
			$GLOBALS['_options'][ $name ] = (string) $v;
		}
		// a different counter name in the same hour must not be summed in
		$other                          = 'aura_worker_door_c_unobserved_h' . $hour_now;
		$GLOBALS['_rows'][ $other ]    = '999';
		$GLOBALS['_options'][ $other ] = '999';

		$this->assertSame( 10, Aura_Worker_Elementor_Door::count_30d( 'log_ungoverned', $now ) );
		$this->assertSame( 999, Aura_Worker_Elementor_Door::count_30d( 'unobserved', $now ) );
	}

	public function test_an_unmapped_call_while_the_log_is_closed_writes_no_row_and_bumps_log_ungoverned_but_the_refusal_still_answers(): void {
		// Exercises Task 5's existing behaviour (record_terminal_only()) through
		// the counter governor_block() now reads — this task adds no new bump.
		Aura_Worker_Elementor_Door::init();
		sa_register_ability(
			'elementor/create-page', // any registered elementor/* ability makes active() true
			array(
				'execute_callback'    => static function () {
					return array();
				},
				'permission_callback' => '__return_true',
			)
		);
		sa_register_ability(
			'elementor/future-thing', // unmapped: outside READ_ALLOWLIST and WRITE_TABLE
			array(
				'execute_callback'    => static function () {
					return array();
				},
				'permission_callback' => '__return_true',
			)
		);
		$GLOBALS['_current_user_id'] = 3;
		$GLOBALS['_user_logins'][3]  = 'bot';
		Aura_Worker_Door_Log::close();

		$out = wp_get_ability( 'elementor/future-thing' )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aura_ability_unmapped', $out->get_error_code(), 'the refusal still answers' );
		$this->assertSame( array(), Aura_Worker_Door_Log::log_after( 0 ), 'no row was written' );
		$this->assertSame( 1, Aura_Worker_Elementor_Door::count_30d( 'log_ungoverned' ) );

		$b = $this->block();
		$this->assertSame( 1, $b['log_ungoverned_30d'] );
	}

	/**
	 * Ruling P57: an unreadable queue is reported as unknown, not as empty.
	 *
	 * `held_count` and `queue_full` are the same fact, so they say "unknown"
	 * together rather than one of them inventing a zero.
	 */
	public function test_an_unreadable_queue_reports_null_rather_than_zero(): void {
		$this->bringUpTheDoor();
		$GLOBALS['_sa_rows_read_error'][ $GLOBALS['wpdb']->esc_like( Aura_Worker_Door_Holds::HELD ) ] = true;

		$b = $this->block();

		$GLOBALS['_sa_rows_read_error'] = array();
		$this->assertNull( $b['held_count'] );
		$this->assertNull( $b['queue_full'] );
	}

	// --- a throw inside governor_block() is isolated ------------------------

	public function test_a_throw_inside_governor_block_yields_error_for_governor_only(): void {
		$this->bringUpTheDoor();
		$GLOBALS['wpdb'] = new SA_Governor_Throwing_Wpdb();

		$result = $this->tool->execute( array() );
		$b      = $result['elementor'];

		$this->assertArrayHasKey( 'error', $b['governor'] );
		$this->assertSame( 'governor query exploded', $b['governor']['error'] );
		// every other subtree is untouched — none of their reads share the
		// door's option-name prefix, so none of them see the broken $wpdb.
		$this->assertArrayHasKey( 'mcp_module', $b );
		$this->assertArrayNotHasKey( 'error', $b['mcp_module'] );
		$this->assertArrayNotHasKey( 'error', $b['consent'] );
		$this->assertArrayNotHasKey( 'error', $b['app_passwords']['elementor'] );
		$this->assertArrayNotHasKey( 'error', $b['app_passwords']['other'] );
		$this->assertArrayNotHasKey( 'error', $b['coverage'] );
	}

	// --- the manage_options early-return shape includes governor ------------

	public function test_without_manage_options_the_governor_subtree_is_the_error_shape_too(): void {
		$GLOBALS['_caps'] = array( 'update_plugins' ); // held, but NOT manage_options
		$b                = $this->tool->execute( array() )['elementor'];
		$this->assertSame( array( 'error' => 'manage_options required' ), $b['governor'] );
	}
}
