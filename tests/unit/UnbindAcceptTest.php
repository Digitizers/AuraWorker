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
		Aura_Worker_Security::_set_authenticating_uuid_for_tests( 'uuid-manual', 3 ); // the password that authenticated THIS request, and the user WordPress named beside it
		sa_add_app_password( 3, 'uuid-manual' );                                      // …who really holds it
		// DIFFERENT from the hook's user on purpose (round-4 C4): a
		// determine_current_user filter, or any wp_set_current_user() before
		// the route, moves the global. The marker must record what the hook
		// said, not what the request currently is.
		$GLOBALS['_current_user_id'] = 9;

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
		$this->assertSame( 3, $m['app_password_users']['uuid-manual'], 'the hook\'s user, not get_current_user_id()' );
		$this->assertFalse( sa_app_password_exists( 3, 'uuid-manual' ), 'and that is who it is revoked from' );
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
		Aura_Worker_Security::_set_authenticating_uuid_for_tests( 'uuid-second', 7 );
		$GLOBALS['_current_user_id'] = 9; // desynchronised on purpose (round-4 C4)

		$res = Aura_Worker_Rules::accept( $this->unbind_env( array( 'seq' => 12 ) ) );

		$this->assertSame( true, $res['unbound'] );
		$m = Aura_Worker_Unbind::read();
		$this->assertSame( array( 'uuid-managed', 'uuid-second' ), $m['app_password_uuids'] );
		$this->assertSame( 7, $m['app_password_users']['uuid-second'], 'the hook\'s user, not the request\'s current one' );
		$stored = maybe_unserialize( sa_read_option_uncached( Aura_Worker_Unbind::OPTION ) );
		$this->assertSame( 7, $stored['app_password_users']['uuid-second'], 'and that is what the RAW row holds' );
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
		// `leftovers` travels too (round-4 M9): empty here because nothing is
		// owed, which is the only reading of `cleanup_complete: true`.
		$this->assertSame( array( 'success' => true, 'seq' => 9, 'unbound' => true, 'cleanup_complete' => true, 'leftovers' => array() ), $resp->get_data() );
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
		$this->assertSame( array( 'success' => true, 'seq' => 11, 'unbound' => true, 'cleanup_complete' => true, 'leftovers' => array() ), $resp->get_data() );
		$this->assertFalse( get_option( 'aura_worker_site_token' ), 'the retry finished the teardown the first attempt started' );
	}

	/**
	 * Round-1 C1, end to end through a real accept(): a managed record whose
	 * `user_id` half never landed. rules.php copies it into the marker as
	 * `(int) ( $managed['user_id'] ?? 0 )` — owner 0 — and with no
	 * `aura_worker_connect_user_id` to fall back to, Phase B cannot identify
	 * whose credential it is. It must therefore refuse to finish: the token
	 * survives, `cleanup_complete` is false, and the leftover is named, which
	 * is the `aura_unbind_incomplete` signal Task 7's rebind consults before it
	 * removes the marker (and with it the core-REST seam that is the only thing
	 * still refusing that password).
	 */
	public function test_a_marker_uuid_with_no_resolvable_owner_blocks_the_teardown(): void {
		$token_hash = sa_token_hash();
		Aura_Worker_Rules::bind( 'c1', $token_hash );
		// The half-written record tracking_is_incomplete() exists to detect:
		// a uuid with no usable user_id, and no connect user to fall back to.
		$GLOBALS['_options'][ Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION ] = array( 'uuid' => 'uuid-managed' );
		sa_add_app_password( 3, 'uuid-managed' );

		$res = Aura_Worker_Rules::accept( $this->unbind_env() );          // final: true

		$this->assertSame( true, $res['unbound'] );
		$this->assertFalse( $res['cleanup_complete'], 'an unidentifiable credential is not a finished teardown' );
		$this->assertNull( Aura_Worker_Unbind::read()['app_password_users']['uuid-managed'], 'an owner that could not be resolved is recorded as an explicit unknown, never 0' );
		$this->assertContains( 'app_passwords', Aura_Worker_Unbind::leftovers() );
		$this->assertTrue( sa_app_password_exists( 3, 'uuid-managed' ), 'the administrator credential is still live' );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ), 'so the token stays and the retry path stays open' );
		$this->assertNotContains( 'token', $GLOBALS['_unbind_trace'] );
	}

	/**
	 * Round-3, the C2/C3 scenario end to end: the managed record's `user_id`
	 * half never landed, and the password belongs to user 7 — not to the
	 * connecting user 3, and (C3) not necessarily to anybody an
	 * administrator-role query would return. Phase A tries the connector as a
	 * CANDIDATE, does not find the password there, and therefore records an
	 * explicit unknown instead of a guess. Phase B then makes no claim at all:
	 * the token stays, `cleanup_complete` is false, and the leftover is named
	 * — which is the `aura_unbind_incomplete` signal Task 7's rebind consults
	 * before it removes the marker and with it the core-REST seam that is the
	 * only thing still refusing that password.
	 */
	public function test_an_unconfirmed_owner_is_recorded_unknown_and_stops_the_teardown(): void {
		$token_hash = sa_token_hash();
		Aura_Worker_Rules::bind( 'c1', $token_hash );
		update_option( 'aura_worker_connect_user_id', 3 );                 // the connector…
		$GLOBALS['_options'][ Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION ] = array( 'uuid' => 'uuid-managed' );
		sa_add_app_password( 7, 'uuid-managed' );                          // …but user 7 owns the password

		$res = Aura_Worker_Rules::accept( $this->unbind_env() );           // final: true

		$this->assertNull( Aura_Worker_Unbind::read()['app_password_users']['uuid-managed'], 'an unconfirmed guess is not recorded as knowledge' );
		// The RAW row, not read()'s normalisation of it: what Phase A STORES
		// must itself be the explicit unknown. `0` normalises to the same
		// answer today, but it is a user id in shape, and the whole point of
		// rounds 1-3 is that a value which names nobody must never be written
		// where a user id is read.
		$stored = maybe_unserialize( sa_read_option_uncached( Aura_Worker_Unbind::OPTION ) );
		$this->assertArrayHasKey( 'uuid-managed', $stored['app_password_users'], 'the entry is recorded…' );
		$this->assertNull( $stored['app_password_users']['uuid-managed'], '…as null, never 0' );
		$this->assertFalse( $res['cleanup_complete'] );
		$this->assertTrue( sa_app_password_exists( 7, 'uuid-managed' ), 'the credential is still live' );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ), 'so the token stays' );
		$this->assertContains( 'app_passwords', Aura_Worker_Unbind::leftovers() );
	}

	/**
	 * The other half of Phase A's resolution: the connecting user is a
	 * candidate, and when the password really IS in that user's list this
	 * request has CONFIRMED it — so the owner is knowledge, gets recorded, and
	 * Phase B revokes it with its single authoritative lookup.
	 */
	public function test_a_confirmed_connect_user_becomes_the_recorded_owner(): void {
		$token_hash = sa_token_hash();
		Aura_Worker_Rules::bind( 'c1', $token_hash );
		update_option( 'aura_worker_connect_user_id', 3 );
		$GLOBALS['_options'][ Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION ] = array( 'uuid' => 'uuid-managed' );
		sa_add_app_password( 3, 'uuid-managed' );                          // the connector really does hold it

		$res = Aura_Worker_Rules::accept( $this->unbind_env() );

		$this->assertSame( 3, Aura_Worker_Unbind::read()['app_password_users']['uuid-managed'] );
		$this->assertFalse( sa_app_password_exists( 3, 'uuid-managed' ), 'and it is revoked' );
		$this->assertTrue( $res['cleanup_complete'] );
		$this->assertFalse( get_option( 'aura_worker_site_token' ) );
	}

	/**
	 * The managed record's own `user_id` is a statement by the writer about
	 * that exact password, so it is recorded as-is — no confirmation lookup,
	 * and no consultation of the connector.
	 */
	public function test_the_managed_records_own_user_id_is_recorded_as_written(): void {
		$token_hash = sa_token_hash();
		Aura_Worker_Rules::bind( 'c1', $token_hash );
		update_option( 'aura_worker_connect_user_id', 3 );
		$GLOBALS['_options'][ Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION ] = array( 'uuid' => 'uuid-managed', 'user_id' => 9 );
		sa_add_app_password( 9, 'uuid-managed' );

		Aura_Worker_Rules::accept( $this->unbind_env() );

		$this->assertSame( 9, Aura_Worker_Unbind::read()['app_password_users']['uuid-managed'] );
		$this->assertFalse( sa_app_password_exists( 9, 'uuid-managed' ) );
	}

	/**
	 * The fast path's append records the SAME way: WordPress named the user
	 * that password authenticated as, so that is knowledge — and when it
	 * cannot be named at all (no current user), an explicit unknown, never 0.
	 */
	public function test_an_appended_uuid_with_no_current_user_records_an_explicit_unknown(): void {
		sa_set_marker( array( 'site' => sa_token_hash(), 'seq' => 9, 'connect_user_id' => 0 ) );
		Aura_Worker_Security::_set_authenticating_uuid_for_tests( 'uuid-second' );
		$GLOBALS['_current_user_id'] = 0;

		$res = Aura_Worker_Rules::accept( $this->unbind_env( array( 'seq' => 12 ) ) );

		$this->assertSame( true, $res['unbound'] );
		$this->assertNull( Aura_Worker_Unbind::read()['app_password_users']['uuid-second'] );
		$stored = maybe_unserialize( sa_read_option_uncached( Aura_Worker_Unbind::OPTION ) );
		$this->assertNull( $stored['app_password_users']['uuid-second'], 'the append stores the explicit unknown too, never 0' );
		$this->assertFalse( $res['cleanup_complete'], 'and an unknown owner stops the teardown' );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ) );
	}

	// -----------------------------------------------------------------------
	// Round 4, C4 — Phase A records the user WordPress named beside the uuid,
	// never the user the request happens to be running as.
	// -----------------------------------------------------------------------

	/**
	 * The reviewer's end-to-end probe. WordPress authenticates the request
	 * with user 5's Application Password and says so on the hook; something
	 * that runs afterwards — a `determine_current_user` filter, a
	 * user-switching / SSO / "view as" plugin calling `wp_set_current_user()`
	 * on init — leaves the global at 9.
	 *
	 * Recording 9 was `cleanup_complete: true` with the token deleted and user
	 * 5's `manage_options` credential still live: rounds 1-3's outcome through
	 * a fourth door. The marker must name 5, Phase B's single lookup must find
	 * the password there, and only then may the token go.
	 */
	public function test_the_owner_is_the_hooks_user_even_when_the_request_moved_on(): void {
		Aura_Worker_Rules::bind( 'c1', sa_token_hash() );
		update_option( 'aura_worker_connect_user_id', 3 );
		sa_add_app_password( 5, 'uuid-manual' );                                   // user 5 really holds it
		Aura_Worker_Security::_set_authenticating_uuid_for_tests( 'uuid-manual', 5 ); // …and WordPress said so
		$GLOBALS['_current_user_id'] = 9;                                          // …while the request now runs as 9

		$res = Aura_Worker_Rules::accept( $this->unbind_env() );                   // final: true

		$stored = maybe_unserialize( sa_read_option_uncached( Aura_Worker_Unbind::OPTION ) );
		$this->assertSame( 5, $stored['app_password_users']['uuid-manual'], 'the RAW row names the hook\'s user' );
		$this->assertFalse( sa_app_password_exists( 5, 'uuid-manual' ), 'and the credential is actually revoked' );
		$this->assertTrue( $res['cleanup_complete'] );
		$this->assertSame( array(), $res['leftovers'] );
		$this->assertFalse( get_option( 'aura_worker_site_token' ) );
	}

	/**
	 * The other half, and the one that makes the mutation visible: user 9 —
	 * the request's current user — really does hold a password with this uuid,
	 * and the hook named nobody (it never fired; the uuid reached this request
	 * some other way). Re-deriving the owner from the global would find it,
	 * record 9 as knowledge and finish the teardown. What the site actually
	 * KNOWS is nothing, so it records the explicit unknown and stops.
	 */
	public function test_the_current_user_is_never_borrowed_when_the_hook_named_nobody(): void {
		Aura_Worker_Rules::bind( 'c1', sa_token_hash() );
		update_option( 'aura_worker_connect_user_id', 0 );
		sa_add_app_password( 9, 'uuid-manual' );
		Aura_Worker_Security::_set_authenticating_uuid_for_tests( 'uuid-manual' ); // no identity beside it
		$GLOBALS['_current_user_id'] = 9;

		$res = Aura_Worker_Rules::accept( $this->unbind_env() );                   // final: true

		$stored = maybe_unserialize( sa_read_option_uncached( Aura_Worker_Unbind::OPTION ) );
		$this->assertArrayHasKey( 'uuid-manual', $stored['app_password_users'] );
		$this->assertNull( $stored['app_password_users']['uuid-manual'], 'an explicit unknown, never the request\'s current user' );
		$this->assertFalse( $res['cleanup_complete'] );
		$this->assertSame( array( 'app_passwords' ), $res['leftovers'] );
		$this->assertTrue( sa_app_password_exists( 9, 'uuid-manual' ), 'nothing was deleted from a user the site cannot name' );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ) );
	}

	// -----------------------------------------------------------------------
	// Round 4, I5 — Phase A confirms a candidate owner on evidence, and a
	// read that failed is not evidence.
	// -----------------------------------------------------------------------

	/**
	 * The connecting user is a CANDIDATE. Confirming it means finding the
	 * password in that user's list — and a list that could not be read is not
	 * a finding. Recorded as the explicit unknown instead, so Phase B's one
	 * lookup is never run against a guess.
	 */
	public function test_a_candidate_owner_is_not_confirmed_by_a_read_that_failed(): void {
		Aura_Worker_Rules::bind( 'c1', sa_token_hash() );
		update_option( 'aura_worker_connect_user_id', 3 );
		$GLOBALS['_options'][ Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION ] = array( 'uuid' => 'uuid-managed' );
		sa_add_app_password( 3, 'uuid-managed' );                                  // the connector does hold it…
		$GLOBALS['_sa_app_password_read_fail'][3] = true;                          // …but this request cannot read that

		$res = Aura_Worker_Rules::accept( $this->unbind_env() );

		$GLOBALS['_sa_app_password_read_fail'] = array();
		$stored = maybe_unserialize( sa_read_option_uncached( Aura_Worker_Unbind::OPTION ) );
		$this->assertNull( $stored['app_password_users']['uuid-managed'], 'unconfirmed is unknown, never recorded as knowledge' );
		$this->assertFalse( $res['cleanup_complete'] );
		$this->assertSame( array( 'app_passwords' ), $res['leftovers'] );
		$this->assertTrue( sa_app_password_exists( 3, 'uuid-managed' ), 'the credential is still live' );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ) );
	}

	// -----------------------------------------------------------------------
	// Round 4, M9 — what is owed travels on the wire, by name.
	// -----------------------------------------------------------------------

	/**
	 * `cleanup_complete: false` has two opposite causes, and Aura cannot tell
	 * them apart from a bool: something could not be proven removed, or the
	 * token was deliberately kept because the document was not `final`. Here
	 * it is the second — nothing is owed, so `leftovers` is EMPTY and Aura may
	 * retire the tombstone when its siblings are done.
	 */
	public function test_a_non_final_unbind_reports_no_leftovers(): void {
		Aura_Worker_Rules::bind( 'c1', sa_token_hash() );
		update_option( 'aura_worker_connect_user_id', 3 );
		$req = new WP_REST_Request( 'POST', '/aura/v2/rules' );
		$req->set_param( 'ruleset', $this->unbind_env( array( 'final' => false ) ) );

		$body = ( new Aura_Worker_API( new Aura_Worker_Security() ) )->receive_rules( $req )->get_data();

		$this->assertFalse( $body['cleanup_complete'], 'the token was kept, deliberately' );
		$this->assertSame( array(), $body['leftovers'], 'and nothing is owed' );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ) );
	}

	/**
	 * And the case that must never look like the one above: an owner the site
	 * cannot name, so a `manage_options` credential is still live. `leftovers`
	 * names it, which is what stops Aura retiring the very tombstone that is
	 * the site's only remaining way to be told about the remainder.
	 */
	public function test_an_unknown_owner_names_app_passwords_on_the_wire(): void {
		Aura_Worker_Rules::bind( 'c1', sa_token_hash() );
		update_option( 'aura_worker_connect_user_id', 0 );
		$GLOBALS['_options'][ Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION ] = array( 'uuid' => 'uuid-managed' );
		sa_add_app_password( 7, 'uuid-managed' );
		$req = new WP_REST_Request( 'POST', '/aura/v2/rules' );
		$req->set_param( 'ruleset', $this->unbind_env() ); // final: true

		$body = ( new Aura_Worker_API( new Aura_Worker_Security() ) )->receive_rules( $req )->get_data();

		$this->assertFalse( $body['cleanup_complete'] );
		$this->assertSame( array( 'app_passwords' ), $body['leftovers'] );
		$this->assertTrue( sa_app_password_exists( 7, 'uuid-managed' ) );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ) );
	}

	/**
	 * The same, through the FAST path — the retry of a tombstone whose marker
	 * is already on the site. It is the path an interrupted unbind actually
	 * comes back on, so it must carry the same answer: what is owed, by name.
	 */
	public function test_the_fast_path_reports_the_leftovers_too(): void {
		sa_set_marker(
			array(
				'site'               => sa_token_hash(),
				'seq'                => 9,
				'connect_user_id'    => 0,
				'app_password_uuids' => array( 'uuid-managed' ),
				'app_password_users' => array(),   // an owner this site cannot name
			)
		);
		sa_add_app_password( 7, 'uuid-managed' );
		$req = new WP_REST_Request( 'POST', '/aura/v2/rules' );
		$req->set_param( 'ruleset', $this->unbind_env( array( 'seq' => 12 ) ) ); // final: true

		$body = ( new Aura_Worker_API( new Aura_Worker_Security() ) )->receive_rules( $req )->get_data();

		$this->assertSame( 12, $body['seq'], 'the retry\'s own seq' );
		$this->assertFalse( $body['cleanup_complete'] );
		$this->assertSame( array( 'app_passwords' ), $body['leftovers'] );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ) );
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

		do_action( 'application_password_did_authenticate', new WP_User( 3 ), array( 'uuid' => 'uuid-from-wp', 'name' => 'Aura SiteAgent' ) );

		$this->assertSame( 'uuid-from-wp', Aura_Worker_Security::authenticating_app_password_uuid() );
		$this->assertSame( 3, Aura_Worker_Security::authenticating_app_password_user(), 'the pairing, not just the uuid (round-4 C4)' );
	}

	public function test_a_password_item_with_no_uuid_records_nothing(): void {
		( new Aura_Worker() )->init();
		Aura_Worker_Security::_set_authenticating_uuid_for_tests( 'stale' );

		do_action( 'application_password_did_authenticate', new WP_User( 3 ), 'not an item at all' );

		$this->assertNull( Aura_Worker_Security::authenticating_app_password_uuid(), 'an unreadable item must clear, never keep, a stale uuid' );
		$this->assertNull( Aura_Worker_Security::authenticating_app_password_user(), 'and the user beside it, which names nothing without a uuid' );
	}

	/**
	 * Round-4 C4, the input side of the same rule. WordPress's own signature
	 * for this hook is `WP_User|mixed`, and only a WP_User with a positive ID
	 * is an identity. A stdClass that happens to carry an `ID`, a bare int, or
	 * a user object for the anonymous user names nobody — and a cast would
	 * turn each of them into a user id that Phase B then treats as knowledge.
	 */
	public function test_only_a_wp_user_with_a_real_id_is_an_identity(): void {
		( new Aura_Worker() )->init();

		do_action( 'application_password_did_authenticate', (object) array( 'ID' => 5 ), array( 'uuid' => 'uuid-manual' ) );
		$this->assertSame( 'uuid-manual', Aura_Worker_Security::authenticating_app_password_uuid(), 'the uuid is still captured' );
		$this->assertNull( Aura_Worker_Security::authenticating_app_password_user(), 'but a stdClass with an ID is not a WP_User' );

		do_action( 'application_password_did_authenticate', 5, array( 'uuid' => 'uuid-manual' ) );
		$this->assertNull( Aura_Worker_Security::authenticating_app_password_user(), 'nor is a bare int' );

		do_action( 'application_password_did_authenticate', new WP_User( 0 ), array( 'uuid' => 'uuid-manual' ) );
		$this->assertNull( Aura_Worker_Security::authenticating_app_password_user(), 'nor is user 0, which is nobody' );
	}

	/**
	 * The whole chain, with nothing injected: WordPress fires the hook, the
	 * plugin's own registration catches it, and the uuid lands in the marker
	 * the unbind writes.
	 */
	public function test_a_uuid_captured_from_the_real_hook_reaches_the_marker(): void {
		( new Aura_Worker() )->init();
		Aura_Worker_Rules::bind( 'c1', sa_token_hash() );
		// The global is NOT the hook's user (round-4 C4). It used to be set to
		// match, which is exactly why the divergence was invisible.
		$GLOBALS['_current_user_id'] = 9;

		do_action( 'application_password_did_authenticate', new WP_User( 5 ), array( 'uuid' => 'uuid-manual' ) );
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
