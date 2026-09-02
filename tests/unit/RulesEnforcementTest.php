<?php
/**
 * A rule is enforced where every tool path passes: execute_tool(). A block
 * runs nothing and snapshots nothing; a warn runs and says so.
 *
 * Uses the fake tools from ToolBaseTest.php. The ruleset is written directly
 * to the option (its verification is RulesetStoreTest's concern).
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

/** A fake that records whether it ran — so "block means nothing ran" is provable. */
class SA_Recording_Tool extends Aura_Tool_Base {
	public static $ran = 0;
	public function get_name() {
		return 'test_recording_tool';
	}
	public function get_description() {
		return 'records executions';
	}
	public function get_parameters() {
		return array( 'post_id' => array( 'type' => 'integer', 'description' => 'post', 'required' => false ) );
	}
	public function get_returns() {
		return array( 'ok' => array( 'type' => 'boolean' ) );
	}
	public function touches( $params ) {
		$id = isset( $params['post_id'] ) ? (string) (int) $params['post_id'] : '';
		return '' === $id ? parent::touches( $params ) : array( array( 'type' => 'post', 'id' => $id ) );
	}
	public function execute( $params ) {
		++self::$ran;
		return array( 'ok' => true );
	}
}

/** A fake that fails the way a real tool fails: by throwing an Exception. */
class SA_Throwing_Tool extends Aura_Tool_Base {
	public function get_name() {
		return 'test_throwing_tool';
	}
	public function get_description() {
		return 'always throws';
	}
	public function get_parameters() {
		return array();
	}
	public function get_returns() {
		return array( 'ok' => array( 'type' => 'boolean' ) );
	}
	public function execute( $params ) {
		throw new Exception( 'the update failed' );
	}
}

/**
 * A fake that fails the way a BUG fails: a PHP Error. TypeError implements
 * Throwable without extending Exception, which is the whole point of the
 * double — `catch ( Exception )` never sees it.
 */
class SA_Fatal_Tool extends Aura_Tool_Base {
	public function get_name() {
		return 'test_fatal_tool';
	}
	public function get_description() {
		return 'always raises a PHP Error';
	}
	public function get_parameters() {
		return array();
	}
	public function get_returns() {
		return array( 'ok' => array( 'type' => 'boolean' ) );
	}
	public function execute( $params ) {
		throw new TypeError( 'bad argument' );
	}
}

/**
 * A snapshot-first tool, shaped like the real ones (e.g.
 * class-tool-gutenberg-update-block.php): the FIRST thing execute() does is
 * capture state before mutating anything. Real tools do this through
 * `Aura_Worker_Snapshots::snapshot_post()` et al., which hit the filesystem
 * and are too heavy for a unit double; this double marks the same POINT in
 * the sequence with a static counter instead, so a test can prove the point
 * was never reached rather than merely that `execute()` overall didn't run.
 */
class SA_Snapshotting_Tool extends Aura_Tool_Base {
	public static $snapshot_attempts = 0;
	public function get_name() {
		return 'test_snapshotting_tool';
	}
	public function get_description() {
		return 'snapshots before it mutates, like a real power tool';
	}
	public function get_parameters() {
		return array();
	}
	public function get_returns() {
		return array( 'ok' => array( 'type' => 'boolean' ) );
	}
	public function execute( $params ) {
		++self::$snapshot_attempts; // Stands in for Aura_Worker_Snapshots::snapshot_post().
		return array( 'ok' => true );
	}
}

final class RulesEnforcementTest extends TestCase {

	protected function setUp(): void {
		sa_reset_state();
		SA_Recording_Tool::$ran               = 0;
		SA_Snapshotting_Tool::$snapshot_attempts = 0;
	}

	private function install( array $rules ): void {
		$GLOBALS['_options'][ Aura_Worker_Rules::OPTION ] = array(
			'envelope'    => 'x.y',
			'seq'         => 1,
			'issued_at'   => '2026-08-21T00:00:00Z',
			'received_at' => time(),
			'rules'       => $rules,
		);
	}

	private function rule( string $key, string $effect, string $type, ?string $id = null ): array {
		return array(
			'key'    => $key,
			'effect' => $effect,
			'target' => array( 'type' => $type, 'id' => $id ),
			'reason' => "r:{$key}",
		);
	}

	private function fired( string $hook ): array {
		return array_values( array_filter( $GLOBALS['_did_actions'], static function ( $a ) use ( $hook ) {
			return $a['tag'] === $hook;
		} ) );
	}

	public function test_with_no_ruleset_the_tool_runs(): void {
		$tools = new Aura_Worker_Tools();
		$res   = $tools->execute_tool( 'test_recording_tool', array( 'post_id' => 7 ) );
		$this->assertTrue( $res['success'] );
		$this->assertSame( 1, SA_Recording_Tool::$ran );
	}

