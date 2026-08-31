<?php
/**
 * Self-update failure recovery (SiteAgent #77).
 *
 * `self_update()` used to download, verify, overwrite and reactivate — with no
 * backup, no verification that the site still booted, and nothing to restore.
 * `update_plugin_safely()` had given every OTHER plugin that cycle for
 * versions; the plugin that manages the site was the one updated without it.
 *
 * The check that makes this possible is the loopback: `run_health_check()`
 * begins with `wp_remote_get( home_url() )`, a SEPARATE process that loads the
 * new files. An in-process assertion after overwriting the running plugin
 * proves nothing, because the old code is already in memory and keeps
 * answering.
 *
 * These tests drive the real Aura_Worker_Rollback against a real temp plugin
 * directory, so the zip round trip is exercised rather than mocked, and assert
 * on the CONTENT on disk — the only thing that says a rollback actually
 * happened.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class SelfUpdateRecoveryTest extends TestCase {

	private string $slug = 'digitizer-site-worker';
	private string $dir;

	protected function setUp(): void {
		$this->dir = WP_PLUGIN_DIR . '/' . $this->slug;
		$this->rmdir( $this->dir );
		mkdir( $this->dir, 0777, true );
		file_put_contents( $this->dir . '/digitizer-site-worker.php', 'OLD BUILD' );

		$GLOBALS['_mutations']      = array();
		$GLOBALS['_wp_http_calls']  = array();
		$GLOBALS['_http_error']     = false;
		$GLOBALS['_http_response']  = array( 'response' => array( 'code' => 200 ), 'body' => '<html>ok</html>' );
		$GLOBALS['_install_result'] = true;
		$GLOBALS['_install_effect'] = function () {
			file_put_contents( $this->dir . '/digitizer-site-worker.php', 'NEW BUILD' );
		};
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_install_effect'], $GLOBALS['_install_result'] );
		$this->rmdir( $this->dir );
		foreach ( glob( WP_CONTENT_DIR . '/aura-backups/*.zip' ) ?: array() as $f ) {
			unlink( $f );
		}
	}

	private function rmdir( string $d ): void {
		if ( ! is_dir( $d ) ) {
			return;
		}
		foreach ( scandir( $d ) as $f ) {
			if ( '.' === $f || '..' === $f ) {
				continue;
			}
			$p = $d . '/' . $f;
			is_dir( $p ) ? $this->rmdir( $p ) : unlink( $p );
		}
		rmdir( $d );
	}

	private function onDisk(): string {
		$f = $this->dir . '/digitizer-site-worker.php';
		return is_file( $f ) ? (string) file_get_contents( $f ) : '';
	}

	private function selfUpdate(): array {
		$updater = new Aura_Worker_Updater();
		return $updater->self_update( 'https://github.com/Digitizers/SiteAgent/releases/download/v9.9.9/x.zip' );
	}

	public function test_a_healthy_update_keeps_the_new_build_and_reports_its_backup(): void {
		$res = $this->selfUpdate();

		$this->assertTrue( $res['success'] );
		$this->assertTrue( $res['backed_up'] );
		$this->assertTrue( $res['health_checked'] );
		$this->assertFalse( $res['rolled_back'] );
		$this->assertSame( 'NEW BUILD', $this->onDisk() );
	}

	public function test_the_health_check_is_a_real_request_to_the_site(): void {
		// If this ever became an in-process assertion it would be worthless
		// here: the running plugin's old code is already loaded and would keep
		// answering for a build that cannot boot.
		$this->selfUpdate();

		$this->assertNotEmpty( $GLOBALS['_wp_http_calls'] );
	}

	public function test_a_build_that_breaks_the_site_is_rolled_back(): void {
		$GLOBALS['_http_response'] = array( 'response' => array( 'code' => 500 ), 'body' => 'error' );

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertTrue( $res['rolled_back'] );
		$this->assertFalse( $res['healthy'] );
		// The only claim that matters: the previous build is back on disk.
		$this->assertSame( 'OLD BUILD', $this->onDisk() );
	}

	public function test_an_install_that_fails_partway_is_rolled_back(): void {
		// `install()` with overwrite_package deletes before it writes, so a
		// failure can leave the directory incomplete. This is the case the
		// backup most exists for.
		$GLOBALS['_install_result'] = false;
		$GLOBALS['_install_effect'] = function () {
			unlink( $this->dir . '/digitizer-site-worker.php' );
		};

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertTrue( $res['rolled_back'] );
		$this->assertSame( 'OLD BUILD', $this->onDisk() );
	}

	public function test_a_wp_error_install_is_rolled_back_too(): void {
		$GLOBALS['_install_result'] = new WP_Error( 'fs', 'filesystem exploded' );
		$GLOBALS['_install_effect'] = function () {
			unlink( $this->dir . '/digitizer-site-worker.php' );
		};

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertTrue( $res['rolled_back'] );
		$this->assertSame( 'OLD BUILD', $this->onDisk() );
	}

	public function test_an_unbackable_site_still_updates_and_says_it_had_no_way_back(): void {
		// The #472 lesson, applied here: refusing would be safer for this one
		// site and would make every site without a usable backup directory
		// permanently un-updatable — a gate that can never pass. So the update
		// proceeds and the result records that it was unrecoverable.
		$backups = WP_CONTENT_DIR . '/aura-backups';
		foreach ( glob( $backups . '/*.zip' ) ?: array() as $f ) {
			unlink( $f );
		}
		// Make the backup impossible: no source directory to archive.
		$this->rmdir( $this->dir );

		$res = $this->selfUpdate();

		$this->assertFalse( $res['backed_up'] );
		$this->assertTrue( $res['success'] );
		$this->assertFalse( $res['rolled_back'] );
	}

	public function test_an_unhealthy_update_with_no_backup_reports_failure_rather_than_success(): void {
		$this->rmdir( $this->dir );
		$GLOBALS['_http_response']  = array( 'response' => array( 'code' => 500 ), 'body' => 'error' );
		$GLOBALS['_install_effect'] = null;

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertFalse( $res['backed_up'] );
		$this->assertFalse( $res['rolled_back'] );
		// Nothing to restore is not the same as nothing wrong — an operator has
		// to know this one needs hands.
		$this->assertStringContainsString( 'no backup', strtolower( $res['error'] ) );
	}
}
