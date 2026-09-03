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
	const TYPES = array( 'site', 'page', 'post', 'plugin', 'design_system', 'page_create' );

	/** Target types that carry no id — a rule on them names the whole category. */
	const ID_LESS_TYPES = array( 'site', 'design_system', 'page_create' );

	/** `page` and `post` are the same ID seen from two directions. */
	const CONTENT_TYPES = array( 'page', 'post' );

	/** Effects the matcher understands. `allow` is meaningful only at the Elementor door (2.16.0). */
	const EFFECTS = array( 'block', 'warn', 'allow' );

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
	 * `block` beats `warn` beats `allow` beats no match. Expired rules never
	 * match. Unknown target types never match — Aura refuses them at write
	 * time, and if one arrives anyway the site does not guess.
	 *
	 * @param array    $touches Resources the call declares: list of {type,id}.
	 * @param array    $rules   Rules from the current ruleset.
	 * @param int|null $now     Unix time; defaults to now. Injected for tests.
	 * @return array|null
	 */
	/**
	 * This site's own id in the rules' vocabulary, or '' when it is unknown.
	 *
	 * ONE accessor, so every surface that judges a rule — the enforcement
	 * path and the preview the gateway shows before approval — asks the same
	 * question of the same record. A second spelling is how a preview comes to
	 * report a block that execution would skip (round-1 P2).
	 *
	 * Read defensively: '' both when the document carried no identity and when
	 * the record predates 2.12.0 and no repair has run yet. The matcher turns
	 * '' into "enforce everything".
	 *
	 * @since 2.12.0
	 *
	 * @return string
	 */
	public static function site_ref() {
		$rec = self::stored();
		return ( is_array( $rec ) && isset( $rec['site_ref'] ) && is_string( $rec['site_ref'] ) ) ? $rec['site_ref'] : '';
	}

	/**
	 * Is this rule's `sites` a NARROWING this site can act on?
	 *
	 * Only a non-empty list of non-empty strings is. Aura's validator refuses
	 * anything else at write time, so an accepted document should never carry
	 * one — but `accept()` does not validate individual rules, and a rule is
	 * enforced from whatever was signed. A malformed value (`sites: [42]`, a
	 * decoded object, a list with one junk entry) must therefore read as NO
	 * narrowing at all: the rule stays client-wide and over-blocks. Treating it
	 * as a narrowing would fail OPEN — the strict comparison could never match,
	 * so the rule would be skipped everywhere (round-1 P2).
	 *
	 * @since 2.12.0
	 *
	 * @param mixed $sites The rule's `sites` value.
	 * @return bool
	 */
	private static function is_site_narrowing( $sites ) {
		if ( ! is_array( $sites ) || empty( $sites ) ) {
			return false;
		}
		// A decoded JSON object arrives as an associative array; its KEYS are
		// not ids, so it is not a list of them.
		if ( array_keys( $sites ) !== range( 0, count( $sites ) - 1 ) ) {
			return false;
		}
		foreach ( $sites as $id ) {
			// A stored identity is TRIMMED at accept() time, and the match is
			// strict — so `" res_A "` or `" "` can never equal any site's id,
			// and reading them as a narrowing would skip the rule EVERYWHERE
			// (round-3 P2). Nor are they trimmed here: Aura's ids carry no
			// padding, so trimming would invent an id the document did not
			// name. An entry that is not already normalised means the list is
			// not one this site can read — client-wide, over-block.
			if ( ! is_string( $id ) || '' === trim( $id ) || trim( $id ) !== $id ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Does this rule reach THIS site?
	 *
	 * The one predicate for it (round-2 P2). Both the matcher and the expiry
	 * report ask it, so a rule scoped to a sibling can never be skipped for
	 * enforcement while still being announced as this site's expired rule —
	 * two statements about one rule, from one test.
	 *
	 * Normative, and fail-closed in both directions that matter: an unknown
	 * identity reaches EVERY rule, and a `sites` this site cannot read is no
	 * narrowing at all.
	 *
	 * @since 2.12.0
	 *
	 * @param array  $rule     The rule.
	 * @param string $site_ref This site's id, or '' when unknown.
	 * @return bool
	 */
	private static function rule_reaches_here( array $rule, $site_ref ) {
		if ( ! isset( $rule['sites'] ) || ! self::is_site_narrowing( $rule['sites'] ) ) {
			return true; // client-wide
		}
		return ( '' === $site_ref ) || in_array( $site_ref, $rule['sites'], true );
	}

	public static function match( array $touches, array $rules, $now = null, $site_ref = '' ) {
		$now      = null === $now ? time() : (int) $now;
		$winner   = null;
		$touched  = self::normalize_touches( $touches );
		$site_ref = is_string( $site_ref ) ? $site_ref : '';

		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || self::is_expired( $rule, $now ) ) {
				continue;
			}
			$effect = isset( $rule['effect'] ) ? (string) $rule['effect'] : '';
			if ( ! in_array( $effect, self::EFFECTS, true ) ) {
				continue;
			}
			// Site scoping (Aura spec §4, 2.12.0), through the shared predicate
			// so the expiry report cannot disagree with enforcement about the
			// very same rule.
			if ( ! self::rule_reaches_here( $rule, $site_ref ) ) {
				continue; // scoped to other sites: not this one's rule
			}
			if ( ! self::rule_touches( $rule, $touched ) ) {
				continue;
			}
			if ( 'block' === $effect ) {
				return $rule; // Nothing outranks a block.
			}
			// warn > allow: a caveat someone wrote wins over an opt-in someone
			// else wrote (spec §3.4). First match wins within a rank.
			if ( null === $winner || ( 'warn' === $effect && 'allow' === $winner['effect'] ) ) {
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
		if ( in_array( $type, self::ID_LESS_TYPES, true ) ) {
			if ( 'site' === $type ) {
				return ! empty( $touched ); // a freeze catches everything declared
			}
			return isset( $touched[ $type . ':*' ] ); // design_system / page_create: the category itself
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
	 * Runs the whole decision under the site-wide claim (#434): an unbind
	 * (Task 3) writes under the same claim, and the two must never interleave
	 * — a ruleset landing mid-unbind, or an unbind landing mid-push, would
	 * leave the site's enforced state and Aura's record of it disagreeing.
	 * A caller that cannot take the claim is told to retry, not queued: this
	 * is a REST request, not a job.
	 *
	 * @param string $envelope Signed document from the gateway.
	 * @param int    $attempt  Internal: how many times this call has re-decided
	 *                         after losing the compare-and-swap.
	 * @return true|array|WP_Error True for an ordinary ruleset; the unbind
	 *                             answer `{ unbound, seq, cleanup_complete }`
	 *                             for an unbind document or a fast-path retry
	 *                             (#434); WP_Error otherwise.
	 */
	public static function accept( $envelope, $attempt = 0 ) {
		$fence = Aura_Worker_Magic_Link::claim_site();
		if ( '' === $fence ) {
			return new WP_Error(
				'aura_site_busy',
				__( 'Another Aura operation holds this site; retry shortly.', 'digitizer-site-worker' ),
				array( 'status' => 503 )
			);
		}
		try {
			return self::accept_under_claim( $envelope, $attempt, $fence );
		} finally {
			Aura_Worker_Magic_Link::release_site( $fence );
		}
	}

	/**
	 * The former accept() body, run under a site claim the caller already
	 * holds; $fence fences the unbind marker's writes (#434). Never call this
	 * without first taking the claim — the CAS retry branch below recurses
	 * HERE, not into accept(), so the claim is taken exactly once per request
	 * and never re-entered.
	 *
	 * Verification order (spec §2.3, Phase A):
	 *   0. the unbind MARKER — before any signature work at all;
	 *   1. signature, then `v` / `seq` / `rules` / `client` shape;
	 *   2. (nothing: step 0 already handled a marker that is set);
	 *   3. `site` against the authoritative uncached token, `client` against
	 *      the stored record, then the stale-seq comparison;
	 *   4. an `unbind` document writes the marker under the claim — before
	 *      which nothing about this request has written anything.
	 *
	 * @param string $envelope Signed document from the gateway.
	 * @param int    $attempt  How many times this call has re-decided after
	 *                         losing the compare-and-swap.
	 * @param string $fence    This request's site-claim fence.
	 * @return true|array|WP_Error
	 */
	private static function accept_under_claim( $envelope, $attempt, $fence ) {
		// STEP 0 — the marker fast path, BEFORE any signature work (spec §2.3).
		// An unbound site may already have had its gateway key deleted by a
		// previous Phase B, so nothing here may depend on verifying anything:
		// the token that authenticated this request is the whole authority.
		//
		// is_set_strict(), never is_set(): an UNREADABLE marker is not "the
		// site is bound". A database blip answers the retryable store failure
		// and writes nothing, rather than letting an ordinary push install
		// rules on a site Aura has already disconnected. This is exactly why
		// Aura_Worker_Unbind::read() is tri-state.
		$marked = Aura_Worker_Unbind::is_set_strict();
		if ( is_wp_error( $marked ) ) {
			return $marked;
		}
		if ( $marked ) {
			// The document is read LENIENTLY here — peek_payload(), no
			// verification — only to learn which seq this retry echoes and
			// whether it says `final`. fast_path_or_refusal() re-reads the
			// marker itself and is the authority; a marker that vanished
			// between the probe and it answers null and we carry on as an
			// ordinary push.
			$fast = self::fast_path_or_refusal( $fence, self::final_flag_of( $envelope ), self::seq_of( $envelope, null ) );
			if ( null !== $fast ) {
				return $fast;
			}
		}

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

		// WHO this site is, in Aura's own names (spec §4, 2.12.0). `site`
		// proves the document was issued FOR us; `site_ref` is the id a rule's
		// `sites` list names. Absent — an older Aura, or a document signed
		// before that field existed — stores as '' and the matcher then
		// enforces every scoped rule: an identity we do not know can only be
		// answered by over-blocking.
		$site_ref = isset( $doc['site_ref'] ) && is_string( $doc['site_ref'] ) ? trim( $doc['site_ref'] ) : '';

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
			//
			// It is also the ONLY moment a ≤2.11 record can learn its own
			// `site_ref` from the wire (2.12.0): Aura does not re-push a
			// document it has already confirmed, so a site upgraded between
			// two pushes would otherwise hold rules it cannot scope. Heal the
			// record here — the same verified bytes, one field added — and
			// answer the retry as before.
			if ( '' !== $site_ref && ( ! isset( $current['site_ref'] ) || $current['site_ref'] !== $site_ref )
				// Same claim re-check as the primary write below (I1): this
				// heal is a write too, and best-effort already — silently
				// skipping it when the claim is gone is consistent with the
				// "re-deciding here would be worse than doing nothing" note
				// just below, not a new failure mode.
				&& Aura_Worker_Magic_Link::holds_site_claim( $fence )
			) {
				$healed             = $current;
				$healed['site_ref'] = $site_ref;
				// A lost swap (or a database refusal) is NOT a failure of this
				// request: the racer's record was written by an accept() of a
				// newer document, which stores `site_ref` itself. Re-deciding
				// here would be worse than doing nothing — the retry would meet
				// the newer seq and answer 409 for a document Aura has already
				// delivered, turning an idempotent 200 into a recorded conflict.
				self::swap( $current, $healed );
			}
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

		// STEP 4 — an unbind document (spec §2.3). Everything above ran first
		// and unchanged: an unbind is refused for the same reasons, with the
		// same codes, as any other document. Only now — with the signature
		// verified, the token proven ours and the seq proven newer — does the
		// marker get written, and the ruleset store is left exactly as it was
		// (Phase B clears it, under the same claim).
		if ( isset( $doc['unbind'] ) && true === $doc['unbind'] ) {
			// The token hash the document was bound to — the AUTHORITATIVE one
			// read above and proved equal to the document's own `site`, never a
			// fresh read. A retry is matched against this after the stored
			// record is gone.
			$marker = self::new_marker( $ours, $site_ref, $client, (int) $doc['seq'] );
			if ( is_wp_error( $marker ) ) {
				return $marker; // the token moved under this request; nothing is written
			}
			// Claim-fenced and verified by read-back; a failed write is a
			// retryable 500 with nothing else touched — never a silent unbind
			// that no boundary can see.
			//
			// DELIBERATELY WEAKER than append_authenticating_uuid()'s check,
			// and only safe HERE. write_under_claim()'s read-back proves "the
			// row now names my site at my seq" — it says nothing about the
			// UUIDs. That is sufficient at THIS call site and nowhere else,
			// because step 0 already established the marker is ABSENT: there
			// is no prior row whose site+seq could match while this write's
			// other fields were lost, so a write that did not land reads back
			// as no row at all and the read-back fails. Tasks 4/8: any write
			// to a marker that may ALREADY EXIST — an append, a re-mark, a
			// Phase-B rewrite — must verify the field it changed, the way
			// append_authenticating_uuid() re-reads and checks the UUID
			// (rules.php, `append_authenticating_uuid()`). Do not copy the
			// bare form below into that context.
			if ( ! Aura_Worker_Unbind::write_under_claim( $marker, $fence ) ) {
				return self::unbind_store_failed();
			}
			$done = Aura_Worker_Unbind::cleanup( isset( $doc['final'] ) && true === $doc['final'], $fence );
			return array(
				'unbound'          => true,
				'seq'              => (int) $doc['seq'],
				'cleanup_complete' => (bool) $done,
				// What is still OWED, by name (#434 Task 4, M9). Without it
				// `cleanup_complete: false` has two opposite meanings — a
				// credential that could not be proven revoked, or the
				// deliberate `! final` token retention — and Aura cannot tell
				// them apart from a bool, so it would retire the very
				// tombstone that names a live credential. Empty exactly when
				// nothing is owed.
				'leftovers'        => Aura_Worker_Unbind::leftovers(),
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
			// The authoritative token hash read above — which the wrong_site
			// check just proved equals the document's own `site`. A real record
			// carries the binding forward, so the next old-client document meets
			// the same refusal and the stale check keeps working.
			'token_hash'  => $ours,
			// This site's own id in the rules' vocabulary (2.12.0). Stored
			// RAW, exactly as the document carried it — '' when it carried
			// none. Never synthesised on read: `stored()` is the value the
			// compare-and-swap names, and a record that gains a field on the
			// way out could never be matched by the CAS that must heal it.
			'site_ref'    => $site_ref,
			'seq'         => (int) $doc['seq'],
			'issued_at'   => isset( $doc['issued_at'] ) ? (string) $doc['issued_at'] : '',
			'received_at' => time(),
			'rules'       => array_values( array_filter( $doc['rules'], 'is_array' ) ),
		);
		// Re-verify the claim immediately before writing (review round 1,
		// I1): swap()/insert_if_absent() are plain CAS statements with no
		// claim predicate, so without this an evicted handler — the activation
		// repair path (magic-link.php:845) evicts a live claim on purpose —
		// would still install its ruleset. That splits exactly what the
		// site-wide claim exists to keep together: Task 3's unbind marker
		// write IS claim-fenced, so an evicted handler would write the
		// ruleset and be refused the marker, leaving the site's enforced
		// state and Aura's record of it disagreeing (the docblock above).
		// Checked here — once per accept_under_claim() invocation — rather
		// than once in accept(): the CAS retry recurses back into
		// accept_under_claim() from the top, so this line runs again on
		// every re-decide, closing the window a single upfront check would
		// leave open across MAX_SWAP_ATTEMPTS retries. Still a residual,
		// unavoidable microseconds-wide window between this check and the
		// write it guards — the same one holds_site_claim()'s own docblock
		// names.
		if ( ! Aura_Worker_Magic_Link::holds_site_claim( $fence ) ) {
			return new WP_Error(
				'aura_site_busy',
				__( 'Another Aura operation holds this site; retry shortly.', 'digitizer-site-worker' ),
				array( 'status' => 503 )
			);
		}
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
			return self::accept_under_claim( $envelope, $attempt + 1, $fence );
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
	 * STEP 0 of Phase A (spec §2.3), extracted so the unkeyed bare-body form
	 * (Task 8) answers a retry exactly as the enveloped form does.
	 *
	 * Reads the marker uncached and decides on the TOKEN alone — no signature,
	 * no decoding, because an unbound site's gateway key may already be gone:
	 *  - marker set and the site's stored token hashes to the marker's `site`:
	 *    this request IS the departed binding, so it is a retry of the unbind.
	 *    Append the authenticating Application Password's UUID, re-run Phase B,
	 *    and answer 200 `unbound`.
	 *  - marker set and the token differs: some other binding is talking to an
	 *    unbound site. 403 `aura_site_unbound`, nothing touched.
	 *  - marker absent: null, and the caller carries on with its ordinary path.
	 *
	 * @internal Consumed by accept_under_claim() and by Task 8's bare body.
	 *
	 * @param string   $fence This request's site-claim fence.
	 * @param bool     $final Whether the request says `final: true` — only then
	 *                        may Phase B delete the site token.
	 * @param int|null $seq   The seq the REQUEST carries, echoed back verbatim;
	 *                        null to answer the marker's own seq. Aura requires
	 *                        the echoed seq to match the one it pushed, and a
	 *                        second legacy tombstone sharing this token carries
	 *                        a different clearSeq from the one that marked it.
	 * @return array|WP_Error|null The unbind answer, a refusal/failure, or null
	 *                             when there is no marker.
	 */
	public static function fast_path_or_refusal( $fence, $final, $seq ) {
		$marker = Aura_Worker_Unbind::read();
		if ( is_wp_error( $marker ) ) {
			return $marker; // aura_ruleset_store_failed, 500 — retryable, fails CLOSED
		}
		if ( null === $marker ) {
			return null;
		}
		$ours = self::site_token_uncached();
		if ( is_wp_error( $ours ) ) {
			return $ours;
		}
		$ours = (string) $ours;
		if ( '' === $ours || ! hash_equals( (string) $marker['site'], $ours ) ) {
			return Aura_Worker_Unbind::refusal();
		}
		// The marker must carry every credential that authenticated an unbind
		// BEFORE Phase B may delete the token: a failed — therefore unverified
		// — append means a password the core-REST seam would not recognise, so
		// nothing may proceed to cleanup. Retryable, nothing else touched.
		if ( ! self::append_authenticating_uuid( $marker, $fence ) ) {
			return self::unbind_store_failed();
		}
		$done = Aura_Worker_Unbind::cleanup( (bool) $final, $fence );
		return array(
			'unbound'          => true,
			'seq'              => null === $seq ? (int) $marker['seq'] : (int) $seq,
			'cleanup_complete' => (bool) $done,
			// See the build path above: the names of what is still owed, so a
			// false `cleanup_complete` can be told from a `! final` one (M9).
			'leftovers'        => Aura_Worker_Unbind::leftovers(),
		);
	}

	/**
	 * The Phase-A marker for a binding that is departing: who the site is, who
	 * it was bound to, and EVERY credential that can still authenticate as
	 * that binding — copied in BEFORE Phase B deletes the options that name
	 * them (the managed Application Password SiteAgent minted, and the one
	 * that authenticated THIS request: a manually connected site's, or one
	 * Aura's PATCH installed, was never minted here). The core-REST seam
	 * matches on the marker's identity, never on the live options.
	 *
	 * ONE builder for both Phase-A writers — the enveloped document and the
	 * unkeyed bare body (Task 8). The credential copy is the half that must
	 * never drift: a marker missing a uuid is an administrator-level REST
	 * credential the boundaries do not recognise and Phase B never revokes.
	 *
	 * Every field is written at the type Aura_Worker_Unbind::read() requires —
	 * strings for `at`/`site`/`site_ref`/`client`, an int for `seq` — because a
	 * field that is present and of the wrong type reads as MALFORMED, which
	 * refuses every mutation on the site with no way back (#434 Task 4). The
	 * signature enforces that for the two the bare body supplies.
	 *
	 * @since 2.13.0
	 *
	 * @param string $site     The site's own token hash, authoritative.
	 * @param string $site_ref This site's id in Aura's vocabulary, '' when unknown.
	 * @param string $client   The departing client.
	 * @param int    $seq      The unbind's seq.
	 * @return array|WP_Error The marker, or a refusal when the token this
	 *                        marker would name is not the one that
	 *                        authenticated the request.
	 */
	private static function new_marker( string $site, string $site_ref, string $client, int $seq ) {
		// THE MARKER NAMES THE TOKEN THAT AUTHENTICATED THIS REQUEST, and
		// refuses to name any other (Codex round-5 P1).
		//
		// `$site` is read from the option uncached under the site claim — but
		// the claim is taken AFTER authentication, and an administrator can
		// press Regenerate Token in between. A request that paused there would
		// otherwise mark the REPLACEMENT binding with the replacement's own
		// token, and on the unkeyed path a `final: true` body would then go on
		// to delete that fresh token: an unbind for a binding that had already
		// ended, executed against the one that replaced it.
		//
		// Absence is refused as firmly as a mismatch. Both Phase A paths run
		// under /aura/v2/rules, whose permission callback cannot be reached
		// without check_aura_token(), so "no token authenticated this request"
		// is not a state a real Phase A can be in — and treating it as
		// permission would be the fail-open this whole file is about.
		$authenticated = Aura_Worker_Security::authenticated_token_hash();
		if ( null === $authenticated || '' === $site || ! hash_equals( $site, $authenticated ) ) {
			return new WP_Error(
				'aura_site_token_changed',
				__( 'This site token changed while the request was in flight; retry the unbind.', 'digitizer-site-worker' ),
				array( 'status' => 409 )
			);
		}
		$marker = array(
			'at'                 => gmdate( 'c' ),
			'site'               => $site,
			'site_ref'           => $site_ref,
			'client'             => $client,
			'seq'                => $seq,
			'connect_user_id'    => (int) get_option( 'aura_worker_connect_user_id', 0 ),
			'app_password_uuids' => array(),
			'app_password_users' => array(),
		);
		$managed = get_option( Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION, null );
		if ( is_array( $managed ) && ! empty( $managed['uuid'] ) ) {
			$uuid                                  = (string) $managed['uuid'];
			$marker['app_password_uuids'][]        = $uuid;
			$marker['app_password_users'][ $uuid ] = self::marker_password_owner( $uuid, (int) ( $managed['user_id'] ?? 0 ), (int) $marker['connect_user_id'] );
		}
		$auth = Aura_Worker_Security::authenticating_app_password_uuid();
		if ( is_string( $auth ) && '' !== $auth && ! in_array( $auth, $marker['app_password_uuids'], true ) ) {
			$marker['app_password_uuids'][]        = $auth;
			// The user the SAME hook named beside this uuid — never
			// get_current_user_id(), which is a fact about the request and
			// not about the password (#434 Task 4, C4).
			$marker['app_password_users'][ $auth ] = self::marker_password_owner( $auth, (int) Aura_Worker_Security::authenticating_app_password_user(), (int) $marker['connect_user_id'] );
		}
		return $marker;
	}

	/** The longest `client` / `site_ref` a bare unbind body may store in the marker. */
	const MAX_BODY_FIELD = 191;

	/**
	 * Phase A for a site that holds NO gateway public key (#434 Task 8).
	 *
	 * A manually connected site was never given one, so no signed envelope can
	 * verify there and every push answers 412 `no_gateway_key` — which would
	 * strand its tombstone forever. Such a site's only binding IS its token,
	 * and the route's permission callback has already proved the caller holds
	 * it (`Aura_Worker_Security::check_aura_token()` refuses every request to
	 * /aura/v2/rules without it), so the token is sufficient authority for the
	 * one operation that ENDS that binding.
	 *
	 * Deliberately narrow:
	 *  - a site that CAN verify signatures refuses this form (400) — there the
	 *    envelope is the authority and a token-only unbind would be a downgrade;
	 *  - every field comes from an unsigned request body, so each is validated
	 *    and normalised before anything is written. A marker whose `client` or
	 *    `site_ref` is not a string reads back MALFORMED, and a malformed
	 *    marker refuses every mutation on the site with no way back short of
	 *    the operator's teardown panel (#434 Task 4). A caller must never be
	 *    able to write one.
	 *
	 * Runs under the same site-wide claim as accept(), for the same reason: an
	 * unbind and a ruleset push must never interleave.
	 *
	 * @since 2.13.0
	 *
	 * @param array $body The request's own values, RAW: `client`, `seq`,
	 *                    optional `site_ref` and `final`. Nothing here is
	 *                    trusted to be a string, an int, or present at all.
	 * @return array|WP_Error The same `{ unbound, seq, cleanup_complete,
	 *                        leftovers }` answer the enveloped form returns, or
	 *                        a refusal.
	 */
	public static function accept_bare_unbind( array $body ) {
		// Validated BEFORE the claim is taken: nothing about a malformed body
		// needs the site, and a request that could take the claim on its way to
		// a 400 could 503 a good one behind it.
		$fields = self::validated_unbind_body( $body );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$fence = Aura_Worker_Magic_Link::claim_site();
		if ( '' === $fence ) {
			return new WP_Error(
				'aura_site_busy',
				__( 'Another Aura operation holds this site; retry shortly.', 'digitizer-site-worker' ),
				array( 'status' => 503 )
			);
		}
		try {
			return self::bare_unbind_under_claim( $fields, $fence );
		} finally {
			Aura_Worker_Magic_Link::release_site( $fence );
		}
	}

	/**
	 * The bare unbind's body, validated and normalised into exactly the values
	 * the marker stores — or a refusal.
	 *
	 * Presence is asked with array_key_exists()/isset() and the TYPE is checked
	 * rather than cast: `(string) $body['client']` fatals on an object and
	 * answers 'Array' for an array, and either would write a marker nobody
	 * meant. A field that is PRESENT but of the wrong type is a refusal, never
	 * a silent default — the same rule Aura_Worker_Unbind::read() applies to a
	 * stored marker, applied one step earlier, at the only place a caller can
	 * still be told.
	 *
	 * @param array $body Raw request values.
	 * @return array|WP_Error `client`, `site_ref`, `seq`, `final`.
	 */
	private static function validated_unbind_body( array $body ) {
		$client = array_key_exists( 'client', $body ) ? $body['client'] : null;
		if ( ! is_string( $client ) ) {
			return self::bare_unbind_rejected( 'client is required and must be a string' );
		}
		$client = trim( $client );
		if ( '' === $client || strlen( $client ) > self::MAX_BODY_FIELD ) {
			return self::bare_unbind_rejected( sprintf( 'client must be 1-%d characters', self::MAX_BODY_FIELD ) );
		}
		// Absent or null is '' — an Aura that sends no `site_ref`, exactly as
		// the enveloped form reads a document without the field. PRESENT but
		// not a string is a refusal, NOT a silent '': the caller asked for
		// something this site cannot store, and answering it with a marker
		// that names a different site than the one requested would be worse
		// than saying no.
		$site_ref = ( array_key_exists( 'site_ref', $body ) && null !== $body['site_ref'] ) ? $body['site_ref'] : '';
		if ( ! is_string( $site_ref ) ) {
			return self::bare_unbind_rejected( 'site_ref must be a string' );
		}
		$site_ref = trim( $site_ref );
		if ( strlen( $site_ref ) > self::MAX_BODY_FIELD ) {
			return self::bare_unbind_rejected( sprintf( 'site_ref must be at most %d characters', self::MAX_BODY_FIELD ) );
		}
		$seq = self::body_seq( array_key_exists( 'seq', $body ) ? $body['seq'] : null );
		if ( null === $seq ) {
			return self::bare_unbind_rejected( 'seq must be a non-negative integer' );
		}
		return array(
			'client'   => $client,
			'site_ref' => $site_ref,
			'seq'      => $seq,
			// Read the way final_flag_of() reads a document: `final` only ever
			// WIDENS Phase B (it permits deleting the site token), so anything
			// that is not unambiguously true is false. A form-encoded body
			// carries it as a string.
			'final'    => array_key_exists( 'final', $body ) && in_array( $body['final'], array( true, 1, '1', 'true' ), true ),
		);
	}

	/**
	 * The seq a bare body carries, as a non-negative int — or null when it
	 * carries none this site is willing to store.
	 *
	 * A form-encoded body carries every value as a string, so a digit string is
	 * accepted, but only one that survives the round trip: a value past
	 * PHP_INT_MAX saturates on cast, and the seq is ECHOED back for Aura to
	 * match against the tombstone it pushed, so a seq that changed on the way
	 * in is no answer at all. `0` is a seq like any other and must never be
	 * read as absent.
	 *
	 * @param mixed $value The raw value.
	 * @return int|null
	 */
	private static function body_seq( $value ) {
		if ( is_int( $value ) ) {
			return $value >= 0 ? $value : null;
		}
		if ( is_string( $value ) && '' !== $value && ctype_digit( $value ) ) {
			$seq = (int) $value;
			return (string) $seq === $value ? $seq : null;
		}
		return null;
	}

	/**
	 * A bare unbind body this site will not act on. 400 and permanent: the same
	 * body will be refused for the same reason on every retry, so Aura must fix
	 * it rather than wait.
	 *
	 * @param string $why What was wrong.
	 * @return WP_Error
	 */
	private static function bare_unbind_rejected( $why ) {
		return new WP_Error( 'aura_ruleset_rejected', 'Unbind refused: ' . $why, array( 'status' => 400 ) );
	}

	/**
	 * The bare unbind's body, run under a site claim the caller already holds.
	 *
	 * @param array  $fields Validated body — client, site_ref, seq, final.
	 * @param string $fence  This request's site-claim fence.
	 * @return array|WP_Error
	 */
	private static function bare_unbind_under_claim( array $fields, $fence ) {
		// STEP 0 — the SAME fast path the enveloped form takes, and BEFORE the
		// keyed refusal below. Phase B deletes the gateway key at step (4) and
		// the site token at step (5), so an unbind interrupted after step (4)
		// leaves a site indistinguishable from a never-keyed one, and one
		// interrupted before it leaves a marked site that still holds its key
		// (#434 Task 4 review). Judging a RETRY by the key would strand the
		// first and refuse the second; the marker plus the token is the whole
		// authority here, exactly as it is for an envelope this site can no
		// longer verify.
		//
		// The CURRENT request's seq, never the marker's: two legacy tombstones
		// sharing one token carry different clearSeqs, and echoing the marker's
		// would fail Aura's seq check after the token is already deleted —
		// stranding the very tombstone this path exists to rescue.
		$fast = self::fast_path_or_refusal( $fence, $fields['final'], $fields['seq'] );
		if ( null !== $fast ) {
			return $fast;
		}

		// A site that can verify a signature has no business accepting an
		// unverified unbind: there the envelope IS the authority, and a
		// token-only form would be a downgrade — a stolen token could evict
		// Aura from a keyed site. The RAW option, not has_usable_key(): a key
		// that is present but corrupt still means "this site was keyed", and
		// the answer to a key it cannot use is to reconnect (the 412), never to
		// widen this path.
		$key = self::read_option_uncached( 'aura_worker_grant_pubkey' );
		if ( is_wp_error( $key ) ) {
			return $key; // fails CLOSED: a key that could not be read is not an absent one
		}
		// NOT trim()'d (round-1 LOW-1): a whitespace-only row is a value, and
		// Aura_Worker_Grant::is_enforced() calls such a site keyed. Trimming it
		// away here would have TWO parts of the plugin disagree about whether
		// the same site is keyed — and this side is the one that would admit a
		// token-only eviction.
		if ( null !== $key && '' !== (string) maybe_unserialize( $key ) ) {
			return self::bare_unbind_rejected( 'this site verifies signed documents; send the signed unbind envelope' );
		}

		$ours = self::site_token_uncached();
		if ( is_wp_error( $ours ) ) {
			return $ours;
		}
		$ours = (string) $ours;
		if ( '' === $ours ) {
			// Nothing to unbind, and — decisively — a marker whose `site` is ''
			// could never be matched by a retry: fast_path_or_refusal() answers
			// refusal() on an empty token, so the site would refuse every
			// mutation forever with no way back. The route's own permission
			// callback already refuses a tokenless request; this is the same
			// answer for a token deleted between that check and this one.
			return self::bare_unbind_rejected( 'this site holds no site token' );
		}

		// The bare form's counterpart to the enveloped path's client_mismatch
		// (round-1 LOW-3). Defence in depth, not authorisation: the token is
		// what proves this caller may unbind, and nothing downstream reads the
		// marker's `client` — so this exists to make an Aura bug that
		// mis-addresses a tombstone VISIBLE instead of silent.
		//
		// Conditional on the record, exactly as the enveloped check is
		// (`! $stale && null !== $current && isset( $current['client'] )`).
		// bound_client() is a report-only accessor: it already answers '' for a
		// record that is missing, unreadable or bound to some other token, and
		// each of those skips the check rather than refusing. That is the right
		// direction HERE and only here — an unbind must not become impossible
		// because the store this site no longer needs cannot be read, which is
		// the whole reason the marker path never depends on it.
		$bound = self::bound_client();
		if ( '' !== $bound && ! hash_equals( $bound, $fields['client'] ) ) {
			return new WP_Error(
				'aura_ruleset_client_mismatch',
				sprintf( 'Unbind refused: issued for client %s, this site is bound to %s', $fields['client'], $bound ),
				array( 'status' => 409 )
			);
		}

		$marker = self::new_marker( $ours, $fields['site_ref'], $fields['client'], $fields['seq'] );
		if ( is_wp_error( $marker ) ) {
			return $marker; // the token moved under this request; nothing is written
		}
		// write_under_claim()'s read-back proves only "the row now names my
		// site at my seq", which is sufficient here for the same reason it is
		// in the enveloped path and nowhere else: the fast path above returned
		// null, which it does ONLY for a marker read as genuinely ABSENT. There
		// is no prior row whose site+seq could match while this write's other
		// fields were lost.
		if ( ! Aura_Worker_Unbind::write_under_claim( $marker, $fence ) ) {
			return self::unbind_store_failed();
		}
		$done = Aura_Worker_Unbind::cleanup( $fields['final'], $fence );
		return array(
			'unbound'          => true,
			'seq'              => $fields['seq'],
			'cleanup_complete' => (bool) $done,
			// Identical in shape to the enveloped answer, `leftovers` included:
			// Aura branches on empty (only the shared token is outstanding) vs
			// non-empty (a credential this site could not prove revoked), and
			// an ABSENT field would read as "something may be owed" (Task 4 M9).
			'leftovers'        => Aura_Worker_Unbind::leftovers(),
		);
	}

	/**
	 * The owner Phase A records for one of the marker's Application Passwords:
	 * a user id this request actually KNOWS, or null — an explicit unknown.
	 * Never 0, which is not a user and was read as one for three review rounds
	 * (#434 Task 4, C1/C2/C3).
	 *
	 * Phase B does exactly one lookup, against the owner recorded here, and an
	 * Application Password lives in exactly one user's meta — so that lookup is
	 * decisive only if what is written here is knowledge rather than a guess.
	 * Resolution therefore belongs at WRITE time, where the request has the
	 * facts:
	 *
	 *   - `$claimed` — the user WordPress named beside the captured uuid on
	 *     `application_password_did_authenticate`
	 *     (`Aura_Worker_Security::authenticating_app_password_user()`), or the
	 *     `user_id` the managed record wrote beside its own uuid. Either is a
	 *     statement by the writer about that exact password: authoritative.
	 *     `get_current_user_id()` is NOT one of them — it is a fact about the
	 *     request, and a `determine_current_user` filter or any
	 *     `wp_set_current_user()` desynchronises the two (#434 Task 4, C4).
	 *     Never re-derive what you were told.
	 *   - the connecting user — a CANDIDATE, not a statement. Recorded only
	 *     once this request has CONFIRMED the password really is in that user's
	 *     list; an unconfirmed guess would be read as authoritative later, and
	 *     a lookup against a wrong owner answering "not there" is precisely how
	 *     round 2 deleted the site token beside a live administrator credential.
	 *   - otherwise null. Phase B will not attempt a proof it cannot make: the
	 *     teardown stops short of the token and waits for the operator.
	 *
	 * @since 2.13.0
	 *
	 * @param string $uuid            The password's uuid.
	 * @param int    $claimed         The owner the writer names, if any.
	 * @param int    $connect_user_id The connecting user, as a candidate.
	 * @return int|null
	 */
	private static function marker_password_owner( $uuid, $claimed, $connect_user_id ) {
		if ( $claimed > 0 ) {
			return (int) $claimed;
		}
		// PROVEN present, never merely "not proven gone" (#434 Task 4, I5):
		// password_state() answers 'unknown' for a user-meta read it could not
		// complete, and an unreadable list is not a confirmation. Recording a
		// candidate on a failed read would write a guess where Phase B reads
		// knowledge.
		if ( $connect_user_id > 0 && Aura_Worker_Magic_Link::STATE_PRESENT === Aura_Worker_Magic_Link::password_state( $connect_user_id, (string) $uuid ) ) {
			return (int) $connect_user_id;
		}
		return null;
	}

	/**
	 * Add the Application Password that authenticated THIS request to the
	 * marker, if it is not already there. The fast path calls this on every
	 * visit (spec §2.3): two legacy rows sharing one token leave two
	 * tombstones, each authenticating with its own password, and the token
	 * outlives both unbinds precisely so the second can be recorded here.
	 *
	 * Verified by an uncached re-read of the marker rather than by
	 * write_under_claim()'s own read-back: that read-back proves "the row names
	 * my site at my seq", which an append does not change — so it would pass
	 * whether or not the UUID landed.
	 *
	 * @param array  $marker The marker, replaced in place by the stored copy on
	 *                       a successful append.
	 * @param string $fence  This request's site-claim fence.
	 * @return bool False only when an append was needed and did not verify.
	 */
	private static function append_authenticating_uuid( array &$marker, $fence ) {
		$auth = Aura_Worker_Security::authenticating_app_password_uuid();
		if ( ! is_string( $auth ) || '' === $auth ) {
			return true; // a token-only request carries no password to record
		}
		// The list this append EXTENDS is read through the one rule that reads
		// it. `$marker` arrives from Aura_Worker_Unbind::read(), so today the
		// field is always a clean list — and "already validated upstream" is
		// exactly the assumption that turns into the next round's finding. A
		// list that cannot be read is not one to append to: fail closed, and
		// the caller answers a retryable 500 with nothing written.
		$uuids = aura_worker_credential_list( $marker['app_password_uuids'] ?? null );
		if ( null === $uuids ) {
			return false;
		}
		if ( in_array( $auth, $uuids, true ) ) {
			return true;
		}
		$uuids[] = $auth;
		$users   = isset( $marker['app_password_users'] ) && is_array( $marker['app_password_users'] )
			? $marker['app_password_users']
			: array();
		// The hook's own pairing, not the request's current user (#434 C4).
		$users[ $auth ] = self::marker_password_owner( $auth, (int) Aura_Worker_Security::authenticating_app_password_user(), (int) ( $marker['connect_user_id'] ?? 0 ) );

		$updated                       = $marker;
		$updated['app_password_uuids'] = $uuids;
		$updated['app_password_users'] = $users;
		if ( ! Aura_Worker_Unbind::write_under_claim( $updated, (string) $fence ) ) {
			return false;
		}
		$back = Aura_Worker_Unbind::read();
		if ( ! is_array( $back ) || ! in_array( $auth, $back['app_password_uuids'], true ) ) {
			return false;
		}
		$marker = $back;
		return true;
	}

	/**
	 * The one refusal Phase A answers when the marker could not be recorded.
	 * Retryable: Aura's tombstone stays pending and the site is untouched.
	 *
	 * @return WP_Error
	 */
	private static function unbind_store_failed() {
		return new WP_Error(
			'aura_unbind_store_failed',
			__( 'Could not record the disconnect; retry.', 'digitizer-site-worker' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Does this document say `final: true`? Read LENIENTLY — the fast path has
	 * no key to verify with, and the token is the authority there. Anything
	 * that is not exactly `true` is false, garbage included: `final` only ever
	 * widens Phase B (it permits deleting the token), so the safe reading of an
	 * unreadable document is "not final".
	 *
	 * @param string $envelope Signed document, or anything at all.
	 * @return bool
	 */
	private static function final_flag_of( $envelope ) {
		$doc = Aura_Worker_Grant::peek_payload( $envelope );
		return isset( $doc['final'] ) && true === $doc['final'];
	}

	/**
	 * The seq this document carries, read leniently, or $fallback when it
	 * carries no usable one. Same lenient reading as final_flag_of(): the fast
	 * path echoes the seq back so Aura can match it to the push it sent, and a
	 * document it cannot read has no seq to echo.
	 *
	 * @param string   $envelope Signed document, or anything at all.
	 * @param int|null $fallback What to answer when there is no valid seq.
	 * @return int|null
	 */
	private static function seq_of( $envelope, $fallback ) {
		$doc = Aura_Worker_Grant::peek_payload( $envelope );
		if ( isset( $doc['seq'] ) && is_int( $doc['seq'] ) && $doc['seq'] >= 0 ) {
			return $doc['seq'];
		}
		return $fallback;
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
	 * @return int|false Rows written: 1 when this caller changed or created the
	 *                   row, 0 when it did not own the claim (or the value was
	 *                   already exactly this), FALSE when a statement failed.
	 *                   A caller that needs the write as an ownership proof
	 *                   reads it (round-28).
	 */
	public static function write_option_if_claimed( $option, $value, $claim, $fence, $autoload = 'yes' ) {
		global $wpdb;
		if ( '' === (string) $fence || '' === (string) $claim ) {
			return 0;
		}
		$like = $wpdb->esc_like( $fence . '|' ) . '%';
		$raw  = maybe_serialize( $value );
		$wpdb->last_error = '';
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} o JOIN {$wpdb->options} c ON c.option_name = %s AND c.option_value LIKE %s SET o.option_value = %s WHERE o.option_name = %s",
				$claim,
				$like,
				$raw,
				$option
			)
		);
		if ( false === $updated || '' !== (string) $wpdb->last_error ) {
			self::forget_option_cache( $option );
			return false;
		}
		$inserted = $wpdb->query(
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
		if ( false === $inserted || '' !== (string) $wpdb->last_error ) {
			return false;
		}
		return (int) $updated + (int) $inserted;
	}

	/**
	 * Delete an option ONLY while the caller still holds a named claim — the
	 * same reasoning as write_option_if_claimed(), for the install steps that
	 * remove a value rather than set one.
	 *
	 * @param string $option Option name.
	 * @param string $claim  The claim option's name.
	 * @param string $fence  The caller's fence.
	 * @return int|false Rows removed: 1 when this caller took the row, 0 when
	 *                   it did not own the claim (or the row was already gone),
	 *                   and FALSE when the statement itself failed. A database
	 *                   error read as "0 rows" would be read as "not mine" and
	 *                   let the caller carry on as though nothing were owed
	 *                   (round-18), so the two are kept apart.
	 */
	public static function delete_option_if_claimed( $option, $claim, $fence ) {
		global $wpdb;
		if ( '' === (string) $fence || '' === (string) $claim ) {
			return 0;
		}
		$wpdb->last_error = '';
		$rows = $wpdb->query(
			$wpdb->prepare(
				"DELETE o FROM {$wpdb->options} o JOIN {$wpdb->options} c ON c.option_name = %s AND c.option_value LIKE %s WHERE o.option_name = %s",
				$claim,
				$wpdb->esc_like( $fence . '|' ) . '%',
				$option
			)
		);
		self::forget_option_cache( $option );
		if ( false === $rows || '' !== (string) $wpdb->last_error ) {
			return false;
		}
		return (int) $rows;
	}

	/**
	 * delete_option_if_claimed()'s PREFIX sibling: every row whose name matches
	 * `$like`, deleted only while the site claim still carries `$fence`.
	 *
	 * The Elementor door's state is not a fixed set of option names — one row
	 * per hold, one per claim, one per log seq — so an unbind cannot enumerate
	 * it into per-name deletes without issuing thousands of statements. The
	 * fence is identical: the same JOIN on the claim row, so a caller that
	 * lost the site mid-cleanup deletes nothing at all.
	 *
	 * `$like` is passed through verbatim (the caller builds it with
	 * `$wpdb->esc_like()`), and the option cache is not consulted — these
	 * statements go round it, so callers evict what they must.
	 *
	 * @param string $like  The LIKE pattern for option_name.
	 * @param string $claim The site-claim option name.
	 * @param string $fence This caller's claim fence.
	 * @return int|false Rows deleted, or false on a database error.
	 */
	public static function delete_options_like_if_claimed( $like, $claim, $fence ) {
		global $wpdb;
		if ( '' === (string) $fence || '' === (string) $claim || '' === (string) $like ) {
			return 0;
		}
		$wpdb->last_error = '';
		$rows             = $wpdb->query(
			$wpdb->prepare(
				"DELETE o FROM {$wpdb->options} o JOIN {$wpdb->options} c ON c.option_name = %s AND c.option_value LIKE %s WHERE o.option_name LIKE %s",
				$claim,
				$wpdb->esc_like( $fence . '|' ) . '%',
				$like
			)
		);
		if ( false === $rows || '' !== (string) $wpdb->last_error ) {
			return false;
		}
		return (int) $rows;
	}

	/**
	 * One raw options-table read for callers outside this class — the row, not
	 * the option cache. Used to VERIFY a claim-conditional write landed
	 * (round-18): those statements go round the cache, and a write that failed
	 * is indistinguishable from one that changed nothing without reading back.
	 *
	 * @param string $name Option name.
	 * @return string|null|WP_Error Raw value, null when absent, WP_Error on a database error.
	 */
	public static function read_option_uncached( $name ) {
		return self::option_raw( $name );
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
	 * Rebuild the stored record from its OWN verified envelope — offline.
	 *
	 * The upgrade window this exists for: a record written by <= 2.11 kept the
	 * rules but not `site_ref`, and Aura does not push again for a document it
	 * has already confirmed. Without a repair such a site would enforce every
	 * scoped rule (fail-closed, so safe) until its client's next release — which
	 * may be never.
	 *
	 * The record stores the accepted envelope VERBATIM, so the repair needs no
	 * network: re-verify those bytes with the site's own stored public key and
	 * read the identity out of the document that was already accepted. Bytes
	 * that no longer verify — a tampered row, a rotated key — are never a source
	 * of truth, and the record is left exactly as it is.
	 *
	 * The write goes through the same compare-and-swap as `accept()`, and LOSING
	 * it is success: whatever won is a newer record, written by an `accept()`
	 * that stores `site_ref` itself. Idempotent — a complete record answers true
	 * without writing.
	 *
	 * @since 2.12.0
	 *
	 * @return bool The record now carries the identity this version knows how to use.
	 */
	public static function backfill_from_stored_envelope() {
		$current = self::stored();
		if ( null === $current || ! isset( $current['envelope'] ) || '' === (string) $current['envelope'] ) {
			// No record, or the connect's sentinel, which carries no envelope
			// and no rules: nothing to repair, and nothing broken.
			return true;
		}
		$doc = Aura_Worker_Grant::verify_signed_document( (string) $current['envelope'] );
		if ( ! is_array( $doc ) ) {
			// Never rebuild from unverified bytes. Answering false keeps the
			// version marker behind so a later request — after a key is
			// re-provisioned, say — tries again.
			return false;
		}
		$site_ref = isset( $doc['site_ref'] ) && is_string( $doc['site_ref'] ) ? trim( $doc['site_ref'] ) : '';
		if ( isset( $current['site_ref'] ) && $current['site_ref'] === $site_ref ) {
			return true; // already complete
		}
		// #434 review round 1 (I3): this write raced Aura_Worker_Rules::accept()
		// at the option layer — a genuine, unclaimed writer of the SAME store
		// every accept() now serialises through the site-wide claim. Closing
		// that here costs little: this function already treats a lost write
		// as success (below), so a refused claim is simply this function's
		// existing "retry next request" contract (plugins_loaded calls it on
		// every load until the version marker advances) — busy just means
		// "not this request."
		$fence = Aura_Worker_Magic_Link::claim_site();
		if ( '' === $fence ) {
			return false;
		}
		try {
			$record             = $current;
			$record['site_ref'] = $site_ref;
			$swapped            = self::swap( $current, $record );
			if ( true === $swapped ) {
				return true;
			}
			// A lost swap or a refused write: decide on the RECORD, not on the
			// return value. A racer's newer record already carries the field, and
			// that is the outcome this function is asked about.
			$after = self::stored();
			return is_array( $after ) && isset( $after['site_ref'] );
		} finally {
			Aura_Worker_Magic_Link::release_site( $fence );
		}
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
		$now      = null === $now ? time() : (int) $now;
		$site_ref = self::site_ref();
		$out      = array();
		foreach ( self::rules() as $rule ) {
			if ( ! is_array( $rule ) || ! self::is_expired( $rule, $now ) || ! isset( $rule['key'] ) ) {
				continue;
			}
			// A rule scoped to OTHER sites is not this site's protection, so
			// its expiry is not this site's news (round-2 P2). Announcing it
			// here — and reporting it in `audit_rules` as this site's
			// `expired_active` — raises a fleet alert about a rule that never
			// applied here, and the operator is sent to repair the wrong site.
			// The same predicate enforcement uses: an unknown identity still
			// reports everything, exactly as it still enforces everything.
			if ( ! self::rule_reaches_here( $rule, $site_ref ) ) {
				continue;
			}
			$out[] = (string) $rule['key'];
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
	 * @return array {effect: null|'warn'|'block', rule?: array, recorded?: bool} `allow` is never returned here — it reads as no match.
	 */
	/**
	 * The winner that ENFORCEMENT would apply on the tools path: match()'s
	 * winner, with an `allow` winner treated as no match at all.
	 *
	 * The one accessor for that question, because two callers ask it — the
	 * enforcing path below, and Aura_Worker_Tools::preview_tool(), which
	 * shows the operator what approving this call would do. A preview that
	 * asked match() directly reported `effect: 'allow'` for a call the very
	 * next request runs with no rule at all: the two disagreeing about the
	 * same call, which is exactly what site_ref() was threaded through
	 * match() to prevent.
	 *
	 * `allow` is not "permitted, on the record" here. The tools path already
	 * defaults to the approval queue, so an allow rule changes nothing it
	 * does; the effect speaks only at the Elementor door, which asks match()
	 * for itself (class-elementor-door-governor.php).
	 *
	 * @param array    $touches  What the call declares it touches.
	 * @param array    $rules    The ruleset to judge against.
	 * @param int|null $now      Unix time; injected for tests.
	 * @param string   $site_ref This site's identity.
	 * @return array|null The rule enforcement would apply, or null.
	 */
	public static function enforceable_match( array $touches, array $rules, $now = null, $site_ref = '' ) {
		$rule = self::match( $touches, $rules, $now, $site_ref );
		if ( null !== $rule && 'allow' === ( isset( $rule['effect'] ) ? $rule['effect'] : '' ) ) {
			return null;
		}
		return $rule;
	}

	public static function enforce( array $touches, $tool_name, $now = null ) {
		self::note_expired( $now );
		// Judged in THIS site's identity (self::site_ref(), the one accessor —
		// the preview path asks the same question of the same record, so the
		// two can never disagree). The fork inherits this through enforce(),
		// so its governance wrapper needs no change of its own.
		$rule = self::enforceable_match( $touches, self::rules(), $now, self::site_ref() );
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
	 * Is this request being made AS the binding Aura unbound (#434 Task 6)?
	 *
	 * This is the other door. Task 5 closed SiteAgent's own REST routes and
	 * Aura_Worker_Grant::verify(), so an unbound site refuses every `aura/*`
	 * mutation. WordPress core's REST API — /wp/v2/*, /wc/v3/*, anything a
	 * plugin registers — is reached with the SAME credentials and never passes
	 * through any of those seams, so without this a site that refuses
	 * `aura/v2/snapshot` still accepts `POST /wp/v2/posts` from the
	 * Application Password Aura just unbound.
	 *
	 * IDENTITY COMES FROM THE MARKER, NEVER FROM A LIVE OPTION. By the time
	 * this matters, Phase B has already deleted `aura_worker_connect_user_id`
	 * and the managed Application Password record; the credentials outlive
	 * them, because the site token is deleted LAST. The marker is the only
	 * record of which credentials the departed binding held, which is exactly
	 * why Phase A copies them into it before Phase B removes anything (Task 3).
	 * Any version of this predicate that consults a live option answers
	 * "not the departed binding" for every request that arrives after the
	 * cleanup it exists to survive.
	 *
	 * Three answers, and the reasoning for each:
	 *
	 * 1. The marker cannot be READ — a database failure, or a marker that
	 *    exists and is malformed (Aura_Worker_Unbind::read() answers WP_Error
	 *    for both). TRUE. This is a refusal boundary and an unreadable marker
	 *    is not a clean site; more precisely, it is not evidence that THIS
	 *    request is innocent, and absence of proof of guilt is not proof of
	 *    innocence — the mistake this project has made six times. The cost is
	 *    stated rather than hidden: while a marker is unreadable, EVERY agent
	 *    write through core REST is refused, including credentials that have
	 *    nothing to do with Aura. That site is already refusing every SiteAgent
	 *    mutation for the same reason (refuse_if_unbound() uses is_set(), which
	 *    reads an unreadable marker as set), it is already disconnected, and
	 *    Task 9's removal panel is the operator's way out. A human at the
	 *    keyboard is unaffected either way: every caller of this predicate sits
	 *    behind is_agent_rest_request(), which stands aside for a cookie
	 *    session.
	 * 2. No marker at all. FALSE — the site is bound; nothing here applies.
	 * 3. A marker. TRUE when this request presents one of the credentials the
	 *    marker names:
	 *    - the Application Password whose UUID the marker recorded. The UUID
	 *      alone, never paired with the owner the marker also stored: a UUID
	 *      identifies exactly one password, while `app_password_users` holds
	 *      null for an owner the site could not determine, and requiring a
	 *      match against an unknown would read that unknown as innocence.
	 *    - the token run-as path (Aura_Worker_Security::ran_as_token()), on its
	 *      own. The PATH, not the user id it resolved to: comparing against the
	 *      marker's `connect_user_id` would be strictly WEAKER and wrong,
	 *      because once Phase B has deleted `aura_worker_connect_user_id`,
	 *      resolve_connect_user() falls back to the FIRST administrator — so
	 *      the ids routinely differ on exactly the requests this predicate
	 *      exists to catch.
	 *
	 *      THE TOKEN HASH USED TO NARROW THIS CLAUSE, AND #434 TASK 7 REMOVED
	 *      IT — deliberately, which is what the tripwire in UnbindCoreRestTest
	 *      exists to force. Round-1 MAJOR-1 added the comparison because
	 *      nothing in the plugin cleared the marker on a rebind: a re-connected
	 *      site stayed marked forever, so its NEW binding's token-only requests
	 *      had to be told apart from the departed one's by hash. Task 7 removes
	 *      the premise instead — the connect callback and "Regenerate Token"
	 *      now clear the marker as the last step of a rebind that succeeded end
	 *      to end — and with the premise gone the comparison inverts from a
	 *      narrowing into a HOLE. What "marker present, token differs" means
	 *      today is a rebind that installed the replacement token and then
	 *      failed (the binding write, the gateway key, the mint, the connect
	 *      user), which the two-call bracket deliberately leaves refusing. A
	 *      hash comparison here would wave exactly that half-installed token
	 *      through core REST — re-opening, by another route, the very hole the
	 *      bracket's ordering closes.
	 *
	 *      It also puts this boundary back in step with the other one:
	 *      Aura_Worker_Security::refuse_if_unbound() gates SiteAgent's own
	 *      routes on is_set() alone and has always refused the half-installed
	 *      token. Two seams that must agree about one question now answer it
	 *      the same way.
	 *
	 *      Nothing here reads the site token any more, so its unreadability is
	 *      no longer a case to reason about — a marked site refuses the run-as
	 *      whatever the token says.
	 *
	 * @since 2.13.0
	 *
	 * @return bool
	 */
	public static function departed_binding_request(): bool {
		$marker = Aura_Worker_Unbind::read();
		if ( is_wp_error( $marker ) ) {
			return true; // Unreadable is not innocent.
		}
		if ( null === $marker ) {
			return false;
		}
		$uuid = Aura_Worker_Security::authenticating_app_password_uuid();
		if ( null !== $uuid && '' !== $uuid && in_array( $uuid, $marker['app_password_uuids'], true ) ) {
			return true;
		}
		// The marker stands, therefore no rebind has been PROVEN complete on
		// this site — so a token run-as is the departed binding's, or a failed
		// rebind's half-installed replacement. Neither may write. See the
		// docblock for why the token hash no longer narrows this.
		return null !== Aura_Worker_Security::ran_as_token();
	}

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
		// An unbound site refuses every mutation made as the departed binding,
		// whatever the route and whether or not any rule matches (#434 Task 6).
		// BEFORE the branches below, all three of which return early for
		// routes they judge no further — the refusal is categorical, not a
		// verdict about a rule. The ruleset route is the one exemption, for the
		// reason Aura_Worker_Unbind::is_rules_route() states.
		if ( ! Aura_Worker_Unbind::is_rules_route( $route ) && self::departed_binding_request() ) {
			return Aura_Worker_Unbind::refusal();
		}
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
		// #434 Task 6 — an unbound site refuses the departed binding's writes
		// at core's own insert filter too. A WP_Error here is what core's post
		// controller already expects from this filter, so the write stops and
		// the caller is told why.
		if ( self::departed_binding_request() ) {
			return Aura_Worker_Unbind::refusal();
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
		// #434 Task 6 — the departed binding may not delete anything, of any
		// post type. BEFORE the CORE_TYPES filter below: that list is the RULE
		// vocabulary's ('post', 'page'), and an unbind is not a rule — a
		// product, an order or any custom type is just as much a mutation.
		//
		// `false`, never a WP_Error, for the reason spelled out at the block
		// below: this value becomes wp_delete_post()'s / wp_trash_post()'s
		// return, whose contract is a post object or false, and a WP_Error is
		// truthy — every caller would read the refusal as a success.
		if ( self::departed_binding_request() ) {
			return false;
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
