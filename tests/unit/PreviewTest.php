<?php
/**
 * Tests for the tools/preview path — Aura_Worker_Tools::preview_tool and the
 * Aura_Worker_MCP preview REST handler. This is the dry-run surface the Aura
 * gateway calls to show a human what a power action would do before approval.
 *
 * Relies on the fake tools declared in ToolBaseTest.php (auto-registered by the
 * registry via get_declared_classes): SA_Fake_Tool (no preview) and
 * SA_Fake_Power_Tool (supports_preview=true, dry_run returns a payload).
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class PreviewTest extends TestCase {

	private Aura_Worker_Tools $registry;

	protected function setUp(): void {
		sa_reset_state();
		$this->registry = new Aura_Worker_Tools();
	}

	// --- registry preview_tool ------------------------------------------------

	public function test_preview_unknown_tool_fails(): void {
		$result = $this->registry->preview_tool( 'no_such_tool', array() );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Unknown tool', $result['error'] );
	}

	public function test_preview_validates_params_first(): void {
		// SA_Fake_Tool requires "target".
		$result = $this->registry->preview_tool( 'test_double_tool', array() );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'Parameter validation failed.', $result['error'] );
	}

	public function test_preview_unsupported_tool_returns_null_preview(): void {
		// SA_Fake_Tool does not declare supports_preview.
		$result = $this->registry->preview_tool( 'test_double_tool', array( 'target' => 'x' ) );
		$this->assertTrue( $result['success'] );
		$this->assertFalse( $result['supported'] );
		$this->assertNull( $result['preview'] );
	}

	public function test_preview_supported_tool_returns_dry_run_payload(): void {
		$result = $this->registry->preview_tool( 'test_power_double', array( 'target' => 'homepage' ) );
		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['supported'] );
		$this->assertSame( array( 'preview' => 'would run: homepage' ), $result['preview'] );
	}

	// --- MCP REST handler -----------------------------------------------------

	private function mcp(): Aura_Worker_MCP {
		return new Aura_Worker_MCP( new Aura_Worker_Security() );
	}

	private function request( string $tool, array $params ): WP_REST_Request {
		$req = new WP_REST_Request();
		$req->set_param( 'tool', $tool );
		$req->set_param( 'params', $params );
		return $req;
	}

	public function test_handler_returns_preview_payload(): void {
		$resp = $this->mcp()->preview_tool( $this->request( 'test_power_double', array( 'target' => 'x' ) ) );

		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
		$data = $resp->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertTrue( $data['supported'] );
		$this->assertSame( array( 'preview' => 'would run: x' ), $data['preview'] );
	}

	public function test_handler_unknown_tool_is_400(): void {
		$resp = $this->mcp()->preview_tool( $this->request( 'no_such_tool', array() ) );

		$this->assertSame( 400, $resp->get_status() );
		$this->assertFalse( $resp->get_data()['success'] );
	}

	public function test_handler_defaults_missing_params_to_empty(): void {
		// params omitted entirely — handler must coerce to array(), not fatal.
		$req = new WP_REST_Request();
		$req->set_param( 'tool', 'test_double_tool' );
		$resp = $this->mcp()->preview_tool( $req );

		// test_double_tool requires "target" → validation failure, cleanly returned.
		$this->assertSame( 400, $resp->get_status() );
		$this->assertFalse( $resp->get_data()['success'] );
	}

	public function test_preview_declares_what_the_call_touches_even_without_a_dry_run(): void {
		$tools = new Aura_Worker_Tools();
		$res   = $tools->preview_tool( 'test_double_tool', array( 'target' => 'x' ) );
		$this->assertTrue( $res['success'] );
		$this->assertFalse( $res['supported'] );
		// test_double_tool declares nothing (Task 5), so it carries the sentinel —
		// which is exactly what the gateway must see for an undeclared tool.
		$this->assertSame( array( array( 'type' => Aura_Worker_Rules::UNKNOWN, 'id' => '*' ) ), $res['touches'] );
		$this->assertNull( $res['rule_match'] );
	}

	public function test_preview_reports_the_rule_that_would_block_without_blocking(): void {
		$GLOBALS['_options'][ Aura_Worker_Rules::OPTION ] = array(
			'envelope'    => 'x.y',
			'seq'         => 1,
			'issued_at'   => '',
			'received_at' => time(),
			'rules'       => array(
				array( 'key' => 'rule/freeze', 'effect' => 'block', 'target' => array( 'type' => 'site' ), 'reason' => 'deploy' ),
			),
		);
		$tools = new Aura_Worker_Tools();
		$res   = $tools->preview_tool( 'test_double_tool', array( 'target' => 'x' ) );
		$this->assertTrue( $res['success'], 'a preview must not be blocked' );
		$this->assertSame( array( 'key' => 'rule/freeze', 'effect' => 'block', 'reason' => 'deploy' ), $res['rule_match'] );
		$this->assertEmpty( array_filter( $GLOBALS['_did_actions'], static function ( $a ) {
			return 'aura_worker_rule_blocked' === $a['tag'];
		} ), 'a preview fired the block hook' );
	}

	public function test_preview_reports_no_rule_for_an_allow_winner_because_execution_ignores_it(): void {
		// `allow` speaks only at the Elementor door: Aura_Worker_Rules::enforce()
		// nulls an allow winner, so the tools path runs the call with no rule at
		// all. A preview that named the rule anyway would warn the operator about
		// a verdict the very next request does not apply.
		$GLOBALS['_options'][ Aura_Worker_Rules::OPTION ] = array(
			'envelope'    => 'x.y',
			'seq'         => 1,
			'issued_at'   => '',
			'received_at' => time(),
			'rules'       => array(
				array( 'key' => 'rule/press-ok', 'effect' => 'allow', 'target' => array( 'type' => 'page', 'id' => '7' ), 'reason' => 'the press page is agent-owned' ),
			),
		);
		$tools = new Aura_Worker_Tools();
		$res   = $tools->preview_tool( 'test_double_tool', array( 'target' => 'x' ) );

		$this->assertTrue( $res['success'] );
		$this->assertNotNull( Aura_Worker_Rules::match( $res['touches'], Aura_Worker_Rules::rules(), null, Aura_Worker_Rules::site_ref() ), 'the rule really does match this call — match() is not the question' );
		$this->assertNull( $res['rule_match'], 'the preview reports what enforcement would apply, and enforcement applies nothing' );
		$this->assertSame( array( 'effect' => null ), Aura_Worker_Rules::enforce( $res['touches'], 'test_double_tool' ), 'the two agree about the same call' );
	}
}
