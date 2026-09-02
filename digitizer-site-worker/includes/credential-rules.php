<?php
/**
 * THE CREDENTIAL RULES (#434) — the questions about an Application Password
 * that more than one world has to answer the same way.
 *
 * The unbind marker's `app_password_uuids` is the only field on the site whose
 * EMPTINESS is a security claim. `cleanup()` reads it to decide the credentials
 * are settled and then deletes the site token; `departed_binding_request()` and
 * the authentication refusal read it to recognise a live Application Password;
 * `uninstall.php` reads it to decide whether the marker may be swept away. So
 * "this binding held no credentials" may only ever be said by a list that
 * actually says it — never inferred from one that could not be read.
 *
 * Five rounds of review found that inference in five different places, because
 * each reader re-derived the rule for itself: a truncated aggregate read as a
 * whole list, a row that would not unserialize read as no row, a non-array
 * field normalised to `array()`, an unreadable field treated as "nothing to
 * add", a corrupt ENTRY dropped from an otherwise valid list. Patching them one
 * at a time is what produced the next one. The rule lives here now, and every
 * reader asks it rather than restating it.
 *
 * This file defines pure functions and nothing else — no class, no hooks, no
 * bootstrap — precisely so `uninstall.php`, which must not load the plugin,
 * can require it and be bound by the same rules as the plugin itself. Three
 * review rounds landed in uninstall.php alone because it re-derived, from
 * memory, reasoning the plugin already had.
 *
 * @package Aura_Worker
 */

// Loaded by the plugin bootstrap AND by uninstall.php, which runs with
// WP_UNINSTALL_PLUGIN defined and ABSPATH available — never directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'aura_worker_credential_list' ) ) {
	/**
	 * The uuids a stored credential list proves, or null when it proves
	 * nothing.
	 *
	 * NULL — "unproven" — is returned for every shape that is not a list of
	 * usable uuids, and the caller must treat it as a debt it cannot see
	 * rather than as an absence:
	 *
	 *  - the field is missing, or is not an array. Every writer supplies the
	 *    array, so any other shape is a row this build cannot read.
	 *  - ANY entry is not a string or an int. Dropping such an entry and
	 *    returning the rest asserts that the dropped one named no credential,
	 *    which is exactly the claim that cannot be made — the entry is
	 *    unreadable, and the Application Password it named may be live. (It is
	 *    not stringified either: `strval()` on an object without __toString is
	 *    a PHP 8 Error thrown out of a boundary that runs on nearly every
	 *    request, and an array would degrade to the literal "Array".)
	 *  - ANY entry is an empty string. No writer produces one, and it names no
	 *    credential, so its presence means the row has been altered.
	 *
	 * An EMPTY ARRAY is a real answer and a strong one: the list was read, and
	 * it says this binding holds no credentials.
	 *
	 * @since 2.13.0
	 *
	 * @param mixed $raw Whatever the stored marker holds for the field.
	 * @return string[]|null The uuids, or null when nothing was proved.
	 */
	function aura_worker_credential_list( $raw ) {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$out = array();
		foreach ( $raw as $uuid ) {
			if ( ! is_string( $uuid ) && ! is_int( $uuid ) ) {
				return null;
			}
			$uuid = (string) $uuid;
			if ( '' === $uuid ) {
				return null;
			}
			if ( ! in_array( $uuid, $out, true ) ) {
				$out[] = $uuid;
			}
		}
		return $out;
	}
}

