<?php
/**
 * Tests for the K5 security-audit read surface: check_core_checksums,
 * scan_executable_files, audit_admin_accounts, audit_cron.
 *
 * Fixture-based (temp trees, mocked manifest via the filter seam, wpdb var
 * queue for LENGTH() pre-checks) — no network. Covers the spec's acceptance
 * criteria (b), (c), (f), (g) plus the failure paths.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

require_once SA_PLUGIN_DIR . '/includes/tools/class-tool-check-core-checksums.php';
require_once SA_PLUGIN_DIR . '/includes/tools/class-tool-scan-executable-files.php';
require_once SA_PLUGIN_DIR . '/includes/tools/class-tool-audit-admin-accounts.php';
require_once SA_PLUGIN_DIR . '/includes/tools/class-tool-audit-cron.php';

final class SecurityAuditToolsTest extends TestCase {

	/** @var string[] Temp dirs to remove in tearDown. */
	private array $tmp_dirs = array();

	protected function setUp(): void {
		sa_reset_state();
	}

	protected function tearDown(): void {
		foreach ( $this->tmp_dirs as $dir ) {
			$this->rrmdir( $dir );
		}
		$this->tmp_dirs = array();
		parent::tearDown();
	}

	private function make_tmp_dir(): string {
		$dir = sys_get_temp_dir() . '/sa-k5-' . bin2hex( random_bytes( 6 ) );
		mkdir( $dir, 0700, true );
		$this->tmp_dirs[] = $dir;
		return $dir;
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			@unlink( $dir );
			return;
		}
		foreach ( scandir( $dir ) ?: array() as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->rrmdir( $path );
			} else {
				@unlink( $path );
			}
		}
		@rmdir( $dir );
	}

	// -------------------------------------------------------------------------
	// check_core_checksums — acceptance (b)
	// -------------------------------------------------------------------------

	/** Builds a fixture core tree + manifest; returns [base, manifest]. */
	private function core_fixture(): array {
		$base = $this->make_tmp_dir();
		mkdir( $base . '/wp-admin' );
		mkdir( $base . '/wp-includes' );
		mkdir( $base . '/wp-content' );

		file_put_contents( $base . '/wp-load.php', "<?php // core\n" );
		file_put_contents( $base . '/wp-admin/admin.php', "<?php // admin\n" );
		file_put_contents( $base . '/wp-includes/version.php', "<?php // version\n" );
		file_put_contents( $base . '/wp-config.php', "<?php // config\n" );

		$manifest = array(
			'wp-load.php'             => md5_file( $base . '/wp-load.php' ),
			'wp-admin/admin.php'      => md5_file( $base . '/wp-admin/admin.php' ),
			'wp-includes/version.php' => md5_file( $base . '/wp-includes/version.php' ),
		);
		return array( $base, $manifest );
	}

	private function checksums_tool( string $base, ?array $manifest ) {
		$GLOBALS['_filters']['aura_worker_core_checksums_manifest'][] = static fn() => $manifest;
		$GLOBALS['_filters']['aura_worker_core_checksums_base'][]     = static fn() => $base;
		return new Aura_Tool_Check_Core_Checksums();
	}

	public function test_checksums_detects_modified_core_file(): void {
		[ $base, $manifest ] = $this->core_fixture();
		file_put_contents( $base . '/wp-includes/version.php', "<?php // TAMPERED\n" );

		$result = $this->checksums_tool( $base, $manifest )->execute( array() );

		$files = array_column( $result['modified'], 'file' );
		$this->assertContains( 'wp-includes/version.php', $files );
		$this->assertFalse( $result['coverage']['truncated'] );
	}

	public function test_checksums_detects_wp_includes_implant(): void {
		[ $base, $manifest ] = $this->core_fixture();
		file_put_contents( $base . '/wp-includes/evil.php', "<?php evil();\n" );

		$result = $this->checksums_tool( $base, $manifest )->execute( array() );

		$files = array_column( $result['unexpected'], 'file' );
		$this->assertContains( 'wp-includes/evil.php', $files );
	}

	public function test_checksums_detects_abspath_root_implant_and_spares_allowlist(): void {
		[ $base, $manifest ] = $this->core_fixture();
		file_put_contents( $base . '/wp-shell.php', "<?php shell();\n" );

		$result = $this->checksums_tool( $base, $manifest )->execute( array() );

		$files = array_column( $result['unexpected'], 'file' );
		$this->assertContains( 'wp-shell.php', $files, 'Root-level implant beside wp-load.php must be reported.' );
		$this->assertNotContains( 'wp-config.php', $files, 'Allowlisted root files are never reported as unexpected.' );
		$this->assertContains( 'wp-config.php', $result['root_extra'] );
	}

	public function test_checksums_reports_symlink_at_core_path_without_hashing(): void {
		[ $base, $manifest ] = $this->core_fixture();
		unlink( $base . '/wp-includes/version.php' );
		symlink( '/etc/hostname', $base . '/wp-includes/version.php' );

		$result = $this->checksums_tool( $base, $manifest )->execute( array() );

		$kinds = array_column( $result['special'], 'kind', 'file' );
		$this->assertSame( 'symlink', $kinds['wp-includes/version.php'] ?? null, 'Symlink at a core path is a finding, never hashed or followed.' );
		$this->assertNotContains( 'wp-includes/version.php', array_column( $result['modified'], 'file' ) );
	}

	public function test_checksums_symlinked_ancestor_dir_reported_not_followed(): void {
		[ $base, $manifest ] = $this->core_fixture();

		// Replace wp-includes with a symlink to a look-alike tree: hashing
		// through it would read OUTSIDE the intended core tree.
		$outside = $this->make_tmp_dir();
		file_put_contents( $outside . '/version.php', "<?php // outside\n" );
		$this->rrmdir( $base . '/wp-includes' );
		symlink( $outside, $base . '/wp-includes' );

		$result = $this->checksums_tool( $base, $manifest )->execute( array() );

		$kinds = array_column( $result['special'], 'kind', 'file' );
		$this->assertSame( 'symlink', $kinds['wp-includes'] ?? null, 'A symlinked ancestor directory is the finding.' );
		$this->assertNotContains( 'wp-includes/version.php', array_column( $result['modified'], 'file' ), 'Files under a symlinked ancestor are never hashed.' );
	}

	public function test_admin_audit_site_admins_size_query_scoped_to_network(): void {
		$GLOBALS['_is_multisite'] = true;
		$GLOBALS['_db_var_queue'] = array( 60 );
		$GLOBALS['_site_options']['site_admins'] = array( 'boss' );
		$GLOBALS['_admins'] = array();

		( new Aura_Tool_Audit_Admin_Accounts() )->execute( array() );

		$queries = array_filter(
			$GLOBALS['_db_prepared'],
			static fn( $q ) => in_array( 'site_admins', $q['args'], true )
		);
		$this->assertNotEmpty( $queries );
		$q = array_values( $queries )[0];
		$this->assertStringContainsString( 'site_id = %d', $q['query'], 'Size pre-check must be scoped to the current network.' );
	}

	public function test_checksums_manifest_unavailable_fails_closed(): void {
		[ $base ] = $this->core_fixture();

		$result = $this->checksums_tool( $base, null )->execute( array() );

		$this->assertSame( 'manifest_unavailable', $result['error'] );
		$this->assertArrayNotHasKey( 'modified', $result, '"Could not verify" must never look like "verified clean".' );
	}

	public function test_checksums_live_fetch_is_https_only(): void {
		[ $base ] = $this->core_fixture();
		$GLOBALS['_filters']['aura_worker_core_checksums_base'][] = static fn() => $base;
		$GLOBALS['_http_error']                                    = true;

		$tool   = new Aura_Tool_Check_Core_Checksums();
		$result = $tool->execute( array() );

		$this->assertSame( 'manifest_unavailable', $result['error'], 'HTTPS failure is manifest_unavailable — never a downgrade.' );
		$this->assertNotEmpty( $GLOBALS['_wp_http_calls'] );
		$call = $GLOBALS['_wp_http_calls'][0];
		$this->assertStringStartsWith( 'https://api.wordpress.org/core/checksums/1.0/', $call['url'] );
		$this->assertTrue( $call['args']['sslverify'] );
	}

	public function test_checksums_cap_hit_reports_truncation(): void {
		[ $base, $manifest ] = $this->core_fixture();

		$tool = new class() extends Aura_Tool_Check_Core_Checksums {
			const MAX_FILES = 1;
		};
		$GLOBALS['_filters']['aura_worker_core_checksums_manifest'][] = static fn() => $manifest;
		$GLOBALS['_filters']['aura_worker_core_checksums_base'][]     = static fn() => $base;

		$result = $tool->execute( array() );

		$this->assertTrue( $result['coverage']['truncated'] );
		$this->assertSame( 'max_files', $result['coverage']['cap'] );
		$this->assertSame( 1, $result['coverage']['files_checked'], 'total/checked are lower-bound semantics when truncated.' );
	}

	// -------------------------------------------------------------------------
	// scan_executable_files — acceptance (c)
	// -------------------------------------------------------------------------

	private function exec_tool( string $dir ) {
		$GLOBALS['_filters']['aura_worker_scan_executable_dirs'][] = static fn() => array( $dir );
		return new Aura_Tool_Scan_Executable_Files();
	}

	public function test_exec_scan_finds_planted_shell_and_ignores_media(): void {
		$dir = $this->make_tmp_dir();
		file_put_contents( $dir . '/shell.php', "<?php shell();\n" );
		file_put_contents( $dir . '/photo.jpg', 'JPEGDATA' );

		$result = $this->exec_tool( $dir )->execute( array() );

		$files = array_column( $result['findings'], 'file' );
		$this->assertContains( 'shell.php', $files );
		$this->assertNotContains( 'photo.jpg', $files );
	}

	public function test_exec_scan_reports_symlink_without_following(): void {
		$dir    = $this->make_tmp_dir();
		$target = $this->make_tmp_dir();
		file_put_contents( $target . '/outside.php', "<?php outside();\n" );
		symlink( $target, $dir . '/link-out' );

		$result = $this->exec_tool( $dir )->execute( array() );

		$by_file = array_column( $result['findings'], 'kind', 'file' );
		$this->assertSame( 'symlink', $by_file['link-out'] ?? null, 'Symlink reported as an observation.' );
		$this->assertNotContains( 'link-out/outside.php', array_column( $result['findings'], 'file' ), 'The link must never be followed.' );
	}

	public function test_exec_scan_cap_hit_reports_truncation(): void {
		$dir = $this->make_tmp_dir();
		for ( $i = 0; $i < 5; $i++ ) {
			file_put_contents( $dir . "/f$i.php", '<?php' );
		}

		$tool = new class() extends Aura_Tool_Scan_Executable_Files {
			const MAX_ENTRIES = 2;
		};
		$GLOBALS['_filters']['aura_worker_scan_executable_dirs'][] = static fn() => array( $dir );

		$result = $tool->execute( array() );

		$this->assertTrue( $result['coverage']['truncated'] );
		$this->assertSame( 'max_entries', $result['coverage']['cap'] );
	}

	// -------------------------------------------------------------------------
	// audit_admin_accounts — acceptance (f)
	// -------------------------------------------------------------------------

	private function make_user( int $id, string $login, array $roles, string $registered ): object {
		return (object) array(
			'ID'              => $id,
			'user_login'      => $login,
			'roles'           => $roles,
			'user_registered' => $registered,
		);
	}

	public function test_admin_audit_single_site_functional_case(): void {
		$GLOBALS['_admins'] = array(
			$this->make_user( 1, 'owner', array( 'administrator' ), '2024-01-01 00:00:00' ),
			$this->make_user( 7, 'shadow', array( 'editor' ), gmdate( 'Y-m-d H:i:s', time() - 5 * DAY_IN_SECONDS ) ),
		);
		$GLOBALS['_user_meta'][1]['_application_passwords'] = array( array( 'uuid' => 'a' ), array( 'uuid' => 'b' ) );
		// LENGTH() pre-checks: two app-password queries (small sizes).
		$GLOBALS['_db_var_queue'] = array( 100, 50 );

		$result = ( new Aura_Tool_Audit_Admin_Accounts() )->execute( array() );

		$by_login = array_column( $result['accounts'], null, 'user_login' );
		$this->assertTrue( $by_login['owner']['is_admin'] );
		$this->assertSame( 2, $by_login['owner']['app_passwords'] );
		$this->assertFalse( $by_login['owner']['recently_created'] );
		$this->assertTrue( $by_login['shadow']['capability_outside_role'], 'Editor holding manage_options is flagged as capability outside role.' );
		$this->assertTrue( $by_login['shadow']['recently_created'] );
		$this->assertSame( 'not_multisite', $result['super_admins'] );
	}

	public function test_admin_audit_multisite_lists_nonmember_super_admin(): void {
		$GLOBALS['_is_multisite']                = true;
		$GLOBALS['_site_options']['site_admins'] = array( 'network-boss' );
		// LENGTH() queue: site_admins size, then app-password sizes.
		$GLOBALS['_db_var_queue'] = array( 60, 10 );
		$GLOBALS['_admins']        = array(
			$this->make_user( 1, 'owner', array( 'administrator' ), '2024-01-01 00:00:00' ),
		);

		$result = ( new Aura_Tool_Audit_Admin_Accounts() )->execute( array() );

		$this->assertSame( array( 'network-boss' ), $result['super_admins'], 'A super admin who is not a member of the scanned site must still surface.' );
	}

	public function test_admin_audit_oversized_site_admins_skipped(): void {
		$GLOBALS['_is_multisite'] = true;
		// site_admins LENGTH over the 1 MB threshold.
		$GLOBALS['_db_var_queue'] = array( 2000000 );
		$GLOBALS['_admins']        = array();

		$result = ( new Aura_Tool_Audit_Admin_Accounts() )->execute( array() );

		$this->assertSame( 'oversized_skipped', $result['super_admins'] );
	}

	public function test_admin_audit_cap_hit_reports_truncation(): void {
		$tool = new class() extends Aura_Tool_Audit_Admin_Accounts {
			const MAX_ACCOUNTS = 1;
		};
		$GLOBALS['_db_var_queue'] = array( 10, 10 );
		$GLOBALS['_admins']        = array(
			$this->make_user( 1, 'a', array( 'administrator' ), '2024-01-01 00:00:00' ),
			$this->make_user( 2, 'b', array( 'administrator' ), '2024-01-01 00:00:00' ),
		);

		$result = $tool->execute( array() );

		$this->assertTrue( $result['coverage']['truncated'] );
		$this->assertSame( 'max_accounts', $result['coverage']['cap'] );
		$this->assertCount( 1, $result['accounts'] );
	}

	// -------------------------------------------------------------------------
	// audit_cron — acceptance (g) + ordinary functional case
	// -------------------------------------------------------------------------

	public function test_cron_ordinary_inventory_with_fact_flags(): void {
		$GLOBALS['_db_var_queue'] = array( 500 ); // Raw option size: small.
		$GLOBALS['_cron_array']   = array(
			1900000000 => array(
				'wp_update_plugins' => array(
					'k1' => array( 'schedule' => 'hourly', 'args' => array() ),
				),
				'suspicious_hook'   => array(
					'k2' => array( 'schedule' => 'burst', 'interval' => 10, 'args' => array( 'x' ) ),
				),
			),
		);
		// wp_update_plugins has a registered callback; suspicious_hook does not.
		$GLOBALS['_filters']['wp_update_plugins'][] = static fn( $v ) => $v;

		$result = ( new Aura_Tool_Audit_Cron() )->execute( array() );

		$by_hook = array_column( $result['events'], null, 'hook' );
		$this->assertSame( 3600, $by_hook['wp_update_plugins']['interval'] );
		$this->assertFalse( $by_hook['wp_update_plugins']['interval_lt_60s'] );
		$this->assertFalse( $by_hook['wp_update_plugins']['unresolved_in_this_context'] );
		$this->assertTrue( $by_hook['suspicious_hook']['interval_lt_60s'] );
		$this->assertTrue( $by_hook['suspicious_hook']['unresolved_in_this_context'] );
		$this->assertFalse( $result['coverage']['truncated'] );
	}

	public function test_cron_oversized_option_returns_fact_without_materializing(): void {
		$GLOBALS['_db_var_queue'] = array( 5000000 );
		$GLOBALS['_cron_array']   = array( 1 => array( 'should_never_be_read' => array() ) );

		$result = ( new Aura_Tool_Audit_Cron() )->execute( array() );

		$this->assertSame( 'cron_option_oversized', $result['error'] );
		$this->assertSame( 5000000, $result['size_bytes'] );
		$this->assertArrayNotHasKey( 'events', $result );
	}

	public function test_cron_cap_hit_reports_truncation(): void {
		$tool = new class() extends Aura_Tool_Audit_Cron {
			const MAX_EVENTS = 1;
		};
		$GLOBALS['_db_var_queue'] = array( 500 );
		$GLOBALS['_cron_array']   = array(
			1900000000 => array(
				'hook_a' => array( 'k' => array( 'schedule' => 'hourly', 'args' => array() ) ),
				'hook_b' => array( 'k' => array( 'schedule' => 'hourly', 'args' => array() ) ),
			),
		);

		$result = $tool->execute( array() );

		$this->assertTrue( $result['coverage']['truncated'] );
		$this->assertSame( 'max_events', $result['coverage']['cap'] );
		$this->assertSame( 2, $result['coverage']['total_seen'], 'total_seen keeps counting past the cap (lower-bound semantics are exact here).' );
		$this->assertCount( 1, $result['events'] );
	}

	// -------------------------------------------------------------------------
	// Contract: all four are read-only with explicit annotations
	// -------------------------------------------------------------------------

	public function test_all_four_tools_declare_read_only_annotations(): void {
		$tools = array(
			new Aura_Tool_Check_Core_Checksums(),
			new Aura_Tool_Scan_Executable_Files(),
			new Aura_Tool_Audit_Admin_Accounts(),
			new Aura_Tool_Audit_Cron(),
		);
		foreach ( $tools as $tool ) {
			$a = $tool->get_annotations();
			$this->assertTrue( $a['read_only'], $tool->get_name() );
			$this->assertFalse( $a['destructive'], $tool->get_name() );
			$this->assertFalse( $a['requires_approval'], $tool->get_name() );
		}
	}
}
