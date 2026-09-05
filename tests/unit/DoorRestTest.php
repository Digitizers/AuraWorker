<?php
/**
 * REST `/door/reject` and `/door/ack` — the operator-facing side of the
 * Elementor door's hold queue and log (spec §3.6-3.10) — plus the MCP tool
 * `snapshot_get`. Both routes are grant-gated exactly like restore_snapshot():
 * the same permission_callback (check_admin_permission, 401 with no/bad
 * token) and the same Aura_Worker_Grant::require_for() (403 with no/bad grant
 * once a gateway key is provisioned).
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class DoorRestTest extends TestCase {

	private $api;

	protected function setUp(): void {
		sa_reset_state();
		$GLOBALS['_options']['aura_worker_site_token'] = Aura_Worker_Security::hash_token( 'tok' );
		// The coverage check every real request has already run by dispatch
		// time (`wp_abilities_api_init` fires on init, REST on rest_api_init).
		// The ack response's `door` field reads the seam through
		// door_state(), so a suite that never decided it would be reading
		// whatever the previous test class left on the static.
		Aura_Worker_Elementor_Door::reset_for_tests();
		Aura_Worker_Elementor_Door::init();
		// Elementor IS on this site: every request modelled here is an ack or
		// a reject against a LIVE door, and since Ruling P30 `door_state()`
		// reads active() too — a site with no Elementor is closed however
		// healthy its seam and its log are.
		$GLOBALS['_sa_force_door'] = true; // stands in for Elementor's MCP module class
		do_action( 'wp_abilities_api_init' );
		$this->api = new Aura_Worker_API( new Aura_Worker_Security() );
	}

	private function request( array $params ): WP_REST_Request {
		$req = new WP_REST_Request();
		$req->set_header( 'X-Aura-Token', 'tok' );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return $req;
	}

	private function call( array $over = array() ): array {
		return $over + array(
			'ability' => 'elementor/publish-document',
			'input'   => array( 'post_id' => 7 ),
			'touches' => array( array( 'type' => 'page', 'id' => '7' ) ),
			'actor'   => array( 'user_id' => 3, 'login' => 'bot' ),
			'verdict' => 'none',
			'rule'    => null,
		);
	}

	/** @var string|null the gateway secret key, once enforce_grants() has run */
	private $grant_secret = null;

	/** Provisions a real gateway pubkey, turning on grant enforcement. */
	private function enforce_grants(): void {
		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
			$this->markTestSkipped( 'ext-sodium is not available.' );
		}
		$kp = sodium_crypto_sign_keypair();
		$this->grant_secret = sodium_crypto_sign_secretkey( $kp );
		$GLOBALS['_options']['aura_worker_grant_pubkey'] = base64_encode( sodium_crypto_sign_publickey( $kp ) );
	}

	/** One signed, single-use grant over ($action, $params), the way Aura mints it. */
	private function mint( string $action, array $params ): string {
		$now     = time();
		$payload = array(
			'v'             => 1,
			'tool'          => $action,
			'params_sha256' => hash( 'sha256', Aura_Worker_Grant::canonical_json( $params ) ),
			'site'          => (string) get_option( 'aura_worker_site_token', '' ),
			'nonce'         => bin2hex( random_bytes( 16 ) ),
			'iat'           => $now,
			'exp'           => $now + 300,
		);
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$sig  = sodium_crypto_sign_detached( $json, (string) $this->grant_secret );
		$b64  = static function ( string $raw ): string {
			return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
		};
		return $b64( $json ) . '.' . $b64( $sig );
	}

	/* ---- POST /aura/v2/snapshot/restore: the grant binds aura_ref ---- */

	/**
	 * `aura_ref` is the correlation id ingestion associates the terminal
	 * result with an AgentAction. A grant minted over `{id}` alone left it
	 * unbound, so anyone who could replay a valid restore grant could swap
	 * the correlation id (Ruling P13).
	 */
	public function test_a_grant_over_the_id_alone_is_refused_when_the_request_carries_an_aura_ref(): void {
		$this->enforce_grants();
		$req = $this->request( array( 'id' => 'snap_x', 'aura_ref' => 'act_9' ) );
		$req->set_header( 'X-Aura-Approval-Grant', $this->mint( 'wp.snapshot.restore', array( 'id' => 'snap_x' ) ) );

		$res = $this->api->restore_snapshot( $req );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_grant_required', $res->get_error_code() );
		$this->assertSame( 403, $res->get_error_data()['status'] );
	}

	public function test_a_grant_minted_over_the_id_and_the_same_aura_ref_passes(): void {
		$this->enforce_grants();
		$req = $this->request( array( 'id' => 'snap_x', 'aura_ref' => 'act_9' ) );
		$req->set_header( 'X-Aura-Approval-Grant', $this->mint( 'wp.snapshot.restore', array( 'id' => 'snap_x', 'aura_ref' => 'act_9' ) ) );

		$res = $this->api->restore_snapshot( $req );

		// Past the grant: the envelope simply is not on this site.
		$this->assertInstanceOf( WP_REST_Response::class, $res );
		$this->assertSame( 404, $res->get_status() );
	}

	public function test_a_grant_bound_to_one_aura_ref_does_not_authorize_another(): void {
		$this->enforce_grants();
		$req = $this->request( array( 'id' => 'snap_x', 'aura_ref' => 'act_OTHER' ) );
		$req->set_header( 'X-Aura-Approval-Grant', $this->mint( 'wp.snapshot.restore', array( 'id' => 'snap_x', 'aura_ref' => 'act_9' ) ) );

		$res = $this->api->restore_snapshot( $req );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_grant_required', $res->get_error_code() );
	}

	/** A legacy caller that sends no correlation id is unchanged: `{id}` alone. */
	public function test_a_request_without_an_aura_ref_passes_under_a_grant_over_the_id_alone(): void {
		$this->enforce_grants();
		$req = $this->request( array( 'id' => 'snap_x' ) );
		$req->set_header( 'X-Aura-Approval-Grant', $this->mint( 'wp.snapshot.restore', array( 'id' => 'snap_x' ) ) );

		$res = $this->api->restore_snapshot( $req );

		$this->assertInstanceOf( WP_REST_Response::class, $res );
		$this->assertSame( 404, $res->get_status() );
	}

	/* ---- POST /aura/v1/door/reject ---- */

	public function test_reject_answers_per_ref_for_a_held_a_claimed_twin_and_an_unknown_ref(): void {
		$held    = Aura_Worker_Door_Holds::hold( $this->call() );
		$claimed = Aura_Worker_Door_Holds::hold( $this->call() );
		Aura_Worker_Door_Holds::claim( $claimed );
		$unknown = 'door_' . str_repeat( '0', 36 );

		$res = $this->api->reject_door_holds( $this->request( array( 'refs' => array( $held, $claimed, $unknown ) ) ) );

		$this->assertInstanceOf( WP_REST_Response::class, $res );
		$this->assertSame( 200, $res->status );
		$this->assertSame(
			array(
				$held    => 'rejected',
				$claimed => 'already_claimed',
				$unknown => 'not_held',
			),
			$res->data['results']
		);
	}

	public function test_reject_drops_non_string_refs_and_caps_the_list_at_50(): void {
		$refs = array( 123, array( 'nested' ), null, true, 1.5 );
		for ( $i = 0; $i < 60; $i++ ) {
			$refs[] = 'door_pad_' . $i;
		}

		$res = $this->api->reject_door_holds( $this->request( array( 'refs' => $refs ) ) );

		$this->assertCount( 50, $res->data['results'], 'non-strings must be dropped and the survivors capped at 50' );
		foreach ( $res->data['results'] as $ref => $verdict ) {
			$this->assertStringStartsWith( 'door_pad_', $ref );
			$this->assertSame( 'not_held', $verdict, 'none of these padding refs were ever held' );
		}
	}

	public function test_reject_requires_a_grant_once_one_is_enforced(): void {
		$this->enforce_grants();

		$res = $this->api->reject_door_holds( $this->request( array( 'refs' => array( 'door_x' ) ) ) );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_grant_required', $res->get_error_code() );
		$this->assertSame( 403, $res->get_error_data()['status'] );
	}

	public function test_reject_and_ack_refuse_without_a_valid_token(): void {
		// Same permission_callback both routes register — check_admin_permission.
		$security = new Aura_Worker_Security();
		$req      = new WP_REST_Request(); // no X-Aura-Token header at all
		$res      = $security->check_admin_permission( $req );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_invalid_token', $res->get_error_code() );
		$this->assertSame( 401, $res->get_error_data()['status'] );
	}

	/* ---- POST /aura/v1/door/ack ---- */

	public function test_ack_for_a_foreign_epoch_acks_nothing(): void {
		Aura_Worker_Door_Log::open_pending( array( 'ability' => 'x', 'actor' => array(), 'touches' => array(), 'verdict' => 'allow' ) );
		$real_epoch = Aura_Worker_Door_Log::epoch();

		$res = $this->api->ack_door_log( $this->request( array( 'epoch' => 'not-the-real-epoch', 'seq' => 1 ) ) );

		$this->assertInstanceOf( WP_REST_Response::class, $res );
		$this->assertSame( 200, $res->status );
		$this->assertSame( 0, $res->data['acked'] );
		$this->assertSame( 0, $res->data['floor'] );
		$this->assertSame( $real_epoch, $res->data['epoch'], 'epoch relayed is the SITE\'s current one, not the caller\'s' );
		$this->assertSame( 1, $res->data['unacked'] );
		$this->assertSame( 'open', $res->data['door'] );
	}

	public function test_a_valid_ack_raises_the_floor_and_deletes_the_acked_rows(): void {
		Aura_Worker_Door_Log::open_pending( array( 'ability' => 'x', 'actor' => array(), 'touches' => array(), 'verdict' => 'allow' ) ); // seq 1
		Aura_Worker_Door_Log::open_pending( array( 'ability' => 'x', 'actor' => array(), 'touches' => array(), 'verdict' => 'allow' ) ); // seq 2
		$epoch = Aura_Worker_Door_Log::epoch();

		$res = $this->api->ack_door_log( $this->request( array( 'epoch' => $epoch, 'seq' => 1 ) ) );

		$this->assertSame( 1, $res->data['acked'] );
		$this->assertSame( 1, $res->data['floor'] );
		$this->assertSame( $epoch, $res->data['epoch'] );
		$this->assertSame( 1, $res->data['unacked'], 'seq 2 is still unacked' );
		$this->assertSame( 'open', $res->data['door'] );
		$this->assertFalse( get_option( 'aura_worker_door_log_1' ), 'the acked row is gone' );
		$this->assertIsArray( get_option( 'aura_worker_door_log_2' ), 'the still-unacked row survives' );
	}

	/**
	 * Ruling S15 (Codex round-6 P2 on #88): `Aura_Worker_Door_Log::ack()`
	 * answers `committed: false` when a real floor raise's own version bump
	 * fails and the whole unit rolls back — nothing acked, nothing purged.
	 * The route treats that exactly like `restore_unsettled()` treats an
	 * unsettled restore: RETRYABLE (503 aura_log_failed), never a 200 that
	 * claims the ack happened.
	 */
	public function test_a_failed_bump_inside_ack_answers_503_aura_log_failed(): void {
		Aura_Worker_Door_Log::open_pending( array( 'ability' => 'x', 'actor' => array(), 'touches' => array(), 'verdict' => 'allow' ) );
		$epoch = Aura_Worker_Door_Log::epoch();

		$GLOBALS['_sa_option_write_fail'][ Aura_Worker_Door_Log::OBSERVATION ] = true;
		$res                                                                  = $this->api->ack_door_log( $this->request( array( 'epoch' => $epoch, 'seq' => 1 ) ) );
		$GLOBALS['_sa_option_write_fail']                                     = array();

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_log_failed', $res->get_error_code() );
		$data = $res->get_error_data();
		$this->assertSame( 503, $data['status'] );
		$this->assertIsArray( get_option( 'aura_worker_door_log_1' ), 'the row this ack would have purged is still there' );
	}

	public function test_a_stale_ack_at_or_below_the_current_floor_is_a_no_op(): void {
		Aura_Worker_Door_Log::open_pending( array( 'ability' => 'x', 'actor' => array(), 'touches' => array(), 'verdict' => 'allow' ) );
		$epoch = Aura_Worker_Door_Log::epoch();
		$first = $this->api->ack_door_log( $this->request( array( 'epoch' => $epoch, 'seq' => 1 ) ) );
		$this->assertSame( 1, $first->data['acked'] );

		$second = $this->api->ack_door_log( $this->request( array( 'epoch' => $epoch, 'seq' => 1 ) ) );

		$this->assertSame( 0, $second->data['acked'], 'a re-ack of an already-acked seq deletes nothing new' );
		$this->assertSame( 1, $second->data['floor'], 'the floor does not move backwards or re-rise' );
	}

	public function test_ack_requires_a_grant_once_one_is_enforced(): void {
		$this->enforce_grants();

		$res = $this->api->ack_door_log( $this->request( array( 'epoch' => 'e', 'seq' => 1 ) ) );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_grant_required', $res->get_error_code() );
		$this->assertSame( 403, $res->get_error_data()['status'] );
	}

	/* ---- POST /aura/v1/door/rotate ---- */

	/**
	 * Rotation is a WRITE on the durable log identity, so it is Aura's
	 * grant-gated decision — never a side effect of a `/status` read
	 * (Ruling P20).
	 */
	public function test_rotate_requires_a_grant_once_one_is_enforced(): void {
		$this->enforce_grants();
		$before = Aura_Worker_Door_Log::epoch();

		$res = $this->api->rotate_door_epoch( $this->request( array( 'epoch' => $before ) ) );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_grant_required', $res->get_error_code() );
		$this->assertSame( 403, $res->get_error_data()['status'] );
		$this->assertSame( $before, get_option( Aura_Worker_Door_Log::EPOCH ), 'nothing rotated' );
	}

	/**
	 * Ruling P91 (F2): a legitimate rotation re-stamps the binding record's
	 * epoch witness, so the next same-identity connect is still a no-op.
	 *
	 * The record names the epoch it was written with, and P81's repair reads a
	 * disagreement as a half-done rebind — so `/door/rotate` used to cost the
	 * site its whole queue on the very next connect: a new generation for an
	 * identity that never changed, every hold queued since the rotation gone
	 * foreign, in-flight writes failing their fence.
	 */
	public function test_a_rotation_restamps_the_witness_so_the_next_connect_rebinds_nothing(): void {
		$this->enforce_grants();
		$fence = Aura_Worker_Magic_Link::claim_site();
		$this->assertTrue( Aura_Worker_Door_Log::rotate_binding( array( 'client' => 'c1', 'dashboard' => 'https://dash.example' ), Aura_Worker_Magic_Link::SITE_CLAIM, $fence ) );
		$gen    = Aura_Worker_Door_Log::binding_raw();
		$before = Aura_Worker_Door_Log::epoch();
		$req    = $this->request( array( 'epoch' => $before ) );
		$req->set_header( 'X-Aura-Approval-Grant', $this->mint( 'door.rotate', array( 'epoch' => $before ) ) );

		$res = $this->api->rotate_door_epoch( $req );

		$this->assertTrue( $res->data['rotated'] );
		$this->assertArrayNotHasKey( 'witness_stale', $res->data );
		$this->assertSame( Aura_Worker_Door_Log::epoch_raw(), Aura_Worker_Door_Log::binding_record()['epoch'], 'the record names the new cursor' );

		// …and the same client connecting again rebinds NOTHING.
		$this->assertTrue( Aura_Worker_Door_Log::rotate_binding( array( 'client' => 'c1', 'dashboard' => 'https://dash.example' ), Aura_Worker_Magic_Link::SITE_CLAIM, $fence ) );

		$this->assertSame( $gen, Aura_Worker_Door_Log::binding_raw(), 'the generation never moved' );
		$this->assertSame( Aura_Worker_Door_Log::epoch_raw(), $res->data['epoch'], 'nor did the cursor' );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	/**
	 * Ruling S62 (Codex round-23 P2 on #88): an AMBIGUOUSLY committed
	 * rotation that actually landed used to answer `rotated: false`, so
	 * this route never called restamp_binding_epoch() at all -- the
	 * binding record kept naming the OLD epoch, and the next
	 * same-identity connect (Ruling P91) read that disagreement as a
	 * half-done rebind. rotate_epoch() now completes the rotation
	 * idempotently on an unknown commit, so this route's own
	 * `! empty( $out['rotated'] )` check still fires and the restamp
	 * still runs.
	 */
	public function test_an_ambiguous_rotation_that_landed_still_restamps_the_binding_witness(): void {
		$this->enforce_grants();
		$fence = Aura_Worker_Magic_Link::claim_site();
		$this->assertTrue( Aura_Worker_Door_Log::rotate_binding( array( 'client' => 'c1', 'dashboard' => 'https://dash.example' ), Aura_Worker_Magic_Link::SITE_CLAIM, $fence ) );
		$gen    = Aura_Worker_Door_Log::binding_raw();
		$before = Aura_Worker_Door_Log::epoch();
		$req    = $this->request( array( 'epoch' => $before ) );
		$req->set_header( 'X-Aura-Approval-Grant', $this->mint( 'door.rotate', array( 'epoch' => $before ) ) );

		$GLOBALS['_sa_uuid_fixed']              = 'nonce-s62-rest';
		$GLOBALS['_sa_reconnect_after_commit']  = true;
		$witness                                = Aura_Worker_Door_Log::LAST_TX_PREFIX . 'nonce-s62-rest';
		$GLOBALS['_sa_option_read_fail'][ $witness ] = true;

		$res = $this->api->rotate_door_epoch( $req );

		$GLOBALS['_sa_reconnect_after_commit'] = false;
		unset( $GLOBALS['_sa_uuid_fixed'] );
		$GLOBALS['_sa_option_read_fail'] = array();

		$this->assertTrue( $res->data['rotated'], 'the ambiguous commit actually landed' );
		$this->assertSame( 'nonce-s62-rest', $res->data['epoch'] );
		$this->assertArrayNotHasKey( 'witness_stale', $res->data, 'restamp_binding_epoch() ran and landed' );
		$this->assertSame( 'nonce-s62-rest', Aura_Worker_Door_Log::binding_record()['epoch'], 'the binding record names the new cursor, not the old one' );
		$this->assertSame( $gen, Aura_Worker_Door_Log::binding_raw(), 'the generation itself never moved -- only the epoch witness' );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	/**
	 * Ruling S77 (Codex round-31 P2 on #88): an AMBIGUOUSLY committed
	 * rotation whose OWN verifying re-read (`epoch_raw()`) ALSO could not
	 * be proven used to fall straight through to `rotated: false` —
	 * indistinguishable from a proven miss. This route's own
	 * `! empty( $out['rotated'] )` check then never ran
	 * `restamp_binding_epoch()`, and a caller retrying with the SAME
	 * (now-stale) `$epoch` would lose the fence against whatever this
	 * call's own mint actually landed as and ALSO answer `false` —
	 * forever, since nothing here ever revisits it. `rotate_epoch()` now
	 * answers `rotated: null` for this specific double-unproven case, and
	 * this route turns that into a retryable 503 (`may_have_run`) rather
	 * than a false `200 rotated: false`. See
	 * `test_an_ambiguous_rotation_that_landed_still_restamps_the_binding_witness()`
	 * just above for the OTHER half of the SAME mechanism — the identical
	 * ambiguous commit, but with a HEALTHY verify that finds `current ==`
	 * this call's own pre-minted target — which Ruling S77 leaves
	 * completely unchanged: it still completes and restamps.
	 */
	public function test_an_ambiguous_rotation_whose_verify_also_fails_answers_a_retryable_503(): void {
		$this->enforce_grants();
		$fence = Aura_Worker_Magic_Link::claim_site();
		$this->assertTrue( Aura_Worker_Door_Log::rotate_binding( array( 'client' => 'c1', 'dashboard' => 'https://dash.example' ), Aura_Worker_Magic_Link::SITE_CLAIM, $fence ) );
		$gen    = Aura_Worker_Door_Log::binding_raw();
		$before = Aura_Worker_Door_Log::epoch();
		$req    = $this->request( array( 'epoch' => $before ) );
		$req->set_header( 'X-Aura-Approval-Grant', $this->mint( 'door.rotate', array( 'epoch' => $before ) ) );

		$GLOBALS['_sa_uuid_fixed']             = 'nonce-s77-rest';
		$GLOBALS['_sa_reconnect_after_commit'] = true;
		$witness                               = Aura_Worker_Door_Log::LAST_TX_PREFIX . 'nonce-s77-rest';
		$GLOBALS['_sa_option_read_fail'][ $witness ]                = true;
		// AND the verifying epoch_raw() re-read also fails.
		$GLOBALS['_sa_option_read_fail'][ Aura_Worker_Door_Log::EPOCH ] = true;

		$res = $this->api->rotate_door_epoch( $req );

		$GLOBALS['_sa_reconnect_after_commit'] = false;
		unset( $GLOBALS['_sa_uuid_fixed'] );
		$GLOBALS['_sa_option_read_fail'] = array();

		$this->assertInstanceOf( WP_Error::class, $res, 'genuinely unknown — never a false "rotated: false" 200' );
		$this->assertSame( 503, $res->get_error_data()['status'] );
		$this->assertTrue( $res->get_error_data()['may_have_run'], 'a caller must retry, not assume nothing happened' );

		// The rotation actually DID land — confirmed with a healthy read
		// now that the seams are cleared, the SAME way the docblock's own
		// sibling test proves it for the healthy-verify case.
		$this->assertSame( 'nonce-s77-rest', get_option( Aura_Worker_Door_Log::EPOCH ) );
		$this->assertSame( $gen, Aura_Worker_Door_Log::binding_raw(), 'the generation itself never moved' );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	/**
	 * Ruling P92 (F1): a rotation superseded mid-flight never stamps its stale
	 * epoch over the winner's witness.
	 *
	 * Fencing on the record's bytes alone was not enough: this rotation can
	 * pause after minting B while a concurrent rotation or rebind installs C
	 * and stamps the record with it, and the resumed call would overwrite C's
	 * witness with B — manufacturing exactly the disagreement the witness
	 * exists to prevent, and costing the site its queue on the next connect.
	 */
	public function test_a_superseded_rotation_never_stamps_its_stale_epoch(): void {
		$this->enforce_grants();
		$fence = Aura_Worker_Magic_Link::claim_site();
		Aura_Worker_Door_Log::rotate_binding( array( 'client' => 'c1', 'dashboard' => 'https://dash.example' ), Aura_Worker_Magic_Link::SITE_CLAIM, $fence );
		Aura_Worker_Magic_Link::release_site( $fence );
		$before = Aura_Worker_Door_Log::epoch();
		$req    = $this->request( array( 'epoch' => $before ) );
		$req->set_header( 'X-Aura-Approval-Grant', $this->mint( 'door.rotate', array( 'epoch' => $before ) ) );
		$res = $this->api->rotate_door_epoch( $req );
		$b   = (string) $res->data['epoch'];
		$this->assertTrue( $res->data['rotated'] );

		// …and now C lands, with its own witness, before anything else reads.
		$GLOBALS['_options'][ Aura_Worker_Door_Log::EPOCH ] = 'epoch-c';
		$GLOBALS['_rows'][ Aura_Worker_Door_Log::EPOCH ]    = 'epoch-c';
		$rec          = Aura_Worker_Door_Log::binding_record();
		$rec['epoch'] = 'epoch-c';
		$GLOBALS['_options'][ Aura_Worker_Door_Log::BINDING ] = $rec;
		$GLOBALS['_rows'][ Aura_Worker_Door_Log::BINDING ]    = maybe_serialize( $rec );

		// The paused rotation resumes and tries to stamp B.
		$this->assertTrue( Aura_Worker_Door_Log::restamp_binding_epoch( $b ), 'a later rotation owns the witness: nothing owed' );

		$this->assertSame( 'epoch-c', Aura_Worker_Door_Log::binding_record()['epoch'], "the winner's witness stands" );
	}

	/** A witness that will not land is REPORTED, never a refusal. */
	public function test_a_witness_restamp_that_cannot_land_is_reported(): void {
		$this->enforce_grants();
		$fence = Aura_Worker_Magic_Link::claim_site();
		Aura_Worker_Door_Log::rotate_binding( array( 'client' => 'c1', 'dashboard' => 'https://dash.example' ), Aura_Worker_Magic_Link::SITE_CLAIM, $fence );
		Aura_Worker_Magic_Link::release_site( $fence );
		$before = Aura_Worker_Door_Log::epoch();
		$req    = $this->request( array( 'epoch' => $before ) );
		$req->set_header( 'X-Aura-Approval-Grant', $this->mint( 'door.rotate', array( 'epoch' => $before ) ) );
		$GLOBALS['_sa_option_cas_fail'][ Aura_Worker_Door_Log::BINDING ] = true;

		$res = $this->api->rotate_door_epoch( $req );

		$GLOBALS['_sa_option_cas_fail'] = array();
		$this->assertTrue( $res->data['rotated'], 'the rotation itself succeeded' );
		$this->assertTrue( $res->data['witness_stale'], 'and Aura is told the record did not catch up' );
	}

	public function test_a_grant_over_the_current_epoch_rotates_and_keeps_the_floor_and_the_rows(): void {
		$this->enforce_grants();
		for ( $i = 1; $i <= 3; $i++ ) {
			$seq = Aura_Worker_Door_Log::open_pending( array( 'ability' => 'x', 'actor' => array(), 'touches' => array(), 'verdict' => 'allow' ) );
			Aura_Worker_Door_Log::settle( $seq, array( 'result' => 'ok' ) );
		}
		$before = Aura_Worker_Door_Log::epoch();
		Aura_Worker_Door_Log::ack( $before, 2 );  // floor 2, rows 1-2 dropped
		Aura_Worker_Door_Log::close();
		Aura_Worker_Door_Log::bump_refused();
		$req = $this->request( array( 'epoch' => $before ) );
		$req->set_header( 'X-Aura-Approval-Grant', $this->mint( 'door.rotate', array( 'epoch' => $before ) ) );

		$res = $this->api->rotate_door_epoch( $req );

		$this->assertInstanceOf( WP_REST_Response::class, $res );
		$this->assertSame( 200, $res->status );
		$this->assertTrue( $res->data['rotated'] );
		$this->assertNotSame( $before, $res->data['epoch'] );
		$this->assertSame( Aura_Worker_Door_Log::epoch(), $res->data['epoch'], 'the epoch answered is the one now in force' );
		$this->assertSame( 2, $res->data['floor'], 'the ack floor is retained (Ruling P2\')' );
		$this->assertFalse( get_option( Aura_Worker_Door_Log::FULL_MARKER, false ), 'the closure state is cleared' );
		$this->assertFalse( get_option( Aura_Worker_Door_Log::FULL_COUNTER, false ) );
		$this->assertIsArray( get_option( 'aura_worker_door_log_3' ), 'the rows themselves are kept' );
	}

	public function test_a_stale_epoch_answers_the_current_one_without_rotating(): void {
		$this->enforce_grants();
		$before = Aura_Worker_Door_Log::epoch();
		$req    = $this->request( array( 'epoch' => 'an-epoch-this-site-has-moved-past' ) );
		$req->set_header( 'X-Aura-Approval-Grant', $this->mint( 'door.rotate', array( 'epoch' => 'an-epoch-this-site-has-moved-past' ) ) );

		$res = $this->api->rotate_door_epoch( $req );

		$this->assertSame( 200, $res->status );
		$this->assertFalse( $res->data['rotated'], 'a retry of a rotation that already happened rotates nothing' );
		$this->assertSame( $before, $res->data['epoch'] );
		$this->assertSame( $before, get_option( Aura_Worker_Door_Log::EPOCH ) );
	}

	/* ---- MCP tool: snapshot_get ---- */

	private function fixture_page( int $id ): void {
		$GLOBALS['_posts'][ $id ] = (object) array(
			'ID'             => $id,
			'post_title'     => 'A page',
			'post_name'      => 'a-page',
			'post_content'   => '<p>hi</p>',
			'post_excerpt'   => '',
			'post_status'    => 'publish',
			'post_type'      => 'page',
			'post_parent'    => 0,
			'menu_order'     => 0,
			'post_author'    => 1,
			'post_date'      => '2026-01-01 00:00:00',
			'post_date_gmt'  => '2026-01-01 00:00:00',
			'comment_status' => 'open',
			'ping_status'    => 'open',
		);
	}

	public function test_snapshot_get_returns_a_page_envelope_with_its_payload_base64(): void {
		$this->fixture_page( 7 );
		$snapshots = new Aura_Worker_Snapshots();
		$captured  = $snapshots->snapshot_posts( array( 7 ), array(), array( 'kind_label' => 'page' ) );
		$this->assertTrue( $captured['success'] );
		$id = $captured['snapshot']['id'];

		$tool = new Aura_Tool_Snapshot_Get();
		$out  = $tool->execute( array( 'id' => $id ) );

		$this->assertTrue( $out['found'] );
		$this->assertIsArray( $out['record'] );
		$this->assertSame( $id, $out['record']['id'] );
		$this->assertSame( 'page', $out['record']['door_kind'] );
		$this->assertArrayNotHasKey( 'payload_path', $out['record'], 'the on-disk path must never leak' );
		$this->assertIsString( $out['payload'] );
		$this->assertArrayNotHasKey( 'truncated', $out, 'a small payload is never reported truncated' );

		$decoded = base64_decode( $out['payload'], true );
		$this->assertNotFalse( $decoded, 'payload must be valid base64' );
		$unserialized = unserialize( $decoded, array( 'allowed_classes' => false ) );
		$this->assertIsArray( $unserialized );
		$this->assertTrue( $unserialized[7]['existed'] );
		$this->assertSame( 'A page', $unserialized[7]['fields']['post_title'] );
	}

	public function test_snapshot_get_withholds_the_payload_of_an_envelope_that_is_not_a_door_capture(): void {
		// A read-scoped session can snapshot ANY option through
		// POST /aura/v2/snapshot — option names are not jailed there — and
		// would then read the stored value straight back out of this tool.
		// snapshot_get exists to hand back what the DOOR captured, so an
		// envelope whose door_kind is not one of DOOR_KINDS comes back as
		// metadata only.
		update_option( 'aura_worker_secret_thing', 'the-bytes-nobody-asked-for' );
		$snapshots = new Aura_Worker_Snapshots();
		$captured  = $snapshots->snapshot_option( 'aura_worker_secret_thing' );
		$this->assertTrue( $captured['success'] );

		$tool = new Aura_Tool_Snapshot_Get();
		$out  = $tool->execute( array( 'id' => $captured['snapshot']['id'] ) );

		$this->assertTrue( $out['found'] );
		$this->assertSame( 'option', $out['record']['kind'], 'the record itself is still described' );
		$this->assertNull( $out['payload'] );
		$this->assertTrue( $out['withheld'] );
		$this->assertArrayNotHasKey( 'truncated', $out, 'withheld is not truncated: nothing about the size was the reason' );
		$this->assertArrayHasKey( 'withheld', $tool->get_returns(), 'and the shape declares it' );
	}

	/* ---- multisite: an envelope belongs to the blog that took it ---- */

	/** Capture a page envelope AS blog $blog, then come back to blog 1. */
	private function envelope_from_blog( int $blog, int $post_id = 7 ): array {
		$this->fixture_page( $post_id );
		$GLOBALS['_current_blog_id'] = $blog;
		$captured                    = ( new Aura_Worker_Snapshots() )->snapshot_posts( array( $post_id ), array(), array( 'kind_label' => 'page' ) );
		$GLOBALS['_current_blog_id'] = 1;
		$this->assertTrue( $captured['success'] );
		return $captured['snapshot'];
	}

	/** Rewrite an envelope's stored json — for forging a legacy record. */
	private function rewrite_envelope( string $id, callable $fn ): void {
		$path = WP_CONTENT_DIR . '/aura-backups/snapshots/' . $id . '.json';
		$rec  = json_decode( file_get_contents( $path ), true );
		file_put_contents( $path, wp_json_encode( $fn( $rec ) ) );
	}

	public function test_an_envelope_records_the_blog_that_took_it(): void {
		$env = $this->envelope_from_blog( 2 );
		$this->assertSame( 2, $env['blog_id'] );
	}

	/**
	 * Every blog on a multisite shares one snapshots directory, and the ids
	 * are listed. Without this, one subsite's credentials read another
	 * subsite's captured Elementor content (Ruling P15).
	 */
	public function test_snapshot_get_withholds_the_payload_of_another_blogs_envelope(): void {
		$env = $this->envelope_from_blog( 2 );

		$out = ( new Aura_Tool_Snapshot_Get() )->execute( array( 'id' => $env['id'] ) );

		$this->assertTrue( $out['found'] );
		$this->assertSame( 'page', $out['record']['door_kind'], 'the record itself is still described' );
		$this->assertNull( $out['payload'] );
		$this->assertTrue( $out['withheld'] );
		$this->assertTrue( $out['foreign_blog'] );
		$this->assertArrayHasKey( 'foreign_blog', ( new Aura_Tool_Snapshot_Get() )->get_returns(), 'and the shape declares it' );
	}

	public function test_snapshot_get_serves_this_blogs_own_envelope(): void {
		$env = $this->envelope_from_blog( 1 );

		$out = ( new Aura_Tool_Snapshot_Get() )->execute( array( 'id' => $env['id'] ) );

		$this->assertIsString( $out['payload'] );
		$this->assertArrayNotHasKey( 'foreign_blog', $out );
		$this->assertArrayNotHasKey( 'withheld', $out );
	}

	/**
	 * A legacy envelope predates the stamp. On a single site it can only be
	 * this site's, and is served as before; on a multisite it cannot be
	 * placed, and unplaceable is not the same as ours.
	 */
	public function test_a_legacy_envelope_serves_on_a_single_site_and_is_withheld_on_a_multisite(): void {
		$env = $this->envelope_from_blog( 1 );
		$this->rewrite_envelope(
			$env['id'],
			static function ( array $rec ): array {
				unset( $rec['blog_id'] );
				return $rec;
			}
		);

		$single = ( new Aura_Tool_Snapshot_Get() )->execute( array( 'id' => $env['id'] ) );
		$this->assertIsString( $single['payload'], 'on a single site there is only one blog it can be' );

		$GLOBALS['_is_multisite'] = true;
		$multi                    = ( new Aura_Tool_Snapshot_Get() )->execute( array( 'id' => $env['id'] ) );
		$this->assertNull( $multi['payload'] );
		$this->assertTrue( $multi['withheld'] );
		$this->assertTrue( $multi['foreign_blog'] );
	}

	public function test_snapshot_get_answers_found_false_for_an_unknown_id(): void {
		$tool = new Aura_Tool_Snapshot_Get();
		$out  = $tool->execute( array( 'id' => 'snap_does_not_exist_at_all' ) );

		$this->assertSame(
			array(
				'found'   => false,
				'record'  => null,
				'payload' => null,
			),
			$out
		);
	}

	public function test_snapshot_get_is_registered_read_only_with_no_approval_required(): void {
		$tool = new Aura_Tool_Snapshot_Get();
		$this->assertSame(
			array(
				'read_only'         => true,
				'destructive'       => false,
				'requires_approval' => false,
				'supports_preview'  => false,
			),
			$tool->get_annotations()
		);
	}
}