if ( ! function_exists( 'aura_worker_credential_owner' ) ) {
	/**
	 * The user a stored credential OWNER names, or null when it names nobody
	 * this code may act on.
	 *
	 * The other half of the same record, and the same rule (#434 Codex
	 * round-7 P1). `uninstall.php` cast the stored value with `(int)`, and PHP
	 * reads `"42junk"` as 42: a damaged attribution became a confident one, the
	 * revocation asked user 42's list, was told "not there", and reported a
	 * credential belonging to somebody else as settled — after which the sweep
	 * deleted the marker that was its only record. validated() had the correct
	 * test all along, in its own copy, which is exactly the arrangement that
	 * lets one copy be wrong.
	 *
	 * NULL is returned for every shape that is not a positive integer, and it
	 * means the SAME thing on both sides: this site does not know whose
	 * password this is. Phase B's single lookup is authoritative only against
	 * an owner the site actually recorded, so a value that names nobody must
	 * never be passed off as one — and 0 is not a user.
	 *
	 * @since 2.13.0
	 *
	 * @param mixed $raw Whatever the stored marker holds for the owner.
	 * @return int|null The user id, or null when nothing was named.
	 */
	function aura_worker_credential_owner( $raw ) {
		if ( is_int( $raw ) ) {
			return $raw > 0 ? $raw : null;
		}
		if ( is_string( $raw ) && ctype_digit( $raw ) ) {
			$id = (int) $raw;
			return $id > 0 ? $id : null;
		}
		return null;
	}
}

