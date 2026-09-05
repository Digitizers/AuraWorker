<?php
/**
 * Held door writes: one option row per hold, claimed by MOVING it, and every
 * race between claim, reject and expiry resolved so that at most one side
 * proceeds and nothing runs twice (spec §3.6, §3.7, round-9/round-16).
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class DoorHoldsTest extends TestCase {

	protected function setUp(): void {
		sa_reset_state();
		require_once dirname( __DIR__, 2 ) . '/digitizer-site-worker/includes/class-aura-worker-door-log.php';
		require_once dirname( __DIR__, 2 ) . '/digitizer-site-worker/includes/class-aura-worker-door-holds.php';
	}

	private function call( array $over = array() ): array {
		return $over + array(
			'ability' => 'elementor/publish-document',
			'input'   => array( 'post_id' => 7 ),
			'touches' => array( array( 'type' => 'page', 'id' => '7' ) ),
			'actor'   => array( 'user_id' => 3, 'login' => 'bot', 'app_password_name' => 'Elementor MCP (Claude)', 'app_password_uuid' => 'u', 'via' => 'mcp' ),
			'verdict' => 'none',
			'rule'    => null,
		);
	}

	public function test_a_hold_is_one_row_named_by_its_ref_with_a_7_day_ttl(): void {
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		$this->assertMatchesRegularExpression( '/^door_[0-9a-f-]{36}$/', $ref );
		$row = $GLOBALS['_options'][ 'aura_worker_door_held_' . $ref ];
		$this->assertSame( 7, $row['input']['post_id'] );
		$this->assertSame( 'none', $row['verdict'] );
		$this->assertEqualsWithDelta( time() + 7 * 86400, strtotime( $row['expires_at'] ), 5 );
	}

	/** The LIKE prefix the held read carries, as the statement escapes it. */
	private function heldReadKey(): string {
		return $GLOBALS['wpdb']->esc_like( Aura_Worker_Door_Holds::HELD );
	}

	/**
	 * Ruling P57: an unreadable queue is not an empty one.
	 *
	 * `rows()` cast a failed `get_results()` to `array()`, so `count()` read
	 * the queue as below capacity and `hold()` admitted past CAP — again and
	 * again, for as long as the read kept failing. The bounded approval queue
	 * was bounded only while the database was healthy.
	 */
	public function test_a_hold_refuses_retryably_when_the_queue_cannot_be_read(): void {
		$GLOBALS['_sa_rows_read_error'][ $this->heldReadKey() ] = true;

		$out = Aura_Worker_Door_Holds::hold( $this->call() );

		$GLOBALS['_sa_rows_read_error'] = array();
		Aura_Worker_Door_Holds::forget_held(); // a healthy read is a NEW request (Ruling P71)
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aura_hold_failed', $out->get_error_code(), 'retryable, and NOT queue_full' );
		$this->assertSame( 503, $out->get_error_data()['status'] );
		$this->assertSame( array(), Aura_Worker_Door_Holds::listing(), 'nothing was inserted' );
		$this->assertSame( 0, Aura_Worker_Door_Holds::count() );
	}

	/** count() answers null rather than a false zero. */
	public function test_an_unreadable_queue_counts_as_null_not_zero(): void {
		Aura_Worker_Door_Holds::hold( $this->call() );
		$this->assertSame( 1, Aura_Worker_Door_Holds::count() );

		$GLOBALS['_sa_rows_read_error'][ $this->heldReadKey() ] = true;
		Aura_Worker_Door_Holds::forget_held(); // the failing read is a NEW request (Ruling P71)
		$this->assertNull( Aura_Worker_Door_Holds::count() );
		$this->assertTrue( Aura_Worker_Door_Holds::queue_unreadable() );
		$GLOBALS['_sa_rows_read_error'] = array();
	}

	/**
	 * Ruling P71 (F4): `held` and `held_unreadable` come from ONE read.
	 *
	 * They were two queries. A read that failed for `listing()` and succeeded
	 * for `queue_unreadable()` a microsecond later put `held: []` beside
	 * `held_unreadable: false` in the same fragment — which is precisely the
	 * pair Ruling P57 exists to make impossible, and which Aura reads as "the
	 * queue is empty".
	 */
	public function test_a_first_failing_read_leaves_the_fragment_saying_unreadable(): void {
		// Seeded straight into the store, so this request has not read the
		// queue yet and the seam below breaks its FIRST read.
		$row = array(
			'ref'        => 'door_seeded',
			'ability'    => 'elementor/publish-document',
			'actor'      => array( 'user_id' => 3, 'login' => 'bot' ),
			'touches'    => array(),
			'verdict'    => 'none',
			'created_at' => gmdate( 'c' ),
			'expires_at' => gmdate( 'c', time() + 3600 ),
			'binding'    => Aura_Worker_Door_Log::binding(),
		);
		$GLOBALS['_options']['aura_worker_door_held_door_seeded'] = $row;
		$GLOBALS['_rows']['aura_worker_door_held_door_seeded']    = maybe_serialize( $row );
		$GLOBALS['_sa_rows_read_error'][ $this->heldReadKey() ] = 1; // the FIRST held read fails, the next succeeds

		$held       = Aura_Worker_Door_Holds::listing();
		$unreadable = Aura_Worker_Door_Holds::queue_unreadable();

		$GLOBALS['_sa_rows_read_error'] = array();
		$this->assertSame( array(), $held );
		$this->assertTrue( $unreadable, 'an empty list and a readable queue must never be reported together' );
		$this->assertNull( Aura_Worker_Door_Holds::count(), 'and the cap agrees: unknown, not zero' );
	}

	/**
	 * Ruling P96 (F2), the hold's half: a queued approval `present()` could not
	 * witness is not queued at all.
	 */
	public function test_a_hold_whose_epoch_cannot_be_minted_stores_nothing(): void {
		unset( $GLOBALS['_options'][ Aura_Worker_Door_Log::EPOCH ], $GLOBALS['_rows'][ Aura_Worker_Door_Log::EPOCH ] );
		$GLOBALS['_sa_insert_unique_fail'] = Aura_Worker_Door_Log::EPOCH;

		$out = Aura_Worker_Door_Holds::hold( $this->call() );

		$GLOBALS['_sa_insert_unique_fail'] = false;
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aura_hold_failed', $out->get_error_code() );
		$this->assertSame( 503, $out->get_error_data()['status'] );
		Aura_Worker_Door_Holds::forget_held();
		$this->assertSame( array(), Aura_Worker_Door_Holds::listing(), 'nothing was queued' );
		$this->assertSame(
			array(),
			array_filter(
				array_keys( $GLOBALS['_rows'] ),
				static function ( $k ) {
					return 0 === strpos( (string) $k, Aura_Worker_Door_Holds::HELD );
				}
			),
			'and no held row exists'
		);
	}

	/** …and with the epoch present, both proceed exactly as before. */
	public function test_a_hold_with_an_epoch_present_is_queued(): void {
		$this->assertNotSame( '', Aura_Worker_Door_Log::epoch() );

		$ref = Aura_Worker_Door_Holds::hold( $this->call() );

		$this->assertIsString( $ref );
		$this->assertNotNull( Aura_Worker_Door_Holds::get_held( $ref ) );
	}

	/** A sweep with an unreadable read deletes nothing. */
	public function test_a_sweep_with_an_unreadable_queue_deletes_nothing(): void {
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		$GLOBALS['_options'][ 'aura_worker_door_held_' . $ref ]['expires_at'] = gmdate( 'c', time() - 10 );
		$GLOBALS['_rows'][ 'aura_worker_door_held_' . $ref ] = maybe_serialize( $GLOBALS['_options'][ 'aura_worker_door_held_' . $ref ] );
		$GLOBALS['_sa_rows_read_error'][ $this->heldReadKey() ] = true;

		$this->assertSame( 0, Aura_Worker_Door_Holds::sweep( time(), self::CLAIM_STALE_MS ) );

		$GLOBALS['_sa_rows_read_error'] = array();
		$this->assertArrayHasKey( 'aura_worker_door_held_' . $ref, $GLOBALS['_rows'], 'never delete on a guess' );
	}

	/**
	 * A departed client's hold is invisible, not deleted.
	 *
	 * Six review rounds went into racing a DELETE of that queue against every
	 * request that might already be holding, claiming or replaying one of its
	 * rows. None of it was needed: the row records the generation that queued
	 * it, and every reader asks.
	 */
	public function test_a_hold_from_another_binding_is_invisible_to_every_reader(): void {
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		$this->assertNotNull( Aura_Worker_Door_Holds::get_held( $ref ) );

		sa_rotate_binding( array( 'client' => 'other', 'dashboard' => 'https://other.example' ) ); // a changed-binding connect, or an unbind

		$this->assertNull( Aura_Worker_Door_Holds::get_held( $ref ), 'not readable' );
		$this->assertSame( array(), Aura_Worker_Door_Holds::listing(), 'not listed' );
		$this->assertSame( 0, Aura_Worker_Door_Holds::count(), 'and not charging the cap' );
		$this->assertInstanceOf( WP_Error::class, Aura_Worker_Door_Holds::claim( $ref ) );
		$this->assertSame( 'not_held', Aura_Worker_Door_Holds::claim( $ref )->get_error_code(), 'and not claimable' );
		$this->assertArrayHasKey( 'aura_worker_door_held_' . $ref, $GLOBALS['_rows'], 'the row itself is still on disk' );
	}

	/** …and the sweep is what finally removes it. */
	public function test_the_sweep_removes_a_hold_from_another_binding(): void {
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		sa_rotate_binding( array( 'client' => 'other', 'dashboard' => 'https://other.example' ) );

		$this->assertSame( 1, Aura_Worker_Door_Holds::sweep( time(), self::CLAIM_STALE_MS ) );

		$this->assertArrayNotHasKey( 'aura_worker_door_held_' . $ref, $GLOBALS['_rows'] );
	}

	/** A hold of the CURRENT binding is untouched by all of that. */
	public function test_a_hold_of_the_current_binding_is_listed_claimable_and_counted(): void {
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );

		$this->assertSame( array( $ref ), array_column( Aura_Worker_Door_Holds::listing(), 'ref' ) );
		$this->assertSame( 1, Aura_Worker_Door_Holds::count() );
		$this->assertSame( 0, Aura_Worker_Door_Holds::sweep( time(), self::CLAIM_STALE_MS ) );
		$this->assertIsArray( Aura_Worker_Door_Holds::claim( $ref ) );
	}

	/** Publish an identity the way a connect does: token, client sentinel, dashboard. */
	private function publishIdentity( string $client, string $rawToken ): void {
		$hash = hash( 'sha256', $rawToken );
		$GLOBALS['_options']['aura_worker_site_token'] = $hash;
		$GLOBALS['_rows']['aura_worker_site_token']    = maybe_serialize( $hash );
		$GLOBALS['_options']['aura_worker_dashboard_url'] = 'https://app.example';
		$GLOBALS['_rows']['aura_worker_dashboard_url']    = maybe_serialize( 'https://app.example' );
		Aura_Worker_Rules::bind( $client, $hash );
		Aura_Worker_Door_Log::forget_live_identity();
		Aura_Worker_Door_Holds::forget_held();
	}

	/**
	 * Ruling P73: an `unset` record is ADOPTED.
	 *
	 * A site 2.16 meets already connected mints an `unset` placeholder, which
	 * states nothing about whose door this is. Adoption states what the site
	 * can already see — the client sentinel and the dashboard URL — WITHOUT
	 * moving the generation, so the site's own rows stay current and no
	 * approval is stranded.
	 *
	 * The window this originally closed (a replacement connect publishing its
	 * identity before the rotation) no longer exists: since Ruling P75 a
	 * connect refuses to run over a live foreign binding at all. What adoption
	 * still does is put every site's record into a state the unbind, the
	 * connect refusal and `leftovers()` can all read.
	 */
	public function test_an_unset_record_is_adopted_to_the_identity_the_site_is_live_under(): void {
		$rec = array( 'gen' => 'upgrade-gen', 'state' => 'unset', 'client' => null, 'dashboard' => null );
		$GLOBALS['_options'][ Aura_Worker_Door_Log::BINDING ] = $rec;
		$GLOBALS['_rows'][ Aura_Worker_Door_Log::BINDING ]    = maybe_serialize( $rec );
		$this->publishIdentity( 'client-a', 'tok-a' );
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		$this->assertIsString( $ref );
		$this->assertSame( 'upgrade-gen', $GLOBALS['_options'][ 'aura_worker_door_held_' . $ref ]['binding'], 'the placeholder generation, unchanged' );

		$after = Aura_Worker_Door_Log::binding_record();

		$this->assertSame( 'bound', $after['state'] );
		$this->assertSame( 'client-a', $after['client'] );
		$this->assertSame( 'https://app.example', $after['dashboard'] );
		$this->assertSame( 'upgrade-gen', $after['gen'], 'ADOPTED, not rotated' );
		$this->assertNotNull( Aura_Worker_Door_Holds::get_held( $ref ), 'so the site still serves its own queue' );
	}

	/** …and a site with NO live identity keeps `unset`: nothing to adopt it for. */
	public function test_a_site_with_no_live_identity_keeps_an_unset_record(): void {
		$rec = array( 'gen' => 'upgrade-gen', 'state' => 'unset', 'client' => null, 'dashboard' => null );
		$GLOBALS['_options'][ Aura_Worker_Door_Log::BINDING ] = $rec;
		$GLOBALS['_rows'][ Aura_Worker_Door_Log::BINDING ]    = maybe_serialize( $rec );

		$this->assertSame( 'unset', Aura_Worker_Door_Log::binding_record()['state'] );
	}

	/**
	 * Ruling P72 (a): the LOSER of a first-reader race reads the winner's row.
	 *
	 * Two requests meeting a site with no binding record both read the name as
	 * absent and both try to mint. The loser's `insert_unique()` did not evict
	 * its own `notoptions` entry, so the `get_option()` after it still answered
	 * null for a row the winner had demonstrably just inserted — and the mint
	 * returned ''. Everything that request then stamped carried an empty
	 * binding, which every reader treated as legacy and therefore permanently
	 * current: after a rebind, the replacement client could claim and execute
	 * the previous client's stored mutation.
	 */
	public function test_the_loser_of_a_lazy_mint_race_reads_the_winners_generation(): void {
		// The winner's row, written by another request straight to the
		// database — and this request's negative cache, from the read that
		// decided to mint.
		$winner = array( 'gen' => 'winner-gen', 'state' => 'unset', 'client' => null, 'dashboard' => null );
		$GLOBALS['_rows'][ Aura_Worker_Door_Log::BINDING ] = maybe_serialize( $winner );
		$GLOBALS['_notoptions'][ Aura_Worker_Door_Log::BINDING ] = true;

		$this->assertSame( 'winner-gen', Aura_Worker_Door_Log::binding() );

		$GLOBALS['_notoptions'][ Aura_Worker_Door_Log::BINDING ] = true; // and again, for the hold's own read
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );

		$this->assertIsString( $ref );
		$this->assertSame( 'winner-gen', $GLOBALS['_options'][ 'aura_worker_door_held_' . $ref ]['binding'] );
	}

	/**
	 * …and a mint that cannot be established at all refuses, retryably.
	 *
	 * The alternative is stamping '' — which is exactly the row this ruling
	 * exists to stop being written.
	 */
	public function test_a_hold_refuses_retryably_when_the_binding_cannot_be_minted(): void {
		// ONLY the binding mint loses, and its row is genuinely absent: the
		// re-read finds nothing, so the mint is UNREADABLE.
		$GLOBALS['_sa_insert_unique_fail'] = Aura_Worker_Door_Log::BINDING;

		$out = Aura_Worker_Door_Holds::hold( $this->call() );

		$GLOBALS['_sa_insert_unique_fail'] = false;
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aura_hold_failed', $out->get_error_code() );
		$this->assertSame( 503, $out->get_error_data()['status'] );
		Aura_Worker_Door_Holds::forget_held();
		$this->assertSame( array(), Aura_Worker_Door_Holds::listing(), 'nothing was written' );
		$this->assertSame( 0, Aura_Worker_Door_Holds::count(), 'and no row is charging the cap' );
		$this->assertSame(
			array(),
			array_filter(
				array_keys( $GLOBALS['_rows'] ),
				static function ( $k ) {
					return 0 === strpos( (string) $k, Aura_Worker_Door_Holds::HELD );
				}
			),
			'no held row was written with an empty binding'
		);
	}

	/**
	 * Ruling P72 (b): a hold carrying NO binding is NOT ours.
	 *
	 * There is no build for such a row to predate — 2.16 introduces the door,
	 * the stamp and the fence together — so an empty stamp can only have come
	 * from a lazy-mint race that read its own negative cache. The old "legacy
	 * is current" allowance made exactly those rows current for ever, and after
	 * a rebind the replacement client could claim and run one.
	 */
	public function test_a_hold_with_no_binding_is_foreign_and_is_swept(): void {
		$ref  = Aura_Worker_Door_Holds::hold( $this->call() );
		$name = 'aura_worker_door_held_' . $ref;
		$row  = $GLOBALS['_options'][ $name ];
		unset( $row['binding'] ); // what the lost-mint race used to write
		$GLOBALS['_options'][ $name ] = $row;
		$GLOBALS['_rows'][ $name ]    = maybe_serialize( $row );
		Aura_Worker_Door_Holds::forget_held();

		$this->assertNull( Aura_Worker_Door_Holds::get_held( $ref ), 'not readable' );
		$this->assertSame( array(), Aura_Worker_Door_Holds::listing(), 'not listed' );
		$this->assertSame( 'not_held', Aura_Worker_Door_Holds::claim( $ref )->get_error_code(), 'not claimable' );
		$this->assertSame( 1, Aura_Worker_Door_Holds::sweep( time(), self::CLAIM_STALE_MS ), 'and swept like any other foreign row' );
	}

	/**
	 * Ruling P61 (F1): a LAZY record is not an unbound site.
	 *
	 * 2.16 meeting an already-connected site mints a placeholder with a null
	 * identity. Treating that as equal to an unbind's target meant the unbind
	 * rotated NOTHING — and callbacks waiting at the generation fence walked
	 * through after the site had been unbound.
	 */
	public function test_an_unbind_rotates_a_lazily_minted_record(): void {
		$before = Aura_Worker_Door_Log::binding(); // the lazy mint
		$this->assertSame( 'unset', Aura_Worker_Door_Log::binding_record()['state'] );

		$this->assertTrue( sa_rotate_binding( array( 'client' => null, 'dashboard' => null ) ) );

		$this->assertNotSame( $before, Aura_Worker_Door_Log::binding(), 'the generation moved' );
		$this->assertSame( 'unbound', Aura_Worker_Door_Log::binding_record()['state'] );
	}

	/** …and a connect rotates it too: `unset` is never equal to anything. */
	public function test_a_connect_rotates_a_lazily_minted_record(): void {
		$before = Aura_Worker_Door_Log::binding();

		$this->assertTrue( sa_rotate_binding( array( 'client' => 'c1', 'dashboard' => 'https://dash.example' ) ) );

		$this->assertNotSame( $before, Aura_Worker_Door_Log::binding() );
		$this->assertSame( 'bound', Aura_Worker_Door_Log::binding_record()['state'] );
	}

	/** An already-unbound record does not rotate again for another unbind. */
	public function test_an_unbind_of_an_already_unbound_record_is_a_no_op(): void {
		sa_rotate_binding( array( 'client' => null, 'dashboard' => null ) );
		$gen = Aura_Worker_Door_Log::binding();

		$this->assertTrue( sa_rotate_binding( array( 'client' => null, 'dashboard' => null ) ) );

		$this->assertSame( $gen, Aura_Worker_Door_Log::binding() );
	}

	/** A record written before the state existed reads as `unset`. */
	public function test_a_record_without_a_state_reads_as_unset(): void {
		$legacy = array( 'gen' => 'legacy-gen', 'client' => null, 'dashboard' => null );
		$GLOBALS['_options'][ Aura_Worker_Door_Log::BINDING ] = $legacy;
		$GLOBALS['_rows'][ Aura_Worker_Door_Log::BINDING ]    = maybe_serialize( $legacy );

		$this->assertSame( 'unset', Aura_Worker_Door_Log::binding_record()['state'] );
		$this->assertTrue( sa_rotate_binding( array( 'client' => null, 'dashboard' => null ) ), 'and rotates' );
		$this->assertNotSame( 'legacy-gen', Aura_Worker_Door_Log::binding() );
	}

	/** The dashboard base URL a legacy (clientless) connect would leave behind. */
	private function legacyConnect( string $dashboard ): void {
		$GLOBALS['_options']['aura_worker_dashboard_url'] = $dashboard;
		$GLOBALS['_rows']['aura_worker_dashboard_url']    = maybe_serialize( $dashboard );
		sa_rotate_binding( array( 'client' => null, 'dashboard' => $dashboard ) );
	}

	/**
	 * Ruling P66 (F1): a clientless connect ALWAYS rotates.
	 *
	 * The legacy callback signs no `client` line, so the identity it states is
	 * nothing but a dashboard base URL — and two distinct Aura customers
	 * commonly share one. Equality on that answered "same binding", so
	 * reconnecting the site to a DIFFERENT customer left the departed client's
	 * generation current and its held mutations approvable by the replacement.
	 */
	public function test_a_second_clientless_connect_on_the_same_dashboard_rotates(): void {
		$this->legacyConnect( 'https://dash.example' );
		$first = Aura_Worker_Door_Log::binding();
		$ref   = Aura_Worker_Door_Holds::hold( $this->call() );
		$this->assertNotNull( Aura_Worker_Door_Holds::get_held( $ref ), 'held under the first connect' );

		$this->legacyConnect( 'https://dash.example' ); // the replacement customer, same dashboard

		$this->assertNotSame( $first, Aura_Worker_Door_Log::binding(), 'an unprovable identity never equals' );
		$this->assertNull( Aura_Worker_Door_Holds::get_held( $ref ), 'the departed client\'s hold is foreign' );
		$this->assertSame( array(), Aura_Worker_Door_Holds::listing() );
		$this->assertSame( 'not_held', Aura_Worker_Door_Holds::claim( $ref )->get_error_code() );
	}

	/**
	 * …and a clientless site still serves its OWN queue between connects: the
	 * record names no client, the live identity names none either, and the
	 * dashboards match, so its rows are current.
	 */
	public function test_a_clientless_site_serves_its_own_queue_between_connects(): void {
		$this->legacyConnect( 'https://dash.example' );

		$ref = Aura_Worker_Door_Holds::hold( $this->call() );

		$this->assertNotNull( Aura_Worker_Door_Holds::get_held( $ref ) );
		$this->assertSame( array( $ref ), array_column( Aura_Worker_Door_Holds::listing(), 'ref' ) );
		$this->assertSame( 1, Aura_Worker_Door_Holds::count() );
		$this->assertSame( 0, Aura_Worker_Door_Holds::sweep( time(), self::CLAIM_STALE_MS ) );
		$this->assertIsArray( Aura_Worker_Door_Holds::claim( $ref ) );
	}

	/**
	 * Ruling P85 (F1): the sweep's DELETE reads the generation from the
	 * database, not from this process's cache.
	 *
	 * The option cache is populated at AUTHENTICATION. A `/status` request that
	 * authenticated under generation A and then paused while an unbind and a
	 * connect installed B would judge B's brand-new hold against A, call it
	 * foreign, and delete it — permanently, for the client that had just queued
	 * it. A read may be a moment old; a delete may not.
	 */
	public function test_the_sweep_judges_against_the_database_not_a_cached_generation(): void {
		sa_rotate_binding( array( 'client' => 'c1', 'dashboard' => 'https://dash.example' ) );
		$a = Aura_Worker_Door_Log::binding_raw();
		// This request's cache, primed the way authentication primes it.
		$this->assertSame( $a, Aura_Worker_Door_Log::binding_record()['gen'] );

		// ANOTHER process rebinds — the row moves, this cache does not — and
		// the new binding queues a hold of its own.
		$b   = array( 'gen' => 'generation-b', 'state' => 'bound', 'client' => 'c2', 'dashboard' => 'https://dash.example' );
		$GLOBALS['_rows'][ Aura_Worker_Door_Log::BINDING ] = maybe_serialize( $b );
		$row = array(
			'ref'        => 'door_fresh',
			'ability'    => 'elementor/publish-document',
			'actor'      => array( 'user_id' => 3, 'login' => 'bot' ),
			'touches'    => array(),
			'verdict'    => 'none',
			'created_at' => gmdate( 'c' ),
			'expires_at' => gmdate( 'c', time() + 3600 ),
			'binding'    => 'generation-b',
		);
		$GLOBALS['_options'][ 'aura_worker_door_held_door_fresh' ] = $row;
		$GLOBALS['_rows'][ 'aura_worker_door_held_door_fresh' ]    = maybe_serialize( $row );
		Aura_Worker_Door_Holds::forget_held();

		$this->assertSame( 0, Aura_Worker_Door_Holds::sweep( time(), self::CLAIM_STALE_MS ) );

		$this->assertArrayHasKey( 'aura_worker_door_held_door_fresh', $GLOBALS['_rows'], "the new binding's own hold survives" );
	}

	/** …and a generation it cannot read at all deletes nothing (P57 shape). */
	public function test_a_sweep_that_cannot_read_the_generation_deletes_nothing(): void {
		sa_rotate_binding( array( 'client' => 'c1', 'dashboard' => 'https://dash.example' ) );
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		$this->assertIsString( $ref );
		// The record is gone from under the sweep: nothing to judge by.
		unset( $GLOBALS['_options'][ Aura_Worker_Door_Log::BINDING ], $GLOBALS['_rows'][ Aura_Worker_Door_Log::BINDING ] );
		Aura_Worker_Door_Holds::forget_held();

		$this->assertSame( 0, Aura_Worker_Door_Holds::sweep( time(), self::CLAIM_STALE_MS ) );

		$this->assertArrayHasKey( 'aura_worker_door_held_' . $ref, $GLOBALS['_rows'] );
	}

	/**
	 * Ruling P83 (F1): the epoch rotation is claim-conditioned too.
	 *
	 * The record's compare-and-swap joins the claim row; the epoch rotation
	 * that now precedes it did not. A connect or unbind handler that passed
	 * `holds_site_claim()` and then stalled until another handler took the site
	 * over could resume, rotate the WINNER's epoch — invalidating its in-flight
	 * acknowledgements and leaving its record naming a cursor the site had left
	 * — and only then be rejected by the record write.
	 */
	public function test_a_stale_rebind_whose_claim_was_seized_never_rotates_the_winners_epoch(): void {
		$fence = Aura_Worker_Magic_Link::claim_site();
		$this->assertTrue( Aura_Worker_Door_Log::rotate_binding( array( 'client' => 'c1', 'dashboard' => 'https://dash.example' ), Aura_Worker_Magic_Link::SITE_CLAIM, $fence ) );
		$epoch = Aura_Worker_Door_Log::epoch_raw();
		$gen   = Aura_Worker_Door_Log::binding_raw();

		// The winner seizes the site in the window between this handler's own
		// claim check and its epoch rotation.
		$GLOBALS['_sa_before_fenced_delete'][ Aura_Worker_Door_Log::EPOCH ] = static function () {
			$row = 'winner-fence|' . time();
			$GLOBALS['_options'][ Aura_Worker_Magic_Link::SITE_CLAIM ] = $row;
			$GLOBALS['_rows'][ Aura_Worker_Magic_Link::SITE_CLAIM ]    = maybe_serialize( $row );
		};

		$done = Aura_Worker_Door_Log::rotate_binding( array( 'client' => 'c2', 'dashboard' => 'https://dash.example' ), Aura_Worker_Magic_Link::SITE_CLAIM, $fence );

		$GLOBALS['_sa_before_fenced_delete'] = array();
		$this->assertFalse( $done );
		$this->assertSame( $epoch, Aura_Worker_Door_Log::epoch_raw(), "the winner's cursor is untouched" );
		$this->assertSame( $gen, Aura_Worker_Door_Log::binding_raw(), 'and so is its binding' );
	}

	/**
	 * Ruling P81 (F1): the epoch rotates FIRST, and a failure to rotate it
	 * fails the whole rebind with nothing changed.
	 *
	 * It used to follow the record's compare-and-swap with its answer ignored,
	 * so a failed rotation left the NEW binding sitting on the PREVIOUS epoch
	 * and the rebind still reported success — and nothing repaired it, because
	 * the next connect states the same identity and the shortcut declared it
	 * done. An ack authenticated under the old binding, carrying that same
	 * epoch, could then advance the floor over the new binding's entries.
	 */
	public function test_a_rebind_whose_epoch_cannot_rotate_changes_nothing(): void {
		sa_rotate_binding( array( 'client' => 'c1', 'dashboard' => 'https://dash.example' ) );
		$gen   = Aura_Worker_Door_Log::binding_raw();
		$epoch = Aura_Worker_Door_Log::epoch_raw();
		$GLOBALS['_sa_option_delete_fail'][ Aura_Worker_Door_Log::EPOCH ] = true;

		$this->assertFalse( sa_rotate_binding( array( 'client' => 'c2', 'dashboard' => 'https://dash.example' ) ) );

		$GLOBALS['_sa_option_delete_fail'] = array();
		$this->assertSame( $epoch, Aura_Worker_Door_Log::epoch_raw(), 'the cursor did not move' );
		$this->assertSame( $gen, Aura_Worker_Door_Log::binding_raw(), 'and neither did the binding' );
		$this->assertSame( 'c1', Aura_Worker_Door_Log::binding_record()['client'] );
	}

	/** …and a record write that fails after it leaves a retry that completes. */
	public function test_a_rebind_whose_record_write_fails_is_completed_by_the_retry(): void {
		sa_rotate_binding( array( 'client' => 'c1', 'dashboard' => 'https://dash.example' ) );
		$GLOBALS['_sa_option_write_fail'][ Aura_Worker_Door_Log::BINDING ] = 1; // the first write only

		$this->assertFalse( sa_rotate_binding( array( 'client' => 'c2', 'dashboard' => 'https://dash.example' ) ) );
		$this->assertSame( 'c1', Aura_Worker_Door_Log::binding_record()['client'], 'the record is still the old one' );

		$this->assertTrue( sa_rotate_binding( array( 'client' => 'c2', 'dashboard' => 'https://dash.example' ) ) );

		$GLOBALS['_sa_option_write_fail'] = array();
		$rec = Aura_Worker_Door_Log::binding_record();
		$this->assertSame( 'c2', $rec['client'] );
		$this->assertSame( Aura_Worker_Door_Log::epoch_raw(), $rec['epoch'], 'and the record names the epoch it belongs to' );
	}

	/**
	 * …and a record whose epoch is NOT the site's is repaired by the very next
	 * connect stating the same identity — the shortcut is idempotent, not
	 * merely fast.
	 */
	public function test_a_same_identity_connect_repairs_a_record_on_a_stale_epoch(): void {
		sa_rotate_binding( array( 'client' => 'c1', 'dashboard' => 'https://dash.example' ) );
		// The state a half-done rebind leaves: the record names an epoch the
		// site has moved off.
		$rec          = Aura_Worker_Door_Log::binding_record();
		$rec['epoch'] = 'an-epoch-this-site-has-left';
		$GLOBALS['_options'][ Aura_Worker_Door_Log::BINDING ] = $rec;
		$GLOBALS['_rows'][ Aura_Worker_Door_Log::BINDING ]    = maybe_serialize( $rec );
		$gen   = $rec['gen'];
		$epoch = Aura_Worker_Door_Log::epoch_raw();

		$this->assertTrue( sa_rotate_binding( array( 'client' => 'c1', 'dashboard' => 'https://dash.example' ) ) );

		$after = Aura_Worker_Door_Log::binding_record();
		$this->assertNotSame( $gen, $after['gen'], 'the rebind really ran' );
		$this->assertNotSame( $epoch, Aura_Worker_Door_Log::epoch_raw(), 'the cursor moved with it' );
		$this->assertSame( Aura_Worker_Door_Log::epoch_raw(), $after['epoch'], 'and they agree now' );
	}

	/**
	 * rotate_binding() mints a value nothing older can match — and is a NO-OP
	 * when the record already names the identity being installed (Ruling P59).
	 */
	public function test_rotate_binding_mints_a_generation_and_is_idempotent_by_identity(): void {
		$before   = Aura_Worker_Door_Log::binding();
		$identity = array( 'client' => 'c1', 'dashboard' => 'https://dash.example' );

		$this->assertTrue( sa_rotate_binding( $identity ) );
		$after = Aura_Worker_Door_Log::binding();
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $after );
		$this->assertNotSame( $before, $after );

		// The SAME identity again changes nothing — which is what lets a failed
		// rotation simply be retried by the next connect.
		$this->assertTrue( sa_rotate_binding( $identity ) );
		$this->assertSame( $after, Aura_Worker_Door_Log::binding() );

		// A different one moves it.
		$this->assertTrue( sa_rotate_binding( array( 'client' => 'c2', 'dashboard' => 'https://dash.example' ) ) );
		$this->assertNotSame( $after, Aura_Worker_Door_Log::binding() );
	}

	public function test_a_claimed_ref_still_holds_a_slot(): void {
		for ( $i = 0; $i < 49; $i++ ) {
			Aura_Worker_Door_Holds::hold( $this->call() );
		}
		$ref = Aura_Worker_Door_Holds::hold( $this->call() ); // 50th
		Aura_Worker_Door_Holds::claim( $ref );                  // moved to CLAIMED
		$this->assertSame( 50, Aura_Worker_Door_Holds::count() );
		$this->assertSame( 'aura_hold_queue_full', Aura_Worker_Door_Holds::hold( $this->call() )->get_error_code() );
	}

	public function test_hold_serialises_count_and_insert_under_the_lock(): void {
		add_option( Aura_Worker_Door_Holds::LOCK, time(), '', 'no' ); // a fresh lock: another request is inside hold()
		$err = Aura_Worker_Door_Holds::hold( $this->call() );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'aura_hold_busy', $err->get_error_code() );
		$this->assertSame( 5, $err->get_error_data()['retry_after'] );
		$this->assertSame( 0, Aura_Worker_Door_Holds::count() );

		update_option( Aura_Worker_Door_Holds::LOCK, time() - Aura_Worker_Door_Holds::LOCK_S - 1 ); // a crashed holder
		$this->assertIsString( Aura_Worker_Door_Holds::hold( $this->call() ) );
		$this->assertFalse( get_option( Aura_Worker_Door_Holds::LOCK ) ); // released after the hold
		$this->assertSame( 1, Aura_Worker_Door_Holds::count() );

		$GLOBALS['_sa_insert_unique_fail'] = true; // the row insert fails inside the lock…
		$this->assertSame( 'aura_hold_failed', Aura_Worker_Door_Holds::hold( $this->call() )->get_error_code() );
		unset( $GLOBALS['_sa_insert_unique_fail'] );
		$this->assertFalse( get_option( Aura_Worker_Door_Holds::LOCK ) ); // …and the lock is still released
	}

	/**
	 * Ruling S37 (Codex round-15 class sweep on #88): raw_bytes() answering
	 * `null` for both "the lock row vanished" and "the read could not be
	 * proven" used to mean take_lock() looped back and raced straight to
	 * insert_unique() as if the lock had already gone — the opposite of
	 * what an UNREADABLE lock proves. It now backs off exactly like a
	 * lock it can see is fresh, and never seizes it.
	 */
	public function test_take_lock_does_not_seize_a_lock_it_cannot_prove_stale(): void {
		$stale = time() - Aura_Worker_Door_Holds::LOCK_S - 1;
		add_option( Aura_Worker_Door_Holds::LOCK, $stale, '', 'no' ); // genuinely stale, if only it could be read
		$GLOBALS['_sa_option_read_fail'][ Aura_Worker_Door_Holds::LOCK ] = true; // every read of it fails

		$err = Aura_Worker_Door_Holds::hold( $this->call() );

		$GLOBALS['_sa_option_read_fail'] = array();
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'aura_hold_busy', $err->get_error_code(), 'never seizes a lock it could not prove stale' );
		$this->assertSame( 0, Aura_Worker_Door_Holds::count(), 'nothing was admitted while the lock could not be evaluated' );
		$this->assertSame( (string) $stale, (string) $GLOBALS['_rows'][ Aura_Worker_Door_Holds::LOCK ], 'the original lock row is untouched' );
	}

	public function test_two_racers_on_a_stale_lock_only_one_of_them_takes_it(): void {
		$stale = time() - Aura_Worker_Door_Holds::LOCK_S - 1;
		add_option( Aura_Worker_Door_Holds::LOCK, $stale, '', 'no' );
		// Racer B wins the SAME window A's own fenced delete is about to
		// close: it fires INSIDE that delete's own SQL branch (never
		// _sa_before_swap, which also fires on take_lock()'s very first
		// insert_unique() attempt — before staleness is even judged — and so
		// cannot tell a fenced delete from an unconditional one; round-2
		// finding). B deletes the stale row and installs its OWN fresh lock,
		// exactly as a real racer's insert_unique() would leave things.
		$racers_lock = time();
		$GLOBALS['_sa_before_fenced_delete'][ Aura_Worker_Door_Holds::LOCK ] = static function () use ( $racers_lock ) {
			unset( $GLOBALS['_options'][ Aura_Worker_Door_Holds::LOCK ], $GLOBALS['_rows'][ Aura_Worker_Door_Holds::LOCK ] );
			$GLOBALS['_options'][ Aura_Worker_Door_Holds::LOCK ] = $racers_lock;
			$GLOBALS['_rows'][ Aura_Worker_Door_Holds::LOCK ]    = (string) $racers_lock;
		};
		$err = Aura_Worker_Door_Holds::hold( $this->call() );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'aura_hold_busy', $err->get_error_code(), 'A never gets past the fenced delete: its own insert_unique() keeps meeting a lock it does not own' );
		$this->assertSame( $racers_lock, $GLOBALS['_options'][ Aura_Worker_Door_Holds::LOCK ], "the racer's lock was never overwritten by A" );
		$this->assertSame( 0, Aura_Worker_Door_Holds::count(), 'no held row leaked while the lock could not be taken' );
	}

	public function test_the_fenced_lock_delete_never_removes_a_value_it_did_not_read(): void {
		global $wpdb;
		$stale = time() - Aura_Worker_Door_Holds::LOCK_S - 1;
		add_option( Aura_Worker_Door_Holds::LOCK, $stale, '', 'no' ); // what a reader would see
		// Something replaces the lock's value between that read and the
		// delete fenced on it — the exact window round-1's fix closes,
		// exercised directly against the fenced-DELETE SQL shape itself
		// rather than through take_lock()'s retry loop.
		$fresh = time();
		$GLOBALS['_sa_before_fenced_delete'][ Aura_Worker_Door_Holds::LOCK ] = static function () use ( $fresh ) {
			$GLOBALS['_options'][ Aura_Worker_Door_Holds::LOCK ] = $fresh;
			$GLOBALS['_rows'][ Aura_Worker_Door_Holds::LOCK ]    = (string) $fresh;
		};
		$gone = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", Aura_Worker_Door_Holds::LOCK, (string) $stale ) );
		$this->assertSame( 0, (int) $gone, 'the delete was fenced on stale bytes that no longer match' );
		$this->assertSame( $fresh, $GLOBALS['_options'][ Aura_Worker_Door_Holds::LOCK ], 'the value that replaced it survives' );
	}

	public function test_a_holders_release_never_deletes_a_lock_that_replaced_its_own(): void {
		// A holds the lock, stalls past LOCK_S, and a racer's fenced delete
		// replaces the row with ITS lock. A's `finally` then runs. An
		// unconditional delete_option() there removes the racer's brand-new
		// lock — and B goes on running hold_locked() with no mutex at all,
		// which is the serialization the lock exists to provide.
		//
		// The window is opened inside the HELD row's own INSERT (the shared
		// _sa_before_swap seam, which also fires on take_lock()'s insert — the
		// guard below is what keeps this firing only once the lock is taken).
		$racers_lock = time() . '|racer';
		$GLOBALS['_sa_before_swap'] = static function () use ( $racers_lock ) {
			if ( ! isset( $GLOBALS['_rows'][ Aura_Worker_Door_Holds::LOCK ] ) ) {
				return; // take_lock()'s own insert, before there is a lock at all
			}
			$GLOBALS['_options'][ Aura_Worker_Door_Holds::LOCK ] = $racers_lock;
			$GLOBALS['_rows'][ Aura_Worker_Door_Holds::LOCK ]    = $racers_lock;
		};

		$this->assertIsString( Aura_Worker_Door_Holds::hold( $this->call() ) );

		$this->assertSame( $racers_lock, $GLOBALS['_rows'][ Aura_Worker_Door_Holds::LOCK ] ?? null, "the release was fenced on the bytes this request inserted, so the racer's lock stands" );
	}

	public function test_a_holder_releases_the_lock_it_actually_inserted(): void {
		$this->assertIsString( Aura_Worker_Door_Holds::hold( $this->call() ) );
		$this->assertArrayNotHasKey( Aura_Worker_Door_Holds::LOCK, $GLOBALS['_rows'], 'the ordinary path still releases' );
		$this->assertFalse( get_option( Aura_Worker_Door_Holds::LOCK, false ) );
	}

	public function test_the_51st_hold_is_refused_queue_full(): void {
		for ( $i = 0; $i < 50; $i++ ) {
			$this->assertIsString( Aura_Worker_Door_Holds::hold( $this->call() ) );
		}
		$err = Aura_Worker_Door_Holds::hold( $this->call() );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'aura_hold_queue_full', $err->get_error_code() );
	}

	public function test_a_hold_whose_insert_fails_is_refused_hold_failed(): void {
		$GLOBALS['_sa_insert_unique_fail'] = true; // bootstrap seam: insert_unique() returns false without writing
		$err                                = Aura_Worker_Door_Holds::hold( $this->call() );
		$this->assertSame( 'aura_hold_failed', $err->get_error_code() );
	}

	public function test_claim_moves_the_row_and_a_second_claim_answers_not_held(): void {
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		$c   = Aura_Worker_Door_Holds::claim( $ref );
		$this->assertIsArray( $c );
		$this->assertArrayHasKey( 'claimed_at', $c );
		$this->assertArrayNotHasKey( 'aura_worker_door_held_' . $ref, $GLOBALS['_options'] );
		$this->assertArrayHasKey( 'aura_worker_door_claimed_' . $ref, $GLOBALS['_options'] );
		$again = Aura_Worker_Door_Holds::claim( $ref );
		$this->assertSame( 'not_held', $again->get_error_code() );
	}

	/**
	 * Ruling S37 (Codex round-15 class sweep on #88): from_db() answering
	 * `null` for both "the row is genuinely absent" and "the read could
	 * not be proven" used to mean claim() answered `not_held` — a 404
	 * Aura reads as "this approval no longer exists" — for a hold that
	 * was, in fact, sitting right there, unreadable rather than gone.
	 * from_db()'s read_was_unreadable() signal now lets claim() answer the
	 * RETRYABLE path instead, and touch nothing.
	 */
	public function test_a_claim_answers_retryably_when_the_held_read_is_unreadable_never_not_held(): void {
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		$GLOBALS['_sa_option_read_fail'][ Aura_Worker_Door_Holds::HELD . $ref ] = true;

		$out = Aura_Worker_Door_Holds::claim( $ref );

		$GLOBALS['_sa_option_read_fail'] = array();
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aura_hold_failed', $out->get_error_code(), 'never `not_held` — that would tell Aura the approval is gone' );
		$this->assertSame( 503, $out->get_error_data()['status'] );
		// The hold is untouched — a HEALTHY claim() right afterwards still
		// finds and moves it, proving nothing was spent on the failed read.
		$this->assertIsArray( Aura_Worker_Door_Holds::claim( $ref ) );
	}

	public function test_a_claim_that_finds_the_held_row_gone_backs_out(): void {
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		// A reject wins the race between the replay's read and its claim.
		$GLOBALS['_sa_before_swap'] = static function () use ( $ref ) {
			unset( $GLOBALS['_options'][ 'aura_worker_door_held_' . $ref ], $GLOBALS['_rows'][ 'aura_worker_door_held_' . $ref ] );
		};
		$err = Aura_Worker_Door_Holds::claim( $ref );
		$this->assertSame( 'not_held', $err->get_error_code() );
		$this->assertArrayNotHasKey( 'aura_worker_door_claimed_' . $ref, $GLOBALS['_options'], 'the claim backed out' );
	}

	public function test_unclaim_moves_the_row_back_to_held_with_its_original_fields(): void {
		$ref    = Aura_Worker_Door_Holds::hold( $this->call() );
		$before = $GLOBALS['_options'][ 'aura_worker_door_held_' . $ref ];
		Aura_Worker_Door_Holds::claim( $ref );
		Aura_Worker_Door_Holds::stamp_terminal_seq( $ref, 12 );

		$this->assertTrue( Aura_Worker_Door_Holds::unclaim( $ref ) );

		$back = Aura_Worker_Door_Holds::get_held( $ref );
		$this->assertIsArray( $back );
		$this->assertArrayHasKey( 'restored_at', $back, 'the row says an unclaim put it back (Ruling P41)' );
		unset( $back['restored_at'] );
		$this->assertSame( $before, $back, 'everything else is the row that was held' );
		$this->assertNull( Aura_Worker_Door_Holds::get_claimed( $ref ), 'and the twin is gone' );
		$this->assertSame( 1, Aura_Worker_Door_Holds::count() );
		// The approval was not spent: the ref can be claimed again.
		$this->assertIsArray( Aura_Worker_Door_Holds::claim( $ref ) );
	}

	/** The claimed row records the BINDING it was claimed under (Ruling P51). */
	public function test_a_claim_records_the_binding_it_was_taken_under(): void {
		$ref     = Aura_Worker_Door_Holds::hold( $this->call() );
		$binding = Aura_Worker_Door_Log::binding();

		Aura_Worker_Door_Holds::claim( $ref );

		$this->assertSame( $binding, Aura_Worker_Door_Holds::claimed_binding( $ref ) );
		$this->assertSame( $binding, Aura_Worker_Door_Holds::get_claimed( $ref )['binding'] );
		$this->assertArrayNotHasKey( 'epoch', Aura_Worker_Door_Holds::get_claimed( $ref ), 'the log epoch is not the fence' );
		// …and it STAYS on the row all the way back to the queue (Ruling P58):
		// the hold carries its own binding from the moment it was queued.
		Aura_Worker_Door_Holds::unclaim( $ref );
		$this->assertSame( $binding, Aura_Worker_Door_Holds::get_held( $ref )['binding'] );
		$this->assertNull( Aura_Worker_Door_Holds::claimed_binding( $ref ), 'and a ref with no claimed row has none' );
	}

	/** The binding generation is minted once and survives, exactly like the epoch. */
	public function test_the_binding_generation_is_minted_once_and_is_not_the_epoch(): void {
		$a = Aura_Worker_Door_Log::binding();
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $a );
		$this->assertSame( $a, Aura_Worker_Door_Log::binding() );
		$this->assertNotSame( Aura_Worker_Door_Log::epoch(), $a, 'two independent generations' );
	}

	public function test_unclaim_keeps_the_claimed_row_when_the_held_name_is_taken(): void {
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		Aura_Worker_Door_Holds::claim( $ref );
		// Something already put a row back under this ref (or the insert
		// simply fails): the claimed row must stand, because it is the only
		// record of the attempt.
		$GLOBALS['_sa_insert_unique_fail'] = true;

		$this->assertFalse( Aura_Worker_Door_Holds::unclaim( $ref ) );
		$this->assertNotNull( Aura_Worker_Door_Holds::get_claimed( $ref ) );
		$this->assertNull( Aura_Worker_Door_Holds::get_held( $ref ) );
	}

	public function test_unclaim_of_a_ref_with_no_claimed_row_is_false(): void {
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		$this->assertFalse( Aura_Worker_Door_Holds::unclaim( $ref ) );
		$this->assertFalse( Aura_Worker_Door_Holds::unclaim( 'door_nope' ) );
		$this->assertNotNull( Aura_Worker_Door_Holds::get_held( $ref ), 'the held row is untouched' );
	}

	public function test_reject_refuses_to_delete_a_held_row_that_has_a_claimed_twin(): void {
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		$GLOBALS['_options'][ 'aura_worker_door_claimed_' . $ref ] = $GLOBALS['_options'][ 'aura_worker_door_held_' . $ref ]; // an orphaned pair
		$this->assertSame( 'already_claimed', Aura_Worker_Door_Holds::reject( $ref ) );
		$this->assertArrayHasKey( 'aura_worker_door_held_' . $ref, $GLOBALS['_options'] );
		$this->assertSame( 'not_held', Aura_Worker_Door_Holds::reject( 'door_nope' ) );
	}

	public function test_refresh_rule_never_recreates_a_deleted_row(): void {
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		$this->assertTrue( Aura_Worker_Door_Holds::refresh_rule( $ref, array( 'key' => 'rule/w', 'ruleHash' => 'h', 'reason' => 'r' ) ) );
		$this->assertSame( 'rule/w', $GLOBALS['_options'][ 'aura_worker_door_held_' . $ref ]['rule']['key'] );
		Aura_Worker_Door_Holds::reject( $ref );
		$this->assertFalse( Aura_Worker_Door_Holds::refresh_rule( $ref, array( 'key' => 'rule/w2', 'ruleHash' => 'h2', 'reason' => 'r' ) ) );
		$this->assertArrayNotHasKey( 'aura_worker_door_held_' . $ref, $GLOBALS['_options'] );
	}

	public function test_listing_hides_a_held_row_with_a_claimed_twin_and_never_carries_the_input(): void {
		$a = Aura_Worker_Door_Holds::hold( $this->call() );
		$b = Aura_Worker_Door_Holds::hold( $this->call( array( 'verdict' => 'warn', 'rule' => array( 'key' => 'rule/w', 'ruleHash' => 'h', 'reason' => 'careful' ) ) ) );
		Aura_Worker_Door_Holds::claim( $a );
		$GLOBALS['_options'][ 'aura_worker_door_held_' . $a ] = $GLOBALS['_options'][ 'aura_worker_door_claimed_' . $a ]; // orphan pair for a
		$list = Aura_Worker_Door_Holds::listing();
		$this->assertSame( array( $b ), array_column( $list, 'ref' ) );
		$this->assertArrayNotHasKey( 'input', $list[0] );
		$this->assertSame( 'warn', $list[0]['verdict'] );
		$this->assertSame( 'careful', $list[0]['rule']['reason'] );
	}

	public function test_listing_excludes_an_expired_but_unclaimed_hold(): void {
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		// Same $_rows caveat as sweep()'s test below: rows() reads the database.
		$GLOBALS['_options'][ 'aura_worker_door_held_' . $ref ]['expires_at'] = gmdate( 'c', time() - 10 );
		$GLOBALS['_rows'][ 'aura_worker_door_held_' . $ref ] = maybe_serialize( $GLOBALS['_options'][ 'aura_worker_door_held_' . $ref ] );
		$this->assertSame( array(), Aura_Worker_Door_Holds::listing() );
	}

	public function test_sweep_expires_holds_and_removes_orphaned_held_twins(): void {
		$a = Aura_Worker_Door_Holds::hold( $this->call() );
		$b = Aura_Worker_Door_Holds::hold( $this->call() );
		// rows() reads the DATABASE ($_rows), not this request's option cache —
		// a mutation seeded only into $_options would be invisible to it, so
		// both are written here (the same reason DoorLogTest::backdate() does).
		$GLOBALS['_options'][ 'aura_worker_door_held_' . $a ]['expires_at'] = gmdate( 'c', time() - 10 );
		$GLOBALS['_rows'][ 'aura_worker_door_held_' . $a ] = maybe_serialize( $GLOBALS['_options'][ 'aura_worker_door_held_' . $a ] );
		Aura_Worker_Door_Holds::claim( $b );
		$this->reseedHeldTwin( $b );
		// …and the claim is OLD, which is the case the sweep owns: a claim
		// still inside CLAIM_STALE_MS is a replay mid-move (see below).
		$this->backdateClaim( $b, self::CLAIM_STALE_MS_S + 60 );
		Aura_Worker_Door_Holds::sweep( time(), self::CLAIM_STALE_MS );
		$this->assertArrayNotHasKey( 'aura_worker_door_held_' . $a, $GLOBALS['_options'] );
		$this->assertArrayNotHasKey( 'aura_worker_door_held_' . $b, $GLOBALS['_options'] );
		$this->assertArrayHasKey( 'aura_worker_door_claimed_' . $b, $GLOBALS['_options'] );
	}

	public function test_sweep_leaves_a_held_row_whose_claimed_twin_is_still_in_flight(): void {
		// claim() inserts the claimed twin and THEN deletes the held row. A
		// sweep landing in that window used to delete the held row itself;
		// claim()'s own delete then removed 0 rows, read that as "a reject won"
		// and backed out by deleting the claimed twin — both rows gone, the
		// operator's approval lost for ever, `not_held` on every later replay.
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		Aura_Worker_Door_Holds::claim( $ref );
		$this->reseedHeldTwin( $ref ); // the window: both rows exist

		$gone = Aura_Worker_Door_Holds::sweep( time(), self::CLAIM_STALE_MS );

		$this->assertSame( 0, $gone );
		$this->assertArrayHasKey( 'aura_worker_door_held_' . $ref, $GLOBALS['_rows'], 'a fresh claim is a replay mid-move; its own delete is still coming' );
		$this->assertArrayHasKey( 'aura_worker_door_claimed_' . $ref, $GLOBALS['_rows'] );
	}

	/**
	 * Ruling P41: a sweep that meets an unclaim mid-move FINISHES it.
	 *
	 * unclaim() inserts the held row and only then deletes the claimed twin.
	 * A replay still going past CLAIM_STALE_MS makes that twin look stale, so
	 * a concurrent `/status` sweep saw the transient pair, applied claim()'s
	 * rule and deleted the hold that had just been restored. unclaim()'s own
	 * delete then succeeded, give_back() answered `retry_later` — and the ref
	 * was held by nothing at all. The operator's approval was gone.
	 */
	public function test_a_sweep_racing_an_unclaim_finishes_the_move_instead_of_deleting_the_hold(): void {
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		Aura_Worker_Door_Holds::claim( $ref );
		// The replay is still running well past the stale bound.
		$this->backdateClaim( $ref, self::CLAIM_STALE_MS_S + 60 );
		$swept = null;
		// The window: the sweep lands after unclaim()'s held INSERT and before
		// its claimed DELETE.
		$GLOBALS['_sa_after_insert_unique'][ Aura_Worker_Door_Holds::HELD . $ref ] = static function () use ( &$swept ) {
			// A racer, so it runs as if on its OWN connection (Ruling S8) —
			// sweep()'s own deletes each open their own versioned() unit,
			// which would nest inside unclaim()'s still-open one otherwise.
			sa_on_another_connection( function () use ( &$swept ) {
				$swept = Aura_Worker_Door_Holds::sweep( time(), self::CLAIM_STALE_MS );
			} );
		};

		$restored = Aura_Worker_Door_Holds::unclaim( $ref );

		$this->assertSame( 0, $swept, 'nothing was swept — a move was finished' );
		$this->assertFalse( $restored, 'unclaim reports what it can see: the sweep had already deleted its twin' );
		$held = Aura_Worker_Door_Holds::get_held( $ref );
		$this->assertIsArray( $held, 'and the hold is BACK, which is what a retry depends on' );
		$this->assertArrayHasKey( 'restored_at', $held );
		$this->assertNull( Aura_Worker_Door_Holds::get_claimed( $ref ), 'the claimed row is gone, deleted by whichever side got there first' );
		// The approval was not spent: the ref can be claimed again.
		$this->assertIsArray( Aura_Worker_Door_Holds::claim( $ref ) );
	}

	/**
	 * The other half of that comparison: a held row restored BEFORE the claim
	 * beside it is claim()'s move, not an unclaim's, so today's rule stands.
	 */
	public function test_a_stale_claim_taken_after_a_restore_still_sweeps_the_held_row(): void {
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		Aura_Worker_Door_Holds::claim( $ref );
		$this->assertTrue( Aura_Worker_Door_Holds::unclaim( $ref ) ); // stamps restored_at
		Aura_Worker_Door_Holds::claim( $ref );                        // …and it is claimed again
		$this->reseedHeldTwin( $ref );                                // claim()'s own window
		$this->patchRow( 'aura_worker_door_held_' . $ref, array( 'restored_at' => gmdate( 'c', time() - 3600 ) ) );
		$this->backdateClaim( $ref, self::CLAIM_STALE_MS_S + 60 );

		$this->assertSame( 1, Aura_Worker_Door_Holds::sweep( time(), self::CLAIM_STALE_MS ), 'the restore is older than the claim: this is claim()\'s move' );
		$this->assertArrayNotHasKey( 'aura_worker_door_held_' . $ref, $GLOBALS['_rows'] );
		$this->assertArrayHasKey( 'aura_worker_door_claimed_' . $ref, $GLOBALS['_rows'] );
	}

	public function test_sweep_still_removes_a_held_twin_whose_claim_is_unstamped(): void {
		// A stamp that cannot be read is not evidence of freshness — the same
		// rule the creation mutex is cleared by.
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		Aura_Worker_Door_Holds::claim( $ref );
		$this->reseedHeldTwin( $ref );
		$this->patchRow( 'aura_worker_door_claimed_' . $ref, array( 'claimed_at' => '' ) );

		$this->assertSame( 1, Aura_Worker_Door_Holds::sweep( time(), self::CLAIM_STALE_MS ) );
		$this->assertArrayNotHasKey( 'aura_worker_door_held_' . $ref, $GLOBALS['_rows'] );
	}

	/**
	 * listing() hides an expired hold and sweep() deletes it — but both run
	 * on a `/status` poll, and an unpolled site can sit past a hold's seven
	 * days with the row still there. A ref an approver kept must not still
	 * execute (Ruling P18).
	 */
	public function test_get_held_answers_null_for_an_expired_row_and_removes_it(): void {
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		$this->patchRow( 'aura_worker_door_held_' . $ref, array( 'expires_at' => gmdate( 'c', time() - 1 ) ) );

		$this->assertNull( Aura_Worker_Door_Holds::get_held( $ref ) );
		$this->assertArrayNotHasKey( 'aura_worker_door_held_' . $ref, $GLOBALS['_rows'], 'and it is gone, exactly as the sweep would have left it' );
	}

	public function test_get_held_leaves_an_expired_row_whose_claimed_twin_is_in_flight(): void {
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		Aura_Worker_Door_Holds::claim( $ref );
		$this->reseedHeldTwin( $ref );
		$this->patchRow( 'aura_worker_door_held_' . $ref, array( 'expires_at' => gmdate( 'c', time() - 1 ) ) );

		$this->assertNull( Aura_Worker_Door_Holds::get_held( $ref ) );
		$this->assertArrayHasKey( 'aura_worker_door_held_' . $ref, $GLOBALS['_rows'], 'the claim owns this ref; the sweep decides that row, not a reader' );
	}

	public function test_claim_refuses_an_expired_row_and_creates_no_twin(): void {
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		$this->patchRow( 'aura_worker_door_held_' . $ref, array( 'expires_at' => gmdate( 'c', time() - 1 ) ) );

		$out = Aura_Worker_Door_Holds::claim( $ref );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'not_held', $out->get_error_code() );
		$this->assertArrayNotHasKey( 'aura_worker_door_claimed_' . $ref, $GLOBALS['_rows'], 'nothing was claimed' );
	}

	public function test_a_hold_that_expires_a_second_from_now_is_still_held(): void {
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		$this->patchRow( 'aura_worker_door_held_' . $ref, array( 'expires_at' => gmdate( 'c', time() + 1 ) ) );

		$this->assertNotNull( Aura_Worker_Door_Holds::get_held( $ref ) );
		$this->assertIsArray( Aura_Worker_Door_Holds::claim( $ref ) );
	}

	/** Hold $n calls and expire every one of them, without sweeping. */
	private function expiredHolds( int $n ): array {
		$refs = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$refs[] = $ref = Aura_Worker_Door_Holds::hold( $this->call() );
			$this->patchRow( 'aura_worker_door_held_' . $ref, array( 'expires_at' => gmdate( 'c', time() - 10 ) ) );
		}
		return $refs;
	}

	/**
	 * The cap counted rows the queue no longer honours: listing() hides an
	 * expired hold and get_held() refuses it, but count() still charged a
	 * slot for it. On a site whose `/status` is not being polled, fifty
	 * expired approvals closed the door to every new Elementor write.
	 */
	public function test_expired_holds_do_not_fill_the_queue_and_are_purged_under_the_lock(): void {
		$refs = $this->expiredHolds( Aura_Worker_Door_Holds::CAP );

		$ref = Aura_Worker_Door_Holds::hold( $this->call() );

		$this->assertIsString( $ref, 'the queue was full of nothing' );
		foreach ( $refs as $dead ) {
			$this->assertArrayNotHasKey( 'aura_worker_door_held_' . $dead, $GLOBALS['_rows'], 'the expired rows are gone' );
		}
		$this->assertSame( 1, Aura_Worker_Door_Holds::count() );
	}

	public function test_one_expired_row_beside_a_full_house_of_live_ones_admits_one_more(): void {
		for ( $i = 0; $i < Aura_Worker_Door_Holds::CAP - 1; $i++ ) {
			Aura_Worker_Door_Holds::hold( $this->call() );
		}
		$this->expiredHolds( 1 );

		$this->assertIsString( Aura_Worker_Door_Holds::hold( $this->call() ) );
		$this->assertSame( Aura_Worker_Door_Holds::CAP, Aura_Worker_Door_Holds::count() );
	}

	public function test_a_full_house_of_LIVE_holds_still_refuses(): void {
		for ( $i = 0; $i < Aura_Worker_Door_Holds::CAP; $i++ ) {
			Aura_Worker_Door_Holds::hold( $this->call() );
		}

		$out = Aura_Worker_Door_Holds::hold( $this->call() );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aura_hold_queue_full', $out->get_error_code() );
	}

	/**
	 * A claim in flight still holds its slot, expired or not: the row is the
	 * replay's, and the sweep — not the cap check — decides when it goes.
	 */
	public function test_an_expired_held_row_with_a_claimed_twin_keeps_its_slot(): void {
		$ref = Aura_Worker_Door_Holds::hold( $this->call() );
		Aura_Worker_Door_Holds::claim( $ref );
		$this->reseedHeldTwin( $ref );
		$this->patchRow( 'aura_worker_door_held_' . $ref, array( 'expires_at' => gmdate( 'c', time() - 10 ) ) );

		$this->assertSame( 1, Aura_Worker_Door_Holds::count(), 'the claimed ref still counts' );
		Aura_Worker_Door_Holds::hold( $this->call() );
		$this->assertArrayHasKey( 'aura_worker_door_held_' . $ref, $GLOBALS['_rows'], 'and its held twin was not purged out from under the replay' );
	}

	/**
	 * Ruling S6 (Codex round-3 P1 on #88): `forget_held()` is the choke point
	 * that bumps the door version for every held/claimed write on this
	 * class's side — `hold()`, `claim()`, `unclaim()`, `release()`,
	 * `reject()`, `refresh_rule()`, `refresh_touches()`, `stamp_terminal_seq()`
	 * and the sweep — because every one of them already calls it uniformly.
	 * Proven here across the write shapes that reach it by the most
	 * different routes: a fresh insert (`hold()`), a CAS row update
	 * (`refresh_rule()`), and a raw, fenced delete (`release()`).
	 */
	public function test_hold_refresh_rule_and_release_each_bump_the_door_version(): void {
		$before = Aura_Worker_Door_Log::door_version_raw();
		$ref    = Aura_Worker_Door_Holds::hold( $this->call() );
		$after_hold = Aura_Worker_Door_Log::door_version_raw();
		$this->assertIsInt( $after_hold );
		$this->assertNotSame( $before, $after_hold, 'a fresh insert (hold()) bumps' );

		Aura_Worker_Door_Holds::refresh_rule( $ref, array( 'key' => 'r', 'state' => 'blocked' ) );
		$after_refresh = Aura_Worker_Door_Log::door_version_raw();
		$this->assertGreaterThan( $after_hold, $after_refresh, 'a CAS row update (refresh_rule()) bumps too' );

		Aura_Worker_Door_Holds::release( $ref );
		$after_release = Aura_Worker_Door_Log::door_version_raw();
		$this->assertGreaterThan( $after_refresh, $after_release, 'and a raw, fenced delete (release()) bumps as well — none of the three go through write_option_where()/insert_unique() alone' );
	}

	/**
	 * The internal hold-queue LOCK mutex is not door state Aura ever sees —
	 * `insert_unique()`'s exemption list (Ruling S6) must not bump for its
	 * acquisition. Tested directly on the primitive rather than through
	 * `hold()`, which itself performs SEVERAL real mutations for one call
	 * (an epoch mint, a binding mint, the held row's own insert — each
	 * legitimately bumps on its own, so counting hold()'s total bumps could
	 * never isolate the lock's contribution from theirs).
	 */
	public function test_insert_unique_on_the_hold_lock_name_does_not_bump_the_door_version(): void {
		$before = Aura_Worker_Door_Log::door_version_raw();
		Aura_Worker_Door_Log::insert_unique( Aura_Worker_Door_Holds::LOCK, 'token' );
		$this->assertSame( $before, Aura_Worker_Door_Log::door_version_raw(), 'the internal mutex is not reported door state, so acquiring it must not bump' );

		// The exemption is scoped to that one name — an ordinary insert right
		// after it still bumps, proving the primitive itself is not disabled.
		Aura_Worker_Door_Log::insert_unique( 'aura_worker_door_held_test-ref', array( 'x' => 1 ) );
		$this->assertNotSame( $before, Aura_Worker_Door_Log::door_version_raw(), 'an ordinary door-prefixed insert still bumps' );
	}

	/**
	 * Ruling S8 (Codex round-4 P1 on #88): hold()'s state write and its
	 * version bump run in ONE transaction — proven from the request's own
	 * statement log, the same way open_pending()/ack()/rotate_epoch() are in
	 * DoorLogTest.php. hold() itself can open SEVERAL transactions in one
	 * call (an epoch mint, a binding mint, the held row's own insert), so
	 * this finds the bump nearest the HELD row's own insert rather than
	 * assuming there is only one transaction in the log.
	 */
	public function test_hold_bumps_the_version_inside_its_own_transaction(): void {
		$GLOBALS['_db_queries'] = array();
		$ref                    = Aura_Worker_Door_Holds::hold( $this->call() );
		$this->assertIsString( $ref );

		$log = $GLOBALS['_db_queries'];
		$held_insert = null;
		foreach ( $log as $i => $sql ) {
			if ( false !== strpos( (string) $sql, Aura_Worker_Door_Holds::HELD . $ref ) ) {
				$held_insert = $i;
				break;
			}
		}
		$this->assertNotNull( $held_insert, 'the held row was written at all' );
		// The bump nearest (at or after) the held row's own insert.
		$bump = null;
		for ( $i = $held_insert, $n = count( $log ); $i < $n; $i++ ) {
			if ( false !== strpos( (string) $log[ $i ], Aura_Worker_Door_Log::OBSERVATION ) ) {
				$bump = $i;
				break;
			}
		}
		$this->assertNotNull( $bump, 'the held insert bumped the version' );
		$start = null;
		for ( $i = $bump; $i >= 0; $i-- ) {
			if ( 'START TRANSACTION' === trim( (string) $log[ $i ] ) ) {
				$start = $i;
				break;
			}
		}
		$commit = null;
		for ( $i = $bump, $n = count( $log ); $i < $n; $i++ ) {
			if ( 'COMMIT' === trim( (string) $log[ $i ] ) ) {
				$commit = $i;
				break;
			}
		}
		$this->assertNotNull( $start, 'a transaction opened before the bump' );
		$this->assertNotNull( $commit, 'and closed with a COMMIT after it' );
		$this->assertLessThanOrEqual( $held_insert, $start, "the SAME transaction covers the held row's own insert" );
	}

	/** The reconciler's stale-claim bound, as the governor declares it. */
	private const CLAIM_STALE_MS   = 600000;
	private const CLAIM_STALE_MS_S = 600;

	/** Put the held row back beside its claimed twin — claim()'s own window. */
	private function reseedHeldTwin( string $ref ): void {
		$row = $GLOBALS['_options'][ 'aura_worker_door_claimed_' . $ref ];
		$GLOBALS['_options'][ 'aura_worker_door_held_' . $ref ] = $row;
		$GLOBALS['_rows'][ 'aura_worker_door_held_' . $ref ]    = maybe_serialize( $row );
		unset( $GLOBALS['_notoptions'][ 'aura_worker_door_held_' . $ref ] );
	}

	private function backdateClaim( string $ref, int $seconds_ago ): void {
		$this->patchRow( 'aura_worker_door_claimed_' . $ref, array( 'claimed_at' => gmdate( 'c', time() - $seconds_ago ) ) );
	}

	/** Merge fields into a row, in the "database" ($_rows) and the cache alike. */
	private function patchRow( string $name, array $fields ): void {
		$row                          = array_merge( (array) $GLOBALS['_options'][ $name ], $fields );
		$GLOBALS['_options'][ $name ] = $row;
		$GLOBALS['_rows'][ $name ]    = maybe_serialize( $row );
	}
}
