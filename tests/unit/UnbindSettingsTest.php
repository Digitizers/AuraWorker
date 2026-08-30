<?php
/**
 * THE OPERATOR'S SCREEN (#434 Task 9) — "Disconnected by Aura" and
 * *Remove remaining Aura data*.
 *
 * Every earlier task in this plan deliberately chose to STOP rather than guess:
 * Phase B will not delete a credential it cannot attribute, the core-REST seam
 * refuses everything while the marker is unreadable, and both rebinds refuse a
 * site that still owes something. Each of those choices is safe only because
 * somewhere there is a way out that does not involve editing the options table
 * by hand. This file is that way out, and the properties it pins are the ones
 * that make it safe to offer:
 *
 *  - the marker is deleted only on PROOF that nothing it names is left, the
 *    site token included — which leftovers() can never report on;
 *  - an Application Password the marker could not attribute is removed by
 *    asking the whole table, once, on the operator's explicit command — never
 *    by guessing an owner;
 *  - what the screen SAYS is bounded by what the site could actually read.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class UnbindSettingsTest extends TestCase {

	/** The administrator this site's binding was made by. */
	const ADMIN = 3;

	/** The Application Password the connect minted, owner recorded. */
	const MANAGED = 'uuid-old';

	protected function setUp(): void {
		sa_reset_state();
		$GLOBALS['_admins'] = array( self::ADMIN );
		// The gateway key as a plain row, not sa_install_gateway_key(): nothing
		// here verifies a signature, and this suite must not skip itself on a
		// host without ext-sodium. Phase B step (4) only cares that the row is
		// there to delete.
		$GLOBALS['_options']['aura_worker_grant_pubkey']   = 'not-a-real-key';
		$GLOBALS['_options']['aura_worker_dashboard_url']  = 'https://app.my-aura.app';
		$GLOBALS['_options']['aura_worker_connect_user_id'] = self::ADMIN;
		sa_token_hash(); // installs the site token row
		sa_set_managed_app_password( self::ADMIN, self::MANAGED );
		sa_set_marker(
			array(
				'app_password_uuids' => array( self::MANAGED ),
				'app_password_users' => array( self::MANAGED => self::ADMIN ),
			)
		);
	}

	/** The settings panel's HTML. */
	private function panel(): string {
		return sa_capture(
			static function () {
				( new Aura_Worker_Magic_Link() )->render_connect_section();
			}
		);
	}

	/** The marker EXACTLY as stored — read() normalises, and this must not. */
	private function stored_marker() {
		return maybe_unserialize( sa_read_option_uncached( Aura_Worker_Unbind::OPTION ) );
	}

	/** Run the teardown as that user and return the JSON it answered with. */
	private function remove( int $user = self::ADMIN ): array {
		return sa_admin_ajax_call( 'aura_worker_remove_aura_data', $user );
	}

	/* ------------------------------------------------------------------ */
	/* The panel                                                           */
	/* ------------------------------------------------------------------ */

	public function test_panel_shows_disconnected_at_and_the_hint_while_a_password_remains(): void {
		$html = $this->panel();
		$this->assertStringContainsString( 'Disconnected by Aura', $html );
		$this->assertStringContainsString( '2026-08-29', $html );
		$this->assertStringContainsString( 'Remove remaining Aura data', $html );
	}

	/**
	 * The precedence, stated as the thing it prevents. `aura_worker_dashboard_url`
	 * and the token are both still there while Phase B is outstanding — that is
	 * the state Phase A leaves — so the screen's ordinary "connected" reasoning
	 * would paint a green check and a dashboard URL over a site that refuses
	 * every write at both boundaries.
	 */
	public function test_a_disconnected_site_is_never_painted_as_connected(): void {
		$this->assertNotFalse( get_option( 'aura_worker_dashboard_url' ), 'the departed URL is still there' );
		$html = $this->panel();
		$this->assertStringNotContainsString( 'Connected to Aura dashboard', $html );
		$this->assertStringNotContainsString( 'dashicons-yes-alt', $html );
	}

	public function test_panel_hides_the_button_once_nothing_remains(): void {
		$fence = Aura_Worker_Magic_Link::claim_site();
		$this->assertTrue( Aura_Worker_Unbind::cleanup( true, $fence ) );
		Aura_Worker_Magic_Link::release_site( $fence );

		$html = $this->panel();
		$this->assertStringContainsString( 'Disconnected by Aura', $html );
		$this->assertStringNotContainsString( 'Remove remaining Aura data', $html );
	}

	/**
	 * Steps (1)-(4) done, the token still there. leftovers() is empty and says
	 * nothing about the token, so a panel that consulted it alone would hide
	 * the one control that can still delete one.
	 */
	public function test_the_button_stays_while_only_the_token_remains(): void {
		$fence = Aura_Worker_Magic_Link::claim_site();
		Aura_Worker_Unbind::cleanup( false, $fence ); // never the token
		Aura_Worker_Magic_Link::release_site( $fence );

		$this->assertSame( array(), Aura_Worker_Unbind::leftovers(), 'steps (1)-(4) are settled' );
		$this->assertNotNull( Aura_Worker_Rules::read_option_uncached( 'aura_worker_site_token' ) );
		$this->assertStringContainsString( 'Remove remaining Aura data', $this->panel() );
	}

	/**
	 * A token row nobody could read is not a token row that is gone. The
	 * control stays on offer.
	 */
	public function test_an_unreadable_token_row_still_offers_the_button(): void {
		$fence = Aura_Worker_Magic_Link::claim_site();
		Aura_Worker_Unbind::cleanup( true, $fence );
		Aura_Worker_Magic_Link::release_site( $fence );
		$GLOBALS['_sa_option_read_fail']['aura_worker_site_token'] = true;

		$this->assertStringContainsString( 'Remove remaining Aura data', $this->panel() );
	}

	/**
	 * leftovers() fails CLOSED and names all four steps for a marker it could
	 * not read. That is right for a gate and a lie on a screen: nobody has
	 * established that this site owes four specific things. It says what is
	 * true — it could not look — and still offers the control.
	 */
	public function test_an_unreadable_marker_is_reported_as_unknown_not_as_four_debts(): void {
		$GLOBALS['_sa_option_read_fail'][ Aura_Worker_Unbind::OPTION ] = true;
		$this->assertCount( 4, Aura_Worker_Unbind::leftovers(), 'the gate still fails closed' );

		$html = $this->panel();
		$this->assertStringContainsString( 'Disconnected by Aura', $html );
		$this->assertStringNotContainsString( '2026-08-29', $html, 'no marker was read, so no moment may be printed' );
		$this->assertStringContainsString( 'cannot read its own disconnect record', $html );
		$this->assertStringNotContainsString( 'grant_pubkey', $html );
		$this->assertStringContainsString( 'Remove remaining Aura data', $html );
	}

	/* ------------------------------------------------------------------ */
	/* The teardown                                                        */
	/* ------------------------------------------------------------------ */

	public function test_remove_aura_data_runs_cleanup_deletes_token_and_marker(): void {
		$out = $this->remove();

		$this->assertTrue( $out['success'] );
		$this->assertSame( array( 'removed' => true ), $out['data'] );
		$this->assertFalse( sa_app_password_exists( self::ADMIN, self::MANAGED ) );
		$this->assertFalse( get_option( 'aura_worker_site_token' ) );
		$this->assertFalse( Aura_Worker_Unbind::is_set() );
		$this->assertFalse( get_option( Aura_Worker_Magic_Link::SITE_CLAIM ), 'the site claim is released' );
	}

	/** The order, witnessed: the token is entered last, and only then. */
	public function test_the_teardown_deletes_the_token_last(): void {
		$this->remove();
		$this->assertSame( array( 'revoke', 'options', 'ruleset', 'grant', 'token' ), $GLOBALS['_unbind_trace'] );
	}

	public function test_remove_aura_data_requires_manage_options(): void {
		$out = $this->remove( 7 ); // a subscriber

		$this->assertFalse( $out['success'] );
		$this->assertSame( 403, $out['status'] );
		$this->assertTrue( Aura_Worker_Unbind::is_set() );
		$this->assertTrue( sa_app_password_exists( self::ADMIN, self::MANAGED ), 'nothing was revoked' );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ) );
	}

	public function test_remove_aura_data_requires_a_nonce(): void {
		$GLOBALS['_sa_ajax_referer_fails'] = true;
		$out                               = $this->remove(); // a real administrator
		$GLOBALS['_sa_ajax_referer_fails'] = false;

		$this->assertFalse( $out['success'] );
		$this->assertTrue( Aura_Worker_Unbind::is_set(), 'a cross-site POST must not tear the site down' );
		$this->assertTrue( sa_app_password_exists( self::ADMIN, self::MANAGED ) );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ) );
	}

	/**
	 * THE SAFETY PROPERTY. `cleanup()` returns true only after an uncached read
	 * has proven the token row gone; a delete that did not land answers false
	 * with an EMPTY leftovers list, because leftovers() tracks steps (1)-(4)
	 * and can never name the token. Deleting the marker on that answer would
	 * strand a live site token with nothing left to name it.
	 */
	public function test_remove_aura_data_keeps_the_marker_when_the_token_delete_fails(): void {
		$GLOBALS['_sa_option_delete_fail']['aura_worker_site_token'] = true;

		$out = $this->remove();

		$this->assertFalse( $out['success'] );
		$this->assertSame( 409, $out['status'] );
		$this->assertSame( 'aura_unbind_incomplete', $out['data']['code'] );
		$this->assertContains( 'token', $out['data']['leftover'] );
		$this->assertSame( array( 'token' ), $out['data']['leftover'], 'steps (1)-(4) really did complete' );
		$this->assertTrue( Aura_Worker_Unbind::is_set(), 'a usable token must stay refused' );
		$this->assertNotNull( Aura_Worker_Rules::read_option_uncached( 'aura_worker_site_token' ) );
	}

	public function test_remove_aura_data_with_a_leftover_keeps_the_marker(): void {
		$GLOBALS['_fail_delete_app_password'] = self::MANAGED;

		$out = $this->remove();

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'aura_unbind_incomplete', $out['data']['code'] );
		$this->assertSame( array( 'app_passwords', 'token' ), $out['data']['leftover'] );
		$this->assertTrue( Aura_Worker_Unbind::is_set() );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ), 'the token outlives every step that can still fail' );
		$this->assertNotContains( 'token', $GLOBALS['_unbind_trace'], 'step (5) was never entered' );
	}

	/** A site that is not disconnected has nothing to tear down. */
	public function test_remove_aura_data_refuses_when_there_is_no_marker(): void {
		sa_clear_marker();

		$out = $this->remove();

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'aura_not_unbound', $out['data']['code'] );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ) );
		$this->assertTrue( sa_app_password_exists( self::ADMIN, self::MANAGED ) );
	}

	/**
	 * An unreadable marker is refused with its OWN story, the way
	 * finish_before_rebind() refuses a rebind (Task 7): "the record could not
	 * be read" — not `aura_unbind_incomplete` with four steps nobody has
	 * established are owed.
	 */
	public function test_remove_aura_data_refuses_an_unreadable_marker_with_its_own_code(): void {
		$GLOBALS['_sa_option_read_fail'][ Aura_Worker_Unbind::OPTION ] = true;

		$out = $this->remove();

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'aura_unbind_unreadable', $out['data']['code'] );
		$this->assertArrayNotHasKey( 'leftover', $out['data'] );
		$this->assertSame( array(), $GLOBALS['_unbind_trace'], 'nothing was torn down' );
	}

	/* ------------------------------------------------------------------ */
	/* The unattributed Application Password — obligations 1 and 3          */
	/* ------------------------------------------------------------------ */

	/**
	 * THE DEAD END TASK 4 CHOSE, AND THE WAY OUT.
	 *
	 * Phase A could not name an owner for this password, so Phase B attempts no
	 * proof about it at all: leftovers() owes `app_passwords` unconditionally,
	 * cleanup() never reaches step (5), and the tombstone pends forever. The
	 * holder here is a NON-administrator, which the enumeration Task 4 deleted
	 * would never have found either.
	 *
	 * The operator's teardown asks the whole table instead, deletes the
	 * credential wherever it is, and proves it gone with a second statement.
	 * That is also what closes the XML-RPC door Task 6 recorded: it is live
	 * only on this path, and it closes because the credential is REMOVED, not
	 * because another entry point was guarded.
	 */
	public function test_an_unattributed_password_is_found_site_wide_and_removed(): void {
		sa_add_app_password( 9, 'uuid-ghost' );
		sa_set_marker(
			array(
				'app_password_uuids' => array( 'uuid-ghost' ),
				'app_password_users' => array(),
			)
		);
		$this->assertContains( 'app_passwords', Aura_Worker_Unbind::leftovers(), 'unattributed: owed forever, by design' );

		$out = $this->remove();

		$this->assertTrue( $out['success'] );
		$this->assertFalse( sa_app_password_exists( 9, 'uuid-ghost' ), 'the credential is gone from a user nobody named' );
		$this->assertFalse( Aura_Worker_Unbind::is_set() );
		$this->assertFalse( get_option( 'aura_worker_site_token' ) );
	}

	/**
	 * The other half of the same resolution: a uuid no list on this site
	 * carries names no credential, so it is retired from the marker — and the
	 * RAW row is what says so, because read() normalises.
	 *
	 * Step (4) is made to fail so the marker survives to be inspected.
	 */
	public function test_a_password_proven_to_exist_nowhere_is_retired_from_the_marker(): void {
		sa_set_marker(
			array(
				'app_password_uuids' => array( 'uuid-ghost', self::MANAGED ),
				'app_password_users' => array( self::MANAGED => self::ADMIN ),
			)
		);
		$GLOBALS['_sa_option_delete_fail']['aura_worker_grant_pubkey'] = true;

		$out = $this->remove();

		$this->assertFalse( $out['success'] );
		$this->assertSame( array( 'grant_pubkey', 'token' ), $out['data']['leftover'], 'the password debt is settled; the key is not' );
		$this->assertSame( array(), $out['data']['unattributed'] );

		$raw = $this->stored_marker();
		$this->assertSame( array( self::MANAGED ), $raw['app_password_uuids'], 'the ghost is retired from the stored row' );
		$this->assertArrayNotHasKey( 'uuid-ghost', $raw['app_password_users'], 'and no owner was invented for it' );
	}

	/**
	 * A STATEMENT THAT PROVED NOTHING IS NOT AN ABSENCE. The scan fails, so the
	 * uuid keeps its unknown, the marker is untouched, and the operator is told
	 * which credential to revoke by hand rather than being told it is gone.
	 */
	public function test_an_unprovable_scan_changes_nothing_and_names_the_uuid(): void {
		sa_add_app_password( 9, 'uuid-ghost' );
		sa_set_marker(
			array(
				'app_password_uuids' => array( 'uuid-ghost' ),
				'app_password_users' => array(),
			)
		);
		$GLOBALS['_sa_app_password_scan_fail'] = true;

		$out = $this->remove();

		$this->assertFalse( $out['success'] );
		$this->assertSame( array( 'uuid-ghost' ), $out['data']['unattributed'] );
		$this->assertContains( 'app_passwords', $out['data']['leftover'] );
		$this->assertStringContainsString( 'uuid-ghost', $out['data']['message'], 'the operator is told what to revoke' );
		$this->assertTrue( sa_app_password_exists( 9, 'uuid-ghost' ) );
		$this->assertSame( array( 'uuid-ghost' ), $this->stored_marker()['app_password_uuids'] );
		$this->assertTrue( Aura_Worker_Unbind::is_set() );
	}

	/**
	 * The delete is not the proof — the second statement is. Here the credential
	 * is found and the delete refuses, so the uuid stays named and the marker
	 * keeps it.
	 */
	public function test_a_delete_that_did_not_land_leaves_the_uuid_owed(): void {
		sa_add_app_password( 9, 'uuid-ghost' );
		sa_set_marker(
			array(
				'app_password_uuids' => array( 'uuid-ghost' ),
				'app_password_users' => array(),
			)
		);
		$GLOBALS['_fail_delete_app_password'] = 'uuid-ghost';

		$out = $this->remove();

		$this->assertFalse( $out['success'] );
		$this->assertSame( array( 'uuid-ghost' ), $out['data']['unattributed'] );
		$this->assertTrue( sa_app_password_exists( 9, 'uuid-ghost' ) );
		$this->assertSame( array( 'uuid-ghost' ), $this->stored_marker()['app_password_uuids'] );
	}

	/**
	 * The retirement is believed only once the row says so. The marker rewrite
	 * is refused here, so the uuid is still named on disk — and reporting it as
	 * settled would hand the next teardown a marker that still owes it.
	 */
	public function test_a_retirement_that_did_not_land_is_reported_as_still_owed(): void {
		sa_set_marker(
			array(
				'app_password_uuids' => array( 'uuid-ghost' ),
				'app_password_users' => array(),
			)
		);
		$GLOBALS['_sa_option_write_fail'][ Aura_Worker_Unbind::OPTION ] = true;

		$out = $this->remove();

		$this->assertFalse( $out['success'] );
		$this->assertSame( array( 'uuid-ghost' ), $out['data']['unattributed'] );
		$this->assertSame( array( 'uuid-ghost' ), $this->stored_marker()['app_password_uuids'] );
	}

	/**
	 * ROWS MATCHED, AND THE ANSWER NAMED NOBODY. GROUP_CONCAT truncates at
	 * `group_concat_max_len` and MySQL reports it in a warning nothing here
	 * reads, so a scan CAN come back with a list this code cannot use. That is
	 * an unreadable answer, not the empty one — the statement found rows — and
	 * reading it as "nobody holds it" would retire a uuid on evidence that says
	 * the opposite.
	 */
	public function test_a_scan_answer_that_names_nobody_is_not_an_absence(): void {
		sa_add_app_password( 9, 'uuid-ghost' );
		sa_set_marker(
			array(
				'app_password_uuids' => array( 'uuid-ghost' ),
				'app_password_users' => array(),
			)
		);
		$GLOBALS['_sa_app_password_scan_answer'] = '0'; // a row, naming no usable user

		$out = $this->remove();

		$this->assertFalse( $out['success'] );
		$this->assertSame( array( 'uuid-ghost' ), $out['data']['unattributed'] );
		$this->assertSame( array( 'uuid-ghost' ), $this->stored_marker()['app_password_uuids'] );
		$this->assertTrue( Aura_Worker_Unbind::is_set() );
	}

	/**
	 * The token half of the leftover list fails closed too: a row the teardown
	 * could not read is not a row it may report gone. Without this, the one
	 * thing leftovers() can never name would go unnamed exactly when nobody
	 * knows whether it is there.
	 */
	public function test_an_unreadable_token_row_is_named_as_a_leftover(): void {
		$GLOBALS['_sa_option_read_fail']['aura_worker_site_token'] = true;

		$out = $this->remove();

		$this->assertFalse( $out['success'] );
		$this->assertContains( 'token', $out['data']['leftover'] );
		$this->assertTrue( Aura_Worker_Unbind::is_set() );
	}

	/**
	 * An attributed password is left to Phase B's single lookup: the site-wide
	 * statement is never issued for it, so the teardown does not become the
	 * search this plan deleted.
	 */
	public function test_an_attributed_password_is_never_scanned_for(): void {
		$this->remove();

		foreach ( $GLOBALS['_db_queries'] as $query ) {
			$this->assertStringNotContainsString( 'GROUP_CONCAT', $query, 'a recorded owner is looked up, never searched for' );
		}
	}

	/* ------------------------------------------------------------------ */
	/* The unprovable-probe breadcrumb — obligation 2                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Task 6 left the breadcrumb firing into nothing, so a tombstone that would
	 * never converge had no explanation anywhere. It is counted now — and
	 * BOUNDED: three scalars, one row, whatever the failure rate.
	 */
	public function test_an_unprovable_probe_is_counted_and_bounded(): void {
		( new Aura_Worker() )->init();

		do_action( 'aura_worker_app_password_probe_unproven', 5 );
		do_action( 'aura_worker_app_password_probe_unproven', 5 );

		$report = Aura_Worker_Magic_Link::probe_unproven_report();
		$this->assertSame( 2, $report['count'] );
		$this->assertSame( 5, $report['owner'] );
		$this->assertNotSame( '', $report['at'], 'and when it last happened' );

		update_option(
			Aura_Worker_Magic_Link::PROBE_UNPROVEN_OPTION,
			array( 'count' => Aura_Worker_Magic_Link::PROBE_UNPROVEN_MAX, 'at' => 'then', 'owner' => 5 ),
			false
		);
		do_action( 'aura_worker_app_password_probe_unproven', 5 );
		$this->assertSame(
			Aura_Worker_Magic_Link::PROBE_UNPROVEN_MAX,
			Aura_Worker_Magic_Link::probe_unproven_report()['count'],
			'the count saturates — an option must not grow with the failure rate'
		);
	}

	/** Nothing recorded is not a zero to report. */
	public function test_no_probe_failure_reports_nothing(): void {
		$this->assertNull( Aura_Worker_Magic_Link::probe_unproven_report() );
		$api  = new Aura_Worker_API( new Aura_Worker_Security() );
		$body = $api->get_status( new WP_REST_Request( 'GET', '/aura/v1/status' ) )->get_data();
		$this->assertArrayNotHasKey( 'app_password_probe_unproven', $body );
	}

	/**
	 * The half the ruling actually asked for: Aura polls `/status`, so that is
	 * where the explanation has to arrive. An object, like `unbound` beside it,
	 * so its shape never changes with its contents.
	 */
	public function test_status_carries_the_breadcrumb_to_aura(): void {
		( new Aura_Worker() )->init();
		$GLOBALS['_sa_app_password_read_fail'][ self::ADMIN ] = true;
		$GLOBALS['_sa_wpdb_query_filtered_out']               = true; // the probe's statement never runs

		Aura_Worker_Magic_Link::password_state( self::ADMIN, self::MANAGED );

		$GLOBALS['_sa_wpdb_query_filtered_out'] = false;
		$api                                    = new Aura_Worker_API( new Aura_Worker_Security() );
		$body                                   = $api->get_status( new WP_REST_Request( 'GET', '/aura/v1/status' ) )->get_data();

		$this->assertIsObject( $body['app_password_probe_unproven'] );
		$this->assertSame( 1, ( (array) $body['app_password_probe_unproven'] )['count'] );
		$this->assertSame( self::ADMIN, ( (array) $body['app_password_probe_unproven'] )['owner'] );
	}

	/**
	 * The listener only counts because something registers it. Deleting the
	 * registration, or renaming the hook, leaves every assertion above green
	 * and no site would ever explain itself.
	 */
	public function test_the_plugin_registers_the_probe_recorder(): void {
		( new Aura_Worker() )->init();

		$entries = $GLOBALS['_filters']['aura_worker_app_password_probe_unproven'] ?? array();
		$found   = array_values(
			array_filter(
				$entries,
				static function ( $entry ) {
					return isset( $entry['callback'] )
						&& array( 'Aura_Worker_Magic_Link', 'record_probe_unproven' ) === $entry['callback'];
				}
			)
		);
		$this->assertCount( 1, $found, 'registered exactly once' );
		$this->assertSame( 1, $found[0]['accepted_args'], 'the owner rides the first argument' );
	}

	/**
	 * …and outside the is_admin() branch: the probe fails on REST requests,
	 * which is where the explanation has to be recorded.
	 */
	public function test_the_probe_recorder_is_registered_on_a_front_end_request(): void {
		$GLOBALS['_is_admin'] = false;
		( new Aura_Worker() )->init();

		$this->assertNotEmpty( $GLOBALS['_filters']['aura_worker_app_password_probe_unproven'] ?? array() );
		$this->assertEmpty( $GLOBALS['_filters']['wp_ajax_aura_worker_remove_aura_data'] ?? array(), 'the teardown is admin-only' );
	}

	/* ------------------------------------------------------------------ */
	/* The fixture itself                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * sa_capture() must close its buffer on every exit. A fixture that leaks
	 * one swallows the rest of the run's output, PHPUnit's report included —
	 * the same property sa_with_plugin_file()'s cleanup is pinned for.
	 */
	public function test_the_capture_fixture_closes_its_buffer_when_the_body_throws(): void {
		$level = ob_get_level();
		try {
			sa_capture(
				static function () {
					echo 'half a panel';
					throw new RuntimeException( 'boom' );
				}
			);
			$this->fail( 'the exception must reach the caller' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}
		$this->assertSame( $level, ob_get_level(), 'the buffer is closed on the way out' );
	}
}
