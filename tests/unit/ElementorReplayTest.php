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

	protected function tearDown(): void {
		$dir = WP_CONTENT_DIR . '/aura-backups/snapshots';
		if ( is_dir( $dir ) ) {
			@chmod( $dir, 0777 );
			foreach ( (array) glob( $dir . '/*' ) as $file ) {
				@unlink( $file );
			}
		}
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

	/**
	 * Task 7's compensation seam: the envelope store cannot be written, so
	 * finish_creation() has to compensate. Nothing is faked — the real
	 * Aura_Worker_Snapshots writes to a directory it cannot write to.
	 *
	 * @param callable $fn What to run.
	 * @return mixed
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

	/**
	 * The held row as it comes back from an unclaim, minus the `restored_at`
	 * stamp unclaim() adds (Ruling P41) — so a test can still say "everything
	 * the hold carried is back" without pinning byte-identity the sweep now
	 * depends on NOT holding.
	 */
	private function holdWithoutRestoreStamp( string $ref ): ?array {
		$row = Aura_Worker_Door_Holds::get_held( $ref );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$this->assertArrayHasKey( 'restored_at', $row, 'an unclaimed hold says so' );
		unset( $row['restored_at'] );
		return $row;
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

	/** A `manage-classes` delete, and the pages Elementor says it would rewrite. */
	private function classDelete(): array {
		return array( 'operations' => array( array( 'action' => 'delete', 'id' => 'g-a' ) ) );
	}

	/** Seed a page the class→posts index can name. */
	private function seedPage( int $id ): void {
		$GLOBALS['_posts'][ $id ] = (object) array(
			'ID'           => $id,
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_content' => '',
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
		$this->assertSame( 'this user may not edit pages', $out['error'], 'the answer says why, not just that' );
	}

	/**
	 * Ruling P40: a permission callback that THROWS is refused with a record,
	 * not left to the reconciler.
	 *
	 * The check runs after the claim — deliberately: the claim is what makes
	 * the actor switch safe against a concurrent replay — and `replay()` had
	 * only a `finally`, so a throw from Elementor's callback (or from whatever
	 * a plugin filtered onto it) escaped the whole method. The request died
	 * with an uncaught error, the CLAIMED row survived, and ten minutes later
	 * the reconciler called the attempt `interrupted` and spent the operator's
	 * approval on a callback that never ran.
	 */
	public function test_a_permission_callback_that_throws_refuses_and_releases_the_claim(): void {
		$this->registerAll();
		$this->installRuleset( array() );
		$ref       = $this->holdCall();
		$inner_ran = 0;
		add_action(
			'sa_test_inner_ran',
			static function () use ( &$inner_ran ) {
				++$inner_ran;
			}
		);
		$this->register(
			'elementor/publish-document',
			null,
			static function () {
				throw new RuntimeException( 'the permission callback exploded' );
			}
		);

		$out = Aura_Worker_Elementor_Door::replay( $ref, null );

		$this->assertFalse( $out['ok'] );
		$this->assertSame( 'refused_by_permission', $out['reason'] );
		$this->assertStringContainsString( 'exploded', $out['error'] );
		$this->assertSame( 0, $inner_ran, 'the write path was never entered' );
		$this->assertSame( array(), $this->ran, 'and nothing ran' );
		$this->assertNull( Aura_Worker_Door_Holds::get_held( $ref ), 'the hold is released, not left to be replayed for ever' );
		$this->assertNull( Aura_Worker_Door_Holds::get_claimed( $ref ), 'and no claimed row is left for the reconciler to call `interrupted`' );
		$log = Aura_Worker_Door_Log::log_after( 0 );
		$this->assertSame( 'refused', $log[1]['result'] );
		$this->assertSame( 'refused_by_permission', $log[1]['reason'] );
		$this->assertSame( $ref, $log[1]['ref'] );
		$this->assertStringContainsString( 'exploded', $log[1]['error'], 'the throw is what the entry says' );
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
	// Ruling P7: a transient refusal never spends the approval
	// -----------------------------------------------------------------------

	public function test_a_snapshot_failure_after_the_claim_puts_the_hold_back(): void {
		$this->registerAll();
		$this->installRuleset( array() );
		$ref    = $this->holdCall();
		$before = Aura_Worker_Door_Holds::get_held( $ref );
		Aura_Worker_Elementor_Door::set_snapshotter_for_tests(
			static function () {
				return array(
					'success' => false,
					'error'   => 'disk full',
				);
			}
		);

		$out = Aura_Worker_Elementor_Door::replay( $ref, null );

		$this->assertFalse( $out['ok'] );
		$this->assertSame( 'retry_later', $out['reason'] );
		$this->assertSame( 'aura_snapshot_failed', $out['code'] );
		$this->assertStringContainsString( 'disk full', $out['error'] );
		$this->assertArrayNotHasKey( 'claim_retained', $out );
		$this->assertSame( array(), $this->ran, 'the write never happened' );
		$this->assertSame( $before, $this->holdWithoutRestoreStamp( $ref ), 'the hold is back, field for field' );
		$this->assertNull( Aura_Worker_Door_Holds::get_claimed( $ref ) );
		$log = Aura_Worker_Door_Log::log_after( 0 );
		$this->assertSame( 'refused', $log[1]['result'] );
		$this->assertSame( 'snapshot_failed', $log[1]['reason'] );

		// …and the same approval works once the site can snapshot again.
		Aura_Worker_Elementor_Door::set_snapshotter_for_tests(
			static function () {
				return array(
					'success'  => true,
					'snapshot' => array( 'id' => 'snap_test' ),
				);
			}
		);
		$second = Aura_Worker_Elementor_Door::replay( $ref, null );
		$this->assertTrue( $second['ok'] );
		$this->assertSame( 1, $this->ran['elementor/publish-document'] );
	}

	/**
	 * Ruling P33: a SETUP failure under a replay gives the approval back.
	 *
	 * `snapshot_for()` THROWING (rather than answering `success: false`) took
	 * the governor's \Throwable catch, which read `seq > 0` as "it may have
	 * run" and settled the entry `failed`. replay() releases a claimed hold on
	 * `failed`, so an operator's one approval was permanently consumed by a
	 * write that never happened — the callback was not even reached, and the
	 * row's `ran` witness is patched a few lines after the snapshot.
	 */
	public function test_a_snapshot_that_throws_under_a_replay_gives_the_approval_back(): void {
		$this->registerAll();
		$this->installRuleset( array() );
		$ref    = $this->holdCall();
		$before = Aura_Worker_Door_Holds::get_held( $ref );
		Aura_Worker_Elementor_Door::set_snapshotter_for_tests(
			static function () {
				throw new RuntimeException( 'the reader exploded' );
			}
		);

		$out = Aura_Worker_Elementor_Door::replay( $ref, null );

		$this->assertFalse( $out['ok'] );
		$this->assertSame( 'retry_later', $out['reason'], 'the approval is not spent on a call that never ran' );
		$this->assertSame( 'aura_log_failed', $out['code'] );
		$this->assertSame( array(), $this->ran, 'the write never happened' );
		$this->assertSame( $before, $this->holdWithoutRestoreStamp( $ref ), 'the hold is back, field for field' );
		$this->assertNull( Aura_Worker_Door_Holds::get_claimed( $ref ) );
		$log = Aura_Worker_Door_Log::log_after( 0 );
		$this->assertSame( 'refused', $log[1]['result'] );
		$this->assertSame( 'setup_failed', $log[1]['reason'] );
		$this->assertFalse( $log[1]['may_have_run'] );
		$this->assertArrayNotHasKey( 'ran', $log[1] );

		// …and the same approval still works once the site can read again.
		Aura_Worker_Elementor_Door::set_snapshotter_for_tests(
			static function () {
				return array(
					'success'  => true,
					'snapshot' => array( 'id' => 'snap_test' ),
				);
			}
		);
		$second = Aura_Worker_Elementor_Door::replay( $ref, null );
		$this->assertTrue( $second['ok'] );
		$this->assertSame( 1, $this->ran['elementor/publish-document'] );
	}

	/**
	 * Ruling P41, end to end: a `/status` sweep landing inside the unclaim of
	 * a replay that outran CLAIM_STALE_MS must not cost the approval.
	 *
	 * The sweep used to delete the hold this unclaim had just restored, and
	 * give_back() — which judged only by the CLAIMED row — still answered
	 * `retry_later`. Aura retried a ref that was held by nothing.
	 */
	public function test_a_sweep_inside_the_unclaim_of_a_long_running_replay_keeps_the_approval(): void {
		$this->registerAll();
		$this->installRuleset( array() );
		$ref = $this->holdCall();
		Aura_Worker_Elementor_Door::set_snapshotter_for_tests(
			static function () {
				return array( 'success' => false, 'error' => 'disk full' );
			}
		);
		// This replay is still running past the stale bound when it gives up,
		// and a poll arrives between unclaim()'s held INSERT and its claimed
		// DELETE.
		$GLOBALS['_sa_after_insert_unique'][ Aura_Worker_Door_Holds::HELD . $ref ] = static function () use ( $ref ) {
			$row = get_option( Aura_Worker_Door_Holds::CLAIMED . $ref, array() );
			$row['claimed_at'] = gmdate( 'c', time() - 3600 );
			$GLOBALS['_options'][ Aura_Worker_Door_Holds::CLAIMED . $ref ] = $row;
			$GLOBALS['_rows'][ Aura_Worker_Door_Holds::CLAIMED . $ref ]    = maybe_serialize( $row );
			Aura_Worker_Door_Holds::sweep( time(), Aura_Worker_Elementor_Door::CLAIM_STALE_MS );
		};

		$out = Aura_Worker_Elementor_Door::replay( $ref, null );

		$this->assertFalse( $out['ok'] );
		$this->assertSame( 'retry_later', $out['reason'] );
		$this->assertArrayNotHasKey( 'claim_retained', $out, 'the hold IS back, so nothing is retained' );
		$this->assertSame( array(), $this->ran );
		$this->assertNotNull( Aura_Worker_Door_Holds::get_held( $ref ), 'and `retry_later` is true: the ref is held again' );
		$this->assertNull( Aura_Worker_Door_Holds::get_claimed( $ref ) );

		// …and the same approval still works.
		Aura_Worker_Elementor_Door::set_snapshotter_for_tests(
			static function () {
				return array( 'success' => true, 'snapshot' => array( 'id' => 'snap_test' ) );
			}
		);
		$this->assertTrue( Aura_Worker_Elementor_Door::replay( $ref, null )['ok'] );
		$this->assertSame( 1, $this->ran['elementor/publish-document'] );
	}

	/**
	 * The give_back() half on its own: an unclaim whose CLAIMED row vanished
	 * entirely (a release racing it) leaves nothing held — and that IS the
	 * approval lost, whatever the claimed row says. Judged by the claimed row
	 * alone, this answered a bare `retry_later` (Ruling P41).
	 */
	public function test_an_unclaim_that_restores_nothing_says_the_approval_is_spent(): void {
		$this->registerAll();
		$this->installRuleset( array() );
		$ref = $this->holdCall();
		Aura_Worker_Elementor_Door::set_snapshotter_for_tests(
			static function () {
				return array( 'success' => false, 'error' => 'disk full' );
			}
		);
		// Both rows are taken out from under the replay before it gives up.
		$GLOBALS['_sa_before_swap'] = static function () use ( $ref ) {
			if ( null !== Aura_Worker_Door_Holds::get_claimed( $ref ) ) {
				Aura_Worker_Door_Holds::release( $ref );
			}
		};

		$out = Aura_Worker_Elementor_Door::replay( $ref, null );

		$this->assertSame( 'retry_later', $out['reason'] );
		$this->assertNull( Aura_Worker_Door_Holds::get_held( $ref ) );
		$this->assertTrue( $out['claim_retained'], 'nothing is held, so Aura must not expect a second approval' );
	}

	public function test_a_move_back_that_cannot_insert_keeps_the_claimed_row_and_says_so(): void {
		$this->registerAll();
		$this->installRuleset( array() );
		$ref = $this->holdCall();
		Aura_Worker_Elementor_Door::set_snapshotter_for_tests(
			static function () {
				// The refusal, and — on the way out — a database that refuses
				// the move-back's conditional INSERT too.
				$GLOBALS['_sa_insert_unique_fail'] = true;
				return array(
					'success' => false,
					'error'   => 'disk full',
				);
			}
		);

		$out = Aura_Worker_Elementor_Door::replay( $ref, null );

		$this->assertSame( 'retry_later', $out['reason'] );
		$this->assertTrue( $out['claim_retained'], 'Aura is told the ref will not answer a second approval' );
		$this->assertNotNull( Aura_Worker_Door_Holds::get_claimed( $ref ), 'the claimed row is the only record left' );
		$this->assertNull( Aura_Worker_Door_Holds::get_held( $ref ) );
	}

	// -----------------------------------------------------------------------
	// The answer comes from the terminal entry, or it is `interrupted`
	// -----------------------------------------------------------------------

	public function test_a_stamp_that_cannot_be_written_refuses_before_the_write(): void {
		$this->registerAll();
		$this->installRuleset( array() );
		$ref = $this->holdCall();
		// The stamp's compare-and-swap is refused at the driver. It happens at
		// ADMISSION now, so the call is refused before it can run: a claimed
		// row with no seq would otherwise be indistinguishable afterwards from
		// a call that never started.
		$GLOBALS['_sa_option_cas_fail'][ 'aura_worker_door_claimed_' . $ref ] = true;

		$out = Aura_Worker_Elementor_Door::replay( $ref, null );

		$this->assertSame( 'retry_later', $out['reason'] );
		$this->assertSame( 'aura_log_failed', $out['code'] );
		$this->assertSame( array(), $this->ran, 'nothing ran' );
		$this->assertNotNull( Aura_Worker_Door_Holds::get_held( $ref ), 'the approval is not spent' );
		$this->assertNull( Aura_Worker_Door_Holds::get_claimed( $ref ) );
		$log = Aura_Worker_Door_Log::log_after( 0 );
		$this->assertSame( 'refused', $log[1]['result'] );
		$this->assertSame( 'terminal_seq_unstamped', $log[1]['reason'] );
	}

	public function test_an_entry_that_cannot_be_read_answers_interrupted_and_retains_the_claim(): void {
		$this->registerAll();
		$this->installRuleset( array() );
		$ref = $this->holdCall();
		$this->register(
			'elementor/publish-document',
			static function ( $input ) {
				// The row this call would be judged by disappears mid-write.
				unset( $GLOBALS['_options']['aura_worker_door_log_2'], $GLOBALS['_rows']['aura_worker_door_log_2'] );
				return array(
					'ok'    => true,
					'input' => $input,
				);
			}
		);

		$out = Aura_Worker_Elementor_Door::replay( $ref, null );

		$this->assertFalse( $out['ok'], 'a return value is not evidence' );
		$this->assertSame( 'interrupted', $out['reason'] );
		$this->assertSame( $ref, $out['ref'] );
		$this->assertNotNull( Aura_Worker_Door_Holds::get_claimed( $ref ), 'the claimed row is left for the reconciler' );
		$this->assertNull( Aura_Worker_Door_Holds::get_held( $ref ) );
	}

	public function test_a_closed_log_gives_the_hold_back(): void {
		$this->registerAll();
		$this->installRuleset( array() );
		$ref    = $this->holdCall();
		$before = Aura_Worker_Door_Holds::get_held( $ref );
		Aura_Worker_Door_Log::close(); // Aura stopped acknowledging while the call waited

		$out = Aura_Worker_Elementor_Door::replay( $ref, null );

		$this->assertSame( 'retry_later', $out['reason'] );
		$this->assertSame( 'aura_log_full', $out['code'] );
		$this->assertSame( array(), $this->ran );
		$this->assertSame( $before, $this->holdWithoutRestoreStamp( $ref ) );
		$this->assertNull( Aura_Worker_Door_Holds::get_claimed( $ref ) );
	}

	public function test_a_target_that_stopped_being_attributable_is_refused_for_good(): void {
		$this->registerAll();
		$this->installRuleset( array() );
		$ref = $this->holdCall();
		// The component post was re-typed while the call waited: no retry can
		// make it snapshottable again.
		Aura_Worker_Elementor_Door::set_snapshotter_for_tests(
			static function () {
				return array(
					'success' => false,
					'error'   => 'post 7 is not a component',
					'code'    => 'aura_target_unattributed',
				);
			}
		);

		$out = Aura_Worker_Elementor_Door::replay( $ref, null );

		$this->assertFalse( $out['ok'] );
		$this->assertSame( 'refused', $out['reason'], 'not `interrupted` — the site provably did not run it' );
		$this->assertSame( 'aura_target_unattributed', $out['code'] );
		$this->assertSame( array(), $this->ran );
		$this->assertNull( Aura_Worker_Door_Holds::get_held( $ref ), 'the hold is spent, not parked for the reconciler' );
		$this->assertNull( Aura_Worker_Door_Holds::get_claimed( $ref ) );
		$log = Aura_Worker_Door_Log::log_after( 0 );
		$this->assertSame( 'refused', $log[1]['result'] );
		$this->assertSame( 'aura_target_unattributed', $log[1]['code'] );
	}

	/**
	 * The approval arrives after the hold's seven days, on a site whose
	 * `/status` never ran to sweep it (Ruling P18).
	 */
	public function test_an_expired_hold_is_not_replayed(): void {
		$this->registerAll();
		$this->installRuleset( array() );
		$ref  = $this->holdCall();
		$name = 'aura_worker_door_held_' . $ref;
		$row  = array_merge( (array) $GLOBALS['_options'][ $name ], array( 'expires_at' => gmdate( 'c', time() - 1 ) ) );
		$GLOBALS['_options'][ $name ] = $row;
		$GLOBALS['_rows'][ $name ]    = maybe_serialize( $row );

		$out = Aura_Worker_Elementor_Door::replay( $ref, null );

		$this->assertFalse( $out['ok'] );
		$this->assertSame( 'not_held', $out['reason'] );
		$this->assertSame( array(), $this->ran, 'an expired approval executes nothing' );
		$this->assertNull( Aura_Worker_Door_Holds::get_claimed( $ref ), 'and nothing was claimed' );
	}

	public function test_a_hold_a_second_from_expiry_still_replays(): void {
		$this->registerAll();
		$this->installRuleset( array() );
		$ref  = $this->holdCall();
		$name = 'aura_worker_door_held_' . $ref;
		$row  = array_merge( (array) $GLOBALS['_options'][ $name ], array( 'expires_at' => gmdate( 'c', time() + 1 ) ) );
		$GLOBALS['_options'][ $name ] = $row;
		$GLOBALS['_rows'][ $name ]    = maybe_serialize( $row );

		$out = Aura_Worker_Elementor_Door::replay( $ref, null );

		$this->assertTrue( $out['ok'] );
		$this->assertSame( 1, $this->ran['elementor/publish-document'] );
	}

	// -----------------------------------------------------------------------
	// The `ran` witness
	// -----------------------------------------------------------------------

	public function test_a_ran_witness_that_cannot_be_written_refuses_before_the_callback(): void {
		$this->registerAll();
		$this->installRuleset( array() );
		$ref = $this->holdCall();
		// Only the `ran` patch fails; the admission, the stamp and the settle
		// after it still land.
		$GLOBALS['_sa_option_cas_fail']['aura_worker_door_log_2'] = static function ( $value ) {
			return false !== strpos( (string) $value, 's:3:"ran";b:1;' );
		};

		$out = Aura_Worker_Elementor_Door::replay( $ref, null );

		$this->assertSame( 'retry_later', $out['reason'] );
		$this->assertSame( array(), $this->ran, 'a write whose witness is not durable does not run' );
		$this->assertNotNull( Aura_Worker_Door_Holds::get_held( $ref ) );
		$log = Aura_Worker_Door_Log::log_after( 0 );
		$this->assertSame( 'refused', $log[1]['result'] );
		$this->assertSame( 'ran_witness_failed', $log[1]['reason'] );
	}

	/**
	 * The snapshot was TAKEN and the row could not be told (Ruling P14). The
	 * call provably did not run, so the approval must go back — the row used
	 * to be left pending, which replay() reads as `interrupted`, keeps the
	 * claim for, and the reconciler then releases ten minutes later: a
	 * transient log write permanently discarded an approved call.
	 */
	public function test_a_snapshot_id_that_cannot_be_recorded_gives_the_hold_back(): void {
		$this->registerAll();
		$this->installRuleset( array() );
		$ref = $this->holdCall();
		// Only the pre-write PATCH of the id fails; the terminal settle that
		// carries the same id (under `snapshot_id_unrecorded`) still lands.
		$GLOBALS['_sa_option_cas_fail']['aura_worker_door_log_2'] = static function ( $value ) {
			return false !== strpos( (string) $value, 'snapshot_id' )
				&& false === strpos( (string) $value, 'snapshot_id_unrecorded' );
		};

		$out = Aura_Worker_Elementor_Door::replay( $ref, null );

		$this->assertFalse( $out['ok'] );
		$this->assertSame( 'retry_later', $out['reason'] );
		$this->assertSame( array(), $this->ran, 'nothing ran' );
		$this->assertNotNull( Aura_Worker_Door_Holds::get_held( $ref ), 'the approval is not spent' );
		$this->assertNull( Aura_Worker_Door_Holds::get_claimed( $ref ) );
		$entry = Aura_Worker_Door_Log::get( 2 );
		$this->assertSame( 'refused', $entry['result'] );
		$this->assertSame( 'snapshot_id_unrecorded', $entry['reason'] );
		$this->assertSame( 'snap_test', $entry['snapshot_id'], 'the envelope was taken, and stays traceable' );
		$this->assertArrayNotHasKey( 'ran', $entry );

		// And the retry Aura is told to make actually works.
		unset( $GLOBALS['_sa_option_cas_fail']['aura_worker_door_log_2'] );
		$again = Aura_Worker_Elementor_Door::replay( $ref, null );
		$this->assertTrue( $again['ok'] );
		$this->assertSame( 1, $this->ran['elementor/publish-document'] );
	}

	/**
	 * The same failure under an approval: the entry is non-terminal, so
	 * replay() answers `interrupted` and KEEPS the claim — a call that may
	 * have run is never handed back for a second approval (Ruling P16).
	 */
	public function test_a_terminal_settle_that_fails_after_the_callback_is_interrupted_and_keeps_the_claim(): void {
		$this->registerAll();
		$this->installRuleset( array() );
		$ref = $this->holdCall();
		$GLOBALS['_sa_option_cas_fail']['aura_worker_door_log_2'] = static function ( $value ) {
			return false !== strpos( (string) $value, 'settled_at' );
		};

		$out = Aura_Worker_Elementor_Door::replay( $ref, null );

		$this->assertFalse( $out['ok'] );
		$this->assertSame( 'interrupted', $out['reason'] );
		$this->assertSame( 1, $this->ran['elementor/publish-document'], 'it ran' );
		$this->assertNotNull( Aura_Worker_Door_Holds::get_claimed( $ref ), 'the claim is kept — it may have run' );
		$this->assertNull( Aura_Worker_Door_Holds::get_held( $ref ), 'and it is not approvable again' );
		$entry = Aura_Worker_Door_Log::get( 2 );
		$this->assertSame( 'pending', $entry['result'] );
		$this->assertTrue( $entry['ran'] );
	}

	public function test_the_stamp_and_the_ran_witness_are_on_the_row_before_the_callback(): void {
		$this->registerAll();
		$this->installRuleset( array() );
		$ref  = $this->holdCall();
		$seen = array();
		$this->register(
			'elementor/publish-document',
			static function () use ( $ref, &$seen ) {
				$seen['stamp'] = Aura_Worker_Door_Holds::get_claimed( $ref )['terminal_seq'] ?? null;
				$seen['ran']   = Aura_Worker_Door_Log::get( 2 )['ran'] ?? null;
				throw new RuntimeException( 'elementor died mid-write' );
			}
		);

		$out = Aura_Worker_Elementor_Door::replay( $ref, null );

		$this->assertSame( 2, $seen['stamp'], 'the claimed row knew its entry before the write' );
		$this->assertTrue( $seen['ran'], 'and the row knew the callback was about to run' );
		$this->assertSame( 'failed', $out['reason'], 'a call that may have run is never retried' );
		$entry = Aura_Worker_Door_Log::get( 2 );
		$this->assertSame( 'failed', $entry['result'] );
		$this->assertTrue( $entry['may_have_run'] );
		$this->assertTrue( $entry['ran'], 'the witness survives the settle' );
	}

	// -----------------------------------------------------------------------
	// The Critical case: a creation whose envelope could not be stored
	// -----------------------------------------------------------------------

	public function test_a_creation_whose_envelope_fails_is_failed_and_never_replayed_again(): void {
		$this->registerAll();
		$this->installRuleset( array() );
		$ref  = $this->holdCall( 'elementor/create-page', array() );
		$made = 0;
		$this->register(
			'elementor/create-page',
			static function () use ( &$made ) {
				$made = wp_insert_post(
					array(
						'post_type'   => 'page',
						'post_title'  => 'made',
						'post_author' => 3,
					)
				);
				return array( 'id' => $made );
			}
		);

		$out = $this->withUnwritableSnapshots(
			static function () use ( $ref ) {
				return Aura_Worker_Elementor_Door::replay( $ref, null );
			}
		);

		// The page WAS created. `retry_later` here would create a second one.
		$this->assertFalse( $out['ok'] );
		$this->assertSame( 'failed', $out['reason'] );
		$this->assertSame( 'aura_snapshot_failed', $out['code'], 'the same retryable code — and it is NOT retried' );
		$this->assertSame( array( $made ), $out['created_post_ids'] );
		$this->assertSame( array( $made ), $out['compensated'] );
		$entry = Aura_Worker_Door_Log::get( 2 );
		$this->assertSame( 'failed', $entry['result'] );
		$this->assertTrue( $entry['ran'] );
		$this->assertNull( Aura_Worker_Door_Holds::get_held( $ref ), 'the approval is spent' );
		$this->assertNull( Aura_Worker_Door_Holds::get_claimed( $ref ) );
		$this->assertSame( 'not_held', Aura_Worker_Elementor_Door::replay( $ref, null )['reason'] );
		$this->assertSame( 1, $this->ran['elementor/create-page'], 'exactly one creation' );
	}

	// -----------------------------------------------------------------------
	// The ability is gone
	// -----------------------------------------------------------------------

	public function test_an_ability_gone_since_the_hold_is_refused_and_logged(): void {
		$this->registerAll();
		$this->installRuleset( array() );
		$ref = $this->holdCall();
		unset( $GLOBALS['_abilities']['elementor/publish-document'] ); // Elementor deactivated

		$out = Aura_Worker_Elementor_Door::replay( $ref, null );

		$this->assertFalse( $out['ok'] );
		$this->assertSame( 'refused_by_missing_ability', $out['reason'], 'not `not_held` — that would mean retry' );
		$this->assertSame( array(), $this->ran );
		$log = Aura_Worker_Door_Log::log_after( 0 );
		$this->assertSame( 'refused', $log[1]['result'] );
		$this->assertSame( 'ability_missing', $log[1]['reason'] );
		$this->assertSame( $ref, $log[1]['ref'] );
		$this->assertNull( Aura_Worker_Door_Holds::get_held( $ref ) );
		$this->assertNull( Aura_Worker_Door_Holds::get_claimed( $ref ) );
	}

	// -----------------------------------------------------------------------
	// The permission re-check is the ACTOR's
	// -----------------------------------------------------------------------

	public function test_the_permission_check_runs_as_the_actor_not_the_approver(): void {
		$this->registerAll();
		$this->installRuleset( array() );
		$ref  = $this->holdCall();
		$seen = null;
		$this->register(
			'elementor/publish-document',
			null,
			static function () use ( &$seen ) {
				$seen = get_current_user_id();
				return true;
			}
		);
		$GLOBALS['_current_user_id'] = 9;

		$this->assertTrue( Aura_Worker_Elementor_Door::replay( $ref, null )['ok'] );
		$this->assertSame( 3, $seen, 'the ability was asked about the user who wanted the write' );
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

	// -----------------------------------------------------------------------
	// Ruling P34: the CURRENT touches are re-judged, not the stored ones
	// -----------------------------------------------------------------------

	/**
	 * The class→posts index moved while the hold waited: page 13 started
	 * using the class, and a warn rule protects it.
	 *
	 * Re-judging the STORED touches saw only page 12, so the design-system
	 * ack was accepted, the call was claimed and run — and page 13's warn was
	 * discovered at priority 1, where (before Ruling P32) it was merely
	 * recorded. Re-judging the CURRENT touches holds the call for a second
	 * acknowledgement, naming the rule the operator has not seen.
	 */
	public function test_a_page_that_started_using_the_class_forces_a_fresh_acknowledgement(): void {
		$this->seedPage( 12 );
		$this->seedPage( 13 );
		$this->registerAll();
		$this->installRuleset(
			array(
				array( 'key' => 'rule/ds', 'effect' => 'warn', 'target' => array( 'type' => 'design_system' ), 'reason' => 'classes are shared' ),
			)
		);
		$GLOBALS['_sa_class_relations'] = array( 'g-a' => array( 12 ) );
		$ref                            = $this->holdCall( 'elementor/manage-classes', $this->classDelete() );
		$this->assertSame(
			array( 'design_system:*', 'page:12' ),
			$this->refs( Aura_Worker_Door_Holds::get_held( $ref )['touches'] ),
			'what the operator was shown'
		);

		// The approval Aura is about to send acknowledges the design-system
		// rule — the only one that existed when the hold was made.
		$ds_ack = Aura_Worker_Elementor_Door::rule_evidence(
			array( 'key' => 'rule/ds', 'effect' => 'warn', 'target' => array( 'type' => 'design_system' ), 'reason' => 'classes are shared' )
		);

		// …and meanwhile page 13 starts using the class, under a warn rule.
		$GLOBALS['_sa_class_relations'] = array( 'g-a' => array( 12, 13 ) );
		// The page rule is ordered first: `match()` returns ONE rule for a
		// whole touch set, and within a rank the first match wins — so this
		// is the rule the operator must now answer for. Judged on the STORED
		// touches it would not match at all (page 13 is not in that set), the
		// design-system ack would be accepted, and the call would run.
		$this->installRuleset(
			array(
				array( 'key' => 'rule/watch-13', 'effect' => 'warn', 'target' => array( 'type' => 'page', 'id' => '13' ), 'reason' => 'new page, tell me' ),
				array( 'key' => 'rule/ds', 'effect' => 'warn', 'target' => array( 'type' => 'design_system' ), 'reason' => 'classes are shared' ),
			)
		);

		$out = Aura_Worker_Elementor_Door::replay( $ref, array( 'key' => $ds_ack['key'], 'ruleHash' => $ds_ack['ruleHash'] ) );

		$this->assertFalse( $out['ok'] );
		$this->assertSame( 'warn_changed', $out['reason'] );
		$this->assertSame( 'rule/watch-13', $out['rule']['key'], 'the rule the operator has not acknowledged' );
		$this->assertSame( array(), $this->ran, 'nothing ran' );
		$this->assertSame(
			array( 'design_system:*', 'page:12', 'page:13' ),
			$this->refs( Aura_Worker_Door_Holds::get_held( $ref )['touches'] ),
			'and the hold now shows what would actually run'
		);
		$this->assertNull( Aura_Worker_Door_Holds::get_claimed( $ref ), 'never claimed' );
	}

	/**
	 * The same drift, but the new page is BLOCKED. Before Ruling P34 that was
	 * discovered inside judge_collateral() — one priority after Elementor had
	 * already deleted the class row — so the refusal came too late to prevent
	 * the deletion. Now it never gets past the re-judgement.
	 */
	public function test_a_page_that_started_using_the_class_under_a_block_refuses_before_the_class_is_deleted(): void {
		$this->seedPage( 12 );
		$this->seedPage( 13 );
		$deleted = 0;
		$this->register(
			'elementor/manage-classes',
			static function () use ( &$deleted ) {
				++$deleted; // Elementor deleting the class row
				return array( 'ok' => true );
			}
		);
		do_action( 'wp_abilities_api_init' );
		$this->installRuleset( array() ); // no rule: the call is held with verdict `none`
		$GLOBALS['_sa_class_relations'] = array( 'g-a' => array( 12 ) );
		$ref                            = $this->holdCall( 'elementor/manage-classes', $this->classDelete() );

		$GLOBALS['_sa_class_relations'] = array( 'g-a' => array( 12, 13 ) );
		$this->installRuleset(
			array( array( 'key' => 'rule/keep-13', 'effect' => 'block', 'target' => array( 'type' => 'page', 'id' => '13' ), 'reason' => 'hands off' ) )
		);

		$out = Aura_Worker_Elementor_Door::replay( $ref, null );

		$this->assertFalse( $out['ok'] );
		$this->assertSame( 'refused_by_current_rule', $out['reason'] );
		$this->assertSame( 'rule/keep-13', $out['rule_key'] );
		$this->assertSame( 0, $deleted, 'the class was never deleted' );
		$this->assertNull( Aura_Worker_Door_Holds::get_held( $ref ), 'the hold is rejected, not parked' );
		$this->assertNull( Aura_Worker_Door_Holds::get_claimed( $ref ) );
		$log = Aura_Worker_Door_Log::log_after( 0 );
		$this->assertSame( 'refused_by_current_rule', end( $log )['reason'] );
		$this->assertSame(
			array( 'design_system:*', 'page:12', 'page:13' ),
			$this->refs( end( $log )['touches'] ),
			'the entry records what was judged'
		);
	}

	/**
	 * The target stopped being one Aura can attribute while the hold waited —
	 * the page was deleted. Retrying can never help, so the approval is spent
	 * rather than parked (Ruling P34).
	 */
	public function test_a_target_that_became_unattributable_during_the_hold_is_refused_for_good(): void {
		$this->registerAll();
		$this->installRuleset( array() );
		$ref = $this->holdCall();

		unset( $GLOBALS['_posts'][7] ); // the page is gone

		$out = Aura_Worker_Elementor_Door::replay( $ref, null );

		$this->assertFalse( $out['ok'] );
		$this->assertSame( 'target_unattributed', $out['reason'] );
		$this->assertSame( 'aura_target_unattributed', $out['code'] );
		$this->assertSame( array(), $this->ran );
		$this->assertNull( Aura_Worker_Door_Holds::get_held( $ref ) );
		$log = Aura_Worker_Door_Log::log_after( 0 );
		$this->assertSame( 'target_unattributed', end( $log )['reason'] );
	}

	/** "type:id" for each touch, so an assertion reads like the declaration. */
	private function refs( array $touches ): array {
		return array_map(
			static function ( $t ) {
				return $t['type'] . ':' . $t['id'];
			},
			$touches
		);
	}

	// -----------------------------------------------------------------------
	// Ruling P36: the entry's actor is WHOSE call it is, not who approved it
	// -----------------------------------------------------------------------

	/**
	 * The approval arrives on a different credential and a different
	 * transport from the call it releases — which is the normal case: the
	 * held call came from an assistant's Application Password over Elementor's
	 * abilities route, and the approval comes from Aura's gateway.
	 *
	 * `replay()` switches the current USER, but `actor()` also reads the
	 * Application Password uuid THIS request authenticated with and the route
	 * it arrived on. Rebuilding it in the wrapper therefore minted a person
	 * who never existed: the held user's id and login, wearing the approver's
	 * credential and transport, recorded as the author of the mutation.
	 */
	public function test_a_replay_records_the_held_actor_and_names_the_approver_beside_it(): void {
		Aura_Worker_Security::init();
		$GLOBALS['_user_logins'][3]   = 'assistant';
		$GLOBALS['_user_logins'][9]   = 'operator';
		$GLOBALS['_app_passwords'][3] = array( array( 'uuid' => 'uuid-assistant', 'name' => 'Studio assistant', 'created' => time() ) );
		$GLOBALS['_app_passwords'][9] = array( array( 'uuid' => 'uuid-operator', 'name' => 'Aura gateway', 'created' => time() ) );
		$this->registerAll();
		$this->installRuleset( array() );

		// The held call: user 3, uuid-assistant, over the abilities REST route.
		sa_authenticate_app_password( 3, 'uuid-assistant' );
		Aura_Worker_Call_Context::set_rest_route_for_tests( '/wp-abilities/v1/abilities/elementor/publish-document' );
		$ref   = $this->holdCall();
		$stored = Aura_Worker_Door_Holds::get_held( $ref )['actor'];
		$this->assertSame( 'uuid-assistant', $stored['app_password_uuid'] );
		$this->assertSame( 'rest', $stored['via'] );

		// The approval: user 9, uuid-operator, over SiteAgent's own transport.
		$GLOBALS['_current_user_id'] = 9;
		sa_authenticate_app_password( 9, 'uuid-operator' );
		Aura_Worker_Call_Context::set_rest_route_for_tests( '/aura/v1/tools/elementor_replay_ability' );

		$out = Aura_Worker_Elementor_Door::replay( $ref, null );

		$this->assertTrue( $out['ok'] );
		$log   = Aura_Worker_Door_Log::log_after( 0 );
		$entry = end( $log );
		$this->assertSame( $stored, $entry['actor'], 'the held actor, verbatim — no hybrid identity' );
		$this->assertSame( 'uuid-operator', $entry['approved_by']['app_password_uuid'] );
		$this->assertSame( 9, $entry['approved_by']['user_id'] );
		$this->assertSame( 'operator', $entry['approved_by']['login'] );
		$this->assertSame( 'mcp', $entry['approved_by']['via'] );
		$this->assertSame( 3, $this->seen['elementor/publish-document'], 'and it still RAN as the held actor' );
	}

	/**
	 * An approval that carries no identifiable user does NOT refuse the
	 * replay — the grant already authorised it — it just leaves `approved_by`
	 * null (Ruling P36).
	 */
	public function test_an_unidentifiable_approver_leaves_approved_by_null_without_refusing(): void {
		$this->registerAll();
		$this->installRuleset( array() );
		$ref = $this->holdCall();

		$GLOBALS['_current_user_id'] = 0; // e.g. a cron-driven replay

		$out = Aura_Worker_Elementor_Door::replay( $ref, null );

		$this->assertTrue( $out['ok'] );
		$log   = Aura_Worker_Door_Log::log_after( 0 );
		$entry = end( $log );
		$this->assertSame( 3, $entry['actor']['user_id'], 'the call is still the held actor\'s' );
		$this->assertArrayHasKey( 'approved_by', $entry, 'the field is always present on a replay entry' );
		$this->assertNull( $entry['approved_by'] );
	}
}
