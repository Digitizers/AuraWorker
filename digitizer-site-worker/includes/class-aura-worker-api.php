<?php
/**
 * REST API handler for SiteAgent.
 *
 * Registers and handles all /wp-json/aura/v1/ endpoints.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Worker_API {

	/**
	 * REST API namespace (v1).
	 *
	 * @var string
	 */
	const NAMESPACE = 'aura/v1';

	/**
	 * REST API namespace (v2).
	 *
	 * @var string
	 */
	const NAMESPACE_V2 = 'aura/v2';

	/**
	 * Security handler.
	 *
	 * @var Aura_Worker_Security
	 */
	private $security;

	/**
	 * Updater handler.
	 *
	 * @var Aura_Worker_Updater
	 */
	private $updater;

	/**
	 * Constructor.
	 *
	 * @param Aura_Worker_Security $security Security handler instance.
	 */
	public function __construct( Aura_Worker_Security $security ) {
		$this->security = $security;
		$this->updater  = new Aura_Worker_Updater();
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes() {
		// Magic link: receive site token from Aura dashboard (public — validated by transient).
		register_rest_route( self::NAMESPACE, '/connect', array(
			'methods'             => 'POST',
			'callback'            => array( new Aura_Worker_Magic_Link(), 'handle_connect' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'magic_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => __( 'One-time magic link ID generated during the connect flow.', 'digitizer-site-worker' ),
				),
				'token' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => __( 'Site token issued by the Aura dashboard.', 'digitizer-site-worker' ),
				),
				'dashboard_url' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
					'description'       => __( 'Base URL of the Aura dashboard that issued the token.', 'digitizer-site-worker' ),
				),
				'timestamp' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'description'       => __( 'Unix timestamp of the callback, for replay protection.', 'digitizer-site-worker' ),
				),
				'signature' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => __( 'HMAC-SHA256 signature of the connect payload.', 'digitizer-site-worker' ),
				),
				'grant_pubkey' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => __( 'Optional base64 Ed25519 gateway public key to provision for approval-grant verification. Covered by the signature.', 'digitizer-site-worker' ),
				),
			),
		) );

		// Status & health check (read-only).
		register_rest_route( self::NAMESPACE, '/status', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_status' ),
			'permission_callback' => array( $this->security, 'check_read_permission' ),
		) );

		// Available updates (read-only).
		register_rest_route( self::NAMESPACE, '/updates', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_updates' ),
			'permission_callback' => array( $this->security, 'check_read_permission' ),
		) );

		// Update core.
		register_rest_route( self::NAMESPACE, '/update/core', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'update_core' ),
			'permission_callback' => array( $this->security, 'check_update_core_permission' ),
		) );

		// Update a plugin.
		register_rest_route( self::NAMESPACE, '/update/plugin', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'update_plugin' ),
			'permission_callback' => array( $this->security, 'check_update_plugins_permission' ),
			'args'                => array(
				'plugin' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => function( $value ) {
						return is_string( $value ) && preg_match( '/^[a-zA-Z0-9_\-]+\/[a-zA-Z0-9_\-]+\.php$/', $value );
					},
					'description'       => __( 'Plugin file path (e.g., akismet/akismet.php)', 'digitizer-site-worker' ),
				),
			),
		) );

		// Update a theme.
		register_rest_route( self::NAMESPACE, '/update/theme', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'update_theme' ),
			'permission_callback' => array( $this->security, 'check_update_themes_permission' ),
			'args'                => array(
				'theme' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => function( $value ) {
						return is_string( $value ) && preg_match( '/^[a-zA-Z0-9_\-]+$/', $value );
					},
					'description'       => __( 'Theme stylesheet slug', 'digitizer-site-worker' ),
				),
			),
		) );

		// Update translations.
		register_rest_route( self::NAMESPACE, '/update/translations', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'update_translations' ),
			'permission_callback' => array( $this->security, 'check_update_core_permission' ),
		) );

		// Database migration status (read-only).
		register_rest_route( self::NAMESPACE, '/database-status', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_database_status' ),
			'permission_callback' => array( $this->security, 'check_read_permission' ),
		) );

		// Self-update SiteAgent from a zip URL.
		register_rest_route( self::NAMESPACE, '/self-update', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'self_update' ),
			'permission_callback' => array( $this->security, 'check_update_plugins_permission' ),
			'args'                => array(
				'zip_url' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
					'validate_callback' => function( $value ) {
						// Single source of truth: defer to the same allowlist the
							// handler enforces, so the aura_worker_self_update_allowed_hosts
							// filter can actually extend the permitted sources instead of
							// being shadowed by a hard-coded pattern here.
							return is_string( $value ) && $this->is_allowed_self_update_url( $value );
					},
					'description'       => __( 'GitHub release zip URL for SiteAgent.', 'digitizer-site-worker' ),
				),
			),
		) );

		// Update database tables (core or plugin-specific).
		register_rest_route( self::NAMESPACE, '/update/database', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'update_database' ),
			'permission_callback' => array( $this->security, 'check_update_core_permission' ),
			'args'                => array(
				'plugin' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => __( 'Plugin migration key (e.g., elementor, woocommerce). Omit for core wp_upgrade.', 'digitizer-site-worker' ),
				),
			),
		) );

		// v2: Chunked batch plugin update with health-check auto-rollback.
		register_rest_route( self::NAMESPACE_V2, '/update/batch', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'batch_update_plugins' ),
			'permission_callback' => array( $this->security, 'check_update_plugins_permission' ),
			'args'                => array(
				'plugins' => array(
					'required'    => true,
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Array of plugin file paths (e.g. ["akismet/akismet.php"]).', 'digitizer-site-worker' ),
				),
				'chunk_size' => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 5,
					'minimum'           => 1,
					'sanitize_callback' => 'absint',
					'description'       => __( 'Number of plugins to process per chunk (default 5).', 'digitizer-site-worker' ),
				),
				'create_backup' => array(
					'required'    => false,
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'Whether to backup each plugin before updating (default true).', 'digitizer-site-worker' ),
				),
			),
		) );

		// v2: Health check (HTTP, PHP errors, WSOD, DB).
		register_rest_route( self::NAMESPACE_V2, '/health', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_health' ),
			'permission_callback' => array( $this->security, 'check_read_permission' ),
		) );

		// v2: Plugin rollback.
		register_rest_route( self::NAMESPACE_V2, '/rollback/(?P<plugin>[a-z0-9\-]+)', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rollback_plugin' ),
			'permission_callback' => array( $this->security, 'check_update_plugins_permission' ),
			'args'                => array(
				'plugin' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => __( 'Plugin folder slug to roll back.', 'digitizer-site-worker' ),
				),
				'backup_path' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => __( 'Absolute path to a specific backup zip. Omit to use the most recent backup.', 'digitizer-site-worker' ),
				),
			),
		) );

		// v2: Snapshots — capture-before-write for files/options (the reversal
		// substrate the Governed Power Tools use; created before a power write,
		// restored to undo it).
		register_rest_route( self::NAMESPACE_V2, '/snapshot', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'create_snapshot' ),
			'permission_callback' => array( $this->security, 'check_admin_permission' ),
			'args'                => array(
				'kind' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => __( 'Snapshot kind: "file" or "option".', 'digitizer-site-worker' ),
				),
				'target' => array(
					'required'    => true,
					'type'        => 'string',
					'description' => __( 'File path (kind=file) or option name (kind=option).', 'digitizer-site-worker' ),
				),
			),
		) );

		register_rest_route( self::NAMESPACE_V2, '/snapshot/restore', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'restore_snapshot' ),
			'permission_callback' => array( $this->security, 'check_admin_permission' ),
			'args'                => array(
				'id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => __( 'Snapshot id to restore.', 'digitizer-site-worker' ),
				),
			),
		) );

		register_rest_route( self::NAMESPACE_V2, '/snapshots', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_snapshots' ),
			'permission_callback' => array( $this->security, 'check_read_permission' ),
		) );

	}

	/**
	 * GET /aura/v1/status
	 *
	 * Returns comprehensive site health information.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response Site status data.
	 */
	public function get_status( $request ) {
		global $wpdb;

		// Get active theme.
		$theme = wp_get_theme();

		// Get all plugins.
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins    = get_plugins();
		$active_plugins = get_option( 'active_plugins', array() );

		$plugins = array();
		foreach ( $all_plugins as $file => $data ) {
			$plugins[] = array(
				'file'    => $file,
				'name'    => $data['Name'],
				'version' => $data['Version'],
				'active'  => in_array( $file, $active_plugins, true ),
				'slug'    => dirname( $file ),
			);
		}

		// Get WordPress environment info.
		$status = array(
			'aura_worker_version' => AURA_WORKER_VERSION,
			'wp_version'          => get_bloginfo( 'version' ),
			'php_version'         => phpversion(),
			'mysql_version'       => $wpdb->db_version(),
			'db_version'          => get_option( 'db_version' ),
			'site_url'            => get_site_url(),
			'home_url'            => get_home_url(),
			'is_multisite'        => is_multisite(),
			'locale'              => get_locale(),
			'timezone'            => wp_timezone_string(),
			'memory_limit'        => WP_MEMORY_LIMIT,
			'max_upload_size'     => wp_max_upload_size(),
			'debug_mode'          => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'theme'               => array(
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
				'slug'    => $theme->get_stylesheet(),
				'parent'  => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
			),
			'plugins'             => $plugins,
			'plugin_count'        => array(
				'total'  => count( $all_plugins ),
				'active' => count( $active_plugins ),
			),
			'db_prefix'           => $wpdb->prefix,
			'db_tables'           => count( $wpdb->get_results( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			'disk_usage'          => $this->get_disk_usage(),
			'server_software'     => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
			'timestamp'           => gmdate( 'c' ),
		);

		return rest_ensure_response( $status );
	}

	/**
	 * GET /aura/v1/updates
	 *
	 * Returns all available updates. Uses cached data by default.
	 * Add ?refresh=1 to force fresh check (may fail on low-memory servers).
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response Available updates.
	 */
	public function get_updates( $request ) {
		$refresh = (bool) $request->get_param( 'refresh' );
		$updates = $this->updater->get_available_updates( $refresh );
		return rest_ensure_response( $updates );
	}

	/**
	 * POST /aura/v1/update/core
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Update result, or WP_Error(403) if a grant is required.
	 */
	public function update_core( $request ) {
		$guard = Aura_Worker_Grant::require_for( $request, 'wp.update.core', array() );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		// No narrower resource in the vocabulary: a freeze catches this, and
		// nothing else can. (Spec §11 keeps theme/db types out until asked for.)
		$rule = Aura_Worker_Rules::guard_rest( array( array( 'type' => 'site', 'id' => '*' ) ), 'wp.update.core' );
		if ( is_wp_error( $rule ) ) {
			return $rule;
		}

		$result = $this->updater->update_core();
		$status = $result['success'] ? 200 : 500;
		return new WP_REST_Response( Aura_Worker_Rules::with_warnings( $result ), $status );
	}

	/**
	 * POST /aura/v1/update/plugin
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Update result, or WP_Error(403) if a grant is required.
	 */
	public function update_plugin( $request ) {
		$plugin_file = $request->get_param( 'plugin' );

		$guard = Aura_Worker_Grant::require_for( $request, 'wp.update.plugin', array( 'plugin' => $plugin_file ) );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		// Operator rules. This handler bypasses execute_tool() (it calls the
		// updater directly), so it enforces here; see Aura_Worker_Rules for why
		// there are exactly two enforcement points and this is the second.
		$rule = Aura_Worker_Rules::guard_rest(
			array( array( 'type' => 'plugin', 'id' => Aura_Worker_Rules::plugin_slug( $plugin_file ) ) ),
			'wp.update.plugin'
		);
		if ( is_wp_error( $rule ) ) {
			return $rule;
		}

		// Validate plugin exists.
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins = get_plugins();

		if ( ! isset( $all_plugins[ $plugin_file ] ) ) {
			return new WP_REST_Response( Aura_Worker_Rules::with_warnings( array(
				'success' => false,
				'error'   => __( 'Plugin not found.', 'digitizer-site-worker' ),
			) ), 404 );
		}

		$result = $this->updater->update_plugin( $plugin_file );
		$status = $result['success'] ? 200 : 500;
		return new WP_REST_Response( Aura_Worker_Rules::with_warnings( $result ), $status );
	}

	/**
	 * POST /aura/v1/update/theme
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Update result, or WP_Error(403) if a grant is required.
	 */
	public function update_theme( $request ) {
		$theme_slug = $request->get_param( 'theme' );

		$guard = Aura_Worker_Grant::require_for( $request, 'wp.update.theme', array( 'theme' => $theme_slug ) );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		// No narrower resource in the vocabulary: a freeze catches this, and
		// nothing else can. (Spec §11 keeps theme/db types out until asked for.)
		$rule = Aura_Worker_Rules::guard_rest( array( array( 'type' => 'site', 'id' => '*' ) ), 'wp.update.theme' );
		if ( is_wp_error( $rule ) ) {
			return $rule;
		}

		// Validate theme exists.
		$theme = wp_get_theme( $theme_slug );
		if ( ! $theme->exists() ) {
			return new WP_REST_Response( Aura_Worker_Rules::with_warnings( array(
				'success' => false,
				'error'   => __( 'Theme not found.', 'digitizer-site-worker' ),
			) ), 404 );
		}

		$result = $this->updater->update_theme( $theme_slug );
		$status = $result['success'] ? 200 : 500;
		return new WP_REST_Response( Aura_Worker_Rules::with_warnings( $result ), $status );
	}

	/**
	 * POST /aura/v1/update/translations
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Update result, or WP_Error(403) if a grant is required.
	 */
	public function update_translations( $request ) {
		$guard = Aura_Worker_Grant::require_for( $request, 'wp.update.translations', array() );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		// No narrower resource in the vocabulary: a freeze catches this, and
		// nothing else can. (Spec §11 keeps theme/db types out until asked for.)
		$rule = Aura_Worker_Rules::guard_rest( array( array( 'type' => 'site', 'id' => '*' ) ), 'wp.update.translations' );
		if ( is_wp_error( $rule ) ) {
			return $rule;
		}

		$result = $this->updater->update_translations();
		$status = $result['success'] ? 200 : 500;
		return new WP_REST_Response( Aura_Worker_Rules::with_warnings( $result ), $status );
	}

	/**
	 * GET /aura/v1/database-status
	 *
	 * Returns pending database migration status for detected plugins.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response Database migration status.
	 */
	public function get_database_status( $request ) {
		$status = $this->updater->get_database_status();
		return rest_ensure_response( $status );
	}

	/**
	 * POST /aura/v1/self-update
	 *
	 * Updates the SiteAgent plugin from a GitHub release zip URL.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Update result with version info, or WP_Error(403) if a grant is required.
	 */
	public function self_update( $request ) {
		$zip_url = $request->get_param( 'zip_url' );
		$sha256  = (string) $request->get_param( 'sha256' );

		// Bind sha256 into the grant when the gateway supplied it, so the Ed25519
		// signature covers the expected bytes too — the grant then can't be spent
		// against a different digest. Absent → { zip_url } only (back-compat with
		// gateways/releases that provide no digest).
		$grant_params = ( '' !== $sha256 )
			? array( 'zip_url' => $zip_url, 'sha256' => $sha256 )
			: array( 'zip_url' => $zip_url );
		$guard = Aura_Worker_Grant::require_for( $request, 'wp.self_update', $grant_params );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		// No narrower resource in the vocabulary: a freeze catches this, and
		// nothing else can. (Spec §11 keeps theme/db types out until asked for.)
		$rule = Aura_Worker_Rules::guard_rest( array( array( 'type' => 'site', 'id' => '*' ) ), 'wp.self_update' );
		if ( is_wp_error( $rule ) ) {
			return $rule;
		}

		// Source allowlist: only install self-update zips from the official repo.
		// Bounds a signed grant to a trusted source, so even an approved
		// self-update can't be pointed at attacker-hosted code.
		if ( ! $this->is_allowed_self_update_url( $zip_url ) ) {
			return new WP_REST_Response( Aura_Worker_Rules::with_warnings( array(
				'success' => false,
				'error'   => __( 'Self-update source not allowed.', 'digitizer-site-worker' ),
			) ), 400 );
		}

		$result = $this->updater->self_update( $zip_url, $sha256 );
		$status = $result['success'] ? 200 : 500;
		return new WP_REST_Response( Aura_Worker_Rules::with_warnings( $result ), $status );
	}

	/**
	 * Whether a self-update zip URL is from an allowlisted source.
	 *
	 * Defaults to the official GitHub repo release-download path
	 * (`github.com/Digitizers/SiteAgent/`) over HTTPS — the exact form the Aura
	 * gateway sends (a GitHub release `browser_download_url`). GitHub 302-redirects
	 * that URL to its asset CDN, but WordPress's HTTP layer follows the redirect
	 * internally, so the CDN host is never itself a `zip_url` input and does not
	 * need allowlisting. Override via the `aura_worker_self_update_allowed_hosts`
	 * filter (host => required path prefix, '' means any path on that host).
	 *
	 * @param string $url Candidate zip URL.
	 * @return bool
	 */
	private function is_allowed_self_update_url( $url ) {
		$url = (string) $url;
		if ( 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
			return false;
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		// Reject dot-segment traversal (raw OR percent-encoded). An HTTP transport
		// may normalize `..` before fetching, so a URL like
		// `/Digitizers/SiteAgent/releases/download/../../attacker/evil/x.zip` would
		// otherwise pass the prefix check but be fetched from another repo.
		$lower_path = strtolower( $path );
		if ( false !== strpos( $path, '..' )
			|| false !== strpos( rawurldecode( $path ), '..' )
			|| false !== strpos( $lower_path, '%2e' )
			|| false !== strpos( $lower_path, '%2f' )
			|| false !== strpos( $lower_path, '%5c' ) ) {
			return false;
		}

		// Only ever install a .zip — never an archive tarball or arbitrary asset.
		if ( '.zip' !== strtolower( substr( $path, -4 ) ) ) {
			return false;
		}

		// Default: the official repo's RELEASE-DOWNLOAD path only — not
		// /archive/… branch/tag tarballs, which would let a grant approve
		// arbitrary repo contents rather than a published release.
		$allowed = array(
			'github.com' => '/Digitizers/SiteAgent/releases/download/',
		);
		$allowed = apply_filters( 'aura_worker_self_update_allowed_hosts', $allowed );

		if ( ! isset( $allowed[ $host ] ) ) {
			return false;
		}
		$prefix = (string) $allowed[ $host ];
		if ( '' !== $prefix && 0 !== strpos( $path, $prefix ) ) {
			return false;
		}
		return true;
	}

	/**
	 * POST /aura/v1/update/database
	 *
	 * Runs core wp_upgrade or a plugin-specific database migration.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Update result, or WP_Error(403) if a grant is required.
	 */
	public function update_database( $request ) {
		$plugin = $request->get_param( 'plugin' );

		// Core DB optimization sends no target (binds {}); a specific plugin
		// migration binds { plugin }. Both must be individually approvable.
		$grant_params = ( null === $plugin || '' === $plugin ) ? array() : array( 'plugin' => $plugin );
		$guard        = Aura_Worker_Grant::require_for( $request, 'wp.update.database', $grant_params );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		// No narrower resource in the vocabulary: a freeze catches this, and
		// nothing else can. (Spec §11 keeps theme/db types out until asked for.)
		$rule = Aura_Worker_Rules::guard_rest( array( array( 'type' => 'site', 'id' => '*' ) ), 'wp.update.database' );
		if ( is_wp_error( $rule ) ) {
			return $rule;
		}

		$result = $this->updater->update_database( $plugin );
		$status = $result['success'] ? 200 : 500;
		return new WP_REST_Response( Aura_Worker_Rules::with_warnings( $result ), $status );
	}

	/**
	 * POST /aura/v2/update/batch
	 *
	 * Processes plugins in chunks, backing up and health-checking each one.
	 * Automatically rolls back if the health check fails after an update.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Batch update summary and per-plugin results, or WP_Error(403) if a grant is required.
	 */
	public function batch_update_plugins( $request ) {
		$plugins       = $request->get_param( 'plugins' );
		$chunk_size    = $request->get_param( 'chunk_size' ) ?? 5;
		$create_backup = $request->get_param( 'create_backup' ) ?? true;

		if ( empty( $plugins ) || ! is_array( $plugins ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => __( 'No plugins provided.', 'digitizer-site-worker' ),
			), 400 );
		}

		// Sanitize + validate the plugin file paths FIRST, then bind the grant over
		// the EXACT list that will be executed. Binding post-sanitize matters: a
		// value that normalizes into a different valid path must not be able to
		// slip past the exact-parameter grant. The gateway sends already-valid
		// paths, so sanitize is a no-op there and the bound hash still matches.
		$plugins = array_values( array_filter( array_map( 'sanitize_text_field', $plugins ), function( $p ) {
			return preg_match( '/^[a-zA-Z0-9_\-]+\/[a-zA-Z0-9_\-]+\.php$/', $p );
		} ) );

		if ( empty( $plugins ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => __( 'No valid plugin file paths provided.', 'digitizer-site-worker' ),
			), 400 );
		}

		// Bind the whole effective payload — including the safety options — so an
		// approved batch can't be replayed with create_backup flipped off.
		$guard = Aura_Worker_Grant::require_for(
			$request,
			'wp.update.batch',
			array(
				'plugins'       => $plugins,
				'chunk_size'    => (int) $chunk_size,
				'create_backup' => (bool) $create_backup,
			)
		);
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		// A batch touches the site (it is a maintenance operation) AND each
		// named plugin, so a freeze and a per-plugin rule both apply.
		$touches = array( array( 'type' => 'site', 'id' => '*' ) );
		foreach ( (array) $plugins as $p ) {
			$touches[] = array( 'type' => 'plugin', 'id' => Aura_Worker_Rules::plugin_slug( $p ) );
		}
		$rule = Aura_Worker_Rules::guard_rest( $touches, 'wp.update.batch' );
		if ( is_wp_error( $rule ) ) {
			return $rule;
		}

		$result = $this->updater->batch_update_plugins( $plugins, (int) $chunk_size, (bool) $create_backup );
		return new WP_REST_Response( Aura_Worker_Rules::with_warnings( array_merge( array( 'success' => true ), $result ) ), 200 );
	}

	/**
	 * GET /aura/v2/health
	 *
	 * Runs HTTP, PHP error log, white-screen, and database checks.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response Health check results.
	 */
	public function get_health( $request ) {
		$health = new Aura_Worker_Health();
		$result = $health->run_health_check();
		return rest_ensure_response( $result );
	}

	/**
	 * POST /aura/v2/rollback/{plugin}
	 *
	 * Backs up (if needed) and restores a plugin from a backup zip.
	 * If no backup_path is supplied, the most recent backup for the plugin is used.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Rollback result, or WP_Error(403) if a grant is required.
	 */
	public function rollback_plugin( $request ) {
		$plugin_slug = $request->get_param( 'plugin' );
		$backup_path = $request->get_param( 'backup_path' );

		// Bind BOTH the plugin and the caller-supplied backup_path: the handler
		// passes a request-provided backup_path straight to restore_plugin(), so
		// a grant approved for one backup must not be spent to restore a
		// different zip. An empty backup_path (server picks the most recent) binds
		// as '' and must be signed the same way by the gateway.
		$guard = Aura_Worker_Grant::require_for(
			$request,
			'wp.rollback',
			array(
				'plugin'      => $plugin_slug,
				'backup_path' => (string) $backup_path,
			)
		);
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$rule = Aura_Worker_Rules::guard_rest(
			array( array( 'type' => 'plugin', 'id' => Aura_Worker_Rules::plugin_slug( (string) $plugin_slug ) ) ),
			'wp.rollback'
		);
		if ( is_wp_error( $rule ) ) {
			return $rule;
		}

		$rollback = new Aura_Worker_Rollback();

		// If no specific backup path given, use the most recent backup.
		if ( empty( $backup_path ) ) {
			$backups = $rollback->list_backups( $plugin_slug );
			if ( empty( $backups ) ) {
				return new WP_REST_Response( Aura_Worker_Rules::with_warnings( array(
					'success' => false,
					'error'   => __( 'No backups found for this plugin.', 'digitizer-site-worker' ),
				) ), 404 );
			}
			$backup_path = $backups[0]['path'];
		}

		$result = $rollback->restore_plugin( $plugin_slug, $backup_path );
		$status = $result['success'] ? 200 : 500;
		return new WP_REST_Response( Aura_Worker_Rules::with_warnings( $result ), $status );
	}

	/**
	 * Resolve + jail a snapshot file target to wp-content, refusing wp-config.php.
	 *
	 * @param string $target Requested file path.
	 * @return string|WP_Error Safe real path, or a WP_Error to refuse.
	 */
	private function validate_snapshot_file_target( $target ) {
		$target = (string) $target;
		if ( '' === $target || false !== strpos( $target, "\0" ) ) {
			return new WP_Error( 'aura_invalid_target', __( 'Invalid file target.', 'digitizer-site-worker' ) );
		}

		$real = realpath( $target );
		if ( false === $real || ! is_file( $real ) ) {
			return new WP_Error( 'aura_not_found', __( 'File not found.', 'digitizer-site-worker' ) );
		}
		if ( 'wp-config.php' === strtolower( basename( $real ) ) ) {
			return new WP_Error( 'aura_refused', __( 'Refused: wp-config.php cannot be snapshotted.', 'digitizer-site-worker' ) );
		}

		$root = realpath( WP_CONTENT_DIR );
		if ( false === $root ) {
			return new WP_Error( 'aura_no_root', __( 'Content directory not found.', 'digitizer-site-worker' ) );
		}
		$in_jail = ( $real === $root ) || ( 0 === strpos( $real, rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR ) );
		if ( ! $in_jail ) {
			return new WP_Error( 'aura_outside_jail', __( 'Refused: path is outside wp-content.', 'digitizer-site-worker' ) );
		}

		return $real;
	}

	/**
	 * POST /aura/v2/snapshot
	 *
	 * Capture a file or option before a power write, so it can be reversed.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Result, or WP_Error(403) if a grant is required.
	 */
	public function create_snapshot( $request ) {
		$kind   = $request->get_param( 'kind' );
		$target = $request->get_param( 'target' );

		// Deliberately NOT rule-guarded: a snapshot captures state and changes
		// nothing, and a freeze must not refuse the safety net during exactly the
		// window it exists for. RulesRestCoverageTest lists this as the one
		// exemption.
		$guard = Aura_Worker_Grant::require_for(
			$request,
			'wp.snapshot.create',
			array( 'kind' => $kind, 'target' => $target )
		);
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$snapshots = new Aura_Worker_Snapshots();

		if ( 'file' === $kind ) {
			// Jail the file target to wp-content and refuse wp-config.php — otherwise
			// a caller could snapshot a sensitive absolute path (e.g. wp-config.php)
			// into the snapshots dir and fetch the payload, bypassing the read jail.
			$jail = $this->validate_snapshot_file_target( $target );
			if ( is_wp_error( $jail ) ) {
				return new WP_REST_Response( array( 'success' => false, 'error' => $jail->get_error_message() ), 400 );
			}
			$result = $snapshots->snapshot_file( $jail );
		} elseif ( 'option' === $kind ) {
			$result = $snapshots->snapshot_option( $target );
		} else {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => __( 'Unknown snapshot kind. Use "file" or "option".', 'digitizer-site-worker' ),
			), 400 );
		}

		$status = $result['success'] ? 200 : 400;
		return new WP_REST_Response( $result, $status );
	}

	/**
	 * POST /aura/v2/snapshot/restore
	 *
	 * Restore state captured by a prior snapshot (undo a power write).
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Result, or WP_Error(403) if a grant is required.
	 */
	public function restore_snapshot( $request ) {
		$id = $request->get_param( 'id' );

		$guard = Aura_Worker_Grant::require_for( $request, 'wp.snapshot.restore', array( 'id' => $id ) );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		// No narrower resource in the vocabulary: a freeze catches this, and
		// nothing else can. (Spec §11 keeps theme/db types out until asked for.)
		$rule = Aura_Worker_Rules::guard_rest( array( array( 'type' => 'site', 'id' => '*' ) ), 'wp.snapshot.restore' );
		if ( is_wp_error( $rule ) ) {
			return $rule;
		}

		$snapshots = new Aura_Worker_Snapshots();
		$result    = $snapshots->restore( $id );

		$status = $result['success'] ? 200 : 404;
		return new WP_REST_Response( Aura_Worker_Rules::with_warnings( $result ), $status );
	}

	/**
	 * GET /aura/v2/snapshots
	 *
	 * List stored snapshots (newest first).
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response
	 */
	public function list_snapshots( $request ) {
		$snapshots = new Aura_Worker_Snapshots();
		$list      = $snapshots->list_snapshots();

		return rest_ensure_response( array(
			'snapshots' => $list,
			'count'     => count( $list ),
		) );
	}

	/**
	 * Calculate disk usage of the WordPress installation.
	 *
	 * @return string Human-readable disk usage.
	 */
	private function get_disk_usage() {
		$uploads_dir = wp_get_upload_dir();
		$upload_path = $uploads_dir['basedir'];

		if ( ! is_dir( $upload_path ) ) {
			return 'unknown';
		}

		// Only check uploads directory size (fast).
		$size = 0;
		$iter = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $upload_path, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY,
			RecursiveIteratorIterator::CATCH_GET_CHILD
		);

		foreach ( $iter as $file ) {
			if ( $file->isFile() ) {
				$size += $file->getSize();
			}
		}

		return size_format( $size );
	}
}
