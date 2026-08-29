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
	 * A password the marker names but whose owner it does not: the connecting
	 * user is the fallback, and a marker naming neither leaves the credential
	 * alone rather than guessing at user 0.
	 */
	public function test_a_uuid_with_no_recorded_user_falls_back_to_the_connect_user(): void {
		sa_set_marker(
			array(
				'site'               => sa_token_hash(),
				'connect_user_id'    => 3,
				'app_password_uuids' => array( 'uuid-manual' ),
				'app_password_users' => array(),
			)
		);
		$fence = Aura_Worker_Magic_Link::claim_site();
		$this->assertTrue( Aura_Worker_Unbind::cleanup( true, $fence ) );
		$this->assertFalse( sa_app_password_exists( 3, 'uuid-manual' ) );
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
