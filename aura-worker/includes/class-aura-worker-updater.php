<?php
/**
 * Update handler for Aura Worker.
 *
 * Handles WordPress core, plugin, theme, translation, and database updates
 * using WordPress internal Upgrader classes.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Worker_Updater {

	/**
	 * Load required WordPress upgrade files.
	 */
	private function load_upgrade_dependencies() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
	}

	/**
	 * Get available updates for everything.
	 *
	 * Uses cached transients by default (lightweight).
	 * Pass ?refresh=1 to force a fresh check (requires more memory).
	 *
	 * @param bool $force_refresh Whether to force fresh update checks.
	 * @return array Update information.
	 */
	public function get_available_updates( $force_refresh = false ) {
		// Temporarily increase memory for update checks.
		wp_raise_memory_limit( 'admin' );

		// Load required admin files for update functions.
		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( $force_refresh ) {
			wp_version_check();
			wp_update_plugins();
			wp_update_themes();
		}

		$result = array(
			'core'         => $this->get_core_updates(),
			'plugins'      => $this->get_plugin_updates(),
			'themes'       => $this->get_theme_updates(),
			'translations' => $this->get_translation_updates(),
			'cached'       => ! $force_refresh,
		);

		return $result;
	}

	/**
	 * Get core update info.
	 *
	 * @return array|null Core update data or null.
	 */
	private function get_core_updates() {
		$updates = get_core_updates();

		if ( empty( $updates ) || ! is_array( $updates ) || is_wp_error( $updates ) ) {
			return null;
		}

		$update = $updates[0];
		if ( 'latest' === $update->response ) {
			return null;
		}

		return array(
			'current' => get_bloginfo( 'version' ),
			'new'     => $update->version,
			'locale'  => $update->locale,
		);
	}

	/**
	 * Get plugin updates.
	 *
	 * @return array List of plugins with available updates.
	 */
	private function get_plugin_updates() {
		$update_plugins = get_site_transient( 'update_plugins' );
		$updates        = array();

		if ( ! empty( $update_plugins->response ) ) {
			$all_plugins = get_plugins();

			foreach ( $update_plugins->response as $plugin_file => $plugin_data ) {
				$current_data = isset( $all_plugins[ $plugin_file ] ) ? $all_plugins[ $plugin_file ] : array();

				$updates[] = array(
					'file'        => $plugin_file,
					'slug'        => isset( $plugin_data->slug ) ? $plugin_data->slug : dirname( $plugin_file ),
					'name'        => isset( $current_data['Name'] ) ? $current_data['Name'] : '',
					'current'     => isset( $current_data['Version'] ) ? $current_data['Version'] : '',
					'new'         => isset( $plugin_data->new_version ) ? $plugin_data->new_version : '',
					'auto_update' => wp_is_auto_update_enabled_for_type( 'plugin' ),
				);
			}
		}

		return $updates;
	}

	/**
	 * Get theme updates.
	 *
	 * @return array List of themes with available updates.
	 */
	private function get_theme_updates() {
		$update_themes = get_site_transient( 'update_themes' );
		$updates       = array();

		if ( ! empty( $update_themes->response ) ) {
			foreach ( $update_themes->response as $theme_slug => $theme_data ) {
				$theme = wp_get_theme( $theme_slug );

				$updates[] = array(
					'slug'    => $theme_slug,
					'name'    => $theme->get( 'Name' ),
					'current' => $theme->get( 'Version' ),
					'new'     => isset( $theme_data['new_version'] ) ? $theme_data['new_version'] : '',
				);
			}
		}

		return $updates;
	}

	/**
	 * Get translation updates.
	 *
	 * @return int Number of translation updates available.
	 */
	private function get_translation_updates() {
		$translations = wp_get_translation_updates();
		return count( $translations );
	}

	/**
	 * Update a specific plugin.
	 *
	 * @param string $plugin_file Plugin file path (e.g., "akismet/akismet.php").
	 * @return array Result with success status and message.
	 */
	public function update_plugin( $plugin_file ) {
		$this->load_upgrade_dependencies();

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( $plugin_file );

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'error'   => $result->get_error_message(),
			);
		}

		if ( false === $result ) {
			return array(
				'success' => false,
				'error'   => __( 'Update failed. The plugin may not have an update available.', 'aura-worker' ),
			);
		}

		if ( null === $result ) {
			return array(
				'success' => false,
				'error'   => __( 'No update available for this plugin.', 'aura-worker' ),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Plugin updated successfully.', 'aura-worker' ),
		);
	}

	/**
	 * Update a specific theme.
	 *
	 * @param string $theme_slug Theme stylesheet slug.
	 * @return array Result with success status and message.
	 */
	public function update_theme( $theme_slug ) {
		$this->load_upgrade_dependencies();

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Theme_Upgrader( $skin );
		$result   = $upgrader->upgrade( $theme_slug );

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'error'   => $result->get_error_message(),
			);
		}

		if ( false === $result ) {
			return array(
				'success' => false,
				'error'   => __( 'Update failed. The theme may not have an update available.', 'aura-worker' ),
			);
		}

		if ( null === $result ) {
			return array(
				'success' => false,
				'error'   => __( 'No update available for this theme.', 'aura-worker' ),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Theme updated successfully.', 'aura-worker' ),
		);
	}

	/**
	 * Update WordPress core.
	 *
	 * @return array Result with success status and message.
	 */
	public function update_core() {
		$this->load_upgrade_dependencies();

		$updates = get_core_updates();

		if ( empty( $updates ) || ! is_array( $updates ) || 'latest' === $updates[0]->response ) {
			return array(
				'success' => true,
				'message' => __( 'WordPress is already up to date.', 'aura-worker' ),
			);
		}

		$update   = $updates[0];
		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Core_Upgrader( $skin );
		$result   = $upgrader->upgrade( $update );

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'error'   => $result->get_error_message(),
			);
		}

		if ( false === $result ) {
			return array(
				'success' => false,
				'error'   => __( 'Core update failed (filesystem error).', 'aura-worker' ),
			);
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %s: WordPress version */
				__( 'WordPress updated to %s.', 'aura-worker' ),
				$update->version
			),
		);
	}

	/**
	 * Update all translations.
	 *
	 * @return array Result with success status and message.
	 */
	public function update_translations() {
		$this->load_upgrade_dependencies();

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Language_Pack_Upgrader( $skin );
		$result   = $upgrader->bulk_upgrade();

		if ( false === $result ) {
			return array(
				'success' => false,
				'error'   => __( 'Translation update failed.', 'aura-worker' ),
			);
		}

		$updated_count = is_array( $result ) ? count( array_filter( $result ) ) : 0;

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of translations updated */
				__( '%d translation(s) updated.', 'aura-worker' ),
				$updated_count
			),
		);
	}

	/**
	 * Get the plugin migration registry.
	 *
	 * Maps known plugin slugs to their detection, pending-check, and
	 * migration callables. Third-party plugins can register their own
	 * entries via the `aura_worker_migration_registry` filter.
	 *
	 * @return array Keyed array of migration entries.
	 */
	private function get_migration_registry() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$registry = array(
			'elementor'     => array(
				'label'   => 'Elementor',
				'detect'  => function () {
					return defined( 'ELEMENTOR_VERSION' ) && is_plugin_active( 'elementor/elementor.php' );
				},
				'pending' => function () {
					if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
						return false;
					}
					$db_ver = get_option( 'elementor_version', '0' );
					return version_compare( $db_ver, ELEMENTOR_VERSION, '<' );
				},
				'async'   => true,
				'run'     => function () {
					if ( ! class_exists( '\Elementor\Plugin' ) ) {
						return;
					}
					\Elementor\Plugin::instance()->files_manager->clear_cache();
					if ( isset( \Elementor\Plugin::instance()->upgrade ) ) {
						\Elementor\Plugin::instance()->upgrade->do_upgrade();
					}
					// Do NOT set elementor_version here — Elementor's
					// on_runner_complete() handles it after background tasks finish.
				},
			),
			'elementor-pro' => array(
				'label'   => 'Elementor Pro',
				'detect'  => function () {
					return defined( 'ELEMENTOR_PRO_VERSION' ) && is_plugin_active( 'elementor-pro/elementor-pro.php' );
				},
				'pending' => function () {
					if ( ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
						return false;
					}
					$db_ver = get_option( 'elementor_pro_version', '0' );
					return version_compare( $db_ver, ELEMENTOR_PRO_VERSION, '<' );
				},
				'async'   => true,
				'run'     => function () {
					if ( ! class_exists( '\ElementorPro\Plugin' ) ) {
						return;
					}
					if ( isset( \ElementorPro\Plugin::instance()->upgrade ) ) {
						\ElementorPro\Plugin::instance()->upgrade->do_upgrade();
					}
					// Do NOT set elementor_pro_version here — Elementor Pro's
					// on_runner_complete() handles it after background tasks finish.
				},
			),
			'woocommerce'   => array(
				'label'   => 'WooCommerce',
				'detect'  => function () {
					return defined( 'WC_VERSION' ) && is_plugin_active( 'woocommerce/woocommerce.php' );
				},
				'pending' => function () {
					if ( ! defined( 'WC_VERSION' ) ) {
						return false;
					}
					$db_ver = get_option( 'woocommerce_db_version', '0' );
					return version_compare( $db_ver, WC_VERSION, '<' );
				},
				'run'     => function () {
					if ( class_exists( 'WC_Install' ) ) {
						\WC_Install::install();
					}
				},
			),
			'jet-engine'    => array(
				'label'   => 'JetEngine (Crocoblock)',
				'detect'  => function () {
					return defined( 'JET_ENGINE_VERSION' ) && is_plugin_active( 'jet-engine/jet-engine.php' );
				},
				'pending' => function () {
					if ( ! defined( 'JET_ENGINE_VERSION' ) ) {
						return false;
					}
					$db_ver = get_option( 'jet_engine_db_version', '0' );
					return version_compare( $db_ver, JET_ENGINE_VERSION, '<' );
				},
				'run'     => function () {
					if ( function_exists( 'jet_engine' ) && isset( jet_engine()->update_db_updater ) ) {
						jet_engine()->update_db_updater->update_db();
					}
				},
			),
		);

		/**
		 * Filter the plugin migration registry.
		 *
		 * Allows third-party plugins to register their own database
		 * migration handlers without modifying AuraWorker core.
		 *
		 * @param array $registry Keyed array of migration entries.
		 */
		return apply_filters( 'aura_worker_migration_registry', $registry );
	}

	/**
	 * Get database migration status for all detected plugins.
	 *
	 * Returns which plugins are installed and whether they have
	 * pending database migrations.
	 *
	 * @return array Keyed array of { label, pending } per plugin.
	 */
	public function get_database_status() {
		$registry   = $this->get_migration_registry();
		$migrations = array();

		foreach ( $registry as $key => $entry ) {
			if ( call_user_func( $entry['detect'] ) ) {
				$migrations[ $key ] = array(
					'label'   => $entry['label'],
					'pending' => (bool) call_user_func( $entry['pending'] ),
				);
			}
		}

		return $migrations;
	}

	/**
	 * Run database upgrade.
	 *
	 * When $plugin is null, runs WordPress core dbDelta (wp_upgrade).
	 * When $plugin is a registry key, runs that plugin's migration.
	 *
	 * @param string|null $plugin Optional plugin key from migration registry.
	 * @return array Result with success status.
	 */
	public function update_database( $plugin = null ) {
		// Extend execution time for potentially long migrations.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 );
		}

		// Plugin-specific migration.
		if ( $plugin ) {
			$registry = $this->get_migration_registry();

			if ( ! isset( $registry[ $plugin ] ) ) {
				return array(
					'success' => false,
					'error'   => __( 'Unknown plugin migration key.', 'aura-worker' ),
				);
			}

			$entry = $registry[ $plugin ];

			if ( ! call_user_func( $entry['detect'] ) ) {
				return array(
					'success' => false,
					'error'   => sprintf(
						/* translators: %s: Plugin label */
						__( '%s is not installed or active.', 'aura-worker' ),
						$entry['label']
					),
				);
			}

			$is_async = ! empty( $entry['async'] );

			try {
				call_user_func( $entry['run'] );
			} catch ( \Throwable $e ) {
				return array(
					'success' => false,
					'error'   => sprintf(
						/* translators: %1$s: Plugin label, %2$s: Error message */
						__( '%1$s migration failed: %2$s', 'aura-worker' ),
						$entry['label'],
						$e->getMessage()
					),
				);
			}

			if ( $is_async ) {
				return array(
					'success' => true,
					'async'   => true,
					'message' => sprintf(
						/* translators: %s: Plugin label */
						__( '%s database migration triggered. It will complete in the background — poll database-status to check progress.', 'aura-worker' ),
						$entry['label']
					),
				);
			}

			return array(
				'success' => true,
				'message' => sprintf(
					/* translators: %s: Plugin label */
					__( '%s database migration completed.', 'aura-worker' ),
					$entry['label']
				),
			);
		}

		// Core WordPress database upgrade (default).
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$db_version_before = get_option( 'db_version' );
		wp_upgrade();
		$db_version_after = get_option( 'db_version' );

		return array(
			'success'    => true,
			'message'    => __( 'Database tables updated.', 'aura-worker' ),
			'db_before'  => $db_version_before,
			'db_after'   => $db_version_after,
			'changed'    => $db_version_before !== $db_version_after,
		);
	}
}
