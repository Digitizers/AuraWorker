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
		// Sign with the pubkey the gateway intends (or omit it from the message).
		$sig_pubkey = array_key_exists( 'sign_pubkey', $over ) ? $over['sign_pubkey'] : $pubkey;
		$signature  = Aura_Worker_Magic_Link::sign_connect_payload( $this->secret, $this->magic_id, $token, $dashboard_url, $timestamp, (string) $sig_pubkey );

		$req = new WP_REST_Request();
		$req->set_param( 'magic_id', $this->magic_id );
		$req->set_param( 'token', $token );
		$req->set_param( 'dashboard_url', $dashboard_url );
		$req->set_param( 'timestamp', $timestamp );
		$req->set_param( 'signature', $over['signature'] ?? $signature );
		if ( null !== $pubkey ) {
			$req->set_param( 'grant_pubkey', $pubkey );
		}
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
}
