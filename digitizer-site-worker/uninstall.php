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
// record that identifies it is deleted (2.11.0, round-6). It is an
// administrator-level credential: removing the plugin must not leave it
// authenticating to WordPress — or to any other REST/MCP plugin — with
// nothing left on the site that even records its existence. Deleted by the
// STORED UUID, never by name (the display name is user-chosen). The option
// name mirrors Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION; this file
// deliberately loads no plugin code.
$aura_pw_record  = get_option( 'aura_worker_app_password', null );
$aura_pw_owner   = is_array( $aura_pw_record ) ? (int) ( $aura_pw_record['user_id'] ?? 0 ) : 0;
$aura_pw_uuid    = is_array( $aura_pw_record ) ? (string) ( $aura_pw_record['uuid'] ?? '' ) : '';
$aura_pw_tracked = ( $aura_pw_owner > 0 && '' !== $aura_pw_uuid );
// A record that is NOT usable — present but malformed — names a password this
// code cannot delete, and the connect path treats it as a possibly live orphan
// (round-13). Uninstall must not discard it either (round-15): it is the only
// thing left pointing at an administrator credential that may still work, so it
// stays behind for manual recovery. Nothing recorded at all → nothing to keep.
$aura_pw_present = ( null !== $aura_pw_record && false !== $aura_pw_record && '' !== $aura_pw_record );
$aura_pw_gone    = ! $aura_pw_present;
if ( $aura_pw_tracked && class_exists( 'WP_Application_Passwords' ) ) {
	WP_Application_Passwords::delete_application_password( $aura_pw_owner, $aura_pw_uuid );
	// The revocation is PROVEN by the owner's list, not by the delete's return
	// value (round-7): it answers false for a failed user-meta write too. The
	// record is the only trace of which credential this is, so it is discarded
	// ONLY once the credential is really gone — otherwise a live administrator
	// password would survive the uninstall with its owner and UUID
	// irrecoverably forgotten. Left in place, a reinstall can finish the job.
	$aura_pw_gone = true;
	foreach ( WP_Application_Passwords::get_user_application_passwords( $aura_pw_owner ) as $aura_pw_item ) {
		if ( isset( $aura_pw_item['uuid'] ) && $aura_pw_uuid === (string) $aura_pw_item['uuid'] ) {
			$aura_pw_gone = false;
			break;
		}
	}
}
if ( $aura_pw_gone ) {
	delete_option( 'aura_worker_app_password' );
}

// Remove plugin options.
delete_option( 'aura_worker_activated' );
delete_option( 'aura_worker_version' );
delete_option( 'aura_worker_site_token' );
delete_option( 'aura_worker_allowed_ips' );
delete_option( 'aura_worker_allowed_domains' );
delete_option( 'aura_worker_dashboard_url' );
delete_option( 'aura_worker_connect_lock' );
delete_option( 'aura_worker_reconnect_needed' ); // mirrors Aura_Worker_Magic_Link::RECONNECT_NEEDED_OPTION // the site-wide connect claim (mirrors Aura_Worker_Magic_Link::SITE_CLAIM)
