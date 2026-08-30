<?php
/**
 * MCP (AI Agent Tools) REST router for SiteAgent.
 *
 * Exposes three endpoints under the aura/mcp namespace:
 *   POST /aura/mcp/tools/list    — list available tools
 *   POST /aura/mcp/tools/execute — execute a named tool
 *   GET  /aura/mcp/context       — shortcut to get_site_context tool
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Worker_MCP {

	/**
	 * REST API namespace for MCP endpoints.
	 */
	const NAMESPACE = 'aura/mcp';

	/**
	 * Tool registry instance.
	 *
	 * @var Aura_Worker_Tools
	 */
	private $tools;

	/**
	 * Security handler for permission callbacks.
	 *
	 * @var Aura_Worker_Security
	 */
	private $security;

	/**
	 * Constructor.
	 *
	 * @param Aura_Worker_Security $security Security handler instance.
	 */
	public function __construct( Aura_Worker_Security $security ) {
		$this->security = $security;

		require_once plugin_dir_path( __FILE__ ) . 'class-aura-worker-tools.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-aura-worker-grant.php';
		$this->tools = new Aura_Worker_Tools();
	}

	/**
	 * Register MCP REST API routes.
	 * Called via rest_api_init.
	 */
	public function register_routes() {
		// POST /aura/mcp/tools/list — list all available tools.
		register_rest_route( self::NAMESPACE, '/tools/list', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'list_tools' ),
			'permission_callback' => array( $this->security, 'check_read_permission' ),
		) );

		// POST /aura/mcp/tools/execute — execute a named tool with parameters.
		register_rest_route( self::NAMESPACE, '/tools/execute', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'execute_tool' ),
			'permission_callback' => array( $this->security, 'check_update_plugins_permission' ),
			'args'                => array(
				'tool' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => __( 'Tool name to execute (e.g. "get_site_context").', 'digitizer-site-worker' ),
				),
				'params' => array(
					'required'    => false,
					'type'        => 'object',
					'description' => __( 'Parameters to pass to the tool (key/value pairs).', 'digitizer-site-worker' ),
					'default'     => array(),
				),
			),
		) );

		// POST /aura/mcp/tools/preview — dry-run a tool (no state change).
		register_rest_route( self::NAMESPACE, '/tools/preview', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'preview_tool' ),
			'permission_callback' => array( $this->security, 'check_read_permission' ),
			'args'                => array(
				'tool' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => __( 'Tool name to preview.', 'digitizer-site-worker' ),
				),
				'params' => array(
					'required'    => false,
					'type'        => 'object',
					'description' => __( 'Parameters to preview (key/value pairs).', 'digitizer-site-worker' ),
					'default'     => array(),
				),
			),
		) );

		// GET /aura/mcp/context — shortcut that returns site context.
		register_rest_route( self::NAMESPACE, '/context', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_context' ),
			'permission_callback' => array( $this->security, 'check_read_permission' ),
		) );
	}

	/**
	 * POST /aura/mcp/tools/list
	 *
	 * Returns metadata for all registered MCP tools.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function list_tools( $request ) {
		$tools = $this->tools->list_tools();
		return rest_ensure_response( array(
			'tools' => $tools,
			'count' => count( $tools ),
		) );
	}

	/**
	 * POST /aura/mcp/tools/execute
	 *
	 * Validates and executes a named tool with the supplied parameters.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function execute_tool( $request ) {
		$tool_name = $request->get_param( 'tool' );
		$params    = $request->get_param( 'params' );

		if ( ! is_array( $params ) ) {
			$params = array();
		}

		// Approval-grant enforcement (gateway / X-Aura-Token path only). When the
		// gateway has provisioned its public key, EVERY mutating (non read-only)
		// tool must carry a valid single-use signed grant bound to THIS exact call
		// — so a stolen token alone can only ever run READ tools, never a write or
		// a power op. The WordPress Abilities / Application-Password path has a
		// different, capability-based trust model and does not reach this handler.
		$tool = $this->tools->get_tool( $tool_name );
		if ( null !== $tool ) {
			$annotations = $tool->get_annotations();
			// A grant is required for any mutating (non read-only) tool OR any tool
			// that declares requires_approval. The second clause matters for a
			// dangerous READ that still needs approval — e.g. `db_query`, which is
			// read_only=true but requires_approval=true; without it a stolen token
			// could dump the database. Read tools that don't require approval are
			// exempt (a leaked token may still read).
			$needs_grant = empty( $annotations['read_only'] ) || ! empty( $annotations['requires_approval'] );
			if ( $needs_grant && Aura_Worker_Grant::is_enforced() ) {
				// The grant is bound to `params`, which is exactly what this handler
				// executes. The gateway also sends a `parameters` alias (identical to
				// `params`) for older-plugin compatibility; only `params` is ever run
				// here. Defense in depth: refuse any request whose `parameters` alias
				// disagrees with `params`, so nothing can be fed executable arguments
				// the grant never signed.
				$alias = $request->get_param( 'parameters' );
				if ( null !== $alias
					&& Aura_Worker_Grant::canonical_json( is_array( $alias ) ? $alias : array() )
						!== Aura_Worker_Grant::canonical_json( $params ) ) {
					return new WP_REST_Response(
						array(
							'success' => false,
							'error'   => 'Approval grant required or invalid: params/parameters mismatch.',
						),
						403
					);
				}
				$grant  = (string) $request->get_header( 'X-Aura-Approval-Grant' );
				$reason = Aura_Worker_Grant::verify( $grant, $tool_name, $params );
				// A WP_Error is not a verdict about the GRANT — it is a refusal
				// that has nothing to do with one (an unbound site, #434 Task 5).
				// It carries its own code and status, and concatenating it into
				// the message below would be a fatal, so it is answered first.
				if ( is_wp_error( $reason ) ) {
					$data = $reason->get_error_data();
					return new WP_REST_Response(
						array(
							'success' => false,
							'code'    => $reason->get_error_code(),
							'error'   => $reason->get_error_message(),
						),
						( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 403
					);
				}
				if ( true !== $reason ) {
					return new WP_REST_Response(
						array(
							'success' => false,
							'error'   => 'Approval grant required or invalid: ' . $reason,
						),
						403
					);
				}
			}
		}

		$result = $this->tools->execute_tool( $tool_name, $params );
		// A tool result may carry its own HTTP status (a rule block is a 403,
		// not a 400 — the call was well-formed and refused on policy).
		$status = $result['success'] ? 200 : ( isset( $result['status'] ) ? (int) $result['status'] : 400 );

		return new WP_REST_Response( $result, $status );
	}

	/**
	 * POST /aura/mcp/tools/preview
	 *
	 * Returns a non-executing preview of a tool for the given parameters.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function preview_tool( $request ) {
		$tool_name = $request->get_param( 'tool' );
		$params    = $request->get_param( 'params' );

		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$result = $this->tools->preview_tool( $tool_name, $params );
		$status = $result['success'] ? 200 : 400;

		return new WP_REST_Response( $result, $status );
	}

	/**
	 * GET /aura/mcp/context
	 *
	 * Delegates to the get_site_context tool with default parameters.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_context( $request ) {
		$include_plugins     = $request->get_param( 'include_plugins' );
		$include_performance = $request->get_param( 'include_performance' );

		$params = array();
		if ( null !== $include_plugins ) {
			$params['include_plugins'] = filter_var( $include_plugins, FILTER_VALIDATE_BOOLEAN );
		}
		if ( null !== $include_performance ) {
			$params['include_performance'] = filter_var( $include_performance, FILTER_VALIDATE_BOOLEAN );
		}

		$result = $this->tools->execute_tool( 'get_site_context', $params );

		if ( ! $result['success'] ) {
			return new WP_REST_Response( $result, 500 );
		}

		return rest_ensure_response( $result['result'] );
	}
}
