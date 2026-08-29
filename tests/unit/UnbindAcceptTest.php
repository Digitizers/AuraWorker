<?php
/**
 * Every Aura_Worker_Rules::accept() runs under the site-wide claim (#434),
 * so an unbind (Task 3) and an ordinary ruleset push can never interleave.
 * A held claim answers 503 aura_site_busy, not a stored write.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class UnbindAcceptTest extends TestCase {

	protected function setUp(): void {
		sa_reset_state();
		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
			$this->markTestSkipped( 'ext-sodium is not available.' );
		}
		sa_install_gateway_key();
	}

	public function test_ordinary_accept_takes_and_releases_the_site_claim(): void {
		$env = sa_sign_ruleset( array( 'v' => 1, 'client' => 'c1', 'site' => sa_token_hash(), 'site_ref' => 'r1', 'seq' => 1, 'issued_at' => '2026-08-29T10:00:00Z', 'rules' => array() ) );
		$res = Aura_Worker_Rules::accept( $env );
		// #434 review round 1 (M4): the contract is `true`, not merely
		// "anything but a WP_Error" — assertTrue pins that exactly.
		$this->assertTrue( $res );
		$this->assertSame( '', (string) get_option( Aura_Worker_Magic_Link::SITE_CLAIM, '' ), 'claim released after accept' );
	}

	public function test_accept_refuses_503_busy_while_the_site_claim_is_held(): void {
		$fence = Aura_Worker_Magic_Link::claim_site();
		$env   = sa_sign_ruleset( array( 'v' => 1, 'client' => 'c1', 'site' => sa_token_hash(), 'site_ref' => 'r1', 'seq' => 1, 'issued_at' => '2026-08-29T10:00:00Z', 'rules' => array() ) );
		$res   = Aura_Worker_Rules::accept( $env );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_site_busy', $res->get_error_code() );
		$this->assertSame( 503, $res->get_error_data()['status'] );
		$this->assertNull( Aura_Worker_Rules::current(), 'nothing written' );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	public function test_receive_rules_transports_busy_as_503(): void {
		$fence = Aura_Worker_Magic_Link::claim_site();
		$req   = new WP_REST_Request( 'POST', '/aura/v2/rules' );
		$req->set_param( 'ruleset', sa_sign_ruleset( array( 'v' => 1, 'client' => 'c1', 'site' => sa_token_hash(), 'site_ref' => 'r1', 'seq' => 1, 'issued_at' => 'x', 'rules' => array() ) ) );
		$resp = ( new Aura_Worker_API( new Aura_Worker_Security() ) )->receive_rules( $req );
		$this->assertSame( 503, $resp->get_status() );
		$this->assertSame( 'aura_site_busy', $resp->get_data()['code'] );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	/**
	 * #434 review round 1 (I1): the claim held at entry must still be held
	 * immediately before the write, re-checked on every CAS retry — not
	 * assumed for the whole body. repair_site_claim() (magic-link.php:845,
	 * activation-only) is the one caller that evicts a claim out from under
	 * a live handler; modeled here by evicting mid-decision, between the
	 * store read and the write, via the after-store-read seam.
	 */
	public function test_accept_refuses_when_the_claim_is_evicted_mid_body(): void {
		$env = sa_sign_ruleset( array( 'v' => 1, 'client' => 'c1', 'site' => sa_token_hash(), 'site_ref' => 'r1', 'seq' => 1, 'issued_at' => '2026-08-29T10:00:00Z', 'rules' => array() ) );
		$GLOBALS['_sa_after_store_read'] = function () {
			Aura_Worker_Magic_Link::forget_site_claim();
			$GLOBALS['_sa_after_store_read'] = null;
		};

		$res = Aura_Worker_Rules::accept( $env );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_site_busy', $res->get_error_code() );
		$this->assertSame( 503, $res->get_error_data()['status'] );
		$this->assertNull( Aura_Worker_Rules::current(), 'the evicted handler must not still install its ruleset' );
	}

	/**
	 * #434 review round 1 (M2): pins the release-on-throw half of the
	 * try/finally contract the whole design rests on — a `return` and a
	 * `WP_Error` return are already covered by the tests above.
	 */
	public function test_finally_releases_the_claim_even_when_the_body_throws(): void {
		$env = sa_sign_ruleset( array( 'v' => 1, 'client' => 'c1', 'site' => sa_token_hash(), 'site_ref' => 'r1', 'seq' => 1, 'issued_at' => '2026-08-29T10:00:00Z', 'rules' => array() ) );
		$GLOBALS['_sa_after_store_read'] = function () {
			$GLOBALS['_sa_after_store_read'] = null;
			throw new RuntimeException( 'simulated fatal mid-decision' );
		};

		try {
			Aura_Worker_Rules::accept( $env );
			$this->fail( 'accept() must let the exception propagate, not swallow it' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'simulated fatal mid-decision', $e->getMessage() );
		}
		$this->assertSame( '', (string) get_option( Aura_Worker_Magic_Link::SITE_CLAIM, '' ), 'the finally block must still release the claim' );
	}
}
