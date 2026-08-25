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

	/**
	 * Wire the core-REST enforcement seams and the (currently empty) counter
	 * hooks Task 10 fills in.
	 *
	 * Two ID-aware filters, because core fires the post-type-specific name: a
	 * page save is rest_pre_insert_page, a post save rest_pre_insert_post.
	 * Deletion has NO rest_pre_delete_* filter — core's post controller offers
	 * only `rest_{type}_trashable` (a bool) and the after-the-fact
	 * `rest_delete_{type}` action. The seams that can actually refuse are
	 * core's own data-layer short-circuits, which every deletion path goes
	 * through: wp_delete_post() → `pre_delete_post`, wp_trash_post() →
	 * `pre_trash_post`. They fire for wp-admin and WP-CLI too, so the callback
	 * applies the same agent test — a human deleting a page in wp-admin is
	 * unaffected.
	 *
	 * And any mutation on any route, for the freeze: products, media, menus,
	 * settings, routes nobody has thought of. Site rules only — this seam does
	 * not know target IDs; the filters above do.
	 *
	 * A warn at a core seam cannot change core's body; it goes out as a
	 * header. The frame that collects those warnings opens and closes on the
	 * ONE pair of hooks core runs together: both live in respond_to_request()
	 * (class-wp-rest-server.php :1256 and :1318) with no return between them,
	 * and respond_to_request() is reached from dispatch(), so an internal
	 * rest_do_request() opens and closes its own frame too. Neither
	 * rest_post_dispatch (serve_request() only — an internal dispatch never
	 * reaches it) nor rest_pre_dispatch (three exits after it that never reach
	 * a callback) can promise that. Priority 1 for the open, so the frame
	 * precedes guard_core_any()'s records at priority 5.
	 */
	public static function init() {
		add_action( 'aura_worker_rule_blocked', array( __CLASS__, 'record_block' ), 10, 2 );
		add_action( 'aura_worker_rule_warned', array( __CLASS__, 'record_warn' ), 10, 2 );

		// Core's own REST API is where Aura's content tools, an app-password
		// agent and a second MCP server all write posts and pages. Nothing on
		// that path reaches execute_tool(), so the rule is enforced at core's
		// seam — which is what makes it a property of the site, not of one
		// client. Third enforcement point; audit_rules lists it.
		add_filter( 'rest_pre_insert_post', array( __CLASS__, 'guard_core_post' ), 5, 2 );
		add_filter( 'rest_pre_insert_page', array( __CLASS__, 'guard_core_post' ), 5, 2 );
		add_filter( 'pre_delete_post', array( __CLASS__, 'guard_core_delete' ), 5, 3 );
		add_filter( 'pre_trash_post', array( __CLASS__, 'guard_core_delete' ), 5, 3 );

		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'guard_core_any' ), 5, 3 );

		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'open_frame' ), 1, 3 );
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'send_warning_header' ), 10, 3 );
	}

	/**
	 * Rolling 24h counters: ONE OPTION PER HOUR, each bumped with an atomic
	 * SQL increment.
	 *
	 * Not a transient (a TTL reset on every bump never expires on a busy site
	 * and counts forever). Not an hour => count map in one option either: that
	 * is read-modify-write, and two refusals in the same second would both
	 * read N and both write N+1, losing one — the audit would undercount
	 * exactly when enforcement is busiest. `UPDATE ... SET option_value =
	 * option_value + 1` is one statement; the database serialises it.
	 *
	 * Hour-granular: the bucket that straddles the 24h mark is KEPT, so the
	 * count is "the last 24h rounded up to the hour" — it may include up to 59
	 * minutes more, it never omits an event younger than 24h.
	 *
	 * Option names are prefix + hour index (hours since the epoch), zero-padded
	 * to seven digits in bucket_name() so the string comparison the sweep
	 * relies on orders exactly as the numbers do — today's index is six digits
	 * (~496000) and crosses a million in 2084.
	 */
	const BLOCKED_COUNTER = 'aura_worker_rules_blocked_h';
	const WARNED_COUNTER  = 'aura_worker_rules_warned_h';

	/**
	 * Bumps the blocked-in-the-last-24h counter.
	 *
	 * @param string   $tool_name Tool that was refused.
	 * @param array    $rule      The rule that decided.
	 * @param int|null $now       Unix time; injected for tests.
	 */
	public static function record_block( $tool_name = '', $rule = array(), $now = null ) {
		self::bump( self::BLOCKED_COUNTER, $now );
	}

	/**
	 * Bumps the warned-in-the-last-24h counter. See record_block().
	 *
	 * @param string   $tool_name Tool that ran.
	 * @param array    $rule      The rule that matched.
	 * @param int|null $now       Unix time; injected for tests.
	 */
	public static function record_warn( $tool_name = '', $rule = array(), $now = null ) {
		self::bump( self::WARNED_COUNTER, $now );
	}

	/**
	 * Option name for one hour of one counter.
	 *
	 * @param string $prefix BLOCKED_COUNTER or WARNED_COUNTER.
	 * @param int    $hour   Hours since the epoch.
	 * @return string
	 */
	public static function bucket_name( $prefix, $hour ) {
		// Zero-pad to 7 so lexical order == numeric order past hour 999999 too.
		return $prefix . str_pad( (string) (int) $hour, 7, '0', STR_PAD_LEFT );
	}

	/**
	 * @param string   $prefix BLOCKED_COUNTER or WARNED_COUNTER.
	 * @param int|null $now    Unix time; injected for tests.
	 */
	private static function bump( $prefix, $now = null ) {
		global $wpdb;
		$now  = null === $now ? time() : (int) $now;
		$hour = (int) floor( $now / HOUR_IN_SECONDS );
		$name = self::bucket_name( $prefix, $hour );

		// Atomic create-or-increment, one statement, no window at all.
		//
		// NOT add_option() to seed a '0' row before incrementing. Core's
		// add_option() SKIPS its own existence check whenever `notoptions`
		// lists the key — and get_option() writes `notoptions[$name] = true`
		// on every MISS, which count_24h() causes routinely just by reading
		// buckets that do not exist yet. When that happens, add_option()
		// falls through to `INSERT ... ON DUPLICATE KEY UPDATE option_value
		// = VALUES(option_value)`, which OVERWRITES an existing row with the
		// seed value and reports success — resetting a live count back to
		// zero. That is the same hazard insert_if_absent() (Task 4) documents
		// for the ruleset store, and it is exactly the undercount this
		// counter exists to prevent.
		//
		// So the seed and the increment are ONE statement instead: the first
		// bump of the hour inserts '1', every later bump in the same hour
		// adds one to whatever is there. Nothing ever reads the row first.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no') ON DUPLICATE KEY UPDATE option_value = option_value + 1",
				$name
			)
		);
		wp_cache_delete( $name, 'options' );
		// And `notoptions`: count_24h() reads this bucket through get_option()
		// before the first bump of the hour, and that miss lists the name in
		// core's negative cache. The INSERT above creates the row behind that
		// cache's back, so without this eviction get_option() keeps answering
		// "absent" — for the rest of the request, and on a persistent object
		// cache for every request after — and the count stays at zero.
		wp_cache_delete( 'notoptions', 'options' );

		// Sweep hour-options older than the boundary hour. Same-length names
		// (see bucket_name) make the string comparison a numeric one.
		// Through sweep_options() (Task 4), which evicts what it deletes: a
		// stale cache entry for a deleted bucket would be counted again by
		// count_24h() long after its hour had passed.
		$oldest = (int) floor( ( $now - DAY_IN_SECONDS ) / HOUR_IN_SECONDS );
		self::sweep_options( $prefix, self::bucket_name( $prefix, $oldest ) );
	}

	/**
	 * Events in the last 24 hours, rounded up to the hour (the boundary
	 * bucket is kept — see the class comment).
	 *
	 * @param string   $prefix Counter prefix.
	 * @param int|null $now    Unix time.
	 * @return int
	 */
	public static function count_24h( $prefix, $now = null ) {
		$now    = null === $now ? time() : (int) $now;
		$oldest = (int) floor( ( $now - DAY_IN_SECONDS ) / HOUR_IN_SECONDS );
		$newest = (int) floor( $now / HOUR_IN_SECONDS );
		$sum    = 0;
		for ( $h = $oldest; $h <= $newest; $h++ ) {
			$sum += (int) get_option( self::bucket_name( $prefix, $h ), 0 );
		}
		return $sum;
	}

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
		// as a client mismatch. Compared below, against the AUTHORITATIVE token
		// and after the store read (see the ordering note there); it still
		// holds for the very first document, before anything is stored.
		$site = isset( $doc['site'] ) && is_string( $doc['site'] ) ? $doc['site'] : '';

		// AUTHORITATIVE reads, in the connect's write order reversed: the store
		// first, then the token — both from the database, never from this
		// request's option cache (a request paused after authenticating with
		// the OLD token still has that token cached; read through the cache it
		// would pass wrong_site, misjudge the new sentinel as stale, and install
		// the old client's document — Codex round 11). The connect writes token
		// THEN sentinel, so reading store THEN token leaves exactly three cases:
		// (old store, old token) → decide, then the swap fails against the
		// sentinel and re-decides; (old store, new token) → wrong_site below;
		// (sentinel, new token) → client_mismatch below. There is no fourth.
		// ONE read of the ONE value this decision is about: the binding lives
		// inside this record, and the compare-and-swap names exactly it.
		$current = self::stored_uncached();
		if ( is_wp_error( $current ) ) {
			return $current; // the database, not the site: a retryable 500, never wrong_site
		}
		if ( isset( $GLOBALS['_sa_after_store_read'] ) && is_callable( $GLOBALS['_sa_after_store_read'] ) ) {
			call_user_func( $GLOBALS['_sa_after_store_read'] ); // test seam — inert in production
		}
		$ours = self::site_token_uncached();
		if ( is_wp_error( $ours ) ) {
			return $ours;
		}
		if ( '' === $site || '' === $ours || ! hash_equals( $ours, $site ) ) {
			return new WP_Error( 'aura_ruleset_wrong_site', 'Ruleset refused: not issued for this site', array( 'status' => 403 ) );
		}
		if ( null !== $current && isset( $current['envelope'] ) && '' !== (string) $current['envelope'] && hash_equals( (string) $current['envelope'], (string) $envelope ) ) {
			// The very document we already hold — a retry after a lost 200.
			// Delivered is delivered; saying 409 would record it as failed forever.
			return true;
		}
		$stale = null !== $current && self::is_stale( $current, $ours );
		if ( ! $stale && null !== $current && isset( $current['client'] ) && $client !== (string) $current['client'] ) {
			// Bound (the sentinel, or a record that followed it) or legacy: the
			// stored rules — or the binding — are not another client's to
			// replace. A rebinding goes through connect(). The seq comparison
			// below would be meaningless across clients.
			return new WP_Error(
				'aura_ruleset_client_mismatch',
				sprintf( 'Ruleset refused: issued for client %s, this site is bound to %s', $client, (string) $current['client'] ),
				array( 'status' => 409 )
			);
		}
		if ( ! $stale && null !== $current && $doc['seq'] <= (int) $current['seq'] ) {
			// Includes the sentinel's seq 0: the bound client's first document is 1.
			return new WP_Error(
				'aura_ruleset_stale',
				sprintf( 'Ruleset refused: seq %d is not newer than stored seq %d', $doc['seq'], (int) $current['seq'] ),
				array( 'status' => 409 )
			);
		}
		// A stale record (its token is no longer the site's — two connects
		// interleaved) binds nobody and bars nothing: this document, which the
		// wrong_site check above proved is for the site's CURRENT token,
		// replaces it with no seq comparison. The swap still names it.

		// Compare-and-swap. The seq check above read $current; between that read
		// and this write another request can install a newer ruleset — a retry
		// of seq 6 racing a fresh seq 7 would otherwise land last and roll policy
		// backwards, silently removing a block the operator just added. The
		// write therefore names the value it expects to replace, and a losing
		// racer re-reads and re-decides rather than overwriting.
		$record = array(
			'envelope'    => (string) $envelope,
			'client'      => $client,
			// The authoritative token hash read above — which the wrong_site
			// check just proved equals the document's own `site`. A real record
			// carries the binding forward, so the next old-client document meets
			// the same refusal and the stale check keeps working.
			'token_hash'  => $ours,
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
		if ( false !== $rows && $rows > 0 ) {
			wp_cache_delete( self::OPTION, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			return true;
		}

		// Either 0 rows (the NOT EXISTS subquery saw a row) or false (the
		// statement failed). For two first pushes racing, false is how the
		// unique index on option_name (MySQL 1062) or InnoDB's gap locks
		// (1213 deadlock) report the loser — the same lost race as 0 rows,
		// arriving through a different door. For a broken database it is a
		// real error. The database says which, and says it locale-free: a row
		// is there, or it is not. Never the error text — lc_messages
		// localises $wpdb->last_error, so matching "Duplicate entry" fixes
		// the race on English servers only. And never a retry when no row is
		// there: a lock-wait timeout (1205) leaves no winner, and retrying the
		// INSERT would wait the full innodb_lock_wait_timeout again on every
		// attempt (2.10.1).
		//
		// Evict first, whichever way this goes: this INSERT is only reached
		// right after current() just missed, and a real get_option() lists
		// the key in `notoptions` on exactly that miss (wp-includes/option.php
		// ~107) — short-circuiting every later read in this request. Evicted,
		// the next current() actually re-queries rather than trusting a
		// "no row" cache entry the database never confirmed; and on the lost
		// race below, swap_raw() succeeds but nothing could read it back
		// otherwise, silently disabling enforcement for the rest of the
		// request on a corrupt-row repair.
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$raw = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::OPTION )
		);
		if ( null === $raw ) {
			if ( false === $rows ) {
				// The statement failed and nothing won: a store failure, not a
				// race. Aura retries a 500 later; this request does not.
				return new WP_Error(
					'aura_ruleset_store_failed',
					'Ruleset not stored: the database refused the write.',
					array( 'status' => 500 )
				);
			}
			// 0 rows, yet the row the subquery saw has vanished under us
			// (deleted between the INSERT and this read). Re-decide from the
			// top rather than guessing at a value that no longer exists.
			return false;
		}
		// A row is there — we lost this INSERT. Whichever of the three
		// sub-paths below decides: a racer's valid record (re-decide against
		// it from the top) or a truncated/hand-edited value with no seq to
		// compare (repair it, still by CAS against its exact bytes).
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
	 * The raw stored record — a real ruleset OR the connect's seq-0 sentinel.
	 * This is what accept() decides against and names in its compare-and-swap:
	 * one value, read once.
	 *
	 * @since 2.10.2
	 *
	 * @return array|null
	 */
	public static function stored() {
		$rec = get_option( self::OPTION, null );
		return self::is_record( $rec ) ? $rec : null;
	}

	/**
	 * The stored RULESET, or null when there is none — the connect's sentinel
	 * is a binding, not a ruleset, and reads as null here so no policy exists
	 * until the bound client's first push. Everything that reports or enforces
	 * (rules(), audit_rules, the status route) goes through this.
	 *
	 * @return array|null
	 */
	public static function current() {
		$rec = self::stored();
		return ( null === $rec || self::is_sentinel( $rec ) ) ? null : $rec;
	}

	/**
	 * Is this value a stored record? One definition, used by stored() and by
	 * swap() on a value read straight from the database.
	 *
	 * @param mixed $rec Candidate.
	 * @return bool
	 */
	private static function is_record( $rec ) {
		return is_array( $rec ) && isset( $rec['seq'], $rec['rules'] ) && is_array( $rec['rules'] );
	}

	/**
	 * The connect's binding record: seq 0, no rules, flagged.
	 *
	 * @since 2.10.2
	 *
	 * @param array $rec Stored record.
	 * @return bool
	 */
	private static function is_sentinel( array $rec ) {
		return ! empty( $rec['bound'] ) && 0 === (int) $rec['seq'] && empty( $rec['rules'] );
	}

	/**
	 * Write the binding (2.10.2): the ruleset store becomes a seq-0 sentinel
	 * naming the client and the token this connect installed. Called only by
	 * Aura_Worker_Magic_Link::handle_connect(), after the token is stored.
	 *
	 * @since 2.10.2
	 *
	 * @param string $client     Aura client id.
	 * @param string $token_hash Hash of the site token just installed.
	 * @return true|WP_Error
	 */
	/**
	 * Write an option ONLY while the caller still holds a named claim, in one
	 * statement (2.11.0, round-10). A check followed by a write is two
	 * statements, and a request paused between them — deactivation does not
	 * terminate a running PHP request — resumes and writes anyway, over an
	 * install that has already answered 200. Joining the claim row into the
	 * write makes ownership part of its own predicate.
	 *
	 * Two statements are issued because MySQL has no conditional upsert that
	 * can carry this predicate: an UPDATE for a row that exists, an
	 * INSERT … SELECT for one that does not. Both match nothing for a caller
	 * whose fence is no longer in the claim.
	 *
	 * @param string $option   Option name.
	 * @param mixed  $value    Value (serialised as the options table stores it).
	 * @param string $claim    The claim option's name.
	 * @param string $fence    The caller's fence.
	 * @param string $autoload 'yes' or 'no' for a row this call creates.
	 */
	public static function write_option_if_claimed( $option, $value, $claim, $fence, $autoload = 'yes' ) {
		global $wpdb;
		if ( '' === (string) $fence || '' === (string) $claim ) {
			return;
		}
		$like = $wpdb->esc_like( $fence . '|' ) . '%';
		$raw  = maybe_serialize( $value );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} o JOIN {$wpdb->options} c ON c.option_name = %s AND c.option_value LIKE %s SET o.option_value = %s WHERE o.option_name = %s",
				$claim,
				$like,
				$raw,
				$option
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) SELECT %s, %s, %s FROM {$wpdb->options} c WHERE c.option_name = %s AND c.option_value LIKE %s AND NOT EXISTS ( SELECT 1 FROM {$wpdb->options} WHERE option_name = %s )",
				$option,
				$raw,
				$autoload,
				$claim,
				$like,
				$option
			)
		);
		self::forget_option_cache( $option );
	}

	/**
	 * Delete an option ONLY while the caller still holds a named claim — the
	 * same reasoning as write_option_if_claimed(), for the install steps that
	 * remove a value rather than set one.
	 *
	 * @param string $option Option name.
	 * @param string $claim  The claim option's name.
	 * @param string $fence  The caller's fence.
	 */
	public static function delete_option_if_claimed( $option, $claim, $fence ) {
		global $wpdb;
		if ( '' === (string) $fence || '' === (string) $claim ) {
			return;
		}
		$wpdb->query(
			$wpdb->prepare(
				"DELETE o FROM {$wpdb->options} o JOIN {$wpdb->options} c ON c.option_name = %s AND c.option_value LIKE %s WHERE o.option_name = %s",
				$claim,
				$wpdb->esc_like( $fence . '|' ) . '%',
				$option
			)
		);
		self::forget_option_cache( $option );
	}

	/**
	 * Evict what update_option()/delete_option() would have maintained for a
	 * row written behind the option cache's back.
	 *
	 * @param string $option Option name.
	 */
	private static function forget_option_cache( $option ) {
		wp_cache_delete( $option, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	public static function bind( $client, $token_hash, $claim = '', $fence = '' ) {
		$record = array(
			'envelope'    => '',
			'client'      => (string) $client,
			'token_hash'  => (string) $token_hash,
			'seq'         => 0,
			'issued_at'   => '',
			'received_at' => time(),
			'rules'       => array(),
			'bound'       => true,
		);
		// update_option() answers false both for "unchanged" and for "the write
		// failed", so the return value alone proves nothing; what proves the
		// binding is the ROW. Read it back from the database (never this
		// request's cache) and compare the fields that matter.
		// Under a connect's site claim the binding is written conditionally on
		// it (round-10): a handler that lost the claim must not overwrite the
		// winner's binding with one naming its own, now-superseded token.
		if ( '' !== (string) $claim && '' !== (string) $fence ) {
			self::write_option_if_claimed( self::OPTION, $record, $claim, $fence, 'no' );
		} else {
			update_option( self::OPTION, $record, false );
		}
		$stored = self::stored_uncached();
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		if ( ! is_array( $stored ) || empty( $stored['bound'] ) || (string) $stored['client'] !== (string) $client || (string) $stored['token_hash'] !== (string) $token_hash ) {
			return new WP_Error( 'aura_connect_store_failed', 'Connect not completed: the client binding could not be stored; retry.', array( 'status' => 500 ) );
		}
		return true;
	}

	/**
	 * Does the stored record bind this site to a client, for the token the
	 * site currently holds? A record without a token_hash (2.10.0/2.10.1, or
	 * an older dashboard's connect) binds nobody; a record whose token_hash is
	 * not the site's current token is STALE — written by a connect whose token
	 * a concurrent connect then overwrote — and binds nobody either.
	 *
	 * @since 2.10.2
	 *
	 * @param array|null $rec Stored record (stored()); read fresh when null.
	 * @return string The bound client, or ''.
	 */
	public static function bound_client( $rec = null ) {
		$rec = null === $rec ? self::stored_uncached() : $rec;
		// '' === … on the client, not empty(): a client id is opaque, so "0" is
		// a client — reported as unbound here it would contradict accept(),
		// whose comparison is strict and would bind it.
		if ( is_wp_error( $rec ) || null === $rec || '' === (string) ( $rec['client'] ?? '' ) || empty( $rec['token_hash'] ) ) {
			return ''; // a read failure here is reported by accept(); this is a report-only accessor
		}
		$ours = self::site_token_uncached();
		return ( ! is_wp_error( $ours ) && '' !== $ours && hash_equals( $ours, (string) $rec['token_hash'] ) ) ? (string) $rec['client'] : '';
	}

	/**
	 * Is this stored record for a token that is no longer the site's? $ours is
	 * the AUTHORITATIVE token (site_token_uncached()), read by the caller AFTER
	 * the store — never this request's cached copy.
	 *
	 * @since 2.10.2
	 *
	 * @param array  $rec  Stored record.
	 * @param string $ours The site's current token hash.
	 * @return bool
	 */
	private static function is_stale( array $rec, $ours ) {
		if ( empty( $rec['token_hash'] ) ) {
			return false; // legacy record: no claim about a token, never stale
		}
		return '' === (string) $ours || ! hash_equals( (string) $ours, (string) $rec['token_hash'] );
	}

	/**
	 * The stored record straight from the database. accept() must not decide
	 * on this request's option cache: an earlier read in the same request
	 * (enforce(), audit_rules) may have cached a value a connect has since
	 * replaced. Same statement swap_raw() uses to classify a lost race.
	 *
	 * @since 2.10.2
	 *
	 * @return array|null|WP_Error
	 */
	public static function stored_uncached() {
		$raw = self::option_raw( self::OPTION );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		$rec = null === $raw ? null : maybe_unserialize( $raw );
		return self::is_record( $rec ) ? $rec : null;
	}

	/**
	 * One raw options-table read, with the database's failure told apart from
	 * an absent row: $wpdb->get_var() answers null for BOTH, and a transient
	 * database error read as "no token" would turn a valid push into a 403
	 * wrong_site instead of the retryable store failure it is (Codex round 16).
	 *
	 * @since 2.10.2
	 *
	 * @param string $name Option name.
	 * @return string|null|WP_Error Raw value, null when absent, WP_Error on a database error.
	 */
	private static function option_raw( $name ) {
		global $wpdb;
		$wpdb->last_error = '';
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );
		if ( '' !== (string) $wpdb->last_error ) {
			return new WP_Error( 'aura_ruleset_store_failed', 'Ruleset not evaluated: the options table could not be read; retry.', array( 'status' => 500 ) );
		}
		return $raw;
	}

	/**
	 * The site token hash straight from the database — the value the connect
	 * writes FIRST, so a request that reads it after the store sees any
	 * re-home that the store read predates.
	 *
	 * @since 2.10.2
	 *
	 * @return string|WP_Error
	 */
	public static function site_token_uncached() {
		$raw = self::option_raw( 'aura_worker_site_token' );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		return null === $raw ? '' : (string) maybe_unserialize( $raw );
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
	public static function clear( $claim = '', $fence = '' ) {
		// Under a connect's site claim this is one of the install's writes, so
		// it is conditional on the claim like the rest (round-10).
		if ( '' !== (string) $claim && '' !== (string) $fence ) {
			self::delete_option_if_claimed( self::OPTION, $claim, $fence );
		} else {
			delete_option( self::OPTION );
		}
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
	 * Prefix for the per-magic-link connect claims (Aura_Worker_Magic_Link).
	 * Swept here, by the age inside the value, because this class already runs
	 * the daily bounded sweep.
	 *
	 * @since 2.10.2
	 */
	const MAGIC_CLAIM = 'aura_magic_claim_';

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
	 * `$parse` moves the bound from the NAME to the stored VALUE, for the one
	 * family of rows whose age is not in its name: the magic-link claims, named
	 * after the magic id and dated inside the value. Same closed race — a claim
	 * is always written with the current time, so no row this sweep reads can be
	 * recreated below the bound it read it against.
	 *
	 * @param string        $prefix Option-name prefix.
	 * @param string|int    $before Delete only rows strictly below this — a name
	 *                              when $parse is null, else a parsed value.
	 * @param callable|null $parse  Given a row's raw value, return the integer
	 *                              compared against $before. Null = compare names.
	 */
	private static function sweep_options( $prefix, $before, $parse = null ) {
		global $wpdb;
		if ( null !== $parse ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( $prefix ) . '%'
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				if ( isset( $row['option_name'], $row['option_value'] ) && (int) call_user_func( $parse, $row['option_value'] ) < (int) $before ) {
					delete_option( $row['option_name'] );
				}
			}
			return;
		}
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
		//
		// add_option() is safe here, unlike in bump(): the claim's value is
		// the constant 1, so if the row already exists this write's fallback
		// `INSERT ... ON DUPLICATE KEY UPDATE` sets option_value back to the
		// same 1, MySQL reports 0 rows affected, and add_option() correctly
		// returns false ("already claimed"). Never copy this for a value that
		// changes between calls — that is precisely the shape bump() had to
		// stop using because it silently overwrites a real count with the seed.
		if ( add_option( self::expired_claim( self::SWEEP_CLAIM, $day ), 1, '', false ) ) {
			// Every claim from a day that has ended, for every rule — one
			// statement, because the day leads the name. This is where retired
			// rules' claims go too: nothing has to know which rules left.
			self::sweep_stale_claims( $day );
			// And the magic-link connect claims a dead handler left behind
			// (2.10.2). Garbage only: the magic transient such a row belonged
			// to expired fifty minutes before the row is eligible, so sweeping
			// one admits nobody — the link is refused at the transient check.
			// Dated inside the value ("<fence>|<unix ts>"), not in the name.
			self::sweep_options(
				self::MAGIC_CLAIM,
				$now - HOUR_IN_SECONDS,
				static function ( $v ) {
					return (int) substr( strrchr( '|' . $v, '|' ), 1 );
				}
			);
		}
		foreach ( self::expired_keys( $now ) as $key ) {
			$hash  = self::rule_hash( $key );
			$today = self::expired_claim( $hash, $day );
			// Same constant-value reasoning as the sweep's own claim above.
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

	/* ------------------------------------------------------------------ */
	/* Core REST content writes                                            */
	/* ------------------------------------------------------------------ */

	/** Post types the vocabulary can name. The delete filter fires for all. */
	const CORE_TYPES = array( 'post', 'page' );

	/**
	 * Test seam for REST_REQUEST, which a test cannot define per case.
	 *
	 * @var bool|null
	 */
	public static $rest_request_override = null;

	/**
	 * Test seam for core's $wp_rest_auth_cookie global.
	 *
	 * @var bool|null
	 */
	public static $cookie_auth_override = null;

	// self::$scopes and reset_records() were added with enforce() in Task 5;
	// the seams below only consume the `recorded` flag it returns.

	/**
	 * Did WordPress itself authenticate this request from a cookie session?
	 *
	 * Core decides this before any handler runs: rest_cookie_collect_status()
	 * sets $wp_rest_auth_cookie = true only when the auth cookie validated,
	 * and rest_cookie_check_errors() then requires a verified nonce or ends
	 * the request (no nonce at all → the user is set to 0 and could not write).
	 * So `true === $wp_rest_auth_cookie` at our seams means cookie AND nonce,
	 * verified by core. A header the caller chose to send proves nothing —
	 * any bearer client can add X-WP-Nonce — so it is never consulted.
	 *
	 * An application-password session sets this global false and reports
	 * itself through rest_get_authenticated_app_password(); checked too, as
	 * defence in depth.
	 *
	 * @return bool
	 */
	private static function is_cookie_authenticated() {
		if ( function_exists( 'rest_get_authenticated_app_password' ) && null !== rest_get_authenticated_app_password() ) {
			return false; // An agent, whatever else is true.
		}
		if ( null !== self::$cookie_auth_override ) {
			return (bool) self::$cookie_auth_override;
		}
		return isset( $GLOBALS['wp_rest_auth_cookie'] ) && true === $GLOBALS['wp_rest_auth_cookie'];
	}

	/**
	 * Is this a REST request from an agent — not a human, not the public, not
	 * SiteAgent itself?
	 *
	 * Not agents, at every seam:
	 *  - wp-admin, WP-CLI and cron: the site operating on itself (not REST).
	 *  - A Gutenberg save: that is REST too (/wp/v2), but core authenticated it
	 *    from a cookie session with a verified nonce (see
	 *    is_cookie_authenticated()). That is an editor at the keyboard, and the
	 *    spec promises wp-admin is unaffected.
	 *  - SiteAgent's own routes: execute_tool() already decided; refusing again
	 *    would double-enforce the same call on its way to the same post.
	 *
	 * And at the GENERIC seam only ($require_identity = true), an agent must be
	 * POSITIVELY identified: an authenticated user. That seam sees every REST
	 * route, public ones included — a storefront checkout, a form submission,
	 * a payment webhook — and "not a cookie session" must never be read there
	 * as "therefore an agent", or a freeze takes the shop offline.
	 *
	 * The ID-aware INSERT filters pass false. Core reaches
	 * rest_pre_insert_{post,page} only for a caller it has already authorised
	 * to edit, so nothing legitimate is anonymous there — and if an anonymous
	 * write ever did arrive, standing aside would be the wrong answer.
	 *
	 * The DELETE data seam does NOT get that exemption, and the difference is
	 * the reason this parameter exists rather than being hard-coded.
	 * `pre_delete_post` / `pre_trash_post` are data-layer hooks, not REST
	 * ones: any plugin's PUBLIC endpoint can reach them, and core has
	 * authorised nothing. Reading "not a cookie session" as "therefore an
	 * agent" there would let a site freeze break an unauthenticated form
	 * submission or checkout cleanup that deletes a draft — precisely the
	 * anonymous traffic guard_core_any() is careful to let through, reversed
	 * one layer down. So guard_core_delete() demands the same positive
	 * identity as the generic seam.
	 *
	 * @param WP_REST_Request|null $request          Request, when the filter has one.
	 * @param bool                 $require_identity Demand an authenticated user (generic seam).
	 * @return bool
	 */
	private static function is_agent_rest_request( $request = null, $require_identity = true ) {
		$is_rest = null !== self::$rest_request_override
			? (bool) self::$rest_request_override
			: ( defined( 'REST_REQUEST' ) && REST_REQUEST );
		if ( ! $is_rest ) {
			return false;
		}
		if ( $require_identity && ! is_user_logged_in() ) {
			return false; // Public traffic on a public route.
		}
		if ( self::is_cookie_authenticated() ) {
			return false;
		}
		if ( class_exists( 'Aura_Worker_Call_Context' ) && Aura_Worker_Call_Context::is_own_transport() && null !== Aura_Worker_Call_Context::rest_route() ) {
			return false;
		}
		return true;
	}

	/** Methods that do not change anything. */
	const SAFE_METHODS = array( 'GET', 'HEAD', 'OPTIONS' );

	/**
	 * Routes whose writes the ID-aware filter owns — EXACTLY the shapes core
	 * dispatches through rest_pre_insert_{post,page}: the collection
	 * (`/wp/v2/posts`) and one item (`/wp/v2/posts/7`), with or without a
	 * trailing slash. The generic seam skips a CREATE or UPDATE on these: a
	 * site rule already matches any non-empty declaration, so the insert
	 * filter enforces a freeze on a page write by itself, and enforcing here
	 * too would fire the warn hook twice for one mutation. A DELETE on the
	 * same shapes is not skipped — core runs no pre-delete filter in the REST
	 * controller, so this seam is where the route's ID can still stop it.
	 *
	 * Nothing nested is exempt. `/wp/v2/posts/7/revisions/9` (DELETE) and
	 * `/wp/v2/posts/7/autosaves` (POST) are mutations core serves WITHOUT
	 * running either ID filter; a prefix match would have handed them a hole
	 * under the site freeze. They go through the generic seam like any route.
	 */
	const ID_AWARE_ROUTES = '#^/wp/v2/(posts|pages)(/\d+)?/?$#';

	/**
	 * Core's plugins controller route — collection (`/wp/v2/plugins`, install
	 * via POST) and item (`/wp/v2/plugins/<dir>/<file>` or a bare `<file>` for
	 * a single-file plugin, PUT/PATCH activates or deactivates via `status`,
	 * DELETE removes). Mirrors
	 * `WP_REST_Plugins_Controller`'s own item pattern exactly — do not loosen
	 * it; a wider match would misparse a slug that happens to contain a dot.
	 * There is no `rest_pre_*` filter anywhere in this controller, so this
	 * route seam is the only place a plugin rule can hold.
	 */
	const PLUGIN_ROUTE = '#^/wp/v2/plugins(?:/(?P<plugin>[^.\/]+(?:/[^.\/]+)?))?/?$#';

	/**
	 * `rest_request_before_callbacks` — every REST route, before its handler.
	 *
	 * Applies SITE rules on any route SiteAgent does not own — under a
	 * freeze, any unsafe method from an agent is refused — plus PLUGIN rules
	 * on core's own plugin routes (activate/deactivate/delete/install via
	 * `/wp/v2/plugins`): that controller has no `rest_pre_*` filter at all,
	 * so the route seam is the only place a `plugin:<slug>` rule can meet an
	 * agent using core's REST API directly (the legacy SiteAgent update route
	 * and `update_plugin_safely` already declare `plugin:<slug>`; this closes
	 * the third door). Page/post rules are not applied here because this seam
	 * does not reliably know the target ID; the type-specific filters do.
	 *
	 * @param mixed           $response Earlier short-circuit, if any.
	 * @param array           $handler  Route handler.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function guard_core_any( $response, $handler, $request ) {
		if ( null !== $response || ! self::is_agent_rest_request( $request ) ) {
			return $response;
		}
		$method = is_object( $request ) && method_exists( $request, 'get_method' ) ? strtoupper( (string) $request->get_method() ) : 'GET';
		if ( in_array( $method, self::SAFE_METHODS, true ) ) {
			return $response;
		}
		$route = is_object( $request ) && method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
		if ( preg_match( self::ID_AWARE_ROUTES, $route, $shape ) ) {
			// `rest_pre_insert_{post,page}` owns creates and updates on these
			// routes and carries the rule there, so enforcing here as well
			// would record one mutation twice.
			//
			// DELETE is different: core runs NO pre-delete filter in the REST
			// controller, so nothing downstream of this point knows the rule
			// until the post is already gone. The route names the target, so
			// this seam enforces it — belt to the `pre_delete_post` braces.
			if ( 'DELETE' !== $method || ! isset( $shape[2] ) || '' === $shape[2] ) {
				return $response;
			}
			$id      = (string) (int) ltrim( $shape[2], '/' );
			$verdict = self::enforce(
				array(
					array( 'type' => 'page', 'id' => $id ),
					array( 'type' => 'post', 'id' => $id ),
				),
				'core.rest.delete:' . $route
			);
			if ( 'block' === $verdict['effect'] ) {
				$res = self::blocked_result( 'DELETE ' . $route, $verdict['rule'] );
				return new WP_Error( 'aura_rule_blocked', $res['error'], array( 'status' => 403, 'rule' => $res['rule'] ) );
			}
			if ( 'warn' === $verdict['effect'] && ! empty( $verdict['recorded'] ) ) {
				self::note_warning( $verdict['rule'] );
			}
			return $response;
		}
		if ( preg_match( self::PLUGIN_ROUTE, $route, $pm ) ) {
			// Core runs no rest_pre_* filter on the plugins controller at
			// all, so this route seam is the only place a plugin:<slug> rule
			// can meet activate/deactivate (PUT/PATCH via `status`), delete,
			// or install (POST with `slug`) through /wp/v2/plugins directly.
			$slug = '';
			if ( isset( $pm['plugin'] ) && '' !== $pm['plugin'] ) {
				$slug = self::plugin_slug( $pm['plugin'] );
			} elseif ( 'POST' === $method && is_object( $request ) && method_exists( $request, 'get_param' ) ) {
				$raw = (string) $request->get_param( 'slug' );
				if ( '' !== $raw ) {
					$slug = self::plugin_slug( sanitize_text_field( $raw ) );
				}
			}
			if ( '' !== $slug ) {
				// Declare the plugin touch ONLY. A site rule already matches
				// any non-empty declaration, so a freeze catches this the
				// same way a plugin rule does; declaring site:* alongside
				// would change nothing and read as if it did.
				$verdict = self::enforce(
					array( array( 'type' => 'plugin', 'id' => $slug ) ),
					'core.rest.' . strtolower( $method ) . ':' . $route
				);
				if ( 'block' === $verdict['effect'] ) {
					$res = self::blocked_result( $method . ' ' . $route, $verdict['rule'] );
					return new WP_Error( 'aura_rule_blocked', $res['error'], array( 'status' => 403, 'rule' => $res['rule'] ) );
				}
				if ( 'warn' === $verdict['effect'] && ! empty( $verdict['recorded'] ) ) {
					self::note_warning( $verdict['rule'] );
				}
				return $response;
			}
			// Nothing usable derived (e.g. an install request with no slug —
			// core rejects that itself). Fall through to the site-only
			// branch below rather than declaring an empty list, which
			// normalises to the "undeclared" sentinel and would over-block.
		}
		$verdict = self::enforce( array( array( 'type' => 'site', 'id' => '*' ) ), 'core.rest.' . strtolower( $method ) . ':' . $route );
		if ( 'block' === $verdict['effect'] ) {
			$res = self::blocked_result( $method . ' ' . $route, $verdict['rule'] );
			return new WP_Error( 'aura_rule_blocked', $res['error'], array( 'status' => 403, 'rule' => $res['rule'] ) );
		}
		if ( 'warn' === $verdict['effect'] && ! empty( $verdict['recorded'] ) ) {
			self::note_warning( $verdict['rule'] );
		}
		return $response;
	}

	/**
	 * `rest_pre_insert_post` / `rest_pre_insert_page`.
	 *
	 * @param stdClass|WP_Error $prepared Prepared post, or an earlier error.
	 * @param WP_REST_Request   $request  Request.
	 * @return stdClass|WP_Error
	 */
	public static function guard_core_post( $prepared, $request ) {
		if ( is_wp_error( $prepared ) || ! self::is_agent_rest_request( $request, false ) ) {
			return $prepared;
		}
		$id = 0;
		if ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
			$id = (int) $request->get_param( 'id' );
		}
		if ( $id <= 0 && is_object( $prepared ) && isset( $prepared->ID ) ) {
			$id = (int) $prepared->ID;
		}
		$touches = $id > 0
			? array( array( 'type' => 'post', 'id' => (string) $id ), array( 'type' => 'page', 'id' => (string) $id ) )
			: array( array( 'type' => 'site', 'id' => '*' ) ); // A create: nothing narrower yet.

		$verdict = self::enforce( $touches, 'core.rest.insert' );
		if ( 'block' === $verdict['effect'] ) {
			$res = self::blocked_result( 'core.rest.insert', $verdict['rule'] );
			return new WP_Error( 'aura_rule_blocked', $res['error'], array( 'status' => 403, 'rule' => $res['rule'] ) );
		}
		if ( 'warn' === $verdict['effect'] && ! empty( $verdict['recorded'] ) ) {
			self::note_warning( $verdict['rule'] );
		}
		return $prepared;
	}

	/**
	 * `pre_delete_post` and `pre_trash_post` — core's data-layer short-circuits.
	 *
	 * These are the only seams that can actually stop a deletion: the REST post
	 * controller has no pre-delete filter (only `rest_{type}_trashable`, a
	 * bool, and `rest_delete_{type}`, an action that fires after the post is
	 * gone). Returning a non-null value here stops `wp_delete_post()` /
	 * `wp_trash_post()`, and every deletion path in WordPress goes through one
	 * of them — REST routes we anticipated, routes a plugin adds, and anything
	 * else an agent can reach. That is why this is a rule about deletion rather
	 * than a list of routes.
	 *
	 * Because they also fire for wp-admin, WP-CLI and cron, the callback keeps
	 * the same agent test used everywhere else: a human deleting a page in the
	 * editor is not touched.
	 *
	 * @param mixed          $check Earlier short-circuit, if any (null = proceed).
	 * @param WP_Post|object $post  Post being deleted or trashed.
	 * @param mixed          $extra `$force_delete` (delete) / previous status (trash).
	 * @return mixed `$check` (null) to proceed, false to refuse. NEVER WP_Error.
	 */
	public static function guard_core_delete( $check, $post, $extra = null ) {
		// Positive identity, as at the generic seam: this hook is reachable
		// from any public endpoint, and an anonymous caller is not an agent.
		if ( null !== $check || ! self::is_agent_rest_request() ) {
			return $check;
		}
		if ( ! is_object( $post ) || ! isset( $post->ID, $post->post_type ) || ! in_array( (string) $post->post_type, self::CORE_TYPES, true ) ) {
			return $check;
		}
		$id      = (string) (int) $post->ID;
		$verdict = self::enforce(
			array( array( 'type' => 'post', 'id' => $id ), array( 'type' => 'page', 'id' => $id ) ),
			'core.delete:' . $post->post_type . ':' . $id
		);
		if ( 'block' === $verdict['effect'] ) {
			// `false`, and nothing more.
			//
			// This value becomes wp_delete_post()'s / wp_trash_post()'s return
			// value, whose contract is a post object or false — a WP_Error here
			// is truthy and would be read as success. `false` is the "did not
			// delete" every caller already handles, so the deletion stops and
			// the REST controller answers its own `rest_cannot_delete`.
			//
			// It does NOT carry the rule's name to the caller on this path, and
			// that is a deliberate limit rather than an oversight. Reporting it
			// would mean holding the refusal until something can attach it to a
			// response, and WordPress gives no seam that reliably runs for the
			// dispatch that caused it: `rest_post_dispatch` fires in
			// serve_request(), while an internal rest_do_request() calls
			// dispatch() directly and never reaches it.
			//
			// Nothing about the ENFORCEMENT depends on it: the post is not
			// deleted, `aura_worker_rule_blocked` fires, `audit_rules` counts
			// it, and the fleet sees it. The exact 403 naming the rule is
			// carried by guard_core_any() for `/wp/v2/(posts|pages)/<id>` —
			// the routes Aura's own tools and Angie actually use. What is lost
			// is only the message quality on a route nobody anticipated.
			return false;
		}
		if ( 'warn' === $verdict['effect'] && ! empty( $verdict['recorded'] ) ) {
			self::note_warning( $verdict['rule'] );
		}
		return $check;
	}
}
