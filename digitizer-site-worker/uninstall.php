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
	$aura_pw_tracked = ( $aura_pw_owner > 0 && '' !== $aura_pw_uuid );
	// PENDING MINTS (round-36): each entry of $record['intents'] is a connect
	// that was interrupted between creating an Application Password and
	// recording which one it is. The plugin's own reconciliation settles those
	// by app_id — and after this file runs there is no plugin left to do it, so
	// uninstall settles them here, or the credentials outlive everything that
	// could find them. Mirrors Aura_Worker_Magic_Link::reconcile_mint_intent().
	$aura_pw_intents       = ( is_array( $aura_pw_record ) && isset( $aura_pw_record['intents'] ) && is_array( $aura_pw_record['intents'] ) ) ? $aura_pw_record['intents'] : array();
	$aura_intents_all_gone = true;
	foreach ( $aura_pw_intents as $aura_intent_app_id => $aura_intent ) {
		$aura_intent_owner = (int) ( $aura_intent['user_id'] ?? 0 );
		if ( $aura_intent_owner <= 0 || '' === (string) $aura_intent_app_id || ! class_exists( 'WP_Application_Passwords' ) ) {
			$aura_intents_all_gone = false; // nothing here can settle it
			continue;
		}
		$aura_intent_uuid = '';
		foreach ( WP_Application_Passwords::get_user_application_passwords( $aura_intent_owner ) as $aura_pw_item ) {
			if ( '' !== (string) ( $aura_pw_item['app_id'] ?? '' ) && (string) $aura_intent_app_id === (string) $aura_pw_item['app_id'] && ! empty( $aura_pw_item['uuid'] ) ) {
				$aura_intent_uuid = (string) $aura_pw_item['uuid'];
				break;
			}
		}
		if ( '' === $aura_intent_uuid ) {
			// Nothing was created under this intent YET. Absence at scan time
			// settles nothing (round-38): its connect may be paused between
			// recording the intent and creating the password, and a request
			// already loaded resumes perfectly well after the plugin is gone.
			// The record is kept — one option row against a credential nothing
			// could ever find.
			$aura_intents_all_gone = false;
			continue;
		}
		WP_Application_Passwords::delete_application_password( $aura_intent_owner, $aura_intent_uuid );
		// PROVEN by the owner's list, exactly as the credential's own revocation
		// is (round-37): delete_application_password() answers false for a
		// failed user-meta write too, and an intent discarded over an unproven
		// delete takes with it the only app_id that identifies a live
		// administrator credential.
		foreach ( WP_Application_Passwords::get_user_application_passwords( $aura_intent_owner ) as $aura_pw_item ) {
			if ( $aura_intent_uuid === (string) ( $aura_pw_item['uuid'] ?? '' ) ) {
				$aura_intents_all_gone = false;
				break;
			}
		}
	}

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
		// …and only together with every pending mint: the record carries both,
		// so it goes only when nothing it describes is left.
		$aura_pw_gone = $aura_intents_all_gone;
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

	// THE KEY SET IS NOT WRITTEN DOWN HERE (#434 Task 10).
	//
	// This file used to name every option it removed, one delete_option() per
	// key, and the list was maintained by whoever happened to remember. It fell
	// behind — twice over. `aura_worker_unbound` (the unbind marker) survived an
	// uninstall, so a reinstall minted a fresh token onto a site whose stale
	// marker refused every authenticated mutation, with nothing left on disk to
	// explain why; and the ruleset, the gateway key, the connect user, the rule
	// counters and both throttle transients were never removed at all. A
	// hand-maintained enumeration in the one file whose entire job IS the
	// enumeration is the bug, so the enumeration is gone: everything the plugin
	// stores is removed BY NAMESPACE.
	//
	// Three prefixes, because that is the whole of the plugin's key space —
	// including the four families no by-name list could ever have covered, whose
	// names are computed at runtime: the hourly rule counters
	// (aura_worker_rules_{blocked,warned}_h<hour>, written by raw SQL and so
	// invisible to any scan of storage-function calls), the per-rule-per-day
	// expiry notices (aura_worker_rule_expired_<day>_<hash>), the per-IP token
	// throttles (aura_worker_tokfail_<md5>), the per-link connect transients and
	// claims (aura_magic_<id>, aura_magic_claim_<id>) and the single-use grant
	// nonces (aura_grant_nonce_<hash>). tests/unit/UninstallCoverageTest.php
	// computes the plugin's storage keys from source and fails, by name, if one
	// ever lands outside this list.
	//
	// The rows are read first and removed through delete_option()/
	// delete_transient() rather than deleted by one bulk statement, so core
	// maintains `notoptions`, the autoload cache and each transient's timeout
	// row exactly as it would for a by-name removal.
	$aura_prefixes = array( 'aura_worker_', 'aura_magic_', 'aura_grant_nonce_' );
	// The one row that may outlive this uninstall: a tracking record whose
	// Application Password revocation could not be PROVEN is deliberately kept
	// above, and the sweep must not undo that decision.
	$aura_keep = $aura_pw_gone ? array() : array( 'aura_worker_app_password' );

	global $wpdb;
	// The WHOLE literal is escaped, never a prefix concatenated onto an
	// already-escaped one: the underscores framing `_transient_` are LIKE
	// wildcards, and appending them after esc_like() left them so — matching
	// `xtransientXaura_worker_foreign` too, a third-party row that then fell
	// past both `_transient_` guards below and was deleted as an option.
	foreach ( $aura_prefixes as $aura_prefix ) {
		foreach ( array( '', '_transient_', '_transient_timeout_' ) as $aura_scope ) {
			$aura_pattern = $wpdb->esc_like( $aura_scope . $aura_prefix ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Enumerating the plugin's own rows by prefix is the point of this file; there is no core API that lists options by name, and nothing is left to cache after it.
			$aura_names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $aura_pattern ) );
			foreach ( (array) $aura_names as $aura_name ) {
				$aura_name = (string) $aura_name;
				if ( in_array( $aura_name, $aura_keep, true ) ) {
					continue;
				}
				if ( 0 === strpos( $aura_name, '_transient_timeout_' ) ) {
					continue; // delete_transient() removes the timeout with its value row
				}
				if ( 0 === strpos( $aura_name, '_transient_' ) ) {
					delete_transient( substr( $aura_name, strlen( '_transient_' ) ) );
					continue;
				}
				delete_option( $aura_name );
			}
		}
	}

	// A site with a PERSISTENT object cache keeps its transients out of the
	// options table entirely, so the sweep above sees no row for them. The two
	// whose names are fixed are therefore also deleted by name; the computed
	// ones (aura_magic_<id>, aura_worker_tokfail_<md5>) cannot be, and expire on
	// their own within 30 minutes.
	delete_transient( 'aura_worker_token_reveal' );  // the one-time token reveal (activation / regeneration)
	delete_transient( 'aura_worker_unbind_finish' ); // the Phase B self-heal throttle (mirrors Aura_Worker_Unbind::FINISH_TRANSIENT)
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
