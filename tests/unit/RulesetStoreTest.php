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
		// insert_if_absent() must evict `notoptions` on this losing branch too,
		// not only when it wins: real get_option() lists the key in
		// `notoptions` on the very miss that sent us into this INSERT, and that
		// cache entry would otherwise short-circuit every later current() in
		// this request to null even though a valid ruleset (seq 8) now sits in
		// the row we just lost the race for. The stub simulates the racer by
		// calling accept() recursively (see bootstrap.php `query()`), which
		// itself wins and evicts once via the pre-existing winning branch — so
		// a plain "contains" check would pass even without this fix. Count the
		// occurrences instead: the recursive winner accounts for exactly one,
		// and this call's own losing branch must contribute a second.
		$notoptions_evictions = array_filter(
			$GLOBALS['_cache_deletes'],
			static fn( $d ) => $d === array( 'key' => 'notoptions', 'group' => 'options' )
		);
		$this->assertGreaterThanOrEqual(
			2,
			count( $notoptions_evictions ),
			'the losing branch itself must also evict notoptions, not only the nested race winner'
		);
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

	public function test_a_duplicate_key_error_on_the_first_insert_is_a_lost_race_not_a_store_error(): void {
		// Two first pushes racing: both NOT EXISTS subqueries can see no row
		// before either INSERT commits, and then the unique index on
		// option_name turns the loser's statement into a duplicate-key error
		// (or InnoDB's gap locks deadlock the pair and roll one back).
		// $wpdb->query() reports that as false — the same value a broken
		// database returns — so the classification must come from the row
		// (there, or not), never from last_error, which the stub localises
		// on purpose. A lost race ends in the ordinary re-decision against
		// the winner's row, never in 500 aura_ruleset_store_failed while the
		// winner sits installed.
		$GLOBALS['_insert_racer']   = $this->ruleset( 8, array( $this->freeze() ) );
		$GLOBALS['_db_query_error'] = 'duplicate';

		$res = Aura_Worker_Rules::accept( $this->ruleset( 2 ) );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_ruleset_stale', $res->get_error_code(), 'a duplicate-key race was reported as a store failure' );
		$this->assertSame( 8, Aura_Worker_Rules::current()['seq'], 'the winner must stay installed' );
	}

	public function test_a_newer_push_that_loses_the_first_insert_by_duplicate_key_still_installs(): void {
		// The mirror image of the test above: losing the INSERT to the unique
		// index is not a refusal. Seq 9 re-reads, finds the racer's 5, and
		// installs over it by CAS — one retry, not an error.
		$GLOBALS['_insert_racer']   = $this->ruleset( 5 );
		$GLOBALS['_db_query_error'] = 'duplicate';

		$this->assertTrue( Aura_Worker_Rules::accept( $this->ruleset( 9 ) ) );
		$this->assertSame( 9, Aura_Worker_Rules::current()['seq'] );
	}

	public function test_a_failed_first_insert_with_no_row_behind_it_is_not_retried(): void {
		// A lock-wait timeout (MySQL 1205) fails the statement and leaves no
		// winner. Treating that as a lost race would send accept() back to
		// the INSERT, which waits the full innodb_lock_wait_timeout again —
		// up to MAX_SWAP_ATTEMPTS times, minutes for one REST request. One
		// statement, one 500; Aura retries later, this request does not.
		$GLOBALS['_db_query_error'] = true;

		$res = Aura_Worker_Rules::accept( $this->ruleset( 1 ) );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_ruleset_store_failed', $res->get_error_code() );
		$inserts = array_filter(
			$GLOBALS['_db_queries'],
			static fn( $q ) => false !== strpos( $q, 'WHERE NOT EXISTS' )
		);
		$this->assertCount( 1, $inserts, 'a failed INSERT with no row behind it was retried' );
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
		// The repair goes through insert_if_absent()'s losing branch (this row
		// already exists) before swap_raw() fixes it — so notoptions must be
		// evicted here too, or the repaired ruleset is unreadable by
		// current() for the rest of the request (indefinitely under a
		// persistent object cache), and enforcement goes silently off.
		$this->assertContains(
			array( 'key' => 'notoptions', 'group' => 'options' ),
			$GLOBALS['_cache_deletes'],
			'a corrupt-row repair must evict notoptions so a later current() re-reads the database'
		);
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

	/** What the site-side connect writes (Task 6): the binding IS the stored record, naming the current token. */
	private function bind( string $client, ?string $token_hash = null ): void {
		Aura_Worker_Rules::bind( $client, $token_hash ?? $this->site() );
	}

	public function test_a_bound_site_refuses_another_clients_document_whatever_it_holds(): void {
		// Aura#378 Ruling C1 / SiteAgent#65. connect() replaced the store with
		// the NEW client's sentinel. Whatever the old client still has in flight
		// — its clear (empty) or a late real ruleset — is refused, and the
		// sentinel stays: the new client's first document (seq 1, even empty)
		// installs over it, and the inverse race (late old NON-empty document
		// onto the new client's empty ruleset) is refused too.
		$this->bind( 'client-new' );
		$this->assertSame( 'aura_ruleset_client_mismatch', Aura_Worker_Rules::accept( $this->ruleset( 9, array(), null, 'client-old' ) )->get_error_code() );
		$this->assertSame( 'aura_ruleset_client_mismatch', Aura_Worker_Rules::accept( $this->ruleset( 12, array( $this->freeze() ), null, 'client-old' ) )->get_error_code() );
		$this->assertNull( Aura_Worker_Rules::current() );
		$this->assertSame( 'client-new', Aura_Worker_Rules::stored()['client'] );

		$this->assertTrue( Aura_Worker_Rules::accept( $this->ruleset( 1, array(), null, 'client-new' ) ) );
		$this->assertSame( 1, Aura_Worker_Rules::current()['seq'] );
		$this->assertSame( 'aura_ruleset_client_mismatch', Aura_Worker_Rules::accept( $this->ruleset( 13, array( $this->freeze() ), null, 'client-old' ) )->get_error_code() );
		$this->assertTrue( Aura_Worker_Rules::accept( $this->ruleset( 2, array( $this->freeze() ), null, 'client-new' ) ) );
		$this->assertSame( 2, Aura_Worker_Rules::current()['seq'] );
	}

	public function test_the_old_clients_own_document_is_not_delivered_on_a_re_homed_site(): void {
		// The identical-envelope shortcut compares against the stored record —
		// which is now the sentinel, so an old envelope never matches it.
		$env = $this->ruleset( 5, array( $this->freeze() ), null, 'client-old' );
		$this->assertTrue( Aura_Worker_Rules::accept( $env ) );
		$this->bind( 'client-new' );
		$this->assertSame( 'aura_ruleset_client_mismatch', Aura_Worker_Rules::accept( $env )->get_error_code() );
	}

	public function test_an_already_authenticated_old_request_loses_its_swap_to_the_connect_and_re_decides(): void {
		// The whole point of putting the binding INSIDE the swapped value: the
		// old request decided against the pre-connect record (or nothing), the
		// connect replaced that value, the swap names a value that is gone and
		// fails, and the re-decision meets the sentinel. No second read of any
		// other option — nothing for WordPress's per-request option cache to
		// serve stale (Codex round 8).
		$old_env = $this->ruleset( 6, array( $this->freeze() ), null, 'client-old' );
		Aura_Worker_Rules::accept( $this->ruleset( 5, array( $this->freeze() ), null, 'client-old' ) );
		$GLOBALS['_sa_before_swap'] = function () {
			Aura_Worker_Rules::bind( 'client-new', $this->site() ); // the connect, between the old request's read and its write
			$GLOBALS['_sa_before_swap'] = null;
		};
		$res = Aura_Worker_Rules::accept( $old_env );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_ruleset_client_mismatch', $res->get_error_code() );
		$this->assertSame( 'client-new', Aura_Worker_Rules::stored()['client'] );
		$this->assertNull( Aura_Worker_Rules::current() );
	}

	public function test_a_paused_old_request_whose_option_cache_still_holds_the_old_token_is_refused_after_one_ordinary_re_home(): void {
		// Codex round 11 on the plan PR. The request authenticated with token A,
		// cached it, and was paused; meanwhile one normal connect installed token
		// B and client B's sentinel. Read through the cache the request would
		// pass wrong_site, misjudge the sentinel as stale, and install A's
		// document. accept() reads store and token from the DATABASE.
		Aura_Worker_Rules::accept( $this->ruleset( 5, array( $this->freeze() ), null, 'client-A' ) ); // site holds A's ruleset under token A
		$token_a = $this->site();
		$GLOBALS['_sa_option_cache'] = array( 'aura_worker_site_token' => $token_a ); // what THIS request's cache holds: token A
		$token_b = hash( 'sha256', 'token-B' );
		$GLOBALS['_options']['aura_worker_site_token'] = $token_b;        // the re-home: token B …
		Aura_Worker_Rules::bind( 'client-B', $token_b );                   // … then client B's sentinel (database state)
		$res = Aura_Worker_Rules::accept( $this->ruleset( 6, array( $this->freeze() ), null, 'client-A', $token_a ) ); // site = token A (the document's own binding)
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_ruleset_wrong_site', $res->get_error_code(), 'The token is read from the database, after the store.' );
		$this->assertSame( 'client-B', Aura_Worker_Rules::stored()['client'] );
		$this->assertNull( Aura_Worker_Rules::current() );
	}

	public function test_a_database_error_on_the_uncached_reads_is_a_retryable_store_failure_not_wrong_site(): void {
		// $wpdb->get_var() answers null for an absent row AND for a failed query.
		// Read as "no token", a transient database error would turn a valid push
		// into 403 wrong_site — Aura would record a binding problem instead of
		// retrying. The read inspects $wpdb->last_error and says which it was.
		$GLOBALS['_sa_wpdb_error'] = 'MySQL server has gone away';
		$res = Aura_Worker_Rules::accept( $this->ruleset( 1, array( $this->freeze() ) ) );
		$GLOBALS['_sa_wpdb_error'] = '';
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_ruleset_store_failed', $res->get_error_code() );
		$this->assertSame( 500, $res->get_error_data()['status'] );
		$this->assertNull( Aura_Worker_Rules::current() );
	}

	public function test_reads_are_ordered_store_then_token_so_a_connect_between_them_is_caught(): void {
		// The connect writes token THEN sentinel. A request that read the
		// pre-connect store and then reads the token sees token B → wrong_site.
		Aura_Worker_Rules::accept( $this->ruleset( 5, array( $this->freeze() ), null, 'client-A' ) );
		$GLOBALS['_sa_after_store_read'] = function () {
			$token_b = hash( 'sha256', 'token-B' );
			$GLOBALS['_options']['aura_worker_site_token'] = $token_b;
			Aura_Worker_Rules::bind( 'client-B', $token_b );
			$GLOBALS['_sa_after_store_read'] = null;
		};
		$GLOBALS['_db_queries'] = array();
		$res = Aura_Worker_Rules::accept( $this->ruleset( 6, array( $this->freeze() ), null, 'client-A' ) );
		$this->assertSame( 'aura_ruleset_wrong_site', $res->get_error_code() );
		$this->assertSame( 'client-B', Aura_Worker_Rules::stored()['client'] );
		// And refused on the FIRST pass, without attempting a write: read
		// token-then-store, this document would pass wrong_site against the
		// pre-connect token, swap, lose, and only then re-decide.
		$this->assertEmpty(
			array_filter( $GLOBALS['_db_queries'], static fn( $q ) => false !== strpos( $q, 'UPDATE ' ) || false !== strpos( $q, 'INSERT ' ) ),
			'the token was read before the store: a write was attempted against a value the connect had already replaced'
		);
	}

	public function test_an_old_request_that_read_nothing_stored_also_loses_to_the_connect(): void {
		// A site that never held a ruleset: the old request decided against
		// "nothing stored" and would INSERT; the connect's sentinel is there
		// first, the conditional insert reports the row exists, and the
		// re-decision meets the sentinel.
		$GLOBALS['_sa_before_swap'] = function () {
			Aura_Worker_Rules::bind( 'client-new', $this->site() );
			$GLOBALS['_sa_before_swap'] = null;
		};
		$this->assertSame( 'aura_ruleset_client_mismatch', Aura_Worker_Rules::accept( $this->ruleset( 1, array( $this->freeze() ), null, 'client-old' ) )->get_error_code() );
		$this->assertSame( 'client-new', Aura_Worker_Rules::stored()['client'] );
	}

	public function test_a_sentinel_for_a_token_that_is_no_longer_the_sites_is_stale_and_replaceable(): void {
		// Two connects at once (Codex round 7): token A, token B, sentinel A.
		// The sentinel names its token; one written for a token that is no
		// longer current binds nobody and is replaced by the next document —
		// never "authenticates B, accepts only A".
		$this->bind( 'client-A', hash( 'sha256', 'token-A' ) );
		$GLOBALS['_options']['aura_worker_site_token'] = hash( 'sha256', 'token-B' ); // B's token write landed last
		$this->assertSame( '', Aura_Worker_Rules::bound_client() );
		$this->assertTrue( Aura_Worker_Rules::accept( $this->ruleset( 1, array( $this->freeze() ), null, 'client-B', hash( 'sha256', 'token-B' ) ) ) );
		$this->assertSame( 'client-B', Aura_Worker_Rules::current()['client'] );
	}

	public function test_a_stale_sentinel_does_not_lower_the_bar_for_the_same_client(): void {
		// Stale means "replaceable by a document for this site's token" — the
		// document's own seq rule still applies once a real record is in.
		$this->bind( 'client-B', hash( 'sha256', 'token-A' ) );
		$GLOBALS['_options']['aura_worker_site_token'] = hash( 'sha256', 'token-B' );
		$this->assertTrue( Aura_Worker_Rules::accept( $this->ruleset( 4, array( $this->freeze() ), null, 'client-B', hash( 'sha256', 'token-B' ) ) ) );
		$this->assertSame( 'aura_ruleset_stale', Aura_Worker_Rules::accept( $this->ruleset( 3, array(), null, 'client-B', hash( 'sha256', 'token-B' ) ) )->get_error_code() );
	}

	public function test_an_unbound_site_keeps_the_stored_record_comparison(): void {
		// A site connected by an older Aura has no sentinel: what it holds is a
		// 2.10.1-shaped record, which makes no claim about a token and is
		// therefore never stale. Today's behaviour — including the residual
		// race — until it reconnects (the fleet shows it as
		// ruleset_wrong_client meanwhile). A record read as stale would let any
		// client's document replace it, which is the opposite of the fix.
		$legacy = array( 'envelope' => 'x.y', 'client' => 'client-1', 'seq' => 5, 'issued_at' => '', 'received_at' => time(), 'rules' => array( $this->freeze() ) );
		$GLOBALS['_options'][ Aura_Worker_Rules::OPTION ] = $legacy;
		$GLOBALS['_rows'][ Aura_Worker_Rules::OPTION ]    = maybe_serialize( $legacy );
		$this->site(); // this site has a token …
		$this->assertSame( '', Aura_Worker_Rules::bound_client(), '… which the legacy record says nothing about' );
		$this->assertSame( 'aura_ruleset_client_mismatch', Aura_Worker_Rules::accept( $this->ruleset( 1, array(), null, 'client-2' ) )->get_error_code() );
		$this->assertTrue( Aura_Worker_Rules::accept( $this->ruleset( 6, array() ) ) );
	}

	public function test_within_a_bound_client_seq_stays_monotonic_from_the_sentinel_up(): void {
		$this->bind( 'client-new' );
		$this->assertSame( 'aura_ruleset_stale', Aura_Worker_Rules::accept( $this->ruleset( 0, array(), null, 'client-new' ) )->get_error_code(), 'seq 0 is the sentinel\'s; the first document is 1.' );
		$this->assertTrue( Aura_Worker_Rules::accept( $this->ruleset( 9, array(), null, 'client-new' ) ) );
		$this->assertSame( 'aura_ruleset_stale', Aura_Worker_Rules::accept( $this->ruleset( 3, array( $this->freeze() ), null, 'client-new' ) )->get_error_code() );
	}

	public function test_a_real_record_carries_the_binding_forward(): void {
		// Once the bound client's document is installed, the record still names
		// the token (accept() copies the sentinel's token_hash), so a later
		// old-client document meets the same refusal and a later concurrent
		// connect's stale check still works.
		$this->bind( 'client-new' );
		Aura_Worker_Rules::accept( $this->ruleset( 1, array( $this->freeze() ), null, 'client-new' ) );
		$this->assertSame( $this->site(), Aura_Worker_Rules::stored()['token_hash'] );
		$this->assertSame( 'client-new', Aura_Worker_Rules::bound_client() );
	}

	public function test_clear_forgets_the_binding_too(): void {
		$this->bind( 'client-new' );
		Aura_Worker_Rules::clear();
		$this->assertNull( Aura_Worker_Rules::stored() );
		$this->assertSame( '', Aura_Worker_Rules::bound_client() );
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
