<?php
/**
 * Tests for G-grants public-key provisioning through the signed /connect flow.
 *
 * The gateway public key is delivered as a 5th, signature-covered field on the
 * magic-link connect callback, so a stolen token alone can't provision an
 * attacker-chosen key.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class ConnectProvisionTest extends TestCase {

	private Aura_Worker_Magic_Link $ml;
	private string $secret;
	private string $magic_id;
	private string $pubkey; // base64 32-byte Ed25519 key

	protected function setUp(): void {
		sa_reset_state();
		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
			$this->markTestSkipped( 'ext-sodium is not available.' );
		}
		$this->ml       = new Aura_Worker_Magic_Link();
		$this->secret   = 'one-time-connect-secret';
		$this->magic_id = 'magic123';
		$this->pubkey   = base64_encode( sodium_crypto_sign_publickey( sodium_crypto_sign_keypair() ) );
		set_transient( 'aura_magic_' . $this->magic_id, array( 'connect_secret' => $this->secret, 'connect_user_id' => 1 ), 600 );
	}

	private function request( array $over = array() ): WP_REST_Request {
		$token         = $over['token'] ?? 'raw-token';
		$dashboard_url = $over['dashboard_url'] ?? 'https://dash.example';
		$timestamp     = $over['timestamp'] ?? time();
		$pubkey        = array_key_exists( 'grant_pubkey', $over ) ? $over['grant_pubkey'] : $this->pubkey;
		$client        = array_key_exists( 'client', $over ) ? $over['client'] : null;
		// Sign with the pubkey / client the gateway intends (or omit them from the message).
		$sig_pubkey = array_key_exists( 'sign_pubkey', $over ) ? $over['sign_pubkey'] : $pubkey;
		$sig_client = array_key_exists( 'sign_client', $over ) ? $over['sign_client'] : $client;
		$signature  = Aura_Worker_Magic_Link::sign_connect_payload( $this->secret, $this->magic_id, $token, $dashboard_url, $timestamp, (string) $sig_pubkey, (string) $sig_client );

		$req = new WP_REST_Request();
		$req->set_param( 'magic_id', $this->magic_id );
		$req->set_param( 'token', $token );
		$req->set_param( 'dashboard_url', $dashboard_url );
		$req->set_param( 'timestamp', $timestamp );
		$req->set_param( 'signature', $over['signature'] ?? $signature );
		if ( null !== $pubkey ) {
			$req->set_param( 'grant_pubkey', $pubkey );
		}
		if ( null !== $client ) {
			$req->set_param( 'client', $client );
		}
		return $req;
	}

	/** The same request under another magic link — nothing has minted a transient for it. */
	private function request_for( string $magic_id ): WP_REST_Request {
		$req = $this->request();
		$req->set_param( 'magic_id', $magic_id );
		return $req;
	}

	public function test_provisions_pubkey_on_signed_connect(): void {
		$res = $this->ml->handle_connect( $this->request() );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( $this->pubkey, get_option( 'aura_worker_grant_pubkey' ) );
		$this->assertTrue( Aura_Worker_Grant::is_enforced() );
	}

	public function test_connect_without_pubkey_leaves_enforcement_off(): void {
		// 4-field callback (no grant_pubkey): still connects, no key provisioned.
		$res = $this->ml->handle_connect( $this->request( array( 'grant_pubkey' => null ) ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertFalse( array_key_exists( 'aura_worker_grant_pubkey', $GLOBALS['_options'] ) );
	}

	public function test_pubkey_must_be_covered_by_signature(): void {
		// Gateway sent a pubkey but signed only the 4 base fields → signature
		// mismatch (the plugin includes the pubkey in the signed message).
		$res = $this->ml->handle_connect( $this->request( array( 'sign_pubkey' => '' ) ) );
		$this->assertSame( 401, $res->get_status() );
		$this->assertFalse( array_key_exists( 'aura_worker_grant_pubkey', $GLOBALS['_options'] ) );
	}

	public function test_rejects_invalid_pubkey(): void {
		// Correctly signed, but the key isn't a 32-byte Ed25519 key.
		$bad = base64_encode( 'too-short' );
		$res = $this->ml->handle_connect( $this->request( array( 'grant_pubkey' => $bad ) ) );
		$this->assertSame( 400, $res->get_status() );
		$this->assertFalse( array_key_exists( 'aura_worker_grant_pubkey', $GLOBALS['_options'] ) );
	}

	public function test_stale_timestamp_rejected(): void {
		$res = $this->ml->handle_connect( $this->request( array( 'timestamp' => time() - 3600 ) ) );
		$this->assertSame( 400, $res->get_status() );
	}

	public function test_keyless_reconnect_clears_stale_key(): void {
		// A key is already provisioned...
		$GLOBALS['_options']['aura_worker_grant_pubkey'] = $this->pubkey;
		$this->assertTrue( Aura_Worker_Grant::is_enforced() );
		// ...then a signed 4-field (keyless) reconnect must clear it, so a fresh
		// dashboard that doesn't use grants isn't blocked by a stale key.
		$res = $this->ml->handle_connect( $this->request( array( 'grant_pubkey' => null ) ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertFalse( array_key_exists( 'aura_worker_grant_pubkey', $GLOBALS['_options'] ) );
		$this->assertFalse( Aura_Worker_Grant::is_enforced() );
	}

	public function test_a_connect_clears_any_previous_ruleset(): void {
		$GLOBALS['_options'][ Aura_Worker_Rules::OPTION ] = array(
			'envelope' => 'x.y', 'client' => 'old', 'seq' => 9, 'issued_at' => '', 'received_at' => time(), 'rules' => array(),
		);
		$res = $this->ml->handle_connect( $this->request() );
		$this->assertSame( 200, $res->get_status() );
		$this->assertNull( Aura_Worker_Rules::current() );
	}

	public function test_a_signed_connect_binds_the_site_to_its_client_inside_the_ruleset_store(): void {
		$GLOBALS['_options']['aura_worker_ruleset'] = array( 'client' => 'client-old', 'seq' => 5, 'rules' => array( array( 'key' => 'rule/x' ) ), 'envelope' => 'e' );
		$res = $this->ml->handle_connect( $this->request( array( 'client' => 'client-new', 'token' => 'raw-token' ) ) );
		$this->assertSame( 200, $res->get_status() );
		$stored = Aura_Worker_Rules::stored();
		$this->assertSame( 'client-new', $stored['client'] );
		$this->assertSame( Aura_Worker_Security::hash_token( 'raw-token' ), $stored['token_hash'] );
		$this->assertSame( 0, $stored['seq'] );
		$this->assertSame( array(), $stored['rules'] );
		$this->assertTrue( $stored['bound'] );
		$this->assertNull( Aura_Worker_Rules::current(), 'A sentinel is not a ruleset: no policy until the first push.' );
		$this->assertSame( array(), Aura_Worker_Rules::rules() );
		$this->assertSame( 'client-new', Aura_Worker_Rules::bound_client() );
	}

	public function test_the_binding_is_one_write_to_the_one_value_accept_swaps_against(): void {
		// There is no separate binding option to interleave with the store, and
		// no moment at which the store is empty and unbound: the old record is
		// REPLACED by the sentinel, never deleted first.
		$GLOBALS['_options']['aura_worker_ruleset'] = array( 'client' => 'client-old', 'seq' => 5, 'rules' => array(), 'envelope' => 'e' );
		$GLOBALS['_option_writes'] = array();
		$this->ml->handle_connect( $this->request( array( 'client' => 'client-new' ) ) );
		$writes = array_values( array_filter( $GLOBALS['_option_writes'], static function ( $w ) { return 'aura_worker_ruleset' === $w[1] || 'aura_worker_client' === $w[1]; } ) );
		$this->assertSame( array( array( 'set', 'aura_worker_ruleset' ) ), $writes, 'One write, to the ruleset option; no delete, no second option.' );
	}

	public function test_a_token_write_that_does_not_land_fails_the_connect_before_binding_or_consuming_the_magic_link(): void {
		// Codex round 30: the token row is verified exactly like the sentinel —
		// read back from the database. A refused or filtered token write must
		// not be followed by a sentinel for the requested hash (it would read as
		// stale: unbound behind a 200) nor by a consumed transient.
		// Since round-9 the token is written with one claim-conditional
		// statement, so an option FILTER can no longer rewrite it (that is the
		// point). What can still fail is the write itself.
		$GLOBALS['_options']['aura_worker_site_token'] = Aura_Worker_Security::hash_token( 'old-token' );
		$GLOBALS['_rows']['aura_worker_site_token']    = $GLOBALS['_options']['aura_worker_site_token'];
		$GLOBALS['_sa_option_write_fail'] = array( 'aura_worker_site_token' => true );
		$GLOBALS['_option_writes'] = array();
		$res = $this->ml->handle_connect( $this->request( array( 'client' => 'client-new', 'token' => 'new-token' ) ) );
		$GLOBALS['_sa_option_write_fail'] = array();
		$this->assertSame( 500, $res->get_status() );
		$this->assertSame( 'aura_connect_store_failed', $res->get_data()['code'] );
		$this->assertNotContains( array( 'set', 'aura_worker_ruleset' ), $GLOBALS['_option_writes'], 'No sentinel for a token that is not there.' );
		$this->assertSame( Aura_Worker_Security::hash_token( 'old-token' ), get_option( 'aura_worker_site_token' ) );
		$this->assertNotFalse( get_transient( 'aura_magic_' . $this->magic_id ) );
		$this->assertFalse( get_option( 'aura_magic_claim_' . $this->magic_id, false ) );
	}

	public function test_every_install_write_is_conditional_on_the_site_claim(): void {
		// Codex round-10: fencing only the token left the rest of the install
		// unconditional. A handler resuming after its claim was released could
		// overwrite the winner's binding with one naming its own superseded
		// token (which binds nobody — the site goes UNBOUND behind the
		// winner's 200), and replace the winner's dashboard URL and gateway
		// key, so grants signed for the winner's key fail closed.
		$claim = Aura_Worker_Magic_Link::SITE_CLAIM;
		$GLOBALS['_options'][ $claim ] = 'winner-fence|' . time();
		$GLOBALS['_rows'][ $claim ]    = $GLOBALS['_options'][ $claim ];
		$winner_url = 'https://winner.example';
		$GLOBALS['_options']['aura_worker_dashboard_url'] = $winner_url;
		$GLOBALS['_rows']['aura_worker_dashboard_url']    = $winner_url;
		$GLOBALS['_options']['aura_worker_grant_pubkey']  = 'winner-key';
		$GLOBALS['_rows']['aura_worker_grant_pubkey']     = 'winner-key';

		$bound = Aura_Worker_Rules::bind( 'client-loser', Aura_Worker_Security::hash_token( 'losers-token' ), $claim, 'loser-fence' );
		$this->assertInstanceOf( WP_Error::class, $bound, 'a superseded handler cannot bind' );
		$this->assertNull( sa_read_option_uncached( Aura_Worker_Rules::OPTION ) );

		Aura_Worker_Rules::write_option_if_claimed( 'aura_worker_dashboard_url', 'https://loser.example', $claim, 'loser-fence' );
		Aura_Worker_Rules::write_option_if_claimed( 'aura_worker_grant_pubkey', 'loser-key', $claim, 'loser-fence' );
		Aura_Worker_Rules::delete_option_if_claimed( 'aura_worker_grant_pubkey', $claim, 'loser-fence' );
		Aura_Worker_Rules::clear( $claim, 'loser-fence' );
		$this->assertSame( $winner_url, sa_read_option_uncached( 'aura_worker_dashboard_url' ) );
		$this->assertSame( 'winner-key', sa_read_option_uncached( 'aura_worker_grant_pubkey' ) );

		// The holder's own writes land, including the delete and the clear.
		$this->assertTrue( Aura_Worker_Rules::bind( 'client-winner', Aura_Worker_Security::hash_token( 'winners-token' ), $claim, 'winner-fence' ) );
		Aura_Worker_Rules::write_option_if_claimed( 'aura_worker_dashboard_url', 'https://winner2.example', $claim, 'winner-fence' );
		$this->assertSame( 'https://winner2.example', sa_read_option_uncached( 'aura_worker_dashboard_url' ) );
		Aura_Worker_Rules::delete_option_if_claimed( 'aura_worker_grant_pubkey', $claim, 'winner-fence' );
		$this->assertNull( sa_read_option_uncached( 'aura_worker_grant_pubkey' ) );
		Aura_Worker_Rules::clear( $claim, 'winner-fence' );
		$this->assertNull( sa_read_option_uncached( Aura_Worker_Rules::OPTION ) );
	}

	public function test_a_grant_key_that_does_not_land_fails_the_connect(): void {
		// Round-18: Aura_Worker_Grant::is_enforced() follows the key. A write
		// that silently did not land leaves enforcement OFF while the dashboard
		// believes the site is protected — every approval-required and mutating
		// tool reachable with the site token alone, behind a 200.
		$key = base64_encode( str_repeat( 'k', 32 ) );
		$GLOBALS['_sa_option_write_fail'] = array( 'aura_worker_grant_pubkey' => true );
		$res = $this->ml->handle_connect( $this->request( array( 'grant_pubkey' => $key ) ) );
		$GLOBALS['_sa_option_write_fail'] = array();
		$this->assertSame( 500, $res->get_status() );
		$this->assertSame( 'aura_connect_store_failed', $res->get_data()['code'] );
		$this->assertNotFalse( get_transient( 'aura_magic_' . $this->magic_id ), 'retryable: the same callback can be repeated' );
		$this->assertFalse( get_option( 'aura_worker_grant_pubkey', false ) );

		// The mirror case: a keyless connect that cannot CLEAR a previous key
		// would leave the site failing every write closed against a key this
		// dashboard cannot sign for.
		$GLOBALS['_options']['aura_worker_grant_pubkey'] = 'old-key';
		$GLOBALS['_rows']['aura_worker_grant_pubkey']    = 'old-key';
		$GLOBALS['_sa_option_write_fail'] = array( 'aura_worker_grant_pubkey' => true );
		$res = $this->ml->handle_connect( $this->request() );
		$GLOBALS['_sa_option_write_fail'] = array();
		$this->assertSame( 500, $res->get_status() );
		$this->assertSame( 'aura_connect_store_failed', $res->get_data()['code'] );
	}

	public function test_a_binding_that_does_not_land_fails_the_connect_without_consuming_the_magic_link(): void {
		// update_option() can fail (or be a no-op) after the token was stored.
		// The connect must not report success unbound, and must leave the
		// one-time transient so the same variant can be retried.
		$GLOBALS['_sa_option_write_fail'] = array( 'aura_worker_ruleset' => true );
		$res = $this->ml->handle_connect( $this->request( array( 'client' => 'client-new' ) ) );
		$GLOBALS['_sa_option_write_fail'] = array();
		$this->assertSame( 500, $res->get_status() );
		$this->assertSame( 'aura_connect_store_failed', $res->get_data()['code'] );
		$this->assertSame( '', Aura_Worker_Rules::bound_client() );
		$this->assertNotFalse( get_transient( 'aura_magic_' . $this->magic_id ), 'The magic link is still usable for the retry.' );
		$this->assertFalse( get_option( 'aura_magic_claim_' . $this->magic_id, false ), '…and the claim is released, so the retry is not refused as in-progress.' );
	}

	public function test_a_client_line_not_covered_by_the_signature_is_refused(): void {
		// The param is present but the HMAC was computed without it: a stolen
		// token cannot re-home a site to an attacker-chosen client.
		$res = $this->ml->handle_connect( $this->request( array( 'client' => 'client-evil', 'sign_client' => '' ) ) );
		$this->assertSame( 401, $res->get_status() );
		$this->assertSame( '', Aura_Worker_Rules::bound_client() );
	}

	public function test_a_pubkey_moved_into_the_client_field_is_refused(): void {
		// Codex round 32: the 5th line for a pubkey is bare (2.x format). Were the
		// client line bare too, a request signed for { pubkey: PK, client: '' }
		// would verify unchanged as { pubkey: '', client: PK } — same five lines —
		// and bind the site to "PK" without the secret. The client line is
		// labelled `client:<id>`, so the moved value recomputes differently.
		$res = $this->ml->handle_connect( $this->request( array(
			'grant_pubkey' => '',
			'client'       => $this->pubkey,
			'sign_pubkey'  => $this->pubkey,
			'sign_client'  => '',
		) ) );
		$this->assertSame( 401, $res->get_status() );
		$this->assertSame( '', Aura_Worker_Rules::bound_client() );
		$this->assertNotSame( Aura_Worker_Security::hash_token( 'raw-token' ), get_option( 'aura_worker_site_token' ), 'nothing installed' );
	}

	public function test_a_client_line_without_a_pubkey_is_the_fifth_line_and_the_sentinel_survives_the_keyless_branch(): void {
		// Each optional line is appended iff its parameter is non-empty, in the
		// fixed order pubkey, client — a keyless connect with a client signs five
		// lines, and the plugin recomputes exactly that. This is the variant a
		// 2.10.2 site WITHOUT libsodium accepts, so the keyless branch (which
		// deletes the grant key and, for an older dashboard, clears the store)
		// must leave the sentinel alone (Codex round 18).
		$res = $this->ml->handle_connect( $this->request( array( 'grant_pubkey' => null, 'client' => 'client-new' ) ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'client-new', Aura_Worker_Rules::bound_client() );
		$this->assertNotNull( Aura_Worker_Rules::stored(), 'The sentinel is still there.' );
		$this->assertFalse( Aura_Worker_Grant::is_enforced() );
	}

	public function test_a_connect_without_a_client_clears_as_before(): void {
		// An older Aura binds nothing: the store is cleared, exactly as 2.10.0
		// did, and the site reads as unbound.
		$GLOBALS['_options']['aura_worker_ruleset'] = array( 'client' => 'client-old', 'token_hash' => 'h', 'seq' => 0, 'rules' => array(), 'bound' => true );
		$res = $this->ml->handle_connect( $this->request() );
		$this->assertSame( 200, $res->get_status() );
		$this->assertNull( Aura_Worker_Rules::stored() );
		$this->assertSame( '', Aura_Worker_Rules::bound_client() );
	}

	public function test_a_concurrent_connect_for_the_same_magic_id_is_refused_at_the_claim_while_the_first_still_runs(): void {
		// Codex round 21 on the plan PR: Aura's fallback after a TIMEOUT can send
		// the next (weaker) variant while the first handler is still running —
		// past the transient check, before its success path deletes the
		// transient. Both would see the magic link as valid; the weaker one
		// would clear the binding the first is about to write. The claim is
		// taken before anything else and the second handler is refused there.
		$GLOBALS['_options']['aura_magic_claim_' . $this->magic_id] = 'other-fence|' . time(); // the first handler holds the claim
		$GLOBALS['_option_writes'] = array();
		$res = $this->ml->handle_connect( $this->request( array( 'grant_pubkey' => null ) ) ); // the weaker, clientless variant
		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'aura_connect_in_progress', $res->get_data()['code'] );
		$this->assertSame( array(), $GLOBALS['_option_writes'], 'Refused before the transient check: nothing written, nothing cleared.' );
		$this->assertNotFalse( get_transient( 'aura_magic_' . $this->magic_id ) );
		// …and the claim really is FIRST: with the same claim held, a magic link
		// whose transient is gone still answers the claim's 409, never the
		// transient's 400. Taken any later, the handler would already have run
		// checks — and, further down, writes — on a link another handler owns.
		delete_transient( 'aura_magic_' . $this->magic_id );
		$this->assertSame( 409, $this->ml->handle_connect( $this->request( array( 'client' => 'client-new' ) ) )->get_status() );
	}

	public function test_the_claim_is_released_on_a_refusal_and_after_a_success(): void {
		$this->assertSame( 401, $this->ml->handle_connect( $this->request( array( 'signature' => 'bad' ) ) )->get_status() );
		$this->assertFalse( get_option( 'aura_magic_claim_' . $this->magic_id, false ), 'A refused attempt releases the claim so the next variant can try.' );
		$this->assertSame( 200, $this->ml->handle_connect( $this->request( array( 'client' => 'client-new' ) ) )->get_status() );
		$this->assertFalse( get_option( 'aura_magic_claim_' . $this->magic_id, false ), 'Released after success too — no orphan row per connect; the CONSUMED TRANSIENT is what refuses a replay (400), not a lingering claim (409).' );
		$this->assertFalse( get_transient( 'aura_magic_' . $this->magic_id ) );
	}

	public function test_a_claim_is_never_taken_over_by_age(): void {
		// No timed takeover (Codex rounds 21–26): every takeover rule leaves an
		// interleaving in which a paused original resumes over its replacement.
		// A row — however old — refuses. A dead handler costs one magic link.
		$GLOBALS['_options']['aura_magic_claim_' . $this->magic_id] = 'dead-fence|' . ( time() - 6 * HOUR_IN_SECONDS );
		$GLOBALS['_option_writes'] = array();
		$res = $this->ml->handle_connect( $this->request( array( 'client' => 'client-new' ) ) );
		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'aura_connect_in_progress', $res->get_data()['code'] );
		$this->assertSame( array(), $GLOBALS['_option_writes'] );
	}

	private function claim( string $key ): string {
		$m = new ReflectionMethod( Aura_Worker_Magic_Link::class, 'claim_magic_link' );
		if ( PHP_VERSION_ID < 80100 ) {
			$m->setAccessible( true );
		}
		return (string) $m->invoke( null, $key );
	}

	public function test_two_claims_for_one_magic_link_admit_exactly_one(): void {
		// Codex round 1 on #66. Two callbacks for one magic link reach the claim
		// at the same moment. add_option() cannot serialise them: its existence
		// check and its write are two statements and the write is
		// `INSERT … ON DUPLICATE KEY UPDATE`, so BOTH callers get true, the
		// later fence overwrites the earlier one, and both handlers run on past
		// the still-live transient and interleave their token/binding writes.
		// A conditional INSERT cannot do that — wp_options' UNIQUE KEY on
		// option_name decides, and the loser is refused with ''.
		$key   = Aura_Worker_Rules::MAGIC_CLAIM . 'race';
		$other = '';
		// The other handler, running inside this one's window (between the
		// existence check and the write).
		$GLOBALS['_sa_before_swap'] = function () use ( $key, &$other ) {
			$GLOBALS['_sa_before_swap'] = null;
			$other = $this->claim( $key );
		};
		$mine = $this->claim( $key );

		$held = array_values( array_filter( array( $mine, $other ) ) );
		$this->assertCount( 1, $held, 'exactly one handler may hold the claim' );
		$this->assertSame( 0, strpos( (string) $GLOBALS['_options'][ $key ], $held[0] . '|' ), "…and the row carries that handler's fence, never the loser's" );
	}

	public function test_a_release_never_deletes_another_handlers_claim(): void {
		// The conditional DELETE names this handler's fence; a foreign row is untouched.
		$key = 'aura_magic_claim_' . $this->magic_id;
		$GLOBALS['_options'][ $key ] = 'other-fence|' . time();
		$m = new ReflectionMethod( Aura_Worker_Magic_Link::class, 'release_magic_link' );
		// Reflection ignores visibility since PHP 8.1; setAccessible() is only
		// needed on 7.4 (and is a deprecated no-op from 8.5) — as in SecurityTest.
		if ( PHP_VERSION_ID < 80100 ) {
			$m->setAccessible( true );
		}
		$m->invoke( null, $key, 'my-fence' );
		$this->assertSame( 0, strpos( (string) $GLOBALS['_options'][ $key ], 'other-fence|' ) );
	}

	public function test_orphaned_claims_are_swept_by_age_and_a_swept_orphan_admits_nobody(): void {
		// A dead handler's row is garbage: its transient is long gone. The daily
		// sweep removes rows older than an hour; the magic link still refuses
		// at the transient check.
		$key = 'aura_magic_claim_old';
		$GLOBALS['_options'][ $key ] = 'dead-fence|' . ( time() - 2 * HOUR_IN_SECONDS );
		Aura_Worker_Rules::enforce( array( array( 'type' => 'site', 'id' => '*' ) ), 'x' ); // runs note_expired() → the sweeps
		$this->assertArrayNotHasKey( $key, $GLOBALS['_options'] );
		$this->assertSame( 400, $this->ml->handle_connect( $this->request_for( 'old' ) )->get_status(), 'no transient → refused after the claim is taken' );
	}

	public function test_a_second_connect_with_the_same_magic_id_after_a_success_is_refused_before_anything_is_written(): void {
		// The one-time transient is consumed ONLY on success. This is what makes
		// Aura's variant fallback safe: if a client-bearing connect committed but
		// its response was lost to a timeout, every later variant for the same
		// magic_id is refused here — before the token, the binding or the store
		// is touched — so a weaker variant can never downgrade a binding that
		// already landed (Task 7).
		$this->assertSame( 200, $this->ml->handle_connect( $this->request( array( 'client' => 'client-new', 'token' => 'tok-1' ) ) )->get_status() );
		$GLOBALS['_option_writes'] = array();
		$res = $this->ml->handle_connect( $this->request( array( 'grant_pubkey' => null, 'token' => 'tok-2' ) ) ); // the bare variant, same magic_id
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( array(), $GLOBALS['_option_writes'], 'Nothing written: the refusal happens at the transient check.' );
		$this->assertSame( 'client-new', Aura_Worker_Rules::bound_client() );
		$this->assertSame( Aura_Worker_Security::hash_token( 'tok-1' ), get_option( 'aura_worker_site_token' ) );
	}

	public function test_legacy_four_and_five_line_signatures_still_validate(): void {
		$this->assertSame( 200, $this->ml->handle_connect( $this->request( array( 'grant_pubkey' => null ) ) )->get_status() );
		sa_reset_state();
		set_transient( 'aura_magic_' . $this->magic_id, array( 'connect_secret' => $this->secret, 'connect_user_id' => 1 ), 600 );
		$this->assertSame( 200, $this->ml->handle_connect( $this->request() )->get_status() );
	}

	// -----------------------------------------------------------------------
	// Ruling P44': the door survives a same-client reconnect, and only that
	// -----------------------------------------------------------------------

	/** A live previous binding: this site's token, its client, its dashboard. */
	private function seedPreviousBinding( ?string $client = 'c1', string $dashboard = 'https://dash.example' ): void {
		$hash = Aura_Worker_Security::hash_token( 'old-token' );
		$GLOBALS['_options']['aura_worker_site_token'] = $hash;
		$GLOBALS['_rows']['aura_worker_site_token']    = maybe_serialize( $hash );
		update_option( 'aura_worker_dashboard_url', $dashboard );
		if ( null !== $client ) {
			Aura_Worker_Rules::bind( $client, $hash );
		}
	}

	/** A hold and a settled log row belonging to that binding. */
	private function seedDoorState(): string {
		foreach ( array( 'class-aura-worker-door-log', 'class-aura-worker-door-holds', 'class-elementor-door-governor' ) as $f ) {
			require_once dirname( __DIR__, 2 ) . '/digitizer-site-worker/includes/' . $f . '.php';
		}
		$call = array(
			'ability' => 'elementor/publish-document',
			'input'   => array( 'post_id' => 7 ),
			'touches' => array( array( 'type' => 'page', 'id' => '7' ) ),
			'actor'   => array( 'user_id' => 3, 'login' => 'bot' ),
			'verdict' => 'none',
		);
		$ref = Aura_Worker_Door_Holds::hold( $call );
		$this->assertIsString( $ref );
		$seq = Aura_Worker_Door_Log::open_pending( $call );
		Aura_Worker_Door_Log::settle( $seq, array( 'result' => 'ok' ) );
		return (string) $ref;
	}

	/**
	 * Ruling P58: a changed-binding connect mints a NEW generation and rotates
	 * the epoch. Nothing is deleted, and it can never refuse.
	 *
	 * The departed client's hold is still on disk — and invisible: not listed,
	 * not claimable, not charging the cap. Its log entries stay too, because
	 * the log is the SITE's audit trail and the new client drains it by acking.
	 */
	public function test_a_changed_binding_connect_mints_a_new_generation_and_keeps_every_row(): void {
		$this->seedPreviousBinding( 'c1', 'https://dash.example' );
		$ref            = $this->seedDoorState();
		$before_binding = Aura_Worker_Door_Log::binding();
		$before_epoch   = Aura_Worker_Door_Log::epoch();

		$res = $this->ml->handle_connect( $this->request( array( 'client' => 'c2', 'dashboard_url' => 'https://dash.example' ) ) );

		$this->assertSame( 200, $res->get_status() );
		$this->assertNotSame( $before_binding, Aura_Worker_Door_Log::binding(), 'a new generation' );
		$this->assertNotSame( $before_epoch, Aura_Worker_Door_Log::epoch(), 'and a new epoch' );
		// THE ROWS ARE STILL THERE — and belong to nobody the new client can reach.
		$this->assertArrayHasKey( 'aura_worker_door_held_' . $ref, $GLOBALS['_rows'], 'nothing was deleted' );
		$this->assertNull( Aura_Worker_Door_Holds::get_held( $ref ), 'the departed hold is not readable' );
		$this->assertSame( array(), Aura_Worker_Door_Holds::listing(), 'nor listed' );
		$this->assertSame( 'not_held', Aura_Worker_Elementor_Door::replay( $ref, null )['reason'], 'nor replayable' );
		// …and the log is served regardless, each entry naming the binding that wrote it.
		$log = Aura_Worker_Door_Log::log_after( 0 );
		$this->assertNotSame( array(), $log, "the site's audit trail is not the binding's to take" );
		$this->assertSame( $before_binding, $log[0]['binding'], 'a departed client\'s entry, and it says so' );
	}

	/** A same-binding re-save leaves the generation, and the queue, alone. */
	public function test_a_same_binding_reconnect_leaves_the_generation_untouched(): void {
		$this->seedPreviousBinding( 'c1', 'https://dash.example' );
		$ref            = $this->seedDoorState();
		$before_binding = Aura_Worker_Door_Log::binding();

		$res = $this->ml->handle_connect( $this->request( array( 'client' => 'c1', 'dashboard_url' => 'https://dash.example' ) ) );

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( $before_binding, Aura_Worker_Door_Log::binding() );
		$this->assertNotNull( Aura_Worker_Door_Holds::get_held( $ref ), 'the operator keeps their queue' );
	}

	/**
	 * A token ROTATION is not a new owner. The same site reconnecting to the
	 * same client keeps its pending approvals and its unacked log — discarding
	 * an operator's queue because their credentials were refreshed would be a
	 * bug of its own.
	 */
	public function test_a_same_client_reconnect_keeps_the_doors_approvals(): void {
		$this->seedPreviousBinding( 'c1', 'https://dash.example' );
		$ref = $this->seedDoorState();

		$res = $this->ml->handle_connect( $this->request( array( 'client' => 'c1', 'dashboard_url' => 'https://dash.example' ) ) );

		$this->assertSame( 200, $res->get_status() );
		$this->assertNotNull( Aura_Worker_Door_Holds::get_held( $ref ), 'the approval survives a rotation' );
		$this->assertSame( array( 1 ), array_column( Aura_Worker_Door_Log::log_after( 0 ), 'seq' ), 'and so does the unacked log' );
		$this->assertTrue( Aura_Worker_Elementor_Door::present() );
	}

	/** …and a successful changed-binding connect still writes every one of them. */
	public function test_a_successful_changed_binding_connect_writes_them_all(): void {
		$this->seedPreviousBinding( 'c1', 'https://dash.example' );
		update_option( 'aura_worker_connect_user_id', 41 );
		$this->seedDoorState();

		$res = $this->ml->handle_connect( $this->request( array( 'client' => 'c2', 'dashboard_url' => 'https://new-dash.example' ) ) );

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 1, (int) get_option( 'aura_worker_connect_user_id' ), "the new link's administrator" );
		$this->assertSame( Aura_Worker_Security::hash_token( 'raw-token' ), get_option( 'aura_worker_site_token' ) );
		$this->assertSame( 'https://new-dash.example', get_option( 'aura_worker_dashboard_url' ) );
		$this->assertSame( $this->pubkey, get_option( 'aura_worker_grant_pubkey' ) );
		$this->assertSame( 'c2', Aura_Worker_Rules::bound_client() );
	}

	/** And the NEW side naming no client is unprovable in the same way. */
}
