<?php
/**
 * Every mutating tool declares what a call touches. The default is the
 * `unknown` sentinel, which matches EVERY rule — so a tool that forgets is
 * caught by a page rule and a plugin rule, not only by a freeze. That is the
 * inverse of the default J5 found on the abilities path. This file pins that
 * each shipped tool either narrows deliberately or declares site:* on purpose;
 * none may inherit the sentinel.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class ToolTouchesTest extends TestCase {

	/**
	 * Tools whose honest answer IS the whole site, and say so explicitly.
	 * (`db_query` lives in the Power Pack, a separate plugin, and declares there.)
	 */
	const SITE_WIDE = array(
		'clear_caches',
		'cleanup_transients',
		'cleanup_orphaned_assets',
		'backup_plugins',
	);

	protected function setUp(): void {
		sa_reset_state();
	}

	public function test_the_base_default_is_the_unknown_sentinel(): void {
		$tool = new SA_Fake_Tool();
		$this->assertSame( array( array( 'type' => 'unknown', 'id' => '*' ) ), $tool->touches( array( 'target' => 'x' ) ) );
	}

	public function test_maintenance_tools_declare_the_site_explicitly(): void {
		$tools = new Aura_Worker_Tools();
		foreach ( self::SITE_WIDE as $name ) {
			$tool = $tools->get_tool( $name );
			$this->assertNotNull( $tool, "{$name} is not registered" );
			$this->assertSame( array( array( 'type' => 'site', 'id' => '*' ) ), $tool->touches( array() ), "{$name} does not declare site:*" );
		}
	}

	public function test_update_plugin_safely_touches_the_named_plugin(): void {
		$tools = new Aura_Worker_Tools();
		$tool  = $tools->get_tool( 'update_plugin_safely' );
		$this->assertSame(
			array( array( 'type' => 'plugin', 'id' => 'woocommerce' ) ),
			$tool->touches( array( 'plugin_slug' => 'woocommerce' ) )
		);
	}

	/** @dataProvider content_tools */
	public function test_content_tools_touch_the_post_under_both_names( string $name ): void {
		$tools = new Aura_Worker_Tools();
		$tool  = $tools->get_tool( $name );
		$this->assertNotNull( $tool, "{$name} is not registered" );
		$this->assertSame(
			array(
				array( 'type' => 'post', 'id' => '42' ),
				array( 'type' => 'page', 'id' => '42' ),
			),
			$tool->touches( array( 'post_id' => 42 ) )
		);
	}

	public static function content_tools(): array {
		return array(
			array( 'set_seo_meta' ),
			array( 'update_page_block' ),
			array( 'create_page_from_blocks' ),
		);
	}

	public function test_a_content_tool_without_a_post_id_falls_back_to_the_site(): void {
		// create_page_from_blocks with no post_id creates a NEW page: there is no
		// narrower resource yet. It declares site:* (a freeze catches a create;
		// a rule on an existing page does not), not the sentinel.
		$tools = new Aura_Worker_Tools();
		$tool  = $tools->get_tool( 'create_page_from_blocks' );
		$this->assertSame( array( array( 'type' => 'site', 'id' => '*' ) ), $tool->touches( array() ) );
	}

	public function test_no_shipped_mutating_tool_inherits_the_sentinel(): void {
		// The sentinel is the safety net, not a declaration. Every shipped tool
		// that needs a rule says what it touches — narrowly, or site:* on
		// purpose — and it says it in a form the matcher can read. "Differs
		// from the sentinel" is not enough: `[]` differs from the sentinel too,
		// and a declaration that normalises to nothing would be a tool exempt
		// from every rule, which is the opposite of what this test is for.
		$tools = new Aura_Worker_Tools();
		foreach ( $tools->list_tools() as $meta ) {
			$tool = $tools->get_tool( $meta['name'] );
			$ann  = $tool->get_annotations();
			$needs_rule = empty( $ann['read_only'] ) || ! empty( $ann['requires_approval'] );
			if ( ! $needs_rule || 0 === strpos( $meta['name'], 'test_' ) ) {
				continue;
			}
			$declared = $tool->touches( array( 'post_id' => 1, 'plugin_slug' => 'x' ) );
			$this->assertNotSame(
				array( array( 'type' => 'unknown', 'id' => '*' ) ),
				$declared,
				"{$meta['name']} inherits the sentinel — declare touches(): narrow it, or site:* on purpose"
			);
			$this->assertIsArray( $declared, "{$meta['name']}: touches() must return a list" );
			$this->assertNotEmpty( $declared, "{$meta['name']}: touches() returned an empty list — a tool that touches nothing is a tool no rule can reach" );
			foreach ( $declared as $entry ) {
				$this->assertIsArray( $entry, "{$meta['name']}: a touches() entry must be an array" );
				$this->assertArrayHasKey( 'type', $entry, "{$meta['name']}: a touches() entry has no type" );
				$this->assertArrayHasKey( 'id', $entry, "{$meta['name']}: a touches() entry has no id" );
				$this->assertContains(
					$entry['type'],
					array( 'site', 'page', 'post', 'plugin' ),
					"{$meta['name']}: touches() declared an unrecognised type — the vocabulary is site|page|post|plugin"
				);
				$this->assertNotSame( '', (string) $entry['id'], "{$meta['name']}: a touches() entry has an empty id" );
			}
		}
	}

	public function test_a_declaration_that_normalises_to_nothing_is_treated_as_undeclared(): void {
		// The matcher's own guard, independent of the sweep above: whatever a
		// tool returns, a site freeze still catches it. `[]`, entries with no
		// id, entries that are not arrays at all — all of them mean "this tool
		// told us nothing", which is the sentinel, not an exemption.
		$freeze = array( 'key' => 'rule/freeze', 'effect' => 'block', 'target' => array( 'type' => 'site' ), 'reason' => '' );
		foreach ( array(
			array(),
			array( 'nonsense' ),
			array( array( 'type' => 'page' ) ),
			array( array( 'type' => '', 'id' => '' ) ),
		) as $i => $declared ) {
			$this->assertNotNull(
				Aura_Worker_Rules::match( $declared, array( $freeze ) ),
				"declaration #{$i} escaped a site freeze"
			);
		}
	}
}
