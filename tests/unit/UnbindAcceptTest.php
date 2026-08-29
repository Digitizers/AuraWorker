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

	// -----------------------------------------------------------------------
	// Task 3 — Phase A: the unbind document, the marker fast path, the write.
	// -----------------------------------------------------------------------

	/**
	 * A clear tombstone's envelope, `unbind: true` after `rules` (spec §2.1).
	 *
	 * @param array $over Fields to override.
	 * @return string
	 */
	private function unbind_env( array $over = array() ): string {
		return sa_sign_ruleset( array_merge( array( 'v' => 1, 'client' => 'c1', 'site' => sa_token_hash(), 'site_ref' => 'r1', 'seq' => 9, 'issued_at' => '2026-08-29T10:00:00Z', 'rules' => array(), 'unbind' => true, 'final' => true ), $over ) );
	}

	public function test_unbind_writes_the_marker_with_the_binding_identity(): void {
		// Captured BEFORE the unbind: sa_token_hash() re-seeds the token option
		// when it is absent, and Phase B (`final: true` below) deletes that row
		// — so calling it again after the fact would quietly put the row back
		// and the "the token is gone" assertion would be reading its own seed.
		$token_hash = sa_token_hash();
		Aura_Worker_Rules::bind( 'c1', $token_hash );                   // seq-0 sentinel, as a connect leaves it
		update_option( 'aura_worker_connect_user_id', 3 );
		sa_set_managed_app_password( 3, 'uuid-managed' );
		Aura_Worker_Security::_set_authenticating_uuid_for_tests( 'uuid-manual' ); // the password that authenticated THIS request
		$GLOBALS['_current_user_id'] = 3;                                         // the user that password belongs to

		$res = Aura_Worker_Rules::accept( $this->unbind_env() );

		$this->assertSame( true, $res['unbound'] );
		$this->assertSame( 9, $res['seq'] );
		// Task 4: the response reports the cleanup that actually ran. Everything
		// this site holds is removable and the document said `final: true`, so
		// the whole of Phase B completed — proven here by the state it left,
		// not by the flag alone.
		$this->assertTrue( $res['cleanup_complete'] );
		$this->assertFalse( sa_app_password_exists( 3, 'uuid-managed' ), 'the minted credential is revoked' );
		$this->assertFalse( get_option( 'aura_worker_connect_user_id' ) );
		$this->assertNull( Aura_Worker_Rules::stored_uncached(), 'the departed client\'s store is cleared' );
		$this->assertFalse( get_option( 'aura_worker_grant_pubkey' ) );
		$this->assertFalse( get_option( 'aura_worker_site_token' ), 'and the token goes last' );
		$this->assertSame( array( 'revoke', 'options', 'ruleset', 'grant', 'token' ), $GLOBALS['_unbind_trace'] );
		$m = Aura_Worker_Unbind::read();
		$this->assertSame( $token_hash, $m['site'] );
		$this->assertSame( 'r1', $m['site_ref'] );
		$this->assertSame( 'c1', $m['client'] );
		$this->assertSame( 9, $m['seq'] );
		$this->assertSame( 3, $m['connect_user_id'] );
		$this->assertEqualsCanonicalizing( array( 'uuid-managed', 'uuid-manual' ), $m['app_password_uuids'] );
		$this->assertSame( 3, $m['app_password_users']['uuid-managed'] );
		$this->assertSame( 3, $m['app_password_users']['uuid-manual'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T/', $m['at'] );
		$this->assertSame( '', (string) get_option( Aura_Worker_Magic_Link::SITE_CLAIM, '' ), 'claim released' );
	}

	public function test_wrong_site_writes_nothing(): void {
		Aura_Worker_Rules::bind( 'c1', sa_token_hash() );
		$res = Aura_Worker_Rules::accept( $this->unbind_env( array( 'site' => str_repeat( 'b', 64 ) ) ) );
		$this->assertSame( 'aura_ruleset_wrong_site', $res->get_error_code() );
		$this->assertFalse( Aura_Worker_Unbind::is_set() );
	}

	public function test_client_mismatch_writes_nothing(): void {
		Aura_Worker_Rules::bind( 'c-other', sa_token_hash() );
		$res = Aura_Worker_Rules::accept( $this->unbind_env() );
		$this->assertSame( 'aura_ruleset_client_mismatch', $res->get_error_code() );
		$this->assertFalse( Aura_Worker_Unbind::is_set() );
	}

	public function test_stale_seq_writes_no_marker(): void {
		Aura_Worker_Rules::accept( sa_sign_ruleset( array( 'v' => 1, 'client' => 'c1', 'site' => sa_token_hash(), 'site_ref' => 'r1', 'seq' => 9, 'issued_at' => 'x', 'rules' => array() ) ) );
		$res = Aura_Worker_Rules::accept( $this->unbind_env( array( 'seq' => 9 ) ) );
		$this->assertSame( 'aura_ruleset_stale', $res->get_error_code() );
		$this->assertFalse( Aura_Worker_Unbind::is_set() );
	}

	/**
	 * DEVIATION from the brief's `test_marker_set_ordinary_document_is_refused_
	 * store_untouched`, which asserted `aura_site_unbound` here. Spec §2.3 step
	 * 0 is explicit: the fast path answers on the TOKEN alone, before any
	 * decoding — and the brief's own
	 * test_marker_set_token_matches_fast_path_answers_unbound_without_verifying
	 * feeds it literal garbage and demands `unbound`. No ordering satisfies
	 * both, so the token match wins and an ordinary push arriving at an
	 * unbound site learns `unbound: true`. The half of that test that is not
	 * in conflict — the ruleset store is never touched — is what is pinned.
	 */
	public function test_marker_set_ordinary_document_answers_unbound_store_untouched(): void {
		sa_set_marker( array( 'site' => sa_token_hash(), 'seq' => 9 ) );
		$before = get_option( Aura_Worker_Rules::OPTION );
		$res    = Aura_Worker_Rules::accept( sa_sign_ruleset( array( 'v' => 1, 'client' => 'c1', 'site' => sa_token_hash(), 'site_ref' => 'r1', 'seq' => 10, 'issued_at' => 'x', 'rules' => array() ) ) );
		$this->assertSame( true, $res['unbound'] );
		$this->assertSame( 10, $res['seq'] );
		$this->assertSame( $before, get_option( Aura_Worker_Rules::OPTION ) );
	}

	public function test_marker_set_token_matches_fast_path_answers_unbound_without_verifying(): void {
		sa_set_marker( array( 'site' => sa_token_hash(), 'seq' => 9 ) );
		delete_option( 'aura_worker_grant_pubkey' );                       // key already gone: no envelope can verify
		$res = Aura_Worker_Rules::accept( 'garbage-not-an-envelope' );
		$this->assertSame( true, $res['unbound'] );
		$this->assertSame( 9, $res['seq'] );
	}

	public function test_fast_path_echoes_the_retrys_own_seq(): void {
		sa_set_marker( array( 'site' => sa_token_hash(), 'seq' => 9 ) );
		$res = Aura_Worker_Rules::accept( $this->unbind_env( array( 'seq' => 12, 'final' => false ) ) ); // a sibling tombstone's clearSeq
		$this->assertSame( 12, $res['seq'] );
	}

	public function test_fast_path_aborts_when_the_uuid_append_fails(): void {
		sa_set_marker( array( 'site' => sa_token_hash(), 'seq' => 9, 'app_password_uuids' => array( 'uuid-managed' ) ) );
		Aura_Worker_Security::_set_authenticating_uuid_for_tests( 'uuid-second' );
		$GLOBALS['_sa_option_write_fail'][ Aura_Worker_Unbind::OPTION ] = true;   // the claimed marker rewrite fails

		$res = Aura_Worker_Rules::accept( $this->unbind_env( array( 'final' => true ) ) );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_unbind_store_failed', $res->get_error_code() );
		$this->assertSame( 500, $res->get_error_data()['status'] );
		// Task 4: the token survives because Phase B never STARTED — the trace
		// is what proves that. `final: true` above means a cleanup that ran at
		// all would have reached step (5).
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ) );
		$this->assertSame( array(), $GLOBALS['_unbind_trace'], 'not one cleanup step ran' );
		$this->assertSame( array( 'uuid-managed' ), Aura_Worker_Unbind::read()['app_password_uuids'] );
	}

	public function test_fast_path_appends_the_authenticating_uuid_on_every_visit(): void {
		sa_set_marker( array( 'site' => sa_token_hash(), 'seq' => 9, 'app_password_uuids' => array( 'uuid-managed' ), 'app_password_users' => array( 'uuid-managed' => 3 ) ) );
		Aura_Worker_Security::_set_authenticating_uuid_for_tests( 'uuid-second' );
		$GLOBALS['_current_user_id'] = 7;

		$res = Aura_Worker_Rules::accept( $this->unbind_env( array( 'seq' => 12 ) ) );

		$this->assertSame( true, $res['unbound'] );
		$m = Aura_Worker_Unbind::read();
		$this->assertSame( array( 'uuid-managed', 'uuid-second' ), $m['app_password_uuids'] );
		$this->assertSame( 7, $m['app_password_users']['uuid-second'] );
		$this->assertSame( 9, $m['seq'], 'the append must not move the marker seq' );
	}

	public function test_marker_set_token_differs_is_403_nothing_touched(): void {
		sa_set_marker( array( 'site' => str_repeat( 'c', 64 ), 'seq' => 9 ) );
		$res = Aura_Worker_Rules::accept( $this->unbind_env() );
		$this->assertSame( 'aura_site_unbound', $res->get_error_code() );
		$this->assertSame( 403, $res->get_error_data()['status'] );
		$this->assertSame( str_repeat( 'c', 64 ), Aura_Worker_Unbind::read()['site'] );
	}

	/**
	 * Controller ruling 1: an UNREADABLE marker is never "the site is bound".
	 * Task 1 made read() tri-state exactly so this boundary can fail CLOSED —
	 * a database blip at step 0 answers the retryable store failure and writes
	 * nothing, rather than proceeding as though no unbind had ever happened.
	 */
	public function test_unreadable_marker_fails_closed_and_writes_nothing(): void {
		Aura_Worker_Rules::bind( 'c1', sa_token_hash() );
		$before = get_option( Aura_Worker_Rules::OPTION );
		// Scoped to the MARKER's own row: a request-wide $wpdb failure would
		// break the ruleset store's read too and the assertion below would pass
		// for the wrong reason.
		$GLOBALS['_sa_option_read_fail'][ Aura_Worker_Unbind::OPTION ] = true;

		$res = Aura_Worker_Rules::accept( $this->unbind_env() );

		$GLOBALS['_sa_option_read_fail'] = array();

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_ruleset_store_failed', $res->get_error_code() );
		$this->assertSame( 500, $res->get_error_data()['status'] );
		$this->assertSame( $before, get_option( Aura_Worker_Rules::OPTION ) );
		$this->assertNull( Aura_Worker_Unbind::read(), 'nothing marked on an unreadable marker' );
	}

	/**
	 * DEVIATION from the brief, which asserted `$res['seq']`: accept() answers
	 * plain `true` for an ordinary document and has since 2.10 — the array
	 * shape is the unbind answer only. What the brief meant is pinned instead:
	 * the ruleset lands, and no marker is written.
	 */
	public function test_envelope_without_unbind_stores_rules_exactly_as_before(): void {
		$env = sa_sign_ruleset( array( 'v' => 1, 'client' => 'c1', 'site' => sa_token_hash(), 'site_ref' => 'r1', 'seq' => 1, 'issued_at' => 'x', 'rules' => array( array( 'key' => 'rule/x', 'effect' => 'block', 'target' => 'site:*' ) ) ) );
		$res = Aura_Worker_Rules::accept( $env );
		$this->assertTrue( $res );
		$this->assertSame( 1, Aura_Worker_Rules::current()['seq'] );
		$this->assertFalse( Aura_Worker_Unbind::is_set() );
	}

	public function test_receive_rules_answers_the_contract_body(): void {
		Aura_Worker_Rules::bind( 'c1', sa_token_hash() );
		$req = new WP_REST_Request( 'POST', '/aura/v2/rules' );
		$req->set_param( 'ruleset', $this->unbind_env() );
		$resp = ( new Aura_Worker_API( new Aura_Worker_Security() ) )->receive_rules( $req );
		$this->assertSame( 200, $resp->get_status() );
		// Task 4: everything this site holds is removable and the document says
		// `final: true`, so the transported body reports a COMPLETED Phase B —
		// and the token it deleted is gone, which is what makes the flag mean
		// anything.
		$this->assertSame( array( 'success' => true, 'seq' => 9, 'unbound' => true, 'cleanup_complete' => true ), $resp->get_data() );
		$this->assertFalse( get_option( 'aura_worker_site_token' ) );
	}

	/**
	 * The 412 `no_gateway_key` branch must not pre-empt the fast path: Phase B
	 * deletes the gateway key BEFORE the token, so a tombstone retried after a
	 * partial cleanup would otherwise be stranded on a 412 forever.
	 */
	public function test_receive_rules_lets_the_fast_path_run_with_no_gateway_key(): void {
		sa_set_marker( array( 'site' => sa_token_hash(), 'seq' => 9 ) );
		delete_option( 'aura_worker_grant_pubkey' );
		$req = new WP_REST_Request( 'POST', '/aura/v2/rules' );
		$req->set_param( 'ruleset', $this->unbind_env( array( 'seq' => 11 ) ) );
		$resp = ( new Aura_Worker_API( new Aura_Worker_Security() ) )->receive_rules( $req );
		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( array( 'success' => true, 'seq' => 11, 'unbound' => true, 'cleanup_complete' => true ), $resp->get_data() );
		$this->assertFalse( get_option( 'aura_worker_site_token' ), 'the retry finished the teardown the first attempt started' );
	}

	public function test_receive_rules_still_412s_a_marker_less_unkeyed_site(): void {
		delete_option( 'aura_worker_grant_pubkey' );
		$req = new WP_REST_Request( 'POST', '/aura/v2/rules' );
		$req->set_param( 'ruleset', 'anything' );
		$resp = ( new Aura_Worker_API( new Aura_Worker_Security() ) )->receive_rules( $req );
		$this->assertSame( 412, $resp->get_status() );
		$this->assertSame( 'no_gateway_key', $resp->get_data()['code'] );
	}

	// -----------------------------------------------------------------------
	// Task 3 review round 1 — I1, I3, M1.
	// -----------------------------------------------------------------------

	/**
	 * I1. The production source of the authenticating UUID had no coverage at
	 * all: every other test injects it through
	 * _set_authenticating_uuid_for_tests(), so deleting the registration and
	 * gutting the callback left the suite green. A wrong hook name, or the
	 * arity dropped from 2 to 1 (which silently discards the $item the uuid
	 * rides in), would then ship unnoticed — and Task 5/6's core-REST seam
	 * would keep accepting /wp/v2/* writes made with a manually connected or
	 * PATCH-installed Application Password against an unbound site, the exact
	 * case spec §2.3's app_password_uuids[] exists to close.
	 */
	public function test_the_plugin_registers_the_application_password_capture_hook(): void {
		( new Aura_Worker() )->init();

		$entries = $GLOBALS['_filters']['application_password_did_authenticate'] ?? array();
		$this->assertCount( 1, $entries, 'registered exactly once, under the name WordPress fires' );
		$this->assertSame( array( 'Aura_Worker_Security', 'capture_app_password' ), $entries[0]['callback'] );
		$this->assertSame( 10, $entries[0]['priority'] );
		$this->assertSame( 2, $entries[0]['accepted_args'], 'the uuid rides the SECOND argument; arity 1 would drop it' );
	}

	public function test_a_fired_application_password_hook_records_the_uuid(): void {
		( new Aura_Worker() )->init();

		do_action( 'application_password_did_authenticate', (object) array( 'ID' => 3 ), array( 'uuid' => 'uuid-from-wp', 'name' => 'Aura SiteAgent' ) );

		$this->assertSame( 'uuid-from-wp', Aura_Worker_Security::authenticating_app_password_uuid() );
	}

	public function test_a_password_item_with_no_uuid_records_nothing(): void {
		( new Aura_Worker() )->init();
		Aura_Worker_Security::_set_authenticating_uuid_for_tests( 'stale' );

		do_action( 'application_password_did_authenticate', (object) array( 'ID' => 3 ), 'not an item at all' );

		$this->assertNull( Aura_Worker_Security::authenticating_app_password_uuid(), 'an unreadable item must clear, never keep, a stale uuid' );
	}

	/**
	 * The whole chain, with nothing injected: WordPress fires the hook, the
	 * plugin's own registration catches it, and the uuid lands in the marker
	 * the unbind writes.
	 */
	public function test_a_uuid_captured_from_the_real_hook_reaches_the_marker(): void {
		( new Aura_Worker() )->init();
		Aura_Worker_Rules::bind( 'c1', sa_token_hash() );
		$GLOBALS['_current_user_id'] = 5;

		do_action( 'application_password_did_authenticate', (object) array( 'ID' => 5 ), array( 'uuid' => 'uuid-manual' ) );
		$res = Aura_Worker_Rules::accept( $this->unbind_env() );

		$this->assertSame( true, $res['unbound'] );
		$m = Aura_Worker_Unbind::read();
		$this->assertSame( array( 'uuid-manual' ), $m['app_password_uuids'] );
		$this->assertSame( 5, $m['app_password_users']['uuid-manual'] );
	}

	/**
	 * I3. The dangerous variant of the fail-closed case, which the
	 * unbind-document test above cannot reach: the marker IS set, its read
	 * fails, and an ORDINARY ruleset push arrives. Without step 0's fail-closed
	 * branch the push sails through every remaining check — the marker is the
	 * only thing that knows this site is unbound — and installs rules on a site
	 * Aura has already disconnected.
	 *
	 * The read failure is scoped to the marker's own row: the ruleset store
	 * must still be readable, or the request would fail closed for the wrong
	 * reason and the test would prove nothing.
	 */
	public function test_an_unreadable_marker_refuses_an_ordinary_push_and_installs_nothing(): void {
		sa_set_marker( array( 'site' => sa_token_hash(), 'seq' => 9 ) );
		$env = sa_sign_ruleset( array( 'v' => 1, 'client' => 'c1', 'site' => sa_token_hash(), 'site_ref' => 'r1', 'seq' => 10, 'issued_at' => 'x', 'rules' => array( array( 'key' => 'rule/x', 'effect' => 'block', 'target' => 'site:*' ) ) ) );
		$before = get_option( Aura_Worker_Rules::OPTION );
		$GLOBALS['_sa_option_read_fail'][ Aura_Worker_Unbind::OPTION ] = true;

		$res = Aura_Worker_Rules::accept( $env );

		$GLOBALS['_sa_option_read_fail'] = array();
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_ruleset_store_failed', $res->get_error_code() );
		$this->assertSame( 500, $res->get_error_data()['status'] );
		$this->assertNull( Aura_Worker_Rules::current(), 'no rules may be installed on an unbound site' );
		$this->assertSame( $before, get_option( Aura_Worker_Rules::OPTION ) );
		$this->assertSame( sa_token_hash(), Aura_Worker_Unbind::read()['site'], 'the marker is untouched' );
	}

	/**
	 * M1. The append's own verification, isolated. write_under_claim()'s
	 * read-back compares site + seq — fields an append does not change — so it
	 * reports success whether or not the uuid landed. Only
	 * append_authenticating_uuid()'s re-read of app_password_uuids can tell,
	 * and this is the one seam that can prove it: the claimed write REPORTS
	 * SUCCESS while the stored row keeps its old value.
	 */
	public function test_the_append_is_refused_when_the_write_lands_without_the_uuid(): void {
		sa_set_marker( array( 'site' => sa_token_hash(), 'seq' => 9, 'app_password_uuids' => array( 'uuid-managed' ), 'app_password_users' => array( 'uuid-managed' => 3 ) ) );
		Aura_Worker_Security::_set_authenticating_uuid_for_tests( 'uuid-second' );
		$GLOBALS['_sa_option_write_divert'][ Aura_Worker_Unbind::OPTION ] = true;

		$res = Aura_Worker_Rules::accept( $this->unbind_env() );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_unbind_store_failed', $res->get_error_code() );
		$this->assertSame( 500, $res->get_error_data()['status'] );
		$this->assertSame( array( 'uuid-managed' ), Aura_Worker_Unbind::read()['app_password_uuids'], 'the uuid genuinely did not land' );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ) );
		$this->assertSame( array(), $GLOBALS['_unbind_trace'], 'nothing proceeded to cleanup' );
	}
}
