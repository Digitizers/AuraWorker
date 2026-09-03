<?php
/**
 * A post was created and the door log could not be told which one.
 *
 * The insert hook is the FIRST witness of a creation, and the only one that
 * knows an id is this call's rather than merely above its watermark. Writing
 * it to the row as it happens is what lets a request that dies mid-write be
 * finished by anyone else. When that write fails, carrying on would leave the
 * id in request memory alone — and a timeout a moment later would leave the
 * reconciler nothing but the watermark's suspicion, which is deliberately
 * treated as unproven: no envelope, no compensation, a page nobody can undo.
 *
 * So the creation is aborted while the hook still holds the id, and this is
 * the exception that carries it out of Elementor's callback.
 *
 * @package Aura_Worker
 * @since 2.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The row could not witness a created post.
 */
class Aura_Worker_Door_Witness_Exception extends RuntimeException {

	/**
	 * The post the hook saw and the row does not know about.
	 *
	 * @var int
	 */
	private $post_id;

	/**
	 * @param int    $post_id The created post.
	 * @param string $message Message.
	 */
	public function __construct( $post_id, $message = '' ) {
		parent::__construct( (string) $message );
		$this->post_id = (int) $post_id;
	}

	/**
	 * @return int
	 */
	public function post_id() {
		return $this->post_id;
	}
}
