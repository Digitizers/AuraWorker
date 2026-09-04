<?php
/**
 * Snapshot engine for SiteAgent.
 *
 * Generalizes the plugin-zip rollback (class-aura-worker-rollback.php) into
 * capture-before-write snapshots for the surfaces the Governed Power Tools
 * touch: individual files, WordPress options, and post-meta keys (the shape
 * Elementor page/kit data lives in — `_elementor_data`, `_elementor_page_settings`,
 * kit-scoped globals). Each snapshot is a small JSON record (plus a payload copy
 * for non-trivial kinds) under wp-content/aura-backups/snapshots/, so the Aura
 * gateway can preview and reverse a power action the same way it already reverses
 * page/resource snapshots.
 *
 * Table snapshots are intentionally deferred (they need $wpdb + row-cap policy);
 * the record shape reserves the 'db_table' kind for that later work.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Worker_Snapshots {

	/**
	 * Door envelope kinds the retention sweep may prune (Ruling R6). The
	 * pre-existing kinds (`file|option|post|meta|posts` without a door label)
	 * are never pruned — they are taken by the power tools, whose retention is
	 * the operator's to decide.
	 */
	const DOOR_KINDS = array( 'page', 'component', 'design_system', 'creation', 'creation_restore' );

	/**
	 * Directory where snapshots are stored.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Constructor — ensures the snapshot directory exists and is protected.
	 */
	public function __construct() {
		$this->dir = WP_CONTENT_DIR . '/aura-backups/snapshots/';
		if ( ! file_exists( $this->dir ) ) {
			wp_mkdir_p( $this->dir );

			global $wp_filesystem;
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			WP_Filesystem();
			$wp_filesystem->put_contents( $this->dir . '.htaccess', 'Deny from all', FS_CHMOD_FILE );
			$wp_filesystem->put_contents( $this->dir . 'index.php', '<?php // Silence is golden.', FS_CHMOD_FILE );
		}
	}

	/**
	 * Generate a sortable, unique snapshot id.
	 *
	 * @return string
	 */
	private function new_id() {
		// Timestamp prefix keeps ids newest-first sortable; the suffix is a CSPRNG
		// value (not a predictable uniqid) so payload filenames can't be guessed
		// on a host where the .htaccess deny is ignored (nginx).
		try {
			$rand = bin2hex( random_bytes( 12 ) );
		} catch ( \Exception $e ) {
			$rand = substr( md5( uniqid( '', true ) ), 0, 24 );
		}
		return 'snap_' . gmdate( 'Ymd_His' ) . '_' . $rand;
	}

	/**
	 * Write a snapshot record (and optional payload) to disk.
	 *
	 * @param array       $meta    Record metadata (kind, target, created, ...).
	 * @param string|null $payload Optional raw payload to store alongside.
	 * @return array|false The stored record, or false if any write failed (so the
	 *                     caller can fail closed — a power tool must not proceed
	 *                     believing it has a rollback point when it doesn't).
	 */
	private function persist( $meta, $payload = null ) {
		$id                  = $this->new_id();
		$meta['id']          = $id;
		$meta['created_gmt'] = gmdate( 'Y-m-d H:i:s' );
		// WHICH BLOG TOOK IT (Ruling P15). Every blog on a multisite shares
		// this one directory, and an envelope's ids — post ids, option names —
		// mean nothing outside the blog they were read on. The stamp is what
		// lets a read be withheld and a restore be refused; without it, one
		// subsite's credentials read and overwrite another subsite's content.
		$meta['blog_id']     = self::current_blog_id();

		if ( null !== $payload ) {
			$payload_path = $this->dir . $id . '.payload';
			if ( false === file_put_contents( $payload_path, $payload ) ) {
				return false;
			}
			$meta['payload_path'] = $payload_path;
		}

		$json = wp_json_encode( $meta );
		if ( false === $json ) {
			return false;
		}
		$meta_path = $this->dir . $id . '.json';
		if ( false === file_put_contents( $meta_path, $json ) ) {
			return false;
		}
		$meta['meta_path'] = $meta_path;

		return $meta;
	}

	/**
	 * This blog's id — 1 on a single site, where core's own default is 1 and
	 * the function may not exist at all on very old cores.
	 *
	 * @return int
	 */
	private static function current_blog_id() {
		return function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
	}

	/**
	 * Is this envelope this blog's to act on?
	 *
	 * FALSE only when the stamp is PRESENT and names another blog. A legacy
	 * envelope carries no stamp and cannot be placed — and refusing to
	 * restore every capture taken before the stamp existed would break the
	 * undo of every write on the site. The READ path is stricter (see
	 * Aura_Tool_Snapshot_Get): on a multisite an unplaceable envelope has its
	 * payload withheld, because handing over content that may be another
	 * blog's is a leak, while restoring your own old capture is not.
	 *
	 * @param array $record The envelope.
	 * @return bool
	 */
	public static function belongs_to_current_blog( array $record ) {
		return ! isset( $record['blog_id'] ) || (int) $record['blog_id'] === self::current_blog_id();
	}

	/** The one refusal both the API and restore() answer a foreign envelope with. */
	const FOREIGN_BLOG_ERROR = 'Snapshot belongs to another site.';

	/**
	 * Capture a file's current contents before it is modified.
	 *
	 * @param string $path Absolute path to the file.
	 * @return array { success: bool, snapshot?: array, error?: string }
	 */
	public function snapshot_file( $path ) {
		if ( ! is_string( $path ) || '' === $path || ! file_exists( $path ) || ! is_file( $path ) ) {
			return array( 'success' => false, 'error' => 'File not found: ' . (string) $path );
		}

		$contents = file_get_contents( $path );
		if ( false === $contents ) {
			return array( 'success' => false, 'error' => 'Unable to read file: ' . $path );
		}

		$record = $this->persist(
			array(
				'kind'   => 'file',
				'target' => $path,
				'bytes'  => strlen( $contents ),
			),
			$contents
		);

		if ( false === $record ) {
			return array( 'success' => false, 'error' => 'Failed to persist snapshot (disk full or unwritable).' );
		}
		return array( 'success' => true, 'snapshot' => $record );
	}

	/**
	 * Capture a WordPress option's current value before it is changed.
	 *
	 * @param string $name Option name.
	 * @return array { success: bool, snapshot?: array, error?: string }
	 */
	public function snapshot_option( $name ) {
		if ( ! is_string( $name ) || '' === $name ) {
			return array( 'success' => false, 'error' => 'Invalid option name.' );
		}

		// Uncollidable sentinel: a fresh object can never equal a stored option
		// value, so an option whose value happens to be a magic string isn't
		// mistaken for "absent" (which restore would wrongly delete).
		$sentinel = new stdClass();
		$value    = get_option( $name, $sentinel );
		$existed  = ( $value !== $sentinel );

		$record = $this->persist(
			array(
				'kind'    => 'option',
				'target'  => $name,
				'existed' => $existed,
			),
			$existed ? serialize( $value ) : ''
		);

		if ( false === $record ) {
			return array( 'success' => false, 'error' => 'Failed to persist snapshot (disk full or unwritable).' );
		}
		return array( 'success' => true, 'snapshot' => $record );
	}

	/**
	 * Capture a post's current content before it is edited (Gutenberg/block edits).
	 *
	 * @param int $post_id Post ID.
	 * @return array { success: bool, snapshot?: array, error?: string }
	 */
	public function snapshot_post( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return array( 'success' => false, 'error' => 'Invalid post id.' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'success' => false, 'error' => 'Post not found: ' . $post_id );
		}

		$record = $this->persist(
			array(
				'kind'    => 'post',
				'target'  => $post_id,
			),
			(string) $post->post_content
		);

		if ( false === $record ) {
			return array( 'success' => false, 'error' => 'Failed to persist snapshot (disk full or unwritable).' );
		}
		return array( 'success' => true, 'snapshot' => $record );
	}

	/**
	 * Capture one or more post-meta keys before they are rewritten.
	 *
	 * This is the surface Elementor page/kit data lives in — `_elementor_data`,
	 * `_elementor_page_settings`, and the kit-scoped globals repositories all
	 * store a single serialized value under one meta key. Each requested key is
	 * captured with its existence flag (so restore can re-delete a key that was
	 * absent at capture time rather than resurrecting an empty one) and its
	 * primary value. It targets single-valued meta keys — the shape every
	 * Elementor storage key uses — not multi-row meta.
	 *
	 * @param int          $post_id Post ID.
	 * @param string|array $keys    Meta key, or list of meta keys, to capture.
	 * @return array { success: bool, snapshot?: array, error?: string }
	 */
	public function snapshot_meta( $post_id, $keys ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return array( 'success' => false, 'error' => 'Invalid post id.' );
		}
		if ( ! get_post( $post_id ) ) {
			return array( 'success' => false, 'error' => 'Post not found: ' . $post_id );
		}
		// Reject revision/autosave IDs. get_post_meta reads the revision's own
		// meta, but update_post_meta/delete_post_meta on a revision can affect the
		// parent — so a snapshot taken against a revision could later clobber or
		// wipe the parent page's Elementor data. Elementor data lives on the
		// parent post, so callers must pass the parent id.
		if ( wp_is_post_revision( $post_id ) ) {
			return array( 'success' => false, 'error' => 'Refusing to snapshot a revision/autosave; pass the parent post id.' );
		}

		if ( is_string( $keys ) ) {
			$keys = array( $keys );
		}
		if ( ! is_array( $keys ) || empty( $keys ) ) {
			return array( 'success' => false, 'error' => 'No meta keys given to snapshot.' );
		}

		$captured = array();
		foreach ( $keys as $key ) {
			if ( ! is_string( $key ) || '' === $key ) {
				return array( 'success' => false, 'error' => 'Invalid meta key.' );
			}
			$existed          = metadata_exists( 'post', $post_id, $key );
			$captured[ $key ] = array(
				'existed' => $existed,
				// get_post_meta returns the stored (unslashed) value; restore
				// re-slashes before writing so the value round-trips exactly.
				'value'   => $existed ? get_post_meta( $post_id, $key, true ) : '',
			);
		}

		$record = $this->persist(
			array(
				'kind'   => 'meta',
				'target' => $post_id,
				'keys'   => array_map( 'strval', array_keys( $captured ) ),
			),
			serialize( $captured )
		);

		if ( false === $record ) {
			return array( 'success' => false, 'error' => 'Failed to persist snapshot (disk full or unwritable).' );
		}
		return array( 'success' => true, 'snapshot' => $record );
	}

	/**
	 * Capture a SET of posts (existence + selected meta) so a multi-post write can
	 * be fully rolled back. On restore this recreates a post the write DELETED —
	 * with its ORIGINAL id, via `import_id`, so id references elsewhere (e.g. an
	 * Elementor class_id → post_id map) stay valid — DELETES a post the write
	 * CREATED, and restores the captured meta on a surviving/recreated post.
	 *
	 * Built for Elementor v4 global classes (per-class CPT posts: a create adds a
	 * post, a delete removes one). The cascade — the affected pages' `_elementor_data`
	 * that a class delete rewrites — is snapshotted separately by the caller via
	 * snapshot_meta(); this primitive owns only the class posts themselves.
	 *
	 * @param int[]        $post_ids  Post IDs to capture (each may or may not exist).
	 * @param string|array $meta_keys Meta key(s) to capture per existing post.
	 * @param array        $opts      Optional door metadata:
	 *                                `cpts`       — post types this capture is the WHOLE set of,
	 *                                               so restore also deletes a post of those types
	 *                                               that the write ADDED (Ruling R3);
	 *                                `kind_label` — the door kind (`page|component|design_system|
	 *                                               creation_restore`) stored as `door_kind`;
	 *                                `door`       — `{ seq, ability }` for the audit trail.
	 * @return array { success: bool, snapshot?: array, error?: string }
	 */
	public function snapshot_posts( $post_ids, $meta_keys, $opts = array() ) {
		if ( ! is_array( $post_ids ) || empty( $post_ids ) ) {
			return array( 'success' => false, 'error' => 'No post ids given to snapshot.' );
		}
		if ( is_string( $meta_keys ) ) {
			$meta_keys = array( $meta_keys );
		}
		if ( ! is_array( $meta_keys ) ) {
			$meta_keys = array();
		}
		foreach ( $meta_keys as $k ) {
			if ( ! is_string( $k ) || '' === $k ) {
				return array( 'success' => false, 'error' => 'Invalid meta key.' );
			}
		}

		$captured = array();
		foreach ( array_unique( array_map( 'intval', $post_ids ) ) as $pid ) {
			if ( $pid <= 0 ) {
				return array( 'success' => false, 'error' => 'Invalid post id.' );
			}
			if ( wp_is_post_revision( $pid ) ) {
				return array( 'success' => false, 'error' => 'Refusing to snapshot a revision/autosave id: ' . $pid );
			}
			$post = get_post( $pid );
			if ( ! $post ) {
				$captured[ $pid ] = array( 'existed' => false );
				continue;
			}
			$meta = array();
			foreach ( $meta_keys as $key ) {
				$existed      = metadata_exists( 'post', $pid, $key );
				$meta[ $key ] = array(
					'existed' => $existed,
					'value'   => $existed ? get_post_meta( $pid, $key, true ) : '',
				);
			}
			$captured[ $pid ] = array(
				'existed' => true,
				'fields'  => array(
					'post_type'      => $post->post_type,
					'post_status'    => $post->post_status,
					'post_title'     => $post->post_title,
					'post_name'      => $post->post_name,
					'post_parent'    => (int) $post->post_parent,
					'post_content'   => $post->post_content,
					'post_excerpt'   => $post->post_excerpt,
					'menu_order'     => (int) $post->menu_order,
					// Preserve identity/scheduling so a recreate is faithful (a fresh
					// wp_insert_post would otherwise stamp "now" + the current user).
					'post_author'    => $post->post_author,
					'post_date'      => $post->post_date,
					'post_date_gmt'  => $post->post_date_gmt,
					'comment_status' => $post->comment_status,
					'ping_status'    => $post->ping_status,
				),
				'meta'    => $meta,
			);
		}

		$meta = array(
			'kind'    => 'posts',
			'targets' => array_map( 'intval', array_keys( $captured ) ),
			'keys'    => array_values( array_map( 'strval', $meta_keys ) ),
		);
		if ( ! empty( $opts['cpts'] ) && is_array( $opts['cpts'] ) ) {
			$meta['cpts'] = array_values( array_map( 'strval', $opts['cpts'] ) );
		}
		if ( ! empty( $opts['kind_label'] ) ) {
			$meta['door_kind'] = (string) $opts['kind_label'];
		}
		if ( ! empty( $opts['door'] ) && is_array( $opts['door'] ) ) {
			$meta['door'] = $opts['door'];
		}

		$record = $this->persist( $meta, serialize( $captured ) );
		if ( false === $record ) {
			return array( 'success' => false, 'error' => 'Failed to persist snapshot (disk full or unwritable).' );
		}
		return array( 'success' => true, 'snapshot' => $record );
	}

	/**
	 * A creation: there was nothing to capture BEFORE, so the envelope names
	 * what the write made. Kind `creation`; its restore is a governed trash
	 * (never a delete — see the `creation` case in restore()).
	 *
	 * @param int[]  $post_ids  The ids the write created.
	 * @param string $post_type Their post type.
	 * @param array  $door      `{ seq, ability }` for the audit trail.
	 * @return array { success: bool, snapshot?: array, error?: string }
	 */
	public function snapshot_creation( array $post_ids, $post_type, array $door ) {
		$record = $this->persist(
			array(
				'kind'             => 'creation',
				'door_kind'        => 'creation',
				'created_post_ids' => array_values( array_map( 'intval', $post_ids ) ),
				'post_type'        => (string) $post_type,
				'door'             => $door,
			)
		);
		if ( false === $record ) {
			return array( 'success' => false, 'error' => 'Failed to persist snapshot (disk full or unwritable).' );
		}
		return array( 'success' => true, 'snapshot' => $record );
	}

	/**
	 * Restore a captured `{ key => { existed, value } }` meta map onto a post —
	 * deleting keys absent at capture, re-slashing and writing the rest, verifying
	 * each (both delete_post_meta and update_post_meta return false ambiguously).
	 * Shared by the `meta` and `posts` restore kinds.
	 *
	 * @param int   $post_id  Target post id (must already exist).
	 * @param array $captured Captured meta map.
	 * @return array { success: bool, error?: string }
	 */
	private function restore_meta_map( $post_id, $captured ) {
		foreach ( $captured as $key => $info ) {
			$key = (string) $key;
			if ( empty( $info['existed'] ) ) {
				delete_post_meta( $post_id, $key );
				if ( metadata_exists( 'post', $post_id, $key ) ) {
					return array( 'success' => false, 'error' => 'Failed to remove meta key: ' . $key );
				}
				continue;
			}
			$ok = update_post_meta( $post_id, $key, wp_slash( $info['value'] ) );
			if ( false === $ok && get_post_meta( $post_id, $key, true ) !== $info['value'] ) {
				return array( 'success' => false, 'error' => 'Failed to restore meta key: ' . $key );
			}
		}
		return array( 'success' => true );
	}

	/**
	 * Load a snapshot record by id.
	 *
	 * @param string $id Snapshot id.
	 * @return array|null
	 */
	public function get( $id ) {
		$meta_path = $this->dir . basename( (string) $id ) . '.json';
		if ( ! file_exists( $meta_path ) ) {
			return null;
		}
		$data = json_decode( file_get_contents( $meta_path ), true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Unserialize a snapshot payload without ever instantiating a class.
	 *
	 * The payload files are written by this plugin, but they sit on disk and are
	 * the one untrusted-if-tampered input on the restore path — a plain
	 * unserialize() would let a crafted payload build arbitrary objects and fire
	 * __wakeup()/__destruct() gadget chains. `allowed_classes => false` turns any
	 * serialized object into a __PHP_Incomplete_Class instead, closing that
	 * surface. Everything this engine actually captures — option values, the
	 * meta/post arrays it builds, Elementor's JSON-string payloads — is scalars
	 * and arrays, so nothing legitimate is lost. A payload that *did* contain an
	 * object was either tampered or a pathological object-valued option; the
	 * callers detect the resulting incomplete class (an object is not an array,
	 * and is_stripped_object() catches the top-level option case) and fail closed
	 * rather than write it back.
	 *
	 * @param string $raw Serialized payload bytes.
	 * @return mixed Unserialized value, or false on malformed input.
	 */
	private function unserialize_payload( $raw ) {
		return unserialize( (string) $raw, array( 'allowed_classes' => false ) );
	}

	/**
	 * Maximum array nesting the stripped-object walk will descend before it
	 * treats the payload as unsafe. Real snapshot payloads (option values, the
	 * shallow meta/post maps, Elementor's JSON-string blobs) are nowhere near
	 * this deep, so a legitimate payload never reaches it; the cap exists only
	 * to bound a tampered one.
	 */
	private const MAX_WALK_DEPTH = 64;

	/**
	 * True when a value is — or contains, at any depth — an object stripped by
	 * allowed_classes => false.
	 *
	 * The check must recurse: allowed_classes => false strips every serialized
	 * object to __PHP_Incomplete_Class, but a tampered payload can nest one
	 * inside an otherwise-valid array (e.g. serialize( array( 'x' => $gadget ) )).
	 * A top-level-only check passes that array straight through and update_option
	 * / update_post_meta persists the incomplete class as a "successful" restore.
	 * Walking the whole structure is what lets every kind fail closed on it.
	 *
	 * The walk is depth-bounded: unserialize() faithfully rebuilds a serialized
	 * reference cycle (e.g. `a:1:{i:0;R:1;}`) into a genuinely self-referential
	 * array, and an unbounded recursion over one would exhaust the stack and fatal
	 * the restore request rather than fail closed. Hitting the cap is itself
	 * treated as "unsafe" (return true) so a pathological payload is rejected, not
	 * restored.
	 *
	 * @param mixed $value Unserialized value.
	 * @param int   $depth Current recursion depth (internal).
	 * @return bool
	 */
	private function contains_stripped_object( $value, $depth = 0 ) {
		if ( $value instanceof \__PHP_Incomplete_Class ) {
			return true;
		}
		if ( is_array( $value ) ) {
			if ( $depth >= self::MAX_WALK_DEPTH ) {
				// Too deep to be a real payload — a reference cycle or a crafted
				// deeply-nested array. Refuse it rather than recurse into a fatal.
				return true;
			}
			foreach ( $value as $item ) {
				if ( $this->contains_stripped_object( $item, $depth + 1 ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Restore state from a snapshot.
	 *
	 * @param string $id Snapshot id.
	 * @return array { success: bool, error?: string }
	 */
	public function restore( $id ) {
		$record = $this->get( $id );
		if ( null === $record ) {
			return array( 'success' => false, 'error' => 'Snapshot not found.' );
		}
		if ( ! self::belongs_to_current_blog( $record ) ) {
			// A DESIGNATED refusal, not a failure: the envelope is intact and
			// restorable — on the blog that took it (Ruling P15). Its ids
			// address that blog's tables, so writing it here would overwrite
			// whatever happens to share those numbers.
			return array(
				'success' => false,
				'code'    => 'aura_foreign_blog',
				'error'   => self::FOREIGN_BLOG_ERROR,
			);
		}

		switch ( $record['kind'] ) {
			case 'file':
				$payload_path = $record['payload_path'] ?? '';
				if ( ! $payload_path || ! file_exists( $payload_path ) ) {
					return array( 'success' => false, 'error' => 'Snapshot payload missing.' );
				}
				$ok = file_put_contents( $record['target'], file_get_contents( $payload_path ) );
				return ( false === $ok )
					? array( 'success' => false, 'error' => 'Failed to write file: ' . $record['target'] )
					: array( 'success' => true );

			case 'option':
				if ( empty( $record['existed'] ) ) {
					delete_option( $record['target'] );
					return array( 'success' => true );
				}
				$payload_path = $record['payload_path'] ?? '';
				$raw          = ( $payload_path && file_exists( $payload_path ) ) ? file_get_contents( $payload_path ) : '';
				$value        = $this->unserialize_payload( $raw );
				// Fail closed rather than write back an incomplete class: the payload
				// held a serialized object (tampered, or a rare object-valued option),
				// which allowed_classes => false intentionally refused to rebuild.
				// Recurses so a nested object inside an array is caught too, not just
				// a top-level one.
				if ( $this->contains_stripped_object( $value ) ) {
					return array( 'success' => false, 'error' => 'Snapshot payload contains an object and cannot be safely restored.' );
				}
				update_option( $record['target'], $value );
				return array( 'success' => true );

			case 'post':
				// Fail closed if the payload is gone — writing '' would WIPE the
				// page instead of restoring it (matches the file case).
				$payload_path = $record['payload_path'] ?? '';
				if ( ! $payload_path || ! file_exists( $payload_path ) ) {
					return array( 'success' => false, 'error' => 'Snapshot payload missing.' );
				}
				$content = file_get_contents( $payload_path );
				$result  = wp_update_post(
					array(
						'ID'           => (int) $record['target'],
						'post_content' => $content,
					),
					true
				);
				return is_wp_error( $result )
					? array( 'success' => false, 'error' => $result->get_error_message() )
					: array( 'success' => true );

			case 'meta':
				$payload_path = $record['payload_path'] ?? '';
				if ( ! $payload_path || ! file_exists( $payload_path ) ) {
					return array( 'success' => false, 'error' => 'Snapshot payload missing.' );
				}
				$captured = $this->unserialize_payload( file_get_contents( $payload_path ) );
				// A tampered payload that serialized an object is stripped to an
				// incomplete class. Reject both a top-level one (not an array) and one
				// nested inside a captured meta value, before any of it is written.
				if ( ! is_array( $captured ) || $this->contains_stripped_object( $captured ) ) {
					return array( 'success' => false, 'error' => 'Snapshot payload corrupt.' );
				}
				$post_id = (int) $record['target'];
				// If the page/kit was deleted after the snapshot was taken, writing
				// meta would add orphaned wp_postmeta rows for a non-existent object
				// and falsely report a successful restore. Fail closed.
				if ( ! get_post( $post_id ) ) {
					return array( 'success' => false, 'error' => 'Target post no longer exists; cannot restore.' );
				}
				// Delete keys absent at capture, re-slash + write the rest, verify each
				// (both delete_post_meta and update_post_meta return false ambiguously).
				return $this->restore_meta_map( $post_id, $captured );

			case 'posts':
				return $this->restore_posts_record( $record );

			case 'creation_restore':
				// The pre-restore capture of a creation restore: a `posts` envelope
				// under another name, restored exactly the same way. (Every envelope
				// this plugin writes for that kind carries `kind: posts` and only the
				// `door_kind` label; the case is here so a record that names the door
				// kind directly is not answered "unsupported".)
				return $this->restore_posts_record( $record );

			case 'creation':
				// Core's wp_trash_post() DELETES when the trash is off (post.php), so
				// on such a site "undo the creation" is not reversible at all. Refuse
				// BEFORE touching anything rather than destroy the page silently.
				if ( defined( 'EMPTY_TRASH_DAYS' ) && 0 === (int) EMPTY_TRASH_DAYS ) {
					return array(
						'success' => false,
						'code'    => 'aura_trash_disabled',
						'error'   => 'this site has the trash disabled — the created page cannot be undone reversibly; delete it by hand',
					);
				}
				$trashed = array();
				$already = array();
				foreach ( (array) ( $record['created_post_ids'] ?? array() ) as $pid ) {
					$pid  = (int) $pid;
					$post = get_post( $pid );
					if ( ! $post ) {
						continue; // gone already: nothing to undo
					}
					if ( 'trash' === $post->post_status ) {
						$already[] = $pid;
						continue;
					}
					// wp_trash_post()'s return is not proof (a filter can short-circuit
					// it); the status is.
					wp_trash_post( $pid );
					$after = get_post( $pid );
					if ( ! $after || 'trash' !== $after->post_status ) {
						return array( 'success' => false, 'error' => 'Failed to trash created post: ' . $pid );
					}
					$trashed[] = $pid;
				}
				return array( 'success' => true, 'trashed' => $trashed, 'already' => $already );

			default:
				return array( 'success' => false, 'error' => 'Unsupported snapshot kind: ' . $record['kind'] );
		}
	}

	/**
	 * The `posts` restore: put every captured id back (recreating one the write
	 * deleted, reverting one it changed), then — for a SET-typed capture — remove
	 * every post of the captured types that was not in the set.
	 *
	 * @param array $record The envelope.
	 * @return array { success: bool, error?: string }
	 */
	private function restore_posts_record( array $record ) {
		$payload_path = $record['payload_path'] ?? '';
		if ( ! $payload_path || ! file_exists( $payload_path ) ) {
			return array( 'success' => false, 'error' => 'Snapshot payload missing.' );
		}
		$captured = $this->unserialize_payload( file_get_contents( $payload_path ) );
		// A tampered payload that serialized an object is stripped to an
		// incomplete class. Reject both a top-level one (not an array) and one
		// nested inside a captured post/meta value, before any of it is written.
		if ( ! is_array( $captured ) || $this->contains_stripped_object( $captured ) ) {
			return array( 'success' => false, 'error' => 'Snapshot payload corrupt.' );
		}
		// An EMPTY capture is not a record of an empty set — snapshot_posts()
		// refuses an empty id list and writes one entry per id, so a payload
		// that deserializes to nothing is truncated or partly written. Refusing
		// is the only honest verdict: reporting success would answer 200 and
		// settle the door entry `ok` having rolled nothing back, and for a
		// set-typed capture it would additionally read as "every class and
		// style on the site was added by the write".
		if ( empty( $captured ) ) {
			return array( 'success' => false, 'error' => 'Snapshot payload corrupt.' );
		}
		foreach ( $captured as $pid => $info ) {
			$pid    = (int) $pid;
			$exists = (bool) get_post( $pid );
			$was    = ! empty( $info['existed'] );

			if ( ! $was ) {
				// Absent at capture. If the write CREATED it, delete to roll
				// back; if still absent, nothing to do. Verify by existence —
				// wp_delete_post's return is unreliable (a pre_delete_post
				// filter can short-circuit it to a truthy value without
				// deleting), so a truthy return doesn't prove removal.
				if ( $exists ) {
					wp_delete_post( $pid, true );
					if ( get_post( $pid ) ) {
						return array( 'success' => false, 'error' => 'Failed to delete created post: ' . $pid );
					}
				}
				continue;
			}

			$fields = is_array( $info['fields'] ?? null ) ? $info['fields'] : array();

			if ( ! $exists ) {
				// Present at capture, deleted by the write — recreate it with
				// its ORIGINAL id (import_id) so id references stay valid.
				$insert              = $fields;
				$insert['import_id'] = $pid;
				$new                 = wp_insert_post( wp_slash( $insert ), true );
				if ( is_wp_error( $new ) ) {
					return array( 'success' => false, 'error' => 'Failed to recreate post ' . $pid . ': ' . $new->get_error_message() );
				}
				if ( (int) $new !== $pid ) {
					return array( 'success' => false, 'error' => 'Recreated post got id ' . (int) $new . ', expected ' . $pid . ' (id already taken).' );
				}
			} elseif ( ! empty( $fields ) ) {
				// Present at capture AND still present — but the write may have
				// changed its fields (e.g. a "delete" that trashed it: status →
				// 'trash', row kept). Revert the captured fields, not just meta.
				$update       = $fields;
				$update['ID'] = $pid;
				$upd          = wp_update_post( wp_slash( $update ), true );
				if ( is_wp_error( $upd ) ) {
					return array( 'success' => false, 'error' => 'Failed to restore fields of post ' . $pid . ': ' . $upd->get_error_message() );
				}
			}

			$meta = is_array( $info['meta'] ?? null ) ? $info['meta'] : array();
			$res  = $this->restore_meta_map( $pid, $meta );
			if ( empty( $res['success'] ) ) {
				return $res;
			}
		}
		// A SET-typed capture (design_system): a post of those types that was
		// NOT in the capture was ADDED by the write — remove it, or the restored
		// order meta points at rows that should not exist.
		if ( ! empty( $record['cpts'] ) && is_array( $record['cpts'] ) ) {
			$keep = array_map( 'intval', array_keys( $captured ) );
			$all  = get_posts(
				array(
					'post_type'   => $record['cpts'],
					'post_status' => 'any',
					'numberposts' => -1,
					'fields'      => 'ids',
				)
			);
			foreach ( $all as $extra ) {
				if ( in_array( (int) $extra, $keep, true ) ) {
					continue;
				}
				// Verified by existence, not by the return — see above.
				wp_delete_post( (int) $extra, true );
				if ( get_post( (int) $extra ) ) {
					return array( 'success' => false, 'error' => 'Failed to remove added post: ' . (int) $extra );
				}
			}
		}
		return array( 'success' => true );
	}

	/**
	 * List stored snapshots, newest-first.
	 *
	 * @return array
	 */
	public function list_snapshots() {
		$out   = array();
		$files = glob( $this->dir . 'snap_*.json' );
		if ( ! $files ) {
			return $out;
		}
		foreach ( $files as $file ) {
			$data = json_decode( file_get_contents( $file ), true );
			if ( is_array( $data ) ) {
				$out[] = $data;
			}
		}
		usort( $out, function ( $a, $b ) {
			return strcmp( $b['id'], $a['id'] );
		} );
		return $out;
	}

	/**
	 * Delete door envelopes older than $days (Ruling R6).
	 *
	 * Keyed on `door_kind`, never on `kind`: a door capture of a page IS a
	 * `posts` envelope, and the power tools' own `posts`/`file`/`option`
	 * captures must survive this sweep untouched.
	 *
	 * ON MULTISITE, ONLY THIS BLOG'S (Ruling P39). Every blog shares the one
	 * directory this class is configured with, and the reconciler — plus its
	 * six-hourly PRUNED_AT throttle — runs independently per blog off that
	 * blog's own `/status` poll. Unscoped, each polled subsite swept the whole
	 * NETWORK's envelopes and deleted other subsites' rollback points, which
	 * both violates the blog ownership `belongs_to_current_blog()` enforces on
	 * every read and restore, and undoes the undo of writes on sites that were
	 * never polled.
	 *
	 * A LEGACY record — one written before the `blog_id` stamp existed — cannot
	 * be placed, so only the MAIN site prunes it: some blog has to, or it would
	 * live for ever, and the main site is the one choice that cannot be made
	 * twice. This matches `belongs_to_current_blog()`'s reading of an
	 * unstamped record as "not provably foreign" without letting every subsite
	 * act on it.
	 *
	 * The per-blog full scan remains — it is throttled to once per six hours
	 * per blog. Per-blog STORAGE (a directory per blog, so the scan is
	 * naturally scoped) is the follow-up this leaves open.
	 *
	 * @param int      $days  Age in days; anything older goes.
	 * @param string[] $kinds The `door_kind` values to prune (see DOOR_KINDS).
	 * @return int How many were deleted.
	 */
	public function prune_older_than( $days, array $kinds ) {
		$cut       = time() - (int) $days * DAY_IN_SECONDS;
		$n         = 0;
		$network   = function_exists( 'is_multisite' ) && is_multisite();
		$main_site = ! $network || ! function_exists( 'is_main_site' ) || is_main_site();
		foreach ( $this->list_snapshots() as $rec ) {
			if ( ! in_array( (string) ( $rec['door_kind'] ?? '' ), $kinds, true ) ) {
				continue;
			}
			if ( $network && ! self::prunable_here( $rec, $main_site ) ) {
				continue;
			}
			// The stamp persist() wrote is a UTC wall clock with no zone on it.
			$at = strtotime( (string) ( $rec['created_gmt'] ?? '' ) . ' UTC' );
			if ( false === $at || $at >= $cut ) {
				continue;
			}
			if ( $this->delete( $rec['id'] ) ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * Is this record THIS blog's to prune? Multisite only — the caller has
	 * already established that (Ruling P39).
	 *
	 * @param array $record    The envelope.
	 * @param bool  $main_site Is the current blog the network's main site?
	 * @return bool
	 */
	private static function prunable_here( array $record, $main_site ) {
		if ( ! isset( $record['blog_id'] ) ) {
			return (bool) $main_site; // legacy, unplaceable: one blog prunes it, not every blog
		}
		return (int) $record['blog_id'] === self::current_blog_id();
	}

	/**
	 * Delete a snapshot (record + payload).
	 *
	 * @param string $id Snapshot id.
	 * @return bool
	 */
	public function delete( $id ) {
		$record = $this->get( $id );
		if ( null === $record ) {
			return false;
		}
		if ( ! empty( $record['payload_path'] ) && file_exists( $record['payload_path'] ) ) {
			wp_delete_file( $record['payload_path'] );
		}
		wp_delete_file( $this->dir . basename( (string) $id ) . '.json' );
		return true;
	}
}
