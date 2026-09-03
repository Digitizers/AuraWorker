<?php
/**
 * Creations through the door: the insert observer, the per-site mutex, the
 * wp_posts watermark, compensation when the envelope cannot be stored, and
 * the collateral captured before Elementor deletes a global class from the
 * pages that used it (spec §3.6–§3.8).
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class ElementorDoorCreationTest extends TestCase {

	/** @var array<string,int> how many times each inner callback ran */
	private $ran = array();

	protected function setUp(): void {
		sa_reset_state();
		$this->rrmdir( WP_CONTENT_DIR );
		mkdir( WP_CONTENT_DIR, 0755, true );
		foreach ( array( 'class-aura-worker-door-log', 'class-aura-worker-door-holds', 'class-elementor-door-governor' ) as $f ) {
			require_once dirname( __DIR__, 2 ) . '/digitizer-site-worker/includes/' . $f . '.php';
		}
		Aura_Worker_Elementor_Door::reset_for_tests();
		Aura_Worker_Elementor_Door::init();
		$GLOBALS['_current_user_id'] = 3;
		$GLOBALS['_user_logins'][3]  = 'bot';
		$this->ran                   = array();
	}

	protected function tearDown(): void {
		$dir = WP_CONTENT_DIR . '/aura-backups/snapshots';
		if ( is_dir( $dir ) ) {
			@chmod( $dir, 0777 );
		}
		$this->rrmdir( WP_CONTENT_DIR );
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( array_diff( scandir( $dir ), array( '.', '..' ) ) as $item ) {
			$path = $dir . '/' . $item;
			is_dir( $path ) ? $this->rrmdir( $path ) : @unlink( $path );
		}
		@rmdir( $dir );
	}

	/* ------------------------------------------------------------------ */
	/* Fixtures                                                            */
	/* ------------------------------------------------------------------ */

	/** Register one governed ability whose inner callback is $inner. */
	private function register( string $slug, callable $inner ): void {
		$ran   = &$this->ran;
		$outer = static function ( $input ) use ( &$ran, $slug, $inner ) {
			$ran[ $slug ] = ( $ran[ $slug ] ?? 0 ) + 1;
			return $inner( $input );
		};
		sa_register_ability(
			$slug,
			array(
				'execute_callback'    => $outer,
				'permission_callback' => '__return_true',
			)
		);
		do_action( 'wp_abilities_api_init' );
	}

	/** The stored ruleset record, written straight to the option. */
	private function installRuleset( array $rules ): void {
		$GLOBALS['_options'][ Aura_Worker_Rules::OPTION ] = array(
			'envelope'    => 'x.y',
			'seq'         => 5,
			'issued_at'   => '2026-09-02T00:00:00Z',
			'received_at' => time(),
			'rules'       => $rules,
		);
	}

	/** An `allow` rule over every creation. */
	private function allowCreations(): void {
		$this->installRuleset(
			array(
				array( 'key' => 'rule/c', 'effect' => 'allow', 'target' => array( 'type' => 'page_create' ), 'reason' => 'ok' ),
				array( 'key' => 'rule/d', 'effect' => 'allow', 'target' => array( 'type' => 'design_system' ), 'reason' => 'ok' ),
			)
		);
	}

	/** Run a `create-page` whose inner callback is $inner. */
	private function createPage( callable $inner, array $input = array() ) {
		$this->allowCreations();
		$this->register( 'elementor/create-page', $inner );
		return wp_get_ability( 'elementor/create-page' )->execute( $input );
	}

	/** A post that was already there, with every field a capture reads. */
	private function seedPost( int $id, string $type = 'page', int $author = 3 ): void {
		$GLOBALS['_posts'][ $id ] = (object) array(
			'ID'             => $id,
			'post_type'      => $type,
			'post_status'    => 'publish',
			'post_title'     => 'p-' . $id,
			'post_name'      => 'p-' . $id,
			'post_parent'    => 0,
			'post_content'   => '',
			'post_excerpt'   => '',
			'menu_order'     => 0,
			'post_author'    => $author,
			'post_date'      => '2026-01-02 03:04:05',
			'post_date_gmt'  => '2026-01-02 03:04:05',
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		);
	}

	/** A page insert attributed to the actor, the way Elementor's would be. */
	private function insertPage( string $type = 'page' ): int {
		return wp_insert_post( array( 'post_type' => $type, 'post_title' => 'made', 'post_author' => 3 ) );
	}

	private function row( int $seq ): array {
		$row = Aura_Worker_Door_Log::get( $seq );
		$this->assertIsArray( $row, "door log row {$seq} is missing" );
		return $row;
	}

	/** The rolling counter bucket bump_counter() writes. */
	private function counter( string $name ): int {
		return (int) get_option( 'aura_worker_door_c_' . $name . '_h' . (int) floor( time() / HOUR_IN_SECONDS ), 0 );
	}

	private function envelopes(): array {
		$files = glob( WP_CONTENT_DIR . '/aura-backups/snapshots/snap_*.json' );
		return $files ? $files : array();
	}

	/** Every stored `creation` envelope, decoded. */
	private function creationEnvelopes(): array {
		$out = array();
		foreach ( $this->envelopes() as $path ) {
			$rec = json_decode( file_get_contents( $path ), true );
			if ( is_array( $rec ) && 'creation' === ( $rec['door_kind'] ?? '' ) ) {
				$out[] = $rec;
			}
		}
		return $out;
	}

	private function envelope( string $id ): array {
		$snaps = new Aura_Worker_Snapshots();
		$rec   = $snaps->get( $id );
		$this->assertIsArray( $rec, "envelope {$id} is missing" );
		return $rec;
	}

	/**
	 * Run $fn with the snapshot store unwritable, so every persist() fails.
	 *
	 * The E_WARNING file_put_contents() raises on the way is the DISK failing,
	 * not a defect under test; it is silenced for the duration so the suite's
	 * output stays pristine.
	 */
	private function withUnwritableSnapshots( callable $fn ) {
		$dir = WP_CONTENT_DIR . '/aura-backups/snapshots';
		if ( ! is_dir( $dir ) ) {
			new Aura_Worker_Snapshots(); // the constructor creates it
		}
		chmod( $dir, 0555 );
		if ( false !== @file_put_contents( $dir . '/probe', 'x' ) ) {
			@unlink( $dir . '/probe' );
			chmod( $dir, 0777 );
			$this->markTestSkipped( 'filesystem does not enforce the mode (running as root?)' );
		}
		set_error_handler( static function () { return true; }, E_WARNING );
		try {
			return $fn();
		} finally {
			restore_error_handler();
			chmod( $dir, 0777 );
		}
	}

	/* ------------------------------------------------------------------ */
	/* (a) the hook records the id BEFORE the callback returns             */
	/* ------------------------------------------------------------------ */

	public function test_a_creation_records_its_id_on_the_row_before_the_callback_returns(): void {
		$seen = array();
		// Priority 2: after the observer at 1, still inside wp_insert_post().
		add_action(
			'wp_insert_post',
			static function ( $id ) use ( &$seen ) {
				$row    = Aura_Worker_Door_Log::get( 1 );
				$seen[] = array( 'ids' => $row['created_post_ids'] ?? null, 'mark' => $row['post_watermark'] ?? null );
			},
			2,
			3
		);

		$made = 0;
		$out  = $this->createPage(
			function () use ( &$made ) {
				$made = $this->insertPage();
				return array( 'id' => $made );
			}
		);

		$this->assertSame( array( 'id' => $made ), $out );
		$this->assertSame( array( $made ), $seen[0]['ids'], 'the id is durable on the row before wp_insert_post() returns' );
		$this->assertIsInt( $seen[0]['mark'] );
		$this->assertLessThan( $made, $seen[0]['mark'], 'the watermark predates the insert' );

		$row = $this->row( 1 );
		$this->assertSame( 'ok', $row['result'] );
		$this->assertSame( array( $made ), $row['created_post_ids'] );
		$this->assertSame( array( 'page' ), $row['expected_types'] );
		$this->assertArrayNotHasKey( 'hook_missed', $row );
		$this->assertArrayNotHasKey( 'unattributed_result', $row );

		$env = $this->envelope( (string) $row['snapshot_id'] );
		$this->assertSame( 'creation', $env['door_kind'] );
		$this->assertSame( array( $made ), $env['created_post_ids'] );
		$this->assertSame( 'page', $env['post_type'] );
		$this->assertSame( 1, $env['door']['seq'] );
		$this->assertSame( 'elementor/create-page', $env['door']['ability'] );

		// (c) the mutex is released once the entry is terminal.
		$this->assertFalse( get_option( Aura_Worker_Elementor_Door::CREATING, false ) );
		$this->assertSame( 0, $this->counter( 'unobserved' ) );
	}

	/* ------------------------------------------------------------------ */
	/* (b) a second creation while the mutex is held                       */
	/* ------------------------------------------------------------------ */

	public function test_a_second_creation_while_the_mutex_is_held_is_refused_and_writes_nothing(): void {
		// The mutex an unfinished creation left behind.
		add_option( Aura_Worker_Elementor_Door::CREATING, array( 'seq' => 99, 'started_at' => gmdate( 'c' ) ) );

		$out = $this->createPage( function () { return array( 'id' => $this->insertPage() ); } );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aura_creation_busy', $out->get_error_code() );
		$this->assertSame( 503, $out->get_error_data()['status'] );
		$this->assertSame( 30, $out->get_error_data()['retry_after'] );
		$this->assertArrayNotHasKey( 'elementor/create-page', $this->ran, 'Elementor never ran' );
		$this->assertSame( array(), $GLOBALS['_posts'], 'nothing was created' );
		$this->assertSame( array(), $this->envelopes(), 'nothing was captured' );

		$row = $this->row( 1 );
		$this->assertSame( 'refused', $row['result'] );
		$this->assertSame( 'creation_busy', $row['reason'] );
		$this->assertArrayNotHasKey( 'post_watermark', $row, 'no watermark was stamped' );
		// The holder's own row is untouched — the refusal never released it.
		$this->assertSame( 99, get_option( Aura_Worker_Elementor_Door::CREATING )['seq'] );
	}

	/**
	 * Ruling P5: the mutex is a conditional INSERT, not add_option().
	 *
	 * The racer takes the mutex between this caller's existence test and its
	 * write — the window core's add_option() leaves open, because its check and
	 * its `INSERT … ON DUPLICATE KEY UPDATE` are two statements and the second
	 * one overwrites. A caller that used add_option() would "take" a mutex
	 * another creation already holds, and both would run.
	 */
	public function test_a_racer_that_takes_the_mutex_first_leaves_this_call_busy(): void {
		$GLOBALS['_sa_before_swap'] = static function () {
			// Only inside the mutex's OWN statement — every other door-log
			// insert of this request passes through the same seam.
			if ( false === strpos( (string) $GLOBALS['wpdb']->last_query, Aura_Worker_Elementor_Door::CREATING ) ) {
				return;
			}
			$GLOBALS['_options'][ Aura_Worker_Elementor_Door::CREATING ] = array( 'seq' => 99, 'started_at' => gmdate( 'c' ) );
			$GLOBALS['_rows'][ Aura_Worker_Elementor_Door::CREATING ]    = maybe_serialize( $GLOBALS['_options'][ Aura_Worker_Elementor_Door::CREATING ] );
		};

		$out = $this->createPage( function () { return array( 'id' => $this->insertPage() ); } );

		$this->assertSame( 'aura_creation_busy', $out->get_error_code() );
		$this->assertArrayNotHasKey( 'elementor/create-page', $this->ran );
		$this->assertSame( 99, get_option( Aura_Worker_Elementor_Door::CREATING )['seq'], "the racer's mutex was never overwritten" );
	}

	/**
	 * Ruling P17: the release is a DELETE FENCED on the bytes this request
	 * inserted, never an unconditional delete_option().
	 *
	 * A creation still running past CLAIM_STALE_MS has its mutex cleared by
	 * the reconciler, and a second creation takes a replacement row. This
	 * request's `mutex_held` is still set, so an unconditional delete on its
	 * way out removes the SECOND request's mutex — and a third creation then
	 * runs beside it.
	 */
	public function test_a_release_never_deletes_a_mutex_another_request_took(): void {
		$replacement = array( 'seq' => 99, 'started_at' => gmdate( 'c' ) );
		$this->createPage(
			function () use ( $replacement ) {
				// The reconciler cleared this call's stale mutex, and another
				// request's creation took the row.
				$GLOBALS['_options'][ Aura_Worker_Elementor_Door::CREATING ] = $replacement;
				$GLOBALS['_rows'][ Aura_Worker_Elementor_Door::CREATING ]    = maybe_serialize( $replacement );
				return array( 'id' => $this->insertPage() );
			}
		);

		$this->assertSame( $replacement, get_option( Aura_Worker_Elementor_Door::CREATING, null ), 'the second creation still owns the site' );
		$this->assertSame( maybe_serialize( $replacement ), $GLOBALS['_rows'][ Aura_Worker_Elementor_Door::CREATING ] ?? null );
	}

	/** And the ordinary case is unchanged: a call releases its own row. */
	public function test_a_release_clears_the_row_this_request_inserted(): void {
		$this->createPage( function () { return array( 'id' => $this->insertPage() ); } );

		$this->assertFalse( get_option( Aura_Worker_Elementor_Door::CREATING, false ) );
		$this->assertArrayNotHasKey( Aura_Worker_Elementor_Door::CREATING, $GLOBALS['_rows'] );
	}

	/**
	 * A throw BEFORE the watermark was stamped: the diff has no mark to
	 * compare against, so it makes no claim at all. Treating a missing
	 * watermark as 0 would attribute every page this user ever made to the
	 * call — and trash them all when the envelope could not be stored.
	 */
	public function test_a_throw_before_the_watermark_attributes_nothing(): void {
		$this->seedPost( 60 ); // this user's earlier work
		$this->seedPost( 61 );
		$GLOBALS['_sa_before_swap'] = static function () {
			if ( false !== strpos( (string) $GLOBALS['wpdb']->last_query, Aura_Worker_Elementor_Door::CREATING ) ) {
				throw new RuntimeException( 'the database went away' );
			}
		};

		$out = $this->withUnwritableSnapshots(
			function () {
				return $this->createPage( function () { return array( 'ok' => true ); } );
			}
		);

		$this->assertSame( 'aura_governor_error', $out->get_error_code() );
		$row = $this->row( 1 );
		$this->assertSame( array(), $row['created_post_ids'] );
		$this->assertArrayNotHasKey( 'compensated', $row );
		$this->assertSame( 'publish', get_post( 60 )->post_status, 'nothing of this user\'s was touched' );
		$this->assertSame( 'publish', get_post( 61 )->post_status );
		$this->assertFalse( get_option( Aura_Worker_Elementor_Door::CREATING, false ) );
	}

	/* ------------------------------------------------------------------ */
	/* (d) updates and unexpected types                                    */
	/* ------------------------------------------------------------------ */

	public function test_an_update_is_not_recorded_and_an_unexpected_type_is_other_inserts(): void {
		$this->seedPost( 7 );

		$made = array();
		$this->createPage(
			function () use ( &$made ) {
				wp_update_post( array( 'ID' => 7, 'post_status' => 'publish' ) ); // $update === true
				$made['page']  = $this->insertPage();
				$made['other'] = $this->insertPage( 'attachment' );
				return array( 'id' => $made['page'] );
			}
		);

		$row = $this->row( 1 );
		$this->assertSame( array( $made['page'] ), $row['created_post_ids'], 'an update is not a creation' );
		$this->assertSame( array( $made['other'] ), $row['other_inserts'] );
		$this->assertNotContains( 7, $row['created_post_ids'] );
	}

	/* ------------------------------------------------------------------ */
	/* (e) the watermark catches what the hook missed                      */
	/* ------------------------------------------------------------------ */

	/**
	 * A post above the watermark the hook never saw is EVIDENCE, not part of
	 * the creation (Ruling P11). The watermark cannot tell this call's own
	 * unhooked insert from a concurrent request's — same user, same type,
	 * same window — so it is recorded and left alone: out of the envelope's
	 * `created_post_ids`, which is the set a restore trashes.
	 */
	public function test_a_concurrent_insert_the_hook_never_saw_is_evidence_not_part_of_the_creation(): void {
		$hooked = 0;
		$quiet  = 0;
		$this->createPage(
			function () use ( &$hooked, &$quiet ) {
				$hooked = $this->insertPage();
				// ANOTHER request's insert, landing above this call's
				// watermark: seeded straight into the store, so no insert
				// hook fires and nothing but the diff ever sees it.
				$quiet = $hooked + 5000;
				$this->seedPost( $quiet );
				return array( 'id' => $hooked );
			}
		);

		$row = $this->row( 1 );
		$this->assertSame( array( $hooked ), $row['created_post_ids'], 'only the hooked id is this call\'s' );
		$this->assertSame( array( $quiet ), $row['observed_by_watermark'] );
		$this->assertSame( array( $quiet ), $row['unproven'] );
		$this->assertSame( 1, $row['hook_missed'] );
		$this->assertSame( 1, $this->counter( 'hook_missed' ) );
		$this->assertSame( array( $hooked ), $this->envelope( (string) $row['snapshot_id'] )['created_post_ids'], 'a restore of this envelope trashes the hooked id alone' );
	}

	/**
	 * The same partition on the compensation path: a snapshot the store
	 * cannot take trashes what this call PROVABLY made, and never the post
	 * beside it.
	 */
	public function test_an_unproven_id_is_never_compensated(): void {
		$hooked = 0;
		$quiet  = 0;
		$out    = $this->withUnwritableSnapshots(
			function () use ( &$hooked, &$quiet ) {
				return $this->createPage(
					function () use ( &$hooked, &$quiet ) {
						$hooked = $this->insertPage();
						$quiet  = $hooked + 5000;
						$this->seedPost( $quiet );
						return array( 'id' => $hooked );
					}
				);
			}
		);

		$this->assertSame( 'aura_snapshot_failed', $out->get_error_code() );
		$row = $this->row( 1 );
		$this->assertSame( array( $hooked ), $row['created_post_ids'] );
		$this->assertSame( array( $quiet ), $row['unproven'] );
		$this->assertSame( array( $hooked ), $row['compensated'] );
		$this->assertSame( array(), $row['uncompensated'] );
		$this->assertSame( 'trash', get_post( $hooked )->post_status );
		$this->assertSame( 'publish', get_post( $quiet )->post_status, 'the post beside this call survives' );
	}

	/**
	 * The one way a diff-only id becomes PROVEN: the callback's own result
	 * names it. Two witnesses then agree it is this call's — the diff saw it,
	 * and the ability said it made it.
	 */
	public function test_an_id_the_result_names_that_the_diff_also_saw_is_proven(): void {
		$quiet = 0;
		$this->createPage(
			function () use ( &$quiet ) {
				$quiet = 9500; // above the watermark, no hook, but named below
				$this->seedPost( $quiet );
				return array( 'id' => $quiet );
			}
		);

		$row = $this->row( 1 );
		$this->assertSame( array( $quiet ), $row['created_post_ids'] );
		$this->assertArrayNotHasKey( 'unproven', $row );
		$this->assertArrayNotHasKey( 'unattributed_result', $row );
		$this->assertSame( array( $quiet ), $row['observed_by_watermark'], 'the hook still missed it, and the entry still says so' );
		$this->assertSame( array( $quiet ), $this->envelope( (string) $row['snapshot_id'] )['created_post_ids'] );
	}

	public function test_a_post_another_author_created_is_not_attributed_to_this_call(): void {
		$this->createPage(
			function () {
				$this->seedPost( 9001, 'page', 44 ); // another author's insert
				return array( 'ok' => true );
			}
		);

		$row = $this->row( 1 );
		$this->assertSame( array(), $row['created_post_ids'] );
		$this->assertSame( 1, $this->counter( 'unobserved' ), 'a success that created nothing observable is counted' );
	}

	/** The third counter of the trio, for completeness: an ability nobody mapped. */
	public function test_an_unmapped_ability_moves_the_unknown_ability_counter(): void {
		$this->allowCreations();
		$this->register( 'elementor/future-thing', static function () { return array( 'ok' => true ); } );
		wp_get_ability( 'elementor/future-thing' )->execute( array() );
		$this->assertSame( 1, $this->counter( 'unknown_ability' ) );
		$this->assertSame( 0, $this->counter( 'hook_missed' ) );
	}

	public function test_an_id_the_result_names_that_no_witness_saw_is_unattributed(): void {
		$this->createPage( function () { return array( 'id' => 4242 ); } );
		$this->assertSame( 4242, $this->row( 1 )['unattributed_result'] );
	}

	/* ------------------------------------------------------------------ */
	/* (e2) a created id the ROW cannot witness (Ruling P26)               */
	/* ------------------------------------------------------------------ */

	/** Fail ONLY observe_insert()'s own patch of the created ids. */
	private function failWitnessPatch(): void {
		$GLOBALS['_sa_option_cas_fail']['aura_worker_door_log_1'] = static function ( $value ) {
			$v = (string) $value;
			// Every write after the watermark stamp carries the field, so the
			// discriminator is a NON-EMPTY list on a row that is still
			// pending: the stamp writes `a:0:{}`, the terminal settle carries
			// `settled_at`.
			return false === strpos( $v, 's:16:"created_post_ids";a:0:{}' )
				&& false !== strpos( $v, 'created_post_ids' )
				&& false === strpos( $v, 'settled_at' );
		};
	}

	/**
	 * The post exists and the row cannot be told. Carrying on would leave the
	 * id in request memory alone: a timeout or fatal before finish_creation()
	 * then leaves the reconciler nothing but the watermark, which is UNPROVEN
	 * — so the post would get neither an envelope nor compensation. The
	 * creation is aborted HERE, while the hook still holds the id.
	 */
	public function test_a_created_id_the_row_cannot_witness_aborts_the_creation(): void {
		$this->failWitnessPatch();

		$out = $this->createPage(
			function () {
				$this->insertPage(); // throws inside the hook: nothing after this runs
				return array( 'ok' => true );
			}
		);

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aura_log_failed', $out->get_error_code() );
		$data = $out->get_error_data();
		$this->assertSame( 503, $data['status'] );
		$this->assertTrue( $data['may_have_run'] );
		$this->assertSame( 1, $data['seq'] );
		$this->assertCount( 1, $data['created_post_ids'] );
		$made = (int) $data['created_post_ids'][0];

		$row = $this->row( 1 );
		$this->assertSame( 'failed', $row['result'] );
		$this->assertSame( 'witness_unrecorded', $row['reason'] );
		$this->assertTrue( $row['may_have_run'] );
		$this->assertSame( array( $made ), $row['created_post_ids'], 'from the hook witness, not the diff' );

		$envelopes = $this->creationEnvelopes();
		$this->assertCount( 1, $envelopes, 'exactly one creation envelope' );
		$this->assertSame( array( $made ), $envelopes[0]['created_post_ids'] );
		$this->assertSame( $envelopes[0]['id'], $data['snapshot_id'] );
		$this->assertSame( 'draft', get_post( $made )->post_status, 'the post is left in place — it is restorable' );
		$this->assertFalse( get_option( Aura_Worker_Elementor_Door::CREATING, false ), 'the mutex is released' );
	}

	public function test_a_witness_that_cannot_be_recorded_compensates_when_the_envelope_cannot_be_stored_either(): void {
		$out = $this->withUnwritableSnapshots(
			function () {
				$this->failWitnessPatch();
				return $this->createPage(
					function () {
						$this->insertPage();
						return array( 'ok' => true );
					}
				);
			}
		);

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aura_log_failed', $out->get_error_code() );
		$this->assertTrue( $out->get_error_data()['may_have_run'] );

		$row  = $this->row( 1 );
		$this->assertSame( 'failed', $row['result'] );
		$this->assertCount( 1, $row['compensated'] );
		$made = (int) $row['compensated'][0];
		$this->assertSame( array(), $row['uncompensated'] );
		$this->assertSame( 'trash', get_post( $made )->post_status, 'nothing could make it restorable, so it was undone' );
		$this->assertFalse( get_option( Aura_Worker_Elementor_Door::CREATING, false ), 'the mutex is released' );
	}

	/**
	 * The row READ can fail too, and it fails before the id has been written
	 * anywhere. The witness is kept in memory FIRST, and an unreadable row is
	 * treated exactly like an unwritable one.
	 */
	public function test_a_row_read_that_fails_after_the_insert_aborts_like_a_failed_patch(): void {
		// The options-table read the observer (priority 1) is about to make
		// answers nothing — this request's cache holds a null for the name.
		// The row itself is still in the database, which is what every later
		// write goes through.
		$GLOBALS['_sa_option_cache']['aura_worker_door_log_1'] = null;

		$out = $this->createPage(
			function () {
				$this->insertPage();
				return array( 'ok' => true );
			}
		);

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aura_log_failed', $out->get_error_code() );
		$this->assertTrue( $out->get_error_data()['may_have_run'] );
		$this->assertCount( 1, $out->get_error_data()['created_post_ids'], 'the id the hook held in memory' );
		$made = (int) $out->get_error_data()['created_post_ids'][0];

		$envelopes = $this->creationEnvelopes();
		$this->assertCount( 1, $envelopes );
		$this->assertSame( array( $made ), $envelopes[0]['created_post_ids'], 'it is restorable' );
		unset( $GLOBALS['_sa_option_cache']['aura_worker_door_log_1'] ); // the transient is over
		$this->assertSame( 'witness_unrecorded', $this->row( 1 )['reason'] );
		$this->assertSame( array( $made ), $this->row( 1 )['created_post_ids'], 'the row learned it from finish_creation()' );
	}

	/** An `other_inserts` patch is advisory — it records nothing a rollback needs. */
	public function test_an_other_inserts_patch_that_fails_does_not_abort_the_creation(): void {
		$GLOBALS['_sa_option_cas_fail']['aura_worker_door_log_1'] = static function ( $value ) {
			return false !== strpos( (string) $value, 'other_inserts' );
		};

		$made = 0;
		$out  = $this->createPage(
			function () use ( &$made ) {
				wp_insert_post( array( 'post_type' => 'attachment', 'post_title' => 'side effect', 'post_author' => 3 ) );
				$made = $this->insertPage();
				return array( 'id' => $made );
			}
		);

		$this->assertSame( array( 'id' => $made ), $out );
		$row = $this->row( 1 );
		$this->assertSame( 'ok', $row['result'] );
		$this->assertSame( array( $made ), $row['created_post_ids'] );
	}

	/* ------------------------------------------------------------------ */
	/* (f) compensation                                                    */
	/* ------------------------------------------------------------------ */

	public function test_an_envelope_that_cannot_be_stored_trashes_what_was_created(): void {
		$made = 0;
		$out  = $this->withUnwritableSnapshots(
			function () use ( &$made ) {
				return $this->createPage(
					function () use ( &$made ) {
						$made = $this->insertPage();
						return array( 'id' => $made );
					}
				);
			}
		);

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aura_snapshot_failed', $out->get_error_code() );
		$this->assertSame( array(), $out->get_error_data()['uncompensated'] );
		$this->assertStringContainsString( 'the creation was undone', $out->get_error_message() );
		$this->assertSame( 'trash', get_post( $made )->post_status );

		$row = $this->row( 1 );
		$this->assertSame( 'failed', $row['result'] );
		$this->assertSame( 'snapshot_failed', $row['reason'] );
		$this->assertSame( array( $made ), $row['compensated'] );
		$this->assertSame( array(), $row['uncompensated'] );
		$this->assertSame( 'trash', $row['compensated_by'] );
		$this->assertFalse( get_option( Aura_Worker_Elementor_Door::CREATING, false ), 'the mutex is released' );
	}

	public function test_a_callback_that_inserts_and_then_reports_failure_still_gets_an_envelope(): void {
		$made = 0;
		$out  = $this->createPage(
			function () use ( &$made ) {
				$made = $this->insertPage();
				return new WP_Error( 'elementor_boom', 'no' );
			}
		);

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'elementor_boom', $out->get_error_code() );
		$row = $this->row( 1 );
		$this->assertSame( 'failed', $row['result'] );
		$this->assertSame( array( $made ), $row['created_post_ids'] );
		$this->assertSame( array( $made ), $this->envelope( (string) $row['snapshot_id'] )['created_post_ids'] );
		$this->assertSame( 'draft', get_post( $made )->post_status, 'a recorded creation is not undone' );
	}

	public function test_a_failed_callback_that_inserted_is_compensated_when_the_envelope_cannot_be_stored(): void {
		$made = 0;
		$out  = $this->withUnwritableSnapshots(
			function () use ( &$made ) {
				return $this->createPage(
					function () use ( &$made ) {
						$made = $this->insertPage();
						return new WP_Error( 'elementor_boom', 'no' );
					}
				);
			}
		);

		$this->assertSame( 'aura_snapshot_failed', $out->get_error_code() );
		$this->assertSame( array( $made ), $this->row( 1 )['compensated'] );
		$this->assertSame( 'trash', get_post( $made )->post_status );
	}

	/**
	 * Where the trash is disabled, wp_trash_post() DELETES (post.php) — so the
	 * entry says `delete`, not `trash`: the two are not the same news.
	 *
	 * The constant can only be defined once per process, and defining it would
	 * change wp_trash_post() for every later test in this one.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_compensation_reports_a_delete_where_the_trash_is_disabled(): void {
		define( 'EMPTY_TRASH_DAYS', 0 );
		$made = 0;
		$out  = $this->withUnwritableSnapshots(
			function () use ( &$made ) {
				return $this->createPage(
					function () use ( &$made ) {
						$made = $this->insertPage();
						return array( 'id' => $made );
					}
				);
			}
		);

		$this->assertSame( 'aura_snapshot_failed', $out->get_error_code() );
		$row = $this->row( 1 );
		$this->assertSame( 'delete', $row['compensated_by'] );
		$this->assertSame( array( $made ), $row['compensated'] );
		$this->assertNull( get_post( $made ), 'it was deleted, and the entry says so' );
	}

	public function test_a_post_that_cannot_be_trashed_is_reported_uncompensated(): void {
		$made = 0;
		$out  = $this->withUnwritableSnapshots(
			function () use ( &$made ) {
				return $this->createPage(
					function () use ( &$made ) {
						$made = $this->insertPage();
						$GLOBALS['_sa_state']['wp_trash_post_noop'][ $made ] = true;
						return array( 'id' => $made );
					}
				);
			}
		);

		$this->assertSame( 'aura_snapshot_failed', $out->get_error_code() );
		$this->assertSame( array( $made ), $out->get_error_data()['uncompensated'] );
		$this->assertStringContainsString( (string) $made, $out->get_error_message() );
		$this->assertStringContainsString( 'check the site', $out->get_error_message() );
		$row = $this->row( 1 );
		$this->assertSame( array(), $row['compensated'] );
		$this->assertSame( array( $made ), $row['uncompensated'] );
		$this->assertSame( 'draft', get_post( $made )->post_status, 'it really is still live' );
	}

	/* ------------------------------------------------------------------ */
	/* (f2c) a watermark that cannot be persisted                          */
	/* ------------------------------------------------------------------ */

	public function test_a_watermark_that_cannot_be_recorded_refuses_before_elementor_runs(): void {
		// The row's compare-and-swap fails for the watermark patch alone: the
		// admission before it and the settle after it still land.
		$GLOBALS['_sa_option_cas_fail']['aura_worker_door_log_1'] = static function ( $value ) {
			return false !== strpos( (string) $value, 'post_watermark' );
		};

		$out = $this->createPage( function () { return array( 'id' => $this->insertPage() ); } );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aura_log_failed', $out->get_error_code() );
		$this->assertArrayNotHasKey( 'elementor/create-page', $this->ran, 'Elementor never ran' );
		$this->assertSame( array(), $GLOBALS['_posts'] );
		$row = $this->row( 1 );
		$this->assertSame( 'refused', $row['result'] );
		$this->assertSame( 'watermark_failed', $row['reason'] );
		$this->assertFalse( get_option( Aura_Worker_Elementor_Door::CREATING, false ), 'the mutex is released' );
	}

	/* ------------------------------------------------------------------ */
	/* (f4/f5) a callback that throws                                      */
	/* ------------------------------------------------------------------ */

	public function test_a_creating_callback_that_throws_settles_failed_and_releases_the_mutex(): void {
		$made = 0;
		$out  = $this->createPage(
			function () use ( &$made ) {
				$made = $this->insertPage();
				throw new RuntimeException( 'boom' );
			}
		);

		$this->assertSame( 'aura_governor_error', $out->get_error_code() );
		$row = $this->row( 1 );
		$this->assertSame( 'failed', $row['result'] );
		$this->assertTrue( $row['may_have_run'] );
		$this->assertSame( array( $made ), $row['created_post_ids'] );
		$this->assertNotSame( '', (string) $row['snapshot_id'], 'what it created is still restorable' );
		$this->assertSame( array( $made ), $this->envelope( (string) $row['snapshot_id'] )['created_post_ids'] );
		$this->assertFalse( get_option( Aura_Worker_Elementor_Door::CREATING, false ) );

		// A settled row does not stop the log: the next entry is served.
		$this->register( 'elementor/manage-classes', static function () { return array( 'ok' => true ); } );
		Aura_Worker_Elementor_Door::set_snapshotter_for_tests( static function () { return array( 'success' => true, 'snapshot' => array( 'id' => 'snap_ds' ) ); } );
		wp_get_ability( 'elementor/manage-classes' )->execute( array() );
		$this->assertSame( array( 1, 2 ), array_column( Aura_Worker_Door_Log::log_after( 0 ), 'seq' ) );
	}

	public function test_a_throwing_callback_whose_envelope_fails_is_compensated(): void {
		$made = 0;
		$out  = $this->withUnwritableSnapshots(
			function () use ( &$made ) {
				return $this->createPage(
					function () use ( &$made ) {
						$made = $this->insertPage();
						throw new RuntimeException( 'boom' );
					}
				);
			}
		);

		$this->assertSame( 'aura_governor_error', $out->get_error_code() );
		$row = $this->row( 1 );
		$this->assertSame( 'failed', $row['result'] );
		$this->assertSame( 'exception_then_compensated', $row['reason'] );
		$this->assertSame( array( $made ), $row['compensated'] );
		$this->assertSame( 'trash', get_post( $made )->post_status );
		$this->assertFalse( get_option( Aura_Worker_Elementor_Door::CREATING, false ) );
	}

	/* ------------------------------------------------------------------ */
	/* (g) class-deletion collateral                                       */
	/* ------------------------------------------------------------------ */

	/** Register `manage-classes` with a stubbed pre-write capture. */
	private function registerClasses( callable $inner ): void {
		$this->allowCreations();
		Aura_Worker_Elementor_Door::set_snapshotter_for_tests(
			static function () {
				return array( 'success' => true, 'snapshot' => array( 'id' => 'snap_ds' ) );
			}
		);
		$this->register( 'elementor/manage-classes', $inner );
	}

	public function test_class_cleanup_collateral_is_captured_and_recorded_before_elementors_handler(): void {
		$this->seedPost( 11 );
		$this->seedPost( 12 );
		update_post_meta( 11, '_elementor_data', '[{"class":"a"}]' );
		update_post_meta( 12, '_elementor_data', '[{"class":"a"}]' );

		$elementor_saw = null;
		add_action(
			'elementor/global_classes/cleanup',
			static function () use ( &$elementor_saw ) {
				$row           = Aura_Worker_Door_Log::get( 1 );
				$elementor_saw = $row['collateral_snapshot_ids'] ?? null;
			},
			10,
			2
		);

		$this->registerClasses(
			static function () {
				do_action( 'elementor/global_classes/cleanup', array( 'g-a' ), array( 11, 12 ) );
				return array( 'ok' => true );
			}
		);
		wp_get_ability( 'elementor/manage-classes' )->execute( array() );

		$row = $this->row( 1 );
		$this->assertCount( 1, $row['collateral_snapshot_ids'] );
		$this->assertSame( $row['collateral_snapshot_ids'], $elementor_saw, 'the id is on the row before Elementor rewrites the pages' );
		$this->assertSame( 'ok', $row['result'] );
		$this->assertSame( 'snap_ds', $row['snapshot_id'] );
		$this->assertArrayNotHasKey( 'collateral_warned', $row, 'no rule names these pages' );
		$this->assertArrayNotHasKey( 'collateral_blocked', $row );

		$env = $this->envelope( (string) $row['collateral_snapshot_ids'][0] );
		$this->assertSame( 'page', $env['door_kind'] );
		$this->assertSame( array( 11, 12 ), $env['targets'] );
		$this->assertSame( array( '_elementor_data' ), $env['keys'] );
		$this->assertSame( 1, $env['door']['collateral_of'] );
		$this->assertSame( 1, $env['door']['seq'] );
	}

	public function test_a_collateral_capture_that_fails_refuses_before_elementors_handler(): void {
		$this->seedPost( 11 );

		$elementor_ran = 0;
		add_action(
			'elementor/global_classes/cleanup',
			static function () use ( &$elementor_ran ) {
				++$elementor_ran;
			},
			10,
			2
		);

		$out = $this->withUnwritableSnapshots(
			function () {
				$this->registerClasses(
					static function () {
						do_action( 'elementor/global_classes/cleanup', array( 'g-a' ), array( 11 ) );
						return array( 'ok' => true );
					}
				);
				return wp_get_ability( 'elementor/manage-classes' )->execute( array() );
			}
		);

		$this->assertSame( 'aura_governor_error', $out->get_error_code() );
		$this->assertSame( 0, $elementor_ran, "Elementor's own cleanup never ran" );
		$row = $this->row( 1 );
		$this->assertSame( 'failed', $row['result'] );
		$this->assertTrue( $row['may_have_run'] );
	}

	/**
	 * Ruling P22: the collateral pages are JUDGED, not merely captured.
	 *
	 * The call declares `design_system:*`, so a rule protecting page 7 never
	 * saw it — while this hook learns, one priority before Elementor rewrites
	 * them, exactly which pages the deletion is about to change.
	 */
	public function test_a_block_rule_on_a_collateral_page_refuses_before_elementors_cleanup(): void {
		$this->seedPost( 7 );
		update_post_meta( 7, '_elementor_data', '[{"class":"a"}]' );
		$elementor_ran = 0;
		add_action(
			'elementor/global_classes/cleanup',
			static function () use ( &$elementor_ran ) {
				++$elementor_ran;
			},
			10,
			2
		);
		$this->registerClasses(
			static function () {
				do_action( 'elementor/global_classes/cleanup', array( 'g-a' ), array( 7 ) );
				return array( 'ok' => true );
			}
		);
		$this->installRuleset(
			array(
				array( 'key' => 'rule/ds', 'effect' => 'allow', 'target' => array( 'type' => 'design_system' ), 'reason' => 'classes are fine' ),
				array( 'key' => 'rule/keep-7', 'effect' => 'block', 'target' => array( 'type' => 'page', 'id' => '7' ), 'reason' => 'the client signed off on this page' ),
			)
		);

		$out = wp_get_ability( 'elementor/manage-classes' )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aura_rule_blocked', $out->get_error_code() );
		$this->assertSame( 403, $out->get_error_data()['status'] );
		$this->assertTrue( $out->get_error_data()['may_have_run'], 'the class row was already deleted inside Elementor' );
		$this->assertSame( 'snap_ds', $out->get_error_data()['restorable_from'] );
		$this->assertSame( 0, $elementor_ran, "Elementor's own cleanup never ran, so page 7 was never rewritten" );
		$this->assertSame( '[{"class":"a"}]', get_post_meta( 7, '_elementor_data', true ) );
		$this->assertSame( array(), $this->envelopes(), 'nothing was captured either' );

		$row = $this->row( 1 );
		$this->assertSame( 'refused', $row['result'] );
		$this->assertSame( 'collateral_blocked', $row['reason'] );
		$this->assertSame( array( 7 ), $row['collateral_blocked'] );
		$this->assertSame( 'rule/keep-7', $row['rule_key'] );
		$this->assertSame( 'rule/keep-7', $row['rule']['key'] );
		$this->assertSame( 'snap_ds', $row['snapshot_id'], 'the design-system envelope the deletion can be rolled back from' );
		$this->assertTrue( $row['may_have_run'] );
	}

	public function test_a_warn_rule_on_a_collateral_page_is_recorded_and_the_cleanup_proceeds(): void {
		$this->seedPost( 7 );
		update_post_meta( 7, '_elementor_data', '[{"class":"a"}]' );
		$elementor_ran = 0;
		add_action(
			'elementor/global_classes/cleanup',
			static function () use ( &$elementor_ran ) {
				++$elementor_ran;
			},
			10,
			2
		);
		$this->registerClasses(
			static function () {
				do_action( 'elementor/global_classes/cleanup', array( 'g-a' ), array( 7 ) );
				return array( 'ok' => true );
			}
		);
		$this->installRuleset(
			array(
				array( 'key' => 'rule/ds', 'effect' => 'allow', 'target' => array( 'type' => 'design_system' ), 'reason' => 'classes are fine' ),
				array( 'key' => 'rule/watch-7', 'effect' => 'warn', 'target' => array( 'type' => 'page', 'id' => '7' ), 'reason' => 'tell me about it' ),
			)
		);

		$out = wp_get_ability( 'elementor/manage-classes' )->execute( array() );

		$this->assertSame( array( 'ok' => true ), $out );
		$this->assertSame( 1, $elementor_ran, 'a warn does not stop the cleanup' );
		$row = $this->row( 1 );
		$this->assertSame( 'ok', $row['result'] );
		$this->assertSame( array( 7 ), $row['collateral_warned'] );
		$this->assertSame( 'rule/watch-7', $row['collateral_rule']['key'] );
		$this->assertSame( 'tell me about it', $row['collateral_rule']['reason'] );
		$this->assertCount( 1, $row['collateral_snapshot_ids'], 'and the capture still happened' );
	}

	public function test_a_cleanup_outside_a_design_system_request_is_ignored(): void {
		$this->seedPost( 11 );
		do_action( 'elementor/global_classes/cleanup', array( 'g-a' ), array( 11 ) );
		$this->assertSame( array(), $this->envelopes() );
	}

	/* ------------------------------------------------------------------ */
	/* (h) a component creation                                            */
	/* ------------------------------------------------------------------ */

	public function test_a_manage_component_without_an_id_creates_a_component(): void {
		$this->allowCreations();
		$made = array();
		$this->register(
			'elementor/manage-component',
			function () use ( &$made ) {
				$made['component'] = $this->insertPage( Aura_Worker_Elementor_Door::CPT_COMPONENT );
				$made['page']      = $this->insertPage();
				return array( 'id' => $made['component'] );
			}
		);
		$out = wp_get_ability( 'elementor/manage-component' )->execute( array() );

		$this->assertSame( array( 'id' => $made['component'] ), $out );
		$row = $this->row( 1 );
		$this->assertSame( array( Aura_Worker_Elementor_Door::CPT_COMPONENT ), $row['expected_types'] );
		$this->assertSame( array( $made['component'] ), $row['created_post_ids'] );
		$this->assertSame( array( $made['page'] ), $row['other_inserts'] );
		$this->assertSame( Aura_Worker_Elementor_Door::CPT_COMPONENT, $this->envelope( (string) $row['snapshot_id'] )['post_type'] );
	}

	public function test_a_manage_component_naming_an_id_is_not_a_creation(): void {
		$this->seedPost( 21, Aura_Worker_Elementor_Door::CPT_COMPONENT );
		$this->allowCreations();
		$this->register( 'elementor/manage-component', static function () { return array( 'ok' => true ); } );
		wp_get_ability( 'elementor/manage-component' )->execute( array( 'id' => 21 ) );

		$row = $this->row( 1 );
		$this->assertArrayNotHasKey( 'post_watermark', $row, 'an existing component is captured before the write, not after' );
		$this->assertSame( 'component', $this->envelope( (string) $row['snapshot_id'] )['door_kind'] );
	}

	public function test_the_expected_type_follows_the_input_post_type(): void {
		$made = 0;
		$this->createPage(
			function () use ( &$made ) {
				$made = $this->insertPage( 'post' );
				return array( 'id' => $made );
			},
			array( 'post_type' => 'post' )
		);

		$row = $this->row( 1 );
		$this->assertSame( array( 'post' ), $row['expected_types'] );
		$this->assertSame( array( $made ), $row['created_post_ids'] );
		$this->assertSame( 'post', $this->envelope( (string) $row['snapshot_id'] )['post_type'] );
	}
	/* ------------------------------------------------------------------ */
	/* Round 1: a creation is finished ONCE, and released by its owner     */
	/* ------------------------------------------------------------------ */

	/**
	 * A throw AFTER finish_creation() already returned. The catch must NOT
	 * finish the creation a second time: that would write a duplicate envelope
	 * and — if the store failed that time — trash the very post the first
	 * envelope had just made restorable, with no mutex held.
	 */
	public function test_a_throw_after_the_creation_finished_does_not_finish_it_again(): void {
		// One-shot: the terminal settle's compare-and-swap throws. `settled_at`
		// appears in no other write of this request.
		$GLOBALS['_sa_before_swap'] = static function () {
			if ( false === strpos( (string) $GLOBALS['wpdb']->last_query, 'settled_at' ) ) {
				return;
			}
			$GLOBALS['_sa_before_swap'] = null; // the catch's own settle must land
			throw new RuntimeException( 'the row would not settle' );
		};

		$made = 0;
		$out  = $this->createPage(
			function () use ( &$made ) {
				$made = $this->insertPage();
				return array( 'id' => $made );
			}
		);

		$this->assertSame( 'aura_governor_error', $out->get_error_code() );
		$this->assertCount( 1, $this->creationEnvelopes(), 'exactly one creation envelope' );
		$this->assertSame( 'draft', get_post( $made )->post_status, 'the post the envelope covers is not trashed' );

		$row = $this->row( 1 );
		$this->assertSame( 'failed', $row['result'] );
		$this->assertTrue( $row['may_have_run'] );
		$this->assertSame( 'exception', $row['reason'] );
		$this->assertSame( array( $made ), $row['created_post_ids'] );
		$this->assertSame( $this->creationEnvelopes()[0]['id'], $row['snapshot_id'], "the first finish's envelope is what the entry names" );
		$this->assertArrayNotHasKey( 'compensated', $row );

		// Released exactly once, by the request that took it.
		$this->assertFalse( get_option( Aura_Worker_Elementor_Door::CREATING, false ) );
		$deletes = array_filter(
			$GLOBALS['_option_writes'],
			static function ( $w ) {
				return array( 'delete', Aura_Worker_Elementor_Door::CREATING ) === $w;
			}
		);
		$this->assertCount( 1, $deletes );
	}

	/**
	 * A throw while ANOTHER creation holds the mutex: this request never took
	 * the row, so nothing on its failure path may delete it — a released mutex
	 * would let a third call in beside the one still running.
	 */
	public function test_a_throw_taking_the_mutex_never_releases_another_requests(): void {
		$GLOBALS['_options'][ Aura_Worker_Elementor_Door::CREATING ] = array( 'seq' => 99, 'started_at' => gmdate( 'c' ) );
		$GLOBALS['_rows'][ Aura_Worker_Elementor_Door::CREATING ]    = maybe_serialize( $GLOBALS['_options'][ Aura_Worker_Elementor_Door::CREATING ] );
		$GLOBALS['_sa_before_swap']                                  = static function () {
			if ( false !== strpos( (string) $GLOBALS['wpdb']->last_query, Aura_Worker_Elementor_Door::CREATING ) ) {
				throw new RuntimeException( 'the database went away' );
			}
		};

		$out = $this->createPage( function () { return array( 'ok' => true ); } );

		$this->assertSame( 'aura_governor_error', $out->get_error_code() );
		$this->assertArrayNotHasKey( 'elementor/create-page', $this->ran );
		$this->assertSame( 99, get_option( Aura_Worker_Elementor_Door::CREATING )['seq'], "the holder's mutex is still there" );
		$this->assertSame( 'failed', $this->row( 1 )['result'] );
	}

}
