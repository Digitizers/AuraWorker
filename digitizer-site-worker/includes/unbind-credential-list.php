<?php
/**
 * ONE RULE: what a stored credential list PROVES (#434).
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
 * This file defines ONE pure function and nothing else — no class, no hooks, no
 * bootstrap — precisely so `uninstall.php`, which must not load the plugin,
 * can require it and be bound by the same rule as the plugin itself.
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
