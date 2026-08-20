<?php
/**
 * Tests for audit_mcp_exposure — the second-door inventory (Aura plan K7 step 2).
 *
 * The tool answers one question an operator cannot answer any other way: is
 * there another MCP server on this site, and how much of the shared ability
 * registry would it serve? Everything here is a fact the site can state about
 * itself; the verdict belongs to the fleet rollup and to the person reading it.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

require_once SA_PLUGIN_DIR . '/includes/tools/class-tool-audit-mcp-exposure.php';

final class McpExposureAuditTest extends TestCase {

	private Aura_Tool_Audit_Mcp_Exposure $tool;

	protected function setUp(): void {
		sa_reset_state();
		$this->tool = new Aura_Tool_Audit_Mcp_Exposure();
	}

	/** A read tool: no readonly=false annotation. */
	private function read_meta( array $extra = array() ): array {
		return array_merge( array( 'annotations' => array( 'readonly' => true ) ), $extra );
	}

	/** A write tool: an explicit readonly=false. */
	private function write_meta( array $extra = array() ): array {
		return array_merge( array( 'annotations' => array( 'readonly' => false ) ), $extra );
	}

	// --- the tool's own contract --------------------------------------------

	public function test_the_tool_declares_itself_read_only(): void {
		// A tool that audits agent doors must not be one. If this ever flips,
		// the gateway would start queueing it for approval — and an operator
		// investigating an incident would be unable to look.
		$annotations = $this->tool->get_annotations();

		$this->assertTrue( $annotations['read_only'] );
		$this->assertFalse( $annotations['destructive'] );
		$this->assertFalse( $annotations['requires_approval'] );
		$this->assertSame( 'audit_mcp_exposure', $this->tool->get_name() );
		$this->assertSame( array(), $this->tool->get_parameters() );
	}

	// --- the exposure rule ---------------------------------------------------

	public function test_an_ability_declaring_no_type_counts_as_exposed(): void {
		// Absent means 'tool' to every consumer, which is why the default is the
		// dangerous one and why the fork had to start declaring something else.
		sa_register_ability( 'someplugin/do-thing', $this->write_meta() );

		$result = $this->tool->execute( array() );

		$this->assertSame( 1, $result['abilities']['total'] );
		$this->assertSame( 1, $result['abilities']['discoverable_by_type_rule'] );
		$this->assertSame( 1, $result['abilities']['discoverable_and_mutating'] );
		$this->assertSame( array( 'someplugin/do-thing' ), $result['abilities']['discoverable_mutating_names'] );
	}

	public function test_an_ability_declaring_a_non_tool_type_is_not_exposed(): void {
		// What elementor-mcp 1.30.0+ does for its writes. Also covers the real
		// MCP types, which are equally "not tool" for this rule.
		foreach ( array( 'private', 'resource', 'prompt' ) as $type ) {
			sa_reset_state();
			sa_register_ability( 'someplugin/do-thing', $this->write_meta( array( 'mcp' => array( 'type' => $type ) ) ) );

			$result = $this->tool->execute( array() );

			$this->assertSame( 1, $result['abilities']['total'], $type );
			$this->assertSame( 0, $result['abilities']['discoverable_by_type_rule'], $type );
			$this->assertSame( 0, $result['abilities']['discoverable_and_mutating'], $type );
		}
	}

	public function test_an_ability_declaring_tool_explicitly_counts_as_exposed(): void {
		sa_register_ability( 'someplugin/do-thing', $this->write_meta( array( 'mcp' => array( 'type' => 'tool' ) ) ) );

		$this->assertSame( 1, $this->tool->execute( array() )['abilities']['discoverable_by_type_rule'] );
	}

	public function test_exposed_reads_are_counted_separately_from_writes(): void {
		// Read tools reachable from another server are the expected state, not a
		// finding — conflating the two would make every site look alarming.
		sa_register_ability( 'someplugin/list-things', $this->read_meta() );
		sa_register_ability( 'someplugin/do-thing', $this->write_meta() );

		$result = $this->tool->execute( array() );

		$this->assertSame( 2, $result['abilities']['discoverable_by_type_rule'] );
		$this->assertSame( 1, $result['abilities']['discoverable_and_mutating'] );
	}

	public function test_an_unclassified_ability_is_not_assumed_to_mutate(): void {
		// No readonly annotation at all. Guessing from the name would turn a
		// naming convention into a security finding.
		sa_register_ability( 'someplugin/delete-everything', array() );

		$result = $this->tool->execute( array() );

		$this->assertSame( 1, $result['abilities']['discoverable_by_type_rule'] );
		$this->assertSame( 0, $result['abilities']['discoverable_and_mutating'] );
	}

	public function test_counts_are_reported_with_no_server_and_are_gateable(): void {
		// The counts describe the ABILITIES, not any server's reach: with no
		// second server registered, nothing serves them, and a consumer must be
		// able to see that without inferring it. Both facts are in one response,
		// which is why the counts stay honest on a site with no door — they say
		// what would be handed over the moment one is installed.
		sa_register_ability( 'someplugin/do-thing', $this->write_meta() );

		$result = $this->tool->execute( array() );

		$this->assertSame( 1, $result['abilities']['discoverable_and_mutating'] );
		$this->assertSame( array(), $result['servers'], 'Nothing serves them here, and the response says so.' );
	}

	// --- bounded coverage ----------------------------------------------------

	public function test_the_ability_scan_is_bounded_and_says_so(): void {
		$cap = Aura_Tool_Audit_Mcp_Exposure::MAX_ABILITIES;
		for ( $i = 0; $i < $cap + 5; $i++ ) {
			sa_register_ability( 'someplugin/tool-' . $i, $this->read_meta() );
		}

		$result = $this->tool->execute( array() );

		$this->assertSame( $cap + 5, $result['coverage']['total_seen'], 'Everything is counted…' );
		$this->assertSame( $cap, $result['coverage']['returned'], '…but only the cap is inspected.' );
		$this->assertTrue( $result['coverage']['truncated'] );
		$this->assertSame( 'max_abilities', $result['coverage']['cap'] );
	}

	public function test_the_named_list_is_bounded_separately_and_still_flags_truncation(): void {
		// Both caps make the answer a lower bound, and the rollup must be able
		// to say so without knowing which one tripped.
		$named = Aura_Tool_Audit_Mcp_Exposure::MAX_NAMED;
		for ( $i = 0; $i < $named + 3; $i++ ) {
			sa_register_ability( 'someplugin/write-' . $i, $this->write_meta() );
		}

		$result = $this->tool->execute( array() );

		$this->assertSame( $named + 3, $result['abilities']['discoverable_and_mutating'], 'The COUNT is complete…' );
		$this->assertCount( $named, $result['abilities']['discoverable_mutating_names'], '…only the naming is capped.' );
		$this->assertTrue( $result['coverage']['truncated'] );
		$this->assertSame( 'max_named', $result['coverage']['cap'] );
	}

	// --- a site with no Abilities API ---------------------------------------

	public function test_an_empty_registry_reports_zeros_not_an_error(): void {
		$result = $this->tool->execute( array() );

		$this->assertTrue( $result['abilities_api_active'], 'The stub registry exists here.' );
		$this->assertSame( 0, $result['abilities']['total'] );
		$this->assertSame( 0, $result['abilities']['discoverable_and_mutating'] );
		$this->assertFalse( $result['coverage']['truncated'] );
		$this->assertSame( '', $result['coverage']['cap'] );
	}

	public function test_the_adapter_version_is_read_from_the_official_constant(): void {
		// The official WordPress MCP Adapter publishes WP_MCP_ADAPTER_VERSION.
		// Checking only the bundled-copy name reported an empty version on
		// exactly the sites most likely to have a second door, and a field that
		// is present but blank reads as "unknown".
		$this->assertSame(
			'1.2.3',
			Aura_Tool_Audit_Mcp_Exposure::pick_version( array( 'WP_MCP_ADAPTER_VERSION' => '1.2.3' ) )
		);
		$this->assertSame(
			'0.9.0',
			Aura_Tool_Audit_Mcp_Exposure::pick_version( array( 'WP_MCP_VERSION' => '0.9.0' ) ),
			'A bundled copy still answers.'
		);
		$this->assertSame(
			'1.2.3',
			Aura_Tool_Audit_Mcp_Exposure::pick_version(
				array( 'WP_MCP_ADAPTER_VERSION' => '1.2.3', 'WP_MCP_VERSION' => '0.9.0' )
			),
			'The official constant wins when both are present.'
		);
		$this->assertSame( '', Aura_Tool_Audit_Mcp_Exposure::pick_version( array() ) );
		$this->assertSame(
			'',
			Aura_Tool_Audit_Mcp_Exposure::pick_version( array( 'WP_MCP_ADAPTER_VERSION' => '' ) ),
			'An empty constant is no answer, not an answer of "".'
		);
	}

	// --- servers -------------------------------------------------------------

	public function test_no_mcp_adapter_means_no_servers_and_no_claim(): void {
		// The adapter is absent in this suite, which is also the state of most
		// managed sites. Silence here must read as "no second door found", not
		// as an error — a tool that errors on the common case gets ignored.
		$result = $this->tool->execute( array() );

		$this->assertFalse( $result['mcp_adapter']['active'] );
		$this->assertSame( array(), $result['servers'] );
		$this->assertFalse( $result['angie']['active'] );
		$this->assertFalse( $result['angie']['mcp_server_present'] );
	}

	public function test_angie_being_active_is_not_reported_as_angie_serving_mcp(): void {
		// Angie can be installed with its MCP module off. Reporting the plugin's
		// presence as a live second door would be a finding the operator cannot
		// act on, and one they would eventually learn to ignore.
		$tool = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			protected function angie_state() {
				// Mirror the real method with the plugin present and no server.
				$server = false;
				foreach ( $this->servers() as $entry ) {
					if ( 'angie' === ( $entry['id'] ?? '' ) ) {
						$server = true;
					}
				}
				return array(
					'active'             => true,
					'version'            => '1.1.12',
					'mcp_server_present' => $server,
				);
			}
		};

		$result = $tool->execute( array() );

		$this->assertTrue( $result['angie']['active'] );
		$this->assertFalse( $result['angie']['mcp_server_present'] );
	}

	public function test_registered_servers_are_reported_with_id_and_route(): void {
		$tool = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			protected function servers() {
				return array(
					array( 'id' => 'angie', 'route' => '/mcp/angie', 'tool_count' => 4 ),
				);
			}
		};

		$result = $tool->execute( array() );

		$this->assertSame( 'angie', $result['servers'][0]['id'] );
		$this->assertSame( '/mcp/angie', $result['servers'][0]['route'] );
		$this->assertSame( 4, $result['servers'][0]['tool_count'] );
		$this->assertTrue(
			$result['angie']['mcp_server_present'],
			'A server registered under the angie id IS the second door, whatever the plugin constant says.'
		);
	}
}
