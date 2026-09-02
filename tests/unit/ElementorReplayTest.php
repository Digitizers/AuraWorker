<?php
/**
 * Replaying a held Elementor-door write after Aura's approval (spec §3.7).
 *
 * The order is the point: read the hold, pin the ruleset, RE-JUDGE before
 * claiming, check the approver's acknowledgement of a warn, re-check the
 * ability's own permission callback as the stored actor, claim by moving the
 * row, run through the registry as that actor, and answer from the terminal
 * log entry — never from the fact that the call returned.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class ElementorReplayTest extends TestCase {

	/** @var array<string,int> how many times each inner callback ran */
	private $ran = array();

	/** @var array<string,int> the user each inner callback saw */
	private $seen = array();

	protected function setUp(): void {
		sa_reset_state();
		foreach ( array( 'class-aura-worker-door-log', 'class-aura-worker-door-holds', 'class-elementor-door-governor' ) as $f ) {
			require_once dirname( __DIR__, 2 ) . '/digitizer-site-worker/includes/' . $f . '.php';
		}
		Aura_Worker_Elementor_Door::reset_for_tests();
		Aura_Worker_Elementor_Door::init();
		$GLOBALS['_current_user_id'] = 3;
		$GLOBALS['_user_logins'][3]  = 'bot';
		$GLOBALS['_posts'][7]        = (object) array(
			'ID'           => 7,
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_content' => '',
		);
		$this->ran  = array();
		$this->seen = array();
		Aura_Worker_Elementor_Door::set_snapshotter_for_tests(
			static function () {
				return array(
					'success'  => true,
					'snapshot' => array( 'id' => 'snap_test' ),
				);
			}
		);
	}

	// -----------------------------------------------------------------------
	// Fixtures
	// -----------------------------------------------------------------------

	/**
	 * Register one ability through the real filter, with a counting inner
	 * callback that records which user it ran as.
	 *
	 * @param string        $slug       Ability.
	 * @param callable|null $body       What the inner callback returns.
	 * @param mixed         $permission permission_callback.
	 */
	private function register( string $slug, ?callable $body = null, $permission = '__return_true' ): void {
		$ran   = &$this->ran;
		$seen  = &$this->seen;
		$inner = static function ( $input ) use ( &$ran, &$seen, $slug, $body ) {
			$ran[ $slug ]  = ( $ran[ $slug ] ?? 0 ) + 1;
			$seen[ $slug ] = get_current_user_id();
			return null === $body ? array(
				'ok'    => true,
				'input' => $input,
			) : $body( $input );
		};
		sa_register_ability(
			$slug,
			array(
				'execute_callback'    => $inner,
				'permission_callback' => $permission,
				'meta'                => array(),
			)
		);
	}

	/** Every governed slug, registered, then the coverage check. */
	private function registerAll(): void {
		foreach ( array_merge( Aura_Worker_Elementor_Door::READ_ALLOWLIST, array_keys( Aura_Worker_Elementor_Door::WRITE_TABLE ) ) as $slug ) {
			$this->register( $slug );
		}
		do_action( 'wp_abilities_api_init' );
	}

	/**
	 * The stored ruleset record, written straight to the option — the shape
	 * ElementorDoorGovernorTest::installRuleset() uses.
	 *
	 * @param array $rules Rules.
	 * @param int   $seq   Ruleset seq.
	 */
	private function installRuleset( array $rules, int $seq = 5 ): void {
		$GLOBALS['_options'][ Aura_Worker_Rules::OPTION ] = array(
			'envelope'    => 'x.y',
			'seq'         => $seq,
			'issued_at'   => '2026-09-02T00:00:00Z',
			'received_at' => time(),
			'rules'       => $rules,
		);
	}

	/** Hold a real call through the door and return its ref. */
	private function holdCall( string $slug = 'elementor/publish-document', array $input = array( 'post_id' => 7 ) ): string {
		$out = wp_get_ability( $slug )->execute( $input );
		$this->assertSame( 'aura_held_for_approval', $out->get_error_code(), 'the fixture must actually hold' );
		return (string) $out->get_error_data()['ref'];
	}

	/** A warn rule on page 7. */
	private function warnRule( string $reason = 'careful' ): array {
		return array(
			'key'    => 'rule/w',
			'effect' => 'warn',
			'target' => array(
				'type' => 'page',
				'id'   => '7',
			),
			'reason' => $reason,
		);
	}

	// -----------------------------------------------------------------------
	// (a) the happy path
	// -----------------------------------------------------------------------

	public function test_a_held_call_runs_as_the_stored_actor_and_answers_from_the_terminal_entry(): void {
		$this->registerAll();
		$this->installRuleset( array() );
		$ref = $this->holdCall();

		// Aura's approval arrives as somebody else entirely.
		$GLOBALS['_current_user_id'] = 9;
		$out                         = Aura_Worker_Elementor_Door::replay( $ref, null );

		$this->assertTrue( $out['ok'] );
		$this->assertSame( array( 'ok' => true, 'input' => array( 'post_id' => 7 ) ), $out['result'] );
		$this->assertSame( 'snap_test', $out['snapshot_id'] );
		$this->assertSame( 3, $this->seen['elementor/publish-document'], 'the write ran as the user who asked for it' );
		$this->assertSame( 9, get_current_user_id(), 'and the approver is restored afterwards' );

		$log = Aura_Worker_Door_Log::log_after( 0 );
		$this->assertCount( 2, $log );
		$this->assertSame( 'held', $log[0]['result'] );
		$this->assertSame( 'ok', $log[1]['result'] );
		$this->assertSame( $ref, $log[1]['ref'], 'the terminal entry carries the ref it ran for' );
		$this->assertSame( 5, $log[1]['ruleset_seq'], 'and the ruleset it was judged against' );
		$this->assertSame( 'approved', $log[1]['verdict'] );
		$this->assertSame( 'snap_test', $log[1]['snapshot_id'] );

		$this->assertNull( Aura_Worker_Door_Holds::get_held( $ref ), 'the held row is gone' );
		$this->assertNull( Aura_Worker_Door_Holds::get_claimed( $ref ), 'and so is the claimed twin' );
	}

	// -----------------------------------------------------------------------
	// (b) an unknown ref
	// -----------------------------------------------------------------------

	public function test_an_unknown_ref_is_not_held_and_runs_nothing(): void {
		$this->registerAll();
		$this->installRuleset( array() );

		$out = Aura_Worker_Elementor_Door::replay( 'door_nope', null );

		$this->assertFalse( $out['ok'] );
		$this->assertSame( 'not_held', $out['reason'] );
		$this->assertSame( array(), $this->ran );
		$this->assertSame( array(), Aura_Worker_Door_Log::log_after( 0 ) );
	}

	// -----------------------------------------------------------------------
	// (c) a block delivered since the hold
	// -----------------------------------------------------------------------

	public function test_a_block_delivered_since_the_hold_refuses_and_rejects_the_hold(): void {
		$this->registerAll();
		$this->installRuleset( array() );
		$ref = $this->holdCall();
		$this->installRuleset(
			array(
				array(
					'key'    => 'rule/b',
					'effect' => 'block',
					'target' => array(
						'type' => 'page',
						'id'   => '7',
					),
					'reason' => 'frozen',
				),
			)
		);

		$out = Aura_Worker_Elementor_Door::replay( $ref, null );

		$this->assertFalse( $out['ok'] );
		$this->assertSame( 'refused_by_current_rule', $out['reason'] );
		$this->assertSame( 'rule/b', $out['rule_key'] );
		$this->assertSame( array(), $this->ran, 'nothing ran' );
		$this->assertNull( Aura_Worker_Door_Holds::get_held( $ref ), 'the hold is rejected, not left for a retry' );
		$this->assertNull( Aura_Worker_Door_Holds::get_claimed( $ref ), 'and it was never claimed' );

		$log = Aura_Worker_Door_Log::log_after( 0 );
		$this->assertSame( 'refused', $log[1]['result'] );
		$this->assertSame( 'refused_by_current_rule', $log[1]['reason'] );
		$this->assertSame( $ref, $log[1]['ref'] );
		$this->assertSame( 1, Aura_Worker_Rules::count_24h( Aura_Worker_Rules::BLOCKED_COUNTER ) );
	}

	// -----------------------------------------------------------------------
	// (d) the warn acknowledgement
	// -----------------------------------------------------------------------

	public function test_a_warn_hold_with_a_matching_ack_runs_and_records_the_warn(): void {
		$this->registerAll();
		$this->installRuleset( array( $this->warnRule() ) );
		$ref  = $this->holdCall();
		$held = Aura_Worker_Door_Holds::get_held( $ref );

		$out = Aura_Worker_Elementor_Door::replay(
			$ref,
			array(
				'key'      => $held['rule']['key'],
				'ruleHash' => $held['rule']['ruleHash'],
			)
		);

		$this->assertTrue( $out['ok'] );
		$this->assertSame( 1, $this->ran['elementor/publish-document'] );
		$log = Aura_Worker_Door_Log::log_after( 0 );
		$this->assertSame( 'ok', $log[1]['result'] );
		$this->assertSame( 'warn', $log[1]['verdict'], 'an acknowledged warn is recorded as a warn, not as an approval' );
		$this->assertSame( 'rule/w', $log[1]['rule_key'] );
	}

	public function test_a_warn_replayed_with_no_ack_answers_warn_changed_and_runs_nothing(): void {
		$this->registerAll();
		$this->installRuleset( array( $this->warnRule() ) );
		$ref = $this->holdCall();

		$out = Aura_Worker_Elementor_Door::replay( $ref, null );

		$this->assertFalse( $out['ok'] );
		$this->assertSame( 'warn_changed', $out['reason'] );
		$this->assertSame( 'rule/w', $out['rule']['key'] );
		$this->assertSame( 'careful', $out['rule']['reason'] );
		$this->assertSame( array(), $this->ran );
		$this->assertNotNull( Aura_Worker_Door_Holds::get_held( $ref ), 'the hold stays for a second approval' );
		$this->assertNull( Aura_Worker_Door_Holds::get_claimed( $ref ), 'nothing was claimed' );
	}

	public function test_a_stale_ack_answers_warn_changed_with_the_fresh_rule_and_refreshes_the_hold(): void {
		$this->registerAll();
		$this->installRuleset( array( $this->warnRule() ) );
		$ref    = $this->holdCall();
		$stale  = Aura_Worker_Door_Holds::get_held( $ref )['rule'];
		// The operator re-issued the rule with a different reason while the
		// call sat in the queue.
		$this->installRuleset( array( $this->warnRule( 'even more careful' ) ) );
		$fresh = Aura_Worker_Elementor_Door::rule_evidence( $this->warnRule( 'even more careful' ) );

		$out = Aura_Worker_Elementor_Door::replay( $ref, $stale );

		$this->assertFalse( $out['ok'] );
		$this->assertSame( 'warn_changed', $out['reason'] );
		$this->assertSame( $fresh['ruleHash'], $out['rule']['ruleHash'] );
		$this->assertSame( 'even more careful', $out['rule']['reason'] );
		$this->assertSame( array(), $this->ran );
		$refreshed = Aura_Worker_Door_Holds::get_held( $ref );
		$this->assertSame( $fresh['ruleHash'], $refreshed['rule']['ruleHash'], 'the hold now carries the rule the operator must acknowledge' );
		$this->assertSame( 'warn', $refreshed['verdict'] );
	}

	// -----------------------------------------------------------------------
	// (e) claimed twins
	// -----------------------------------------------------------------------

	public function test_a_second_replay_of_the_same_ref_is_not_held(): void {
		$this->registerAll();
		$this->installRuleset( array() );
		$ref = $this->holdCall();

		$this->assertTrue( Aura_Worker_Elementor_Door::replay( $ref, null )['ok'] );
		$out = Aura_Worker_Elementor_Door::replay( $ref, null );

		$this->assertFalse( $out['ok'] );
		$this->assertSame( 'not_held', $out['reason'] );
		$this->assertSame( 1, $this->ran['elementor/publish-document'], 'the write ran once' );
	}

	public function test_a_claimed_twin_with_no_terminal_is_not_held(): void {
		$this->registerAll();
		$this->installRuleset( array() );
		$ref = $this->holdCall();
		// An in-flight (or interrupted) replay: the twin exists beside a held
		// row the mover has not deleted yet.
		$GLOBALS['_options'][ 'aura_worker_door_claimed_' . $ref ] = Aura_Worker_Door_Holds::get_held( $ref );

		$out = Aura_Worker_Elementor_Door::replay( $ref, null );

		$this->assertFalse( $out['ok'] );
		$this->assertSame( 'not_held', $out['reason'] );
		$this->assertSame( array(), $this->ran );
	}

	// -----------------------------------------------------------------------
	// (e2) the ability reports an error
	// -----------------------------------------------------------------------

	public function test_an_error_shaped_result_answers_failed_and_the_entry_is_failed(): void {
		$this->registerAll();
		$this->installRuleset( array() );
		$ref = $this->holdCall();
		$this->register(
			'elementor/publish-document',
			static function () {
				return array(
					'status'  => 'error',
					'message' => 'elementor said no',
				);
			}
		);

		$out = Aura_Worker_Elementor_Door::replay( $ref, null );

		$this->assertFalse( $out['ok'] );
		$this->assertSame( 'failed', $out['reason'] );
		$this->assertSame( 'ability reported an error', $out['error'] );
		$log = Aura_Worker_Door_Log::log_after( 0 );
		$this->assertSame( 'failed', $log[1]['result'] );
		$this->assertNull( Aura_Worker_Door_Holds::get_claimed( $ref ), 'the claim is released either way' );
	}

	// -----------------------------------------------------------------------
	// (e4) the actor's permission, re-checked at approval time
	// -----------------------------------------------------------------------

	public function test_a_permission_callback_that_refuses_the_actor_runs_nothing(): void {
		$this->registerAll();
		$this->installRuleset( array() );
		$ref = $this->holdCall();
		$this->register( 'elementor/publish-document', null, '__return_false' );

		$out = Aura_Worker_Elementor_Door::replay( $ref, null );

		$this->assertFalse( $out['ok'] );
		$this->assertSame( 'refused_by_permission', $out['reason'] );
		$this->assertSame( array(), $this->ran );
		$log = Aura_Worker_Door_Log::log_after( 0 );
		$this->assertSame( 'refused', $log[1]['result'] );
		$this->assertSame( 'refused_by_permission', $log[1]['reason'] );
		$this->assertSame( $ref, $log[1]['ref'] );
		$this->assertNull( Aura_Worker_Door_Holds::get_held( $ref ), 'the hold is released, not left to be replayed for ever' );
		$this->assertNull( Aura_Worker_Door_Holds::get_claimed( $ref ) );
	}

	public function test_a_permission_callback_that_errors_for_the_actor_is_reported_with_its_message(): void {
		$this->registerAll();
		$this->installRuleset( array() );
		$ref = $this->holdCall();
		$this->register(
			'elementor/publish-document',
			null,
			static function () {
				return new WP_Error( 'nope', 'this user may not edit pages' );
			}
		);

		$out = Aura_Worker_Elementor_Door::replay( $ref, null );

		$this->assertSame( 'refused_by_permission', $out['reason'] );
		$this->assertSame( array(), $this->ran );
		$this->assertSame( 'this user may not edit pages', Aura_Worker_Door_Log::log_after( 0 )[1]['error'] );
	}

	// -----------------------------------------------------------------------
	// (f) races around the claim
	// -----------------------------------------------------------------------

	public function test_a_reject_racing_the_claim_leaves_the_replay_not_held(): void {
		$this->registerAll();
		$this->installRuleset( array() );
		$ref = $this->holdCall();
		// The window inside claim(): the twin's conditional INSERT is running,
		// and the operator's reject deletes the held row it was moving.
		$GLOBALS['_sa_before_swap'] = static function () use ( $ref ) {
			$GLOBALS['_sa_before_swap'] = null; // fires once
			Aura_Worker_Door_Holds::reject( $ref );
		};

		$out = Aura_Worker_Elementor_Door::replay( $ref, null );

		$this->assertFalse( $out['ok'] );
		$this->assertSame( 'not_held', $out['reason'] );
		$this->assertSame( array(), $this->ran );
		$this->assertNull( Aura_Worker_Door_Holds::get_claimed( $ref ), 'the claim backed out' );
	}

	public function test_a_claim_racing_the_reject_answers_already_claimed(): void {
		$this->registerAll();
		$this->installRuleset( array() );
		$ref = $this->holdCall();
		// The reverse window: reject() has read the held row and is about to
		// delete it when a replay's claim moves it.
		$GLOBALS['_sa_after_option_read'] = static function ( $name ) use ( $ref ) {
			if ( 'aura_worker_door_held_' . $ref !== $name ) {
				return;
			}
			$GLOBALS['_sa_after_option_read'] = null; // fires once, and never re-enters
			Aura_Worker_Door_Holds::claim( $ref );
		};

		$this->assertSame( 'already_claimed', Aura_Worker_Door_Holds::reject( $ref ) );
		$this->assertNotNull( Aura_Worker_Door_Holds::get_claimed( $ref ), 'the claimed twin the reject must not step on' );
	}

	// -----------------------------------------------------------------------
	// (g) the pinned ruleset
	// -----------------------------------------------------------------------

	public function test_a_ruleset_delivered_mid_replay_is_not_consulted(): void {
		$this->registerAll();
		$this->installRuleset( array() );
		$ref = $this->holdCall();
		$this->register(
			'elementor/publish-document',
			function ( $input ) {
				// A push lands while the write is running.
				$this->installRuleset(
					array(
						array(
							'key'    => 'rule/b',
							'effect' => 'block',
							'target' => array(
								'type' => 'page',
								'id'   => '7',
							),
							'reason' => 'too late',
						),
					),
					9
				);
				return array(
					'ok'    => true,
					'input' => $input,
				);
			}
		);

		$out = Aura_Worker_Elementor_Door::replay( $ref, null );

		$this->assertTrue( $out['ok'], 'the call proceeded against the ruleset it was judged on' );
		$this->assertSame( 1, $this->ran['elementor/publish-document'] );
		$log = Aura_Worker_Door_Log::log_after( 0 );
		$this->assertSame( 'ok', $log[1]['result'] );
		$this->assertSame( 5, $log[1]['ruleset_seq'], 'the pinned seq, not the one that landed mid-write' );
	}

	// -----------------------------------------------------------------------
	// The tool
	// -----------------------------------------------------------------------

	public function test_the_tool_declares_the_site_and_hands_its_ref_to_the_governor(): void {
		$this->registerAll();
		$this->installRuleset( array() );
		$ref   = $this->holdCall();
		$tools = new Aura_Worker_Tools();
		$tool  = $tools->get_tool( 'elementor_replay_ability' );

		$this->assertNotNull( $tool, 'elementor_replay_ability is not registered' );
		$this->assertSame( array( array( 'type' => 'site', 'id' => '*' ) ), $tool->touches( array( 'ref' => $ref ) ) );
		$this->assertSame(
			array(
				'read_only'         => false,
				'destructive'       => true,
				'requires_approval' => true,
				'supports_preview'  => false,
			),
			$tool->get_annotations()
		);

		$out = $tool->execute( array( 'ref' => $ref ) );

		$this->assertTrue( $out['ok'] );
		$this->assertSame( 1, $this->ran['elementor/publish-document'] );
	}

	public function test_the_tool_passes_a_warn_acknowledgement_through(): void {
		$this->registerAll();
		$this->installRuleset( array( $this->warnRule() ) );
		$ref   = $this->holdCall();
		$held  = Aura_Worker_Door_Holds::get_held( $ref );
		$tools = new Aura_Worker_Tools();

		$out = $tools->get_tool( 'elementor_replay_ability' )->execute(
			array(
				'ref' => $ref,
				'ack' => array(
					'key'      => $held['rule']['key'],
					'ruleHash' => $held['rule']['ruleHash'],
				),
			)
		);

		$this->assertTrue( $out['ok'] );
		$this->assertSame( 'warn', Aura_Worker_Door_Log::log_after( 0 )[1]['verdict'] );
	}
}
