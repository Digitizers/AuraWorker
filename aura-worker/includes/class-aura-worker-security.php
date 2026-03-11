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
		// Layer 1a: Check IP whitelist (if configured).
		$ip_check = $this->check_ip_whitelist();
		if ( is_wp_error( $ip_check ) ) {
			return $ip_check;
		}

		// Layer 1b: Check domain whitelist (if configured).
		$domain_check = $this->check_domain_whitelist( $request );
		if ( is_wp_error( $domain_check ) ) {
			return $domain_check;
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
				__( 'Your IP address is not authorized.', 'aurawp' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Check if the request origin domain is in the allowed list.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return bool|WP_Error True if allowed, WP_Error if blocked.
	 */
	private function check_domain_whitelist( $request ) {
		$allowed_domains = get_option( 'aura_worker_allowed_domains', '' );

		// If no domains configured, allow all.
		if ( empty( trim( $allowed_domains ) ) ) {
			return true;
		}

		$allowed = array_filter( array_map( 'trim', explode( "\n", strtolower( $allowed_domains ) ) ) );

		// Check Origin header first, then Referer as fallback.
		$origin  = $request->get_header( 'Origin' );
		$referer = $request->get_header( 'Referer' );

		$request_host = '';
		if ( ! empty( $origin ) ) {
			$parsed = wp_parse_url( $origin );
			$request_host = isset( $parsed['host'] ) ? strtolower( $parsed['host'] ) : '';
		} elseif ( ! empty( $referer ) ) {
			$parsed = wp_parse_url( $referer );
			$request_host = isset( $parsed['host'] ) ? strtolower( $parsed['host'] ) : '';
		}

		// No Origin/Referer header — this is a server-to-server request (e.g. from the
		// Aura dashboard). Allow it through; the token check still protects the endpoint.
		if ( empty( $request_host ) ) {
			return true;
		}

		if ( ! in_array( $request_host, $allowed, true ) ) {
			return new WP_Error(
				'aura_domain_blocked',
				__( 'Your request origin domain is not authorized.', 'aurawp' ),
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
		$provided_token = (string) $request->get_header( 'X-Aura-Token' );
		$stored_token   = get_option( 'aura_worker_site_token', '' );

		if ( empty( $stored_token ) ) {
			return new WP_Error(
				'aura_not_configured',
				__( 'Aura Worker is not configured. Please set a site token.', 'aurawp' ),
				array( 'status' => 500 )
			);
		}

		if ( empty( $provided_token ) || ! hash_equals( $stored_token, $provided_token ) ) {
			return new WP_Error(
				'aura_invalid_token',
				__( 'Invalid or missing Aura token.', 'aurawp' ),
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
		// Only trust REMOTE_ADDR — proxy headers (X-Forwarded-For, CF-Connecting-IP)
		// are client-controlled and trivially spoofed. Managed hosts and reverse proxies
		// should be configured to set REMOTE_ADDR correctly at the server layer.
		return isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '0.0.0.0';
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
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'aura_insufficient_permissions',
				__( 'You do not have permission to perform this action.', 'aurawp' ),
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
				__( 'You do not have permission to view this data.', 'aurawp' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}
}
