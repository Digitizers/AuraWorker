<?php
/**
 * MCP Tool: get_site_context
 *
 * Returns a comprehensive snapshot of the WordPress site environment.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Tool_Site_Context extends Aura_Tool_Base {

	public function get_name() {
		return 'get_site_context';
	}

	public function get_description() {
		return 'Returns a comprehensive snapshot of the WordPress site: core info, PHP environment, active theme, plugins, disk usage, and detected issues. Optionally includes full plugin list and performance metrics.';
	}

	public function get_parameters() {
		return array(
			'include_plugins' => array(
				'type'        => 'boolean',
				'description' => 'Include full plugin list with versions and active status (default true).',
				'required'    => false,
				'default'     => true,
			),
			'include_performance' => array(
				'type'        => 'boolean',
				'description' => 'Include performance metrics: memory usage, opcache status, max_execution_time (default true).',
				'required'    => false,
				'default'     => true,
			),
		);
	}

	public function get_returns() {
		return array(
			'site_url'          => 'string — public site URL',
			'site_name'         => 'string — blog name',
			'wp_version'        => 'string — WordPress version',
			'php_version'       => 'string — PHP version',
			'active_theme'      => 'array — name, version, slug, parent',
			'is_multisite'      => 'bool',
			'locale'            => 'string',
			'timezone'          => 'string',
			'disk_usage'        => 'array — uploads_bytes, plugins_bytes, uploads_human, plugins_human',
			'plugins'           => 'array|null — plugin list (when include_plugins=true)',
			'performance'       => 'array|null — memory, opcache, max_execution (when include_performance=true)',
			'issues'            => 'array — detected issues (outdated PHP, pending WP update, outdated plugins)',
		);
	}

	public function execute( $params ) {
		global $wpdb;

		$include_plugins     = isset( $params['include_plugins'] ) ? (bool) $params['include_plugins'] : true;
		$include_performance = isset( $params['include_performance'] ) ? (bool) $params['include_performance'] : true;

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$theme          = wp_get_theme();
		$all_plugins    = get_plugins();
		$active_plugins = get_option( 'active_plugins', array() );

		// Build plugin list.
		$plugins = null;
		if ( $include_plugins ) {
			$plugins = array();
			foreach ( $all_plugins as $file => $data ) {
				$plugins[] = array(
					'file'    => $file,
					'slug'    => dirname( $file ),
					'name'    => $data['Name'],
					'version' => $data['Version'],
					'active'  => in_array( $file, $active_plugins, true ),
				);
			}
		}

		// Disk usage.
		$disk_usage = $this->get_disk_usage();

		// Performance metrics.
		$performance = null;
		if ( $include_performance ) {
			$memory_usage = memory_get_usage( true );
			$memory_limit = $this->parse_size( WP_MEMORY_LIMIT );
			$opcache      = array( 'enabled' => false );
			if ( function_exists( 'opcache_get_status' ) ) {
				$status = @opcache_get_status( false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				if ( is_array( $status ) ) {
					$opcache = array(
						'enabled'             => true,
						'hit_rate'            => isset( $status['opcache_statistics']['opcache_hit_rate'] )
							? round( $status['opcache_statistics']['opcache_hit_rate'], 2 )
							: null,
						'memory_used_bytes'   => isset( $status['memory_usage']['used_memory'] )
							? $status['memory_usage']['used_memory']
							: null,
						'memory_free_bytes'   => isset( $status['memory_usage']['free_memory'] )
							? $status['memory_usage']['free_memory']
							: null,
					);
				}
			}
			$performance = array(
				'memory_used_bytes'   => $memory_usage,
				'memory_used_human'   => size_format( $memory_usage ),
				'memory_limit'        => WP_MEMORY_LIMIT,
				'memory_limit_bytes'  => $memory_limit,
				'max_execution_time'  => (int) ini_get( 'max_execution_time' ),
				'opcache'             => $opcache,
			);
		}

		// Detected issues.
		$issues = $this->detect_issues( $all_plugins );

		return array(
			'site_url'    => get_site_url(),
			'site_name'   => get_bloginfo( 'name' ),
			'wp_version'  => get_bloginfo( 'version' ),
			'php_version' => phpversion(),
			'active_theme' => array(
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
				'slug'    => $theme->get_stylesheet(),
				'parent'  => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
			),
			'is_multisite'   => is_multisite(),
			'locale'         => get_locale(),
			'timezone'       => wp_timezone_string(),
			'disk_usage'     => $disk_usage,
			'plugins'        => $plugins,
			'performance'    => $performance,
			'issues'         => $issues,
			'generated_at'   => gmdate( 'c' ),
		);
	}

	/**
	 * Calculate disk usage for uploads and plugins directories.
	 *
	 * @return array
	 */
	private function get_disk_usage() {
		$uploads_dir   = wp_get_upload_dir();
		$uploads_path  = $uploads_dir['basedir'];
		$plugins_path  = WP_PLUGIN_DIR;

		$uploads_bytes = $this->dir_size( $uploads_path );
		$plugins_bytes = $this->dir_size( $plugins_path );

		return array(
			'uploads_bytes'  => $uploads_bytes,
			'uploads_human'  => size_format( $uploads_bytes ),
			'plugins_bytes'  => $plugins_bytes,
			'plugins_human'  => size_format( $plugins_bytes ),
		);
	}

	/**
	 * Recursively calculate directory size in bytes.
	 *
	 * @param string $path Directory path.
	 * @return int
	 */
	private function dir_size( $path ) {
		if ( ! is_dir( $path ) ) {
			return 0;
		}
		$size = 0;
		try {
			$iter = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY,
				RecursiveIteratorIterator::CATCH_GET_CHILD
			);
			foreach ( $iter as $file ) {
				if ( $file->isFile() ) {
					$size += $file->getSize();
				}
			}
		} catch ( Exception $e ) {
			// Silently ignore unreadable directories.
		}
		return $size;
	}

	/**
	 * Parse a PHP size string (e.g. "128M") into bytes.
	 *
	 * @param string $size Size string.
	 * @return int
	 */
	private function parse_size( $size ) {
		$unit = strtoupper( substr( $size, -1 ) );
		$val  = (int) $size;
		switch ( $unit ) {
			case 'G':
				return $val * 1024 * 1024 * 1024;
			case 'M':
				return $val * 1024 * 1024;
			case 'K':
				return $val * 1024;
			default:
				return $val;
		}
	}

	/**
	 * Detect common site issues.
	 *
	 * @param array $all_plugins All installed plugins from get_plugins().
	 * @return array
	 */
	private function detect_issues( $all_plugins ) {
		$issues = array();

		// PHP version check — 7.4 is EOL; 8.0 is minimum recommended.
		$php_version = phpversion();
		if ( version_compare( $php_version, '8.0', '<' ) ) {
			$issues[] = array(
				'type'    => 'php_version',
				'message' => "PHP $php_version is outdated. PHP 8.0 or higher is recommended.",
			);
		}

		// WordPress core update available.
		$core_updates = get_core_updates();
		if ( ! empty( $core_updates ) && is_array( $core_updates ) && ! is_wp_error( $core_updates ) ) {
			$update = $core_updates[0];
			if ( isset( $update->response ) && $update->response !== 'latest' ) {
				$issues[] = array(
					'type'       => 'wp_update',
					'message'    => 'A WordPress core update is available.',
					'current'    => get_bloginfo( 'version' ),
					'available'  => isset( $update->version ) ? $update->version : 'unknown',
				);
			}
		}

		// Outdated plugins.
		$plugin_updates = get_site_transient( 'update_plugins' );
		if ( ! empty( $plugin_updates->response ) ) {
			$outdated_count = count( $plugin_updates->response );
			$outdated_names = array();
			foreach ( $plugin_updates->response as $file => $data ) {
				if ( isset( $all_plugins[ $file ] ) ) {
					$outdated_names[] = $all_plugins[ $file ]['Name'];
				}
			}
			$issues[] = array(
				'type'    => 'outdated_plugins',
				'message' => "$outdated_count plugin(s) have available updates.",
				'count'   => $outdated_count,
				'plugins' => $outdated_names,
			);
		}

		return $issues;
	}
}
