<?php
/**
 * The one exception the Elementor door throws to REFUSE a call it has already
 * admitted — a rule that only became applicable once the write was underway.
 *
 * It exists because the governor's catch-all treats a throw as a governor
 * failure ("it may have run — check the site"), which is the wrong news for a
 * deliberate refusal. This class carries the verdict instead: which rule
 * decided it, and which resources it names, so execute() can settle the entry
 * `refused` and answer the caller `aura_rule_blocked` (403) like any other
 * blocked write.
 *
 * @package Aura_Worker
 * @since 2.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A rule blocked this call mid-write.
 */
class Aura_Worker_Door_Blocked_Exception extends RuntimeException {

	/**
	 * The matched rule, as stored.
	 *
	 * @var array
	 */
	private $rule;

	/**
	 * The ids the rule names in this call.
	 *
	 * @var int[]
	 */
	private $ids;

	/**
	 * Why the call is being refused — the `reason` the entry settles with.
	 *
	 * `collateral_blocked` (a block rule named one of the pages) is the
	 * default because it was the only case until Ruling P32 added
	 * `collateral_unacknowledged`: a WARN rule naming a page the approval
	 * never covered. Both refuse; they differ only in what the operator has
	 * to do about it, so they are one exception with two reasons rather than
	 * two classes the catch would have to know apart.
	 *
	 * @var string
	 */
	private $reason;

	/**
	 * The verdict the entry records: `block` or `warn`.
	 *
	 * @var string
	 */
	private $verdict;

	/**
	 * @param array  $rule    The rule that refused.
	 * @param int[]  $ids     The refused resource ids.
	 * @param string $message Message.
	 * @param string $reason  Entry reason: collateral_blocked|collateral_unacknowledged.
	 * @param string $verdict Entry verdict: block|warn.
	 */
	public function __construct( array $rule, array $ids, $message = '', $reason = 'collateral_blocked', $verdict = 'block' ) {
		parent::__construct( (string) $message );
		$this->rule    = $rule;
		$this->ids     = array_values( array_map( 'intval', $ids ) );
		$this->reason  = (string) $reason;
		$this->verdict = (string) $verdict;
	}

	/**
	 * @return string collateral_blocked|collateral_unacknowledged.
	 */
	public function reason() {
		return $this->reason;
	}

	/**
	 * @return string block|warn.
	 */
	public function verdict() {
		return $this->verdict;
	}

	/**
	 * @return array The rule, as stored.
	 */
	public function rule() {
		return $this->rule;
	}

	/**
	 * @return string
	 */
	public function rule_key() {
		return (string) ( isset( $this->rule['key'] ) ? $this->rule['key'] : '' );
	}

	/**
	 * @return int[]
	 */
	public function ids() {
		return $this->ids;
	}
}
