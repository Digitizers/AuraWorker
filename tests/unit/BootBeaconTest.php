<?php
/**
 * The boot beacon (PR #78): the positive fact `self_update()` reads to learn
 * whether the build it installed came up. Two properties, both load-bearing:
 * it writes NOTHING unless asked, so an ordinary request does no database
 * write; and it echoes the nonce it was asked with, so a beacon from an
 * earlier boot can never satisfy a later verdict.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class BootBeaconTest extends TestCase {

	protected function setUp(): void {
		// Through the stub's own delete path: it keeps a row store beside
		// `_options`, and resetting only `_options` let a nonce leak between
		// tests — a "no verdict pending" case then found one pending.
		delete_option( 'aura_worker_boot' );
		delete_option( 'aura_worker_boot_fatal' );
		delete_option( 'aura_worker_boot_nonce' );
		$GLOBALS['_options']    = array();
		$GLOBALS['_notoptions'] = array();
	}

	public function test_an_ordinary_boot_writes_nothing(): void {
		$this->assertFalse( Aura_Worker_Updater::write_boot_beacon( '2.14.0' ) );
		$this->assertArrayNotHasKey( 'aura_worker_boot', $GLOBALS['_options'] );
	}

	public function test_a_requested_boot_writes_version_and_nonce_and_leaves_the_nonce_for_the_updater(): void {
		update_option( 'aura_worker_boot_nonce', 'abc123', false );

		$this->assertTrue( Aura_Worker_Updater::write_boot_beacon( '2.14.0' ) );
		$this->assertSame(
			array( 'version' => '2.14.0', 'nonce' => 'abc123' ),
			$GLOBALS['_options']['aura_worker_boot']
		);
		// The UPDATER spends the nonce after its verdict. If the boot write
		// spent it, a fatal later in the same request could not be recorded.
		$this->assertSame( 'abc123', $GLOBALS['_options']['aura_worker_boot_nonce'] );
	}

	private const DIR = '/srv/wp-content/plugins/digitizer-site-worker/';

	public function test_a_fatal_in_our_file_with_a_nonce_armed_is_recorded(): void {
		update_option( 'aura_worker_boot_nonce', 'n1', false );
		$err = array( 'type' => E_ERROR, 'file' => self::DIR . 'includes/x.php', 'line' => 3, 'message' => 'Uncaught Error' );

		$this->assertTrue( aura_worker_record_fatal_beacon( $err, '2.14.0', self::DIR ) );
		$b = $GLOBALS['_options']['aura_worker_boot_fatal'];
		$this->assertSame( 'n1', $b['nonce'] );
		$this->assertSame( '2.14.0', $b['version'] );
		$this->assertSame( 'x.php', $b['file'] );
		// Its own record: nothing about a boot is touched.
		$this->assertArrayNotHasKey( 'aura_worker_boot', $GLOBALS['_options'] );
	}

	public function test_a_fatal_with_no_verdict_pending_is_not_recorded(): void {
		$err = array( 'type' => E_ERROR, 'file' => self::DIR . 'includes/x.php', 'line' => 3, 'message' => 'boom' );
		$this->assertFalse( aura_worker_record_fatal_beacon( $err, '2.14.0', self::DIR ) );
		$this->assertArrayNotHasKey( 'aura_worker_boot_fatal', $GLOBALS['_options'] );
	}

	public function test_a_fatal_in_ANOTHER_plugins_file_is_not_ours(): void {
		$err = array( 'type' => E_ERROR, 'file' => '/srv/wp-content/plugins/other/x.php', 'line' => 1, 'message' => 'theirs' );
		$this->assertFalse( aura_worker_is_own_fatal( $err, self::DIR ) );
	}

	public function test_a_warning_or_notice_is_not_a_fatal(): void {
		foreach ( array( E_WARNING, E_NOTICE, E_DEPRECATED, E_USER_WARNING ) as $type ) {
			$err = array( 'type' => $type, 'file' => self::DIR . 'x.php', 'line' => 1, 'message' => 'meh' );
			$this->assertFalse( aura_worker_is_own_fatal( $err, self::DIR ), 'type ' . $type );
		}
	}

	public function test_a_parse_error_in_an_include_counts(): void {
		$err = array( 'type' => E_PARSE, 'file' => self::DIR . 'includes/class-aura-worker.php', 'line' => 9, 'message' => 'syntax error' );
		$this->assertTrue( aura_worker_is_own_fatal( $err, self::DIR ) );
	}

	public function test_windows_separators_still_attribute(): void {
		$err = array( 'type' => E_ERROR, 'file' => 'C:\\inetpub\\wp-content\\plugins\\digitizer-site-worker\\includes\\x.php', 'line' => 1, 'message' => 'boom' );
		$this->assertTrue( aura_worker_is_own_fatal( $err, 'C:\\inetpub\\wp-content\\plugins\\digitizer-site-worker\\' ) );
	}

	public function test_a_sibling_directory_with_our_name_as_a_prefix_is_not_ours(): void {
		// digitizer-site-worker-pro/ must not attribute to digitizer-site-worker/.
		$err = array( 'type' => E_ERROR, 'file' => '/srv/wp-content/plugins/digitizer-site-worker-pro/x.php', 'line' => 1, 'message' => 'boom' );
		$this->assertFalse( aura_worker_is_own_fatal( $err, self::DIR ) );
	}

	public function test_boot_and_fatal_are_separate_records_so_neither_write_can_erase_the_other(): void {
		// Codex round-11: a read-then-write "fatal wins" rule on one option
		// raced between two requests. Two records, two owners — precedence is
		// the verdict's, at read time.
		update_option( 'aura_worker_boot_nonce', 'n2', false );
		$err = array( 'type' => E_ERROR, 'file' => self::DIR . 'x.php', 'line' => 1, 'message' => 'boom' );

		aura_worker_record_fatal_beacon( $err, '2.14.0', self::DIR );
		Aura_Worker_Updater::write_boot_beacon( '2.14.0' );

		$this->assertSame( 'n2', $GLOBALS['_options']['aura_worker_boot_fatal']['nonce'] );
		$this->assertSame( 'n2', $GLOBALS['_options']['aura_worker_boot']['nonce'] );
	}
}
