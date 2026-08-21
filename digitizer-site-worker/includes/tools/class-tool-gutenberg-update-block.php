<?php
/**
 * MCP tool: update a single Gutenberg block on a post/page.
 *
 * Approval-gated + snapshot-first (the prior post_content is captured so the
 * edit is reversible). Edits a leaf block by index — merges attributes and/or
 * replaces inner HTML, optionally changes the block type. Nested innerBlocks are
 * left intact.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Tool_Gutenberg_Update_Block extends Aura_Tool_Base {

	public function get_name() {
		return 'update_page_block';
	}

	public function get_description() {
		return 'Update one Gutenberg block on a post/page by index (merge attributes, replace inner HTML, optionally change block name). Snapshot-first and approval-gated.';
	}

	public function get_parameters() {
		return array(
			'post_id'     => array(
				'type'        => 'integer',
				'description' => 'The post/page ID.',
				'required'    => true,
			),
			'block_index' => array(
				'type'        => 'integer',
				'description' => 'Index of the block to update (from list_page_blocks).',
				'required'    => true,
			),
			'attrs'       => array(
				'type'        => 'object',
				'description' => 'Attributes to merge into the block (optional).',
				'required'    => false,
			),
			'inner_html'  => array(
				'type'        => 'string',
				'description' => 'Replacement inner HTML for the block (optional).',
				'required'    => false,
			),
			'block_name'  => array(
				'type'        => 'string',
				'description' => 'Change the block type, e.g. "core/paragraph" (optional).',
				'required'    => false,
			),
		);
	}

	public function get_returns() {
		return array(
			'post_id'     => array( 'type' => 'integer' ),
			'block_index' => array( 'type' => 'integer' ),
			'updated'     => array( 'type' => 'boolean' ),
			'snapshot_id' => array( 'type' => 'string' ),
		);
	}

	public function get_annotations() {
		return array(
			'read_only'         => false,
			'destructive'       => true,
			'requires_approval' => true,
			'supports_preview'  => true,
		);
	}

	/**
	 * Load + locate the target block. Returns { ok, post?, blocks?, error? }.
	 *
	 * @param array $params Parameters.
	 * @return array
	 */
	private function locate( $params ) {
		$post_id = (int) ( isset( $params['post_id'] ) ? $params['post_id'] : 0 );
		$index   = (int) ( isset( $params['block_index'] ) ? $params['block_index'] : -1 );

		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'ok' => false, 'error' => 'Post not found: ' . $post_id );
		}

		$blocks = parse_blocks( (string) $post->post_content );
		if ( $index < 0 || $index >= count( $blocks ) ) {
			return array( 'ok' => false, 'error' => 'Block index out of range: ' . $index );
		}

		return array( 'ok' => true, 'post' => $post, 'blocks' => $blocks, 'index' => $index );
	}

	/**
	 * Apply the requested change to one block in place.
	 *
	 * @param array $block  The block to modify.
	 * @param array $params Parameters.
	 * @return array Modified block.
	 */
	private function apply_change( $block, $params ) {
		if ( isset( $params['block_name'] ) && '' !== $params['block_name'] ) {
			$block['blockName'] = (string) $params['block_name'];
		}
		if ( isset( $params['attrs'] ) && is_array( $params['attrs'] ) ) {
			$existing        = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			$block['attrs']  = array_merge( $existing, $params['attrs'] );
		}
		if ( isset( $params['inner_html'] ) ) {
			// Only reached for a leaf block (non-leaf inner_html is refused up
			// front, see inner_html_conflict) — keep innerContent in sync so
			// serialize_blocks emits the new markup.
			$block['innerHTML']    = (string) $params['inner_html'];
			$block['innerContent'] = array( (string) $params['inner_html'] );
		}
		return $block;
	}

	/**
	 * Replacing inner_html on a block that has nested innerBlocks would drop the
	 * child blocks on serialization (their null placeholders in innerContent are
	 * lost). Refuse it rather than silently deleting the children.
	 *
	 * @param array $block  The target block.
	 * @param array $params Parameters.
	 * @return string|false Error message, or false if allowed.
	 */
	private function inner_html_conflict( $block, $params ) {
		if ( isset( $params['inner_html'] ) && ! empty( $block['innerBlocks'] ) ) {
			return 'Refused: cannot replace inner HTML of a block that has nested blocks — that would drop the child blocks. Edit the child blocks instead.';
		}
		return false;
	}

	public function dry_run( $params ) {
		$located = $this->locate( $params );
		if ( ! $located['ok'] ) {
			return array( 'error' => $located['error'] );
		}
		$index   = $located['index'];
		$current = $located['blocks'][ $index ];

		$conflict = $this->inner_html_conflict( $current, $params );
		if ( false !== $conflict ) {
			return array( 'error' => $conflict );
		}

		$updated = $this->apply_change( $current, $params );

		$preview = array(
			'post_id'     => (int) $params['post_id'],
			'block_index' => $index,
			'current'     => array(
				'name'  => isset( $current['blockName'] ) ? $current['blockName'] : null,
				'attrs' => isset( $current['attrs'] ) ? $current['attrs'] : array(),
			),
			'proposed'    => array(
				'name'  => isset( $updated['blockName'] ) ? $updated['blockName'] : null,
				'attrs' => isset( $updated['attrs'] ) ? $updated['attrs'] : array(),
			),
		);

		// Surface an inner_html change so the approver sees what will actually
		// change (the tool advertises supports_preview and approval depends on it).
		if ( isset( $params['inner_html'] ) ) {
			$preview['current']['inner_html']  = isset( $current['innerHTML'] ) ? $current['innerHTML'] : '';
			$preview['proposed']['inner_html'] = (string) $params['inner_html'];
		}

		return $preview;
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
		$located = $this->locate( $params );
		if ( ! $located['ok'] ) {
			return array( 'error' => $located['error'] );
		}

		$post   = $located['post'];
		$blocks = $located['blocks'];
		$index  = $located['index'];

		$conflict = $this->inner_html_conflict( $blocks[ $index ], $params );
		if ( false !== $conflict ) {
			return array( 'error' => $conflict );
		}

		// Snapshot-first (reversible). Fail closed if we can't capture a backup.
		$snapshot_id = '';
		if ( ! class_exists( 'Aura_Worker_Snapshots' ) ) {
			return array( 'error' => 'Snapshot engine unavailable; refusing to edit without a backup.' );
		}
		$snaps = new Aura_Worker_Snapshots();
		$snap  = $snaps->snapshot_post( (int) $post->ID );
		if ( empty( $snap['success'] ) ) {
			$reason = isset( $snap['error'] ) ? $snap['error'] : 'unknown error';
			return array( 'error' => 'Snapshot failed (' . $reason . '); refusing to edit.' );
		}
		$snapshot_id = $snap['snapshot']['id'];

		$blocks[ $index ] = $this->apply_change( $blocks[ $index ], $params );
		$new_content      = serialize_blocks( $blocks );

		$result = wp_update_post(
			array(
				'ID'           => (int) $post->ID,
				'post_content' => $new_content,
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return array( 'error' => $result->get_error_message() );
		}

		return array(
			'post_id'     => (int) $post->ID,
			'block_index' => $index,
			'updated'     => true,
			'snapshot_id' => $snapshot_id,
		);
	}
}
