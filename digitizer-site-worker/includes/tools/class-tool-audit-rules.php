<?php
/**
 * Read-only audit of operator-rule enforcement on this site.
 *
 * Facts only: is a ruleset present, how old is it, what has enforcement done
 * in the last day, which rules have expired but are still listed, and which
 * enforcement points this build has. The fleet rollup turns these into
 * findings — a stale ruleset means the operator's rule is not protecting
 * this site, which is the thing worth seeing.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Tool_Audit_Rules extends Aura_Tool_Base {

	public function get_name() {
		return 'audit_rules';
	}

	public function get_description() {
		return 'Read-only audit of operator rules: whether a signed ruleset is present and how old it is, 24h block/warn counts, rules that have expired but are still listed, and the enforcement points in this build. Makes no changes.';
	}

	public function get_parameters() {
		return array();
	}

	public function get_returns() {
		return array(
			'ruleset'     => 'object|null — { seq, issued_at, received_at, rule_count }; null when no ruleset has ever been accepted (no policy — not "no rules")',
			'keyed'       => 'bool — whether this site holds a USABLE gateway public key (decodes to a valid Ed25519 key); false means it cannot verify ANY ruleset and must be reconnected',
			'enforcement' => 'object — { blocked_24h, warned_24h, expired_active: string[], points: string[] } — points lists the enforcement seams in this build',
			'coverage'    => 'object — { total_seen, returned, truncated, cap } bounded-coverage contract (never truncates; counts rules)',
		);
	}

	public function get_annotations() {
		return array(
			'read_only'         => true,
			'destructive'       => false,
			'requires_approval' => false,
			'supports_preview'  => false,
		);
	}

	public function execute( $params ) {
		$rec   = Aura_Worker_Rules::current();
		$rules = Aura_Worker_Rules::rules();

		return array(
			'ruleset'     => null === $rec ? null : array(
				'seq'         => (int) $rec['seq'],
				'issued_at'   => isset( $rec['issued_at'] ) ? (string) $rec['issued_at'] : '',
				'received_at' => isset( $rec['received_at'] ) ? (int) $rec['received_at'] : 0,
				'rule_count'  => count( $rules ),
			),
			'keyed'       => Aura_Worker_Grant::has_usable_key(),
			'enforcement' => array(
				'blocked_24h'    => Aura_Worker_Rules::count_24h( Aura_Worker_Rules::BLOCKED_COUNTER ),
				'warned_24h'     => Aura_Worker_Rules::count_24h( Aura_Worker_Rules::WARNED_COUNTER ),
				'expired_active' => Aura_Worker_Rules::expired_keys(),
				'points'         => array( 'execute_tool', 'rest_updates', 'core_rest_content' ),
			),
			'coverage'    => array(
				'total_seen' => count( $rules ),
				'returned'   => count( $rules ),
				'truncated'  => false,
				'cap'        => '',
			),
		);
	}
}
