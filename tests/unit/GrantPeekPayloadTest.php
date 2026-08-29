<?php
/**
 * Aura_Worker_Grant::peek_payload() — the UNVERIFIED payload read the unbind
 * marker's fast path relies on (#434, spec §2.3 step 0). It is the only thing
 * standing between a document Aura sent and the two values the fast path takes
 * from it: the `seq` it echoes back, and `final`, which from Task 4 gates the
 * irreversible deletion of the site token. Everything it cannot read must
 * degrade to "no payload" — an empty array — never to a half-read one.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class GrantPeekPayloadTest extends TestCase {

	protected function setUp(): void {
		sa_reset_state();
		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
			$this->markTestSkipped( 'ext-sodium is not available.' );
		}
		sa_install_gateway_key();
	}

	public function test_a_valid_envelope_yields_its_payload_verbatim(): void {
		$payload = array( 'v' => 1, 'client' => 'c1', 'site' => sa_token_hash(), 'site_ref' => 'r1', 'seq' => 9, 'issued_at' => 'x', 'rules' => array(), 'unbind' => true, 'final' => true );
		$this->assertSame( $payload, Aura_Worker_Grant::peek_payload( sa_sign_ruleset( $payload ) ) );
	}

	/**
	 * It reads the payload segment, not a signature — so it never depends on a
	 * gateway key being present. That is the whole reason the fast path can use
	 * it after Phase B has deleted the key.
	 */
	public function test_it_reads_a_payload_with_no_gateway_key_installed(): void {
		$env = sa_sign_ruleset( array( 'seq' => 4, 'final' => false ) );
		delete_option( 'aura_worker_grant_pubkey' );
		$this->assertSame( array( 'seq' => 4, 'final' => false ), Aura_Worker_Grant::peek_payload( $env ) );
	}

	/**
	 * Task 8's unkeyed form has no signature to append; peek reads the bare
	 * payload segment on its own just as well.
	 */
	public function test_a_bare_payload_segment_with_no_signature_still_reads(): void {
		$json = wp_json_encode( array( 'unbind' => true, 'seq' => 3 ) );
		$this->assertSame( array( 'unbind' => true, 'seq' => 3 ), Aura_Worker_Grant::peek_payload( sa_b64url( $json ) ) );
	}

	/**
	 * @dataProvider not_a_payload
	 *
	 * @param string $envelope Anything that is not a document.
	 * @param string $why      What it models.
	 */
	public function test_anything_that_is_not_an_object_payload_reads_as_empty( string $envelope, string $why ): void {
		$this->assertSame( array(), Aura_Worker_Grant::peek_payload( $envelope ), $why );
	}

	public static function not_a_payload(): array {
		return array(
			'empty string'             => array( '', 'no segments at all' ),
			'a lone separator'         => array( '.sig', 'an empty payload segment' ),
			'not base64 at all'        => array( 'garbage-not-an-envelope', 'the string the fast-path garbage test feeds accept()' ),
			'valid b64, not JSON'      => array( sa_b64url( 'not json at all' ) . '.' . sa_b64url( 'sig' ), 'decodes to bytes that are not a document' ),
			'JSON scalar payload'      => array( sa_b64url( '12' ) . '.' . sa_b64url( 'sig' ), 'a number is not an object' ),
			'JSON string payload'      => array( sa_b64url( '"final"' ) . '.' . sa_b64url( 'sig' ), 'a string is not an object' ),
			'JSON true payload'        => array( sa_b64url( 'true' ) . '.' . sa_b64url( 'sig' ), 'a bool is not an object' ),
			'JSON null payload'        => array( sa_b64url( 'null' ) . '.' . sa_b64url( 'sig' ), 'null is not an object' ),
		);
	}

	/**
	 * A payload it cannot read carries no `final`, and the fast path reads a
	 * missing `final` as false — the safe direction, since `final` only ever
	 * WIDENS Phase B (it is what permits deleting the site token).
	 */
	public function test_an_unreadable_payload_carries_no_final_flag(): void {
		$this->assertArrayNotHasKey( 'final', Aura_Worker_Grant::peek_payload( 'garbage-not-an-envelope' ) );
		$this->assertArrayNotHasKey( 'final', Aura_Worker_Grant::peek_payload( sa_sign_ruleset( array( 'seq' => 1 ) ) ) );
	}
}
