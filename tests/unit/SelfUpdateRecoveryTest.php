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
		$GLOBALS['_http_response']  = array( 'response' => array( 'code' => 401 ), 'body' => '{"code":"rest_forbidden"}' );
		$GLOBALS['_http_responses_by_url'] = array();
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

	/** The site answers core REST but has no aura route: the build did not come up. */
	private function pluginRouteGone(): array {
		return array(
			'aura/v1/status' => array( 'response' => array( 'code' => 404 ), 'body' => '{}' ),
			'wp/v2/types'    => array( 'response' => array( 'code' => 200 ), 'body' => '{}' ),
		);
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

	public function test_the_verdict_asks_the_plugins_own_route_and_defeats_caches(): void {
		// Two properties in one: the probe goes to an aura route (only the NEW
		// build can answer it, unlike the home page, which proves only that
		// WordPress renders), and it carries a unique query argument so a
		// full-page cache or CDN cannot serve it without starting PHP.
		$this->selfUpdate();

		$urls = array_column( $GLOBALS['_wp_http_calls'], 'url' );
		$this->assertNotEmpty( $urls );
		$this->assertStringContainsString( 'aura/v1/status', $urls[0] );
		$this->assertStringContainsString( 'aura_probe=', $urls[0] );
	}

	public function test_a_403_from_the_route_is_a_pass_because_the_refusal_proves_it_registered(): void {
		$GLOBALS['_http_response'] = array( 'response' => array( 'code' => 403 ), 'body' => '{}' );

		$this->assertTrue( $this->selfUpdate()['success'] );
	}

	public function test_a_site_that_blocks_anonymous_REST_is_not_rolled_back_on_that_alone(): void {
		// 404 from the aura route AND core REST unreachable: the evidence cannot
		// tell "this plugin is dead" from "REST is closed to strangers here", so
		// it must not destroy a working install on the guess.
		$GLOBALS['_http_responses_by_url'] = array(
			'aura/v1/status' => array( 'response' => array( 'code' => 404 ), 'body' => '{}' ),
			'wp/v2/types'    => array( 'response' => array( 'code' => 404 ), 'body' => '{}' ),
		);

		$res = $this->selfUpdate();

		$this->assertTrue( $res['success'] );
		$this->assertFalse( $res['rolled_back'] );
		$this->assertSame( 'NEW BUILD', $this->onDisk() );
	}

	public function test_a_build_that_breaks_the_site_is_rolled_back(): void {
		$GLOBALS['_http_responses_by_url'] = $this->pluginRouteGone();

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertTrue( $res['rolled_back'] );
		$this->assertFalse( $res['healthy'] );
		// The only claim that matters: the previous build is back on disk.
		$this->assertSame( 'OLD BUILD', $this->onDisk() );
	}

	public function test_a_fatal_already_in_the_log_does_not_roll_back_a_healthy_update(): void {
		// The old aggregate check read the last 5KB of the log and failed on ANY
		// fatal, whatever its age — so on a site with one old fatal every
		// healthy self-update would have been rolled back (Codex round-1 P2).
		$log = WP_CONTENT_DIR . '/debug.log';
		file_put_contents( $log, "[01-Jan-2020] PHP Fatal error: something last year\n" );

		try {
			$res = $this->selfUpdate();
			$this->assertTrue( $res['success'] );
			$this->assertSame( 'NEW BUILD', $this->onDisk() );
		} finally {
			unlink( $log );
		}
	}

	public function test_an_old_fatal_is_ignored_even_when_the_log_grows_afterwards(): void {
		// The discriminating case. If the log does not grow, an early return
		// shields the offset logic and a whole-log scan looks identical. Here
		// the update appends something harmless, so a scan that started at byte
		// zero would find the OLD fatal and roll back a healthy build.
		$log = WP_CONTENT_DIR . '/debug.log';
		file_put_contents( $log, "[01-Jan-2020] PHP Fatal error: something last year\n" );
		$GLOBALS['_install_effect'] = function () use ( $log ) {
			file_put_contents( $this->dir . '/digitizer-site-worker.php', 'NEW BUILD' );
			file_put_contents( $log, "[today] PHP Notice: undefined index, harmless\n", FILE_APPEND );
		};

		try {
			$res = $this->selfUpdate();
			$this->assertTrue( $res['success'] );
			$this->assertSame( 'NEW BUILD', $this->onDisk() );
		} finally {
			unlink( $log );
		}
	}

	public function test_a_fatal_written_BY_this_update_does_roll_it_back(): void {
		$log = WP_CONTENT_DIR . '/debug.log';
		file_put_contents( $log, "[01-Jan-2020] PHP Fatal error: something last year\n" );
		$GLOBALS['_install_effect'] = function () use ( $log ) {
			file_put_contents( $this->dir . '/digitizer-site-worker.php', 'NEW BUILD' );
			file_put_contents( $log, "[today] PHP Fatal error: the new build\n", FILE_APPEND );
		};

		try {
			$res = $this->selfUpdate();
			$this->assertFalse( $res['success'] );
			$this->assertTrue( $res['rolled_back'] );
			$this->assertSame( 'OLD BUILD', $this->onDisk() );
		} finally {
			unlink( $log );
		}
	}

	public function test_a_rotated_log_claims_nothing_rather_than_reading_someone_elses_window(): void {
		$log = WP_CONTENT_DIR . '/debug.log';
		file_put_contents( $log, str_repeat( 'x', 4096 ) . "\n" );
		$GLOBALS['_install_effect'] = function () use ( $log ) {
			file_put_contents( $this->dir . '/digitizer-site-worker.php', 'NEW BUILD' );
			// Rotation: the log is now SHORTER than the offset taken before.
			file_put_contents( $log, "[today] PHP Fatal error: from before rotation\n" );
		};

		try {
			$this->assertTrue( $this->selfUpdate()['success'] );
		} finally {
			unlink( $log );
		}
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

	public function test_a_restore_that_cannot_write_is_not_reported_as_a_rollback(): void {
		// `extractTo()` returns false when the PHP process cannot write to
		// WP_PLUGIN_DIR — the ordinary case being a site that updates over
		// FTP/SSH. Reporting `rolled_back: true` there tells an operator the
		// site was recovered when the plugin may be missing (Codex round-1 P1).
		$GLOBALS['_http_responses_by_url'] = $this->pluginRouteGone();
		$GLOBALS['_install_effect'] = function () {
			file_put_contents( $this->dir . '/digitizer-site-worker.php', 'NEW BUILD' );
			// Corrupt the only backup so extraction cannot succeed.
			foreach ( glob( WP_CONTENT_DIR . '/aura-backups/*.zip' ) ?: array() as $f ) {
				file_put_contents( $f, 'not a zip' );
			}
		};

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertFalse( $res['rolled_back'] );
		$this->assertNotNull( $res['restore_error'] );
	}

	public function test_an_unhealthy_update_with_no_backup_reports_failure_rather_than_success(): void {
		$this->rmdir( $this->dir );
		$GLOBALS['_http_responses_by_url'] = $this->pluginRouteGone();
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
