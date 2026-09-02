<?php
/**
 * aura_worker_app_password_list( $owner, $max_bytes ): the byte bound is part
 * of the statement that returns the value, so a concurrent usermeta write
 * cannot swap an oversized value in between a probe and a fetch.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once SA_PLUGIN_DIR . '/includes/credential-rules.php';

final class AppPasswordListBoundedTest extends TestCase {

	protected function setUp(): void {
		sa_reset_state();
		$GLOBALS['_sa_unproven_calls'] = array();
		add_action(
			'aura_worker_app_password_probe_unproven',
			static function ( $owner, $reason = '' ) {
				$GLOBALS['_sa_unproven_calls'][] = array( (int) $owner, (string) $reason );
			},
			10,
			2
		);
	}

	public function test_without_a_bound_the_statement_is_unchanged(): void {
		// Every existing caller passes no bound and must see exactly the shape
		// the #434 tests pin — this is the regression guard for them.
		$GLOBALS['_app_passwords'][7] = array( array( 'uuid' => 'u-1', 'name' => 'Aura SiteAgent' ) );
		$list = aura_worker_app_password_list( 7 );
		$this->assertSame( 'u-1', $list[0]['uuid'] );
		$this->assertMatchesRegularExpression( "/^SELECT '[^']*' AS probe, \(SELECT meta_value FROM /", end( $GLOBALS['_db_queries'] ) );
	}

	public function test_an_in_bound_row_reads_exactly_as_without_a_bound(): void {
		$GLOBALS['_app_passwords'][7] = array( array( 'uuid' => 'u-1', 'name' => 'Elementor MCP - X' ) );
		$this->assertSame( aura_worker_app_password_list( 7 ), aura_worker_app_password_list( 7, 262144 ) );
		$this->assertStringContainsString( 'IF(LENGTH(meta_value) <= 262144, meta_value, NULL)', end( $GLOBALS['_db_queries'] ) );
	}

	public function test_no_row_is_an_empty_list_with_a_bound_too(): void {
		$this->assertSame( array(), aura_worker_app_password_list( 9, 262144 ) );
		$this->assertSame( array(), $GLOBALS['_sa_unproven_calls'] );
	}

	public function test_an_oversized_row_is_null_and_never_decoded(): void {
		// The value is NOT in the result set: the stub returns v NULL when len
		// exceeds the bound, exactly as MySQL's IF() would. A null here is
		// "proved nothing", which no caller may read as "holds none".
		$GLOBALS['_app_passwords'][7] = array( array( 'uuid' => 'u-1', 'name' => str_repeat( 'x', 300 ) ) );
		$this->assertNull( aura_worker_app_password_list( 7, 100 ) );
		$this->assertSame( array( array( 7, 'oversized' ) ), $GLOBALS['_sa_unproven_calls'] );
	}

	public function test_a_failed_statement_is_null_with_a_bound_too(): void {
		$GLOBALS['_sa_app_password_read_fail'][7] = true;
		$this->assertNull( aura_worker_app_password_list( 7, 262144 ) );
		$this->assertSame( array( array( 7, '' ) ), $GLOBALS['_sa_unproven_calls'] );
	}

	// --- $notify = false: a read-only caller must fire no action (finding 3) ---

	public function test_notify_false_suppresses_the_oversized_action(): void {
		$GLOBALS['_app_passwords'][7] = array( array( 'uuid' => 'u-1', 'name' => str_repeat( 'x', 300 ) ) );
		$this->assertNull( aura_worker_app_password_list( 7, 100, false ) );
		$this->assertSame( array(), $GLOBALS['_sa_unproven_calls'] );
	}

	public function test_notify_false_suppresses_the_failed_statement_action(): void {
		$GLOBALS['_sa_app_password_read_fail'][7] = true;
		$this->assertNull( aura_worker_app_password_list( 7, 262144, false ) );
		$this->assertSame( array(), $GLOBALS['_sa_unproven_calls'] );
	}

	public function test_notify_defaults_true_so_existing_callers_are_unaffected(): void {
		// Omitting the third argument must behave exactly as before it existed.
		$GLOBALS['_app_passwords'][7] = array( array( 'uuid' => 'u-1', 'name' => str_repeat( 'x', 300 ) ) );
		$this->assertNull( aura_worker_app_password_list( 7, 100 ) );
		$this->assertSame( array( array( 7, 'oversized' ) ), $GLOBALS['_sa_unproven_calls'] );
	}

	// --- allowed_classes => false decode (finding 4) ---------------------------

	public function test_a_serialised_object_row_decodes_to_an_empty_array_unbounded(): void {
		// The stub can only serialize $_app_passwords entries (always arrays),
		// so a raw serialized-object row is injected directly — the shape a
		// crafted/corrupted usermeta row would take.
		$GLOBALS['_sa_app_password_raw'][7] = 'O:8:"stdClass":1:{s:4:"uuid";s:3:"u-1";}';
		$this->assertSame( array(), aura_worker_app_password_list( 7 ) );
	}

	public function test_a_serialised_object_row_decodes_to_an_empty_array_bounded(): void {
		$GLOBALS['_sa_app_password_raw'][7] = 'O:8:"stdClass":1:{s:4:"uuid";s:3:"u-1";}';
		$this->assertSame( array(), aura_worker_app_password_list( 7, 262144 ) );
	}

	public function test_a_non_serialized_plain_string_row_is_an_empty_array(): void {
		// is_serialized() gates the decode: a plain string never reaches
		// unserialize() at all, and is never a valid Application Password list.
		$GLOBALS['_sa_app_password_raw'][7] = 'not-serialized-garbage';
		$this->assertSame( array(), aura_worker_app_password_list( 7, 262144 ) );
	}
}
