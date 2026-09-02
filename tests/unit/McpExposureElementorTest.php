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

	private function maybe_throw( $seam ) {
		if ( in_array( $seam, $this->throw_in, true ) ) {
			throw new RuntimeException( $seam . ' exploded' );
		}
	}
	protected function elementor_env() {
		$this->maybe_throw( 'env' );
		return $this->env;
	}
	protected function elementor_ability_names() {
		$this->maybe_throw( 'abilities' );
		return $this->abilities;
	}
	protected function servers() {
		return $this->server_list;
	}
	protected function consent_rows() {
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
		$this->tool->throw_in = array( 'abilities' );
		$this->assertSame( array( 'error' => 'abilities exploded' ), $this->block()['mcp_module'] );
	}

	public function test_strings_are_clipped_at_200(): void {
		$this->tool->env = array( 'installed' => true, 'version' => str_repeat( 'v', 500 ), 'class_present' => true, 'active' => true );
		$this->assertSame( 200, strlen( $this->block()['version'] ) );
	}

	public function test_elementor_module_from_is_pure(): void {
		$m = Aura_Tool_Audit_Mcp_Exposure::elementor_module_from(
			array( 'installed' => true, 'version' => '4.3.0', 'class_present' => true, 'active' => false ),
			array( 'elementor/a', 'elementor/b' ),
			array( array( 'id' => 'angie' ) )
		);
		$this->assertSame( array( 'class_present' => true, 'active' => false, 'abilities_registered' => 2, 'server_id' => null ), $m );
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
		$this->assertSame(
			'SELECT m.user_id, u.user_login, LENGTH(m.meta_value) AS len, IF(LENGTH(m.meta_value) <= %d, m.meta_value, NULL) AS v FROM wp_usermeta m LEFT JOIN wp_users u ON u.ID = m.user_id WHERE m.meta_key = %s ORDER BY m.umeta_id ASC LIMIT %d',
			$prepared[0]['query']
		);
		$this->assertSame( array( 262144, 'elementor_mcp_consent', 51 ), $prepared[0]['args'] );
	}
}
