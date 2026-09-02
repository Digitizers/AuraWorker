<?php
/**
 * Held Elementor-door writes (spec §3.6, §3.7).
 *
 * One option row per hold, never one array: a real conditional INSERT
 * (Aura_Worker_Door_Log::insert_unique(), never add_option() — WordPress
 * core's add_option() is an INSERT … ON DUPLICATE KEY UPDATE behind a cached
 * existence check, so a racer that passed the check can still overwrite and
 * get `true` back; it is not a mutex) on the options table's unique name, so
 * two concurrent holds cannot read-modify-write each other away. A replay
 * CLAIMS a hold by moving it — INSERT the claimed twin, then DELETE the held
 * row, and the delete must remove exactly one row or the claim backs out.
 * Reject and the TTL sweep delete a held row only when it has no claimed
 * twin.
 *
 * @package Aura_Worker
 * @since 2.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Worker_Door_Holds {

	const HELD    = 'aura_worker_door_held_';
	const CLAIMED = 'aura_worker_door_claimed_';
	const TTL_S   = 604800;
	const CAP     = 50;
	const LOCK    = 'aura_worker_door_hold_lock';
	const LOCK_S  = 30;

	/**
	 * @param array $call { ability, input, touches, actor, verdict, rule }.
	 * @return string|WP_Error ref, or aura_hold_queue_full / aura_hold_failed.
	 */
	public static function hold( array $call ) {
		// Count-then-insert is ONE transition under a short mutex: ten requests
		// arriving at a count of 49 would otherwise all pass the check and leave
		// 59 rows (Codex round-9 P2 on #499). insert_unique() on the lock name is
		// a real conditional INSERT on the options table's unique key, the same
		// primitive the creation mutex uses.
		if ( ! self::take_lock() ) {
			return new WP_Error( 'aura_hold_busy', 'This site is admitting another call for approval; retry.', array( 'status' => 503, 'retry_after' => 5 ) );
		}
		try {
			return self::hold_locked( $call );
		} finally {
			delete_option( self::LOCK );
		}
	}

	/**
	 * Takes the hold-queue mutex; a lock older than LOCK_S is a crashed
	 * holder and is replaced — but the replacement is a DELETE fenced on the
	 * exact bytes this call read as stale, never an unconditional
	 * delete_option(). Two racers both meeting the same crashed lock would
	 * otherwise interleave as: A deletes, A inserts (wins), B deletes — B's
	 * unconditional delete has no idea A's row is not the stale one it saw a
	 * moment ago, so it removes A's brand-new lock, B inserts (wins too), and
	 * A's own `finally` release later deletes OUT FROM UNDER B. The cap check
	 * in hold_locked() would then run for both at once — unserialized despite
	 * the mutex (round-1 finding on task 4's review). Fencing the delete on
	 * the read bytes means only the racer whose delete actually hits the
	 * stale row gets to insert next; a racer that loses the delete just loops
	 * and meets whichever lock is there afterwards.
	 *
	 * @return bool
	 */
	private static function take_lock() {
		global $wpdb;
		for ( $i = 0; $i < 3; $i++ ) {
			if ( Aura_Worker_Door_Log::insert_unique( self::LOCK, time() ) ) {
				return true;
			}
			wp_cache_delete( self::LOCK, 'options' );
			$raw = self::raw_bytes( self::LOCK );
			if ( null === $raw ) {
				continue; // the row vanished between the failed insert and this read
			}
			$held = (int) $raw;
			if ( $held && time() - $held > self::LOCK_S ) {
				$wpdb->last_error = '';
				$gone = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK, $raw ) );
				wp_cache_delete( self::LOCK, 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				if ( 1 === (int) $gone ) {
					continue; // whoever's insert_unique() wins next owns it
				}
				// Lost the delete race — some other caller's fence matched
				// instead (or already won and released). Fall through to the
				// same backoff a live lock gets; the next iteration re-reads
				// whatever is there now.
			}
			usleep( 50000 );
		}
		return false;
	}

	/** The body of hold(), run only while the lock is held. */
	private static function hold_locked( array $call ) {
		if ( self::count() >= self::CAP ) {
			return new WP_Error( 'aura_hold_queue_full', 'Aura\'s approval queue for this site is full (50 held calls); ask the operator to act on it.', array( 'status' => 503 ) );
		}
		$ref = 'door_' . wp_generate_uuid4();
		$now = time();
		$row = array(
			'ref'        => $ref,
			'ability'    => (string) $call['ability'],
			'input'      => is_array( $call['input'] ?? null ) ? $call['input'] : array(),
			'touches'    => is_array( $call['touches'] ?? null ) ? $call['touches'] : array(),
			'actor'      => is_array( $call['actor'] ?? null ) ? $call['actor'] : array(),
			'verdict'    => in_array( $call['verdict'] ?? '', array( 'none', 'warn', 'rules_unavailable' ), true ) ? $call['verdict'] : 'none',
			'rule'       => is_array( $call['rule'] ?? null ) ? self::rule_fields( $call['rule'] ) : null,
			'created_at' => gmdate( 'c', $now ),
			'expires_at' => gmdate( 'c', $now + self::TTL_S ),
		);
		if ( ! Aura_Worker_Door_Log::insert_unique( self::HELD . $ref, $row ) ) {
			return new WP_Error( 'aura_hold_failed', 'This site could not store the call for approval; it was not run.', array( 'status' => 503 ) );
		}
		return $ref;
	}

	/** @param array $rule Rule. @return array { key, ruleHash, reason } */
	private static function rule_fields( array $rule ) {
		return array(
			'key'      => (string) ( $rule['key'] ?? '' ),
			'ruleHash' => (string) ( $rule['ruleHash'] ?? '' ),
			'reason'   => (string) ( $rule['reason'] ?? '' ),
		);
	}

	/** @param string $ref Ref. @return array|null */
	public static function get_held( $ref ) {
		$row = get_option( self::HELD . self::clean( $ref ), null );
		return is_array( $row ) ? $row : null;
	}

	/** @param string $ref Ref. @return array|null */
	public static function get_claimed( $ref ) {
		$row = get_option( self::CLAIMED . self::clean( $ref ), null );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Update the warn rule on a held row IN PLACE — a conditional UPDATE on
	 * the existing row, never `update_option()`, which would recreate a row a
	 * reject or the sweep deleted meanwhile (round-16 P1).
	 *
	 * @param string $ref  Ref.
	 * @param array  $rule Rule.
	 * @return bool
	 */
	public static function refresh_rule( $ref, array $rule ) {
		$option = self::HELD . self::clean( $ref );
		$before = self::from_db( $option );
		if ( null === $before ) {
			return false;
		}
		$after            = $before;
		$after['verdict'] = 'warn';
		$after['rule']    = self::rule_fields( $rule );
		return Aura_Worker_Door_Log::write_option_where( $option, $after, $before );
	}

	/**
	 * Claim by MOVE: insert the claimed twin, then delete the held row and
	 * require that delete to remove one row.
	 *
	 * @param string $ref Ref.
	 * @return array|WP_Error The claimed entry, or `not_held`.
	 */
	public static function claim( $ref ) {
		global $wpdb;
		$ref  = self::clean( $ref );
		$held = self::from_db( self::HELD . $ref );
		if ( null === $held ) {
			return self::not_held();
		}
		$claimed               = $held;
		$claimed['claimed_at'] = gmdate( 'c' );
		if ( ! Aura_Worker_Door_Log::insert_unique( self::CLAIMED . $ref, $claimed ) ) {
			return self::not_held(); // already claimed
		}
		$wpdb->last_error = '';
		$gone             = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::HELD . $ref ) );
		wp_cache_delete( self::HELD . $ref, 'options' );
		if ( 1 !== (int) $gone ) {
			// A reject or the sweep won the race: the entry we claimed from
			// no longer exists. Back out; nothing runs.
			delete_option( self::CLAIMED . $ref );
			return self::not_held();
		}
		return $claimed;
	}

	/** @param string $ref Ref. @param int $seq Seq. @return bool */
	public static function stamp_terminal_seq( $ref, $seq ) {
		$option = self::CLAIMED . self::clean( $ref );
		$before = self::from_db( $option );
		if ( null === $before ) {
			return false;
		}
		$after                 = $before;
		$after['terminal_seq'] = (int) $seq;
		return Aura_Worker_Door_Log::write_option_where( $option, $after, $before );
	}

	/** Delete the claimed row and any held twin. @param string $ref Ref. */
	public static function release( $ref ) {
		$ref = self::clean( $ref );
		delete_option( self::CLAIMED . $ref );
		delete_option( self::HELD . $ref );
	}

	/**
	 * @param string $ref Ref.
	 * @return string rejected|already_claimed|not_held
	 */
	public static function reject( $ref ) {
		global $wpdb;
		$ref = self::clean( $ref );
		if ( null !== self::get_claimed( $ref ) ) {
			return 'already_claimed';
		}
		if ( null === self::from_db( self::HELD . $ref ) ) {
			return 'not_held';
		}
		// The delete must remove ONE row, exactly as claim() demands of its
		// own delete (Codex round-8 P1): a replay that claimed between the
		// read above and this statement already moved the row, and a reject
		// reported on top of it would let Aura mark rejected an action whose
		// mutation is running.
		$wpdb->last_error = '';
		$gone             = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::HELD . $ref ) );
		wp_cache_delete( self::HELD . $ref, 'options' );
		return 1 === (int) $gone ? 'rejected' : 'already_claimed';
	}

	/**
	 * What `/status` reports: no inputs, no claimed twins, nothing expired.
	 *
	 * @return array[]
	 */
	public static function listing() {
		$out = array();
		$now = time();
		foreach ( self::rows( self::HELD ) as $ref => $row ) {
			if ( null !== self::get_claimed( $ref ) ) {
				continue;
			}
			if ( strtotime( (string) ( $row['expires_at'] ?? '' ) ) <= $now ) {
				continue;
			}
			$out[] = array(
				'ref'        => $ref,
				'ability'    => $row['ability'] ?? '',
				'actor'      => $row['actor'] ?? array(),
				'touches'    => $row['touches'] ?? array(),
				'verdict'    => $row['verdict'] ?? 'none',
				'rule'       => $row['rule'] ?? null,
				'created_at' => $row['created_at'] ?? '',
			);
		}
		return $out;
	}

	/**
	 * Expire holds; remove a held row whose claimed twin exists (§3.7).
	 *
	 * @param int $now Unix time.
	 */
	public static function sweep( $now ) {
		foreach ( self::rows( self::HELD ) as $ref => $row ) {
			if ( null !== self::get_claimed( $ref ) ) {
				delete_option( self::HELD . $ref ); // the replay's own delete, retried
				continue;
			}
			if ( strtotime( (string) ( $row['expires_at'] ?? '' ) ) <= (int) $now ) {
				delete_option( self::HELD . $ref );
			}
		}
	}

	/**
	 * @param int $ms Age.
	 * @return array[] claimed rows older than $ms, keyed by ref.
	 */
	public static function stale_claims( $ms ) {
		$cut = time() - (int) floor( $ms / 1000 );
		$out = array();
		foreach ( self::rows( self::CLAIMED ) as $ref => $row ) {
			if ( strtotime( (string) ( $row['claimed_at'] ?? '' ) ) <= $cut ) {
				$out[ $ref ] = $row;
			}
		}
		return $out;
	}

	/**
	 * Slots in use: every ref with a held OR a claimed row, counted once
	 * (Codex round-8 P2 — a claim MOVES the row, so counting only held rows
	 * let interrupted replays accumulate beside another fifty holds).
	 *
	 * @return int
	 */
	public static function count() {
		$refs = array_merge( array_keys( self::rows( self::HELD ) ), array_keys( self::rows( self::CLAIMED ) ) );
		return count( array_unique( $refs ) );
	}

	/**
	 * Every row under a prefix, from the database, keyed by ref.
	 *
	 * @param string $prefix HELD or CLAIMED.
	 * @return array<string,array>
	 */
	private static function rows( $prefix ) {
		global $wpdb;
		$like = $wpdb->esc_like( $prefix ) . '%';
		// ARRAY_A, matching Aura_Worker_Rules::sweep_options()'s identical
		// SELECT — $wpdb::OBJECT is the default and would otherwise hand back
		// stdClass rows this class never asks for.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $like ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$val = maybe_unserialize( $r['option_value'] );
			if ( is_array( $val ) ) {
				$out[ substr( $r['option_name'], strlen( $prefix ) ) ] = $val;
			}
		}
		return $out;
	}

	/** @param string $option Option. @return array|null the DATABASE's row. */
	private static function from_db( $option ) {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option ) );
		if ( null === $raw ) {
			return null;
		}
		$val = maybe_unserialize( $raw );
		return is_array( $val ) ? $val : null;
	}

	/**
	 * The DATABASE's raw, still-serialized bytes for one option — never this
	 * request's option cache, and never unserialized. take_lock() fences its
	 * stale-lock delete on exactly these bytes (compared byte-for-byte, the
	 * same way write_option_where()'s UPDATE compares its `$before`), so the
	 * fence can only match the row this call actually read, never a fresher
	 * one a racer already installed.
	 *
	 * @param string $option Option.
	 * @return string|null
	 */
	private static function raw_bytes( $option ) {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option ) );
		return null === $raw ? null : (string) $raw;
	}

	/** @param string $ref Ref. @return string */
	private static function clean( $ref ) {
		return preg_replace( '/[^a-z0-9_-]/', '', (string) $ref );
	}

	/** @return WP_Error */
	private static function not_held() {
		return new WP_Error( 'not_held', 'No held call with that reference.', array( 'status' => 404 ) );
	}
}
