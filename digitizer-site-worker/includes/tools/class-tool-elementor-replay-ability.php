<?php
/**
 * MCP tool: replay a held Elementor-door write after Aura's approval.
 *
 * The door holds every write it cannot allow by rule and refuses the caller
 * with a ref (spec §3.6). This tool is how the approval comes back: Aura
 * calls it with that ref, the site re-judges the held call against its
 * CURRENT ruleset, claims the hold by moving it, and runs the ability as the
 * user who asked for it in the first place — never as Aura.
 *
 * Nothing here decides anything. The tool is the transport;
 * Aura_Worker_Elementor_Door::replay() owns the order (re-judge, acknowledge,
 * permission, claim, run, answer from the terminal log entry).
 *
 * @package Aura_Worker
 * @since 2.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Tool_Elementor_Replay_Ability extends Aura_Tool_Base {

	/**
	 * Tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'elementor_replay_ability';
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function get_description() {
		return 'Run a write that Elementor\'s MCP door held for approval (spec §3.7). Re-judges against the current ruleset first; claims the hold by moving it; runs as the user who asked.';
	}

	/**
	 * Parameter schema.
	 *
	 * @return array
	 */
	public function get_parameters() {
		return array(
			'ref' => array(
				'type'        => 'string',
				'description' => 'The hold reference (door_…).',
				'required'    => true,
			),
			'ack' => array(
				'type'        => 'object',
				'description' => 'The approver\'s acknowledgement of a warn rule: { key, ruleHash }.',
				'required'    => false,
			),
		);
	}

	/**
	 * Return shape.
	 *
	 * @return array
	 */
	public function get_returns() {
		return array(
			'ok'               => 'bool',
			'result'           => 'mixed',
			'snapshot_id'      => 'string|null',
			'created_post_ids' => 'int[]',
			'reason'           => 'not_held|refused_by_current_rule|refused_by_permission|refused_by_missing_ability|refused|warn_changed|retry_later|interrupted|failed',
			'code'             => 'string',
			'claim_retained'   => 'bool',
			'rule'             => 'object',
		);
	}

	/**
	 * A write, and one that always needs a human behind it — this IS the
	 * human's answer arriving.
	 *
	 * @return array
	 */
	public function get_annotations() {
		return array(
			'read_only'         => false,
			'destructive'       => true,
			'requires_approval' => true,
			'supports_preview'  => false,
		);
	}

	/**
	 * The whole site: what the held call itself touches was judged when it
	 * was held, and is re-judged inside replay() — but a site freeze must
	 * still catch this tool.
	 *
	 * @param array $params Params.
	 * @return array
	 */
	public function touches( $params ) {
		return array(
			array(
				'type' => 'site',
				'id'   => '*',
			),
		);
	}

	/**
	 * @param array $params Params.
	 * @return array
	 */
	public function execute( $params ) {
		$ack = null;
		if ( isset( $params['ack'] ) && is_array( $params['ack'] ) ) {
			$ack = array(
				'key'      => (string) ( isset( $params['ack']['key'] ) ? $params['ack']['key'] : '' ),
				'ruleHash' => (string) ( isset( $params['ack']['ruleHash'] ) ? $params['ack']['ruleHash'] : '' ),
			);
		}
		return Aura_Worker_Elementor_Door::replay( (string) ( isset( $params['ref'] ) ? $params['ref'] : '' ), $ack );
	}
}
