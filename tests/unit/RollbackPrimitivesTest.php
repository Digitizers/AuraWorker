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

	public function test_a_directory_that_cannot_be_removed_is_not_reported_as_restored(): void {
		// Codex round-2: if the installed directory survives but its files stay
		// writable, `extractTo()` overwrites the ones the backup contains and
		// reports success — while every file the broken release ADDED is still
		// on disk. That is a half-restored plugin described as a clean rollback.
		$rollback = new Aura_Worker_Rollback();
		$backup   = $rollback->backup_plugin( $this->slug );
		$this->assertTrue( $backup['success'] );

		// A file the backup does not contain: what a broken release left behind.
		file_put_contents( $this->dir . '/stray-from-broken-build.php', 'STRAY' );

		// Read-only PARENT: the directory entry cannot be removed, while the
		// files inside it remain writable.
		$perms = fileperms( WP_PLUGIN_DIR ) & 0777;
		chmod( WP_PLUGIN_DIR, 0555 );
		if ( false !== @file_put_contents( WP_PLUGIN_DIR . '/.sa-probe', 'x' ) ) {
			@unlink( WP_PLUGIN_DIR . '/.sa-probe' );
			chmod( WP_PLUGIN_DIR, $perms );
			$this->markTestSkipped( 'filesystem does not enforce the read-only mode (running as root?)' );
		}

		try {
			$restore = @$rollback->restore_plugin( $this->slug, $backup['backup_path'] );
			$this->assertFalse( $restore['success'], 'a directory that could not be removed is not a rollback' );
		} finally {
			chmod( WP_PLUGIN_DIR, $perms );
			@unlink( $this->dir . '/stray-from-broken-build.php' );
		}
	}

	public function test_the_files_a_restore_must_drop_from_the_cache_are_every_php_file_it_wrote(): void {
		// Files on disk are not what PHP runs: the failed release was already
		// compiled by the loopback probe, and where `opcache.validate_timestamps`
		// is off the workers keep executing that cached code however correct the
		// bytes are (Codex round-3).
		//
		// This pins WHICH files a restore has to drop — the part with judgement
		// in it. The `opcache_invalidate()` call itself is deliberately NOT
		// covered: PHP defines that function even where OPcache is disabled, so
		// a recording stub can never stand in for it, and a test that asserted
		// around that would be passing for a reason unrelated to its claim.
		mkdir( $this->dir . '/sub', 0777, true );
		file_put_contents( $this->dir . '/sub/inner.php', '<?php' );
		file_put_contents( $this->dir . '/readme.txt', 'not php' );

		$m = new ReflectionMethod( Aura_Worker_Rollback::class, 'php_files_under' );
		$m->setAccessible( true );
		$found = $m->invoke( new Aura_Worker_Rollback(), $this->dir );

		sort( $found );
		$this->assertSame(
			array( realpath( $this->dir . '/main.php' ), realpath( $this->dir . '/sub/inner.php' ) ),
			$found,
			'every PHP file it restored, and nothing else'
		);

		unlink( $this->dir . '/sub/inner.php' );
		rmdir( $this->dir . '/sub' );
		unlink( $this->dir . '/readme.txt' );
	}

	public function test_a_missing_backup_file_is_refused(): void {
		$rollback = new Aura_Worker_Rollback();
		$restore  = $rollback->restore_plugin( $this->slug, WP_CONTENT_DIR . '/aura-backups/nope.zip' );

		$this->assertFalse( $restore['success'] );
		$this->assertTrue( is_dir( $this->dir ) );
	}

	public function test_an_archive_that_could_not_take_every_file_is_not_offered_as_a_backup(): void {
		// A partially populated but perfectly readable zip used to come back as
		// `success: true`, and a later restore would delete the installed
		// directory, extract the few entries that made it, and call that a
		// rollback (Codex round-3). An incomplete archive is not a backup, and
		// it must not be left on disk for something else to trust.
		$rollback = new Aura_Worker_Rollback();

		// A dangling symlink is the honest version of "a file disappeared during
		// traversal": the iterator yields it, `getRealPath()` answers false, and
		// `addFile()` cannot take it.
		$dangling = $this->dir . '/vanished.php';
		symlink( $this->dir . '/definitely-not-here.php', $dangling );

		try {
			$res = $rollback->backup_plugin( $this->slug );
			$this->assertFalse( $res['success'] );
			$this->assertSame(
				array(),
				glob( WP_CONTENT_DIR . '/aura-backups/' . $this->slug . '_*.zip' ) ?: array(),
				'an incomplete archive must not be left behind'
			);
		} finally {
			unlink( $dangling );
		}
	}

	public function test_a_traversal_that_throws_comes_out_as_an_unsuccessful_backup_not_an_exception(): void {
		// An unreadable subtree makes RecursiveDirectoryIterator throw on
		// descent. That exception used to escape the "unsuccessful backup"
		// contract and kill the request before the upgrader ran (Codex round-6).
		$sub = $this->dir . '/locked';
		mkdir( $sub, 0777, true );
		file_put_contents( $sub . '/inner.php', '<?php' );
		chmod( $sub, 0000 );
		if ( @scandir( $sub ) !== false ) {
			chmod( $sub, 0777 );
			unlink( $sub . '/inner.php' );
			rmdir( $sub );
			$this->markTestSkipped( 'filesystem does not enforce the mode (running as root?)' );
		}

		try {
			$res = ( new Aura_Worker_Rollback() )->backup_plugin( $this->slug );
			$this->assertFalse( $res['success'] );
			$this->assertSame( array(), glob( WP_CONTENT_DIR . '/aura-backups/' . $this->slug . '_*.zip' ) ?: array() );
		} finally {
			chmod( $sub, 0777 );
			unlink( $sub . '/inner.php' );
			rmdir( $sub );
		}
	}

	public function test_construction_survives_a_filesystem_transport_that_will_not_initialise(): void {
		// Codex round-13: with no aura-backups dir yet and WP_Filesystem() failing
		// (FTP/SSH site, no credentials), the constructor dereferenced a null
		// $wp_filesystem and fatalled — before backup_plugin() could even report
		// itself unsuccessful. Directory protection is best-effort; construction
		// and the backup itself must still work.
		$dir = WP_CONTENT_DIR . '/aura-backups';
		foreach ( glob( $dir . '/*' ) ?: array() as $f ) { @unlink( $f ); }
		foreach ( glob( $dir . '/.*' ) ?: array() as $f ) { if ( is_file( $f ) ) { @unlink( $f ); } }
		@rmdir( $dir );
		$GLOBALS['_wp_filesystem_unavailable'] = true;
		$GLOBALS['wp_filesystem'] = null;

		try {
			$rollback = new Aura_Worker_Rollback();
			$backup   = $rollback->backup_plugin( $this->slug );
			$this->assertTrue( $backup['success'], 'the backup itself needs ZipArchive, not a WP_Filesystem transport' );
		} finally {
			unset( $GLOBALS['_wp_filesystem_unavailable'] );
			$GLOBALS['wp_filesystem'] = null;
		}
	}

	public function test_backing_up_a_directory_that_is_not_there_fails_cleanly(): void {
		$rollback = new Aura_Worker_Rollback();
		$res      = $rollback->backup_plugin( 'sa-no-such-plugin' );

		$this->assertFalse( $res['success'] );
		$this->assertNotEmpty( $res['error'] );
	}

	public function test_a_restore_works_when_no_filesystem_transport_initialises(): void {
		// Round 13 let construction and the backup survive a site whose
		// WP_Filesystem() returns false. The restore then reached
		// delete_directory(), which dereferenced the null $wp_filesystem and
		// fatalled — after the backup was made, at the moment it was needed
		// (Codex round-14 P1). The archive is written and read directly; the
		// deletion between them has to be direct too.
		$rollback = new Aura_Worker_Rollback();
		$backup   = $rollback->backup_plugin( $this->slug );
		$this->assertTrue( $backup['success'] );
		file_put_contents( $this->dir . '/main.php', 'BROKEN' );
		file_put_contents( $this->dir . '/added-by-broken-build.php', 'x' );
		mkdir( $this->dir . '/sub' );
		file_put_contents( $this->dir . '/sub/deep.php', 'y' );

		$GLOBALS['_wp_filesystem_unavailable'] = true;
		$GLOBALS['wp_filesystem'] = null;
		try {
			$res = $rollback->restore_plugin( $this->slug, $backup['backup_path'] );
		} finally {
			unset( $GLOBALS['_wp_filesystem_unavailable'] );
			$GLOBALS['wp_filesystem'] = null;
			if ( is_file( $this->dir . '/sub/deep.php' ) ) {
				unlink( $this->dir . '/sub/deep.php' );
			}
			if ( is_dir( $this->dir . '/sub' ) ) {
				rmdir( $this->dir . '/sub' );
			}
		}

		$this->assertTrue( $res['success'], $res['error'] ?? '' );
		$this->assertSame( 'ORIGINAL', file_get_contents( $this->dir . '/main.php' ) );
		$this->assertFileDoesNotExist( $this->dir . '/added-by-broken-build.php', 'a file the broken build added must not survive the restore' );
		$this->assertDirectoryDoesNotExist( $this->dir . '/sub' );
	}

	public function test_a_symlinked_directory_is_archived_by_its_contents_and_restored_whole(): void {
		// The iterator yielded a symlinked directory as a directory and did not
		// descend into it, so the archive held an empty dir and called itself
		// complete; a restore then put an EMPTY directory where plugin code had
		// been, and the main-file check — which never looks there — still said
		// `rolled_back: true` (Codex round-15 P1). A restore extracts real
		// directories, so the target's contents are what the backup must hold.
		$target = sys_get_temp_dir() . '/sa-linked-target-' . getmypid();
		mkdir( $target . '/deeper', 0777, true );
		file_put_contents( $target . '/inner.php', 'LINKED CODE' );
		file_put_contents( $target . '/deeper/leaf.php', 'LEAF' );
		symlink( $target, $this->dir . '/linked' );
		$rollback = new Aura_Worker_Rollback();

		try {
			$backup = $rollback->backup_plugin( $this->slug );
			$this->assertTrue( $backup['success'], $backup['error'] ?? '' );

			// The broken build removed the link; the restore has to bring the
			// code back, not an empty directory named after it.
			unlink( $this->dir . '/linked' );
			file_put_contents( $this->dir . '/main.php', 'BROKEN' );
			$res = $rollback->restore_plugin( $this->slug, $backup['backup_path'] );

			$this->assertTrue( $res['success'], $res['error'] ?? '' );
			$this->assertSame( 'ORIGINAL', file_get_contents( $this->dir . '/main.php' ) );
			$this->assertSame( 'LINKED CODE', @file_get_contents( $this->dir . '/linked/inner.php' ), 'the linked directory came back empty' );
			$this->assertSame( 'LEAF', @file_get_contents( $this->dir . '/linked/deeper/leaf.php' ) );
		} finally {
			foreach ( array( $this->dir . '/linked', $target ) as $d ) {
				if ( is_link( $d ) ) {
					unlink( $d );
				} elseif ( is_dir( $d ) ) {
					foreach ( array( '/deeper/leaf.php', '/inner.php' ) as $f ) {
						if ( is_file( $d . $f ) ) {
							unlink( $d . $f );
						}
					}
					if ( is_dir( $d . '/deeper' ) ) {
						rmdir( $d . '/deeper' );
					}
					rmdir( $d );
				}
			}
		}
	}

	public function test_a_symlink_looping_back_into_the_plugin_makes_the_backup_incomplete_not_endless(): void {
		// Following links is what the fix above does; a link to an ancestor of
		// the directory being walked would then never end. It cannot be put in
		// an archive faithfully, so the backup reports itself incomplete and is
		// not kept — the same door every other gap comes out of.
		symlink( $this->dir, $this->dir . '/loop' );
		try {
			$res = ( new Aura_Worker_Rollback() )->backup_plugin( $this->slug );
			$this->assertFalse( $res['success'] );
			$this->assertSame( array(), glob( WP_CONTENT_DIR . '/aura-backups/' . $this->slug . '_*.zip' ) ?: array() );
		} finally {
			unlink( $this->dir . '/loop' );
		}
	}

	public function test_a_restore_without_a_transport_does_not_delete_through_a_symlinked_plugin_root(): void {
		// The direct-deletion fallback checked `isLink()` on every CHILD and never
		// on the root, so a plugin directory that is itself a symlink — a common
		// deployment layout — was walked into and its TARGET emptied: a shared
		// checkout outside WP_PLUGIN_DIR destroyed by a rollback, and the link
		// itself left standing (Codex round-16 P1). The link goes as a link; the
		// target is not ours to touch.
		$target = sys_get_temp_dir() . '/sa-checkout-' . getmypid();
		mkdir( $target . '/inc', 0777, true );
		file_put_contents( $target . '/main.php', 'ORIGINAL' );
		file_put_contents( $target . '/inc/lib.php', 'SHARED' );
		unlink( $this->dir . '/main.php' );
		rmdir( $this->dir );
		symlink( $target, $this->dir );
		$rollback = new Aura_Worker_Rollback();

		try {
			$backup = $rollback->backup_plugin( $this->slug );
			$this->assertTrue( $backup['success'], $backup['error'] ?? '' );

			$GLOBALS['_wp_filesystem_unavailable'] = true;
			$GLOBALS['wp_filesystem'] = null;
			try {
				$res = $rollback->restore_plugin( $this->slug, $backup['backup_path'] );
			} finally {
				unset( $GLOBALS['_wp_filesystem_unavailable'] );
				$GLOBALS['wp_filesystem'] = null;
			}

			$this->assertSame( 'SHARED', @file_get_contents( $target . '/inc/lib.php' ), 'the symlink target was deleted through' );
			$this->assertSame( 'ORIGINAL', @file_get_contents( $target . '/main.php' ) );
			$this->assertFalse( is_link( $this->dir ), 'the link itself was left standing' );
			$this->assertTrue( $res['success'], $res['error'] ?? '' );
			$this->assertSame( 'ORIGINAL', @file_get_contents( $this->dir . '/main.php' ) );
		} finally {
			if ( is_link( $this->dir ) ) {
				unlink( $this->dir );
			}
			foreach ( array( $this->dir, $target ) as $d ) {
				if ( is_dir( $d ) && ! is_link( $d ) ) {
					foreach ( array( '/inc/lib.php', '/main.php' ) as $f ) {
						if ( is_file( $d . $f ) ) {
							unlink( $d . $f );
						}
					}
					if ( is_dir( $d . '/inc' ) ) {
						rmdir( $d . '/inc' );
					}
				}
			}
			if ( is_dir( $target ) ) {
				rmdir( $target );
			}
		}
	}
}
