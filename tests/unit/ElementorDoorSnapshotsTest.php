<?php
/**
 * The door's snapshot kinds: a page/component capture, the design system as
 * ONE set-typed envelope, a creation whose restore is a governed trash, the
 * pre-restore capture that makes a restore itself reversible, and the
 * retention sweep that prunes only door envelopes (Ruling R3, R6).
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class ElementorDoorSnapshotsTest extends TestCase {

	/** @var Aura_Worker_API */
	private $api;

	/** @var array<string,int> how many times each inner callback ran */
	private $ran = array();

	protected function setUp(): void {
		sa_reset_state();
		$this->rrmdir( WP_CONTENT_DIR );
		mkdir( WP_CONTENT_DIR, 0755, true );
		Aura_Worker_Elementor_Door::reset_for_tests();
		Aura_Worker_Elementor_Door::init();
		$GLOBALS['_current_user_id'] = 3;
		$GLOBALS['_user_logins'][3]  = 'bot';
		$GLOBALS['_options']['aura_worker_site_token'] = Aura_Worker_Security::hash_token( 'tok' );
		$this->api = new Aura_Worker_API( new Aura_Worker_Security() );
		$this->ran = array();
	}

	protected function tearDown(): void {
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

	private function seedPost( int $id, string $type = 'page', string $status = 'publish' ): void {
		$GLOBALS['_posts'][ $id ] = (object) array(
			'ID'             => $id,
			'post_type'      => $type,
			'post_status'    => $status,
			'post_title'     => 'p-' . $id,
			'post_name'      => 'p-' . $id,
			'post_parent'    => 0,
			'post_content'   => '',
			'post_excerpt'   => '',
			'menu_order'     => 0,
			'post_author'    => 7,
			'post_date'      => '2026-01-02 03:04:05',
			'post_date_gmt'  => '2026-01-02 03:04:05',
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		);
	}

	/** The kit + two classes + one default style: the whole design system. */
	private function seedDesignSystem(): void {
		$this->seedPost( 100, 'elementor_library' );
		$GLOBALS['_sa_kit_id'] = 100;
		update_post_meta( 100, '_elementor_global_classes_order', 'a,b' );
		$this->seedPost( 201, Aura_Worker_Elementor_Door::CPT_GLOBAL_CLASS );
		update_post_meta( 201, '_elementor_global_class_data', '{"c":"a"}' );
		$this->seedPost( 202, Aura_Worker_Elementor_Door::CPT_GLOBAL_CLASS );
		update_post_meta( 202, '_elementor_global_class_data', '{"c":"b"}' );
		$this->seedPost( 301, Aura_Worker_Elementor_Door::CPT_DEFAULT_STYLE );
		update_post_meta( 301, '_elementor_default_style_data', '{"s":1}' );
	}

	/** Call the governor's private snapshot_for() directly — it is the unit here. */
	private function snapshotFor( string $slug, array $touches, array $input ): array {
		$m = new ReflectionMethod( Aura_Worker_Elementor_Door::class, 'snapshot_for' );
		if ( PHP_VERSION_ID < 80100 ) {
			$m->setAccessible( true );
		}
		return $m->invoke( null, $slug, $touches, $input );
	}

	/** Register one governed ability with a counting inner callback. */
	private function registerAbility( string $slug ): void {
		$ran         = &$this->ran;
		sa_register_ability(
			$slug,
			array(
				'execute_callback'    => static function ( $input ) use ( &$ran, $slug ) {
					$ran[ $slug ] = ( $ran[ $slug ] ?? 0 ) + 1;
					return array( 'ok' => true, 'input' => $input );
				},
				'permission_callback' => '__return_true',
			)
		);
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

	private function request( array $params ): WP_REST_Request {
		$req = new WP_REST_Request();
		$req->set_header( 'X-Aura-Token', 'tok' );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return $req;
	}

	/** Backdate an envelope's created_gmt on disk. */
	private function ageEnvelope( string $id, int $days ): void {
		$path = WP_CONTENT_DIR . '/aura-backups/snapshots/' . $id . '.json';
		$rec  = json_decode( file_get_contents( $path ), true );
		$rec['created_gmt'] = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		file_put_contents( $path, wp_json_encode( $rec ) );
	}

	private function envelopeCount(): int {
		$files = glob( WP_CONTENT_DIR . '/aura-backups/snapshots/snap_*.json' );
		return $files ? count( $files ) : 0;
	}

	/* ------------------------------------------------------------------ */
	/* (a) a page envelope                                                 */
	/* ------------------------------------------------------------------ */

	public function test_a_page_envelope_captures_the_row_and_the_three_meta_keys(): void {
		$this->seedPost( 7, 'page', 'draft' );
		update_post_meta( 7, '_elementor_data', '[{"v":1}]' );
		update_post_meta( 7, '_elementor_page_settings', '{"title":"before"}' );

		$snap = $this->snapshotFor( 'elementor/manage-elements', array( array( 'type' => 'page', 'id' => '7' ) ), array( 'post_id' => 7 ) );
		$this->assertTrue( $snap['success'] );
		$this->assertSame( 'posts', $snap['snapshot']['kind'] );
		$this->assertSame( 'page', $snap['snapshot']['door_kind'] );
		$this->assertSame( array( 7 ), $snap['snapshot']['targets'] );
		$this->assertSame( Aura_Worker_Elementor_Door::PAGE_META_KEYS, $snap['snapshot']['keys'] );
		$this->assertArrayNotHasKey( 'cpts', $snap['snapshot'], 'a page is not a set capture' );
		$this->assertSame( 'elementor/manage-elements', $snap['snapshot']['door']['ability'] );

		// The write publishes it and rewrites both meta keys.
		wp_update_post( array( 'ID' => 7, 'post_status' => 'publish' ) );
		update_post_meta( 7, '_elementor_data', '[{"v":2}]' );
		update_post_meta( 7, '_elementor_page_settings', '{"title":"after"}' );

		$snaps = new Aura_Worker_Snapshots();
		$this->assertTrue( $snaps->restore( $snap['snapshot']['id'] )['success'] );
		$this->assertSame( 'draft', get_post( 7 )->post_status );
		$this->assertSame( '[{"v":1}]', get_post_meta( 7, '_elementor_data', true ) );
		$this->assertSame( '{"title":"before"}', get_post_meta( 7, '_elementor_page_settings', true ) );
	}

	/* ------------------------------------------------------------------ */
	/* (b) the design system as ONE set-typed envelope                     */
	/* ------------------------------------------------------------------ */

	public function test_a_design_system_envelope_is_the_kit_every_class_and_every_style(): void {
		$this->seedDesignSystem();

		$snap = $this->snapshotFor( 'elementor/manage-classes', array( array( 'type' => 'design_system', 'id' => '*' ) ), array() );
		$this->assertTrue( $snap['success'] );
		$this->assertSame( 'design_system', $snap['snapshot']['door_kind'] );
		$this->assertSame( array( 100, 201, 202, 301 ), $snap['snapshot']['targets'] );
		$this->assertSame(
			array( Aura_Worker_Elementor_Door::CPT_GLOBAL_CLASS, Aura_Worker_Elementor_Door::CPT_DEFAULT_STYLE ),
			$snap['snapshot']['cpts']
		);
		$this->assertContains( '_elementor_global_classes_order', $snap['snapshot']['keys'] );
		$this->assertContains( '_elementor_global_class_data', $snap['snapshot']['keys'] );
	}

	public function test_a_design_system_restore_removes_an_added_class_and_recreates_a_deleted_one(): void {
		$this->seedDesignSystem();
		$snaps = new Aura_Worker_Snapshots();
		$snap  = $this->snapshotFor( 'elementor/manage-classes', array( array( 'type' => 'design_system', 'id' => '*' ) ), array() );

		// The write ADDS 203 and DELETES 202, and rewrites the kit's order.
		$this->seedPost( 203, Aura_Worker_Elementor_Door::CPT_GLOBAL_CLASS );
		update_post_meta( 203, '_elementor_global_class_data', '{"c":"new"}' );
		wp_delete_post( 202, true );
		update_post_meta( 100, '_elementor_global_classes_order', 'a,new' );

		$this->assertTrue( $snaps->restore( $snap['snapshot']['id'] )['success'] );
		$this->assertNull( get_post( 203 ), 'a class the write ADDED is removed by the set semantics' );
		$this->assertNotNull( get_post( 202 ), 'a class the write DELETED is recreated' );
		$this->assertSame( 202, (int) get_post( 202 )->ID, 'recreated with its ORIGINAL id' );
		$this->assertSame( '{"c":"b"}', get_post_meta( 202, '_elementor_global_class_data', true ) );
		$this->assertSame( 'a,b', get_post_meta( 100, '_elementor_global_classes_order', true ) );
		$this->assertNotNull( get_post( 301 ), 'a default style of a captured type is not collateral damage' );
	}

	public function test_a_truncated_set_capture_refuses_and_never_wipes_the_design_system(): void {
		$this->seedDesignSystem();
		$snaps = new Aura_Worker_Snapshots();
		$snap  = $this->snapshotFor( 'elementor/manage-classes', array( array( 'type' => 'design_system', 'id' => '*' ) ), array() );

		// The payload is truncated to an empty (still well-formed) capture. An
		// empty set is not a record of an empty design system: it must refuse,
		// and it must NOT read as "every class on the site was added".
		file_put_contents( $snap['snapshot']['payload_path'], serialize( array() ) );

		$out = $snaps->restore( $snap['snapshot']['id'] );
		$this->assertFalse( $out['success'], 'an empty capture is a corrupt payload, not a done restore' );
		$this->assertSame( 'Snapshot payload corrupt.', $out['error'] );
		$this->assertNotNull( get_post( 201 ) );
		$this->assertNotNull( get_post( 202 ) );
		$this->assertNotNull( get_post( 301 ) );
	}

	public function test_a_truncated_set_capture_settles_the_door_entry_failed(): void {
		$this->seedDesignSystem();
		$snaps = new Aura_Worker_Snapshots();
		$snap  = $this->snapshotFor( 'elementor/manage-classes', array( array( 'type' => 'design_system', 'id' => '*' ) ), array() );
		file_put_contents( $snap['snapshot']['payload_path'], serialize( array() ) );

		$res = $this->api->restore_snapshot( $this->request( array( 'id' => $snap['snapshot']['id'] ) ) );
		$this->assertSame( 500, $res->get_status(), 'never 200 for a restore that rolled nothing back' );
		$this->assertFalse( $res->data['success'] );
		$this->assertSame( 'failed', Aura_Worker_Door_Log::get( 1 )['result'] );
		$this->assertNotNull( get_post( 202 ) );
	}

	/* ------------------------------------------------------------------ */
	/* (a0) the pre-restore capture enumerates the CURRENT set             */
	/* ------------------------------------------------------------------ */

	public function test_a_design_system_pre_restore_capture_enumerates_the_current_set(): void {
		$this->seedDesignSystem();
		$snaps = new Aura_Worker_Snapshots();
		$first = $this->snapshotFor( 'elementor/manage-classes', array( array( 'type' => 'design_system', 'id' => '*' ) ), array() );

		// Since that envelope: 203 added, 202 gone.
		$this->seedPost( 203, Aura_Worker_Elementor_Door::CPT_GLOBAL_CLASS );
		update_post_meta( 203, '_elementor_global_class_data', '{"c":"new"}' );
		wp_delete_post( 202, true );

		$pre = Aura_Worker_Elementor_Door::pre_restore_capture( $snaps->get( $first['snapshot']['id'] ) );
		$this->assertTrue( $pre['success'] );
		$this->assertSame(
			array( 100, 201, 203, 301 ),
			$pre['snapshot']['targets'],
			'the CURRENT set, not the old envelope\'s targets'
		);

		// The restore rolls the set back to the first envelope…
		$this->assertTrue( $snaps->restore( $first['snapshot']['id'] )['success'] );
		$this->assertNull( get_post( 203 ) );
		$this->assertNotNull( get_post( 202 ) );

		// …and the restore-of-the-restore brings 203 back.
		$this->assertTrue( $snaps->restore( $pre['snapshot']['id'] )['success'] );
		$this->assertNotNull( get_post( 203 ), 'the class added since the envelope survives a restore-of-the-restore' );
		$this->assertSame( '{"c":"new"}', get_post_meta( 203, '_elementor_global_class_data', true ) );
		$this->assertNull( get_post( 202 ), 'and the one the first restore recreated is removed again' );
	}

	/* ------------------------------------------------------------------ */
	/* (c) a component write on a post that is not a component             */
	/* ------------------------------------------------------------------ */

	public function test_a_component_write_on_a_non_component_post_is_refused_unattributed(): void {
		$this->seedPost( 55, 'page' );
		$out = $this->snapshotFor( 'elementor/manage-component', array( array( 'type' => 'design_system', 'id' => '*' ) ), array( 'id' => 55 ) );

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'aura_target_unattributed', $out['code'] );
		$this->assertSame( 0, $this->envelopeCount(), 'nothing was written' );
	}

	/**
	 * End to end: the designated code reaches the CALLER, as a 403. An
	 * unattributable target can never become snapshottable, so answering the
	 * generic retryable 503 `aura_snapshot_failed` would tell an agent to try
	 * again forever.
	 */
	public function test_an_unattributable_component_target_answers_403_through_the_door(): void {
		$this->seedPost( 55, 'page' );
		$this->registerAbility( 'elementor/manage-component' );
		$this->installRuleset( array( array( 'key' => 'rule/a', 'effect' => 'allow', 'target' => array( 'type' => 'design_system' ), 'reason' => 'ok' ) ) );

		$out = wp_get_ability( 'elementor/manage-component' )->execute( array( 'id' => 55 ) );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aura_target_unattributed', $out->get_error_code() );
		$this->assertSame( 403, $out->get_error_data()['status'] );
		$this->assertArrayNotHasKey( 'elementor/manage-component', $this->ran, 'the write never ran' );
		$this->assertSame( 'refused', Aura_Worker_Door_Log::log_after( 0 )[0]['result'] );
		$this->assertSame( 0, $this->envelopeCount(), 'nothing was written' );
	}

	public function test_a_capture_that_fails_for_any_other_reason_is_still_a_retryable_503(): void {
		$this->seedPost( 7, 'page', 'draft' );
		$this->registerAbility( 'elementor/manage-elements' );
		$this->installRuleset( array( array( 'key' => 'rule/a', 'effect' => 'allow', 'target' => array( 'type' => 'page', 'id' => '7' ), 'reason' => 'ok' ) ) );
		Aura_Worker_Elementor_Door::set_snapshotter_for_tests( static function () { return array( 'success' => false, 'error' => 'disk full' ); } );

		$out = wp_get_ability( 'elementor/manage-elements' )->execute( array( 'post_id' => 7 ) );

		$this->assertSame( 'aura_snapshot_failed', $out->get_error_code() );
		$this->assertSame( 503, $out->get_error_data()['status'] );
		$this->assertSame( 'refused', Aura_Worker_Door_Log::log_after( 0 )[0]['result'] );
	}

	public function test_a_component_write_on_a_component_post_captures_it(): void {
		$this->seedPost( 56, Aura_Worker_Elementor_Door::CPT_COMPONENT );
		update_post_meta( 56, '_elementor_data', '[{"c":1}]' );
		$out = $this->snapshotFor( 'elementor/manage-component', array( array( 'type' => 'design_system', 'id' => '*' ) ), array( 'id' => 56 ) );

		$this->assertTrue( $out['success'] );
		$this->assertSame( 'component', $out['snapshot']['door_kind'] );
		$this->assertSame( array( 56 ), $out['snapshot']['targets'] );
	}

	public function test_a_component_write_with_no_id_is_the_creation_path(): void {
		$out = $this->snapshotFor( 'elementor/manage-component', array( array( 'type' => 'design_system', 'id' => '*' ) ), array() );
		$this->assertTrue( $out['success'] );
		$this->assertTrue( $out['creation'] );
		$this->assertNull( $out['snapshot']['id'] );
		$this->assertSame( 0, $this->envelopeCount(), 'a creation captures AFTER, not before' );
	}

	/**
	 * The wrapper's own fork: a component call naming no id takes the creation
	 * path (mutex + watermark + a creation envelope after), exactly as
	 * `create-page` does — there is nothing to capture before either.
	 *
	 * @dataProvider creating_calls
	 */
	public function test_which_calls_are_creating( string $slug, array $input, bool $expected ): void {
		$m = new ReflectionMethod( Aura_Worker_Elementor_Door::class, 'is_creating' );
		if ( PHP_VERSION_ID < 80100 ) {
			$m->setAccessible( true );
		}
		$this->assertSame( $expected, $m->invoke( null, $slug, $input ) );
	}

	public static function creating_calls(): array {
		return array(
			'create-page always'          => array( 'elementor/create-page', array(), true ),
			'component with no id'        => array( 'elementor/manage-component', array(), true ),
			'component with an empty id'  => array( 'elementor/manage-component', array( 'id' => '' ), true ),
			'component with a zero id'    => array( 'elementor/manage-component', array( 'id' => 0 ), true ),
			'component with an id'        => array( 'elementor/manage-component', array( 'id' => 56 ), false ),
			'a page write'                => array( 'elementor/manage-elements', array( 'post_id' => 7 ), false ),
			'a design-system write'       => array( 'elementor/manage-classes', array(), false ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* (d) a creation restore is a governed trash                          */
	/* ------------------------------------------------------------------ */

	public function test_a_creation_restore_trashes_every_created_id_and_repeats_as_already(): void {
		$this->seedPost( 501, 'page' );
		$this->seedPost( 502, 'page' );
		$snaps = new Aura_Worker_Snapshots();
		$rec   = $snaps->snapshot_creation( array( 501, 502 ), 'page', array( 'seq' => 1, 'ability' => 'elementor/create-page' ) );

		$this->assertTrue( $rec['success'] );
		$this->assertSame( 'creation', $rec['snapshot']['kind'] );
		$this->assertSame( 'creation', $rec['snapshot']['door_kind'] );
		$this->assertSame( array( 501, 502 ), $rec['snapshot']['created_post_ids'] );
		$this->assertArrayNotHasKey( 'payload_path', $rec['snapshot'], 'a creation envelope has no payload' );

		$out = $snaps->restore( $rec['snapshot']['id'] );
		$this->assertTrue( $out['success'] );
		$this->assertSame( array( 501, 502 ), $out['trashed'] );
		$this->assertSame( array(), $out['already'] );
		$this->assertSame( 'trash', get_post( 501 )->post_status );

		$again = $snaps->restore( $rec['snapshot']['id'] );
		$this->assertTrue( $again['success'] );
		$this->assertSame( array(), $again['trashed'] );
		$this->assertSame( array( 501, 502 ), $again['already'] );
	}

	public function test_a_creation_restore_skips_an_id_that_is_already_gone(): void {
		$this->seedPost( 505, 'page' );
		$snaps = new Aura_Worker_Snapshots();
		$rec   = $snaps->snapshot_creation( array( 505, 506 ), 'page', array( 'seq' => 2, 'ability' => 'elementor/create-page' ) );

		$out = $snaps->restore( $rec['snapshot']['id'] );
		$this->assertTrue( $out['success'] );
		$this->assertSame( array( 505 ), $out['trashed'] );
	}

	/**
	 * Core's wp_trash_post() DELETES when EMPTY_TRASH_DAYS is 0, so a creation
	 * restore on such a site would be irreversible. It is refused instead —
	 * and refused BEFORE anything is touched.
	 *
	 * The constant can only be defined once per process, and defining it would
	 * change wp_trash_post()'s behaviour for every later test in this process,
	 * so this one case runs isolated.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_creation_restore_refuses_where_the_trash_is_disabled(): void {
		define( 'EMPTY_TRASH_DAYS', 0 );
		$this->seedPost( 501, 'page' );
		$this->seedPost( 502, 'page' );
		$snaps = new Aura_Worker_Snapshots();
		$rec   = $snaps->snapshot_creation( array( 501, 502 ), 'page', array( 'seq' => 1, 'ability' => 'elementor/create-page' ) );
		$out   = $snaps->restore( $rec['snapshot']['id'] );

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'aura_trash_disabled', $out['code'] );
		$this->assertArrayHasKey( 501, $GLOBALS['_posts'], 'nothing was deleted' );
		$this->assertArrayHasKey( 502, $GLOBALS['_posts'], 'nothing was deleted' );

		// And through REST it is a DESIGNATED refusal — 409, never 500 (an
		// execution failure) and never 404 (which Aura reads as "gone").
		$res = $this->api->restore_snapshot( $this->request( array( 'id' => $rec['snapshot']['id'] ) ) );
		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'aura_trash_disabled', $res->data['code'] );
		$this->assertArrayHasKey( 501, $GLOBALS['_posts'], 'nothing was deleted' );
		$this->assertSame( 'failed', Aura_Worker_Door_Log::get( 1 )['result'] );
	}

	/* ------------------------------------------------------------------ */
	/* (e) the REST restore reserves, captures, restores, settles          */
	/* ------------------------------------------------------------------ */

	/** A `page` envelope taken through the governor, ready to restore. */
	private function pageEnvelope(): array {
		$this->seedPost( 7, 'page', 'draft' );
		update_post_meta( 7, '_elementor_data', '[{"v":1}]' );
		$snap = $this->snapshotFor( 'elementor/manage-elements', array( array( 'type' => 'page', 'id' => '7' ) ), array( 'post_id' => 7 ) );
		update_post_meta( 7, '_elementor_data', '[{"v":2}]' );
		return $snap['snapshot'];
	}

	public function test_the_rest_restore_captures_the_current_state_and_settles_the_door_entry(): void {
		$env = $this->pageEnvelope();

		$res = $this->api->restore_snapshot( $this->request( array( 'id' => $env['id'], 'aura_ref' => 'act_42' ) ) );
		$this->assertInstanceOf( WP_REST_Response::class, $res );
		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( $res->data['success'] );
		$this->assertSame( '[{"v":1}]', get_post_meta( 7, '_elementor_data', true ) );

		$row = Aura_Worker_Door_Log::get( 1 );
		$this->assertSame( 'aura/restore', $row['ability'] );
		$this->assertSame( 'ok', $row['result'] );
		$this->assertSame( $env['id'], $row['restore_of'] );
		$this->assertSame( 'act_42', $row['ref'] );
		$this->assertSame( array( array( 'type' => 'page', 'id' => '7' ) ), $row['touches'], 'the envelope\'s own target, not an empty set' );
		$this->assertSame( 'rules_unavailable', $row['verdict'], 'no ruleset is stored on this site, and the entry says so' );

		// The pre-restore envelope is a same-kind capture of what was there.
		$snaps = new Aura_Worker_Snapshots();
		$pre   = $snaps->get( $row['snapshot_id'] );
		$this->assertNotNull( $pre );
		$this->assertSame( 'page', $pre['door_kind'] );
		$this->assertSame( $env['id'], $pre['door']['restore_of'] );
		$this->assertTrue( $snaps->restore( $pre['id'] )['success'] );
		$this->assertSame( '[{"v":2}]', get_post_meta( 7, '_elementor_data', true ), 'the restore is itself reversible' );
	}

	public function test_a_closed_log_refuses_the_restore_before_anything_is_captured(): void {
		$env = $this->pageEnvelope();
		$before = $this->envelopeCount();
		Aura_Worker_Door_Log::close();

		$res = $this->api->restore_snapshot( $this->request( array( 'id' => $env['id'] ) ) );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_log_full', $res->get_error_code() );
		$this->assertSame( $before, $this->envelopeCount(), 'nothing was captured' );
		$this->assertSame( '[{"v":2}]', get_post_meta( 7, '_elementor_data', true ), 'nothing was restored' );
	}

	public function test_a_log_that_cannot_take_the_row_refuses_the_restore(): void {
		$env = $this->pageEnvelope();
		$before = $this->envelopeCount();
		$GLOBALS['_sa_insert_unique_fail'] = true;

		$res = $this->api->restore_snapshot( $this->request( array( 'id' => $env['id'] ) ) );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_log_failed', $res->get_error_code() );
		$this->assertSame( $before, $this->envelopeCount(), 'nothing was captured' );
		$this->assertSame( '[{"v":2}]', get_post_meta( 7, '_elementor_data', true ), 'nothing was restored' );
	}

	public function test_a_creation_restore_through_rest_captures_the_created_posts_first(): void {
		$this->seedPost( 511, 'page' );
		update_post_meta( 511, '_elementor_data', '[{"made":true}]' );
		$snaps = new Aura_Worker_Snapshots();
		$rec   = $snaps->snapshot_creation( array( 511 ), 'page', array( 'seq' => 1, 'ability' => 'elementor/create-page' ) );

		$res = $this->api->restore_snapshot( $this->request( array( 'id' => $rec['snapshot']['id'] ) ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'trash', get_post( 511 )->post_status );

		$row = Aura_Worker_Door_Log::get( 1 );
		$this->assertSame( 'ok', $row['result'] );
		$this->assertSame( array( 511 ), $row['trashed'] );

		$pre = $snaps->get( $row['snapshot_id'] );
		$this->assertSame( 'creation_restore', $pre['door_kind'] );
		$this->assertSame( array( 511 ), $pre['targets'] );

		// Restoring THAT brings the created page back out of the trash.
		$this->assertTrue( $snaps->restore( $pre['id'] )['success'] );
		$this->assertSame( 'publish', get_post( 511 )->post_status );
		$this->assertSame( '[{"made":true}]', get_post_meta( 511, '_elementor_data', true ) );
	}

	/* ------------------------------------------------------------------ */
	/* (e2) a restore is judged on what the ENVELOPE touches (Ruling P12)   */
	/* ------------------------------------------------------------------ */

	public function test_a_block_rule_on_the_targeted_page_refuses_a_door_restore(): void {
		$env    = $this->pageEnvelope();
		$before = $this->envelopeCount();
		$this->installRuleset(
			array(
				array( 'key' => 'rule/keep-7', 'effect' => 'block', 'target' => array( 'type' => 'page', 'id' => '7' ), 'reason' => 'the client signed off on this page' ),
			)
		);

		$res = $this->api->restore_snapshot( $this->request( array( 'id' => $env['id'], 'aura_ref' => 'act_9' ) ) );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_rule_blocked', $res->get_error_code() );
		$this->assertSame( 403, $res->get_error_data()['status'] );
		$this->assertSame( $before, $this->envelopeCount(), 'nothing was captured' );
		$this->assertSame( '[{"v":2}]', get_post_meta( 7, '_elementor_data', true ), 'nothing was restored' );

		$row = Aura_Worker_Door_Log::get( 1 );
		$this->assertSame( 'aura/restore', $row['ability'] );
		$this->assertSame( 'refused', $row['result'] );
		$this->assertSame( 'block', $row['verdict'] );
		$this->assertSame( 'rule/keep-7', $row['rule_key'] );
		$this->assertSame( array( array( 'type' => 'page', 'id' => '7' ) ), $row['touches'] );
		$this->assertSame( $env['id'], $row['restore_of'] );
		$this->assertNull( Aura_Worker_Door_Log::get( 2 ), 'no restore entry was reserved beside the refusal' );
	}

	public function test_a_block_rule_on_the_design_system_refuses_a_design_system_restore(): void {
		$this->seedDesignSystem();
		$snap = $this->snapshotFor( 'elementor/manage-classes', array( array( 'type' => 'design_system', 'id' => '*' ) ), array() );
		update_post_meta( 100, '_elementor_global_classes_order', 'a,changed' );
		$before = $this->envelopeCount();
		$this->installRuleset(
			array(
				array( 'key' => 'rule/freeze-ds', 'effect' => 'block', 'target' => array( 'type' => 'design_system' ), 'reason' => 'brand review' ),
			)
		);

		$res = $this->api->restore_snapshot( $this->request( array( 'id' => $snap['snapshot']['id'] ) ) );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_rule_blocked', $res->get_error_code() );
		$this->assertSame( $before, $this->envelopeCount(), 'nothing was captured' );
		$this->assertSame( 'a,changed', get_post_meta( 100, '_elementor_global_classes_order', true ), 'nothing was restored' );
		$row = Aura_Worker_Door_Log::get( 1 );
		$this->assertSame( 'refused', $row['result'] );
		$this->assertSame( array( array( 'type' => 'design_system', 'id' => '*' ) ), $row['touches'] );
	}

	public function test_a_block_rule_on_a_created_page_refuses_undoing_that_creation(): void {
		$this->seedPost( 511, 'page' );
		$snaps = new Aura_Worker_Snapshots();
		$rec   = $snaps->snapshot_creation( array( 511 ), 'page', array( 'seq' => 1, 'ability' => 'elementor/create-page' ) );
		$this->installRuleset(
			array(
				array( 'key' => 'rule/keep-511', 'effect' => 'block', 'target' => array( 'type' => 'page', 'id' => '511' ), 'reason' => 'live' ),
			)
		);

		$res = $this->api->restore_snapshot( $this->request( array( 'id' => $rec['snapshot']['id'] ) ) );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_rule_blocked', $res->get_error_code() );
		$this->assertSame( 'publish', get_post( 511 )->post_status, 'the protected page was not trashed' );
		$this->assertSame( array( array( 'type' => 'page', 'id' => '511' ) ), Aura_Worker_Door_Log::get( 1 )['touches'] );
	}

	/**
	 * A component write is judged on `design_system:*` (touches_for()'s
	 * `component` case), so the restore that puts that component back must be
	 * judged on it too — otherwise a rule that stopped the write cannot stop
	 * the undo of it. A component restore declares BOTH: its target page and
	 * the design system.
	 */
	public function test_a_block_rule_on_the_design_system_refuses_a_component_restore(): void {
		$this->seedPost( 56, Aura_Worker_Elementor_Door::CPT_COMPONENT );
		update_post_meta( 56, '_elementor_data', '[{"c":1}]' );
		$snap = $this->snapshotFor( 'elementor/manage-component', array( array( 'type' => 'design_system', 'id' => '*' ) ), array( 'id' => 56 ) );
		update_post_meta( 56, '_elementor_data', '[{"c":2}]' );
		$before = $this->envelopeCount();
		$this->installRuleset(
			array(
				array( 'key' => 'rule/freeze-ds', 'effect' => 'block', 'target' => array( 'type' => 'design_system' ), 'reason' => 'brand review' ),
			)
		);

		$res = $this->api->restore_snapshot( $this->request( array( 'id' => $snap['snapshot']['id'] ) ) );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_rule_blocked', $res->get_error_code() );
		$this->assertSame( 403, $res->get_error_data()['status'] );
		$this->assertSame( $before, $this->envelopeCount(), 'nothing was captured' );
		$this->assertSame( '[{"c":2}]', get_post_meta( 56, '_elementor_data', true ), 'nothing was restored' );
		$row = Aura_Worker_Door_Log::get( 1 );
		$this->assertSame( 'refused', $row['result'] );
		$this->assertSame(
			array(
				array( 'type' => 'page', 'id' => '56' ),
				array( 'type' => 'design_system', 'id' => '*' ),
			),
			$row['touches'],
			'the component and the design system, exactly as its write declared'
		);
	}

	/**
	 * A `manage-component` call naming no id CREATES the component, so its
	 * envelope is a `creation` — and undoing it TRASHES that component. The
	 * write was governed as `design_system:*`; so is the undo of it. Read off
	 * the envelope's own `post_type`, which snapshot_creation() stores.
	 */
	public function test_a_block_rule_on_the_design_system_refuses_undoing_a_component_creation(): void {
		$this->seedPost( 57, Aura_Worker_Elementor_Door::CPT_COMPONENT );
		$snaps = new Aura_Worker_Snapshots();
		$rec   = $snaps->snapshot_creation( array( 57 ), Aura_Worker_Elementor_Door::CPT_COMPONENT, array( 'seq' => 1, 'ability' => 'elementor/manage-component' ) );
		$this->installRuleset(
			array(
				array( 'key' => 'rule/freeze-ds', 'effect' => 'block', 'target' => array( 'type' => 'design_system' ), 'reason' => 'brand review' ),
			)
		);

		$res = $this->api->restore_snapshot( $this->request( array( 'id' => $rec['snapshot']['id'] ) ) );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_rule_blocked', $res->get_error_code() );
		$this->assertSame( 403, $res->get_error_data()['status'] );
		$this->assertSame( 'publish', get_post( 57 )->post_status, 'the component was not trashed' );
		$row = Aura_Worker_Door_Log::get( 1 );
		$this->assertSame( 'refused', $row['result'] );
		$this->assertSame(
			array(
				array( 'type' => 'page', 'id' => '57' ),
				array( 'type' => 'design_system', 'id' => '*' ),
			),
			$row['touches']
		);
	}

	/** The fallback witness: an envelope with no post_type, but an ability that names one. */
	public function test_a_component_creation_with_no_post_type_is_recognised_by_its_ability(): void {
		$this->seedPost( 58, Aura_Worker_Elementor_Door::CPT_COMPONENT );
		$snaps = new Aura_Worker_Snapshots();
		$rec   = $snaps->snapshot_creation( array( 58 ), '', array( 'seq' => 1, 'ability' => 'elementor/manage-component' ) );
		$this->installRuleset(
			array(
				array( 'key' => 'rule/freeze-ds', 'effect' => 'block', 'target' => array( 'type' => 'design_system' ), 'reason' => 'brand review' ),
			)
		);

		$res = $this->api->restore_snapshot( $this->request( array( 'id' => $rec['snapshot']['id'] ) ) );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_rule_blocked', $res->get_error_code() );
		$this->assertSame( 'publish', get_post( 58 )->post_status );
	}

	/**
	 * And the second-order undo: the `creation_restore` capture taken while
	 * undoing a component creation carries that creation's ability, so
	 * restoring THAT is judged on the design system too.
	 */
	public function test_a_component_creations_own_restore_capture_is_judged_on_the_design_system(): void {
		$this->seedPost( 60, Aura_Worker_Elementor_Door::CPT_COMPONENT );
		$snaps = new Aura_Worker_Snapshots();
		$rec   = $snaps->snapshot_creation( array( 60 ), Aura_Worker_Elementor_Door::CPT_COMPONENT, array( 'seq' => 1, 'ability' => 'elementor/manage-component' ) );

		// No rule yet: the creation is undone, and a creation_restore capture
		// is taken of the component on its way to the trash.
		$this->assertSame( 200, $this->api->restore_snapshot( $this->request( array( 'id' => $rec['snapshot']['id'] ) ) )->get_status() );
		$pre = $snaps->get( Aura_Worker_Door_Log::get( 1 )['snapshot_id'] );
		$this->assertSame( 'creation_restore', $pre['door_kind'] );
		$this->assertSame( 'elementor/manage-component', $pre['door']['ability'], 'the creating ability is carried onto the capture' );
		$this->assertSame( 'trash', get_post( 60 )->post_status );

		$this->installRuleset(
			array(
				array( 'key' => 'rule/freeze-ds', 'effect' => 'block', 'target' => array( 'type' => 'design_system' ), 'reason' => 'brand review' ),
			)
		);

		$res = $this->api->restore_snapshot( $this->request( array( 'id' => $pre['id'] ) ) );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_rule_blocked', $res->get_error_code() );
		$this->assertSame( 'trash', get_post( 60 )->post_status, 'the component was not brought back' );
	}

	public function test_a_design_system_rule_does_not_reach_a_page_creations_restore(): void {
		$this->seedPost( 59, 'page' );
		$snaps = new Aura_Worker_Snapshots();
		$rec   = $snaps->snapshot_creation( array( 59 ), 'page', array( 'seq' => 1, 'ability' => 'elementor/create-page' ) );
		$this->installRuleset(
			array(
				array( 'key' => 'rule/freeze-ds', 'effect' => 'block', 'target' => array( 'type' => 'design_system' ), 'reason' => 'brand review' ),
			)
		);

		$res = $this->api->restore_snapshot( $this->request( array( 'id' => $rec['snapshot']['id'] ) ) );

		$this->assertInstanceOf( WP_REST_Response::class, $res );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'trash', get_post( 59 )->post_status, 'a page creation touches no design system' );
		$this->assertSame( array( array( 'type' => 'page', 'id' => '59' ) ), Aura_Worker_Door_Log::get( 1 )['touches'] );
	}

	public function test_a_design_system_rule_does_not_reach_a_page_restore(): void {
		$env = $this->pageEnvelope();
		$this->installRuleset(
			array(
				array( 'key' => 'rule/freeze-ds', 'effect' => 'block', 'target' => array( 'type' => 'design_system' ), 'reason' => 'brand review' ),
			)
		);

		$res = $this->api->restore_snapshot( $this->request( array( 'id' => $env['id'] ) ) );

		$this->assertInstanceOf( WP_REST_Response::class, $res );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( '[{"v":1}]', get_post_meta( 7, '_elementor_data', true ), 'a page restore touches no design system' );
		$row = Aura_Worker_Door_Log::get( 1 );
		$this->assertSame( 'ok', $row['result'] );
		$this->assertSame( 'none', $row['verdict'] );
		$this->assertSame( array( array( 'type' => 'page', 'id' => '7' ) ), $row['touches'] );
	}

	public function test_a_warn_rule_lets_the_restore_run_and_is_recorded_on_its_entry(): void {
		$env = $this->pageEnvelope();
		$this->installRuleset(
			array(
				array( 'key' => 'rule/watch-7', 'effect' => 'warn', 'target' => array( 'type' => 'page', 'id' => '7' ), 'reason' => 'tell me about it' ),
			)
		);

		$res = $this->api->restore_snapshot( $this->request( array( 'id' => $env['id'] ) ) );

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( '[{"v":1}]', get_post_meta( 7, '_elementor_data', true ), 'a warn does not stop a restore' );
		$row = Aura_Worker_Door_Log::get( 1 );
		$this->assertSame( 'ok', $row['result'] );
		$this->assertSame( 'warn', $row['verdict'] );
		$this->assertSame( 'rule/watch-7', $row['rule_key'] );
		$this->assertSame( 'rule/watch-7', $row['rule']['key'] );
		$this->assertSame( 'tell me about it', $row['rule']['reason'] );
		$this->assertNotSame( '', (string) $row['rule']['ruleHash'] );
	}

	public function test_a_ruleset_that_matches_nothing_leaves_the_restore_entry_verdict_none(): void {
		$env = $this->pageEnvelope();
		$this->installRuleset(
			array(
				array( 'key' => 'rule/other', 'effect' => 'block', 'target' => array( 'type' => 'page', 'id' => '99' ), 'reason' => 'not this one' ),
			)
		);

		$res = $this->api->restore_snapshot( $this->request( array( 'id' => $env['id'] ) ) );

		$this->assertSame( 200, $res->get_status() );
		$row = Aura_Worker_Door_Log::get( 1 );
		$this->assertSame( 'ok', $row['result'] );
		$this->assertSame( 'none', $row['verdict'] );
		$this->assertSame( array( array( 'type' => 'page', 'id' => '7' ) ), $row['touches'] );
	}

	public function test_a_hostile_correlation_id_reaches_the_row_stripped(): void {
		$env = $this->pageEnvelope();
		// TWO strippers, in the order the request meets them: the handler's
		// own sanitize_text_field() — which is what the route's
		// sanitize_callback does in production, and now also what is bound
		// into the grant — takes the tag out, and the governor's allowlist
		// takes everything outside [A-Za-z0-9_-] out of what is left.
		$this->api->restore_snapshot( $this->request( array( 'id' => $env['id'], 'aura_ref' => 'act/../<script>-1' ) ) );
		$this->assertSame( 'act-1', Aura_Worker_Door_Log::get( 1 )['ref'] );
	}

	public function test_no_correlation_id_leaves_the_ref_null(): void {
		$env = $this->pageEnvelope();
		$this->api->restore_snapshot( $this->request( array( 'id' => $env['id'] ) ) );
		$this->assertNull( Aura_Worker_Door_Log::get( 1 )['ref'] );
	}

	public function test_a_door_restore_that_fails_settles_the_entry_failed(): void {
		$env = $this->pageEnvelope();
		// The envelope IS here; its payload is not — an execution failure.
		unlink( $env['payload_path'] );

		$res = $this->api->restore_snapshot( $this->request( array( 'id' => $env['id'] ) ) );
		$this->assertSame( 500, $res->get_status() );

		$row = Aura_Worker_Door_Log::get( 1 );
		$this->assertSame( 'failed', $row['result'] );
		$this->assertSame( 'Snapshot payload missing.', $row['error'] );
		$this->assertNotSame( '', (string) $row['snapshot_id'], 'the pre-restore capture still happened, and is still named' );
		$this->assertSame( '[{"v":2}]', get_post_meta( 7, '_elementor_data', true ), 'nothing was restored' );
	}

	/**
	 * The write half of Ruling P15: a foreign blog's envelope must not be
	 * written over THIS blog either — its ids mean nothing here.
	 */
	public function test_a_restore_of_another_blogs_envelope_is_refused_before_anything_is_captured(): void {
		$GLOBALS['_current_blog_id'] = 2;
		$env                         = $this->pageEnvelope();
		$GLOBALS['_current_blog_id'] = 1;
		$before                      = $this->envelopeCount();

		$res = $this->api->restore_snapshot( $this->request( array( 'id' => $env['id'] ) ) );

		$this->assertInstanceOf( WP_REST_Response::class, $res );
		$this->assertSame( 409, $res->get_status() );
		$this->assertFalse( $res->data['success'] );
		$this->assertSame( 'Snapshot belongs to another site.', $res->data['error'] );
		$this->assertSame( '[{"v":2}]', get_post_meta( 7, '_elementor_data', true ), 'nothing was restored' );
		$this->assertSame( $before, $this->envelopeCount(), 'nothing was captured' );
		$this->assertNull( Aura_Worker_Door_Log::get( 1 ), 'and no restore entry was opened' );
	}

	public function test_a_restore_of_this_blogs_envelope_is_unaffected(): void {
		$env = $this->pageEnvelope();

		$res = $this->api->restore_snapshot( $this->request( array( 'id' => $env['id'] ) ) );

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( '[{"v":1}]', get_post_meta( 7, '_elementor_data', true ) );
	}

	/** The rolling counter bump_counter() writes. */
	private function counter( string $name ): int {
		return (int) get_option( 'aura_worker_door_c_' . $name . '_h' . (int) floor( time() / HOUR_IN_SECONDS ), 0 );
	}

	/**
	 * The restore RAN and the log could not say so (Ruling P19). Answering
	 * 200 would tell Aura the rollback is recorded while the entry sits
	 * pending, to be reconciled `interrupted` ten minutes later.
	 */
	public function test_a_restore_whose_entry_cannot_be_settled_answers_may_have_run(): void {
		$env = $this->pageEnvelope();
		// Only the terminal settle fails; the reservation and the pre-restore
		// patch still land.
		$GLOBALS['_sa_option_cas_fail']['aura_worker_door_log_1'] = static function ( $value ) {
			return false !== strpos( (string) $value, 'settled_at' );
		};

		$res = $this->api->restore_snapshot( $this->request( array( 'id' => $env['id'] ) ) );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_log_failed', $res->get_error_code() );
		$this->assertSame( 503, $res->get_error_data()['status'] );
		$this->assertTrue( $res->get_error_data()['may_have_run'] );
		$this->assertSame( 1, $res->get_error_data()['seq'] );
		$this->assertSame( '[{"v":1}]', get_post_meta( 7, '_elementor_data', true ), 'the content WAS restored' );
		$this->assertSame( 'pending', Aura_Worker_Door_Log::get( 1 )['result'], 'left for the reconciler' );
		$this->assertSame( 1, $this->counter( 'log_ungoverned' ) );
	}

	/** And a restore that never ran says THAT, rather than borrowing the doubt. */
	public function test_a_restore_that_never_ran_and_cannot_be_settled_says_may_have_run_false(): void {
		$env = $this->pageEnvelope();
		// The pre-restore id cannot be recorded — and neither can the
		// `failed` settle that refusal writes.
		$GLOBALS['_sa_option_cas_fail']['aura_worker_door_log_1'] = static function ( $value ) {
			return false !== strpos( (string) $value, 'snapshot_id' );
		};

		$res = $this->api->restore_snapshot( $this->request( array( 'id' => $env['id'] ) ) );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_log_failed', $res->get_error_code() );
		$this->assertFalse( $res->get_error_data()['may_have_run'] );
		$this->assertSame( '[{"v":2}]', get_post_meta( 7, '_elementor_data', true ), 'nothing was restored' );
		$this->assertSame( 1, $this->counter( 'log_ungoverned' ) );
	}

	public function test_a_missing_envelope_is_404_and_an_execution_failure_is_500(): void {
		$res = $this->api->restore_snapshot( $this->request( array( 'id' => 'snap_nope' ) ) );
		$this->assertSame( 404, $res->get_status() );

		// An envelope that IS here but whose target is gone: 500, never 404.
		$this->seedPost( 61, 'page' );
		update_post_meta( 61, '_elementor_data', 'x' );
		$snaps = new Aura_Worker_Snapshots();
		$env   = $snaps->snapshot_meta( 61, '_elementor_data' );
		wp_delete_post( 61, true );
		$res = $this->api->restore_snapshot( $this->request( array( 'id' => $env['snapshot']['id'] ) ) );
		$this->assertSame( 500, $res->get_status() );
	}

	/* ------------------------------------------------------------------ */
	/* (f) retention                                                       */
	/* ------------------------------------------------------------------ */

	public function test_pruning_touches_only_door_envelopes(): void {
		$this->seedPost( 7, 'page', 'draft' );
		$snaps = new Aura_Worker_Snapshots();
		$door  = $this->snapshotFor( 'elementor/manage-elements', array( array( 'type' => 'page', 'id' => '7' ) ), array( 'post_id' => 7 ) );

		file_put_contents( WP_CONTENT_DIR . '/t.php', 'x' );
		$file = $snaps->snapshot_file( WP_CONTENT_DIR . '/t.php' );
		$fresh = $this->snapshotFor( 'elementor/manage-elements', array( array( 'type' => 'page', 'id' => '7' ) ), array( 'post_id' => 7 ) );

		$this->ageEnvelope( $door['snapshot']['id'], 31 );
		$this->ageEnvelope( $file['snapshot']['id'], 31 );

		$this->assertSame( 1, $snaps->prune_older_than( 30, Aura_Worker_Snapshots::DOOR_KINDS ) );
		$this->assertNull( $snaps->get( $door['snapshot']['id'] ), 'the 31-day-old door envelope is gone' );
		$this->assertNotNull( $snaps->get( $file['snapshot']['id'] ), 'a `file` envelope is never pruned' );
		$this->assertNotNull( $snaps->get( $fresh['snapshot']['id'] ), 'a fresh door envelope stays' );
	}

	public function test_pruning_removes_the_payload_too(): void {
		$this->seedPost( 7, 'page', 'draft' );
		$snaps = new Aura_Worker_Snapshots();
		$door  = $this->snapshotFor( 'elementor/manage-elements', array( array( 'type' => 'page', 'id' => '7' ) ), array( 'post_id' => 7 ) );
		$payload = $door['snapshot']['payload_path'];
		$this->assertFileExists( $payload );

		$this->ageEnvelope( $door['snapshot']['id'], 40 );
		$snaps->prune_older_than( 30, Aura_Worker_Snapshots::DOOR_KINDS );
		$this->assertFileDoesNotExist( $payload );
	}
}
