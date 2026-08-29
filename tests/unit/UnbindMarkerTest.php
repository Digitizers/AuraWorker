<?php
/**
 * The unbind marker (#434, spec §2.3): one option, written under the site
 * claim, always read uncached. This task covers only the marker's own
 * read/write/delete surface and its appearance in GET /aura/v1/status —
 * Phase A/B and the mutation-boundary refusal land in later tasks.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class UnbindMarkerTest extends TestCase {

	protected function setUp(): void {
		sa_reset_state();
	}

	private function marker(): array {
		return array(
			'at'                  => '2026-08-29T10:00:00Z',
			'site'                => str_repeat( 'a', 64 ),
			'site_ref'            => 'res1',
			'client'              => 'c1',
			'seq'                 => 7,
			'connect_user_id'     => 3,
			'app_password_uuids'  => array( 'u-1' ),
			'app_password_users'  => array( 'u-1' => 3 ),
		);
	}

	public function test_absent_marker_reads_null_and_is_not_set(): void {
		$this->assertNull( Aura_Worker_Unbind::read() );
		$this->assertFalse( Aura_Worker_Unbind::is_set() );
		$this->assertNull( Aura_Worker_Unbind::status_fragment() );
	}

	public function test_write_under_claim_persists_uncached_and_is_read_back(): void {
		$fence = Aura_Worker_Magic_Link::claim_site();
		$this->assertNotSame( '', $fence );
		$this->assertTrue( Aura_Worker_Unbind::write_under_claim( $this->marker(), $fence ) );
		$this->assertSame( 'res1', Aura_Worker_Unbind::read()['site_ref'] );
		$this->assertSame( array( 'at' => '2026-08-29T10:00:00Z', 'site_ref' => 'res1' ), Aura_Worker_Unbind::status_fragment() );
		// The row must land autoload='no' — a marker only Task 5's
		// mutation-boundary checks read must never be pulled onto every page
		// load. $GLOBALS['_rows_autoload'] is populated by the bootstrap's
		// claim-fenced INSERT emulation (tests/bootstrap.php), which is the
		// exact statement write_under_claim() issues via
		// write_option_if_claimed().
		$this->assertSame( 'no', $GLOBALS['_rows_autoload'][ Aura_Worker_Unbind::OPTION ] ?? null );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	public function test_write_without_the_claim_writes_nothing(): void {
		$this->assertFalse( Aura_Worker_Unbind::write_under_claim( $this->marker(), '' ) );
		$this->assertNull( Aura_Worker_Unbind::read() );
	}

	public function test_write_with_a_lost_claim_writes_nothing(): void {
		$fence = Aura_Worker_Magic_Link::claim_site();
		Aura_Worker_Magic_Link::release_site( $fence );
		$this->assertFalse( Aura_Worker_Unbind::write_under_claim( $this->marker(), $fence ) );
		$this->assertNull( Aura_Worker_Unbind::read() );
	}

	public function test_delete_under_claim_only(): void {
		$fence = Aura_Worker_Magic_Link::claim_site();
		Aura_Worker_Unbind::write_under_claim( $this->marker(), $fence );
		$this->assertFalse( Aura_Worker_Unbind::delete_under_claim( '' ) );
		$this->assertTrue( Aura_Worker_Unbind::is_set() );
		$this->assertTrue( Aura_Worker_Unbind::delete_under_claim( $fence ) );
		$this->assertFalse( Aura_Worker_Unbind::is_set() );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	public function test_garbage_marker_reads_null(): void {
		$GLOBALS['_options'][ Aura_Worker_Unbind::OPTION ] = 'not-an-array';
		$GLOBALS['_rows'][ Aura_Worker_Unbind::OPTION ]    = 'not-an-array';
		$this->assertNull( Aura_Worker_Unbind::read() );
	}

	public function test_status_reports_the_marker(): void {
		$fence = Aura_Worker_Magic_Link::claim_site();
		Aura_Worker_Unbind::write_under_claim( $this->marker(), $fence );
		Aura_Worker_Magic_Link::release_site( $fence );
		$api  = new Aura_Worker_API( new Aura_Worker_Security() );
		$body = $api->get_status( new WP_REST_Request( 'GET', '/aura/v1/status' ) )->get_data();
		$this->assertSame( array( 'at' => '2026-08-29T10:00:00Z', 'site_ref' => 'res1' ), $body['unbound'] );
	}

	public function test_status_omits_unbound_when_no_marker(): void {
		$api  = new Aura_Worker_API( new Aura_Worker_Security() );
		$body = $api->get_status( new WP_REST_Request( 'GET', '/aura/v1/status' ) )->get_data();
		$this->assertArrayNotHasKey( 'unbound', $body );
	}

	/**
	 * A marker missing `at` (corruption, or a future writer that forgets the
	 * field) must be rejected like any other malformed marker — not read
	 * through into status_fragment()'s unconditional `(string) $m['at']` and
	 * trigger an undefined-key warning.
	 */
	public function test_marker_missing_at_reads_null(): void {
		$marker = $this->marker();
		unset( $marker['at'] );
		$GLOBALS['_rows'][ Aura_Worker_Unbind::OPTION ] = maybe_serialize( $marker );
		$this->assertNull( Aura_Worker_Unbind::read() );
		$this->assertNull( Aura_Worker_Unbind::status_fragment() );
	}

	/**
	 * A genuine database failure on the uncached read is NOT "no marker" —
	 * $wpdb->get_var() answers null for both, and read() must tell them apart
	 * (mirrors Aura_Worker_Rules::stored_uncached(), round-16/Codex).
	 * Collapsing the two would let a transient DB blip on a genuinely-unbound
	 * site read as "site is bound" and let a mutation through.
	 */
	public function test_read_bubbles_a_database_error_instead_of_reading_absent(): void {
		$GLOBALS['_sa_wpdb_error'] = 'MySQL server has gone away';
		$res                       = Aura_Worker_Unbind::read();
		$GLOBALS['_sa_wpdb_error'] = '';
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * is_set() is the plain-boolean convenience for callers that only want a
	 * display/witness answer — it deliberately FAILS OPEN on a database
	 * error (treats "unknown" as "unbound"). A mutation boundary must use
	 * is_set_strict() instead; that contract is exercised below.
	 */
	public function test_is_set_fails_open_on_a_database_error(): void {
		$GLOBALS['_sa_wpdb_error'] = 'MySQL server has gone away';
		$is_set                    = Aura_Worker_Unbind::is_set();
		$GLOBALS['_sa_wpdb_error'] = '';
		$this->assertTrue( $is_set );
	}

	public function test_is_set_strict_matches_is_set_when_the_read_succeeds(): void {
		$this->assertFalse( Aura_Worker_Unbind::is_set_strict() );
		$fence = Aura_Worker_Magic_Link::claim_site();
		Aura_Worker_Unbind::write_under_claim( $this->marker(), $fence );
		Aura_Worker_Magic_Link::release_site( $fence );
		$this->assertTrue( Aura_Worker_Unbind::is_set_strict() );
	}

	/**
	 * The strict form Task 5/6's enforcement boundary must use: it surfaces
	 * the WP_Error rather than collapsing it, so the caller can fail CLOSED
	 * (refuse the mutation) instead of assuming "not unbound".
	 */
	public function test_is_set_strict_surfaces_a_database_error(): void {
		$GLOBALS['_sa_wpdb_error'] = 'MySQL server has gone away';
		$res                       = Aura_Worker_Unbind::is_set_strict();
		$GLOBALS['_sa_wpdb_error'] = '';
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * /status is a witness, not a gate: it must not claim "no marker" with
	 * the same confidence as a genuine absence when the read itself failed.
	 * status_fragment() answers null either way (the two are indistinguishable
	 * from a REST consumer's perspective — a security decision needs
	 * is_set_strict(), not this field).
	 */
	public function test_status_fragment_returns_null_on_a_database_error(): void {
		$GLOBALS['_sa_wpdb_error'] = 'MySQL server has gone away';
		$fragment                  = Aura_Worker_Unbind::status_fragment();
		$GLOBALS['_sa_wpdb_error'] = '';
		$this->assertNull( $fragment );
	}
}
