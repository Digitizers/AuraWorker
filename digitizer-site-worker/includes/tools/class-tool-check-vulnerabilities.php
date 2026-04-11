<?php
/**
 * MCP Tool: check_vulnerabilities
 *
 * Checks installed plugins against the WordPress.org API to identify outdated
 * or potentially vulnerable versions.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Tool_Check_Vulnerabilities extends Aura_Tool_Base {

	public function get_name() {
		return 'check_vulnerabilities';
	}

	public function get_description() {
		return 'Checks installed plugins against the WordPress.org plugin API to identify outdated versions. Reports plugins where the installed version is behind the latest available version on WordPress.org.';
	}

	public function get_parameters() {
		return array(
			'active_only' => array(
				'type'        => 'boolean',
				'description' => 'When true, only check active plugins (default false — checks all installed plugins).',
				'required'    => false,
				'default'     => false,
			),
		);
	}

	public function get_returns() {
		return array(
			'plugins_checked'   => 'int — number of plugins checked',
			'vulnerable_count'  => 'int — number of plugins with available updates',
			'issues'            => 'array — list of plugins with issues: slug, name, installed_version, latest_version, update_available',
			'skipped'           => 'array — slugs skipped (not found on WordPress.org, e.g. premium plugins)',
			'checked_at'        => 'string — ISO 8601 timestamp',
		);
	}

	public function execute( $params ) {
		$active_only = isset( $params['active_only'] ) ? (bool) $params['active_only'] : false;

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins    = get_plugins();
		$active_plugins = get_option( 'active_plugins', array() );

		$issues  = array();
		$skipped = array();
		$checked = 0;

		foreach ( $all_plugins as $file => $data ) {
			if ( $active_only && ! in_array( $file, $active_plugins, true ) ) {
				continue;
			}

			$slug = dirname( $file );

			// Single-file plugins in root (no subdirectory).
			if ( '.' === $slug || '' === $slug ) {
				$skipped[] = $file;
				continue;
			}

			$api_data = $this->query_plugin_api( $slug );
			$checked++;

			if ( null === $api_data ) {
				// Plugin not found on WordPress.org (premium or custom plugin).
				$skipped[] = $slug;
				continue;
			}

			$installed = $data['Version'];
			$latest    = $api_data['version'];

			if ( version_compare( $installed, $latest, '<' ) ) {
				$issues[] = array(
					'slug'              => $slug,
					'name'              => $data['Name'],
					'installed_version' => $installed,
					'latest_version'    => $latest,
					'update_available'  => true,
					'active'            => in_array( $file, $active_plugins, true ),
					'changelog_url'     => isset( $api_data['url'] )
						? "https://wordpress.org/plugins/$slug/#developers"
						: null,
				);
			}
		}

		return array(
			'plugins_checked'  => $checked,
			'vulnerable_count' => count( $issues ),
			'issues'           => $issues,
			'skipped'          => $skipped,
			'checked_at'       => gmdate( 'c' ),
		);
	}

	/**
	 * Query the WordPress.org Plugin Information API for a plugin slug.
	 *
	 * Returns an array with at least 'version' key, or null if not found.
	 *
	 * @param string $slug Plugin slug.
	 * @return array|null
	 */
	private function query_plugin_api( $slug ) {
		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}

		$response = plugins_api(
			'plugin_information',
			array(
				'slug'   => $slug,
				'fields' => array(
					'version'      => true,
					'last_updated' => true,
					'short_description' => false,
					'sections'     => false,
					'tags'         => false,
					'icons'        => false,
					'banners'      => false,
					'screenshots'  => false,
					'reviews'      => false,
					'ratings'      => false,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		return array(
			'version' => $response->version,
			'url'     => isset( $response->homepage ) ? $response->homepage : null,
		);
	}
}
