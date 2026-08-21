<?php
/**
 * MCP tool: create a page/post from Gutenberg block markup.
 *
 * Draft-first: a new page is created as a draft unless the caller explicitly
 * asks to publish. Approval-gated (it creates content), but not destructive —
 * nothing existing is changed.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Tool_Gutenberg_Create_Page extends Aura_Tool_Base {

	/**
	 * Post statuses a caller may request. Anything else falls back to draft.
	 */
	const ALLOWED_STATUS = array( 'draft', 'pending', 'private', 'publish' );

	/**
	 * Post types a caller may create.
	 */
	const ALLOWED_TYPE = array( 'page', 'post' );

	public function get_name() {
		return 'create_page_from_blocks';
	}

	public function get_description() {
		return 'Create a new page/post from Gutenberg block markup. Draft-first (published only if explicitly requested). Approval-gated.';
	}

	public function get_parameters() {
		return array(
			'title'         => array(
				'type'        => 'string',
				'description' => 'The page/post title.',
				'required'    => true,
			),
			'blocks_markup' => array(
				'type'        => 'string',
				'description' => 'Gutenberg block markup for post_content (e.g. "<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->").',
				'required'    => true,
			),
			'status'        => array(
				'type'        => 'string',
				'description' => 'draft (default) | pending | private | publish.',
				'required'    => false,
			),
			'post_type'     => array(
				'type'        => 'string',
				'description' => 'page (default) | post.',
				'required'    => false,
			),
		);
	}

	public function get_returns() {
		return array(
			'post_id' => array( 'type' => 'integer' ),
			'status'  => array( 'type' => 'string' ),
			'type'    => array( 'type' => 'string' ),
		);
	}

	public function get_annotations() {
		return array(
			'read_only'         => false,
			'destructive'       => false,
			'requires_approval' => true,
			'supports_preview'  => false,
		);
	}

	/** @inheritDoc */
	public function touches( $params ) {
		$id = isset( $params['post_id'] ) ? (int) $params['post_id'] : 0;
		if ( $id <= 0 ) {
			// No post yet (a create), or none given: the honest answer is the
			// site — a freeze catches it, a rule on an existing page does not.
			return array( array( 'type' => 'site', 'id' => '*' ) );
		}
		return array(
			array( 'type' => 'post', 'id' => (string) $id ),
			array( 'type' => 'page', 'id' => (string) $id ),
		);
	}

	public function execute( $params ) {
		$title  = isset( $params['title'] ) ? sanitize_text_field( $params['title'] ) : '';
		$markup = isset( $params['blocks_markup'] ) ? (string) $params['blocks_markup'] : '';
		if ( '' === $title ) {
			return array( 'error' => 'A title is required.' );
		}

		$status = isset( $params['status'] ) ? strtolower( (string) $params['status'] ) : 'draft';
		if ( ! in_array( $status, self::ALLOWED_STATUS, true ) ) {
			$status = 'draft'; // draft-first for anything unexpected.
		}

		$type = isset( $params['post_type'] ) ? strtolower( (string) $params['post_type'] ) : 'page';
		if ( ! in_array( $type, self::ALLOWED_TYPE, true ) ) {
			$type = 'page';
		}

		$post_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_content' => $markup,
				'post_status'  => $status,
				'post_type'    => $type,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return array( 'error' => $post_id->get_error_message() );
		}

		return array(
			'post_id' => (int) $post_id,
			'status'  => $status,
			'type'    => $type,
		);
	}
}
