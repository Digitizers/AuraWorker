<?php
/**
 * 2.11.0 — the signed /connect callback mints the dashboard's Application
 * Password for the admin who created the link, rotating any earlier one.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class ConnectAppPasswordTest extends TestCase {

	private Aura_Worker_Magic_Link $ml;
	private string $secret   = 'one-time-connect-secret';
	private string $magic_id = 'magic123';

	protected function setUp(): void {
		sa_reset_state();
		$this->ml = new Aura_Worker_Magic_Link();
		$GLOBALS['_admins'] = array( 7 );
		set_transient( 'aura_magic_' . $this->magic_id, array( 'connect_secret' => $this->secret, 'connect_user_id' => 7 ), 600 );
	}

	/** The stored Application Password record (2.11.0: one option, both halves). */
	private function record(): ?array {
		$rec = get_option( Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION, null );
		return is_array( $rec ) ? $rec : null;
	}

	private function recordUuid(): string {
		return (string) ( $this->record()['uuid'] ?? '' );
	}

	private function recordOwner(): int {
		return (int) ( $this->record()['user_id'] ?? 0 );
	}

	/** The same record read from the row rather than the option cache. */
	private function uncachedRecord(): ?array {
		$raw = sa_read_option_uncached( Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION );
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$rec = maybe_unserialize( $raw );
		return is_array( $rec ) ? $rec : null;
	}

	private function uncachedUuid(): ?string {
		$rec = $this->uncachedRecord();
		return null === $rec ? null : (string) ( $rec['uuid'] ?? '' );
	}

	private function uncachedOwner(): int {
		$rec = $this->uncachedRecord();
		return null === $rec ? 0 : (int) ( $rec['user_id'] ?? 0 );
	}

	private function request(): WP_REST_Request {
		$token = 'raw-token';
		$dash  = 'https://dash.example';
		$ts    = time();
		$req   = new WP_REST_Request();
		$req->set_param( 'magic_id', $this->magic_id );
		$req->set_param( 'token', $token );
		$req->set_param( 'dashboard_url', $dash );
		$req->set_param( 'timestamp', $ts );
		$req->set_param( 'signature', Aura_Worker_Magic_Link::sign_connect_payload( $this->secret, $this->magic_id, $token, $dash, $ts ) );
		return $req;
	}

	public function test_signed_connect_mints_an_app_password_for_the_link_creator_and_returns_it_once(): void {
		$res  = $this->ml->handle_connect( $this->request() );
		$data = $res->get_data();
		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertSame( 'user7', $data['app_password']['user_login'] );
		$this->assertStringStartsWith( 'pw-', $data['app_password']['password'] );
		$this->assertArrayNotHasKey( 'app_password_unavailable', $data );
		// Stored on the site under the fixed name, for that user only.
		$items = WP_Application_Passwords::get_user_application_passwords( 7 );
		$this->assertCount( 1, $items );
		$this->assertSame( Aura_Worker_Magic_Link::APP_PASSWORD_NAME, $items[0]['name'] );
		// The connect itself is unchanged: token stored, dashboard bound.
		$this->assertNotEmpty( get_option( 'aura_worker_site_token' ) );
	}

	public function test_rotation_deletes_only_the_STORED_password_by_uuid_never_a_same_named_stranger(): void {
		// A password the operator happens to name "Aura SiteAgent" by hand — no
		// stored UUID points at it, so rotation must leave it alone (round-5).
		WP_Application_Passwords::create_new_application_password( 7, array( 'name' => Aura_Worker_Magic_Link::APP_PASSWORD_NAME ) );
		$this->ml->handle_connect( $this->request() ); // mints Aura's own, stores its uuid
		$stored_uuid = $this->recordUuid();
		$this->assertNotEmpty( $stored_uuid );
		$this->assertCount( 2, WP_Application_Passwords::get_user_application_passwords( 7 ) ); // the stranger + Aura's

		// A second connect rotates ONLY the stored one; the stranger survives, and the stored uuid moves.
		set_transient( 'aura_magic_' . $this->magic_id, array( 'connect_secret' => $this->secret, 'connect_user_id' => 7 ), 600 );
		$this->ml->handle_connect( $this->request() );
		$uuids = array_map( static fn( $i ) => $i['uuid'], WP_Application_Passwords::get_user_application_passwords( 7 ) );
		$this->assertNotContains( $stored_uuid, $uuids, 'the previous Aura password is gone' );
		$this->assertCount( 2, $uuids, 'the stranger stays, one fresh Aura password' );
		$this->assertContains( $this->recordUuid(), $uuids );
	}

	public function test_a_reconnect_by_another_admin_revokes_the_previous_creator_s_aura_password(): void {
		// Admin 7 connected first (this connect); then admin 9 reconnects via a new link.
		$this->ml->handle_connect( $this->request() );
		$this->assertCount( 1, WP_Application_Passwords::get_user_application_passwords( 7 ) );
		$this->assertSame( 7, (int) ( get_option( Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION )['user_id'] ?? 0 ) );
		$GLOBALS['_admins'] = array( 7, 9 );
		set_transient( 'aura_magic_' . $this->magic_id, array( 'connect_secret' => $this->secret, 'connect_user_id' => 9 ), 600 );
		$data = $this->ml->handle_connect( $this->request() )->get_data();
		$this->assertSame( 'user9', $data['app_password']['user_login'] );
		$this->assertSame( array(), WP_Application_Passwords::get_user_application_passwords( 7 ), "the previous creator's Aura password is revoked" );
		$this->assertCount( 1, WP_Application_Passwords::get_user_application_passwords( 9 ) );
		$this->assertSame( 9, $this->recordOwner() );
	}

	public function test_unavailable_app_passwords_leave_the_connect_token_only_and_name_the_reason(): void {
		$GLOBALS['_app_passwords_available'] = false;
		$res  = $this->ml->handle_connect( $this->request() );
		$data = $res->get_data();
		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertArrayNotHasKey( 'app_password', $data );
		$this->assertSame( 'app_passwords_unavailable', $data['app_password_unavailable'] );
		$this->assertSame( array(), WP_Application_Passwords::get_user_application_passwords( 7 ) );
	}

	public function test_a_non_admin_link_creator_mints_nothing(): void {
		$GLOBALS['_admins'] = array(); // user 7 is no longer an administrator
		$data = $this->ml->handle_connect( $this->request() )->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( 'connect_user_not_admin', $data['app_password_unavailable'] );
	}

	public function test_a_link_without_a_creator_mints_nothing(): void {
		set_transient( 'aura_magic_' . $this->magic_id, array( 'connect_secret' => $this->secret ), 600 );
		$data = $this->ml->handle_connect( $this->request() )->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( 'connect_user_unknown', $data['app_password_unavailable'] );
	}

	public function test_the_whole_install_runs_under_ONE_site_wide_claim_released_on_every_exit(): void {
		// Another link's handler holds the site — this one is refused before it touches anything.
		$GLOBALS['_options'][ Aura_Worker_Magic_Link::SITE_CLAIM ] = 'other-fence|' . time();
		$res = $this->ml->handle_connect( $this->request() );
		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'aura_connect_in_progress', $res->get_data()['code'] );
		$this->assertFalse( get_option( 'aura_worker_site_token', false ) );
		$this->assertSame( array(), WP_Application_Passwords::get_user_application_passwords( 7 ) );
		$this->assertFalse( get_option( 'aura_magic_claim_' . $this->magic_id, false ), 'the per-link claim is released when the site claim is refused' );
		// The holder finishes: both claims released.
		unset( $GLOBALS['_options'][ Aura_Worker_Magic_Link::SITE_CLAIM ] );
		$res = $this->ml->handle_connect( $this->request() );
		$this->assertSame( 200, $res->get_status() );
		$this->assertFalse( get_option( Aura_Worker_Magic_Link::SITE_CLAIM, false ) );
		$this->assertFalse( get_option( 'aura_magic_claim_' . $this->magic_id, false ) );
		// It must NOT live under the swept prefix (round-8): the hourly sweep
		// deletes everything there once it is an hour old, which is an
		// age-based takeover of the site claim by another name.
		$this->assertStringStartsNotWith( Aura_Worker_Rules::MAGIC_CLAIM, Aura_Worker_Magic_Link::SITE_CLAIM );
	}

	public function test_the_password_is_minted_while_the_site_claim_is_still_held(): void {
		unset( $GLOBALS['_sa_site_claim_during_mint'] );
		$res = $this->ml->handle_connect( $this->request() );
		$this->assertSame( 200, $res->get_status() );
		$this->assertNotFalse( $GLOBALS['_sa_site_claim_during_mint'] ?? false, 'the site-wide claim must cover the mint' );
		$this->assertFalse( get_option( Aura_Worker_Magic_Link::SITE_CLAIM, false ), '…and be released after it' );
	}

	public function test_a_reconnect_that_can_mint_nothing_still_revokes_the_previous_owner_s_password(): void {
		$this->ml->handle_connect( $this->request() ); // admin 7 owns the Aura password
		$this->assertCount( 1, WP_Application_Passwords::get_user_application_passwords( 7 ) );
		// User 9 reconnects but is NOT an administrator: no replacement — the old credential still dies with the old token.
		set_transient( 'aura_magic_' . $this->magic_id, array( 'connect_secret' => $this->secret, 'connect_user_id' => 9 ), 600 );
		$data = $this->ml->handle_connect( $this->request() )->get_data();
		$this->assertSame( 'connect_user_not_admin', $data['app_password_unavailable'] );
		$this->assertSame( array(), WP_Application_Passwords::get_user_application_passwords( 7 ) );
		$this->assertNull( $this->record(), 'no record remains' );
	}

	public function test_a_revocation_that_did_not_land_is_a_retryable_500_that_keeps_the_transient(): void {
		$this->ml->handle_connect( $this->request() ); // admin 7 owns one, uuid stored
		$this->assertCount( 1, WP_Application_Passwords::get_user_application_passwords( 7 ) );
		set_transient( 'aura_magic_' . $this->magic_id, array( 'connect_secret' => $this->secret, 'connect_user_id' => 7 ), 600 ); // a new link
		$GLOBALS['_app_passwords_delete_fail'] = true;
		$res = $this->ml->handle_connect( $this->request() );
		$this->assertSame( 500, $res->get_status() );
		$this->assertSame( 'app_password_revoke_failed', $res->get_data()['code'] );
		$this->assertArrayNotHasKey( 'success', $res->get_data() );
		$this->assertCount( 1, WP_Application_Passwords::get_user_application_passwords( 7 ), 'the old one is still there — honestly' );
		// Retryable: the transient survives so the dashboard can try again.
		$this->assertNotFalse( get_transient( 'aura_magic_' . $this->magic_id ), 'the transient is kept for the retry' );
		$this->assertSame( 7, $this->recordOwner(), 'the owner is still known' );
	}

	public function test_an_owner_record_that_did_not_persist_revokes_the_new_password_and_returns_none(): void {
		$GLOBALS['_sa_option_write_fail'][ Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION ] = true;
		$data = $this->ml->handle_connect( $this->request() )->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( 'app_password_owner_unrecorded', $data['app_password_unavailable'] );
		$this->assertArrayNotHasKey( 'app_password', $data );
		$this->assertSame( array(), WP_Application_Passwords::get_user_application_passwords( 7 ), 'the password just created is revoked again' );
	}

	public function test_an_unverified_caller_never_contends_for_the_site_claim(): void {
		// Someone else holds the site: a BOGUS callback must be refused on its signature (401), not on the claim (409).
		$GLOBALS['_options'][ Aura_Worker_Magic_Link::SITE_CLAIM ] = 'other-fence|' . time();
		$req = $this->request();
		$req->set_param( 'signature', 'bogus' );
		$res = $this->ml->handle_connect( $req );
		$this->assertSame( 401, $res->get_status() );
		$this->assertFalse( get_option( 'aura_magic_claim_' . $this->magic_id, false ), 'the per-link claim is released' );
	}

	public function test_a_site_claim_is_never_taken_over_by_age_and_is_released_by_deactivating(): void {
		// Round-7, owner decision: a handler the dashboard timed out on may
		// still be running, so NO age lets a second install start beside it.
		// However old the claim is, the connect is refused.
		$GLOBALS['_options'][ Aura_Worker_Magic_Link::SITE_CLAIM ] = 'dead-fence|' . ( time() - 86400 );
		$res = $this->ml->handle_connect( $this->request() );
		$this->assertSame( 409, $res->get_status() );
		$this->assertStringStartsWith( 'dead-fence|', (string) get_option( Aura_Worker_Magic_Link::SITE_CLAIM ), 'no takeover, at any age' );
		$this->assertFalse( get_option( 'aura_magic_claim_' . $this->magic_id, false ), 'the per-link claim is released' );

		// The operator's release: deactivating the plugin (which no handler
		// survives) calls the ONE method that deletes the claim.
		Aura_Worker_Magic_Link::forget_site_claim();
		$this->assertFalse( get_option( Aura_Worker_Magic_Link::SITE_CLAIM, false ) );
		$res = $this->ml->handle_connect( $this->request() );
		$this->assertSame( 200, $res->get_status(), 'the connect proceeds once the claim is released' );

		// …and it is wired to activation and deactivation, and to nothing else.
		$main = file_get_contents( __DIR__ . '/../../digitizer-site-worker/digitizer-site-worker.php' );
		$this->assertSame( 2, substr_count( $main, 'Aura_Worker_Magic_Link::forget_site_claim();' ) );
		$this->assertSame( 0, substr_count( file_get_contents( __DIR__ . '/../../digitizer-site-worker/includes/class-aura-worker-api.php' ), 'forget_site_claim' ) );
	}

	public function test_regenerating_the_site_token_revokes_the_managed_password(): void {
		$this->ml->handle_connect( $this->request() );
		$uuid = $this->recordUuid();
		$this->assertNotEmpty( $uuid );
		// The rotation path calls the ONE revocation.
		$this->assertTrue( Aura_Worker_Magic_Link::revoke_managed_password() );
		$this->assertSame( array(), WP_Application_Passwords::get_user_application_passwords( 7 ) );
		$this->assertNull( $this->record() );
		// …and the handler that regenerates the token is wired to it.
		$src = file_get_contents( __DIR__ . '/../../digitizer-site-worker/includes/class-aura-worker.php' );
		$this->assertStringContainsString( 'Aura_Worker_Magic_Link::revoke_managed_password( $site_fence )', $src );
	}

	public function test_uninstall_revokes_the_managed_password_by_uuid_before_deleting_its_options(): void {
		$src = file_get_contents( __DIR__ . '/../../digitizer-site-worker/uninstall.php' );
		$this->assertStringContainsString( "WP_Application_Passwords::delete_application_password( \$aura_pw_owner, \$aura_pw_uuid )", $src );
		$this->assertStringContainsString( "get_option( 'aura_worker_app_password', null )", $src );
		// The revocation precedes the deletion of the options that identify it.
		$this->assertLessThan(
			strpos( $src, "delete_option( 'aura_worker_app_password' )" ),
			strpos( $src, 'delete_application_password' ),
			'revoke first, then forget'
		);
	}

	public function test_a_fresh_password_that_can_be_neither_recorded_nor_revoked_is_terminal_not_retryable(): void {
		// Round-7 P1: with option writes AND the delete failing, the cleanup of
		// the untracked password does not land. Reporting a token-only success
		// there would leave a live administrator credential on the site with
		// nothing recording it.
		// Round-11 P1: and it must not be RETRIED either — the next attempt
		// would find nothing recorded, mint again, and every retry would add
		// another live untracked administrator credential. The magic link is
		// consumed to stop that.
		$GLOBALS['_sa_option_write_fail'][ Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION ] = true;
		$GLOBALS['_app_passwords_delete_fail'] = true;
		$res  = $this->ml->handle_connect( $this->request() );
		$data = $res->get_data();
		$this->assertSame( 500, $res->get_status() );
		$this->assertSame( 'app_password_orphan_untracked', $data['code'] );
		$this->assertArrayNotHasKey( 'success', $data );
		$this->assertFalse( get_transient( 'aura_magic_' . $this->magic_id ), 'the magic link is consumed: no second mint beside the orphan' );
		$this->assertFalse( get_option( Aura_Worker_Magic_Link::SITE_CLAIM, false ), 'the claim is released' );
		$this->assertCount( 1, WP_Application_Passwords::get_user_application_passwords( 7 ), 'the password really is still live' );

		// A retry of the same link mints nothing at all — it is refused at the
		// consumed transient, before any credential work.
		$res = $this->ml->handle_connect( $this->request() );
		$this->assertSame( 400, $res->get_status() );
		$this->assertCount( 1, WP_Application_Passwords::get_user_application_passwords( 7 ), 'still exactly one orphan' );
	}

	public function test_a_password_that_could_not_be_revoked_has_its_tracking_recovered(): void {
		// Same round-7 P1, with the option store refusing only the FIRST write:
		// the orphan the cleanup could not delete is recorded after all, so the
		// next connect's rotation can revoke it by uuid.
		// The claim-conditional write is two statements (UPDATE, then INSERT for
		// an absent row), so refusing two refuses the FIRST persist entirely.
		$GLOBALS['_sa_option_write_fail'][ Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION ] = 2;
		$GLOBALS['_app_passwords_delete_fail'] = true;
		$res = $this->ml->handle_connect( $this->request() );
		$this->assertSame( 500, $res->get_status() );
		$this->assertSame( 'app_password_orphaned', $res->get_data()['code'], 'tracked: the next attempt can revoke it, so this one is retryable' );
		$live = WP_Application_Passwords::get_user_application_passwords( 7 );
		$this->assertCount( 1, $live );
		$this->assertSame( 7, (int) ( get_option( Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION )['user_id'] ?? 0 ) );
		$this->assertSame( $live[0]['uuid'], $this->recordUuid(), 'the orphan is tracked' );
		// …and the rotation can now kill it once the store recovers.
		$GLOBALS['_app_passwords_delete_fail'] = false;
		$this->assertTrue( Aura_Worker_Magic_Link::revoke_managed_password() );
		$this->assertSame( array(), WP_Application_Passwords::get_user_application_passwords( 7 ) );
	}

	public function test_uninstall_keeps_the_tracking_options_when_the_revocation_does_not_land(): void {
		// Round-7 P1: deleting owner+uuid after a FAILED delete would leave an
		// administrator password alive with its identity irrecoverably forgotten.
		$this->ml->handle_connect( $this->request() );
		$uuid = $this->recordUuid();
		$this->assertNotEmpty( $uuid );
		$GLOBALS['_app_passwords_delete_fail'] = true;
		$this->run_uninstall();
		$this->assertSame( 7, $this->recordOwner(), 'the owner is still recorded' );
		$this->assertSame( $uuid, $this->recordUuid(), 'the uuid is still recorded' );
		$this->assertCount( 1, WP_Application_Passwords::get_user_application_passwords( 7 ) );

		// …and when the revocation DOES land, the tracking is forgotten.
		$GLOBALS['_app_passwords_delete_fail'] = false;
		$this->run_uninstall();
		$this->assertSame( array(), WP_Application_Passwords::get_user_application_passwords( 7 ) );
		$this->assertNull( $this->record() );
	}

	public function test_regenerating_the_token_is_refused_while_a_connect_holds_the_site(): void {
		// Round-7 P2: a regeneration that lands between a callback's revocation
		// and its mint revokes nothing and still reports the site disconnected,
		// while the callback hands out a fresh administrator credential. It takes
		// the same site-wide claim a connect does.
		$GLOBALS['_options'][ Aura_Worker_Magic_Link::SITE_CLAIM ] = 'live-fence|' . time();
		$plugin = new Aura_Worker();
		$before = get_option( 'aura_worker_site_token' );
		try {
			$plugin->ajax_regenerate_token();
			$this->fail( 'the handler returned without sending a JSON response' );
		} catch ( SA_Json_Response $res ) {
			$this->assertFalse( $res->success );
			$this->assertSame( 409, $res->status );
		}
		$this->assertSame( $before, get_option( 'aura_worker_site_token' ), 'nothing was rotated' );
		$this->assertStringStartsWith( 'live-fence|', (string) get_option( Aura_Worker_Magic_Link::SITE_CLAIM ), "the connect's claim is untouched" );

		// With the site free, the rotation runs and releases the claim it took.
		delete_option( Aura_Worker_Magic_Link::SITE_CLAIM );
		try {
			$plugin->ajax_regenerate_token();
			$this->fail( 'the handler returned without sending a JSON response' );
		} catch ( SA_Json_Response $res ) {
			$this->assertTrue( $res->success );
		}
		$this->assertFalse( get_option( Aura_Worker_Magic_Link::SITE_CLAIM, false ), 'the rotation released the claim' );
	}

	public function test_the_hourly_magic_claim_sweep_never_removes_the_site_claim(): void {
		// Round-8: under the swept MAGIC_CLAIM prefix, an hour-old site claim
		// would be deleted by any request that runs daily rules enforcement —
		// an age-based takeover by another name.
		$GLOBALS['_options'][ Aura_Worker_Magic_Link::SITE_CLAIM ] = 'live-fence|' . ( time() - 6 * HOUR_IN_SECONDS );
		$GLOBALS['_rows'][ Aura_Worker_Magic_Link::SITE_CLAIM ]    = $GLOBALS['_options'][ Aura_Worker_Magic_Link::SITE_CLAIM ];
		$stale = Aura_Worker_Rules::MAGIC_CLAIM . 'someLink';
		$GLOBALS['_options'][ $stale ] = 'dead-fence|' . ( time() - 6 * HOUR_IN_SECONDS );
		$GLOBALS['_rows'][ $stale ]    = $GLOBALS['_options'][ $stale ];

		Aura_Worker_Rules::enforce( array( array( 'type' => 'site', 'id' => '*' ) ), 'x' );

		$this->assertFalse( get_option( $stale, false ), 'a per-link orphan still ages out' );
		$this->assertStringStartsWith( 'live-fence|', (string) get_option( Aura_Worker_Magic_Link::SITE_CLAIM ), 'the site claim is not swept' );
	}

	public function test_a_superseded_connect_whose_password_will_not_die_is_terminal(): void {
		// Round-16: losing the claim mid-mint leaves the fresh password
		// untracked (the tracking writes went with the claim), and the record on
		// the site belongs to the install that replaced this one — so nothing
		// here may write over it. A revocation that then fails leaves a live
		// orphan, and retrying would mint more beside it.
		$GLOBALS['_sa_steal_site_claim_during_mint'] = true;
		$GLOBALS['_app_passwords_delete_fail']       = true;
		$res  = $this->ml->handle_connect( $this->request() );
		$data = $res->get_data();
		$this->assertSame( 500, $res->get_status() );
		$this->assertSame( 'app_password_orphan_untracked', $data['code'] );
		$this->assertFalse( get_transient( 'aura_magic_' . $this->magic_id ), 'the link is consumed: no second mint beside the orphan' );
		$this->assertCount( 1, WP_Application_Passwords::get_user_application_passwords( 7 ) );
	}

	public function test_a_connect_that_loses_the_site_mid_mint_returns_nothing_and_revokes_what_it_created(): void {
		// Round-8: every write the claim protects re-checks that the claim is
		// still this handler's, so a release (an operator's, anyone's) cannot
		// let a resumed handler hand back a password beside another install.
		$GLOBALS['_sa_steal_site_claim_during_mint'] = true;
		$res = $this->ml->handle_connect( $this->request() );
		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'aura_connect_lost_claim', $res->get_data()['code'] );
		$this->assertArrayNotHasKey( 'app_password', $res->get_data() );
		$this->assertSame( array(), WP_Application_Passwords::get_user_application_passwords( 7 ), 'the password it minted is revoked' );
		$this->assertNotEmpty( get_transient( 'aura_magic_' . $this->magic_id ), 'the transient survives — the dashboard retries' );
	}

	public function test_the_returned_app_password_never_carries_the_sites_own_uuid(): void {
		$data = $this->ml->handle_connect( $this->request() )->get_data();
		$this->assertSame( array( 'user_login', 'password' ), array_keys( $data['app_password'] ) );
	}

	public function test_deactivating_the_plugin_revokes_the_managed_password_and_keeps_tracking_on_failure(): void {
		// Round-8: deactivation is the documented way to disconnect Aura, but
		// unregistering the routes leaves an administrator credential that core
		// and every other REST/MCP plugin still accept.
		$main = file_get_contents( __DIR__ . '/../../digitizer-site-worker/digitizer-site-worker.php' );
		$deactivate = substr( $main, strpos( $main, 'function aura_worker_deactivate()' ) );
		$deactivate = substr( $deactivate, 0, strpos( $deactivate, "\nregister_deactivation_hook" ) );
		$this->assertStringContainsString( 'Aura_Worker_Magic_Link::revoke_managed_password()', $deactivate );
		$this->assertStringContainsString( 'Aura_Worker_Magic_Link::forget_site_claim();', $deactivate );
		// …and activation finishes a revocation deactivation could not land
		// (round-11): reaching it with a record still present means exactly
		// that, since a successful revocation clears the record.
		$activate = substr( $main, strpos( $main, 'function aura_worker_activate()' ) );
		$activate = substr( $activate, 0, strpos( $activate, "\nregister_activation_hook" ) );
		$this->assertStringContainsString( 'Aura_Worker_Magic_Link::revoke_managed_password()', $activate );

		// A revocation that did not land keeps the owner/uuid, so a
		// reactivation or the uninstall can finish the job.
		$this->ml->handle_connect( $this->request() );
		$uuid = $this->recordUuid();
		$GLOBALS['_app_passwords_delete_fail'] = true;
		$this->assertFalse( Aura_Worker_Magic_Link::revoke_managed_password() );
		$this->assertSame( $uuid, $this->recordUuid() );
		$this->assertSame( 7, (int) ( get_option( Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION )['user_id'] ?? 0 ) );
	}

	public function test_the_token_write_is_conditional_on_the_claim_not_merely_preceded_by_a_check(): void {
		// Round-9: a handler paused between a check and the write resumes and
		// writes anyway — overwriting the winner's token AFTER the winner
		// answered 200, so the dashboard holds a token the site rejects and
		// nothing anywhere reports it. Ownership is part of the statement.
		$winner = Aura_Worker_Security::hash_token( 'the-winners-token' );
		$GLOBALS['_options']['aura_worker_site_token'] = $winner;
		$GLOBALS['_rows']['aura_worker_site_token']    = $winner;
		$GLOBALS['_options'][ Aura_Worker_Magic_Link::SITE_CLAIM ] = 'winner-fence|' . time();
		$GLOBALS['_rows'][ Aura_Worker_Magic_Link::SITE_CLAIM ]    = $GLOBALS['_options'][ Aura_Worker_Magic_Link::SITE_CLAIM ];

		$write = new ReflectionMethod( Aura_Worker_Magic_Link::class, 'write_token_under_claim' );
		if ( PHP_VERSION_ID < 80100 ) {
			$write->setAccessible( true );
		}
		$write->invoke( null, Aura_Worker_Security::hash_token( 'the-losers-token' ), 'loser-fence' );
		$this->assertSame( $winner, sa_read_option_uncached( 'aura_worker_site_token' ), 'a handler that lost the claim writes nothing' );

		// The holder's own write lands.
		$mine = Aura_Worker_Security::hash_token( 'the-winners-second-token' );
		$write->invoke( null, $mine, 'winner-fence' );
		$this->assertSame( $mine, sa_read_option_uncached( 'aura_worker_site_token' ) );

		// …and on a site with no token row yet, likewise only for the holder.
		unset( $GLOBALS['_options']['aura_worker_site_token'], $GLOBALS['_rows']['aura_worker_site_token'] );
		$write->invoke( null, Aura_Worker_Security::hash_token( 'x' ), 'loser-fence' );
		$this->assertNull( sa_read_option_uncached( 'aura_worker_site_token' ) );
		$write->invoke( null, $mine, 'winner-fence' );
		$this->assertSame( $mine, sa_read_option_uncached( 'aura_worker_site_token' ) );
	}

	public function test_a_regeneration_that_lost_the_site_revokes_nothing_and_still_reveals_its_token(): void {
		// Round-11 P2: a rotation paused after its swap, its claim released by
		// an operator, must not revoke the Application Password of the connect
		// that replaced it, nor delete that connect's dashboard URL — and must
		// still hand its own token to the admin (refusing that is the #67
		// defect).
		$this->ml->handle_connect( $this->request() ); // a connection exists: password + dashboard URL
		$this->assertCount( 1, WP_Application_Passwords::get_user_application_passwords( 7 ) );
		$this->assertNotEmpty( get_option( 'aura_worker_dashboard_url' ) );

		// The claim goes away between this rotation's read and its swap. (The
		// hook also fires for the conditional INSERT that TAKES the claim, so
		// it waits until the row is actually there before removing it.)
		$GLOBALS['_sa_before_swap'] = static function () {
			if ( ! isset( $GLOBALS['_options'][ Aura_Worker_Magic_Link::SITE_CLAIM ] ) ) {
				return;
			}
			unset( $GLOBALS['_options'][ Aura_Worker_Magic_Link::SITE_CLAIM ], $GLOBALS['_rows'][ Aura_Worker_Magic_Link::SITE_CLAIM ] );
			$GLOBALS['_sa_before_swap'] = null;
		};
		$plugin = new Aura_Worker();
		try {
			$plugin->ajax_regenerate_token();
			$this->fail( 'the handler returned without sending a JSON response' );
		} catch ( SA_Json_Response $res ) {
			$this->assertTrue( $res->success, 'the token is still revealed' );
			$this->assertNotSame( '', (string) ( $res->data['token'] ?? '' ) );
		}
		$this->assertCount( 1, WP_Application_Passwords::get_user_application_passwords( 7 ), "the winner's password is untouched" );
		$this->assertNotEmpty( get_option( 'aura_worker_dashboard_url' ), "…and so is its dashboard URL" );
	}

	public function test_a_password_wordpress_refuses_to_create_is_retried_not_completed_token_only(): void {
		// Round-12: a WP_Error out of create_new_application_password() is an
		// operational failure of the site's own store — a failing user-meta
		// write — not one of the supported "this site cannot have one" cases.
		// Completing there finishes onboarding without the credential the
		// builder tools need, and gives the dashboard no way to ask again.
		$GLOBALS['_sa_app_password_create_fails'] = true;
		$res  = $this->ml->handle_connect( $this->request() );
		$data = $res->get_data();
		$this->assertSame( 500, $res->get_status() );
		$this->assertSame( 'app_password_mint_failed', $data['code'] );
		$this->assertArrayNotHasKey( 'success', $data );
		$this->assertNotEmpty( get_transient( 'aura_magic_' . $this->magic_id ), 'the same callback can be retried' );
		$this->assertFalse( get_option( Aura_Worker_Magic_Link::SITE_CLAIM, false ), 'the claim is released so the retry is not refused' );

		// The retry, with the store working again, completes with a password.
		$GLOBALS['_sa_app_password_create_fails'] = false;
		$data = $this->ml->handle_connect( $this->request() )->get_data();
		$this->assertSame( 'user7', $data['app_password']['user_login'] );
	}

	public function test_the_tracking_record_is_written_conditionally_on_the_claim(): void {
		// Round-12: unfenced, a handler that lost the site would overwrite the
		// winner's owner/UUID with its own and then delete only its OWN
		// password — leaving the winner's administrator credential live and the
		// site's record of it pointing at a password that no longer exists.
		$this->ml->handle_connect( $this->request() ); // the winner's install
		$winner_uuid = $this->recordUuid();
		$this->assertNotEmpty( $winner_uuid );
		$GLOBALS['_options'][ Aura_Worker_Magic_Link::SITE_CLAIM ] = 'winner-fence|' . time();
		$GLOBALS['_rows'][ Aura_Worker_Magic_Link::SITE_CLAIM ]    = $GLOBALS['_options'][ Aura_Worker_Magic_Link::SITE_CLAIM ];

		$mint = new ReflectionMethod( Aura_Worker_Magic_Link::class, 'persist_password_owner' );
		if ( PHP_VERSION_ID < 80100 ) {
			$mint->setAccessible( true );
		}
		$mint->invoke( null, 99, 'loser-uuid', 'loser-fence' );
		$this->assertSame( $winner_uuid, $this->uncachedUuid() );
		$this->assertSame( '7', (string) $this->uncachedOwner() );

		$mint->invoke( null, 99, 'winner-second-uuid', 'winner-fence' );
		$this->assertSame( 'winner-second-uuid', $this->uncachedUuid() );
	}

	public function test_an_unusable_tracking_record_refuses_every_further_mint(): void {
		// Round-13: a mint whose owner/UUID writes only partly landed leaves a
		// lone option behind. Read as "nothing recorded", the NEXT link — a new
		// one, so consuming the old transient does not help — would mint a
		// second live administrator credential beside the orphan.
		update_option( Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION, array( 'user_id' => 7 ) ); // a record with no uuid: present but unusable
		$res  = $this->ml->handle_connect( $this->request() );
		$data = $res->get_data();
		$this->assertSame( 500, $res->get_status() );
		$this->assertSame( 'app_password_tracking_incomplete', $data['code'] );
		$this->assertSame( array(), WP_Application_Passwords::get_user_application_passwords( 7 ), 'nothing new was minted' );
		$this->assertFalse( get_option( Aura_Worker_Magic_Link::SITE_CLAIM, false ) );
		// The revocation cannot report success on half a record either — the
		// deactivation and uninstall paths must log it, not clear it silently.
		$this->assertFalse( Aura_Worker_Magic_Link::revoke_managed_password() );
		$this->assertSame( 7, (int) ( get_option( Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION )['user_id'] ?? 0 ) );

		// Cleared by hand, the next connect mints again (a fresh link: the
		// terminal refusal consumed the previous one).
		delete_option( Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION );
		set_transient( 'aura_magic_' . $this->magic_id, array( 'connect_secret' => $this->secret, 'connect_user_id' => 7 ), 600 );
		$data = $this->ml->handle_connect( $this->request() )->get_data();
		$this->assertSame( 'user7', $data['app_password']['user_login'] );
	}

	public function test_a_record_does_not_outlive_the_password_it_named(): void {
		// Round-14: with only the UUID write refused, the cleanup deletes the
		// password successfully — so the owner option left behind names nothing.
		// It would refuse every later magic link and make deactivation report a
		// revocation failure, for a credential that no longer exists.
		$GLOBALS['_sa_option_write_fail'][ Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION ] = true;
		$data = $this->ml->handle_connect( $this->request() )->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( 'app_password_owner_unrecorded', $data['app_password_unavailable'] );
		$this->assertSame( array(), WP_Application_Passwords::get_user_application_passwords( 7 ), 'the password was revoked' );
		$this->assertNull( $this->record(), '…and its record with it' );
		$this->assertTrue( Aura_Worker_Magic_Link::revoke_managed_password(), 'nothing is recorded, so nothing is owed' );

		// The next connect is not refused.
		unset( $GLOBALS['_sa_option_write_fail'][ Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION ] );
		set_transient( 'aura_magic_' . $this->magic_id, array( 'connect_secret' => $this->secret, 'connect_user_id' => 7 ), 600 );
		$data = $this->ml->handle_connect( $this->request() )->get_data();
		$this->assertSame( 'user7', $data['app_password']['user_login'] );
	}

	public function test_uninstall_keeps_an_unusable_tracking_record(): void {
		// Round-15: an incomplete pair names a password uninstall cannot delete
		// but that may still authenticate. The surviving option is the only
		// thing left pointing at it, so it outlives the plugin.
		update_option( Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION, array( 'user_id' => 7 ) );
		$this->run_uninstall();
		$this->assertSame( array( 'user_id' => 7 ), $this->record(), 'an unusable record outlives the plugin' );

		// The mirror case: a uuid with no owner is just as unusable.
		update_option( Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION, array( 'uuid' => 'lonely-uuid' ) );
		$this->run_uninstall();
		$this->assertSame( array( 'uuid' => 'lonely-uuid' ), $this->record() );
	}

	public function test_the_rotation_forgets_the_record_only_while_it_owns_the_claim(): void {
		// Round-15: revoke_managed_password() deleted the owner/UUID pair
		// unconditionally, so a connect resuming after its claim was released
		// would erase the WINNING install's record — leaving that install's
		// administrator credential live and beyond every later rotation.
		$this->ml->handle_connect( $this->request() ); // the winner's install
		$winner_uuid = $this->recordUuid();
		$GLOBALS['_options'][ Aura_Worker_Magic_Link::SITE_CLAIM ] = 'winner-fence|' . time();
		$GLOBALS['_rows'][ Aura_Worker_Magic_Link::SITE_CLAIM ]    = $GLOBALS['_options'][ Aura_Worker_Magic_Link::SITE_CLAIM ];

		Aura_Worker_Magic_Link::revoke_managed_password( 'loser-fence' );
		$this->assertSame( $winner_uuid, $this->uncachedUuid(), "the winner's record survives" );
		$this->assertSame( '7', (string) $this->uncachedOwner() );

		Aura_Worker_Magic_Link::revoke_managed_password( 'winner-fence' );
		$this->assertNull( $this->uncachedUuid() );
	}

	public function test_the_record_is_one_option_so_a_lost_claim_cannot_leave_half_of_it(): void {
		// Round-17: as two claim-conditional statements, a claim released
		// between them wrote the owner and skipped the UUID — a record no code
		// can act on, which then refused every later connect until an operator
		// repaired it. One option, one statement: either the whole record lands
		// or none of it does.
		$claim = Aura_Worker_Magic_Link::SITE_CLAIM;
		$GLOBALS['_options'][ $claim ] = 'winner-fence|' . time();
		$GLOBALS['_rows'][ $claim ]    = $GLOBALS['_options'][ $claim ];
		$persist = new ReflectionMethod( Aura_Worker_Magic_Link::class, 'persist_password_owner' );
		if ( PHP_VERSION_ID < 80100 ) {
			$persist->setAccessible( true );
		}
		$persist->invoke( null, 9, 'loser-uuid', 'loser-fence' );
		$this->assertNull( $this->uncachedRecord(), 'a superseded fence writes nothing at all' );

		$persist->invoke( null, 9, 'winner-uuid', 'winner-fence' );
		$this->assertSame( array( 'user_id' => 9, 'uuid' => 'winner-uuid' ), $this->uncachedRecord() );
	}

	public function test_the_rotation_takes_the_record_before_it_deletes_the_password(): void {
		// Round-17: the regeneration checked "do I still hold the claim?" and
		// then revoked — two steps. Paused between them it would delete the
		// Application Password of the connect that replaced it, one the
		// dashboard already holds. Removing the record IS the ownership test
		// now, and it cannot be raced: only the caller whose statement removed
		// the row goes on to delete the password.
		$this->ml->handle_connect( $this->request() ); // the winner's install
		$winner_uuid = $this->recordUuid();
		$claim = Aura_Worker_Magic_Link::SITE_CLAIM;
		$GLOBALS['_options'][ $claim ] = 'winner-fence|' . time();
		$GLOBALS['_rows'][ $claim ]    = $GLOBALS['_options'][ $claim ];

		$this->assertTrue( Aura_Worker_Magic_Link::revoke_managed_password( 'loser-fence' ), 'nothing is owed by a caller that owns nothing' );
		$this->assertCount( 1, WP_Application_Passwords::get_user_application_passwords( 7 ), "the winner's password survives" );
		$this->assertSame( $winner_uuid, $this->uncachedUuid(), '…and so does its record' );

		// The holder's own revocation takes both.
		$this->assertTrue( Aura_Worker_Magic_Link::revoke_managed_password( 'winner-fence' ) );
		$this->assertSame( array(), WP_Application_Passwords::get_user_application_passwords( 7 ) );
		$this->assertNull( $this->uncachedRecord() );
	}

	public function test_a_revocation_that_fails_puts_the_record_back(): void {
		// The record is consumed BEFORE the password is deleted, so a delete
		// that does not land must restore it — otherwise the live credential
		// would be left with nothing naming it.
		$this->ml->handle_connect( $this->request() );
		$uuid  = $this->recordUuid();
		$claim = Aura_Worker_Magic_Link::SITE_CLAIM;
		$GLOBALS['_options'][ $claim ] = 'mine|' . time();
		$GLOBALS['_rows'][ $claim ]    = $GLOBALS['_options'][ $claim ];
		$GLOBALS['_app_passwords_delete_fail'] = true;

		$this->assertFalse( Aura_Worker_Magic_Link::revoke_managed_password( 'mine' ) );
		$this->assertSame( array( 'user_id' => 7, 'uuid' => $uuid ), $this->uncachedRecord(), 'the record is restored' );
		$this->assertCount( 1, WP_Application_Passwords::get_user_application_passwords( 7 ) );
	}

	public function test_a_failed_record_delete_is_a_failed_revocation_not_a_lost_claim(): void {
		// Round-18: delete_option_if_claimed() answering false (the statement
		// failed) read as 0 rows would mean "the record is not mine", the
		// revocation would report nothing owed, and the mint would write a
		// replacement record over one whose password is still live — and now
		// untracked.
		$this->ml->handle_connect( $this->request() );
		$uuid  = $this->recordUuid();
		$claim = Aura_Worker_Magic_Link::SITE_CLAIM;
		$GLOBALS['_options'][ $claim ] = 'mine|' . time();
		$GLOBALS['_rows'][ $claim ]    = $GLOBALS['_options'][ $claim ];
		$GLOBALS['_sa_option_delete_fail'][ Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION ] = true;

		$this->assertFalse( Aura_Worker_Magic_Link::revoke_managed_password( 'mine' ) );
		$this->assertSame( $uuid, $this->uncachedUuid(), 'the record survives a failed delete' );
		$this->assertCount( 1, WP_Application_Passwords::get_user_application_passwords( 7 ), 'and so does the password' );
	}

	public function test_deactivation_flags_the_revoked_builder_credential_and_a_connect_clears_it(): void {
		// Round-19: the token binding survives deactivation, so the settings
		// screen kept reporting an intact connection while the credential the
		// builder tools authenticate with was gone — and hid the connect
		// button, leaving no way to restore it from the site.
		$this->ml->handle_connect( $this->request() );
		$this->assertNotNull( $this->record() );
		$this->assertSame( '', (string) get_option( Aura_Worker_Magic_Link::RECONNECT_NEEDED_OPTION, '' ) );

		// Deactivation revokes it and says so.
		$main = file_get_contents( __DIR__ . '/../../digitizer-site-worker/digitizer-site-worker.php' );
		$this->assertStringContainsString( 'Aura_Worker_Magic_Link::RECONNECT_NEEDED_OPTION, 1, false', $main );
		update_option( Aura_Worker_Magic_Link::RECONNECT_NEEDED_OPTION, 1, false );

		// The settings screen keeps the connect button reachable and explains why.
		ob_start();
		$this->ml->render_connect_section();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'was revoked when this plugin was deactivated', $html );
		$this->assertStringContainsString( 'aura-connect-btn', $html, 'the button is reachable again' );

		// A fresh connect mints a replacement and clears the flag.
		set_transient( 'aura_magic_' . $this->magic_id, array( 'connect_secret' => $this->secret, 'connect_user_id' => 7 ), 600 );
		$data = $this->ml->handle_connect( $this->request() )->get_data();
		$this->assertSame( 'user7', $data['app_password']['user_login'] );
		$this->assertSame( '', (string) get_option( Aura_Worker_Magic_Link::RECONNECT_NEEDED_OPTION, '' ) );

		// …and with no flag the screen is the plain connected one, no button.
		ob_start();
		$this->ml->render_connect_section();
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'aura-connect-btn', $html );
	}

	public function test_a_previous_password_left_unrevoked_AND_unrecorded_is_terminal(): void {
		// Round-20: the revocation consumes the record before deleting the
		// password and restores it when the delete fails. If that restoration
		// fails too, the old administrator credential is live with nothing
		// naming it — and a retryable answer would let the next attempt mint
		// another beside it.
		$this->ml->handle_connect( $this->request() );
		$this->assertNotNull( $this->record() );
		// The delete fails, and so does putting the record back.
		$GLOBALS['_app_passwords_delete_fail'] = true;
		$GLOBALS['_sa_option_write_fail'][ Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION ] = true;
		set_transient( 'aura_magic_' . $this->magic_id, array( 'connect_secret' => $this->secret, 'connect_user_id' => 7 ), 600 );
		$res  = $this->ml->handle_connect( $this->request() );
		$data = $res->get_data();
		$this->assertSame( 500, $res->get_status() );
		$this->assertSame( 'app_password_orphan_untracked', $data['code'] );
		$this->assertFalse( get_transient( 'aura_magic_' . $this->magic_id ), 'terminal: no retry can mint beside the orphan' );
		$this->assertCount( 1, WP_Application_Passwords::get_user_application_passwords( 7 ), 'nothing new was minted' );
	}

	public function test_activation_completing_a_deferred_revocation_flags_the_reconnect(): void {
		// Round-20: deactivation that could not revoke keeps the record and sets
		// no flag; activation retries and, succeeding, must set the flag itself
		// — otherwise the screen reports an intact connection over a credential
		// it has just revoked.
		$main = file_get_contents( __DIR__ . '/../../digitizer-site-worker/digitizer-site-worker.php' );
		$activate = substr( $main, strpos( $main, 'function aura_worker_activate()' ) );
		$activate = substr( $activate, 0, strpos( $activate, "\nregister_activation_hook" ) );
		$this->assertStringContainsString( 'Aura_Worker_Magic_Link::RECONNECT_NEEDED_OPTION, 1, false', $activate );
		$this->assertStringContainsString( 'Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION, null', $activate, 'the flag is set only when a record was actually there' );
	}

	/** Run uninstall.php the way WordPress does — the file loads no plugin code. */
	private function run_uninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'digitizer-site-worker/digitizer-site-worker.php' );
		}
		include __DIR__ . '/../../digitizer-site-worker/uninstall.php';
	}

	public function test_a_rejected_connect_mints_nothing(): void {
		$req = $this->request();
		$req->set_param( 'signature', 'bogus' );
		$res = $this->ml->handle_connect( $req );
		$this->assertSame( 401, $res->get_status() );
		$this->assertSame( array(), WP_Application_Passwords::get_user_application_passwords( 7 ) );
	}
}
