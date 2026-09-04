<?php
/**
 * The facts the governor stands on, pinned against Elementor 4.3.0-beta1
 * (read from the wordpress.org zip on 2026-09-02) and WP 7.1's Abilities
 * API. A pin that fails here means the release that moved it must be read
 * before the governor is trusted on it.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class ElementorDoorContractTest extends TestCase {

	protected function setUp(): void {
		sa_reset_state();
		require_once dirname( __DIR__, 2 ) . '/digitizer-site-worker/includes/class-elementor-door-governor.php';
	}

	public function test_the_27_ability_ids_are_partitioned_into_16_reads_and_11_writes(): void {
		$reads  = Aura_Worker_Elementor_Door::READ_ALLOWLIST;
		$writes = array_keys( Aura_Worker_Elementor_Door::WRITE_TABLE );
		$this->assertCount( 16, $reads );
		$this->assertCount( 11, $writes );
		$this->assertSame( array(), array_intersect( $reads, $writes ), 'a slug cannot be both' );
		$all = array_merge( $reads, $writes );
		sort( $all );
		$this->assertSame( 27, count( array_unique( $all ) ) );
		foreach ( $all as $slug ) {
			$this->assertMatchesRegularExpression( '#^elementor/[a-z0-9-]+$#', $slug );
		}
	}

	public function test_wp_ability_keeps_the_stored_callback_in_a_protected_property_the_governor_can_reflect(): void {
		$prop = new ReflectionProperty( WP_Ability::class, 'execute_callback' );
		$this->assertFalse( $prop->isPublic(), 'no getter, no public property — Reflection is the read (R2)' );
	}

	public function test_the_two_transport_routes_the_close_covers(): void {
		$this->assertTrue( Aura_Worker_Elementor_Door::route_is_door( '/elementor/mcp' ) );
		$this->assertTrue( Aura_Worker_Elementor_Door::route_is_door( '/elementor/mcp/' ) );
		$this->assertTrue( Aura_Worker_Elementor_Door::route_is_door( '/wp-abilities/v1/abilities/elementor/create-page/run' ) );
		$this->assertFalse( Aura_Worker_Elementor_Door::route_is_door( '/wp-abilities/v1/abilities/aura/check_health/run' ) );
		$this->assertFalse( Aura_Worker_Elementor_Door::route_is_door( '/elementor-mcp-composer/v1.0.10/mcp-credentials' ), 'the onboarding admin controllers are not the door' );
		$this->assertFalse( Aura_Worker_Elementor_Door::route_is_door( '/aura/mcp/tools/execute' ) );
	}

	public function test_design_system_storage_keys_are_the_4_3_ones(): void {
		$this->assertSame( 'e_global_class', Aura_Worker_Elementor_Door::CPT_GLOBAL_CLASS );
		$this->assertSame( 'e_default_style', Aura_Worker_Elementor_Door::CPT_DEFAULT_STYLE );
		$this->assertSame( 'elementor_component', Aura_Worker_Elementor_Door::CPT_COMPONENT );
		$this->assertSame(
			array( '_elementor_global_variables', '_elementor_global_classes_order', '_elementor_global_classes_order_preview', '_elementor_global_classes_labels', '_elementor_global_classes_labels_preview', '_elementor_default_styles_post_ids', '_elementor_page_settings' ),
			Aura_Worker_Elementor_Door::KIT_META_KEYS
		);
	}
}
