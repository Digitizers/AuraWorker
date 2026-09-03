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
	 * @param array $rule    The rule that blocked.
	 * @param int[] $ids     The blocked resource ids.
	 * @param string $message Message.
	 */
	public function __construct( array $rule, array $ids, $message = '' ) {
		parent::__construct( (string) $message );
		$this->rule = $rule;
		$this->ids  = array_values( array_map( 'intval', $ids ) );
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
