<?php
/**
 * THE LEASE, AND THE LAST WORD BEFORE SUCCESS (#434, Codex round-8).
 *
 * seize_stale_claim() bounds a claim stranded by a fatal to
 * SITE_CLAIM_TAKEOVER_AFTER seconds. Its docblock says a claim "a live request
 * refreshing it" cannot be seized — and nothing refreshed one, so a connect
 * that legitimately overran the window became seizable while it was still
 * working, and a replacement could revoke the credentials it was about to
 * return.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class SiteClaimLeaseTest extends TestCase {

	protected function setUp(): void {
		sa_reset_state();
	}

	/** Rewrite the claim row's timestamp, the way a long-running handler ages it. */
	private function age_the_claim( string $fence, int $seconds ): void {
		$value = $fence . '|' . ( time() - $seconds );
		$GLOBALS['_options'][ Aura_Worker_Magic_Link::SITE_CLAIM ] = $value;
		$GLOBALS['_rows'][ Aura_Worker_Magic_Link::SITE_CLAIM ]    = $value;
	}

	public function test_a_refreshed_claim_is_no_longer_seizable(): void {
		$fence = Aura_Worker_Magic_Link::claim_site();
		$this->age_the_claim( $fence, Aura_Worker_Magic_Link::SITE_CLAIM_TAKEOVER_AFTER + 60 );

		$this->assertTrue( Aura_Worker_Magic_Link::touch_site_claim( $fence ) );

		$this->assertSame( '', Aura_Worker_Magic_Link::claim_site(), 'a working handler was evicted anyway' );
		$this->assertTrue( Aura_Worker_Magic_Link::holds_site_claim( $fence ) );
	}

	/** Without the refresh, the same claim IS seizable — the window this closes. */
	public function test_an_unrefreshed_stale_claim_is_seizable(): void {
		$fence = Aura_Worker_Magic_Link::claim_site();
		$this->age_the_claim( $fence, Aura_Worker_Magic_Link::SITE_CLAIM_TAKEOVER_AFTER + 60 );

		$this->assertNotSame( '', Aura_Worker_Magic_Link::claim_site() );
		$this->assertFalse( Aura_Worker_Magic_Link::holds_site_claim( $fence ) );
	}

	/** A fence that no longer holds the site cannot refresh it back. */
	public function test_a_lost_claim_cannot_be_refreshed(): void {
		$fence = Aura_Worker_Magic_Link::claim_site();
		$this->age_the_claim( $fence, Aura_Worker_Magic_Link::SITE_CLAIM_TAKEOVER_AFTER + 60 );
		$seizer = Aura_Worker_Magic_Link::claim_site();

		$this->assertNotSame( '', $seizer );
		$this->assertFalse( Aura_Worker_Magic_Link::touch_site_claim( $fence ) );
		$this->assertTrue( Aura_Worker_Magic_Link::holds_site_claim( $seizer ), "the loser's refresh must not disturb the winner" );
	}

	public function test_an_empty_fence_refreshes_nothing(): void {
		Aura_Worker_Magic_Link::claim_site();
		$this->assertFalse( Aura_Worker_Magic_Link::touch_site_claim( '' ) );
	}
}
