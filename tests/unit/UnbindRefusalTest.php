<?php
/**
 * The write boundary (#434 Task 5). A site carrying the unbind marker must
 * refuse every mutation with 403 aura_site_unbound — even from a caller
 * holding a valid site token — while reads keep working.
 *
 * The refusal is asserted for every registered non-safe-method route,
 * enumerated from the LIVE route table (sa_registered_routes()) rather than
 * from a list written here. A hard-coded list is the failure this file exists
 * to prevent: a mutating route added next year with an unguarded permission
 * callback would ship green. Two categories are excluded, each named with its
 * reason and each pinned by its own test below — EXEMPT (routes that must stay
 * reachable) and POST_READ_ROUTES (non-safe methods that are reads).
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class UnbindRefusalTest extends TestCase {

	/**
	 * HTTP methods that cannot change state, so the marker does not gate them.
	 */
	const SAFE = array( 'GET', 'HEAD', 'OPTIONS' );

	/**
	 * Routes whose non-safe methods are deliberately NOT refused, each with the
	 * reason it must stay reachable at an unbound site.
	 */
	const EXEMPT = array(
		// The unbind envelope arrives here, and so does every retry of it —
		// Task 3 answers those from the marker fast path. A site that refused
		// this route could not be told anything, including that it is unbound.
		'/aura/v2/rules'   => 'the unbind envelope and its retries arrive here (Task 3 fast path)',
		// The rebind. Public by design — a signed magic-link callback, so its
		// permission_callback is __return_true and no site-token layer runs —
		// and the one way a marked site comes back. Refusing it would strand
		// every unbound site permanently.
		'/aura/v1/connect' => 'the rebind path; refusing it would strand a marked site for good',
	);

	/**
	 * The non-safe-method routes that are READS. Named here, never derived
	 * from the permission callback: a future state-changing route that reused
	 * check_read_permission must still be tested, not excused by it.
	 *
	 * Both are POSTs by MCP protocol convention (a JSON body on a listing
	 * call). tools/preview returns what a call WOULD touch and which rule
	 * would decide it, executing nothing (class-aura-worker-tools.php,
	 * preview_tool()).
	 */
	const POST_READ_ROUTES = array(
		'/aura/mcp/tools/list',
		'/aura/mcp/tools/preview',
	);

	/**
	 * The permission callbacks refuse_if_unbound() is wired into.
	 */
	const GUARDED_CALLBACKS = array(
		'check_admin_permission',
		'check_update_plugins_permission',
		'check_update_core_permission',
		'check_update_themes_permission',
	);

	protected function setUp(): void {
		sa_reset_state();
		sa_install_gateway_key();
		// A token-only request runs as the connecting administrator; without a
		// resolvable admin, validate_request() answers aura_not_configured and
		// nothing would ever reach the unbind boundary.
		$GLOBALS['_admins'] = array( 3 );
		$GLOBALS['_options']['aura_worker_connect_user_id'] = 3;
		sa_set_marker( array( 'site' => sa_token_hash(), 'seq' => 9 ) );
	}

	/**
	 * The uppercase method list of one registered endpoint. `methods` may be a
	 * string, a comma-separated string, or an array — core accepts all three.
	 *
	 * @param array $endpoint One registered endpoint.
	 * @return array<int,string>
	 */
	private static function methods_of( array $endpoint ): array {
		$out = array();
		foreach ( (array) $endpoint['methods'] as $declared ) {
			foreach ( explode( ',', (string) $declared ) as $one ) {
				$out[] = strtoupper( trim( $one ) );
			}
		}
		return $out;
	}

	/**
	 * The method name of an endpoint's permission callback, or the function
	 * name when it is a plain callable string ('__return_true').
	 *
	 * @param array $endpoint One registered endpoint.
	 * @return string
	 */
	private static function callback_name( array $endpoint ): string {
		$cb = $endpoint['permission_callback'];
		return is_array( $cb ) ? (string) $cb[1] : (string) $cb;
	}

	/**
	 * A registered route PATTERN turned into a path a request can carry:
	 * every named capture becomes a concrete segment.
	 *
	 * @param string $route Registered route pattern.
	 * @return string
	 */
	private static function concrete( string $route ): string {
		return (string) preg_replace( '/\(\?P<[A-Za-z_][A-Za-z0-9_]*>[^)]*\)/', 'akismet', $route );
	}

	/**
	 * Every registered route whose method is not GET/HEAD/OPTIONS, minus the
	 * exemptions and the named POST reads — from the LIVE route table,
	 * regardless of which permission callback it happens to use.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function mutating_routes(): array {
		$out = array();
		foreach ( sa_registered_routes() as $route => $endpoints ) {
			if ( isset( self::EXEMPT[ $route ] ) || in_array( $route, self::POST_READ_ROUTES, true ) ) {
				continue;
			}
			foreach ( $endpoints as $endpoint ) {
				foreach ( self::methods_of( $endpoint ) as $method ) {
					if ( in_array( $method, self::SAFE, true ) ) {
						continue;
					}
					$out[ $method . ' ' . $route ] = array( $method, self::concrete( $route ) );
				}
			}
		}
		return $out;
	}

	/**
	 * The enumeration is the whole guarantee, so it is pinned against the
	 * routes known to exist today: an empty or broken sweep cannot pass
	 * vacuously, and neither can one that silently loses a route.
	 */
	public function test_the_enumeration_is_not_vacuous(): void {
		$known = array(
			'/aura/mcp/tools/execute',
			'/aura/v1/update/core',
			'/aura/v1/update/plugin',
			'/aura/v1/update/theme',
			'/aura/v1/update/translations',
			'/aura/v1/self-update',
			'/aura/v1/update/database',
			'/aura/v2/update/batch',
			'/aura/v2/rollback/akismet',
			'/aura/v2/snapshot',
			'/aura/v2/snapshot/restore',
		);
		$seen = array_column( array_values( self::mutating_routes() ), 1 );
		foreach ( $known as $route ) {
			$this->assertContains( $route, $seen, $route );
		}
		$this->assertGreaterThanOrEqual( count( $known ), count( $seen ) );
	}

	/**
	 * An exemption that no longer names a registered route is a stale excuse:
	 * it would sit here reading as deliberate while the route it once covered
	 * was renamed out from under it.
	 */
	public function test_every_exemption_names_a_registered_route(): void {
		$routes = array_keys( sa_registered_routes() );
		$this->assertNotEmpty( $routes );
		foreach ( array_keys( self::EXEMPT ) as $route ) {
			$this->assertContains( $route, $routes, $route );
		}
		foreach ( self::POST_READ_ROUTES as $route ) {
			$this->assertContains( $route, $routes, $route );
		}
	}

	/**
	 * Every non-safe endpoint must answer through a callback this task
	 * accounted for. A new mutating route on check_read_permission — or on
	 * __return_true — never reaches refuse_if_unbound(), and this is where
	 * that gets noticed rather than in production.
	 */
	public function test_every_non_safe_route_has_an_accounted_for_permission_callback(): void {
		$checked = 0;
		foreach ( sa_registered_routes() as $route => $endpoints ) {
			foreach ( $endpoints as $endpoint ) {
				if ( array() === array_diff( self::methods_of( $endpoint ), self::SAFE ) ) {
					continue;
				}
				++$checked;
				$callback = self::callback_name( $endpoint );
				if ( in_array( $callback, self::GUARDED_CALLBACKS, true ) ) {
					continue;
				}
				if ( 'check_read_permission' === $callback ) {
					$this->assertContains(
						$route,
						self::POST_READ_ROUTES,
						"{$route} answers a non-safe method through the READ callback — decide explicitly whether it is a read and name it in POST_READ_ROUTES, or move it to a guarded callback"
					);
					continue;
				}
				$this->assertArrayHasKey(
					$route,
					self::EXEMPT,
					"{$route} answers a non-safe method through '{$callback}', which refuse_if_unbound() never sees — give it a guarded permission callback, or name it in EXEMPT with the reason it must stay reachable"
				);
			}
		}
		$this->assertGreaterThanOrEqual( 12, $checked, 'the sweep saw almost no non-safe endpoints — is the route table being built?' );
	}

	/**
	 * @dataProvider mutating_routes
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Concrete route path.
	 */
	public function test_each_mutating_route_is_refused_before_any_side_effect( string $method, string $route ): void {
		$before = sa_snapshot_side_effects();
		$result = sa_dispatch_permission( sa_token_request( $method, $route ) );
		$this->assertInstanceOf( WP_Error::class, $result, $route );
		$this->assertSame( 'aura_site_unbound', $result->get_error_code(), $route );
		$this->assertSame( 403, $result->get_error_data()['status'], $route );
		$this->assertSame( $before, sa_snapshot_side_effects(), $route );
	}

	/**
	 * Reads keep working — every safe-method endpoint plus the named POST
	 * reads, again from the live table, so a read added later is covered the
	 * day it is written.
	 */
	public function test_every_read_is_still_reachable(): void {
		$checked = 0;
		foreach ( sa_registered_routes() as $route => $endpoints ) {
			if ( isset( self::EXEMPT[ $route ] ) ) {
				continue;
			}
			foreach ( $endpoints as $endpoint ) {
				foreach ( self::methods_of( $endpoint ) as $method ) {
					$is_read = in_array( $method, self::SAFE, true ) || in_array( $route, self::POST_READ_ROUTES, true );
					if ( ! $is_read ) {
						continue;
					}
					$this->assertTrue(
						sa_dispatch_permission( sa_token_request( $method, self::concrete( $route ) ) ),
						"{$method} {$route}"
					);
					++$checked;
				}
			}
		}
		$this->assertGreaterThanOrEqual( 8, $checked, 'no reads were exercised — is the route table being built?' );
	}

	/**
	 * Every `add_action( 'rest_api_init', … )` in the plugin, as
	 * `path => count` — the path RELATIVE to SA_PLUGIN_DIR. Read from the
	 * source, tolerating either quote style (round-1 NEW-1: the same registrar
	 * written with double quotes was a green survivor of the single-quote-literal
	 * scan).
	 *
	 * THE KEY IS A PATH, NEVER A BASENAME (#434 Task 7 round-2). Keyed by
	 * basename, this map SILENTLY LOST entries: `RecursiveDirectoryIterator`
	 * walks `includes/aaa_legacy/` before `includes/`, so a second file sharing
	 * a basename with a real one had its slot overwritten by the real file, and
	 * a third `rest_api_init` registration in it left this whole class green
	 * while its routes were invisible to every assertion here. An enumeration
	 * whose guarantee is COMPLETENESS must not have a key space in which two
	 * findings can collapse into one. Reproduced and pinned by
	 * test_a_registrar_hidden_under_a_shared_basename_is_still_found.
	 *
	 * A hook name held in a constant or a variable is still invisible here.
	 * That is stated in the failure messages rather than papered over.
	 *
	 * @return array<string,int>
	 */
	private static function rest_api_init_registrations(): array {
		$found    = array();
		$scanned  = 0;
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( SA_PLUGIN_DIR ) );
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}
			++$scanned;
			$path   = (string) $file->getPathname();
			$source = (string) file_get_contents( $path );
			$hits   = preg_match_all( '#add_action\(\s*[\'"]rest_api_init[\'"]#', $source );
			if ( $hits > 0 ) {
				$found[ ltrim( str_replace( SA_PLUGIN_DIR, '', $path ), '/' ) ] = $hits;
			}
		}
		if ( $scanned < 30 ) {
			throw new RuntimeException( "the source scan found only {$scanned} files — is SA_PLUGIN_DIR right?" );
		}
		return $found;
	}

	/**
	 * The enumeration's guarantee has to survive a change to the SET of route
	 * registrars, not only to the routes inside the two we happened to know
	 * about (round-1 IMPORTANT-1). sa_build_route_table() therefore runs the
	 * plugin's own bootstrap and fires `rest_api_init`, so a registrar added
	 * anywhere Aura_Worker::init() reaches is swept automatically.
	 *
	 * The one thing still named by hand is that entry point, and this keeps it
	 * honest in two ways. Every `add_action( 'rest_api_init', … )` must live in
	 * the file that entry point lives in — one hooked up from a constructor, a
	 * second bootstrap function or another file never runs during the build,
	 * and its routes would be invisible to this whole class. And the NUMBER of
	 * them in that file is pinned, because being in the right file is not the
	 * same as being REACHED: `init()` has an `if ( is_admin() )` branch that is
	 * false when the table is built, and a registrar inside it (or behind any
	 * `defined()` / `class_exists()` guard) would pass a file-level scan while
	 * never executing (round-1 NEW-2). A third registration therefore reddens
	 * here whatever guards it, and the message says what to do about it.
	 */
	public function test_the_route_table_is_built_from_the_only_entry_point_that_registers_routes(): void {
		$found = self::rest_api_init_registrations();
		$this->assertSame(
			array( 'includes/class-aura-worker.php' => 2 ),
			$found,
			"the plugin's rest_api_init registrations changed. They must all live in the file sa_build_route_table() runs, AND the build must actually reach them — a registrar behind is_admin(), defined() or class_exists() sits in the right file and still never executes, so its routes are invisible to every assertion in this class. Add it to Aura_Worker::init()'s unconditional body, or teach sa_build_route_table()/this test to build with that condition true. (A hook name held in a constant or variable is invisible to this scan entirely.)"
		);
	}

	/**
	 * THE KEY SPACE ITSELF, pinned (#434 Task 7 round-2).
	 *
	 * The scan above is worth exactly as much as its inability to LOSE a
	 * finding, and for a whole task it could lose one: keyed by basename, a
	 * registrar in `includes/aaa_legacy/class-aura-worker.php` had its slot
	 * overwritten by the real `includes/class-aura-worker.php` — the iterator
	 * reaches the subdirectory first — and the assertion above stayed green
	 * with a third `rest_api_init` registration sitting on disk.
	 *
	 * This puts that exact file back and requires BOTH to be reported. A scan
	 * that silently merges two findings is not an enumeration, and no message
	 * would ever have told anybody.
	 */
	public function test_a_registrar_hidden_under_a_shared_basename_is_still_found(): void {
		$found = sa_with_plugin_file(
			'includes/aaa_legacy/class-aura-worker.php',
			"<?php\nadd_action( 'rest_api_init', 'legacy_registrar' );\n",
			static function () {
				return self::rest_api_init_registrations();
			}
		);
		$this->assertSame(
			array(
				'includes/aaa_legacy/class-aura-worker.php' => 1,
				'includes/class-aura-worker.php'            => 2,
			),
			$found,
			'the scan lost a registrar to a shared basename — its completeness guarantee is void'
		);
	}

	/**
	 * The concrete conditional the plugin has today: `init()`'s
	 * `if ( is_admin() )` branch. The table is built with is_admin() FALSE,
	 * because that is what a REST request is — so a route registered only in
	 * the admin branch would never appear in the sweep. Building it both ways
	 * and comparing is what turns that from a silent blind spot into a failure
	 * (round-1 NEW-2): if the two tables differ, some route's existence
	 * depends on a condition the sweep does not hold.
	 */
	public function test_the_route_table_does_not_depend_on_being_a_front_end_request(): void {
		$normalise = static function ( array $table ): array {
			$out = array();
			foreach ( $table as $route => $endpoints ) {
				foreach ( $endpoints as $endpoint ) {
					$out[ $route ][] = implode( ',', self::methods_of( $endpoint ) ) . ' => ' . self::callback_name( $endpoint );
				}
			}
			ksort( $out );
			return $out;
		};

		$as_front_end       = $normalise( sa_build_route_table() );
		$GLOBALS['_is_admin'] = true;
		try {
			$as_admin = $normalise( sa_build_route_table() );
		} finally {
			$GLOBALS['_is_admin'] = false;
		}

		$this->assertNotEmpty( $as_front_end );
		$this->assertSame(
			$as_front_end,
			$as_admin,
			'a route is registered only under one value of is_admin(), so one of the two builds cannot see it — the refusal sweep runs against the front-end build'
		);
	}

	/**
	 * The build registers nothing that outlives it (round-1 NEW-3). It empties
	 * $GLOBALS['_filters'] so an ambient listener cannot smuggle a route in,
	 * and must put back exactly what it found — Aura_Worker::init() hooks up
	 * the rules' core-REST filters, the app-password capture, the unbind sweep
	 * and the abilities hooks, and a caller that loses its own listeners to
	 * that is a caller whose next assertion silently means something else.
	 *
	 * This is testable only because sa_build_route_table() is separate from
	 * the memoised wrapper; through sa_registered_routes() the second call
	 * never builds, which is what made this look unpinnable in round 1.
	 */
	public function test_the_build_puts_back_every_filter_it_found(): void {
		$sentinel = static function ( $value ) {
			return $value;
		};
		add_filter( 'sa_sentinel_hook', $sentinel );
		$before = $GLOBALS['_filters'];
		$this->assertArrayHasKey( 'sa_sentinel_hook', $before );
		// Not an empty window: sa_reset_state() leaves this one registered, and
		// it is the hook Phase B's step trace is recorded on.
		$this->assertArrayHasKey( 'aura_worker_unbind_step', $before );

		sa_build_route_table();

		$this->assertSame( $before, $GLOBALS['_filters'], 'the build did not restore the filters it found' );
	}

	/**
	 * And the other direction: a listener already on `rest_api_init` when the
	 * build runs must not contribute routes to the table. Without the reset,
	 * whatever a test had hooked up would be swept as though the plugin had
	 * registered it — and an exemption granted to a route the plugin does not
	 * have is a hole nobody would ever find.
	 */
	public function test_the_build_ignores_ambient_rest_api_init_listeners(): void {
		add_action(
			'rest_api_init',
			static function () {
				register_rest_route(
					'aura/v1',
					'/ambient',
					array(
						'methods'             => 'POST',
						'callback'            => '__return_true',
						'permission_callback' => '__return_true',
					)
				);
			}
		);

		$this->assertArrayNotHasKey( '/aura/v1/ambient', sa_build_route_table() );
	}

	/**
	 * The exemption is anchored at BOTH ends (round-1 MINOR-3). Right-anchored
	 * alone it also exempted any route ENDING in /aura/v2/rules — unreachable
	 * today, but an exemption that widens the day a route takes a permissive
	 * capture is not an exemption anybody chose.
	 */
	public function test_only_the_rules_route_itself_is_exempt(): void {
		$security = new Aura_Worker_Security();
		foreach ( array( '/aura/v1/anything/aura/v2/rules', '/aura/v2/rules/extra', 'aura/v2/rules' ) as $lookalike ) {
			$result = $security->refuse_if_unbound( sa_token_request( 'POST', $lookalike ) );
			$this->assertInstanceOf( WP_Error::class, $result, $lookalike );
			$this->assertSame( 'aura_site_unbound', $result->get_error_code(), $lookalike );
		}
		$this->assertTrue( $security->refuse_if_unbound( sa_token_request( 'POST', '/aura/v2/rules' ) ) );
	}

	/**
	 * Params that reach a successful preview, per tool. Not the set being
	 * swept — that comes from the registry below — but the fixtures needed to
	 * DRIVE it. A preview-capable tool with no entry here fails the sweep
	 * rather than being skipped, which is the point.
	 *
	 * @return array<string,array>
	 */
	private static function preview_fixtures(): array {
		return array(
			// dry_run=false is the adversarial input: the tool must override it.
			'cleanup_orphaned_assets' => array( 'dry_run' => false ),
			'update_page_block'       => array( 'post_id' => 4242, 'block_index' => 0, 'inner_html' => 'changed' ),
			'test_power_double'       => array( 'target' => 'homepage' ),
		);
	}

	/**
	 * `/aura/mcp/tools/preview` is excluded from the refusal sweep because a
	 * preview executes nothing. That is a property of every preview-capable
	 * tool, and until now nothing asserted it (round-1 MINOR-4) — it rested on
	 * one line in class-tool-cleanup-assets.php. If a preview ever mutates,
	 * the exemption becomes a hole an unbound site is waved through.
	 */
	public function test_no_preview_capable_tool_mutates_anything(): void {
		$GLOBALS['_caps'] = null;
		$GLOBALS['_posts'][4242] = (object) array(
			'ID'           => 4242,
			'post_title'   => 'Fixture',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => wp_json_encode(
				array(
					array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerHTML' => 'before', 'innerContent' => array( 'before' ), 'innerBlocks' => array() ),
				)
			),
		);
		$GLOBALS['_wp_query_posts'] = array( 101, 102 );

		$registry  = new Aura_Worker_Tools();
		$fixtures  = self::preview_fixtures();
		$swept     = 0;
		foreach ( $registry->list_tools() as $meta ) {
			$name = (string) $meta['name'];
			$tool = $registry->get_tool( $name );
			$annotations = $tool->get_annotations();
			if ( empty( $annotations['supports_preview'] ) ) {
				continue;
			}
			$this->assertArrayHasKey( $name, $fixtures, "{$name} supports preview but this sweep has no params to drive it — add a fixture, do not skip it" );

			$before = sa_snapshot_side_effects();
			$result = $registry->preview_tool( $name, $fixtures[ $name ] );
			$this->assertTrue( $result['success'], $name );
			$this->assertTrue( $result['supported'], $name );
			$this->assertSame( $before, sa_snapshot_side_effects(), "{$name}: a PREVIEW mutated the site" );
			++$swept;
		}
		$this->assertSame( count( $fixtures ), $swept, 'the preview sweep did not see every fixtured tool' );

		// The post the block preview was pointed at is byte-identical.
		$this->assertStringContainsString( 'before', $GLOBALS['_posts'][4242]->post_content );
		$this->assertStringNotContainsString( 'changed', $GLOBALS['_posts'][4242]->post_content );
	}

	/**
	 * And the single line the whole classification rests on: cleanup_assets'
	 * dry_run() delegates to execute(), so it must override the caller's
	 * dry_run rather than pass it through.
	 */
	public function test_a_preview_overrides_a_caller_supplied_live_run(): void {
		$GLOBALS['_wp_query_posts'] = array( 101, 102 );
		$result = ( new Aura_Worker_Tools() )->preview_tool( 'cleanup_orphaned_assets', array( 'dry_run' => false ) );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $result['preview']['orphaned_count'], 'the fixture found no orphans, so the delete branch was never in play' );
		$this->assertTrue( $result['preview']['dry_run'], 'the caller asked for a live run and the preview honoured it' );
		$this->assertSame( 0, $result['preview']['removed_count'] );
	}

	public function test_rules_route_is_exempt_so_the_fast_path_is_reachable(): void {
		$this->assertTrue( sa_dispatch_permission( sa_token_request( 'POST', '/aura/v2/rules' ) ) );
	}

	public function test_connect_stays_reachable_so_a_marked_site_can_be_rebound(): void {
		$this->assertTrue( sa_dispatch_permission( sa_token_request( 'POST', '/aura/v1/connect' ) ) );
	}

	/**
	 * The boundary refuses because of the MARKER, not on principle: a bound
	 * site's mutating routes still authorize.
	 */
	public function test_a_bound_site_is_not_refused(): void {
		sa_clear_marker();
		$this->assertTrue( sa_dispatch_permission( sa_token_request( 'POST', '/aura/v1/update/core' ) ) );
	}

	/**
	 * An unreadable marker is not a clean site. is_set() answers true on a
	 * WP_Error read, and a refusal boundary is exactly where that has to be
	 * the answer — the alternative is a database blip re-opening every write.
	 */
	public function test_an_unreadable_marker_still_refuses(): void {
		sa_clear_marker();
		$GLOBALS['_sa_option_read_fail'][ Aura_Worker_Unbind::OPTION ] = true;
		try {
			$result = sa_dispatch_permission( sa_token_request( 'POST', '/aura/v1/update/core' ) );
		} finally {
			$GLOBALS['_sa_option_read_fail'] = array();
		}
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aura_site_unbound', $result->get_error_code() );
	}

	/**
	 * The unbind refusal comes AFTER authentication, never instead of it: a
	 * caller who cannot prove it holds the token gets the token layer's
	 * answer, and learns nothing about this site's binding. Also the state
	 * Phase B's final step leaves behind — the token deleted.
	 */
	public function test_token_gone_after_final_cleanup_is_the_token_layers_answer(): void {
		delete_option( 'aura_worker_site_token' );
		unset( $GLOBALS['_rows']['aura_worker_site_token'] );
		$result = sa_dispatch_permission( sa_token_request( 'POST', '/aura/v1/update/core' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aura_not_configured', $result->get_error_code() );
	}

	public function test_a_wrong_token_gets_the_token_layers_answer_not_the_unbind_one(): void {
		$request = sa_token_request( 'POST', '/aura/v1/update/core' );
		$request->set_header( 'X-Aura-Token', 'not-the-token' );
		$result = sa_dispatch_permission( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aura_invalid_token', $result->get_error_code() );
	}

	/**
	 * The grant verifier is the OTHER way a call reaches a mutation: the
	 * WordPress Abilities path (Aura_Worker_Call_Context) never touches these
	 * permission callbacks, and asks the verifier instead.
	 */
	public function test_grant_verify_refuses(): void {
		$result = Aura_Worker_Grant::verify( 'any-header', 'some_tool', array() );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aura_site_unbound', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * And keeps its own contract at a bound site: a short string reason, which
	 * is what both callers were written against.
	 */
	public function test_grant_verify_keeps_its_string_contract_at_a_bound_site(): void {
		sa_clear_marker();
		$this->assertIsString( Aura_Worker_Grant::verify( 'not-a-grant', 'some_tool', array() ) );
	}

	/**
	 * The one caller that renders the verifier's answer into a message string.
	 * A WP_Error concatenated into that string is a fatal, so the handler has
	 * to recognise it — and the refusal must arrive as its own code and
	 * status, not as an "invalid grant".
	 */
	public function test_the_mcp_execute_handler_renders_the_grant_refusal_without_fataling(): void {
		$mcp     = new Aura_Worker_MCP( new Aura_Worker_Security() );
		$request = sa_token_request( 'POST', '/aura/mcp/tools/execute' );
		$request->set_param( 'tool', 'update_plugin_safely' );
		$request->set_param( 'params', array( 'plugin' => 'akismet/akismet.php' ) );

		$response = $mcp->execute_tool( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 403, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['success'] );
		$this->assertSame( 'aura_site_unbound', $data['code'] );
		$this->assertSame( array(), $GLOBALS['_mutations'] );
	}
}
