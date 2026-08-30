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
