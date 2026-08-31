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
	/** @var string|null Previous ini error_log, restored in tearDown. */
	private $prev_error_log = null;

	/**
	 * Point the PHP error log at a file this test owns.
	 *
	 * The production resolver reads `ini_get( 'error_log' )` FIRST and only
	 * falls back to WP_CONTENT_DIR/debug.log when it is empty or missing. A
	 * developer machine usually leaves it empty, so writing to debug.log
	 * happened to work locally — and CI sets it, so the same test wrote to a
	 * file the code never read and saw no fatals. Assume nothing about the
	 * environment: name the file.
	 */
	private function useLog(): string {
		$log                  = WP_CONTENT_DIR . '/sa-test-error.log';
		$this->prev_error_log = ini_get( 'error_log' );
		ini_set( 'error_log', $log );
		return $log;
	}

	protected function setUp(): void {
		$this->dir = WP_PLUGIN_DIR . '/' . $this->slug;
		$this->rmdir( $this->dir );
		mkdir( $this->dir, 0777, true );
		file_put_contents( $this->dir . '/digitizer-site-worker.php', 'OLD BUILD' );

		$GLOBALS['_mutations']      = array();
		$GLOBALS['_wp_http_calls']  = array();
		$GLOBALS['_http_error']     = false;
		// A healthy site: the registered route refuses an anonymous caller (401)
		// while an unregistered path under the same namespace 404s. The two
		// differing is what proves the plugin registered.
		$GLOBALS['_http_response']         = array( 'response' => array( 'code' => 404 ), 'body' => '{}' );
		$GLOBALS['_http_responses_by_url'] = $this->pluginRouteUp();
		$GLOBALS['_install_result'] = true;
		$GLOBALS['_install_effect'] = function () {
			file_put_contents( $this->dir . '/digitizer-site-worker.php', 'NEW BUILD' );
		};
	}

	protected function tearDown(): void {
		if ( null !== $this->prev_error_log ) {
			ini_set( 'error_log', $this->prev_error_log );
			$this->prev_error_log = null;
		}
		@unlink( WP_CONTENT_DIR . '/sa-test-error.log' );
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

	/** Registered route refuses anonymously; an unregistered path 404s. */
	private function pluginRouteUp(): array {
		return array(
			'__aura_probe_absent__' => array( 'response' => array( 'code' => 404 ), 'body' => '{}' ),
			'aura/v1/status'        => array( 'response' => array( 'code' => 401 ), 'body' => '{"code":"rest_forbidden"}' ),
			'wp/v2/types'           => array( 'response' => array( 'code' => 200 ), 'body' => '{}' ),
		);
	}

	/** The aura route is indistinguishable from an absent one, and core REST works. */
	private function pluginRouteGone(): array {
		return array(
			'__aura_probe_absent__' => array( 'response' => array( 'code' => 404 ), 'body' => '{}' ),
			'aura/v1/status'        => array( 'response' => array( 'code' => 404 ), 'body' => '{}' ),
			'wp/v2/types'           => array( 'response' => array( 'code' => 200 ), 'body' => '{}' ),
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

	public function test_a_403_refusal_that_differs_from_an_absent_path_proves_registration(): void {
		$GLOBALS['_http_responses_by_url'] = array(
			'__aura_probe_absent__' => array( 'response' => array( 'code' => 404 ), 'body' => '{}' ),
			'aura/v1/status'        => array( 'response' => array( 'code' => 403 ), 'body' => '{}' ),
			'wp/v2/types'           => array( 'response' => array( 'code' => 200 ), 'body' => '{}' ),
		);

		$this->assertTrue( $this->selfUpdate()['success'] );
	}

	public function test_a_site_that_denies_anonymous_REST_globally_is_never_rolled_back_on_a_401(): void {
		// The case a fixed "401/403 means registered" list gets wrong (Codex
		// round-2 P1). An authentication filter answers 401 for EVERY path, so
		// the aura route and a path that does not exist are indistinguishable —
		// this probe has learned nothing and must not claim the plugin is up OR
		// that it is down.
		$GLOBALS['_http_responses_by_url'] = array(
			'__aura_probe_absent__' => array( 'response' => array( 'code' => 401 ), 'body' => '{}' ),
			'aura/v1/status'        => array( 'response' => array( 'code' => 401 ), 'body' => '{}' ),
			'wp/v2/types'           => array( 'response' => array( 'code' => 401 ), 'body' => '{}' ),
		);

		$res = $this->selfUpdate();

		$this->assertTrue( $res['success'], 'inconclusive evidence must not destroy a working install' );
		$this->assertFalse( $res['rolled_back'] );
		$this->assertSame( 'NEW BUILD', $this->onDisk() );
	}

	public function test_a_401_that_an_ABSENT_path_also_returns_is_not_treated_as_registration(): void {
		// The case that separates the control-comparison from a fixed
		// "401/403 means registered" list. Anonymous REST demonstrably works
		// here (core answers 200), yet a path that cannot exist answers 401 just
		// like the real one — so nothing in the aura namespace is being routed
		// by this plugin, and a status list would have called that healthy.
		$GLOBALS['_http_responses_by_url'] = array(
			'__aura_probe_absent__' => array( 'response' => array( 'code' => 401 ), 'body' => '{}' ),
			'aura/v1/status'        => array( 'response' => array( 'code' => 401 ), 'body' => '{}' ),
			'wp/v2/types'           => array( 'response' => array( 'code' => 200 ), 'body' => '{}' ),
		);

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertTrue( $res['rolled_back'] );
		$this->assertSame( 'OLD BUILD', $this->onDisk() );
	}

	public function test_a_build_that_never_registered_is_rolled_back_even_when_REST_denies_anonymously(): void {
		// Same 401-everywhere site, but core REST proves anonymous REST works.
		$GLOBALS['_http_responses_by_url'] = $this->pluginRouteGone();

		$this->assertTrue( $this->selfUpdate()['rolled_back'] );
	}

	public function test_a_log_created_BY_the_new_build_is_read_from_byte_zero(): void {
		// No log before the install; the new build fatals during bootstrap and
		// creates one by doing so. Discarding a null offset lost exactly this
		// case, and both REST probes fail from the same bootstrap, so the REST
		// evidence reads as inconclusive (Codex round-2 P1).
		$log = WP_CONTENT_DIR . '/sa-test-error.log';
		@unlink( $log );
		$this->prev_error_log = ini_get( 'error_log' );
		ini_set( 'error_log', $log );

		$GLOBALS['_http_responses_by_url'] = array(
			'__aura_probe_absent__' => array( 'response' => array( 'code' => 500 ), 'body' => '' ),
			'aura/v1/status'        => array( 'response' => array( 'code' => 500 ), 'body' => '' ),
			'wp/v2/types'           => array( 'response' => array( 'code' => 500 ), 'body' => '' ),
		);
		$GLOBALS['_install_effect'] = function () use ( $log ) {
			file_put_contents( $this->dir . '/digitizer-site-worker.php', 'NEW BUILD' );
			file_put_contents( $log, "[today] PHP Fatal error: cannot redeclare aura_worker_init()\n" );
		};

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertTrue( $res['rolled_back'] );
		$this->assertSame( 'OLD BUILD', $this->onDisk() );
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
		$log = $this->useLog();
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
		$log = $this->useLog();
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
		$log = $this->useLog();
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
		$log = $this->useLog();
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
		// Make the backup impossible: no source directory to archive. The default
		// install effect writes INTO that directory, so it has to go too —
		// otherwise the test fails on its own fixture rather than on the
		// behaviour it is describing.
		$this->rmdir( $this->dir );
		$GLOBALS['_install_effect'] = null;

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

	public function test_a_failed_restore_does_not_claim_in_its_MESSAGE_that_it_rolled_back(): void {
		// The fields said `rolled_back: false` while the human-facing `error`
		// still said the site "was rolled back" (Codex round-2 P2). A dashboard
		// showing only the message told an operator the site had recovered while
		// it was still carrying the broken build.
		$GLOBALS['_http_responses_by_url'] = $this->pluginRouteGone();
		$GLOBALS['_install_effect']        = function () {
			file_put_contents( $this->dir . '/digitizer-site-worker.php', 'NEW BUILD' );
			foreach ( glob( WP_CONTENT_DIR . '/aura-backups/*.zip' ) ?: array() as $f ) {
				file_put_contents( $f, 'not a zip' );
			}
		};

		$res = $this->selfUpdate();

		$this->assertFalse( $res['rolled_back'] );
		$this->assertStringNotContainsString( 'was rolled back', $res['error'] );
		$this->assertStringContainsString( 'could not be rolled back', $res['error'] );
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
