<?php
/**
 * REST API handler for Aura Worker.
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
	 * REST API namespace.
	 *
	 * @var string
	 */
	const NAMESPACE = 'aura/v1';

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
			'permission_callback' => array( $this->security, 'check_admin_permission' ),
		) );

		// Update a plugin.
		register_rest_route( self::NAMESPACE, '/update/plugin', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'update_plugin' ),
			'permission_callback' => array( $this->security, 'check_admin_permission' ),
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
			'permission_callback' => array( $this->security, 'check_admin_permission' ),
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
			'permission_callback' => array( $this->security, 'check_admin_permission' ),
		) );

		// Database migration status (read-only).
		register_rest_route( self::NAMESPACE, '/database-status', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_database_status' ),
			'permission_callback' => array( $this->security, 'check_read_permission' ),
		) );

		// Self-update AuraWorker from a zip URL.
		register_rest_route( self::NAMESPACE, '/self-update', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'self_update' ),
			'permission_callback' => array( $this->security, 'check_admin_permission' ),
			'args'                => array(
				'zip_url' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
					'validate_callback' => function( $value ) {
						return filter_var( $value, FILTER_VALIDATE_URL )
							&& preg_match( '#^https://github\.com/Digitizers/AuraWorker/releases/download/.+\.zip$#', $value );
					},
					'description'       => __( 'GitHub release zip URL for AuraWorker.', 'digitizer-site-worker' ),
				),
			),
		) );

		// Update database tables (core or plugin-specific).
		register_rest_route( self::NAMESPACE, '/update/database', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'update_database' ),
			'permission_callback' => array( $this->security, 'check_admin_permission' ),
			'args'                => array(
				'plugin' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => __( 'Plugin migration key (e.g., elementor, woocommerce). Omit for core wp_upgrade.', 'digitizer-site-worker' ),
				),
			),
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
	 * @return WP_REST_Response Update result.
	 */
	public function update_core( $request ) {
		$result = $this->updater->update_core();
		$status = $result['success'] ? 200 : 500;
		return new WP_REST_Response( $result, $status );
	}

	/**
	 * POST /aura/v1/update/plugin
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response Update result.
	 */
	public function update_plugin( $request ) {
		$plugin_file = $request->get_param( 'plugin' );

		// Validate plugin exists.
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins = get_plugins();

		if ( ! isset( $all_plugins[ $plugin_file ] ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => __( 'Plugin not found.', 'digitizer-site-worker' ),
			), 404 );
		}

		$result = $this->updater->update_plugin( $plugin_file );
		$status = $result['success'] ? 200 : 500;
		return new WP_REST_Response( $result, $status );
	}

	/**
	 * POST /aura/v1/update/theme
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response Update result.
	 */
	public function update_theme( $request ) {
		$theme_slug = $request->get_param( 'theme' );

		// Validate theme exists.
		$theme = wp_get_theme( $theme_slug );
		if ( ! $theme->exists() ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => __( 'Theme not found.', 'digitizer-site-worker' ),
			), 404 );
		}

		$result = $this->updater->update_theme( $theme_slug );
		$status = $result['success'] ? 200 : 500;
		return new WP_REST_Response( $result, $status );
	}

	/**
	 * POST /aura/v1/update/translations
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response Update result.
	 */
	public function update_translations( $request ) {
		$result = $this->updater->update_translations();
		$status = $result['success'] ? 200 : 500;
		return new WP_REST_Response( $result, $status );
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
	 * Updates the AuraWorker plugin from a GitHub release zip URL.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response Update result with version info.
	 */
	public function self_update( $request ) {
		$zip_url = $request->get_param( 'zip_url' );
		$result  = $this->updater->self_update( $zip_url );
		$status  = $result['success'] ? 200 : 500;
		return new WP_REST_Response( $result, $status );
	}

	/**
	 * POST /aura/v1/update/database
	 *
	 * Runs core wp_upgrade or a plugin-specific database migration.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response Update result.
	 */
	public function update_database( $request ) {
		$plugin = $request->get_param( 'plugin' );
		$result = $this->updater->update_database( $plugin );
		$status = $result['success'] ? 200 : 500;
		return new WP_REST_Response( $result, $status );
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
