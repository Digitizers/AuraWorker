<?php
/**
 * The unbind marker (#434, spec §2.3): one option, written under the site
 * claim, always read uncached. This task covers only the marker's own
 * read/write/delete surface and its appearance in GET /aura/v1/status —
 * Phase A/B and the mutation-boundary refusal land in later tasks.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class UnbindMarkerTest extends TestCase {

	protected function setUp(): void {
		sa_reset_state();
	}

	private function marker(): array {
		return array(
			'at'                  => '2026-08-29T10:00:00Z',
			'site'                => str_repeat( 'a', 64 ),
			'site_ref'            => 'res1',
			'client'              => 'c1',
			'seq'                 => 7,
			'connect_user_id'     => 3,
			'app_password_uuids'  => array( 'u-1' ),
			'app_password_users'  => array( 'u-1' => 3 ),
		);
	}

	public function test_absent_marker_reads_null_and_is_not_set(): void {
		$this->assertNull( Aura_Worker_Unbind::read() );
		$this->assertFalse( Aura_Worker_Unbind::is_set() );
		$this->assertNull( Aura_Worker_Unbind::status_fragment() );
	}

	public function test_write_under_claim_persists_uncached_and_is_read_back(): void {
		$fence = Aura_Worker_Magic_Link::claim_site();
		$this->assertNotSame( '', $fence );
		$this->assertTrue( Aura_Worker_Unbind::write_under_claim( $this->marker(), $fence ) );
		$this->assertSame( 'res1', Aura_Worker_Unbind::read()['site_ref'] );
		$this->assertSame( array( 'at' => '2026-08-29T10:00:00Z', 'site_ref' => 'res1' ), Aura_Worker_Unbind::status_fragment() );
		// The bootstrap's INSERT-statement regex captures an `autoload` column
		// but never stores it anywhere queryable ($GLOBALS['_rows'] holds only
		// the serialized value) — there is no `$GLOBALS['_autoload']` model to
		// assert against, so this only re-confirms the row landed at all.
		$this->assertArrayHasKey( Aura_Worker_Unbind::OPTION, $GLOBALS['_rows'] );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	public function test_write_without_the_claim_writes_nothing(): void {
		$this->assertFalse( Aura_Worker_Unbind::write_under_claim( $this->marker(), '' ) );
		$this->assertNull( Aura_Worker_Unbind::read() );
	}

	public function test_write_with_a_lost_claim_writes_nothing(): void {
		$fence = Aura_Worker_Magic_Link::claim_site();
		Aura_Worker_Magic_Link::release_site( $fence );
		$this->assertFalse( Aura_Worker_Unbind::write_under_claim( $this->marker(), $fence ) );
		$this->assertNull( Aura_Worker_Unbind::read() );
	}

	public function test_delete_under_claim_only(): void {
		$fence = Aura_Worker_Magic_Link::claim_site();
		Aura_Worker_Unbind::write_under_claim( $this->marker(), $fence );
		$this->assertFalse( Aura_Worker_Unbind::delete_under_claim( '' ) );
		$this->assertTrue( Aura_Worker_Unbind::is_set() );
		$this->assertTrue( Aura_Worker_Unbind::delete_under_claim( $fence ) );
		$this->assertFalse( Aura_Worker_Unbind::is_set() );
		Aura_Worker_Magic_Link::release_site( $fence );
	}

	public function test_garbage_marker_reads_null(): void {
		$GLOBALS['_options'][ Aura_Worker_Unbind::OPTION ] = 'not-an-array';
		$GLOBALS['_rows'][ Aura_Worker_Unbind::OPTION ]    = 'not-an-array';
		$this->assertNull( Aura_Worker_Unbind::read() );
	}

	public function test_status_reports_the_marker(): void {
		$fence = Aura_Worker_Magic_Link::claim_site();
		Aura_Worker_Unbind::write_under_claim( $this->marker(), $fence );
		Aura_Worker_Magic_Link::release_site( $fence );
		$api  = new Aura_Worker_API( new Aura_Worker_Security() );
		$body = $api->get_status( new WP_REST_Request( 'GET', '/aura/v1/status' ) )->get_data();
		$this->assertSame( array( 'at' => '2026-08-29T10:00:00Z', 'site_ref' => 'res1' ), $body['unbound'] );
	}

	public function test_status_omits_unbound_when_no_marker(): void {
		$api  = new Aura_Worker_API( new Aura_Worker_Security() );
		$body = $api->get_status( new WP_REST_Request( 'GET', '/aura/v1/status' ) )->get_data();
		$this->assertArrayNotHasKey( 'unbound', $body );
	}
}
