<?php
/**
 * The ruleset is stored only after the same Ed25519 key that signs grants has
 * vouched for it, and only if it is newer than what we hold. Real signatures,
 * as in GrantTest.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class RulesetStoreTest extends TestCase {

	private $secret;

	protected function setUp(): void {
		sa_reset_state();
		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
			$this->markTestSkipped( 'ext-sodium is not available.' );
		}
		$kp           = sodium_crypto_sign_keypair();
		$this->secret = sodium_crypto_sign_secretkey( $kp );
		$GLOBALS['_options']['aura_worker_grant_pubkey'] = base64_encode( sodium_crypto_sign_publickey( $kp ) );
	}

	private function b64url( string $s ): string {
		return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
	}

	/** Sign an arbitrary array the way Aura signs a ruleset. */
	private function sign( array $payload, ?string $secret = null ): string {
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$sig  = sodium_crypto_sign_detached( $json, $secret ?? $this->secret );
		return $this->b64url( $json ) . '.' . $this->b64url( $sig );
	}

	public function test_a_validly_signed_document_decodes(): void {
		$doc = Aura_Worker_Grant::verify_signed_document( $this->sign( array( 'v' => 1, 'seq' => 3 ) ) );
		$this->assertIsArray( $doc );
		$this->assertSame( 3, $doc['seq'] );
	}

	public function test_a_document_signed_by_another_key_is_refused(): void {
		$other = sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() );
		$res   = Aura_Worker_Grant::verify_signed_document( $this->sign( array( 'v' => 1, 'seq' => 3 ), $other ) );
		$this->assertIsString( $res );
	}

	public function test_a_malformed_envelope_is_refused(): void {
		$this->assertIsString( Aura_Worker_Grant::verify_signed_document( 'no-dot-here' ) );
		$this->assertIsString( Aura_Worker_Grant::verify_signed_document( '' ) );
	}

	public function test_without_a_provisioned_key_nothing_verifies(): void {
		unset( $GLOBALS['_options']['aura_worker_grant_pubkey'] );
		$this->assertIsString( Aura_Worker_Grant::verify_signed_document( $this->sign( array( 'v' => 1 ) ) ) );
	}

	public function test_a_truncated_key_is_not_a_usable_key(): void {
		// is_enforced() means "the option is non-empty" — deliberately, for
		// grants. A half-written or corrupt key is enforced-but-unusable: it
		// verifies nothing, so every caller asking "can this site verify a
		// ruleset at all?" must ask has_usable_key(), or a provisioning
		// accident gets reported as a stream of bad documents forever.
		$this->assertTrue( Aura_Worker_Grant::has_usable_key() );

		$GLOBALS['_options']['aura_worker_grant_pubkey'] = base64_encode( str_repeat( 'k', 16 ) ); // 16 bytes, not 32
		$this->assertTrue( Aura_Worker_Grant::is_enforced(), 'precondition: a truncated key still counts as enforced' );
		$this->assertFalse( Aura_Worker_Grant::has_usable_key() );
		$this->assertIsString( Aura_Worker_Grant::verify_signed_document( $this->sign( array( 'v' => 1 ) ) ) );

		$GLOBALS['_options']['aura_worker_grant_pubkey'] = 'not base64 at all!!';
		$this->assertFalse( Aura_Worker_Grant::has_usable_key() );

		unset( $GLOBALS['_options']['aura_worker_grant_pubkey'] );
		$this->assertFalse( Aura_Worker_Grant::has_usable_key() );
	}
}
