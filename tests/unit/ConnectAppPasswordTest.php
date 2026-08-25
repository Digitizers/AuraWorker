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
		$stored_uuid = get_option( Aura_Worker_Magic_Link::APP_PASSWORD_UUID_OPTION );
		$this->assertNotEmpty( $stored_uuid );
		$this->assertCount( 2, WP_Application_Passwords::get_user_application_passwords( 7 ) ); // the stranger + Aura's

		// A second connect rotates ONLY the stored one; the stranger survives, and the stored uuid moves.
		set_transient( 'aura_magic_' . $this->magic_id, array( 'connect_secret' => $this->secret, 'connect_user_id' => 7 ), 600 );
		$this->ml->handle_connect( $this->request() );
		$uuids = array_map( static fn( $i ) => $i['uuid'], WP_Application_Passwords::get_user_application_passwords( 7 ) );
		$this->assertNotContains( $stored_uuid, $uuids, 'the previous Aura password is gone' );
		$this->assertCount( 2, $uuids, 'the stranger stays, one fresh Aura password' );
		$this->assertContains( get_option( Aura_Worker_Magic_Link::APP_PASSWORD_UUID_OPTION ), $uuids );
	}

	public function test_a_reconnect_by_another_admin_revokes_the_previous_creator_s_aura_password(): void {
		// Admin 7 connected first (this connect); then admin 9 reconnects via a new link.
		$this->ml->handle_connect( $this->request() );
		$this->assertCount( 1, WP_Application_Passwords::get_user_application_passwords( 7 ) );
		$this->assertSame( 7, (int) get_option( Aura_Worker_Magic_Link::APP_PASSWORD_OWNER_OPTION ) );
		$GLOBALS['_admins'] = array( 7, 9 );
		set_transient( 'aura_magic_' . $this->magic_id, array( 'connect_secret' => $this->secret, 'connect_user_id' => 9 ), 600 );
		$data = $this->ml->handle_connect( $this->request() )->get_data();
		$this->assertSame( 'user9', $data['app_password']['user_login'] );
		$this->assertSame( array(), WP_Application_Passwords::get_user_application_passwords( 7 ), "the previous creator's Aura password is revoked" );
		$this->assertCount( 1, WP_Application_Passwords::get_user_application_passwords( 9 ) );
		$this->assertSame( 9, (int) get_option( Aura_Worker_Magic_Link::APP_PASSWORD_OWNER_OPTION ) );
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
		// The site claim lives under the swept prefix — an orphan ages out like a link's.
		$this->assertStringStartsWith( Aura_Worker_Rules::MAGIC_CLAIM, Aura_Worker_Magic_Link::SITE_CLAIM );
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
		$this->assertFalse( get_option( Aura_Worker_Magic_Link::APP_PASSWORD_OWNER_OPTION, false ), 'no owner remains' );
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
		$this->assertSame( 7, (int) get_option( Aura_Worker_Magic_Link::APP_PASSWORD_OWNER_OPTION ), 'the owner is still known' );
	}

	public function test_an_owner_record_that_did_not_persist_revokes_the_new_password_and_returns_none(): void {
		$GLOBALS['_sa_option_write_fail'][ Aura_Worker_Magic_Link::APP_PASSWORD_OWNER_OPTION ] = true;
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

	public function test_an_orphaned_site_claim_is_taken_over_after_the_stale_window_but_a_live_one_never_is(): void {
		// A handler killed mid-connect leaves the site claim behind; unlike a
		// per-link orphan it would refuse EVERY later connect (round-6).
		$GLOBALS['_options'][ Aura_Worker_Magic_Link::SITE_CLAIM ] = 'dead-fence|' . ( time() - Aura_Worker_Magic_Link::SITE_CLAIM_STALE_SECONDS - 10 );
		$res = $this->ml->handle_connect( $this->request() );
		$this->assertSame( 200, $res->get_status(), 'a stale site claim is reaped and the connect proceeds' );
		$this->assertFalse( get_option( Aura_Worker_Magic_Link::SITE_CLAIM, false ) );

		// …but a LIVE one (younger than the window) still refuses.
		sa_reset_state();
		$GLOBALS['_admins'] = array( 7 );
		set_transient( 'aura_magic_' . $this->magic_id, array( 'connect_secret' => $this->secret, 'connect_user_id' => 7 ), 600 );
		$GLOBALS['_options'][ Aura_Worker_Magic_Link::SITE_CLAIM ] = 'live-fence|' . time();
		$res = $this->ml->handle_connect( $this->request() );
		$this->assertSame( 409, $res->get_status() );
		$this->assertStringStartsWith( 'live-fence|', (string) get_option( Aura_Worker_Magic_Link::SITE_CLAIM ), 'the live claim is untouched' );
	}

	public function test_regenerating_the_site_token_revokes_the_managed_password(): void {
		$this->ml->handle_connect( $this->request() );
		$uuid = (string) get_option( Aura_Worker_Magic_Link::APP_PASSWORD_UUID_OPTION );
		$this->assertNotEmpty( $uuid );
		// The rotation path calls the ONE revocation.
		$this->assertTrue( Aura_Worker_Magic_Link::revoke_managed_password() );
		$this->assertSame( array(), WP_Application_Passwords::get_user_application_passwords( 7 ) );
		$this->assertFalse( get_option( Aura_Worker_Magic_Link::APP_PASSWORD_UUID_OPTION, false ) );
		$this->assertFalse( get_option( Aura_Worker_Magic_Link::APP_PASSWORD_OWNER_OPTION, false ) );
		// …and the handler that regenerates the token is wired to it.
		$src = file_get_contents( __DIR__ . '/../../digitizer-site-worker/includes/class-aura-worker.php' );
		$this->assertStringContainsString( 'Aura_Worker_Magic_Link::revoke_managed_password()', $src );
	}

	public function test_uninstall_revokes_the_managed_password_by_uuid_before_deleting_its_options(): void {
		$src = file_get_contents( __DIR__ . '/../../digitizer-site-worker/uninstall.php' );
		$this->assertStringContainsString( "WP_Application_Passwords::delete_application_password( \$aura_pw_owner, \$aura_pw_uuid )", $src );
		$this->assertStringContainsString( "get_option( 'aura_worker_app_password_uuid', '' )", $src );
		// The revocation precedes the deletion of the options that identify it.
		$this->assertLessThan(
			strpos( $src, "delete_option( 'aura_worker_app_password_uuid' )" ),
			strpos( $src, 'delete_application_password' ),
			'revoke first, then forget'
		);
	}

	public function test_a_rejected_connect_mints_nothing(): void {
		$req = $this->request();
		$req->set_param( 'signature', 'bogus' );
		$res = $this->ml->handle_connect( $req );
		$this->assertSame( 401, $res->get_status() );
		$this->assertSame( array(), WP_Application_Passwords::get_user_application_passwords( 7 ) );
	}
}
