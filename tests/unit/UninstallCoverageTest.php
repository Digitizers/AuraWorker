<?php
/**
 * #434 Task 10 — uninstall.php removes every key the plugin stores, and the SET
 * of those keys is COMPUTED FROM SOURCE rather than remembered.
 *
 * The bug this exists for: `aura_worker_unbound` (the unbind marker, 2.13.0)
 * was not in uninstall.php's by-name list. Uninstall a disconnected site,
 * reinstall it, and activation mints a fresh token onto a site whose stale
 * marker still refuses every authenticated mutation — a site that looks new and
 * answers nothing, with nothing left on disk to explain it. It was not the only
 * miss: the ruleset, the gateway key, the connect user, both rule counters and
 * both throttle transients were never removed either, and four whole families
 * of runtime-named rows (grant nonces, expiry notices, token throttles, connect
 * transients) could not have been removed by any by-name list at all.
 *
 * The RULE, not the line. Adding six delete_option() calls fixes today and
 * guarantees a seventh miss, because the failing thing was never a particular
 * key — it was a hand-maintained enumeration sitting in the one file whose
 * entire job is to enumerate. uninstall.php now sweeps BY NAMESPACE PREFIX, and
 * this class reads the plugin's sources, works out what it stores, and fails BY
 * NAME when a key lands outside the swept namespaces.
 *
 * Three scans, because no one of them is complete on its own and each covers
 * the next one's blind spot:
 *
 *   (1) storage-function writes — every literal or class-constant key handed to
 *       update_option()/add_option()/set_transient()/…, resolved by reflection;
 *   (2) unresolved (runtime-named) writes — the same call sites whose key is
 *       computed, path-keyed and acknowledged one by one, so a NEW dynamic
 *       family cannot be added silently;
 *   (3) raw options-table writes — statements that bypass the storage API
 *       entirely and are therefore invisible to (1) and (2). The rule counters
 *       are written this way.
 *
 * WHERE THE RECURSION STOPS, and why that is defensible. Scan (1) is driven by
 * a hand-written list of WordPress storage-function names, and nothing computes
 * THAT list. It does not need to: it is WordPress's own public storage API,
 * fixed and versioned upstream, not something this plugin can extend. And the
 * one way around it — writing a row without calling any of them — is exactly
 * what scan (3) enumerates, from the table name rather than the function name.
 * So the residual hole is a key that is BOTH written through some further
 * wrapper this plugin invents AND never touches {$wpdb->options}: a row in
 * another table, which no uninstall sweep over the options table was ever going
 * to reach, and which would need its own guard. That is stated in the failure
 * messages rather than papered over.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class UninstallCoverageTest extends TestCase {

	/**
	 * Storage WRITES. Reads are deliberately absent: the plugin reads plenty of
	 * keys it does not own (`active_plugins`, `db_version`, `elementor_version`)
	 * and uninstall must not touch those. A row exists because something WROTE
	 * it, so the writes are the whole of what an uninstall owes.
	 */
	private const WRITE_FUNCTIONS = array(
		'add_option',
		'update_option',
		'add_site_option',
		'update_site_option',
		'set_transient',
		'set_site_transient',
	);

	/**
	 * Every call site whose key is computed at runtime, as `path => count`, with
	 * the prefix it produces named here and swept by uninstall.php.
	 *
	 * Path-keyed and counted, exactly as the REST-registrar sweep is (#434 Task
	 * 5): a basename key would let two files collapse into one, and a bare
	 * presence check would let a SECOND dynamic write appear in an
	 * already-listed file without anyone deciding anything about it.
	 *
	 * - includes/boot-beacon.php — update_option( aura_worker_fatal_record_key() )
	 *   is the fatal beacon, one record PER BUILD VERSION so two builds dying in
	 *   the same window cannot overwrite each other (#78, Codex round-12):
	 *   'aura_worker_boot_fatal_' . <version>. Swept by the 'aura_worker_'
	 *   prefix in uninstall.php.
	 * - includes/class-aura-worker-grant.php — add_option( $key ) reserving a
	 *   single-use grant nonce: NONCE_PREFIX . hash → 'aura_grant_nonce_'.
	 * - includes/class-aura-worker-rules.php — two add_option() claims, the
	 *   daily sweep's own and one per expired rule, both built by
	 *   expired_claim(): EXPIRED_NOTICE . day . '_' . hash →
	 *   'aura_worker_rule_expired_'.
	 * - includes/class-aura-worker-security.php — set_transient( $key ) counting
	 *   failed token attempts per IP: 'aura_worker_tokfail_' . md5( ip ).
	 * - includes/class-aura-worker-snapshots.php — update_option( $target ) is
	 *   the ONE dynamic write that is not the plugin's own storage: it RESTORES
	 *   a site option a snapshot captured, so the key belongs to WordPress or to
	 *   another plugin and uninstall must never remove it.
	 */
	private const ACKNOWLEDGED_DYNAMIC_WRITES = array(
		'includes/boot-beacon.php'                 => 1,
		'includes/class-aura-worker-grant.php'     => 1,
		'includes/class-aura-worker-rules.php'     => 2,
		'includes/class-aura-worker-security.php'  => 1,
		'includes/class-aura-worker-snapshots.php' => 1,
	);

	/**
	 * Every file with raw INSERT/UPDATE statements against `{$wpdb->options}`,
	 * as `path => count`. These write rows without calling a storage function
	 * at all, so the scans above are blind to them by construction.
	 *
	 * - includes/class-aura-worker.php (2) — the site token, written
	 *   conditionally on the site claim: 'aura_worker_site_token'.
	 * - includes/class-aura-worker-magic-link.php (3) — the site claim itself:
	 *   'aura_worker_connect_lock'. Taken, seized when stale, and — since
	 *   #434 Codex round-8 — refreshed while a long connect is still working,
	 *   all three as compare-and-swaps on that one key.
	 * - includes/class-aura-worker-rules.php (5) — the hourly rule counters
	 *   ('aura_worker_rules_blocked_h<hour>' / '…warned_h<hour>', an
	 *   INSERT … ON DUPLICATE KEY UPDATE that no scan of function calls could
	 *   ever see) and the ruleset store's compare-and-swap on
	 *   'aura_worker_ruleset'.
	 * - includes/class-aura-worker-door-log.php (9, 2.16.0) — each door log
	 *   row's compare-and-set (write_option_where()'s UPDATE, on
	 *   'aura_worker_door_log_<seq>'), the ack floor's upward-only raise
	 *   (ack()'s UPDATE on 'aura_worker_door_log_acked'), the closure
	 *   refusal counter's atomic increment (bump_refused()'s
	 *   INSERT … ON DUPLICATE KEY UPDATE on 'aura_worker_door_log_full_refused'),
	 *   and insert_unique()'s real conditional INSERT (`INSERT ... WHERE NOT
	 *   EXISTS`, shared by seq allocation, the epoch and the closure marker —
	 *   one call site in source, counted once regardless of how many option
	 *   names it is called with at runtime), and — since Ruling P59 — the
	 *   binding generation's compare-and-swap (rotate_binding()'s UPDATE on
	 *   'aura_worker_door_binding', which must change exactly one row so a
	 *   transient failure cannot read as a rotation that happened), and — since
	 *   Ruling P68 — that same rotation's two CLAIM-CONDITIONAL forms on the
	 *   same 'aura_worker_door_binding' key: an UPDATE and an INSERT, each
	 *   joined to the site claim row ('aura_worker_connect_lock'), so a stale
	 *   unbind whose claim was taken over cannot rotate the winner's binding,
	 *   and — since Ruling P73 — the ADOPTION of an `unset` record on that same
	 *   'aura_worker_door_binding' key: a compare-and-swap that states the
	 *   identity the site is already live under WITHOUT moving the generation,
	 *   so an upgraded site's own rows stay current while a replacement
	 *   connect's identity writes make the departed ones foreign at once, and —
	 *   since Ruling P91 — the EPOCH WITNESS re-stamp on that same key, which a
	 *   grant-gated `/door/rotate` makes so a legitimate cursor rotation is not
	 *   mistaken for a half-done rebind. (Ruling P90 added no statement: it
	 *   JOINED the ack's existing two — the floor raise on
	 *   'aura_worker_door_log_acked' and the row purge on
	 *   'aura_worker_door_log_<seq>' — to 'aura_worker_door_epoch', so neither
	 *   can cross a rotation.)
	 *   All names fall under the swept 'aura_worker_' prefix. And — since
	 *   2.16.2, Ruling A65 — the site-issued observation witness's own atomic
	 *   increment (bump_door_version()'s INSERT … ON DUPLICATE KEY UPDATE on
	 *   'aura_worker_door_observation'), the same upsert shape bump_refused()
	 *   above already uses. Also under the swept 'aura_worker_' prefix. And —
	 *   since Ruling S82, Codex round-33 P2 on #88 — restamp_observation_forward()'s
	 *   OWN INSERT … ON DUPLICATE KEY UPDATE on that SAME
	 *   'aura_worker_door_observation' key: a clock-and-witness-floored
	 *   restamp Aura's own `door_observation_seen` (see
	 *   Aura_Worker_Elementor_Door::maybe_restamp_observation_forward()'s
	 *   own docblock) forces past a value this site's copy has fallen
	 *   behind after a whole-DB restore. Same key as bump_door_version()'s
	 *   own write above, already under the swept 'aura_worker_' prefix. And —
	 *   since Ruling S30, Codex round-13 P1 on #88, superseded by Ruling S32,
	 *   Codex round-14 P1 on #88 — versioned()'s DURABLE commit-witness
	 *   write, a PLAIN INSERT (no ON DUPLICATE KEY UPDATE — a real second
	 *   INSERT of the same name would collide) on
	 *   'aura_worker_door_tx_<nonce>' inside every mutating transaction,
	 *   before the version bump: a plain option row survives a reconnect
	 *   that a MySQL session variable does not, so it stands in for the
	 *   session nonce (Ruling S16) when that nonce cannot be read back
	 *   after COMMIT. Named BY the transaction's own nonce (S30's row was
	 *   ONE shared key every unit overwrote, so a second unit's commit
	 *   could land on it between this unit's write and its own read-back)
	 *   so two concurrent units' witness rows can never collide. The unit
	 *   deletes its own row once the check is settled, and a bounded
	 *   janitor DELETE (LIKE 'aura_worker_door_tx_%' AND older than
	 *   LAST_TX_MAX_AGE_S, at most LAST_TX_JANITOR_LIMIT rows) sweeps up
	 *   whatever a died process left behind — the DELETEs are not counted
	 *   here (the ledger only tracks INSERT/UPDATE call sites), but both
	 *   fall under the swept 'aura_worker_' prefix same as the INSERT.
	 * - includes/class-elementor-door-governor.php (3, 2.16.0) — the door's
	 *   rolling 30-day counters, in the rule counters' shape:
	 *   'aura_worker_door_c_<name>_h<hour>', an atomic
	 *   INSERT … ON DUPLICATE KEY UPDATE no scan of function calls could see.
	 *   Under the swept 'aura_worker_' prefix. And — since Ruling S26,
	 *   Codex round-11 P1 on #88 — sync_computed_state()'s persist of the
	 *   computed `{ active, seam, door }` tuple on 'aura_worker_door_computed':
	 *   a real conditional INSERT (`WHERE NOT EXISTS`, insert_unique_write()'s
	 *   own shape) for the first-ever mint, and a fenced compare-and-swap
	 *   UPDATE on the exact bytes last read, so a request racing a newer
	 *   transition can never overwrite it blind. Also under the swept
	 *   'aura_worker_' prefix.
	 */
	private const ACKNOWLEDGED_RAW_OPTION_WRITES = array(
		'includes/class-aura-worker-door-log.php'    => 12,
		'includes/class-aura-worker-magic-link.php'  => 3,
		'includes/class-aura-worker-rules.php'       => 5,
		'includes/class-aura-worker.php'             => 2,
		'includes/class-elementor-door-governor.php' => 3,
	);

	protected function setUp(): void {
		sa_reset_state();
	}

	// -----------------------------------------------------------------------
	// The scans
	// -----------------------------------------------------------------------

	/** uninstall.php's own source. */
	private static function uninstall_source(): string {
		return (string) file_get_contents( SA_PLUGIN_DIR . '/uninstall.php' );
	}

	/**
	 * The namespace prefixes uninstall.php sweeps, READ OUT OF UNINSTALL.PHP.
	 *
	 * Not restated here: a copy would drift, and the copy is what this whole
	 * class exists to stop being the mechanism. Add a prefix there and every
	 * assertion below honours it; add a key under no prefix and they redden.
	 *
	 * @return string[]
	 */
	private static function swept_prefixes(): array {
		$src = self::uninstall_source();
		if ( ! preg_match( '/\$aura_prefixes\s*=\s*array\(([^)]*)\)/', $src, $m ) ) {
			throw new RuntimeException( 'uninstall.php no longer declares $aura_prefixes — this class cannot tell what it sweeps' );
		}
		preg_match_all( "/'([^']+)'/", $m[1], $found );
		$prefixes = $found[1];
		sort( $prefixes );
		if ( array() === $prefixes ) {
			throw new RuntimeException( 'uninstall.php sweeps no prefixes at all' );
		}
		return $prefixes;
	}

	/** Is $key removed by the prefix sweep? */
	private static function swept( string $key ): bool {
		foreach ( self::swept_prefixes() as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Scan (1) and (2) in one pass over the plugin's comment-free sources.
	 *
	 * A key is EXACT when the whole argument is one literal or constant, and a
	 * PREFIX when that literal is concatenated onto something (`'aura_magic_' .
	 * $magic_id`). The distinction is not cosmetic: an exact transient name can
	 * be deleted by name and must be, a family's cannot.
	 *
	 * @return array{keys:array<string,string>,prefixes:array<string,string>,dynamic:array<string,int>}
	 *         keys/prefixes: name => 'option' or 'transient';
	 *         dynamic: relative path => unresolved write count.
	 */
	private static function scan_writes(): array {
		$keys     = array();
		$prefixes = array();
		$dynamic  = array();
		$fns      = implode( '|', self::WRITE_FUNCTIONS );
		foreach ( sa_plugin_php_sources() as $path => $source ) {
			// `self::` needs the class the file declares to resolve.
			$class = preg_match( '/\bclass\s+([A-Za-z_][A-Za-z0-9_]*)/', $source, $cm ) ? $cm[1] : '';
			$at    = 0;
			while ( preg_match( '/(?<![\w>$])(' . $fns . ')\s*\(/', $source, $m, PREG_OFFSET_CAPTURE, $at ) ) {
				$open = (int) $m[0][1] + strlen( $m[0][0] );
				$at   = $open;
				$kind = in_array( $m[1][0], array( 'set_transient', 'set_site_transient' ), true ) ? 'transient' : 'option';
				$arg  = self::first_argument( $source, $open );
				$hit  = self::resolve( $arg, $class );
				if ( null === $hit ) {
					$dynamic[ $path ] = ( $dynamic[ $path ] ?? 0 ) + 1;
					continue;
				}
				list( $name, $partial ) = $hit;
				// A key already seen as a transient stays a transient: the
				// stronger obligation (a delete_transient() by name) wins.
				if ( $partial ) {
					if ( ! isset( $prefixes[ $name ] ) || 'transient' === $kind ) {
						$prefixes[ $name ] = $kind;
					}
					continue;
				}
				if ( ! isset( $keys[ $name ] ) || 'transient' === $kind ) {
					$keys[ $name ] = $kind;
				}
			}
		}
		ksort( $keys );
		ksort( $prefixes );
		ksort( $dynamic );
		return array( 'keys' => $keys, 'prefixes' => $prefixes, 'dynamic' => $dynamic );
	}

	/**
	 * The first argument of a call, from just after its `(` — walked with a
	 * paren/bracket depth and a quote state, because `self::expired_claim(
	 * self::SWEEP_CLAIM, $day )` and `$record['target']` both contain
	 * characters a comma-split would trip over.
	 */
	private static function first_argument( string $source, int $from ): string {
		$depth = 0;
		$quote = '';
		$len   = strlen( $source );
		for ( $i = $from; $i < $len; $i++ ) {
			$c = $source[ $i ];
			if ( '' !== $quote ) {
				if ( '\\' === $c ) {
					++$i;
					continue;
				}
				if ( $c === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( "'" === $c || '"' === $c ) {
				$quote = $c;
				continue;
			}
			if ( '(' === $c || '[' === $c ) {
				++$depth;
				continue;
			}
			if ( ')' === $c || ']' === $c ) {
				if ( 0 === $depth ) {
					return trim( substr( $source, $from, $i - $from ) );
				}
				--$depth;
				continue;
			}
			if ( ',' === $c && 0 === $depth ) {
				return trim( substr( $source, $from, $i - $from ) );
			}
		}
		return trim( substr( $source, $from ) );
	}

	/**
	 * Resolve an argument to the key it names, as `array( name, is_prefix )`, or
	 * null when it is computed at runtime.
	 *
	 * A constant is resolved by REFLECTION against the loaded class, never by
	 * re-parsing its declaration: constant() reads the same value production
	 * does, so a constant whose value is edited cannot leave this scan asserting
	 * yesterday's name. That is also the tie the CONSTRAINT asks for —
	 * uninstall.php loads no plugin code and must use literals, and this is what
	 * binds those literals to the constants they mirror.
	 */
	private static function resolve( string $arg, string $class ): ?array {
		$arg = trim( $arg );
		// 'literal', or 'literal' . <anything> — the latter names a family.
		if ( preg_match( "/^'([^']*)'/", $arg, $m ) || preg_match( '/^"([^"$\\\\]*)"/', $arg, $m ) ) {
			return array( $m[1], strlen( $m[0] ) !== strlen( $arg ) );
		}
		// Self::CONST / Class::CONST, optionally concatenated onto something.
		if ( preg_match( '/^(self|static|[A-Za-z_][A-Za-z0-9_]*)::([A-Z][A-Z0-9_]*)/', $arg, $m ) ) {
			$owner = in_array( strtolower( $m[1] ), array( 'self', 'static' ), true ) ? $class : $m[1];
			if ( '' !== $owner && defined( $owner . '::' . $m[2] ) ) {
				$value = constant( $owner . '::' . $m[2] );
				if ( is_string( $value ) ) {
					return array( $value, strlen( $m[0] ) !== strlen( $arg ) );
				}
			}
		}
		return null;
	}

	/**
	 * Scan (3): raw INSERT/UPDATE statements against the options table, as
	 * `relative path => count`.
	 */
	private static function raw_option_writes(): array {
		$found = array();
		foreach ( sa_plugin_php_sources() as $path => $source ) {
			$hits = preg_match_all( '/\b(INSERT INTO|UPDATE)\s+\{\$wpdb->options\}/', $source );
			if ( $hits > 0 ) {
				$found[ $path ] = $hits;
			}
		}
		ksort( $found );
		return $found;
	}

	// -----------------------------------------------------------------------
	// The guards
	// -----------------------------------------------------------------------

	/**
	 * THE GUARD. Every key the plugin writes through a storage function is
	 * removed by uninstall.
	 */
	public function test_every_key_the_plugin_writes_is_swept_by_uninstall(): void {
		$scan      = self::scan_writes();
		$uncovered = array();
		foreach ( $scan['keys'] + $scan['prefixes'] as $key => $kind ) {
			if ( ! self::swept( $key ) ) {
				$uncovered[] = "{$key} ({$kind})";
			}
		}
		$this->assertSame(
			array(),
			$uncovered,
			"uninstall.php does not remove every key this plugin stores.\n"
			. "A WP.org plugin's uninstall must leave nothing behind, and the key(s) above are written somewhere in the plugin but fall under none of the namespace prefixes uninstall.php sweeps ("
			. implode( ', ', self::swept_prefixes() ) . ").\n"
			. "Do ONE of these — do not add a delete_option() line and move on, that is the failure this guard exists for:\n"
			. "  * name the key inside an existing prefix (aura_worker_… is the plugin's namespace); or\n"
			. "  * add its prefix to \$aura_prefixes in digitizer-site-worker/uninstall.php, and say in the comment there what family it covers."
		);
		// A scan matching nothing would pass the assertion above trivially.
		$this->assertGreaterThanOrEqual( 8, count( $scan['keys'] ), 'the write scan resolved almost no keys — it is not looking at the plugin' );
	}

	/**
	 * A transient does not live in the options table on a site with a persistent
	 * object cache, so the prefix sweep sees no row for it. Every transient whose
	 * name is FIXED must therefore also be deleted by name.
	 */
	public function test_every_fixed_name_transient_is_also_deleted_by_name(): void {
		$src     = self::uninstall_source();
		$missing = array();
		foreach ( self::scan_writes()['keys'] as $key => $kind ) {
			if ( 'transient' !== $kind ) {
				continue; // families are excluded: only the prefix is known, never a name to delete
			}
			if ( ! preg_match( "/delete_transient\(\s*'" . preg_quote( $key, '/' ) . "'\s*\)/", $src ) ) {
				$missing[] = $key;
			}
		}
		$this->assertSame(
			array(),
			$missing,
			"uninstall.php sweeps the options table, but a site with a PERSISTENT OBJECT CACHE keeps its transients out of that table entirely — the sweep finds no row and the value survives the uninstall. Add delete_transient( '<key>' ) to uninstall.php for each name above."
		);
	}

	/**
	 * The runtime-named writes, each acknowledged. A new dynamic family is a
	 * decision — which prefix does it live under, and is that prefix swept — so
	 * it must not be possible to add one without making it.
	 */
	public function test_every_runtime_named_write_is_acknowledged(): void {
		$this->assertSame(
			self::ACKNOWLEDGED_DYNAMIC_WRITES,
			self::scan_writes()['dynamic'],
			"the set of storage writes whose KEY IS COMPUTED AT RUNTIME changed.\n"
			. "Such a key can never be covered by name, only by prefix. Work out what prefix the new call site produces, confirm uninstall.php sweeps it, then record the file and its new count in ACKNOWLEDGED_DYNAMIC_WRITES with the prefix named in the docblock."
		);
	}

	/**
	 * Scan (3). A raw statement writes a row without calling any storage
	 * function, so the scans above cannot see it — the hourly rule counters are
	 * written exactly this way, and were leaked by every uninstall before this
	 * one.
	 */
	public function test_every_raw_options_table_write_is_acknowledged(): void {
		$this->assertSame(
			self::ACKNOWLEDGED_RAW_OPTION_WRITES,
			self::raw_option_writes(),
			"the set of RAW INSERT/UPDATE statements against the options table changed.\n"
			. "These bypass update_option()/add_option() and are invisible to every scan of storage-function calls, so uninstall coverage for them is not checked anywhere else. Confirm the option name the new statement writes falls under a prefix uninstall.php sweeps, then record the file and its new count in ACKNOWLEDGED_RAW_OPTION_WRITES with the key it writes named in the docblock."
		);
	}

	/**
	 * The two ends tied together: uninstall.php's own by-name deletes must
	 * themselves fall under the prefixes it sweeps. A by-name delete that does
	 * not is a key outside the namespace — the sweep would miss it on any site
	 * where the by-name delete failed to match, and, more to the point, it means
	 * the namespace is no longer the whole truth about where this plugin stores.
	 */
	public function test_uninstalls_own_by_name_deletes_all_live_inside_the_swept_namespaces(): void {
		preg_match_all( "/delete_(?:option|transient)\(\s*'([^']+)'\s*\)/", self::uninstall_source(), $m );
		$outside = array_values( array_filter( array_unique( $m[1] ), static function ( $key ) {
			return ! self::swept( $key );
		} ) );
		$this->assertSame( array(), $outside, 'uninstall.php deletes a key by name that its own prefix sweep would not reach — either bring the key inside the namespace or add its prefix to $aura_prefixes' );
		$this->assertNotEmpty( $m[1], 'uninstall.php deletes nothing by name at all — the app-password record and the object-cache transients need it' );
	}

	// -----------------------------------------------------------------------
	// Behaviour: the sweep really removes what the scans found
	// -----------------------------------------------------------------------

	/**
	 * Every key the scans found, materialised as a row and then uninstalled.
	 *
	 * This is what stops the guards above from being an argument about strings:
	 * the keys are computed, planted in the "database", and the real
	 * uninstall.php is run over them.
	 */
	public function test_uninstall_removes_every_computed_key_from_a_populated_site(): void {
		$scan   = self::scan_writes();
		$seeded = array();
		// The app-password record is deliberately excluded: whether it survives
		// is a decision about a live administrator credential, made on the state
		// of that credential and pinned by its own two tests below and in
		// ConnectAppPasswordTest — not something to seed with a junk value here.
		$names = array_diff( array_keys( $scan['keys'] ), array( Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION ) );
		// A resolved PREFIX is planted as a real INSTANCE of its family, which
		// is the row a site actually holds.
		foreach ( array_keys( $scan['prefixes'] ) as $family ) {
			$names[] = $family . 'instance';
		}
		foreach ( $names as $name ) {
			$seeded[]         = $name;
			$GLOBALS['_rows'][ $name ]    = maybe_serialize( 'seeded' );
			$GLOBALS['_options'][ $name ] = 'seeded';
		}
		// The runtime-named families, spelled as a site holds them.
		foreach ( array(
			'aura_grant_nonce_deadbeef',
			'aura_worker_rule_expired_0020000_abc123',
			'aura_worker_rules_blocked_h0496000',
			'aura_worker_rules_warned_h0496000',
			'_transient_aura_magic_abc',
			'_transient_timeout_aura_magic_abc',
			'_transient_aura_worker_tokfail_' . md5( '203.0.113.9' ),
			'_transient_timeout_aura_worker_tokfail_' . md5( '203.0.113.9' ),
		) as $name ) {
			$seeded[]                     = $name;
			$GLOBALS['_rows'][ $name ]    = maybe_serialize( 'seeded' );
			$GLOBALS['_options'][ $name ] = 'seeded';
		}
		// One row that is NOT the plugin's: a snapshot restores site options
		// belonging to WordPress and other plugins, and uninstall must not take
		// those with it.
		$GLOBALS['_rows']['active_plugins']    = maybe_serialize( array( 'other/other.php' ) );
		$GLOBALS['_options']['active_plugins'] = array( 'other/other.php' );

		$this->assertGreaterThanOrEqual( 16, count( $seeded ), 'nothing much was seeded — the scan is not producing keys' );
		self::run_uninstall();

		$left = array();
		foreach ( $seeded as $name ) {
			if ( array_key_exists( $name, $GLOBALS['_rows'] ) || array_key_exists( $name, $GLOBALS['_options'] ) ) {
				$left[] = $name;
			}
		}
		$this->assertSame( array(), $left, 'these rows survived the uninstall' );
		$this->assertArrayHasKey( 'active_plugins', $GLOBALS['_options'], "uninstall took another owner's option with it" );
	}

	/**
	 * THE BUG, end to end (Codex on PR #76). Uninstall a disconnected site,
	 * reinstall it: activation mints a fresh token, and the stale marker must
	 * NOT still be refusing every authenticated mutation.
	 */
	public function test_the_unbind_marker_does_not_survive_an_uninstall(): void {
		update_option(
			Aura_Worker_Unbind::OPTION,
			array( 'unbound_at' => gmdate( 'c' ), 'app_password_uuids' => array() )
		);
		set_transient( Aura_Worker_Unbind::FINISH_TRANSIENT, 1, 300 );
		$this->assertTrue( Aura_Worker_Unbind::is_set(), 'the site really is unbound before the uninstall' );

		self::run_uninstall();

		$this->assertFalse(
			Aura_Worker_Unbind::is_set(),
			'the unbind marker survived the uninstall — a reinstall would mint a fresh token onto a site that refuses every authenticated mutation, with nothing on disk to say why'
		);
		$this->assertFalse( get_transient( Aura_Worker_Unbind::FINISH_TRANSIENT ), 'and its self-heal throttle went with it' );
	}

	/**
	 * The one deliberate survivor. A tracking record whose Application Password
	 * revocation could not be PROVEN is kept so a reinstall can finish the job
	 * (round-7); a prefix sweep that quietly undid that would throw away the
	 * only trace of a live administrator credential.
	 */
	public function test_the_sweep_does_not_undo_the_deliberate_app_password_keep(): void {
		$GLOBALS['_app_passwords'][7]          = array( array( 'uuid' => 'uuid-1', 'name' => 'Aura SiteAgent' ) );
		$GLOBALS['_app_passwords_delete_fail'] = true;
		update_option( Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION, array( 'user_id' => 7, 'uuid' => 'uuid-1' ) );
		update_option( 'aura_worker_site_token', 'hash' );

		self::run_uninstall();

		$this->assertIsArray(
			get_option( Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION, null ),
			'the prefix sweep removed the record uninstall deliberately kept — the live credential it names is now unfindable'
		);
		$this->assertFalse( get_option( 'aura_worker_site_token', false ), 'everything else still went' );
	}

	// -----------------------------------------------------------------------
	// The key space itself — the guards cannot LOSE a finding
	// -----------------------------------------------------------------------

	/**
	 * A key outside the swept namespaces reddens the guard, BY NAME.
	 *
	 * This is the whole promise: the next author is told what they broke and
	 * what to do about it, rather than left to discover a leaked row on a
	 * customer's site. The fixture is a plausible one — a new key under a new
	 * prefix, written the ordinary way.
	 */
	public function test_a_key_outside_the_swept_namespaces_is_reported(): void {
		$uncovered = sa_with_plugin_file(
			'includes/aaa_legacy/class-aura-worker-strays.php',
			"<?php\nupdate_option( 'siteagent_stray_key', 1 );\n",
			static function () {
				$scan = self::scan_writes();
				$out  = array();
				foreach ( $scan['keys'] + $scan['prefixes'] as $key => $kind ) {
					if ( ! self::swept( $key ) ) {
						$out[] = $key;
					}
				}
				return $out;
			}
		);
		$this->assertSame( array( 'siteagent_stray_key' ), $uncovered, 'a key stored outside every swept prefix must be reported by name' );
	}

	/**
	 * And it is found even when its file shares a basename with a real one.
	 *
	 * Keyed by basename these scans SILENTLY LOSE entries —
	 * RecursiveDirectoryIterator walks includes/aaa_legacy/ before includes/, so
	 * the real file overwrites the fixture's slot — and that exact collision hid
	 * a REST registrar for a whole task before anyone looked (#434 Task 7
	 * round-2). An enumeration whose guarantee is COMPLETENESS must not have a
	 * key space in which two findings can collapse into one.
	 */
	public function test_a_dynamic_write_hidden_under_a_shared_basename_is_still_found(): void {
		$dynamic = sa_with_plugin_file(
			'includes/aaa_legacy/class-aura-worker-rules.php',
			"<?php\nclass Aura_Worker_Legacy {\n\tpublic function f( \$name ) {\n\t\tupdate_option( \$name, 1 );\n\t}\n}\n",
			static function () {
				return self::scan_writes()['dynamic'];
			}
		);
		$this->assertSame(
			array( 'includes/aaa_legacy/class-aura-worker-rules.php' => 1 ) + self::ACKNOWLEDGED_DYNAMIC_WRITES,
			$dynamic,
			'the scan lost a dynamic write to a shared basename — its completeness guarantee is void'
		);
	}

	/**
	 * A raw options-table write in a file that has none today is reported too —
	 * the scan that covers the storage API's blind spot has to have its own
	 * completeness pinned, or it is only a list of files somebody once looked at.
	 */
	public function test_a_new_raw_options_table_write_is_reported(): void {
		$found = sa_with_plugin_file(
			'includes/aaa_legacy/class-aura-worker-raw.php',
			"<?php\n\$wpdb->query( \"INSERT INTO {\$wpdb->options} (option_name) VALUES ('x')\" );\n",
			static function () {
				return self::raw_option_writes();
			}
		);
		$this->assertArrayHasKey( 'includes/aaa_legacy/class-aura-worker-raw.php', $found, 'a new raw options-table write must be reported' );
		$this->assertSame( 1, $found['includes/aaa_legacy/class-aura-worker-raw.php'] );
	}

	/**
	 * The constant resolution is real reflection, not a re-parse: change a
	 * constant's VALUE and the scan follows it. This is what ties uninstall.php's
	 * literals — the file loads no plugin code, so literals are all it may use —
	 * to the constants they mirror.
	 */
	public function test_a_constant_keyed_write_resolves_through_the_loaded_class(): void {
		$keys = self::scan_writes()['keys'];
		$this->assertArrayHasKey( Aura_Worker_Unbind::FINISH_TRANSIENT, $keys, 'set_transient( self::FINISH_TRANSIENT ) must resolve to its value' );
		$this->assertSame( 'transient', $keys[ Aura_Worker_Unbind::FINISH_TRANSIENT ] );
		$this->assertArrayHasKey( Aura_Worker_Rules::OPTION, $keys, 'update_option( self::OPTION ) must resolve to its value' );
		$this->assertArrayHasKey( Aura_Worker_Magic_Link::PROBE_UNPROVEN_OPTION, $keys, 'a multi-line update_option() call must resolve too' );
	}

	/** Run uninstall.php the way WordPress does — the file loads no plugin code. */
	/**
	 * THE SWEEP MATCHES ITS OWN NAMESPACE, NOT A PATTERN THAT LOOKS LIKE IT
	 * (Codex on PR #76, round 2). `_transient_` is framed by underscores and
	 * an unescaped underscore is a LIKE wildcard, so a prefix concatenated
	 * onto an already-escaped one matched `xtransientYaura_worker_foreign`
	 * too — a third-party row that then failed both `_transient_` guards and
	 * was deleted as a plain option.
	 */
	public function test_a_foreign_option_shaped_like_the_transient_prefix_survives(): void {
		$foreign = 'xtransientYaura_worker_foreign';
		$GLOBALS['_rows'][ $foreign ]    = maybe_serialize( 'somebody else\'s' );
		$GLOBALS['_options'][ $foreign ] = 'somebody else\'s';
		update_option( 'aura_worker_site_token', 'hash' );

		self::run_uninstall();

		$this->assertArrayHasKey( $foreign, $GLOBALS['_options'], "uninstall took another owner's option with it" );
		$this->assertFalse( get_option( 'aura_worker_site_token', false ), 'the plugin\'s own row still went' );
	}

	/**
	 * THE MARKER IS A CREDENTIAL RECORD TOO (Codex on PR #76, round 3). Phase B
	 * could not prove this password revoked, so the marker's uuid list is the
	 * only trace of a live administrator credential — the record settled first
	 * names only the password this plugin minted, never one an operator
	 * connected by hand. Sweeping the marker away would leave the credential
	 * authenticating with nothing on the site that records it.
	 */
	public function test_a_marker_naming_an_unrevocable_password_outlives_the_uninstall(): void {
		$GLOBALS['_app_passwords'][7]          = array( array( 'uuid' => 'uuid-manual', 'name' => 'Somebody' ) );
		$GLOBALS['_app_passwords_delete_fail'] = true;
		update_option(
			Aura_Worker_Unbind::OPTION,
			array(
				'unbound_at'         => gmdate( 'c' ),
				'app_password_uuids' => array( 'uuid-manual' ),
				'app_password_users' => array( 'uuid-manual' => 7 ),
			)
		);
		update_option( 'aura_worker_site_token', 'hash' );

		self::run_uninstall();

		$this->assertTrue( Aura_Worker_Unbind::is_set(), 'the only trace of a live administrator credential was swept away' );
		$this->assertSame( array( 'uuid-manual' ), get_option( Aura_Worker_Unbind::OPTION )['app_password_uuids'] );
		$this->assertFalse( get_option( 'aura_worker_site_token', false ), 'everything else still went' );
	}

	/**
	 * An entry the marker repair could not attribute carries a NULL owner
	 * rather than a guessed one, so nothing in this file can address it — and
	 * the site-wide search that could lives in plugin code uninstall does not
	 * load. The debt stands; the marker stays.
	 */
	public function test_a_marker_entry_with_no_recovered_owner_outlives_the_uninstall(): void {
		update_option(
			Aura_Worker_Unbind::OPTION,
			array(
				'unbound_at'         => gmdate( 'c' ),
				'app_password_uuids' => array( 'uuid-orphan' ),
				'app_password_users' => array( 'uuid-orphan' => null ),
			)
		);

		self::run_uninstall();

		$this->assertTrue( Aura_Worker_Unbind::is_set(), 'an unattributed credential is not an absent one' );
	}

	/**
	 * And the debt really is settled when it can be: the credential is revoked
	 * by its stored uuid and the marker goes with everything else, so a
	 * reinstall does not meet a marker refusing every mutation for a password
	 * that no longer exists.
	 */
	public function test_a_marker_whose_password_is_revoked_does_not_outlive_the_uninstall(): void {
		$GLOBALS['_app_passwords'][7] = array( array( 'uuid' => 'uuid-manual', 'name' => 'Somebody' ) );
		update_option(
			Aura_Worker_Unbind::OPTION,
			array(
				'unbound_at'         => gmdate( 'c' ),
				'app_password_uuids' => array( 'uuid-manual' ),
				'app_password_users' => array( 'uuid-manual' => 7 ),
			)
		);

		self::run_uninstall();

		$this->assertFalse( sa_app_password_exists( 7, 'uuid-manual' ), 'the credential the marker named is revoked' );
		$this->assertFalse( Aura_Worker_Unbind::is_set(), 'and nothing is owed, so the marker goes' );
	}

	/**
	 * A MARKER WHOSE CREDENTIAL LIST CANNOT BE READ IS AN UNSETTLED DEBT
	 * (Codex round-6 P1). This file used to map an unreadable list to an empty
	 * array and call the marker settled, which swept away what may be the only
	 * record of a still-live manually supplied Application Password.
	 *
	 * @dataProvider unreadable_credential_lists
	 *
	 * @param mixed $value What the field holds.
	 */
	public function test_a_marker_whose_credential_list_is_unreadable_outlives_the_uninstall( $value ): void {
		$marker = array( 'unbound_at' => gmdate( 'c' ), 'app_password_users' => array() );
		if ( '__missing__' !== $value ) {
			$marker['app_password_uuids'] = $value;
		}
		update_option( Aura_Worker_Unbind::OPTION, $marker );
		update_option( 'aura_worker_site_token', 'hash' );

		self::run_uninstall();

		$this->assertNotFalse( get_option( Aura_Worker_Unbind::OPTION, false ), 'the marker was swept on a list nothing could read' );
		$this->assertFalse( get_option( 'aura_worker_site_token', false ), 'everything else still went' );
	}

	/** @return array<string,array{0:mixed}> */
	public static function unreadable_credential_lists(): array {
		return array(
			'missing'          => array( '__missing__' ),
			'not a list'       => array( 'uuid-manual' ),
			'a nested entry'   => array( array( array( 'nested' ) ) ),
			'an empty entry'   => array( array( '' ) ),
		);
	}

	/**
	 * A DAMAGED OWNER IS AN UNKNOWN ONE, NEVER A CONFIDENT WRONG ONE (Codex
	 * round-7 P1). `(int) "42junk"` is 42 in PHP, so the revocation asked user
	 * 42's list, was told "not there", and reported a credential that belongs
	 * to somebody else as settled — after which the sweep deleted the marker
	 * that was its only record.
	 */
	public function test_a_marker_owner_that_names_nobody_outlives_the_uninstall(): void {
		$GLOBALS['_app_passwords'][9] = array( array( 'uuid' => 'uuid-manual', 'name' => 'Somebody' ) );
		update_option(
			Aura_Worker_Unbind::OPTION,
			array(
				'unbound_at'         => gmdate( 'c' ),
				'app_password_uuids' => array( 'uuid-manual' ),
				'app_password_users' => array( 'uuid-manual' => '42junk' ),
			)
		);
		update_option( 'aura_worker_site_token', 'hash' );

		self::run_uninstall();

		$this->assertNotFalse( get_option( Aura_Worker_Unbind::OPTION, false ), 'the debt was settled against a user the row never named' );
		$this->assertTrue( sa_app_password_exists( 9, 'uuid-manual' ), 'and the real holder still has it' );
		$this->assertFalse( get_option( 'aura_worker_site_token', false ), 'everything else still went' );
	}

	/**
	 * A READ THAT FAILED IS NOT A REVOCATION (Codex round-10 P1).
	 * `WP_Application_Passwords::get_user_application_passwords()` answers an
	 * empty array for a user who holds none AND for a usermeta read that could
	 * not be completed, so uninstall believed a credential revoked because the
	 * list it asked came back empty — and swept away the only record of it.
	 */
	public function test_a_marker_whose_revocation_cannot_be_proven_outlives_the_uninstall(): void {
		$GLOBALS['_app_passwords'][7]                = array( array( 'uuid' => 'uuid-manual', 'name' => 'Somebody' ) );
		$GLOBALS['_app_passwords_delete_fail']       = true;
		$GLOBALS['_sa_app_password_read_fail'][7]    = true; // and the confirming read fails too
		update_option(
			Aura_Worker_Unbind::OPTION,
			array(
				'unbound_at'         => gmdate( 'c' ),
				'app_password_uuids' => array( 'uuid-manual' ),
				'app_password_users' => array( 'uuid-manual' => 7 ),
			)
		);
		update_option( 'aura_worker_site_token', 'hash' );

		self::run_uninstall();

		$this->assertNotFalse( get_option( Aura_Worker_Unbind::OPTION, false ), 'an unreadable list was believed as an absence' );
		$this->assertFalse( get_option( 'aura_worker_site_token', false ), 'everything else still went' );
	}

	/** The plugin's own record fails closed on the same evidence. */
	public function test_an_app_password_record_whose_revocation_cannot_be_proven_is_kept(): void {
		$GLOBALS['_app_passwords'][7]             = array( array( 'uuid' => 'uuid-1', 'name' => 'Aura SiteAgent' ) );
		$GLOBALS['_app_passwords_delete_fail']    = true;
		$GLOBALS['_sa_app_password_read_fail'][7] = true;
		update_option( Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION, array( 'user_id' => 7, 'uuid' => 'uuid-1' ) );
		update_option( 'aura_worker_site_token', 'hash' );

		self::run_uninstall();

		$this->assertIsArray( get_option( Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION, null ) );
		$this->assertFalse( get_option( 'aura_worker_site_token', false ) );
	}

	private static function run_uninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'digitizer-site-worker/digitizer-site-worker.php' );
		}
		include SA_PLUGIN_DIR . '/uninstall.php';
	}
}
