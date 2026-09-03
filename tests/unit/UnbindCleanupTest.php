<?php
/**
 * Phase B of the two-phase site unbind (#434, spec §2.3): the cleanup that
 * runs under the site claim after Phase A's marker is written, in ONE fixed
 * order, ending — only on Aura's `final: true` and only once every earlier
 * step is PROVEN complete — in the irreversible deletion of the site token.
 *
 * DEVIATION from the brief's setUp, which called sa_install_gateway_key():
 * Phase B verifies nothing, so nothing here needs ext-sodium. The gateway
 * public key is seeded as the opaque row it is, and this file therefore keeps
 * covering the cleanup on a platform without the extension, where
 * UnbindAcceptTest skips itself entirely.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class UnbindCleanupTest extends TestCase {

	protected function setUp(): void {
		sa_reset_state();
		// No administrator fixture: since round 3 Phase B enumerates nobody. It
		// looks ONCE, at the owner the marker recorded, and an owner it does
		// not know is never proven gone.
		$GLOBALS['_options']['aura_worker_grant_pubkey'] = 'a-gateway-public-key';
		update_option( 'aura_worker_connect_user_id', 3 );
		update_option( 'aura_worker_dashboard_url', 'https://app.example' );
		Aura_Worker_Rules::bind( 'c1', sa_token_hash() );
		sa_set_managed_app_password( 3, 'uuid-managed' );
		sa_add_app_password( 3, 'uuid-manual' );
		sa_add_app_password( 3, 'uuid-unrelated' );
		sa_set_marker(
			array(
				'site'               => sa_token_hash(),
				'seq'                => 9,
				'connect_user_id'    => 3,
				'app_password_uuids' => array( 'uuid-managed', 'uuid-manual' ),
				'app_password_users' => array(
					'uuid-managed' => 3,
					'uuid-manual'  => 3,
				),
			)
		);
	}

	// -----------------------------------------------------------------------
	// cleanup(): the fixed order, and what proves each step complete.
	// -----------------------------------------------------------------------

	public function test_final_cleanup_removes_everything_in_order_and_reports_complete(): void {
		$fence = Aura_Worker_Magic_Link::claim_site();
		$this->assertTrue( Aura_Worker_Unbind::cleanup( true, $fence ) );
		$this->assertFalse( sa_app_password_exists( 3, 'uuid-managed' ) );
		$this->assertFalse( sa_app_password_exists( 3, 'uuid-manual' ) );
		$this->assertTrue( sa_app_password_exists( 3, 'uuid-unrelated' ), 'unrelated password untouched' );
		$this->assertFalse( get_option( 'aura_worker_dashboard_url' ) );
		$this->assertFalse( get_option( 'aura_worker_connect_user_id' ) );
		$this->assertNull( Aura_Worker_Rules::current() );
		$this->assertNull( Aura_Worker_Rules::stored_uncached(), 'the store row itself is gone, sentinel included' );
		$this->assertFalse( get_option( 'aura_worker_grant_pubkey' ) );
		$this->assertFalse( get_option( 'aura_worker_site_token' ) );
		$this->assertTrue( Aura_Worker_Unbind::is_set(), 'the marker survives cleanup' );
		$this->assertSame( array(), Aura_Worker_Unbind::leftovers() );
		$this->assertSame( array( 'revoke', 'options', 'ruleset', 'grant', 'door', 'token' ), $GLOBALS['_unbind_trace'] );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	public function test_not_final_keeps_the_token_and_reports_incomplete(): void {
		$fence = Aura_Worker_Magic_Link::claim_site();
		$this->assertFalse( Aura_Worker_Unbind::cleanup( false, $fence ) );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ) );
		$this->assertSame( array(), Aura_Worker_Unbind::leftovers(), '(1)-(4) done' );
		$this->assertSame( array( 'revoke', 'options', 'ruleset', 'grant', 'door' ), $GLOBALS['_unbind_trace'], 'the bookkeeping steps all ran; step (5) is not even entered' );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	public function test_a_failing_step_reports_incomplete_and_never_reaches_the_token(): void {
		$GLOBALS['_fail_delete_app_password'] = 'uuid-manual';
		$fence                                = Aura_Worker_Magic_Link::claim_site();
		$this->assertFalse( Aura_Worker_Unbind::cleanup( true, $fence ) );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ) );
		$this->assertContains( 'app_passwords', Aura_Worker_Unbind::leftovers() );
		$this->assertNotContains( 'token', $GLOBALS['_unbind_trace'], 'a `final` call still stops short of the token' );
		// Round-1 I1: ONLY step (5) is gated. Steps (1)-(4a) are best-effort and
		// all attempted — a credential the host will never let go of must not
		// pin the departed client's ruleset, dashboard url, gateway key and
		// APPROVAL QUEUE (Ruling P44) on the site forever, which is what an
		// abort here would do (maybe_finish() would re-enter the same abort
		// every 300 s).
		$this->assertSame( array( 'revoke', 'options', 'ruleset', 'grant', 'door' ), $GLOBALS['_unbind_trace'] );
		$this->assertFalse( sa_app_password_exists( 3, 'uuid-managed' ), 'the rest of step (1) ran' );
		$this->assertFalse( get_option( 'aura_worker_dashboard_url' ), 'step (2) ran anyway' );
		$this->assertFalse( get_option( 'aura_worker_connect_user_id' ), 'step (2) ran anyway' );
		$this->assertNull( Aura_Worker_Rules::stored_uncached(), 'step (3) ran anyway' );
		$this->assertFalse( get_option( 'aura_worker_grant_pubkey' ), 'step (4) ran anyway' );
		$this->assertSame( array( 'app_passwords' ), Aura_Worker_Unbind::leftovers(), 'and nothing else is owed' );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	/**
	 * The same gate, failed at a DIFFERENT step: a claim-conditional delete
	 * that the database refuses. Step (1) succeeded, so this pins the check
	 * on the WHOLE of (1)-(4) rather than on the revoke alone.
	 */
	public function test_a_refused_option_delete_reports_incomplete_and_keeps_the_token(): void {
		$GLOBALS['_sa_option_delete_fail']['aura_worker_dashboard_url'] = true;
		$fence = Aura_Worker_Magic_Link::claim_site();
		$this->assertFalse( Aura_Worker_Unbind::cleanup( true, $fence ) );
		$this->assertContains( 'options', Aura_Worker_Unbind::leftovers() );
		$this->assertFalse( sa_app_password_exists( 3, 'uuid-managed' ), 'the earlier step still ran' );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ) );
		$this->assertNotContains( 'token', $GLOBALS['_unbind_trace'] );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	/**
	 * The token delete is PROVEN by an uncached re-read, never inferred from
	 * the statement's return: a DELETE that reports rows while the row
	 * survives (a replication lag, a trigger) must not be reported to Aura as
	 * a completed teardown, or the fleet retires a connection whose site can
	 * still be reached with the token it kept.
	 */
	public function test_a_token_delete_that_does_not_land_reports_incomplete(): void {
		$GLOBALS['_sa_option_delete_fail']['aura_worker_site_token'] = true;
		$fence = Aura_Worker_Magic_Link::claim_site();
		$this->assertFalse( Aura_Worker_Unbind::cleanup( true, $fence ) );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ) );
		$this->assertContains( 'token', $GLOBALS['_unbind_trace'], 'it was attempted' );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	public function test_cleanup_is_idempotent(): void {
		$fence = Aura_Worker_Magic_Link::claim_site();
		Aura_Worker_Unbind::cleanup( false, $fence );
		$this->assertFalse( Aura_Worker_Unbind::cleanup( false, $fence ) ); // still incomplete: token kept
		$this->assertTrue( Aura_Worker_Unbind::cleanup( true, $fence ) );
		$this->assertTrue( Aura_Worker_Unbind::cleanup( true, $fence ) );  // nothing left, still true
		$this->assertTrue( sa_app_password_exists( 3, 'uuid-unrelated' ) );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	public function test_cleanup_without_the_site_claim_removes_nothing(): void {
		$this->assertFalse( Aura_Worker_Unbind::cleanup( true, '' ) );
		$this->assertFalse( Aura_Worker_Unbind::cleanup( true, 'not-the-fence' ) );
		$fence = Aura_Worker_Magic_Link::claim_site();
		Aura_Worker_Magic_Link::release_site( $fence );
		$this->assertFalse( Aura_Worker_Unbind::cleanup( true, $fence ), 'a released fence holds nothing' );
		$this->assertTrue( sa_app_password_exists( 3, 'uuid-managed' ) );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ) );
		$this->assertSame( array(), $GLOBALS['_unbind_trace'] );
	}

	public function test_cleanup_with_no_marker_removes_nothing(): void {
		sa_clear_marker();
		$fence = Aura_Worker_Magic_Link::claim_site();
		$this->assertFalse( Aura_Worker_Unbind::cleanup( true, $fence ) );
		$this->assertTrue( sa_app_password_exists( 3, 'uuid-managed' ) );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ) );
		$this->assertSame( array(), $GLOBALS['_unbind_trace'] );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	/**
	 * Controller ruling 3. An UNREADABLE marker is not "no marker" and not "a
	 * marker naming nothing": read() is tri-state precisely so a database blip
	 * cannot be mistaken for either. Phase B must do NOTHING — least of all
	 * delete the token — on a read it could not complete.
	 */
	public function test_cleanup_fails_closed_when_the_marker_is_unreadable(): void {
		$fence = Aura_Worker_Magic_Link::claim_site();
		$GLOBALS['_sa_option_read_fail'][ Aura_Worker_Unbind::OPTION ] = true;

		$done = Aura_Worker_Unbind::cleanup( true, $fence );

		$GLOBALS['_sa_option_read_fail'] = array();
		$this->assertFalse( $done );
		$this->assertTrue( sa_app_password_exists( 3, 'uuid-managed' ) );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ) );
		$this->assertNotFalse( get_option( 'aura_worker_grant_pubkey' ) );
		$this->assertSame( array(), $GLOBALS['_unbind_trace'], 'not one step ran' );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	/**
	 * leftovers() answers the same way, for the same reason: "I could not read
	 * the marker" must never be reported as "nothing is pending". Anything
	 * that consumes it (this class's own gate on step (5), Task 9's teardown)
	 * would otherwise read an unreadable site as a finished one.
	 */
	public function test_leftovers_fails_closed_when_the_marker_is_unreadable(): void {
		$GLOBALS['_sa_option_read_fail'][ Aura_Worker_Unbind::OPTION ] = true;

		$left = Aura_Worker_Unbind::leftovers();

		$GLOBALS['_sa_option_read_fail'] = array();
		$this->assertSame( array( 'app_passwords', 'options', 'ruleset', 'grant_pubkey', 'door' ), $left );
	}

	public function test_leftovers_names_every_pending_step_and_nothing_else(): void {
		$this->assertSame( array( 'app_passwords', 'options', 'ruleset', 'grant_pubkey' ), Aura_Worker_Unbind::leftovers() );
	}

	public function test_leftovers_is_empty_with_no_marker(): void {
		sa_clear_marker();
		$this->assertSame( array(), Aura_Worker_Unbind::leftovers() );
	}

	/**
	 * A uuid the marker names but whose owner it does not: Phase B does NOT
	 * fall back to the connecting user, and does not search — that resolution
	 * moved to Phase A (round 3), where the request still knows who
	 * authenticated. Here the marker simply says "unknown", so nothing is
	 * revoked, nothing is proven, and the teardown stops short of the token.
	 */
	public function test_a_uuid_with_no_recorded_owner_is_never_proven_gone(): void {
		sa_set_marker(
			array(
				'site'               => sa_token_hash(),
				'connect_user_id'    => 3,                                 // even though the connector DOES hold it
				'app_password_uuids' => array( 'uuid-manual' ),
				'app_password_users' => array(),
			)
		);
		$fence = Aura_Worker_Magic_Link::claim_site();

		$this->assertFalse( Aura_Worker_Unbind::cleanup( true, $fence ) );

		$this->assertSame( array( 'app_passwords' ), Aura_Worker_Unbind::leftovers() );
		$this->assertTrue( sa_app_password_exists( 3, 'uuid-manual' ), 'nothing is deleted from a user the marker did not name' );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ) );
		$this->assertNotContains( 'token', $GLOBALS['_unbind_trace'] );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	/**
	 * Round-1 C1, restated for the round-3 mechanism: `0` is not a user id. It
	 * is what an earlier build recorded for a managed row whose `user_id` half
	 * never landed, so read() normalises it to the explicit unknown it always
	 * was — and an unknown is never reported clean. Reported clean, the gate
	 * would open and step (5) would delete the token while a live
	 * administrator Application Password remained, with no token left for any
	 * retry to be matched to the marker.
	 */
	public function test_a_uuid_whose_owner_cannot_be_resolved_is_never_reported_clean(): void {
		sa_set_marker(
			array(
				'site'               => sa_token_hash(),
				'connect_user_id'    => 0,
				'app_password_uuids' => array( 'uuid-managed' ),
				'app_password_users' => array( 'uuid-managed' => 0 ),      // the half-written record's shape
			)
		);
		$this->assertNull( Aura_Worker_Unbind::read()['app_password_users']['uuid-managed'], '0 reads as unknown, never as a user' );
		$fence = Aura_Worker_Magic_Link::claim_site();

		$this->assertFalse( Aura_Worker_Unbind::cleanup( true, $fence ) );

		$this->assertContains( 'app_passwords', Aura_Worker_Unbind::leftovers() );
		$this->assertTrue( sa_app_password_exists( 3, 'uuid-managed' ), 'the credential is still live — which is the point' );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ), 'so the token must survive' );
		$this->assertNotContains( 'token', $GLOBALS['_unbind_trace'] );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	/**
	 * Round-3 C3. The holder here has `manage_options` through a CUSTOM role,
	 * so no administrator-role query would ever have found him — which is how
	 * the round-2 search declared a live credential conclusively absent and
	 * deleted the token. Phase B no longer searches at all: an owner the
	 * marker does not name is simply never proven gone, whoever holds it and
	 * whatever roles the site has.
	 */
	public function test_an_unknown_owner_blocks_the_teardown_whoever_actually_holds_it(): void {
		$GLOBALS['_app_passwords'] = array();
		sa_add_app_password( 11, 'uuid-managed' );                          // a custom admin-equivalent role
		sa_set_marker(
			array(
				'site'               => sa_token_hash(),
				'connect_user_id'    => 3,
				'app_password_uuids' => array( 'uuid-managed' ),
				'app_password_users' => array( 'uuid-managed' => null ),
			)
		);
		$fence = Aura_Worker_Magic_Link::claim_site();

		$this->assertFalse( Aura_Worker_Unbind::cleanup( true, $fence ) );

		$this->assertContains( 'app_passwords', Aura_Worker_Unbind::leftovers() );
		$this->assertTrue( sa_app_password_exists( 11, 'uuid-managed' ) );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ) );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	/**
	 * A KNOWN owner is decisive in both directions. An Application Password
	 * lives in exactly one user's meta, so the one lookup at the recorded
	 * owner both finds it (revoke it there) and, once it is gone, proves it.
	 */
	public function test_a_recorded_owner_is_revoked_and_then_proven_gone(): void {
		$GLOBALS['_app_passwords'] = array();
		sa_add_app_password( 7, 'uuid-managed' );
		sa_set_marker(
			array(
				'site'               => sa_token_hash(),
				'connect_user_id'    => 3,                                 // NOT the owner, and never consulted
				'app_password_uuids' => array( 'uuid-managed' ),
				'app_password_users' => array( 'uuid-managed' => 7 ),
			)
		);
		$fence = Aura_Worker_Magic_Link::claim_site();

		$this->assertTrue( Aura_Worker_Unbind::cleanup( true, $fence ) );

		$this->assertFalse( sa_app_password_exists( 7, 'uuid-managed' ), 'revoked at the recorded owner' );
		$this->assertSame( array(), Aura_Worker_Unbind::leftovers() );
		$this->assertFalse( get_option( 'aura_worker_site_token' ) );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	/**
	 * The same, with the revoke refused: never a deleted token beside a live
	 * credential. This is the pairing every round of this finding is about.
	 */
	public function test_a_recorded_owners_password_that_cannot_be_revoked_keeps_the_token(): void {
		$GLOBALS['_app_passwords'] = array();
		sa_add_app_password( 7, 'uuid-managed' );
		$GLOBALS['_fail_delete_app_password'] = 'uuid-managed';
		sa_set_marker(
			array(
				'site'               => sa_token_hash(),
				'connect_user_id'    => 3,
				'app_password_uuids' => array( 'uuid-managed' ),
				'app_password_users' => array( 'uuid-managed' => 7 ),
			)
		);
		$fence = Aura_Worker_Magic_Link::claim_site();

		$this->assertFalse( Aura_Worker_Unbind::cleanup( true, $fence ) );

		$this->assertTrue( sa_app_password_exists( 7, 'uuid-managed' ) );
		$this->assertContains( 'app_passwords', Aura_Worker_Unbind::leftovers() );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ) );
		$this->assertNotContains( 'token', $GLOBALS['_unbind_trace'] );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	/**
	 * Round-2 I2: the blocking state must CONVERGE for an owner the site
	 * knows. §2.3 tells the operator to revoke the credential by hand in
	 * Users -> Profile; once they have, the next sweep's single lookup finds
	 * nothing and the teardown finishes.
	 */
	public function test_a_hand_revoked_password_lets_the_teardown_converge(): void {
		$GLOBALS['_app_passwords'] = array();
		sa_add_app_password( 7, 'uuid-managed' );
		$GLOBALS['_fail_delete_app_password'] = 'uuid-managed';            // Phase B cannot revoke it
		sa_set_marker(
			array(
				'site'               => sa_token_hash(),
				'connect_user_id'    => 3,
				'app_password_uuids' => array( 'uuid-managed' ),
				'app_password_users' => array( 'uuid-managed' => 7 ),
			)
		);
		$fence = Aura_Worker_Magic_Link::claim_site();
		$this->assertFalse( Aura_Worker_Unbind::cleanup( true, $fence ), 'stuck while the credential is live' );

		$GLOBALS['_app_passwords'][7] = array();                           // the operator revokes it by hand

		$this->assertSame( array(), Aura_Worker_Unbind::leftovers(), 'nothing is owed any more' );
		$this->assertTrue( Aura_Worker_Unbind::cleanup( true, $fence ), 'and the teardown completes' );
		$this->assertFalse( get_option( 'aura_worker_site_token' ) );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	/**
	 * Round-1 M3. A ruleset row that no longer parses as a record is still a
	 * row: stored_uncached() maps it to null, so accounting on that would
	 * report step (3) complete while the row survived the teardown.
	 */
	public function test_a_malformed_ruleset_row_is_still_a_leftover_and_is_cleared(): void {
		$GLOBALS['_rows'][ Aura_Worker_Rules::OPTION ] = 'not-a-record';
		unset( $GLOBALS['_options'][ Aura_Worker_Rules::OPTION ] );
		$this->assertNull( Aura_Worker_Rules::stored_uncached(), 'the row does not parse — the premise of the bug' );

		$this->assertContains( 'ruleset', Aura_Worker_Unbind::leftovers() );

		$fence = Aura_Worker_Magic_Link::claim_site();
		$this->assertTrue( Aura_Worker_Unbind::cleanup( true, $fence ) );
		$this->assertNull( Aura_Worker_Rules::read_option_uncached( Aura_Worker_Rules::OPTION ), 'the row itself is gone' );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	// -----------------------------------------------------------------------
	// maybe_finish(): (1)-(4) on init, throttled, under the claim, never (5).
	// -----------------------------------------------------------------------

	public function test_maybe_finish_runs_1_to_4_never_5_and_is_throttled(): void {
		Aura_Worker_Unbind::maybe_finish();
		$this->assertSame( array(), Aura_Worker_Unbind::leftovers() );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ) );
		$this->assertNotContains( 'token', $GLOBALS['_unbind_trace'], 'maybe_finish() may never delete the token' );
		$this->assertNotFalse( get_transient( Aura_Worker_Unbind::FINISH_TRANSIENT ) );
		sa_add_app_password( 3, 'uuid-managed' );                          // re-appears; throttled run must not touch it
		Aura_Worker_Unbind::maybe_finish();
		$this->assertTrue( sa_app_password_exists( 3, 'uuid-managed' ) );
	}

	/**
	 * Final round MINOR-2. is_set() is a DELIBERATELY UNCACHED raw read — one
	 * guaranteed query — and it used to be asked BEFORE the throttle, so the
	 * throttle did not throttle the expensive half: an unbound site paid that
	 * query on every page load, front-end, admin and cron alike, forever. The
	 * cheap question is asked first now, and this is what says so: a throttled
	 * sweep must issue no statement against the marker row at all.
	 */
	public function test_a_throttled_sweep_does_not_pay_the_uncached_marker_read(): void {
		set_transient( Aura_Worker_Unbind::FINISH_TRANSIENT, 1, Aura_Worker_Unbind::FINISH_THROTTLE );
		$GLOBALS['_db_queries'] = array();

		Aura_Worker_Unbind::maybe_finish();

		$marker_statements = array_values(
			array_filter(
				$GLOBALS['_db_queries'],
				static function ( $q ) {
					return false !== strpos( (string) $q, Aura_Worker_Unbind::OPTION );
				}
			)
		);
		$this->assertSame( array(), $marker_statements, 'the throttle is consulted before the raw read, not after' );
		$this->assertTrue( sa_app_password_exists( 3, 'uuid-managed' ), 'and the throttled sweep still did nothing' );
	}

	/**
	 * The other half of that reordering, and the property it must not break:
	 * asking the throttle first cannot skip a finish that is DUE. The throttle
	 * is armed only on the path where the marker is set, so a site that is not
	 * unbound never arms it — and a Phase A landing a moment later is swept on
	 * the very next request, not after FINISH_THROTTLE seconds.
	 */
	public function test_a_sweep_that_finds_no_marker_does_not_arm_the_throttle(): void {
		sa_clear_marker();

		Aura_Worker_Unbind::maybe_finish();

		$this->assertFalse( get_transient( Aura_Worker_Unbind::FINISH_TRANSIENT ), 'a bound site must not arm the throttle a MARKED site uses' );
		$this->assertNotFalse( get_transient( Aura_Worker_Unbind::ABSENT_TRANSIENT ), 'and it must not pay the uncached read again on the very next request' );

		sa_set_marker(
			array(
				'site'               => sa_token_hash(),
				'seq'                => 9,
				'connect_user_id'    => 3,
				'app_password_uuids' => array( 'uuid-managed' ),
				'app_password_users' => array( 'uuid-managed' => 3 ),
			)
		);
		Aura_Worker_Unbind::maybe_finish();

		$this->assertFalse( sa_app_password_exists( 3, 'uuid-managed' ), 'the finish that became due ran at once' );
	}

	/**
	 * THE NEGATIVE THROTTLE IS CLEARED BY THE WRITE THAT INVALIDATES IT
	 * (Codex round-11 P2). A bound site stops paying is_set()'s uncached query
	 * on every request — but a Phase A landing a moment later must still heal
	 * on the request that follows it, not after the TTL.
	 */
	public function test_phase_a_clears_the_negative_throttle(): void {
		sa_clear_marker();
		Aura_Worker_Unbind::maybe_finish();
		$this->assertNotFalse( get_transient( Aura_Worker_Unbind::ABSENT_TRANSIENT ) );

		$fence = Aura_Worker_Magic_Link::claim_site();
		$this->assertTrue(
			Aura_Worker_Unbind::write_under_claim(
				array(
					'at'                 => '2026-08-29T10:00:00Z',
					'site'               => sa_token_hash(),
					'site_ref'           => 'res1',
					'client'             => 'c1',
					'seq'                => 9,
					'connect_user_id'    => 3,
					'app_password_uuids' => array(),
					'app_password_users' => array(),
				),
				$fence
			)
		);
		Aura_Worker_Magic_Link::release_site( $fence );

		$this->assertFalse( get_transient( Aura_Worker_Unbind::ABSENT_TRANSIENT ), 'the site is no longer one with no marker' );
	}

	/**
	 * A write that did NOT LAND leaves the negative alone — nothing changed, so
	 * nothing is invalidated. The claim-fenced statement reports success while
	 * the row diverges (_sa_option_write_divert), which is the only seam that
	 * reaches the read-back at all: a foreign fence is refused several guards
	 * earlier and would prove nothing about this branch.
	 */
	public function test_a_phase_a_that_did_not_land_leaves_the_negative_throttle_alone(): void {
		sa_clear_marker();
		Aura_Worker_Unbind::maybe_finish();
		$this->assertNotFalse( get_transient( Aura_Worker_Unbind::ABSENT_TRANSIENT ) );

		$fence = Aura_Worker_Magic_Link::claim_site();
		$GLOBALS['_sa_option_write_divert'][ Aura_Worker_Unbind::OPTION ] = true;

		$this->assertFalse(
			Aura_Worker_Unbind::write_under_claim(
				array(
					'at'                 => '2026-08-29T10:00:00Z',
					'site'               => sa_token_hash(),
					'site_ref'           => 'res1',
					'client'             => 'c1',
					'seq'                => 9,
					'connect_user_id'    => 3,
					'app_password_uuids' => array(),
					'app_password_users' => array(),
				),
				$fence
			)
		);

		$GLOBALS['_sa_option_write_divert'] = array();
		Aura_Worker_Magic_Link::release_site( $fence );
		$this->assertNotFalse( get_transient( Aura_Worker_Unbind::ABSENT_TRANSIENT ), 'no marker landed, so nothing was invalidated' );
	}

	public function test_maybe_finish_releases_the_claim(): void {
		Aura_Worker_Unbind::maybe_finish();
		$this->assertSame( '', (string) get_option( Aura_Worker_Magic_Link::SITE_CLAIM, '' ) );
	}

	public function test_maybe_finish_stops_when_the_token_hash_differs_from_the_marker(): void {
		update_option( 'aura_worker_site_token', str_repeat( 'd', 64 ) );  // a reconnect rotated the token but (test) left the marker
		$GLOBALS['_rows']['aura_worker_site_token'] = str_repeat( 'd', 64 );
		Aura_Worker_Unbind::maybe_finish();
		$this->assertTrue( sa_app_password_exists( 3, 'uuid-managed' ), 'nothing removed: the marker is not this binding' );
		$this->assertNotFalse( get_option( 'aura_worker_grant_pubkey' ) );
	}

	public function test_maybe_finish_proceeds_when_the_token_is_absent(): void {
		delete_option( 'aura_worker_site_token' );
		unset( $GLOBALS['_rows']['aura_worker_site_token'] );
		Aura_Worker_Unbind::maybe_finish();
		$this->assertFalse( sa_app_password_exists( 3, 'uuid-managed' ) );
	}

	public function test_maybe_finish_cannot_run_while_a_connect_holds_the_claim(): void {
		$fence = Aura_Worker_Magic_Link::claim_site();
		Aura_Worker_Unbind::maybe_finish();
		$this->assertTrue( sa_app_password_exists( 3, 'uuid-managed' ) );
		$this->assertSame( array(), $GLOBALS['_unbind_trace'] );
		$this->assertTrue( Aura_Worker_Magic_Link::holds_site_claim( $fence ), 'the connect still holds it — the sweep never seized it' );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	public function test_maybe_finish_with_no_marker_deletes_nothing(): void {
		sa_clear_marker();
		Aura_Worker_Unbind::maybe_finish();
		$this->assertTrue( sa_app_password_exists( 3, 'uuid-managed' ) );
		// stored_uncached(), not current(): current() maps the connect's seq-0
		// sentinel to null, so it would read as "cleared" whether or not the
		// row is still there. The ROW is what step (3) removes.
		$this->assertNotNull( Aura_Worker_Rules::stored_uncached() );
	}

	/**
	 * is_set() fails OPEN by design (an unreadable marker reads as "unbound"),
	 * so maybe_finish() cannot stop there — it re-reads and stops on the
	 * WP_Error itself. Without that, a database blip would send the sweep into
	 * a cleanup for a binding it cannot identify.
	 */
	public function test_maybe_finish_fails_closed_when_the_marker_is_unreadable(): void {
		$GLOBALS['_sa_option_read_fail'][ Aura_Worker_Unbind::OPTION ] = true;

		Aura_Worker_Unbind::maybe_finish();

		$GLOBALS['_sa_option_read_fail'] = array();
		$this->assertTrue( sa_app_password_exists( 3, 'uuid-managed' ) );
		$this->assertNotFalse( get_option( 'aura_worker_grant_pubkey' ) );
		$this->assertSame( array(), $GLOBALS['_unbind_trace'] );
		$this->assertSame( '', (string) get_option( Aura_Worker_Magic_Link::SITE_CLAIM, '' ), 'and the claim is still released' );
	}

	/**
	 * A token read that fails is not "no token": it must stop the sweep the
	 * same way an unreadable marker does, or a blip at exactly this moment
	 * would let the sweep act on a binding it could not confirm is this one.
	 */
	public function test_maybe_finish_stops_when_the_token_read_fails(): void {
		$GLOBALS['_sa_option_read_fail']['aura_worker_site_token'] = true;

		Aura_Worker_Unbind::maybe_finish();

		$GLOBALS['_sa_option_read_fail'] = array();
		$this->assertTrue( sa_app_password_exists( 3, 'uuid-managed' ) );
		$this->assertSame( array(), $GLOBALS['_unbind_trace'] );
	}

	// -----------------------------------------------------------------------
	// Round 4, I5 — "gone" may never be answered from a read that failed.
	// -----------------------------------------------------------------------

	/**
	 * The last negative proof in Phase B. Core implements
	 * WP_Application_Passwords::get_user_application_passwords() as a
	 * get_user_meta() followed by `if ( ! is_array( $passwords ) ) return
	 * array();`, so a meta read that could not be completed is
	 * INDISTINGUISHABLE there from "this user holds none" — and round 3 made
	 * that the SOLE evidence gating step (5). With the owner's list
	 * unreadable, `leftovers()` must name the credential rather than report a
	 * clean site: this is `option_absent()`'s discipline, applied to a
	 * credential instead of a row.
	 */
	public function test_an_owners_unreadable_password_list_is_never_proof_of_absence(): void {
		$GLOBALS['_sa_app_password_read_fail'][3] = true; // that user's meta row will not read

		$left = Aura_Worker_Unbind::leftovers();

		$GLOBALS['_sa_app_password_read_fail'] = array();
		$this->assertContains( 'app_passwords', $left, 'a read that failed is not evidence the credential is gone' );
	}

	/**
	 * And the whole irreversible step turns on it: `final: true`, everything
	 * else removable, the recorded owner known — and the one thing that cannot
	 * be READ is the credential itself. The token must survive.
	 */
	public function test_an_unreadable_password_list_stops_the_teardown_short_of_the_token(): void {
		$fence                                    = Aura_Worker_Magic_Link::claim_site();
		$GLOBALS['_sa_app_password_read_fail'][3] = true;

		$done = Aura_Worker_Unbind::cleanup( true, $fence );

		$GLOBALS['_sa_app_password_read_fail'] = array();
		Aura_Worker_Magic_Link::release_site( $fence );
		$this->assertFalse( $done );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ), 'the token outlives a proof that could not be made' );
		$this->assertNotContains( 'token', $GLOBALS['_unbind_trace'] );
	}

	/**
	 * The tri-state itself, at the one implementation every caller shares.
	 * A boolean cannot carry three answers, and each of this function's
	 * callers decides which way it leans for itself.
	 */
	public function test_password_state_tells_present_gone_and_unreadable_apart(): void {
		$this->assertSame( 'present', Aura_Worker_Magic_Link::password_state( 3, 'uuid-manual' ) );
		$this->assertSame( 'gone', Aura_Worker_Magic_Link::password_state( 3, 'uuid-never-existed' ) );
		$this->assertSame( 'gone', Aura_Worker_Magic_Link::password_state( 4, 'uuid-manual' ), 'a user with no row at all holds nothing' );

		$GLOBALS['_sa_app_password_read_fail'][3] = true;
		$state                                    = Aura_Worker_Magic_Link::password_state( 3, 'uuid-manual' );
		$GLOBALS['_sa_app_password_read_fail']    = array();

		$this->assertSame( 'unknown', $state );
		$this->assertTrue( Aura_Worker_Magic_Link::password_gone( 3, 'uuid-never-existed' ), 'a genuine absence still reads as gone' );
	}

	/**
	 * The confirming read needs a database handle to make its confirmation
	 * with. Without one it has proved nothing — which is 'unknown', not
	 * 'gone'. (A cheap guard, but it is the branch that runs on the platforms
	 * this code has least visibility into.)
	 */
	public function test_a_missing_database_handle_is_never_proof_of_absence(): void {
		$real            = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new stdClass(); // no usermeta table, no get_var
		try {
			$state = Aura_Worker_Magic_Link::password_state( 3, 'uuid-never-existed' );
		} finally {
			$GLOBALS['wpdb'] = $real;
		}
		$this->assertSame( 'unknown', $state );
	}

	/** Every `aura_worker_app_password_probe_unproven` fired so far this test. */
	private function probe_unproven(): array {
		return array_values(
			array_filter(
				$GLOBALS['_did_actions'],
				static function ( $a ) {
					return 'aura_worker_app_password_probe_unproven' === $a['tag'];
				}
			)
		);
	}

	/**
	 * The nonce's job is FRESHNESS, not secrecy — it is echoed back in the
	 * same statement and is never confidential. So the only property that
	 * matters is that two probes in one request cannot agree, and the
	 * randomiser alone cannot promise that: since WP 7.0 wp_generate_uuid4()
	 * draws from wp_rand(), which is pluggable and loads AFTER plugins, so a
	 * third party can pin it. A pinned uuid would put us back where M12 began
	 * — a constant nonce always matches, so the proof degenerates to "we got
	 * a row", which is exactly what a stale result set provides. The
	 * function-local counter is what nothing outside can make repeat. (#434 N4)
	 */
	public function test_a_pinned_randomiser_cannot_make_two_probes_share_a_nonce(): void {
		$GLOBALS['_sa_uuid_fixed'] = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
		$GLOBALS['_db_queries']    = array();

		// User 4 holds nothing, so core's list cannot short-circuit and the
		// confirming probe — the only path that issues the statement — runs.
		Aura_Worker_Magic_Link::password_state( 4, 'uuid-manual' );
		Aura_Worker_Magic_Link::password_state( 4, 'uuid-manual' );

		$probes = array();
		foreach ( $GLOBALS['_db_queries'] as $q ) {
			if ( preg_match( "/^SELECT '([^']*)' AS probe,/", (string) $q, $m ) ) {
				$probes[] = $m[1];
			}
		}
		$this->assertCount( 2, $probes, 'both probes issued their own statement' );
		$this->assertNotSame( $probes[0], $probes[1], 'a host that pinned the randomiser still cannot make one probe answer for another' );
	}

	/**
	 * The breadcrumb exists so an eternally pending tombstone can be
	 * explained, and it travels wherever the site sends diagnostics — so it
	 * carries the owner and nothing else. Naming the nonce, the uuid or the
	 * token here would leak a credential identifier into any listener. (#434 N5)
	 */
	public function test_the_breadcrumb_names_the_owner_and_carries_no_secret(): void {
		$GLOBALS['_sa_app_password_read_fail'][3] = true;
		$GLOBALS['_sa_wpdb_query_filtered_out']   = true;
		Aura_Worker_Magic_Link::password_state( 3, 'uuid-manual' );
		$GLOBALS['_sa_wpdb_query_filtered_out']   = false;
		$GLOBALS['_sa_app_password_read_fail']    = array();

		$fired = $this->probe_unproven();
		$this->assertCount( 1, $fired );
		$this->assertSame( array( 3 ), $fired[0]['args'], 'the owner, and only the owner' );
		$this->assertStringNotContainsString( 'uuid-manual', wp_json_encode( $fired[0]['args'] ), 'no credential identifier rides the breadcrumb' );
	}

	/**
	 * A handle that has declared itself not ready runs nothing: wpdb::query()
	 * returns at its first line, before flush(), and wpdb::get_row() ignores
	 * that return value and extracts its answer from the PREVIOUS statement's
	 * last_result. Here that previous statement is another probe — of a user
	 * who really holds nothing — so the stale answer is a well-formed probe
	 * row whose `v` is null: exactly the shape a false proof of absence needs.
	 * The only thing that tells it apart from this call's own answer is the
	 * nonce it does not carry. (#434 M12/N1)
	 *
	 * The readiness check this test used to pin is GONE, and had to be: a
	 * db.php drop-in that extends wpdb and never calls parent::__construct()
	 * — HyperDB, LudicrousDB — inherits `public $ready = false` and keeps it
	 * for the life of the request, so reading $ready stranded every such site
	 * mid-unbind forever. The nonce needs no cooperation from the handle.
	 */
	public function test_an_unready_database_handle_is_never_asked_and_never_proves_absence(): void {
		$wpdb = $GLOBALS['wpdb'];
		$this->assertSame( 'gone', Aura_Worker_Magic_Link::password_state( 4, 'uuid-manual' ), 'a real statement, leaving its own empty result behind' );

		// User 3 really holds uuid-manual; core's cached list cannot be read,
		// so the confirming probe is what decides. The handle then refuses it.
		$GLOBALS['_sa_app_password_read_fail'][3] = true;
		$wpdb->ready                              = false;
		$state                                    = Aura_Worker_Magic_Link::password_state( 3, 'uuid-manual' );
		$wpdb->ready                              = true;
		$GLOBALS['_sa_app_password_read_fail']    = array();

		$this->assertSame( 'unknown', $state, 'a handle that refuses statements proves nothing, and user 3 really holds uuid-manual' );
		$this->assertCount( 1, $this->probe_unproven() );
	}

	/**
	 * The other early return in wpdb::query(): a `query` filter that blanks
	 * the SQL. The statement never runs and get_row() hands back the row of
	 * whatever ran last — here another USER's probe, answering null. Reading
	 * that as "user 3's live administrator credential is gone" is the whole of
	 * #434's Critical family, arriving through the one door left open; the
	 * nonce that row does not carry is what closes it. (#434 M12/N1)
	 */
	public function test_a_statement_that_never_ran_is_never_proof_of_absence(): void {
		$this->assertSame( 'gone', Aura_Worker_Magic_Link::password_state( 4, 'uuid-manual' ), 'user 4 has no row: the previous statement, and its empty answer' );

		// User 3 really holds uuid-manual; core's cached list cannot be read,
		// so the confirming probe is what decides.
		$GLOBALS['_sa_app_password_read_fail'][3] = true;
		$GLOBALS['_sa_wpdb_query_filtered_out']   = true;
		$state                                    = Aura_Worker_Magic_Link::password_state( 3, 'uuid-manual' );
		$GLOBALS['_sa_wpdb_query_filtered_out']   = false;
		$GLOBALS['_sa_app_password_read_fail']    = array();

		$this->assertSame( 'unknown', $state, 'the answer came from another query and is no answer at all' );
		$this->assertCount( 1, $this->probe_unproven(), 'a probe that cannot prove itself says so: an eternally pending tombstone must be diagnosable' );
	}

	/**
	 * The other half of the in-band proof, and a different failure entirely:
	 * the statement ran and came back with NOTHING — a dead connection, a
	 * driver-level error, a handle that answered false. wpdb has no result set
	 * to extract from, so get_row() answers null. Null is not a row, so it
	 * carries no nonce, so it proves nothing — where the round-5 probe read
	 * exactly this shape as "no meta row, therefore no passwords". (#434 N1)
	 */
	public function test_a_probe_that_came_back_with_no_row_is_never_proof_of_absence(): void {
		// User 3 really holds uuid-manual, and core's cached list cannot be
		// read either, so this probe is the only thing deciding.
		$GLOBALS['_sa_app_password_read_fail'][3] = true;

		$state = Aura_Worker_Magic_Link::password_state( 3, 'uuid-manual' );

		$GLOBALS['_sa_app_password_read_fail'] = array();
		$this->assertSame( 'unknown', $state, 'no row is no answer' );
		$this->assertCount( 1, $this->probe_unproven() );
	}

	/**
	 * And a statement that could not be BUILT is never issued. core's
	 * prepare() answers null when it refuses the call; get_row( null ) then
	 * answers null and the nonce would reject that answer anyway, so this
	 * guard changes no outcome — it is the earlier, cheaper refusal, and what
	 * it pins is that nothing is asked at all. That is why the assertion below
	 * is on the breadcrumb: "we never issued a statement" and "we issued one
	 * and disbelieved its answer" are both `unknown`, and the breadcrumb is
	 * the only thing that tells them apart. Stated plainly rather than dressed
	 * up as a safety outcome it is not (#434 N3, and N2's lesson).
	 */
	public function test_a_statement_that_could_not_be_prepared_is_never_issued(): void {
		$GLOBALS['_sa_app_password_read_fail'][3] = true; // the probe is what decides
		$GLOBALS['_sa_wpdb_prepare_null']         = true; // prepare() refuses the call
		$state                                    = Aura_Worker_Magic_Link::password_state( 3, 'uuid-manual' );
		$GLOBALS['_sa_wpdb_prepare_null']         = false;
		$GLOBALS['_sa_app_password_read_fail']    = array();

		$this->assertSame( 'unknown', $state, 'nothing was issued, so nothing was proved' );
		$this->assertSame( array(), $this->probe_unproven(), 'and no statement was issued to disbelieve' );
	}

	/**
	 * password_gone() answers PROVEN gone and nothing else: the boolean form
	 * every fail-closed caller in the plugin reads.
	 */
	public function test_password_gone_is_false_for_a_list_that_could_not_be_read(): void {
		$GLOBALS['_sa_app_password_read_fail'][3] = true;

		$gone = Aura_Worker_Magic_Link::password_gone( 3, 'uuid-manual' );

		$GLOBALS['_sa_app_password_read_fail'] = array();
		$this->assertFalse( $gone );
	}

	/**
	 * The sweep only runs because something hooks it. Deleting the
	 * registration, renaming the hook, or dropping it to a priority that never
	 * fires would leave every test above green and no site would ever finish
	 * its cleanup on its own.
	 */
	public function test_the_plugin_registers_maybe_finish_on_init(): void {
		( new Aura_Worker() )->init();

		$entries = $GLOBALS['_filters']['init'] ?? array();
		$found   = array_values(
			array_filter(
				$entries,
				static function ( $entry ) {
					return isset( $entry['callback'] ) && array( 'Aura_Worker_Unbind', 'maybe_finish' ) === $entry['callback'];
				}
			)
		);
		$this->assertCount( 1, $found, 'registered exactly once, on the hook WordPress fires' );
		$this->assertSame( 10, $found[0]['priority'] );
	}

	// -----------------------------------------------------------------------
	// Ruling P44: the unbind takes the Elementor door's queue and log with it
	// -----------------------------------------------------------------------

	/**
	 * Seed a full door: a held row, a claimed row, three log rows, the ack
	 * floor, the closure marker + its counter, the epoch — plus a 30-day
	 * counter bucket and a snapshot envelope, which must SURVIVE.
	 *
	 * @return array The refs and names the assertions read back.
	 */
	private function seedDoor(): array {
		foreach ( array( 'class-aura-worker-door-log', 'class-aura-worker-door-holds', 'class-elementor-door-governor' ) as $f ) {
			require_once dirname( __DIR__, 2 ) . '/digitizer-site-worker/includes/' . $f . '.php';
		}
		$call = array(
			'ability' => 'elementor/publish-document',
			'input'   => array( 'post_id' => 7 ),
			'touches' => array( array( 'type' => 'page', 'id' => '7' ) ),
			'actor'   => array( 'user_id' => 3, 'login' => 'bot' ),
			'verdict' => 'none',
		);
		$held    = Aura_Worker_Door_Holds::hold( $call );
		$claimed = Aura_Worker_Door_Holds::hold( $call );
		Aura_Worker_Door_Holds::claim( $claimed );
		// A DIED replay, not a live one: a claim younger than CLAIM_STALE_MS
		// stops the wipe outright (Ruling P50), which is its own test below.
		$this->ageClaim( $claimed );
		for ( $i = 0; $i < 3; $i++ ) {
			$seq = Aura_Worker_Door_Log::open_pending( $call );
			Aura_Worker_Door_Log::settle( $seq, array( 'result' => 'ok' ) );
		}
		Aura_Worker_Door_Log::ack( Aura_Worker_Door_Log::epoch(), 1 ); // installs the floor
		Aura_Worker_Door_Log::close();                                 // the full marker
		Aura_Worker_Door_Log::bump_refused();                          // and its counter
		// The binding's working state too (Ruling P44 follow-up): a fresh
		// binding must inherit neither the creation mutex nor the retention
		// throttle.
		update_option( Aura_Worker_Elementor_Door::CREATING, array( 'seq' => 1, 'started_at' => gmdate( 'c' ) ) );
		update_option( Aura_Worker_Elementor_Door::PRUNED_AT, gmdate( 'c' ) );
		Aura_Worker_Door_Log::binding(); // the generation a replay is fenced to (Ruling P51)
		$bucket                       = 'aura_worker_door_c_log_ungoverned_h' . (int) floor( time() / HOUR_IN_SECONDS );
		$GLOBALS['_options'][ $bucket ] = 4;
		$GLOBALS['_rows'][ $bucket ]    = maybe_serialize( 4 );
		$env                          = ( new Aura_Worker_Snapshots() )->snapshot_option( 'blogname' );
		$this->assertTrue( $env['success'] );
		return array(
			'held'    => $held,
			'claimed' => $claimed,
			'bucket'  => $bucket,
			'env'     => (string) $env['snapshot']['id'],
		);
	}

	/**
	 * The door's TRANSACTIONAL rows, from the "database" — everything under
	 * `aura_worker_door_` except the 30-day counter buckets, which share the
	 * prefix and are deliberately kept (Ruling P44).
	 */
	private function doorRows(): array {
		$out = array();
		foreach ( array_unique( array_merge( array_keys( $GLOBALS['_rows'] ), array_keys( $GLOBALS['_options'] ) ) ) as $name ) {
			$name = (string) $name;
			if ( 0 === strpos( $name, 'aura_worker_door_' ) && 0 !== strpos( $name, Aura_Worker_Elementor_Door::COUNTER_PREFIX ) ) {
				$out[] = $name;
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * A hold is a stored WordPress action — an ability, its input, and the
	 * ACTOR to run it as. It used to survive every cleanup step, so a site
	 * later connected to a DIFFERENT Aura client was served the departed
	 * client's holds through `/status` and could approve one through
	 * `elementor_replay_ability`.
	 */
	public function test_cleanup_takes_the_doors_queue_and_log_and_keeps_the_history(): void {
		$door  = $this->seedDoor();
		$this->assertNotEmpty( Aura_Worker_Door_Holds::listing(), 'the queue is there to begin with' );
		$fence = Aura_Worker_Magic_Link::claim_site();

		$this->assertTrue( Aura_Worker_Unbind::cleanup( true, $fence ) );

		$this->assertSame( array(), $this->doorRows(), 'no held, claimed, log, floor, marker, counter, epoch, mutex or throttle row survives' );
		$this->assertFalse( get_option( Aura_Worker_Elementor_Door::CREATING, false ), 'the creation mutex is not inherited' );
		$this->assertFalse( get_option( Aura_Worker_Elementor_Door::PRUNED_AT, false ), 'nor the retention throttle' );
		$this->assertFalse( get_option( Aura_Worker_Door_Log::BINDING, false ), 'nor the binding generation' );
		$this->assertSame( array(), Aura_Worker_Door_Holds::listing() );
		$this->assertNull( Aura_Worker_Door_Holds::get_held( $door['held'] ) );
		$this->assertNull( Aura_Worker_Door_Holds::get_claimed( $door['claimed'] ) );
		$this->assertSame( array(), Aura_Worker_Door_Log::log_after( 0 ) );
		$this->assertSame( 0, Aura_Worker_Door_Log::floor() );
		$this->assertFalse( Aura_Worker_Door_Log::is_closed() );
		$this->assertFalse( Aura_Worker_Elementor_Door::present(), 'and the site reports no door at all' );
		$this->assertNull( Aura_Worker_Elementor_Door::status_fragment( 0, '' ) );

		// KEPT: this SITE's history, not the departed binding's state.
		$this->assertSame( 4, (int) get_option( $door['bucket'], 0 ), 'the 30-day counter bucket survives' );
		$this->assertNotNull( ( new Aura_Worker_Snapshots() )->get( $door['env'] ), 'and so does the snapshot envelope' );
	}

	/** …and the next binding starts a FRESH epoch at cursor 0. */
	public function test_the_next_binding_starts_a_new_epoch_with_an_empty_log(): void {
		$door   = $this->seedDoor();
		$before = Aura_Worker_Door_Log::epoch();
		$fence  = Aura_Worker_Magic_Link::claim_site();
		$this->assertTrue( Aura_Worker_Unbind::cleanup( true, $fence ) );

		$GLOBALS['_sa_force_door'] = true; // the next binding's site has Elementor
		Aura_Worker_Elementor_Door::reset_for_tests();
		$frag = Aura_Worker_Elementor_Door::status_fragment( 0, '' );

		$this->assertIsArray( $frag );
		$this->assertNotSame( $before, $frag['epoch'], 'a fresh epoch, not the departed client\'s' );
		$this->assertSame( array(), $frag['log'] );
		$this->assertSame( array(), $frag['held'] );
		$this->assertSame( 0, $frag['log_floor'] );
		$this->assertSame( 0, $frag['log_unacked'] );
		$this->assertNull( $frag['log_full'] );
	}

	/** Backdate a claimed row past CLAIM_STALE_MS, in the database and the cache alike. */
	private function ageClaim( string $ref, int $extra_s = 60 ): void {
		$name = Aura_Worker_Door_Holds::CLAIMED . $ref;
		$row  = get_option( $name, array() );
		$row['claimed_at']          = gmdate( 'c', time() - (int) ( Aura_Worker_Elementor_Door::CLAIM_STALE_MS / 1000 ) - $extra_s );
		$GLOBALS['_options'][ $name ] = $row;
		$GLOBALS['_rows'][ $name ]    = maybe_serialize( $row );
	}

	/**
	 * The LIKE pattern the held-prefix delete actually carries (underscores
	 * escaped). The prefix READ seam is keyed the same way — see
	 * test_an_unreadable_claimed_row_read_stops_the_wipe.
	 */
	private function heldLikePattern(): string {
		return $GLOBALS['wpdb']->esc_like( Aura_Worker_Door_Holds::HELD ) . '%';
	}

	/** Somebody else owns the hold-queue mutex, and their lock is FRESH. */
	private function holdTheLock( int $age_s = 0 ): string {
		$token                                                    = ( time() - $age_s ) . '|' . 'ffffffff-ffff-4fff-8fff-ffffffffffff';
		$GLOBALS['_options'][ Aura_Worker_Door_Holds::LOCK ]      = $token;
		$GLOBALS['_rows'][ Aura_Worker_Door_Holds::LOCK ]         = maybe_serialize( $token );
		unset( $GLOBALS['_notoptions'][ Aura_Worker_Door_Holds::LOCK ] );
		return $token;
	}

	/**
	 * Ruling P46: a wipe that cannot take the mutex deletes NOTHING.
	 *
	 * Entering the deletes on a failed `take_lock()` was worse than not wiping:
	 * the holder resumes inside `hold_locked()` and inserts its held row AFTER
	 * the prefix deletes have run, so a changed-client reconnect finished with
	 * a departed client's stored mutation in the new binding's queue.
	 */
	public function test_a_wipe_that_cannot_take_the_hold_lock_deletes_nothing(): void {
		$this->seedDoor();
		$lock   = $this->holdTheLock();
		$before = $this->doorRows(); // the lock row included: it must survive too
		$fence  = Aura_Worker_Magic_Link::claim_site();

		$this->assertFalse( Aura_Worker_Elementor_Door::wipe_for_unbind( Aura_Worker_Magic_Link::SITE_CLAIM, $fence ) );

		$this->assertSame( $before, $this->doorRows(), 'every row survives' );
		$this->assertSame( $lock, get_option( Aura_Worker_Door_Holds::LOCK ), "and the holder's lock is untouched" );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	/** A STALE holder is taken over by take_lock()'s own rule, and the wipe runs. */
	public function test_a_wipe_takes_over_a_stale_hold_lock_and_wipes(): void {
		$this->seedDoor();
		$this->holdTheLock( Aura_Worker_Door_Holds::LOCK_S + 60 );
		$fence = Aura_Worker_Magic_Link::claim_site();

		$this->assertTrue( Aura_Worker_Elementor_Door::wipe_for_unbind( Aura_Worker_Magic_Link::SITE_CLAIM, $fence ) );

		$this->assertSame( array(), $this->doorRows() );
		$this->assertFalse( get_option( Aura_Worker_Door_Holds::LOCK ), 'and the wipe released the lock it took' );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	/**
	 * …and the unbind reports the debt rather than claiming completion: `door`
	 * is a leftover like any other, so the token stays and the drain's next
	 * Phase-B pass wipes for real.
	 */
	public function test_an_unbind_blocked_on_the_hold_lock_reports_door_and_completes_on_the_next_pass(): void {
		$this->seedDoor();
		$this->holdTheLock();
		$fence = Aura_Worker_Magic_Link::claim_site();

		$this->assertFalse( Aura_Worker_Unbind::cleanup( true, $fence ), 'not complete' );
		$this->assertContains( 'door', Aura_Worker_Unbind::leftovers() );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ), 'the token stays while anything is owed' );
		$this->assertNotEmpty( Aura_Worker_Door_Holds::listing(), 'and the queue is still there' );

		// The holder finishes; the drain calls cleanup() again.
		delete_option( Aura_Worker_Door_Holds::LOCK );
		unset( $GLOBALS['_rows'][ Aura_Worker_Door_Holds::LOCK ] );

		$this->assertTrue( Aura_Worker_Unbind::cleanup( true, $fence ) );
		$this->assertSame( array(), Aura_Worker_Unbind::leftovers() );
		$this->assertSame( array(), $this->doorRows() );
		$this->assertFalse( get_option( 'aura_worker_site_token' ) );
	}

	/**
	 * Ruling P50: a wipe does not START while a replay is in flight.
	 *
	 * This is the mechanism the previous three rounds kept patching around. A
	 * replay that has CLAIMED its hold is between the claim and its callback,
	 * and nothing the wipe deletes can make that request stop — so the wipe
	 * refuses instead, and the caller retries.
	 */
	public function test_a_wipe_refuses_while_a_replay_is_in_flight(): void {
		$door = $this->seedDoor();
		// The claimed row is FRESH again: a live replay, not a died one.
		$this->ageClaim( $door['claimed'], -30 ); // thirty seconds in the future of the bound
		$before = $this->doorRows();
		$fence  = Aura_Worker_Magic_Link::claim_site();

		$this->assertFalse( Aura_Worker_Elementor_Door::wipe_for_unbind( Aura_Worker_Magic_Link::SITE_CLAIM, $fence ) );

		$this->assertSame( $before, $this->doorRows(), 'nothing was deleted' );
		$this->assertFalse( get_option( Aura_Worker_Door_Holds::LOCK ), 'and the lock it took was released' );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	/** A claim older than CLAIM_STALE_MS is a died replay the reconciler owns: the wipe proceeds. */
	public function test_a_wipe_proceeds_past_a_stale_claim(): void {
		$door = $this->seedDoor(); // seedDoor() already ages its claim
		$fence = Aura_Worker_Magic_Link::claim_site();

		$this->assertTrue( Aura_Worker_Elementor_Door::wipe_for_unbind( Aura_Worker_Magic_Link::SITE_CLAIM, $fence ) );

		$this->assertSame( array(), $this->doorRows() );
		$this->assertNull( Aura_Worker_Door_Holds::get_claimed( $door['claimed'] ) );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	/** A claim whose stamp cannot be read is treated as IN FLIGHT, not as stale. */
	public function test_a_claim_with_an_unreadable_stamp_stops_the_wipe(): void {
		$door = $this->seedDoor();
		$name = Aura_Worker_Door_Holds::CLAIMED . $door['claimed'];
		$row  = get_option( $name, array() );
		$row['claimed_at']            = '';
		$GLOBALS['_options'][ $name ] = $row;
		$GLOBALS['_rows'][ $name ]    = maybe_serialize( $row );
		$fence                        = Aura_Worker_Magic_Link::claim_site();

		$this->assertFalse( Aura_Worker_Elementor_Door::wipe_for_unbind( Aura_Worker_Magic_Link::SITE_CLAIM, $fence ) );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	/**
	 * Ruling P49: a wipe answers true only when every statement ran AND
	 * nothing is left.
	 *
	 * A transient database error on the held-prefix delete used to be
	 * invisible — the later statements succeeded, the wipe reported true, and
	 * a changed-client connect persisted the new binding over an old client's
	 * held mutation that was still there, visible and replayable by the new
	 * client.
	 */
	public function test_a_wipe_whose_held_delete_fails_answers_false(): void {
		$door                                                                        = $this->seedDoor();
		$GLOBALS['_sa_option_delete_like_fail'][ $this->heldLikePattern() ]           = true;
		$fence                                                                       = Aura_Worker_Magic_Link::claim_site();

		$this->assertFalse( Aura_Worker_Elementor_Door::wipe_for_unbind( Aura_Worker_Magic_Link::SITE_CLAIM, $fence ) );

		// The rest of the statements still RAN — a partial wipe leaves as
		// little behind as it can, and the next pass finishes it.
		$this->assertNotNull( Aura_Worker_Door_Holds::get_held( $door['held'] ), 'the family that failed is untouched' );
		$this->assertSame( array( 'aura_worker_door_held_' . $door['held'] ), $this->doorRows(), 'and nothing else survived' );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	/** …and the unbind reports it, so the drain finishes the job. */
	public function test_a_partial_wipe_keeps_the_unbind_incomplete_until_it_finishes(): void {
		$this->seedDoor();
		$GLOBALS['_sa_option_delete_like_fail'][ $this->heldLikePattern() ] = true;
		$fence                                                            = Aura_Worker_Magic_Link::claim_site();

		$this->assertFalse( Aura_Worker_Unbind::cleanup( true, $fence ) );
		$this->assertContains( 'door', Aura_Worker_Unbind::leftovers() );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ) );

		$GLOBALS['_sa_option_delete_like_fail'] = array(); // the database recovers

		$this->assertTrue( Aura_Worker_Unbind::cleanup( true, $fence ) );
		$this->assertSame( array(), $this->doorRows() );
		$this->assertFalse( get_option( 'aura_worker_site_token' ) );
	}

	/**
	 * Every statement succeeding is not the same claim as "nothing is left":
	 * the answer is the second one.
	 */
	public function test_a_wipe_answers_false_when_a_row_survives_every_successful_statement(): void {
		$this->seedDoor();
		$fence = Aura_Worker_Magic_Link::claim_site();
		// A row that reappears the instant the deletes are done — the shape a
		// racer, or a fence that stopped matching, would leave.
		$GLOBALS['_sa_after_option_read'] = null;
		$survivor                         = 'aura_worker_door_log_99';
		$this->assertTrue( Aura_Worker_Elementor_Door::wipe_for_unbind( Aura_Worker_Magic_Link::SITE_CLAIM, $fence ), 'a clean wipe first' );
		$GLOBALS['_options'][ $survivor ] = array( 'seq' => 99, 'result' => 'ok', 'admitted' => true );
		$GLOBALS['_rows'][ $survivor ]    = maybe_serialize( $GLOBALS['_options'][ $survivor ] );

		$this->assertFalse(
			Aura_Worker_Elementor_Door::wipe_for_unbind( Aura_Worker_Magic_Link::SITE_CLAIM, 'not-the-fence' ),
			'the deletes matched nothing, so the row is still there and the answer is false'
		);
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	/**
	 * Ruling P49': an unreadable count is not an empty door.
	 *
	 * `has_state()` decides whether a wipe may report success and whether the
	 * unbind may drop `door` from its leftovers. `get_var()` answers null for a
	 * broken statement as readily as for a real zero, so reading the value
	 * alone let a database error report a clean wipe over a door that is still
	 * full — the one direction this must never fail in.
	 */
	public function test_an_unreadable_state_count_reports_the_door_as_still_there(): void {
		$this->seedDoor();
		$fence                                   = Aura_Worker_Magic_Link::claim_site();
		$GLOBALS['_sa_door_has_state_error']     = true;

		$this->assertTrue( Aura_Worker_Elementor_Door::has_state(), 'a read it cannot trust answers "state remains"' );
		$this->assertFalse( Aura_Worker_Elementor_Door::wipe_for_unbind( Aura_Worker_Magic_Link::SITE_CLAIM, $fence ) );

		$GLOBALS['_sa_door_has_state_error'] = false;
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	/** …and the unbind keeps the door owed, so a later pass verifies it for real. */
	public function test_an_unreadable_state_count_keeps_the_unbind_incomplete(): void {
		$this->seedDoor();
		$fence                               = Aura_Worker_Magic_Link::claim_site();
		$GLOBALS['_sa_door_has_state_error'] = true;

		$this->assertFalse( Aura_Worker_Unbind::cleanup( true, $fence ) );
		$this->assertContains( 'door', Aura_Worker_Unbind::leftovers() );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ), 'the token stays while anything is owed' );

		$GLOBALS['_sa_door_has_state_error'] = false; // the database recovers

		$this->assertTrue( Aura_Worker_Unbind::cleanup( true, $fence ) );
		$this->assertSame( array(), $this->doorRows() );
	}

	/**
	 * The same rule for the in-flight check: a claimed-row read that FAILED is
	 * not an empty set, so the wipe refuses rather than deleting under a replay
	 * it could not see (Ruling P49').
	 */
	public function test_an_unreadable_claimed_row_read_stops_the_wipe(): void {
		$this->seedDoor(); // its claim is stale, so only the READ failure can stop this
		$before                                                                    = $this->doorRows();
		$GLOBALS['_sa_rows_read_error'][ $GLOBALS['wpdb']->esc_like( Aura_Worker_Door_Holds::CLAIMED ) ] = true;
		$fence                                                                     = Aura_Worker_Magic_Link::claim_site();

		$this->assertFalse( Aura_Worker_Elementor_Door::wipe_for_unbind( Aura_Worker_Magic_Link::SITE_CLAIM, $fence ) );

		$GLOBALS['_sa_rows_read_error'] = array();
		$this->assertSame( $before, $this->doorRows(), 'nothing was deleted' );
		$this->assertFalse( get_option( Aura_Worker_Door_Holds::LOCK ), 'and the lock it took was released' );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	/** wipe() answers a NAMED status, so a caller cannot conflate busy with failed. */
	public function test_the_wipe_reports_a_named_status_for_each_outcome(): void {
		$this->seedDoor();
		$fence = Aura_Worker_Magic_Link::claim_site();

		$token                                              = time() . '|ffffffff-ffff-4fff-8fff-ffffffffffff';
		$GLOBALS['_options'][ Aura_Worker_Door_Holds::LOCK ] = $token;
		$GLOBALS['_rows'][ Aura_Worker_Door_Holds::LOCK ]    = maybe_serialize( $token );
		$this->assertSame( Aura_Worker_Door_Holds::WIPE_BUSY, Aura_Worker_Door_Holds::wipe( Aura_Worker_Magic_Link::SITE_CLAIM, $fence ), 'no lock: nothing attempted' );
		delete_option( Aura_Worker_Door_Holds::LOCK );
		unset( $GLOBALS['_rows'][ Aura_Worker_Door_Holds::LOCK ] );

		$GLOBALS['_sa_option_delete_like_fail'][ $this->heldLikePattern() ] = true;
		$this->assertSame( Aura_Worker_Door_Holds::WIPE_FAILED, Aura_Worker_Door_Holds::wipe( Aura_Worker_Magic_Link::SITE_CLAIM, $fence ), 'ran, and one failed' );
		$GLOBALS['_sa_option_delete_like_fail'] = array();

		$this->assertSame( Aura_Worker_Door_Holds::WIPE_DONE, Aura_Worker_Door_Holds::wipe( Aura_Worker_Magic_Link::SITE_CLAIM, $fence ), 'every statement ran' );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	/** A caller that lost the site claim deletes nothing — the same fence every step uses. */
	public function test_the_door_wipe_is_fenced_on_the_site_claim(): void {
		$this->seedDoor();
		$before = $this->doorRows();

		Aura_Worker_Elementor_Door::wipe_for_unbind( Aura_Worker_Magic_Link::SITE_CLAIM, 'not-the-fence' );

		$this->assertSame( $before, $this->doorRows(), 'a stolen claim deletes nothing at all' );
	}
}
