<?php
/**
 * The unkeyed fallback (#434 Task 8): a manually connected site holds no
 * `aura_worker_grant_pubkey`, so no signed envelope can verify there and every
 * push answers 412 `no_gateway_key` — which would strand its unbind tombstone
 * forever. Such a site's only binding IS its token, and /aura/v2/rules refuses
 * every request that does not present it, so the token is sufficient authority
 * for the one operation that ends that binding: a bare `{ unbind: true, … }`
 * body with no envelope at all.
 *
 * Narrow on purpose — a site that CAN verify a signature refuses this form.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class UnbindUnkeyedTest extends TestCase {

	/** This site's token hash, remembered BEFORE the unbind deletes it — sa_token_hash() re-installs the token when it is gone. */
	private $hash;

	protected function setUp(): void {
		sa_reset_state();
		$this->hash         = sa_token_hash();  // the site token, and NO gateway key
		$GLOBALS['_admins'] = array( 3 );       // someone for a token-only request to run as
	}

	private function api(): Aura_Worker_API {
		return new Aura_Worker_API( new Aura_Worker_Security() );
	}

	/**
	 * The bare body, as a request. Every value is set the way a caller sets
	 * it — nothing is pre-validated on its way in.
	 *
	 * @param array $over Fields to override; a value of null unsets the field.
	 * @return WP_REST_Request
	 */
	private function bare( array $over = array() ): WP_REST_Request {
		$req    = new WP_REST_Request( 'POST', '/aura/v2/rules' );
		$fields = array_merge(
			array( 'unbind' => true, 'site_ref' => 'r1', 'client' => 'c1', 'seq' => 4, 'final' => true ),
			$over
		);
		$req->set_header( 'X-Aura-Token', SA_RAW_SITE_TOKEN );
		foreach ( $fields as $k => $v ) {
			if ( null === $v ) {
				continue; // an explicit null override means "the caller sent no such field"
			}
			$req->set_param( $k, $v );
		}
		return $req;
	}

	/**
	 * The marker as STORED — not Aura_Worker_Unbind::read(), which normalises
	 * on the way out and would report types this test is asking about.
	 *
	 * @return mixed
	 */
	private function stored_marker() {
		$raw = sa_read_option_uncached( Aura_Worker_Unbind::OPTION );
		return null === $raw ? null : maybe_unserialize( $raw );
	}

	// -----------------------------------------------------------------------
	// The path itself.
	// -----------------------------------------------------------------------

	public function test_bare_unbind_marks_and_cleans_on_an_unkeyed_site(): void {
		$resp = $this->api()->receive_rules( $this->bare() );

		$this->assertSame( 200, $resp->get_status() );
		// `leftovers` travels on the bare answer exactly as it does on the
		// enveloped one (Task 4 M9): empty means "nothing is owed", and an
		// ABSENT field would read as "something may be", which is a different
		// decision on Aura's side.
		$this->assertSame(
			array( 'success' => true, 'seq' => 4, 'unbound' => true, 'cleanup_complete' => true, 'leftovers' => array() ),
			$resp->get_data()
		);
		$m = $this->stored_marker();
		$this->assertIsArray( $m );
		$this->assertSame( $this->hash, $m['site'] );
		$this->assertSame( 'c1', $m['client'] );
		$this->assertSame( 4, $m['seq'] );
		$this->assertSame( 'r1', $m['site_ref'] );
		$this->assertFalse( get_option( 'aura_worker_site_token' ), 'a final unbind deleted the token last' );
	}

	public function test_a_keyed_site_refuses_the_bare_form_400_and_writes_nothing(): void {
		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
			$this->markTestSkipped( 'ext-sodium is not available.' );
		}
		sa_install_gateway_key();

		$resp = $this->api()->receive_rules( $this->bare() );

		$this->assertSame( 400, $resp->get_status() );
		$this->assertSame( 'aura_ruleset_rejected', $resp->get_data()['code'] );
		$this->assertFalse( Aura_Worker_Unbind::is_set() );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ) );
	}

	/**
	 * A key that is PRESENT but unusable is still a keyed site: the answer to
	 * a key this site cannot verify with is "reconnect" (the 412 on the
	 * enforced path), never a token-only unbind. has_usable_key() would say
	 * "no key" for every row below and open the path.
	 *
	 * The whitespace rows are round-1 LOW-1: Aura_Worker_Grant::is_enforced()
	 * calls such a site keyed, so trimming the value here would have two parts
	 * of the plugin disagree about the same site — and this is the side that
	 * would admit a token-only eviction.
	 *
	 * @dataProvider present_but_unusable_keys
	 * @param string $key The stored `aura_worker_grant_pubkey`.
	 */
	public function test_a_key_this_site_cannot_use_is_still_a_key( string $key ): void {
		$GLOBALS['_options']['aura_worker_grant_pubkey'] = $key;

		$resp = $this->api()->receive_rules( $this->bare() );

		$this->assertSame( 400, $resp->get_status() );
		$this->assertSame( 'aura_ruleset_rejected', $resp->get_data()['code'] );
		$this->assertFalse( Aura_Worker_Unbind::is_set() );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ) );
	}

	public static function present_but_unusable_keys(): array {
		return array(
			'garbage'      => array( 'not-a-key' ),
			'truncated'    => array( base64_encode( 'too-short' ) ),
			'one space'    => array( ' ' ),
			'whitespace'   => array( "  \t\n " ),
		);
	}

	/**
	 * A gateway key that could not be READ is not an absent one. $wpdb->get_var()
	 * answers null for both, so collapsing them would let a transient database
	 * error open the very path a keyed site must refuse. Scoped to that one
	 * option, so the rest of the request still works and the assertion cannot
	 * be satisfied by a later shared failure.
	 */
	public function test_an_unreadable_gateway_key_is_not_an_absent_one(): void {
		$GLOBALS['_sa_option_read_fail']['aura_worker_grant_pubkey'] = true;

		$resp = $this->api()->receive_rules( $this->bare() );

		$this->assertSame( 500, $resp->get_status() );
		$this->assertSame( 'aura_ruleset_store_failed', $resp->get_data()['code'] );
		$this->assertFalse( Aura_Worker_Unbind::is_set(), 'nothing was written' );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ) );
	}

	public function test_unkeyed_site_without_unbind_still_answers_412(): void {
		$req = new WP_REST_Request( 'POST', '/aura/v2/rules' );
		$req->set_param( 'ruleset', 'x' );

		$resp = $this->api()->receive_rules( $req );

		$this->assertSame( 412, $resp->get_status() );
		$this->assertSame( 'no_gateway_key', $resp->get_data()['code'] );
	}

	public function test_a_non_final_bare_unbind_keeps_the_token(): void {
		$resp = $this->api()->receive_rules( $this->bare( array( 'final' => false ) ) );

		$this->assertSame( 200, $resp->get_status() );
		$this->assertFalse( $resp->get_data()['cleanup_complete'], 'the token was kept, deliberately' );
		$this->assertSame( array(), $resp->get_data()['leftovers'], 'and nothing is owed' );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ) );
	}

	// -----------------------------------------------------------------------
	// The body is untrusted input. A caller must never be able to write a
	// marker that reads back MALFORMED — that refuses every mutation on the
	// site with no way back short of the operator's teardown (Task 4).
	// -----------------------------------------------------------------------

	/**
	 * @dataProvider bad_bodies
	 */
	public function test_a_body_this_site_will_not_store_is_refused_and_writes_nothing( array $over ): void {
		$resp = $this->api()->receive_rules( $this->bare( $over ) );

		$this->assertSame( 400, $resp->get_status() );
		$this->assertSame( 'aura_ruleset_rejected', $resp->get_data()['code'] );
		$this->assertFalse( Aura_Worker_Unbind::is_set(), 'nothing was written' );
		$this->assertNull( $this->stored_marker() );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ), 'and nothing was cleaned up' );
	}

	public static function bad_bodies(): array {
		return array(
			'client empty'          => array( array( 'client' => '' ) ),
			'client whitespace'     => array( array( 'client' => "  \n" ) ),
			'client absent'         => array( array( 'client' => null ) ),
			'client not a string'   => array( array( 'client' => array( 'c1' ) ) ),
			'client too long'       => array( array( 'client' => str_repeat( 'c', 192 ) ) ),
			'site_ref not a string' => array( array( 'site_ref' => array( 'r1' ) ) ),
			'site_ref too long'     => array( array( 'site_ref' => str_repeat( 'r', 192 ) ) ),
			'seq absent'            => array( array( 'seq' => null ) ),
			'seq negative'          => array( array( 'seq' => -1 ) ),
			'seq not a number'      => array( array( 'seq' => 'four' ) ),
			'seq a float'           => array( array( 'seq' => 4.5 ) ),
			'seq true'              => array( array( 'seq' => true ) ),
		);
	}

	/**
	 * Only an unambiguous `true` opts into the bare form. A body that merely
	 * looks truthy is NOT an unbind — reading one as an unbind writes a marker
	 * nobody meant and disconnects a live site — so it falls through to the
	 * ruleset form and is told to send one of the two.
	 *
	 * @dataProvider not_an_unbind
	 * @param mixed $value The `unbind` value.
	 */
	public function test_only_an_unambiguous_true_opts_into_the_bare_form( $value ): void {
		$req = $this->bare();
		$req->set_param( 'unbind', $value );

		$resp = $this->api()->receive_rules( $req );

		$this->assertSame( 400, $resp->get_status() );
		$this->assertSame( 'aura_ruleset_rejected', $resp->get_data()['code'] );
		$this->assertFalse( Aura_Worker_Unbind::is_set(), 'nothing was written' );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ) );
	}

	public static function not_an_unbind(): array {
		return array(
			'false'      => array( false ),
			'zero'       => array( 0 ),
			"the string" => array( '0' ),
			'yes'        => array( 'yes' ),
			'no'         => array( 'no' ),
			'an array'   => array( array( true ) ),
			'a float'    => array( 1.0 ),
		);
	}

	/**
	 * `0` is a seq like any other. `??`-style absence checks do not fall
	 * through an integer zero, and neither may this one.
	 */
	public function test_seq_zero_is_a_seq_not_an_absence(): void {
		$resp = $this->api()->receive_rules( $this->bare( array( 'seq' => 0 ) ) );

		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( 0, $resp->get_data()['seq'] );
		$this->assertSame( 0, $this->stored_marker()['seq'] );
	}

	/**
	 * A form-encoded body carries every value as a string, so a digit string is
	 * a seq — stored as an INT, because a marker whose `seq` is a string reads
	 * back malformed.
	 */
	public function test_a_digit_string_seq_is_stored_as_an_int(): void {
		$resp = $this->api()->receive_rules( $this->bare( array( 'seq' => '7', 'final' => '1' ) ) );

		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( 7, $resp->get_data()['seq'] );
		$this->assertSame( 7, $this->stored_marker()['seq'] );
		$this->assertFalse( get_option( 'aura_worker_site_token' ), "'1' says final, as a form body spells it" );
	}

	/**
	 * A seq past PHP_INT_MAX saturates on cast, so the marker would hold a
	 * number the caller never sent — and the seq is ECHOED for Aura to match
	 * against the tombstone it pushed.
	 */
	public function test_a_seq_that_would_not_survive_the_cast_is_refused(): void {
		$resp = $this->api()->receive_rules( $this->bare( array( 'seq' => '99999999999999999999' ) ) );

		$this->assertSame( 400, $resp->get_status() );
		$this->assertFalse( Aura_Worker_Unbind::is_set() );
	}

	/**
	 * Task 4's review asked for exactly this: a marker written from a body
	 * that carries no `site_ref` must still read as SET — a stored `null`
	 * there reads MALFORMED, and a malformed marker wedges the site.
	 */
	public function test_a_body_without_site_ref_writes_a_marker_that_reads_as_set(): void {
		$resp = $this->api()->receive_rules( $this->bare( array( 'site_ref' => null ) ) );

		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( '', $this->stored_marker()['site_ref'], "the empty string, never null" );
		$this->assertTrue( Aura_Worker_Unbind::is_set() );
		$this->assertIsArray( Aura_Worker_Unbind::read(), 'readable, not malformed' );
		$this->assertNotNull( Aura_Worker_Unbind::status_fragment(), 'and the site can say what happened to it' );
	}

	/** Every field the marker's own type check reads, at the type it demands. */
	public function test_the_marker_a_bare_body_writes_is_typed_the_way_read_demands(): void {
		$this->api()->receive_rules( $this->bare( array( 'seq' => '4', 'client' => ' c1 ', 'site_ref' => ' r1 ' ) ) );

		$m = $this->stored_marker();
		$this->assertIsString( $m['at'] );
		$this->assertIsString( $m['site'] );
		$this->assertIsString( $m['client'] );
		$this->assertIsString( $m['site_ref'] );
		$this->assertIsInt( $m['seq'] );
		$this->assertSame( 'c1', $m['client'], 'trimmed' );
		$this->assertSame( 'r1', $m['site_ref'], 'trimmed' );
	}

	// -----------------------------------------------------------------------
	// Ordering, and the retry.
	// -----------------------------------------------------------------------

	public function test_bare_retry_hits_the_fast_path_and_echoes_its_own_seq(): void {
		$this->api()->receive_rules( $this->bare( array( 'seq' => 4, 'final' => false ) ) );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ) );

		// The sibling tombstone's clearSeq — a DIFFERENT seq from the one that
		// wrote the marker. Echoing the marker's would fail Aura's seq check
		// after the token is already gone, stranding this very tombstone.
		$resp = $this->api()->receive_rules( $this->bare( array( 'seq' => 11, 'final' => true ) ) );

		$this->assertSame( 11, $resp->get_data()['seq'] );
		$this->assertTrue( $resp->get_data()['cleanup_complete'] );
		$this->assertSame( 4, $this->stored_marker()['seq'], "the marker keeps its own seq" );
		$this->assertFalse( get_option( 'aura_worker_site_token' ) );
	}

	/**
	 * Phase B deletes the gateway key at step (4) and the token at step (5), so
	 * an unbind interrupted BEFORE step (4) leaves a marked site that still
	 * holds its key. The marker fast path must answer that retry — judging it
	 * by the key would refuse the one request that can finish the teardown.
	 */
	public function test_the_fast_path_answers_before_the_keyed_refusal(): void {
		sa_set_marker( array( 'site' => $this->hash, 'seq' => 9 ) );
		$GLOBALS['_options']['aura_worker_grant_pubkey'] = 'still-here';

		$resp = $this->api()->receive_rules( $this->bare( array( 'seq' => 12 ) ) );

		$this->assertSame( 200, $resp->get_status() );
		$this->assertTrue( $resp->get_data()['unbound'] );
		$this->assertSame( 12, $resp->get_data()['seq'] );
		$this->assertFalse( get_option( 'aura_worker_grant_pubkey' ), 'step (4) finished' );
		$this->assertFalse( get_option( 'aura_worker_site_token' ), 'and step (5) after it' );
	}

	/**
	 * A bare body presented with a token that is not the marker's: some other
	 * binding talking to an already-unbound site. 403, nothing touched.
	 */
	public function test_another_binding_is_refused_by_the_marker(): void {
		sa_set_marker( array( 'site' => str_repeat( 'a', 64 ), 'seq' => 9 ) );

		$resp = $this->api()->receive_rules( $this->bare() );

		$this->assertSame( 403, $resp->get_status() );
		$this->assertSame( 'aura_site_unbound', $resp->get_data()['code'] );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ) );
	}

	/**
	 * A marker whose `site` is '' could never be matched by a retry — the fast
	 * path answers refusal() on an empty token — so the site would refuse every
	 * mutation forever with no way back. Reachable only if the token is deleted
	 * between the route's permission check and the handler.
	 */
	public function test_a_site_with_no_token_writes_no_marker(): void {
		unset( $GLOBALS['_options']['aura_worker_site_token'] );

		$resp = $this->api()->receive_rules( $this->bare() );

		$this->assertSame( 400, $resp->get_status() );
		$this->assertFalse( Aura_Worker_Unbind::is_set() );
	}

	public function test_a_held_site_claim_answers_503_and_writes_nothing(): void {
		$fence = Aura_Worker_Magic_Link::claim_site();

		$resp = $this->api()->receive_rules( $this->bare() );

		$this->assertSame( 503, $resp->get_status() );
		$this->assertSame( 'aura_site_busy', $resp->get_data()['code'] );
		$this->assertFalse( Aura_Worker_Unbind::is_set() );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	// -----------------------------------------------------------------------
	// The wire: through the registered route, not the handler.
	// -----------------------------------------------------------------------

	public function test_bare_body_passes_route_argument_validation_when_dispatched(): void {
		$resp = sa_dispatch_route( $this->bare() );

		$this->assertSame( 200, $resp->get_status() );
		$this->assertTrue( $resp->get_data()['unbound'] );
		$this->assertSame( 4, $resp->get_data()['seq'] );
	}

	public function test_dispatched_request_with_neither_form_is_400(): void {
		$req = new WP_REST_Request( 'POST', '/aura/v2/rules' );
		$req->set_header( 'X-Aura-Token', SA_RAW_SITE_TOKEN );

		$resp = sa_dispatch_route( $req );

		$this->assertSame( 400, $resp->get_status() );
		$this->assertSame( 'aura_ruleset_rejected', $resp->get_data()['code'] );
		$this->assertFalse( Aura_Worker_Unbind::is_set() );
	}

	/**
	 * The whole authority of this form is the token, and it is the ROUTE that
	 * proves it: a bare body with the wrong one never reaches the handler.
	 */
	public function test_a_wrong_token_is_refused_before_the_handler_runs(): void {
		$req = $this->bare();
		$req->set_header( 'X-Aura-Token', 'not-the-token' );

		$refusal = sa_dispatch_permission( $req );

		$this->assertInstanceOf( WP_Error::class, $refusal );
		$this->assertSame( 'aura_invalid_token', $refusal->get_error_code() );
		$this->assertSame( 401, $refusal->get_error_data()['status'] );
		$this->assertFalse( Aura_Worker_Unbind::is_set() );
	}

	// -----------------------------------------------------------------------
	// The client cross-check (round-1 LOW-3) — defence in depth, not
	// authorisation: the token is what proves the caller may unbind.
	// -----------------------------------------------------------------------

	public function test_a_bare_body_naming_another_client_is_refused(): void {
		Aura_Worker_Rules::bind( 'c1', $this->hash );

		$resp = $this->api()->receive_rules( $this->bare( array( 'client' => 'someone-else' ) ) );

		$this->assertSame( 409, $resp->get_status() );
		$this->assertSame( 'aura_ruleset_client_mismatch', $resp->get_data()['code'] );
		$this->assertFalse( Aura_Worker_Unbind::is_set(), 'nothing was written' );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ) );
	}

	public function test_a_bare_body_naming_the_bound_client_is_accepted(): void {
		Aura_Worker_Rules::bind( 'c1', $this->hash );

		$resp = $this->api()->receive_rules( $this->bare() );

		$this->assertSame( 200, $resp->get_status() );
		$this->assertTrue( $resp->get_data()['unbound'] );
		$this->assertSame( array(), $resp->get_data()['leftovers'] );
	}

	/**
	 * The check is conditional on the record, exactly as the enveloped path's
	 * is. A site with nothing stored — the manually connected case this whole
	 * path exists for — is not cross-checked against a binding it never had,
	 * and an unbind must never become impossible because the store the site no
	 * longer needs is missing.
	 */
	public function test_a_site_with_no_stored_record_is_not_cross_checked(): void {
		$resp = $this->api()->receive_rules( $this->bare( array( 'client' => 'anything-at-all' ) ) );

		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( 'anything-at-all', $this->stored_marker()['client'] );
	}

	/**
	 * A record bound to a token that is no longer this site's binds nobody —
	 * bound_client() answers '' for it — so it cannot refuse an unbind either.
	 */
	public function test_a_stale_record_does_not_cross_check(): void {
		Aura_Worker_Rules::bind( 'c1', str_repeat( 'a', 64 ) );

		$resp = $this->api()->receive_rules( $this->bare( array( 'client' => 'c2' ) ) );

		$this->assertSame( 200, $resp->get_status() );
	}

	// -----------------------------------------------------------------------
	// The transport itself (round-1 LOW-2). Both producers always send
	// `leftovers`, so its default is unreachable from receive_rules() — and a
	// defence no test can reach is indistinguishable from a wrong one. The
	// mapping is called directly here, with the answer no producer makes.
	// -----------------------------------------------------------------------

	public function test_an_answer_carrying_no_leftovers_is_transported_as_everything_owed(): void {
		$body = Aura_Worker_API::unbind_response(
			array( 'unbound' => true, 'seq' => 4, 'cleanup_complete' => false )
		)->get_data();

		$this->assertSame(
			array( 'app_passwords', 'options', 'ruleset', 'grant_pubkey' ),
			$body['leftovers'],
			'an answer that made no claim must never be read as "owes nothing"'
		);
		$this->assertNotSame( array(), $body['leftovers'] );
	}

	/**
	 * The same for a `leftovers` that is present but not a list: it is not a
	 * claim either.
	 */
	public function test_a_leftovers_that_is_not_a_list_is_transported_as_everything_owed(): void {
		$body = Aura_Worker_API::unbind_response(
			array( 'unbound' => true, 'seq' => 4, 'cleanup_complete' => false, 'leftovers' => 'app_passwords' )
		)->get_data();

		$this->assertSame( array( 'app_passwords', 'options', 'ruleset', 'grant_pubkey' ), $body['leftovers'] );
	}

	/** And a list that IS there travels verbatim — the default never overrides one. */
	public function test_a_leftovers_list_travels_verbatim(): void {
		$body = Aura_Worker_API::unbind_response(
			array( 'unbound' => true, 'seq' => 4, 'cleanup_complete' => false, 'leftovers' => array( 'app_passwords' ) )
		)->get_data();

		$this->assertSame( array( 'app_passwords' ), $body['leftovers'] );

		$empty = Aura_Worker_API::unbind_response(
			array( 'unbound' => true, 'seq' => 4, 'cleanup_complete' => true, 'leftovers' => array() )
		)->get_data();

		$this->assertSame( array(), $empty['leftovers'], 'a proven-empty list is a claim and must survive' );
	}

	// -----------------------------------------------------------------------
	// One shape, two forms.
	// -----------------------------------------------------------------------

	/**
	 * The INTERNAL answer, not the transported one: receive_rules() supplies a
	 * `leftovers` list for an answer that carries none, so a producer that
	 * dropped the field would still put a plausible list on the wire. This path
	 * must carry its own — what is owed is a question only Phase B's own call
	 * can answer.
	 */
	public function test_the_bare_answer_carries_its_own_leftovers(): void {
		$res = Aura_Worker_Rules::accept_bare_unbind(
			array( 'site_ref' => 'r1', 'client' => 'c1', 'seq' => 4, 'final' => true )
		);

		$this->assertIsArray( $res );
		$this->assertSame( array( 'unbound', 'seq', 'cleanup_complete', 'leftovers' ), array_keys( $res ) );
		$this->assertIsArray( $res['leftovers'] );
		$this->assertSame( array(), $res['leftovers'] );
	}

	/**
	 * Aura reads ONE contract off this route. The bare answer and the enveloped
	 * one are built from the same state and must be identical — same keys, same
	 * order, same types — because Aura branches on `leftovers` and would read a
	 * missing field as "something may still be owed".
	 */
	public function test_the_bare_answer_is_the_enveloped_answer(): void {
		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
			$this->markTestSkipped( 'ext-sodium is not available.' );
		}
		$bare = $this->api()->receive_rules( $this->bare() )->get_data();

		sa_reset_state();
		sa_token_hash();
		$GLOBALS['_admins'] = array( 3 );
		sa_install_gateway_key();
		Aura_Worker_Rules::bind( 'c1', sa_token_hash() );
		$req = new WP_REST_Request( 'POST', '/aura/v2/rules' );
		$req->set_param(
			'ruleset',
			sa_sign_ruleset(
				array(
					'v'        => 1,
					'client'   => 'c1',
					'site'     => sa_token_hash(),
					'site_ref' => 'r1',
					'seq'      => 4,
					'unbind'   => true,
					'final'    => true,
					'issued_at' => '2026-08-29T10:00:00Z',
					'rules'    => array(),
				)
			)
		);
		$enveloped = $this->api()->receive_rules( $req )->get_data();

		$this->assertSame( $enveloped, $bare );
	}
}
