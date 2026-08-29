<?php
/**
 * The site-wide claim (SITE_CLAIM) originally had NO timed takeover at all
 * (round-7, owner decision) — safe only while rare, operator-initiated
 * lifecycle operations held it. #434 puts a routine, gateway-driven path
 * (Aura_Worker_Rules::accept()) behind the same lock, so a single crashed
 * push would otherwise strand the site forever: `finally` cannot catch an
 * OOM kill or a max_execution_time fatal. Review round 1 (I2) bounds that
 * with SITE_CLAIM_TAKEOVER_AFTER — a claim recorded stale enough may be
 * seized, via the same conditional compare-and-swap the ruleset store uses.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class SiteClaimTakeoverTest extends TestCase {

	protected function setUp(): void {
		sa_reset_state();
	}

	/**
	 * Rewrites the claim row's recorded timestamp in place, keeping the
	 * fence it already carries — simulating a claim that has simply sat for
	 * $age seconds, the way a crashed handler's would.
	 *
	 * @param string $fence The existing holder's fence.
	 * @param int    $age   Seconds to backdate the claim by.
	 */
	private function backdate_claim( string $fence, int $age ): void {
		$value = $fence . '|' . ( time() - $age );
		$GLOBALS['_rows'][ Aura_Worker_Magic_Link::SITE_CLAIM ]    = $value;
		$GLOBALS['_options'][ Aura_Worker_Magic_Link::SITE_CLAIM ] = $value;
	}

	public function test_a_claim_recorded_121_seconds_old_is_seizable(): void {
		$original = Aura_Worker_Magic_Link::claim_site();
		$this->assertNotSame( '', $original );
		$this->backdate_claim( $original, 121 );

		$new = Aura_Worker_Magic_Link::claim_site();

		$this->assertNotSame( '', $new, 'a claim older than SITE_CLAIM_TAKEOVER_AFTER must be seizable' );
		$this->assertNotSame( $original, $new, 'the seizing caller gets its OWN fence, not the evicted one' );
		$this->assertTrue( Aura_Worker_Magic_Link::holds_site_claim( $new ) );
		Aura_Worker_Magic_Link::release_site( $new );
	}

	public function test_a_claim_recorded_119_seconds_old_is_not_seizable(): void {
		$original = Aura_Worker_Magic_Link::claim_site();
		$this->backdate_claim( $original, 119 );

		$second = Aura_Worker_Magic_Link::claim_site();

		$this->assertSame( '', $second, 'a claim not yet past SITE_CLAIM_TAKEOVER_AFTER must refuse a second claimant' );
		$this->assertTrue( Aura_Worker_Magic_Link::holds_site_claim( $original ), 'the original holder must still hold it' );
		Aura_Worker_Magic_Link::release_site( $original );
	}

	public function test_a_claim_recorded_exactly_at_the_threshold_is_not_seizable(): void {
		// "Exceeds" (I2's wording), not "reaches": the boundary itself sides
		// with the original holder, the same way a >= 3 vs > 3 choice
		// elsewhere in this codebase (MAX_SWAP_ATTEMPTS) is deliberate.
		$original = Aura_Worker_Magic_Link::claim_site();
		$this->backdate_claim( $original, Aura_Worker_Magic_Link::SITE_CLAIM_TAKEOVER_AFTER );

		$this->assertSame( '', Aura_Worker_Magic_Link::claim_site() );
		Aura_Worker_Magic_Link::release_site( $original );
	}

	public function test_a_seized_claims_original_holder_can_no_longer_write(): void {
		$original = Aura_Worker_Magic_Link::claim_site();
		$this->backdate_claim( $original, 121 );
		$new = Aura_Worker_Magic_Link::claim_site();
		$this->assertNotSame( '', $new );

		// The original holder's fence no longer matches the row a seize
		// replaced — every claim-conditional write it attempts now, and
		// accept_under_claim()'s I1 re-check, must see that.
		$this->assertFalse( Aura_Worker_Magic_Link::holds_site_claim( $original ) );
		$this->assertSame(
			0,
			Aura_Worker_Rules::write_option_if_claimed( 'aura_worker_dashboard_url', 'https://evicted.example', Aura_Worker_Magic_Link::SITE_CLAIM, $original ),
			'the evicted holder must not still be able to write under its old fence'
		);
		Aura_Worker_Magic_Link::release_site( $new );
	}

	public function test_a_row_with_no_recorded_timestamp_is_never_seized(): void {
		// Backward compatibility: a value with no "|<ts>" suffix (there is no
		// production writer of one today, but the format is not enforced
		// elsewhere either) must be treated as fresh, not as infinitely
		// stale.
		$GLOBALS['_rows'][ Aura_Worker_Magic_Link::SITE_CLAIM ]    = 'a-fence-with-no-timestamp';
		$GLOBALS['_options'][ Aura_Worker_Magic_Link::SITE_CLAIM ] = 'a-fence-with-no-timestamp';

		$this->assertSame( '', Aura_Worker_Magic_Link::claim_site() );
	}

	public function test_per_magic_link_claims_are_still_never_seized(): void {
		// The takeover is specific to the site-wide claim's callers
		// (claim_site(), handle_connect()'s own take); per-link claims keep
		// the original round-1 "no timed takeover, deliberately" reasoning
		// untouched by this task. Reflection is the only way to reach the
		// private per-link path directly from a test.
		$method = new ReflectionMethod( Aura_Worker_Magic_Link::class, 'claim_magic_link' );
		$method->setAccessible( true );

		$claim_key = 'aura_magic_claim_test-link';
		$original  = $method->invoke( null, $claim_key );
		$this->assertNotSame( '', $original );
		$value = $original . '|' . ( time() - 100000 ); // absurdly old
		$GLOBALS['_rows'][ $claim_key ]    = $value;
		$GLOBALS['_options'][ $claim_key ] = $value;

		$this->assertSame( '', $method->invoke( null, $claim_key ), 'a per-link claim must not be seizable regardless of age' );
	}
}
