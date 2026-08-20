<?php
/**
 * The Abilities-API surface as a transport, not just a registration.
 *
 * SiteAgent dual-registers its tools as WordPress abilities (since 2.5.0) so
 * standard MCP clients can discover them. That registration publishes to the
 * SITE, not to a server: any co-installed MCP server enumerating
 * `wp_get_abilities()` can serve them — Elementor's Angie exposes every
 * third-party ability that does not declare a non-`tool` `meta.mcp.type`.
 *
 * The gateway's approval grants are enforced in the REST handlers
 * (`class-aura-worker-api.php`, `class-aura-worker-mcp.php`). The ability
 * executor calls `execute_tool()` directly, so nothing on that path had ever
 * asked for a grant — an assistant holding an admin application password could
 * run a mutating tool with no approval, no snapshot binding, and no audit
 * entry, which is exactly the invariant the product sells.
 *
 * These tests pin the two guards that close it (Aura plan J5):
 *   1. registration — mutating abilities declare a type co-installed servers
 *      do not serve;
 *   2. permission — a mutating ability arriving on any transport but
 *      SiteAgent's own is refused unless it carries a valid grant.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class AbilitiesForeignTransportTest extends TestCase {

	protected function setUp(): void {
		sa_reset_state();
		Aura_Worker_Call_Context::reset();
		$abilities = new Aura_Worker_Abilities();
		$abilities->register_category();
		$abilities->register();
	}

	protected function tearDown(): void {
		Aura_Worker_Call_Context::reset();
	}

	/**
	 * Abilities that need a grant: the mutating ones, plus the approval-bound
	 * reads. Same rule the gateway path uses — `db_query` is read_only and
	 * requires_approval, and a stolen credential that dumps the database is not
	 * a lesser problem than one that writes.
	 */
	private function mutating_abilities(): array {
		$out = array();
		foreach ( $GLOBALS['_abilities'] as $name => $args ) {
			$ann = isset( $args['meta']['annotations'] ) ? $args['meta']['annotations'] : array();
			if ( empty( $ann['readonly'] ) || ! empty( $ann['requires_approval'] ) ) {
				$out[ $name ] = $args;
			}
		}
		return $out;
	}

	/** Reads that carry no approval requirement — the discoverable set. */
	private function plain_read_abilities(): array {
		$out = array();
		foreach ( $GLOBALS['_abilities'] as $name => $args ) {
			$ann = isset( $args['meta']['annotations'] ) ? $args['meta']['annotations'] : array();
			if ( ! empty( $ann['readonly'] ) && empty( $ann['requires_approval'] ) ) {
				$out[ $name ] = $args;
			}
		}
		return $out;
	}

	public function test_the_registration_has_mutating_abilities_to_protect(): void {
		// If this ever hits zero the rest of the file is vacuously green.
		$this->assertNotEmpty( $this->mutating_abilities() );
	}

	public function test_mutating_abilities_are_not_offered_to_co_installed_servers(): void {
		foreach ( $this->mutating_abilities() as $name => $args ) {
			$mcp = isset( $args['meta']['mcp'] ) ? $args['meta']['mcp'] : array();
			$this->assertArrayHasKey( 'type', $mcp, "{$name} declares no mcp.type, so Angie serves it" );
			$this->assertNotSame( 'tool', $mcp['type'], "{$name} is offered as a tool to any MCP server" );
			$this->assertNotSame( 'resource', $mcp['type'], "{$name} would be published as a resource instead" );
			$this->assertEmpty( $mcp['public'] ?? false, "{$name} still opts into Angie's own surface" );
		}
	}

	public function test_plain_read_abilities_stay_discoverable(): void {
		// The shield is aimed at writes. Withdrawing the reads too would break
		// the interoperability the dual-registration exists for.
		//
		// "Read" here means read-only AND not approval-bound: `db_query` is
		// read_only yet requires_approval, because a stolen credential that can
		// dump the database is not a lesser problem than one that can write.
		// Those follow the write rule, and the gateway path already says so.
		$read = $this->plain_read_abilities();
		$this->assertNotEmpty( $read );
		foreach ( $read as $name => $args ) {
			$type = $args['meta']['mcp']['type'] ?? 'tool';
			$this->assertSame( 'tool', $type, "read ability {$name} was withdrawn from discovery" );
		}
	}

	public function test_a_mutating_ability_refuses_a_foreign_transport_without_a_grant(): void {
		// The door this closes: a request arriving on someone else's REST route
		// with an admin application password.
		$GLOBALS['_caps'] = array( 'manage_options' );
		Aura_Worker_Call_Context::set_rest_route_for_tests( '/mcp/angie' );

		foreach ( $this->mutating_abilities() as $name => $args ) {
			$allowed = call_user_func( $args['permission_callback'], array() );
			$this->assertTrue(
				is_wp_error( $allowed ) || false === $allowed,
				"{$name} executed over a foreign transport with no approval grant"
			);
		}
	}

	public function test_the_same_ability_is_allowed_on_siteagent_s_own_transport(): void {
		$GLOBALS['_caps'] = array( 'manage_options' );
		Aura_Worker_Call_Context::set_rest_route_for_tests( '/aura/mcp/tools/execute' );

		foreach ( $this->mutating_abilities() as $name => $args ) {
			$this->assertTrue(
				true === call_user_func( $args['permission_callback'], array() ),
				"{$name} was refused on SiteAgent's own gateway path"
			);
		}
	}

	public function test_plain_read_abilities_are_unaffected_by_the_transport(): void {
		$GLOBALS['_caps'] = array( 'manage_options' );
		Aura_Worker_Call_Context::set_rest_route_for_tests( '/mcp/angie' );

		$checked = 0;
		foreach ( $this->plain_read_abilities() as $name => $args ) {
			++$checked;
			$this->assertTrue(
				true === call_user_func( $args['permission_callback'], array() ),
				"read ability {$name} was refused over a foreign transport"
			);
		}
		$this->assertGreaterThan( 0, $checked );
	}

	public function test_a_namespace_that_merely_starts_like_ours_is_foreign(): void {
		// A foreign server picks its own namespace. `aura/v10/...` and
		// `aura/mcp-foreign/...` both start with a namespace we own and are
		// neither of them, so a prefix test would hand them the exemption.
		$GLOBALS['_caps'] = array( 'manage_options' );

		foreach ( array( '/aura/v10/write', '/aura/mcp-foreign/tools/execute', '/aura/v1x/y' ) as $route ) {
			Aura_Worker_Call_Context::set_rest_route_for_tests( $route );
			$this->assertFalse(
				Aura_Worker_Call_Context::is_own_transport(),
				"{$route} was treated as SiteAgent's own transport"
			);

			foreach ( $this->mutating_abilities() as $name => $args ) {
				$allowed = call_user_func( $args['permission_callback'], array() );
				$this->assertTrue(
					is_wp_error( $allowed ) || false === $allowed,
					"{$name} was allowed over {$route}"
				);
			}
		}
	}

	public function test_our_own_namespaces_are_still_recognised(): void {
		foreach ( array( '/aura/v1/status', '/aura/v2/health', '/aura/mcp/tools/execute', 'aura/mcp' ) as $route ) {
			Aura_Worker_Call_Context::set_rest_route_for_tests( $route );
			$this->assertTrue(
				Aura_Worker_Call_Context::is_own_transport(),
				"{$route} was not recognised as SiteAgent's own"
			);
		}
	}

	public function test_capability_still_gates_before_any_of_this(): void {
		// The transport guard is additive. A caller without manage_options is
		// refused wherever the request came from.
		$GLOBALS['_caps'] = array();
		Aura_Worker_Call_Context::set_rest_route_for_tests( '/aura/mcp/tools/execute' );

		foreach ( $GLOBALS['_abilities'] as $name => $args ) {
			$allowed = call_user_func( $args['permission_callback'], array() );
			$this->assertTrue(
				is_wp_error( $allowed ) || false === $allowed,
				"{$name} allowed a caller without manage_options"
			);
		}
	}
}
