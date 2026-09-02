<?php
/**
 * audit_mcp_exposure — the `elementor` block (2.15.0).
 *
 * Every WordPress and database read the block makes goes through a protected
 * seam; SA_Elementor_Fake_Tool exposes each as a public property so a test
 * states the site it models and reads the block it produces. Two tests at the
 * end run the REAL seams against the bootstrap's $wpdb stub, pinning the SQL.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once SA_PLUGIN_DIR . '/includes/credential-rules.php';
require_once SA_PLUGIN_DIR . '/includes/tools/class-tool-audit-mcp-exposure.php';
require_once SA_PLUGIN_DIR . '/includes/tools/class-tool-audit-admin-accounts.php';

class SA_Elementor_Fake_Tool extends Aura_Tool_Audit_Mcp_Exposure {
	/** @var array */
	public $env = array( 'installed' => true, 'version' => '4.3.0-beta1', 'class_present' => true, 'active' => true );
	/** @var array|null */
	public $plugin_header = null;
	/** @var array|null */
	public $abilities = array();
	/** @var array */
	public $server_list = array( array( 'id' => 'elementor-mcp-server', 'route' => '/elementor/mcp', 'tool_count' => 27 ) );
	/** @var array */
	public $consent_rows = array();
	/** @var int[] */
	public $candidates = array();
	/** @var array uid => array|null */
	public $lists = array();
	/** @var int[][] pages of user ids, in order */
	public $context_pages = array();
	/** @var int */
	public $context_total = 0;
	/** @var string[] seam names that throw */
	public $throw_in = array();
	/** @var int[] user ids whose list was read, in order */
	public $reads = array();
	/** @var int how many times consent_rows() was invoked — the manage_options gate test proves this stays 0 */
	public $consent_rows_calls = 0;

	private function maybe_throw( $seam ) {
		if ( in_array( $seam, $this->throw_in, true ) ) {
			throw new RuntimeException( $seam . ' exploded' );
		}
	}
	protected function elementor_env() {
		$this->maybe_throw( 'env' );
		return $this->env;
	}
	protected function elementor_plugin_header() {
		return $this->plugin_header;
	}
	protected function elementor_ability_names() {
		$this->maybe_throw( 'abilities' );
		return $this->abilities;
	}
	protected function servers() {
		$this->maybe_throw( 'servers' );
		return $this->server_list;
	}
	protected function ability_exposure( $abilities_active ) {
		$this->maybe_throw( 'exposure' );
		return parent::ability_exposure( $abilities_active );
	}

	protected function consent_rows() {
		++$this->consent_rows_calls;
		$this->maybe_throw( 'consent' );
		return $this->consent_rows;
	}
	protected function elementor_candidate_ids() {
		$this->maybe_throw( 'candidates' );
		return $this->candidates;
	}
	protected function password_list( $uid ) {
		$this->maybe_throw( 'list' );
		$this->reads[] = (int) $uid;
		return array_key_exists( (int) $uid, $this->lists ) ? $this->lists[ (int) $uid ] : array();
	}
	protected function context_user_ids( $offset, $number ) {
		$this->maybe_throw( 'context' );
		$page = (int) ( $offset / $number );
		return isset( $this->context_pages[ $page ] ) ? $this->context_pages[ $page ] : array();
	}
	protected function context_users_total() {
		$this->maybe_throw( 'total' );
		return $this->context_total;
	}
	protected function user_login( $uid ) {
		return 'user' . (int) $uid;
	}
}

final class McpExposureElementorTest extends TestCase {

	private SA_Elementor_Fake_Tool $tool;

	protected function setUp(): void {
		sa_reset_state();
		$this->tool = new SA_Elementor_Fake_Tool();
	}

	private function block(): array {
		$result = $this->tool->execute( array() );
		$this->assertArrayHasKey( 'elementor', $result );
		return $result['elementor'];
	}

	// --- Task 2: the module subtree -----------------------------------------

	public function test_the_block_is_documented_and_the_byte_bound_matches_the_admin_audit(): void {
		$this->assertArrayHasKey( 'elementor', $this->tool->get_returns() );
		$this->assertSame( Aura_Tool_Audit_Admin_Accounts::MAX_APP_PASSWORD_BYTES, Aura_Tool_Audit_Mcp_Exposure::MAX_APP_PASSWORD_BYTES );
	}

	public function test_the_rest_of_the_payload_is_unchanged(): void {
		$result = $this->tool->execute( array() );
		foreach ( array( 'abilities_api_active', 'mcp_adapter', 'servers', 'angie', 'abilities', 'coverage' ) as $key ) {
			$this->assertArrayHasKey( $key, $result );
		}
	}

	// --- Codex round-3 P2: the block requires manage_options -----------------
	// `POST /aura/mcp/tools/execute` is gated by check_update_plugins_permission,
	// not manage_options — a caller holding update_plugins without manage_options
	// must get the { error } shape on every subtree, with NOTHING read.

	public function test_without_manage_options_every_subtree_is_the_error_shape_and_nothing_is_read(): void {
		$GLOBALS['_caps'] = array( 'update_plugins' ); // held, but NOT manage_options
		$b = $this->block();
		$this->assertSame(
			array(
				'installed'     => false,
				'version'       => null,
				'mcp_module'    => array( 'error' => 'manage_options required' ),
				'consent'       => array( 'error' => 'manage_options required' ),
				'app_passwords' => array(
					'elementor' => array( 'error' => 'manage_options required' ),
					'other'     => array( 'error' => 'manage_options required' ),
				),
				'coverage'      => array( 'error' => 'manage_options required' ),
				'governor'      => array( 'error' => 'manage_options required' ),
			),
			$b
		);
		$this->assertSame( array(), $this->tool->reads );
		$this->assertSame( 0, $this->tool->consent_rows_calls );
	}

	public function test_with_manage_options_the_block_is_read(): void {
		// sa_reset_state()'s default ($GLOBALS['_caps'] = null) already allows
		// everything; asserted explicitly here as the gate's other branch.
		$GLOBALS['_caps'] = null;
		$this->tool->abilities = array( 'elementor/create-page' );
		$b = $this->block();
		$this->assertTrue( $b['installed'] );
		$this->assertArrayHasKey( 'class_present', $b['mcp_module'] );
		$this->assertSame( 1, $b['mcp_module']['abilities_registered'] );
	}

	public function test_a_clean_4_3_site_is_built_with_the_official_server_id(): void {
		$this->tool->abilities = array( 'elementor/create-page', 'elementor/get-page', 'elementor-mcp/save-page', 'angie/run' );
		$b = $this->block();
		$this->assertTrue( $b['installed'] );
		$this->assertSame( '4.3.0-beta1', $b['version'] );
		$this->assertSame(
			array( 'class_present' => true, 'active' => true, 'abilities_registered' => 2, 'server_id' => 'elementor-mcp-server' ),
			$b['mcp_module']
		);
	}

	public function test_abilities_registered_counts_only_the_elementor_prefix(): void {
		// The fork's elementor-mcp/* and Angie's abilities share the unified
		// server; they must never inflate Elementor's own count.
		$this->tool->abilities = array( 'elementor-mcp/save', 'angie/x', 'elementor/a', 'elementorx/b' );
		$this->assertSame( 1, $this->block()['mcp_module']['abilities_registered'] );
	}

	public function test_abilities_registered_is_null_when_the_abilities_api_is_absent(): void {
		$this->tool->abilities = null;
		$this->assertNull( $this->block()['mcp_module']['abilities_registered'] );
	}

