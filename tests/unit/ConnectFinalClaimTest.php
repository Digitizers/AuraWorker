<?php
/**
 * A CONNECT EVICTED IN ITS TAIL MUST NOT REPORT SUCCESS (#434, Codex round-8).
 *
 * Every step of the connect verifies the site claim when it acts, but the last
 * such check still had a tail after it. A handler evicted there answered 200
 * carrying a token and an Application Password the replacement connect had
 * already revoked — leaving Aura holding credentials that authenticate
 * nothing, and a site it believes is connected.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class ConnectFinalClaimTest extends TestCase {

	private Aura_Worker_Magic_Link $ml;
	private string $secret   = 'one-time-connect-secret';
	private string $magic_id = 'magic-tail';

	protected function setUp(): void {
		sa_reset_state();
		$this->ml = new Aura_Worker_Magic_Link();
		$GLOBALS['_admins'] = array( 1 );
		set_transient( 'aura_magic_' . $this->magic_id, array( 'connect_secret' => $this->secret, 'connect_user_id' => 1 ), 600 );
	}

	private function request(): WP_REST_Request {
		$token     = 'raw-token';
		$dash      = 'https://dash.example';
		$timestamp = time();
		$req       = new WP_REST_Request();
		$req->set_param( 'magic_id', $this->magic_id );
		$req->set_param( 'token', $token );
		$req->set_param( 'dashboard_url', $dash );
		$req->set_param( 'timestamp', $timestamp );
		$req->set_param( 'signature', Aura_Worker_Magic_Link::sign_connect_payload( $this->secret, $this->magic_id, $token, $dash, $timestamp, '', '' ) );
		return $req;
	}

	/**
	 * THE LEASE IS REFRESHED BY THE CONNECT ITSELF. The refresh changes
	 * nothing observable in a fast request — which is exactly why its absence
	 * has to be asserted directly, rather than inferred from a timestamp two
	 * statements apart in the same second.
	 */
	public function test_the_connect_refreshes_its_lease_once_the_mint_is_behind_it(): void {
		$refreshed = array();
		add_action(
			'aura_worker_connect_lease_refreshed',
			static function ( $ok ) use ( &$refreshed ) {
				$refreshed[] = $ok;
			}
		);

		$this->assertSame( 200, $this->ml->handle_connect( $this->request() )->get_status() );

		$this->assertSame( array( true ), $refreshed, 'the tail ran on the lease the claim was taken with' );
	}

	public function test_an_undisturbed_connect_still_succeeds(): void {
		$this->assertSame( 200, $this->ml->handle_connect( $this->request() )->get_status() );
		$this->assertFalse( get_transient( 'aura_magic_' . $this->magic_id ), 'the link was consumed' );
	}

	/**
	 * The eviction, staged exactly where it hurt: after the last ownership
	 * check, before the response.
	 */
	public function test_a_connect_that_lost_the_site_in_its_tail_answers_409(): void {
		add_action(
			'aura_worker_connect_before_success',
			static function () {
				// Somebody else now holds the site.
				$value = 'the-replacement-fence|' . time();
				$GLOBALS['_options'][ Aura_Worker_Magic_Link::SITE_CLAIM ] = $value;
				$GLOBALS['_rows'][ Aura_Worker_Magic_Link::SITE_CLAIM ]    = $value;
			}
		);

		$res = $this->ml->handle_connect( $this->request() );

		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'aura_site_taken', $res->get_data()['code'] );
		$this->assertNotFalse( get_transient( 'aura_magic_' . $this->magic_id ), 'the link stays usable for the retry' );
		$this->assertSame(
			'the-replacement-fence|' . time(),
			sa_read_option_uncached( Aura_Worker_Magic_Link::SITE_CLAIM ),
			"the loser's release must not remove the winner's claim"
		);
	}
}
