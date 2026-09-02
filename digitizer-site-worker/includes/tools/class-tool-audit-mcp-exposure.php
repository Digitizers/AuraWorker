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

	/** Every list the `elementor` block returns is capped at this many rows (spec §3). */
	const ELEMENTOR_LIST_CAP = 50;

	/** `edit_posts` users inspected for the app-password context, at most. */
	const ELEMENTOR_CONTEXT_CAP = 200;

	/** Page size of the context enumeration. */
	const ELEMENTOR_CONTEXT_PAGE = 50;

	/** Every string the block returns is clipped to this many characters. */
	const ELEMENTOR_STRING_MAX = 200;

	/** "Used recently" horizon for the context counts, in days. */
	const ELEMENTOR_RECENT_DAYS = 30;

	/**
	 * Raw serialized usermeta length considered parse-safe. MUST equal
	 * Aura_Tool_Audit_Admin_Accounts::MAX_APP_PASSWORD_BYTES (a test pins it):
	 * one bound for one kind of row, whichever tool reads it.
	 */
	const MAX_APP_PASSWORD_BYTES = 262144; // 256 KB.

	const ELEMENTOR_PASSWORD_PREFIX = 'Elementor MCP';
	const ELEMENTOR_SERVER_ID       = 'elementor-mcp-server';
	const ELEMENTOR_CONSENT_META    = 'elementor_mcp_consent';
	const ELEMENTOR_MODULE_CLASS    = '\\Elementor\\Modules\\Mcp\\Module';

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
			'elementor'            => 'object — Elementor >= 4.3\'s official MCP door (2.15.0). { installed, version, mcp_module: { class_present, active, abilities_registered, server_id }, consent: [{ user_id, login, allowed, timestamp }], consent_unproven: [user_id], consent_truncated, app_passwords: { elementor: [{ user_id, login, name, created, last_used, last_ip }], elementor_entries_truncated, elementor_unproven: [user_id], candidates_read, elementor_truncated, other: { users_checked, count, recently_used, unproven: [user_id] } }, coverage: { users_total, users_checked, truncated, cap } }. consent rows and Elementor-named Application Passwords are found across ALL users (two bounded usermeta queries, 50 rows each); every other Application Password of edit_posts users is counted (200 users). No usermeta value over 256 KB is decoded — such a row is listed in the subtree\'s *_unproven. A scan that failed is { error } in its place (mcp_module / consent / app_passwords.elementor / app_passwords.other + coverage), never an empty list. Requires manage_options; every subtree is { error: \'manage_options required\' } otherwise. Shape: Digitizers/Aura docs/superpowers/specs/2026-09-02-elementor-mcp-door-detection-design.md §3.',
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
			'elementor'            => $this->elementor_state(),
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
	 * Clip a string to the block's per-string bound.
	 *
	 * @param mixed $s Value.
	 * @return string
	 */
	protected function clip( $s ) {
		$s = (string) $s;
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, static::ELEMENTOR_STRING_MAX ) : substr( $s, 0, static::ELEMENTOR_STRING_MAX );
	}

	/**
	 * The error subtree for a scan that threw.
	 *
	 * @param \Throwable $e The throw.
	 * @return array { error }
	 */
	protected function subtree_error( $e ) {
		$msg = $e->getMessage();
		return array( 'error' => $this->clip( '' === $msg ? get_class( $e ) : $msg ) );
	}

	/**
	 * Clears `$wpdb->last_error` before a statement this class is about to
	 * judge by it, guarded so a foreign `$wpdb` lacking the property cannot
	 * fatal.
	 *
	 * @param mixed $wpdb A wpdb-like object.
	 * @return void
	 */
	private static function clear_last_error( $wpdb ) {
		if ( is_object( $wpdb ) && property_exists( $wpdb, 'last_error' ) ) {
			$wpdb->last_error = '';
		}
	}

	/**
	 * Whether a `get_results()` call failed: not-an-array (a database or
	 * driver outage never reaching a result set), OR an array with
	 * `last_error` set. `get_results()` answers its CLEARED `$last_result` —
	 * an empty array — when the statement itself fails, so an array alone is
	 * never proof of success; only `last_error`, read right after the same
	 * call, tells a broken table apart from a clean "no rows" (Codex round-2
	 * P2).
	 *
	 * @param mixed $wpdb A wpdb-like object.
	 * @param mixed $rows Whatever `get_results()` returned.
	 * @return bool
	 */
	private static function results_failed( $wpdb, $rows ) {
		if ( ! is_array( $rows ) ) {
			return true;
		}
		return is_object( $wpdb ) && isset( $wpdb->last_error ) && '' !== (string) $wpdb->last_error;
	}

	/**
	 * Elementor's presence and module state, read from the live site.
	 * A seam: tests override it, since a suite cannot define-then-undefine
	 * ELEMENTOR_VERSION or unload a class.
	 *
	 * `installed` and `version` are decided from the installed-plugin
	 * INVENTORY, not only from runtime signals: a deactivated Elementor loads
	 * neither ELEMENTOR_VERSION nor `\Elementor\Plugin`, yet remains on disk
	 * with its consent/password rows still meaningful (Codex round-1 P2).
	 * `class_present` / `active` stay runtime-only — they answer whether the
	 * MCP module itself is live, which a plugin on disk but deactivated is
	 * not.
	 *
	 * @return array { installed, version, class_present, active }
	 */
	protected function elementor_env() {
		$class         = static::ELEMENTOR_MODULE_CLASS;
		$class_present = class_exists( $class );
		$active        = null;
		if ( $class_present && is_callable( array( $class, 'is_active' ) ) ) {
			$active = (bool) call_user_func( array( $class, 'is_active' ) );
		}

		$header          = $this->elementor_plugin_header();
		$header_version  = is_array( $header ) && isset( $header['Version'] ) && is_string( $header['Version'] ) && '' !== $header['Version']
			? $header['Version']
			: null;

		return array(
			'installed'     => defined( 'ELEMENTOR_VERSION' ) || class_exists( '\\Elementor\\Plugin' ) || is_array( $header ),
			'version'       => defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : $header_version,
			'class_present' => $class_present,
			'active'        => $active,
		);
	}

	/**
	 * The installed-plugin inventory's header for Elementor's own file, or
	 * null when it is absent or unreadable. A seam — tests override it.
	 *
	 * Reads `get_plugins()`, the same core inventory function
	 * `Aura_Worker_Updater::get_migration_registry()` uses to detect
	 * Elementor, following its precedent for loading it
	 * (`includes/class-aura-worker-updater.php`, ~line 1224). Unlike that
	 * caller this does NOT use `is_plugin_active()`: a deactivated plugin
	 * remaining ON DISK is exactly the case this seam exists to still report.
	 * A missing admin include, or a non-array/missing entry, degrades to
	 * "not in inventory" rather than a fatal — this is a read-only audit
	 * tool that must never break a site's report over an environment quirk.
	 *
	 * @return array|null
	 */
	protected function elementor_plugin_header() {
		if ( ! defined( 'ABSPATH' ) ) {
			return null;
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			return null;
		}
		$plugins = get_plugins();
		if ( ! is_array( $plugins ) || ! isset( $plugins['elementor/elementor.php'] ) || ! is_array( $plugins['elementor/elementor.php'] ) ) {
			return null;
		}
		return $plugins['elementor/elementor.php'];
	}

	/**
	 * Names of every registered ability, or null when the Abilities API is absent.
	 *
	 * @return string[]|null
	 */
	protected function elementor_ability_names() {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return null;
		}
		$names = array();
		foreach ( (array) wp_get_abilities() as $ability ) {
			if ( is_object( $ability ) && method_exists( $ability, 'get_name' ) ) {
				$names[] = (string) $ability->get_name();
			}
		}
		return $names;
	}

	/**
	 * The module subtree from its inputs — pure, so tests reach every branch.
	 *
	 * @param array      $env           From elementor_env().
	 * @param array|null $ability_names From elementor_ability_names().
	 * @param array      $servers       From servers().
	 * @return array { class_present, active, abilities_registered, server_id }
	 */
	public static function elementor_module_from( array $env, $ability_names, array $servers ) {
		$class_present = ! empty( $env['class_present'] );
		// Same rule elementor_state() applies to the outer `installed`: the class
		// cannot exist without Elementor, so either signal counts. A server_id is
		// attributed to Elementor only when Elementor is believed present at all —
		// otherwise a same-named server registered by something else would be
		// misreported as Elementor's own door.
		$installed = ! empty( $env['installed'] ) || $class_present;
		// class absent ⇒ active null, whatever was read: the gate belongs to the class.
		$active = $class_present && array_key_exists( 'active', $env ) && is_bool( $env['active'] ) ? $env['active'] : null;
		$count  = null;
		if ( is_array( $ability_names ) ) {
			$count = 0;
			foreach ( $ability_names as $name ) {
				if ( is_string( $name ) && 0 === strpos( $name, 'elementor/' ) ) {
					++$count;
				}
			}
		}
		$server_id = null;
		if ( $installed ) {
			foreach ( $servers as $entry ) {
				if ( isset( $entry['id'] ) && static::ELEMENTOR_SERVER_ID === $entry['id'] ) {
					$server_id = static::ELEMENTOR_SERVER_ID;
					break;
				}
			}
		}
		return array(
			'class_present'        => $class_present,
			'active'               => $active,
			'abilities_registered' => $count,
			'server_id'            => $server_id,
		);
	}

	/**
	 * Storage → bool for a consent's `allowed`: Elementor writes a bool; a
	 * legacy or hand-edited row may hold 1 / '1'. Nothing else is consent.
	 *
	 * @param mixed $v Stored value.
	 * @return bool
	 */
	public static function as_bool( $v ) {
		return true === $v || 1 === $v || '1' === $v;
	}

	/**
	 * A user's login for the block. A seam — used where a row carries no
	 * login of its own (no JOIN available) and one must be looked up.
	 *
	 * @param int $uid User id.
	 * @return string|null
	 */
	protected function user_login( $uid ) {
		$u = get_userdata( (int) $uid );
		return is_object( $u ) && isset( $u->user_login ) && '' !== (string) $u->user_login ? (string) $u->user_login : null;
	}

	/**
	 * Login for a row already carrying one (the consent query's JOIN) —
	 * clipped, with the id as the fallback name when the JOIN found no user.
	 * Deliberately does NOT fall through to user_login(): the consent scan's
	 * one bounded query is the point, and a per-row lookup for every departed
	 * user would turn it into up to 50 more.
	 *
	 * @param int         $uid   User id.
	 * @param string|null $login A login already read from the row.
	 * @return string
	 */
	protected function login_for( $uid, $login = null ) {
		return is_string( $login ) && '' !== $login ? $this->clip( $login ) : 'user:' . (int) $uid;
	}

	/**
	 * Every `elementor_mcp_consent` row, ONE PER USER, valid ids only, across
	 * ALL users, bounded in rows (cap + 1, the extra only sets the flag) and
	 * in bytes (the value comes back NULL past the bound, in the same
	 * statement). The dedupe (MIN(umeta_id), "first row per user wins") and
	 * the `user_id > 0` filter run in SQL, BEFORE the LIMIT — so 51 raw rows
	 * always means 51 distinct valid users, and `consent_truncated` cannot
	 * read false while a later user's row was never fetched (Codex round-4
	 * P2, #85: the old statement capped raw rows before dedupe/filtering, so
	 * a duplicate or invalid row inside the window silently hid a real user
	 * past it). A seam.
	 *
	 * @return object[] { user_id, user_login, len, v }
	 */
	protected function consent_rows() {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->usermeta, $wpdb->users ) ) {
			throw new \RuntimeException( 'database unavailable' );
		}
		$sql = $wpdb->prepare(
			"SELECT m.user_id, u.user_login, LENGTH(m.meta_value) AS len, IF(LENGTH(m.meta_value) <= %d, m.meta_value, NULL) AS v FROM {$wpdb->usermeta} m LEFT JOIN {$wpdb->users} u ON u.ID = m.user_id WHERE m.meta_key = %s AND m.user_id > 0 AND m.umeta_id IN (SELECT MIN(umeta_id) FROM {$wpdb->usermeta} WHERE meta_key = %s GROUP BY user_id) ORDER BY m.umeta_id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			static::MAX_APP_PASSWORD_BYTES,
			static::ELEMENTOR_CONSENT_META,
			static::ELEMENTOR_CONSENT_META,
			static::ELEMENTOR_LIST_CAP + 1
		);
		if ( ! is_string( $sql ) || '' === $sql ) {
			throw new \RuntimeException( 'consent statement could not be prepared' );
		}
		static::clear_last_error( $wpdb );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql );
		if ( static::results_failed( $wpdb, $rows ) ) {
			throw new \RuntimeException( 'consent statement failed' . ( ! empty( $wpdb->last_error ) ? ': ' . esc_html( $wpdb->last_error ) : '' ) );
		}
		return $rows;
	}

	/**
	 * The consent subtree.
	 *
	 * @return array { consent, consent_unproven, consent_truncated }
	 */
	protected function elementor_consent() {
		$rows = $this->consent_rows();
		// The SQL itself now guarantees one valid-user row per user, in order,
		// before the LIMIT (see consent_rows()) — that is the PRIMARY
		// guarantee behind the truncation invariant. The filter and dedupe
		// below are belt-and-braces: no-ops against a healthy database, kept
		// so a row reaching here from any other seam (a test fake, a future
		// caller) still cannot break the invariant.
		//
		// Filter out non-user rows BEFORE the cap: the parser invariant
		// (consent_truncated ⇒ count(consent) + count(consent_unproven) == 50)
		// must hold by construction, and a row this scan will never attribute
		// to anyone must not consume one of the 50 slots.
		$rows = array_values(
			array_filter(
				$rows,
				static function ( $row ) {
					return isset( $row->user_id ) && (int) $row->user_id > 0;
				}
			)
		);
		// Dedupe by user_id BEFORE the cap too — two rows for the same user
		// (a duplicate meta row) must not land the user in both `consent` and
		// `consent_unproven`, which would break the invariant
		// consent_unproven ∩ consent[].user_id = ∅. Rows are already ordered
		// by umeta_id ASC, so the first occurrence per user is the one kept.
		$seen = array();
		$rows = array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( &$seen ) {
					$uid = (int) $row->user_id;
					if ( isset( $seen[ $uid ] ) ) {
						return false;
					}
					$seen[ $uid ] = true;
					return true;
				}
			)
		);
		$truncated = count( $rows ) > static::ELEMENTOR_LIST_CAP;
		$rows      = array_slice( $rows, 0, static::ELEMENTOR_LIST_CAP );
		$consent   = array();
		$unproven  = array();
		foreach ( $rows as $row ) {
			$uid = (int) $row->user_id;
			if ( ! isset( $row->v ) || ! is_string( $row->v ) ) {
				$unproven[] = $uid; // over the byte bound: never decoded
				continue;
			}
			// is_serialized() first, the same gate maybe_unserialize() applies,
			// so a plain non-serialized string (not the documented shape) is
			// unproven without ever handing unserialize() bytes it will warn
			// on. allowed_classes => false on the actual decode: this
			// meta_value comes from a usermeta scan with no capability
			// filter, so it must be treated as untrusted — a plain
			// unserialize()/maybe_unserialize() would let a crafted payload
			// instantiate arbitrary classes and fire __wakeup()/__destruct()
			// gadgets. Forcing classes off turns any serialized object into
			// __PHP_Incomplete_Class instead, which is not an array and lands
			// the row in consent_unproven below — never decoded as consent.
			$data = is_serialized( $row->v, true )
				? unserialize( (string) $row->v, array( 'allowed_classes' => false ) )
				: false;
			if ( ! is_array( $data ) || ! array_key_exists( 'allowed', $data ) ) {
				$unproven[] = $uid; // a row that is not the documented shape
				continue;
			}
			$consent[] = array(
				'user_id'   => $uid,
				'login'     => $this->login_for( $uid, isset( $row->user_login ) ? $row->user_login : null ),
				'allowed'   => static::as_bool( $data['allowed'] ),
				'timestamp' => isset( $data['timestamp'] ) && is_numeric( $data['timestamp'] ) ? (int) $data['timestamp'] : null,
			);
		}
		return array(
			'consent'           => $consent,
			'consent_unproven'  => $unproven,
			'consent_truncated' => $truncated,
		);
	}

	/**
	 * Users whose serialised Application Password list contains the prefix —
	 * a PRE-FILTER over all users, distinct, ordered, bounded. A seam.
	 *
	 * @return int[]
	 */
	protected function elementor_candidate_ids() {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->usermeta ) ) {
			throw new \RuntimeException( 'database unavailable' );
		}
		$sql = $wpdb->prepare(
			"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s ORDER BY user_id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'_application_passwords',
			'%' . $wpdb->esc_like( static::ELEMENTOR_PASSWORD_PREFIX ) . '%',
			static::ELEMENTOR_LIST_CAP + 1
		);
		if ( ! is_string( $sql ) || '' === $sql ) {
			throw new \RuntimeException( 'candidate statement could not be prepared' );
		}
		static::clear_last_error( $wpdb );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql );
		if ( static::results_failed( $wpdb, $rows ) ) {
			throw new \RuntimeException( 'candidate statement failed' . ( ! empty( $wpdb->last_error ) ? ': ' . esc_html( $wpdb->last_error ) : '' ) );
		}
		$ids = array();
		foreach ( $rows as $row ) {
			if ( isset( $row->user_id ) && (int) $row->user_id > 0 ) {
				$ids[] = (int) $row->user_id;
			}
		}
		return $ids;
	}

	/**
	 * One user's Application Password list, byte-bounded, PROVEN read or null. A seam.
	 *
	 * `$notify` is false: this audit is a `read_only: true` tool that can walk up
	 * to ~250 users, so an oversized or failed read here must never fire the
	 * #434 unbind breadcrumb (`aura_worker_app_password_probe_unproven`) — a
	 * read-only tool overwriting that breadcrumb with an unrelated user is a
	 * write this annotation promises never happens.
	 *
	 * @param int $uid User id.
	 * @return array|null
	 */
	protected function password_list( $uid ) {
		return aura_worker_app_password_list( (int) $uid, static::MAX_APP_PASSWORD_BYTES, false );
	}

	/**
	 * A stored Application Password item as the block reports it.
	 *
	 * @param int   $uid  Owner.
	 * @param array $item Core's stored item.
	 * @return array { user_id, login, name, created, last_used, last_ip }
	 */
	protected function password_entry( $uid, array $item ) {
		return array(
			'user_id'   => (int) $uid,
			'login'     => $this->login_for( $uid, $this->user_login( $uid ) ),
			'name'      => $this->clip( isset( $item['name'] ) ? $item['name'] : '' ),
			'created'   => isset( $item['created'] ) && is_numeric( $item['created'] ) ? (int) $item['created'] : null,
			'last_used' => isset( $item['last_used'] ) && is_numeric( $item['last_used'] ) ? (int) $item['last_used'] : null,
			'last_ip'   => isset( $item['last_ip'] ) && is_string( $item['last_ip'] ) && '' !== $item['last_ip'] ? $this->clip( $item['last_ip'] ) : null,
		);
	}

	/**
	 * Does a stored item carry an Elementor-issued name?
	 *
	 * @param mixed $item Stored item.
	 * @return bool
	 */
	protected static function is_elementor_named( $item ) {
		return is_array( $item ) && isset( $item['name'] ) && is_string( $item['name'] ) && 0 === strpos( $item['name'], static::ELEMENTOR_PASSWORD_PREFIX );
	}

	/**
	 * The Elementor-password subtree.
	 *
	 * @return array { elementor, elementor_entries_truncated, elementor_unproven, candidates_read, elementor_truncated }
	 */
	protected function elementor_passwords() {
		$candidates = $this->elementor_candidate_ids();
		$truncated  = count( $candidates ) > static::ELEMENTOR_LIST_CAP;
		$candidates = array_slice( $candidates, 0, static::ELEMENTOR_LIST_CAP );
		$entries    = array();
		$unproven   = array();
		$entries_truncated = false;
		$read       = 0;
		foreach ( $candidates as $uid ) {
			++$read;
			$list = $this->password_list( $uid );
			if ( null === $list ) {
				$unproven[] = (int) $uid; // a LIKE match is not a name read
				continue;
			}
			foreach ( $list as $item ) {
				if ( ! static::is_elementor_named( $item ) ) {
					continue;
				}
				if ( count( $entries ) >= static::ELEMENTOR_LIST_CAP ) {
					// Only THIS candidate's remaining items stop being appended —
					// the outer loop keeps walking the (<=50) candidates so every
					// one is still read (or recorded unproven) and candidates_read
					// reaches the number of candidates in the slice. A `break 2`
					// here abandoned the rest of the slice unread the moment the
					// entry cap tripped, so candidates_read could land far short
					// of 50 while elementor_truncated was still true — breaking
					// the spec invariant elementor_truncated ⇒ candidates_read == 50.
					$entries_truncated = true;
					break;
				}
				$entries[] = $this->password_entry( $uid, $item );
			}
		}
		return array(
			'elementor'                   => $entries,
			'elementor_entries_truncated' => $entries_truncated,
			'elementor_unproven'          => $unproven,
			'candidates_read'             => $read,
			'elementor_truncated'         => $truncated,
		);
	}

	/**
	 * One page of edit_posts user ids. A seam.
	 *
	 * @param int $offset Offset.
	 * @param int $number Page size.
	 * @return int[]
	 */
	protected function context_user_ids( $offset, $number ) {
		global $wpdb;
		static::clear_last_error( $wpdb );
		$q = new \WP_User_Query(
			array(
				'capability'  => 'edit_posts',
				'fields'      => 'ID',
				'number'      => (int) $number,
				'offset'      => (int) $offset,
				'orderby'     => 'ID',
				'order'       => 'ASC',
				'count_total' => false,
			)
		);
		$rows = $q->get_results();
		// WP_User_Query wraps a wpdb statement that can fail the same way the
		// direct SQL in consent_rows()/elementor_candidate_ids() can: WordPress
		// answers an empty array on a database failure, never a throw, so an
		// empty result is indistinguishable from "no edit_posts users" unless
		// last_error is consulted right after the call (Codex round-3 P2).
		if ( static::results_failed( $wpdb, $rows ) ) {
			throw new \RuntimeException( 'user query failed' . ( is_object( $wpdb ) && ! empty( $wpdb->last_error ) ? ': ' . esc_html( $wpdb->last_error ) : '' ) );
		}
		$ids = array();
		foreach ( (array) $rows as $id ) {
			$ids[] = (int) ( is_object( $id ) && isset( $id->ID ) ? $id->ID : $id );
		}
		return $ids;
	}

	/**
	 * How many edit_posts users the site has. A seam.
	 *
	 * @return int
	 */
	protected function context_users_total() {
		global $wpdb;
		static::clear_last_error( $wpdb );
		$q = new \WP_User_Query(
			array(
				'capability'  => 'edit_posts',
				'fields'      => 'ID',
				'number'      => 1,
				'count_total' => true,
			)
		);
		$total = $q->get_total();
		if ( is_object( $wpdb ) && ! empty( $wpdb->last_error ) ) {
			throw new \RuntimeException( 'user query failed: ' . esc_html( $wpdb->last_error ) );
		}
		return (int) $total;
	}

	/**
	 * The context subtree: every OTHER Application Password of edit_posts
	 * users, as counts. An Elementor-named one met here is already reported
	 * in `elementor` and is not counted again.
	 *
	 * @return array { other: { users_checked, count, recently_used, unproven }, coverage: { users_total, users_checked, truncated, cap } }
	 */
	protected function elementor_context() {
		$total     = $this->context_users_total();
		$checked   = 0;
		$count     = 0;
		$recent    = 0;
		$unproven  = array();
		$truncated = false;
		$seen      = array();
		$offset    = 0;
		$horizon   = time() - static::ELEMENTOR_RECENT_DAYS * 86400;
		// Bounded by construction, not only by the pages running dry: a
		// context_user_ids() a pre_user_query filter has stripped $offset from
		// can hand back the SAME full page forever. count($ids) === PAGE then
		// keeps `if ( count( $ids ) < PAGE ) break;` from firing while $checked
		// never advances, and the while() below would never exit on its own.
		// Two independent guards close it: a hard ceiling on how many pages are
		// EVER fetched — ceil(cap/page) pages of pure progress, plus one more,
		// since the last page needed to reach the cap can straddle an earlier
		// one (ids already seen at its head, new ones only at its tail) and
		// still be required — and a break the moment one full page contributes
		// zero ids not already seen, the tell that nothing is actually advancing.
		$max_pages = (int) ceil( static::ELEMENTOR_CONTEXT_CAP / static::ELEMENTOR_CONTEXT_PAGE ) + 1;
		$pages     = 0;
		while ( $checked < static::ELEMENTOR_CONTEXT_CAP && $pages < $max_pages ) {
			++$pages;
			$ids = $this->context_user_ids( $offset, static::ELEMENTOR_CONTEXT_PAGE );
			if ( empty( $ids ) ) {
				break;
			}
			$new = 0;
			foreach ( $ids as $uid ) {
				$uid = (int) $uid;
				if ( $uid <= 0 || isset( $seen[ $uid ] ) ) {
					continue;
				}
				if ( $checked >= static::ELEMENTOR_CONTEXT_CAP ) {
					$truncated = true;
					break 2;
				}
				$seen[ $uid ] = true;
				++$new;
				++$checked;
				$list = $this->password_list( $uid );
				if ( null === $list ) {
					$unproven[] = $uid;
					continue;
				}
				foreach ( $list as $item ) {
					if ( ! is_array( $item ) || static::is_elementor_named( $item ) ) {
						continue;
					}
					++$count;
					if ( isset( $item['last_used'] ) && is_numeric( $item['last_used'] ) && (int) $item['last_used'] >= $horizon ) {
						++$recent;
					}
				}
			}
			if ( 0 === $new ) {
				// A full page that advanced nothing: the offset is not being
				// honoured. Continuing would fetch the same page forever without
				// ever reaching the cap.
				break;
			}
			if ( count( $ids ) < static::ELEMENTOR_CONTEXT_PAGE ) {
				break;
			}
			$offset += static::ELEMENTOR_CONTEXT_PAGE;
		}
		// The parser refuses users_checked > users_total, and truncated:false
		// with users_checked != users_total. A count that raced the pages is
		// reconciled: more checked than counted → the pages are the truth;
		// fewer checked than counted with the pages exhausted → incomplete.
		if ( $checked > $total ) {
			$total = $checked;
		}
		if ( $total > $checked ) {
			$truncated = true;
		}
		return array(
			'other'    => array(
				'users_checked' => $checked,
				'count'         => $count,
				'recently_used' => $recent,
				'unproven'      => $unproven,
			),
			'coverage' => array(
				'users_total'   => $total,
				'users_checked' => $checked,
				'truncated'     => $truncated,
				'cap'           => static::ELEMENTOR_CONTEXT_CAP,
			),
		);
	}

	/**
	 * The `elementor` block: four scans, each failing on its own.
	 *
	 * @return array
	 */
	protected function elementor_state() {
		// `POST /aura/mcp/tools/execute` is gated by check_update_plugins_permission
		// (class-aura-worker-mcp.php), not manage_options — an authenticated user
		// holding update_plugins (a custom role, an Application Password) but not
		// manage_options can otherwise reach this block and read every user's
		// Elementor-issued password names, last_ip, and consent rows. The
		// Abilities path already gates on manage_options
		// (Aura_Worker_Abilities::make_permission()); this closes the same door
		// on the REST execute path. Aura's own calls run as a stored administrator
		// (class-aura-worker-security.php), so this never affects the gateway.
		// Every subtree the block promises is replaced with the SAME shape a
		// throw in that subtree already produces — a consumer that treats
		// `{ error }` as unknown needs no new case — and NOTHING is read: no
		// env, no ability count, no consent/candidate/context statement.
		if ( ! current_user_can( 'manage_options' ) ) {
			return array(
				'installed'     => false,
				'version'       => null,
				'mcp_module'    => array( 'error' => 'manage_options required' ),
				'consent'       => array( 'error' => 'manage_options required' ),
				'app_passwords' => array(
					'elementor' => array( 'error' => 'manage_options required' ),
					'other'     => array( 'error' => 'manage_options required' ),
				),
				'coverage'      => array( 'error' => 'manage_options required' ),
			);
		}
		$out = array(
			'installed' => false,
			'version'   => null,
		);
		// Two tries, not one: elementor_env() alone decides installed/version
		// (its own `class_present` already carries the "class implies
		// installed" rule — see elementor_module_from()'s identical formula).
		// A throw counting abilities or listing servers is a DISCOVERY
		// failure, not an installation one, and must not erase an environment
		// that was read fine (Codex round-2 P2).
		$env = null;
		try {
			$env = $this->elementor_env();
		} catch ( \Throwable $e ) {
			$out['mcp_module'] = $this->subtree_error( $e );
		}

		if ( null !== $env ) {
			$installed        = ! empty( $env['installed'] ) || ! empty( $env['class_present'] );
			$out['installed'] = $installed;
			$out['version']   = $installed && isset( $env['version'] ) && is_string( $env['version'] ) && '' !== $env['version'] ? $this->clip( $env['version'] ) : null;
			try {
				$out['mcp_module'] = static::elementor_module_from( $env, $this->elementor_ability_names(), $this->servers() );
			} catch ( \Throwable $e ) {
				// installed/version already derived from $env alone, above —
				// left untouched here.
				$out['mcp_module'] = $this->subtree_error( $e );
			}
		}

		try {
			$out += $this->elementor_consent();
		} catch ( \Throwable $e ) {
			$out['consent'] = $this->subtree_error( $e );
		}
		try {
			$passwords = $this->elementor_passwords();
		} catch ( \Throwable $e ) {
			$passwords = array( 'elementor' => $this->subtree_error( $e ) );
		}
		try {
			$ctx                  = $this->elementor_context();
			$passwords['other']   = $ctx['other'];
			$out['app_passwords'] = $passwords;
			$out['coverage']      = $ctx['coverage'];
		} catch ( \Throwable $e ) {
			$passwords['other']   = $this->subtree_error( $e );
			$out['app_passwords'] = $passwords;
			$out['coverage']      = $this->subtree_error( $e );
		}
		return $out;
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
