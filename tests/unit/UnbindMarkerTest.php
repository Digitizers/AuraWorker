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

	/**
	 * Round-1 M1. The two tests above pass with write_under_claim()'s own
	 * guard removed — write_option_if_claimed()'s SQL predicate refuses a
	 * foreign fence too, so the RETURN VALUE cannot tell the two apart. What
	 * the guard uniquely buys is that no statement is issued at all, so that
	 * is what is asserted: it is the one claim guard in this file no test
	 * would otherwise notice disappearing, while cleanup()'s equivalent is
	 * pinned.
	 */
	public function test_write_under_a_foreign_fence_issues_no_statement_at_all(): void {
		Aura_Worker_Magic_Link::claim_site();                      // someone else holds the site
		$GLOBALS['_db_queries'] = array();

		$this->assertFalse( Aura_Worker_Unbind::write_under_claim( $this->marker(), 'not-the-fence' ) );

		$writes = array_values(
			array_filter(
				$GLOBALS['_db_queries'],
				static function ( $q ) {
					return 0 === strncmp( (string) $q, 'UPDATE', 6 ) || 0 === strncmp( (string) $q, 'INSERT', 6 );
				}
			)
		);
		$this->assertSame( array(), $writes, 'the guard refuses before any write statement is issued' );
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
		// An OBJECT, and its JSON is an object too (round-4 M10): Aura parses
		// `unbound` as {at, site_ref}, and a PHP array would reach it as `[]`
		// the moment no field was readable.
		$this->assertIsObject( $body['unbound'] );
		$this->assertSame( array( 'at' => '2026-08-29T10:00:00Z', 'site_ref' => 'res1' ), (array) $body['unbound'] );
		$this->assertSame( '{"at":"2026-08-29T10:00:00Z","site_ref":"res1"}', wp_json_encode( $body['unbound'] ) );
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
	 *
	 * CONTRACT CHANGE, #434 Task 4 round-2 I3: this used to read as `null`,
	 * i.e. "no marker" — a fail-OPEN that un-refuses the site while Aura
	 * believes it disconnected. An array in this row is an ATTEMPTED marker,
	 * so one that does not satisfy the shape now reads as MALFORMED (a
	 * WP_Error), which every caller already fails CLOSED on. The original
	 * intent — status_fragment() must not read a missing field through — is
	 * unchanged and still asserted.
	 */
	public function test_marker_missing_at_reads_malformed_never_absent(): void {
		$marker = $this->marker();
		unset( $marker['at'] );
		$GLOBALS['_rows'][ Aura_Worker_Unbind::OPTION ] = maybe_serialize( $marker );

		$res = Aura_Worker_Unbind::read();

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_unbind_marker_malformed', $res->get_error_code() );
		$this->assertTrue( Aura_Worker_Unbind::is_set(), 'a corrupted marker still means unbound' );
		$this->assertInstanceOf( WP_Error::class, Aura_Worker_Unbind::is_set_strict(), 'and an enforcement boundary fails closed on it' );
		// Round-3 I4: the witness reports the STATE (the row exists, so the
		// site is unbound) and omits the field it cannot read — the original
		// intent, that nothing reads a missing `at` through, is unchanged.
		$fragment = Aura_Worker_Unbind::status_fragment();
		$this->assertIsArray( $fragment );
		$this->assertArrayNotHasKey( 'at', $fragment );
		$this->assertSame( 'res1', $fragment['site_ref'] );
	}

	/**
	 * Round-2 I3, the shape that motivated it: `isset()` is false for a key
	 * that is PRESENT and null, so a marker with a null `site_ref` read as no
	 * marker at all. Task 8's bare body takes `site_ref`/`client` from a
	 * request (`$body['site_ref'] ?? null`), so this is the writer that
	 * produces one.
	 */
	public function test_a_null_site_ref_reads_malformed_never_absent(): void {
		$marker             = $this->marker();
		$marker['site_ref'] = null;
		$GLOBALS['_rows'][ Aura_Worker_Unbind::OPTION ] = maybe_serialize( $marker );

		$res = Aura_Worker_Unbind::read();

		$this->assertInstanceOf( WP_Error::class, $res, 'present-but-null is corrupted, not absent' );
		$this->assertSame( 'aura_unbind_marker_malformed', $res->get_error_code() );
		$this->assertTrue( Aura_Worker_Unbind::is_set(), 'the site keeps refusing' );
		$fragment = Aura_Worker_Unbind::status_fragment();
		$this->assertIsArray( $fragment, 'and /status still reports the site unbound' );
		$this->assertArrayNotHasKey( 'site_ref', $fragment );
		$this->assertSame( '2026-08-29T10:00:00Z', $fragment['at'] );
	}

	public function test_a_null_client_reads_malformed_never_absent(): void {
		$marker           = $this->marker();
		$marker['client'] = null;
		$GLOBALS['_rows'][ Aura_Worker_Unbind::OPTION ] = maybe_serialize( $marker );

		$res = Aura_Worker_Unbind::read();

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_unbind_marker_malformed', $res->get_error_code() );
		$this->assertTrue( Aura_Worker_Unbind::is_set() );
	}

	/**
	 * The type half of the same check: a `seq` that arrives as a string is not
	 * the marker Phase A writes, and the comparisons that decide a retry
	 * (`(int) $back['seq'] === (int) $marker['seq']`) must not be handed one.
	 */
	public function test_a_wrong_typed_seq_reads_malformed(): void {
		$marker        = $this->marker();
		$marker['seq'] = '7';
		$GLOBALS['_rows'][ Aura_Worker_Unbind::OPTION ] = maybe_serialize( $marker );

		$this->assertInstanceOf( WP_Error::class, Aura_Worker_Unbind::read() );
	}

	/**
	 * And the other side of the line: a row that is not an array at all was
	 * never a marker, so it stays genuinely ABSENT. Malformed and absent are
	 * different answers and must not collapse into one.
	 */
	public function test_a_non_array_row_is_still_absent_not_malformed(): void {
		$GLOBALS['_rows'][ Aura_Worker_Unbind::OPTION ] = maybe_serialize( 42 );
		$this->assertNull( Aura_Worker_Unbind::read() );
		$this->assertFalse( Aura_Worker_Unbind::is_set() );
	}

	/**
	 * Round-3 I4. Aura's PATCH and manual-connect preflight keys on the
	 * PRESENCE of `unbound` in /status. A malformed marker refuses every local
	 * boundary, so a silent witness would let Aura write a binding to a site
	 * that will refuse everything — worse than before the marker was corrupted,
	 * when the site was at least consistently bound. The key must be there even
	 * when its contents cannot be.
	 */
	public function test_status_still_reports_unbound_for_a_malformed_marker(): void {
		$marker             = $this->marker();
		$marker['site_ref'] = null;
		$GLOBALS['_rows'][ Aura_Worker_Unbind::OPTION ] = maybe_serialize( $marker );

		$api  = new Aura_Worker_API( new Aura_Worker_Security() );
		$body = $api->get_status( new WP_REST_Request( 'GET', '/aura/v1/status' ) )->get_data();

		$this->assertArrayHasKey( 'unbound', $body, 'the gate and the witness must agree' );
	}

	/**
	 * A marker so corrupted that not one field is readable still reports the
	 * state: an EMPTY object is a correct answer, because the preflight keys
	 * on the key, not on its contents.
	 */
	public function test_status_reports_an_empty_object_when_no_field_is_readable(): void {
		$GLOBALS['_rows'][ Aura_Worker_Unbind::OPTION ] = maybe_serialize( array( 'at' => null, 'site_ref' => null, 'site' => null, 'client' => null, 'seq' => null ) );

		$api  = new Aura_Worker_API( new Aura_Worker_Security() );
		$body = $api->get_status( new WP_REST_Request( 'GET', '/aura/v1/status' ) )->get_data();

		$this->assertArrayHasKey( 'unbound', $body );
		$this->assertSame( array(), (array) $body['unbound'] );
		// M10: `{}`, never `[]`. A strict Aura-side parse of `unbound` as an
		// object rejects a JSON array, and the site would read as bound again
		// — the exact failure the malformed-marker branch exists to prevent.
		$this->assertSame( '{}', wp_json_encode( $body['unbound'] ) );
	}

	/**
	 * And the line the witness must NOT cross: a read it could not complete is
	 * still reported as nothing at all. "The database blipped" is not evidence
	 * that this site is unbound, and /status must not claim it.
	 */
	public function test_status_still_says_nothing_when_the_read_itself_failed(): void {
		$GLOBALS['_sa_option_read_fail'][ Aura_Worker_Unbind::OPTION ] = true;

		$fragment = Aura_Worker_Unbind::status_fragment();

		$GLOBALS['_sa_option_read_fail'] = array();
		$this->assertNull( $fragment );
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
