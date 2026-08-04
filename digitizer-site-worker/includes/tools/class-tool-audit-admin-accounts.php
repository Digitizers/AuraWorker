<?php
/**
 * MCP Tool: audit_admin_accounts
 *
 * Read-only privileged-account audit: administrators with registration
 * recency, users holding admin capabilities outside the administrator role,
 * per-admin application-password counts, and (on multisite) network super
 * admins read directly from the pre-size-checked site_admins option.
 * Facts, not verdicts.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Tool_Audit_Admin_Accounts extends Aura_Tool_Base {

	/** Max accounts returned. */
	const MAX_ACCOUNTS = 200;

	/** Raw serialized _application_passwords length considered parse-safe. */
	const MAX_APP_PASSWORD_BYTES = 262144; // 256 KB.

	/** Raw serialized site_admins network-option length considered parse-safe. */
	const MAX_SITE_ADMINS_BYTES = 1048576; // 1 MB.

	/** "Recently created" horizon in days. */
	const RECENT_DAYS = 30;

	public function get_name() {
		return 'audit_admin_accounts';
	}

	public function get_description() {
		return 'Read-only privileged-account audit: lists administrators with registration recency, users holding admin capabilities outside the administrator role, per-admin application-password counts, and on multisite the network super admins (read from the site_admins option after a raw-size pre-check). Returns bounded facts; makes no changes.';
	}

	public function get_parameters() {
		return array();
	}

	public function get_returns() {
		return array(
			'accounts'     => 'array — { user_login, user_id, roles, is_admin, capability_outside_role, user_registered, recently_created, network_super_admin, app_passwords }',
			'super_admins' => 'array|string — multisite network super-admin logins, or "oversized_skipped" when the raw site_admins option exceeds the parse-safe size (itself a red flag); "not_multisite" on single site',
			'coverage'     => 'object — { total_seen, returned, truncated, cap } bounded-coverage contract',
		);
	}

	/**
	 * Read-only: never mutates the site.
	 */
	public function get_annotations() {
		return array(
			'read_only'         => true,
			'destructive'       => false,
			'requires_approval' => false,
			'supports_preview'  => false,
		);
	}

	public function execute( $params ) {
		$accounts   = array();
		$truncated  = false;
		$cap        = '';
		$total_seen = 0;

		// Multisite super admins FIRST — network-level membership requires
		// neither an administrator role nor current-site membership, so a
		// per-site query alone can omit the highest-privileged accounts.
		$super_admins  = 'not_multisite';
		$super_logins  = array();
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$super_admins = $this->read_super_admins();
			if ( is_array( $super_admins ) ) {
				$super_logins = $super_admins;
			}
		}

		// Per-site privileged users: the UNION of role=administrator and
		// capability=manage_options. Either alone has a hole — a customized
		// administrator role stripped of manage_options (but keeping other
		// privileged caps) escapes the capability query, and a non-admin role
		// granted manage_options escapes the role query. Deduped by user ID.
		$by_role = get_users(
			array(
				'role'   => 'administrator',
				'number' => static::MAX_ACCOUNTS + 1,
				'fields' => 'all',
			)
		);
		$by_cap  = get_users(
			array(
				'capability' => 'manage_options',
				'number'     => static::MAX_ACCOUNTS + 1,
				'fields'     => 'all',
			)
		);

		$users = array();
		foreach ( array_merge( (array) $by_role, (array) $by_cap ) as $candidate ) {
			if ( is_object( $candidate ) && isset( $candidate->ID ) && ! isset( $users[ (int) $candidate->ID ] ) ) {
				$users[ (int) $candidate->ID ] = $candidate;
			}
		}

		$now = time();
		foreach ( (array) $users as $user ) {
			$total_seen++;
			if ( count( $accounts ) >= static::MAX_ACCOUNTS ) {
				$truncated = true;
				$cap       = 'max_accounts';
				break;
			}

			$roles      = isset( $user->roles ) ? array_values( (array) $user->roles ) : array();
			$is_admin   = in_array( 'administrator', $roles, true );
			$registered = isset( $user->user_registered ) ? (string) $user->user_registered : '';
			$reg_ts     = $registered ? strtotime( $registered ) : false;

			$accounts[] = array(
				'user_login'              => (string) $user->user_login,
				'user_id'                 => (int) $user->ID,
				'roles'                   => $roles,
				'is_admin'                => $is_admin,
				'capability_outside_role' => ! $is_admin,
				'user_registered'         => $registered,
				'recently_created'        => ( false !== $reg_ts ) && ( ( $now - $reg_ts ) < static::RECENT_DAYS * DAY_IN_SECONDS ),
				'network_super_admin'     => in_array( (string) $user->user_login, $super_logins, true ),
				'app_passwords'           => $this->app_password_count( (int) $user->ID ),
			);
		}

		return array(
			'accounts'     => $accounts,
			'super_admins' => $super_admins,
			'coverage'     => array(
				'total_seen' => $total_seen,
				'returned'   => count( $accounts ),
				'truncated'  => $truncated,
				'cap'        => $truncated ? $cap : '',
			),
		);
	}

	/**
	 * Reads network super admins from the site_admins option DIRECTLY.
	 *
	 * get_super_admins() is never called: it retrieves and unserializes the
	 * complete network option before any cap could run. This pre-checks the
	 * raw serialized LENGTH() and only then reads the value.
	 *
	 * @return array|string Logins, or 'oversized_skipped'.
	 */
	protected function read_super_admins() {
		global $wpdb;

		$size = 0;
		if ( isset( $wpdb ) && method_exists( $wpdb, 'get_var' ) ) {
			$meta_table = isset( $wpdb->sitemeta ) ? $wpdb->sitemeta : $wpdb->prefix . 'sitemeta';
			// Scope to the CURRENT network: on multi-network installs sitemeta
			// holds one site_admins row per site_id, and an arbitrary row's
			// size says nothing about the row get_site_option() will read.
			$network_id = function_exists( 'get_current_network_id' )
				? (int) get_current_network_id()
				: ( isset( $wpdb->siteid ) ? (int) $wpdb->siteid : 1 );
			$size       = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT LENGTH(meta_value) FROM {$meta_table} WHERE site_id = %d AND meta_key = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$network_id,
					'site_admins'
				)
			);
		}

		if ( $size > static::MAX_SITE_ADMINS_BYTES ) {
			return 'oversized_skipped';
		}

		$value = function_exists( 'get_site_option' ) ? get_site_option( 'site_admins', array() ) : array();
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_map( 'strval', array_values( $value ) );
	}

	/**
	 * Per-user application-password count with a raw-length pre-check.
	 *
	 * WordPress stores the user's COMPLETE list as one serialized usermeta
	 * value, so counting requires unserializing it — the pre-check keeps an
	 * attacker-inflated value from being parsed at all.
	 *
	 * @param int $user_id User id.
	 * @return int|string Count, or 'oversized_skipped'.
	 */
	protected function app_password_count( $user_id ) {
		global $wpdb;

		$size = 0;
		if ( isset( $wpdb ) && method_exists( $wpdb, 'get_var' ) ) {
			$usermeta_table = isset( $wpdb->usermeta ) ? $wpdb->usermeta : $wpdb->prefix . 'usermeta';
			$size           = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT LENGTH(meta_value) FROM {$usermeta_table} WHERE user_id = %d AND meta_key = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$user_id,
					'_application_passwords'
				)
			);
		}

		if ( $size > static::MAX_APP_PASSWORD_BYTES ) {
			return 'oversized_skipped';
		}

		$meta = get_user_meta( $user_id, '_application_passwords', true );
		return is_array( $meta ) ? count( $meta ) : 0;
	}
}
