<?php
/**
 * MCP Tool Registry for SiteAgent.
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

		$this->register_external_tools();
	}

	/**
	 * Let companion plugins contribute additional MCP tools.
	 *
	 * A companion (e.g. the SiteAgent Power Pack, which ships the approval-gated
	 * execute-php / wp-cli / filesystem / DB tools that must NOT live in the
	 * wordpress.org build) hooks this filter to register its own tool classes:
	 *
	 *     add_filter( 'aura_worker_register_tools', function ( $tools ) {
	 *         $tools[] = My_Power_Tool::class;      // class name, or
	 *         $tools[] = new My_Power_Tool();        // an instance
	 *         return $tools;
	 *     } );
	 *
	 * Every contributed entry must resolve to an Aura_Tool_Base subclass; anything
	 * else is ignored. This loads only locally-installed PHP — nothing remote.
	 */
	private function register_external_tools() {
		$external = apply_filters( 'aura_worker_register_tools', array() );
		if ( empty( $external ) || ! is_array( $external ) ) {
			return;
		}

		foreach ( $external as $entry ) {
			$tool = null;
			if ( $entry instanceof Aura_Tool_Base ) {
				$tool = $entry;
			} elseif ( is_string( $entry ) && class_exists( $entry ) && is_subclass_of( $entry, 'Aura_Tool_Base' ) ) {
				$tool = new $entry();
			}

			if ( $tool ) {
				// First registration wins — a companion cannot silently shadow a core tool.
				$name = $tool->get_name();
				if ( ! isset( $this->tools[ $name ] ) ) {
					$this->tools[ $name ] = $tool;
				}
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

		$annotations = $tool->get_annotations();

		// Operator rules, decided AFTER parameter validation (a malformed call
		// fails on its parameters, not on a rule) and BEFORE anything runs or
		// snapshots — and only for calls that could change something. A plain
		// read inherits the unknown sentinel too, and enforcing it would let a
		// freeze refuse audit_rules itself. Same predicate as the grant gate:
		// mutating, or an approval-bound read such as db_query.
		//
		// This is the one place every tool path passes through — the legacy REST
		// update handlers in class-aura-worker-api.php and core's own /wp/v2
		// content routes do not, and enforce separately; audit_rules lists all
		// three points so a fourth is visible when it is missing.
		$verdict = array( 'effect' => null );
		if ( Aura_Worker_Call_Context::tool_needs_grant( $annotations ) ) {
			$verdict = Aura_Worker_Rules::enforce( $tool->touches( $params ), $name );
			if ( 'block' === $verdict['effect'] ) {
				return Aura_Worker_Rules::blocked_result( $name, $verdict['rule'] );
			}
		}

		if ( ! empty( $annotations['requires_approval'] ) ) {
			/**
			 * Fires immediately before an approval-required (power) tool executes.
			 *
			 * The plugin cannot itself distinguish a gateway-approved call from a
			 * raw token call — both carry the same site token — so approval is
			 * enforced by the Aura gateway. This hook records EVERY execution of an
			 * approval-required tool for forensics, so an inline/unapproved call is
			 * at least auditable. (A signed one-time approval grant that the plugin
			 * verifies is the planned hard enforcement; see the Power Pack readme.)
			 *
			 * @param string $tool   Tool name.
			 * @param array  $params Parameters the tool was called with.
			 */
			do_action( 'aura_worker_power_execute', $name, $params );
		}

		// Decided before the call, attached after it, whichever way it went.
		// A failure does not retract the warning, and this path has no second
		// channel to recover it: execute_tool() answers its own caller
		// directly, and SiteAgent's own routes are exempt from the core REST
		// seam, so a warning dropped in the catch is one nobody ever hears.
		$warnings = 'warn' === $verdict['effect']
			? array( Aura_Worker_Rules::warning_entry( $verdict['rule'] ) )
			: array();

		try {
			$out = array(
				'success' => true,
				'result'  => $tool->execute( $params ),
			);
		} catch ( Throwable $e ) {
			// Throwable, not Exception. A TypeError or any other PHP Error
			// implements Throwable WITHOUT extending Exception, so the narrower
			// catch let it past — and past this point there is no failure
			// result, no warning, and no response: one tool's fatal takes down
			// the whole MCP request. Nothing is silenced by widening it; the
			// message still goes back to the caller, and the tool boundary is
			// exactly where a per-tool failure should stop being everyone's.
			$out = array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}
		if ( ! empty( $warnings ) ) {
			$out['warnings'] = $warnings;
		}
		return $out;
	}

	/**
	 * Produce a preview of what a tool would do, without executing it.
	 *
	 * Backs the aura/mcp/tools/preview endpoint: the Aura gateway calls this
	 * before queuing a power action so a human can see the effect (static-scan
	 * verdict, planned command, file diff, SQL) at approval time. Tools that do
	 * not declare supports_preview return `supported: false` with a null preview.
	 *
	 * @param string $name   Tool name.
	 * @param array  $params Parameters to preview.
	 * @return array { success: bool, supported?: bool, preview?: mixed, error?: string, errors?: string[] }
	 */
	public function preview_tool( $name, $params ) {
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

		// What this call touches, and which rule would decide it — without
		// enforcing. Previews are exempt from rules (an agent may SEE what would
		// happen), and the gateway reads these two fields to warn before approval.
		// A plain read touches nothing a rule governs, so it declares nothing.
		$annotations = $tool->get_annotations();
		$touches     = Aura_Worker_Call_Context::tool_needs_grant( $annotations ) ? $tool->touches( $params ) : array();
		$rule        = empty( $touches ) ? null : Aura_Worker_Rules::match( $touches, Aura_Worker_Rules::rules() );
		$rule_match  = null === $rule ? null : array(
			'key'    => isset( $rule['key'] ) ? (string) $rule['key'] : 'rule/?',
			'effect' => (string) $rule['effect'],
			'reason' => isset( $rule['reason'] ) ? (string) $rule['reason'] : '',
		);

		if ( empty( $annotations['supports_preview'] ) ) {
			return array(
				'success'    => true,
				'supported'  => false,
				'preview'    => null,
				'touches'    => $touches,
				'rule_match' => $rule_match,
			);
		}

		try {
			return array(
				'success'    => true,
				'supported'  => true,
				'preview'    => $tool->dry_run( $params ),
				'touches'    => $touches,
				'rule_match' => $rule_match,
			);
		} catch ( Exception $e ) {
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}
	}
}
