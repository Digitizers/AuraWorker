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
		$GLOBALS['_options']    = array();
		$GLOBALS['_notoptions'] = array();
	}

	public function test_an_ordinary_boot_writes_nothing(): void {
		$this->assertFalse( Aura_Worker_Updater::write_boot_beacon( '2.14.0' ) );
		$this->assertArrayNotHasKey( 'aura_worker_boot', $GLOBALS['_options'] );
	}

	public function test_a_requested_boot_writes_version_and_nonce_and_spends_the_request(): void {
		update_option( 'aura_worker_boot_nonce', 'abc123', false );

		$this->assertTrue( Aura_Worker_Updater::write_boot_beacon( '2.14.0' ) );
		$this->assertSame(
			array( 'version' => '2.14.0', 'nonce' => 'abc123' ),
			$GLOBALS['_options']['aura_worker_boot']
		);
		$this->assertArrayNotHasKey( 'aura_worker_boot_nonce', $GLOBALS['_options'] );
	}

	public function test_the_beacon_is_hooked_last_on_rest_api_init_by_the_plugins_init(): void {
		// The wiring, not just the writer. A beacon emitted at plugins_loaded
		// vouched for a REST API that had not initialized yet (Codex round-9);
		// it has to run after every route registration on the probe request.
		$GLOBALS['_filters'] = array();
		$plugin = new Aura_Worker();
		$plugin->init();

		$this->assertSame(
			PHP_INT_MAX,
			has_filter( 'rest_api_init', array( 'Aura_Worker_Updater', 'emit_boot_beacon' ) ),
			'the beacon must be registered on rest_api_init at the lowest possible priority'
		);
	}

	public function test_a_malformed_nonce_is_treated_as_no_request(): void {
		update_option( 'aura_worker_boot_nonce', array( 'not', 'a', 'string' ), false );

		$this->assertFalse( Aura_Worker_Updater::write_boot_beacon( '2.14.0' ) );
		$this->assertArrayNotHasKey( 'aura_worker_boot', $GLOBALS['_options'] );
	}
}
