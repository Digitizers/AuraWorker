<?php
/**
 * Operator rules, enforced on the site.
 *
 * A rule is an Aura memory entry under `rule/` (see the P4.1 spec in the Aura
 * repo). Aura signs the client's whole ruleset and pushes it here; this class
 * verifies it, keeps it, and answers one question at write time: does this
 * call touch something a live rule protects?
 *
 * Three groups of methods, deliberately in one file so the contract is read
 * as a whole:
 *   - matching   (pure; no WordPress)  — match(), is_expired()
 *   - the store  (option-backed)       — accept(), current(), …
 *   - enforcement (the seam tools use) — enforce(), guard_result()
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Worker_Rules {

	/** The only resource types a rule may name. Anything else never matches. */
	const TYPES = array( 'site', 'page', 'post', 'plugin' );

	/** `page` and `post` are the same ID seen from two directions. */
	const CONTENT_TYPES = array( 'page', 'post' );

	/**
	 * The base-class default: a tool that never said what it touches. Not a
	 * resource type an operator can name — it matches EVERY rule, so silence
	 * is the most restrictive answer rather than a way past page rules.
	 */
	const UNKNOWN = 'unknown';

	/* ------------------------------------------------------------------ */
	/* Matching — pure                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * The rule that decides this call, or null.
	 *
	 * `block` beats `warn`. Expired rules never match. Unknown target types
	 * never match — Aura refuses them at write time, and if one arrives anyway
	 * the site does not guess.
	 *
	 * @param array    $touches Resources the call declares: list of {type,id}.
	 * @param array    $rules   Rules from the current ruleset.
	 * @param int|null $now     Unix time; defaults to now. Injected for tests.
	 * @return array|null
	 */
	public static function match( array $touches, array $rules, $now = null ) {
		$now     = null === $now ? time() : (int) $now;
		$winner  = null;
		$touched = self::normalize_touches( $touches );

		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || self::is_expired( $rule, $now ) ) {
				continue;
			}
			$effect = isset( $rule['effect'] ) ? (string) $rule['effect'] : '';
			if ( 'block' !== $effect && 'warn' !== $effect ) {
				continue;
			}
			if ( ! self::rule_touches( $rule, $touched ) ) {
				continue;
			}
			if ( 'block' === $effect ) {
				return $rule; // Nothing outranks a block.
			}
			if ( null === $winner ) {
				$winner = $rule;
			}
		}
		return $winner;
	}

	/**
	 * Has this rule's `until` passed? A rule we cannot date is treated as
	 * expired: an unparseable expiry is not a claim we can act on.
	 *
	 * @param array $rule Rule.
	 * @param int   $now  Unix time.
	 * @return bool
	 */
	public static function is_expired( array $rule, $now ) {
		if ( ! isset( $rule['until'] ) || null === $rule['until'] || '' === $rule['until'] ) {
			return false;
		}
		$ts = strtotime( (string) $rule['until'] );
		if ( false === $ts ) {
			return true;
		}
		return $ts <= (int) $now;
	}

	/**
	 * @param array $touches Raw declarations.
	 * @return array<string,true> Set of "type:id".
	 */
	private static function normalize_touches( array $touches ) {
		$set = array();
		foreach ( $touches as $t ) {
			if ( ! is_array( $t ) || ! isset( $t['type'], $t['id'] ) ) {
				continue;
			}
			$type = (string) $t['type'];
			$id   = (string) $t['id'];
			if ( '' === $type || '' === $id ) {
				continue;
			}
			// The sentinel has exactly one form. `unknown:x` is not a narrower
			// kind of unknown — rule_touches() looks for `unknown:*` and would
			// see a non-empty set matching nothing, which is the very hole this
			// normaliser exists to close. Any `unknown` becomes the sentinel.
			if ( self::UNKNOWN === $type ) {
				$set[ self::UNKNOWN . ':*' ] = true;
				continue;
			}
			// Only the vocabulary counts. A type nobody defined — `theme`,
			// `network`, a typo — is not a narrower declaration, it is an
			// unreadable one: keeping it would leave a non-empty set that no
			// page or plugin rule can match, the same exemption an empty
			// declaration used to buy, spelled differently.
			if ( ! in_array( $type, self::TYPES, true ) ) {
				continue;
			}
			$set[ $type . ':' . $id ] = true;
		}
		if ( empty( $set ) ) {
			// A declaration that survives normalisation as nothing — `[]`,
			// entries with no type or no id, or entries naming types outside
			// the vocabulary — is not "touches nothing". It is a tool that has
			// told us nothing, which is exactly what the sentinel is for.
			// Reading it as an empty set would let a mutating tool opt out of
			// every rule, including a site freeze, by declaring garbage.
			$set[ self::UNKNOWN . ':*' ] = true;
		}
		return $set;
	}

	/**
	 * @param array              $rule    Rule.
	 * @param array<string,true> $touched Normalised set.
	 * @return bool
	 */
	private static function rule_touches( array $rule, array $touched ) {
		$target = isset( $rule['target'] ) && is_array( $rule['target'] ) ? $rule['target'] : array();
		$type   = isset( $target['type'] ) ? (string) $target['type'] : '';
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return false;
		}
		if ( isset( $touched[ self::UNKNOWN . ':*' ] ) ) {
			return true; // Undeclared: every live rule applies.
		}
		if ( 'site' === $type ) {
			return ! empty( $touched );
		}
		$id = isset( $target['id'] ) ? (string) $target['id'] : '';
		if ( '' === $id ) {
			return false;
		}
		if ( in_array( $type, self::CONTENT_TYPES, true ) ) {
			foreach ( self::CONTENT_TYPES as $ct ) {
				if ( isset( $touched[ $ct . ':' . $id ] ) ) {
					return true;
				}
			}
			return false;
		}
		return isset( $touched[ $type . ':' . $id ] );
	}
}
