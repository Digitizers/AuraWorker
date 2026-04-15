<?php
/**
 * MCP Tool Registry for Aura Worker.
 *
 * Loads all tool classes from includes/tools/ and provides list, get, and execute methods.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Worker_Tools {

	/**
	 * Registered tool instances, keyed by tool name.
	 *
	 * @var Aura_Tool_Base[]
	 */
	private $tools = array();

	/**
	 * Constructor — loads the base class and all tool files, then registers them.
	 */
	public function __construct() {
		$tools_dir = plugin_dir_path( __FILE__ ) . 'tools/';

		// Load the abstract base class first.
		require_once $tools_dir . 'class-tool-base.php';

		// Auto-load all tool files matching class-tool-*.php.
		$tool_files = glob( $tools_dir . 'class-tool-*.php' );
		if ( ! empty( $tool_files ) ) {
			foreach ( $tool_files as $file ) {
				// Skip the base class itself.
				if ( basename( $file ) === 'class-tool-base.php' ) {
					continue;
				}
				require_once $file;
			}
		}

		// Instantiate each loaded tool class.
		foreach ( get_declared_classes() as $class ) {
			if ( $class !== 'Aura_Tool_Base' && is_subclass_of( $class, 'Aura_Tool_Base' ) ) {
				$tool = new $class();
				$this->tools[ $tool->get_name() ] = $tool;
			}
		}
	}

	/**
	 * Get metadata for all registered tools.
	 *
	 * @return array[]
	 */
	public function list_tools() {
		$list = array();
		foreach ( $this->tools as $tool ) {
			$list[] = $tool->get_metadata();
		}
		return $list;
	}

	/**
	 * Get a single tool instance by name.
	 *
	 * @param string $name Tool name.
	 * @return Aura_Tool_Base|null
	 */
	public function get_tool( $name ) {
		return isset( $this->tools[ $name ] ) ? $this->tools[ $name ] : null;
	}

	/**
	 * Validate and execute a tool by name.
	 *
	 * @param string $name   Tool name.
	 * @param array  $params Parameters to pass to the tool.
	 * @return array { success: bool, result?: mixed, error?: string, errors?: string[] }
	 */
	public function execute_tool( $name, $params ) {
		$tool = $this->get_tool( $name );

		if ( null === $tool ) {
			return array(
				'success' => false,
				'error'   => "Unknown tool: $name",
			);
		}

		$validation = $tool->validate_params( $params );
		if ( ! $validation['valid'] ) {
			return array(
				'success' => false,
				'error'   => 'Parameter validation failed.',
				'errors'  => $validation['errors'],
			);
		}

		try {
			$result = $tool->execute( $params );
			return array(
				'success' => true,
				'result'  => $result,
			);
		} catch ( Exception $e ) {
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}
	}
}
