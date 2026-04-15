<?php
/**
 * MCP (AI Agent Tools) REST router for Aura Worker.
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

		$result = $this->tools->execute_tool( $tool_name, $params );
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
