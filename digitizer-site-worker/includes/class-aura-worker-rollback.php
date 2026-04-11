<?php
/**
 * Plugin rollback class for Aura Worker.
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
			file_put_contents( $this->backup_dir . '.htaccess', 'Deny from all' );
			file_put_contents( $this->backup_dir . 'index.php', '<?php // Silence is golden.' );
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

		$timestamp   = date( 'Y-m-d_H-i-s' );
		$backup_path = $this->backup_dir . $plugin_slug . '_' . $timestamp . '.zip';

		$zip = new ZipArchive();
		if ( $zip->open( $backup_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
			return array( 'success' => false, 'error' => 'Failed to create zip archive' );
		}

		$this->add_directory_to_zip( $zip, $plugin_dir, $plugin_slug );
		$zip->close();

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
		if ( ! file_exists( $backup_path ) ) {
			return array( 'success' => false, 'error' => 'Backup file not found' );
		}
		$plugin_dir = WP_PLUGIN_DIR . '/' . $plugin_slug;
		if ( is_dir( $plugin_dir ) ) {
			$this->delete_directory( $plugin_dir );
		}

		$zip = new ZipArchive();
		if ( $zip->open( $backup_path ) !== true ) {
			return array( 'success' => false, 'error' => 'Failed to open backup archive' );
		}
		$zip->extractTo( WP_PLUGIN_DIR );
		$zip->close();
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
				@unlink( $old['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}

	/**
	 * Recursively add a directory's contents to an open ZipArchive.
	 *
	 * @param ZipArchive $zip         Open zip archive instance.
	 * @param string     $dir         Absolute path to the source directory.
	 * @param string     $relative_to Prefix for zip entry paths.
	 */
	private function add_directory_to_zip( $zip, $dir, $relative_to ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $iterator as $file ) {
			$path = $relative_to . '/' . $iterator->getSubPathName();
			$file->isDir() ? $zip->addEmptyDir( $path ) : $zip->addFile( $file->getRealPath(), $path );
		}
	}

	/**
	 * Recursively delete a directory and all its contents.
	 *
	 * @param string $dir Absolute path to the directory to remove.
	 */
	private function delete_directory( $dir ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $file ) {
			$file->isDir() ? rmdir( $file->getRealPath() ) : unlink( $file->getRealPath() );
		}
		rmdir( $dir );
	}
}