	public function test_a_block_runs_nothing_and_says_which_rule(): void {
		$this->install( array( $this->rule( 'rule/checkout', 'block', 'page', '7' ) ) );
		$tools = new Aura_Worker_Tools();
		$res   = $tools->execute_tool( 'test_recording_tool', array( 'post_id' => 7 ) );

		$this->assertFalse( $res['success'] );
		$this->assertSame( 'aura_rule_blocked', $res['code'] );
		$this->assertSame( 403, $res['status'] );
		$this->assertSame( 'rule/checkout', $res['rule']['key'] );
		$this->assertStringContainsString( 'release the rule', $res['error'] );
		$this->assertSame( 0, SA_Recording_Tool::$ran, 'a blocked tool executed' );
		$this->assertCount( 1, $this->fired( 'aura_worker_rule_blocked' ) );
	}

	public function test_a_block_does_not_reach_the_power_execute_hook(): void {
		// Ordering: rules are decided before the approval-required forensics
		// hook, so a blocked power tool leaves no "it ran" record.
		// test_power_double is the registered power-tool double
		// (SA_Fake_Power_Tool, ToolBaseTest.php); it requires a "target" param,
		// so a valid one is supplied — the block must still fire before the
		// power-execute hook, not because parameter validation rejected it.
		$this->install( array( $this->rule( 'rule/freeze', 'block', 'site' ) ) );
		$tools = new Aura_Worker_Tools();
		$tools->execute_tool( 'test_power_double', array( 'target' => 'homepage' ) );
		$this->assertCount( 0, $this->fired( 'aura_worker_power_execute' ) );
		$this->assertCount( 1, $this->fired( 'aura_worker_rule_blocked' ) );
	}

	public function test_a_warn_runs_and_attaches_the_warning(): void {
		$this->install( array( $this->rule( 'rule/careful', 'warn', 'page', '7' ) ) );
		$tools = new Aura_Worker_Tools();
		$res   = $tools->execute_tool( 'test_recording_tool', array( 'post_id' => 7 ) );

		$this->assertTrue( $res['success'] );
		$this->assertSame( 1, SA_Recording_Tool::$ran );
		$this->assertSame( array( array( 'rule' => 'rule/careful', 'reason' => 'r:rule/careful' ) ), $res['warnings'] );
		$this->assertCount( 1, $this->fired( 'aura_worker_rule_warned' ) );
	}

	public function test_a_rule_on_another_resource_does_not_apply(): void {
		$this->install( array( $this->rule( 'rule/checkout', 'block', 'page', '7' ) ) );
		$tools = new Aura_Worker_Tools();
		$res   = $tools->execute_tool( 'test_recording_tool', array( 'post_id' => 8 ) );
		$this->assertTrue( $res['success'] );
		$this->assertArrayNotHasKey( 'warnings', $res );
	}

	public function test_a_plain_read_is_never_enforced(): void {
		// Reads inherit the sentinel too, so a freeze would refuse every one of
		// them if reads were enforced — including, once Task 10 lands it,
		// audit_rules, which is how the operator sees the freeze. Any shipped
		// read tool proves the point; `audit_cron`
		// (class-tool-audit-cron.php) is read_only with requires_approval
		// false, and unlike get_site_context it does not reach for
		// wp-admin/includes files this stub environment does not provide.
		// (Read that file and pick another plain read if it changed.)
		$this->install( array( $this->rule( 'rule/freeze', 'block', 'site' ) ) );
		$tools = new Aura_Worker_Tools();
		$ann   = $tools->get_tool( 'audit_cron' )->get_annotations();
		$this->assertTrue( $ann['read_only'] );
		$this->assertEmpty( $ann['requires_approval'] );
		$res = $tools->execute_tool( 'audit_cron', array() );
		$this->assertTrue( $res['success'] );
		$this->assertCount( 0, $this->fired( 'aura_worker_rule_blocked' ) );
	}

	public function test_an_approval_bound_read_is_enforced_like_a_write(): void {
		// test_read_approval is read_only + requires_approval
		// (SA_Fake_Read_Approval_Tool, GrantEnforcementTest.php).
		$this->install( array( $this->rule( 'rule/freeze', 'block', 'site' ) ) );
		$tools = new Aura_Worker_Tools();
		$res   = $tools->execute_tool( 'test_read_approval', array() );
		$this->assertSame( 'aura_rule_blocked', $res['code'] ?? null );
	}

