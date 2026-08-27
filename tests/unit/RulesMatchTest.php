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
	// -----------------------------------------------------------------------
	// 2.12.0 — a rule may name the sites it applies to (Aura spec §4). The
	// predicate is normative and fail-closed: a site that cannot prove its own
	// identity enforces EVERY scoped rule, exactly as an unreadable `touches`
	// declaration matches every rule rather than none.
	// -----------------------------------------------------------------------

	/** A rule scoped to the given site ids. */
	private function scoped( string $key, string $effect, array $sites ): array {
		$rule          = $this->rule( $key, $effect, 'site' );
		$rule['sites'] = $sites;
		return $rule;
	}

	public function test_a_scoped_rule_skips_a_foreign_site_and_hits_its_own(): void {
		$rule  = $this->scoped( 'rule/checkout', 'block', array( 'res_A' ) );
		$touch = array( array( 'type' => 'post', 'id' => '7' ) );
		$this->assertNull( Aura_Worker_Rules::match( $touch, array( $rule ), 1000, 'res_B' ) );
		$this->assertSame( 'rule/checkout', Aura_Worker_Rules::match( $touch, array( $rule ), 1000, 'res_A' )['key'] );
	}

	public function test_an_unknown_identity_enforces_everything(): void {
		// The site does not know who it is: a pre-2.12 record, a document that
		// carried no site_ref, a repair that has not run. Over-block.
		$rule  = $this->scoped( 'rule/checkout', 'block', array( 'res_A' ) );
		$touch = array( array( 'type' => 'post', 'id' => '7' ) );
		$this->assertSame( 'rule/checkout', Aura_Worker_Rules::match( $touch, array( $rule ), 1000, '' )['key'] );
		// …and the default argument is that same unknown, so any caller that
		// has not been taught to pass an identity keeps enforcing everything.
		$this->assertSame( 'rule/checkout', Aura_Worker_Rules::match( $touch, array( $rule ), 1000 )['key'] );
	}

	public function test_a_rule_without_sites_is_client_wide_regardless(): void {
		$rule = $this->rule( 'rule/freeze', 'warn', 'site' );
		$this->assertSame(
			'rule/freeze',
			Aura_Worker_Rules::match( array( array( 'type' => 'post', 'id' => '7' ) ), array( $rule ), 1000, 'res_B' )['key']
		);
	}

	public function test_a_malformed_sites_value_is_treated_as_client_wide(): void {
		// Aura's validator refuses these at write time. If one arrives anyway,
		// the site does not guess at a NARROWING it cannot read.
		foreach ( array( 'res_A', 42, new stdClass(), array() ) as $junk ) {
			$rule          = $this->rule( 'rule/checkout', 'block', 'site' );
			$rule['sites'] = $junk;
			$this->assertSame(
				'rule/checkout',
				Aura_Worker_Rules::match( array( array( 'type' => 'post', 'id' => '7' ) ), array( $rule ), 1000, 'res_B' )['key'],
				'an unreadable sites value narrowed a rule away'
			);
		}
	}

	public function test_scoping_is_by_exact_id_never_a_loose_comparison(): void {
		// in_array with strict comparison: a differently-cased or padded id is
		// a different id, and a prefix is not a match.
		$rule  = $this->scoped( 'rule/checkout', 'block', array( 'res_A' ) );
		$touch = array( array( 'type' => 'post', 'id' => '7' ) );
		foreach ( array( 'res_AB', 'RES_A', ' res_A', 'res' ) as $other ) {
			$this->assertNull( Aura_Worker_Rules::match( $touch, array( $rule ), 1000, $other ), "{$other} matched res_A" );
		}
		$numeric = $this->scoped( 'rule/num', 'block', array( '0' ) );
		$this->assertNull( Aura_Worker_Rules::match( $touch, array( $numeric ), 1000, 'res_A' ), 'a non-numeric id matched "0"' );
	}

	public function test_a_foreign_scoped_block_does_not_shadow_a_local_warn(): void {
		// The block wins outright when it applies. When it is scoped elsewhere
		// it must be skipped entirely — not merely demoted — or the warn that
		// follows it would be lost with it.
		$foreign = $this->scoped( 'rule/elsewhere', 'block', array( 'res_A' ) );
		$local   = $this->rule( 'rule/local', 'warn', 'site' );
		$hit     = Aura_Worker_Rules::match( array( array( 'type' => 'post', 'id' => '7' ) ), array( $foreign, $local ), 1000, 'res_B' );
		$this->assertSame( 'rule/local', $hit['key'] );
	}

	public function test_an_expired_scoped_rule_stays_expired(): void {
		$rule          = $this->rule( 'rule/checkout', 'block', 'site', null, '2020-01-01T00:00:00Z' );
		$rule['sites'] = array( 'res_A' );
		$this->assertNull( Aura_Worker_Rules::match( array( array( 'type' => 'post', 'id' => '7' ) ), array( $rule ), 1800000000, 'res_A' ) );
	}
	// -----------------------------------------------------------------------
	// Codex round 1 — three ways the scoping could betray its own promise.
	// -----------------------------------------------------------------------

	public function test_a_malformed_sites_list_never_narrows(): void {
		// `accept()` does not validate individual rules: a rule is enforced
		// from whatever was signed. A non-empty list that is not a list of
		// non-empty strings is therefore possible, and treating it as a
		// narrowing would fail OPEN — the strict comparison could never match,
		// so the rule would be skipped on EVERY site.
		$touch = array( array( 'type' => 'post', 'id' => '7' ) );
		$cases = array(
			'ints'            => array( 42 ),
			'mixed'           => array( 'res_A', 42 ),
			'nested'          => array( array( 'res_A' ) ),
			'object-shaped'   => array( 'a' => 'res_A' ),
			'empty string id' => array( '' ),
			'null id'         => array( null ),
			'bool id'         => array( true ),
		);
		foreach ( $cases as $label => $sites ) {
			$rule          = $this->rule( 'rule/checkout', 'block', 'site' );
			$rule['sites'] = $sites;
			$this->assertSame(
				'rule/checkout',
				Aura_Worker_Rules::match( $touch, array( $rule ), 1000, 'res_B' )['key'],
				"an unreadable sites value ({$label}) narrowed a rule away"
			);
		}
	}

	public function test_one_junk_entry_does_not_disarm_the_whole_rule(): void {
		// The dangerous half of the case above: `['res_A', 42]` on res_A. If
		// the junk made this "a narrowing", res_A would still be matched — but
		// on res_B the rule would vanish. Client-wide is the only reading that
		// cannot lose an enforcement.
		$rule          = $this->rule( 'rule/checkout', 'block', 'site' );
		$rule['sites'] = array( 'res_A', 42 );
		foreach ( array( 'res_A', 'res_B', '' ) as $ref ) {
			$this->assertSame(
				'rule/checkout',
				Aura_Worker_Rules::match( array( array( 'type' => 'post', 'id' => '7' ) ), array( $rule ), 1000, $ref )['key']
			);
		}
	}
}
