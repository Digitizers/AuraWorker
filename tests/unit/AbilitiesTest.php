<?php
/**
 * Tests for Aura_Worker_Abilities — the WordPress Abilities API bridge that
 * dual-registers SiteAgent tools as WP abilities for the official MCP adapter.
 *
 * Uses the fake tools declared in ToolBaseTest.php (auto-registered by the
 * registry): SA_Fake_Tool (required "target") and SA_Fake_Power_Tool
 * (destructive + requires_approval).
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class AbilitiesTest extends TestCase {

	protected function setUp(): void {
		sa_reset_state();
		$abilities = new Aura_Worker_Abilities();
		// Mirror the real hook order: category first (wp_abilities_api_categories_init),
		// then the abilities (wp_abilities_api_init).
		$abilities->register_category();
		$abilities->register();
	}

	public function test_registers_the_category_with_a_description(): void {
		// The category must be registered (on its own earlier init hook) or the
		// Abilities API rejects every ability, so the MCP adapter finds nothing.
		$this->assertArrayHasKey( 'site-management', $GLOBALS['_ability_categories'] );
		$cat = $GLOBALS['_ability_categories']['site-management'];
		$this->assertArrayHasKey( 'label', $cat );
		$this->assertArrayHasKey( 'description', $cat );
		$this->assertNotSame( '', $cat['description'] );
	}

	public function test_all_abilities_reference_a_registered_category(): void {
		foreach ( $GLOBALS['_abilities'] as $name => $ability ) {
			$this->assertArrayHasKey(
				$ability['category'],
				$GLOBALS['_ability_categories'],
				"ability {$name} references an unregistered category"
			);
		}
	}

	public function test_registers_the_shipped_tools_as_abilities(): void {
		$abilities = $GLOBALS['_abilities'];
		$this->assertGreaterThanOrEqual( 10, count( $abilities ) );
		// Names are namespaced + hyphenated.
		$this->assertArrayHasKey( 'aura-worker/test-double-tool', $abilities );
	}

	public function test_ability_shape(): void {
		$a = $GLOBALS['_abilities']['aura-worker/test-double-tool'];

		$this->assertArrayHasKey( 'label', $a );
		$this->assertArrayHasKey( 'description', $a );
		$this->assertSame( 'object', $a['input_schema']['type'] );
		$this->assertContains( 'target', $a['input_schema']['required'] );
		$this->assertIsCallable( $a['execute_callback'] );
		$this->assertIsCallable( $a['permission_callback'] );
		$this->assertTrue( $a['meta']['show_in_rest'] );
		// test_double_tool inherits the base annotations, so it MUTATES — and a
		// mutating ability is withheld from co-installed MCP servers rather
		// than advertised to them (J5).
		$this->assertSame( 'private', $a['meta']['mcp']['type'] );
		$this->assertFalse( $a['meta']['mcp']['public'] );
	}

	public function test_execute_callback_routes_back_to_the_tool(): void {
		$a      = $GLOBALS['_abilities']['aura-worker/test-double-tool'];
		$result = ( $a['execute_callback'] )( array( 'target' => 'homepage' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'homepage', $result['result']['echo']['target'] );
	}

	public function test_annotations_map_from_tool_metadata(): void {
		$power = $GLOBALS['_abilities']['aura-worker/test-power-double']['meta']['annotations'];
		$this->assertTrue( $power['requires_approval'] );
		$this->assertTrue( $power['destructive'] );
		$this->assertFalse( $power['readonly'] );

		$read = $GLOBALS['_abilities']['aura-worker/test-double-tool']['meta']['annotations'];
		$this->assertFalse( $read['requires_approval'] );
	}

	public function test_permission_callback_requires_manage_options(): void {
		$cb = $GLOBALS['_abilities']['aura-worker/test-double-tool']['permission_callback'];

		$GLOBALS['_caps'] = array( 'manage_options' );
		$this->assertTrue( $cb() );

		$GLOBALS['_caps'] = array(); // no caps
		$this->assertFalse( $cb() );
	}

	public function test_parameterless_ability_gets_an_input_default(): void {
		// A tool with no required params must default a missing input to {} so
		// the Abilities API doesn't reject a no-argument call.
		$found = false;
		foreach ( $GLOBALS['_abilities'] as $ability ) {
			if ( empty( $ability['input_schema']['required'] ) ) {
				$this->assertArrayHasKey( 'default', $ability['input_schema'] );
				$found = true;
			}
		}
		$this->assertTrue( $found, 'expected at least one parameterless ability' );
	}

	public function test_shipped_tools_are_registered(): void {
		// A couple of real shipped tools appear as namespaced abilities.
		$this->assertArrayHasKey( 'aura-worker/get-site-context', $GLOBALS['_abilities'] );
		$this->assertIsBool( $GLOBALS['_abilities']['aura-worker/get-site-context']['meta']['annotations']['readonly'] );
	}
}
