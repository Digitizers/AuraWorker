<?php
/**
 * Plugin rollback class for SiteAgent.
 *
 * Creates zip backups of plugin directories and restores them on demand.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Aura_Worker_Rollback {

	/**
	 * Directory where backups are stored.
	 *
	 * @var string
	 */
	private $backup_dir;

	/**
	 * Constructor — ensures the backup directory exists and is protected.
	 */
	public function __construct() {
		$this->backup_dir = WP_CONTENT_DIR . '/aura-backups/';
		if ( ! file_exists( $this->backup_dir ) ) {
			wp_mkdir_p( $this->backup_dir );

			// Protecting the directory is best-effort and must never end the
			// request (#78, Codex round-13). `WP_Filesystem()` returns false — and
			// leaves `$wp_filesystem` null — when no transport initialises, which
			// is exactly the FTP/SSH-managed site a caller written to continue
			// with `backed_up: false` was written for. Dereferencing null here
			// fatalled the self-update before its backup could even fail.
			global $wp_filesystem;
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			$ready = WP_Filesystem() && is_object( $wp_filesystem ) && method_exists( $wp_filesystem, 'put_contents' );
			$chmod = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
			foreach ( array( '.htaccess' => 'Deny from all', 'index.php' => '<?php // Silence is golden.' ) as $name => $body ) {
				if ( $ready ) {
					$wp_filesystem->put_contents( $this->backup_dir . $name, $body, $chmod );
				} else {
					@file_put_contents( $this->backup_dir . $name, $body ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				}
			}
		}
	}

	/**
	 * Create a zip backup of a plugin directory.
	 *
	 * @param string $plugin_slug The plugin folder name (e.g. "akismet").
	 * @return array { success: bool, backup_path?: string, error?: string }
	 */
	public function backup_plugin( $plugin_slug ) {
		$plugin_dir = WP_PLUGIN_DIR . '/' . $plugin_slug;
		if ( ! is_dir( $plugin_dir ) ) {
			return array( 'success' => false, 'error' => "Plugin directory not found: $plugin_slug" );
		}

		// ext-zip is OPTIONAL in PHP. Without this guard `new ZipArchive()`
		// raises an uncaught Error and the whole request dies — so a caller
		// written to carry on with `backed_up: false` never gets the chance, and
		// the endpoint fatals instead (#77, Codex round-1 P1). Answer the same
		// shape every other failure here answers.
		if ( ! class_exists( 'ZipArchive' ) ) {
			return array( 'success' => false, 'error' => 'ZipArchive is not available on this site' );
		}

		$timestamp   = gmdate( 'Y-m-d_H-i-s' );
		$backup_path = $this->backup_dir . $plugin_slug . '_' . $timestamp . '.zip';

		$zip = new ZipArchive();
		if ( $zip->open( $backup_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
			return array( 'success' => false, 'error' => 'Failed to create zip archive' );
		}

		// An archive is only a backup if everything got in AND it closed. Both
		// were ignored, so a partially populated but perfectly readable zip was
		// handed back as usable — and `restore_plugin()` would then delete the
		// installed directory, extract the few entries that made it, and report
		// a successful rollback with most of the old build missing (#77, Codex
		// round-3).
		// Traversal itself can THROW — an unreadable subtree, a directory that
		// vanishes mid-walk — and an exception here escaped the documented
		// "unsuccessful backup" contract entirely: the request died before the
		// upgrader ran, so a caller written to continue with `backed_up: false`
		// never got the chance, and the site could not self-update until the
		// filesystem was repaired (#77, Codex round-6 P1). Every way this can
		// fail must come out the same door.
		try {
			$complete = $this->add_directory_to_zip( $zip, $plugin_dir, $plugin_slug );
		} catch ( Throwable $e ) {
			$complete = false;
		}
		$closed = $zip->close();

		if ( ! $complete || ! $closed ) {
			// Do not leave a plausible-looking archive lying around for a later
			// restore to trust.
			if ( file_exists( $backup_path ) ) {
				wp_delete_file( $backup_path );
			}
			return array(
				'success' => false,
				'error'   => 'Backup archive is incomplete — not every file could be added',
			);
		}

		return array( 'success' => true, 'backup_path' => $backup_path );
	}

	/**
	 * Restore a plugin from a backup zip file.
	 *
	 * @param string $plugin_slug  The plugin folder name.
	 * @param string $backup_path  Absolute path to the backup zip.
	 * @return array { success: bool, error?: string }
	 */
	public function restore_plugin( $plugin_slug, $backup_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return array( 'success' => false, 'error' => 'ZipArchive is not available on this site' );
		}
		if ( ! file_exists( $backup_path ) ) {
			return array( 'success' => false, 'error' => 'Backup file not found' );
		}
		$plugin_dir = WP_PLUGIN_DIR . '/' . $plugin_slug;

		$zip = new ZipArchive();
		if ( $zip->open( $backup_path ) !== true ) {
			return array( 'success' => false, 'error' => 'Failed to open backup archive' );
		}

		// Open the archive BEFORE deleting anything. The old order deleted the
		// directory first and then discovered it could not read the backup —
		// turning a recoverable state into an empty one.
		//
		// The deletion must SUCCEED before extracting (#77, Codex round-2 P1).
		// If the directory survives but its files stay writable, `extractTo()`
		// happily overwrites the ones the backup contains and reports success —
		// while every file the broken release ADDED is still sitting there. That
		// is a half-restored plugin reported as a clean rollback. Better to
		// refuse and say so than to claim a recovery that did not happen.
		if ( is_dir( $plugin_dir ) && ! $this->delete_directory( $plugin_dir ) ) {
			$zip->close();
			return array(
				'success' => false,
				'error'   => 'Could not remove the installed plugin directory before restoring',
			);
		}

		// `extractTo()` returns false when it cannot write — the ordinary case
		// being a site whose PHP process does not own WP_PLUGIN_DIR because
		// WordPress updates over FTP/SSH. Ignoring it reported a successful
		// restore after deleting the directory, so a caller could claim
		// `rolled_back: true` with the plugin missing (#77, Codex round-1 P1).
		$extracted = $zip->extractTo( WP_PLUGIN_DIR );
		$closed    = $zip->close();

		if ( ! $extracted || ! $closed ) {
			return array(
				'success' => false,
				'error'   => 'Failed to extract the backup archive — the plugin directory may be incomplete',
			);
		}

		// Files on disk are not what PHP runs. The failed release was already
		// compiled by the loopback probe, and on a server with
		// `opcache.validate_timestamps=0` (or a long revalidation window) the
		// workers keep executing that cached broken code no matter what these
		// bytes say — so "restored" would be true of the filesystem and false of
		// the site (#77, Codex round-3). Extracting directly bypasses the
		// per-file invalidation WordPress's own upgrader does.
		$this->invalidate_opcache( $plugin_dir );

		return array( 'success' => true );
	}

	/**
	 * List available backups, optionally filtered by plugin slug.
	 *
	 * Results are sorted newest-first.
	 *
	 * @param string|null $plugin_slug Filter to a specific plugin, or null for all.
	 * @return array List of backup records.
	 */
	public function list_backups( $plugin_slug = null ) {
		$backups = array();
		$files   = glob( $this->backup_dir . '*.zip' );
		if ( ! $files ) {
			return $backups;
		}

		foreach ( $files as $file ) {
			$filename = basename( $file );
			if ( preg_match( '/^(.+)_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\.zip$/', $filename, $matches ) ) {
				$slug = $matches[1];
				if ( $plugin_slug && $slug !== $plugin_slug ) {
					continue;
				}
				$backups[] = array(
					'plugin_slug' => $slug,
					'timestamp'   => str_replace( '_', ' ', $matches[2] ),
					'filename'    => $filename,
					'size_kb'     => round( filesize( $file ) / 1024, 1 ),
					'path'        => $file,
				);
			}
		}
		usort( $backups, function( $a, $b ) {
			return strcmp( $b['timestamp'], $a['timestamp'] );
		} );
		return $backups;
	}

	/**
	 * Delete old backups, keeping only the most recent N per plugin.
	 *
	 * @param int $keep_per_plugin Number of backups to keep per plugin slug.
	 */
	public function cleanup_old_backups( $keep_per_plugin = 3 ) {
		$backups   = $this->list_backups();
		$by_plugin = array();
		foreach ( $backups as $backup ) {
			$by_plugin[ $backup['plugin_slug'] ][] = $backup;
		}
		foreach ( $by_plugin as $plugin_backups ) {
			foreach ( array_slice( $plugin_backups, $keep_per_plugin ) as $old ) {
				wp_delete_file( $old['path'] );
			}
		}
	}

	/**
	 * Recursively add a directory's contents to an open ZipArchive.
	 *
	 * A symlinked DIRECTORY is followed into its target and its contents
	 * archived under the link's path (#78, Codex round-15 P1). The previous
	 * iterator yielded the link as a directory but did not descend, so the
	 * archive held an empty directory and still called itself complete — and a
	 * restore then replaced that subtree with an empty one while the main-file
	 * check, which never looks there, reported `rolled_back: true`. A restore
	 * extracts real directories, so the target's contents ARE what has to be
	 * in the archive. A link back into an ancestor of the directory being
	 * walked cannot be represented and would never end; it makes the backup
	 * incomplete, which `backup_plugin()` reports and refuses to keep.
	 *
	 * @param ZipArchive $zip         Open zip archive instance.
	 * @param string     $dir         Absolute path to the source directory.
	 * @param string     $relative_to Prefix for zip entry paths.
	 * @param array      $chain       Real paths of the directories on the current
	 *                                descent, keyed by path — the loop guard.
	 * @return bool Whether EVERY entry went in.
	 */
	private function add_directory_to_zip( $zip, $dir, $relative_to, array $chain = array() ) {
		$real = realpath( $dir );
		if ( false === $real || '' === $real || isset( $chain[ $real ] ) || ! is_readable( $dir ) ) {
			return false;
		}
		$chain[ $real ] = true;
		$entries        = scandir( $dir );
		if ( false === $entries ) {
			return false;
		}
		$complete = true;
		foreach ( $entries as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$path  = $dir . '/' . $name;
			$entry = $relative_to . '/' . $name;
			if ( is_dir( $path ) ) {
				// `is_dir()` follows a link, so a symlinked directory arrives here
				// and is walked like any other; the chain stops it from looping.
				if ( ! $zip->addEmptyDir( $entry ) ) {
					$complete = false;
				}
				if ( ! $this->add_directory_to_zip( $zip, $path, $entry, $chain ) ) {
					$complete = false;
				}
				continue;
			}
			// `realpath()` answers false for a dangling symlink or a file that
			// vanished mid-traversal, and PHP 8 throws a ValueError if that reaches
			// `addFile()` — so the backup would fatal instead of reporting itself
			// incomplete, which is the opposite of the point. Record the gap and
			// keep going: one entry that did not go in makes this archive an
			// incomplete record of the build, and saying so is the difference
			// between a backup and a half-backup nobody knows is half.
			$real_file = realpath( $path );
			$added     = ( false === $real_file || '' === $real_file || ! is_file( $real_file ) )
				? false
				: $zip->addFile( $real_file, $entry );
			if ( ! $added ) {
				$complete = false;
			}
		}
		return $complete;
	}

	/**
	 * Drop compiled copies of every PHP file under a directory.
	 *
	 * Best-effort by nature: OPcache may be absent, disabled for CLI, or
	 * restricted — none of which is a reason to fail a restore that otherwise
	 * succeeded. What it must not do is stay silent about a stale cache it could
	 * have cleared.
	 *
	 * @param string $dir Absolute path just restored.
	 * @return void
	 */
	private function invalidate_opcache( $dir ) {
		if ( ! function_exists( 'opcache_invalidate' ) ) {
			return;
		}
		foreach ( $this->php_files_under( $dir ) as $file ) {
			@opcache_invalidate( $file, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Every PHP file under a directory, absolute paths.
	 *
	 * Split out from `invalidate_opcache()` so the part with judgement in it —
	 * WHICH files a restore has to drop from the cache — is testable. The
	 * invalidation itself is a one-line call the test runtime cannot observe,
	 * because PHP defines `opcache_invalidate()` even where OPcache is off, so
	 * a recording stub can never take its place.
	 *
	 * @param string $dir Absolute directory path.
	 * @return string[]
	 */
	private function php_files_under( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return array();
		}
		$out      = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
				$real = $file->getRealPath();
				if ( false !== $real && '' !== $real ) {
					$out[] = $real;
				}
			}
		}
		return $out;
	}

	/**
	 * Recursively delete a directory and all its contents.
	 *
	 * @param string $dir Absolute path to the directory to remove.
	 */
	private function delete_directory( $dir ) {
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		// `WP_Filesystem()` returns false — and leaves `$wp_filesystem` null —
		// when no transport initialises (FTP/SSH credentials not in wp-config).
		// The backup that brought us here was written by ZipArchive directly and
		// the extract that follows is direct too, so the one step between them
		// must not fatal reaching for a transport neither of them needed: a
		// restore that dies here leaves the plugin half-deleted and the caller
		// with no result at all (#78, Codex round-14 P1). Delete directly instead.
		if ( WP_Filesystem() && is_object( $wp_filesystem ) && method_exists( $wp_filesystem, 'delete' ) ) {
			$wp_filesystem->delete( $dir, true, 'd' );
		} else {
			$this->delete_directory_directly( $dir );
		}

		// Report on the DIRECTORY, not on the call. `delete()`'s own return is
		// not uniformly trustworthy across filesystem transports, and what the
		// caller needs to know is one fact it can verify: is it gone?
		return ! is_dir( $dir );
	}

	/**
	 * Recursive deletion with PHP's own primitives, for a site with no
	 * `WP_Filesystem` transport. Children first; a symlink is removed as a
	 * link, never followed. Reports nothing — `delete_directory()` judges the
	 * outcome by the one fact that matters, whether the directory is gone.
	 *
	 * @param string $dir Absolute path to the directory to remove.
	 * @return void
	 */
	private function delete_directory_directly( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $entry ) {
			$path = $entry->getPathname();
			if ( $entry->isDir() && ! $entry->isLink() ) {
				@rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			} else {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}
