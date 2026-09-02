<?php
/**
 * The Elementor door's log: one option row per entry, retained until Aura
 * acknowledges it (spec §3.8, §3.10).
 *
 * Every number is allocated by the row's own INSERT above an ack floor that
 * only rises — there is no counter option to leak, and a number exists only
 * once its row does, so a contiguous-prefix ack never meets a hole. Nothing
 * but an ack deletes a row; a request that backs out settles its row
 * `discarded` instead.
 *
 * @package Aura_Worker
 * @since 2.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Worker_Door_Log {

	const PREFIX       = 'aura_worker_door_log_';
	const FLOOR        = 'aura_worker_door_log_acked';
	const EPOCH        = 'aura_worker_door_epoch';
	const FULL_MARKER  = 'aura_worker_door_log_full_since';
	const FULL_COUNTER = 'aura_worker_door_log_full_refused';
	const MAX_UNACKED  = 2000;
	const PAGE         = 100;
	/** How many INSERT collisions to ride through before giving up. */
	const ALLOC_TRIES  = 8;
	/** A log ROW: the prefix followed by digits only — never the floor, marker or counter options that share the prefix. */
	const ROW_REGEXP   = '^aura_worker_door_log_[0-9]+$';

	const TERMINAL = array( 'ok', 'refused', 'failed', 'interrupted', 'discarded', 'held' );

	/**
	 * The site's log epoch — minted once, new only when the option store is
	 * gone (a reinstall). A credential re-save or a reconnect never moves it.
	 *
	 * @return string
	 */
	public static function epoch() {
		$cur = get_option( self::EPOCH, '' );
		if ( is_string( $cur ) && '' !== $cur ) {
			return $cur;
		}
		add_option( self::EPOCH, wp_generate_uuid4(), '', 'no' ); // a concurrent mint loses the INSERT and reads the winner's
		$cur = get_option( self::EPOCH, '' );
		return is_string( $cur ) ? $cur : '';
	}

	/**
	 * Allocate the next seq by inserting the row that owns it.
	 *
	 * @param array $entry Fields (ability, actor, touches, verdict, …).
	 * @return int|WP_Error seq, or `aura_log_failed`.
	 */
	public static function open_pending( array $entry ) {
		for ( $try = 0; $try < self::ALLOC_TRIES; $try++ ) {
			$seq = max( self::highest_row_seq(), self::floor() ) + 1;
			$row = array_merge(
				$entry,
				array(
					'seq'      => $seq,
					'at'       => gmdate( 'c' ),
					'result'   => 'pending',
					'admitted' => false,
				)
			);
			if ( add_option( self::PREFIX . $seq, $row, '', 'no' ) ) {
				return $seq;
			}
			// Unique-name collision: a concurrent writer took this number.
		}
		return new WP_Error( 'aura_log_failed', 'The door log could not record this call; it was not run.', array( 'status' => 503 ) );
	}

	/** @param int $seq Seq. @return bool */
	public static function admit( $seq ) {
		return self::patch( (int) $seq, array( 'admitted' => true ) );
	}

	/**
	 * @param int   $seq      Seq.
	 * @param array $terminal Must carry `result` in TERMINAL.
	 * @return bool
	 */
	public static function settle( $seq, array $terminal ) {
		if ( ! isset( $terminal['result'] ) || ! in_array( $terminal['result'], self::TERMINAL, true ) ) {
			return false;
		}
		$terminal['settled_at'] = gmdate( 'c' );
		// A terminal row is SERVED, so it is admitted in the same write
		// (Codex round-6 P1 on #499): an `admit()` that failed a moment before
		// a `settle()` that succeeded would otherwise leave a terminal row
		// `log_after` stops at and the reconciler never revisits.
		$terminal['admitted'] = true;
		return self::patch( (int) $seq, $terminal );
	}

	/**
	 * A discarded row is ADMITTED in the same write (Codex round-5 P1 on
	 * #499): `log_after` stops at an un-admitted row, and a discard that left
	 * `admitted: false` would hide every later entry for ever.
	 *
	 * @param int $seq Seq. @return bool
	 */
	public static function discard( $seq ) {
		return self::settle( $seq, array( 'result' => 'discarded' ) ); // settle() admits
	}

	/**
	 * Patch a row that is still pending — the durable in-flight writes
	 * (snapshot id, watermark, created ids, collateral ids). Never changes
	 * `result`; never touches a row that already settled.
	 *
	 * @param int   $seq    Seq.
	 * @param array $fields Fields (without `result`).
	 * @return bool
	 */
	public static function patch_pending( $seq, array $fields ) {
		unset( $fields['result'] );
		$option = self::PREFIX . (int) $seq;
		$before = self::row_from_db( $option );
		if ( null === $before || 'pending' !== ( $before['result'] ?? '' ) ) {
			return false;
		}
		return self::write_option_where( $option, array_merge( $before, $fields ), $before );
	}

	/** @param int $seq Seq. @return array|null */
	public static function get( $seq ) {
		$row = get_option( self::PREFIX . (int) $seq, null );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Conditional in-place update: compare-and-set on the bytes read a moment
	 * ago, so a row another writer deleted is never recreated and a row it
	 * changed is never overwritten blind.
	 *
	 * @param int   $seq    Seq.
	 * @param array $fields Fields to merge.
	 * @return bool ONE row changed.
	 */
	private static function patch( $seq, array $fields ) {
		$option = self::PREFIX . $seq;
		$before = self::row_from_db( $option );
		if ( null === $before ) {
			return false;
		}
		$after = array_merge( $before, $fields );
		return self::write_option_where( $option, $after, $before );
	}

	/**
	 * `UPDATE … SET option_value = %s WHERE option_name = %s AND option_value = %s`.
	 * Public: the holds store and the governor use the same primitive.
	 *
	 * @param string $option Option name.
	 * @param array  $after  New value.
	 * @param array  $before The value the caller read (the predicate).
	 * @return bool
	 */
	public static function write_option_where( $option, array $after, array $before ) {
		global $wpdb;
		$wpdb->last_error = '';
		$n                = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				maybe_serialize( $after ),
				$option,
				maybe_serialize( $before )
			)
		);
		wp_cache_delete( $option, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return 1 === (int) $n && '' === (string) $wpdb->last_error;
	}

	/**
	 * The row as the DATABASE holds it — never this request's option cache.
	 *
	 * @param string $option Option name.
	 * @return array|null
	 */
	private static function row_from_db( $option ) {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option ) );
		if ( null === $raw ) {
			return null;
		}
		$val = maybe_unserialize( $raw );
		return is_array( $val ) ? $val : null;
	}

	/** @return int */
	public static function floor() {
		return (int) get_option( self::FLOOR, 0 );
	}

	/**
	 * The highest seq that has a row.
	 *
	 * @return int
	 */
	public static function highest_row_seq() {
		global $wpdb;
		$like = $wpdb->esc_like( self::PREFIX ) . '%';
		// The NUMERIC rows only: the floor, the closure marker and the refusal
		// counter share this prefix and a non-numeric suffix casts to 0
		// (Codex round-4 P1 on #499 — an ack that deleted the floor would
		// restart numbering at 1 under Aura's cursor).
		$n = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(CAST(SUBSTRING(option_name, %d) AS UNSIGNED)) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name REGEXP %s",
				strlen( self::PREFIX ) + 1,
				$like,
				self::ROW_REGEXP
			)
		);
		return (int) $n;
	}

	/** @return int */
	public static function count_unacked() {
		global $wpdb;
		$like = $wpdb->esc_like( self::PREFIX ) . '%';
		$n    = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name REGEXP %s AND CAST(SUBSTRING(option_name, %d) AS UNSIGNED) > %d",
				$like,
				self::ROW_REGEXP,
				strlen( self::PREFIX ) + 1,
				self::floor()
			)
		);
		return (int) $n;
	}

	/** @return bool */
	public static function is_closed() {
		return false !== get_option( self::FULL_MARKER, false );
	}

	/** One owner: the INSERT. */
	public static function close() {
		add_option( self::FULL_MARKER, gmdate( 'c' ), '', 'no' );
	}

	/** Atomic increment, no row per refusal. */
	public static function bump_refused() {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no') ON DUPLICATE KEY UPDATE option_value = option_value + 1",
				self::FULL_COUNTER
			)
		);
		wp_cache_delete( self::FULL_COUNTER, 'options' );
	}

	/**
	 * @return array{ since: string|null, refused: int }|null
	 */
	public static function full_report() {
		if ( ! self::is_closed() ) {
			return null;
		}
		return array(
			'since'   => (string) get_option( self::FULL_MARKER, '' ),
			'refused' => (int) get_option( self::FULL_COUNTER, 0 ),
		);
	}

	/**
	 * Aura committed everything up to $seq: raise the floor (upward only,
	 * BEFORE the delete), delete the rows, reopen if under the bound.
	 *
	 * @param string $epoch The epoch Aura is acking.
	 * @param int    $seq   Highest seq of its contiguous committed prefix.
	 * @return array{ acked: int, floor: int }
	 */
	public static function ack( $epoch, $seq ) {
		global $wpdb;
		$seq = (int) $seq;
		if ( ! is_string( $epoch ) || $epoch !== self::epoch() ) {
			return array( 'acked' => 0, 'floor' => self::floor() );
		}
		// Floor: INSERT if absent, else raise only when lower. The floor as it
		// stood BEFORE the raise bounds the cache invalidation below to the
		// newly acked range — never 1..seq on a site with a long history
		// (Codex round-5 P2).
		add_option( self::FLOOR, 0, '', 'no' );
		$prev_floor_before_raise = self::floor();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d",
				(string) $seq,
				self::FLOOR,
				$seq
			)
		);
		wp_cache_delete( self::FLOOR, 'options' );
		$floor = self::floor();
		$acked = 0;
		if ( $seq <= $floor ) {
			$prev_floor = $prev_floor_before_raise; // read BEFORE the raise, below
			$like  = $wpdb->esc_like( self::PREFIX ) . '%';
			$acked = (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name REGEXP %s AND CAST(SUBSTRING(option_name, %d) AS UNSIGNED) <= %d",
					$like,
					self::ROW_REGEXP,
					strlen( self::PREFIX ) + 1,
					$seq
				)
			);
			for ( $i = $prev_floor + 1; $i <= $seq; $i++ ) {
				wp_cache_delete( self::PREFIX . $i, 'options' );
			}
		}
		if ( self::is_closed() && self::count_unacked() < self::MAX_UNACKED ) {
			delete_option( self::FULL_MARKER );
			delete_option( self::FULL_COUNTER );
		}
		return array( 'acked' => $acked, 'floor' => $floor );
	}

	/**
	 * Terminal entries with seq > $after, ascending, stopping at the first
	 * pending or un-admitted row — the site-side contiguous prefix.
	 *
	 * @param int $after Aura's cursor.
	 * @param int $limit Page size.
	 * @return array[]
	 */
	public static function log_after( $after, $limit = self::PAGE ) {
		$after = max( (int) $after, self::floor() );
		$out   = array();
		$seq   = $after;
		$top   = self::highest_row_seq();
		while ( count( $out ) < $limit && $seq < $top ) {
			$seq++;
			$row = self::get( $seq );
			if ( null === $row ) {
				break; // a number with no row is a hole — cannot happen by construction; stop rather than skip
			}
			if ( empty( $row['admitted'] ) || 'pending' === ( $row['result'] ?? 'pending' ) ) {
				break;
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Mints a new epoch, clears the floor and closure state, and leaves every
	 * log row in place — the reconciler's recovery from an epoch mismatch it
	 * cannot otherwise resolve (Task 9).
	 *
	 * @return string The new epoch.
	 */
	public static function rotate_epoch() {
		delete_option( self::EPOCH );
		delete_option( self::FLOOR );
		delete_option( self::FULL_MARKER );
		delete_option( self::FULL_COUNTER );
		return self::epoch();
	}

	/**
	 * Rows still pending (or never admitted) whose `at` is older than $ms.
	 *
	 * @param int $ms Age in milliseconds.
	 * @return array[]
	 */
	public static function stale_pending( $ms ) {
		$cut = time() - (int) floor( $ms / 1000 );
		$out = array();
		$top = self::highest_row_seq();
		for ( $seq = self::floor() + 1; $seq <= $top; $seq++ ) {
			$row = self::get( $seq );
			if ( null === $row || 'pending' !== ( $row['result'] ?? '' ) ) {
				continue;
			}
			if ( strtotime( (string) ( $row['at'] ?? '' ) ) <= $cut ) {
				$out[] = $row;
			}
		}
		return $out;
	}
}
