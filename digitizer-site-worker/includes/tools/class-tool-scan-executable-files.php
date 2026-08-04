<?php
/**
 * MCP Tool: scan_executable_files
 *
 * Read-only observation scan of write-expected directories (uploads) for
 * files that should not be there: PHP/phar/executable files, .htaccess
 * overrides, and symlinks. Observations only — path, size, mtime — no
 * content heuristics and no "malware" verdicts.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Tool_Scan_Executable_Files extends Aura_Tool_Base {

	/** Max directory entries visited before the scan reports truncation. */
	const MAX_ENTRIES = 20000;

	/** Max findings returned. */
	const MAX_FINDINGS = 500;

	/** Extensions that do not belong in an uploads tree. */
	const EXECUTABLE_EXTENSIONS = array( 'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar', 'cgi', 'pl', 'sh', 'exe' );

	public function get_name() {
		return 'scan_executable_files';
	}

	public function get_description() {
		return 'Read-only scan of the uploads directory for files that should not live there: PHP/phar/executable files, .htaccess overrides, and symlinks (reported with their target, never followed). Returns observations only — path, size, mtime — with explicit scan coverage; makes no changes and renders no malware verdicts.';
	}

	public function get_parameters() {
		return array();
	}

	public function get_returns() {
		return array(
			'findings' => 'array — { file, kind: executable|htaccess|symlink, size, mtime, target? }',
			'coverage' => 'object — { total_seen, returned, truncated, cap } bounded-coverage contract; an empty findings list with truncated=true means "nothing found before the cap", never "clean"',
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
		$dirs = $this->scan_dirs();

		$findings  = array();
		$entries   = 0;
		$truncated = false;
		$cap       = '';

		foreach ( $dirs as $dir ) {
			if ( $truncated ) {
				break;
			}
			$this->walk( $dir, '', $findings, $entries, $truncated, $cap );
		}

		return array(
			'findings' => $findings,
			'coverage' => array(
				'total_seen' => $entries,
				'returned'   => count( $findings ),
				'truncated'  => $truncated,
				'cap'        => $truncated ? $cap : '',
			),
		);
	}

	/**
	 * Directories to scan. Defaults to the uploads basedir; filterable so
	 * fixtures point at a temp tree.
	 *
	 * @return string[]
	 */
	protected function scan_dirs() {
		$uploads = wp_get_upload_dir();
		$dirs    = array( (string) $uploads['basedir'] );

		/**
		 * Filters the directories the executable-file scan walks.
		 *
		 * @param string[] $dirs Absolute directory paths.
		 */
		return (array) apply_filters( 'aura_worker_scan_executable_dirs', $dirs );
	}

	/**
	 * Iterative bounded walk. lstat discipline: symlinks are REPORTED (with
	 * their target) and never followed — following one could leave the
	 * intended tree entirely or loop.
	 *
	 * @param string $base      Absolute base dir.
	 * @param string $rel_dir   Relative subdirectory ('' = base).
	 * @param array  $findings  Accumulator (by reference).
	 * @param int    $entries   Entry counter (by reference).
	 * @param bool   $truncated Truncation flag (by reference).
	 * @param string $cap       Tripped-cap label (by reference).
	 */
	private function walk( $base, $rel_dir, &$findings, &$entries, &$truncated, &$cap ) {
		$base  = rtrim( (string) $base, '/' );
		$stack = array( $rel_dir );

		while ( ! empty( $stack ) ) {
			$dir    = array_pop( $stack );
			$abs    = '' === $dir ? $base : $base . '/' . $dir;
			$handle = @opendir( $abs );
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
				if ( count( $findings ) >= static::MAX_FINDINGS ) {
					$truncated = true;
					$cap       = 'max_findings';
					closedir( $handle );
					return;
				}

				$rel  = ( '' === $dir ) ? $entry : $dir . '/' . $entry;
				$path = $base . '/' . $rel;

				if ( is_link( $path ) ) {
					// lstat metadata only: filemtime() would resolve the target,
					// and a target on a slow/unavailable mount can stall the scan
					// — the no-follow promise covers metadata too.
					$lstat      = @lstat( $path );
					$findings[] = array(
						'file'   => $rel,
						'kind'   => 'symlink',
						'size'   => 0,
						'mtime'  => is_array( $lstat ) && isset( $lstat['mtime'] ) ? (int) $lstat['mtime'] : 0,
						'target' => (string) @readlink( $path ),
					);
					continue; // Never follow.
				}
				if ( is_dir( $path ) ) {
					$stack[] = $rel;
					continue;
				}

				$lower = strtolower( $entry );
				$ext   = pathinfo( $lower, PATHINFO_EXTENSION );

				if ( '.htaccess' === $lower || in_array( $ext, self::EXECUTABLE_EXTENSIONS, true ) ) {
					$findings[] = array(
						'file'  => $rel,
						'kind'  => '.htaccess' === $lower ? 'htaccess' : 'executable',
						'size'  => (int) @filesize( $path ),
						'mtime' => (int) @filemtime( $path ),
					);
				}
			}
			closedir( $handle );
		}
	}
}
