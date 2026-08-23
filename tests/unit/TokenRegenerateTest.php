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

	/** A write the database refuses must not hand out a token as if it worked. */
	public function test_a_failed_write_reports_an_error_and_reveals_nothing(): void {
		update_option( 'aura_worker_site_token', Aura_Worker_Security::hash_token( 'the-previous-token' ) );
		$before = get_option( 'aura_worker_site_token' );

		$GLOBALS['_sa_option_write_fail']['aura_worker_site_token'] = true;
		$res = $this->regenerate();

		$this->assertFalse( $res->success, 'a rotation that did not persist must report failure' );
		$this->assertSame( $before, get_option( 'aura_worker_site_token' ), 'the stored token must be untouched' );
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
	 * A filter that REWRITES the value must not leave the site tokenless.
	 *
	 * Refusing a write and rewriting it fail differently: a rewrite persists, so
	 * the row ends up matching neither the new token nor the old one and the site
	 * authenticates nothing. The handler must put the previous value back rather
	 * than report "unchanged" over a store it has just invalidated.
	 */
	public function test_a_rewriting_filter_leaves_the_previous_token_in_place(): void {
		$previous = Aura_Worker_Security::hash_token( 'the-previous-token' );
		update_option( 'aura_worker_site_token', $previous );

		// Installed AFTER the seeding write so the seed lands intact.
		add_filter(
			'sanitize_option_aura_worker_site_token',
			static function ( $value ) {
				return strrev( (string) $value );
			}
		);

		$res = $this->regenerate();

		$this->assertFalse( $res->success, 'a rotation that did not persist must report failure' );
		// The restore is a raw compare-and-swap, so it lands the exact bytes and
		// is not passed back through the rewriting filter. Read the row, not the
		// option cache.
		$this->assertSame(
			$previous,
			sa_read_option_uncached( 'aura_worker_site_token' ),
			'the previous token must be restored exactly'
		);
		$this->assertFalse( get_transient( 'aura_worker_token_reveal' ), 'no token may be revealed' );
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

		$this->assertFalse( $res->success, 'a rotation that did not persist must report failure' );
		$this->assertSame(
			$previous,
			sa_read_option_uncached( 'aura_worker_site_token' ),
			'the row must be restored even while the cache serves something else'
		);
		$this->assertStringContainsString(
			'current token is unchanged',
			(string) ( $res->data['message'] ?? '' ),
			'the message must describe the database, not the cache'
		);
	}

	/**
	 * A failed rotation must never overwrite one that succeeded concurrently.
	 *
	 * Two administrators rotating at once: request A fails and restores, request
	 * B succeeds in between. An unconditional restore would revoke B's token AND
	 * bring back the token A was rotating away from — the compromised one, in the
	 * case this feature exists for. The restore is therefore a compare-and-swap
	 * against the exact value A observed, and must do nothing once B has written.
	 */
	public function test_a_failed_rotation_does_not_clobber_a_concurrent_success(): void {
		$previous  = Aura_Worker_Security::hash_token( 'the-previous-token' );
		$observed  = Aura_Worker_Security::hash_token( 'what-request-a-saw' );
		$concurrent = Aura_Worker_Security::hash_token( 'request-b-token' );

		// The row already holds B's successful rotation.
		update_option( 'aura_worker_site_token', $concurrent );

		$restore = new ReflectionMethod( Aura_Worker::class, 'restore_token_if_unchanged' );
		if ( PHP_VERSION_ID < 80100 ) {
			$restore->setAccessible( true );
		}
		$did = $restore->invoke( $this->plugin, $observed, $previous );

		$this->assertFalse( $did, 'the restore must report that it changed nothing' );
		$this->assertSame(
			$concurrent,
			sa_read_option_uncached( 'aura_worker_site_token' ),
			"the concurrent rotation's token must survive"
		);
	}
}
