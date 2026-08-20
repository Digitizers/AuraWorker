<?php
/**
 * Which transport did this call arrive on?
 *
 * SiteAgent's approval grants are enforced in its own REST handlers. That was
 * sufficient while `aura/*` was the only way to reach a tool. Dual-registering
 * the tools as WordPress abilities (2.5.0) changed the premise: an ability is
 * published to the SITE, not to a server, so any co-installed MCP server that
 * enumerates `wp_get_abilities()` can serve it. Elementor's Angie does exactly
 * that for every third-party ability which does not declare a non-`tool`
 * `meta.mcp.type`.
 *
 * The trust model recorded in class-aura-worker-mcp.php — "the Abilities /
 * Application-Password path has a different, capability-based trust model" —
 * was a deliberate decision, and it is the decision this class revisits. It
 * held while the capability was the whole gate. It stops holding once an AI
 * assistant with an admin application password can reach a mutating tool
 * through somebody else's MCP server, because then "capability-gated" means
 * "an agent may mutate the site with no approval, no snapshot binding, and no
 * audit entry" — the invariant the product exists to keep.
 *
 * Modelled on the fork's `Elementor_MCP_Call_Context` (elementor-mcp #59). The
 * shape is deliberately the same so the two can be reasoned about together;
 * the grant verifier and the tool registry are SiteAgent's own.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Worker_Call_Context {

	/**
	 * REST namespaces SiteAgent serves itself. A call arriving on one of these
	 * has already passed the gateway's own enforcement — grants in
	 * class-aura-worker-api.php and class-aura-worker-mcp.php — so this class
	 * must not double-refuse it.
	 */
	const OWN_NAMESPACES = array( 'aura/v1', 'aura/v2', 'aura/mcp' );

	/**
	 * The REST route currently dispatching, or null outside REST.
	 *
	 * @var string|null
	 */
	private static $rest_route = null;

	/**
	 * Calls whose grant already verified during THIS request.
	 *
	 * `Aura_Worker_Grant::verify()` reserves the nonce as it validates —
	 * single-use is the point — and `WP_Ability::execute()` re-runs the
	 * permission callback before executing. Verifying twice therefore spends
	 * the grant on the first check and refuses the second, turning every valid
	 * approved mutation into a denial. The grant is proven once per call and
	 * the proof is remembered for the rest of the request.
	 *
	 * @var array<string,bool>
	 */
	private static $verified = array();

	/**
	 * Start recording the dispatching route.
	 */
	public static function init() {
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'record' ), 10, 3 );
	}

	/**
	 * Record the route without altering dispatch. A pass-through filter: it
	 * returns $result untouched, always.
	 *
	 * @param mixed           $result  Short-circuit value.
	 * @param mixed           $server  REST server.
	 * @param WP_REST_Request $request Request being dispatched.
	 * @return mixed
	 */
	public static function record( $result, $server = null, $request = null ) {
		if ( $request && method_exists( $request, 'get_route' ) ) {
			self::$rest_route = (string) $request->get_route();
		}
		return $result;
	}

	/**
	 * The route this call arrived on, or null when it is not a REST call.
	 *
	 * @return string|null
	 */
	public static function rest_route() {
		return self::$rest_route;
	}

	/**
	 * Test seam: pretend a REST dispatch is in progress.
	 *
	 * The alternative is a predicate nobody can exercise, which is how a
	 * security check gets inverted without a single test failing.
	 *
	 * @param string|null $route Route, or null for "not a REST call".
	 */
	public static function set_rest_route_for_tests( $route ) {
		self::$rest_route = null === $route ? null : (string) $route;
	}

	/**
	 * Forget any recorded route.
	 */
	public static function reset() {
		self::$rest_route = null;
		self::$verified   = array();
	}

	/**
	 * Is this call on a transport SiteAgent itself serves?
	 *
	 * WP-CLI and non-REST contexts (cron, admin-ajax, direct PHP) count: they
	 * are the site operating on itself, not a remote agent, and refusing them
	 * would break maintenance paths without closing any door.
	 *
	 * @return bool
	 */
	public static function is_own_transport() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}
		$route = self::$rest_route;
		if ( null === $route || '' === $route ) {
			// Not dispatching a REST request.
			return ! ( defined( 'REST_REQUEST' ) && REST_REQUEST );
		}
		$path = ltrim( $route, '/' );
		foreach ( self::OWN_NAMESPACES as $ns ) {
			// Match at a route boundary, not on any prefix: `aura/v10/...` and
			// `aura/mcp-foreign/...` both start with a namespace we own and are
			// neither of them ours. A foreign server is free to choose its own
			// namespace, so a prefix test hands it the exemption.
			if ( $path === $ns || 0 === strpos( $path, $ns . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Human-readable transport, for denial messages and audit records.
	 *
	 * @return string
	 */
	public static function describe() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'wp-cli';
		}
		$route = self::$rest_route;
		if ( null === $route || '' === $route ) {
			return 'non-rest';
		}
		return 'rest:' . $route;
	}

	/**
	 * Does this tool change the site?
	 *
	 * Mirrors the rule the gateway path already uses
	 * (class-aura-worker-mcp.php): a grant is required for any non-read-only
	 * tool OR any tool declaring requires_approval — the second clause covers a
	 * dangerous READ such as `db_query`, read-only yet approval-bound.
	 *
	 * @param array $annotations Tool annotations.
	 * @return bool
	 */
	public static function tool_needs_grant( $annotations ) {
		if ( ! is_array( $annotations ) ) {
			return true;
		}
		return empty( $annotations['read_only'] ) || ! empty( $annotations['requires_approval'] );
	}

	/**
	 * The decision itself, as a pure function of the facts.
	 *
	 * Extracted so it can be tested against every combination, including the
	 * ones a running suite cannot produce by arranging the world — a predicate
	 * that can only be exercised one way is one nobody notices inverting.
	 *
	 * @param bool $needs_grant   Tool mutates (or is approval-bound).
	 * @param bool $own_transport Call arrived on a SiteAgent route.
	 * @param bool $grant_valid   A valid grant was presented for THIS call.
	 * @return string|null Denial reason, or null to allow.
	 */
	public static function decide( $needs_grant, $own_transport, $grant_valid ) {
		if ( ! $needs_grant || $own_transport ) {
			return null;
		}
		if ( $grant_valid ) {
			return null;
		}
		// Fail closed, and note that this does NOT consult
		// Aura_Worker_Grant::is_enforced(). A site with no gateway public key
		// provisioned cannot verify a grant at all, so on the gateway path it
		// runs token-only by design. Reading that as "grants are off, allow the
		// foreign call" would make an unprovisioned site the most exposed one.
		return 'approval grant required for a mutating ability reached over another transport';
	}

	/**
	 * Gate a mutating ability at the permission stage.
	 *
	 * @param string $tool_name   Tool the ability wraps.
	 * @param array  $annotations Tool annotations.
	 * @param array  $input       Ability input (grant binds to it).
	 * @return true|WP_Error
	 */
	public static function guard( $tool_name, $annotations, $input ) {
		$reason = self::decide(
			self::tool_needs_grant( $annotations ),
			self::is_own_transport(),
			self::grant_valid_for( $tool_name, $input )
		);
		if ( null === $reason ) {
			return true;
		}

		/**
		 * Fires when a mutating ability is refused for want of a grant.
		 *
		 * The refusal is the point, but a site that is being probed should be
		 * able to see it — same forensic contract as `aura_worker_grant_denied`
		 * on the REST path.
		 *
		 * @param string $tool_name Tool that was refused.
		 * @param string $transport Where the call came from.
		 */
		do_action( 'aura_worker_ability_denied', (string) $tool_name, self::describe() );

		return new WP_Error(
			'aura_grant_required',
			$reason . ' (' . self::describe() . ')',
			array( 'status' => 403 )
		);
	}

	/**
	 * Was a valid approval grant presented for this exact call?
	 *
	 * The grant binds to the tool name and parameters, exactly as on the
	 * gateway path, so a grant the gateway minted works here unchanged — the
	 * governed path stays open, only the ungoverned one closes.
	 *
	 * @param string $tool_name Tool name.
	 * @param array  $input     Ability input.
	 * @return bool
	 */
	private static function grant_valid_for( $tool_name, $input ) {
		if ( ! class_exists( 'Aura_Worker_Grant' ) || ! Aura_Worker_Grant::is_enforced() ) {
			// No verifier, so nothing can be proven — and an unprovable grant
			// is not a grant. decide() turns this into a refusal.
			return false;
		}
		$grant = '';
		if ( isset( $_SERVER['HTTP_X_AURA_APPROVAL_GRANT'] ) ) {
			$grant = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_AURA_APPROVAL_GRANT'] ) );
		}
		if ( '' === $grant ) {
			return false;
		}

		$params = is_array( $input ) ? $input : array();
		// Keyed on the grant AND the exact call it is being spent on, so the
		// memo can only ever re-answer the question it actually answered: a
		// second ability, or the same ability with different parameters, is a
		// different call and verifies on its own.
		$key = hash(
			'sha256',
			$grant . '|' . (string) $tool_name . '|' . Aura_Worker_Grant::canonical_json( $params )
		);
		if ( isset( self::$verified[ $key ] ) ) {
			return self::$verified[ $key ];
		}

		$ok                     = true === Aura_Worker_Grant::verify( $grant, (string) $tool_name, $params );
		// Only a success is remembered. A failure may be transient (a clock
		// skew, a grant that has not started yet), and caching it would deny a
		// call that would otherwise be allowed moments later in the same
		// request — while caching a success is what keeps single-use single.
		if ( $ok ) {
			self::$verified[ $key ] = true;
		}
		return $ok;
	}
}
