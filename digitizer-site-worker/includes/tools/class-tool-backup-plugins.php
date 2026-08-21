<?php
/**
 * MCP Tool: backup_plugins
 *
 * Zip-snapshots one or all active plugins via Aura_Worker_Rollback, so a
 * mutating action can be reverted. Mutating — gated by Aura's approval policy.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Tool_Backup_Plugins extends Aura_Tool_Base {

	public function get_name() {
		return 'backup_plugins';
	}

	public function get_description() {
		return 'Creates a zip backup of a specific plugin (by slug) or, if none is given, every active plugin — so changes can be rolled back. Returns per-plugin results and the current backup inventory.';
	}

	public function get_parameters() {
		return array(
			'plugin' => array(
				'type'        => 'string',
				'description' => 'Plugin folder slug to back up (e.g. "akismet"). Omit to back up all active plugins.',
				'required'    => false,
			),
		);
	}

	public function get_returns() {
		return array(
			'backed_up' => 'integer — plugins backed up successfully',
			'total'     => 'integer — plugins attempted',
			'results'   => 'array — { plugin, success, backup_file?, error? }',
			'backups'   => 'array — current backup inventory { plugin_slug, timestamp, filename, size_kb }',
		);
	}

	/**
	 * Writes plugin backup archives to disk — a mutating op, so it is never
	 * read-only and must be approved before it runs.
	 */
	public function get_annotations() {
		return array(
			'read_only'         => false,
			'destructive'       => false,
			'requires_approval' => true,
			'supports_preview'  => false,
		);
	}

	/** @inheritDoc — site-wide maintenance: a freeze applies, a page rule does not. */
	public function touches( $params ) {
		return array( array( 'type' => 'site', 'id' => '*' ) );
	}

	public function execute( $params ) {
		$file = dirname( __DIR__ ) . '/class-aura-worker-rollback.php';
		if ( ! class_exists( 'Aura_Worker_Rollback' ) && file_exists( $file ) ) {
			require_once $file;
		}
		if ( ! class_exists( 'Aura_Worker_Rollback' ) ) {
			throw new Exception( 'Rollback module is unavailable.' );
		}

		$rollback = new Aura_Worker_Rollback();

		// Resolve targets: explicit slug, or all active plugins.
		$targets = array();
		if ( ! empty( $params['plugin'] ) ) {
			$targets[] = sanitize_text_field( $params['plugin'] );
		} else {
			$active = (array) get_option( 'active_plugins', array() );
			foreach ( $active as $plugin_file ) {
				$slug = dirname( $plugin_file );
				if ( $slug && '.' !== $slug ) {
					$targets[] = $slug;
				}
			}
			$targets = array_values( array_unique( $targets ) );
		}

		$results = array();
		$ok      = 0;
		foreach ( $targets as $slug ) {
			$result = $rollback->backup_plugin( $slug );
			$success = ! empty( $result['success'] );
			if ( $success ) {
				++$ok;
			}
			$results[] = array(
				'plugin'      => $slug,
				'success'     => $success,
				'backup_file' => isset( $result['backup_path'] ) ? basename( $result['backup_path'] ) : null,
				'error'       => isset( $result['error'] ) ? $result['error'] : null,
			);
		}

		// Sanitized inventory (no absolute server paths).
		$inventory = array();
		foreach ( $rollback->list_backups() as $b ) {
			$inventory[] = array(
				'plugin_slug' => isset( $b['plugin_slug'] ) ? $b['plugin_slug'] : '',
				'timestamp'   => isset( $b['timestamp'] ) ? $b['timestamp'] : '',
				'filename'    => isset( $b['filename'] ) ? $b['filename'] : '',
				'size_kb'     => isset( $b['size_kb'] ) ? $b['size_kb'] : 0,
			);
		}

		return array(
			'backed_up'    => $ok,
			'total'        => count( $targets ),
			'results'      => $results,
			'backups'      => $inventory,
			'generated_at' => gmdate( 'c' ),
		);
	}
}
