<?php
/**
 * Ruling P32: a class deletion's collateral pages are DECLARED and judged
 * before the write, not discovered at priority 1 and waved through.
 *
 * `touches_for( 'elementor/manage-classes', … )` asks Elementor which pages a
 * `delete` operation would rewrite — the same
 * `Global_Classes_Relations::get_posts_by_style()` its own
 * `get_posts_affected_by_deletion()` calls — and adds each one to the touches
 * the ruleset is matched against. A `warn` on one of them therefore HOLDS the
 * call, the way a warn holds everywhere else in this governor.
 *
 * The drift half (a warned page that was never declared) lives in
 * ElementorDoorCreationTest, beside the rest of the cleanup-hook cases.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class ElementorDoorCollateralTest extends TestCase {

	protected function setUp(): void {
		sa_reset_state();
		foreach ( array( 'class-aura-worker-door-log', 'class-aura-worker-door-holds', 'class-elementor-door-governor' ) as $f ) {
			require_once dirname( __DIR__, 2 ) . '/digitizer-site-worker/includes/' . $f . '.php';
		}
		Aura_Worker_Elementor_Door::reset_for_tests();
		Aura_Worker_Elementor_Door::init();
		$GLOBALS['_current_user_id'] = 3;
		$GLOBALS['_user_logins'][3]  = 'bot';
		$this->seedPost( 12 );
		$this->seedPost( 13, 'post' );
	}

	private function seedPost( int $id, string $type = 'page' ): void {
		$GLOBALS['_posts'][ $id ] = (object) array(
			'ID'          => $id,
			'post_type'   => $type,
			'post_status' => 'publish',
			'post_content' => '',
		);
	}

	/** The stored ruleset record, written straight to the option. */
	private function installRuleset( array $rules ): void {
		$GLOBALS['_options'][ Aura_Worker_Rules::OPTION ] = array(
			'envelope'    => 'x.y',
			'seq'         => 5,
			'issued_at'   => '2026-09-03T00:00:00Z',
			'received_at' => time(),
			'rules'       => $rules,
		);
	}

	/** One delete operation naming a class id. */
	private function deleteById( string $class_id = 'g-a' ): array {
		return array( 'operations' => array( array( 'action' => 'delete', 'id' => $class_id ) ) );
	}

	private function touches( array $input ): array {
		$out = Aura_Worker_Elementor_Door::touches_for( 'elementor/manage-classes', $input );
		$this->assertIsArray( $out );
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* (a) the declaration                                                 */
	/* ------------------------------------------------------------------ */

	public function test_a_delete_declares_the_pages_the_class_is_used_on(): void {
		$GLOBALS['_sa_class_relations'] = array( 'g-a' => array( 12, 13 ) );

		$this->assertSame(
			array(
				array( 'type' => 'design_system', 'id' => '*' ),
				array( 'type' => 'page', 'id' => '12' ),
				array( 'type' => 'post', 'id' => '13' ), // post_type decides, as the page case does
			),
			$this->touches( $this->deleteById() )
		);
	}

	public function test_a_delete_by_label_resolves_the_class_the_way_elementor_does(): void {
		$GLOBALS['_sa_class_labels']    = array( 'g-a' => 'Hero', 'g-b' => 'Card' );
		$GLOBALS['_sa_class_relations'] = array( 'g-b' => array( 12 ) );

		$out = $this->touches( array( 'operations' => array( array( 'action' => 'delete', 'label' => 'Card' ) ) ) );

		$this->assertSame( array( 'design_system', 'page' ), array_column( $out, 'type' ) );
		$this->assertSame( '12', $out[1]['id'] );
	}

	public function test_a_label_that_resolves_to_nothing_declares_no_collateral(): void {
		$GLOBALS['_sa_class_labels']    = array( 'g-a' => 'Hero' );
		$GLOBALS['_sa_class_relations'] = array( 'g-a' => array( 12 ) );

		// Elementor answers `class_not_found`: nothing is deleted, so nothing
		// is collateral.
		$this->assertSame(
			array( array( 'type' => 'design_system', 'id' => '*' ) ),
			$this->touches( array( 'operations' => array( array( 'action' => 'delete', 'label' => 'Nope' ) ) ) )
		);
	}

	public function test_only_delete_operations_contribute_collateral(): void {
		$GLOBALS['_sa_class_relations'] = array( 'g-a' => array( 12 ) );

		$this->assertSame(
			array( array( 'type' => 'design_system', 'id' => '*' ) ),
			$this->touches( array( 'operations' => array( array( 'action' => 'update', 'id' => 'g-a' ), array( 'action' => 'create', 'label' => 'New' ) ) ) )
		);
	}

	public function test_a_throw_inside_elementor_contributes_nothing_rather_than_failing_the_call(): void {
		$GLOBALS['_sa_class_relations']       = array( 'g-a' => array( 12 ) );
		$GLOBALS['_sa_class_relations_throw'] = true;

		$this->assertSame(
			array( array( 'type' => 'design_system', 'id' => '*' ) ),
			$this->touches( $this->deleteById() ),
			'best effort: the drift check at priority 1 is still the backstop'
		);
	}

	public function test_the_other_design_system_abilities_declare_no_collateral(): void {
		$GLOBALS['_sa_class_relations'] = array( 'g-a' => array( 12 ) );

		foreach ( array( 'elementor/manage-default-styles', 'elementor/reorder-classes', 'elementor/manage-global-variable' ) as $slug ) {
			$this->assertSame(
				array( array( 'type' => 'design_system', 'id' => '*' ) ),
				Aura_Worker_Elementor_Door::touches_for( $slug, $this->deleteById() ),
				$slug . ' cannot delete a class'
			);
		}
	}

	/* ------------------------------------------------------------------ */
	/* (b) the judgement                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * `match()` takes the STRONGEST rule across the whole touch set — a block
	 * returns immediately, otherwise warn outranks allow — so a warn on one
	 * collateral page beats an allow on `design_system:*` and the call is
	 * HELD for the operator, before anything is written.
	 */
	public function test_a_warn_on_a_collateral_page_holds_a_call_an_allow_would_have_run(): void {
		$GLOBALS['_sa_class_relations'] = array( 'g-a' => array( 12 ) );
		$this->installRuleset(
			array(
				array( 'key' => 'rule/ds', 'effect' => 'allow', 'target' => array( 'type' => 'design_system' ), 'reason' => 'classes are fine' ),
				array( 'key' => 'rule/watch-12', 'effect' => 'warn', 'target' => array( 'type' => 'page', 'id' => '12' ), 'reason' => 'tell me about it' ),
			)
		);

		$touches = $this->touches( $this->deleteById() );
		$verdict = Aura_Worker_Elementor_Door::govern( 'elementor/manage-classes', $touches, $this->deleteById() );

		$this->assertSame( 'hold', $verdict['effect'] );
		$this->assertSame( 'warn', $verdict['verdict'] );
		$this->assertSame( 'rule/watch-12', $verdict['rule']['key'] );
	}

	/** A block on a collateral page outranks everything: refused outright. */
	public function test_a_block_on_a_collateral_page_blocks_the_call(): void {
		$GLOBALS['_sa_class_relations'] = array( 'g-a' => array( 12 ) );
		$this->installRuleset(
			array(
				array( 'key' => 'rule/ds', 'effect' => 'allow', 'target' => array( 'type' => 'design_system' ), 'reason' => 'classes are fine' ),
				array( 'key' => 'rule/keep-12', 'effect' => 'block', 'target' => array( 'type' => 'page', 'id' => '12' ), 'reason' => 'hands off' ),
			)
		);

		$verdict = Aura_Worker_Elementor_Door::govern( 'elementor/manage-classes', $this->touches( $this->deleteById() ), $this->deleteById() );

		$this->assertSame( 'block', $verdict['effect'] );
		$this->assertSame( 'rule/keep-12', $verdict['rule']['key'] );
	}

	/** No collateral, no change: the design-system allow still runs the call. */
	public function test_an_allow_still_allows_when_no_collateral_page_is_ruled_on(): void {
		$GLOBALS['_sa_class_relations'] = array( 'g-a' => array( 12 ) );
		$this->installRuleset(
			array( array( 'key' => 'rule/ds', 'effect' => 'allow', 'target' => array( 'type' => 'design_system' ), 'reason' => 'classes are fine' ) )
		);

		$verdict = Aura_Worker_Elementor_Door::govern( 'elementor/manage-classes', $this->touches( $this->deleteById() ), $this->deleteById() );

		$this->assertSame( 'allow', $verdict['effect'] );
	}

	/** The hold Aura shows the operator names the collateral pages too. */
	public function test_the_hold_lists_the_collateral_pages_for_the_operator(): void {
		$GLOBALS['_sa_class_relations'] = array( 'g-a' => array( 12 ) );
		$this->installRuleset(
			array(
				array( 'key' => 'rule/ds', 'effect' => 'allow', 'target' => array( 'type' => 'design_system' ), 'reason' => 'classes are fine' ),
				array( 'key' => 'rule/watch-12', 'effect' => 'warn', 'target' => array( 'type' => 'page', 'id' => '12' ), 'reason' => 'tell me about it' ),
			)
		);
		sa_register_ability(
			'elementor/manage-classes',
			array(
				'execute_callback'    => static function () {
					return array( 'ok' => true );
				},
				'permission_callback' => '__return_true',
			)
		);
		do_action( 'wp_abilities_api_init' );

		$out = wp_get_ability( 'elementor/manage-classes' )->execute( $this->deleteById() );

		$this->assertSame( 'aura_held_for_approval', $out->get_error_code() );
		$held = Aura_Worker_Door_Holds::get_held( (string) $out->get_error_data()['ref'] );
		$this->assertSame(
			array( 'design_system:*', 'page:12' ),
			array_map(
				static function ( $t ) {
					return $t['type'] . ':' . $t['id'];
				},
				$held['touches']
			),
			'the operator is shown the page the deletion would rewrite'
		);
	}
}
