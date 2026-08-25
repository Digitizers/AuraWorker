<?php
/**
 * Uninstall handler for SiteAgent.
 *
 * Cleans up plugin options when uninstalled.
 *
 * @package Aura_Worker
 */

// Exit if not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Revoke the Application Password Aura minted for the dashboard BEFORE the
// options that identify it are deleted (2.11.0, round-6). It is an
// administrator-level credential: removing the plugin must not leave it
// authenticating to WordPress — or to any other REST/MCP plugin — with
// nothing left on the site that even records its existence. Deleted by the
// STORED UUID, never by name (the display name is user-chosen). The option
// names mirror Aura_Worker_Magic_Link::APP_PASSWORD_OWNER_OPTION and
// ::APP_PASSWORD_UUID_OPTION; this file deliberately loads no plugin code.
$aura_pw_owner = (int) get_option( 'aura_worker_app_password_user_id', 0 );
$aura_pw_uuid  = (string) get_option( 'aura_worker_app_password_uuid', '' );
if ( $aura_pw_owner > 0 && '' !== $aura_pw_uuid && class_exists( 'WP_Application_Passwords' ) ) {
	WP_Application_Passwords::delete_application_password( $aura_pw_owner, $aura_pw_uuid );
}
delete_option( 'aura_worker_app_password_user_id' );
delete_option( 'aura_worker_app_password_uuid' );

// Remove plugin options.
delete_option( 'aura_worker_activated' );
delete_option( 'aura_worker_version' );
delete_option( 'aura_worker_site_token' );
delete_option( 'aura_worker_allowed_ips' );
delete_option( 'aura_worker_allowed_domains' );
delete_option( 'aura_worker_dashboard_url' );
