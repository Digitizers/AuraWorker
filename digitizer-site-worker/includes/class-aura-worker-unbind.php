<?php
/**
 * The unbind marker (#434, spec §2.3). ONE option, written under the site
 * claim by Phase A of an unbind; every mutation boundary reads it uncached
 * and refuses while it is set. Phase B (cleanup) lives here too (Task 4).
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Aura_Worker_Unbind {

	/** The marker option. Autoload 'no' — read only by the request that needs it, never on every page load. */
	const OPTION = 'aura_worker_unbound';

	/** Throttle gate for Phase B cleanup (Task 4). */
	const FINISH_TRANSIENT = 'aura_worker_unbind_finish';

	/** Minimum seconds between Phase-B attempts (Task 4). */
	const FINISH_THROTTLE = 300;

	/**
	 * Wire the init-hooked Phase B sweep. The body is filled in Task 4 —
	 * this task only introduces the hook registration.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_finish' ) );
	}

	/**
	 * The marker, straight from the database — never the option cache. Whether
	 * a site is unbound is a security decision, and a stale cached copy could
	 * let a mutation through a moment after Phase A wrote the refusal.
	 *
	 * @return array|null The marker, or null when absent or unreadable.
	 */
	public static function read(): ?array {
		$raw = Aura_Worker_Rules::read_option_uncached( self::OPTION );
		$m   = is_string( $raw ) ? maybe_unserialize( $raw ) : $raw;
		if ( ! is_array( $m ) || ! isset( $m['site'], $m['site_ref'], $m['client'], $m['seq'] ) ) {
			return null;
		}
		$m['app_password_uuids'] = isset( $m['app_password_uuids'] ) && is_array( $m['app_password_uuids'] )
			? array_values( array_map( 'strval', $m['app_password_uuids'] ) )
			: array();
		$m['app_password_users'] = isset( $m['app_password_users'] ) && is_array( $m['app_password_users'] )
			? $m['app_password_users']
			: array();
		return $m;
	}

	/**
	 * Is this site currently unbound?
	 *
	 * @return bool
	 */
	public static function is_set(): bool {
		return null !== self::read();
	}

	/**
	 * The two fields `/status` reports — never the whole marker, which carries
	 * the app-password UUIDs and the connecting user.
	 *
	 * @return array{at:string,site_ref:string}|null
	 */
	public static function status_fragment(): ?array {
		$m = self::read();
		return $m ? array(
			'at'       => (string) $m['at'],
			'site_ref' => (string) $m['site_ref'],
		) : null;
	}

	/**
	 * Write the marker only while $fence holds the site claim; verified by an
	 * uncached read-back — the same discipline every other claim-conditional
	 * install write in this plugin follows. The row is created with
	 * add_option() (autoload 'no') when absent, since
	 * write_option_if_claimed() only UPDATEs an existing row.
	 *
	 * @param array  $marker The marker to store.
	 * @param string $fence  The caller's site-claim fence.
	 * @return bool True once the write is confirmed by an uncached read-back.
	 */
	public static function write_under_claim( array $marker, string $fence ): bool {
		if ( '' === $fence || ! Aura_Worker_Magic_Link::holds_site_claim( $fence ) ) {
			return false;
		}
		if ( null === Aura_Worker_Rules::read_option_uncached( self::OPTION ) ) {
			add_option( self::OPTION, array(), '', 'no' );
		}
		$ok = Aura_Worker_Rules::write_option_if_claimed( self::OPTION, $marker, Aura_Worker_Magic_Link::SITE_CLAIM, $fence, 'no' );
		if ( ! $ok ) {
			return false;
		}
		$back = self::read();
		return is_array( $back ) && $back['site'] === $marker['site'] && (int) $back['seq'] === (int) $marker['seq'];
	}

	/**
	 * Delete the marker only while $fence holds the site claim; verified by an
	 * uncached read.
	 *
	 * @param string $fence The caller's site-claim fence.
	 * @return bool True once the marker is confirmed absent.
	 */
	public static function delete_under_claim( string $fence ): bool {
		if ( '' === $fence || ! Aura_Worker_Magic_Link::holds_site_claim( $fence ) ) {
			return false;
		}
		Aura_Worker_Rules::delete_option_if_claimed( self::OPTION, Aura_Worker_Magic_Link::SITE_CLAIM, $fence );
		return null === Aura_Worker_Rules::read_option_uncached( self::OPTION );
	}

	/**
	 * The refusal every mutation boundary answers with while the marker is set
	 * (wired to those boundaries in a later task).
	 *
	 * @return WP_Error
	 */
	public static function refusal(): WP_Error {
		return new WP_Error(
			'aura_site_unbound',
			__( 'This site was disconnected by Aura; reconnect it from the site\'s SiteAgent settings.', 'digitizer-site-worker' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Phase B cleanup (Task 4). Empty for now — init() only registers the hook
	 * this task; nothing runs from it yet.
	 */
	public static function maybe_finish(): void {}
}