	public function test_a_page_rule_catches_a_tool_that_declares_nothing(): void {
		// test_double_tool inherits the sentinel. Under a page-only ruleset it
		// must still be refused — that is what the sentinel is for.
		$this->install( array( $this->rule( 'rule/checkout', 'block', 'page', '7' ) ) );
		$tools = new Aura_Worker_Tools();
		$res   = $tools->execute_tool( 'test_double_tool', array( 'target' => 'x' ) );
		$this->assertSame( 'aura_rule_blocked', $res['code'] ?? null );
	}

	public function test_a_warning_survives_a_tool_that_threw(): void {
		// The warn was decided before execute() and is true whatever execute()
		// did. Nothing downstream can recover it: execute_tool() answers its
		// own caller, and SiteAgent's own route is exempt from the core REST
		// seam that carries warnings for everything else.
		$this->install( array( $this->rule( 'rule/careful', 'warn', 'site' ) ) );
		$tools = new Aura_Worker_Tools();
		$res   = $tools->execute_tool( 'test_throwing_tool', array() );

		$this->assertFalse( $res['success'] );
		$this->assertSame( 'the update failed', $res['error'], 'the failure stopped being reported' );
		$this->assertSame(
			array( array( 'rule' => 'rule/careful', 'reason' => 'r:rule/careful' ) ),
			$res['warnings'] ?? null,
			'a tool that failed under a warn rule told its caller nothing about the rule'
		);
	}

	public function test_a_php_error_is_a_failed_call_not_a_dead_request(): void {
		// A TypeError implements Throwable without extending Exception, so a
		// `catch ( Exception )` lets it past — and past the catch there is no
		// failure result, no warning, and no response at all: one tool's bug
		// becomes the whole MCP request's fatal. The tool boundary is where a
		// per-tool failure stops being everyone's.
		$this->install( array( $this->rule( 'rule/careful', 'warn', 'site' ) ) );
		$tools = new Aura_Worker_Tools();
		$res   = $tools->execute_tool( 'test_fatal_tool', array() );

		$this->assertFalse( $res['success'] );
		$this->assertSame( 'bad argument', $res['error'], 'the PHP Error escaped the tool boundary' );
		$this->assertSame(
			array( array( 'rule' => 'rule/careful', 'reason' => 'r:rule/careful' ) ),
			$res['warnings'] ?? null
		);
	}

	public function test_a_block_is_decided_after_parameter_validation(): void {
		// A malformed call fails on its parameters, not on a rule — the rule
		// message would be a lie about a call that could never run.
		$this->install( array( $this->rule( 'rule/freeze', 'block', 'site' ) ) );
		$tools = new Aura_Worker_Tools();
		$res   = $tools->execute_tool( 'test_double_tool', array() ); // missing required "target"
		$this->assertSame( 'Parameter validation failed.', $res['error'] );
		$this->assertCount( 0, $this->fired( 'aura_worker_rule_blocked' ) );
	}

	public function test_dedup_never_exempts_a_later_call(): void {
		// The design's central invariant: the forensic EVENT is deduplicated
		// per rule per dispatch, but the REFUSAL is never deduplicated — a
		// second mutation attempted under an already-recorded rule is still
		// refused. Pin both halves in one test: two calls, two refusals, one
		// event. (A buggy `if ( ! $fresh ) { return array( 'effect' => null ); }`
		// short-circuit right before the block branch in enforce() — the exact
		// defect this invariant guards against — would let the second call
		// through while still leaving every other test in this file green.)
		$this->install( array( $this->rule( 'rule/freeze', 'block', 'site' ) ) );
		$tools = new Aura_Worker_Tools();

		$first  = $tools->execute_tool( 'test_recording_tool', array( 'post_id' => 1 ) );
		$second = $tools->execute_tool( 'test_recording_tool', array( 'post_id' => 2 ) );

		$this->assertSame( 'aura_rule_blocked', $first['code'] ?? null, 'the first call was not refused' );
		$this->assertSame( 'aura_rule_blocked', $second['code'] ?? null, 'deduplicating the event exempted the second call' );
		$this->assertSame( 0, SA_Recording_Tool::$ran );
		$this->assertCount( 1, $this->fired( 'aura_worker_rule_blocked' ), 'the event should fire once per rule per dispatch' );
	}

