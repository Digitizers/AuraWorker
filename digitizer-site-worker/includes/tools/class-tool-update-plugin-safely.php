<?php
/**
 * MCP Tool: update_plugin_safely
 *
 * Updates a single plugin with optional backup and health-check auto-rollback.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Tool_Update_Plugin_Safely extends Aura_Tool_Base {

	public function get_name() {
		return 'update_plugin_safely';
	}

	public function get_description() {
		return 'Updates a single plugin by slug. Optionally creates a backup first and performs a health check after update, rolling back automatically if the site becomes unhealthy.';
	}

	public function get_parameters() {
		return array(
			'plugin_slug' => array(
				'type'        => 'string',
				'description' => 'The plugin folder slug (e.g. "akismet"). Used to locate the plugin file path automatically.',
				'required'    => true,
			),
			'create_backup' => array(
				'type'        => 'boolean',
				'description' => 'Whether to create a backup before updating (default true).',
				'required'    => false,
				'default'     => true,
			),
		);
	}

	public function get_returns() {
		return array(
			'success'              => 'bool — whether the update completed without errors',
			'plugin_file'         => 'string — resolved plugin file path (slug/slug.php)',
			'previous_version'    => 'string|null — version before update',
			'new_version'         => 'string|null — version after update',
			'rollback_performed'  => 'bool — whether a rollback was triggered',
			'health_check_passed' => 'bool — result of post-update health check',
			'error'               => 'string|null — error message if failed',
		);
	}

	public function execute( $params ) {
		$plugin_slug   = sanitize_text_field( $params['plugin_slug'] );
		$create_backup = isset( $params['create_backup'] ) ? (bool) $params['create_backup'] : true;

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Resolve plugin slug to plugin file path (e.g. akismet/akismet.php).
		$plugin_file = $this->resolve_plugin_file( $plugin_slug );
		if ( null === $plugin_file ) {
			return array(
				'success'              => false,
				'plugin_file'         => null,
				'previous_version'    => null,
				'new_version'         => null,
				'rollback_performed'  => false,
				'health_check_passed' => false,
				'error'               => "Could not find an installed plugin matching slug: $plugin_slug",
			);
		}

		// Record previous version.
		$all_plugins      = get_plugins();
		$previous_version = isset( $all_plugins[ $plugin_file ]['Version'] )
			? $all_plugins[ $plugin_file ]['Version']
			: null;

		// Delegate to the batch updater (single plugin, chunk_size=1).
		require_once plugin_dir_path( __FILE__ ) . '../class-aura-worker-updater.php';
		$updater = new Aura_Worker_Updater();
		$batch   = $updater->batch_update_plugins( array( $plugin_file ), 1, $create_backup );

		// Pull per-plugin result.
		$plugin_result = isset( $batch['results'][ $plugin_file ] )
			? $batch['results'][ $plugin_file ]
			: array();

		$success              = ! empty( $plugin_result['success'] );
		$rollback_performed   = ! empty( $plugin_result['rolled_back'] );
		$health_check_passed  = ! empty( $plugin_result['health_passed'] );
		$new_version          = isset( $plugin_result['new_version'] ) ? $plugin_result['new_version'] : null;
		$error                = isset( $plugin_result['error'] ) ? $plugin_result['error'] : null;

		// If batch doesn't expose per-plugin detail, fall back to re-reading plugin data.
		if ( $success && null === $new_version ) {
			wp_clean_plugins_cache( false );
			$refreshed   = get_plugins();
			$new_version = isset( $refreshed[ $plugin_file ]['Version'] )
				? $refreshed[ $plugin_file ]['Version']
				: null;
		}

		return array(
			'success'              => $success,
			'plugin_file'         => $plugin_file,
			'previous_version'    => $previous_version,
			'new_version'         => $new_version,
			'rollback_performed'  => $rollback_performed,
			'health_check_passed' => $health_check_passed,
			'error'               => $error,
		);
	}

	/**
	 * Resolve a plugin slug to its plugin file path (folder/file.php).
	 *
	 * Tries exact match on folder name, then falls back to matching the slug
	 * as a substring of the plugin file path.
	 *
	 * @param string $slug Plugin slug.
	 * @return string|null
	 */
	private function resolve_plugin_file( $slug ) {
		$all_plugins = get_plugins();

		// Exact folder match: slug/slug.php or slug/anything.php.
		foreach ( $all_plugins as $file => $data ) {
			if ( dirname( $file ) === $slug ) {
				return $file;
			}
		}

		// Partial match — slug appears in the file path.
		foreach ( $all_plugins as $file => $data ) {
			if ( false !== strpos( $file, $slug ) ) {
				return $file;
			}
		}

		return null;
	}
}
