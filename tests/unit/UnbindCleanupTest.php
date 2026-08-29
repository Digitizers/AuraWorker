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
		$this->assertSame( array( 'revoke', 'options', 'ruleset', 'grant', 'token' ), $GLOBALS['_unbind_trace'] );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	public function test_not_final_keeps_the_token_and_reports_incomplete(): void {
		$fence = Aura_Worker_Magic_Link::claim_site();
		$this->assertFalse( Aura_Worker_Unbind::cleanup( false, $fence ) );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ) );
		$this->assertSame( array(), Aura_Worker_Unbind::leftovers(), '(1)-(4) done' );
		$this->assertSame( array( 'revoke', 'options', 'ruleset', 'grant' ), $GLOBALS['_unbind_trace'], 'step (5) is not even entered' );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	public function test_a_failing_step_reports_incomplete_and_never_reaches_the_token(): void {
		$GLOBALS['_fail_delete_app_password'] = 'uuid-manual';
		$fence                                = Aura_Worker_Magic_Link::claim_site();
		$this->assertFalse( Aura_Worker_Unbind::cleanup( true, $fence ) );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ) );
		$this->assertContains( 'app_passwords', Aura_Worker_Unbind::leftovers() );
		$this->assertNotContains( 'token', $GLOBALS['_unbind_trace'], 'a `final` call still stops short of the token' );
		// Round-1 I1: ONLY step (5) is gated. Steps (1)-(4) are best-effort and
		// all attempted — a credential the host will never let go of must not
		// pin the departed client's ruleset, dashboard url and gateway key on
		// the site forever, which is what an abort here would do (maybe_finish()
		// would re-enter the same abort every 300 s).
		$this->assertSame( array( 'revoke', 'options', 'ruleset', 'grant' ), $GLOBALS['_unbind_trace'] );
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
		$this->assertSame( array( 'app_passwords', 'options', 'ruleset', 'grant_pubkey' ), $left );
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
}
