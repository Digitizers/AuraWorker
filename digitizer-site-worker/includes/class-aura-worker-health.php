<?php
/**
 * Health check class for Aura Worker.
 *
 * Runs HTTP, PHP error log, white-screen, and database checks.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Aura_Worker_Health {

	/**
	 * Run all health checks and return a summary.
	 *
	 * @return array { healthy: bool, checks: array }
	 */
	public function run_health_check() {
		$checks = array(
			'http_status'   => $this->check_http_status(),
			'php_errors'    => $this->check_php_errors(),
			'white_screen'  => $this->check_white_screen(),
			'db_connection' => $this->check_db_connection(),
		);

		$healthy = true;
		foreach ( $checks as $check ) {
			if ( $check['status'] === 'fail' ) {
				$healthy = false;
				break;
			}
		}

		return array( 'healthy' => $healthy, 'checks' => $checks );
	}

	/**
	 * Check the HTTP response code of the home page.
	 *
	 * @return array { status: 'pass'|'fail', detail: string }
	 */
	private function check_http_status() {
		$response = wp_remote_get( home_url( '/' ), array( 'timeout' => 15, 'sslverify' => false ) );
		if ( is_wp_error( $response ) ) {
			return array( 'status' => 'fail', 'detail' => $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		return $code >= 500
			? array( 'status' => 'fail', 'detail' => "HTTP $code" )
			: array( 'status' => 'pass', 'detail' => "HTTP $code" );
	}

	/**
	 * Check the PHP error log for recent fatal errors.
	 *
	 * @return array { status: 'pass'|'fail', detail: string }
	 */
	private function check_php_errors() {
		$log_file = ini_get( 'error_log' );
		if ( empty( $log_file ) || ! file_exists( $log_file ) ) {
			$log_file = WP_CONTENT_DIR . '/debug.log';
		}
		if ( ! file_exists( $log_file ) ) {
			return array( 'status' => 'pass', 'detail' => 'No error log found' );
		}
		$size = filesize( $log_file );
		// Reading only the last 5KB of a potentially huge log file; WP_Filesystem has no streaming seek.
		$fp = fopen( $log_file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( $size > 5120 ) {
			fseek( $fp, -5120, SEEK_END );
		}
		$tail = fread( $fp, 5120 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( preg_match_all( '/\[.*?\]\s*(PHP Fatal error|PHP Parse error)/i', $tail, $matches ) ) {
			return array( 'status' => 'fail', 'detail' => count( $matches[0] ) . ' fatal errors in recent log' );
		}
		return array( 'status' => 'pass', 'detail' => 'No recent fatal errors' );
	}

	/**
	 * Check for a white screen of death (empty response body).
	 *
	 * @return array { status: 'pass'|'fail', detail: string }
	 */
	private function check_white_screen() {
		$response = wp_remote_get( home_url( '/' ), array( 'timeout' => 15, 'sslverify' => false ) );
		if ( is_wp_error( $response ) ) {
			return array( 'status' => 'fail', 'detail' => $response->get_error_message() );
		}
		$body = wp_remote_retrieve_body( $response );
		if ( empty( trim( $body ) ) ) {
			return array( 'status' => 'fail', 'detail' => 'Empty response body (WSOD)' );
		}
		return array( 'status' => 'pass', 'detail' => 'Response body OK (' . strlen( $body ) . ' bytes)' );
	}

	/**
	 * Check the database connection with a simple query.
	 *
	 * @return array { status: 'pass'|'fail', detail: string }
	 */
	private function check_db_connection() {
		global $wpdb;
		$result = $wpdb->get_var( 'SELECT 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $result === '1'
			? array( 'status' => 'pass', 'detail' => 'Database connection OK' )
			: array( 'status' => 'fail', 'detail' => 'Database query failed' );
	}
}
