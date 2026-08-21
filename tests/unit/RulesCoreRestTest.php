<?php
/**
 * A rule on a page holds against WordPress core's own REST API, not only
 * against SiteAgent's tools — because that is where Aura's content tools, an
 * app-password agent, and a second MCP server all write.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class RulesCoreRestTest extends TestCase {

	protected function setUp(): void {
		sa_reset_state();
		Aura_Worker_Rules::$rest_request_override = true;
		Aura_Worker_Rules::$cookie_auth_override  = false; // not a cookie session...
		$GLOBALS['_logged_in']                    = true;  // ...but authenticated: an agent, unless a test says otherwise
		Aura_Worker_Rules::init();
	}

	protected function tearDown(): void {
		Aura_Worker_Rules::$rest_request_override = null;
		Aura_Worker_Rules::$cookie_auth_override  = null;
		Aura_Worker_Rules::reset_request_warnings();
	}

	private function install( array $rules ): void {
		$GLOBALS['_options'][ Aura_Worker_Rules::OPTION ] = array(
			'envelope' => 'x.y', 'seq' => 1, 'issued_at' => '', 'received_at' => time(), 'rules' => $rules,
		);
	}

	private function rule( string $key, string $effect, string $type, ?string $id = null ): array {
		return array( 'key' => $key, 'effect' => $effect, 'target' => array( 'type' => $type, 'id' => $id ), 'reason' => "r:{$key}" );
	}

	private function prepared( int $id ): stdClass {
		$p = new stdClass();
		$p->ID = $id;
		return $p;
	}

	public function test_an_update_to_a_ruled_page_is_refused(): void {
		$this->install( array( $this->rule( 'rule/checkout', 'block', 'page', '7' ) ) );
		$req = new WP_REST_Request();
		$req->set_param( 'id', 7 );
		$res = apply_filters( 'rest_pre_insert_page', $this->prepared( 7 ), $req );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_rule_blocked', $res->get_error_code() );
		$this->assertSame( 403, $res->get_error_data()['status'] );
	}

	public function test_a_post_rule_catches_the_same_id_on_the_posts_route(): void {
		$this->install( array( $this->rule( 'rule/checkout', 'block', 'page', '7' ) ) );
		$req = new WP_REST_Request();
		$req->set_param( 'id', 7 );
		$this->assertInstanceOf( WP_Error::class, apply_filters( 'rest_pre_insert_post', $this->prepared( 7 ), $req ) );
	}

	public function test_another_page_passes_through_untouched(): void {
		$this->install( array( $this->rule( 'rule/checkout', 'block', 'page', '7' ) ) );
		$req = new WP_REST_Request();
		$req->set_param( 'id', 8 );
		$prepared = $this->prepared( 8 );
		$this->assertSame( $prepared, apply_filters( 'rest_pre_insert_page', $prepared, $req ) );
	}

	public function test_a_create_is_caught_by_a_freeze_but_not_by_a_page_rule(): void {
		$req = new WP_REST_Request(); // no id: a create
		$this->install( array( $this->rule( 'rule/checkout', 'block', 'page', '7' ) ) );
		$this->assertNotInstanceOf( WP_Error::class, apply_filters( 'rest_pre_insert_page', $this->prepared( 0 ), $req ) );
		$this->install( array( $this->rule( 'rule/freeze', 'block', 'site' ) ) );
		$this->assertInstanceOf( WP_Error::class, apply_filters( 'rest_pre_insert_page', $this->prepared( 0 ), $req ) );
	}

	/**
	 * The data-layer seams refuse with `false` (wp_delete_post()'s contract),
	 * not WP_Error — see test_a_data_layer_refusal_stops_the_deletion_and_is_audited.
	 *
	 * @dataProvider delete_filters
	 */
	public function test_a_delete_of_a_ruled_post_is_refused_at_the_core_seam( string $filter ): void {
		// There is NO rest_pre_delete_* filter in WordPress — the post
		// controller offers `rest_{type}_trashable` (a bool) and
		// `rest_delete_{type}` (fired after the post is gone). The seams that
		// can refuse are core's own: wp_delete_post() → pre_delete_post,
		// wp_trash_post() → pre_trash_post. Both, because a REST DELETE
		// without ?force=true trashes rather than deletes.
		$this->install( array( $this->rule( 'rule/checkout', 'block', 'post', '7' ) ) );
		$post            = new stdClass();
		$post->ID        = 7;
		$post->post_type = 'post';
		$this->assertFalse( apply_filters( $filter, null, $post, false ), 'the deletion was not stopped' );
	}

	public static function delete_filters(): array {
		return array( 'force delete' => array( 'pre_delete_post' ), 'trash' => array( 'pre_trash_post' ) );
	}

	public function test_a_delete_of_a_ruled_page_is_refused_on_the_pages_route(): void {
		// The route seam carries DELETE as well, because nothing downstream of
		// the controller knows the rule until the post is already gone. This is
		// the path an app-password agent takes: DELETE /wp/v2/pages/7.
		$this->install( array( $this->rule( 'rule/checkout', 'block', 'page', '7' ) ) );
		$req = $this->call( 'DELETE', '/wp/v2/pages/7' );
		$res = apply_filters( 'rest_request_before_callbacks', null, array(), $req );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_rule_blocked', $res->get_error_code() );
	}

	public function test_a_delete_on_the_posts_route_is_refused_by_a_post_rule(): void {
		$this->install( array( $this->rule( 'rule/checkout', 'block', 'post', '7' ) ) );
		$req = $this->call( 'DELETE', '/wp/v2/posts/7' );
		$this->assertInstanceOf( WP_Error::class, apply_filters( 'rest_request_before_callbacks', null, array(), $req ) );
	}

	/**
	 * @dataProvider overlapping_delete_paths
	 */
	public function test_one_rule_records_once_however_many_seams_see_it( string $route, array $rules ): void {
		// The seams overlap on purpose — no single one covers every path — so
		// the same rule decides the same mutation more than once: the route
		// seam (or the generic branch, for a plugin's own endpoint) and then
		// core's pre_trash_post / pre_delete_post. Enforcement is per call;
		// the EVENT is per dispatch. One hook, one counter bump, one warning.
		$this->install( $rules );
		$req = $this->call( 'DELETE', $route );
		$this->assertNull( apply_filters( 'rest_request_before_callbacks', null, array(), $req ) );

		$post            = new stdClass();
		$post->ID        = 7;
		$post->post_type = 'page';
		$this->assertNull( apply_filters( 'pre_trash_post', null, $post, 'publish' ) );
		$this->assertNull( apply_filters( 'pre_delete_post', null, $post, true ) );

		$warned = array_filter( $GLOBALS['_did_actions'], static function ( $a ) { return 'aura_worker_rule_warned' === $a['tag']; } );
		$this->assertCount( 1, $warned, 'one delete produced more than one warn' );
		$this->assertCount( 1, Aura_Worker_Rules::request_warnings(), 'the caller would see the same warning twice' );
	}

	public static function overlapping_delete_paths(): array {
		return array(
			// The route seam decides, then the data seam sees the same page.
			'core route, page rule' => array(
				'/wp/v2/pages/7',
				array( array( 'key' => 'rule/careful', 'effect' => 'warn', 'target' => array( 'type' => 'page', 'id' => '7' ), 'reason' => 'r' ) ),
			),
			// A plugin's own endpoint: the GENERIC branch decides under a site
			// rule, then the same site rule meets the deletion at the data
			// seam. This is the path a route-shaped memo could never cover.
			'custom route, site rule' => array(
				'/plugin/v1/posts/7',
				array( array( 'key' => 'rule/freeze', 'effect' => 'warn', 'target' => array( 'type' => 'site' ), 'reason' => 'r' ) ),
			),
		);
	}

	public function test_a_delete_reaching_only_the_data_seam_is_still_refused(): void {
		// Deduplicating the event must not exempt a path: a deletion that
		// never passed any earlier seam is still enforced at pre_delete_post.
		// The refusal is `false`, not a WP_Error — see the next test.
		$this->install( array( $this->rule( 'rule/checkout', 'block', 'page', '7' ) ) );
		$post            = new stdClass();
		$post->ID        = 7;
		$post->post_type = 'page';
		$this->assertFalse( apply_filters( 'pre_delete_post', null, $post, true ) );
		$blocked = array_filter( $GLOBALS['_did_actions'], static function ( $a ) { return 'aura_worker_rule_blocked' === $a['tag']; } );
		$this->assertCount( 1, $blocked );
	}

	public function test_a_data_layer_refusal_stops_the_deletion_and_is_audited(): void {
		// What the data-layer seam guarantees, and all it guarantees: the
		// deletion does not happen and the fleet is told. It returns `false`
		// because that is wp_delete_post()'s contract for "did not delete" — a
		// WP_Error there is truthy and would read as success.
		//
		// The rule's name does not reach the caller on this path. The route seam
		// carries the 403 for the routes Aura and Angie use (tests above), and
		// WordPress offers no seam that reliably runs for the dispatch that
		// caused this refusal — rest_post_dispatch fires in serve_request(),
		// while an internal rest_do_request() calls dispatch() directly.
		$this->install( array( $this->rule( 'rule/checkout', 'block', 'page', '7' ) ) );
		$post            = new stdClass();
		$post->ID        = 7;
		$post->post_type = 'page';

		$this->assertFalse( apply_filters( 'pre_delete_post', null, $post, true ), 'the deletion was allowed to proceed' );

		$blocked = array_values( array_filter( $GLOBALS['_did_actions'], static function ( $a ) { return 'aura_worker_rule_blocked' === $a['tag']; } ) );
		$this->assertCount( 1, $blocked, 'a refusal nobody records is a refusal nobody can audit' );
		$this->assertSame( 'rule/checkout', $blocked[0]['args'][1]['key'] );
	}

	public function test_a_second_mutation_under_the_same_rule_is_still_refused(): void {
		// Per-DISPATCH deduplication is about the RECORD, not the decision.
		// Every call still gets its own verdict — two writes under one freeze
		// are two refusals — and here the two writes are two properly closed,
		// separate dispatches, so the fleet sees two events too: each closed
		// dispatch starts the next one fresh.
		$this->install( array( $this->rule( 'rule/freeze', 'block', 'site' ) ) );
		foreach ( array( '/wc/v3/products/1', '/wc/v3/products/2' ) as $route ) {
			$req = $this->call( 'POST', $route );
			$res = apply_filters( 'rest_request_before_callbacks', null, array(), $req );
			$this->assertInstanceOf( WP_Error::class, $res, "{$route} was allowed after the rule had already been recorded" );
			apply_filters( 'rest_request_after_callbacks', new WP_REST_Response( array( 'ok' => true ), 200 ), array(), $req );
		}
		$blocked = array_filter( $GLOBALS['_did_actions'], static function ( $a ) { return 'aura_worker_rule_blocked' === $a['tag']; } );
		$this->assertCount( 2, $blocked, 'a properly closed dispatch did not start the next one fresh' );
	}

	public function test_a_nested_dispatch_under_the_same_rule_reports_its_own_event(): void {
		// A handler calling rest_do_request() mid-flight opens a NESTED frame
		// while the outer one is still open — a different dispatch from the
		// outer's, even when both happen to match the same rule. Per spec §6
		// the nested dispatch must get its own event and its own caller must
		// see its own warning: "a handler calling rest_do_request() would have
		// the nested dispatch's refusal silence its own" is exactly the bug
		// this pins against. Deduplication is per-DISPATCH (the innermost open
		// frame), never per-request.
		$this->install( array( $this->rule( 'rule/careful', 'warn', 'site' ) ) );

		$outer = $this->call( 'POST', '/wc/v3/products/1' );
		apply_filters( 'rest_request_before_callbacks', null, array(), $outer ); // records rule/careful

		$inner = $this->call( 'POST', '/wc/v3/products/2' );
		apply_filters( 'rest_request_before_callbacks', null, array(), $inner ); // nested; same rule

		$inner_resp = apply_filters( 'rest_request_after_callbacks', new WP_REST_Response( array( 'ok' => true ), 200 ), array(), $inner );
		$outer_resp = apply_filters( 'rest_request_after_callbacks', new WP_REST_Response( array( 'ok' => true ), 200 ), array(), $outer );

		$warned = array_filter( $GLOBALS['_did_actions'], static function ( $a ) { return 'aura_worker_rule_warned' === $a['tag']; } );
		$this->assertCount( 2, $warned, "the nested dispatch's own event was silenced by the outer one" );

		$this->assertArrayHasKey( 'X-Aura-Rule-Warnings', $inner_resp->get_headers(), "the nested dispatch's caller was told nothing" );
		$this->assertArrayHasKey( 'X-Aura-Rule-Warnings', $outer_resp->get_headers(), "the outer dispatch's caller was told nothing" );
		$this->assertNotEmpty( json_decode( $inner_resp->get_headers()['X-Aura-Rule-Warnings'], true ), 'the inner header was empty' );
		$this->assertNotEmpty( json_decode( $outer_resp->get_headers()['X-Aura-Rule-Warnings'], true ), 'the outer header was empty' );
	}

	public function test_an_anonymous_delete_at_the_data_seam_is_not_refused(): void {
		// pre_delete_post is a DATA-layer hook: any public endpoint reaches it,
		// and core has authorised nothing. A form submission or a checkout
		// cleanup that deletes its own draft must survive a site freeze — the
		// generic seam already lets that request through, and this seam must
		// not reverse the decision one layer down.
		$GLOBALS['_logged_in'] = false;
		$this->install( array( $this->rule( 'rule/freeze', 'block', 'site' ) ) );

		$post            = new stdClass();
		$post->ID        = 7;
		$post->post_type = 'post';

		$this->assertNull( apply_filters( 'pre_delete_post', null, $post, true ), 'a freeze refused an anonymous public deletion' );
		$this->assertNull( apply_filters( 'pre_trash_post', null, $post, 'publish' ) );
		$this->assertEmpty( array_filter( $GLOBALS['_did_actions'], static function ( $a ) { return 'aura_worker_rule_blocked' === $a['tag']; } ) );
	}

	public function test_a_delete_of_an_unruled_id_passes_both_seams(): void {
		$this->install( array( $this->rule( 'rule/checkout', 'block', 'page', '7' ) ) );
		$req = $this->call( 'DELETE', '/wp/v2/pages/9' );
		$this->assertNull( apply_filters( 'rest_request_before_callbacks', null, array(), $req ) );
		$post            = new stdClass();
		$post->ID        = 9;
		$post->post_type = 'page';
		$this->assertNull( apply_filters( 'pre_delete_post', null, $post, true ) );
	}

	public function test_a_delete_of_another_post_type_is_ignored(): void {
		// The vocabulary has no "product"; the core filters fire for every type.
		$this->install( array( $this->rule( 'rule/freeze', 'block', 'site' ) ) );
		$post            = new stdClass();
		$post->ID        = 7;
		$post->post_type = 'product';
		$this->assertNull( apply_filters( 'pre_delete_post', null, $post, true ) );
	}

	public function test_a_human_deleting_in_wp_admin_is_not_refused(): void {
		// pre_delete_post fires for wp-admin and WP-CLI too. The agent test is
		// the same one every seam uses, so an editor emptying the trash is not
		// stopped by a rule meant for agents.
		$this->install( array( $this->rule( 'rule/checkout', 'block', 'page', '7' ) ) );
		Aura_Worker_Rules::$rest_request_override = false; // not a REST request at all
		$post            = new stdClass();
		$post->ID        = 7;
		$post->post_type = 'page';
		$this->assertNull( apply_filters( 'pre_delete_post', null, $post, true ) );
		Aura_Worker_Rules::$rest_request_override = true;
	}

	public function test_a_warn_lets_the_write_through_and_fires_the_hook(): void {
		$this->install( array( $this->rule( 'rule/careful', 'warn', 'page', '7' ) ) );
		$req = new WP_REST_Request();
		$req->set_param( 'id', 7 );
		$prepared = $this->prepared( 7 );
		$this->assertSame( $prepared, apply_filters( 'rest_pre_insert_page', $prepared, $req ) );
		$warned = array_filter( $GLOBALS['_did_actions'], static function ( $a ) { return 'aura_worker_rule_warned' === $a['tag']; } );
		$this->assertCount( 1, $warned );
	}

	public function test_each_dispatch_carries_only_its_own_warnings(): void {
		// A warned route calls rest_do_request(), and the nested request earns
		// a warning of its own. Each response must carry ONE — its own. A
		// shared list with a start mark gets the inner one right and hands the
		// outer both; a plain shared list gets both wrong.
		$this->install( array(
			$this->rule( 'rule/outer', 'warn', 'page', '7' ),
			$this->rule( 'rule/inner', 'warn', 'page', '8' ),
		) );

		$outer = $this->call( 'POST', '/wp/v2/pages/7' );
		apply_filters( 'rest_request_before_callbacks', null, array(), $outer );
		$outer_req = new WP_REST_Request();
		$outer_req->set_param( 'id', 7 );
		apply_filters( 'rest_pre_insert_page', $this->prepared( 7 ), $outer_req ); // records rule/outer

		$inner = $this->call( 'POST', '/wp/v2/pages/8' );
		apply_filters( 'rest_request_before_callbacks', null, array(), $inner );
		$inner_req = new WP_REST_Request();
		$inner_req->set_param( 'id', 8 );
		apply_filters( 'rest_pre_insert_page', $this->prepared( 8 ), $inner_req ); // records rule/inner
		$inner_resp = apply_filters( 'rest_request_after_callbacks', new WP_REST_Response( array( 'ok' => true ), 200 ), array(), $inner );

		$outer_resp = apply_filters( 'rest_request_after_callbacks', new WP_REST_Response( array( 'ok' => true ), 200 ), array(), $outer );

		$inner_warnings = json_decode( $inner_resp->get_headers()['X-Aura-Rule-Warnings'], true );
		$outer_warnings = json_decode( $outer_resp->get_headers()['X-Aura-Rule-Warnings'], true );
		$this->assertSame( array( 'rule/inner' ), array_column( $inner_warnings, 'rule' ) );
		$this->assertSame( array( 'rule/outer' ), array_column( $outer_warnings, 'rule' ), 'the outer response claimed the nested dispatch\'s warning too' );
	}

	public function test_a_dispatch_that_never_finished_cannot_steal_the_next_ones_warning(): void {
		// The frame hooks are the pair core runs together, so this is the one
		// way a frame can still be orphaned: an exception unwinding out of a
		// nested rest_do_request() that the outer handler catches. The outer
		// dispatch must then keep its own warning rather than pop the orphan.
		$this->install( array( $this->rule( 'rule/outer', 'warn', 'page', '7' ) ) );

		$outer = $this->call( 'POST', '/wp/v2/pages/7' );
		apply_filters( 'rest_request_before_callbacks', null, array(), $outer );
		$outer_req = new WP_REST_Request();
		$outer_req->set_param( 'id', 7 );
		apply_filters( 'rest_pre_insert_page', $this->prepared( 7 ), $outer_req ); // records rule/outer

		// A nested dispatch opens a frame and dies before its after-callbacks.
		$orphan = $this->call( 'POST', '/wp/v2/pages/8' );
		apply_filters( 'rest_request_before_callbacks', null, array(), $orphan );

		$resp = apply_filters( 'rest_request_after_callbacks', new WP_REST_Response( array( 'ok' => true ), 200 ), array(), $outer );
		$warnings = json_decode( $resp->get_headers()['X-Aura-Rule-Warnings'] ?? 'null', true );
		$this->assertSame(
			array( 'rule/outer' ),
			array_column( (array) $warnings, 'rule' ),
			'the outer dispatch took the orphaned frame and lost its own warning'
		);
	}

	public function test_work_after_a_caught_nested_failure_is_still_enforced_and_audited(): void {
		// The documented limit, pinned so it stays a limit and never grows into
		// an enforcement hole. While an orphaned frame is still the innermost
		// one, a mutation the outer handler performs after catching the nested
		// failure records into the orphan, so its WARNING is discarded with it
		// (see close_frame()). What must survive is the refusal, the hook and
		// the counter.
		//
		// A POST rule, not a site freeze: under a freeze the generic seam
		// records the rule in BOTH earlier dispatches, and the post-catch
		// deletion would then be deduplicated against the orphan's own set —
		// so the assertions below would pass on events fired before the
		// deletion and prove nothing about it. With a post rule the generic
		// seam (site rules only) matches neither earlier dispatch, and the
		// deletion is the FIRST time this rule is seen.
		//
		// Task 10 carry-forward: the brief's own version of this test also
		// compares Aura_Worker_Rules::count_24h( BLOCKED_COUNTER ) before/after.
		// Now that the counters and a dispatching do_action() stub both exist,
		// that half is restored — record_block() is not called directly; it
		// runs because the real code path does: enforce() fires
		// `aura_worker_rule_blocked`, init() (called from setUp()) registered
		// record_block() on it, and the bootstrap's do_action() now actually
		// invokes registered listeners instead of only logging.
		$this->install( array( $this->rule( 'rule/checkout', 'block', 'post', '7' ) ) );

		$outer = $this->call( 'POST', '/foo/v1/thing' );
		apply_filters( 'rest_request_before_callbacks', null, array(), $outer );

		// A nested dispatch opens a frame and dies; the outer handler catches.
		$orphan = $this->call( 'POST', '/foo/v1/nested' );
		apply_filters( 'rest_request_before_callbacks', null, array(), $orphan );

		$before         = count( array_filter( $GLOBALS['_did_actions'], static function ( $a ) { return 'aura_worker_rule_blocked' === $a['tag']; } ) );
		$blocked_before = Aura_Worker_Rules::count_24h( Aura_Worker_Rules::BLOCKED_COUNTER );
		$this->assertSame( 0, $before, 'the rule fired before the mutation under test' );

		// The outer handler carries on and deletes the ruled post.
		$post            = new stdClass();
		$post->ID        = 7;
		$post->post_type = 'post';
		$this->assertFalse( apply_filters( 'pre_delete_post', null, $post, true ), 'a refusal was lost with the orphaned frame' );

		$after = count( array_filter( $GLOBALS['_did_actions'], static function ( $a ) { return 'aura_worker_rule_blocked' === $a['tag']; } ) );
		$this->assertSame( $before + 1, $after, 'the audit lost this mutation to the orphaned frame' );
		$this->assertSame(
			$blocked_before + 1,
			Aura_Worker_Rules::count_24h( Aura_Worker_Rules::BLOCKED_COUNTER ),
			'the audit counter lost this mutation to the orphaned frame'
		);
	}

	public function test_a_batch_dispatch_refuses_every_mutation_and_reports_the_rule_once(): void {
		// The batch case, driven from INSIDE one before/after pair: one route
		// callback deleting two posts under one freeze. Both are refused —
		// that is the invariant that matters, and it is per call. The EVENT is
		// per dispatch, so the pair produces ONE blocked hook naming the rule
		// and the caller is told once. The magnitude (two posts, not one) is
		// Aura's to report from its own action log; the site reports that the
		// rule is biting, which is what the fleet reads.
		$this->install( array( $this->rule( 'rule/freeze', 'block', 'site' ) ) );

		$req = $this->call( 'POST', '/foo/v1/bulk-delete' );
		apply_filters( 'rest_request_before_callbacks', null, array(), $req );

		$refused = array();
		foreach ( array( 7, 8 ) as $id ) {
			$post            = new stdClass();
			$post->ID        = $id;
			$post->post_type = 'post';
			$refused[]       = apply_filters( 'pre_delete_post', null, $post, true );
		}
		apply_filters( 'rest_request_after_callbacks', new WP_REST_Response( array( 'ok' => true ), 200 ), array(), $req );

		$this->assertSame(
			array( false, false ),
			$refused,
			'a batch dispatch deleted a post after the first refusal — deduplicating the record must never exempt a call'
		);
		$blocked = array_filter( $GLOBALS['_did_actions'], static function ( $a ) { return 'aura_worker_rule_blocked' === $a['tag']; } );
		$this->assertCount( 1, $blocked, 'the event is per rule per dispatch (see enforce(); magnitude is Aura-side)' );
	}

	public function test_two_mutations_in_one_request_are_each_recorded(): void {
		// Deduplication is per DISPATCH, not per request: a batch endpoint or a
		// handler calling rest_do_request() performs several mutations in one
		// PHP request, and counting only the first would undercount exactly the
		// audit the rule feeds.
		$this->install( array( $this->rule( 'rule/careful', 'warn', 'site' ) ) );

		foreach ( array( '/wc/v3/products/1', '/wc/v3/products/2' ) as $route ) {
			$req = $this->call( 'POST', $route );
			apply_filters( 'rest_request_before_callbacks', null, array(), $req );
			$resp = apply_filters( 'rest_request_after_callbacks', new WP_REST_Response( array( 'ok' => true ), 200 ), array(), $req );
			$this->assertArrayHasKey( 'X-Aura-Rule-Warnings', $resp->get_headers(), "{$route} lost its warning to the previous dispatch" );
		}

		$warned = array_filter( $GLOBALS['_did_actions'], static function ( $a ) { return 'aura_worker_rule_warned' === $a['tag']; } );
		$this->assertCount( 2, $warned, 'the second mutation went uncounted' );
	}

	public function test_a_warning_delivered_in_the_body_is_not_repeated_in_the_header(): void {
		// The two channels exist because different responses are ours to shape:
		// SiteAgent's own handlers own their body, core's routes do not. They
		// are alternatives, not both — a client that reads the body AND the
		// header would otherwise count one mutation twice.
		$this->install( array( $this->rule( 'rule/careful', 'warn', 'site' ) ) );
		// An ID-aware route on a non-DELETE method: the generic seam stands
		// aside, so the only warning here is the handler's own.
		$req = $this->call( 'POST', '/wp/v2/pages/7' );
		apply_filters( 'rest_request_before_callbacks', null, array(), $req );

		$this->assertTrue( true === Aura_Worker_Rules::guard_rest( array( array( 'type' => 'site', 'id' => '*' ) ), 'update_plugin' ) );
		$body = Aura_Worker_Rules::with_warnings( array( 'success' => true ) );
		$resp = apply_filters( 'rest_request_after_callbacks', new WP_REST_Response( $body, 200 ), array(), $req );

		$this->assertCount( 1, $body['warnings'], 'the handler lost the warning it was supposed to carry' );
		$this->assertArrayNotHasKey(
			'X-Aura-Rule-Warnings',
			$resp->get_headers(),
			'the same warning went out in the body and in the header'
		);
	}

	public function test_a_warning_survives_a_callback_that_errored(): void {
		// A warn rule matched, then the handler failed. The warning is still
		// true, and a handler that errored early never reached with_warnings().
		// The error is converted here — the conversion core performs on its
		// very next statement — so the warning goes out through the same
		// header as every other response, and the error's own status and body
		// survive intact.
		$this->install( array( $this->rule( 'rule/careful', 'warn', 'site' ) ) );
		$req = $this->call( 'POST', '/wc/v3/products/1' );
		apply_filters( 'rest_request_before_callbacks', null, array(), $req );

		$err = new WP_Error( 'update_failed', 'nope', array( 'status' => 500 ) );
		$out = apply_filters( 'rest_request_after_callbacks', $err, array(), $req );

		$this->assertInstanceOf( WP_REST_Response::class, $out, 'core would have converted this itself and dropped the header' );
		$this->assertSame( 500, $out->get_status(), 'the error lost its own status in conversion' );
		$this->assertSame( 'update_failed', $out->get_data()['code'] );
		$this->assertArrayNotHasKey( 'additional_data', $out->get_data(), 'the previous error data was archived alongside the real one' );
		$this->assertSame(
			wp_json_encode( array( array( 'rule' => 'rule/careful', 'reason' => 'r:rule/careful' ) ) ),
			$out->get_headers()['X-Aura-Rule-Warnings'] ?? null,
			'the warning went nowhere: the error path skipped the header and no handler body carried it'
		);
	}

	public function test_a_warn_at_a_core_seam_reaches_the_caller_as_a_header(): void {
		// Core owns the body of /wp/v2 responses; the warning goes out as a
		// header on rest_request_after_callbacks so an agent still sees it.
		$this->install( array( $this->rule( 'rule/careful', 'warn', 'page', '7' ) ) );
		$req = new WP_REST_Request();
		$req->set_param( 'id', 7 );
		apply_filters( 'rest_pre_insert_page', $this->prepared( 7 ), $req );
		// A bare array, as a plugin route may return: core runs
		// rest_ensure_response() only AFTER this filter, so the guard has to
		// normalise or the header never reaches those exact routes.
		$resp = apply_filters( 'rest_request_after_callbacks', array( 'id' => 7 ), array(), $req );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame(
			wp_json_encode( array( array( 'rule' => 'rule/careful', 'reason' => 'r:rule/careful' ) ) ),
			$resp->get_headers()['X-Aura-Rule-Warnings'] ?? null
		);
	}

	public function test_outside_a_rest_request_nothing_is_enforced(): void {
		// wp-admin, WP-CLI and cron are the site operating on itself.
		Aura_Worker_Rules::$rest_request_override = false;
		$this->install( array( $this->rule( 'rule/freeze', 'block', 'site' ) ) );
		$req = new WP_REST_Request();
		$req->set_param( 'id', 7 );
		$prepared = $this->prepared( 7 );
		$this->assertSame( $prepared, apply_filters( 'rest_pre_insert_page', $prepared, $req ) );
	}

	/* ---- any mutation, any route: the freeze ---- */

	private function call( string $method, string $route, array $headers = array() ): WP_REST_Request {
		$req = new WP_REST_Request();
		$req->set_method( $method );
		$req->set_route( $route );
		foreach ( $headers as $k => $v ) {
			$req->set_header( $k, $v );
		}
		Aura_Worker_Call_Context::set_rest_route_for_tests( $route );
		return $req;
	}

	public function test_a_freeze_refuses_a_product_write_through_woocommerce_rest(): void {
		$this->install( array( $this->rule( 'rule/freeze', 'block', 'site' ) ) );
		$req = $this->call( 'POST', '/wc/v3/products/7' );
		$res = apply_filters( 'rest_request_before_callbacks', null, array(), $req );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_rule_blocked', $res->get_error_code() );
	}

	public function test_a_freeze_refuses_a_media_upload(): void {
		$this->install( array( $this->rule( 'rule/freeze', 'block', 'site' ) ) );
		$req = $this->call( 'POST', '/wp/v2/media' );
		$this->assertInstanceOf( WP_Error::class, apply_filters( 'rest_request_before_callbacks', null, array(), $req ) );
	}

	public function test_a_freeze_lets_reads_through(): void {
		$this->install( array( $this->rule( 'rule/freeze', 'block', 'site' ) ) );
		foreach ( array( 'GET', 'HEAD', 'OPTIONS' ) as $m ) {
			$req = $this->call( $m, '/wc/v3/products/7' );
			$this->assertNull( apply_filters( 'rest_request_before_callbacks', null, array(), $req ), "{$m} was refused" );
		}
	}

	public function test_a_page_rule_does_not_refuse_an_unrelated_route(): void {
		// The generic seam applies site rules only; it does not know IDs.
		$this->install( array( $this->rule( 'rule/checkout', 'block', 'page', '7' ) ) );
		$req = $this->call( 'POST', '/wp/v2/media' );
		$this->assertNull( apply_filters( 'rest_request_before_callbacks', null, array(), $req ) );
	}

	public function test_siteagents_own_routes_are_exempt_from_the_generic_seam(): void {
		$this->install( array( $this->rule( 'rule/freeze', 'block', 'site' ) ) );
		$req = $this->call( 'POST', '/aura/mcp/tools/execute' );
		$this->assertNull( apply_filters( 'rest_request_before_callbacks', null, array(), $req ) );
	}

	public function test_a_page_write_under_a_site_warn_is_recorded_once(): void {
		// Both seams see a POST /wp/v2/pages/7. The ID-aware filter already
		// enforces a site rule (it matches any declaration), so the generic
		// seam must stand aside or one mutation warns twice.
		$this->install( array( $this->rule( 'rule/careful', 'warn', 'site' ) ) );
		$req = $this->call( 'POST', '/wp/v2/pages/7' );
		$req->set_param( 'id', 7 );
		apply_filters( 'rest_request_before_callbacks', null, array(), $req );
		apply_filters( 'rest_pre_insert_page', $this->prepared( 7 ), $req );
		$warned = array_filter( $GLOBALS['_did_actions'], static function ( $a ) { return 'aura_worker_rule_warned' === $a['tag']; } );
		$this->assertCount( 1, $warned );
	}

	public function test_a_page_write_under_a_site_block_is_still_refused_by_the_id_filter(): void {
		// Standing aside on the generic seam must not open the door: the
		// ID-aware filter carries the freeze for posts and pages.
		$this->install( array( $this->rule( 'rule/freeze', 'block', 'site' ) ) );
		$req = $this->call( 'POST', '/wp/v2/pages/7' );
		$req->set_param( 'id', 7 );
		$this->assertNull( apply_filters( 'rest_request_before_callbacks', null, array(), $req ) );
		$this->assertInstanceOf( WP_Error::class, apply_filters( 'rest_pre_insert_page', $this->prepared( 7 ), $req ) );
	}

	public function test_nested_post_routes_are_not_exempt_from_the_generic_seam(): void {
		// Revisions and autosaves live UNDER /wp/v2/posts but core serves them
		// without running rest_pre_insert_post. A prefix exemption would hand
		// them a hole under the freeze; only the collection and the single
		// item are the ID seam's to carry.
		$this->install( array( $this->rule( 'rule/freeze', 'block', 'site' ) ) );
		foreach ( array( array( 'DELETE', '/wp/v2/posts/7/revisions/9' ), array( 'POST', '/wp/v2/posts/7/autosaves' ), array( 'DELETE', '/wp/v2/pages/7/revisions/9' ) ) as $c ) {
			$req = $this->call( $c[0], $c[1] );
			$res = apply_filters( 'rest_request_before_callbacks', null, array(), $req );
			$this->assertInstanceOf( WP_Error::class, $res, "{$c[0]} {$c[1]} slipped under the freeze" );
		}
	}

	public function test_only_the_exact_post_and_page_shapes_are_exempt(): void {
		// Pin the regex, both ways: what it exempts and what it does not.
		foreach ( array( '/wp/v2/posts', '/wp/v2/posts/', '/wp/v2/posts/7', '/wp/v2/pages/7/' ) as $exempt ) {
			$this->assertSame( 1, preg_match( Aura_Worker_Rules::ID_AWARE_ROUTES, $exempt ), "{$exempt} should be the ID filters'" );
		}
		foreach ( array( '/wp/v2/posts/7/revisions/9', '/wp/v2/posts/7/autosaves', '/wp/v2/posts-extra', '/wp/v2/postsx/7', '/wp/v2/pages/abc' ) as $not ) {
			$this->assertSame( 0, preg_match( Aura_Worker_Rules::ID_AWARE_ROUTES, $not ), "{$not} must go through the generic seam" );
		}
	}

	public function test_an_earlier_error_is_passed_through_untouched(): void {
		$this->install( array( $this->rule( 'rule/freeze', 'block', 'site' ) ) );
		$prior = new WP_Error( 'rest_forbidden', 'nope' );
		$req   = $this->call( 'POST', '/wp/v2/media' );
		$this->assertSame( $prior, apply_filters( 'rest_request_before_callbacks', $prior, array(), $req ) );
	}

	public function test_a_gutenberg_save_through_the_generic_seam_is_not_enforced(): void {
		$this->install( array( $this->rule( 'rule/freeze', 'block', 'site' ) ) );
		Aura_Worker_Rules::$cookie_auth_override = true;
		$req = $this->call( 'POST', '/wc/v3/products/7' );
		$this->assertNull( apply_filters( 'rest_request_before_callbacks', null, array(), $req ) );
	}

	public function test_anonymous_public_traffic_is_not_an_agent(): void {
		// A storefront checkout, a contact-form submission, a payment webhook:
		// unauthenticated POSTs are the site's public traffic, not an agent.
		// "Not a cookie session" is not evidence of an agent; an authenticated
		// identity that core did NOT get from a cookie is. A freeze governs
		// agent writes — it must not take the shop offline.
		$this->install( array( $this->rule( 'rule/freeze', 'block', 'site' ) ) );
		$GLOBALS['_logged_in'] = false;
		foreach ( array( '/wc/store/v1/checkout', '/contact-form-7/v1/contact-forms/3/feedback', '/stripe/v1/webhook' ) as $route ) {
			$req = $this->call( 'POST', $route );
			$this->assertNull( apply_filters( 'rest_request_before_callbacks', null, array(), $req ), "anonymous POST {$route} was treated as an agent" );
		}
		$blocked = array_filter( $GLOBALS['_did_actions'], static function ( $a ) { return 'aura_worker_rule_blocked' === $a['tag']; } );
		$this->assertCount( 0, $blocked );
	}

	public function test_an_anonymous_write_that_reaches_the_id_filter_is_still_refused(): void {
		// The identity requirement belongs to the generic seam, which sees
		// public routes. rest_pre_insert_page is reached only for a caller core
		// already authorised to edit — so an anonymous write arriving here is
		// not public traffic to stand aside for, and this suite has no core to
		// prove what core would do. The rule does not depend on it: any
		// non-cookie, non-own caller at the ID filter is enforced.
		$this->install( array( $this->rule( 'rule/checkout', 'block', 'page', '7' ) ) );
		$GLOBALS['_logged_in'] = false;
		$req = new WP_REST_Request();
		$req->set_param( 'id', 7 );
		$this->assertInstanceOf( WP_Error::class, apply_filters( 'rest_pre_insert_page', $this->prepared( 7 ), $req ) );
	}

	/* ---- posts and pages by ID ---- */

	public function test_a_gutenberg_save_from_wp_admin_is_not_enforced(): void {
		// An editor saving in Gutenberg also goes through /wp/v2, on a cookie
		// session. Core records that it authenticated the cookie AND verified
		// the nonce ($wp_rest_auth_cookie === true) before any handler runs.
		// That is a human at the keyboard — wp-admin is unaffected.
		$this->install( array( $this->rule( 'rule/freeze', 'block', 'site' ) ) );
		Aura_Worker_Rules::$cookie_auth_override = true;
		$req = new WP_REST_Request();
		$req->set_param( 'id', 7 );
		$prepared = $this->prepared( 7 );
		$this->assertSame( $prepared, apply_filters( 'rest_pre_insert_page', $prepared, $req ) );
	}

	public function test_a_spoofed_nonce_header_does_not_make_an_agent_human(): void {
		// Any bearer or app-password client can add X-WP-Nonce to its request.
		// The header is caller-controlled; core's cookie verdict is not.
		$this->install( array( $this->rule( 'rule/freeze', 'block', 'site' ) ) );
		Aura_Worker_Rules::$cookie_auth_override = false;
		$req = new WP_REST_Request();
		$req->set_param( 'id', 7 );
		$req->set_header( 'X-WP-Nonce', 'abc123' );
		$req->set_param( '_wpnonce', 'abc123' );
		$this->assertInstanceOf( WP_Error::class, apply_filters( 'rest_pre_insert_page', $this->prepared( 7 ), $req ) );
	}

	public function test_an_app_password_session_is_an_agent_even_with_cookie_auth_set(): void {
		// Defence in depth: if a request authenticated with an application
		// password, it is an agent whatever else is true of the globals.
		$this->install( array( $this->rule( 'rule/freeze', 'block', 'site' ) ) );
		Aura_Worker_Rules::$cookie_auth_override = true;
		$GLOBALS['_rest_app_password'] = 'uuid-of-app-password';
		$req = new WP_REST_Request();
		$req->set_param( 'id', 7 );
		$this->assertInstanceOf( WP_Error::class, apply_filters( 'rest_pre_insert_page', $this->prepared( 7 ), $req ) );
	}

	public function test_siteagents_own_routes_are_not_double_enforced(): void {
		// execute_tool() already decided; the core filter must not refuse a call
		// that SiteAgent's own tool is making on the way to the same post.
		Aura_Worker_Call_Context::set_rest_route_for_tests( '/aura/mcp/tools/execute' );
		$this->install( array( $this->rule( 'rule/careful', 'warn', 'page', '7' ) ) );
		$req = new WP_REST_Request();
		$req->set_param( 'id', 7 );
		apply_filters( 'rest_pre_insert_page', $this->prepared( 7 ), $req );
		$warned = array_filter( $GLOBALS['_did_actions'], static function ( $a ) { return 'aura_worker_rule_warned' === $a['tag']; } );
		$this->assertCount( 0, $warned, 'the core filter fired inside a SiteAgent route' );
		Aura_Worker_Call_Context::reset();
	}
}