if ( ! function_exists( 'aura_worker_unserialize_array' ) ) {
	/**
	 * Decode a serialized value into an array, or prove it cannot be — without
	 * ever letting `unserialize()` warn.
	 *
	 * `is_serialized()` is a SHAPE check, not a correctness one: a payload like
	 * `a:2:{s:7:"allowed";b:1;}` declares two elements and holds one, passes
	 * the shape check, and makes `unserialize()` emit an `E_WARNING` — which on
	 * a site configured to display errors corrupts the REST JSON this plugin
	 * answers with, and under any handler that turns warnings into exceptions
	 * (PHPUnit's own default, a site's own error handler) throws OUT of a
	 * single-row decode and takes down whatever larger structure was being
	 * built around it (Codex round-7 P2). A caller that decodes one row out of
	 * many — consent, an Application Password list — must be able to treat
	 * THAT row as unproven and keep going; it cannot do that if the decode
	 * itself can end the request.
	 *
	 * The warning is suppressed with `set_error_handler()`, restored in a
	 * `finally` so a throw from elsewhere cannot leave the handler in place —
	 * never with `@`, which silences every notice a warning-to-exception
	 * handler might otherwise still want to see from code this doesn't own.
	 *
	 * `allowed_classes => false`: this decodes untrusted, database-sourced
	 * bytes (a usermeta row, a LIKE-selected candidate), so a plain
	 * `unserialize()` would let a crafted payload instantiate arbitrary
	 * classes and fire `__wakeup()`/`__destruct()` gadgets. A serialised
	 * object becomes `__PHP_Incomplete_Class` instead — not an array, so it
	 * falls through to `false` here exactly like every other non-array shape.
	 *
	 * @since 2.15.0
	 *
	 * @param mixed $raw Whatever the stored value holds.
	 * @return array|false The decoded array, or false when it is not a
	 *                      serialized array, or the decode itself warned.
	 */
	function aura_worker_unserialize_array( $raw ) {
		if ( ! is_string( $raw ) || ! is_serialized( $raw, true ) ) {
			return false;
		}
		$warned = false;
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		set_error_handler(
			static function () use ( &$warned ) {
				$warned = true;
				return true; // suppress: never let the warning propagate
			}
		);
		try {
			$value = unserialize( $raw, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		} finally {
			restore_error_handler();
		}
		if ( $warned || ! is_array( $value ) ) {
			return false;
		}
		return $value;
	}
}

if ( ! function_exists( 'aura_worker_app_password_list' ) ) {
	/**
	 * That user's Application Password list, PROVEN to have been read.
	 *
	 * `WP_Application_Passwords::get_user_application_passwords()` answers an
	 * empty array for a user who holds none AND for a usermeta read that
	 * failed, so it can never prove a revocation landed — and a revocation
	 * "proved" by a failed read is how an administrator credential outlives
	 * the record that names it (#434 Codex round-10 P1).
	 *
	 * The proof that the statement RAN travels IN BAND, as a per-call nonce
	 * selected as a second column: only our own statement can put THIS call's
	 * nonce in a result set. wpdb::get_row() extracts from `$last_result`, and
	 * wpdb::query() has early returns BEFORE its flush() — an unready handle, a
	 * `query` filter that blanks the SQL — each leaving the PREVIOUS
	 * statement's result set in place; third-party db.php drop-ins add their
	 * own and set `$last_query` before connecting, so neither wpdb::$ready nor
	 * wpdb::$last_query is evidence. The nonce is a property of the ANSWER, on
	 * every handle.
	 *
	 * wp_generate_uuid4() and not wp_generate_password(): the latter is
	 * PLUGGABLE and filtered through `random_password`, and a constant nonce is
	 * no nonce at all. Since WP 7.0 wp_generate_uuid4() draws from wp_rand(),
	 * which IS pluggable and loads after plugins, so the monotonic counter
	 * carries the freshness that a pinned randomiser would take away: nothing
	 * outside this function can make two of its calls agree. The nonce is
	 * echoed back in the same statement and is never secret — the property it
	 * must have is FRESHNESS, not unpredictability.
	 *
	 * @since 2.13.0
	 * @since 2.15.0 the $max_bytes bound.
	 * @since 2.15.0 the $notify parameter.
	 *
	 * @param int  $owner     Owner user ID.
	 * @param int  $max_bytes Optional. When > 0, a row whose serialised value
	 *                        exceeds it answers null (oversized, never
	 *                        decoded); 0 = unbounded, the 2.13.0 behaviour.
	 * @param bool $notify    Optional, default true. When false, NEITHER
	 *                        do_action() call below fires — not the oversized
	 *                        one, not the unproven-read one. The return
	 *                        values are unchanged either way. Used by the
	 *                        audit_mcp_exposure tool (a `read_only: true` MCP
	 *                        tool that reads up to ~250 users' lists): firing
	 *                        the action there would write the #434 unbind
	 *                        breadcrumb from a read-only tool, and could
	 *                        overwrite it with an unrelated user.
	 * @return array|null The list — empty for a user with no row, or a row that
	 *                    does not hold an array, exactly as core reads both —
	 *                    or NULL for a read that proved nothing, which no
	 *                    caller may treat as an absence.
	 */
	function aura_worker_app_password_list( $owner, $max_bytes = 0, $notify = true ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->usermeta ) ) {
			return null; // no way to confirm: never a proof of absence
		}
		static $seq = 0;
		++$seq;
		$nonce     = $seq . '-' . wp_generate_uuid4();
		$max_bytes = (int) $max_bytes;
		if ( $max_bytes > 0 ) {
			// The byte bound lives IN the statement that returns the value: a
			// probe-then-fetch would let a concurrent usermeta write swap an
			// oversized value in between, and the fetch would decode it. The
			// LEFT JOIN over a one-row derived table keeps the probe row coming
			// back when the user has no meta row (len NULL) — the nonce proof
			// must not depend on the row existing.
			$sql = $wpdb->prepare(
				"SELECT %s AS probe, m.len, m.v FROM (SELECT 1 AS one) AS o LEFT JOIN (SELECT LENGTH(meta_value) AS len, IF(LENGTH(meta_value) <= %d, meta_value, NULL) AS v FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s LIMIT 1) AS m ON 1 = 1",
				$nonce,
				$max_bytes,
				(int) $owner,
				'_application_passwords'
			);
		} else {
			$sql = $wpdb->prepare( "SELECT %s AS probe, (SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s LIMIT 1) AS v", $nonce, (int) $owner, '_application_passwords' );
		}
		// core's prepare() answers null when it refuses the call: the earlier,
		// cheaper refusal, and it keeps "we never asked" from being reported as
		// "we asked and were told nothing".
		if ( ! is_string( $sql ) || '' === $sql ) {
			return null; // nothing was issued, so nothing was proved
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql );
		if ( ! isset( $row->probe ) || $nonce !== (string) $row->probe ) {
			// No row, or somebody else's row: this call proved nothing, and an
			// unprovable probe owes app_passwords forever, so leave a
			// breadcrumb rather than a tombstone that never explains itself.
			if ( $notify ) {
				do_action( 'aura_worker_app_password_probe_unproven', (int) $owner );
			}
			return null;
		}
		if ( $max_bytes > 0 ) {
			if ( ! isset( $row->len ) ) {
				return array(); // no row at all: that user holds no Application Passwords
			}
			if ( ! isset( $row->v ) ) {
				// The row exists and exceeds the bound: the value was never
				// returned, so it was never decoded. Not an absence.
				if ( $notify ) {
					do_action( 'aura_worker_app_password_probe_unproven', (int) $owner, 'oversized' );
				}
				return null;
			}
		}
		$raw = isset( $row->v ) ? $row->v : null;
		if ( null === $raw ) {
			return array(); // no row at all: that user holds no Application Passwords
		}
		// aura_worker_unserialize_array(): this helper now also serves rows
		// selected by a LIKE over the value itself (the Elementor-door
		// candidate scan), so the value must be treated as untrusted the
		// same way the consent decode (class-tool-audit-mcp-exposure.php)
		// and the snapshot payload decode (class-aura-worker-snapshots.php)
		// already do — allowed_classes => false keeps a crafted payload
		// from instantiating arbitrary classes and firing
		// __wakeup()/__destruct() gadgets, and the shared helper's warning
		// suppression keeps a shape that passes is_serialized() but does
		// not actually unserialize cleanly from throwing under a
		// warnings-to-exceptions handler instead of just failing this one
		// row (#434 Codex round-7 P2).
		$list = aura_worker_unserialize_array( $raw );
		return is_array( $list ) ? $list : array();
	}
}

