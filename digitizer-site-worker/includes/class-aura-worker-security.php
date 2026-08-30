<?php
/**
 * Security handler for SiteAgent.
 *
 * Implements three layers of authentication:
 * 1. WordPress Application Password (Basic Auth)
 * 2. Aura Site Token (X-Aura-Token header)
 * 3. IP Whitelist (optional)
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Worker_Security {

	/**
	 * Max failed token attempts per client IP before requests are throttled.
	 */
	const MAX_TOKEN_FAILURES = 10;

	/**
	 * Window (seconds) over which failed token attempts are counted.
	 */
	const TOKEN_FAILURE_WINDOW = 900; // 15 minutes.

	/**
	 * The UUID of the Application Password that authenticated THIS request, or
	 * null when the request carried no Application Password (a token-only call,
	 * or a cookie-authenticated one). Static, because WordPress hands it over
	 * once, on a hook, long before any handler runs.
	 *
	 * Load-bearing for the unbind (#434, spec §2.3): the marker must carry
	 * every credential that authenticated an unbind BEFORE Phase B revokes
	 * them, or the core-REST seam would stop recognising a request made with a
	 * password it never recorded — a manually connected site, or one whose
	 * password Aura replaced through its PATCH, was never minted by SiteAgent
	 * and so is not in the plugin's own bookkeeping option.
	 *
	 * @var string|null
	 */
	private static $authenticating_uuid = null;

	/**
	 * The user WordPress said that Application Password authenticated as —
	 * captured from the SAME hook call that named the uuid, never re-derived
	 * later from `get_current_user_id()`.
	 *
	 * The two are not the same fact (#434 Task 4, C4). `get_current_user_id()`
	 * is whatever the request's current user is by the time a route callback
	 * runs, and anything on `determine_current_user` after priority 20, or any
	 * `wp_set_current_user()` on `init` / `rest_api_init` /
	 * `rest_authentication_errors` — user-switching, SSO, membership "view as"
	 * and impersonation plugins all do this routinely — moves it. The unbind
	 * marker records this owner as KNOWLEDGE, and Phase B does exactly one
	 * lookup against it: a wrong owner answering "not there" is how the site
	 * token gets deleted beside a live administrator credential. So the
	 * identity is taken from the writer that has it, and never re-derived.
	 *
	 * Null whenever the hook did not fire, or fired with something that is not
	 * a WP_User with a positive ID: an explicit unknown, never 0.
	 *
	 * @var int|null
	 */
	private static $authenticating_user = null;

	/**
	 * Register the hook that captures the authenticating Application Password.
	 * Called from Aura_Worker::init(); WordPress fires the hook during REST
	 * authentication, which is earlier than any route callback.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'application_password_did_authenticate', array( __CLASS__, 'capture_app_password' ), 10, 2 );
	}

	/**
	 * Record the authenticating password's UUID. Only the UUID — never the
	 * password, never the hash: the UUID is an opaque identifier WordPress
	 * already stores in user meta, and it is all the marker needs to recognise
	 * the credential again.
	 *
	 * The USER travels with it (#434 Task 4, C4). WordPress hands this hook the
	 * authoritative pairing — this password, that user — and it is the only
	 * place the pairing exists as a statement rather than as a guess about the
	 * request. It is recorded here and used verbatim; nothing downstream may
	 * re-derive it from `get_current_user_id()`.
	 *
	 * Only a WP_User with a positive ID is an identity. Anything else — a
	 * bare int, a stdClass, false, a WP_Error — is an explicit unknown; a cast
	 * would turn a shape this code does not understand into a user id, which
	 * is exactly the class of mistake rounds 1-4 of #434 are about.
	 *
	 * @param WP_User|mixed $user The authenticated user (WordPress passes it).
	 * @param array|mixed   $item The application password item, with its uuid.
	 * @return void
	 */
	public static function capture_app_password( $user, $item ) {
		$uuid                        = is_array( $item ) && ! empty( $item['uuid'] ) ? (string) $item['uuid'] : null;
		self::$authenticating_uuid   = $uuid;
		// Paired: an item with no readable uuid names no password, so the user
		// beside it identifies nothing either.
		self::$authenticating_user   = ( null !== $uuid && $user instanceof WP_User && (int) $user->ID > 0 ) ? (int) $user->ID : null;
	}

	/**
	 * The UUID of the Application Password that authenticated this request.
	 *
	 * @return string|null
	 */
	public static function authenticating_app_password_uuid() {
		return self::$authenticating_uuid;
	}

	/**
	 * The user WordPress named beside that Application Password, or null when
	 * no hook fired (or it named nothing usable). Never 0 — an owner that
	 * names nobody must not be written where a user id is read (#434 C1-C4).
	 *
	 * @since 2.13.0
	 *
	 * @return int|null
	 */
	public static function authenticating_app_password_user() {
		return self::$authenticating_user;
	}

	/**
	 * Set the captured UUID directly.
	 *
	 * @internal Tests only — production sets this from the WordPress hook above.
	 *
	 * @param string|null $uuid The uuid, or null for a token-only request.
	 * @param int|null    $user The user WordPress named beside it, as the real
	 *                          hook does. Omitted (or <= 0) models a uuid that
	 *                          reached the request with no identity attached —
	 *                          an explicit unknown, exactly as production
	 *                          records when the hook did not fire.
	 * @return void
	 */
	public static function _set_authenticating_uuid_for_tests( $uuid, $user = null ) {
		self::$authenticating_uuid = null === $uuid ? null : (string) $uuid;
		self::$authenticating_user = ( null === $uuid || null === $user || (int) $user <= 0 ) ? null : (int) $user;
	}

	/**
	 * Hash a raw site token for storage / comparison.
	 *
	 * Tokens are stored as a SHA-256 hash so a database leak does not expose a
	 * usable bearer credential. The Aura dashboard holds the only raw copy.
	 *
	 * @param string $raw Raw token value.
	 * @return string 64-char lowercase hex SHA-256 digest.
	 */
	public static function hash_token( $raw ) {
		return hash( 'sha256', (string) $raw );
	}

	/**
	 * Whether a stored value is already a SHA-256 hash (vs a legacy raw token).
	 *
	 * @param string $value Stored token value.
	 * @return bool
	 */
	private function is_hashed( $value ) {
		return (bool) preg_match( '/^[0-9a-f]{64}$/', (string) $value );
	}

	/**
	 * Validate an incoming REST API request.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	public function validate_request( $request ) {
		// Layer 1a: Check IP whitelist (if configured).
		$ip_check = $this->check_ip_whitelist();
		if ( is_wp_error( $ip_check ) ) {
			return $ip_check;
		}

		// Layer 1b: Check domain whitelist (if configured).
		$domain_check = $this->check_domain_whitelist( $request );
		if ( is_wp_error( $domain_check ) ) {
			return $domain_check;
		}

		// Layer 2: Verify Aura site token.
		$token_check = $this->check_aura_token( $request );
		if ( is_wp_error( $token_check ) ) {
			return $token_check;
		}

		// Layer 2.5: Token-only authorization. A valid token alone is sufficient
		// to manage the site (the standard site-management model). If no WP user
		// is authenticated on this request (i.e. no application-password Basic
		// auth), run as the connecting administrator so the Layer-3
		// current_user_can() route gates pass. Requests that DID send an
		// app-password keep their own user context untouched.
		if ( ! is_user_logged_in() ) {
			$run_as = $this->resolve_connect_user();
			if ( ! $run_as ) {
				return new WP_Error(
					'aura_not_configured',
					__( 'SiteAgent has no administrator to run as. Reconnect from the Aura dashboard.', 'digitizer-site-worker' ),
					array( 'status' => 500 )
				);
			}
			wp_set_current_user( $run_as );

			/**
			 * Fires when a request is authorized by its Aura site token alone
			 * (no application-password user) and run as an administrator.
			 *
			 * Lets site owners record token-only actions for forensics — the WP
			 * actor is the resolved admin, so without this the audit trail can't
			 * distinguish a token-run-as from an interactive admin action.
			 *
			 * @param int    $run_as The administrator user ID the request runs as.
			 * @param string $route  The REST route being authorized.
			 */
			do_action( 'aura_worker_token_run_as', $run_as, $request->get_route() );
		}

		// Layer 3: WordPress capability is checked by each route's
		// permission_callback (current_user_can()), now satisfied by the run-as
		// admin above for token-only requests.
		return true;
	}

	/**
	 * Check if the request IP is in the allowed list.
	 *
	 * @return bool|WP_Error True if allowed, WP_Error if blocked.
	 */
	private function check_ip_whitelist() {
		$allowed_ips = get_option( 'aura_worker_allowed_ips', '' );

		// If no IPs configured, allow all.
		if ( empty( trim( $allowed_ips ) ) ) {
			return true;
		}

		$allowed = array_filter( array_map( 'trim', explode( "\n", $allowed_ips ) ) );
		$client_ip = $this->get_client_ip();

		if ( ! in_array( $client_ip, $allowed, true ) ) {
			return new WP_Error(
				'aura_ip_blocked',
				__( 'Your IP address is not authorized.', 'digitizer-site-worker' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Check if the request origin domain is in the allowed list.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return bool|WP_Error True if allowed, WP_Error if blocked.
	 */
	private function check_domain_whitelist( $request ) {
		$allowed_domains = get_option( 'aura_worker_allowed_domains', '' );

		// If no domains configured, allow all.
		if ( empty( trim( $allowed_domains ) ) ) {
			return true;
		}

		$allowed = array_filter( array_map( 'trim', explode( "\n", strtolower( $allowed_domains ) ) ) );

		// Check Origin header first, then Referer as fallback.
		$origin  = $request->get_header( 'Origin' );
		$referer = $request->get_header( 'Referer' );

		$request_host = '';
		if ( ! empty( $origin ) ) {
			$parsed = wp_parse_url( $origin );
			$request_host = isset( $parsed['host'] ) ? strtolower( $parsed['host'] ) : '';
		} elseif ( ! empty( $referer ) ) {
			$parsed = wp_parse_url( $referer );
			$request_host = isset( $parsed['host'] ) ? strtolower( $parsed['host'] ) : '';
		}

		// No Origin/Referer header — this is a server-to-server request (e.g. from the
		// Aura dashboard). Allow it through; the token check still protects the endpoint.
		if ( empty( $request_host ) ) {
			return true;
		}

		if ( ! in_array( $request_host, $allowed, true ) ) {
			return new WP_Error(
				'aura_domain_blocked',
				__( 'Your request origin domain is not authorized.', 'digitizer-site-worker' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Verify the Aura site token from request headers.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return bool|WP_Error True if valid, WP_Error if invalid.
	 */
	private function check_aura_token( $request ) {
		$provided_token = (string) $request->get_header( 'X-Aura-Token' );
		$stored_token   = get_option( 'aura_worker_site_token', '' );

		if ( empty( $stored_token ) ) {
			return new WP_Error(
				'aura_not_configured',
				__( 'SiteAgent is not configured. Please set a site token.', 'digitizer-site-worker' ),
				array( 'status' => 500 )
			);
		}

		// Throttle brute-force attempts before doing any comparison.
		$throttle = $this->check_token_throttle();
		if ( is_wp_error( $throttle ) ) {
			return $throttle;
		}

		$valid = false;
		if ( '' !== $provided_token ) {
			if ( $this->is_hashed( $stored_token ) ) {
				// Modern path: stored value is a SHA-256 hash of the token.
				$valid = hash_equals( $stored_token, self::hash_token( $provided_token ) );
			} else {
				// Legacy path: stored value is a raw token from an older version.
				// Compare raw, then opportunistically migrate to a stored hash.
				$valid = hash_equals( $stored_token, $provided_token );
				if ( $valid ) {
					update_option( 'aura_worker_site_token', self::hash_token( $provided_token ) );
				}
			}
		}

		if ( ! $valid ) {
			$this->record_token_failure();
			return new WP_Error(
				'aura_invalid_token',
				__( 'Invalid or missing Aura token.', 'digitizer-site-worker' ),
				array( 'status' => 401 )
			);
		}

		// Successful auth clears the failure counter for this IP.
		delete_transient( $this->token_failure_key() );

		return true;
	}

	/**
	 * Resolve the administrator to run token-only requests as.
	 *
	 * Prefers the stored connecting admin; falls back to the first administrator.
	 * The returned user MUST hold manage_options so a token can never grant more
	 * than an administrator already has.
	 *
	 * @return int User ID, or 0 if no suitable administrator exists.
	 */
	private function resolve_connect_user() {
		$stored = (int) get_option( 'aura_worker_connect_user_id', 0 );
		if ( $stored > 0 && user_can( $stored, 'manage_options' ) ) {
			return $stored;
		}

		$admins = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => 'ID',
			)
		);
		if ( ! empty( $admins ) ) {
			return (int) $admins[0];
		}

		return 0;
	}

	/**
	 * Transient key for tracking failed token attempts from the current IP.
	 *
	 * @return string
	 */
	private function token_failure_key() {
		return 'aura_worker_tokfail_' . md5( $this->get_client_ip() );
	}

	/**
	 * Block the request if this IP has exceeded the failed-attempt threshold.
	 *
	 * @return bool|WP_Error True if under the limit, WP_Error (429) if throttled.
	 */
	private function check_token_throttle() {
		$failures = (int) get_transient( $this->token_failure_key() );
		if ( $failures >= self::MAX_TOKEN_FAILURES ) {
			return new WP_Error(
				'aura_too_many_attempts',
				__( 'Too many failed authentication attempts. Try again later.', 'digitizer-site-worker' ),
				array( 'status' => 429 )
			);
		}
		return true;
	}

	/**
	 * Increment the failed-attempt counter for the current IP.
	 */
	private function record_token_failure() {
		$key      = $this->token_failure_key();
		$failures = (int) get_transient( $key );
		set_transient( $key, $failures + 1, self::TOKEN_FAILURE_WINDOW );
	}

	/**
	 * Get the client IP address.
	 *
	 * @return string Client IP.
	 */
	private function get_client_ip() {
		// Only trust REMOTE_ADDR — proxy headers (X-Forwarded-For, CF-Connecting-IP)
		// are client-controlled and trivially spoofed. Managed hosts and reverse proxies
		// should be configured to set REMOTE_ADDR correctly at the server layer.
		return isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '0.0.0.0';
	}

	/**
	 * Refuse a mutation at an UNBOUND site (#434 Phase A).
	 *
	 * From the moment the marker is written under the site claim, this site is
	 * no longer Aura's to command: every mutation is refused with
	 * 403 aura_site_unbound while reads keep working, and it stays refused
	 * until the site is reconnected. That has to hold against a caller holding
	 * a perfectly valid site token — a stale automation, a queued job, a
	 * retried run — because the credentials outlive the binding by design:
	 * Phase B deletes the token LAST, so there is a window in which the token
	 * still authenticates and the site must still refuse.
	 *
	 * Called by every mutating permission callback AFTER validate_request()
	 * has succeeded, never before it: a caller who cannot prove it holds the
	 * token gets the token layer's answer and learns nothing about whether
	 * this site is bound.
	 *
	 * Uses is_set(), not is_set_strict(), deliberately. is_set() answers TRUE
	 * when the marker cannot be READ, and at a refusal boundary that is the
	 * only defensible answer: an unreadable marker is not a clean site, and a
	 * database blip must not re-open every write on a site that was
	 * disconnected. is_set_strict() exists for callers that need to tell a
	 * failed read from a clean one; here the two lead to the same refusal, so
	 * the simpler contract is the honest one.
	 *
	 * @since 2.13.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return bool|WP_Error True when the site may still act, WP_Error when it may not.
	 */
	public function refuse_if_unbound( $request ) {
		if ( ! Aura_Worker_Unbind::is_set() ) {
			return true;
		}

		// The unbind envelope — and every retry of it — arrives on /aura/v2/rules,
		// which Task 3 answers from the marker fast path. A site that refused this
		// route could not be told anything, including that it is unbound.
		//
		// Anchored at BOTH ends (round-1 MINOR-3). Right-anchored alone, the
		// exemption also matched '/aura/v1/anything/aura/v2/rules' — unreachable
		// today, because the only registered capture excludes slashes, but an
		// exemption that widens the day someone writes (?P<path>.+) is not an
		// exemption anybody chose.
		$route = (string) $request->get_route();
		if ( preg_match( '#^/aura/v2/rules$#', $route ) ) {
			return true;
		}

		return Aura_Worker_Unbind::refusal();
	}

	/**
	 * Permission callback for REST routes requiring admin access.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return bool|WP_Error True if authorized.
	 */
	public function check_admin_permission( $request ) {
		// First validate Aura-specific security layers.
		$valid = $this->validate_request( $request );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// An unbound site refuses every mutation, however good the credentials.
		$unbound = $this->refuse_if_unbound( $request );
		if ( is_wp_error( $unbound ) ) {
			return $unbound;
		}

		// Then check WordPress capability.
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'aura_insufficient_permissions',
				__( 'You do not have permission to perform this action.', 'digitizer-site-worker' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Permission callback for plugin update routes.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return bool|WP_Error True if authorized.
	 */
	public function check_update_plugins_permission( $request ) {
		$valid = $this->validate_request( $request );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// An unbound site refuses every mutation, however good the credentials.
		$unbound = $this->refuse_if_unbound( $request );
		if ( is_wp_error( $unbound ) ) {
			return $unbound;
		}

		if ( ! current_user_can( 'update_plugins' ) ) {
			return new WP_Error(
				'aura_insufficient_permissions',
				__( 'You do not have permission to update plugins.', 'digitizer-site-worker' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Permission callback for core update routes.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return bool|WP_Error True if authorized.
	 */
	public function check_update_core_permission( $request ) {
		$valid = $this->validate_request( $request );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// An unbound site refuses every mutation, however good the credentials.
		$unbound = $this->refuse_if_unbound( $request );
		if ( is_wp_error( $unbound ) ) {
			return $unbound;
		}

		if ( ! current_user_can( 'update_core' ) ) {
			return new WP_Error(
				'aura_insufficient_permissions',
				__( 'You do not have permission to update WordPress core.', 'digitizer-site-worker' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Permission callback for theme update routes.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return bool|WP_Error True if authorized.
	 */
	public function check_update_themes_permission( $request ) {
		$valid = $this->validate_request( $request );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// An unbound site refuses every mutation, however good the credentials.
		$unbound = $this->refuse_if_unbound( $request );
		if ( is_wp_error( $unbound ) ) {
			return $unbound;
		}

		if ( ! current_user_can( 'update_themes' ) ) {
			return new WP_Error(
				'aura_insufficient_permissions',
				__( 'You do not have permission to update themes.', 'digitizer-site-worker' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Permission callback for read-only routes.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return bool|WP_Error True if authorized.
	 */
	public function check_read_permission( $request ) {
		$valid = $this->validate_request( $request );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'aura_insufficient_permissions',
				__( 'You do not have permission to view this data.', 'digitizer-site-worker' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}
}
