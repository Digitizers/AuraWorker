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
	 * The resolution deletes credentials, so it is claim-conditional like every
	 * other lifecycle operation here. Without the claim — or holding somebody
	 * else's — it must do nothing at all: a connect installing a replacement
	 * binding beside it would otherwise have its fresh credential deleted by a
	 * teardown that no longer owns the site.
	 */
	public function test_the_resolution_does_nothing_without_the_site_claim(): void {
		sa_add_app_password( 9, 'uuid-ghost' );
		sa_set_marker(
			array(
				'app_password_uuids' => array( 'uuid-ghost' ),
				'app_password_users' => array(),
			)
		);

		$this->assertSame( array(), Aura_Worker_Unbind::resolve_unknown_owners( '' ) );
		$this->assertTrue( sa_app_password_exists( 9, 'uuid-ghost' ), 'no claim, no deletion' );

		Aura_Worker_Magic_Link::claim_site(); // somebody else holds it
		$this->assertSame( array(), Aura_Worker_Unbind::resolve_unknown_owners( 'not-my-fence' ) );
		$this->assertTrue( sa_app_password_exists( 9, 'uuid-ghost' ), 'and not on another request\'s claim either' );
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
	/* The damaged record — repair, never delete (fix round 1)             */
	/* ------------------------------------------------------------------ */

	/** Leave the marker row present but unparseable: `seq` is not an int. */
	private function damage_the_marker(): void {
		sa_set_marker( array( 'seq' => 'nine' ) );
		$read = Aura_Worker_Unbind::read();
		$this->assertTrue( is_wp_error( $read ) && Aura_Worker_Unbind::MALFORMED_CODE === $read->get_error_code() );
	}

	/**
	 * THE WORST STATE IN THE DESIGN, AND THE WAY OUT.
	 *
	 * An unparseable marker refuses every agent REST write from every
	 * Application Password on the site, Aura's and the owner's alike; both
	 * rebinds refuse; cleanup() does nothing at all. The teardown rebuilds the
	 * row from the site — the live token hash, and the credentials the name
	 * sweep proves are there — and then runs the ordinary path against it.
	 *
	 * The ghost here is on a NON-administrator and is not in the plugin's
	 * record: it exists only in the sweep.
	 */
	public function test_a_damaged_record_is_repaired_and_then_torn_down(): void {
		$GLOBALS['_app_passwords'][9][] = array( 'uuid' => 'uuid-swept', 'name' => Aura_Worker_Magic_Link::APP_PASSWORD_NAME, 'created' => time() );
		$this->damage_the_marker();

		$out = $this->remove();

		$this->assertTrue( $out['success'] );
		$this->assertFalse( sa_app_password_exists( 9, 'uuid-swept' ), 'the swept credential is gone' );
		$this->assertFalse( sa_app_password_exists( self::ADMIN, self::MANAGED ), 'and so is the recorded one' );
		$this->assertFalse( get_option( 'aura_worker_site_token' ) );
		$this->assertFalse( Aura_Worker_Unbind::is_set() );
	}

	/**
	 * THE GATE, DIRECTION ONE: a transient read failure is NOT a damaged row.
	 * read() answers one WP_Error for both, so a teardown that acted on
	 * `is_wp_error()` alone would repair — and then tear down — a healthy
	 * marker with real outstanding debts, on one database blip.
	 */
	public function test_a_transient_read_failure_repairs_nothing_and_removes_nothing(): void {
		$GLOBALS['_sa_option_read_fail'][ Aura_Worker_Unbind::OPTION ] = true;

		$out = $this->remove();

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'aura_unbind_unreadable', $out['data']['code'] );
		$GLOBALS['_sa_option_read_fail'] = array();
		$this->assertSame( 9, $this->stored_marker()['seq'], 'the healthy marker is untouched' );
		$this->assertTrue( sa_app_password_exists( self::ADMIN, self::MANAGED ) );
		$this->assertNotFalse( get_option( 'aura_worker_site_token' ) );
	}

	/** …and the repair itself refuses it, not merely the handler above it. */
	public function test_the_repair_refuses_a_read_that_did_not_complete(): void {
		$fence = Aura_Worker_Magic_Link::claim_site();
		$GLOBALS['_sa_option_read_fail'][ Aura_Worker_Unbind::OPTION ] = true;

		$this->assertFalse( Aura_Worker_Unbind::repair_malformed_marker( $fence ) );

		$GLOBALS['_sa_option_read_fail'] = array();
		Aura_Worker_Magic_Link::release_site( $fence );
		$this->assertSame( 9, $this->stored_marker()['seq'], 'nothing was rewritten' );
	}

	/** THE GATE, DIRECTION TWO: a damaged row IS repaired. */
	public function test_the_repair_rebuilds_the_row_from_the_site(): void {
		$this->damage_the_marker();
		$fence = Aura_Worker_Magic_Link::claim_site();

		$this->assertTrue( Aura_Worker_Unbind::repair_malformed_marker( $fence ) );
		Aura_Worker_Magic_Link::release_site( $fence );

		$raw = $this->stored_marker();
		$this->assertSame( sa_token_hash(), $raw['site'], 'the repaired marker names the live token' );
		$this->assertSame( 0, $raw['seq'] );
		$this->assertSame( '', $raw['site_ref'] );
		$this->assertSame( '', $raw['client'] );
		$this->assertSame( array( self::MANAGED ), $raw['app_password_uuids'] );
		$this->assertSame( array( self::MANAGED => self::ADMIN ), $raw['app_password_users'], 'each credential with its real owner' );
		$this->assertIsArray( Aura_Worker_Unbind::read(), 'and the result parses' );
	}

	/**
	 * ONE READ THAT SAYS "MALFORMED" IS ONE READ. Another request — a connect
	 * callback, an unbind retry — can land a well-formed marker in the window
	 * between them, and a repair that rewrote the row on the strength of the
	 * first read would throw away a real Phase A: its uuids, its owners, its
	 * seq.
	 */
	public function test_the_repair_reads_twice_and_yields_to_a_marker_written_between_them(): void {
		$this->damage_the_marker();
		$fence = Aura_Worker_Magic_Link::claim_site();

		$GLOBALS['_sa_after_option_read'] = static function ( $name ) {
			if ( Aura_Worker_Unbind::OPTION !== $name ) {
				return;
			}
			$GLOBALS['_sa_after_option_read'] = null; // once
			sa_set_marker( array( 'seq' => 42 ) );    // somebody else's Phase A
		};

		$this->assertFalse( Aura_Worker_Unbind::repair_malformed_marker( $fence ) );

		Aura_Worker_Magic_Link::release_site( $fence );
		$this->assertSame( 42, $this->stored_marker()['seq'], "the other request's marker survives" );
	}

	/**
	 * The repair is believed only once the row PARSES. write_under_claim()
	 * proves the row names this site at this seq — which a repair supplies by
	 * construction — so it cannot tell a marker the teardown can read from one
	 * it cannot. Here the write lands with `at` destroyed, `site` and `seq`
	 * intact: everything write_under_claim() checks still passes.
	 */
	public function test_a_repair_that_lands_unparseable_is_not_reported_as_repaired(): void {
		$this->damage_the_marker();
		$GLOBALS['_sa_option_write_divert'][ Aura_Worker_Unbind::OPTION ] = static function ( $value ) {
			$marker       = maybe_unserialize( $value );
			$marker['at'] = null; // present, wrong type: malformed again
			return maybe_serialize( $marker );
		};

		$out = $this->remove();

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'aura_unbind_unrepairable', $out['data']['code'] );
		$this->assertTrue( Aura_Worker_Unbind::is_set(), 'and the site still refuses everything' );
		$this->assertTrue( sa_app_password_exists( self::ADMIN, self::MANAGED ), 'nothing was torn down' );
	}

	/**
	 * N1, AND IT IS THIS PROJECT'S OWN FAMILY LANDING INSIDE THE REPAIR.
	 *
	 * validated() rejects a row on its five SCALARS; `app_password_uuids` is
	 * not type-checked at all, so a damaged row usually still carries a
	 * perfectly readable credential list — including the authenticating uuid
	 * Phase A appends for a password Aura never minted, under a name of
	 * somebody else's choosing, which the sweep cannot see. Rebuilding from the
	 * sweep alone dropped it, completed the teardown, lifted the refusal and
	 * left that administrator credential LIVE while reporting success.
	 *
	 * The row is merged, not replaced. The manual uuid arrives unattributed,
	 * which is not a dead end: resolve_unknown_owners() is in the same teardown
	 * and settles exactly that.
	 */
	public function test_a_manual_credential_the_damaged_row_names_is_removed_not_dropped(): void {
		sa_add_app_password( 9, 'manual-uuid-1' ); // a name of the operator's choosing
		sa_set_marker(
			array(
				'seq'                => 'nine', // the row is damaged…
				'app_password_uuids' => array( 'manual-uuid-1' ), // …but this is legible
				'app_password_users' => array(),
			)
		);

		$out = $this->remove();

		$this->assertTrue( $out['success'] );
		$this->assertFalse( sa_app_password_exists( 9, 'manual-uuid-1' ), 'the refusal must not lift over a live credential' );
		$this->assertFalse( Aura_Worker_Unbind::is_set() );
	}

	/** …and it is in the repaired row itself, unattributed rather than guessed. */
	public function test_the_repaired_row_carries_the_damaged_rows_uuids(): void {
		sa_add_app_password( 9, 'manual-uuid-1' );
		sa_set_marker(
			array(
				'seq'                => 'nine',
				'app_password_uuids' => array( 'manual-uuid-1', self::MANAGED ),
				'app_password_users' => array( self::MANAGED => self::ADMIN ),
			)
		);
		$fence = Aura_Worker_Magic_Link::claim_site();

		$this->assertTrue( Aura_Worker_Unbind::repair_malformed_marker( $fence ) );
		Aura_Worker_Magic_Link::release_site( $fence );

		$raw = $this->stored_marker();
		$this->assertContains( 'manual-uuid-1', $raw['app_password_uuids'] );
		$this->assertContains( self::MANAGED, $raw['app_password_uuids'] );
		$this->assertNull( $raw['app_password_users']['manual-uuid-1'], 'an owner nobody recorded is an explicit unknown, never a guess' );
		$this->assertSame( self::ADMIN, $raw['app_password_users'][ self::MANAGED ], 'and a known owner survives' );
	}

	/**
	 * The merge normalises the way validated() does: an owner that names nobody
	 * — 0, a word, an object — is the explicit unknown Phase A writes, not a
	 * user id Phase B would then treat as knowledge.
	 */
	public function test_an_owner_the_damaged_row_cannot_name_becomes_an_explicit_unknown(): void {
		sa_add_app_password( 9, 'manual-uuid-1' );
		sa_set_marker(
			array(
				'seq'                => 'nine',
				'app_password_uuids' => array( 'manual-uuid-1' ),
				'app_password_users' => array( 'manual-uuid-1' => 0 ),
			)
		);
		$fence = Aura_Worker_Magic_Link::claim_site();

		$this->assertTrue( Aura_Worker_Unbind::repair_malformed_marker( $fence ) );
		Aura_Worker_Magic_Link::release_site( $fence );

		$this->assertNull( $this->stored_marker()['app_password_users']['manual-uuid-1'] );
	}

	/**
	 * WHERE THE TWO SOURCES DISAGREE, THE FRESHER ONE WINS. The sweep read this
	 * owner out of that user's own list moments ago; the damaged row's copy is
	 * older and, here, unusable. Letting the row overwrite it would turn
	 * knowledge back into an unknown — Phase B's single authoritative lookup
	 * replaced by the site-wide resolution, for a credential nobody was unsure
	 * about.
	 */
	public function test_the_swept_owner_survives_a_damaged_rows_worse_one(): void {
		sa_set_marker(
			array(
				'seq'                => 'nine',
				'app_password_uuids' => array( self::MANAGED ),
				'app_password_users' => array( self::MANAGED => 'nobody' ),
			)
		);
		$fence = Aura_Worker_Magic_Link::claim_site();

		$this->assertTrue( Aura_Worker_Unbind::repair_malformed_marker( $fence ) );
		Aura_Worker_Magic_Link::release_site( $fence );

		$this->assertSame(
			array( self::MANAGED => self::ADMIN ),
			$this->stored_marker()['app_password_users'],
			'the owner the sweep proved is not replaced by one the row cannot state'
		);
	}

	/**
	 * "Legible" means exactly what validated() means by it. An owners map that
	 * is an OBJECT rather than an array is a shape this class ignores
	 * everywhere else — validated() would drop it too — so the repair does not
	 * quietly widen its own notion of readable to reach inside one. The uuid
	 * still survives, unattributed, and the teardown's site-wide resolution
	 * settles it.
	 */
	public function test_an_owners_map_that_is_not_an_array_is_not_read(): void {
		sa_add_app_password( 9, 'manual-uuid-1' );
		sa_set_marker(
			array(
				'seq'                => 'nine',
				'app_password_uuids' => array( 'manual-uuid-1' ),
				'app_password_users' => (object) array( 'manual-uuid-1' => 9 ),
			)
		);
		$fence = Aura_Worker_Magic_Link::claim_site();

		$this->assertTrue( Aura_Worker_Unbind::repair_malformed_marker( $fence ) );
		Aura_Worker_Magic_Link::release_site( $fence );

		$this->assertNull( $this->stored_marker()['app_password_users']['manual-uuid-1'] );
	}

	/**
	 * A shape the row cannot be read out of is DROPPED, never coerced: casting
	 * an array to a string is a warning and a lie, and a uuid nothing can act
	 * on would only wedge the teardown for good.
	 */
	public function test_a_uuid_list_that_cannot_be_read_is_dropped_not_guessed(): void {
		sa_set_marker(
			array(
				'seq'                => 'nine',
				'app_password_uuids' => array( array( 'nested' ), '' ),
				'app_password_users' => 'not an array either',
			)
		);
		$fence = Aura_Worker_Magic_Link::claim_site();

		$this->assertTrue( Aura_Worker_Unbind::repair_malformed_marker( $fence ) );
		Aura_Worker_Magic_Link::release_site( $fence );

		$this->assertSame( array( self::MANAGED ), $this->stored_marker()['app_password_uuids'], 'only what the sweep proved' );
	}

	/** A damaged row with no uuid list at all still repairs from the sweep. */
	public function test_a_damaged_row_with_no_uuid_list_repairs_from_the_sweep_alone(): void {
		$marker = sa_set_marker( array( 'seq' => 'nine' ) );
		unset( $marker['app_password_uuids'], $marker['app_password_users'] );
		$GLOBALS['_options'][ Aura_Worker_Unbind::OPTION ] = $marker;
		unset( $GLOBALS['_rows'][ Aura_Worker_Unbind::OPTION ] );

		$out = $this->remove();

		$this->assertTrue( $out['success'] );
		$this->assertFalse( sa_app_password_exists( self::ADMIN, self::MANAGED ) );
	}

	/**
	 * The name sweep is a superset of what Aura MINTED, not of what it
	 * RECORDED: an operator who renames the managed password takes it out of
	 * the sweep, and the plugin's own record is the only thing that still names
	 * it.
	 */
	public function test_the_repair_names_the_recorded_credential_even_when_its_name_changed(): void {
		$GLOBALS['_app_passwords'][ self::ADMIN ][0]['name'] = 'Renamed by hand';
		$this->damage_the_marker();
		$fence = Aura_Worker_Magic_Link::claim_site();

		$this->assertTrue( Aura_Worker_Unbind::repair_malformed_marker( $fence ) );
		Aura_Worker_Magic_Link::release_site( $fence );

		$this->assertSame( array( self::MANAGED => self::ADMIN ), $this->stored_marker()['app_password_users'] );
	}

	/**
	 * A repaired marker must still be the SITE's: maybe_finish() bails on a
	 * hash mismatch, so a repair that named anything but the live token would
	 * disarm the sweep it just re-enabled. A token read that did not complete
	 * therefore repairs nothing.
	 */
	public function test_the_repair_refuses_when_the_token_cannot_be_read(): void {
		$this->damage_the_marker();
		$GLOBALS['_sa_option_read_fail']['aura_worker_site_token'] = true;

		$out = $this->remove();

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'aura_unbind_unrepairable', $out['data']['code'] );
		$GLOBALS['_sa_option_read_fail'] = array();
		$this->assertSame( 'nine', $this->stored_marker()['seq'], 'the damaged row is left as it was' );
	}

	/**
	 * A credential list nobody could prove is not an empty one. A repair that
	 * wrote a SHORTER list than the site holds would hand the teardown
	 * something it could complete while an administrator credential stayed
	 * live — the one outcome this whole plan exists to prevent.
	 */
	public function test_an_unprovable_sweep_refuses_the_repair(): void {
		$this->damage_the_marker();
		$GLOBALS['_sa_app_password_scan_fail'] = true;

		$out = $this->remove();

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'aura_unbind_unrepairable', $out['data']['code'] );
		$this->assertSame( 'nine', $this->stored_marker()['seq'] );
		$this->assertTrue( sa_app_password_exists( self::ADMIN, self::MANAGED ), 'nothing was revoked' );
		// Every other refusal in this handler releases the site claim on the
		// way out; this one is no different, and a leak here would block the
		// operator's own retry until the 120s takeover (round-2 LOW-5).
		$this->assertFalse( get_option( Aura_Worker_Magic_Link::SITE_CLAIM ), 'the claim is released' );
	}

	/** The same, one step later: a candidate whose own list will not read. */
	public function test_a_candidate_list_that_cannot_be_read_refuses_the_repair(): void {
		$GLOBALS['_app_passwords'][9][] = array( 'uuid' => 'uuid-swept', 'name' => Aura_Worker_Magic_Link::APP_PASSWORD_NAME, 'created' => time() );
		$this->damage_the_marker();
		$GLOBALS['_sa_app_password_read_fail'][9] = true;

		$out = $this->remove();

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'aura_unbind_unrepairable', $out['data']['code'] );
		$this->assertTrue( sa_app_password_exists( 9, 'uuid-swept' ) );
	}

	/**
	 * Nothing is FABRICATED about credentials: a site that holds none gets a
	 * repaired marker naming none, and the teardown completes.
	 */
	public function test_a_repair_invents_no_credentials(): void {
		$GLOBALS['_app_passwords'] = array();
		unset( $GLOBALS['_options'][ Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION ] );
		$this->damage_the_marker();

		$out = $this->remove();

		$this->assertTrue( $out['success'] );
		$this->assertFalse( Aura_Worker_Unbind::is_set() );
	}

	/**
	 * THE WARNING, BOTH HALVES. The rebuilt list is a superset of what Aura
	 * minted and NOT a subset of what the teardown removes, and an operator who
	 * reads only one of those sentences is misled either way.
	 */
	public function test_the_panel_states_both_halves_of_the_name_sweep(): void {
		$this->damage_the_marker();

		$html = $this->panel();

		$this->assertStringContainsString( 'record on this site is damaged', $html );
		$this->assertStringContainsString( 'supplied by hand', $html );
		$this->assertStringContainsString( 'revoke any such password yourself', $html );
		$this->assertStringContainsString( 'Aura SiteAgent', $html, 'and that the name itself is the sweep' );
		$this->assertStringContainsString( 'including one you created yourself under that name', $html );
		$this->assertStringContainsString( 'Remove remaining Aura data', $html );
	}

	/**
	 * The shipped WP_Error a damaged marker travels in tells the operator to
	 * use this control. Until round 1 that control would have refused; the
	 * promise is only keepable because the repair exists.
	 */
	public function test_the_malformed_error_names_a_control_that_now_works(): void {
		$this->damage_the_marker();

		$this->assertStringContainsString( 'Remove remaining Aura data', Aura_Worker_Unbind::read()->get_error_message() );
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
