<?php
/**
 * MCP Tool: cleanup_orphaned_assets
 *
 * Finds media attachments not referenced in post content or as featured images,
 * and optionally deletes them.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Tool_Cleanup_Assets extends Aura_Tool_Base {

	public function get_name() {
		return 'cleanup_orphaned_assets';
	}

	public function get_description() {
		return 'Finds media library attachments that are not referenced in any post content or used as featured images. In dry_run mode (default), only reports orphans without deleting. Set dry_run=false to permanently delete them.';
	}

	public function get_parameters() {
		return array(
			'dry_run' => array(
				'type'        => 'boolean',
				'description' => 'When true (default), only report orphans without deleting. Set to false to permanently delete orphaned attachments.',
				'required'    => false,
				'default'     => true,
			),
			'sample_limit' => array(
				'type'        => 'integer',
				'description' => 'Maximum number of orphaned items to include in the sample list (default 20).',
				'required'    => false,
				'default'     => 20,
			),
		);
	}

	public function get_returns() {
		return array(
			'dry_run'        => 'bool — whether this was a dry run',
			'orphaned_count' => 'int — total orphaned attachments found',
			'removed_count'  => 'int — number of attachments deleted (0 for dry_run)',
			'space_saved'    => 'string — human-readable bytes freed (0 B for dry_run)',
			'space_saved_bytes' => 'int — bytes freed',
			'sample'         => 'array — sample of orphaned attachment info (id, title, filename, size_bytes)',
		);
	}

	/**
	 * Deletes orphaned media on a live run — a destructive, mutating op, so it is
	 * never read-only and must be approved before it runs. It supports a preview:
	 * dry_run() returns the orphan report (delete nothing), so an agent can
	 * inspect what would be removed through the approval-free preview path before
	 * approving the live delete.
	 */
	public function get_annotations() {
		return array(
			'read_only'         => false,
			'destructive'       => true,
			'requires_approval' => true,
			'supports_preview'  => true,
		);
	}

	/**
	 * Preview: run the safe report path (find orphans, delete nothing). This is
	 * the same code the tool runs when dry_run is true, surfaced through the
	 * preview API so the orphan sample/count is available without approval.
	 *
	 * @param array $params Tool parameters.
	 * @return array The dry-run report.
	 */
	public function dry_run( $params ) {
		$params            = is_array( $params ) ? $params : array();
		$params['dry_run'] = true;
		return $this->execute( $params );
	}

	/** @inheritDoc — site-wide maintenance: a freeze applies, a page rule does not. */
	public function touches( $params ) {
		return array( array( 'type' => 'site', 'id' => '*' ) );
	}

	public function execute( $params ) {
		$dry_run      = isset( $params['dry_run'] ) ? (bool) $params['dry_run'] : true;
		$sample_limit = isset( $params['sample_limit'] ) ? (int) $params['sample_limit'] : 20;

		// Collect all attachment IDs.
		$all_attachments = $this->get_all_attachments();
		if ( empty( $all_attachments ) ) {
			return array(
				'dry_run'            => $dry_run,
				'orphaned_count'     => 0,
				'removed_count'      => 0,
				'space_saved'        => '0 B',
				'space_saved_bytes'  => 0,
				'sample'             => array(),
			);
		}

		// Find referenced attachment IDs from post content and featured images.
		$referenced_ids = $this->get_referenced_attachment_ids();

		// Determine orphans.
		$orphan_ids = array();
		foreach ( $all_attachments as $id ) {
			if ( ! in_array( (int) $id, $referenced_ids, true ) ) {
				$orphan_ids[] = (int) $id;
			}
		}

		$orphaned_count    = count( $orphan_ids );
		$removed_count     = 0;
		$space_saved_bytes = 0;
		$sample            = array();

		// Build sample list (first N orphans).
		$sample_ids = array_slice( $orphan_ids, 0, $sample_limit );
		foreach ( $sample_ids as $id ) {
			$file_path = get_attached_file( $id );
			$size      = ( $file_path && file_exists( $file_path ) ) ? filesize( $file_path ) : 0;
			$sample[]  = array(
				'id'         => $id,
				'title'      => get_the_title( $id ),
				'filename'   => $file_path ? basename( $file_path ) : null,
				'size_bytes' => $size,
				'size_human' => size_format( $size ),
			);
		}

		// Delete if not dry run.
		if ( ! $dry_run ) {
			foreach ( $orphan_ids as $id ) {
				$file_path = get_attached_file( $id );
				$size      = ( $file_path && file_exists( $file_path ) ) ? filesize( $file_path ) : 0;

				$deleted = wp_delete_attachment( $id, true );
				if ( $deleted ) {
					$removed_count++;
					$space_saved_bytes += $size;
				}
			}
		}

		return array(
			'dry_run'            => $dry_run,
			'orphaned_count'     => $orphaned_count,
			'removed_count'      => $removed_count,
			'space_saved'        => size_format( $space_saved_bytes ),
			'space_saved_bytes'  => $space_saved_bytes,
			'sample'             => $sample,
		);
	}

	/**
	 * Get all attachment post IDs in the media library.
	 *
	 * @return int[]
	 */
	private function get_all_attachments() {
		$all_ids    = array();
		$batch_size = 500;
		$page       = 1;

		do {
			$query = new WP_Query( array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => $batch_size,
				'paged'          => $page,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			) );

			$batch_ids = array_map( 'intval', $query->posts );
			if ( empty( $batch_ids ) ) {
				break;
			}

			$all_ids = array_merge( $all_ids, $batch_ids );
			$page++;
		} while ( count( $batch_ids ) === $batch_size );

		return $all_ids;
	}

	/**
	 * Collect attachment IDs referenced in post content and as featured images.
	 *
	 * @return int[]
	 */
	private function get_referenced_attachment_ids() {
		global $wpdb;

		$referenced = array();

		// Featured image (post thumbnail) references.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$thumbnail_ids = $wpdb->get_col(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id'"
		);
		foreach ( $thumbnail_ids as $id ) {
			$referenced[] = (int) $id;
		}

		// Attachment IDs embedded in post content via wp:image blocks or classic img src.
		// We look for class="wp-image-{ID}" patterns and data-id attributes.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_contents = $wpdb->get_col(
			"SELECT post_content FROM {$wpdb->posts}
			 WHERE post_status NOT IN ('trash', 'auto-draft')
			   AND post_type NOT IN ('attachment', 'revision')"
		);

		foreach ( $post_contents as $content ) {
			// Match wp-image-{ID} in class attributes (Gutenberg blocks).
			if ( preg_match_all( '/wp-image-(\d+)/', $content, $matches ) ) {
				foreach ( $matches[1] as $id ) {
					$referenced[] = (int) $id;
				}
			}
			// Match data-id="{ID}" (gallery blocks).
			if ( preg_match_all( '/data-id="(\d+)"/', $content, $matches ) ) {
				foreach ( $matches[1] as $id ) {
					$referenced[] = (int) $id;
				}
			}
			// Match attachment IDs in Gutenberg block comments: <!-- wp:image {"id":123} -->.
			if ( preg_match_all( '/"id":(\d+)/', $content, $matches ) ) {
				foreach ( $matches[1] as $id ) {
					$referenced[] = (int) $id;
				}
			}
		}

		// Also include attachments that are children of non-trash posts.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$child_attachments = $wpdb->get_col(
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->posts} parent ON p.post_parent = parent.ID
			 WHERE p.post_type = 'attachment'
			   AND parent.post_status NOT IN ('trash', 'auto-draft')"
		);
		foreach ( $child_attachments as $id ) {
			$referenced[] = (int) $id;
		}

		return array_unique( $referenced );
	}
}