	public function test_server_id_is_null_when_the_official_server_is_not_registered(): void {
		$this->tool->server_list = array( array( 'id' => 'angie', 'route' => '/mcp/angie', 'tool_count' => 4 ) );
		$this->assertNull( $this->block()['mcp_module']['server_id'] );
	}

	public function test_elementor_4_2_4_with_angie_has_the_class_absent_and_active_null(): void {
		// Angie's door on 4.2.4: the route exists, the module does not. The
		// Aura parser needs the MODULE, not the route.
		$this->tool->env = array( 'installed' => true, 'version' => '4.2.4', 'class_present' => false, 'active' => false );
		$this->tool->server_list = array( array( 'id' => 'elementor-mcp-server', 'route' => '/elementor/mcp', 'tool_count' => 3 ) );
		$m = $this->block()['mcp_module'];
		$this->assertFalse( $m['class_present'] );
		$this->assertNull( $m['active'] ); // class absent ⇒ active null, whatever the seam said
		$this->assertSame( 'elementor-mcp-server', $m['server_id'] );
	}

	public function test_elementor_absent_reports_not_installed_and_the_module_shape_still(): void {
		$this->tool->env = array( 'installed' => false, 'version' => '9.9.9', 'class_present' => false, 'active' => null );
		$this->tool->abilities = array( 'elementor/orphan' );
		$b = $this->block();
		$this->assertFalse( $b['installed'] );
		$this->assertNull( $b['version'] ); // installed:false ⇒ version:null
		$this->assertSame( array( 'class_present' => false, 'active' => null, 'abilities_registered' => 1, 'server_id' => null ), $b['mcp_module'] );
	}

	public function test_the_module_class_present_implies_installed(): void {
		// The class cannot exist without Elementor; a seam that says otherwise
		// is corrected rather than emitting a payload the parser refuses.
		$this->tool->env = array( 'installed' => false, 'version' => null, 'class_present' => true, 'active' => true );
		$this->assertTrue( $this->block()['installed'] );
	}

	public function test_active_null_when_is_active_cannot_be_called(): void {
		$this->tool->env = array( 'installed' => true, 'version' => '4.3.0', 'class_present' => true, 'active' => null );
		$this->assertNull( $this->block()['mcp_module']['active'] );
	}

	public function test_a_throw_in_the_module_scan_replaces_only_the_module_subtree(): void {
		$this->tool->throw_in = array( 'env' );
		$b = $this->block();
		$this->assertSame( array( 'error' => 'env exploded' ), $b['mcp_module'] );
		$this->assertFalse( $b['installed'] );
		$this->assertNull( $b['version'] );
		$this->assertSame( array(), $b['consent'] ); // the other subtrees were still read
		$this->assertArrayHasKey( 'coverage', $b );
	}

	public function test_a_throw_counting_abilities_is_a_module_error_too(): void {
		// Codex round-2 P2: a throw counting abilities is a DISCOVERY failure,
		// not an installation one — installed/version, already read from
		// elementor_env() alone, must survive it.
		$this->tool->throw_in = array( 'abilities' );
		$b = $this->block();
		$this->assertSame( array( 'error' => 'abilities exploded' ), $b['mcp_module'] );
		$this->assertTrue( $b['installed'] );
		$this->assertSame( '4.3.0-beta1', $b['version'] );
	}

	public function test_a_throw_listing_servers_is_a_module_error_too(): void {
		// Same rule, the other discovery input: elementor_module_from() also
		// reads servers() to attribute server_id.
		$this->tool->throw_in = array( 'servers' );
		$b = $this->block();
		$this->assertSame( array( 'error' => 'servers exploded' ), $b['mcp_module'] );
		$this->assertTrue( $b['installed'] );
		$this->assertSame( '4.3.0-beta1', $b['version'] );
	}

	public function test_a_throw_listing_servers_never_leaves_execute(): void {
		// Codex round-8 P2: execute() builds `servers` and `angie` BEFORE
		// `elementor`, from the same servers() read — so a throw there used to
		// fail the whole tool and the module-subtree isolation above was never
		// reached. Discovery is now read once in execute(); every reader gets
		// { error } in its own place and the rest of the payload still lands.
		$this->tool->throw_in = array( 'servers' );
		$result = $this->tool->execute( array() );
		$this->assertSame( array( 'error' => 'servers exploded' ), $result['servers'] );
		$this->assertSame( array( 'error' => 'servers exploded' ), $result['angie'] );
		$this->assertSame( array( 'error' => 'servers exploded' ), $result['elementor']['mcp_module'] );
		$this->assertTrue( $result['elementor']['installed'] );
		$this->assertSame( '4.3.0-beta1', $result['elementor']['version'] );
		foreach ( array( 'abilities_api_active', 'mcp_adapter', 'abilities', 'coverage' ) as $key ) {
			$this->assertArrayHasKey( $key, $result );
		}
		$this->assertIsArray( $result['elementor']['consent'] );
	}

	public function test_a_throw_in_the_ability_scan_never_leaves_execute(): void {
		// Codex round-9 P2 (same class as round 8): ability_exposure() scans
		// the registry AFTER the block is built, outside its isolation — a
		// third-party ability throwing from get_meta() discarded the whole
		// audit. Now `abilities` and `coverage` become { error } and every
		// other key, the elementor block included, still lands intact.
		$this->tool->throw_in = array( 'exposure' );
		$result = $this->tool->execute( array() );
		$this->assertSame( array( 'error' => 'exposure exploded' ), $result['abilities'] );
		$this->assertSame( array( 'error' => 'exposure exploded' ), $result['coverage'] );
		$this->assertIsArray( $result['servers'] );
		$this->assertIsArray( $result['angie'] );
		$this->assertTrue( $result['elementor']['installed'] );
		$this->assertSame( '4.3.0-beta1', $result['elementor']['version'] );
		$this->assertArrayNotHasKey( 'error', $result['elementor']['mcp_module'] );
	}

	public function test_strings_are_clipped_at_200(): void {
		$this->tool->env = array( 'installed' => true, 'version' => str_repeat( 'v', 500 ), 'class_present' => true, 'active' => true );
		$this->assertSame( 200, strlen( $this->block()['version'] ) );
	}

	public function test_the_no_mbstring_clip_never_splits_a_character(): void {
		// Codex round-6 P2: a byte substr() at 200 could cut an emoji in half
		// and hand JSON encoding invalid UTF-8.
		$name = 'Elementor MCP ' . str_repeat( "\u{1F642}", 200 );
		$out  = Aura_Tool_Audit_Mcp_Exposure::clip_fallback( $name );
		$this->assertSame( 200, mb_strlen( $out ) );
		$this->assertTrue( (bool) preg_match( '//u', $out ) ); // valid UTF-8
		$this->assertSame( 'Elementor MCP ', substr( $out, 0, 14 ) );
		$this->assertSame( '', Aura_Tool_Audit_Mcp_Exposure::clip_fallback( "abc\xff\xfe" ) ); // invalid UTF-8 → nothing
		$this->assertSame( 'short', Aura_Tool_Audit_Mcp_Exposure::clip_fallback( 'short' ) );
	}

	public function test_elementor_module_from_is_pure(): void {
		$m = Aura_Tool_Audit_Mcp_Exposure::elementor_module_from(
			array( 'installed' => true, 'version' => '4.3.0', 'class_present' => true, 'active' => false ),
			array( 'elementor/a', 'elementor/b' ),
			array( array( 'id' => 'angie' ) )
		);
		$this->assertSame( array( 'class_present' => true, 'active' => false, 'abilities_registered' => 2, 'server_id' => null ), $m );
	}

	// --- Codex round-1 P2: a deactivated Elementor is still "installed" ----
	// via the plugin inventory, never only via runtime signals. These three
	// run the REAL elementor_env()/elementor_plugin_header() (the fake tool
	// overrides elementor_env() wholesale, so it cannot exercise this path).

