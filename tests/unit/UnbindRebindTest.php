<?php
/**
 * THE WAY BACK (#434 Task 7).
 *
 * Phase A of an unbind writes `aura_worker_unbound` under the site claim and
 * the site then refuses every mutation; Phase B cleans up in a fixed order and
 * deletes the site token LAST. Tasks 5 and 6 closed the two write boundaries.
 * Nothing, until now, ever took the refusal away — so a re-connected site was
 * bound and permanently refusing.
 *
 * A rebind is the way back, and it is bracketed around the token install:
 *
 *   finish_before_rebind()   settles the DEPARTED binding's Phase-B debt while
 *                            the old token is still there to identify it, and
 *                            refuses the whole rebind (409) when something is
 *                            still owed. It must come first, because the token
 *                            write disarms Aura_Worker_Unbind::maybe_finish()
 *                            for good — the sweep bails on the hash mismatch a
 *                            replacement token creates — so a credential the
 *                            marker names and nothing revoked would be stranded
 *                            live with nothing left to revoke it.
 *   release_marker_after_rebind()  lifts the refusal, and ONLY as the last step
 *                            of a rebind that succeeded end to end.
 *
 * The ORDER is the safety property: the marker OUTLIVES the old token, so a
 * rebind that fails half-way leaves the site refusing the old token AND the
 * half-installed new one. Every test below is about one half of that bracket.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class UnbindRebindTest extends TestCase {

	/** The administrator both flows run as. */
	const ADMIN = 3;

	/** The Application Password the departed binding held. */
	const OLD_UUID = 'uuid-old';

	private Aura_Worker_Magic_Link $ml;
	private Aura_Worker $plugin;
	private string $secret   = 'one-time-connect-secret';
	private string $magic_id = 'magic123';

	protected function setUp(): void {
		sa_reset_state();
		$this->ml     = new Aura_Worker_Magic_Link();
		$this->plugin = new Aura_Worker();
		// admin-ajax.php fires admin_init before dispatching, which is where the
		// plugin registers its settings (and their sanitize filters).
		$this->plugin->register_settings();
		$GLOBALS['_admins']          = array( self::ADMIN );
		$GLOBALS['_current_user_id'] = self::ADMIN;
		set_transient(
			'aura_magic_' . $this->magic_id,
			array( 'connect_secret' => $this->secret, 'connect_user_id' => self::ADMIN ),
			600
		);
		// The state Phase A leaves: a full binding, and a marker naming it.
		// The token goes in through update_option() rather than being seeded
		// into $_options alone, because "Regenerate Token" swaps it with a
		// byte-exact compare-and-swap against the ROW — a token that exists
		// only in the option cache matches no row and every rotation here
		// would fail for the wrong reason.
		update_option( 'aura_worker_site_token', Aura_Worker_Security::hash_token( SA_RAW_SITE_TOKEN ) );
		update_option( 'aura_worker_connect_user_id', self::ADMIN );
		update_option( 'aura_worker_dashboard_url', 'https://departed.example' );
		Aura_Worker_Rules::bind( 'c1', sa_token_hash() );
		sa_set_managed_app_password( self::ADMIN, self::OLD_UUID );
		sa_set_marker(
			array(
				'site'               => sa_token_hash(),
				'seq'                => 9,
				'connect_user_id'    => self::ADMIN,
				'app_password_uuids' => array( self::OLD_UUID ),
				'app_password_users' => array( self::OLD_UUID => self::ADMIN ),
			)
		);
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * The marker as the DATABASE holds it, never through
	 * Aura_Worker_Unbind::read() — which normalises, and which is production
	 * code this file is testing rather than a witness it may lean on.
	 *
	 * @return array|null
	 */
	private function markerRow(): ?array {
		$raw = sa_read_option_uncached( Aura_Worker_Unbind::OPTION );
		if ( null === $raw ) {
			return null;
		}
		$row = is_string( $raw ) ? maybe_unserialize( $raw ) : $raw;
		return is_array( $row ) ? $row : null;
	}

	/** The site token hash as the database holds it. */
	private function tokenRow(): ?string {
		$raw = sa_read_option_uncached( 'aura_worker_site_token' );
		return null === $raw ? null : (string) maybe_unserialize( $raw );
	}

	/**
	 * A signed connect callback, the way the dashboard sends one.
	 *
	 * Keyless on purpose: this file is about the marker, not about grants, and
	 * a keyless callback keeps it runnable on a host without ext-sodium.
	 *
	 * @param string $token  The raw site token the dashboard is installing.
	 * @param string $client The Aura client it binds this site to.
	 * @return WP_REST_Request
	 */
	private function connectRequest( string $token = 'the-new-token', string $client = 'c2' ): WP_REST_Request {
		$dash = 'https://arrived.example';
		$ts   = time();
		$req  = new WP_REST_Request();
		$req->set_param( 'magic_id', $this->magic_id );
		$req->set_param( 'token', $token );
		$req->set_param( 'dashboard_url', $dash );
		$req->set_param( 'timestamp', $ts );
		$req->set_param( 'client', $client );
		$req->set_param( 'signature', Aura_Worker_Magic_Link::sign_connect_payload( $this->secret, $this->magic_id, $token, $dash, $ts, '', $client ) );
		return $req;
	}

	/** Run the AJAX rotation and return the JSON response it terminated with. */
	private function regenerate(): SA_Json_Response {
		try {
			$this->plugin->ajax_regenerate_token();
		} catch ( SA_Json_Response $res ) {
			return $res;
		}
		$this->fail( 'ajax_regenerate_token() returned without sending a JSON response' );
	}

	/**
	 * Run the LIVE permission callback for a mutating route, presenting a raw
	 * site token. Never a fake: the refusal has to be the one the route
	 * actually answers with.
	 *
	 * @param string $raw The raw token to present.
	 * @return mixed
	 */
	private function mutateAsToken( string $raw ) {
		$GLOBALS['_logged_in'] = false; // Layer 2.5's precondition: no app-password user
		$request               = new WP_REST_Request();
		$request->set_method( 'POST' );
		$request->set_route( '/aura/v1/update/core' );
		$request->set_header( 'X-Aura-Token', $raw );
		return sa_dispatch_permission( $request );
	}

	/**
	 * @param mixed  $result What a permission callback answered.
	 * @param string $why    Message context.
	 */
	private function assertUnbound( $result, string $why ): void {
		$this->assertInstanceOf( WP_Error::class, $result, $why );
		$this->assertSame( 'aura_site_unbound', $result->get_error_code(), $why );
	}

	// -----------------------------------------------------------------------
	// The bracket's first half, on its own.
	// -----------------------------------------------------------------------

	/** A site that was never unbound owes nothing, and nothing is touched. */
	public function test_finish_before_rebind_is_a_no_op_on_a_bound_site(): void {
		sa_clear_marker();
		$fence  = Aura_Worker_Magic_Link::claim_site();
		$before = $GLOBALS['_option_writes'];
		$this->assertTrue( Aura_Worker_Unbind::finish_before_rebind( $fence ) );
		$this->assertSame( $before, $GLOBALS['_option_writes'], 'a bound site is not swept' );
		$this->assertSame( self::ADMIN, (int) get_option( 'aura_worker_connect_user_id' ) );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	/**
	 * Steps (1)-(4), and NOT step (5): the token stays, because the rebind is
	 * about to replace it and the flow's own write-and-verify must find it.
	 */
	public function test_finish_before_rebind_settles_phase_b_and_leaves_the_token(): void {
		$GLOBALS['_options']['aura_worker_grant_pubkey'] = 'a-gateway-public-key';
		$fence = Aura_Worker_Magic_Link::claim_site();
		$this->assertTrue( Aura_Worker_Unbind::finish_before_rebind( $fence ) );
		$this->assertFalse( sa_app_password_exists( self::ADMIN, self::OLD_UUID ), 'the departed credential is revoked' );
		$this->assertNull( sa_read_option_uncached( 'aura_worker_connect_user_id' ) );
		$this->assertNull( sa_read_option_uncached( 'aura_worker_dashboard_url' ) );
		$this->assertNull( sa_read_option_uncached( Aura_Worker_Rules::OPTION ) );
		$this->assertNull( sa_read_option_uncached( 'aura_worker_grant_pubkey' ) );
		$this->assertSame( sa_token_hash(), $this->tokenRow(), 'the token is NOT step (1)-(4)\'s to delete' );
		$this->assertNotNull( $this->markerRow(), 'and the marker is not this half\'s to clear' );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	/**
	 * THE SUBTLE ONE. A credential the marker names that will not die must stop
	 * the rebind BEFORE the token is replaced — after that write nothing on
	 * this site would ever go looking for it again (maybe_finish() bails on the
	 * hash mismatch), so it would be a live `manage_options` credential for a
	 * departed dashboard with nothing left to revoke it.
	 */
	public function test_finish_before_rebind_refuses_while_a_credential_survives(): void {
		$GLOBALS['_fail_delete_app_password'] = self::OLD_UUID;
		$fence = Aura_Worker_Magic_Link::claim_site();
		$out   = Aura_Worker_Unbind::finish_before_rebind( $fence );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aura_unbind_incomplete', $out->get_error_code() );
		$this->assertSame( 409, $out->get_error_data()['status'] );
		$this->assertContains( 'app_passwords', $out->get_error_data()['leftover'] );
		$this->assertNotNull( $this->markerRow() );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	/**
	 * A marker that cannot be READ takes the same refusal, and that is the
	 * fail-CLOSED direction: cleanup() does nothing at all for a marker it
	 * cannot name, so nothing can be proven settled. Reconnecting anyway would
	 * clear a refusal over a binding whose credentials were never enumerated.
	 *
	 * But it refuses with its own CODE, not `aura_unbind_incomplete` (round-1
	 * MINOR-1): nothing was found to be owed here, nobody could look.
	 */
	public function test_finish_before_rebind_refuses_an_unreadable_marker(): void {
		$fence = Aura_Worker_Magic_Link::claim_site();
		$GLOBALS['_sa_option_read_fail'][ Aura_Worker_Unbind::OPTION ] = true;
		$out = Aura_Worker_Unbind::finish_before_rebind( $fence );
		$GLOBALS['_sa_option_read_fail'] = array();
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aura_unbind_unreadable', $out->get_error_code() );
		$this->assertSame( 409, $out->get_error_data()['status'] );
		$this->assertArrayNotHasKey( 'leftover', $out->get_error_data(), '"everything is owed" here means "nothing could be checked"' );
		$this->assertTrue( sa_app_password_exists( self::ADMIN, self::OLD_UUID ), 'and nothing was deleted for a binding the site cannot name' );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	/**
	 * A MALFORMED marker is a record that exists and cannot be trusted, which
	 * is the same unknown and gets the same account of itself.
	 */
	public function test_finish_before_rebind_refuses_a_malformed_marker(): void {
		sa_set_marker( array( 'site_ref' => null ) ); // present, and not the shape read() accepts
		$fence = Aura_Worker_Magic_Link::claim_site();
		$out   = Aura_Worker_Unbind::finish_before_rebind( $fence );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aura_unbind_unreadable', $out->get_error_code() );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	/**
	 * THE POINT OF THE SEPARATE CODE (round-1 MINOR-1). At a site that was
	 * NEVER unbound, a blip in the marker read still refuses — fail-closed, and
	 * nothing is lost because nothing has been written yet — but it must not
	 * tell the operator of a fresh site about a disconnect that never happened.
	 * The message asserted here is about the record being unreadable, and says
	 * nothing about a previous binding.
	 */
	public function test_a_read_blip_at_a_site_that_was_never_unbound_does_not_invent_a_disconnect(): void {
		sa_clear_marker();
		$fence = Aura_Worker_Magic_Link::claim_site();
		$GLOBALS['_sa_option_read_fail'][ Aura_Worker_Unbind::OPTION ] = true;
		$out = Aura_Worker_Unbind::finish_before_rebind( $fence );
		$GLOBALS['_sa_option_read_fail'] = array();
		$this->assertInstanceOf( WP_Error::class, $out, 'still refuses: unreadable is not a clean site' );
		$this->assertSame( 'aura_unbind_unreadable', $out->get_error_code() );
		$this->assertStringNotContainsString( 'previous Aura binding', $out->get_error_message() );
		$this->assertStringContainsString( 'could not be read', $out->get_error_message() );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	/** The release is claim-conditional, like every other lifecycle write. */
	public function test_release_marker_after_rebind_needs_the_claim(): void {
		$this->assertFalse( Aura_Worker_Unbind::release_marker_after_rebind( '' ) );
		$this->assertFalse( Aura_Worker_Unbind::release_marker_after_rebind( 'not-the-holder' ) );
		$this->assertNotNull( $this->markerRow() );
		$fence = Aura_Worker_Magic_Link::claim_site();
		$this->assertTrue( Aura_Worker_Unbind::release_marker_after_rebind( $fence ) );
		$this->assertNull( $this->markerRow() );
		$this->assertTrue( Aura_Worker_Unbind::release_marker_after_rebind( $fence ), 'idempotent: nothing left to clear' );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	// -----------------------------------------------------------------------
	// The connect callback.
	// -----------------------------------------------------------------------

	public function test_the_connect_callback_finishes_phase_b_then_clears_the_marker(): void {
		$res = $this->ml->handle_connect( $this->connectRequest() );
		$this->assertSame( 200, $res->get_status(), var_export( $res->get_data(), true ) );
		$this->assertNull( $this->markerRow(), 'the refusal is lifted' );
		$this->assertFalse( sa_app_password_exists( self::ADMIN, self::OLD_UUID ), 'the departed credential went first' );
		$this->assertSame( Aura_Worker_Security::hash_token( 'the-new-token' ), $this->tokenRow() );
		$this->assertSame( 'c2', Aura_Worker_Rules::bound_client() );
		$this->assertSame( '', (string) get_option( Aura_Worker_Magic_Link::SITE_CLAIM, '' ), 'the claim is released' );
		// …and the replacement binding mutates normally.
		$this->assertTrue( $this->mutateAsToken( 'the-new-token' ) );
	}

	/**
	 * THE ORDERING, read off the writes themselves: the marker is deleted
	 * AFTER the replacement token is written. Reversed, a rebind that then
	 * failed would have re-opened every mutation on a site with no binding.
	 */
	public function test_the_marker_is_cleared_after_the_replacement_token_is_written(): void {
		$GLOBALS['_option_writes'] = array();
		$this->assertSame( 200, $this->ml->handle_connect( $this->connectRequest() )->get_status() );
		$order = array();
		foreach ( $GLOBALS['_option_writes'] as $write ) {
			if ( 'aura_worker_site_token' === $write[1] || Aura_Worker_Unbind::OPTION === $write[1] ) {
				$order[] = $write[0] . ':' . $write[1];
			}
		}
		$this->assertSame(
			array( 'set:aura_worker_site_token', 'delete:' . Aura_Worker_Unbind::OPTION ),
			$order
		);
	}

	/**
	 * A rebind that installed the token and then failed leaves the marker —
	 * and so leaves the site refusing BOTH tokens. This is the round-5 case:
	 * releasing after the token write alone re-enabled mutations when bind()
	 * then failed.
	 *
	 * It is also the window mandate 4 is about: between the token write and
	 * the marker release, `/status` still reports `unbound`, because the
	 * rebind has not happened yet. Aura's connect preflight keys on that field,
	 * and telling it otherwise would let it write a binding to a site that
	 * refuses everything.
	 */
	public function test_the_connect_callback_keeps_the_marker_when_the_binding_write_fails(): void {
		$GLOBALS['_sa_option_write_fail'][ Aura_Worker_Rules::OPTION ] = true;
		$res = $this->ml->handle_connect( $this->connectRequest() );
		$GLOBALS['_sa_option_write_fail'] = array();
		$this->assertSame( 500, $res->get_status() );
		$this->assertSame( 'aura_connect_store_failed', $res->get_data()['code'] );
		$this->assertNotNull( $this->markerRow(), 'the replacement binding was never established' );
		$this->assertSame( Aura_Worker_Security::hash_token( 'the-new-token' ), $this->tokenRow(), 'the token WAS replaced — which is exactly why the marker had to outlive it' );
		$this->assertNotNull( Aura_Worker_Unbind::status_fragment(), '/status still reports this site unbound' );
		$this->assertUnbound( $this->mutateAsToken( 'the-new-token' ), 'the half-installed replacement token' );
	}

	/**
	 * A leftover refuses the whole rebind: 409, the leftovers named, and the
	 * OLD token untouched — so a retry of the same tombstone can still reach
	 * this site and the operator still has something to repair with.
	 */
	public function test_the_connect_callback_refuses_a_rebind_that_would_strand_a_credential(): void {
		$GLOBALS['_fail_delete_app_password'] = self::OLD_UUID;
		$res = $this->ml->handle_connect( $this->connectRequest() );
		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'aura_unbind_incomplete', $res->get_data()['code'] );
		$this->assertContains( 'app_passwords', $res->get_data()['leftover'] );
		$this->assertNotNull( $this->markerRow() );
		$this->assertSame( sa_token_hash(), $this->tokenRow(), 'the old token is not replaced' );
		$this->assertUnbound( $this->mutateAsToken( SA_RAW_SITE_TOKEN ), 'the departed token' );
	}

	/**
	 * The release itself failing is a STORE failure, so the connect must stay
	 * retryable: the magic link is not consumed, and the retry — whose own
	 * finish_before_rebind() finds nothing owed — completes and clears it.
	 */
	public function test_a_failed_marker_release_is_retryable_and_the_retry_completes(): void {
		$GLOBALS['_sa_option_delete_fail'][ Aura_Worker_Unbind::OPTION ] = true;
		$res = $this->ml->handle_connect( $this->connectRequest() );
		$GLOBALS['_sa_option_delete_fail'] = array();
		$this->assertSame( 500, $res->get_status() );
		$this->assertSame( 'aura_unbind_store_failed', $res->get_data()['code'] );
		$this->assertNotNull( $this->markerRow(), 'still refusing' );
		$this->assertNotFalse( get_transient( 'aura_magic_' . $this->magic_id ), 'the link survives for the retry' );

		$retry = $this->ml->handle_connect( $this->connectRequest( 'the-newer-token' ) );
		$this->assertSame( 200, $retry->get_status(), var_export( $retry->get_data(), true ) );
		$this->assertNull( $this->markerRow() );
		$this->assertSame( Aura_Worker_Security::hash_token( 'the-newer-token' ), $this->tokenRow() );
	}

	/**
	 * MINOR-2's pin, and the twin of
	 * test_regenerate_keeps_the_marker_when_the_connect_user_write_fails.
	 * `aura_worker_connect_user_id` is the half of the binding a token-only
	 * request runs on, and Phase B deleted it — so a connect whose own write of
	 * it did not land has not re-established the binding, whatever else
	 * succeeded. Unproven, the marker stays. Both flows, one meaning of
	 * "proven rebind"; this test and its twin are what stop the asymmetry
	 * coming back.
	 */
	public function test_the_connect_callback_keeps_the_marker_when_the_connect_user_write_fails(): void {
		$GLOBALS['_sa_option_write_fail']['aura_worker_connect_user_id'] = true;
		$res = $this->ml->handle_connect( $this->connectRequest() );
		$GLOBALS['_sa_option_write_fail'] = array();
		$this->assertSame( 500, $res->get_status() );
		$this->assertSame( 'aura_unbind_store_failed', $res->get_data()['code'] );
		$this->assertNotNull( $this->markerRow() );
		$this->assertNull( sa_read_option_uncached( 'aura_worker_connect_user_id' ) );
		$this->assertNotFalse( get_transient( 'aura_magic_' . $this->magic_id ), 'retryable' );
		$this->assertUnbound( $this->mutateAsToken( 'the-new-token' ), 'the half-installed replacement token' );
	}

	/**
	 * NIT-1's pin: NOTHING fallible sits after the marker release. The last
	 * write of a token-only install is the "this site cannot have an
	 * Application Password" record, and it lands BEFORE the refusal is lifted.
	 */
	public function test_the_last_install_write_lands_before_the_marker_is_cleared(): void {
		$GLOBALS['_app_passwords_available'] = false; // a healthy token-only site
		$GLOBALS['_option_writes']           = array();
		$res = $this->ml->handle_connect( $this->connectRequest() );
		$this->assertSame( 200, $res->get_status(), var_export( $res->get_data(), true ) );
		$this->assertSame( 'app_passwords_unavailable', $res->get_data()['app_password_unavailable'] );
		$record  = null;
		$cleared = null;
		foreach ( $GLOBALS['_option_writes'] as $i => $write ) {
			if ( 'set' === $write[0] && Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION === $write[1] ) {
				$record = $i;
			}
			if ( 'delete' === $write[0] && Aura_Worker_Unbind::OPTION === $write[1] ) {
				$cleared = $i;
			}
		}
		$this->assertNotNull( $record, 'the token-only record was written' );
		$this->assertNotNull( $cleared, 'and the marker was cleared' );
		$this->assertLessThan( $cleared, $record, 'the install\'s last fallible write precedes the release' );
	}

	/** A site that was never unbound connects exactly as it did before. */
	public function test_a_connect_at_a_bound_site_is_unchanged(): void {
		sa_clear_marker();
		$res = $this->ml->handle_connect( $this->connectRequest() );
		$this->assertSame( 200, $res->get_status() );
		$this->assertNull( $this->markerRow() );
		$this->assertSame( Aura_Worker_Security::hash_token( 'the-new-token' ), $this->tokenRow() );
	}

	// -----------------------------------------------------------------------
	// "Regenerate Token" — the operator's own rebind.
	// -----------------------------------------------------------------------

	public function test_regenerate_finishes_phase_b_then_clears_the_marker(): void {
		$res = $this->regenerate();
		$this->assertTrue( $res->success, var_export( $res->data, true ) );
		$this->assertNull( $this->markerRow() );
		$this->assertFalse( sa_app_password_exists( self::ADMIN, self::OLD_UUID ) );
		// The half of the replacement binding a token-only request runs on:
		// Phase B deleted it, so the rotation's write is the only thing naming
		// this administrator, and it is proven before the refusal is lifted.
		$this->assertSame( self::ADMIN, (int) get_option( 'aura_worker_connect_user_id' ) );
		$raw = (string) $res->data['token'];
		$this->assertSame( Aura_Worker_Security::hash_token( $raw ), $this->tokenRow() );
		$this->assertTrue( $this->mutateAsToken( $raw ), 'the rotated site mutates again' );
	}

	/**
	 * A swap that did not land: the marker survives, the OLD token is still the
	 * site's, and it is still refused. The marker outliving the token is what
	 * makes that true — nothing else in this flow would have refused it.
	 */
	public function test_a_failed_swap_keeps_the_marker_and_the_old_token_stays_refused(): void {
		$GLOBALS['_cas_always_lose'] = true;
		$res = $this->regenerate();
		$GLOBALS['_cas_always_lose'] = false;
		$this->assertFalse( $res->success );
		$this->assertSame( 500, $res->status );
		$this->assertNotNull( $this->markerRow(), 'the marker survives a failed swap' );
		$this->assertSame( sa_token_hash(), $this->tokenRow() );
		$this->assertFalse( get_transient( 'aura_worker_token_reveal' ), 'nothing was revealed' );
		$this->assertUnbound( $this->mutateAsToken( SA_RAW_SITE_TOKEN ), 'the old token' );
	}

	/**
	 * The connect-user write is half of the replacement binding, and Phase B
	 * deleted the row it would otherwise refresh. Unproven, the marker stays —
	 * and the reveal is withheld with it, so the operator is not handed a token
	 * for a site that goes on refusing. Nothing is lost: the rotation is
	 * simply repeated.
	 */
	public function test_regenerate_keeps_the_marker_when_the_connect_user_write_fails(): void {
		$GLOBALS['_sa_option_write_fail']['aura_worker_connect_user_id'] = true;
		$res = $this->regenerate();
		$GLOBALS['_sa_option_write_fail'] = array();
		$this->assertFalse( $res->success );
		$this->assertSame( 500, $res->status );
		$this->assertSame( 'aura_unbind_store_failed', $res->data['code'] );
		$this->assertNotNull( $this->markerRow() );
		$this->assertFalse( get_transient( 'aura_worker_token_reveal' ) );
		$this->assertNull( sa_read_option_uncached( 'aura_worker_connect_user_id' ) );
	}

	/** The same leftover refusal, on the operator's path. */
	public function test_regenerate_refuses_a_rebind_that_would_strand_a_credential(): void {
		$GLOBALS['_fail_delete_app_password'] = self::OLD_UUID;
		$res = $this->regenerate();
		$this->assertFalse( $res->success );
		$this->assertSame( 409, $res->status );
		$this->assertSame( 'aura_unbind_incomplete', $res->data['code'] );
		$this->assertContains( 'app_passwords', $res->data['leftover'] );
		$this->assertNotNull( $this->markerRow() );
		$this->assertSame( sa_token_hash(), $this->tokenRow(), 'nothing was rotated' );
		$this->assertFalse( get_transient( 'aura_worker_token_reveal' ) );
	}

	/** A release that will not land withholds the reveal and keeps refusing. */
	public function test_a_failed_marker_release_withholds_the_reveal(): void {
		$GLOBALS['_sa_option_delete_fail'][ Aura_Worker_Unbind::OPTION ] = true;
		$res = $this->regenerate();
		$GLOBALS['_sa_option_delete_fail'] = array();
		$this->assertFalse( $res->success );
		$this->assertSame( 500, $res->status );
		$this->assertSame( 'aura_unbind_store_failed', $res->data['code'] );
		$this->assertNotNull( $this->markerRow() );
		$this->assertFalse( get_transient( 'aura_worker_token_reveal' ) );
	}

	/**
	 * #67's rule is about a read that fails AFTER the token was stored: it must
	 * not withhold a token that landed. The marker read is BEFORE the swap, so
	 * refusing there stores nothing and loses nothing — the old token is still
	 * the site's and the operator simply tries again.
	 */
	public function test_an_unreadable_marker_refuses_the_rotation_before_anything_is_stored(): void {
		$GLOBALS['_sa_option_read_fail'][ Aura_Worker_Unbind::OPTION ] = true;
		$res = $this->regenerate();
		$GLOBALS['_sa_option_read_fail'] = array();
		$this->assertFalse( $res->success );
		$this->assertSame( 409, $res->status );
		$this->assertSame( 'aura_unbind_unreadable', $res->data['code'] );
		$this->assertSame( sa_token_hash(), $this->tokenRow(), 'the token that was there is still there' );
		$this->assertFalse( get_transient( 'aura_worker_token_reveal' ) );
		$this->assertSame( '', (string) get_option( Aura_Worker_Magic_Link::SITE_CLAIM, '' ), 'the claim is released' );
	}
}
