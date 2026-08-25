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

	public function test_every_connect_rotates_deleting_earlier_aura_passwords_only(): void {
		WP_Application_Passwords::create_new_application_password( 7, array( 'name' => Aura_Worker_Magic_Link::APP_PASSWORD_NAME ) );
		WP_Application_Passwords::create_new_application_password( 7, array( 'name' => Aura_Worker_Magic_Link::APP_PASSWORD_NAME ) );
		WP_Application_Passwords::create_new_application_password( 7, array( 'name' => 'Something else' ) );
		$res = $this->ml->handle_connect( $this->request() );
		$this->assertSame( 200, $res->get_status() );
		$names = array_map( static fn( $i ) => $i['name'], WP_Application_Passwords::get_user_application_passwords( 7 ) );
		sort( $names );
		$this->assertSame( array( Aura_Worker_Magic_Link::APP_PASSWORD_NAME, 'Something else' ), $names ); // one Aura password, the stranger untouched
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

	public function test_a_rejected_connect_mints_nothing(): void {
		$req = $this->request();
		$req->set_param( 'signature', 'bogus' );
		$res = $this->ml->handle_connect( $req );
		$this->assertSame( 401, $res->get_status() );
		$this->assertSame( array(), WP_Application_Passwords::get_user_application_passwords( 7 ) );
	}
}
