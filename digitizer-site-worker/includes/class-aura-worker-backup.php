<?php
/**
 * Backup handler for Aura Worker.
 *
 * Triggers a pre-update backup via the Aura Dashboard relay first,
 * with UpdraftPlus as a fallback when available.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Aura_Worker_Backup {

	/**
	 * Trigger a pre-update backup using the best available method.
	 *
	 * Tries the Aura Dashboard relay first. Falls back to UpdraftPlus
	 * if the dashboard is not configured or the request fails.
	 *
	 * @return array { success: bool, method: string, detail: string }
	 */
	public function trigger_pre_update_backup() {
		// Try Aura Dashboard relay first.
		$aura_backup = $this->request_aura_backup();
		if ( $aura_backup['success'] ) {
			return array( 'success' => true, 'method' => 'aura_relay', 'detail' => $aura_backup['detail'] );
		}

		// Fallback: UpdraftPlus.
		if ( class_exists( 'UpdraftPlus' ) ) {
			return $this->trigger_updraftplus_backup();
		}

		return array( 'success' => false, 'method' => 'none', 'detail' => 'No backup provider available' );
	}

	/**
	 * Request a backup via the Aura Dashboard relay API.
	 *
	 * Reads `aura_worker_dashboard_url` and `aura_worker_site_token` from
	 * WordPress options. Returns failure if either option is missing.
	 *
	 * @return array { success: bool, detail: string }
	 */
	private function request_aura_backup() {
		$dashboard_url = get_option( 'aura_worker_dashboard_url', '' );
		$site_token    = get_option( 'aura_worker_site_token', '' );

		if ( empty( $dashboard_url ) || empty( $site_token ) ) {
			return array( 'success' => false, 'detail' => 'Dashboard not configured' );
		}

		$response = wp_remote_post( $dashboard_url . '/api/resources/backup-request', array(
			'headers' => array(
				'Content-Type' => 'application/json',
				'X-Site-Token' => $site_token,
			),
			'body'    => json_encode( array( 'site_url' => get_site_url() ) ),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'detail' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		return $code === 200
			? array( 'success' => true, 'detail' => 'Backup initiated via Aura Dashboard' )
			: array( 'success' => false, 'detail' => "Dashboard returned HTTP $code" );
	}

	/**
	 * Trigger an UpdraftPlus backup.
	 *
	 * Calls UpdraftPlus's boot_backup method with files and database flags set.
	 *
	 * @return array { success: bool, method: string, detail: string }
	 */
	private function trigger_updraftplus_backup() {
		global $updraftplus;

		if ( ! $updraftplus ) {
			return array( 'success' => false, 'method' => 'updraftplus', 'detail' => 'UpdraftPlus not initialized' );
		}

		$updraftplus->boot_backup( true, true );

		return array( 'success' => true, 'method' => 'updraftplus', 'detail' => 'UpdraftPlus backup triggered' );
	}
}
