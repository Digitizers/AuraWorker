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
	 * Risk/behaviour annotations for this tool.
	 *
	 * Advisory metadata that lets the Aura Fleet Gateway classify a tool from
	 * the plugin's own declaration instead of guessing from its name. Power
	 * tools (execute-php, wp-cli, filesystem, DB writes) MUST override this to
	 * declare themselves non-read-only, approval-required, and (where possible)
	 * preview-capable.
	 *
	 * Keys:
	 *  - read_only         (bool) Makes no state change; safe to run inline.
	 *  - destructive       (bool) May irreversibly change/remove data.
	 *  - requires_approval (bool) Must queue for human approval before executing.
	 *  - supports_preview  (bool) Implements dry_run() for a pre-execution preview.
	 *
	 * Defaults are neutral (not read-only, no approval) so existing tools keep
	 * their current gateway behaviour; the gateway's verb classifier stays
	 * authoritative until a tool opts in by overriding this.
	 *
	 * @return array
	 */
	public function get_annotations() {
		return array(
			'read_only'         => false,
			'destructive'       => false,
			'requires_approval' => false,
			'supports_preview'  => false,
		);
	}

	/**
	 * Produce a preview of what execute() would do, without changing state.
	 *
	 * Tools that set supports_preview=true in get_annotations() override this to
	 * return a structured preview (e.g. a static-scan verdict, the wp-cli command
	 * line, a unified file diff, or a SQL statement + affected-row estimate).
	 *
	 * @param array $params Validated parameters.
	 * @return array|null Preview payload, or null when previews are unsupported.
	 */
	public function dry_run( $params ) {
		return null;
	}

	/**
	 * Which resources does this call touch?
	 *
	 * Operator rules (Aura_Worker_Rules) match against this. The default is the
	 * `unknown` sentinel, which matches EVERY rule: a tool that does not declare
	 * itself is caught by a page rule, a plugin rule and a freeze alike. Silence
	 * is the most restrictive answer — the opposite of the default J5 found on
	 * the abilities path.
	 *
	 * A tool whose honest answer IS the whole site (a cache flush) declares
	 * `site:*` explicitly instead: that is caught by a freeze and not by a page
	 * rule, which is the distinction the sentinel exists to keep.
	 *
	 * Vocabulary: site | page | post | plugin. A content tool declares the same
	 * ID under both `post` and `page`, because the operator writing the rule
	 * does not know which one "checkout" is.
	 *
	 * @param array $params Validated parameters.
	 * @return array<array{type:string,id:string}>
	 */
	public function touches( $params ) {
		return array( array( 'type' => Aura_Worker_Rules::UNKNOWN, 'id' => '*' ) );
	}

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
			'annotations' => $this->get_annotations(),
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
