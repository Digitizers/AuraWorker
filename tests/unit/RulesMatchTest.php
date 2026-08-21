<?php
/**
 * The matcher is a pure function of two arrays, so every combination that
 * would ever decide a refusal is exercised here without WordPress.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class RulesMatchTest extends TestCase {

	private function rule( string $key, string $effect, string $type, ?string $id = null, ?string $until = null ): array {
		return array(
			'key'    => $key,
			'effect' => $effect,
			'target' => array( 'type' => $type, 'id' => $id ),
			'reason' => "because {$key}",
			'until'  => $until,
		);
	}

	public function test_no_rules_matches_nothing(): void {
		$this->assertNull( Aura_Worker_Rules::match( array( array( 'type' => 'page', 'id' => '7' ) ), array() ) );
	}

	public function test_a_site_rule_matches_every_call(): void {
		$freeze = $this->rule( 'rule/freeze', 'block', 'site' );
		foreach ( array( 'site' => '*', 'page' => '7', 'post' => '7', 'plugin' => 'woocommerce' ) as $type => $id ) {
			$hit = Aura_Worker_Rules::match( array( array( 'type' => $type, 'id' => $id ) ), array( $freeze ) );
			$this->assertSame( 'rule/freeze', $hit['key'], "site rule missed {$type}:{$id}" );
		}
	}

	public function test_a_page_rule_matches_the_same_id_declared_as_post(): void {
		// An operator does not know whether "checkout" is a page or a post.
		$rule = $this->rule( 'rule/checkout', 'block', 'page', '7' );
		$this->assertNotNull( Aura_Worker_Rules::match( array( array( 'type' => 'post', 'id' => '7' ) ), array( $rule ) ) );
		$this->assertNotNull( Aura_Worker_Rules::match( array( array( 'type' => 'page', 'id' => '7' ) ), array( $rule ) ) );
		$this->assertNull( Aura_Worker_Rules::match( array( array( 'type' => 'page', 'id' => '8' ) ), array( $rule ) ) );
	}

	public function test_a_plugin_rule_does_not_match_a_page_call(): void {
		$rule = $this->rule( 'rule/woo', 'block', 'plugin', 'woocommerce' );
		$this->assertNull( Aura_Worker_Rules::match( array( array( 'type' => 'page', 'id' => '7' ) ), array( $rule ) ) );
		$this->assertNotNull( Aura_Worker_Rules::match( array( array( 'type' => 'plugin', 'id' => 'woocommerce' ) ), array( $rule ) ) );
	}

	public function test_block_wins_over_warn_on_the_same_resource(): void {
		$rules = array(
			$this->rule( 'rule/warn-it', 'warn', 'page', '7' ),
			$this->rule( 'rule/block-it', 'block', 'page', '7' ),
		);
		$hit = Aura_Worker_Rules::match( array( array( 'type' => 'page', 'id' => '7' ) ), $rules );
		$this->assertSame( 'block', $hit['effect'] );
		$this->assertSame( 'rule/block-it', $hit['key'] );
	}

	public function test_an_expired_rule_is_skipped(): void {
		$now  = 1_800_000_000;
		$dead = $this->rule( 'rule/old', 'block', 'site', null, gmdate( 'c', $now - 60 ) );
		$this->assertTrue( Aura_Worker_Rules::is_expired( $dead, $now ) );
		$this->assertNull( Aura_Worker_Rules::match( array( array( 'type' => 'page', 'id' => '1' ) ), array( $dead ), $now ) );
	}

	public function test_a_future_until_is_still_live(): void {
		$now  = 1_800_000_000;
		$live = $this->rule( 'rule/soon', 'block', 'site', null, gmdate( 'c', $now + 60 ) );
		$this->assertFalse( Aura_Worker_Rules::is_expired( $live, $now ) );
		$this->assertNotNull( Aura_Worker_Rules::match( array( array( 'type' => 'page', 'id' => '1' ) ), array( $live ), $now ) );
	}

	public function test_an_unparseable_until_is_treated_as_expired(): void {
		// A rule we cannot date is a rule we cannot claim is live.
		$rule = $this->rule( 'rule/odd', 'block', 'site', null, 'not-a-date' );
		$this->assertTrue( Aura_Worker_Rules::is_expired( $rule, 1_800_000_000 ) );
	}

	public function test_an_unknown_touch_matches_every_rule(): void {
		// The base-class default. A tool that never declared itself must be
		// caught by a page rule and a plugin rule, not only by a freeze.
		$undeclared = array( array( 'type' => 'unknown', 'id' => '*' ) );
		foreach ( array(
			$this->rule( 'rule/page', 'block', 'page', '7' ),
			$this->rule( 'rule/plugin', 'block', 'plugin', 'woocommerce' ),
			$this->rule( 'rule/freeze', 'block', 'site' ),
		) as $rule ) {
			$this->assertNotNull( Aura_Worker_Rules::match( $undeclared, array( $rule ) ), "{$rule['key']} missed an undeclared tool" );
		}
	}

	public function test_an_explicit_site_touch_is_not_caught_by_a_page_rule(): void {
		// Maintenance tools declare site:* on purpose; a page rule must not stop
		// a cache flush. This is the distinction the sentinel exists to keep.
		$rule = $this->rule( 'rule/page', 'block', 'page', '7' );
		$this->assertNull( Aura_Worker_Rules::match( array( array( 'type' => 'site', 'id' => '*' ) ), array( $rule ) ) );
		$this->assertNotNull( Aura_Worker_Rules::match( array( array( 'type' => 'site', 'id' => '*' ) ), array( $this->rule( 'rule/freeze', 'block', 'site' ) ) ) );
	}

	public function test_a_rule_with_an_unknown_type_never_matches(): void {
		// Aura rejects these at write time; the site must not guess if one arrives.
		$rule = $this->rule( 'rule/weird', 'block', 'theme', 'astra' );
		$this->assertNull( Aura_Worker_Rules::match( array( array( 'type' => 'site', 'id' => '*' ) ), array( $rule ) ) );
	}

	public function test_malformed_touches_entries_do_not_buy_an_exemption(): void {
		// Garbage in a declaration is not a narrow declaration. Every entry
		// here is unusable, so the call has told us nothing — and "nothing"
		// is the sentinel, which every live rule catches. The alternative
		// reading (an empty set matches no rule) would make `[]` the cheapest
		// way for a mutating tool to escape a freeze.
		$rule = $this->rule( 'rule/checkout', 'block', 'page', '7' );
		$hit  = Aura_Worker_Rules::match( array( 'not-an-array', array( 'type' => 'page' ) ), array( $rule ) );
		$this->assertNotNull( $hit, 'a declaration of pure garbage escaped a rule' );
		$this->assertSame( 'rule/checkout', $hit['key'] );
	}

	public function test_a_declaration_of_only_unknown_types_is_not_a_narrow_one(): void {
		// `theme` is outside the vocabulary. Left in the set it would be a
		// non-empty declaration that no page or plugin rule can match — the
		// empty-declaration exemption under another name. It collapses to the
		// sentinel, so every live rule still applies.
		foreach ( array(
			$this->rule( 'rule/checkout', 'block', 'page', '7' ),
			$this->rule( 'rule/wc', 'block', 'plugin', 'woocommerce' ),
			$this->rule( 'rule/freeze', 'block', 'site' ),
		) as $rule ) {
			foreach ( array(
				array( array( 'type' => 'theme', 'id' => 'astra' ) ),          // outside the vocabulary
				array( array( 'type' => 'unknown', 'id' => 'x' ) ),           // the sentinel, misspelt id
				array( array( 'type' => 'unknown', 'id' => '7' ) ),           // ...and one that looks narrow
			) as $declared ) {
				$this->assertNotNull(
					Aura_Worker_Rules::match( $declared, array( $rule ) ),
					"an unreadable declaration escaped {$rule['key']}"
				);
			}
		}
	}

	public function test_a_usable_entry_beside_a_malformed_one_still_narrows(): void {
		// The fallback is for declarations that yield NOTHING. One good entry
		// is a real declaration, and the junk beside it is simply dropped: a
		// page-7 rule does not catch a call that only touches page 9.
		$rule = $this->rule( 'rule/checkout', 'block', 'page', '7' );
		$this->assertNull(
			Aura_Worker_Rules::match(
				array( 'not-an-array', array( 'type' => 'page', 'id' => '9' ) ),
				array( $rule )
			)
		);
	}
}
