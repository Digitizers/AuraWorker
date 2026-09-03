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

	/** Provisions a real gateway pubkey, turning on grant enforcement. */
	private function enforce_grants(): void {
		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
			$this->markTestSkipped( 'ext-sodium is not available.' );
		}
		$kp = sodium_crypto_sign_keypair();
		$GLOBALS['_options']['aura_worker_grant_pubkey'] = base64_encode( sodium_crypto_sign_publickey( $kp ) );
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
