<?php
/**
 * Uninstall handler for Aura Worker.
 *
 * Cleans up plugin options when uninstalled.
 *
 * @package Aura_Worker
 */

// Exit if not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove plugin options.
delete_option( 'aura_worker_activated' );
delete_option( 'aura_worker_version' );
delete_option( 'aura_worker_site_token' );
delete_option( 'aura_worker_allowed_ips' );
