<?php
/**
 * Security handler for Aura Worker.
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
	 * Validate an incoming REST API request.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	public function validate_request( $request ) {
		// Layer 1: Check IP whitelist (if configured).
		$ip_check = $this->check_ip_whitelist();
		if ( is_wp_error( $ip_check ) ) {
			return $ip_check;
		}

		// Layer 2: Verify Aura site token.
		$token_check = $this->check_aura_token( $request );
		if ( is_wp_error( $token_check ) ) {
			return $token_check;
		}

		// Layer 3: WordPress Application Password (handled by WP REST auth).
		// The permission_callback checks current_user_can().
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
				__( 'Your IP address is not authorized.', 'aura-worker' ),
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
		$provided_token = $request->get_header( 'X-Aura-Token' );
		$stored_token   = get_option( 'aura_worker_site_token', '' );

		if ( empty( $stored_token ) ) {
			return new WP_Error(
				'aura_not_configured',
				__( 'Aura Worker is not configured. Please set a site token.', 'aura-worker' ),
				array( 'status' => 500 )
			);
		}

		if ( empty( $provided_token ) || ! hash_equals( $stored_token, $provided_token ) ) {
			return new WP_Error(
				'aura_invalid_token',
				__( 'Invalid or missing Aura token.', 'aura-worker' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Get the client IP address.
	 *
	 * @return string Client IP.
	 */
	private function get_client_ip() {
		// Check common proxy headers (in order of trust).
		$headers = array(
			'HTTP_CF_CONNECTING_IP', // Cloudflare
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',
		);

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				// X-Forwarded-For can contain multiple IPs; take the first.
				$ip = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
				return trim( $ip[0] );
			}
		}

		return '0.0.0.0';
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

		// Then check WordPress capability.
		if ( ! current_user_can( 'update_plugins' ) ) {
			return new WP_Error(
				'aura_insufficient_permissions',
				__( 'You do not have permission to perform this action.', 'aura-worker' ),
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
				__( 'You do not have permission to view this data.', 'aura-worker' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}
}
