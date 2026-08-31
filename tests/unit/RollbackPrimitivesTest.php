<?php
/**
 * Aura_Worker_Rollback's two primitives must report what actually happened
 * (SiteAgent #77, Codex round-1). Both used to answer in ways a caller could
 * not act on:
 *
 *  - `backup_plugin()` constructed a ZipArchive unconditionally, so on a site
 *    without ext-zip it raised an uncaught Error and killed the request —
 *    a caller written to continue with `backed_up: false` never got the chance.
 *  - `restore_plugin()` deleted the plugin directory, ignored `extractTo()`'s
 *    return value, and reported success regardless — so "rolled back" could
 *    mean "the plugin is gone".
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class RollbackPrimitivesTest extends TestCase {

	private string $slug = 'sa-rollback-fixture';
	private string $dir;

	protected function setUp(): void {
		$this->dir = WP_PLUGIN_DIR . '/' . $this->slug;
		if ( ! is_dir( $this->dir ) ) {
			mkdir( $this->dir, 0777, true );
		}
		file_put_contents( $this->dir . '/main.php', 'ORIGINAL' );
	}

	protected function tearDown(): void {
		foreach ( glob( WP_CONTENT_DIR . '/aura-backups/' . $this->slug . '_*.zip' ) ?: array() as $f ) {
			unlink( $f );
		}
		@chmod( WP_PLUGIN_DIR, 0777 );
		if ( is_dir( $this->dir ) ) {
			foreach ( scandir( $this->dir ) as $f ) {
				if ( '.' !== $f && '..' !== $f ) {
					unlink( $this->dir . '/' . $f );
				}
			}
			rmdir( $this->dir );
		}
	}

	public function test_a_real_backup_and_restore_round_trip_returns_the_original(): void {
		$rollback = new Aura_Worker_Rollback();
		$backup   = $rollback->backup_plugin( $this->slug );
		$this->assertTrue( $backup['success'] );

		file_put_contents( $this->dir . '/main.php', 'REPLACED' );
		$restore = $rollback->restore_plugin( $this->slug, $backup['backup_path'] );

		$this->assertTrue( $restore['success'] );
		$this->assertSame( 'ORIGINAL', file_get_contents( $this->dir . '/main.php' ) );
	}

	public function test_an_unreadable_archive_fails_without_destroying_what_is_there(): void {
		// The order matters: opening the archive BEFORE deleting means a backup
		// that turns out to be unreadable leaves the site as it was, instead of
		// converting a recoverable state into an empty one.
		$rollback = new Aura_Worker_Rollback();
		$bad      = WP_CONTENT_DIR . '/aura-backups/' . $this->slug . '_broken.zip';
		file_put_contents( $bad, 'not a zip at all' );

		$restore = $rollback->restore_plugin( $this->slug, $bad );

		$this->assertFalse( $restore['success'] );
		$this->assertTrue( is_dir( $this->dir ), 'the plugin directory must survive a failed restore' );
		$this->assertSame( 'ORIGINAL', file_get_contents( $this->dir . '/main.php' ) );
	}

	public function test_a_valid_archive_that_cannot_be_written_is_not_reported_as_restored(): void {
		// The case the `extractTo()` return value exists for, and the one a
		// corrupt-archive test does NOT reach — there `open()` fails first. The
		// real-world trigger is a site whose PHP process cannot write to
		// WP_PLUGIN_DIR because WordPress updates over FTP/SSH.
		$rollback = new Aura_Worker_Rollback();
		$backup   = $rollback->backup_plugin( $this->slug );
		$this->assertTrue( $backup['success'] );

		// Remove the target first: with the directory still present and
		// writable, extraction just overwrites the files inside it and a
		// read-only PARENT changes nothing. The failure being modelled is
		// "cannot create the plugin directory at all".
		unlink( $this->dir . '/main.php' );
		rmdir( $this->dir );

		$perms = fileperms( WP_PLUGIN_DIR ) & 0777;
		chmod( WP_PLUGIN_DIR, 0555 );
		// Root ignores the mode bits, so prove the environment actually refuses
		// a write before asserting on a failure that would not happen.
		$probe = @file_put_contents( WP_PLUGIN_DIR . '/.sa-write-probe', 'x' );
		if ( false !== $probe ) {
			@unlink( WP_PLUGIN_DIR . '/.sa-write-probe' );
			chmod( WP_PLUGIN_DIR, $perms );
			$this->markTestSkipped( 'filesystem does not enforce the read-only mode (running as root?)' );
		}

		try {
			$restore = @$rollback->restore_plugin( $this->slug, $backup['backup_path'] );
			$this->assertFalse( $restore['success'], 'a restore that could not write must not report success' );
			$this->assertNotEmpty( $restore['error'] );
		} finally {
			chmod( WP_PLUGIN_DIR, $perms );
		}
	}

	public function test_a_missing_backup_file_is_refused(): void {
		$rollback = new Aura_Worker_Rollback();
		$restore  = $rollback->restore_plugin( $this->slug, WP_CONTENT_DIR . '/aura-backups/nope.zip' );

		$this->assertFalse( $restore['success'] );
		$this->assertTrue( is_dir( $this->dir ) );
	}

	public function test_backing_up_a_directory_that_is_not_there_fails_cleanly(): void {
		$rollback = new Aura_Worker_Rollback();
		$res      = $rollback->backup_plugin( 'sa-no-such-plugin' );

		$this->assertFalse( $res['success'] );
		$this->assertNotEmpty( $res['error'] );
	}
}
