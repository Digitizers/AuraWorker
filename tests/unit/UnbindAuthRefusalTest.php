<?php
/**
 * The refusal at AUTHENTICATION (#434, Codex round-4 P1).
 *
 * Every other boundary in this design is gated behind
 * `is_agent_rest_request()`, so the whole refusal surface was REST — while
 * WordPress authenticates Application Passwords on every API surface it
 * recognises, XML-RPC included. A departed binding's credential that Phase B
 * could not prove revoked could therefore go on writing content through
 * `xmlrpc.php` for as long as the debt stood.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class UnbindAuthRefusalTest extends TestCase {

	protected function setUp(): void {
		sa_reset_state();
		Aura_Worker_Rules::$rest_request_override = false; // not REST: the surface with no seam of its own
	}

	protected function tearDown(): void {
		Aura_Worker_Rules::$rest_request_override = null;
	}

	private function marker( array $over = array() ): array {
		return array_merge(
			array(
				'at'                 => '2026-08-29T10:00:00Z',
				'site'               => str_repeat( 'a', 64 ),
				'site_ref'           => 'res1',
				'client'             => 'c1',
				'seq'                => 7,
				'connect_user_id'    => 3,
				'app_password_uuids' => array( 'uuid-departed' ),
				'app_password_users' => array( 'uuid-departed' => 3 ),
			),
			$over
		);
	}

	private function refuse( string $uuid ): WP_Error {
		return Aura_Worker_Security::refuse_departed_credential(
			new WP_Error(),
			null,
			array( 'uuid' => $uuid )
		);
	}

	public function test_the_filter_is_registered_so_the_refusal_actually_runs(): void {
		Aura_Worker_Security::init();
		$this->assertNotFalse(
			has_filter( 'wp_authenticate_application_password_errors', array( 'Aura_Worker_Security', 'refuse_departed_credential' ) ),
			'nothing refuses the credential outside REST'
		);
	}

	public function test_a_bound_site_refuses_nothing(): void {
		$this->assertFalse( $this->refuse( 'uuid-departed' )->has_errors() );
	}

	public function test_a_credential_the_marker_names_does_not_authenticate(): void {
		$GLOBALS['_rows'][ Aura_Worker_Unbind::OPTION ] = maybe_serialize( $this->marker() );

		$err = $this->refuse( 'uuid-departed' );

		$this->assertTrue( $err->has_errors() );
		$this->assertSame( 'aura_site_unbound', $err->get_error_code() );
	}

	/**
	 * The marker names its debts, and a password it does not name is somebody
	 * else\'s — the site owner\'s own credential keeps working.
	 */
	public function test_a_credential_the_marker_does_not_name_still_authenticates(): void {
		$GLOBALS['_rows'][ Aura_Worker_Unbind::OPTION ] = maybe_serialize( $this->marker() );

		$this->assertFalse( $this->refuse( 'uuid-somebody-elses' )->has_errors() );
	}

	/**
	 * Unreadable is not innocent — the same ruling departed_binding_request()
	 * makes. A marker whose credential list cannot be read cannot say which
	 * password is the departed one, and authentication cannot see whether the
	 * call means to read or to write.
	 */
	public function test_an_unreadable_marker_refuses_every_credential(): void {
		$GLOBALS['_rows'][ Aura_Worker_Unbind::OPTION ] = maybe_serialize( 'not-a-marker' );

		$this->assertSame( 'aura_site_unbound', $this->refuse( 'uuid-somebody-elses' )->get_error_code() );
	}

	/**
	 * REST keeps its own seam, which answers 403 `aura_site_unbound` and says
	 * why; a denial here would answer 401 and lose that.
	 */
	public function test_rest_is_left_to_its_own_seam(): void {
		Aura_Worker_Rules::$rest_request_override = true;
		$GLOBALS['_rows'][ Aura_Worker_Unbind::OPTION ] = maybe_serialize( $this->marker() );

		$this->assertFalse( $this->refuse( 'uuid-departed' )->has_errors() );
	}

	/** An error somebody else already raised is not overwritten. */
	public function test_an_existing_refusal_is_left_alone(): void {
		$GLOBALS['_rows'][ Aura_Worker_Unbind::OPTION ] = maybe_serialize( $this->marker() );
		$err = new WP_Error( 'incorrect_password', 'nope' );

		$out = Aura_Worker_Security::refuse_departed_credential( $err, null, array( 'uuid' => 'uuid-departed' ) );

		$this->assertSame( 'incorrect_password', $out->get_error_code() );
	}
}
