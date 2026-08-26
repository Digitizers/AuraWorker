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

// A network-activated plugin's uninstall runs ONCE (round-28). Every subsite has
// its own options table and therefore its own Application Password record, so
// the cleanup below is run in each site's context — otherwise administrator
// credentials survive on every site but one, with the plugin gone.
$aura_uninstall_site = static function () {
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
	// PENDING MINTS (round-36): each entry of $record['intents'] is a connect
	// that was interrupted between creating an Application Password and
	// recording which one it is. The plugin's own reconciliation settles those
	// by app_id — and after this file runs there is no plugin left to do it, so
	// uninstall settles them here, or the credentials outlive everything that
	// could find them. Mirrors Aura_Worker_Magic_Link::reconcile_mint_intent().
	$aura_pw_intents  = ( is_array( $aura_pw_record ) && isset( $aura_pw_record['intents'] ) && is_array( $aura_pw_record['intents'] ) ) ? $aura_pw_record['intents'] : array();
	$aura_intents_all_gone = true;
	if ( array() !== $aura_pw_intents && class_exists( 'WP_Application_Passwords' ) ) {
		foreach ( $aura_pw_intents as $aura_intent_app_id => $aura_intent ) {
			$aura_intent_owner = (int) ( $aura_intent['user_id'] ?? 0 );
			if ( $aura_intent_owner <= 0 || '' === (string) $aura_intent_app_id ) {
				continue;
			}
			foreach ( WP_Application_Passwords::get_user_application_passwords( $aura_intent_owner ) as $aura_pw_item ) {
				if ( '' !== (string) ( $aura_pw_item['app_id'] ?? '' ) && (string) $aura_intent_app_id === (string) $aura_pw_item['app_id'] && ! empty( $aura_pw_item['uuid'] ) ) {
					WP_Application_Passwords::delete_application_password( $aura_intent_owner, (string) $aura_pw_item['uuid'] );
					break;
				}
			}
			// PROVEN by the owner's list, exactly as the credential's own
			// revocation is (round-37): delete_application_password() answers
			// false for a failed user-meta write too, and an intent discarded
			// over an unproven delete takes with it the only app_id that
			// identifies a live administrator credential.
			foreach ( WP_Application_Passwords::get_user_application_passwords( $aura_intent_owner ) as $aura_pw_item ) {
				if ( '' !== (string) ( $aura_pw_item['app_id'] ?? '' ) && (string) $aura_intent_app_id === (string) $aura_pw_item['app_id'] ) {
					$aura_intents_all_gone = false;
					break;
				}
			}
		}
	} elseif ( array() !== $aura_pw_intents ) {
		$aura_intents_all_gone = false; // no Application Passwords API here to prove anything
	}
	$aura_pw_tracked = ( $aura_pw_owner > 0 && '' !== $aura_pw_uuid );
	// A record that is NOT usable — present but malformed — names a password this
	// code cannot delete, and the connect path treats it as a possibly live orphan
	// (round-13). Uninstall must not discard it either (round-15): it is the only
	// thing left pointing at an administrator credential that may still work, so it
	// stays behind for manual recovery. Nothing recorded at all → nothing to keep.
	// A record that holds only pending intents describes no credential the
	// uninstall could not reach, so nothing is kept for it either.
	$aura_pw_intent_only = ( ! $aura_pw_tracked && array() !== $aura_pw_intents && $aura_intents_all_gone );
	$aura_pw_present     = ( null !== $aura_pw_record && false !== $aura_pw_record && '' !== $aura_pw_record );
	$aura_pw_gone        = ( ! $aura_pw_present || $aura_pw_intent_only );
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
	delete_option( 'aura_worker_connect_lock' ); // the site-wide connect claim (mirrors Aura_Worker_Magic_Link::SITE_CLAIM)
};

if ( is_multisite() && function_exists( 'get_sites' ) && function_exists( 'switch_to_blog' ) ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $aura_blog_id ) {
		switch_to_blog( (int) $aura_blog_id );
		$aura_uninstall_site();
		restore_current_blog();
	}
} else {
	$aura_uninstall_site();
}
