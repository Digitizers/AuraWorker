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
 * Breakage is a fact too: a shutdown handler in the dying process records a
 * fatal in one of this plugin's files against the same nonce (the "fatal
 * beacon"). The error-log scanner that preceded it produced review findings in
 * eight of ten rounds and is gone. These tests simulate the fatal beacon the
 * way the probe request would produce it: from `_http_effect`, via
 * `aura_worker_record_fatal_beacon()`, with a file path under the plugin dir.
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

	protected function setUp(): void {
		$this->dir = WP_PLUGIN_DIR . '/' . $this->slug;
		$this->rmdir( $this->dir );
		mkdir( $this->dir, 0777, true );
		file_put_contents( $this->dir . '/digitizer-site-worker.php', $this->build( 'OLD BUILD', AURA_WORKER_VERSION ) );

		$GLOBALS['_mutations']      = array();
		// Remove any beacon or nonce a previous test left, through the stub's OWN
		// delete path: it keeps a row store beside `_options`, and clearing only
		// `_options` let a stale beacon leak into the next test — which then
		// read "stale beacon" for a build that had written nothing.
		delete_option( 'aura_worker_boot' );
		delete_option( 'aura_worker_boot_fatal' );
		delete_option( 'aura_worker_boot_nonce' );
		// A deleted option is listed in `notoptions` and short-circuits get_option
		// until something writes it again; clear that too so absence is absence.
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
		unset( $GLOBALS['_install_effect'], $GLOBALS['_install_result'], $GLOBALS['_http_error'], $GLOBALS['_http_effect'] );
		delete_option( 'aura_worker_boot' );
		delete_option( 'aura_worker_boot_nonce' );
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
		// The beacon is written by the fresh process the LOOPBACK starts, not by
		// the install. Modelling it at install time was the round-8 finding: at
		// that moment the OLD build is what any request runs.
		$GLOBALS['_http_effect'] = $boots
			? function () use ( $version ) { Aura_Worker_Updater::write_boot_beacon( $version ); }
			: null;
	}

	/**
	 * What the dying process records when a fatal in OUR code ends the probe
	 * request: the fatal beacon. Runs from `_http_effect`, i.e. at request time
	 * with the nonce armed — the only moment it can be written.
	 */
	private function diedInOurCode(): void {
		$dir = $this->dir;
		$GLOBALS['_http_effect'] = function () use ( $dir ) {
			aura_worker_record_fatal_beacon(
				array( 'type' => E_ERROR, 'file' => $dir . '/includes/x.php', 'line' => 1, 'message' => 'Uncaught Error: boom' ),
				'9.9.9',
				$dir . '/'
			);
		};
	}

	/** An install whose build does not come up: no boot beacon, and a fatal beacon. */
	private function brokenBuild(): void {
		$GLOBALS['_install_effect'] = function () {
			$this->installNewBuild( false );
			$this->diedInOurCode();
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

	public function test_a_build_that_breaks_the_site_is_rolled_back(): void {
		$this->brokenBuild();

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertTrue( $res['rolled_back'] );
		$this->assertFalse( $res['healthy'] );
		// The only claim that matters: the previous build is back on disk.
		$this->assertSame( 'OLD BUILD', $this->onDisk() );
	}

	public function test_a_fatal_recorded_BY_this_update_rolls_it_back(): void {
		// The build installs, the probe request dies in our code, the dying
		// process records it. Positive evidence of breakage; restored.
		$this->brokenBuild();

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertTrue( $res['rolled_back'] );
		$this->assertSame( 'OLD BUILD', $this->onDisk() );
		$this->assertSame( 'fail', $res['health']['checks']['fatal_beacon']['status'] );
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
		// Neither boots nor dies: nothing of ours is written for THIS nonce.
		$GLOBALS['_install_effect'] = function () {
			$this->installNewBuild( false );
		};

		$res = $this->selfUpdate();

		// Not verified, and — with no fatal recorded either — inconclusive
		// rather than rolled back: a stale beacon is not evidence of anything.
		$this->assertFalse( $res['verified'] );
		$this->assertFalse( $res['rolled_back'] );
		$this->assertSame( 'stale beacon', $res['health']['checks']['boot_beacon']['detail'] );
	}

	public function test_a_beacon_from_the_WRONG_version_does_not_count(): void {
		// The nonce matches but the build that answered is not the one we
		// installed — an OPcache still serving the old files would look like this.
		$GLOBALS['_install_effect'] = function () {
			file_put_contents( $this->dir . '/digitizer-site-worker.php', $this->build( 'NEW BUILD', '9.9.9' ) );
			// The loopback is answered by the OLD version (OPcache still serving it).
			$GLOBALS['_http_effect'] = function () { Aura_Worker_Updater::write_boot_beacon( AURA_WORKER_VERSION ); };
		};

		$res = $this->selfUpdate();

		// Not verified — the wrong build answered. And with no attributed fatal
		// it is inconclusive rather than rolled back: absence of the right
		// beacon is not positive evidence of breakage.
		$this->assertFalse( $res['verified'] );
		$this->assertFalse( $res['rolled_back'] );
		$this->assertSame( 'a different build answered', $res['health']['checks']['boot_beacon']['detail'] );
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

	public function test_ANOTHER_plugins_fatal_is_not_recorded_against_this_verdict(): void {
		// The probe request boots our build, then some other plugin dies. The
		// shutdown handler sees a fatal whose file is not under our directory
		// and records nothing; the boot beacon stands.
		$dir = $this->dir;
		$GLOBALS['_install_effect'] = function () use ( $dir ) {
			$this->installNewBuild( true );
			$GLOBALS['_http_effect'] = function () use ( $dir ) {
				Aura_Worker_Updater::write_boot_beacon( '9.9.9' );
				aura_worker_record_fatal_beacon(
					array( 'type' => E_ERROR, 'file' => WP_PLUGIN_DIR . '/some-other-plugin/x.php', 'line' => 1, 'message' => 'theirs' ),
					'9.9.9',
					$dir . '/'
				);
			};
		};

		$res = $this->selfUpdate();

		$this->assertTrue( $res['success'] );
		$this->assertTrue( $res['verified'] );
	}

	public function test_an_archive_that_lost_its_main_file_is_a_failed_install_and_is_restored(): void {
		// Codex round-7 P1: Plugin_Upgrader accepts an archive whose main file
		// was renamed — some other PHP file has a valid header — while the
		// active-plugin entry still names the missing one. Nothing of ours loads
		// on the loopback, so there is no beacon and no attributed fatal; the
		// verdict would call that inconclusive and let a headless install stand.
		// The main file's header is an on-disk FACT, so it is checked before the
		// verdict is even asked.
		$GLOBALS['_install_effect'] = function () {
			unlink( $this->dir . '/digitizer-site-worker.php' );
			file_put_contents( $this->dir . '/renamed-main.php', $this->build( 'NEW BUILD', '9.9.9' ) );
		};

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertTrue( $res['rolled_back'] );
		$this->assertSame( 'OLD BUILD', $this->onDisk() );
		// A clean rollback leaves nothing the broken release added: the restore
		// removes the directory and re-extracts the backup, so the stray file
		// must be gone. (The first version of this test tried to unlink it as
		// cleanup and failed on PHP 7.4 — because the rollback had already done
		// the job the test was about.)
		$this->assertFileDoesNotExist( $this->dir . '/renamed-main.php' );
	}

	public function test_the_OLD_build_cannot_consume_the_nonce_during_the_install(): void {
		// Codex round-8 P1: while install() runs, other requests are still
		// served by the old build. If the nonce were armed before the install,
		// one of them would write a beacon with the OLD version and delete the
		// nonce — and a broken new build would read as "stale beacon" rather
		// than "no beacon". The nonce must not exist until the install is done.
		$GLOBALS['_install_effect'] = function () {
			// A concurrent request on the old build, mid-install.
			Aura_Worker_Updater::write_boot_beacon( AURA_WORKER_VERSION );
			$this->installNewBuild( false );
		};

		$res = $this->selfUpdate();

		$this->assertSame( 'no beacon written', $res['health']['checks']['boot_beacon']['detail'] );
		$this->assertArrayNotHasKey( 'aura_worker_boot', $GLOBALS['_options'] );
	}

	public function test_an_OLD_build_dying_after_the_nonce_was_armed_does_not_roll_back_a_healthy_new_build(): void {
		// Codex round-11: a request that loaded the old build before the install
		// can still be running when the nonce is armed, and die in old code. Its
		// fatal record names the OLD version, so it is not about the build under
		// verdict, and the new build's clean boot stands.
		$dir = $this->dir;
		$GLOBALS['_install_effect'] = function () use ( $dir ) {
			$this->installNewBuild( true );
			$GLOBALS['_http_effect'] = function () use ( $dir ) {
				Aura_Worker_Updater::write_boot_beacon( '9.9.9' );
				aura_worker_record_fatal_beacon(
					array( 'type' => E_ERROR, 'file' => $dir . '/includes/old.php', 'line' => 1, 'message' => 'straggler' ),
					AURA_WORKER_VERSION, // the OLD build died
					$dir . '/'
				);
			};
		};

		$res = $this->selfUpdate();

		$this->assertTrue( $res['success'] );
		$this->assertTrue( $res['verified'] );
	}

	public function test_a_fatal_recorded_BEFORE_a_clean_boot_on_another_request_still_rolls_back(): void {
		// Codex round-11: two records with two owners, so the boot write cannot
		// replace the fatal however the two requests interleave. Precedence is
		// decided when the verdict reads, not when either writes.
		$dir = $this->dir;
		$GLOBALS['_install_effect'] = function () use ( $dir ) {
			$this->installNewBuild( true );
			$GLOBALS['_http_effect'] = function () use ( $dir ) {
				aura_worker_record_fatal_beacon(
					array( 'type' => E_ERROR, 'file' => $dir . '/includes/x.php', 'line' => 1, 'message' => 'died first' ),
					'9.9.9',
					$dir . '/'
				);
				Aura_Worker_Updater::write_boot_beacon( '9.9.9' ); // a second, clean request, later
			};
		};

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertTrue( $res['rolled_back'] );
	}

	public function test_a_fatal_AFTER_the_boot_beacon_on_the_same_request_still_rolls_back(): void {
		// Init completed and the boot beacon was written; then our code died
		// later in the same request (dispatching the probe). The nonce is still
		// armed — the updater spends it, not the boot write — so the dying
		// process upgrades the beacon to fatal. Breakage wins.
		$dir = $this->dir;
		$GLOBALS['_install_effect'] = function () use ( $dir ) {
			$this->installNewBuild( true );
			$GLOBALS['_http_effect'] = function () use ( $dir ) {
				Aura_Worker_Updater::write_boot_beacon( '9.9.9' );
				aura_worker_record_fatal_beacon(
					array( 'type' => E_ERROR, 'file' => $dir . '/includes/class-aura-worker-api.php', 'line' => 1, 'message' => 'died dispatching' ),
					'9.9.9',
					$dir . '/'
				);
			};
		};

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertTrue( $res['rolled_back'] );
		$this->assertSame( 'OLD BUILD', $this->onDisk() );
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

	public function test_an_unhealthy_update_with_no_backup_reports_failure_rather_than_success(): void {
		// No directory to back up, so no backup. The install itself lands (a
		// fresh directory, real main file), the build does not boot, and it dies
		// in our code — so the VERDICT is what fails, with nothing to restore.
		$this->rmdir( $this->dir );
		$GLOBALS['_install_effect'] = function () {
			mkdir( $this->dir, 0777, true );
			$this->installNewBuild( false );
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
