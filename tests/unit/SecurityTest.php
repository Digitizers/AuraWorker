<?php
/**
 * Tests for Aura_Worker_Security — token hashing, brute-force throttle,
 * domain allowlist, and token-only administrator run-as.
 *
 * The token/domain checks are private; tests reach them via reflection so each
 * layer is exercised in isolation (the intent stated in CLAUDE.md § Testing).
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class SecurityTest extends TestCase {

	private Aura_Worker_Security $security;

	protected function setUp(): void {
		sa_reset_state();
		$this->security = new Aura_Worker_Security();
	}

	/** Invoke a private/protected method by name. */
	private function call_private( string $method, ...$args ) {
		$ref = new ReflectionMethod( Aura_Worker_Security::class, $method );
		// Reflection ignores visibility since PHP 8.1; setAccessible() is only
		// needed on 7.4 (and is a deprecated no-op from 8.5).
		if ( PHP_VERSION_ID < 80100 ) {
			$ref->setAccessible( true );
		}
		return $ref->invoke( $this->security, ...$args );
	}

	private function request_with_token( ?string $token ): WP_REST_Request {
		$req = new WP_REST_Request();
		if ( null !== $token ) {
			$req->set_header( 'X-Aura-Token', $token );
		}
		return $req;
	}

	// --- resolve_connect_user -------------------------------------------------

	/**
	 * A MARKED SITE'S OWN RETRY MUST STILL RESOLVE (Codex round-5 P2).
	 *
	 * Phase B step (2) deletes `aura_worker_connect_user_id` while the token
	 * deliberately lives on, so from that moment the fallback decides — and it
	 * asks for the literal `administrator` ROLE, while every other check in
	 * this plugin asks for the CAPABILITY. On a site whose connecting user
	 * holds manage_options through a custom role, with nobody holding the
	 * administrator role, that resolved nobody: the token-only /rules retry
	 * failed before it could reach the marker's fast path, and the final
	 * cleanup could never be delivered. The marker captured the identity
	 * before the delete.
	 */
	public function test_a_marked_site_resolves_the_run_as_from_the_marker(): void {
		$GLOBALS['_admins']  = array();        // nobody holds the administrator ROLE
		$GLOBALS['_capable'] = array( 5 );     // the connecting user holds manage_options via a custom role
		sa_set_marker( array( 'connect_user_id' => 5 ) );
		delete_option( 'aura_worker_connect_user_id' ); // Phase B step (2) already ran

		$this->assertSame( 5, $this->call_private( 'resolve_connect_user' ) );
	}

	/** The capability gate still applies to the marker's identity. */
	public function test_the_markers_identity_is_refused_without_the_capability(): void {
		$GLOBALS['_admins']  = array();
		$GLOBALS['_capable'] = array();
		sa_set_marker( array( 'connect_user_id' => 5 ) );
		delete_option( 'aura_worker_connect_user_id' );

		$this->assertSame( 0, $this->call_private( 'resolve_connect_user' ) );
	}

	/** And a bound site is unaffected: the stored option still wins. */
	public function test_the_stored_connect_user_still_wins(): void {
		$GLOBALS['_admins']  = array( 9 );
		$GLOBALS['_capable'] = array( 5 );
		update_option( 'aura_worker_connect_user_id', 5 );

		$this->assertSame( 5, $this->call_private( 'resolve_connect_user' ) );
	}

	// --- hash_token -----------------------------------------------------------

	public function test_hash_token_is_deterministic_sha256(): void {
		$raw  = 'super-secret-token';
		$hash = Aura_Worker_Security::hash_token( $raw );

		$this->assertSame( hash( 'sha256', $raw ), $hash );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $hash );
		$this->assertSame( $hash, Aura_Worker_Security::hash_token( $raw ) );
	}

	// --- check_aura_token -----------------------------------------------------

	public function test_unconfigured_site_returns_500(): void {
		// No aura_worker_site_token option set.
		$result = $this->call_private( 'check_aura_token', $this->request_with_token( 'anything' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aura_not_configured', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
	}

	public function test_valid_hashed_token_passes_and_clears_failures(): void {
		$raw = 'valid-token-123';
		update_option( 'aura_worker_site_token', Aura_Worker_Security::hash_token( $raw ) );
		// Pre-seed a failure counter to prove success clears it.
		$key = 'aura_worker_tokfail_' . md5( $_SERVER['REMOTE_ADDR'] );
		set_transient( $key, 3, 900 );

		$result = $this->call_private( 'check_aura_token', $this->request_with_token( $raw ) );

		$this->assertTrue( $result );
		$this->assertFalse( get_transient( $key ) );
	}

	public function test_invalid_token_returns_401_and_records_failure(): void {
		update_option( 'aura_worker_site_token', Aura_Worker_Security::hash_token( 'the-real-one' ) );

		$result = $this->call_private( 'check_aura_token', $this->request_with_token( 'wrong' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aura_invalid_token', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );

		$key = 'aura_worker_tokfail_' . md5( $_SERVER['REMOTE_ADDR'] );
		$this->assertSame( 1, (int) get_transient( $key ) );
	}

	public function test_missing_token_header_is_invalid(): void {
		update_option( 'aura_worker_site_token', Aura_Worker_Security::hash_token( 'x' ) );

		$result = $this->call_private( 'check_aura_token', $this->request_with_token( null ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aura_invalid_token', $result->get_error_code() );
	}

	public function test_throttle_blocks_after_max_failures(): void {
		update_option( 'aura_worker_site_token', Aura_Worker_Security::hash_token( 'x' ) );
		$key = 'aura_worker_tokfail_' . md5( $_SERVER['REMOTE_ADDR'] );
		set_transient( $key, Aura_Worker_Security::MAX_TOKEN_FAILURES, 900 );

		$result = $this->call_private( 'check_aura_token', $this->request_with_token( 'whatever' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aura_too_many_attempts', $result->get_error_code() );
		$this->assertSame( 429, $result->get_error_data()['status'] );
	}

	public function test_legacy_raw_token_migrates_to_hash_on_success(): void {
		$raw = 'legacy-plaintext-token';
		update_option( 'aura_worker_site_token', $raw ); // stored raw (pre-hash era).

		$result = $this->call_private( 'check_aura_token', $this->request_with_token( $raw ) );

		$this->assertTrue( $result );
		// Opportunistic migration: the stored value is now the SHA-256 hash.
		$this->assertSame( Aura_Worker_Security::hash_token( $raw ), get_option( 'aura_worker_site_token' ) );
	}

	// --- domain allowlist -----------------------------------------------------

	public function test_domain_allowlist_blocks_unlisted_origin(): void {
		update_option( 'aura_worker_allowed_domains', "app.my-aura.app\n" );
		$req = new WP_REST_Request();
		$req->set_header( 'Origin', 'https://evil.example.com' );

		$result = $this->call_private( 'check_domain_whitelist', $req );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aura_domain_blocked', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_domain_allowlist_allows_listed_origin(): void {
		update_option( 'aura_worker_allowed_domains', "app.my-aura.app\n" );
		$req = new WP_REST_Request();
		$req->set_header( 'Origin', 'https://app.my-aura.app' );

		$this->assertTrue( $this->call_private( 'check_domain_whitelist', $req ) );
	}

	public function test_server_to_server_request_without_origin_is_allowed(): void {
		update_option( 'aura_worker_allowed_domains', "app.my-aura.app\n" );
		// No Origin/Referer header — the token layer still guards the endpoint.
		$this->assertTrue( $this->call_private( 'check_domain_whitelist', new WP_REST_Request() ) );
	}

	// --- token-only run-as ----------------------------------------------------

	public function test_token_only_request_runs_as_admin(): void {
		$raw = 'good-token';
		update_option( 'aura_worker_site_token', Aura_Worker_Security::hash_token( $raw ) );
		$GLOBALS['_logged_in'] = false;      // no app-password user.
		$GLOBALS['_admins']    = array( 7 ); // one administrator exists.

		$result = $this->security->validate_request( $this->request_with_token( $raw ) );

		$this->assertTrue( $result );
		$this->assertSame( 7, $GLOBALS['_current_user'] );
		// Forensics hook fired for the token-only run-as.
		$tags = array_column( $GLOBALS['_did_actions'], 'tag' );
		$this->assertContains( 'aura_worker_token_run_as', $tags );
	}

	public function test_token_only_request_without_admin_returns_500(): void {
		$raw = 'good-token';
		update_option( 'aura_worker_site_token', Aura_Worker_Security::hash_token( $raw ) );
		$GLOBALS['_logged_in'] = false;
		$GLOBALS['_admins']    = array(); // no administrator to run as.

		$result = $this->security->validate_request( $this->request_with_token( $raw ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aura_not_configured', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
	}

	public function test_app_password_session_is_not_overridden(): void {
		$raw = 'good-token';
		update_option( 'aura_worker_site_token', Aura_Worker_Security::hash_token( $raw ) );
		$GLOBALS['_logged_in'] = true; // app-password user already authenticated.

		$result = $this->security->validate_request( $this->request_with_token( $raw ) );

		$this->assertTrue( $result );
		// run-as must NOT fire when a user is already logged in.
		$this->assertSame( 0, $GLOBALS['_current_user'] );
	}
}
