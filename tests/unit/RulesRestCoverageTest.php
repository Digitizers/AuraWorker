<?php
/**
 * The invariant, in two halves: read from the source — a handler that requires
 * a grant guards rules and returns the guard's error — and then proved by
 * calling every handler the scan found under a site freeze. Nothing else can
 * catch a tenth handler added next year.
 *
 * A separate file from RulesRestRouteTest.php on purpose: PHPUnit's
 * TestSuiteLoader maps one file to exactly one test class — the one whose
 * short name ends with the filename (see
 * vendor/phpunit/phpunit/src/Runner/TestSuiteLoader.php::load()). A second
 * TestCase subclass living in RulesRestRouteTest.php would be loaded (no parse
 * error) but silently dropped from every suite — never collected, never run,
 * never reported missing. That is exactly the failure mode this class exists
 * to prevent for class-aura-worker-api.php, so it cannot be allowed to happen
 * to itself.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class RulesRestCoverageTest extends TestCase {

	/** Grant actions deliberately NOT rule-guarded, each with its reason. */
	const EXEMPT = array(
		// Captures state, changes nothing; a freeze must not block the safety net.
		'wp.snapshot.create',
		// Refuses a held write outright; can only PREVENT a mutation, never
		// cause one, so a freeze blocking it would strand exactly the calls an
		// operator most wants to clear while frozen.
		'door.reject',
		// Governance-plane bookkeeping on Aura's own log (raises the ack
		// floor, lets acked rows be dropped); not a site mutation, and a
		// freeze blocking it could starve the log toward its MAX_UNACKED
		// bound and close the door during exactly the window an operator
		// most wants visibility into it.
		'door.ack',
	);

	protected function setUp(): void {
		sa_reset_state();
		$GLOBALS['_caps'] = array( 'manage_options' );
		// No gateway key: is_enforced() is false, so require_for() returns true
		// and the handlers reach the rule guard — which is what is under test
		// here. (Grants have their own suite.)
		unset( $GLOBALS['_options']['aura_worker_grant_pubkey'] );
	}

	public function test_every_grant_gated_handler_also_guards_rules(): void {
		$src = file_get_contents( SA_PLUGIN_DIR . '/includes/class-aura-worker-api.php' );

		// Every grant call in the file, counted from TOKENS. A regex over the
		// text would also count `require_for(` written inside an error message
		// or a comment; the tokenizer counts calls. This is the denominator: a
		// call the per-method scan cannot account for is a failure, not a skip.
		$total_calls = self::count_grant_calls( $src );
		$this->assertGreaterThanOrEqual( 10, $total_calls, 'fewer grant calls than expected — was the call renamed?' );

		// Split the file into methods — by BRACES, not by a regex. A
		// non-greedy `.*?\n\t}` stops at the first line that looks like a
		// closing brace, which in these handlers is the end of
		// `if ( is_wp_error( $grant ) ) { ... }` — i.e. the scanner would read
		// only the grant check and report every correctly-guarded handler as
		// unguarded. The tokenizer knows where a method actually ends.
		$accounted = 0;
		$guarded   = array();
		foreach ( self::methods_of( $src ) as $name => $parts ) {
			// `grants` is the action of each REAL call, read from that call's
			// own argument tokens — never from a regex over the body, which
			// cannot tell an argument from the same text inside a message.
			// `exec` is the body with literal contents emptied, so "calls the
			// guard" cannot be satisfied by a string that names it.
			$exec   = $parts['exec'];
			$grants = $parts['grants'];
			if ( empty( $grants ) ) {
				continue;
			}
			$accounted += count( $grants );
			foreach ( $grants as $i => $action ) {
				// The action must be a plain single-quoted literal so this
				// scanner can read it. A constant, a variable or an
				// interpolated string is refused here, by name — never
				// silently skipped, and never satisfied by a lookalike
				// elsewhere in the method.
				$this->assertNotNull( $action, "{$name}: require_for() call #{$i} passes an action this scanner cannot read — write it as a literal, e.g. 'wp.update.core'" );
				if ( in_array( $action, self::EXEMPT, true ) ) {
					$this->assertStringNotContainsString( 'guard_rest', $exec, "{$name} is exempt but guards anyway — remove it from EXEMPT" );
					continue;
				}
				$this->assertStringContainsString( 'Aura_Worker_Rules::guard_rest', $exec, "{$name} requires a grant ({$action}) but does not guard rules — add Aura_Worker_Rules::guard_rest() or list the action in EXEMPT with a reason" );
				$this->assertStringContainsString( 'Aura_Worker_Rules::with_warnings', $exec, "{$name} guards rules but drops a warn on the floor — wrap its result in with_warnings()" );
				// Calling the guard is not obeying it. The result must reach a
				// return: either returned directly, or assigned and returned
				// from an is_wp_error() check. A handler that calls guard_rest()
				// and ignores the WP_Error proceeds with the mutation under a
				// blocking rule — and every string assertion above still passes.
				$this->assertTrue(
					self::guard_result_is_returned( $exec ),
					"{$name} calls Aura_Worker_Rules::guard_rest() but never returns its error — assign it and `if ( is_wp_error( \$x ) ) { return \$x; }`. Do NOT `return` the call itself: it answers true when no rule matched, which would end the handler before its work."
				);
				$guarded[] = $name;
			}
		}
		// Every call in the file was seen inside a method the scan understood.
		// A call in a closure, a static helper, or a method with a different
		// brace style would make these differ — and that is the point.
		$this->assertSame( $total_calls, $accounted, 'a require_for() call sits somewhere the per-method scan does not reach — restructure it into a `public function` handler, or extend the scan' );

		// Static checks say the shape is right. This says the site refuses:
		// every handler discovered above is CALLED under a site freeze and must
		// answer with the refusal. A handler added next year is covered the day
		// it is written, without anybody remembering to extend a list.
		$this->assertNotEmpty( $guarded, 'no guarded handlers were discovered — the scan found nothing to check' );
		$api = new Aura_Worker_API( new Aura_Worker_Security() );
		$GLOBALS['_options'][ Aura_Worker_Rules::OPTION ] = array(
			'envelope' => 'x.y', 'client' => 'c1', 'seq' => 1, 'issued_at' => '', 'received_at' => time(),
			'rules'    => array( array( 'key' => 'rule/freeze', 'effect' => 'block', 'target' => array( 'type' => 'site' ), 'reason' => 'deploy' ) ),
		);

		// Real fixtures for the three handlers whose mutating call needs a
		// genuine target to reach: an id-less snapshot or a not-found backup
		// path/plugin dir make guard-before and guard-after indistinguishable —
		// the "already mutated" case this loop exists to catch would silently
		// no-op either way. self::real_fixture_overrides() sets these up and
		// returns the per-handler param overrides that reach them; cleaned up
		// in the finally block below.
		$overrides = self::real_fixture_overrides();

		try {
			foreach ( $guarded as $name ) {
				$request = new WP_REST_Request();
				// Parameters every handler in this file might read. A handler that
				// needs one this list does not have will fail loudly here, which is
				// the right moment to add it.
				foreach ( array_merge(
					array(
						'plugin'  => 'akismet/akismet.php',
						'theme'   => 'twentytwentyfive',
						'id'      => 1,
						'kind'    => 'option',
						'target'  => 'blogname',
						'plugins' => array( 'akismet/akismet.php' ),
					),
					$overrides[ $name ] ?? array()
				) as $key => $value ) {
					$request->set_param( $key, $value );
				}
				$GLOBALS['_mutations'] = array();
				$result                = $api->$name( $request );
				$error                 = is_wp_error( $result ) ? $result : null;
				$this->assertNotNull( $error, "{$name} ran under a site freeze instead of refusing" );
				$this->assertSame( 'aura_rule_blocked', $error->get_error_code(), "{$name} refused, but not because of the rule" );
				// The refusal has to happen INSTEAD of the work, not after it. A
				// handler that updates the site and then returns the rule's error
				// answers exactly the same way to the two assertions above — and
				// has already done the thing the freeze exists to prevent.
				$this->assertSame(
					array(),
					$GLOBALS['_mutations'],
					"{$name} refused, but only after mutating: " . implode( ', ', $GLOBALS['_mutations'] )
				);
			}
		} finally {
			self::clean_up_real_fixtures();
		}
	}

	/**
	 * Real, reachable targets for the three handlers whose guarded mutation
	 * would otherwise no-op on the placeholder params shared above:
	 *
	 *  - restore_snapshot needs a snapshot that actually exists at `id` — a
	 *    made-up id makes Aura_Worker_Snapshots::restore() answer "not found"
	 *    before it ever reaches update_option(), whether the guard ran first
	 *    or not, so the witness would see nothing either way.
	 *  - rollback_plugin needs `backup_path` to be a real file AND the plugin
	 *    directory to exist — Aura_Worker_Rollback::restore_plugin() deletes
	 *    the plugin directory (via $wp_filesystem->delete(), witnessed) before
	 *    it ever opens the zip, so the backup itself does not need to be valid.
	 *  - self_update needs `zip_url` to pass is_allowed_self_update_url()
	 *    (https, github.com, the release-download path, a .zip suffix) so it
	 *    reaches Plugin_Upgrader::install() (already witnessed).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function real_fixture_overrides() {
		$snapshots   = new Aura_Worker_Snapshots();
		$GLOBALS['_options']['blogname'] = 'Test Site'; // existed=true → restore() takes the update_option() branch, not delete_option().
		$snapshot    = $snapshots->snapshot_option( 'blogname' );

		$plugin_dir = WP_PLUGIN_DIR . '/akismet/akismet.php';
		wp_mkdir_p( $plugin_dir );
		file_put_contents( $plugin_dir . '/main.php', "<?php // fixture\n" );

		$backup_path = WP_CONTENT_DIR . '/aura-backups/rules-coverage-fixture.zip';
		wp_mkdir_p( dirname( $backup_path ) );
		file_put_contents( $backup_path, 'not a real zip, but a real file' );

		return array(
			'restore_snapshot' => array( 'id' => $snapshot['snapshot']['id'] ),
			'rollback_plugin'  => array( 'plugin' => 'akismet/akismet.php', 'backup_path' => $backup_path ),
			'self_update'      => array( 'zip_url' => 'https://github.com/Digitizers/SiteAgent/releases/download/v9.9.9/plugin.zip' ),
		);
	}

	/** Remove what real_fixture_overrides() created on disk. */
	private static function clean_up_real_fixtures() {
		$rrmdir = static function ( $dir ) use ( &$rrmdir ) {
			if ( ! is_dir( $dir ) ) {
				return;
			}
			foreach ( array_diff( scandir( $dir ), array( '.', '..' ) ) as $item ) {
				$path = $dir . '/' . $item;
				is_dir( $path ) ? $rrmdir( $path ) : @unlink( $path );
			}
			@rmdir( $dir );
		};
		$rrmdir( WP_PLUGIN_DIR . '/akismet' );
		@unlink( WP_CONTENT_DIR . '/aura-backups/rules-coverage-fixture.zip' );
	}

	/**
	 * Is the guard's result assigned and returned FROM ITS ERROR CHECK?
	 *
	 * Exactly one accepted shape, read from the exec projection (no comments,
	 * no string contents):
	 *
	 *   $x = Aura_Worker_Rules::guard_rest( … );
	 *   if ( is_wp_error( $x ) ) { return $x; }
	 *
	 * `return Aura_Worker_Rules::guard_rest( … );` is NOT accepted, tempting as
	 * it looks: the guard answers `true` when nothing matched or a warn
	 * attached, so returning it directly would end the handler before its work
	 * and hand the caller a bool instead of a REST response. The freeze sweep
	 * cannot see that — under a freeze the shape returns the right error.
	 *
	 * Nor are two independent facts enough ("checks it somewhere" plus
	 * "returns it somewhere"): a handler that logs inside the check, mutates,
	 * and returns the error afterwards satisfies both while the write happens.
	 * The return has to be INSIDE the branch, which is what this matches.
	 *
	 * @param string $exec Method body, code only.
	 * @return bool
	 */
	private static function guard_result_is_returned( $exec ) {
		if ( ! preg_match( '/(\$\w+)\s*=\s*Aura_Worker_Rules::guard_rest\s*\(/', $exec, $m ) ) {
			return false;
		}
		$var = preg_quote( $m[1], '/' );
		// if ( is_wp_error( $x ) ) { return $x; }  — braces optional, nothing
		// but the return inside them.
		return 1 === preg_match(
			'/if\s*\(\s*is_wp_error\s*\(\s*' . $var . '\s*\)\s*\)\s*(\{\s*)?return\s+' . $var . '\s*;/',
			$exec
		);
	}

	public function test_the_scanner_knows_a_guard_that_is_called_from_one_that_is_obeyed(): void {
		// The distinction the behavioural sweep exists for, checked directly on
		// the three shapes: returned inline, assigned-and-returned, and called
		// with its error dropped on the floor.
		$this->assertTrue( self::guard_result_is_returned( '$r = Aura_Worker_Rules::guard_rest( array(), \'wp.x\' ); if ( is_wp_error( $r ) ) { return $r; }' ) );
		$this->assertFalse(
			self::guard_result_is_returned( 'return Aura_Worker_Rules::guard_rest( array(), \'wp.x\' );' ),
			'returning the guard directly ends the handler on success and answers a bool'
		);
		$this->assertFalse(
			self::guard_result_is_returned( '$r = Aura_Worker_Rules::guard_rest( array(), \'wp.x\' ); do_something();' ),
			'a guard whose error is ignored was accepted'
		);
		$this->assertFalse(
			self::guard_result_is_returned( '$r = Aura_Worker_Rules::guard_rest( array(), \'wp.x\' ); if ( is_wp_error( $r ) ) { log_it(); }' ),
			'a guard that is checked but not returned was accepted'
		);
		$this->assertFalse(
			self::guard_result_is_returned( '$r = Aura_Worker_Rules::guard_rest( array(), \'wp.x\' ); if ( is_wp_error( $r ) ) { log_it(); } do_the_update(); return $r;' ),
			'a handler that mutates and then returns the error was accepted'
		);
	}

	public function test_the_scanner_reads_code_not_comments(): void {
		// The invariant is "the handler CALLS the guard", and a substring
		// search over raw source answers "the handler MENTIONS it" — which a
		// comment or an error message satisfies. methods_of() drops comments
		// and string contents, so this cannot pass by talking about the guard.
		$src = <<<'PHP'
<?php
class Fake {
	public function pretend_handler( $request ) {
		// Calls Aura_Worker_Rules::guard_rest() and Aura_Worker_Rules::with_warnings().
		$msg = 'run Aura_Worker_Rules::guard_rest() here';
		return $msg;
	}
	public function real_handler( $request ) {
		$v = Aura_Worker_Rules::guard_rest( array(), 'wp.x' );
		return Aura_Worker_Rules::with_warnings( $v );
	}
}
PHP;
		$bodies = self::methods_of( $src );
		$this->assertStringNotContainsString( 'guard_rest', $bodies['pretend_handler']['exec'] );
		$this->assertStringContainsString( 'guard_rest', $bodies['real_handler']['exec'] );
		$this->assertStringContainsString( 'with_warnings', $bodies['real_handler']['exec'] );
	}

	public function test_the_scanner_reads_the_action_from_the_call_itself(): void {
		// Emptying literals must not blind the scanner to the action, which is
		// a literal — and the action must come from the CALL's arguments, not
		// from anywhere in the body. The second handler is the attack: its real
		// call passes an unreadable action (a constant) while an error message
		// contains the text of an exempt one. A body-wide regex would classify
		// it as `wp.snapshot.create` and wave an unguarded mutating handler
		// through; reading the argument tokens yields null, which fails.
		$src = <<<'PHP'
<?php
class Fake {
	public function honest( $request ) {
		$guard = Aura_Worker_Grant::require_for( $request, 'wp.update.core', array() );
		$rules = Aura_Worker_Rules::guard_rest( array(), 'wp.update.core' );
		return Aura_Worker_Rules::with_warnings( $rules );
	}
	public function sneaky( $request ) {
		$msg   = 'see Aura_Worker_Grant::require_for( $request, \'wp.snapshot.create\' )';
		$guard = Aura_Worker_Grant::require_for( $request, self::SOME_ACTION, array() );
		return $guard;
	}
}
PHP;
		$m = self::methods_of( $src );
		$this->assertSame( array( 'wp.update.core' ), $m['honest']['grants'] );
		$this->assertSame( array( null ), $m['sneaky']['grants'], 'an action was taken from a string instead of the call' );
		$this->assertSame( 2, self::count_grant_calls( $src ), 'the text inside the message was counted as a call' );
	}

	public function test_every_spelling_of_the_class_name_is_a_grant_call(): void {
		// PHP 8 emits ONE token per qualified name, with a different type for
		// each spelling: T_STRING, T_NAME_FULLY_QUALIFIED (`\Aura_Worker_Grant`),
		// T_NAME_QUALIFIED (`Ns\…`), T_NAME_RELATIVE (`namespace\…`). A scanner
		// that lists the types it knows skips the spelling nobody thought of —
		// and a skipped grant call is an unguarded handler passing the
		// invariant with the suite still green. Hence: match the name.
		$src = <<<'PHP'
<?php
namespace Vendor\Pkg;
class Fake {
	public function bare( $request ) {
		return Aura_Worker_Grant::require_for( $request, 'wp.update.core', array() );
	}
	public function fully_qualified( $request ) {
		return \Aura_Worker_Grant::require_for( $request, 'wp.update.theme', array() );
	}
	public function qualified( $request ) {
		return Vendor\Pkg\Aura_Worker_Grant::require_for( $request, 'wp.update.plugin', array() );
	}
	public function relative( $request ) {
		return namespace\Aura_Worker_Grant::require_for( $request, 'wp.self_update', array() );
	}
	public function not_ours( $request ) {
		return Some_Other_Class::require_for( $request, 'wp.nope', array() );
	}
}
PHP;
		$m = self::methods_of( $src );
		$this->assertSame( array( 'wp.update.core' ), $m['bare']['grants'] );
		$this->assertSame( array( 'wp.update.theme' ), $m['fully_qualified']['grants'], 'a fully qualified grant call was not recognised' );
		$this->assertSame( array( 'wp.update.plugin' ), $m['qualified']['grants'], 'a namespace-qualified grant call was not recognised' );
		$this->assertSame( array( 'wp.self_update' ), $m['relative']['grants'], 'a namespace-relative grant call was not recognised' );
		$this->assertSame( array(), $m['not_ours']['grants'], 'require_for() on another class was counted as a grant' );
		$this->assertSame( 4, self::count_grant_calls( $src ) );
	}

	public function test_an_imported_alias_of_the_grant_class_still_counts(): void {
		// `use … as Grant;` gives the same class a name the scanner has never
		// heard of. Unknown name, skipped call, unguarded handler passing the
		// invariant — the failure this scanner keeps almost having. The file's
		// own `use` statements say which names mean the grant class.
		$src = <<<'PHP'
<?php
namespace Vendor\Pkg;
use Vendor\Pkg\Aura_Worker_Grant as Grant;
use Vendor\Pkg\Something_Else as Other;
class Fake {
	public function aliased( $request ) {
		return Grant::require_for( $request, 'wp.update.core', array() );
	}
	public function unrelated( $request ) {
		return Other::require_for( $request, 'wp.nope', array() );
	}
}
PHP;
		$m = self::methods_of( $src );
		$this->assertSame( array( 'wp.update.core' ), $m['aliased']['grants'], 'an aliased grant call was not recognised' );
		$this->assertSame( array(), $m['unrelated']['grants'], 'an alias of another class was counted as a grant' );
		$this->assertSame( 1, self::count_grant_calls( $src ) );
	}

	public function test_grouped_and_listed_imports_are_read_whole(): void {
		// `use Ns\{A as X, B};` and `use A as X, B as Y;` are both valid, and
		// both put the alias somewhere a first-`as`, stop-at-`{` parser never
		// looks. Every clause is read, prefix included.
		$src = <<<'PHP'
<?php
namespace Vendor\Pkg;
use function Vendor\Pkg\helper as h;
use Vendor\Pkg\{Something_Else as Other, Aura_Worker_Grant as Grant};
use Vendor\Pkg\Another as A1, Vendor\Pkg\Aura_Worker_Grant as G2;
class Fake {
	public function grouped( $request ) {
		return Grant::require_for( $request, 'wp.update.core', array() );
	}
	public function listed( $request ) {
		return G2::require_for( $request, 'wp.update.theme', array() );
	}
	public function other( $request ) {
		return Other::require_for( $request, 'wp.nope', array() );
	}
}
PHP;
		$m = self::methods_of( $src );
		$this->assertSame( array( 'wp.update.core' ), $m['grouped']['grants'], 'a grouped-import alias was not recognised' );
		$this->assertSame( array( 'wp.update.theme' ), $m['listed']['grants'], 'a later clause of a comma-separated import was not read' );
		$this->assertSame( array(), $m['other']['grants'] );
		$this->assertSame( 2, self::count_grant_calls( $src ) );
	}

	/**
	 * Every `function name() { ... }` in the source, as:
	 *
	 *  - `exec`   — the body with comments dropped and every string literal
	 *    emptied. Used to ask whether the guards are CALLED: a handler that
	 *    mentions `Aura_Worker_Rules::guard_rest` in an error message or a
	 *    comment must not satisfy an invariant about calling it.
	 *  - `grants` — one entry per real `Aura_Worker_Grant::require_for()` call,
	 *    holding that call's action argument, or **null** when the argument is
	 *    not a plain literal this scanner can read. Parsed from the call's own
	 *    argument tokens — a regex over the body cannot tell an argument from
	 *    the identical text sitting inside a message, so a handler could pass
	 *    an unreadable action while a lookalike string supplied an exempt one.
	 *
	 * Brace-aware via the tokenizer, so braces inside comments or strings
	 * cannot move a method boundary.
	 *
	 * @param string $src PHP source.
	 * @return array<string,array{exec:string,grants:array<int,string|null>}>
	 */
	private static function methods_of( $src ) {
		$tokens             = token_get_all( $src );
		self::$grant_names  = self::grant_names_of( $tokens );
		$out    = array();
		$count  = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! is_array( $tokens[ $i ] ) || T_FUNCTION !== $tokens[ $i ][0] ) {
				continue;
			}
			// The name is the next T_STRING; skip whitespace and `&`.
			$name = '';
			for ( $j = $i + 1; $j < $count; $j++ ) {
				if ( is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) {
					$name = $tokens[ $j ][1];
					break;
				}
				if ( is_string( $tokens[ $j ] ) && '(' === $tokens[ $j ] ) {
					break; // A closure: no name, not a handler.
				}
			}
			if ( '' === $name ) {
				continue;
			}
			// Walk to the opening brace of the body, then to its match.
			$depth    = 0;
			$exec     = '';
			$grants   = array();
			$open     = false;
			$comments = array( T_COMMENT, T_DOC_COMMENT );
			$literals = array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML );
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$text = is_array( $tokens[ $j ] ) ? $tokens[ $j ][1] : $tokens[ $j ];
				if ( ! $open ) {
					if ( ';' === $text ) {
						break; // An abstract/interface declaration: no body.
					}
					if ( '{' === $text ) {
						$open  = true;
						$depth = 1;
					}
					continue;
				}
				if ( '{' === $text ) {
					++$depth;
				} elseif ( '}' === $text ) {
					--$depth;
					if ( 0 === $depth ) {
						$out[ $name ] = array( 'exec' => $exec, 'grants' => $grants );
						$i            = $j;
						break;
					}
				}
				if ( self::is_grant_call( $tokens, $j ) ) {
					$grants[] = self::action_argument_of( $tokens, $j );
				}
				$type = is_array( $tokens[ $j ] ) ? $tokens[ $j ][0] : null;
				if ( null !== $type && in_array( $type, $comments, true ) ) {
					continue; // Not code at all.
				}
				$exec .= ( null !== $type && in_array( $type, $literals, true ) ) ? '' : $text;
			}
		}
		return $out;
	}

	/**
	 * Is the token at $j the `require_for` of `Aura_Worker_Grant::require_for(`?
	 *
	 * @param array $tokens Token stream.
	 * @param int   $j      Index.
	 * @return bool
	 */
	private static function is_grant_call( array $tokens, $j ) {
		if ( ! is_array( $tokens[ $j ] ) || T_STRING !== $tokens[ $j ][0] || 'require_for' !== $tokens[ $j ][1] ) {
			return false;
		}
		// Walk back over whitespace: `::` then the class name.
		$k = self::prev_code( $tokens, $j );
		if ( null === $k || ! is_array( $tokens[ $k ] ) || T_DOUBLE_COLON !== $tokens[ $k ][0] ) {
			return false;
		}
		$k = self::prev_code( $tokens, $k );
		if ( null === $k || ! is_array( $tokens[ $k ] ) ) {
			return false;
		}
		// The token BEFORE a `::` is a class name, whatever PHP called it. Do
		// not enumerate the type: PHP 8 emits T_STRING for the bare spelling,
		// T_NAME_FULLY_QUALIFIED for `\Aura_Worker_Grant`, T_NAME_QUALIFIED for
		// `Ns\Aura_Worker_Grant` and T_NAME_RELATIVE for
		// `namespace\Aura_Worker_Grant`, and a list is exactly what leaves the
		// next spelling silently unscanned — which is how an unguarded handler
		// passes a coverage invariant with the test still green. Match on the
		// name itself; nothing but a name can stand there.
		//
		// Compare the LAST segment, so every qualified spelling counts. (On
		// PHP 7.4 a leading `\` is its own token and the name token is still
		// `Aura_Worker_Grant`, so the same test works there.) `self::$grant_names`
		// also holds any alias the file imported — `use … as Grant;` — because a
		// name the scanner does not know is a call it silently skips, and a
		// skipped grant call is an unguarded handler passing this invariant.
		$parts = explode( '\\', (string) $tokens[ $k ][1] );
		return in_array( end( $parts ), self::$grant_names, true );
	}

	/**
	 * The action argument of the call whose name token is at $j: the second
	 * argument, and only when it is a plain single-quoted literal.
	 *
	 * @param array $tokens Token stream.
	 * @param int   $j      Index of the `require_for` token.
	 * @return string|null Action, or null when it is not a readable literal.
	 */
	private static function action_argument_of( array $tokens, $j ) {
		$count = count( $tokens );
		$k     = self::next_code( $tokens, $j );
		if ( null === $k || '(' !== $tokens[ $k ] ) {
			return null;
		}
		// Split the argument list at top-level commas.
		$depth = 0;
		$args  = array( array() );
		for ( $i = $k + 1; $i < $count; $i++ ) {
			$t = $tokens[ $i ];
			if ( is_string( $t ) && in_array( $t, array( '(', '[' ), true ) ) {
				++$depth;
			} elseif ( is_string( $t ) && in_array( $t, array( ')', ']' ), true ) ) {
				if ( ')' === $t && 0 === $depth ) {
					break; // End of this call.
				}
				--$depth;
			} elseif ( is_string( $t ) && ',' === $t && 0 === $depth ) {
				$args[] = array();
				continue;
			}
			if ( is_array( $t ) && in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$args[ count( $args ) - 1 ][] = $t;
		}
		if ( ! isset( $args[1] ) || 1 !== count( $args[1] ) ) {
			return null; // Missing, or an expression rather than one literal.
		}
		$arg = $args[1][0];
		if ( ! is_array( $arg ) || T_CONSTANT_ENCAPSED_STRING !== $arg[0] ) {
			return null; // A constant, a variable, an interpolated string.
		}
		$raw = $arg[1];
		if ( "'" !== $raw[0] ) {
			return null; // Double-quoted: readable, but the codebase writes '…'.
		}
		return stripcslashes( substr( $raw, 1, -1 ) );
	}

	/**
	 * Names that mean the grant class in one file: the class itself, plus any
	 * alias a `use … as X;` introduced.
	 *
	 * @var string[]
	 */
	private static $grant_names = array( 'Aura_Worker_Grant' );

	/**
	 * Read the file's `use` statements for aliases of the grant class.
	 *
	 * `use Some\Ns\Aura_Worker_Grant as Grant;` makes `Grant::require_for()` a
	 * grant call under a name the scanner would otherwise not know — and an
	 * unknown name is a silently skipped call.
	 *
	 * @param array $tokens Token stream.
	 * @return string[]
	 */
	private static function grant_names_of( array $tokens ) {
		$names = array( 'Aura_Worker_Grant' );
		$count = count( $tokens );
		for ( $j = 0; $j < $count; $j++ ) {
			if ( ! is_array( $tokens[ $j ] ) || T_USE !== $tokens[ $j ][0] ) {
				continue;
			}
			// Read the WHOLE statement, to `;`, braces included. A `use` can be
			// a list (`use A as X, B as Y;`) or grouped
			// (`use Ns\{A as X, B};`), and stopping at the first `{` or the
			// first `as` misses the clause the alias is actually in — which is
			// a grant call under a name the scanner never learns.
			$parts  = array();
			$prefix = '';
			$group  = false;
			for ( $k = $j + 1; $k < $count; $k++ ) {
				$text = is_array( $tokens[ $k ] ) ? $tokens[ $k ][1] : $tokens[ $k ];
				if ( ';' === $text ) {
					break;
				}
				if ( is_array( $tokens[ $k ] ) && in_array( $tokens[ $k ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}
				if ( '{' === $text ) {
					// Everything so far was the group prefix.
					$prefix = implode( '', $parts );
					$parts  = array();
					$group  = true;
					continue;
				}
				if ( '}' === $text ) {
					continue;
				}
				$parts[] = $text;
			}
			if ( empty( $parts ) ) {
				continue;
			}
			// `use function …` / `use const …` import no classes.
			if ( in_array( strtolower( (string) $parts[0] ), array( 'function', 'const' ), true ) ) {
				continue;
			}
			// Split into clauses on commas, then read each one.
			foreach ( explode( ',', implode( ' ', $parts ) ) as $clause ) {
				$words = preg_split( '/\s+/', trim( $clause ), -1, PREG_SPLIT_NO_EMPTY );
				if ( empty( $words ) ) {
					continue;
				}
				$as = array_search( 'as', array_map( 'strtolower', $words ), true );
				if ( false === $as || ! isset( $words[ $as + 1 ], $words[ $as - 1 ] ) ) {
					continue; // No alias in this clause: the class keeps its own name.
				}
				$target = explode( '\\', ( $group ? $prefix : '' ) . $words[ $as - 1 ] );
				if ( 'Aura_Worker_Grant' === end( $target ) ) {
					$names[] = (string) $words[ $as + 1 ];
				}
			}
		}
		return $names;
	}

	/**
	 * Count real grant calls in a source file, under every name that means the
	 * grant class there.
	 *
	 * @param string $src PHP source.
	 * @return int
	 */
	private static function count_grant_calls( $src ) {
		$tokens            = token_get_all( $src );
		self::$grant_names = self::grant_names_of( $tokens );
		$n                 = 0;
		foreach ( array_keys( $tokens ) as $j ) {
			if ( self::is_grant_call( $tokens, $j ) ) {
				++$n;
			}
		}
		return $n;
	}

	/** Index of the previous non-whitespace, non-comment token, or null. */
	private static function prev_code( array $tokens, $j ) {
		for ( $k = $j - 1; $k >= 0; $k-- ) {
			if ( is_array( $tokens[ $k ] ) && in_array( $tokens[ $k ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			return $k;
		}
		return null;
	}

	/** Index of the next non-whitespace, non-comment token, or null. */
	private static function next_code( array $tokens, $j ) {
		$count = count( $tokens );
		for ( $k = $j + 1; $k < $count; $k++ ) {
			if ( is_array( $tokens[ $k ] ) && in_array( $tokens[ $k ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			return $k;
		}
		return null;
	}
}
