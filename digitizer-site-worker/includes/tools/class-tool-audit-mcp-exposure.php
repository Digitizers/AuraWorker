<?php
/**
 * MCP Tool: audit_mcp_exposure
 *
 * Read-only inventory of the site's *other* agent doors.
 *
 * WordPress's Abilities API is a site-wide registry: `wp_register_ability()`
 * publishes to the site, not to a server. Any MCP server installed alongside
 * SiteAgent can therefore enumerate that registry and serve whatever it finds,
 * over a transport the Aura gateway never sees — no approval queue, no audit,
 * no fleet visibility. Elementor's Angie 1.1.12 ships exactly such a server at
 * `/mcp/angie`, and its `execute-ability` proxy runs any third-party ability by
 * name.
 *
 * An operator cannot learn this from anywhere else: the other server registers
 * its own routes and reads the shared registry silently. This tool reports what
 * is there so the fleet rollup can flag the sites that have a second door and
 * how much is behind it.
 *
 * Facts, not verdicts — the same contract as the other audit tools. "A second
 * MCP server exists" and "N mutating abilities are exposed by the type rule"
 * are checkable statements. "This site is compromised" is not, and a plugin's
 * abilities being reachable may be exactly what its author intended.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Tool_Audit_Mcp_Exposure extends Aura_Tool_Base {

	/** Hard cap on inventoried abilities. */
	const MAX_ABILITIES = 500;

	/** Hard cap on named exposed-mutating abilities in the response. */
	const MAX_NAMED = 100;

	/**
	 * Where the adapter version lives, most authoritative first. The official
	 * WordPress MCP Adapter publishes the first; the second is the name a
	 * bundled copy may use.
	 *
	 * @var string[]
	 */
	const VERSION_CONSTANTS = array( 'WP_MCP_ADAPTER_VERSION', 'WP_MCP_VERSION' );

	public function get_name() {
		return 'audit_mcp_exposure';
	}

	public function get_description() {
		return 'Read-only audit of OTHER agent doors on this site: whether the WordPress Abilities API and an MCP adapter are active, which MCP servers besides SiteAgent are registered (id and route), and how many registered abilities a co-installed server would be able to reach — split by whether they mutate. Reports facts (server present, ability counts, exposure rule outcome), never a verdict. Makes no changes.';
	}

	public function get_parameters() {
		return array();
	}

	public function get_returns() {
		return array(
			'abilities_api_active' => 'bool — whether wp_get_abilities() exists on this site',
			'mcp_adapter'          => 'object — { active, version }; version is read from WP_MCP_ADAPTER_VERSION (the official adapter) with WP_MCP_VERSION as a fallback for bundled copies',
			'servers'              => 'array — { id, route, tool_count } for every MCP server registered on this site',
			'angie'                => 'object — { active, version, mcp_server_present } (the known second door; absence of Angie does not mean absence of a second server)',
			'abilities'            => 'object — { total, discoverable_by_type_rule, discoverable_and_mutating, discoverable_mutating_names }. These count abilities that PASS the discovery rule co-installed servers apply (no meta.mcp.type, or "tool") — a property of the abilities, NOT proof that anything currently serves them. Reachability additionally requires a server that resolves targets from the site-wide registry; a server with an explicit tool list reaches only what it lists. Read together with `servers`: with none registered, these counts describe a door that does not exist yet.',
			'coverage'             => 'object — { total_seen, returned, truncated, cap } bounded-coverage contract',
		);
	}

	/**
	 * Read-only: never mutates the site.
	 */
	public function get_annotations() {
		return array(
			'read_only'         => true,
			'destructive'       => false,
			'requires_approval' => false,
			'supports_preview'  => false,
		);
	}

	public function execute( $params ) {
		$abilities_active = function_exists( 'wp_get_abilities' );

		return array(
			'abilities_api_active' => $abilities_active,
			'mcp_adapter'          => $this->adapter_state(),
			'servers'              => $this->servers(),
			'angie'                => $this->angie_state(),
		) + $this->ability_exposure( $abilities_active );
	}

	/**
	 * Whether an MCP adapter is loaded, and which version.
	 *
	 * @return array
	 */
	protected function adapter_state() {
		// The official WordPress MCP Adapter publishes WP_MCP_ADAPTER_VERSION;
		// WP_MCP_VERSION is the name a bundled copy may use. Checking only the
		// latter reported `active: true` with an empty version on the very sites
		// most likely to have a second door — a field that is present but blank
		// reads as "unknown", which is worse than the answer being available.
		$values = array();
		foreach ( self::VERSION_CONSTANTS as $constant ) {
			if ( defined( $constant ) ) {
				$values[ $constant ] = constant( $constant );
			}
		}

		return array(
			'active'  => class_exists( '\\WP\\MCP\\Core\\McpAdapter' ),
			'version' => self::pick_version( $values ),
		);
	}

	/**
	 * The adapter version, given whichever constants are defined.
	 *
	 * Split from the constant lookup so both branches are reachable in a test:
	 * a suite cannot define a constant for one case and undefine it for the
	 * next, and mirroring the precedence in the test instead would be testing
	 * the mirror.
	 *
	 * @param array $values Map of constant name => value, for those defined.
	 * @return string
	 */
	public static function pick_version( array $values ) {
		foreach ( self::VERSION_CONSTANTS as $constant ) {
			if ( isset( $values[ $constant ] ) && '' !== (string) $values[ $constant ] ) {
				return (string) $values[ $constant ];
			}
		}
		return '';
	}

	/**
	 * Every MCP server registered through the adapter, by id and route.
	 *
	 * Read from the adapter's registry rather than by probing for known plugins:
	 * Angie's is the server that exists today, but the exposure belongs to the
	 * shared registry, and the next plugin to create a server inherits it. A
	 * hardcoded Angie check would report a clean site.
	 *
	 * SiteAgent's own tools ride the `aura/mcp` REST interface rather than the
	 * Abilities API, so nothing here is ours to exclude — every entry is a door
	 * other than the gateway's.
	 *
	 * @return array
	 */
	protected function servers() {
		if ( ! class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ) {
			return array();
		}

		$adapter = \WP\MCP\Core\McpAdapter::instance();
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'get_servers' ) ) {
			return array();
		}

		$servers = array();
		foreach ( (array) $adapter->get_servers() as $id => $server ) {
			$route = '';
			$count = null;
			if ( is_object( $server ) ) {
				if ( method_exists( $server, 'get_server_route_namespace' ) && method_exists( $server, 'get_server_route' ) ) {
					$route = '/' . trim( (string) $server->get_server_route_namespace(), '/' )
						. '/' . trim( (string) $server->get_server_route(), '/' );
				}
				if ( method_exists( $server, 'get_tools' ) ) {
					$tools = $server->get_tools();
					$count = is_array( $tools ) ? count( $tools ) : null;
				}
			}
			$servers[] = array(
				'id'         => is_string( $id ) ? $id : '',
				'route'      => $route,
				// A server's OWN tool count. Deliberately not presented as "how
				// much it can reach": a server whose tools resolve targets from
				// the site-wide registry (Angie's execute-ability does) reaches
				// far more than it lists, and one with an explicit list reaches
				// only what is on it. The ability counts below answer that.
				'tool_count' => $count,
			);
		}

		return $servers;
	}

	/**
	 * The known second door, named because operators ask about it by name.
	 *
	 * `mcp_server_present` is the honest part: Angie being active is not the
	 * same as Angie exposing an MCP server, and the module can be off.
	 *
	 * @return array
	 */
	protected function angie_state() {
		$active = defined( 'ANGIE_VERSION' ) || class_exists( '\\Angie\\Plugin' );
		$server = false;
		foreach ( $this->servers() as $entry ) {
			if ( isset( $entry['id'] ) && 'angie' === $entry['id'] ) {
				$server = true;
				break;
			}
		}

		return array(
			'active'             => $active,
			'version'            => defined( 'ANGIE_VERSION' ) ? (string) ANGIE_VERSION : '',
			'mcp_server_present' => $server,
		);
	}

	/**
	 * How many registered abilities PASS the discovery rule co-installed servers
	 * apply, and how many of those mutate.
	 *
	 * This is a property of the abilities, not a claim that anything serves
	 * them. Reachability additionally needs a server that resolves targets from
	 * the site-wide registry — Angie's `execute-ability` does; a server with an
	 * explicit tool list reaches only what it lists, and a site with no second
	 * server at all reaches none of them. The counts are still worth reporting
	 * on such a site, because they say what WOULD be handed over the moment one
	 * is installed, and `servers` is right there to say whether one is. Naming
	 * them after the rule rather than after an outcome keeps a consumer from
	 * reading "40 exposed" as "40 reachable".
	 *
	 * The rule applied is the one co-installed servers use: an ability is
	 * exposed when it declares no `meta.mcp.type`, or declares `tool`. That is
	 * Angie's `Mcp_Adapter_Ability_Discovery` rule, read from its source, and it
	 * gates execution there as well as listing. An ability declaring anything
	 * else — as elementor-mcp 1.30.0+ does for its writes — is not served.
	 *
	 * Mutation is read from the ability's own `readonly` annotation. An ability
	 * that does not classify itself is counted as neither: guessing from a name
	 * would turn a naming convention into a security finding.
	 *
	 * @param bool $abilities_active Whether the registry is available.
	 * @return array
	 */
	protected function ability_exposure( $abilities_active ) {
		if ( ! $abilities_active ) {
			return array(
				'abilities' => array(
					'total'                    => 0,
					'discoverable_by_type_rule' => 0,
					'discoverable_and_mutating'     => 0,
					'discoverable_mutating_names'   => array(),
				),
				'coverage'  => array(
					'total_seen' => 0,
					'returned'   => 0,
					'truncated'  => false,
					'cap'        => '',
				),
			);
		}

		$total      = 0;
		$inspected  = 0;
		$exposed    = 0;
		$mutating   = 0;
		$names      = array();
		$truncated  = false;
		$names_full = false;

		foreach ( (array) wp_get_abilities() as $ability ) {
			$total++;
			if ( $inspected >= static::MAX_ABILITIES ) {
				$truncated = true;
				continue;
			}
			$inspected++;

			if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' ) ) {
				continue;
			}
			$meta = $ability->get_meta();
			$meta = is_array( $meta ) ? $meta : array();

			$type = isset( $meta['mcp']['type'] ) ? $meta['mcp']['type'] : 'tool';
			if ( 'tool' !== $type ) {
				continue;
			}
			$exposed++;

			$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] )
				? $meta['annotations']
				: array();
			// Explicit readonly=false only. An ability that never classified
			// itself is not known to write, and inferring from its name would
			// make a convention into a finding.
			if ( ! array_key_exists( 'readonly', $annotations ) || false !== $annotations['readonly'] ) {
				continue;
			}
			$mutating++;

			if ( count( $names ) >= static::MAX_NAMED ) {
				$names_full = true;
				continue;
			}
			if ( method_exists( $ability, 'get_name' ) ) {
				$names[] = (string) $ability->get_name();
			}
		}

		return array(
			'abilities' => array(
				'total'                    => $total,
				'discoverable_by_type_rule' => $exposed,
				'discoverable_and_mutating'     => $mutating,
				'discoverable_mutating_names'   => $names,
			),
			'coverage'  => array(
				'total_seen' => $total,
				'returned'   => $inspected,
				// Either cap makes the response a lower bound, and the rollup
				// must be able to say so without knowing which one tripped.
				'truncated'  => ( $truncated || $names_full ),
				'cap'        => $truncated ? 'max_abilities' : ( $names_full ? 'max_named' : '' ),
			),
		);
	}
}
