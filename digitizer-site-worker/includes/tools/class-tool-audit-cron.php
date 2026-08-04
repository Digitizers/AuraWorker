<?php
/**
 * MCP Tool: audit_cron
 *
 * Read-only WP-Cron audit: bounded event inventory with two fact-flags —
 * sub-60-second schedules and hooks with no callback attached in this
 * request context (explicitly NOT an orphan verdict). Pre-checks the raw
 * cron option size before materializing anything the tool itself would
 * unserialize into its response.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Tool_Audit_Cron extends Aura_Tool_Base {

	/** Hard cap on inventoried events. */
	const MAX_EVENTS = 500;

	/** Raw serialized cron-option length considered parse-safe (~1 MB). */
	const MAX_CRON_BYTES = 1048576;

	public function get_name() {
		return 'audit_cron';
	}

	public function get_description() {
		return 'Read-only WP-Cron audit: bounded inventory of scheduled events (hook, schedule, interval, next run) with fact-flags for sub-60-second schedules and hooks whose callbacks are not registered in this request context (not proof of orphanhood). Pre-checks the raw cron option size; makes no changes.';
	}

	public function get_parameters() {
		return array();
	}

	public function get_returns() {
		return array(
			'events'   => 'array — { hook, schedule, interval, next_run, args_digest, interval_lt_60s, unresolved_in_this_context }',
			'error'    => 'string — "cron_option_oversized" (with size_bytes) when the raw cron option exceeds the parse-safe threshold; the size itself is a signal',
			'coverage' => 'object — { total_seen, returned, truncated, cap } bounded-coverage contract',
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
		// Size pre-check. Honest scope: `cron` is an autoloaded option, so
		// WordPress's own bootstrap already loaded the raw value — this bounds
		// what THIS TOOL unserializes and returns, not bootstrap memory.
		$size = $this->raw_cron_size();
		if ( $size > static::MAX_CRON_BYTES ) {
			return array(
				'error'      => 'cron_option_oversized',
				'size_bytes' => $size,
			);
		}

		$crons     = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
		$schedules = function_exists( 'wp_get_schedules' ) ? wp_get_schedules() : array();

		$events     = array();
		$total_seen = 0;
		$truncated  = false;

		foreach ( (array) $crons as $timestamp => $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue;
			}
			foreach ( $hooks as $hook => $instances ) {
				if ( ! is_array( $instances ) ) {
					continue;
				}
				foreach ( $instances as $instance_key => $instance ) {
					$total_seen++;
					if ( count( $events ) >= static::MAX_EVENTS ) {
						$truncated = true;
						continue;
					}

					$schedule = isset( $instance['schedule'] ) ? (string) $instance['schedule'] : '';
					$interval = 0;
					if ( isset( $instance['interval'] ) ) {
						$interval = (int) $instance['interval'];
					} elseif ( $schedule && isset( $schedules[ $schedule ]['interval'] ) ) {
						$interval = (int) $schedules[ $schedule ]['interval'];
					}

					// The cron array KEY is WordPress's own md5(serialize(args))
					// for this instance — reuse it instead of re-serializing:
					// serialize() on an object argument would invoke its
					// __serialize()/__sleep() userland hooks, which a read-only
					// audit must never execute.

					$events[] = array(
						'hook'                       => (string) $hook,
						'schedule'                   => $schedule ? $schedule : 'single',
						'interval'                   => $interval,
						'next_run'                   => (int) $timestamp,
						'args_digest'                => (string) $instance_key,
						'interval_lt_60s'            => ( $interval > 0 && $interval < 60 ),
						// Fact, not verdict: plugins may register callbacks only
						// during an actual cron request, and hook names carry no
						// ownership mapping — absence here is NOT orphanhood.
						'unresolved_in_this_context' => function_exists( 'has_action' ) ? ( false === has_action( (string) $hook ) ) : false,
					);
				}
			}
		}

		return array(
			'events'   => $events,
			'coverage' => array(
				'total_seen' => $total_seen,
				'returned'   => count( $events ),
				'truncated'  => $truncated,
				'cap'        => $truncated ? 'max_events' : '',
			),
		);
	}

	/**
	 * Raw serialized size of the cron option via a LENGTH() query.
	 *
	 * @return int Bytes (0 when unknown).
	 */
	protected function raw_cron_size() {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return 0;
		}

		$options_table = isset( $wpdb->options ) ? $wpdb->options : $wpdb->prefix . 'options';

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT LENGTH(option_value) FROM {$options_table} WHERE option_name = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'cron'
			)
		);
	}
}
