<?php
/**
 * THE REFUSAL SURVIVES ITS OWN CLEANUP (#434, Codex round-6 P1).
 *
 * Phase B step (4) deletes the gateway public key while the site is still
 * marked — deliberately, so the fast path can answer a retry on the token
 * alone. But both callers of Aura_Worker_Grant::verify() skipped it entirely
 * when no key was configured, and the marker refusal lived INSIDE verify(). A
 * mutating request that had already passed its permission callback would then
 * find no key, skip the grant path, and never meet the marker at all.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class UnbindKeylessRefusalTest extends TestCase {

	protected function setUp(): void {
		sa_reset_state();
		sa_token_hash();
		$GLOBALS['_admins'] = array( 3 );
		delete_option( 'aura_worker_grant_pubkey' ); // Phase B step (4) already ran
	}

	private function request(): WP_REST_Request {
		$req = new WP_REST_Request( 'POST', '/aura/v1/update/plugin' );
		$req->set_header( 'X-Aura-Token', SA_RAW_SITE_TOKEN );
		return $req;
	}

	public function test_a_keyless_marked_site_refuses_a_guarded_write(): void {
		sa_set_marker();

		$guard = Aura_Worker_Grant::require_for( $this->request(), 'wp.update.plugin', array( 'plugin' => 'x/x.php' ) );

		$this->assertInstanceOf( WP_Error::class, $guard );
		$this->assertSame( 'aura_site_unbound', $guard->get_error_code() );
	}

	/**
	 * A malformed marker refuses too — is_set() is true for a row this build
	 * cannot read, and unreadable is not innocent.
	 */
	public function test_a_keyless_site_with_an_unreadable_marker_refuses(): void {
		$GLOBALS['_rows'][ Aura_Worker_Unbind::OPTION ] = maybe_serialize( 'not-a-marker' );

		$guard = Aura_Worker_Grant::require_for( $this->request(), 'wp.update.plugin', array( 'plugin' => 'x/x.php' ) );

		$this->assertInstanceOf( WP_Error::class, $guard );
		$this->assertSame( 'aura_site_unbound', $guard->get_error_code() );
	}

	/** A keyless site that is still BOUND is untouched: no key, no grant, no refusal. */
	public function test_a_keyless_bound_site_still_passes(): void {
		$this->assertTrue( Aura_Worker_Grant::require_for( $this->request(), 'wp.update.plugin', array( 'plugin' => 'x/x.php' ) ) );
	}

	/**
	 * And the refusal reaches the caller as ITSELF. require_for() renders
	 * verify()'s answer into a message with `.` — a WP_Error there is a fatal,
	 * not a 403.
	 */
	public function test_the_refusal_is_returned_not_concatenated(): void {
		sa_install_gateway_key(); // a keyed site: verify() runs and answers the refusal
		sa_set_marker();

		$guard = Aura_Worker_Grant::require_for( $this->request(), 'wp.update.plugin', array( 'plugin' => 'x/x.php' ) );

		$this->assertInstanceOf( WP_Error::class, $guard );
		$this->assertSame( 'aura_site_unbound', $guard->get_error_code() );
	}
}
