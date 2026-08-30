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
	 * the departed binding's own token: at a marked site no token may reach
	 * Layer 2.5 and write, because Phase B deletes the token last and a rebind
	 * clears the marker only once it has succeeded end to end (#434 Task 7) —
	 * which is now a fact of the code, pinned by the tripwire below and by
	 * UnbindRebindTest, not a claim resting on nothing.
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

	/* ---------------------------------------------------------------- */
	/* A REBIND clears the marker — and NOTHING else does (Task 7)        */
	/* ---------------------------------------------------------------- */

	/**
	 * The premise, asserted rather than believed — in the direction Task 7
	 * turned it round.
	 *
	 * Until Task 7 nothing in this plugin cleared the marker on a rebind, so
	 * round-1 MAJOR-1 narrowed the run-as branch of departed_binding_request()
	 * with a token-hash comparison and this test held the premise still. Task 7
	 * supplies the missing fact — and the comparison was removed with it,
	 * because once a PROVEN rebind clears the marker, "marker present, token
	 * differs" no longer means "a re-connected site" but "a rebind that
	 * installed the token and then failed", which must stay refused.
	 *
	 * So the tripwire now guards the NEW truth, and it is a narrower one: the
	 * marker is cleared through exactly one gateway
	 * (Aura_Worker_Unbind::delete_under_claim(), reached only via
	 * release_marker_after_rebind()), and exactly two flows call it — the
	 * connect callback and "Regenerate Token" — each of which must also settle
	 * the departed binding's Phase-B debt first. A THIRD caller, or a caller
	 * that clears without finishing, breaks the reasoning in
	 * departed_binding_request() and in Aura_Worker_Unbind::maybe_finish(), and
	 * this file is where that has to be noticed.
	 *
	 * TASK 9 IS THE THIRD CALLER, AND THIS TEST IS WHERE THAT DECISION WAS
	 * MADE DELIBERATELY. `ajax_remove_aura_data()` — the operator's "Remove
	 * remaining Aura data" — deletes the marker without any rebind at all, so
	 * "only a proven rebind clears the marker" is no longer the whole truth.
	 * What replaces it is not weaker, because the two routes prove the SAME
	 * thing by different evidence:
	 *
	 *   - a rebind proves the departed binding is settled and a replacement is
	 *     installed end to end (finish_before_rebind() … the token …
	 *     release_marker_after_rebind());
	 *   - the teardown proves there is nothing left to be bound to:
	 *     `cleanup( true, $fence )` returns exactly true only after an uncached
	 *     read has shown the site token row itself gone (Task 4), which is
	 *     strictly more than a rebind proves.
	 *
	 * So the marker still outlives everything it names, on both routes. The
	 * caller list below is the pin: a FOURTH caller, or a teardown that stops
	 * gating on `cleanup()`'s exact `true`, breaks the same reasoning and has
	 * to be noticed here.
	 */
	public function test_only_a_proven_rebind_or_a_proven_teardown_clears_the_marker(): void {
		$sources = self::plugin_sources();

		$gateways = self::files_calling( $sources, 'delete_under_claim' );
		$this->assertSame(
			array( 'class-aura-worker-unbind.php', 'class-aura-worker.php' ),
			$gateways,
			'the marker is deleted by the rebind gateway and by the operator teardown, and by nothing else. Another caller means the refusal can now be lifted somewhere departed_binding_request() has not reasoned about.'
		);

		$rebinds = self::files_calling( $sources, 'release_marker_after_rebind' );
		$this->assertSame(
			array( 'class-aura-worker-magic-link.php', 'class-aura-worker.php' ),
			$rebinds,
			'exactly two flows clear the marker: the connect callback and Regenerate Token.'
		);

		// …and neither may clear it without having settled Phase B first. The
		// bracket is only a bracket if both halves are in the same file.
		foreach ( array( 'class-aura-worker-magic-link.php', 'class-aura-worker.php' ) as $flow ) {
			$this->assertContains(
				$flow,
				self::files_calling( $sources, 'finish_before_rebind' ),
				"{$flow} releases the marker but never settles the departed binding's outstanding Phase-B work first."
			);
		}

		// The teardown's own gate, read out of the source: its delete is
		// reached only under `true !== $done` having been passed. A truthiness
		// test, or a `false !==`, would let a `cleanup()` that answered
		// something other than proof delete the marker.
		$this->assertMatchesRegularExpression(
			'/\$done\s*=\s*Aura_Worker_Unbind::cleanup\(\s*true,\s*\$fence\s*\);\s*if\s*\(\s*true\s*!==\s*\$done\s*\)/',
			$sources[ SA_PLUGIN_DIR . '/includes/class-aura-worker.php' ],
			'the teardown must gate its delete on cleanup() returning exactly true'
		);
	}

	/**
	 * The site-wide holder statement is the OPERATOR'S, and only the
	 * operator's (#434 Task 9). Task 4 deleted the owner search from Phase B
	 * and left one lookup against a recorded owner; if a sweep, the fast path
	 * or leftovers() ever adopted this scan, the cost Task 4 refused to pay on
	 * every request would be back — and so would a proof made on a path nobody
	 * asked for.
	 */
	public function test_the_site_wide_password_scan_is_reached_only_from_the_operator_teardown(): void {
		$sources = self::plugin_sources();

		$this->assertSame(
			array( 'class-aura-worker-unbind.php' ),
			self::files_calling( $sources, 'password_holders' ),
			'the scan has ONE caller — resolve_unknown_owners(). (files_calling() does not count the declaration.)'
		);
		$this->assertSame(
			array( 'class-aura-worker.php' ),
			self::files_calling( $sources, 'resolve_unknown_owners' ),
			'…and that resolution runs only from the operator teardown'
		);
	}

	/**
	 * The tripwire's key space, pinned the same way as Task 5's
	 * (UnbindRefusalTest, round-2). This scan is already path-keyed, so a
	 * caller hiding under a shared basename is reported rather than
	 * overwritten — but "already correct" is not a guard, and the sibling scan
	 * proved how quietly this breaks.
	 */
	public function test_a_caller_hidden_under_a_shared_basename_is_still_found(): void {
		$callers = sa_with_plugin_file(
			'includes/aaa_legacy/class-aura-worker-unbind.php',
			"<?php\nclass Legacy_Unbind { public function go() { Aura_Worker_Unbind::delete_under_claim( 'x' ); } }\n",
			static function () {
				return self::files_calling( self::plugin_sources(), 'delete_under_claim' );
			}
		);
		$this->assertSame(
			array( 'class-aura-worker-unbind.php', 'class-aura-worker-unbind.php', 'class-aura-worker.php' ),
			$callers,
			'the scan lost a caller to a shared basename — the tripwire would never fire on it'
		);
	}

	/**
	 * Every plugin PHP file, COMMENTS STRIPPED with PHP's own tokeniser: a
	 * docblock that DISCUSSES a method (this file's own reasoning does, and so
	 * does the one in class-aura-worker-rules.php) is prose, not a call, and a
	 * text grep cannot tell the two apart.
	 *
	 * Keyed by PATH, never by basename (round-1 NIT): two identically named
	 * files in different directories would collide, and the collision would
	 * SILENTLY drop one of them from every scan below.
	 *
	 * @return array<string,string> path => comment-free source.
	 */
	private static function plugin_sources(): array {
		$sources = array();
		$dir     = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( SA_PLUGIN_DIR ) );
		foreach ( $dir as $file ) {
			if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}
			$source = '';
			foreach ( token_get_all( (string) file_get_contents( $file->getPathname() ) ) as $token ) {
				if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}
				$source .= is_array( $token ) ? $token[1] : $token;
			}
			$sources[ $file->getPathname() ] = $source;
		}
		// A scan that quietly matched almost nothing proves nothing at all.
		if ( count( $sources ) <= 30 ) {
			throw new RuntimeException( 'the source scan found almost nothing — is SA_PLUGIN_DIR right?' );
		}
		return $sources;
	}

	/**
	 * Which files CALL that method — the name followed by an open paren, never
	 * a `function` declaration of it.
	 *
	 * The answer is reported as basenames — that is what a reader of a failure
	 * message needs — but built from the path-keyed map, so a collision cannot
	 * hide a caller.
	 *
	 * @param array<string,string> $sources Comment-free sources by path.
	 * @param string               $method  The method name.
	 * @return string[] Basenames, sorted.
	 */
	private static function files_calling( array $sources, string $method ): array {
		$callers = array();
		foreach ( $sources as $path => $source ) {
			if ( preg_match( '#(?<!function )\b' . preg_quote( $method, '#' ) . '\s*\(#', $source ) ) {
				$callers[] = basename( $path );
			}
		}
		sort( $callers );
		return $callers;
	}

	/**
	 * THE ROUND-1 MAJOR-1 CASE, INVERTED BY TASK 7.
	 *
	 * A marker that names a DIFFERENT token than the one the site now holds no
	 * longer means "this site was re-connected and nothing cleared the marker".
	 * A rebind that completes clears the marker itself, so the only thing this
	 * state can be is a rebind that installed the replacement token and then
	 * failed — the binding write, the gateway key, the mint, the connect user.
	 * That half-installed token must NOT write: the two-call bracket exists
	 * precisely so the marker outlives it, and a hash comparison in
	 * departed_binding_request() would have handed it core REST anyway.
	 *
	 * It is also the answer Aura_Worker_Security::refuse_if_unbound() has
	 * always given at SiteAgent's own routes, which read is_set() alone. Two
	 * boundaries, one question, one answer.
	 */
	public function test_a_half_installed_replacement_token_at_a_still_marked_site_is_refused(): void {
		sa_set_marker(
			array(
				'site'               => hash( 'sha256', 'the-departed-token' ),
				'app_password_uuids' => array( self::MANAGED_UUID ),
			)
		);
		$this->assertNotSame( sa_token_hash(), Aura_Worker_Unbind::read()['site'], 'the site now holds another token' );
		sa_token_run_as( self::MARKER_USER );
		$this->assertRefused( self::core_any( 'POST', '/wp/v2/posts' ), 'a rebind that did not finish' );
		$this->assertInstanceOf(
			WP_Error::class,
			( new Aura_Worker_Security() )->refuse_if_unbound( sa_token_request( 'POST', '/aura/v2/snapshot' ) ),
			"SiteAgent's own routes refuse the same request — the two boundaries agree"
		);
	}

	/**
	 * ...and the same site, marker naming the token the site actually holds,
	 * refuses it too. Removing the hash comparison widened the seam; it could
	 * not have narrowed it, and this is the half that says so.
	 */
	public function test_the_departed_token_at_the_same_site_is_still_refused(): void {
		// The marker names the token the site actually holds — the state Phase
		// A leaves, and the state a rebind has not yet changed.
		$this->assertSame( sa_token_hash(), Aura_Worker_Unbind::read()['site'] );
		sa_token_run_as( self::MARKER_USER );
		$this->assertRefused( self::core_any( 'POST', '/wp/v2/posts' ), 'the departed token' );
	}

	/**
	 * The token swap moves the RUN-AS branch only. A credential the marker
	 * names by uuid is the departed binding's whatever token the site now
	 * holds — Phase B revokes that password, and until it does, it writes.
	 */
	public function test_the_managed_password_is_refused_even_after_the_token_was_replaced(): void {
		sa_set_marker(
			array(
				'site'               => hash( 'sha256', 'the-departed-token' ),
				'app_password_uuids' => array( self::MANAGED_UUID ),
			)
		);
		sa_authenticate_app_password( self::MARKER_USER, self::MANAGED_UUID );
		$this->assertRefused( self::core_any( 'POST', '/wp/v2/posts' ), 'the departed password' );
	}

	/**
	 * NO PROPERTY OF THE TOKEN EXCUSES A RUN-AS AT A MARKED SITE.
	 *
	 * These four states were four separate arguments while the seam compared
	 * hashes — matching, differing, unreadable, empty — and three of them were
	 * "not evidence of a DIFFERENT binding". Task 7 collapses them into one
	 * rule: the marker stands, so no rebind has been proven complete, so the
	 * run-as is refused. The sweep is kept (rather than dropped with the
	 * comparison) because it is the shape a re-narrowing would break: if
	 * anybody puts a token discriminator back, at most one of these rows can
	 * still be refused, and this test says which ones were meant to be.
	 */
	public function test_the_run_as_is_refused_whatever_the_token_says(): void {
		$cases = array(
			'the marker names the token the site holds' => static function (): void {},
			'the marker names a different token'        => static function (): void {
				sa_set_marker( array( 'site' => hash( 'sha256', 'the-departed-token' ) ) );
			},
			'the marker names no token at all'          => static function (): void {
				sa_set_marker( array( 'site' => '' ) );
			},
			'the token row cannot be read'              => static function (): void {
				$GLOBALS['_sa_option_read_fail']['aura_worker_site_token'] = true;
			},
		);
		foreach ( $cases as $why => $arrange ) {
			sa_token_run_as( self::MARKER_USER ); // installs the site's real token first
			$arrange();
			$this->assertRefused( self::core_any( 'POST', '/wp/v2/posts' ), $why );
			$GLOBALS['_sa_option_read_fail'] = array();
		}
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
