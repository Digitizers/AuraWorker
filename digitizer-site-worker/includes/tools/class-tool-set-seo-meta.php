<?php
/**
 * MCP Tool: set_seo_meta (write)
 *
 * Write a post/page's SEO meta (title, description, focus keyword) directly to
 * the active SEO plugin's post meta — Rank Math, Yoast, or SEOPress. Runs on-site
 * via update_post_meta, so it works even when a WAF blocks the plugin's own REST
 * endpoint. Only the provided fields are changed. Governed (approval-gated) by
 * Aura through the verb-led name.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'aura_seo_meta_fields' ) ) {
	/**
	 * Map the active SEO plugin to its post-meta keys.
	 *
	 * @return array|null { plugin, title, description, focus_keyword } or null if none supported.
	 */
	function aura_seo_meta_fields() {
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			return array(
				'plugin'        => 'rankmath',
				'title'         => 'rank_math_title',
				'description'   => 'rank_math_description',
				'focus_keyword' => 'rank_math_focus_keyword',
			);
		}
		if ( defined( 'WPSEO_VERSION' ) ) {
			return array(
				'plugin'        => 'yoast',
				'title'         => '_yoast_wpseo_title',
				'description'   => '_yoast_wpseo_metadesc',
				'focus_keyword' => '_yoast_wpseo_focuskw',
			);
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return array(
				'plugin'        => 'seopress',
				'title'         => '_seopress_titles_title',
				'description'   => '_seopress_titles_desc',
				'focus_keyword' => '_seopress_analysis_target_kw',
			);
		}
		return null;
	}
}

class Aura_Tool_Set_Seo_Meta extends Aura_Tool_Base {

	public function get_name() {
		return 'set_seo_meta';
	}

	public function get_description() {
		return 'Sets a post/page SEO meta (title, description, and/or focus keyword) on the active SEO plugin (Rank Math, Yoast, or SEOPress). Only the fields you pass are changed. Runs on-site, so it succeeds even when a WAF blocks the plugin\'s own REST endpoint.';
	}

	public function get_parameters() {
		return array(
			'post_id'       => array(
				'type'        => 'integer',
				'description' => 'The post/page ID to update.',
				'required'    => true,
			),
			'title'         => array(
				'type'        => 'string',
				'description' => 'SEO meta title. Omit to leave unchanged.',
				'required'    => false,
			),
			'description'   => array(
				'type'        => 'string',
				'description' => 'SEO meta description. Omit to leave unchanged.',
				'required'    => false,
			),
			'focus_keyword' => array(
				'type'        => 'string',
				'description' => 'Primary focus keyword. Omit to leave unchanged.',
				'required'    => false,
			),
		);
	}

	public function get_returns() {
		return array(
			'plugin'  => 'string — rankmath|yoast|seopress',
			'post_id' => 'integer',
			'updated' => 'array — the field names that were written',
		);
	}

	/**
	 * Writes post SEO meta — a mutating op, so it is never read-only and must be
	 * approved before it runs.
	 */
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
		$post_id = isset( $params['post_id'] ) ? (int) $params['post_id'] : 0;
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return array( 'error' => 'Post not found.' );
		}

		$fields = aura_seo_meta_fields();
		if ( ! $fields ) {
			return array( 'error' => 'No supported SEO plugin is active (Rank Math, Yoast, or SEOPress).' );
		}

		$updated = array();

		// Whether the caller actually asked to set any field. Used to tell
		// "nothing was requested" apart from "a field was requested but the write
		// failed" — otherwise a genuine write failure is misreported as if the
		// caller supplied no fields at all.
		$provided = isset( $params['title'] ) || isset( $params['description'] ) || isset( $params['focus_keyword'] );

		// update_post_meta() returns false BOTH on failure AND when the value is
		// unchanged (idempotent). Treat "already equals the desired value" as a
		// successful write so re-running the tool still flushes a stale SEO cache;
		// only a genuine failure (value differs and the write didn't take) is skipped.
		if ( isset( $params['title'] ) ) {
			$val = sanitize_text_field( (string) $params['title'] );
			if ( false !== update_post_meta( $post_id, $fields['title'], $val )
				|| (string) get_post_meta( $post_id, $fields['title'], true ) === $val ) {
				$updated[] = 'title';
			}
		}
		if ( isset( $params['description'] ) ) {
			$val = sanitize_textarea_field( (string) $params['description'] );
			if ( false !== update_post_meta( $post_id, $fields['description'], $val )
				|| (string) get_post_meta( $post_id, $fields['description'], true ) === $val ) {
				$updated[] = 'description';
			}
		}
		if ( isset( $params['focus_keyword'] ) ) {
			$val = sanitize_text_field( (string) $params['focus_keyword'] );
			if ( false !== update_post_meta( $post_id, $fields['focus_keyword'], $val )
				|| (string) get_post_meta( $post_id, $fields['focus_keyword'], true ) === $val ) {
				$updated[] = 'focus_keyword';
			}
		}

		if ( empty( $updated ) ) {
			if ( $provided ) {
				// Fields were supplied but none took — a real write failure, not a
				// missing-input error. Don't tell the caller to "provide" fields
				// they already provided.
				return array( 'error' => 'Failed to write SEO meta — the update did not take. Please try again.' );
			}
			return array( 'error' => 'Nothing to update — provide title, description, and/or focus_keyword.' );
		}

		// Writing the meta directly bypasses each plugin's own save flow, which can
		// leave a cached SEO title/description being served on the frontend. Always
		// flush core post caches; on Yoast, also drop the stale indexable row so it
		// rebuilds from the new meta on the next request. All guarded so non-matching
		// sites are unaffected.
		clean_post_cache( $post_id );
		if ( 'yoast' === $fields['plugin'] ) {
			$this->flush_yoast_indexable( $post_id );
		}

		return array(
			'plugin'  => $fields['plugin'],
			'post_id' => $post_id,
			'updated' => $updated,
		);
	}

	/**
	 * Invalidate Yoast's cached indexable for a post so the new meta is served.
	 *
	 * Yoast keeps a denormalised "indexable" row per post; writing the meta
	 * directly leaves it stale until a manual save/reindex. Deleting the row via
	 * Yoast's public repository surface forces a rebuild on the next request.
	 * Everything is guarded so the call is a no-op on non-Yoast or older Yoast.
	 *
	 * @param int $post_id The post whose indexable should be invalidated.
	 * @return void
	 */
	private function flush_yoast_indexable( $post_id ) {
		if ( ! function_exists( 'YoastSEO' ) ) {
			return;
		}
		try {
			$repository = YoastSEO()->classes->get( 'Yoast\WP\SEO\Repositories\Indexable_Repository' );
			if ( ! is_object( $repository ) || ! method_exists( $repository, 'find_by_id_and_type' ) ) {
				return;
			}
			$indexable = $repository->find_by_id_and_type( (int) $post_id, 'post', false );
			if ( is_object( $indexable ) && method_exists( $indexable, 'delete' ) ) {
				$indexable->delete();
			}
		} catch ( \Throwable $e ) {
			// Yoast internals changed or unavailable — the meta is still written and
			// clean_post_cache() already ran, so fail silently.
			return;
		}
	}
}
