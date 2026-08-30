<?php
/**
 * The core-REST seam (#434 Task 6). Task 5 closed SiteAgent's OWN routes and
 * Aura_Worker_Grant::verify(); this file is about the other door — WordPress
 * core's REST API (/wp/v2/*, /wc/v3/*, anything a plugin registers), which the
 * departed binding reaches with the same Application Password or the same
 * site token and which passes through none of Task 5's seams.
 *
 * EVERY TEST HERE RUNS WITH PHASE B ALREADY DONE. setUp() deletes
 * `aura_worker_connect_user_id` and the managed Application Password record,
 * because that is the state the seam actually runs in: Phase B removes them
 * and deletes the site token LAST, so the credentials outlive every live
 * option that could have named them. An implementation that consults one of
 * those options answers "not the departed binding" for exactly the requests
 * this file is about, and would ship green against tests written the other way
 * round.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class UnbindCoreRestTest extends TestCase {

	/** The Application Password the marker records — the departed binding's. */
	const MANAGED_UUID = 'uuid-managed';

	/** The connecting user the marker records. */
	const MARKER_USER = 3;

	/**
	 * HTTP methods this file sweeps.
	 *
	 * Written down, not derived, and the reason is worth stating: HTTP's
	 * method vocabulary has no runtime enumerator in this environment (there
	 * is no WordPress core here to read WP_REST_Server::ALLMETHODS from), so
	 * something has to name them. What is NOT written down is the partition —
	 * which of them are refused. That is computed from
	 * Aura_Worker_Rules::SAFE_METHODS, the constant the guard itself consults,
	 * so removing OPTIONS from that constant moves OPTIONS to the refused side
	 * of this sweep automatically, and adding a method to it moves it back.
	 * test_the_method_vocabulary_covers_the_guards_own_constant keeps the two
	 * from drifting apart.
	 */
	const VOCABULARY = array( 'GET', 'HEAD', 'OPTIONS', 'POST', 'PUT', 'PATCH', 'DELETE' );

	/**
	 * Core (and third-party) REST routes the departed binding could write to.
	 * Representative, not exhaustive — the guard is route-agnostic by
	 * construction, and test_the_refusal_does_not_depend_on_the_route is what
	 * says so out loud.
	 */
	const FOREIGN_ROUTES = array(
		'/wp/v2/posts',
		'/wp/v2/posts/5',
		'/wp/v2/pages',
		'/wp/v2/users',
		'/wp/v2/plugins',
		'/wp/v2/settings',
		'/wc/v3/products',
		'/elementor/v1/documents/5',
		'/some-other-plugin/v1/thing',
	);

	protected function setUp(): void {
		sa_reset_state();
		// This is a REST request. The rest of the "is this an agent" question
		// is answered by the real predicates: no cookie override is installed,
		// so a cookie session is one only when sa_cookie_session() made it one.
		Aura_Worker_Rules::$rest_request_override = true;
		Aura_Worker_Rules::init();
		Aura_Worker_Security::init(); // the Application-Password capture hook
		sa_set_marker(
			array(
				'site'               => sa_token_hash(),
				'seq'                => 9,
				'connect_user_id'    => self::MARKER_USER,
				'app_password_uuids' => array( self::MANAGED_UUID ),
				'app_password_users' => array( self::MANAGED_UUID => self::MARKER_USER ),
			)
		);
		// Phase B has already deleted these. The seam must match on the
		// MARKER's own record, never on a live option.
		delete_option( 'aura_worker_connect_user_id' );
		delete_option( Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION );
	}

	protected function tearDown(): void {
		Aura_Worker_Rules::$rest_request_override = null;
		Aura_Worker_Rules::reset_request_warnings();
	}

	/**
	 * A request as core would hand it to the seam.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route path.
	 * @return WP_REST_Request
	 */
	private static function request( string $method, string $route ): WP_REST_Request {
		return new WP_REST_Request( $method, $route );
	}

	/**
	 * The generic seam, through the filter the plugin actually registers it on
	 * — never by calling the static directly, so the wiring is under test too.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route path.
	 * @return mixed Whatever the filter chain answered.
	 */
	private static function core_any( string $method, string $route ) {
		return apply_filters( 'rest_request_before_callbacks', null, array(), self::request( $method, $route ) );
	}

	/**
	 * @param mixed  $result  What a seam answered.
	 * @param string $context Message context.
	 */
	private function assertRefused( $result, string $context ): void {
		$this->assertInstanceOf( WP_Error::class, $result, $context );
		$this->assertSame( 'aura_site_unbound', $result->get_error_code(), $context );
		$this->assertSame( 403, $result->get_error_data()['status'], $context );
	}

	/* ---------------------------------------------------------------- */
	/* The premise: nothing here can pass by accident                    */
	/* ---------------------------------------------------------------- */

	/**
	 * Every uuid assertion in this file rests on the capture hook actually
	 * firing. If Aura_Worker_Security stopped listening, the seam would see no
	 * uuid, every "unrelated password passes" test would still pass, and the
	 * refusals would fail loudly — but this says why in one line.
	 */
	public function test_the_application_password_capture_is_wired(): void {
		sa_authenticate_app_password( self::MARKER_USER, self::MANAGED_UUID );
		$this->assertSame( self::MANAGED_UUID, Aura_Worker_Security::authenticating_app_password_uuid() );
	}

	/**
	 * The state every test in this file runs in, asserted rather than assumed:
	 * the options a naive implementation would read are GONE.
	 */
	public function test_phase_b_has_already_removed_the_options_the_seam_must_not_read(): void {
		$this->assertSame( 0, (int) get_option( 'aura_worker_connect_user_id', 0 ) );
		$this->assertNull( get_option( Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION, null ) );
		$marker = Aura_Worker_Unbind::read();
		$this->assertIsArray( $marker );
		$this->assertSame( array( self::MANAGED_UUID ), $marker['app_password_uuids'] );
	}

	/* ---------------------------------------------------------------- */
	/* The Application Password path                                     */
	/* ---------------------------------------------------------------- */

	public function test_a_write_with_the_managed_uuid_is_refused_after_the_options_are_gone(): void {
		sa_authenticate_app_password( self::MARKER_USER, self::MANAGED_UUID );
		$this->assertRefused( self::core_any( 'POST', '/wp/v2/posts' ), 'POST /wp/v2/posts' );
	}

	public function test_a_woocommerce_write_with_the_managed_uuid_is_refused(): void {
		sa_authenticate_app_password( self::MARKER_USER, self::MANAGED_UUID );
		$this->assertRefused( self::core_any( 'POST', '/wc/v3/products' ), 'POST /wc/v3/products' );
	}

	public function test_a_read_with_the_managed_uuid_still_works(): void {
		sa_authenticate_app_password( self::MARKER_USER, self::MANAGED_UUID );
		$this->assertNull( self::core_any( 'GET', '/wp/v2/posts' ) );
	}

	/**
	 * An unrelated Application Password of the SAME user is a different
	 * credential, and the site owner's own automation is not Aura's binding.
	 */
	public function test_an_unrelated_password_of_the_same_user_passes(): void {
		sa_authenticate_app_password( self::MARKER_USER, 'uuid-other' );
		$this->assertNull( self::core_any( 'POST', '/wp/v2/posts' ) );
	}

	/**
	 * The marker decides, and only the marker. A live option naming a
	 * DIFFERENT binding cannot move either answer — which is the difference
	 * between an implementation that survives Phase B and one that does not.
	 */
	public function test_a_live_option_naming_another_binding_changes_nothing(): void {
		update_option( 'aura_worker_connect_user_id', 99 );
		update_option(
			Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION,
			array( 'user_id' => 99, 'uuid' => 'uuid-live' )
		);

		sa_authenticate_app_password( 99, 'uuid-live' );
		$this->assertNull( self::core_any( 'POST', '/wp/v2/posts' ), 'the live record is not the departed binding' );

		sa_reset_state();
		Aura_Worker_Rules::$rest_request_override = true;
		Aura_Worker_Rules::init();
		Aura_Worker_Security::init();
		sa_set_marker(
			array(
				'site'               => sa_token_hash(),
				'seq'                => 9,
				'app_password_uuids' => array( self::MANAGED_UUID ),
			)
		);
		update_option( 'aura_worker_connect_user_id', 99 );
		sa_authenticate_app_password( 99, self::MANAGED_UUID );
		$this->assertRefused( self::core_any( 'POST', '/wp/v2/posts' ), 'the marker still names this password' );
	}

	/**
	 * The uuid is the credential. `app_password_users` may hold null for an
	 * owner the site could not determine, and requiring the authenticating
	 * user to match it would read that unknown as innocence — the exact
	 * inference that produced six Criticals in Tasks 1-4.
	 */
	public function test_the_uuid_matches_even_when_the_marker_knows_no_owner(): void {
		sa_set_marker(
			array(
				'site'               => sa_token_hash(),
				'app_password_uuids' => array( self::MANAGED_UUID ),
				'app_password_users' => array( self::MANAGED_UUID => 0 ), // normalises to null: unknown
			)
		);
		$marker = Aura_Worker_Unbind::read();
		$this->assertNull( $marker['app_password_users'][ self::MANAGED_UUID ], 'the owner is an explicit unknown' );
		sa_authenticate_app_password( 8, self::MANAGED_UUID );
		$this->assertRefused( self::core_any( 'POST', '/wp/v2/posts' ), 'unknown owner, known password' );
	}

	/* ---------------------------------------------------------------- */
	/* The token run-as path                                             */
	/* ---------------------------------------------------------------- */

	/**
	 * The run-as record is written by production code — Layer 2.5 — not by
	 * this test. sa_token_run_as() only supplies the request state.
	 */
	public function test_layer_2_5_records_the_run_as(): void {
		$this->assertNull( Aura_Worker_Security::ran_as_token(), 'nothing ran as anyone yet' );
		$ran = sa_token_run_as( self::MARKER_USER );
		$this->assertSame( self::MARKER_USER, $ran );
		$this->assertSame( self::MARKER_USER, Aura_Worker_Security::ran_as_token() );
	}

	public function test_a_token_run_as_write_is_refused(): void {
		sa_token_run_as( self::MARKER_USER );
		$this->assertRefused( self::core_any( 'DELETE', '/wp/v2/posts/5' ), 'DELETE as the token' );
	}

	/**
	 * THE CASE THE OBVIOUS PREDICATE GETS WRONG.
	 *
	 * Phase B deleted `aura_worker_connect_user_id`, so resolve_connect_user()
	 * falls back to the FIRST administrator — user 7 here, while the marker
	 * recorded user 3. A seam that refused only when the run-as id equals the
	 * marker's `connect_user_id` would wave this request through, and it is
	 * the departed binding's own token: at a marked site no other token can
	 * reach Layer 2.5, because Phase B deletes the token last and a rebind
	 * clears the marker before issuing another.
	 *
	 * The run-as PATH is the proof; the id it resolved to proves nothing.
	 */
	public function test_a_token_run_as_that_resolved_a_different_admin_is_still_refused(): void {
		$ran = sa_token_run_as( 7 );
		$this->assertSame( 7, $ran );
		$this->assertNotSame( self::MARKER_USER, $ran, 'the fallback resolved somebody the marker never named' );
		$this->assertSame( self::MARKER_USER, (int) Aura_Worker_Unbind::read()['connect_user_id'] );
		$this->assertRefused( self::core_any( 'POST', '/wp/v2/posts' ), 'fallback admin, departed token' );
	}

	/**
	 * The one route a marked site must still answer: the ruleset envelope and
	 * every retry of it (Task 3's fast path). A site that refused this could
	 * not be told anything, including that it is unbound.
	 */
	public function test_the_ruleset_route_stays_reachable(): void {
		sa_token_run_as( self::MARKER_USER );
		$this->assertNull( self::core_any( 'POST', '/aura/v2/rules' ) );
	}

	/**
	 * The exemption is anchored at BOTH ends. A right-anchored one also
	 * matched '/anything/aura/v2/rules', and a left-anchored one
	 * '/aura/v2/rulesets' — neither is an exemption anybody chose.
	 *
	 * @dataProvider near_miss_routes
	 *
	 * @param string $route A route that merely resembles the exempt one.
	 */
	public function test_a_route_that_only_resembles_the_ruleset_route_is_not_exempt( string $route ): void {
		sa_token_run_as( self::MARKER_USER );
		$this->assertRefused( self::core_any( 'POST', $route ), $route );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function near_miss_routes(): array {
		return array(
			'suffix'          => array( '/aura/v2/rulesets' ),
			'nested'          => array( '/some-plugin/v1/aura/v2/rules' ),
			'trailing slash'  => array( '/aura/v2/rules/' ),
			'deeper'          => array( '/aura/v2/rules/extra' ),
		);
	}

	/* ---------------------------------------------------------------- */
	/* Methods and routes                                                */
	/* ---------------------------------------------------------------- */

	/**
	 * The vocabulary this file sweeps must at least cover the guard's own
	 * safe-method constant; otherwise a method added there would be swept by
	 * nothing at all and the partition below would quietly shrink.
	 */
	public function test_the_method_vocabulary_covers_the_guards_own_constant(): void {
		$this->assertSame(
			array(),
			array_diff( Aura_Worker_Rules::SAFE_METHODS, self::VOCABULARY ),
			'SAFE_METHODS names a method this sweep never tries'
		);
		$this->assertNotEmpty( array_diff( self::VOCABULARY, Aura_Worker_Rules::SAFE_METHODS ) );
	}

	/**
	 * @dataProvider vocabulary
	 *
	 * @param string $method One HTTP method.
	 */
	public function test_each_method_is_refused_exactly_when_it_is_not_safe( string $method ): void {
		sa_authenticate_app_password( self::MARKER_USER, self::MANAGED_UUID );
		$result = self::core_any( $method, '/wp/v2/posts' );
		if ( in_array( $method, Aura_Worker_Rules::SAFE_METHODS, true ) ) {
			$this->assertNull( $result, "{$method} is safe and must pass" );
			return;
		}
		$this->assertRefused( $result, $method );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function vocabulary(): array {
		$out = array();
		foreach ( self::VOCABULARY as $method ) {
			$out[ $method ] = array( $method );
		}
		return $out;
	}

	/**
	 * @dataProvider foreign_routes
	 *
	 * @param string $route A route SiteAgent does not own.
	 */
	public function test_the_refusal_does_not_depend_on_the_route( string $route ): void {
		sa_authenticate_app_password( self::MARKER_USER, self::MANAGED_UUID );
		$this->assertRefused( self::core_any( 'POST', $route ), $route );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function foreign_routes(): array {
		$out = array();
		foreach ( self::FOREIGN_ROUTES as $route ) {
			$out[ $route ] = array( $route );
		}
		return $out;
	}

	/**
	 * `/wp/v2/posts` is an ID-aware route, where the generic seam normally
	 * stands aside and lets `rest_pre_insert_post` carry the rule. The unbind
	 * refusal is not a rule verdict and must be decided BEFORE that early
	 * return — this is the test that goes red if the check drifts below it.
	 */
	public function test_the_refusal_precedes_the_id_aware_hand_off(): void {
		sa_authenticate_app_password( self::MARKER_USER, self::MANAGED_UUID );
		$this->assertRefused( self::core_any( 'POST', '/wp/v2/posts/7' ), 'PUT-shaped update on an ID-aware route' );
		$this->assertRefused( self::core_any( 'PUT', '/wp/v2/pages/7' ), 'the page collection item' );
	}

	/* ---------------------------------------------------------------- */
	/* The other two seams                                               */
	/* ---------------------------------------------------------------- */

	public function test_the_insert_filter_refuses_the_departed_binding(): void {
		sa_authenticate_app_password( self::MARKER_USER, self::MANAGED_UUID );
		$prepared     = new stdClass();
		$prepared->ID = 5;
		foreach ( array( 'rest_pre_insert_post', 'rest_pre_insert_page' ) as $filter ) {
			$this->assertRefused( apply_filters( $filter, $prepared, self::request( 'POST', '/wp/v2/posts' ) ), $filter );
		}
	}

	public function test_the_insert_filter_leaves_an_unrelated_password_alone(): void {
		sa_authenticate_app_password( self::MARKER_USER, 'uuid-other' );
		$prepared     = new stdClass();
		$prepared->ID = 5;
		$this->assertSame( $prepared, apply_filters( 'rest_pre_insert_post', $prepared, self::request( 'POST', '/wp/v2/posts' ) ) );
	}

	/**
	 * The delete seams refuse with `false` and nothing else. A WP_Error here
	 * becomes wp_delete_post()'s return value, whose contract is a post object
	 * or false — and a WP_Error is truthy, so every caller would read the
	 * refusal as a successful deletion.
	 *
	 * @dataProvider delete_filters
	 *
	 * @param string $filter The data-layer filter.
	 */
	public function test_the_delete_seams_refuse_with_false( string $filter ): void {
		sa_authenticate_app_password( self::MARKER_USER, self::MANAGED_UUID );
		$post = (object) array( 'ID' => 5, 'post_type' => 'post' );
		$this->assertFalse( apply_filters( $filter, null, $post, false ), $filter );
	}

	/**
	 * An unbind is not a rule, so it is not confined to the rule vocabulary's
	 * post types: a product, an order, any custom type is just as much a
	 * mutation. This is what pins the check ABOVE guard_core_delete()'s
	 * CORE_TYPES filter.
	 *
	 * @dataProvider delete_filters
	 *
	 * @param string $filter The data-layer filter.
	 */
	public function test_the_delete_seams_refuse_a_custom_post_type_too( string $filter ): void {
		sa_authenticate_app_password( self::MARKER_USER, self::MANAGED_UUID );
		$post = (object) array( 'ID' => 5, 'post_type' => 'product' );
		$this->assertFalse( apply_filters( $filter, null, $post, false ), $filter );
	}

	public function test_the_delete_seams_leave_an_unrelated_password_alone(): void {
		sa_authenticate_app_password( self::MARKER_USER, 'uuid-other' );
		$post = (object) array( 'ID' => 5, 'post_type' => 'post' );
		$this->assertNull( apply_filters( 'pre_delete_post', null, $post, false ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function delete_filters(): array {
		return array(
			'pre_delete_post' => array( 'pre_delete_post' ),
			'pre_trash_post'  => array( 'pre_trash_post' ),
		);
	}

	/* ---------------------------------------------------------------- */
	/* Every seam, enumerated from the plugin's own registrations        */
	/* ---------------------------------------------------------------- */

	/**
	 * The core seams the PLUGIN registers, discovered at runtime from the hook
	 * registry after Aura_Worker_Rules::init() has run — `hook => guard
	 * method`. Nothing here is a list anybody maintains: add a fourth
	 * guard_core_* callback, or move an existing one to another hook, and this
	 * changes by itself. What it is compared against is the coverage map in
	 * the test below, which is what turns "a new seam exists" into "a new seam
	 * is unpinned".
	 *
	 * @return array<string,string>
	 */
	private static function registered_core_seams(): array {
		$found = array();
		foreach ( $GLOBALS['_filters'] as $hook => $entries ) {
			foreach ( $entries as $entry ) {
				$callback = is_array( $entry ) && array_key_exists( 'callback', $entry ) ? $entry['callback'] : $entry;
				if ( ! is_array( $callback ) || 2 !== count( $callback ) ) {
					continue;
				}
				list( $target, $method ) = $callback;
				if ( 'Aura_Worker_Rules' !== $target || 0 !== strpos( (string) $method, 'guard_core_' ) ) {
					continue;
				}
				$found[ (string) $hook ] = (string) $method;
			}
		}
		return $found;
	}

	/**
	 * Every core seam the plugin registers must refuse the departed binding —
	 * and the enumeration that says which those ARE is the plugin's own
	 * init(), read back out of the hook registry, not a list in this file. An
	 * unpinned seam is a failure with instructions, never a silent skip.
	 */
	public function test_every_registered_core_seam_refuses_the_departed_binding(): void {
		sa_authenticate_app_password( self::MARKER_USER, self::MANAGED_UUID );
		$post = (object) array( 'ID' => 5, 'post_type' => 'post' );

		$prepared     = new stdClass();
		$prepared->ID = 5;

		$invokers = array(
			'rest_request_before_callbacks' => function () {
				return self::core_any( 'POST', '/wp/v2/posts' );
			},
			'rest_pre_insert_post'          => function () use ( $prepared ) {
				return apply_filters( 'rest_pre_insert_post', $prepared, self::request( 'POST', '/wp/v2/posts' ) );
			},
			'rest_pre_insert_page'          => function () use ( $prepared ) {
				return apply_filters( 'rest_pre_insert_page', $prepared, self::request( 'POST', '/wp/v2/pages' ) );
			},
			'pre_delete_post'               => function () use ( $post ) {
				return apply_filters( 'pre_delete_post', null, $post, false );
			},
			'pre_trash_post'                => function () use ( $post ) {
				return apply_filters( 'pre_trash_post', null, $post, 'publish' );
			},
		);

		$seams = self::registered_core_seams();
		$this->assertGreaterThanOrEqual(
			5,
			count( $seams ),
			'the hook registry produced almost no core seams — did Aura_Worker_Rules::init() run?'
		);
		$this->assertSame(
			array(),
			array_diff( array_keys( $seams ), array_keys( $invokers ) ),
			'a core seam the plugin registers is not exercised here. Add an invoker for it above — a guard that returns the wrong refusal shape for its hook either fatals or silently allows.'
		);

		foreach ( $seams as $hook => $guard ) {
			$result = call_user_func( $invokers[ $hook ] );
			if ( is_wp_error( $result ) ) {
				$this->assertSame( 'aura_site_unbound', $result->get_error_code(), "{$hook} ({$guard})" );
				continue;
			}
			$this->assertFalse( $result, "{$hook} ({$guard}) neither refused with the unbind error nor with false" );
		}
	}

	/* ---------------------------------------------------------------- */
	/* Who is NOT touched                                                */
	/* ---------------------------------------------------------------- */

	public function test_a_human_cookie_session_is_unaffected(): void {
		sa_cookie_session( 4 );
		$this->assertNull( self::core_any( 'POST', '/wp/v2/posts' ) );
	}

	public function test_anonymous_public_traffic_is_unaffected(): void {
		$GLOBALS['_logged_in'] = false;
		$this->assertNull( self::core_any( 'POST', '/some-shop/v1/checkout' ) );
	}

	public function test_a_bound_site_is_untouched(): void {
		sa_clear_marker();
		sa_authenticate_app_password( self::MARKER_USER, self::MANAGED_UUID );
		$this->assertNull( self::core_any( 'POST', '/wp/v2/posts' ) );
		$post = (object) array( 'ID' => 5, 'post_type' => 'post' );
		$this->assertNull( apply_filters( 'pre_delete_post', null, $post, false ) );
	}

	/* ---------------------------------------------------------------- */
	/* The unknowns fail CLOSED                                          */
	/* ---------------------------------------------------------------- */

	/**
	 * A marker that cannot be READ — a database failure — is not evidence
	 * that this request is innocent. Absence of proof of guilt is not proof of
	 * innocence, and this boundary refuses.
	 *
	 * The cost is real and stated: while the marker is unreadable, an agent
	 * credential with nothing to do with Aura is refused too. That site is
	 * already refusing every SiteAgent mutation for the same reason
	 * (refuse_if_unbound() uses is_set(), which reads an unreadable marker as
	 * set), and Task 9's removal panel is the way out.
	 */
	public function test_an_unreadable_marker_refuses_even_an_unrelated_credential(): void {
		$GLOBALS['_sa_option_read_fail'][ Aura_Worker_Unbind::OPTION ] = true;
		$this->assertInstanceOf( WP_Error::class, Aura_Worker_Unbind::read(), 'the read really did fail' );
		sa_authenticate_app_password( self::MARKER_USER, 'uuid-other' );
		$this->assertRefused( self::core_any( 'POST', '/wp/v2/posts' ), 'unreadable marker' );
	}

	/**
	 * A marker that exists and is MALFORMED is the same unknown, and it is the
	 * durable one: a DB blip passes, this does not.
	 */
	public function test_a_malformed_marker_refuses_even_an_unrelated_credential(): void {
		sa_set_marker( array( 'seq' => 'nine' ) ); // seq must be an int
		$read = Aura_Worker_Unbind::read();
		$this->assertInstanceOf( WP_Error::class, $read );
		$this->assertSame( Aura_Worker_Unbind::MALFORMED_CODE, $read->get_error_code() );
		sa_authenticate_app_password( self::MARKER_USER, 'uuid-other' );
		$this->assertRefused( self::core_any( 'POST', '/wp/v2/posts' ), 'malformed marker' );
	}

	/**
	 * The blast radius of that fail-closed answer is bounded by the agent test
	 * every caller already sits behind: an unreadable marker does not take a
	 * human editor, or the shop's anonymous traffic, off the air.
	 */
	public function test_an_unreadable_marker_still_leaves_humans_and_public_traffic_alone(): void {
		$GLOBALS['_sa_option_read_fail'][ Aura_Worker_Unbind::OPTION ] = true;
		sa_cookie_session( 4 );
		$this->assertNull( self::core_any( 'POST', '/wp/v2/posts' ), 'a human at the keyboard' );

		sa_reset_state();
		Aura_Worker_Rules::$rest_request_override = true;
		Aura_Worker_Rules::init();
		$GLOBALS['_sa_option_read_fail'][ Aura_Worker_Unbind::OPTION ] = true;
		$GLOBALS['_logged_in']                                        = false;
		$this->assertNull( self::core_any( 'POST', '/some-shop/v1/checkout' ), 'anonymous public traffic' );
	}
}
