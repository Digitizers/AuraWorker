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

final class RulesEnforcementTest extends TestCase {

	protected function setUp(): void {
		sa_reset_state();
		SA_Recording_Tool::$ran = 0;
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
		// test_read_approval is read_only + requires_approval (ToolBaseTest).
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
}
