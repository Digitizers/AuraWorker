<?php
/**
 * Abstract base class for MCP tools.
 *
 * All MCP tools must extend this class and implement the required methods.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Aura_Tool_Base {

	/**
	 * Get the tool name (machine-readable, snake_case).
	 *
	 * @return string
	 */
	abstract public function get_name();

	/**
	 * Get a human-readable description of what this tool does.
	 *
	 * @return string
	 */
	abstract public function get_description();

	/**
	 * Get the parameter schema for this tool.
	 *
	 * Returns an associative array keyed by parameter name.
	 * Each value is an array with keys: type, description, required (bool), default (optional).
	 *
	 * @return array
	 */
	abstract public function get_parameters();

	/**
	 * Get the return value schema description for this tool.
	 *
	 * @return array
	 */
	abstract public function get_returns();

	/**
	 * Execute the tool with the given parameters.
	 *
	 * @param array $params Validated parameters.
	 * @return array Result data.
	 */
	abstract public function execute( $params );

	/**
	 * Get the full metadata array for this tool (used by list_tools).
	 *
	 * @return array
	 */
	public function get_metadata() {
		return array(
			'name'        => $this->get_name(),
			'description' => $this->get_description(),
			'parameters'  => $this->get_parameters(),
			'returns'     => $this->get_returns(),
		);
	}

	/**
	 * Validate that all required parameters are present.
	 *
	 * @param array $params Parameters to validate.
	 * @return array { valid: bool, errors?: string[] }
	 */
	public function validate_params( $params ) {
		$errors = array();
		foreach ( $this->get_parameters() as $name => $def ) {
			if ( ! empty( $def['required'] ) && ! isset( $params[ $name ] ) ) {
				$errors[] = "Missing required parameter: $name";
			}
		}
		return empty( $errors ) ? array( 'valid' => true ) : array( 'valid' => false, 'errors' => $errors );
	}
}
