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

	/* ------------------------------------------------------------------ */
	/* The store — option-backed, signed, monotonic                        */
	/* ------------------------------------------------------------------ */

	/** Option holding the last accepted ruleset record. */
	const OPTION = 'aura_worker_ruleset';

	/** How many times a caller that loses the swap re-decides before giving up. */
	const MAX_SWAP_ATTEMPTS = 3;

	/**
	 * Accept a signed ruleset if it verifies and is newer than what we hold.
	 *
	 * Whole document every time, so there is no delta to misapply and no order
	 * to get wrong. `seq` is monotonic: an older document is refused even when
	 * validly signed, because replaying one is exactly how a released rule
	 * would come back or a new one would vanish. Any failure leaves the stored
	 * record untouched — last-known-good is the contract.
	 *
	 * @param string $envelope Signed document from the gateway.
	 * @param int    $attempt  Internal: how many times this call has re-decided
	 *                         after losing the compare-and-swap.
	 * @return true|WP_Error
	 */
	public static function accept( $envelope, $attempt = 0 ) {
		$doc = Aura_Worker_Grant::verify_signed_document( (string) $envelope );
		if ( ! is_array( $doc ) ) {
			return new WP_Error( 'aura_ruleset_rejected', 'Ruleset refused: ' . $doc, array( 'status' => 400 ) );
		}
		if ( ! isset( $doc['v'] ) || 1 !== (int) $doc['v'] ) {
			return new WP_Error( 'aura_ruleset_rejected', 'Ruleset refused: unsupported version', array( 'status' => 400 ) );
		}
		if ( ! isset( $doc['seq'] ) || ! is_int( $doc['seq'] ) || $doc['seq'] < 0 ) {
			return new WP_Error( 'aura_ruleset_rejected', 'Ruleset refused: seq must be a non-negative integer', array( 'status' => 400 ) );
		}
		if ( ! isset( $doc['rules'] ) || ! is_array( $doc['rules'] ) ) {
			return new WP_Error( 'aura_ruleset_rejected', 'Ruleset refused: rules must be a list', array( 'status' => 400 ) );
		}
		$client = isset( $doc['client'] ) && is_string( $doc['client'] ) ? trim( $doc['client'] ) : '';
		if ( '' === $client ) {
			return new WP_Error( 'aura_ruleset_rejected', 'Ruleset refused: client is required', array( 'status' => 400 ) );
		}

		// Bound to THIS site, exactly as grants are (`site` = the token hash).
		// The gateway key is shared across clients, so without this a valid
		// envelope for site A plus site B's token could install A's rules on B
		// before B's first push — and B's real documents would then be refused
		// as a client mismatch. Checked before anything about the stored record,
		// so it holds for the very first document too.
		$site = isset( $doc['site'] ) && is_string( $doc['site'] ) ? $doc['site'] : '';
		$ours = (string) get_option( 'aura_worker_site_token', '' );
		if ( '' === $site || '' === $ours || ! hash_equals( $ours, $site ) ) {
			return new WP_Error( 'aura_ruleset_wrong_site', 'Ruleset refused: not issued for this site', array( 'status' => 403 ) );
		}

		$current = self::current();
		if ( null !== $current && isset( $current['envelope'] ) && hash_equals( (string) $current['envelope'], (string) $envelope ) ) {
			// The very document we already hold — a retry after a lost 200.
			// Delivered is delivered; saying 409 would record it as failed forever.
			return true;
		}
		if ( null !== $current && isset( $current['client'] ) && $client !== (string) $current['client'] ) {
			// A rebinding goes through connect(), which clears first. Anything
			// else is a misroute or a replay, and the stored rules are not its
			// to replace — the seq comparison below would be meaningless across
			// clients.
			return new WP_Error(
				'aura_ruleset_client_mismatch',
				sprintf( 'Ruleset refused: issued for client %s, this site is bound to %s', $client, (string) $current['client'] ),
				array( 'status' => 409 )
			);
		}
		if ( null !== $current && $doc['seq'] <= (int) $current['seq'] ) {
			return new WP_Error(
				'aura_ruleset_stale',
				sprintf( 'Ruleset refused: seq %d is not newer than stored seq %d', $doc['seq'], (int) $current['seq'] ),
				array( 'status' => 409 )
			);
		}

		// Compare-and-swap. The seq check above read $current; between that read
		// and this write another request can install a newer ruleset — a retry
		// of seq 6 racing a fresh seq 7 would otherwise land last and roll policy
		// backwards, silently removing a block the operator just added. The
		// write therefore names the value it expects to replace, and a losing
		// racer re-reads and re-decides rather than overwriting.
		$record = array(
			'envelope'    => (string) $envelope,
			'client'      => $client,
			'seq'         => (int) $doc['seq'],
			'issued_at'   => isset( $doc['issued_at'] ) ? (string) $doc['issued_at'] : '',
			'received_at' => time(),
			'rules'       => array_values( array_filter( $doc['rules'], 'is_array' ) ),
		);
		$swapped = self::swap( $current, $record );
		if ( true !== $swapped ) {
			if ( is_wp_error( $swapped ) ) {
				// The database refused the write. Retrying cannot help and
				// would spin: say so, and let Aura retry the push later.
				return $swapped;
			}
			// Someone else wrote first. Whatever they wrote, this document is
			// judged against it from the top: an identical envelope is a 200, a
			// newer seq installs, an older one is the 409 it always was. Bounded:
			// a site losing this race repeatedly is a site under a push storm,
			// and unbounded recursion would answer that with a stack overflow.
			if ( $attempt >= self::MAX_SWAP_ATTEMPTS ) {
				return new WP_Error(
					'aura_ruleset_contended',
					'Ruleset not stored: another push kept winning the write; retry.',
					array( 'status' => 503 )
				);
			}
			return self::accept( $envelope, $attempt + 1 );
		}
		// A new ruleset retires rules, and a retired rule's daily claim is
		// named after a key nothing will visit again. Retired ones only: a rule
		// this document still carries keeps today's claim, or accepting a
		// ruleset would announce it a second time.
		// Accepting a ruleset does NOT touch the claims. They are statements
		// about a day, not about a ruleset: yesterday's are swept by
		// note_expired() on the next enforcement, today's are still true. That
		// is also what makes this safe under overlapping pushes — there is no
		// keep-set to go stale between deciding and deleting.
		return true;
	}

	/**
	 * Replace the stored record only if it is still $expected.
	 *
	 * WordPress has no compare-and-swap for options, so this is one UPDATE
	 * with the old serialized value in the WHERE clause, or a conditional
	 * INSERT when the decision was made against nothing stored.
	 *
	 * Three outcomes, kept distinct on purpose:
	 *  - true      — this caller's write landed.
	 *  - false     — a racer wrote first. The caller must re-decide; it must
	 *                NOT read the racer's value and swap against that, which
	 *                would install this document without ever comparing its
	 *                seq to the one now stored. That is the same rollback the
	 *                CAS exists to prevent, one level down.
	 *  - WP_Error  — the database refused the statement. Retrying cannot help.
	 *
	 * @param array|null $expected Record read before the decision, or null.
	 * @param array      $record   Record to store.
	 * @return true|false|WP_Error
	 */
	private static function swap( $expected, $record ) {
		if ( null === $expected ) {
			return self::insert_if_absent( $record );
		}
		return self::swap_raw( maybe_serialize( $expected ), $record );
	}

	/**
	 * Insert the record only if no row named self::OPTION exists yet.
	 *
	 * NOT add_option(): core's add_option() skips its own existence check
	 * whenever the option name is already listed in the `notoptions` cache —
	 * which is exactly the state a first push finds it in, since current()
	 * just missed — and falls through to `INSERT ... ON DUPLICATE KEY
	 * UPDATE`, silently clobbering a winning racer's row and reporting
	 * success. A real conditional insert through $wpdb cannot be fooled that
	 * way: the database, not a cache, decides. Its affected-row count is the
	 * tri-state directly: 1 inserted (we won), 0 a row was already there (we
	 * lost — re-decide), false a database error (store failure, not a race).
	 *
	 * This bypasses add_option()'s own cache maintenance, so a successful
	 * insert evicts explicitly.
	 *
	 * @param array $record Record to store.
	 * @return true|false|WP_Error
	 */
	private static function insert_if_absent( $record ) {
		global $wpdb;
		$rows = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) SELECT %s, %s, %s FROM DUAL WHERE NOT EXISTS ( SELECT 1 FROM {$wpdb->options} WHERE option_name = %s )",
				self::OPTION,
				maybe_serialize( $record ),
				'no',
				self::OPTION
			)
		);
		if ( false === $rows ) {
			return new WP_Error(
				'aura_ruleset_store_failed',
				'Ruleset not stored: the database refused the write.',
				array( 'status' => 500 )
			);
		}
		if ( $rows > 0 ) {
			wp_cache_delete( self::OPTION, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			return true;
		}
		// A row is there. It might be a racer's valid record (re-decide
		// against it from the top) or a truncated/hand-edited value with no
		// seq to compare (repair it, still by CAS against its exact bytes).
		$raw = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::OPTION )
		);
		if ( null === $raw ) {
			// The row we just failed to beat has vanished under us (deleted
			// between the INSERT and this read). Re-decide from the top
			// rather than guessing at a value that no longer exists.
			return false;
		}
		$stored = maybe_unserialize( $raw );
		if ( self::is_record( $stored ) ) {
			return false; // A racer's record. Re-decide against it.
		}
		// The predicate is the RAW bytes, not the decoded value. A row
		// holding `i:5;` decodes to int 5, and maybe_serialize( 5 ) is the
		// string "5" — which matches nothing, so every retry would lose and
		// the corrupt row could never be repaired. Round-tripping is only
		// lossless for values maybe_serialize() would have written.
		return self::swap_raw( $raw, $record );
	}

	/**
	 * The CAS itself, against the exact bytes expected in the row.
	 *
	 * @param string $expected_raw Serialized value the decision was made against.
	 * @param array  $record       Record to store.
	 * @return bool|WP_Error
	 */
	private static function swap_raw( $expected_raw, $record ) {
		global $wpdb;
		$rows = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				maybe_serialize( $record ),
				self::OPTION,
				(string) $expected_raw
			)
		);
		wp_cache_delete( self::OPTION, 'options' );
		if ( false === $rows ) {
			// $wpdb->query() returns false for an SQL error and 0 for "matched
			// nothing". Collapsing the two would turn a broken database into an
			// endless retry.
			return new WP_Error(
				'aura_ruleset_store_failed',
				'Ruleset not stored: the database refused the write.',
				array( 'status' => 500 )
			);
		}
		return $rows > 0;
	}

	/**
	 * The stored record, or null when no ruleset has ever been accepted.
	 *
	 * @return array|null
	 */
	public static function current() {
		$rec = get_option( self::OPTION, null );
		return self::is_record( $rec ) ? $rec : null;
	}

	/**
	 * Is this value a stored ruleset record? One definition, used by current()
	 * and by swap() on a value read straight from the database.
	 *
	 * @param mixed $rec Candidate.
	 * @return bool
	 */
	private static function is_record( $rec ) {
		return is_array( $rec ) && isset( $rec['seq'], $rec['rules'] ) && is_array( $rec['rules'] );
	}

	/**
	 * Rules to match against. Empty when there is no ruleset — which means no
	 * policy, not a refusal.
	 *
	 * @return array
	 */
	public static function rules() {
		$rec = self::current();
		return null === $rec ? array() : $rec['rules'];
	}

	/**
	 * Forget the ruleset (disconnect, tests).
	 */
	public static function clear() {
		delete_option( self::OPTION );
		// The claims are deliberately NOT swept here. They are statements
		// about a DAY, and the time-based sweep in note_expired() drops every
		// claim older than today whatever the ruleset now holds — so a claim
		// left by a disconnect outlives it by at most a day, and no dedicated
		// cleanup is owed. Sweeping here would also be the one UNBOUNDED
		// sweep in the class, the only one whose range includes names an
		// in-flight enforcement can still create; see sweep_options().
	}

	/** Prefix for the per-rule-per-day "already announced" claims. */
	const EXPIRED_NOTICE = 'aura_worker_rule_expired_';

	/**
	 * The rule slot the daily sweep claims for itself.
	 *
	 * A reserved word, not a hash: `rule_hash()` returns 20 hex characters, so
	 * nothing a real rule key can produce collides with it. Sharing the claim
	 * naming is the point — the sweep's own claims are swept by later sweeps,
	 * so it leaves no growing residue of its own.
	 */
	const SWEEP_CLAIM = 'sweep';

	/**
	 * Option name claiming one rule for one day: prefix, DAY, then the rule.
	 *
	 * The day comes first on purpose. A claim is a statement about a day — "we
	 * announced this rule today" — so it stops meaning anything when the day
	 * ends, not when the ruleset changes. With the day leading, one
	 * `option_name < prefix<today>_` deletes every stale claim of every rule in
	 * a single statement, no keep-set and no coupling to what the ruleset
	 * currently holds. Zero-padded to seven digits so lexical order is numeric
	 * order (today's index is five digits, ~20800, under 10^7 past year 29000).
	 *
	 * @param string $hash Rule-key hash (see rule_hash()).
	 * @param int    $day  Day index.
	 * @return string
	 */
	public static function expired_claim( $hash, $day ) {
		return self::EXPIRED_NOTICE . str_pad( (string) (int) $day, 7, '0', STR_PAD_LEFT ) . '_' . $hash;
	}

	/**
	 * The short hash a claim names a rule by.
	 *
	 * @param string $key Rule key.
	 * @return string
	 */
	public static function rule_hash( $key ) {
		return substr( hash( 'sha256', (string) $key ), 0, 20 );
	}

	/**
	 * Drop every claim from a day that has ended.
	 *
	 * A claim says "this rule was announced on this day". Yesterday's claim is
	 * spent whatever the ruleset now holds, and today's is needed whatever the
	 * ruleset now holds — so cleanup is about TIME, and never about ruleset
	 * membership. That is what keeps it correct under concurrency: an accepted
	 * ruleset does not sweep, so no interleaving of two pushes can delete a
	 * claim the winner still needs, and no retired rule can leave a claim
	 * behind for longer than a day.
	 *
	 * One statement, because the day leads the name (see expired_claim()).
	 *
	 * @param int $day Today's day index; claims for earlier days go.
	 */
	private static function sweep_stale_claims( $day ) {
		self::sweep_options(
			self::EXPIRED_NOTICE,
			self::EXPIRED_NOTICE . str_pad( (string) (int) $day, 7, '0', STR_PAD_LEFT ) . '_'
		);
	}

	/**
	 * Delete option rows by name prefix — and evict what was deleted.
	 *
	 * `$wpdb` finds the names — nothing else can, without the caller knowing
	 * which rules or which hours exist, which is the coupling these sweeps
	 * are built to avoid. But the DELETE goes through `delete_option()`, one
	 * name at a time, and NOT through a second `LIKE` statement.
	 *
	 * Two reasons, and both are about the object cache rather than SQL:
	 *
	 *  1. A raw DELETE removes rows and leaves their `options` cache entries.
	 *     A stale entry for a deleted row is worse than the row itself:
	 *     `add_option()` consults the cache, sees a claim that no longer
	 *     exists, returns false, and the expiry announcement it was supposed
	 *     to permit never fires. `clear()` would report having forgotten every
	 *     claim while the site still behaved as though it remembered them.
	 *  2. A second `LIKE` statement does not delete the set that was read. A
	 *     row inserted between the SELECT and the DELETE is deleted by
	 *     name-pattern while the eviction loop, which only knows the names it
	 *     read, leaves its cached value behind. Deleting exactly the captured
	 *     names cannot do that: whatever is not in the set is left whole, row
	 *     and cache together.
	 *
	 * `$before` is REQUIRED, and that is what closes the last race rather
	 * than narrowing it. Every sweep deletes only names strictly below a
	 * bound — an earlier day, an earlier hour — and nothing in the system
	 * ever creates a name below its own bound: a claim is always for TODAY, a
	 * bucket always for THIS hour. So a name this sweep read can never be
	 * recreated in time to be deleted by a second sweep that did not read it.
	 * An unbounded sweep would have exactly that hole, which is why there is
	 * no longer one anywhere in the class.
	 *
	 * `delete_option()` also handles `notoptions` and the autoload cache the
	 * way core expects, which hand-rolled eviction gets wrong quietly.
	 *
	 * The counts are small by construction — one claim per expired rule per
	 * day, one bucket per hour — so N statements is not a cost worth a
	 * correctness hole.
	 *
	 * @param string $prefix Option-name prefix.
	 * @param string $before Delete only names strictly less than this.
	 */
	private static function sweep_options( $prefix, $before ) {
		global $wpdb;
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name < %s",
				$wpdb->esc_like( $prefix ) . '%',
				$before
			)
		);

		foreach ( (array) $names as $name ) {
			delete_option( $name );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Enforcement — the seam tools and routes call                        */
	/* ------------------------------------------------------------------ */

	/**
	 * One frame per dispatch in flight, innermost last, plus a base frame for
	 * work outside REST (WP-CLI, cron, a direct call).
	 *
	 * Each frame holds what belongs to that dispatch alone:
	 *  - `recorded` — rules already recorded there, as `effect|key`, so
	 *    overlapping seams report one mutation once (see enforce()).
	 *  - `warnings` — warn entries recorded there, so the response that
	 *    carries them is the response of the dispatch that earned them.
	 *
	 * A stack, because dispatches nest: a handler may call rest_do_request()
	 * mid-flight. Frames rather than one shared list, because a mark-and-slice
	 * on a shared list gives the OUTER dispatch everything the inner one
	 * recorded too.
	 *
	 * @var array<int,array{recorded:array<string,true>,warnings:array}>
	 */
	private static $scopes = array( array( 'recorded' => array(), 'warnings' => array() ) );

	/**
	 * The innermost frame, by reference.
	 *
	 * @return array
	 */
	private static function &scope() {
		if ( empty( self::$scopes ) ) {
			self::$scopes = array( array( 'recorded' => array(), 'warnings' => array() ) );
		}
		$last = &self::$scopes[ count( self::$scopes ) - 1 ];
		return $last;
	}

	/** Forget every frame. Tests call this; `reset_request_warnings()` does too. */
	public static function reset_records() {
		self::$scopes = array( array( 'recorded' => array(), 'warnings' => array() ) );
	}

	// EXPIRED_NOTICE, expired_claim() and rule_hash() were added in Task 4
	// with sweep_stale_claims(); note_expired() below uses them.

	/**
	 * Keys of rules in the current ruleset whose `until` has passed.
	 *
	 * @param int|null $now Unix time.
	 * @return string[]
	 */
	public static function expired_keys( $now = null ) {
		$now = null === $now ? time() : (int) $now;
		$out = array();
		foreach ( self::rules() as $rule ) {
			if ( is_array( $rule ) && self::is_expired( $rule, $now ) && isset( $rule['key'] ) ) {
				$out[] = (string) $rule['key'];
			}
		}
		return $out;
	}

	/**
	 * Announce rules that are past `until` — once per rule per day (spec §6).
	 *
	 * An expired rule is ignored for matching, which is exactly why it needs
	 * announcing: it looks like protection and is not. The hook is for
	 * forensics and for extensions; `audit_rules` (Task 10) reports the same
	 * set as `expired_active`, but a consumer that subscribes cannot poll a
	 * tool.
	 *
	 * Bounded by a per-rule-per-DAY option that a caller must CLAIM: the name
	 * carries the day, and `add_option()` inserts only when the row is absent,
	 * so exactly one of any number of concurrent requests creates it and fires.
	 * Reading a flag and then writing it would let two requests both see
	 * yesterday, both write today, and both fire — "once per day" that is once
	 * per day only when the site is idle. No scheduled job: the announcement
	 * rides on enforcement, which is when anybody is relying on the rule.
	 *
	 * @param int|null $now Unix time.
	 */
	private static function note_expired( $now = null ) {
		$now = null === $now ? time() : (int) $now;
		$day = (int) floor( $now / DAY_IN_SECONDS );
		// The sweep is claimed like any other statement about a day, and it is
		// claimed BEFORE the loop, not inside it. Inside, it would only ever
		// run for a site that has an expired rule left to announce today —
		// so a rule retired yesterday, or a day on which every remaining
		// expired rule was already announced, would leave its claims behind
		// for good. Its own claim uses the same day-first naming, so tomorrow's
		// sweep deletes today's sweep claim along with today's rule claims,
		// and the cost stays one DELETE per day rather than one per
		// enforcement.
		if ( add_option( self::expired_claim( self::SWEEP_CLAIM, $day ), 1, '', false ) ) {
			// Every claim from a day that has ended, for every rule — one
			// statement, because the day leads the name. This is where retired
			// rules' claims go too: nothing has to know which rules left.
			self::sweep_stale_claims( $day );
		}
		foreach ( self::expired_keys( $now ) as $key ) {
			$hash  = self::rule_hash( $key );
			$today = self::expired_claim( $hash, $day );
			if ( ! add_option( $today, 1, '', false ) ) {
				continue; // Somebody else claimed this rule for today.
			}
			/**
			 * Fires once a day for each rule that is past its `until` and still
			 * in the ruleset.
			 *
			 * @param string $key Rule key, e.g. `rule/holiday-freeze`.
			 * @param int    $day Day index the notice fired for.
			 */
			do_action( 'aura_worker_rule_expired', (string) $key, $day );
		}
	}

	/**
	 * Decide this call against the stored ruleset and fire the forensic hook.
	 *
	 * @param array    $touches   What the call declares it touches.
	 * @param string   $tool_name For the hook and the message.
	 * @param int|null $now       Unix time; injected for tests.
	 * @return array {effect: null|'warn'|'block', rule?: array, recorded?: bool}
	 */
	public static function enforce( array $touches, $tool_name, $now = null ) {
		self::note_expired( $now );
		$rule = self::match( $touches, self::rules(), $now );
		if ( null === $rule ) {
			return array( 'effect' => null );
		}
		// One rule, one record per DISPATCH. Enforcement is per call — every
		// seam still decides, and every refusal still refuses — but the EVENT
		// is per dispatch, because that is the scope over which the seams
		// overlap: one mutation meets the route seam and then core's
		// pre_trash_post and then pre_delete_post, three decisions on one
		// deletion, all inside the dispatch that carries it.
		//
		// The dispatch, and not the OBJECT, because the overlapping seams do
		// not agree on an object: under a site rule the generic seam knows
		// only the route — `site:*` — while the data seam that follows it
		// names post 7. Keying on the object would split those two decisions
		// about one deletion into two events, and hand the caller two warnings
		// for one call.
		//
		// The price, stated rather than hidden: a batch endpoint that mutates
		// N objects under ONE rule in ONE dispatch produces one event, not N.
		// That is an undercount of MAGNITUDE and never of presence — each of
		// the N mutations is still refused, the rule still shows as biting,
		// and `audit_rules` still reports it. Per-object magnitude is Aura's
		// to report, from its own action log (`AgentAction.touches`, plan 2),
		// which records one row per action and is not subject to any of this.
		//
		// Per REQUEST would be worse than either: a handler calling
		// rest_do_request() would have the nested dispatch's refusal silence
		// its own.
		$scope = &self::scope();
		$key   = $rule['effect'] . '|' . $rule['key'];
		$fresh = ! isset( $scope['recorded'][ $key ] );
		$scope['recorded'][ $key ] = true;
		if ( 'block' === $rule['effect'] ) {
			/**
			 * Fires when a rule refused a call. The refusal is the point; a site
			 * being probed should still be able to see it.
			 *
			 * @param string $tool_name Tool that was refused.
			 * @param array  $rule      The rule that decided.
			 */
			if ( $fresh ) {
				do_action( 'aura_worker_rule_blocked', (string) $tool_name, $rule );
			}
			return array( 'effect' => 'block', 'rule' => $rule );
		}
		/**
		 * Fires when a call proceeded under a warn rule.
		 *
		 * @param string $tool_name Tool that ran.
		 * @param array  $rule      The rule that matched.
		 */
		if ( $fresh ) {
			do_action( 'aura_worker_rule_warned', (string) $tool_name, $rule );
		}
		return array( 'effect' => $rule['effect'], 'rule' => $rule, 'recorded' => $fresh );
	}

	/**
	 * The tool-result array for a refusal. Says plainly that approval does not
	 * help — the operator has to release the rule — so nobody goes looking for
	 * a grant bug.
	 *
	 * @param string $tool_name Tool.
	 * @param array  $rule      Deciding rule.
	 * @return array
	 */
	public static function blocked_result( $tool_name, array $rule ) {
		$key    = isset( $rule['key'] ) ? (string) $rule['key'] : 'rule/?';
		$reason = isset( $rule['reason'] ) ? (string) $rule['reason'] : '';
		return array(
			'success' => false,
			'code'    => 'aura_rule_blocked',
			'status'  => 403,
			'error'   => sprintf(
				'%s is blocked by %s%s — approval does not override a rule; release the rule first.',
				(string) $tool_name,
				$key,
				'' === $reason ? '' : ' (' . $reason . ')'
			),
			'rule'    => array(
				'key'    => $key,
				'reason' => $reason,
			),
		);
	}

	/**
	 * @param array $rule Matched warn rule.
	 * @return array {rule: string, reason: string}
	 */
	public static function warning_entry( array $rule ) {
		return array(
			'rule'   => isset( $rule['key'] ) ? (string) $rule['key'] : 'rule/?',
			'reason' => isset( $rule['reason'] ) ? (string) $rule['reason'] : '',
		);
	}

	/* ------------------------------------------------------------------ */
	/* REST — the legacy direct handlers that bypass execute_tool()        */
	/* ------------------------------------------------------------------ */

	/**
	 * REST-flavoured enforcement for handlers that do not pass through
	 * execute_tool(): the legacy update routes in class-aura-worker-api.php.
	 *
	 * @param array  $touches What the handler is about to touch.
	 * @param string $action  Grant action name, for the hook and message.
	 * @return true|WP_Error
	 */
	public static function guard_rest( array $touches, $action ) {
		$verdict = self::enforce( $touches, $action );
		if ( 'warn' === $verdict['effect'] && ! empty( $verdict['recorded'] ) ) {
			self::note_warning( $verdict['rule'] );
			return true;
		}
		if ( 'block' !== $verdict['effect'] ) {
			return true;
		}
		$res = self::blocked_result( $action, $verdict['rule'] );
		return new WP_Error(
			'aura_rule_blocked',
			$res['error'],
			array(
				'status' => 403,
				'rule'   => $res['rule'],
			)
		);
	}

	/**
	 * `rest_request_before_callbacks` — open a frame for this dispatch.
	 *
	 * This hook, not `rest_pre_dispatch`, because this is the pair WordPress
	 * actually guarantees: `rest_request_before_callbacks`
	 * (class-wp-rest-server.php:1256) and `rest_request_after_callbacks`
	 * (:1318) sit in the same method with no `return` between them, so every
	 * path that opens a frame closes it — a block from `guard_core_any()`, a
	 * failed permission callback, a handler returning `WP_Error`, all still
	 * reach the after filter. `rest_pre_dispatch` (:1079) has three exits
	 * after it that never reach a callback at all: a short-circuit by any
	 * other plugin's filter, an unmatched route, an invalid request. A frame
	 * opened there and never closed is closed by the OUTER dispatch instead,
	 * which then loses its own warnings to the leak.
	 *
	 * Priority 1, so the frame exists before `guard_core_any()` (priority 5)
	 * records into it.
	 *
	 * The frame remembers WHICH request opened it. Core's structure makes the
	 * pair reliable, but an exception unwinding out of a nested
	 * `rest_do_request()` that an outer handler catches would still leave a
	 * frame behind — see `close_frame()`, which discards such a frame rather
	 * than letting an outer dispatch mistake it for its own.
	 *
	 * Pass-through filter: returns $response untouched, always.
	 *
	 * @param mixed $response Response so far.
	 * @param array $handler  Route handler.
	 * @param mixed $request  Request being dispatched.
	 * @return mixed
	 */
	public static function open_frame( $response, $handler = null, $request = null ) {
		self::$scopes[] = array(
			'recorded' => array(),
			'warnings' => array(),
			'request'  => is_object( $request ) ? spl_object_id( $request ) : 0,
		);
		return $response;
	}

	/**
	 * Take the frame this request opened, and drop anything stacked on top.
	 *
	 * A frame above ours belongs to a dispatch that exited without reaching
	 * its own after-callbacks; it belongs to nobody now, and popping blindly
	 * would hand it to us and strand our own. Finding our frame by request
	 * identity is what makes that distinguishable.
	 *
	 * ONE THING THIS DOES NOT FIX, stated rather than implied. Between the
	 * moment a nested dispatch is orphaned — an exception unwinding out of
	 * rest_do_request() that the outer handler catches — and the moment the
	 * outer dispatch closes, the orphan is still the innermost frame, so a
	 * mutation the outer handler performs after the catch records into it and
	 * its warning is discarded here with the rest of the orphan. Repairing
	 * that needs a seam that fires as a nested dispatch unwinds, and
	 * WordPress has none: dispatch() does not even pop its own
	 * `dispatching_requests` on an exception (no `finally`,
	 * class-wp-rest-server.php :1064-1127), and `is_dispatching()` answers
	 * whether ANY dispatch is live, never which.
	 *
	 * ENFORCEMENT is untouched: every seam still decides and every block still
	 * blocks, whatever frame the decision records into. What the window
	 * affects is REPORTING, in two ways. The caller-visible warning is
	 * discarded with the orphan. And because the orphan carries its own
	 * `recorded` set, a rule the nested dispatch had already recorded is
	 * deduplicated against that set rather than the outer one — so the event
	 * fires once for the pair instead of once for each, which is the same
	 * per-dispatch bound applied to the wrong dispatch. A rule the orphan
	 * never saw fires and counts normally. Same class as the deletion message
	 * limit in spec §7: reporting quality on a path nobody anticipated, never
	 * enforcement.
	 *
	 * @param mixed $request Request whose dispatch is ending.
	 * @return array The frame, or the base frame when this request opened none.
	 */
	private static function close_frame( $request ) {
		$id   = is_object( $request ) ? spl_object_id( $request ) : 0;
		$mine = null;
		for ( $i = count( self::$scopes ) - 1; $i > 0; $i-- ) {
			if ( isset( self::$scopes[ $i ]['request'] ) && self::$scopes[ $i ]['request'] === $id ) {
				$mine = $i;
				break;
			}
		}
		if ( null === $mine ) {
			return self::scope(); // No frame of ours: read, take nothing.
		}
		$frame        = self::$scopes[ $mine ];
		self::$scopes = array_slice( self::$scopes, 0, $mine );
		return $frame;
	}

	/**
	 * Record a warn entry against the dispatch that earned it.
	 *
	 * @param array $rule Matched warn rule.
	 */
	public static function note_warning( array $rule ) {
		$scope                = &self::scope();
		$scope['warnings'][]  = self::warning_entry( $rule );
	}

	/**
	 * Attach this request's warnings to a handler result.
	 *
	 * @param array $result Handler result array.
	 * @return array
	 */
	public static function with_warnings( array $result ) {
		// Delivering a warning CONSUMES it. A direct handler puts the entry in
		// its own body, and this dispatch's response then goes out through
		// send_warning_header() like any other — which would attach the same
		// entry again as X-Aura-Rule-Warnings, and a client reading both
		// channels would report one mutation twice. Each warning is delivered
		// exactly once, by whichever channel the response can carry: the body
		// where we own it, the header where core does.
		$mine  = self::request_warnings();
		$scope = &self::scope();
		$scope['warnings'] = array();
		if ( ! empty( $mine ) ) {
			$result['warnings'] = array_values( $mine );
		}
		return $result;
	}

	/** Test hook. */
	public static function reset_request_warnings() {
		self::reset_records(); // Frames hold the warnings too (Task 5).
	}

	/**
	 * Warnings recorded this request — what the caller will be told, whether
	 * that arrives in a body or a header.
	 *
	 * @return array<int,array{rule:string,reason:string}>
	 */
	public static function request_warnings() {
		$scope = &self::scope();
		return $scope['warnings'];
	}

	/**
	 * `rest_request_after_callbacks` — core routes own their response body, so
	 * a warn that fired at a core seam reaches the caller as a header instead.
	 *
	 * This hook, not `rest_post_dispatch`: it runs inside
	 * `WP_REST_Server::respond_to_request()`, which `dispatch()` calls — so it
	 * fires for an internal `rest_do_request()` too. `rest_post_dispatch` lives
	 * in `serve_request()` and never sees one, which would leave a warn
	 * recorded and no header on the response the caller actually gets.
	 *
	 * @param mixed $response Response (or WP_Error).
	 * @param array $handler  Route handler.
	 * @param mixed $request  Request.
	 * @return mixed
	 */
	public static function send_warning_header( $response, $handler = null, $request = null ) {
		// This dispatch's frame, and only it. A shared list with a start mark
		// would hand the OUTER dispatch everything a nested rest_do_request()
		// recorded as well — the inner warning attributed to both.
		$frame = self::close_frame( $request );
		$mine  = isset( $frame['warnings'] ) ? $frame['warnings'] : array();
		if ( empty( $mine ) ) {
			return $response;
		}
		if ( is_wp_error( $response ) ) {
			// The callback failed AFTER a warn rule matched — a guarded
			// updater that ran and then errored, say. The warning is still
			// true and the caller still needs it, and a direct handler that
			// errored early never reached its own with_warnings().
			//
			// Convert here rather than writing into the WP_Error. Core's very
			// next statement is the same conversion
			// (respond_to_request() :1319 calls error_to_response(), which is
			// rest_convert_error_to_response()); doing it one line early costs
			// nothing — core then takes its `else` branch and
			// rest_ensure_response() returns our object untouched, with the
			// status the error carried — and it means the error path uses the
			// SAME single delivery channel as every other response instead of
			// a second one. Writing to the error instead would mean
			// WP_Error::add_data() archiving the previous data into
			// additional_data, which core then emits alongside the real one.
			$response = rest_convert_error_to_response( $response );
		}
		// A route callback may return a bare array; core runs
		// rest_ensure_response() AFTER this filter, so testing for an object
		// here would skip exactly the plugin routes the generic seam exists to
		// cover. Normalise first — rest_ensure_response() is idempotent, and
		// core re-running it on the object we return changes nothing.
		$response = rest_ensure_response( $response );
		if ( is_object( $response ) && method_exists( $response, 'header' ) ) {
			$response->header( 'X-Aura-Rule-Warnings', wp_json_encode( array_values( $mine ) ) );
		}
		return $response;
	}

	/**
	 * The plugin slug a rule names, from the `dir/file.php` form REST uses.
	 *
	 * @param string $plugin_file Plugin basename.
	 * @return string
	 */
	public static function plugin_slug( $plugin_file ) {
		$plugin_file = (string) $plugin_file;
		$dir         = dirname( $plugin_file );
		if ( '.' !== $dir && '' !== $dir ) {
			return $dir;
		}
		return preg_replace( '/\.php$/', '', basename( $plugin_file ) );
	}
}
