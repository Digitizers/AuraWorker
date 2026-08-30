<?php
/**
 * Tests for the site-token regeneration handler (#67).
 *
 * The bug these guard against was invisible for a release: `register_setting()`
 * installed a `sanitize_option_aura_worker_site_token` filter that always
 * returned the stored value, and `update_option()` runs that filter on EVERY
 * write — so the handler's write was discarded while the transient reveal, the
 * connect-user write and the dashboard-url deletion all landed. The admin was
 * shown a token that existed nowhere, and the previous token stayed valid.
 *
 * Two details make these tests real rather than decorative:
 *
 *  - `register_settings()` is called in setUp(). The filter is registered on
 *    `admin_init`, which admin-ajax.php fires — so the handler runs with it
 *    active. Omit that call and every test here passes against the bug.
 *  - The assertions compare the STORED hash to the REVEALED token. Asserting
 *    only that the option changed would pass on a handler that stored one token
 *    and revealed another.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class TokenRegenerateTest extends TestCase {

	private Aura_Worker $plugin;

	protected function setUp(): void {
		sa_reset_state();
		$this->plugin = new Aura_Worker();
		// Model admin-ajax.php: it fires admin_init before dispatching, which is
		// where the plugin registers its settings (and their sanitize filters).
		$this->plugin->register_settings();
	}

	/**
	 * Run the handler and return the JSON response it terminated with.
	 */
	private function regenerate(): SA_Json_Response {
		try {
			$this->plugin->ajax_regenerate_token();
		} catch ( SA_Json_Response $res ) {
			return $res;
		}
		$this->fail( 'ajax_regenerate_token() returned without sending a JSON response' );
	}

	/** The stored hash must become the hash of the token handed to the admin. */
	public function test_regenerate_persists_the_revealed_token(): void {
		update_option( 'aura_worker_site_token', Aura_Worker_Security::hash_token( 'the-previous-token' ) );
		$before = get_option( 'aura_worker_site_token' );

		$res = $this->regenerate();
		$this->assertTrue( $res->success, 'regeneration should succeed' );

		$raw = $res->data['token'] ?? '';
		$this->assertNotSame( '', $raw, 'the response must carry the raw token' );
		$this->assertNotSame( $before, get_option( 'aura_worker_site_token' ), 'the stored token must change' );
		$this->assertSame(
			Aura_Worker_Security::hash_token( $raw ),
			get_option( 'aura_worker_site_token' ),
			'the stored hash must be the hash of the token the admin was given'
		);
	}

	/** The one-time reveal transient must name the same token that was stored. */
	public function test_revealed_transient_matches_the_stored_hash(): void {
		update_option( 'aura_worker_site_token', Aura_Worker_Security::hash_token( 'the-previous-token' ) );

		$res     = $this->regenerate();
		$reveal  = get_transient( 'aura_worker_token_reveal' );
		$this->assertSame( $res->data['token'], $reveal, 'the transient and the response must agree' );
		$this->assertSame(
			Aura_Worker_Security::hash_token( $reveal ),
			get_option( 'aura_worker_site_token' )
		);
	}

	/**
	 * Rotation must actually revoke: the previous token stops authenticating.
	 *
	 * This is the security property. A handler that reveals a token without
	 * storing it leaves the old one working, so an admin rotating a leaked
	 * token is told it is revoked when it is not.
	 */
	public function test_the_previous_token_stops_authenticating(): void {
		$old = 'the-previous-token';
		update_option( 'aura_worker_site_token', Aura_Worker_Security::hash_token( $old ) );

		$new = $this->regenerate()->data['token'];

		$stored = get_option( 'aura_worker_site_token' );
		$this->assertFalse(
			hash_equals( $stored, Aura_Worker_Security::hash_token( $old ) ),
			'the old token must no longer match the stored hash'
		);
		$this->assertTrue(
			hash_equals( $stored, Aura_Worker_Security::hash_token( $new ) ),
			'the new token must match the stored hash'
		);
	}

	/** A database that refuses the write must not hand out a token as if it worked. */
	public function test_a_failed_write_reports_an_error_and_reveals_nothing(): void {
		update_option( 'aura_worker_site_token', Aura_Worker_Security::hash_token( 'the-previous-token' ) );
		$before = get_option( 'aura_worker_site_token' );

		$GLOBALS['_db_query_error'] = true;
		$res = $this->regenerate();

		$this->assertFalse( $res->success, 'a rotation that did not persist must report failure' );
		$this->assertSame( $before, sa_read_option_uncached( 'aura_worker_site_token' ), 'the stored token must be untouched' );
		$this->assertFalse( get_transient( 'aura_worker_token_reveal' ), 'no token may be revealed' );
	}

	/**
	 * The settings form must still be unable to overwrite the token.
	 *
	 * The guard being removed existed for a reason: the token is display-only on
	 * the settings screen and a submitted value must never reach the option. The
	 * option is no longer registered to the settings group, so core's allow-list
	 * is what refuses it now — assert that, and the absence of the filter that
	 * froze every writer, since a regression could reintroduce either.
	 */
	public function test_the_token_is_not_a_registered_setting(): void {
		// The allow-list is the property that matters: options.php writes only
		// options registered to the group, so a register_setting() call with no
		// sanitize callback would still expose the token to a crafted submission
		// while leaving a filter-only assertion green.
		$this->assertNotContains(
			'aura_worker_site_token',
			$GLOBALS['_registered_settings']['aura_worker_settings'] ?? array(),
			'registering the token adds it to the settings allow-list, so a submission could overwrite it'
		);
		$this->assertArrayNotHasKey(
			'sanitize_option_aura_worker_site_token',
			$GLOBALS['_filters'],
			'registering the token as a setting freezes every writer, including regeneration'
		);
	}

	/**
	 * A filter rewriting this option can no longer corrupt the stored token.
	 *
	 * The rotation is a raw compare-and-swap, so an option filter never sees it.
	 * That is the point: the bug this fixes was a filter silently deciding what
	 * the token would be, and the cure must not depend on detecting one.
	 */
	public function test_a_rewriting_filter_cannot_corrupt_the_stored_token(): void {
		$previous = Aura_Worker_Security::hash_token( 'the-previous-token' );
		update_option( 'aura_worker_site_token', $previous );

		add_filter(
			'sanitize_option_aura_worker_site_token',
			static function ( $value ) {
				return strrev( (string) $value );
			}
		);

		$res = $this->regenerate();

		$this->assertTrue( $res->success, 'the rotation must succeed despite the filter' );
		$this->assertSame(
			Aura_Worker_Security::hash_token( $res->data['token'] ),
			sa_read_option_uncached( 'aura_worker_site_token' ),
			'the row must hold the hash of the revealed token, unmodified'
		);
	}

	/**
	 * State must be judged from the database, not from the option cache.
	 *
	 * The token option is autoloaded, so core serves it from the `alloptions`
	 * bucket — which a raw compare-and-swap writes straight past. Judging the
	 * outcome with get_option() therefore reads a value the database no longer
	 * holds: the handler would report a correctly restored row as a failure, and
	 * with a persistent object cache the site would keep authenticating against a
	 * token that no longer exists.
	 */
	public function test_state_is_judged_from_the_database_not_the_cache(): void {
		$previous = Aura_Worker_Security::hash_token( 'the-previous-token' );
		update_option( 'aura_worker_site_token', $previous );

		add_filter(
			'sanitize_option_aura_worker_site_token',
			static function ( $value ) {
				return strrev( (string) $value );
			}
		);
		// Model the stale autoloaded copy get_option() would serve.
		$GLOBALS['_sa_option_cache']['aura_worker_site_token'] = 'a-stale-cached-value';

		$res = $this->regenerate();

		$this->assertTrue( $res->success, 'the rotation must be decided by the row, not the stale cache' );
		$this->assertSame(
			Aura_Worker_Security::hash_token( $res->data['token'] ),
			sa_read_option_uncached( 'aura_worker_site_token' ),
			'the row must hold the hash of the revealed token'
		);
	}

	/**
	 * Losing the race must write nothing and say so.
	 *
	 * Two administrators read the same previous value and both try to swap;
	 * MySQL lets exactly one match. The loser must not touch the row — the
	 * earlier write-then-repair shape could revoke the winner's fresh token and
	 * revive the one both were rotating away from.
	 */
	public function test_losing_the_swap_writes_nothing_and_reveals_nothing(): void {
		$previous = Aura_Worker_Security::hash_token( 'the-previous-token' );
		update_option( 'aura_worker_site_token', $previous );

		// A compare-and-swap that never matches: someone else holds the row.
		$GLOBALS['_cas_always_lose'] = true;

		$res = $this->regenerate();

		$this->assertFalse( $res->success, 'a rotation that wrote nothing must report failure' );
		$this->assertSame(
			$previous,
			sa_read_option_uncached( 'aura_worker_site_token' ),
			'the row must be exactly as the loser found it'
		);
		$this->assertFalse( get_transient( 'aura_worker_token_reveal' ), 'no token may be revealed' );
	}

	/**
	 * A successful swap must never be second-guessed by a read.
	 *
	 * The compare-and-swap matched a row and changed it, which is the only proof
	 * available that THIS request stored the token — a read answers what the row
	 * holds, never who wrote it. A confirming read after the write can therefore
	 * only fail spuriously (a transient database error, a stale autoloaded copy),
	 * and failing there revokes the previous token while revealing no
	 * replacement: the administrator is locked out with nothing to reconnect
	 * with. So assert the shape directly — nothing reads the token row after the
	 * write.
	 */
	public function test_a_read_that_fails_never_costs_the_operator_their_token(): void {
		// #67's rule, refined in round-40. A confirming read cannot prove THIS
		// request stored the token — a read answers what the row holds, never
		// who wrote it — so a read that FAILS must not withhold the reveal:
		// that revokes the previous token while showing no replacement, and the
		// administrator is locked out with nothing to reconnect with.
		update_option( 'aura_worker_site_token', Aura_Worker_Security::hash_token( 'the-previous-token' ) );
		// Scoped to the token row — the one this rule is about (#434 Task 7).
		// It used to be armed as a whole-driver failure ($_sa_wpdb_error), which
		// also blinded the uncached read of the UNBIND MARKER that Task 7 put
		// at the top of this handler; the rotation then refused BEFORE storing
		// anything, which loses the operator nothing and is pinned separately
		// (UnbindRebindTest). The assertion below is unchanged, and it is still
		// the confirming read after the write that fails.
		$GLOBALS['_sa_option_read_fail']['aura_worker_site_token'] = true;
		$res = $this->regenerate();
		$GLOBALS['_sa_option_read_fail'] = array();
		$this->assertTrue( $res->success, 'the token is revealed even though nothing could be read back' );
		$this->assertNotSame( '', (string) ( $res->data['token'] ?? '' ) );
	}

	public function test_a_token_another_request_replaced_is_not_revealed(): void {
		// Round-40: a read that SUCCEEDS and disagrees is not ambiguous — a
		// connect stored its own token while this request was paused, and
		// handing over this raw value gives the operator something the site
		// rejects.
		update_option( 'aura_worker_site_token', Aura_Worker_Security::hash_token( 'the-previous-token' ) );
		$stolen = Aura_Worker_Security::hash_token( 'someone-elses-token' );
		$GLOBALS['_sa_after_swap'] = static function ( $name ) use ( $stolen ) {
			if ( 'aura_worker_site_token' === $name ) {
				$GLOBALS['_rows'][ $name ]    = $stolen;
				$GLOBALS['_options'][ $name ] = $stolen;
			}
		};

		$res = $this->regenerate();
		$this->assertFalse( $res->success, 'a superseded token is not revealed' );
		$this->assertSame( 500, $res->status );
		$this->assertStringContainsString( 'Another site token was stored', (string) ( $res->data['message'] ?? '' ) );
		$this->assertFalse( get_transient( 'aura_worker_token_reveal' ), 'and nothing is left to reveal it later' );
	}

	/**
	 * A row that exists but holds an empty value must still be rotatable.
	 *
	 * '' is two states — no row, and a row holding an empty string — and neither
	 * the settings screen nor get_option() can tell them apart. Picking the write
	 * statement from that read is the trap: an empty row given only the
	 * conditional INSERT can never satisfy NOT EXISTS, so the site would report a
	 * failed rotation forever and could never be configured.
	 */
	public function test_an_existing_empty_token_row_can_be_rotated(): void {
		update_option( 'aura_worker_site_token', '' );
		$this->assertSame( '', sa_read_option_uncached( 'aura_worker_site_token' ), 'the row must exist and be empty' );

		$res = $this->regenerate();

		$this->assertTrue( $res->success, 'a site whose token row is empty must be able to get one' );
		$this->assertSame(
			Aura_Worker_Security::hash_token( $res->data['token'] ),
			sa_read_option_uncached( 'aura_worker_site_token' )
		);
	}

	/** With no row yet, the rotation inserts one rather than swapping. */
	public function test_a_site_with_no_token_yet_gets_one(): void {
		unset( $GLOBALS['_options']['aura_worker_site_token'], $GLOBALS['_rows']['aura_worker_site_token'] );

		$res = $this->regenerate();

		$this->assertTrue( $res->success, 'a site with no token must be able to get one' );
		$this->assertSame(
			Aura_Worker_Security::hash_token( $res->data['token'] ),
			sa_read_option_uncached( 'aura_worker_site_token' )
		);
	}
}
