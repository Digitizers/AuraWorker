<?php
/**
 * WordPress Abilities API bridge for SiteAgent.
 *
 * Dual-registers SiteAgent's MCP tools as WordPress *abilities* when the core
 * Abilities API is present, so standard MCP clients (Claude Desktop et al. via
 * the official WordPress MCP adapter) can discover them — the same standards
 * stack Respira, Novamira, and EMCP ride. The plugin's own `aura/mcp` REST
 * namespace stays intact for the Aura Fleet Gateway; this is purely additive.
 *
 * Auth here is WordPress-native: the Abilities/MCP-adapter transport
 * authenticates the request (e.g. Application Password) and the ability's
 * permission_callback gates on capability. This path does NOT use the
 * X-Aura-Token layer — that belongs to the Aura gateway path.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Worker_Abilities {

	/**
	 * Ability namespace prefix.
	 */
	const NAMESPACE_PREFIX = 'aura-worker';

	/**
	 * Tool registry.
	 *
	 * @var Aura_Worker_Tools
	 */
	private $tools;

	/**
	 * Constructor.
	 */
	public function __construct() {
		require_once plugin_dir_path( __FILE__ ) . 'class-aura-worker-tools.php';
		$this->tools = new Aura_Worker_Tools();
	}

	/**
	 * Register the ability category.
	 *
	 * MUST run on `wp_abilities_api_categories_init`, which fires *before*
	 * `wp_abilities_api_init`. The Abilities API rejects any ability whose
	 * category isn't already registered (WP_Abilities_Registry::register()
	 * returns null), so registering the category inside register() — on the
	 * later hook — is too late: every tool would be silently dropped and the
	 * MCP adapter would discover zero SiteAgent tools. A `description` is also
	 * required by wp_register_ability_category().
	 *
	 * The Abilities API only exists on WP 6.9+ (or via the feature plugin), while
	 * SiteAgent still supports 6.2 — hence the function_exists() guard. On older
	 * cores this is a no-op and the plugin runs on its own REST namespace instead.
	 * Plugin Check flags the call as incompatible with "Requires at least: 6.2";
	 * it does not evaluate the guard, so that report is a false positive.
	 */
	public function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			'site-management',
			array(
				'label'       => __( 'Site Management', 'digitizer-site-worker' ),
				'description' => __( 'Manage this WordPress site — content, design, security, and maintenance.', 'digitizer-site-worker' ),
			)
		);
	}

	/**
	 * Register every SiteAgent tool as a WordPress ability.
	 * Hooked on `wp_abilities_api_init`; a no-op when the API is absent (WP < 6.9
	 * without the feature plugin) — see register_category() on the version guard.
	 * The category is registered separately on `wp_abilities_api_categories_init`.
	 */
	public function register() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		foreach ( $this->tools->list_tools() as $meta ) {
			$name = $meta['name'];
			$ann  = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();

			wp_register_ability(
				self::NAMESPACE_PREFIX . '/' . str_replace( '_', '-', $name ),
				array(
					'label'               => $this->labelize( $name ),
					'description'         => isset( $meta['description'] ) ? $meta['description'] : $name,
					'category'            => 'site-management',
					'input_schema'        => $this->build_input_schema( isset( $meta['parameters'] ) ? $meta['parameters'] : array() ),
					'execute_callback'    => $this->make_executor( $name ),
					'permission_callback' => $this->make_permission( $name, $ann ),
					'meta'                => array(
						'show_in_rest' => true,
						'mcp'          => $this->mcp_meta( $ann ),
						'annotations'  => array(
							'readonly'          => ! empty( $ann['read_only'] ),
							'destructive'       => ! empty( $ann['destructive'] ),
							'requires_approval' => ! empty( $ann['requires_approval'] ),
						),
					),
				)
			);
		}
	}

	/**
	 * Build a JSON-Schema input object from a tool's parameter metadata.
	 *
	 * @param array $parameters Tool parameters.
	 * @return array
	 */
	private function build_input_schema( $parameters ) {
		$properties = array();
		$required   = array();

		foreach ( $parameters as $pname => $def ) {
			$properties[ $pname ] = array(
				'type'        => isset( $def['type'] ) ? $def['type'] : 'string',
				'description' => isset( $def['description'] ) ? $def['description'] : '',
			);
			if ( ! empty( $def['required'] ) ) {
				$required[] = $pname;
			}
		}

		$schema = array(
			'type'                 => 'object',
			'properties'           => empty( $properties ) ? (object) array() : $properties,
			'required'             => $required,
			'additionalProperties' => true,
		);

		// When nothing is required, default a missing input to {} so a
		// no-argument ability (check_health, scan_security) isn't rejected by
		// validate_input just because the caller omitted `input`.
		if ( empty( $required ) ) {
			$schema['default'] = (object) array();
		}

		return $schema;
	}

	/**
	 * Executor closure that routes an ability call back through the tool registry.
	 *
	 * @param string $name Tool name.
	 * @return callable
	 */
	private function make_executor( $name ) {
		$tools = $this->tools;
		return static function ( $input ) use ( $tools, $name ) {
			return $tools->execute_tool( $name, is_array( $input ) ? $input : array() );
		};
	}

	/**
	 * Discovery metadata for one ability.
	 *
	 * `wp_register_ability` publishes to the SITE, not to a server, so this is
	 * the only place we get to say who may serve a tool. A co-installed MCP
	 * server admits a third-party ability when `mcp.type` is absent or `tool`
	 * — which is every ability by default, including the ones that update
	 * plugins. Mutating tools therefore declare a type nobody serves.
	 *
	 * `private` rather than `resource`: `resource` is a REAL MCP type, and a
	 * server collecting resources would publish the tool on that surface
	 * instead of withholding it — moving a write somewhere else is not hiding
	 * it. Reads keep `public => true`, since being discoverable is the entire
	 * point of the dual registration.
	 *
	 * This is a first layer, not the line of defence: the value is a
	 * convention, and the bundled MCP adapter coerces unrecognised types back
	 * to `tool` (WordPress/mcp-adapter#297). The permission-stage transport
	 * check below is what holds when it does.
	 *
	 * @param array $annotations Tool annotations.
	 * @return array
	 */
	private function mcp_meta( $annotations ) {
		if ( Aura_Worker_Call_Context::tool_needs_grant( $annotations ) ) {
			return array(
				'type'   => 'private',
				'public' => false,
			);
		}
		return array( 'public' => true );
	}

	/**
	 * Permission gate for an ability: capability first, then transport.
	 *
	 * The capability check is unchanged — SiteAgent tools operate at admin
	 * level and even reads expose admin data. What is new is the second half:
	 * a mutating tool reached over a transport SiteAgent does not serve must
	 * carry a valid approval grant, because the enforcement the gateway path
	 * relies on lives in REST handlers this path never touches.
	 *
	 * At the permission stage rather than inside the executor deliberately: a
	 * refusal here never reaches `execute_tool()`, so nothing runs, nothing is
	 * snapshotted, and the caller is told why.
	 *
	 * @param string $name        Tool name.
	 * @param array  $annotations Tool annotations.
	 * @return callable
	 */
	private function make_permission( $name, $annotations ) {
		return static function ( $input = array() ) use ( $name, $annotations ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return false;
			}
			return Aura_Worker_Call_Context::guard( $name, $annotations, is_array( $input ) ? $input : array() );
		};
	}

	/**
	 * Human label from a snake_case tool name.
	 *
	 * @param string $name Tool name.
	 * @return string
	 */
	private function labelize( $name ) {
		return ucwords( str_replace( '_', ' ', $name ) );
	}
}
