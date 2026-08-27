<?php
/**
 * audit_rules reports whether a ruleset is present, how old it is, and what
 * enforcement has done — facts, not judgements. The fleet rollup decides what
 * they mean.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class AuditRulesTest extends TestCase {

	protected function setUp(): void {
		sa_reset_state();
		Aura_Worker_Rules::init();
	}

	private function run_tool(): array {
		$tools = new Aura_Worker_Tools();
		$res   = $tools->execute_tool( 'audit_rules', array() );
		$this->assertTrue( $res['success'] );
		return $res['result'];
	}

	public function test_is_read_only_and_needs_no_approval(): void {
		$tools = new Aura_Worker_Tools();
		$ann   = $tools->get_tool( 'audit_rules' )->get_annotations();
		$this->assertTrue( $ann['read_only'] );
		$this->assertFalse( $ann['requires_approval'] );
	}

	public function test_no_ruleset_is_reported_as_null_not_empty(): void {
		$r = $this->run_tool();
		$this->assertNull( $r['ruleset'] );
		$this->assertSame( 0, $r['enforcement']['blocked_24h'] );
		$this->assertSame( array( 'execute_tool', 'rest_updates', 'core_rest_content' ), $r['enforcement']['points'] );
	}

	public function test_reports_whether_the_site_can_verify_a_ruleset_at_all(): void {
		$this->assertFalse( $this->run_tool()['keyed'] );
		// Through update_option(), the way connect stores it: the first read
		// above was a miss, and core's `notoptions` remembers misses until a
		// real write forgets them. Seeding the cache array directly would
		// model a key that appeared behind WordPress's back.
		update_option( 'aura_worker_grant_pubkey', base64_encode( str_repeat( 'k', 32 ) ) );
		$this->assertTrue( $this->run_tool()['keyed'] );
		// Present but unusable is not keyed: the rollup must reach
		// ruleset_unverifiable and say "reconnect", not report healthy.
		update_option( 'aura_worker_grant_pubkey', base64_encode( str_repeat( 'k', 16 ) ) );
		$this->assertFalse( $this->run_tool()['keyed'] );
	}

	public function test_a_ruleset_is_described(): void {
		$GLOBALS['_options'][ Aura_Worker_Rules::OPTION ] = array(
			'envelope'    => 'x.y',
			'seq'         => 9,
			'issued_at'   => '2026-08-21T10:00:00Z',
			'received_at' => 1_800_000_000,
			'rules'       => array(
				array( 'key' => 'rule/a', 'effect' => 'block', 'target' => array( 'type' => 'site' ), 'reason' => '' ),
				array( 'key' => 'rule/old', 'effect' => 'block', 'target' => array( 'type' => 'site' ), 'reason' => '', 'until' => '2020-01-01T00:00:00Z' ),
			),
		);
		$r = $this->run_tool();
		$this->assertSame( 9, $r['ruleset']['seq'] );
		$this->assertSame( 2, $r['ruleset']['rule_count'] );
		$this->assertSame( array( 'rule/old' ), $r['enforcement']['expired_active'] );
	}

	public function test_blocks_and_warns_are_counted(): void {
		do_action( 'aura_worker_rule_blocked', 'x', array( 'key' => 'rule/a' ) );
		do_action( 'aura_worker_rule_blocked', 'x', array( 'key' => 'rule/a' ) );
		do_action( 'aura_worker_rule_warned', 'x', array( 'key' => 'rule/b' ) );
		$r = $this->run_tool();
		$this->assertSame( 2, $r['enforcement']['blocked_24h'] );
		$this->assertSame( 1, $r['enforcement']['warned_24h'] );
	}

	public function test_the_window_really_is_24_hours_on_a_busy_site(): void {
		// A transient with its TTL reset on every bump would count forever on
		// any site that blocks once a day. Hour-options older than 24h are not
		// summed, and are deleted on the next bump.
		$now = 1_800_000_000;
		$GLOBALS['_options'][ Aura_Worker_Rules::bucket_name( Aura_Worker_Rules::BLOCKED_COUNTER, (int) floor( ( $now - 30 * HOUR_IN_SECONDS ) / HOUR_IN_SECONDS ) ) ] = '5'; // too old
		$GLOBALS['_options'][ Aura_Worker_Rules::bucket_name( Aura_Worker_Rules::BLOCKED_COUNTER, (int) floor( ( $now - 2 * HOUR_IN_SECONDS ) / HOUR_IN_SECONDS ) ) ]  = '3';
		$this->assertSame( 3, Aura_Worker_Rules::count_24h( Aura_Worker_Rules::BLOCKED_COUNTER, $now ) );
	}

	public function test_the_boundary_hour_is_kept_not_dropped(): void {
		// Buckets are hour-granular and the window is not. At 10:30 the bucket
		// for yesterday 10:00 still holds events from 10:30–10:59 that are
		// younger than 24h; dropping the whole bucket would lose up to an hour
		// of the freshest-but-oldest events. The boundary bucket is kept (the
		// count may then include up to 59 minutes beyond 24h — it never omits).
		// The bucket before it is 25h+ old in every second and goes.
		$now      = 1_800_001_800; // 30 minutes past an hour boundary.
		$boundary = (int) floor( ( $now - DAY_IN_SECONDS ) / HOUR_IN_SECONDS );
		$GLOBALS['_options'][ Aura_Worker_Rules::bucket_name( Aura_Worker_Rules::BLOCKED_COUNTER, $boundary - 1 ) ] = '7'; // 25h+ old: not counted.
		$GLOBALS['_options'][ Aura_Worker_Rules::bucket_name( Aura_Worker_Rules::BLOCKED_COUNTER, $boundary ) ]     = '2'; // straddles 24h: kept.
		$this->assertSame( 2, Aura_Worker_Rules::count_24h( Aura_Worker_Rules::BLOCKED_COUNTER, $now ) );
	}

	public function test_the_increment_is_atomic_not_read_modify_write(): void {
		// Two refusals landing in the same second on a busy site must both be
		// counted. get_option + update_option lets the second overwrite the
		// first; a single UPDATE ... SET option_value = option_value + 1 does
		// not. The stub $wpdb records the SQL it ran: that is the assertion.
		$now  = 1_800_001_800;
		$name = Aura_Worker_Rules::bucket_name( Aura_Worker_Rules::BLOCKED_COUNTER, (int) floor( $now / HOUR_IN_SECONDS ) );
		Aura_Worker_Rules::record_block( 'x', array(), $now );
		Aura_Worker_Rules::record_block( 'x', array(), $now );
		$increments = array_filter( $GLOBALS['_db_queries'], static function ( $q ) use ( $name ) {
			return false !== strpos( $q, 'option_value = option_value + 1' ) && false !== strpos( $q, $name );
		} );
		$this->assertCount( 2, $increments, 'the counter was not bumped with an atomic UPDATE' );
		$this->assertSame( '2', $GLOBALS['_options'][ $name ], 'the stub emulation did not apply the increment' );
		$this->assertSame( 2, Aura_Worker_Rules::count_24h( Aura_Worker_Rules::BLOCKED_COUNTER, $now ) );
	}

	public function test_a_bucket_read_before_its_first_bump_is_still_counted_after_it(): void {
		// count_24h() reads every bucket in the window through get_option(),
		// and a miss lists the name in core's `notoptions` negative cache.
		// bump()'s raw INSERT then creates the row behind that cache's back;
		// evicting only the per-key entry leaves `notoptions` saying "absent",
		// so every later read — the rest of this request, and on a site with
		// a persistent object cache every request after — answers 0 for a row
		// that exists. audit_rules before the first refusal of the hour is
		// exactly this read-before-bump sequence.
		$now = 1_800_001_800;
		$this->assertSame( 0, Aura_Worker_Rules::count_24h( Aura_Worker_Rules::BLOCKED_COUNTER, $now ) );
		Aura_Worker_Rules::record_block( 'x', array(), $now );
		$this->assertSame(
			1,
			Aura_Worker_Rules::count_24h( Aura_Worker_Rules::BLOCKED_COUNTER, $now ),
			'a bucket created after a negative-cache miss is invisible to count_24h()'
		);
	}

	public function test_old_hour_options_are_deleted_on_bump(): void {
		// Each bump sweeps hour-options older than the boundary, so the table
		// does not accumulate one row per hour forever.
		$now      = 1_800_001_800;
		$boundary = (int) floor( ( $now - DAY_IN_SECONDS ) / HOUR_IN_SECONDS );
		$old      = Aura_Worker_Rules::bucket_name( Aura_Worker_Rules::BLOCKED_COUNTER, $boundary - 3 );
		$kept     = Aura_Worker_Rules::bucket_name( Aura_Worker_Rules::BLOCKED_COUNTER, $boundary );
		// Both stores: get_col() enumerates $_rows, which is the "database";
		// $_options is the cache in front of it. A fixture in only one is a
		// fixture the sweep cannot see.
		$GLOBALS['_options'][ $old ]  = '9';
		$GLOBALS['_rows'][ $old ]     = maybe_serialize( '9' );
		$GLOBALS['_options'][ $kept ] = '1';
		$GLOBALS['_rows'][ $kept ]    = maybe_serialize( '1' );
		Aura_Worker_Rules::record_block( 'x', array(), $now );
		$this->assertArrayNotHasKey( $old, $GLOBALS['_options'] );
		$this->assertSame( '1', $GLOBALS['_options'][ $kept ] );
	}

	public function test_an_expired_rule_is_announced_once_a_day(): void {
		// An expired rule is ignored for matching, which is why it has to be
		// announced: it looks like protection and is not. Once per rule per
		// day — a busy site must not turn that into one event per call.
		$now = 1_800_000_000;
		$GLOBALS['_options'][ Aura_Worker_Rules::OPTION ] = array(
			'envelope' => 'x.y', 'client' => 'c1', 'seq' => 1, 'issued_at' => '', 'received_at' => $now,
			'rules'    => array(
				array( 'key' => 'rule/old', 'effect' => 'block', 'target' => array( 'type' => 'site' ), 'reason' => '', 'until' => '2020-01-01T00:00:00Z' ),
				array( 'key' => 'rule/live', 'effect' => 'warn', 'target' => array( 'type' => 'site' ), 'reason' => '' ),
			),
		);
		$fired = static function () {
			return array_values( array_filter( $GLOBALS['_did_actions'], static function ( $a ) {
				return 'aura_worker_rule_expired' === $a['tag'];
			} ) );
		};

		Aura_Worker_Rules::enforce( array( array( 'type' => 'site', 'id' => '*' ) ), 'x', $now );
		Aura_Worker_Rules::enforce( array( array( 'type' => 'site', 'id' => '*' ) ), 'x', $now + 60 );

		$events = $fired();
		$this->assertCount( 1, $events, 'the expired rule was announced more than once in a day' );
		$this->assertSame( 'rule/old', $events[0]['args'][0] );

		// A new day, the rule still listed: announced again.
		Aura_Worker_Rules::enforce( array( array( 'type' => 'site', 'id' => '*' ) ), 'x', $now + DAY_IN_SECONDS );
		$this->assertCount( 2, $fired() );

		// And the older claims are cleaned up, so the table does not grow a row
		// per rule per day forever.
		$hash = substr( hash( 'sha256', 'rule/old' ), 0, 20 );
		$this->assertArrayNotHasKey(
			Aura_Worker_Rules::expired_claim( $hash, (int) floor( $now / DAY_IN_SECONDS ) ),
			$GLOBALS['_options']
		);
	}

	public function test_a_retired_rules_claim_dies_with_its_day(): void {
		// A rule that is released or renamed is never visited again, so nothing
		// can sweep its claim BY NAME. Claims are swept by day instead: the
		// next enforcement on a later day drops every claim older than today,
		// whatever rule it belonged to. No coupling to the ruleset, so no
		// interleaving of two pushes can delete a claim the winner needs.
		$now      = 1_800_000_000;
		$day      = (int) floor( $now / DAY_IN_SECONDS );
		$retired  = Aura_Worker_Rules::expired_claim( Aura_Worker_Rules::rule_hash( 'rule/retired' ), $day - 1 );
		$today    = Aura_Worker_Rules::expired_claim( Aura_Worker_Rules::rule_hash( 'rule/live' ), $day );
		foreach ( array( $retired, $today ) as $name ) {
			$GLOBALS['_options'][ $name ] = 1;
			$GLOBALS['_rows'][ $name ]    = maybe_serialize( 1 );
		}
		$GLOBALS['_options'][ Aura_Worker_Rules::OPTION ] = array(
			'envelope' => 'x.y', 'client' => 'c1', 'seq' => 1, 'issued_at' => '', 'received_at' => $now,
			'rules'    => array( array( 'key' => 'rule/live', 'effect' => 'block', 'target' => array( 'type' => 'site' ), 'reason' => '', 'until' => '2020-01-01T00:00:00Z' ) ),
		);

		Aura_Worker_Rules::enforce( array( array( 'type' => 'site', 'id' => '*' ) ), 'x', $now );

		$this->assertArrayNotHasKey( $retired, $GLOBALS['_options'], "a retired rule's claim outlived its day" );
		$this->assertArrayHasKey( $today, $GLOBALS['_options'], "today's claim was swept and the rule will re-announce" );
	}

	public function test_a_swept_claim_goes_through_the_cache_aware_delete(): void {
		// A raw `DELETE ... LIKE` would remove the rows and leave their
		// `options` cache entries on a site with a persistent object cache. A
		// stale entry for a deleted claim is worse than the claim: add_option()
		// consults the cache, sees a claim that is not there, returns false,
		// and the announcement it was meant to permit never fires. It would
		// also delete rows the sweep never read — a claim inserted by an
		// enforcement already in flight — whose cached value nothing then
		// knows to evict.
		$now   = 1_800_000_000;
		$day   = (int) floor( $now / DAY_IN_SECONDS );
		$stale = Aura_Worker_Rules::expired_claim( Aura_Worker_Rules::rule_hash( 'rule/retired' ), $day - 1 );
		$GLOBALS['_options'][ $stale ] = 1;
		$GLOBALS['_rows'][ $stale ]    = maybe_serialize( 1 );
		$GLOBALS['_options'][ Aura_Worker_Rules::OPTION ] = array(
			'envelope' => 'x.y', 'client' => 'c1', 'seq' => 1, 'issued_at' => '', 'received_at' => $now,
			'rules'    => array( array( 'key' => 'rule/live', 'effect' => 'block', 'target' => array( 'type' => 'site' ), 'reason' => '', 'until' => null ) ),
		);

		Aura_Worker_Rules::enforce( array( array( 'type' => 'site', 'id' => '*' ) ), 'x', $now );

		$this->assertArrayNotHasKey( $stale, $GLOBALS['_options'], 'the stale claim survived the sweep' );
		$this->assertArrayNotHasKey( $stale, $GLOBALS['_rows'], 'the row survived the sweep' );
		$this->assertEmpty(
			array_filter( $GLOBALS['_db_queries'], static function ( $q ) {
				return false !== strpos( $q, 'DELETE' ) && false !== strpos( $q, Aura_Worker_Rules::EXPIRED_NOTICE );
			} ),
			'the sweep deleted by name-pattern instead of deleting the names it read'
		);
	}

	public function test_the_sweep_does_not_depend_on_there_being_a_rule_to_announce(): void {
		// The strongest form of the retired-rule case: NOTHING in the current
		// ruleset is expired, so no claim can be created today. If the sweep
		// rode on a successful claim it would never run again, and yesterday's
		// rows would be permanent. It is claimed for the day in its own right.
		$now   = 1_800_000_000;
		$day   = (int) floor( $now / DAY_IN_SECONDS );
		$stale = Aura_Worker_Rules::expired_claim( Aura_Worker_Rules::rule_hash( 'rule/retired' ), $day - 1 );
		$GLOBALS['_options'][ $stale ] = 1;
		$GLOBALS['_rows'][ $stale ]    = maybe_serialize( 1 );
		$GLOBALS['_options'][ Aura_Worker_Rules::OPTION ] = array(
			'envelope' => 'x.y', 'client' => 'c1', 'seq' => 1, 'issued_at' => '', 'received_at' => $now,
			'rules'    => array( array( 'key' => 'rule/live', 'effect' => 'block', 'target' => array( 'type' => 'site' ), 'reason' => '', 'until' => null ) ),
		);

		Aura_Worker_Rules::enforce( array( array( 'type' => 'site', 'id' => '*' ) ), 'x', $now );

		$this->assertArrayNotHasKey( $stale, $GLOBALS['_options'], 'the sweep never ran because nothing was expired' );
	}

	public function test_the_sweep_itself_runs_once_a_day_not_once_a_call(): void {
		// It is claimed like any other statement about a day, so a busy site
		// pays one DELETE a day, not one per enforcement.
		$now = 1_800_000_000;
		$GLOBALS['_options'][ Aura_Worker_Rules::OPTION ] = array(
			'envelope' => 'x.y', 'client' => 'c1', 'seq' => 1, 'issued_at' => '', 'received_at' => $now,
			'rules'    => array( array( 'key' => 'rule/live', 'effect' => 'block', 'target' => array( 'type' => 'site' ), 'reason' => '', 'until' => null ) ),
		);

		// Any statement naming the claim prefix: the sweep reads the rows it is
		// about to delete, so counting only DELETEs would miss a sweep that
		// ran and found nothing.
		$sweeps = static function () {
			return count( array_filter( $GLOBALS['_db_queries'], static function ( $q ) {
				return false !== strpos( $q, Aura_Worker_Rules::EXPIRED_NOTICE );
			} ) );
		};

		Aura_Worker_Rules::enforce( array( array( 'type' => 'site', 'id' => '*' ) ), 'x', $now );
		$this->assertSame( 1, $sweeps() );
		Aura_Worker_Rules::enforce( array( array( 'type' => 'site', 'id' => '*' ) ), 'x', $now + 60 );
		$this->assertSame( 1, $sweeps(), 'the sweep ran again inside the same day' );
	}

	public function test_claims_from_days_that_were_skipped_are_swept_too(): void {
		// Enforcement is intermittent on most sites: a rule announced on day 10
		// may not be seen again until day 12. Deleting only "yesterday" would
		// leave day 10 behind for good, one row per rule per day the site
		// happened to be busy.
		$now  = 1_800_000_000;
		$day  = (int) floor( $now / DAY_IN_SECONDS );
		$hash = substr( hash( 'sha256', 'rule/old' ), 0, 20 );
		$GLOBALS['_options'][ Aura_Worker_Rules::OPTION ] = array(
			'envelope' => 'x.y', 'client' => 'c1', 'seq' => 1, 'issued_at' => '', 'received_at' => $now,
			'rules'    => array( array( 'key' => 'rule/old', 'effect' => 'block', 'target' => array( 'type' => 'site' ), 'reason' => '', 'until' => '2020-01-01T00:00:00Z' ) ),
		);
		foreach ( array( $day - 9, $day - 5, $day - 2 ) as $stale ) {
			$name = Aura_Worker_Rules::expired_claim( $hash, $stale );
			$GLOBALS['_options'][ $name ] = 1;
			$GLOBALS['_rows'][ $name ]    = maybe_serialize( 1 );
		}

		Aura_Worker_Rules::enforce( array( array( 'type' => 'site', 'id' => '*' ) ), 'x', $now );

		foreach ( array( $day - 9, $day - 5, $day - 2 ) as $stale ) {
			$this->assertArrayNotHasKey( Aura_Worker_Rules::expired_claim( $hash, $stale ), $GLOBALS['_options'], "the claim from day {$stale} was left behind" );
		}
		$this->assertArrayHasKey( Aura_Worker_Rules::expired_claim( $hash, $day ), $GLOBALS['_options'] );
	}

	public function test_the_daily_notice_is_claimed_not_read_then_written(): void {
		// Two requests meeting the same expired rule in the same day must not
		// both see "not fired yet" and both fire. The claim is an insert, and
		// only one insert can win; the stub's add_option() already returns
		// false when the row exists, which is the whole mechanism.
		$now = 1_800_000_000;
		$GLOBALS['_options'][ Aura_Worker_Rules::OPTION ] = array(
			'envelope' => 'x.y', 'client' => 'c1', 'seq' => 1, 'issued_at' => '', 'received_at' => $now,
			'rules'    => array( array( 'key' => 'rule/old', 'effect' => 'block', 'target' => array( 'type' => 'site' ), 'reason' => '', 'until' => '2020-01-01T00:00:00Z' ) ),
		);
		$hash = substr( hash( 'sha256', 'rule/old' ), 0, 20 );
		$day  = (int) floor( $now / DAY_IN_SECONDS );
		// The other request got there first.
		$GLOBALS['_options'][ Aura_Worker_Rules::expired_claim( $hash, $day ) ] = 1;

		Aura_Worker_Rules::enforce( array( array( 'type' => 'site', 'id' => '*' ) ), 'x', $now );

		$this->assertEmpty( array_filter( $GLOBALS['_did_actions'], static function ( $a ) {
			return 'aura_worker_rule_expired' === $a['tag'];
		} ), 'a second request fired a notice another request had already claimed' );
	}

	public function test_a_live_rule_is_never_announced_as_expired(): void {
		$now = 1_800_000_000;
		$GLOBALS['_options'][ Aura_Worker_Rules::OPTION ] = array(
			'envelope' => 'x.y', 'client' => 'c1', 'seq' => 1, 'issued_at' => '', 'received_at' => $now,
			'rules'    => array( array( 'key' => 'rule/live', 'effect' => 'block', 'target' => array( 'type' => 'site' ), 'reason' => '' ) ),
		);
		Aura_Worker_Rules::enforce( array( array( 'type' => 'site', 'id' => '*' ) ), 'x', $now );
		$this->assertEmpty( array_filter( $GLOBALS['_did_actions'], static function ( $a ) {
			return 'aura_worker_rule_expired' === $a['tag'];
		} ) );
	}

	public function test_reports_bounded_coverage_like_every_audit_tool(): void {
		$r = $this->run_tool();
		$this->assertSame( array( 'total_seen' => 0, 'returned' => 0, 'truncated' => false, 'cap' => '' ), $r['coverage'] );
	}
	// -----------------------------------------------------------------------
	// 2.12.0 — enforce() is the bridge the elementor-mcp fork calls, so the
	// scoping has to arrive THERE, from the stored record, with no change on
	// the fork's side. These tests drive the whole path: a stored record, a
	// scoped rule, and the verdict the wrapper acts on.
	// -----------------------------------------------------------------------

	/** Store a record directly — the shape accept() writes. */
	private function store_record( array $rules, $site_ref = null ): void {
		$rec = array(
			'envelope'    => 'x.y',
			'client'      => 'c1',
			'seq'         => 1,
			'issued_at'   => '',
			'received_at' => 1800000000,
			'rules'       => $rules,
		);
		if ( null !== $site_ref ) {
			$rec['site_ref'] = $site_ref;
		}
		$GLOBALS['_options'][ Aura_Worker_Rules::OPTION ] = $rec;
		$GLOBALS['_rows'][ Aura_Worker_Rules::OPTION ]    = maybe_serialize( $rec );
	}

	public function test_enforce_skips_a_rule_scoped_to_another_site(): void {
		$this->store_record(
			array( array( 'key' => 'rule/elsewhere', 'effect' => 'block', 'target' => array( 'type' => 'site' ), 'reason' => 'r', 'sites' => array( 'res_A' ) ) ),
			'res_B'
		);
		$out = Aura_Worker_Rules::enforce( array( array( 'type' => 'post', 'id' => '7' ) ), 'x', 1800000000 );
		$this->assertNull( $out['effect'] );
	}

	public function test_enforce_applies_a_rule_scoped_to_this_site(): void {
		$this->store_record(
			array( array( 'key' => 'rule/mine', 'effect' => 'block', 'target' => array( 'type' => 'site' ), 'reason' => 'r', 'sites' => array( 'res_A' ) ) ),
			'res_A'
		);
		$out = Aura_Worker_Rules::enforce( array( array( 'type' => 'post', 'id' => '7' ) ), 'x', 1800000000 );
		$this->assertSame( 'block', $out['effect'] );
		$this->assertSame( 'rule/mine', $out['rule']['key'] );
	}

	public function test_enforce_on_a_record_written_before_2_12_enforces_everything(): void {
		// No site_ref key at all — the shape 2.11 wrote, before any repair.
		// The site cannot prove it is NOT the named one, so it obeys.
		$this->store_record(
			array( array( 'key' => 'rule/elsewhere', 'effect' => 'block', 'target' => array( 'type' => 'site' ), 'reason' => 'r', 'sites' => array( 'res_A' ) ) )
		);
		$out = Aura_Worker_Rules::enforce( array( array( 'type' => 'post', 'id' => '7' ) ), 'x', 1800000000 );
		$this->assertSame( 'block', $out['effect'], 'an unknown identity must over-block, never under-block' );
	}

	public function test_enforce_still_applies_client_wide_rules_unchanged(): void {
		// The regression that matters most: every rule without `sites` must
		// decide exactly as it did in 2.11, whatever the identity is.
		foreach ( array( 'res_A', '' ) as $ref ) {
			$this->store_record(
				array( array( 'key' => 'rule/freeze', 'effect' => 'block', 'target' => array( 'type' => 'site' ), 'reason' => 'r' ) ),
				$ref
			);
			$out = Aura_Worker_Rules::enforce( array( array( 'type' => 'post', 'id' => '7' ) ), 'x', 1800000000 );
			$this->assertSame( 'block', $out['effect'] );
		}
	}
}