if ( ! function_exists( 'aura_worker_app_password_state' ) ) {
	/**
	 * Is that uuid in that user's list? 'present', 'gone', or 'unknown'.
	 *
	 * "Could not determine" is a THIRD answer, never a quiet 'gone'. Callers
	 * that must fail closed read `'gone' !==`; a caller that must fail closed
	 * the other way — confirming a candidate owner — reads `'present' ===`.
	 * Nobody has to remember which way a boolean leans.
	 *
	 * Answers only about the user it is ASKED about. It is not, and cannot be
	 * made into, evidence that nobody else holds the password.
	 *
	 * @since 2.13.0
	 *
	 * @param int    $owner Owner user ID.
	 * @param string $uuid  Password UUID.
	 * @return string 'present', 'gone' or 'unknown'.
	 */
	function aura_worker_app_password_state( $owner, $uuid ) {
		// Core's own list is asked FIRST, and only for the positive. A filter
		// or an alternative meta store keeps its say over "this password
		// exists"; the raw probe runs only on the path about to conclude
		// ABSENCE, and can turn that conclusion into 'present' or 'unknown' —
		// never the other way.
		if ( class_exists( 'WP_Application_Passwords' ) ) {
			foreach ( WP_Application_Passwords::get_user_application_passwords( (int) $owner ) as $item ) {
				if ( is_array( $item ) && isset( $item['uuid'] ) && (string) $uuid === (string) $item['uuid'] ) {
					return 'present';
				}
			}
		}
		$list = aura_worker_app_password_list( $owner );
		if ( null === $list ) {
			return 'unknown'; // nothing was proved; never absence
		}
		foreach ( $list as $item ) {
			if ( is_array( $item ) && isset( $item['uuid'] ) && (string) $uuid === (string) $item['uuid'] ) {
				return 'present';
			}
		}
		return 'gone';
	}
}