	public function test_a_block_lands_before_any_snapshot_is_attempted(): void {
		// SA_Recording_Tool::$ran === 0 (see test_a_block_runs_nothing_and_says_
		// which_rule) proves execute() as a whole never ran, but real tools
		// snapshot as the FIRST statement inside execute() (see
		// class-tool-gutenberg-update-block.php), before any mutation — so
		// "execute() didn't run" and "no snapshot was taken" are the same fact
		// only as long as nobody hoists snapshot creation above the rule check
		// in execute_tool(). Pin the snapshot point directly: a snapshot of a
		// call that was refused would be a lie about what happened — there is
		// nothing to roll back to, because nothing ran.
		$this->install( array( $this->rule( 'rule/freeze', 'block', 'site' ) ) );
		$tools = new Aura_Worker_Tools();
		$res   = $tools->execute_tool( 'test_snapshotting_tool', array() );

		$this->assertSame( 'aura_rule_blocked', $res['code'] ?? null );
		$this->assertSame( 0, SA_Snapshotting_Tool::$snapshot_attempts, 'a blocked call still took a snapshot' );
	}

	/**
	 * Spec decision 4: a rule outranks an approval. A valid, correctly-scoped
	 * grant is not enough to run a call a rule blocks — the message must name
	 * the rule so nobody goes looking for a grant bug instead. This has to run
	 * through Aura_Worker_MCP::execute_tool(), the only path where the grant
	 * gate and the rule decision both sit in front of the same call: the grant
	 * gate passes (the grant is genuinely valid), then Aura_Worker_Tools::
	 * execute_tool() decides the rule and blocks anyway. Mint pattern copied
	 * from GrantEnforcementTest / AbilitiesGrantReuseTest.
	 */
	public function test_a_rule_outranks_a_valid_approval_grant(): void {
		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
			$this->markTestSkipped( 'ext-sodium is not available.' );
		}
		$keypair = sodium_crypto_sign_keypair();
		$secret  = sodium_crypto_sign_secretkey( $keypair );
		$GLOBALS['_options']['aura_worker_grant_pubkey'] = base64_encode( sodium_crypto_sign_publickey( $keypair ) );
		$site_hash = hash( 'sha256', 'raw-site-token' );
		$GLOBALS['_options']['aura_worker_site_token'] = $site_hash;

		$this->install( array( $this->rule( 'rule/checkout', 'block', 'site' ) ) );

		$params  = array( 'post_id' => 7 );
		$payload = array(
			'v'             => 1,
			'tool'          => 'test_recording_tool',
			'params_sha256' => hash( 'sha256', Aura_Worker_Grant::canonical_json( $params ) ),
			'site'          => $site_hash,
			'nonce'         => bin2hex( random_bytes( 16 ) ),
			'iat'           => time(),
			'exp'           => time() + 300,
		);
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$sig  = sodium_crypto_sign_detached( $json, $secret );
		$b64  = static function ( string $s ): string {
			return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
		};
		$grant = $b64( $json ) . '.' . $b64( $sig );

		$req = new WP_REST_Request();
		$req->set_param( 'tool', 'test_recording_tool' );
		$req->set_param( 'params', $params );
		$req->set_header( 'X-Aura-Approval-Grant', $grant );

		$mcp  = new Aura_Worker_MCP( new Aura_Worker_Security() );
		$resp = $mcp->execute_tool( $req );

		$this->assertSame( 403, $resp->get_status() );
		$data = $resp->get_data();
		$this->assertSame( 'aura_rule_blocked', $data['code'] ?? null );
		// blocked_result()'s exact wording — the point of this test is that it
		// still says this even though a valid grant was presented.
		$this->assertStringContainsString( 'rule/checkout', $data['error'] );
		$this->assertStringContainsString( 'approval does not override a rule; release the rule first', $data['error'] );
		$this->assertSame( 0, SA_Recording_Tool::$ran, 'a rule-blocked call under a valid grant still ran the tool' );
	}

	public function test_the_mcp_route_returns_403_for_a_block(): void {
		$this->install( array( $this->rule( 'rule/freeze', 'block', 'site' ) ) );
		$GLOBALS['_options']['aura_worker_site_token'] = Aura_Worker_Security::hash_token( 'tok' );
		$security = new Aura_Worker_Security();
		$mcp      = new Aura_Worker_MCP( $security );
		$req      = new WP_REST_Request();
		$req->set_header( 'X-Aura-Token', 'tok' );
		$req->set_param( 'tool', 'test_double_tool' );
		$req->set_param( 'params', array( 'target' => 'x' ) );
		$resp = $mcp->execute_tool( $req );
		$this->assertSame( 403, $resp->status );
		$this->assertSame( 'aura_rule_blocked', $resp->data['code'] );
	}

	public function test_enforce_reads_a_matched_allow_as_no_verdict_for_siteagent_tools(): void {
		$this->install( array( $this->rule( 'rule/allow', 'allow', 'site' ) ) );
		$v = Aura_Worker_Rules::enforce( array( array( 'type' => 'site', 'id' => '*' ) ), 'clear_caches' );
		$this->assertNull( $v['effect'], 'allow has no meaning on the tools path — the approval queue is the default there already' );
	}
}
