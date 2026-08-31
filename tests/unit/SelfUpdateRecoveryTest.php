<?php
/**
 * Self-update failure recovery (SiteAgent #77 / PR #78).
 *
 * `self_update()` used to download, verify, overwrite and reactivate — with no
 * backup, no verification that the site still booted, and nothing to restore.
 *
 * The verdict is a FACT the new build writes, not an inference. Four review
 * rounds found a case where every inferential signal lies (a cached home page,
 * a 401 that means two different things, a 500 vs a 404 control, a log tail
 * that is too short or the wrong file). So the updater leaves a nonce, makes
 * one uncacheable request, and reads `aura_worker_boot`, which the new build
 * writes as the last line of its own init. These tests simulate the new build
 * by writing that beacon (or not) from the install effect.
 *
 * Rollback is held to a post-condition: the plugin header on disk must read
 * the version we came from.
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
		file_put_contents( $this->dir . '/digitizer-site-worker.php', $this->build( 'OLD BUILD', AURA_WORKER_VERSION ) );

		$GLOBALS['_mutations']      = array();
		// A deleted option is listed in `notoptions` and short-circuits get_option
		// until something writes it again; a previous test's spent nonce must
		// not leak into this one.
		$GLOBALS['_notoptions']     = array();
		$GLOBALS['_wp_http_calls']  = array();
		$GLOBALS['_http_error']     = false;
		$GLOBALS['_http_response']         = array( 'response' => array( 'code' => 401 ), 'body' => '{}' );
		$GLOBALS['_http_responses_by_url'] = array();
		$GLOBALS['_install_result'] = true;
		$GLOBALS['_install_effect'] = function () {
			$this->installNewBuild( true );
		};
	}

	protected function tearDown(): void {
		if ( null !== $this->prev_error_log ) {
			ini_set( 'error_log', $this->prev_error_log );
			$this->prev_error_log = null;
		}
		@unlink( WP_CONTENT_DIR . '/sa-test-error.log' );
		unset( $GLOBALS['_install_effect'], $GLOBALS['_install_result'], $GLOBALS['_http_error'] );
		unset( $GLOBALS['_options']['aura_worker_boot'], $GLOBALS['_options']['aura_worker_boot_nonce'] );
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

	/** A plugin file with a real Version header, the way WordPress reads it. */
	private function build( string $marker, string $version ): string {
		return "<?php\n/**\n * Plugin Name: SiteAgent\n * Version: {$version}\n */\n// {$marker}\n";
	}

	/** The marker inside the plugin file on disk — OLD BUILD or NEW BUILD. */
	private function onDisk(): string {
		$f = $this->dir . '/digitizer-site-worker.php';
		if ( ! is_file( $f ) ) {
			return '';
		}
		return preg_match( '/(OLD BUILD|NEW BUILD)/', (string) file_get_contents( $f ), $m ) ? $m[1] : '';
	}

	/**
	 * What a real install does, then what the NEW build's own init would do on
	 * the loopback request: write the beacon echoing the nonce the updater left.
	 * `$boots = false` models a build that installs cleanly and fatals on load —
	 * files on disk, no beacon.
	 */
	private function installNewBuild( bool $boots, string $version = '9.9.9' ): void {
		file_put_contents( $this->dir . '/digitizer-site-worker.php', $this->build( 'NEW BUILD', $version ) );
		if ( $boots ) {
			Aura_Worker_Updater::write_boot_beacon( $version );
		}
	}

	/** Append the fatal a dying build of THIS plugin leaves in the log. */
	private function diedInOurCode(): void {
		file_put_contents( $this->useLog(), "[today] PHP Fatal error:  Uncaught Error in /srv/wp-content/plugins/digitizer-site-worker/includes/x.php:1\n", FILE_APPEND );
	}

	/**
	 * An install whose build does not come up: no beacon, and the fatal it died
	 * with in the error log — naming this plugin's directory, as PHP does.
	 * That attributed fatal is the POSITIVE evidence a rollback needs.
	 */
	private function brokenBuild(): void {
		$log = $this->useLog();
		$GLOBALS['_install_effect'] = function () use ( $log ) {
			$this->installNewBuild( false );
			file_put_contents( $log, "[today] PHP Fatal error:  Uncaught Error in /srv/wp-content/plugins/digitizer-site-worker/includes/x.php:1\\n", FILE_APPEND );
		};
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

	public function test_a_log_created_BY_the_new_build_is_read_from_byte_zero(): void {
		// No log before the install; the new build fatals during bootstrap and
		// creates one by doing so. Discarding a null offset lost exactly this
		// case, and both REST probes fail from the same bootstrap, so the REST
		// evidence reads as inconclusive (Codex round-2 P1).
		$log = WP_CONTENT_DIR . '/sa-test-error.log';
		@unlink( $log );
		$this->prev_error_log = ini_get( 'error_log' );
		ini_set( 'error_log', $log );

		$GLOBALS['_install_effect'] = function () use ( $log ) {
			// Fatals during bootstrap: no beacon, and it CREATES the log.
			$this->installNewBuild( false );
			file_put_contents( $log, "[today] PHP Fatal error:  Uncaught Error in /srv/wp-content/plugins/digitizer-site-worker/includes/x.php:1\\n" );
		};

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertTrue( $res['rolled_back'] );
		$this->assertSame( 'OLD BUILD', $this->onDisk() );
	}

	public function test_a_build_that_breaks_the_site_is_rolled_back(): void {
		$this->brokenBuild();

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
		file_put_contents( $log, "[01-Jan-2020] PHP Fatal error: in /srv/wp-content/plugins/digitizer-site-worker/old.php:1\n" );

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
		file_put_contents( $log, "[01-Jan-2020] PHP Fatal error: in /srv/wp-content/plugins/digitizer-site-worker/old.php:1\n" );
		$GLOBALS['_install_effect'] = function () use ( $log ) {
			$this->installNewBuild( true );
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
		file_put_contents( $log, "[01-Jan-2020] PHP Fatal error: in /srv/wp-content/plugins/digitizer-site-worker/old.php:1\n" );
		$GLOBALS['_install_effect'] = function () use ( $log ) {
			// Boots — the beacon is written — but fatals somewhere after; the log
			// is the secondary evidence that still catches it.
			$this->installNewBuild( true );
			file_put_contents( $log, "[today] PHP Fatal error:  Uncaught Error in /srv/wp-content/plugins/digitizer-site-worker/includes/x.php:1\\n", FILE_APPEND );
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
			$this->installNewBuild( true );
			// Rotation: the log is now SHORTER than the offset taken before.
			file_put_contents( $log, "[today] PHP Fatal error:  Uncaught Error in /srv/wp-content/plugins/digitizer-site-worker/includes/x.php:1\\n" );
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
		$GLOBALS['_install_effect'] = function () {
			mkdir( $this->dir, 0777, true );
			$this->installNewBuild( true );
		};

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
		$GLOBALS['_install_effect'] = function () {
			$this->installNewBuild( false );
			$this->diedInOurCode();
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
		$GLOBALS['_install_effect'] = function () {
			$this->installNewBuild( false );
			$this->diedInOurCode();
			foreach ( glob( WP_CONTENT_DIR . '/aura-backups/*.zip' ) ?: array() as $f ) {
				file_put_contents( $f, 'not a zip' );
			}
		};

		$res = $this->selfUpdate();

		$this->assertFalse( $res['rolled_back'] );
		$this->assertStringNotContainsString( 'was rolled back', $res['error'] );
		$this->assertStringContainsString( 'could not be rolled back', $res['error'] );
	}

	public function test_a_restore_that_left_the_wrong_build_on_disk_is_not_a_rollback(): void {
		// The post-condition, and the reason it exists: every step can report
		// success and the site still be running the broken build. Here the
		// archive restores but the plugin file it puts back is not the version
		// we came from, so `rolled_back` must be false however cleanly the
		// extraction went.
		$GLOBALS['_install_effect'] = function () {
			$this->installNewBuild( false );
			$this->diedInOurCode();
			// Rewrite the backup so it restores a DIFFERENT version.
			foreach ( glob( WP_CONTENT_DIR . '/aura-backups/*.zip' ) ?: array() as $f ) {
				$zip = new ZipArchive();
				$zip->open( $f, ZipArchive::OVERWRITE );
				$zip->addFromString(
					'digitizer-site-worker/digitizer-site-worker.php',
					$this->build( 'NEW BUILD', '7.7.7' )
				);
				$zip->close();
			}
		};

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertFalse( $res['rolled_back'], 'a restore that did not bring back the old version is not a rollback' );
	}

	public function test_a_fatal_far_past_the_first_64KiB_of_the_window_is_still_found(): void {
		// The install and three probes can emit that much in notices and
		// deprecations before the bootstrap fatal; reading only the beginning of
		// the window missed the entry the check exists for (Codex round-3).
		$log = $this->useLog();
		file_put_contents( $log, "start of window\n" );
		$GLOBALS['_install_effect'] = function () use ( $log ) {
			$this->installNewBuild( true );
			file_put_contents( $log, str_repeat( "PHP Notice: chatty deprecation\n", 6000 ), FILE_APPEND );
			file_put_contents( $log, "[today] PHP Fatal error:  Uncaught Error in /srv/wp-content/plugins/digitizer-site-worker/includes/x.php:1\\n", FILE_APPEND );
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

	public function test_the_verdict_is_the_beacon_the_new_build_wrote(): void {
		$res = $this->selfUpdate();

		$this->assertTrue( $res['success'] );
		$this->assertTrue( $res['verified'] );
		$this->assertSame( 'pass', $res['health']['checks']['boot_beacon']['status'] );
		// The request for a beacon is spent either way.
		$this->assertFalse( isset( $GLOBALS['_options']['aura_worker_boot_nonce'] ) );
	}

	public function test_a_build_that_installs_but_never_boots_is_rolled_back(): void {
		// Files on disk, loopback answered (so the request reached the site),
		// no beacon: the build did not come up. This is the case the whole
		// feature exists for, and the one every inferential probe got wrong
		// somewhere.
		$this->brokenBuild();

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertTrue( $res['rolled_back'] );
		$this->assertSame( 'OLD BUILD', $this->onDisk() );
	}

	public function test_a_beacon_left_by_an_EARLIER_boot_does_not_count(): void {
		// A stale beacon with some other nonce is exactly what a "did it boot"
		// check must not be fooled by — the previous build booted; this one
		// did not.
		$GLOBALS['_options']['aura_worker_boot'] = array( 'version' => '9.9.9', 'nonce' => 'from-last-time' );
		$this->brokenBuild();

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertTrue( $res['rolled_back'] );
		$this->assertSame( 'stale beacon', $res['health']['checks']['boot_beacon']['detail'] );
	}

	public function test_a_beacon_from_the_WRONG_version_does_not_count(): void {
		// The nonce matches but the build that answered is not the one we
		// installed — an OPcache still serving the old files would look like this.
		$GLOBALS['_install_effect'] = function () {
			file_put_contents( $this->dir . '/digitizer-site-worker.php', $this->build( 'NEW BUILD', '9.9.9' ) );
			Aura_Worker_Updater::write_boot_beacon( AURA_WORKER_VERSION ); // the OLD version booted
		};

		$res = $this->selfUpdate();

		// Not verified — the wrong build answered. And with no attributed fatal
		// it is inconclusive rather than rolled back: absence of the right
		// beacon is not positive evidence of breakage.
		$this->assertFalse( $res['verified'] );
		$this->assertFalse( $res['rolled_back'] );
		$this->assertSame( 'stale beacon', $res['health']['checks']['boot_beacon']['detail'] );
	}

	public function test_no_beacon_and_no_attributed_fatal_is_inconclusive_and_the_update_stands(): void {
		// Files installed, loopback answered 200, no beacon, nothing in the log
		// naming this plugin. Two very different worlds look exactly like this
		// from inside the site — a CDN/WAF/proxy that answered before WordPress
		// ran, or a build that fatals on load with error logging off — and no
		// external signal tells them apart (five review rounds tried). Rolling
		// back here would make every edge-fronted site permanently
		// un-updatable, so the update stands and is reported UNVERIFIED. The
		// second world is the stated residual: the pre-#78 exposure, now
		// visible in the update log rather than invisible.
		$GLOBALS['_install_effect'] = function () {
			$this->installNewBuild( false );
		};

		$res = $this->selfUpdate();

		$this->assertTrue( $res['success'] );
		$this->assertFalse( $res['verified'] );
		$this->assertTrue( $res['health']['inconclusive'] );
		$this->assertFalse( $res['rolled_back'] );
		$this->assertSame( 'NEW BUILD', $this->onDisk() );
	}

	public function test_an_edge_answering_before_WordPress_does_not_cause_a_rollback(): void {
		// Codex round-5 P1: a 301 canonical redirect or a 403 challenge from a
		// CDN/WAF is an HTTP response with no PHP behind it. "We got a response"
		// must not be read as "PHP ran".
		$GLOBALS['_http_response'] = array( 'response' => array( 'code' => 301 ), 'body' => '' );
		$GLOBALS['_install_effect'] = function () {
			$this->installNewBuild( false );
		};

		$res = $this->selfUpdate();

		$this->assertTrue( $res['success'] );
		$this->assertFalse( $res['rolled_back'] );
		$this->assertTrue( $res['health']['inconclusive'] );
	}

	public function test_a_site_that_cannot_reach_itself_is_inconclusive_not_rolled_back(): void {
		$GLOBALS['_http_error'] = true;
		$GLOBALS['_install_effect'] = function () {
			$this->installNewBuild( false );
		};

		$res = $this->selfUpdate();

		$this->assertTrue( $res['success'] );
		$this->assertFalse( $res['verified'] );
		$this->assertFalse( $res['rolled_back'] );
	}

	public function test_an_UNRELATED_fatal_in_the_shared_log_cannot_override_a_valid_beacon(): void {
		// Codex round-5 P2: a busy site's other plugin dies between the snapshot
		// and the check. The byte offset says "new"; the path in the line says
		// "not ours". The line is the fact.
		$log = $this->useLog();
		$GLOBALS['_install_effect'] = function () use ( $log ) {
			$this->installNewBuild( true );
			file_put_contents( $log, "[today] PHP Fatal error:  Uncaught Error in /srv/wp-content/plugins/some-other-plugin/x.php:1\\n", FILE_APPEND );
		};

		try {
			$res = $this->selfUpdate();
			$this->assertTrue( $res['success'] );
			$this->assertTrue( $res['verified'] );
			$this->assertFalse( $res['rolled_back'] );
		} finally {
			unlink( $log );
		}
	}

	public function test_a_fatal_written_with_Windows_separators_is_still_attributed_to_this_plugin(): void {
		// Codex round-6 P1: `C:\\...\\digitizer-site-worker\\x.php` never matched a
		// forward-slash pattern, so a Windows-hosted build that died before its
		// beacon stood as inconclusive with error logging on.
		$log = $this->useLog();
		$GLOBALS['_install_effect'] = function () use ( $log ) {
			$this->installNewBuild( false );
			file_put_contents( $log, "[today] PHP Fatal error:  Uncaught Error in C:\\inetpub\\wp-content\\plugins\\digitizer-site-worker\\includes\\x.php:1\n", FILE_APPEND );
		};

		try {
			$res = $this->selfUpdate();
			$this->assertFalse( $res['success'] );
			$this->assertTrue( $res['rolled_back'] );
		} finally {
			unlink( $log );
		}
	}

	public function test_a_fatal_in_THIS_plugin_rolls_back_even_when_the_beacon_was_written(): void {
		// Boots — init completed — then dies in its own code on the probe. The
		// beacon says "came up"; the attributed fatal says "and broke". Breakage
		// wins.
		$log = $this->useLog();
		$GLOBALS['_install_effect'] = function () use ( $log ) {
			$this->installNewBuild( true );
			file_put_contents( $log, "[today] PHP Fatal error:  Uncaught Error in /srv/wp-content/plugins/digitizer-site-worker/includes/x.php:1\\n", FILE_APPEND );
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

	public function test_a_failed_install_is_held_to_the_same_post_condition_as_a_failed_boot(): void {
		// Codex round-4 P1: the install-failure exit trusted restore_plugin()'s
		// step result while the health-check exit checked the header. Here the
		// restore "succeeds" but puts back a different version.
		$GLOBALS['_install_result'] = false;
		$GLOBALS['_install_effect'] = function () {
			unlink( $this->dir . '/digitizer-site-worker.php' );
			foreach ( glob( WP_CONTENT_DIR . '/aura-backups/*.zip' ) ?: array() as $f ) {
				$zip = new ZipArchive();
				$zip->open( $f, ZipArchive::OVERWRITE );
				$zip->addFromString( 'digitizer-site-worker/digitizer-site-worker.php', $this->build( 'NEW BUILD', '7.7.7' ) );
				$zip->close();
			}
		};

		$res = $this->selfUpdate();

		$this->assertFalse( $res['rolled_back'] );
		$this->assertStringContainsString( 'could NOT be restored', $res['error'] );
	}

	public function test_a_fatal_in_a_log_the_new_build_CREATED_under_a_different_path_is_found(): void {
		// Codex round-4 P1: ini names a file that does not exist yet while
		// debug.log does. The snapshot was taken on debug.log; the new build
		// fatals into the ini file. Applying debug.log's offset to the new,
		// shorter file read as "rotated" and the fatal was ignored.
		$debug = WP_CONTENT_DIR . '/debug.log';
		$ini   = WP_CONTENT_DIR . '/sa-ini-created.log';
		@unlink( $ini );
		file_put_contents( $debug, str_repeat( "old noise\n", 200 ) );
		$this->prev_error_log = ini_get( 'error_log' );
		ini_set( 'error_log', $ini ); // names a file that is not there yet

		$GLOBALS['_install_effect'] = function () use ( $ini ) {
			$this->installNewBuild( false );
			file_put_contents( $ini, "[today] PHP Fatal error:  Uncaught Error in /srv/wp-content/plugins/digitizer-site-worker/includes/x.php:1\\n" );
		};

		try {
			$res = $this->selfUpdate();
			$this->assertFalse( $res['success'] );
			$this->assertSame( 'fail', $res['health']['checks']['php_errors']['status'] );
		} finally {
			@unlink( $debug );
			@unlink( $ini );
		}
	}

	public function test_an_unhealthy_update_with_no_backup_reports_failure_rather_than_success(): void {
		$this->rmdir( $this->dir );
		// Files land nowhere (no dir), no beacon — and the fatal it died with.
		$GLOBALS['_install_effect'] = function () {
			$this->diedInOurCode();
		};

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertFalse( $res['backed_up'] );
		$this->assertFalse( $res['rolled_back'] );
		// Nothing to restore is not the same as nothing wrong — an operator has
		// to know this one needs hands.
		$this->assertStringContainsString( 'no backup', strtolower( $res['error'] ) );
	}
}