	public function test_a_deactivated_elementor_is_installed_with_its_header_version(): void {
		$real = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			protected function elementor_plugin_header() {
				return array( 'Name' => 'Elementor', 'Version' => '4.3.0-beta1' );
			}
			protected function consent_rows() {
				return array();
			}
			protected function elementor_candidate_ids() {
				return array();
			}
			protected function context_user_ids( $offset, $number ) {
				return array();
			}
			protected function context_users_total() {
				return 0;
			}
		};
		$b = $real->execute( array() )['elementor'];
		$this->assertTrue( $b['installed'] );
		$this->assertSame( '4.3.0-beta1', $b['version'] );
		$this->assertFalse( $b['mcp_module']['class_present'] );
		$this->assertNull( $b['mcp_module']['active'] ); // the class is not loaded in the suite
	}

	public function test_no_header_and_no_runtime_signal_is_not_installed(): void {
		$real = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			protected function elementor_plugin_header() {
				return null;
			}
			protected function consent_rows() {
				return array();
			}
			protected function elementor_candidate_ids() {
				return array();
			}
			protected function context_user_ids( $offset, $number ) {
				return array();
			}
			protected function context_users_total() {
				return 0;
			}
		};
		$b = $real->execute( array() )['elementor'];
		$this->assertFalse( $b['installed'] );
		$this->assertNull( $b['version'] );
	}

	public function test_the_real_plugin_inventory_answers_installed_and_version(): void {
		// The bootstrap's get_plugins() stub reads this global (~line 1332);
		// sa_reset_state() also unsets it, but the finally{} below is belt and
		// braces so a failed assertion still cannot leak it into later tests.
		$GLOBALS['_installed_plugins'] = array(
			'elementor/elementor.php' => array( 'Name' => 'Elementor', 'Version' => '4.2.4' ),
		);
		try {
			$real = new class() extends Aura_Tool_Audit_Mcp_Exposure {
				protected function consent_rows() {
					return array();
				}
				protected function elementor_candidate_ids() {
					return array();
				}
				protected function context_user_ids( $offset, $number ) {
					return array();
				}
				protected function context_users_total() {
					return 0;
				}
			};
			$b = $real->execute( array() )['elementor'];
			$this->assertTrue( $b['installed'] );
			$this->assertSame( '4.2.4', $b['version'] );
		} finally {
			unset( $GLOBALS['_installed_plugins'] );
		}
	}

	// --- Task 3: consent ----------------------------------------------------

	private static function consent_row( int $uid, $data, ?string $login = null ): object {
		$raw = is_string( $data ) ? $data : serialize( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		return (object) array( 'user_id' => $uid, 'user_login' => $login ?? 'user' . $uid, 'len' => strlen( $raw ), 'v' => $raw );
	}

	public function test_a_consent_row_is_reported_with_its_owner_and_time(): void {
		$this->tool->consent_rows = array( self::consent_row( 3, array( 'allowed' => true, 'timestamp' => 1788000000 ), 'ben' ) );
		$b = $this->block();
		$this->assertSame( array( array( 'user_id' => 3, 'login' => 'ben', 'allowed' => true, 'timestamp' => 1788000000 ) ), $b['consent'] );
		$this->assertSame( array(), $b['consent_unproven'] );
		$this->assertFalse( $b['consent_truncated'] );
	}

	public function test_consent_is_read_for_every_user_not_only_edit_posts(): void {
		// The seam is a usermeta query with no capability filter; a demoted
		// user's consent row still comes back. Modelled: no context pages at all.
		$this->tool->context_pages = array();
		$this->tool->consent_rows  = array( self::consent_row( 42, array( 'allowed' => true, 'timestamp' => 1 ) ) );
		$this->assertSame( 42, $this->block()['consent'][0]['user_id'] );
	}

	public function test_allowed_false_is_reported_as_false(): void {
		$this->tool->consent_rows = array( self::consent_row( 3, array( 'allowed' => false, 'timestamp' => 5 ) ) );
		$this->assertFalse( $this->block()['consent'][0]['allowed'] );
	}

	public function test_allowed_is_a_strict_bool_from_storage(): void {
		// Elementor stores a bool; a legacy or edited row may hold 1 / '1'.
		// Anything else — 'yes', 'true', 2 — is NOT consent.
		$rows = array();
		foreach ( array( true, 1, '1', 'yes', 'true', 2, null ) as $i => $v ) {
			$rows[] = self::consent_row( $i + 1, array( 'allowed' => $v, 'timestamp' => 5 ) );
		}
		$this->tool->consent_rows = $rows;
		$got = array_map( static function ( $r ) { return $r['allowed']; }, $this->block()['consent'] );
		$this->assertSame( array( true, true, true, false, false, false, false ), $got );
	}

	public function test_a_missing_or_non_numeric_timestamp_is_null(): void {
		$this->tool->consent_rows = array(
			self::consent_row( 1, array( 'allowed' => true ) ),
			self::consent_row( 2, array( 'allowed' => true, 'timestamp' => 'soon' ) ),
			self::consent_row( 3, array( 'allowed' => true, 'timestamp' => '1788000000' ) ),
		);
		$got = array_map( static function ( $r ) { return $r['timestamp']; }, $this->block()['consent'] );
		$this->assertSame( array( null, null, 1788000000 ), $got );
	}

	public function test_an_oversized_consent_row_is_unproven_and_never_decoded(): void {
		// The seam returns v NULL when LENGTH exceeded the bound in the
		// statement; nothing here may try to read it.
		$this->tool->consent_rows = array( (object) array( 'user_id' => 9, 'user_login' => 'big', 'len' => 999999, 'v' => null ) );
		$b = $this->block();
		$this->assertSame( array(), $b['consent'] );
		$this->assertSame( array( 9 ), $b['consent_unproven'] );
	}

	public function test_a_row_that_is_not_the_documented_shape_is_unproven(): void {
		$this->tool->consent_rows = array(
			self::consent_row( 5, 'not-serialized-garbage' ),
			self::consent_row( 6, array( 'no_allowed_key' => 1 ) ),
		);
		$b = $this->block();
		$this->assertSame( array(), $b['consent'] );
		$this->assertSame( array( 5, 6 ), $b['consent_unproven'] );
	}

	public function test_a_row_with_a_valid_envelope_but_a_corrupt_serialization_is_unproven_without_warning(): void {
		// Codex round-7 P2: is_serialized() is a SHAPE check — it passes a
		// payload that declares 2 elements and holds 1, which makes a bare
		// unserialize() emit E_WARNING. This test passing at all IS the proof:
		// PHPUnit converts an escaped E_WARNING into a fatal error, so if the
		// fix's warning suppression ever regresses, this test does not merely
		// fail an assertion — it errors out with the warning itself.
		$this->tool->consent_rows = array( self::consent_row( 5, 'a:2:{s:7:"allowed";b:1;}' ) );
		$b                         = $this->block();
		$this->assertSame( array(), $b['consent'] );
		$this->assertSame( array( 5 ), $b['consent_unproven'] );
	}

	public function test_fifty_one_consent_rows_are_fifty_and_truncated(): void {
		$rows = array();
		for ( $i = 1; $i <= 51; $i++ ) {
			$rows[] = self::consent_row( $i, array( 'allowed' => true, 'timestamp' => $i ) );
		}
		$this->tool->consent_rows = $rows;
		$b = $this->block();
		$this->assertCount( 50, $b['consent'] );
		$this->assertSame( 50, $b['consent'][49]['user_id'] );
		$this->assertTrue( $b['consent_truncated'] );
	}

	public function test_truncation_counts_unproven_rows_toward_the_fifty(): void {
		// Parser invariant: consent_truncated ⇒ count(consent) + count(unproven) == 50.
		$rows = array();
		for ( $i = 1; $i <= 51; $i++ ) {
			$rows[] = 25 === $i
				? (object) array( 'user_id' => $i, 'user_login' => 'x', 'len' => 999999, 'v' => null )
				: self::consent_row( $i, array( 'allowed' => true, 'timestamp' => $i ) );
		}
		$this->tool->consent_rows = $rows;
		$b = $this->block();
		$this->assertTrue( $b['consent_truncated'] );
		$this->assertSame( 50, count( $b['consent'] ) + count( $b['consent_unproven'] ) );
	}

	public function test_a_login_is_clipped_and_a_missing_login_falls_back_to_the_id(): void {
		$this->tool->consent_rows = array(
			self::consent_row( 1, array( 'allowed' => true, 'timestamp' => 1 ), str_repeat( 'l', 300 ) ),
			(object) array( 'user_id' => 2, 'user_login' => null, 'len' => 10, 'v' => serialize( array( 'allowed' => true, 'timestamp' => 1 ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		);
		$c = $this->block()['consent'];
		$this->assertSame( 200, strlen( $c[0]['login'] ) );
		$this->assertSame( 'user:2', $c[1]['login'] );
	}

	public function test_a_throw_in_the_consent_scan_replaces_only_consent(): void {
		$this->tool->throw_in = array( 'consent' );
		$b = $this->block();
		$this->assertSame( array( 'error' => 'consent exploded' ), $b['consent'] );
		$this->assertArrayNotHasKey( 'consent_truncated', $b );
		$this->assertArrayNotHasKey( 'consent_unproven', $b );
		$this->assertSame( true, $b['installed'] ); // the module subtree survived
	}

	public function test_a_serialised_object_in_a_consent_row_is_never_instantiated(): void {
		// A crafted meta_value must never become a live object: allowed_classes
		// must be false, or a gadget's __wakeup()/__destruct() could run.
		$obj_row = (object) array(
			'user_id'    => 9,
			'user_login' => 'attacker',
			'len'        => 60,
			'v'          => 'O:8:"stdClass":2:{s:7:"allowed";b:1;s:9:"timestamp";i:5;}',
		);
		$this->tool->consent_rows = array(
			$obj_row,
			self::consent_row( 3, array( 'allowed' => true, 'timestamp' => 5 ), 'ben' ),
		);
		$b = $this->block();
		$this->assertSame( array( 9 ), $b['consent_unproven'] );
		$this->assertCount( 1, $b['consent'] );
		$this->assertSame( 3, $b['consent'][0]['user_id'] );
		$this->assertTrue( $b['consent'][0]['allowed'] );
	}

	public function test_invalid_user_ids_are_filtered_before_the_cap(): void {
		// A user_id <= 0 row must not consume one of the 50 slots — the
		// truncation invariant (consent + consent_unproven == 50) must hold
		// by construction, not merely when every row happens to be valid.
		$rows = array();
		for ( $i = 1; $i <= 52; $i++ ) {
			$rows[] = 3 === $i
				? (object) array( 'user_id' => 0, 'user_login' => 'nobody', 'len' => 10, 'v' => serialize( array( 'allowed' => true, 'timestamp' => $i ) ) ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
				: self::consent_row( $i, array( 'allowed' => true, 'timestamp' => $i ) );
		}
		$this->tool->consent_rows = $rows;
		$b = $this->block();
		$this->assertTrue( $b['consent_truncated'] );
		$this->assertSame( 50, count( $b['consent'] ) + count( $b['consent_unproven'] ) );
	}

	public function test_duplicate_rows_for_one_user_are_deduped_the_first_wins(): void {
		// Two rows for uid 7 (the query orders by umeta_id ASC, so the first
		// row IS the oversized one here) must not put 7 in both `consent` and
		// `consent_unproven` — that would break the invariant
		// consent_unproven ∩ consent[].user_id = ∅.
		$this->tool->consent_rows = array(
			(object) array( 'user_id' => 7, 'user_login' => 'dup', 'len' => 999999, 'v' => null ), // oversized, first
			self::consent_row( 7, array( 'allowed' => true, 'timestamp' => 1 ) ), // valid, later — dropped
			self::consent_row( 8, array( 'allowed' => true, 'timestamp' => 2 ) ),
		);
		$b = $this->block();
		$this->assertSame( array( 7 ), $b['consent_unproven'] );
		$this->assertSame( array( 8 ), array_map( static function ( $r ) { return $r['user_id']; }, $b['consent'] ) );
	}

	public function test_a_duplicate_among_fifty_two_rows_is_still_fifty_and_truncated(): void {
		$rows = array();
		for ( $i = 1; $i <= 51; $i++ ) {
			$rows[] = self::consent_row( $i, array( 'allowed' => true, 'timestamp' => $i ) );
		}
		// A 52nd row duplicating uid 1: dropped by the dedupe, not counted
		// toward the 50-row cap at all.
		$rows[] = self::consent_row( 1, array( 'allowed' => false, 'timestamp' => 999 ) );
		$this->tool->consent_rows = $rows;
		$b = $this->block();
		$this->assertTrue( $b['consent_truncated'] );
		$this->assertSame( 50, count( $b['consent'] ) + count( $b['consent_unproven'] ) );
		// The FIRST row for uid 1 (allowed:true) is the one kept.
		$this->assertTrue( $b['consent'][0]['allowed'] );
	}

	public function test_the_consent_statement_is_bounded_in_rows_and_bytes(): void {
		// The REAL seam against the bootstrap's $wpdb: pins the SQL shape.
		$real = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			protected function elementor_env() {
				return array( 'installed' => false, 'version' => null, 'class_present' => false, 'active' => null );
			}
			protected function elementor_candidate_ids() {
				return array();
			}
			protected function context_user_ids( $offset, $number ) {
				return array();
			}
			protected function context_users_total() {
				return 0;
			}
		};
		$GLOBALS['_db_rows'] = array( self::consent_row( 4, array( 'allowed' => true, 'timestamp' => 7 ), 'ben' ) );
		$b = $real->execute( array() )['elementor'];
		$this->assertSame( 4, $b['consent'][0]['user_id'] );
		$prepared = array_values( array_filter( $GLOBALS['_db_prepared'], static function ( $p ) {
			return false !== strpos( $p['query'], 'elementor_mcp_consent' ) || in_array( 'elementor_mcp_consent', $p['args'], true );
		} ) );
		$this->assertCount( 1, $prepared );
		// Exact-string pin: proves the statement returns one row per user
		// (MIN(umeta_id), the dedupe subquery) and excludes invalid ids
		// (m.user_id > 0) BEFORE the LIMIT is applied — the fix for Codex
		// round-4 P2 (#85), where 51 raw rows could dedupe to <= 50 distinct
		// users and read consent_truncated as false while a later user's row
		// was never fetched.
		// Codex round-10 P2: the statement is probe/sentinel shaped (the
		// #434 usermeta_holders() pattern) so its result set proves it ran;
		// the inner SELECT is the round-4 statement verbatim.
		$this->assertSame(
			"SELECT %s AS probe, 0 AS user_id, NULL AS user_login, NULL AS len, NULL AS v, NULL AS umeta_id UNION ALL SELECT %s AS probe, c.user_id, c.user_login, c.len, c.v, c.umeta_id FROM (SELECT m.user_id, u.user_login, LENGTH(m.meta_value) AS len, IF(LENGTH(m.meta_value) <= %d, m.meta_value, NULL) AS v, m.umeta_id FROM wp_usermeta m LEFT JOIN wp_users u ON u.ID = m.user_id WHERE m.meta_key = %s AND m.user_id > 0 AND m.umeta_id IN (SELECT MIN(umeta_id) FROM wp_usermeta WHERE meta_key = %s GROUP BY user_id) ORDER BY m.umeta_id ASC LIMIT %d) AS c",
			$prepared[0]['query']
		);
		$this->assertStringContainsString( 'MIN(umeta_id)', $prepared[0]['query'] );
		$this->assertStringContainsString( 'm.user_id > 0', $prepared[0]['query'] );
		$args = $prepared[0]['args'];
		$this->assertCount( 6, $args );
		$this->assertIsString( $args[0] );
		$this->assertNotSame( '', $args[0] );
		$this->assertSame( $args[0], $args[1], 'sentinel and rows carry the SAME nonce' );
		$this->assertSame( array( 262144, 'elementor_mcp_consent', 'elementor_mcp_consent', 51 ), array_slice( $args, 2 ) );
	}

	public function test_a_consent_statement_the_query_filter_blanked_is_an_error_not_a_stale_inventory(): void {
		// Codex round-10 P2: a `query` filter returning '' makes wpdb::query()
		// return before flush(), and get_results() then answers the PREVIOUS
		// statement's rows with last_error untouched. Run once for real (a
		// proven row set with nonce A), then blank the next statement: the
		// stale set carries nonce A, not this call's, and must be { error }.
		$real = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			protected function elementor_env() {
				return array( 'installed' => false, 'version' => null, 'class_present' => false, 'active' => null );
			}
			protected function elementor_candidate_ids() {
				return array();
			}
			protected function context_user_ids( $offset, $number ) {
				return array();
			}
			protected function context_users_total() {
				return 0;
			}
		};
		$GLOBALS['_db_rows'] = array( self::consent_row( 4, array( 'allowed' => true, 'timestamp' => 7 ), 'ben' ) );
		$first = $real->execute( array() )['elementor'];
		$this->assertSame( 4, $first['consent'][0]['user_id'] );

		$GLOBALS['_sa_wpdb_query_filtered_out'] = true;
		$b = $real->execute( array() )['elementor'];
		$this->assertSame( array( 'error' => 'consent statement did not run: result set is not this statement\'s' ), $b['consent'] );
		$this->assertArrayNotHasKey( 'consent_unproven', $b );
	}

	public function test_a_consent_statement_answered_with_nothing_at_all_is_an_error_not_an_empty_inventory(): void {
		// The other stale shape: no previous statement, so the filtered-out
		// call meets an EMPTY last_result — no sentinel, nothing proved.
		$real = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			protected function elementor_env() {
				return array( 'installed' => false, 'version' => null, 'class_present' => false, 'active' => null );
			}
			protected function elementor_candidate_ids() {
				return array();
			}
			protected function context_user_ids( $offset, $number ) {
				return array();
			}
			protected function context_users_total() {
				return 0;
			}
		};
		$GLOBALS['_sa_wpdb_query_filtered_out'] = true;
		$b = $real->execute( array() )['elementor'];
		$this->assertSame( array( 'error' => 'consent statement did not run: no sentinel row' ), $b['consent'] );
	}

	public function test_a_failed_consent_statement_is_an_error_not_an_empty_inventory(): void {
		// Codex round-2 P2: wpdb::get_results() answers its CLEARED
		// $last_result — an empty array — when the statement itself fails,
		// not false. The REAL seam against the bootstrap's $wpdb: an empty
		// row set together with a set last_error must still be reported as
		// { error }, never as "no consent rows".
		$real = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			protected function elementor_env() {
				return array( 'installed' => false, 'version' => null, 'class_present' => false, 'active' => null );
			}
			protected function elementor_candidate_ids() {
				return array();
			}
			protected function context_user_ids( $offset, $number ) {
				return array();
			}
			protected function context_users_total() {
				return 0;
			}
		};
		$GLOBALS['_db_rows']               = array();
		$GLOBALS['_sa_wpdb_results_error'] = 'Table wp_usermeta doesnt exist';
		$b = $real->execute( array() )['elementor'];
		$this->assertSame(
			array( 'error' => 'consent statement failed: Table wp_usermeta doesnt exist' ),
			$b['consent']
		);
		$this->assertArrayNotHasKey( 'consent_unproven', $b );
		$this->assertArrayNotHasKey( 'consent_truncated', $b );
		// The module subtree, read independently, is untouched.
		$this->assertFalse( $b['installed'] );
	}

	// --- Task 4: Elementor passwords -----------------------------------------

	private static function pw( array $over = array() ): array {
		return $over + array(
			'uuid'      => 'u-' . wp_generate_uuid4(),
			'app_id'    => '',
			'name'      => 'Elementor MCP - Claude Desktop (2026-09-01 22:25:05)',
			'password'  => 'hash',
			'created'   => 1788000000,
			'last_used' => null,
			'last_ip'   => null,
		);
	}

	private function passwords(): array {
		return $this->block()['app_passwords'];
	}

	public function test_an_elementor_named_password_is_reported_with_full_detail(): void {
		$this->tool->candidates = array( 3 );
		$this->tool->lists      = array( 3 => array( self::pw( array( 'last_used' => 1788100000, 'last_ip' => '203.0.113.9' ) ) ) );
		$p = $this->passwords();
		$this->assertSame(
			array( array( 'user_id' => 3, 'login' => 'user3', 'name' => 'Elementor MCP - Claude Desktop (2026-09-01 22:25:05)', 'created' => 1788000000, 'last_used' => 1788100000, 'last_ip' => '203.0.113.9' ) ),
			$p['elementor']
		);
		$this->assertSame( 1, $p['candidates_read'] );
		$this->assertFalse( $p['elementor_truncated'] );
		$this->assertFalse( $p['elementor_entries_truncated'] );
		$this->assertSame( array(), $p['elementor_unproven'] );
	}

	public function test_only_names_with_the_prefix_are_reported_the_like_is_a_prefilter(): void {
		// 'Not Elementor MCP' matches the LIKE and must not be reported.
		$this->tool->candidates = array( 3 );
		$this->tool->lists      = array( 3 => array( self::pw( array( 'name' => 'Not Elementor MCP' ) ), self::pw( array( 'name' => 'Aura SiteAgent' ) ), self::pw() ) );
		$p = $this->passwords();
		$this->assertCount( 1, $p['elementor'] );
		$this->assertSame( 1, $p['candidates_read'] );
	}

	public function test_a_candidate_whose_list_cannot_be_read_is_unproven_not_empty(): void {
		$this->tool->candidates = array( 3, 4 );
		$this->tool->lists      = array( 3 => null, 4 => array( self::pw() ) );
		$p = $this->passwords();
		$this->assertSame( array( 3 ), $p['elementor_unproven'] );
		$this->assertSame( 4, $p['elementor'][0]['user_id'] );
		$this->assertSame( 2, $p['candidates_read'] );
	}

	public function test_fifty_one_candidates_read_fifty_and_truncated(): void {
		$this->tool->candidates = range( 1, 51 );
		$p = $this->passwords();
		$this->assertTrue( $p['elementor_truncated'] );
		$this->assertSame( 50, $p['candidates_read'] );
		$this->assertSame( range( 1, 50 ), $this->tool->reads );
	}

	public function test_the_entry_cap_does_not_abandon_the_rest_of_the_candidate_slice(): void {
		// 51 candidates (sliced to 50), candidate 1 alone holds 60 Elementor-
		// named passwords, candidate 2's list is unreadable. Before the fix a
		// `break 2` on the entry cap stopped reading candidates the instant
		// the 50th entry was appended (candidates_read: 1), breaking the spec
		// invariant elementor_truncated ⇒ candidates_read == 50. The fix walks
		// every candidate in the slice regardless — entries past 50 are
		// simply not appended.
		$this->tool->candidates = range( 1, 51 );
		$this->tool->lists      = array(
			1 => array_fill( 0, 60, self::pw() ),
			2 => null,
		);
		$p = $this->passwords();
		$this->assertCount( 50, $p['elementor'] );
		$this->assertTrue( $p['elementor_entries_truncated'] );
		$this->assertTrue( $p['elementor_truncated'] );
		$this->assertSame( 50, $p['candidates_read'] );
		$this->assertSame( array( 2 ), $p['elementor_unproven'] );
		$this->assertSame( range( 1, 50 ), $this->tool->reads );
	}

	public function test_entries_are_capped_at_fifty_across_candidates(): void {
		$this->tool->candidates = array( 1, 2 );
		$this->tool->lists      = array(
			1 => array_fill( 0, 30, self::pw() ),
			2 => array_fill( 0, 30, self::pw() ),
		);
		$p = $this->passwords();
		$this->assertCount( 50, $p['elementor'] );
		$this->assertTrue( $p['elementor_entries_truncated'] );
		$this->assertFalse( $p['elementor_truncated'] );
		$this->assertSame( 2, $p['candidates_read'] );
	}

	public function test_exactly_fifty_entries_is_not_truncated(): void {
		$this->tool->candidates = array( 1 );
		$this->tool->lists      = array( 1 => array_fill( 0, 50, self::pw() ) );
		$this->assertFalse( $this->passwords()['elementor_entries_truncated'] );
	}

	public function test_created_and_last_used_are_ints_or_null_and_strings_are_clipped(): void {
		$this->tool->candidates = array( 1 );
		$this->tool->lists      = array( 1 => array( self::pw( array( 'name' => 'Elementor MCP ' . str_repeat( 'n', 300 ), 'created' => '1788000000', 'last_used' => 'yesterday', 'last_ip' => 5 ) ) ) );
		$e = $this->passwords()['elementor'][0];
		$this->assertSame( 200, strlen( $e['name'] ) );
		$this->assertSame( 1788000000, $e['created'] );
		$this->assertNull( $e['last_used'] );
		$this->assertNull( $e['last_ip'] );
	}

	public function test_a_list_item_that_is_not_an_array_is_skipped(): void {
		$this->tool->candidates = array( 1 );
		$this->tool->lists      = array( 1 => array( 'garbage', self::pw() ) );
		$this->assertCount( 1, $this->passwords()['elementor'] );
	}

	public function test_a_throw_in_the_password_scan_replaces_only_that_subtree(): void {
		$this->tool->throw_in = array( 'candidates' );
		$p = $this->passwords();
		$this->assertSame( array( 'error' => 'candidates exploded' ), $p['elementor'] );
		$this->assertArrayNotHasKey( 'elementor_truncated', $p );
		$this->assertArrayNotHasKey( 'candidates_read', $p );
		$this->assertArrayHasKey( 'other', $p ); // context still ran
	}

	public function test_the_candidate_statement_is_distinct_ordered_and_bounded(): void {
		// The REAL seam against the bootstrap's $wpdb; the stub models the LIKE.
		$real = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			protected function elementor_env() {
				return array( 'installed' => false, 'version' => null, 'class_present' => false, 'active' => null );
			}
			protected function consent_rows() {
				return array();
			}
			protected function context_user_ids( $offset, $number ) {
				return array();
			}
			protected function context_users_total() {
				return 0;
			}
		};
		$GLOBALS['_app_passwords'] = array(
			9 => array( self::pw() ),
			2 => array( self::pw( array( 'name' => 'Aura SiteAgent' ) ) ),
			5 => array( self::pw( array( 'name' => 'Not Elementor MCP' ) ) ),
		);
		$p = $real->execute( array() )['elementor']['app_passwords'];
		$this->assertSame( 2, $p['candidates_read'] ); // users 5 and 9 matched the LIKE
		$this->assertCount( 1, $p['elementor'] );
		$this->assertSame( 9, $p['elementor'][0]['user_id'] );
		$prepared = array_values( array_filter( $GLOBALS['_db_prepared'], static function ( $p ) {
			return false !== strpos( $p['query'], 'SELECT DISTINCT user_id' );
		} ) );
		$this->assertCount( 1, $prepared );
		// Codex round-10 P2: probe/sentinel shaped, the inner SELECT verbatim.
		$this->assertSame( 'SELECT %s AS probe, 0 AS user_id UNION ALL SELECT %s AS probe, c.user_id FROM (SELECT DISTINCT user_id FROM wp_usermeta WHERE meta_key = %s AND meta_value LIKE %s AND user_id > 0 ORDER BY user_id ASC LIMIT %d) AS c', $prepared[0]['query'] );
		$args = $prepared[0]['args'];
		$this->assertCount( 5, $args );
		$this->assertNotSame( '', $args[0] );
		$this->assertSame( $args[0], $args[1], 'sentinel and rows carry the SAME nonce' );
		$this->assertSame( array( '_application_passwords', '%Elementor MCP%', 51 ), array_slice( $args, 2 ) );
		// And each candidate was read through the BOUNDED helper.
		$bounded = array_filter( $GLOBALS['_db_queries'], static function ( $q ) {
			return false !== strpos( $q, 'IF(LENGTH(meta_value) <= 262144' );
		} );
		$this->assertCount( 2, $bounded );
	}

	public function test_a_failed_candidate_statement_is_an_error_not_an_empty_inventory(): void {
		// Codex round-2 P2: the same empty-array-on-failure shape as
		// consent, for the candidate scan. `_sa_app_password_scan_fail`
		// already models real wpdb behaviour here (last_error set, an
		// empty array returned) -- this pins that a table/driver error
		// reads as { error }, never as "no Elementor passwords".
		$real = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			protected function elementor_env() {
				return array( 'installed' => false, 'version' => null, 'class_present' => false, 'active' => null );
			}
			protected function consent_rows() {
				return array();
			}
			protected function context_user_ids( $offset, $number ) {
				return array();
			}
			protected function context_users_total() {
				return 0;
			}
		};
		$GLOBALS['_sa_app_password_scan_fail'] = true;
		$p = $real->execute( array() )['elementor']['app_passwords'];
		$this->assertSame( array( 'error' => 'candidate statement failed: scan failed' ), $p['elementor'] );
		$this->assertArrayNotHasKey( 'candidates_read', $p );
		$this->assertArrayNotHasKey( 'elementor_truncated', $p );
	}

	public function test_a_candidate_statement_the_query_filter_blanked_is_an_error_not_a_stale_inventory(): void {
		// Codex round-10 P2, the candidate scan: same probe/sentinel proof
		// as consent_rows(). A real run first (proven, nonce A), then a
		// blanked statement meeting that stale set.
		$real = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			protected function elementor_env() {
				return array( 'installed' => false, 'version' => null, 'class_present' => false, 'active' => null );
			}
			protected function consent_rows() {
				return array();
			}
			protected function context_user_ids( $offset, $number ) {
				return array();
			}
			protected function context_users_total() {
				return 0;
			}
		};
		$GLOBALS['_app_passwords'][7] = array( array( 'uuid' => 'u-7', 'name' => 'Elementor MCP (cursor)', 'created' => 1, 'last_used' => null, 'last_ip' => null ) );
		$first = $real->execute( array() )['elementor']['app_passwords'];
		$this->assertSame( 1, $first['candidates_read'] );

		$GLOBALS['_sa_wpdb_query_filtered_out'] = true;
		$p = $real->execute( array() )['elementor']['app_passwords'];
		$this->assertSame( array( 'error' => 'candidate statement did not run: result set is not this statement\'s' ), $p['elementor'] );
		$this->assertArrayNotHasKey( 'candidates_read', $p );
	}

	public function test_a_read_only_tool_fires_no_unproven_action_even_when_oversized(): void {
		// `audit_mcp_exposure` is annotated read_only: true, and can read up to
		// ~250 users' Application Password lists. The #434 breadcrumb listener
		// (Aura_Worker_Magic_Link::record_probe_unproven, which update_option()s
		// aura_worker_app_password_probe_unproven) is registered by
		// Aura_Worker::init() in production — never run by this bootstrap — so
		// it is registered here directly, against the REAL class the bootstrap
		// already loads (tests/bootstrap.php requires class-aura-worker-magic-
		// link.php unconditionally), proving the real boundary rather than a
		// mirror of it.
		require_once SA_PLUGIN_DIR . '/includes/class-aura-worker-magic-link.php';
		add_action( 'aura_worker_app_password_probe_unproven', array( 'Aura_Worker_Magic_Link', 'record_probe_unproven' ), 10, 1 );

		$real = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			protected function elementor_env() {
				return array( 'installed' => false, 'version' => null, 'class_present' => false, 'active' => null );
			}
			protected function consent_rows() {
				return array();
			}
			protected function context_user_ids( $offset, $number ) {
				return array();
			}
			protected function context_users_total() {
				return 0;
			}
		};
		// One Elementor-named candidate whose list is oversized (300,000-char
		// name) — a LIKE match the bounded read then cannot decode.
		$GLOBALS['_app_passwords'] = array(
			3 => array( self::pw( array( 'name' => 'Elementor MCP ' . str_repeat( 'n', 300000 ) ) ) ),
		);
		$before = get_option( 'aura_worker_app_password_probe_unproven', null );
		$b      = $real->execute( array() )['elementor'];
		$after  = get_option( 'aura_worker_app_password_probe_unproven', null );

		$this->assertSame( $before, $after ); // the breadcrumb was never written
		$this->assertSame( array( 3 ), $b['app_passwords']['elementor_unproven'] );
	}

	// --- Task 5: context ------------------------------------------------------

	public function test_context_counts_other_passwords_and_recent_use(): void {
		$now = time();
		$this->tool->context_pages = array( array( 1, 2, 3 ) );
		$this->tool->context_total = 3;
		$this->tool->lists         = array(
			1 => array( self::pw( array( 'name' => 'Aura SiteAgent', 'last_used' => $now - 86400 ) ), self::pw( array( 'name' => 'Aura Fleet', 'last_used' => $now - 40 * 86400 ) ) ),
			2 => array(),
			3 => array( self::pw( array( 'name' => 'Zapier', 'last_used' => null ) ) ),
		);
		$b = $this->block();
		$this->assertSame( array( 'users_checked' => 3, 'count' => 3, 'recently_used' => 1, 'unproven' => array() ), $b['app_passwords']['other'] );
		$this->assertSame( array( 'users_total' => 3, 'users_checked' => 3, 'truncated' => false, 'cap' => 200 ), $b['coverage'] );
	}

	public function test_an_elementor_named_password_met_in_context_is_not_counted_twice(): void {
		$this->tool->candidates    = array( 1 );
		$this->tool->context_pages = array( array( 1 ) );
		$this->tool->context_total = 1;
		$this->tool->lists         = array( 1 => array( self::pw(), self::pw( array( 'name' => 'Aura SiteAgent' ) ) ) );
		$b = $this->block();
		$this->assertCount( 1, $b['app_passwords']['elementor'] );
		$this->assertSame( 1, $b['app_passwords']['other']['count'] );
	}

	public function test_an_unreadable_context_list_is_unproven_never_zero(): void {
		$this->tool->context_pages = array( array( 1, 2 ) );
		$this->tool->context_total = 2;
		$this->tool->lists         = array( 1 => null, 2 => array( self::pw( array( 'name' => 'X' ) ) ) );
		$o = $this->block()['app_passwords']['other'];
		$this->assertSame( array( 1 ), $o['unproven'] );
		$this->assertSame( 1, $o['count'] );
		$this->assertSame( 2, $o['users_checked'] );
	}

	public function test_context_pages_through_fifty_at_a_time(): void {
		$this->tool->context_pages = array( range( 1, 50 ), range( 51, 100 ), range( 101, 120 ) );
		$this->tool->context_total = 120;
		$c = $this->block()['coverage'];
		$this->assertSame( array( 'users_total' => 120, 'users_checked' => 120, 'truncated' => false, 'cap' => 200 ), $c );
	}

	public function test_more_than_the_cap_is_truncated_at_two_hundred(): void {
		$this->tool->context_pages = array( range( 1, 50 ), range( 51, 100 ), range( 101, 150 ), range( 151, 200 ), range( 201, 250 ) );
		$this->tool->context_total = 250;
		$b = $this->block();
		$this->assertSame( array( 'users_total' => 250, 'users_checked' => 200, 'truncated' => true, 'cap' => 200 ), $b['coverage'] );
		$this->assertSame( 200, $b['app_passwords']['other']['users_checked'] );
		$this->assertSame( 200, count( array_unique( $this->tool->reads ) ) );
	}

	public function test_a_total_that_lags_the_pages_is_reconciled_so_the_invariant_holds(): void {
		// Parser invariant: truncated:false ⇒ users_checked == users_total, and
		// users_checked <= users_total. A count query that raced a user
		// creation must not produce a payload the parser refuses.
		$this->tool->context_pages = array( array( 1, 2, 3, 4 ) );
		$this->tool->context_total = 3;
		$c = $this->block()['coverage'];
		$this->assertSame( 4, $c['users_total'] );
		$this->assertSame( 4, $c['users_checked'] );
		$this->assertFalse( $c['truncated'] );
	}

	public function test_a_total_above_the_pages_is_reported_as_truncated(): void {
		// The pages ran dry before the count said they should: incomplete, honestly.
		$this->tool->context_pages = array( array( 1, 2 ) );
		$this->tool->context_total = 5;
		$c = $this->block()['coverage'];
		$this->assertSame( array( 'users_total' => 5, 'users_checked' => 2, 'truncated' => true, 'cap' => 200 ), $c );
	}

	public function test_a_duplicate_id_across_pages_is_checked_once(): void {
		// Pages must be FULL for the next one to be fetched (a short page ends
		// the scan), so the duplicate sits at the seam of two 50-id pages.
		$this->tool->context_pages = array( range( 1, 50 ), array( 50, 51 ) );
		$this->tool->context_total = 51;
		$this->assertSame( 51, $this->block()['coverage']['users_checked'] );
	}

	public function test_a_throw_in_the_context_scan_replaces_other_and_coverage_together(): void {
		$this->tool->throw_in      = array( 'context' );
		$this->tool->candidates    = array( 1 );
		$this->tool->lists         = array( 1 => array( self::pw( array( 'last_used' => 5 ) ) ) );
		$b = $this->block();
		$this->assertSame( array( 'error' => 'context exploded' ), $b['app_passwords']['other'] );
		$this->assertSame( array( 'error' => 'context exploded' ), $b['coverage'] );
		$this->assertCount( 1, $b['app_passwords']['elementor'] ); // the used credential survived
	}

	public function test_a_throw_counting_users_is_a_context_error_too(): void {
		$this->tool->throw_in = array( 'total' );
		$this->assertSame( array( 'error' => 'total exploded' ), $this->block()['coverage'] );
	}

	public function test_the_context_query_asks_for_edit_posts_ids_in_order(): void {
		// The REAL seams against the bootstrap's WP_User_Query stub, which
		// records every query's vars.
		$real = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			protected function elementor_env() {
				return array( 'installed' => false, 'version' => null, 'class_present' => false, 'active' => null );
			}
			protected function consent_rows() {
				return array();
			}
			protected function elementor_candidate_ids() {
				return array();
			}
			protected function password_list( $uid ) {
				return array();
			}
		};
		$GLOBALS['_users']        = array( 3, 4 );
		$GLOBALS['_users_total']  = 2;
		$GLOBALS['_user_queries'] = array();
		$b = $real->execute( array() )['elementor'];
		$this->assertSame( array( 'users_total' => 2, 'users_checked' => 2, 'truncated' => false, 'cap' => 200 ), $b['coverage'] );
		$vars = $GLOBALS['_user_queries'];
		$this->assertSame( array( 'capability' => 'edit_posts', 'fields' => 'ID', 'number' => 1, 'count_total' => true ), $vars[0] );
		$this->assertSame( array( 'capability' => 'edit_posts', 'fields' => 'ID', 'number' => 50, 'offset' => 0, 'orderby' => 'ID', 'order' => 'ASC', 'count_total' => false ), $vars[1] );
	}

	public function test_a_failed_user_count_query_is_a_context_error_not_an_empty_inventory(): void {
		// Codex round-3 P2: WP_User_Query wraps a wpdb statement that can fail
		// the same way the direct SQL scans (consent, candidates) can —
		// WordPress answers an empty result / zero total on a database
		// failure, never a throw. The REAL seam against the bootstrap's
		// WP_User_Query stub, via its _sa_user_query_error knob.
		$real = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			protected function elementor_env() {
				return array( 'installed' => false, 'version' => null, 'class_present' => false, 'active' => null );
			}
			protected function consent_rows() {
				return array();
			}
			protected function elementor_candidate_ids() {
				return array();
			}
		};
		$GLOBALS['_sa_user_query_error'] = 'Table wp_users doesnt exist';
		$b = $real->execute( array() )['elementor'];
		$this->assertSame( array( 'error' => 'user query failed: Table wp_users doesnt exist' ), $b['coverage'] );
		$this->assertSame( array( 'error' => 'user query failed: Table wp_users doesnt exist' ), $b['app_passwords']['other'] );
		// mcp_module and consent, read independently, are untouched.
		$this->assertFalse( $b['installed'] );
		$this->assertArrayNotHasKey( 'error', $b['mcp_module'] );
		$this->assertSame( array(), $b['consent'] );
	}

	public function test_a_count_query_failure_cleared_by_found_rows_is_still_caught(): void {
		// Codex round-7 P2: count_total => true runs the main SELECT and then
		// SELECT FOUND_ROWS() via $wpdb->get_var(), which flushes
		// $wpdb->last_error — so a failed main query followed by a successful
		// FOUND_ROWS leaves last_error empty by the time get_total() returns,
		// and the round-3 fix above (which reads last_error only AFTER
		// get_total()) would read 0 with no error. The bootstrap's
		// _sa_user_query_error_cleared_by_found_rows knob models exactly that
		// clearing; context_users_total()'s found_users_query filter is what
		// still sees the error, since it fires between the two statements.
		$real = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			protected function elementor_env() {
				return array( 'installed' => false, 'version' => null, 'class_present' => false, 'active' => null );
			}
			protected function consent_rows() {
				return array();
			}
			protected function elementor_candidate_ids() {
				return array();
			}
		};
		$GLOBALS['_sa_user_query_error'] = 'Table wp_users doesnt exist';
		$GLOBALS['_sa_user_query_error_cleared_by_found_rows'] = true;
		$b = $real->execute( array() )['elementor'];
		$this->assertSame( array( 'error' => 'user query failed: Table wp_users doesnt exist' ), $b['coverage'] );
		$this->assertSame( array( 'error' => 'user query failed: Table wp_users doesnt exist' ), $b['app_passwords']['other'] );
		$this->assertFalse( $b['installed'] );
		$this->assertArrayNotHasKey( 'error', $b['mcp_module'] );
		$this->assertSame( array(), $b['consent'] );
	}

	public function test_a_failed_paging_query_is_also_a_context_error(): void {
		// Isolates context_user_ids()'s own failure path (context_users_total()
		// is overridden to succeed, so the scan reaches the paging query).
		$real = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			protected function elementor_env() {
				return array( 'installed' => false, 'version' => null, 'class_present' => false, 'active' => null );
			}
			protected function consent_rows() {
				return array();
			}
			protected function elementor_candidate_ids() {
				return array();
			}
			protected function context_users_total() {
				return 5;
			}
		};
		$GLOBALS['_sa_user_query_error'] = 'Table wp_users doesnt exist';
		$b = $real->execute( array() )['elementor'];
		$this->assertSame( array( 'error' => 'user query failed: Table wp_users doesnt exist' ), $b['coverage'] );
		$this->assertSame( array( 'error' => 'user query failed: Table wp_users doesnt exist' ), $b['app_passwords']['other'] );
	}

	public function test_a_page_that_never_advances_terminates_and_is_bounded(): void {
		// A context_user_ids() that ignores $offset (a pre_user_query filter
		// dropping it) returns the SAME full page every time. Before the fix,
		// count($ids) === PAGE kept `if ( count( $ids ) < PAGE ) break;` from
		// firing while $checked never advanced, so the while() never exited
		// on its own.
		$seam = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			public $calls = 0;
			protected function elementor_env() {
				return array( 'installed' => false, 'version' => null, 'class_present' => false, 'active' => null );
			}
			protected function consent_rows() {
				return array();
			}
			protected function elementor_candidate_ids() {
				return array();
			}
			protected function context_user_ids( $offset, $number ) {
				++$this->calls;
				return range( 1, 50 );
			}
			protected function context_users_total() {
				return 120;
			}
			protected function password_list( $uid ) {
				return array();
			}
		};
		$b = $seam->execute( array() )['elementor'];
		$this->assertSame( 50, $b['coverage']['users_checked'] );
		$this->assertTrue( $b['coverage']['truncated'] );
		$this->assertLessThanOrEqual( 6, $seam->calls );
	}

	public function test_a_mid_page_cap_reads_every_id_exactly_once(): void {
		// The fixture the ledger deferred: page 4 overlaps page 3 by 25 ids,
		// so the cap (200) is reached partway through it. Nothing here may be
		// read twice, and the scan must still terminate cleanly.
		$seam = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			public $pages = array();
			public $reads = array();
			protected function elementor_env() {
				return array( 'installed' => false, 'version' => null, 'class_present' => false, 'active' => null );
			}
			protected function consent_rows() {
				return array();
			}
			protected function elementor_candidate_ids() {
				return array();
			}
			protected function context_user_ids( $offset, $number ) {
				$page = (int) ( $offset / $number );
				return isset( $this->pages[ $page ] ) ? $this->pages[ $page ] : array();
			}
			protected function context_users_total() {
				return 250;
			}
			protected function password_list( $uid ) {
				$this->reads[] = (int) $uid;
				return array();
			}
		};
		$seam->pages = array(
			range( 1, 50 ),
			range( 51, 100 ),
			range( 101, 150 ),
			range( 126, 175 ),
			range( 176, 250 ),
		);
		$b = $seam->execute( array() )['elementor'];
		$this->assertSame( 200, $b['coverage']['users_checked'] );
		$this->assertTrue( $b['coverage']['truncated'] );
		$this->assertSame( count( $seam->reads ), count( array_unique( $seam->reads ) ) );
	}
}
