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

	/** This site's token hash — the ruleset is bound to it exactly as grants are. */
	private function site(): string {
		if ( empty( $GLOBALS['_options']['aura_worker_site_token'] ) ) {
			$GLOBALS['_options']['aura_worker_site_token'] = hash( 'sha256', 'raw-site-token' );
		}
		return (string) $GLOBALS['_options']['aura_worker_site_token'];
	}

	private function ruleset( int $seq, array $rules = array(), ?string $secret = null, ?string $client = null, ?string $site = null ): string {
		return $this->sign(
			array(
				'v'         => 1,
				'client'    => $client ?? 'client-1',
				'site'      => $site ?? $this->site(),
				'seq'       => $seq,
				'issued_at' => gmdate( 'c', 1_800_000_000 + $seq ),
				'rules'     => $rules,
			),
			$secret
		);
	}

	private function freeze(): array {
		return array(
			'key'    => 'rule/freeze',
			'effect' => 'block',
			'target' => array( 'type' => 'site' ),
			'reason' => 'deploy night',
		);
	}

	public function test_a_first_ruleset_is_stored(): void {
		$this->assertNull( Aura_Worker_Rules::current() );
		$this->assertTrue( Aura_Worker_Rules::accept( $this->ruleset( 1, array( $this->freeze() ) ) ) );
		$cur = Aura_Worker_Rules::current();
		$this->assertSame( 1, $cur['seq'] );
		$this->assertCount( 1, Aura_Worker_Rules::rules() );
		$this->assertArrayHasKey( 'received_at', $cur );
	}

	public function test_a_newer_ruleset_replaces_the_stored_one(): void {
		Aura_Worker_Rules::accept( $this->ruleset( 1, array( $this->freeze() ) ) );
		$this->assertTrue( Aura_Worker_Rules::accept( $this->ruleset( 2, array() ) ) );
		$this->assertSame( 2, Aura_Worker_Rules::current()['seq'] );
		$this->assertSame( array(), Aura_Worker_Rules::rules() );
	}

	public function test_an_older_ruleset_is_refused_even_when_validly_signed(): void {
		// Replaying an old document is how a released rule would come back —
		// or how a newly added one would vanish.
		Aura_Worker_Rules::accept( $this->ruleset( 5, array( $this->freeze() ) ) );
		$res = Aura_Worker_Rules::accept( $this->ruleset( 4, array() ) );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 5, Aura_Worker_Rules::current()['seq'] );
		$this->assertCount( 1, Aura_Worker_Rules::rules() );
	}

	public function test_the_same_seq_with_different_content_is_refused(): void {
		Aura_Worker_Rules::accept( $this->ruleset( 5 ) );
		$this->assertInstanceOf( WP_Error::class, Aura_Worker_Rules::accept( $this->ruleset( 5, array( $this->freeze() ) ) ) );
	}

	public function test_a_racing_older_push_cannot_overwrite_a_newer_one(): void {
		// Two pushes overlap: a retry of seq 6 has already passed the seq check
		// against the stored seq 5 when a fresh seq 7 lands. Without a
		// compare-and-swap the retry writes last and policy rolls backwards —
		// the block the operator added in seq 7 silently disappears. The racer
		// is injected by the $wpdb stub between this call's read and its write.
		Aura_Worker_Rules::accept( $this->ruleset( 5 ) );
		$GLOBALS['_cas_racer'] = $this->ruleset( 7, array( $this->freeze() ) );

		$res = Aura_Worker_Rules::accept( $this->ruleset( 6 ) );

		$this->assertInstanceOf( WP_Error::class, $res, 'the losing racer overwrote a newer ruleset' );
		$this->assertSame( 'aura_ruleset_stale', $res->get_error_code(), 're-deciding after a lost CAS must reach the ordinary stale answer' );
		$this->assertSame( 7, Aura_Worker_Rules::current()['seq'] );
		$this->assertCount( 1, Aura_Worker_Rules::rules(), 'the freeze added by seq 7 was rolled back' );
	}

	public function test_a_racing_newer_push_still_installs_after_a_lost_swap(): void {
		// The mirror image: losing the swap is not a refusal. Seq 9 re-reads,
		// finds the racer's 7, and installs — one retry, not an error.
		Aura_Worker_Rules::accept( $this->ruleset( 5 ) );
		$GLOBALS['_cas_racer'] = $this->ruleset( 7, array( $this->freeze() ) );

		$this->assertTrue( Aura_Worker_Rules::accept( $this->ruleset( 9 ) ) );
		$this->assertSame( 9, Aura_Worker_Rules::current()['seq'] );
	}

	public function test_a_losing_insert_is_classified_from_the_database_not_the_cache(): void {
		// insert_if_absent() issues a real conditional INSERT through $wpdb —
		// not add_option(), which core skips its own existence check for
		// whenever the option is already listed in `notoptions` and would
		// otherwise clobber a winning racer's row via `ON DUPLICATE KEY
		// UPDATE`. When the INSERT reports 0 rows affected (a row is already
		// there), the loser must classify that from an actual re-read of the
		// row — a real SELECT against $wpdb — never from get_option().
		$GLOBALS['_insert_racer'] = $this->ruleset( 8, array( $this->freeze() ) );

		$res = Aura_Worker_Rules::accept( $this->ruleset( 2 ) );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_ruleset_stale', $res->get_error_code(), 'a lost race was reported as a store failure' );
		$this->assertSame( 8, Aura_Worker_Rules::current()['seq'] );
		// The classification came from a query, not a guess or a cache read.
		$reread = array_filter(
			$GLOBALS['_db_queries'],
			static fn( $q ) => false !== strpos( $q, 'SELECT option_value' )
		);
		$this->assertNotEmpty( $reread, 'the lost INSERT must be classified by re-reading the row, not by trusting a cache' );
	}

	public function test_two_first_pushes_racing_do_not_let_the_older_one_win(): void {
		// Nothing is stored yet, so both callers decided against null and both
		// take the INSERT path — a real `INSERT ... WHERE NOT EXISTS`, which
		// the database itself (not a pre-check that a cache can fool) decides
		// between. The loser must re-decide, NOT read the winner's row and
		// swap against it — that would install seq 2 over seq 8 without ever
		// comparing them, which is the rollback one level down.
		$GLOBALS['_insert_racer'] = $this->ruleset( 8, array( $this->freeze() ) );

		$res = Aura_Worker_Rules::accept( $this->ruleset( 2 ) );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_ruleset_stale', $res->get_error_code() );
		$this->assertSame( 8, Aura_Worker_Rules::current()['seq'] );
		$this->assertCount( 1, Aura_Worker_Rules::rules() );
	}

	public function test_a_database_error_on_the_first_insert_is_a_store_error(): void {
		// The first write is a conditional INSERT and never reaches the
		// UPDATE branch, so it needs its own way to tell "a row already
		// exists" (0 affected — a lost race) from "the database refused the
		// statement" (false — a hard error). Reporting contention here would
		// send Aura chasing a race that never happened while the site holds
		// no policy at all.
		$GLOBALS['_db_query_error'] = true;

		$res = Aura_Worker_Rules::accept( $this->ruleset( 1 ) );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_ruleset_store_failed', $res->get_error_code() );
		$this->assertSame( 500, $res->get_error_data()['status'] );
		$this->assertNull( Aura_Worker_Rules::current() );
	}

	/**
	 * @dataProvider corrupt_values
	 */
	public function test_an_unreadable_stored_record_is_replaced_not_retried_forever( $raw ): void {
		// A truncated or hand-edited option is not a racer's record: it has no
		// seq, so there is nothing to compare and nothing to roll back. It is
		// replaced (still by CAS), rather than making every push contend.
		// The serialized scalar is the case that catches a decoded predicate:
		// `i:5;` unserializes to 5, and maybe_serialize( 5 ) is "5", which
		// matches no row — the repair would lose the CAS forever.
		$GLOBALS['_rows'][ Aura_Worker_Rules::OPTION ]    = $raw;
		$GLOBALS['_options'][ Aura_Worker_Rules::OPTION ] = maybe_unserialize( $raw );

		$this->assertTrue( Aura_Worker_Rules::accept( $this->ruleset( 4, array( $this->freeze() ) ) ) );
		$this->assertSame( 4, Aura_Worker_Rules::current()['seq'] );
	}

	public static function corrupt_values(): array {
		return array(
			'a bare string'         => array( 'not-a-record' ),
			'a serialized int'      => array( 'i:5;' ),
			'a serialized bool'     => array( 'b:0;' ),
			'a half-written array'  => array( 'a:2:{s:3:"seq";i:4;' ),
			'an array without rules' => array( maybe_serialize( array( 'seq' => 4 ) ) ),
		);
	}

	public function test_a_database_error_is_reported_not_retried(): void {
		// $wpdb->query() answers false for an SQL error and 0 for "matched
		// nothing". Reading both as "lost the race" would retry a broken
		// database until the stack gives out.
		Aura_Worker_Rules::accept( $this->ruleset( 5 ) );
		$GLOBALS['_db_query_error'] = true;

		$res = Aura_Worker_Rules::accept( $this->ruleset( 6 ) );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_ruleset_store_failed', $res->get_error_code() );
		$this->assertSame( 500, $res->get_error_data()['status'] );
		$this->assertSame( 5, Aura_Worker_Rules::current()['seq'] );
	}

	public function test_endless_contention_ends_in_a_bounded_refusal(): void {
		// A racer that keeps winning must not recurse forever. The stub loses
		// every CAS while leaving the stored record untouched, so each round
		// re-decides against the same seq 5 and the swap fails again — the one
		// arrangement in which the retry could run without end.
		Aura_Worker_Rules::accept( $this->ruleset( 5 ) );
		$GLOBALS['_cas_always_lose'] = true;

		$res = Aura_Worker_Rules::accept( $this->ruleset( 6 ) );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_ruleset_contended', $res->get_error_code() );
		$this->assertSame( 503, $res->get_error_data()['status'] );
		$this->assertSame( 5, Aura_Worker_Rules::current()['seq'] );
	}

	public function test_a_bad_signature_keeps_the_previous_ruleset(): void {
		Aura_Worker_Rules::accept( $this->ruleset( 1, array( $this->freeze() ) ) );
		$other = sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() );
		$this->assertInstanceOf( WP_Error::class, Aura_Worker_Rules::accept( $this->ruleset( 9, array(), $other ) ) );
		$this->assertSame( 1, Aura_Worker_Rules::current()['seq'] );
	}

	public function test_a_document_of_the_wrong_shape_is_refused(): void {
		$this->assertInstanceOf( WP_Error::class, Aura_Worker_Rules::accept( $this->sign( array( 'v' => 2, 'seq' => 1, 'rules' => array() ) ) ) );
		$this->assertInstanceOf( WP_Error::class, Aura_Worker_Rules::accept( $this->sign( array( 'v' => 1, 'seq' => 'one', 'rules' => array() ) ) ) );
		$this->assertInstanceOf( WP_Error::class, Aura_Worker_Rules::accept( $this->sign( array( 'v' => 1, 'seq' => 1, 'rules' => 'nope' ) ) ) );
		$this->assertInstanceOf( WP_Error::class, Aura_Worker_Rules::accept( $this->sign( array( 'v' => 1, 'seq' => -1, 'rules' => array() ) ) ) );
		$this->assertNull( Aura_Worker_Rules::current() );
	}

	public function test_a_ruleset_for_another_client_is_refused(): void {
		// Rebinding goes through connect(), which clears. A document for a
		// different client arriving without that is either a misroute or a
		// replay, and either way the stored rules are not its to replace.
		Aura_Worker_Rules::accept( $this->ruleset( 5, array( $this->freeze() ) ) );
		$res = Aura_Worker_Rules::accept( $this->ruleset( 1, array(), null, 'client-2' ) );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_ruleset_client_mismatch', $res->get_error_code() );
		$this->assertSame( 'client-1', Aura_Worker_Rules::current()['client'] );
	}

	public function test_after_clear_a_lower_seq_from_a_new_client_is_accepted(): void {
		Aura_Worker_Rules::accept( $this->ruleset( 5 ) );
		Aura_Worker_Rules::clear();
		$this->assertTrue( Aura_Worker_Rules::accept( $this->ruleset( 1, array(), null, 'client-2' ) ) );
		$this->assertSame( 'client-2', Aura_Worker_Rules::current()['client'] );
	}

	public function test_a_document_without_a_client_is_refused(): void {
		$this->assertInstanceOf( WP_Error::class, Aura_Worker_Rules::accept( $this->sign( array( 'v' => 1, 'site' => $this->site(), 'seq' => 1, 'rules' => array() ) ) ) );
	}

	public function test_a_document_for_another_site_is_refused_even_as_the_first(): void {
		// The gateway key is shared across clients, so a valid envelope for
		// site A plus site B's token would otherwise install A's rules on B
		// before B's first push — and then refuse B's real documents as a
		// client mismatch. Bound to the site hash exactly as grants are: THIS
		// site (B) has already provisioned its own token, so the refusal
		// below must come from comparing it against A's hash — not merely
		// from nothing being stored yet (see the empty-token case next).
		$this->site(); // provisions this site's own (B's) token.
		$res = Aura_Worker_Rules::accept( $this->ruleset( 1, array( $this->freeze() ), null, null, hash( 'sha256', 'some-other-site' ) ) );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_ruleset_wrong_site', $res->get_error_code() );
		$this->assertNull( Aura_Worker_Rules::current() );
	}

	public function test_a_document_is_refused_before_this_site_has_provisioned_a_token(): void {
		// The other half of the binding: before any token is stored,
		// `aura_worker_site_token` reads as empty, and accept() must still
		// refuse rather than trust an envelope with nothing to check it
		// against.
		$this->assertFalse( array_key_exists( 'aura_worker_site_token', $GLOBALS['_options'] ) );
		$res = Aura_Worker_Rules::accept( $this->ruleset( 1, array( $this->freeze() ), null, null, hash( 'sha256', 'some-other-site' ) ) );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_ruleset_wrong_site', $res->get_error_code() );
		$this->assertNull( Aura_Worker_Rules::current() );
	}

	public function test_a_document_without_a_site_is_refused(): void {
		$this->assertInstanceOf( WP_Error::class, Aura_Worker_Rules::accept( $this->sign( array( 'v' => 1, 'client' => 'client-1', 'seq' => 1, 'rules' => array() ) ) ) );
	}

	public function test_the_identical_envelope_again_is_success_not_a_replay(): void {
		// Aura retries a push whose 200 was lost. The same document at the
		// same seq is already what we hold; saying 409 would record a delivered
		// update as failed forever.
		$env = $this->ruleset( 5, array( $this->freeze() ) );
		$this->assertTrue( Aura_Worker_Rules::accept( $env ) );
		$this->assertTrue( Aura_Worker_Rules::accept( $env ) );
		$this->assertSame( 5, Aura_Worker_Rules::current()['seq'] );
	}

	public function test_a_different_document_at_the_same_seq_is_still_refused(): void {
		$this->assertTrue( Aura_Worker_Rules::accept( $this->ruleset( 5, array( $this->freeze() ) ) ) );
		$res = Aura_Worker_Rules::accept( $this->ruleset( 5, array() ) ); // same seq, different rules
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertCount( 1, Aura_Worker_Rules::rules() );
	}

	public function test_clear_forgets_everything(): void {
		Aura_Worker_Rules::accept( $this->ruleset( 1 ) );
		Aura_Worker_Rules::clear();
		$this->assertNull( Aura_Worker_Rules::current() );
		$this->assertSame( array(), Aura_Worker_Rules::rules() );
	}
}
