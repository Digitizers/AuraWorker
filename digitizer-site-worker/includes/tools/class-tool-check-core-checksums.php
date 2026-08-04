<?php
/**
 * MCP Tool: check_core_checksums
 *
 * Read-only core-integrity audit: verifies every WordPress core file against
 * the official wp.org checksum manifest and reports modified, missing, and
 * unexpected files (including root-level implants beside wp-load.php).
 * Facts, not verdicts — nothing is changed or quarantined.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Tool_Check_Core_Checksums extends Aura_Tool_Base {

	/** Max files hashed before the scan reports itself truncated. */
	const MAX_FILES = 5000;

	/** Max directory entries visited per walk before truncation. */
	const MAX_ENTRIES = 20000;

	/** Per-file hashing byte cap — no genuine core file approaches this. */
	const MAX_FILE_BYTES = 10485760; // 10 MB.

	/** Root-level paths that are expected but absent from the manifest. */
	const ROOT_ALLOWLIST = array(
		'wp-config.php',
		'wp-config-sample.php',
		'wp-content',
		'.htaccess',
		'php.ini',
		'.user.ini',
		'robots.txt',
		'favicon.ico',
	);

	public function get_name() {
		return 'check_core_checksums';
	}

	public function get_description() {
		return 'Read-only core-integrity audit: verifies WordPress core files against the official wp.org checksum manifest (fetched over HTTPS only) and reports modified, missing, and unexpected files under wp-admin, wp-includes, and the WordPress root. Reports facts with explicit scan coverage; makes no changes.';
	}

	public function get_parameters() {
		return array();
	}

	public function get_returns() {
		return array(
			'modified'   => 'array — core files whose hash differs from the manifest { file, expected_md5 }',
			'missing'    => 'array — manifest files absent on disk',
			'unexpected' => 'array — files not in the manifest under wp-admin/, wp-includes/, or the WP root { file, size, mtime }',
			'special'    => 'array — non-regular files (symlink/FIFO/device) or oversized files at core paths { file, kind }',
			'root_extra' => 'array — allowlisted host-added root files present (reported, not flagged)',
			'coverage'   => 'object — { files_expected, files_checked, truncated, cap } scan-coverage contract',
			'error'      => 'string — "manifest_unavailable" when the checksum manifest could not be fetched (no findings are reported in that case)',
		);
	}

	/**
	 * Read-only: never mutates the site.
	 */
	public function get_annotations() {
		return array(
			'read_only'         => true,
			'destructive'       => false,
			'requires_approval' => false,
			'supports_preview'  => false,
		);
	}

	public function execute( $params ) {
		global $wp_version;

		$version = isset( $wp_version ) ? (string) $wp_version : (string) get_bloginfo( 'version' );
		$locale  = function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US';

		$manifest = $this->fetch_manifest( $version, $locale );

		if ( ! is_array( $manifest ) || empty( $manifest ) ) {
			// Fail closed: "could not verify" must never read as "verified
			// clean" — no findings arrays are returned at all.
			return array(
				'error'   => 'manifest_unavailable',
				'version' => $version,
				'locale'  => $locale,
			);
		}

		$base = $this->base_path();

		$modified   = array();
		$missing    = array();
		$special    = array();
		$checked    = 0;
		$truncated  = false;
		$cap        = '';

		// In-scope expectation computed up front (cheap — the manifest is
		// already in memory): stays the FULL in-scope count even when the
		// scan truncates, so expected>checked correctly signals what was
		// left unverified.
		$in_scope = 0;
		foreach ( $manifest as $rel_file => $unused ) {
			if ( 0 !== strpos( (string) $rel_file, 'wp-content/' ) ) {
				$in_scope++;
			}
		}

		$reported_ancestors        = array();
		$this->ancestor_link_cache = array();

		foreach ( $manifest as $rel_file => $expected_md5 ) {
			if ( $checked >= static::MAX_FILES ) {
				$truncated = true;
				$cap       = 'max_files';
				break;
			}

			$rel_file = (string) $rel_file;
			// wp-content is host-owned; the manifest's few wp-content entries
			// (akismet, default themes) churn legitimately — out of scope.
			if ( 0 === strpos( $rel_file, 'wp-content/' ) ) {
				continue;
			}

			$path = $base . $rel_file;
			$checked++;

			// Ancestor discipline: if any directory on the path (wp-includes
			// itself, or an intermediate dir) is a symlink, hashing the file
			// would follow it and read OUTSIDE the tree — the symlinked
			// ancestor is the finding.
			$linked_ancestor = $this->linked_ancestor( $base, $rel_file );
			if ( '' !== $linked_ancestor ) {
				if ( ! isset( $reported_ancestors[ $linked_ancestor ] ) ) {
					$reported_ancestors[ $linked_ancestor ] = true;
					$special[]                              = array(
						'file' => $linked_ancestor,
						'kind' => 'symlink',
					);
				}
				continue;
			}

			// lstat discipline: never hash (or follow) anything that is not a
			// regular file — a symlink/FIFO/device AT a core path is itself a
			// finding, and hashing it could block or read unbounded input.
			$stat_kind = $this->special_kind( $path );
			if ( 'absent' === $stat_kind ) {
				$missing[] = $rel_file;
				continue;
			}
			if ( '' !== $stat_kind ) {
				$special[] = array(
					'file' => $rel_file,
					'kind' => $stat_kind,
				);
				continue;
			}

			$md5 = md5_file( $path );
			if ( false === $md5 || strtolower( $md5 ) !== strtolower( (string) $expected_md5 ) ) {
				$modified[] = array(
					'file'         => $rel_file,
					'expected_md5' => (string) $expected_md5,
				);
			}
		}

		// Unexpected files: manifest-absent entries under the two core dirs
		// plus directly beneath the WordPress root (classic implant homes).
		$unexpected = array();
		$root_extra = array();
		$entries    = 0;

		foreach ( array( 'wp-admin', 'wp-includes' ) as $dir ) {
			$this->walk_unexpected( $base, $dir, $manifest, $unexpected, $special, $entries, $truncated, $cap );
		}
		$this->scan_root( $base, $manifest, $unexpected, $root_extra, $entries, $truncated, $cap );

		return array(
			'modified'   => $modified,
			'missing'    => $missing,
			'unexpected' => $unexpected,
			'special'    => $special,
			'root_extra' => $root_extra,
			'coverage'   => array(
				// In-scope only: the manifest's wp-content entries are skipped
				// by design, and counting them would make a COMPLETE scan look
				// partial (expected > checked with truncated=false).
				'files_expected' => $in_scope,
				'files_checked'  => $checked,
				'truncated'      => $truncated,
				'cap'            => $truncated ? $cap : '',
			),
		);
	}

	/**
	 * Fetches the official checksum manifest over HTTPS ONLY.
	 *
	 * Core's own get_core_checksums() is deliberately not used: on HTTPS
	 * failure it silently retries plaintext HTTP, letting an on-path attacker
	 * serve a forged manifest that green-lights compromised files. An HTTPS
	 * failure here is manifest_unavailable — never a downgrade.
	 *
	 * @param string $version WordPress version.
	 * @param string $locale  Locale.
	 * @return array|null Map of relative file → md5, or null when unavailable.
	 */
	protected function fetch_manifest( $version, $locale ) {
		/**
		 * Test/override seam: supply a manifest directly (fixtures) or null to
		 * proceed with the live HTTPS fetch.
		 *
		 * @param array|null $manifest Manifest override.
		 * @param string     $version  WordPress version.
		 * @param string     $locale   Locale.
		 */
		$override = apply_filters( 'aura_worker_core_checksums_manifest', null, $version, $locale );
		if ( null !== $override ) {
			return is_array( $override ) ? $override : null;
		}

		$url = add_query_arg(
			array(
				'version' => rawurlencode( $version ),
				'locale'  => rawurlencode( $locale ),
			),
			'https://api.wordpress.org/core/checksums/1.0/'
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 15,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['checksums'] ) || ! is_array( $body['checksums'] ) ) {
			return null;
		}

		return $body['checksums'];
	}

	/**
	 * Base path override seam (fixtures point this at a temp tree).
	 *
	 * @return string Trailing-slashed base path.
	 */
	protected function base_path() {
		$base = apply_filters( 'aura_worker_core_checksums_base', ABSPATH );
		return rtrim( (string) $base, '/' ) . '/';
	}

	/** @var array<string,bool> Per-run cache of directory-is-symlink checks. */
	private $ancestor_link_cache = array();

	/**
	 * Returns the first symlinked ancestor directory of a relative file path,
	 * or '' when the whole chain is real directories.
	 *
	 * @param string $base     Trailing-slashed base path.
	 * @param string $rel_file Relative file path.
	 * @return string Relative directory that is a symlink, or ''.
	 */
	private function linked_ancestor( $base, $rel_file ) {
		$parts = explode( '/', $rel_file );
		array_pop( $parts ); // The file itself is classified separately.
		$prefix = '';
		foreach ( $parts as $part ) {
			$prefix = ( '' === $prefix ) ? $part : $prefix . '/' . $part;
			if ( ! isset( $this->ancestor_link_cache[ $prefix ] ) ) {
				$this->ancestor_link_cache[ $prefix ] = is_link( $base . $prefix );
			}
			if ( $this->ancestor_link_cache[ $prefix ] ) {
				return $prefix;
			}
		}
		return '';
	}

	/**
	 * Classifies a path: '' = regular file, 'absent', or a special kind.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	private function special_kind( $path ) {
		// lstat: never follow a symlink at a core path.
		if ( is_link( $path ) ) {
			return 'symlink';
		}
		if ( ! file_exists( $path ) ) {
			return 'absent';
		}
		if ( ! is_file( $path ) ) {
			return is_dir( $path ) ? 'dir_at_file_path' : 'special';
		}
		$size = filesize( $path );
		if ( false !== $size && $size > static::MAX_FILE_BYTES ) {
			return 'oversized';
		}
		return '';
	}

	/**
	 * Walks one core directory reporting files absent from the manifest.
	 *
	 * @param string $base       Base path.
	 * @param string $dir        Relative directory (wp-admin / wp-includes).
	 * @param array  $manifest   Manifest map.
	 * @param array  $unexpected Accumulator (by reference).
	 * @param array  $special    Accumulator (by reference).
	 * @param int    $entries    Entry counter (by reference).
	 * @param bool   $truncated  Truncation flag (by reference).
	 * @param string $cap        Tripped-cap label (by reference).
	 */
	private function walk_unexpected( $base, $dir, $manifest, &$unexpected, &$special, &$entries, &$truncated, &$cap ) {
		$abs = $base . $dir;
		if ( ! is_dir( $abs ) || is_link( $abs ) ) {
			return;
		}

		$stack = array( $dir );
		while ( ! empty( $stack ) ) {
			$rel_dir = array_pop( $stack );
			$handle  = @opendir( $base . $rel_dir );
			if ( false === $handle ) {
				continue;
			}
			while ( false !== ( $entry = readdir( $handle ) ) ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				if ( ++$entries > static::MAX_ENTRIES ) {
					$truncated = true;
					$cap       = 'max_entries';
					closedir( $handle );
					return;
				}
				$rel  = $rel_dir . '/' . $entry;
				$path = $base . $rel;

				if ( is_link( $path ) ) {
					$special[] = array(
						'file' => $rel,
						'kind' => 'symlink',
					);
					continue;
				}
				if ( is_dir( $path ) ) {
					$stack[] = $rel;
					continue;
				}
				if ( ! isset( $manifest[ $rel ] ) ) {
					$unexpected[] = array(
						'file'  => $rel,
						'size'  => (int) @filesize( $path ),
						'mtime' => (int) @filemtime( $path ),
					);
				}
			}
			closedir( $handle );
		}
	}

	/**
	 * Scans files directly beneath the WordPress root.
	 *
	 * @param string $base       Base path.
	 * @param array  $manifest   Manifest map.
	 * @param array  $unexpected Accumulator (by reference).
	 * @param array  $root_extra Accumulator (by reference).
	 * @param int    $entries    Entry counter (by reference).
	 * @param bool   $truncated  Truncation flag (by reference).
	 * @param string $cap        Tripped-cap label (by reference).
	 */
	private function scan_root( $base, $manifest, &$unexpected, &$root_extra, &$entries, &$truncated, &$cap ) {
		$handle = @opendir( $base );
		if ( false === $handle ) {
			return;
		}
		while ( false !== ( $entry = readdir( $handle ) ) ) {
			if ( '.' === $entry || '..' === $entry || 'wp-admin' === $entry || 'wp-includes' === $entry ) {
				continue;
			}
			if ( ++$entries > static::MAX_ENTRIES ) {
				$truncated = true;
				$cap       = 'max_entries';
				break;
			}
			$path = $base . $entry;

			if ( in_array( $entry, self::ROOT_ALLOWLIST, true ) ) {
				$root_extra[] = $entry;
				continue;
			}
			if ( is_link( $path ) ) {
				$unexpected[] = array(
					'file'  => $entry,
					'size'  => 0,
					'mtime' => 0,
				);
				continue;
			}
			if ( is_dir( $path ) ) {
				// Non-core root directories (uploads relocations, host dirs)
				// are out of scope for a file-integrity audit.
				continue;
			}
			if ( ! isset( $manifest[ $entry ] ) ) {
				$unexpected[] = array(
					'file'  => $entry,
					'size'  => (int) @filesize( $path ),
					'mtime' => (int) @filemtime( $path ),
				);
			}
		}
		closedir( $handle );
	}
}
