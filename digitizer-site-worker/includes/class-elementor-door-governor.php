<?php
/**
 * The Elementor MCP door governor (SiteAgent 2.16.0, spec
 * docs/superpowers/specs/2026-09-02-elementor-door-governance-design.md).
 *
 * Elementor >= 4.3 serves its abilities from /elementor/mcp and through
 * core's /wp-abilities/v1 run route; both execute the callback registered
 * with wp_register_ability(). This class wraps that callback for every
 * elementor/* slug outside a named read allowlist (the LAST filter on the
 * args), verifies after registration that the callback finally stored is
 * its own closure — and closes both transports when it cannot — and judges
 * every call against the site's stored ruleset: block refuses, allow runs
 * with a snapshot, everything else is HELD for Aura's approval.
 *
 * @package Aura_Worker
 * @since 2.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Worker_Elementor_Door {

	const MODULE_CLASS   = '\Elementor\Modules\Mcp\Module';
	const CLAIM_STALE_MS = 600000;

	const CPT_GLOBAL_CLASS  = 'e_global_class';
	const CPT_DEFAULT_STYLE = 'e_default_style';
	const CPT_COMPONENT     = 'elementor_component';

	/**
	 * The creation mutex's option name. Task 7 owns taking and releasing it;
	 * the name is here because execute()'s exception path already has to
	 * release it, and a constant defined in one place cannot drift from the
	 * name the mutex actually takes.
	 */
	const CREATING = 'aura_worker_door_creating';

	/** Kit meta the design system lives in (4.3.0-beta1, R3). */
	const KIT_META_KEYS = array(
		'_elementor_global_variables',
		'_elementor_global_classes_order',
		'_elementor_global_classes_order_preview',
		'_elementor_global_classes_labels',
		'_elementor_global_classes_labels_preview',
		'_elementor_default_styles_post_ids',
		'_elementor_page_settings',
	);

	/** Meta captured on a page / component document. */
	const PAGE_META_KEYS = array( '_elementor_data', '_elementor_page_settings', '_elementor_css' );

	/**
	 * The ONLY exemption from the wrapper. Sixteen names: nine read tools and
	 * seven resources (R5). Annotations are not evidence — five current writes
	 * declare destructive => false.
	 */
	const READ_ALLOWLIST = array(
		'elementor/get-default-styles',
		'elementor/get-page-structure',
		'elementor/get-widget-schema',
		'elementor/list-assets',
		'elementor/list-components',
		'elementor/list-posts',
		'elementor/list-resources',
		'elementor/list-widget-schemas',
		'elementor/read-resource',
		'elementor/global-classes-resource',
		'elementor/global-variables-resource',
		'elementor/interactions-schema-resource',
		'elementor/manage-global-variable-guide',
		'elementor/style-best-practices',
		'elementor/wordpress-best-practices',
		'elementor/list-dynamic-tags',
	);

	/**
	 * Ability => target kind. `page` reads input.post_id (or document_id) and
	 * resolves page|post from the post type; `component` reads input.id (a
	 * missing id is a creation); the rest carry no id.
	 */
	const WRITE_TABLE = array(
		'elementor/publish-document'       => 'page',
		'elementor/manage-elements'        => 'page',
		'elementor/update-page-settings'   => 'page',
		'elementor/build-composition'      => 'page',
		'elementor/create-preview-link'    => 'page',
		'elementor/manage-component'       => 'component',
		'elementor/manage-default-styles'  => 'design_system',
		'elementor/manage-classes'         => 'design_system',
		'elementor/reorder-classes'        => 'design_system',
		'elementor/manage-global-variable' => 'design_system',
		'elementor/create-page'            => 'page_create',
	);

	/** @var array<string,Closure> the closure installed per slug, for the coverage check */
	private static $wrapped = array();
	/** @var string ok|unavailable|unchecked */
	private static $seam = 'unchecked';
	/** @var string */
	private static $seam_reason = '';
	/** @var array<string,array> per-request judgement memo */
	private static $memo = array();
	/** @var array<string,WP_Error> per-request hold memo: one call, one hold */
	private static $held = array();
	/** @var callable|null test seam over snapshot_for() */
	private static $snapshotter = null;
	/** @var callable|null test seam over the stored-callback read */
	private static $callback_reader = null;
	/** @var array|null the ruleset pinned for a replay (Task 8) */
	private static $pinned_ruleset = null;
	/** @var array|null the ack a replay carries (Task 8) */
	private static $replay_ack = null;
	/** @var bool|null memoised presence — see active() */
	private static $active = null;

	/* ------------------------------------------------------------------ */
	/* Wiring                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Wire the seam UNCONDITIONALLY.
	 *
	 * This used to return early unless Elementor's MCP module class already
	 * existed — and that was the one fail-OPEN path in a fail-closed design
	 * (Ruling P6). Both plugins bootstrap on `plugins_loaded` at priority 10,
	 * and same-priority callbacks fire in plugin-inclusion order, which is
	 * alphabetical: `digitizer-site-worker` runs BEFORE `elementor`. On such
	 * a site the class did not exist yet, so no wrapper was installed,
	 * `verify_coverage` never ran, `close_transport` was never hooked — and
	 * /elementor/mcp served every write ungoverned while `status_fragment()`
	 * reported the door closed.
	 *
	 * Every hook below is inert on a site without Elementor: `wrap_args`
	 * touches only `elementor/*` slugs, `verify_coverage` has nothing to
	 * verify, `close_transport` matches only the two door route prefixes,
	 * `observe_insert` is scoped to a governed request in flight, and the
	 * cleanup action is fired by Elementor alone. Presence is decided LAZILY
	 * instead — see active().
	 */
	public static function init() {
		add_filter( 'wp_register_ability_args', array( __CLASS__, 'wrap_args' ), PHP_INT_MAX, 2 );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'verify_coverage' ), PHP_INT_MAX );
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'close_transport' ), 2, 3 );
		add_action( 'wp_insert_post', array( __CLASS__, 'observe_insert' ), 1, 3 );
		add_action( 'elementor/global_classes/cleanup', array( __CLASS__, 'capture_class_cleanup' ), 1, 2 );
	}

	/** Test seam. */
	public static function reset_for_tests() {
		self::$wrapped             = array();
		self::$seam                = 'unchecked';
		self::$seam_reason         = '';
		self::$memo                = array();
		self::$held                = array();
		self::$snapshotter         = null;
		self::$callback_reader     = null;
		self::$pinned_ruleset      = null;
		self::$replay_ack          = null;
		self::$request             = null;
		self::$active              = null;
		// $GLOBALS['_sa_force_door'] — active()'s test override, standing in
		// for the module class this suite cannot define — is reset by
		// sa_reset_state(), not written here: production code reads that
		// seam, it never defines it.
	}

	/** @param callable $fn Test seam. */
	public static function set_snapshotter_for_tests( $fn ) {
		self::$snapshotter = $fn;
	}

	/** @param callable $fn Test seam. */
	public static function set_callback_reader_for_tests( $fn ) {
		self::$callback_reader = $fn;
	}

	/** @return string */
	public static function seam() {
		return self::$seam;
	}

	/**
	 * Is there an Elementor door on this site at all?
	 *
	 * Asked at `wp_abilities_api_init` and at request time — never at
	 * `plugins_loaded`, where the answer is a race (Ruling P6). Two
	 * witnesses, because either can be the one that is ready:
	 *
	 * 1. Elementor's MCP module class, looked up WITHOUT autoloading
	 *    (`class_exists( …, false )`): by the time anything asks, Elementor's
	 *    own autoloader has run and the class is loaded if the module is on.
	 * 2. The Abilities registry holding any `elementor/*` id — the module is
	 *    what registers them, so one of them IS the module, whatever the
	 *    class name does next release.
	 *
	 * Only a POSITIVE answer is memoised: within one request the registry
	 * only grows and an autoloader only becomes available, so `true` cannot
	 * turn back into `false` — but a `false` read before Elementor registered
	 * must not be frozen, which is the very load order this exists for.
	 *
	 * @return bool
	 */
	public static function active() {
		if ( true === self::$active ) {
			return true;
		}
		// The suite cannot define Elementor's module class; this stands in
		// for witness 1. Read only — never written by production code.
		if ( ! empty( $GLOBALS['_sa_force_door'] ) ) {
			self::$active = true;
			return true;
		}
		if ( class_exists( self::MODULE_CLASS, false ) ) {
			self::$active = true;
			return true;
		}
		if ( function_exists( 'wp_get_abilities' ) ) {
			foreach ( wp_get_abilities() as $ability ) {
				$name = is_object( $ability ) && method_exists( $ability, 'get_name' ) ? (string) $ability->get_name() : '';
				if ( 0 === strpos( $name, 'elementor/' ) ) {
					self::$active = true;
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * What `/status` says about the door (Task 9 fills the rest of it).
	 *
	 * ABSENT on a site with no door: Aura keys on the fragment's presence to
	 * decide whether this site is governed at all, so a site without
	 * Elementor must not report a door — open or closed.
	 *
	 * @return array|null { epoch, seam, door }
	 */
	public static function status_fragment() {
		if ( ! self::active() ) {
			return null;
		}
		return array(
			'epoch' => Aura_Worker_Door_Log::epoch(),
			'seam'  => self::$seam,
			'door'  => 'ok' === self::$seam ? 'open' : 'closed',
		);
	}

	/* ------------------------------------------------------------------ */
	/* The seam                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Wrap execute_callback for every elementor/* slug outside the allowlist.
	 *
	 * @param array  $args Ability args.
	 * @param string $name Ability name.
	 * @return array
	 */
	public static function wrap_args( $args, $name ) {
		$name = (string) $name;
		if ( 0 !== strpos( $name, 'elementor/' ) || in_array( $name, self::READ_ALLOWLIST, true ) ) {
			return $args;
		}
		if ( ! is_array( $args ) ) {
			return $args;
		}
		$inner = isset( $args['execute_callback'] ) ? $args['execute_callback'] : null;
		$slug  = $name;
		$wrap  = static function ( $input = array() ) use ( $inner, $slug ) {
			return Aura_Worker_Elementor_Door::execute( $slug, $inner, $input );
		};
		self::$wrapped[ $name ]   = $wrap;
		$args['execute_callback'] = $wrap;
		return $args;
	}

	/**
	 * After Elementor registered: is every non-read elementor/* ability
	 * FINALLY registered with our closure? Read back from the registry, by
	 * Reflection on the stored property (R2) — never by re-applying the
	 * filter, which would inspect a fresh wrapper instead of what is stored.
	 */
	public static function verify_coverage() {
		if ( ! self::active() ) {
			// No Elementor, no door, nothing to govern — and emphatically NOT
			// a coverage failure: closing the transport here would 503 a
			// route that does not exist on this site (Ruling P6).
			self::$seam        = 'ok';
			self::$seam_reason = 'inactive: no Elementor MCP module on this site';
			return;
		}
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			self::$seam        = 'unavailable';
			self::$seam_reason = 'abilities api absent';
			return;
		}
		try {
			foreach ( wp_get_abilities() as $ability ) {
				$name = is_object( $ability ) && method_exists( $ability, 'get_name' ) ? (string) $ability->get_name() : '';
				if ( 0 !== strpos( $name, 'elementor/' ) || in_array( $name, self::READ_ALLOWLIST, true ) ) {
					continue;
				}
				$stored = self::stored_callback( $ability );
				if ( ! isset( self::$wrapped[ $name ] ) || $stored !== self::$wrapped[ $name ] ) {
					self::$seam        = 'unavailable';
					self::$seam_reason = 'uncovered: ' . $name;
					return;
				}
			}
		} catch ( \Throwable $e ) {
			self::$seam        = 'unavailable';
			self::$seam_reason = 'stored callback unreadable: ' . $e->getMessage();
			return;
		}
		self::$seam        = 'ok';
		self::$seam_reason = '';
	}

	/**
	 * @param object $ability WP_Ability.
	 * @return mixed the STORED execute callback.
	 * @throws ReflectionException When the property is not there (a coverage failure).
	 */
	private static function stored_callback( $ability ) {
		if ( null !== self::$callback_reader ) {
			return call_user_func( self::$callback_reader, $ability );
		}
		$prop = new ReflectionProperty( get_class( $ability ), 'execute_callback' );
		if ( PHP_VERSION_ID < 80100 ) {
			// Required on the plugin's 7.4 floor; a no-op since 8.1 and
			// deprecated in 8.5, so it is only called where it does something.
			$prop->setAccessible( true );
		}
		return $prop->getValue( $ability );
	}

	/**
	 * @param string $route REST route.
	 * @return bool
	 */
	public static function route_is_door( $route ) {
		$route = (string) $route;
		return (bool) preg_match( '#^/elementor/mcp(/|$)#', $route ) || (bool) preg_match( '#^/wp-abilities/v1/abilities/elementor/#', $route );
	}

	/**
	 * The transport blocker: independent of the wrapper, so a build that
	 * bypasses the wrapper still meets a closed door — reads included.
	 *
	 * @param mixed           $response Short-circuit value.
	 * @param array           $handler  Route handler.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function close_transport( $response, $handler, $request ) {
		if ( null !== $response || ! $request || ! method_exists( $request, 'get_route' ) ) {
			return $response;
		}
		if ( ! self::route_is_door( $request->get_route() ) ) {
			return $response;
		}
		if ( ! self::active() ) {
			return $response; // a door that does not exist is not closed
		}
		if ( 'ok' === self::$seam ) {
			return $response;
		}
		return new WP_Error(
			'aura_door_ungoverned',
			__( 'Aura governs this door and cannot verify its coverage on this build; the door is closed until SiteAgent is updated', 'digitizer-site-worker' ),
			array(
				'status' => 503,
				'reason' => self::$seam_reason,
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Actor, touches, judgement                                           */
	/* ------------------------------------------------------------------ */

	/** @return array|WP_Error */
	public static function actor() {
		$user = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		$uid  = $user ? (int) ( isset( $user->ID ) ? $user->ID : 0 ) : 0;
		if ( $uid <= 0 ) {
			return new WP_Error( 'aura_actor_unidentified', 'This call carries no authenticated user; Aura cannot attribute it.', array( 'status' => 403 ) );
		}
		// The UUID the plugin already captured on `application_password_did_authenticate`
		// (class-aura-worker-security.php:118, 251) — the one place that knows
		// which credential authenticated this request (Codex round-4 P2).
		$uuid = class_exists( 'Aura_Worker_Security' ) ? (string) Aura_Worker_Security::authenticating_app_password_uuid() : '';
		$name = null;
		if ( '' !== $uuid && class_exists( 'WP_Application_Passwords' ) ) {
			$pw   = WP_Application_Passwords::get_user_application_password( $uid, $uuid );
			$name = is_array( $pw ) && isset( $pw['name'] ) ? (string) $pw['name'] : null;
		}
		$route = class_exists( 'Aura_Worker_Call_Context' ) ? (string) Aura_Worker_Call_Context::rest_route() : '';
		return array(
			'user_id'           => $uid,
			'login'             => (string) ( isset( $user->user_login ) ? $user->user_login : '' ),
			'app_password_name' => $name,
			'app_password_uuid' => '' === $uuid ? null : $uuid,
			'via'               => 0 === strpos( $route, '/wp-abilities/' ) ? 'rest' : 'mcp',
		);
	}

	/**
	 * @param string $slug  Ability.
	 * @param array  $input Input.
	 * @return array|WP_Error touches, or aura_target_unattributed.
	 */
	public static function touches_for( $slug, array $input ) {
		$kind = isset( self::WRITE_TABLE[ $slug ] ) ? self::WRITE_TABLE[ $slug ] : null;
		switch ( $kind ) {
			case 'page':
				$id = self::page_id_from( $input );
				if ( null === $id ) {
					return new WP_Error( 'aura_target_unattributed', 'This write names no page Aura can snapshot; it was not run.', array( 'status' => 403 ) );
				}
				$type = get_post_type( $id );
				return array(
					array(
						'type' => 'page' === $type ? 'page' : 'post',
						'id'   => (string) $id,
					),
				);
			case 'component':
				return array(
					array(
						'type' => 'design_system',
						'id'   => '*',
					),
				);
			case 'design_system':
				return array(
					array(
						'type' => 'design_system',
						'id'   => '*',
					),
				);
			case 'page_create':
				return array(
					array(
						'type' => 'page_create',
						'id'   => '*',
					),
				);
			default:
				return new WP_Error( 'aura_ability_unmapped', sprintf( 'SiteAgent does not yet govern the ability "%s"; it was not run.', $slug ), array( 'status' => 403 ) );
		}
	}

	/**
	 * @param array $input Input.
	 * @return int|null
	 */
	private static function page_id_from( array $input ) {
		foreach ( array( 'post_id', 'document_id' ) as $k ) {
			if ( isset( $input[ $k ] ) && is_numeric( $input[ $k ] ) && (int) $input[ $k ] > 0 && (string) (int) $input[ $k ] === (string) $input[ $k ] ) {
				return get_post( (int) $input[ $k ] ) ? (int) $input[ $k ] : null;
			}
		}
		return null;
	}

	/**
	 * The verdict, once per request per (ability, input).
	 *
	 * @param string $slug    Ability.
	 * @param array  $touches Touches.
	 * @param array  $input   Input (memo key only).
	 * @return array { effect: block|hold|allow, rule: array|null, verdict: none|warn|rules_unavailable|block|allow }
	 */
	public static function govern( $slug, array $touches, array $input ) {
		$key = self::memo_key( $slug, $input );
		if ( isset( self::$memo[ $key ] ) ) {
			return self::$memo[ $key ];
		}
		$rec = null !== self::$pinned_ruleset ? self::$pinned_ruleset : Aura_Worker_Rules::current();
		if ( null === $rec || ! isset( $rec['rules'] ) || ! is_array( $rec['rules'] ) ) {
			$out = array(
				'effect'  => 'hold',
				'rule'    => null,
				'verdict' => 'rules_unavailable',
			);
		} else {
			$rule = Aura_Worker_Rules::match( $touches, $rec['rules'], null, Aura_Worker_Rules::site_ref() );
			if ( null === $rule ) {
				$out = array(
					'effect'  => 'hold',
					'rule'    => null,
					'verdict' => 'none',
				);
			} elseif ( 'block' === $rule['effect'] ) {
				$out = array(
					'effect'  => 'block',
					'rule'    => $rule,
					'verdict' => 'block',
				);
			} elseif ( 'warn' === $rule['effect'] ) {
				$out = array(
					'effect'  => 'hold',
					'rule'    => $rule,
					'verdict' => 'warn',
				);
			} else {
				$out = array(
					'effect'  => 'allow',
					'rule'    => $rule,
					'verdict' => 'allow',
				);
			}
		}
		self::$memo[ $key ] = $out;
		return $out;
	}

	/**
	 * One call, one key: the same (ability, input) inside one request is the
	 * same call, whether it is being judged or held.
	 *
	 * @param string $slug  Ability.
	 * @param array  $input Input.
	 * @return string
	 */
	private static function memo_key( $slug, array $input ) {
		return $slug . '|' . hash( 'sha256', (string) wp_json_encode( $input ) );
	}

	/**
	 * @param array $rule Rule.
	 * @return array { key, ruleHash, reason }
	 */
	public static function rule_evidence( array $rule ) {
		return array(
			'key'      => (string) ( isset( $rule['key'] ) ? $rule['key'] : '' ),
			'ruleHash' => hash( 'sha256', (string) wp_json_encode( $rule ) ),
			'reason'   => (string) ( isset( $rule['reason'] ) ? $rule['reason'] : '' ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* The wrapper                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * The execute wrapper installed on every governed ability.
	 *
	 * @param string        $slug  Ability.
	 * @param callable|null $inner The callback Elementor registered.
	 * @param mixed         $input Input.
	 * @return mixed The inner result unchanged, or a WP_Error refusal.
	 */
	public static function execute( $slug, $inner, $input ) {
		$input = is_array( $input ) ? $input : array();
		try {
			return self::govern_and_run( $slug, $inner, $input );
		} catch ( \Throwable $e ) {
			// A broken governor must not become an open door — and a throw
			// AFTER the row was admitted (the callback itself, a snapshot, a
			// collateral capture) must not leave that row pending: `log_after`
			// stops at a pending row, so every later entry would wait ten
			// minutes for the reconciler to call a KNOWN failure "interrupted"
			// (Codex round-3 P1 on #499). Settle it `failed` with whatever
			// evidence the row already carries (created ids, collateral ids
			// were patched in as they happened), release the creation mutex,
			// and say honestly that the call may have run.
			$seq = isset( self::$request['seq'] ) ? (int) self::$request['seq'] : 0;
			$ran = $seq > 0;
			if ( $ran ) {
				$fields = array(
					'result'       => 'failed',
					'reason'       => 'exception',
					'error'        => $e->getMessage(),
					'may_have_run' => true,
				);
				if ( ! empty( self::$request['creating'] ) ) {
					// The observer already put every inserted id on the row; the
					// envelope (or the compensation) must still happen — a row
					// settled here is one the reconciler will never revisit
					// (Codex round-4 P1). finish_creation() releases the mutex.
					try {
						$creation = self::finish_creation( $seq, null, true );
						if ( ! is_wp_error( $creation ) ) {
							$fields = array_merge( $fields, $creation );
						} else {
							$fields['reason'] = 'exception_then_compensated';
						}
					} catch ( \Throwable $creation_error ) {
						delete_option( self::CREATING );
						$fields['creation_error'] = $creation_error->getMessage();
					}
				}
				Aura_Worker_Door_Log::settle( $seq, $fields );
				self::$request = null;
			}
			return new WP_Error(
				'aura_governor_error',
				( $ran ? 'Aura\'s governor failed during this call; it may have run — check the site. ' : 'Aura\'s governor failed on this call; it was not run. ' ) . $e->getMessage(),
				array( 'status' => 503 )
			);
		}
	}

	/**
	 * @param string        $slug  Ability.
	 * @param callable|null $inner Inner.
	 * @param array         $input Input.
	 * @return mixed
	 */
	private static function govern_and_run( $slug, $inner, array $input ) {
		$actor = self::actor();
		if ( is_wp_error( $actor ) ) {
			return $actor;
		}
		$touches = self::touches_for( $slug, $input );
		if ( is_wp_error( $touches ) ) {
			// Unmapped / unattributed: refused before judgement, one terminal entry, nothing held.
			$why = 'aura_ability_unmapped' === $touches->get_error_code() ? 'unknown_ability' : 'unattributed_target';
			self::bump_counter( 'unknown_ability' );
			self::record_terminal_only( $slug, $actor, array(), 'refused', array( 'reason' => $why ) );
			return $touches;
		}
		$verdict = self::govern( $slug, $touches, $input );

		if ( 'block' === $verdict['effect'] ) {
			Aura_Worker_Rules::record_block( $slug, $verdict['rule'] );
			self::record_terminal_only(
				$slug,
				$actor,
				$touches,
				'refused',
				array(
					'verdict'  => 'block',
					'rule_key' => isset( $verdict['rule']['key'] ) ? $verdict['rule']['key'] : '',
				)
			);
			$blocked = Aura_Worker_Rules::blocked_result( $slug, $verdict['rule'] );
			return new WP_Error( 'aura_rule_blocked', $blocked['error'], array( 'status' => 403 ) );
		}

		if ( 'hold' === $verdict['effect'] && null === self::$replay_ack ) {
			return self::hold_call( $slug, $input, $touches, $actor, $verdict );
		}

		// allow (or a replay whose approval is spent): closed log?
		if ( Aura_Worker_Door_Log::is_closed() ) {
			Aura_Worker_Door_Log::bump_refused();
			return self::log_full_error();
		}
		$entry = array(
			'ability'  => $slug,
			'actor'    => $actor,
			'touches'  => $touches,
			'verdict'  => $verdict['verdict'],
			'rule_key' => isset( $verdict['rule']['key'] ) ? (string) $verdict['rule']['key'] : null,
		);
		if ( null !== self::$replay_ack ) {
			$entry['ref']         = self::$replay_ack['ref'];
			$entry['ruleset_seq'] = isset( self::$pinned_ruleset['seq'] ) ? (int) self::$pinned_ruleset['seq'] : null;
		}
		$seq = Aura_Worker_Door_Log::open_pending( $entry );
		if ( is_wp_error( $seq ) ) {
			return $seq;
		}
		// Admission: the row is the reservation. Count, back out above the bound.
		if ( Aura_Worker_Door_Log::count_unacked() > Aura_Worker_Door_Log::MAX_UNACKED ) {
			Aura_Worker_Door_Log::discard( $seq );
			Aura_Worker_Door_Log::close();
			Aura_Worker_Door_Log::bump_refused();
			return self::log_full_error();
		}
		if ( ! Aura_Worker_Door_Log::admit( $seq ) ) {
			return new WP_Error( 'aura_log_failed', 'The door log could not record this call; it was not run.', array( 'status' => 503 ) );
		}
		$kind = isset( self::WRITE_TABLE[ $slug ] ) ? self::WRITE_TABLE[ $slug ] : null;
		// `create-page` always creates; a `manage-component` naming no id is
		// creating one too, and takes the same path — mutex, watermark, and a
		// creation envelope AFTER, because there is nothing to capture before.
		$creating      = self::is_creating( $slug, $input );
		self::$request = array(
			'seq'        => $seq,
			'slug'       => $slug,
			'kind'       => $kind,
			'creating'   => $creating,
			// The witnesses' shared frame: which types this creation may
			// insert, and whose inserts they are. Both are read by the hook
			// observer and by the watermark diff after it.
			'expected'   => self::expected_types( $slug, $input ),
			'actor_id'   => (int) $actor['user_id'],
			'created'    => array(),
			'suspected'  => array(),
			'collateral' => array(),
		);

		// Snapshot before the write (a creation captures after).
		$snapshot_id = null;
		if ( ! $creating ) {
			$snap = self::snapshot_for( $slug, $touches, $input );
			if ( empty( $snap['success'] ) ) {
				Aura_Worker_Door_Log::settle(
					$seq,
					array(
						'result' => 'refused',
						'reason' => 'snapshot_failed',
						'error'  => (string) ( isset( $snap['error'] ) ? $snap['error'] : '' ),
					)
				);
				self::$request = null;
				$why = (string) ( isset( $snap['error'] ) ? $snap['error'] : '' );
				if ( ! empty( $snap['code'] ) ) {
					// A DESIGNATED refusal (today: `aura_target_unattributed`, a
					// component write naming a post that is not a component). The
					// target can never become snapshottable, so this is not the
					// 503 a caller should retry — it is a 403 under its own code,
					// the same answer touches_for() gives an unattributable page.
					return new WP_Error(
						(string) $snap['code'],
						'This write names no target Aura can snapshot; it was not run. ' . $why,
						array( 'status' => 403 )
					);
				}
				return new WP_Error( 'aura_snapshot_failed', 'Aura could not snapshot this target before the write; it was not run. ' . $why, array( 'status' => 503 ) );
			}
			$snapshot_id = (string) $snap['snapshot']['id'];
			if ( ! Aura_Worker_Door_Log::patch_pending( $seq, array( 'snapshot_id' => $snapshot_id ) ) ) { // the id lands on the row BEFORE the write, durably
				// The row stays PENDING on purpose — a snapshot was taken and
				// the row does not know it, which is the reconciler's case,
				// not a terminal one this request can honestly write.
				self::$request = null;
				return new WP_Error( 'aura_log_failed', 'The door log could not record the snapshot before the write; it was not run.', array( 'status' => 503 ) );
			}
		} else {
			$mutex = self::take_creation_mutex( $seq );
			if ( is_wp_error( $mutex ) ) {
				Aura_Worker_Door_Log::settle(
					$seq,
					array(
						'result' => 'refused',
						'reason' => 'creation_busy',
					)
				);
				self::$request = null;
				return $mutex;
			}
			if ( ! self::stamp_watermark( $seq ) ) {
				// Without a durable watermark an interrupted creation could
				// not be found; refuse before Elementor runs.
				delete_option( self::CREATING );
				Aura_Worker_Door_Log::settle(
					$seq,
					array(
						'result' => 'refused',
						'reason' => 'watermark_failed',
					)
				);
				self::$request = null;
				return new WP_Error( 'aura_log_failed', 'The door log could not record the creation watermark; it was not run.', array( 'status' => 503 ) );
			}
		}

		if ( 'warn' === $verdict['verdict'] ) {
			Aura_Worker_Rules::record_warn( $slug, $verdict['rule'] );
		}

		$result = is_callable( $inner ) ? call_user_func( $inner, $input ) : new WP_Error( 'ability_invalid_execute_callback', 'no callback' );
		do_action( 'sa_test_inner_ran', $slug ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- test seam only (ordering: snapshot before the write); no listener in production.

		$failed   = is_wp_error( $result ) || ( is_array( $result ) && 'error' === ( isset( $result['status'] ) ? $result['status'] : '' ) );
		$terminal = array( 'result' => $failed ? 'failed' : 'ok' );
		if ( $failed ) {
			$terminal['error'] = is_wp_error( $result ) ? $result->get_error_message() : 'ability reported an error';
		}
		if ( null !== $snapshot_id ) {
			$terminal['snapshot_id'] = $snapshot_id;
		}
		if ( $creating ) {
			$creation = self::finish_creation( $seq, $result, $failed ); // Task 7
			if ( is_wp_error( $creation ) ) {
				return $creation; // compensated + settled inside
			}
			$terminal = array_merge( $terminal, $creation );
		}
		if ( ! empty( self::$request['collateral'] ) ) {
			$terminal['collateral_snapshot_ids'] = self::$request['collateral'];
		}
		if ( null !== self::$replay_ack ) {
			Aura_Worker_Door_Holds::stamp_terminal_seq( self::$replay_ack['ref'], $seq );
		}
		Aura_Worker_Door_Log::settle( $seq, $terminal );
		self::$request = null;
		return $result;
	}

	/**
	 * Hold: store the call, log it, refuse the client with the ref.
	 *
	 * Memoised on the same key as the verdict: one call inside one request is
	 * ONE hold. An MCP client that re-sends the same call in the same request,
	 * or an ability re-entered through the second transport, must not leave
	 * the operator two approval entries for one write.
	 *
	 * @param string $slug    Ability.
	 * @param array  $input   Input.
	 * @param array  $touches Touches.
	 * @param array  $actor   Actor.
	 * @param array  $verdict Verdict.
	 * @return WP_Error
	 */
	private static function hold_call( $slug, array $input, array $touches, array $actor, array $verdict ) {
		$key = self::memo_key( $slug, $input );
		if ( isset( self::$held[ $key ] ) ) {
			return self::$held[ $key ];
		}
		$ref = Aura_Worker_Door_Holds::hold(
			array(
				'ability' => $slug,
				'input'   => $input,
				'touches' => $touches,
				'actor'   => $actor,
				'verdict' => $verdict['verdict'],
				'rule'    => null !== $verdict['rule'] ? self::rule_evidence( $verdict['rule'] ) : null,
			)
		);
		if ( is_wp_error( $ref ) ) {
			return $ref; // a queue that is full or busy is retryable; never memoised
		}
		if ( 'warn' === $verdict['verdict'] ) {
			Aura_Worker_Rules::record_warn( $slug, $verdict['rule'] );
		}
		self::record_terminal_only(
			$slug,
			$actor,
			$touches,
			'held',
			array(
				'ref'      => $ref,
				'verdict'  => $verdict['verdict'],
				'rule_key' => isset( $verdict['rule']['key'] ) ? $verdict['rule']['key'] : null,
			)
		);
		$refusal            = new WP_Error(
			'aura_held_for_approval',
			sprintf( 'This write is queued for approval in Aura (ref %s). Do not retry; it will run if approved.', $ref ),
			array(
				'status' => 409,
				'ref'    => $ref,
			)
		);
		self::$held[ $key ] = $refusal;
		return $refusal;
	}

	/**
	 * One terminal entry for a call that never ran (held / refused): the row
	 * is inserted, admitted and settled in one go so it is served.
	 *
	 * @param string $slug    Ability.
	 * @param array  $actor   Actor.
	 * @param array  $touches Touches.
	 * @param string $result  held|refused.
	 * @param array  $extra   Extra fields.
	 */
	private static function record_terminal_only( $slug, array $actor, array $touches, $result, array $extra ) {
		// The same admission as a write (Codex round-2 P2): a closed log takes
		// no row for a refusal either — it is counted, not stored — and a row
		// that lands above the bound is settled `discarded`. The refusal
		// itself already stands; what is lost is its record, which the
		// audit's `log_ungoverned_30d` counts.
		if ( Aura_Worker_Door_Log::is_closed() ) {
			Aura_Worker_Door_Log::bump_refused();
			self::bump_counter( 'log_ungoverned' );
			return;
		}
		$seq = Aura_Worker_Door_Log::open_pending(
			array_merge(
				array(
					'ability' => $slug,
					'actor'   => $actor,
					'touches' => $touches,
				),
				$extra
			)
		);
		if ( is_wp_error( $seq ) ) {
			self::bump_counter( 'log_ungoverned' );
			return;
		}
		if ( Aura_Worker_Door_Log::count_unacked() > Aura_Worker_Door_Log::MAX_UNACKED ) {
			Aura_Worker_Door_Log::discard( $seq );
			Aura_Worker_Door_Log::close();
			self::bump_counter( 'log_ungoverned' );
			return;
		}
		Aura_Worker_Door_Log::admit( $seq );
		Aura_Worker_Door_Log::settle( $seq, array_merge( array( 'result' => $result ), $extra ) );
	}

	/**
	 * Rolling 30-day counters in the Aura_Worker_Rules bucket style
	 * (`aura_worker_door_c_<name>_h<hour>`), read by governor_block() (Task 11).
	 *
	 * The statement, and the two cache evictions after it, are the rules
	 * counters' shape exactly (class-aura-worker-rules.php `bump()`): one
	 * atomic create-or-increment, then `notoptions` evicted because a reader
	 * that missed this bucket before its first bump listed the name in core's
	 * negative cache and would otherwise go on reading it as absent.
	 *
	 * @param string $name log_ungoverned|unobserved|hook_missed|unknown_ability.
	 */
	private static function bump_counter( $name ) {
		global $wpdb;
		$option = 'aura_worker_door_c_' . $name . '_h' . (int) floor( time() / HOUR_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no') ON DUPLICATE KEY UPDATE option_value = option_value + 1", $option ) );
		wp_cache_delete( $option, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/** @return WP_Error */
	private static function log_full_error() {
		return new WP_Error( 'aura_log_full', __( "Aura has not acknowledged this site's door log; the door is closed to writes until it does", 'digitizer-site-worker' ), array( 'status' => 503 ) );
	}

	/* ------------------------------------------------------------------ */
	/* Seams the later tasks fill                                          */
	/* ------------------------------------------------------------------ */

	/** @var array|null the request in flight: seq, slug, kind, created[], suspected[], collateral[] */
	private static $request = null;

	/**
	 * Capture the target before a write.
	 *
	 * One envelope per call, whatever the target's shape: a page or component
	 * is its own post + PAGE_META_KEYS; the design system is the kit post,
	 * EVERY `e_global_class` and EVERY `e_default_style` post, and the kit +
	 * class/style meta keys, in ONE `posts` envelope carrying `cpts` — so its
	 * restore also deletes a class or style the write ADDED (Ruling R3).
	 *
	 * @param string $slug    Ability.
	 * @param array  $touches Touches.
	 * @param array  $input   Input.
	 * @return array { success, snapshot?, error?, code?, creation? }
	 */
	private static function snapshot_for( $slug, array $touches, array $input ) {
		if ( null !== self::$snapshotter ) {
			return call_user_func( self::$snapshotter, $slug, $touches, $input );
		}
		$snaps = new Aura_Worker_Snapshots();
		$door  = array(
			'seq'     => isset( self::$request['seq'] ) ? self::$request['seq'] : null,
			'ability' => $slug,
		);
		switch ( isset( self::WRITE_TABLE[ $slug ] ) ? self::WRITE_TABLE[ $slug ] : '' ) {
			case 'page':
				return $snaps->snapshot_posts(
					array( (int) $touches[0]['id'] ),
					self::PAGE_META_KEYS,
					array( 'kind_label' => 'page', 'door' => $door )
				);
			case 'component':
				$id = isset( $input['id'] ) && is_numeric( $input['id'] ) ? (int) $input['id'] : 0;
				if ( $id > 0 && self::CPT_COMPONENT !== get_post_type( $id ) ) {
					// Never snapshot it as something else: an envelope that named
					// the wrong shape would restore the wrong thing.
					return array(
						'success' => false,
						'code'    => 'aura_target_unattributed',
						'error'   => 'not a component post: ' . $id,
					);
				}
				if ( $id > 0 ) {
					return $snaps->snapshot_posts(
						array( $id ),
						self::PAGE_META_KEYS,
						array( 'kind_label' => 'component', 'door' => $door )
					);
				}
				// A manage-component that names no id is CREATING one: nothing
				// exists to capture yet, so the creation path captures after.
				return array( 'success' => true, 'snapshot' => array( 'id' => null ), 'creation' => true );
			case 'design_system':
				$ids  = array_merge(
					array( self::kit_id() ),
					get_posts( array( 'post_type' => self::CPT_GLOBAL_CLASS, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ),
					get_posts( array( 'post_type' => self::CPT_DEFAULT_STYLE, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) )
				);
				$keys = array_merge(
					self::KIT_META_KEYS,
					array(
						'_elementor_global_class_id',
						'_elementor_global_class_data',
						'_elementor_global_class_data_preview',
						'_elementor_global_class_edited',
						'_elementor_default_style_tag',
						'_elementor_default_style_data',
						'_elementor_version',
					)
				);
				return $snaps->snapshot_posts(
					array_values( array_unique( array_map( 'intval', $ids ) ) ),
					$keys,
					array(
						'cpts'       => array( self::CPT_GLOBAL_CLASS, self::CPT_DEFAULT_STYLE ),
						'kind_label' => 'design_system',
						'door'       => $door,
					)
				);
		}
		return array( 'success' => false, 'error' => 'no snapshot kind for ' . $slug );
	}

	/**
	 * The active kit's post id — 0 when unknown, which makes the capture fail,
	 * which refuses the write. A design-system envelope without the kit is not
	 * a rollback point.
	 *
	 * @return int
	 */
	private static function kit_id() {
		if ( isset( $GLOBALS['_sa_kit_id'] ) ) {
			return (int) $GLOBALS['_sa_kit_id']; // test seam; never written by production code
		}
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->kits_manager ) ) {
			return (int) \Elementor\Plugin::$instance->kits_manager->get_active_id();
		}
		return 0;
	}

	/**
	 * Is this call CREATING its target? A `create-page` always is; a
	 * `manage-component` that names no id is too — both have nothing to
	 * capture before the write and take the creation path instead.
	 *
	 * @param string $slug  Ability.
	 * @param array  $input Input.
	 * @return bool
	 */
	private static function is_creating( $slug, array $input ) {
		$kind = isset( self::WRITE_TABLE[ $slug ] ) ? self::WRITE_TABLE[ $slug ] : null;
		if ( 'page_create' === $kind ) {
			return true;
		}
		return 'component' === $kind && ! ( isset( $input['id'] ) && is_numeric( $input['id'] ) && (int) $input['id'] > 0 );
	}

	/**
	 * The post types a creation may legitimately insert — the set both
	 * witnesses judge against: the insert hook files anything else under
	 * `other_inserts`, and the watermark diff never looks outside it.
	 *
	 * @param string $slug  Ability.
	 * @param array  $input Input.
	 * @return string[]
	 */
	private static function expected_types( $slug, array $input ) {
		if ( 'elementor/manage-component' === $slug ) {
			return array( self::CPT_COMPONENT );
		}
		$t = isset( $input['post_type'] ) && is_string( $input['post_type'] ) && '' !== $input['post_type'] ? sanitize_key( $input['post_type'] ) : 'page';
		return array( '' === $t ? 'page' : $t );
	}

	/* ------------------------------------------------------------------ */
	/* A restore is itself a governed write                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Before a door envelope is restored: capture the CURRENT state the same
	 * way, so the restore is itself a Change that can be undone.
	 *
	 * @param array $record The envelope being restored.
	 * @return array { success, snapshot?, error? }
	 */
	public static function pre_restore_capture( array $record ) {
		$snaps = new Aura_Worker_Snapshots();
		$door  = array( 'restore_of' => (string) ( isset( $record['id'] ) ? $record['id'] : '' ) );
		switch ( (string) ( isset( $record['door_kind'] ) ? $record['door_kind'] : '' ) ) {
			case 'design_system':
				// The CURRENT set, not the old envelope's targets: a class or
				// style added since would be deleted by the restore's set
				// semantics, and only a capture that enumerated it can bring it
				// back.
				return self::snapshot_for(
					'elementor/manage-classes',
					array( array( 'type' => 'design_system', 'id' => '*' ) ),
					array()
				);
			case 'page':
			case 'component':
			case 'creation_restore':
				$opts = array( 'kind_label' => (string) $record['door_kind'], 'door' => $door );
				if ( ! empty( $record['cpts'] ) ) {
					$opts['cpts'] = $record['cpts'];
				}
				// A record missing its targets captures nothing, and a capture of
				// nothing refuses the restore — which is the right answer for an
				// envelope that cannot say what it covered.
				return $snaps->snapshot_posts(
					(array) ( isset( $record['targets'] ) ? $record['targets'] : array() ),
					(array) ( isset( $record['keys'] ) ? $record['keys'] : array() ),
					$opts
				);
			case 'creation':
				// Undoing a creation trashes the created posts; capturing them
				// first is what lets the trash itself be undone.
				return $snaps->snapshot_posts(
					(array) ( isset( $record['created_post_ids'] ) ? $record['created_post_ids'] : array() ),
					self::PAGE_META_KEYS,
					array( 'kind_label' => 'creation_restore', 'door' => $door )
				);
		}
		return array( 'success' => true, 'snapshot' => null ); // not a door envelope: nothing to log
	}

	/**
	 * Reserve the door entry for a restore BEFORE anything is captured or
	 * written — the same admission every governed write gets. Logging after
	 * the fact would let a restore run unrecorded when the log was closed or
	 * the insert failed.
	 *
	 * @param array  $record   The envelope about to be restored.
	 * @param string $aura_ref Aura's correlation id for this restore.
	 * @return int|WP_Error seq, or aura_log_full / aura_log_failed.
	 */
	public static function open_restore_entry( array $record, $aura_ref = '' ) {
		if ( Aura_Worker_Door_Log::is_closed() ) {
			Aura_Worker_Door_Log::bump_refused();
			return self::log_full_error();
		}
		$actor = self::actor();
		$seq   = Aura_Worker_Door_Log::open_pending(
			array(
				'ability'    => 'aura/restore',
				'actor'      => is_wp_error( $actor ) ? array( 'user_id' => 0, 'login' => 'aura', 'via' => 'rest' ) : $actor,
				'touches'    => array(),
				'verdict'    => 'allow',
				'restore_of' => (string) ( isset( $record['id'] ) ? $record['id'] : '' ),
				// Aura's own correlation id for this restore (its AgentAction's
				// doorRef), echoed on the entry so ingestion patches THAT row
				// instead of minting a second one.
				'ref'        => '' === (string) $aura_ref ? null : preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $aura_ref ),
			)
		);
		if ( is_wp_error( $seq ) ) {
			return $seq;
		}
		// Admission: the row is the reservation. Count, back out above the bound.
		if ( Aura_Worker_Door_Log::count_unacked() > Aura_Worker_Door_Log::MAX_UNACKED ) {
			Aura_Worker_Door_Log::discard( $seq );
			Aura_Worker_Door_Log::close();
			Aura_Worker_Door_Log::bump_refused();
			return self::log_full_error();
		}
		if ( ! Aura_Worker_Door_Log::admit( $seq ) ) {
			return new WP_Error( 'aura_log_failed', 'The door log could not record this restore; it was not run.', array( 'status' => 503 ) );
		}
		return $seq;
	}

	/**
	 * Settle the reserved restore entry.
	 *
	 * @param int        $seq     The reserved entry.
	 * @param array|null $pre     Pre-restore envelope (or null).
	 * @param array      $outcome restore()'s result.
	 */
	public static function settle_restore_entry( $seq, $pre, array $outcome ) {
		Aura_Worker_Door_Log::settle(
			(int) $seq,
			array(
				'result'      => empty( $outcome['success'] ) ? 'failed' : 'ok',
				'snapshot_id' => is_array( $pre ) ? (string) $pre['id'] : null,
				'trashed'     => isset( $outcome['trashed'] ) ? $outcome['trashed'] : null,
				'error'       => isset( $outcome['error'] ) ? $outcome['error'] : null,
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Creation: the two witnesses, the mutex, and compensation            */
	/* ------------------------------------------------------------------ */

	/**
	 * WITNESS 1 — core's own insert hook, at priority 1.
	 *
	 * Every id is written to the pending row AS IT HAPPENS, one write per
	 * insert, before the callback that made it can return: a request that dies
	 * mid-write still leaves Aura the ids it has to undo. An `$update` is not a
	 * creation, and an insert outside the expected set is recorded apart, in
	 * `other_inserts` — never handed to a `creation` envelope, whose restore
	 * would trash it.
	 *
	 * @param int    $post_id Post id.
	 * @param object $post    Post.
	 * @param bool   $update  Whether this was an update.
	 */
	public static function observe_insert( $post_id, $post, $update ) {
		if ( null === self::$request || empty( self::$request['creating'] ) || $update ) {
			return;
		}
		$id   = (int) $post_id;
		$type = is_object( $post ) ? (string) ( $post->post_type ?? '' ) : (string) get_post_type( $id );
		$seq  = (int) self::$request['seq'];
		$row  = Aura_Worker_Door_Log::get( $seq );
		if ( null === $row ) {
			return;
		}
		if ( in_array( $type, (array) self::$request['expected'], true ) ) {
			self::$request['created'][] = $id;
			$ids                        = array_values( array_unique( array_merge( (array) ( $row['created_post_ids'] ?? array() ), array( $id ) ) ) );
			Aura_Worker_Door_Log::patch_pending( $seq, array( 'created_post_ids' => $ids ) );
		} else {
			$others = array_values( array_unique( array_merge( (array) ( $row['other_inserts'] ?? array() ), array( $id ) ) ) );
			Aura_Worker_Door_Log::patch_pending( $seq, array( 'other_inserts' => $others ) );
		}
	}

	/**
	 * Elementor deleting a global class REWRITES every page that used it, and
	 * those pages are not this call's target — they are its collateral. They
	 * are captured HERE, at priority 1, so the envelope exists (and its id is
	 * on the row) before Elementor's own handler at priority 10 touches them.
	 *
	 * A capture that fails THROWS: the governor's catch turns that into
	 * `aura_governor_error`, which refuses the whole call before the rewrite —
	 * the class posts themselves are still restorable from the design_system
	 * envelope taken before the write.
	 *
	 * @param array $deleted_class_ids Class ids removed.
	 * @param array $affected_post_ids Posts they were on.
	 * @throws RuntimeException When the capture, or the record of it, fails.
	 */
	public static function capture_class_cleanup( $deleted_class_ids, $affected_post_ids ) {
		if ( null === self::$request || 'design_system' !== ( self::$request['kind'] ?? '' ) ) {
			return;
		}
		$ids = array_values( array_filter( array_map( 'intval', (array) $affected_post_ids ) ) );
		if ( empty( $ids ) ) {
			return;
		}
		$snaps = new Aura_Worker_Snapshots();
		$env   = $snaps->snapshot_posts(
			$ids,
			array( '_elementor_data' ),
			array(
				'kind_label' => 'page',
				'door'       => array(
					'seq'           => self::$request['seq'],
					'collateral_of' => self::$request['seq'],
				),
			)
		);
		if ( empty( $env['success'] ) ) {
			throw new RuntimeException( esc_html( 'collateral capture failed: ' . (string) ( $env['error'] ?? '' ) ) ); // the wrapper's catch refuses the call before Elementor's cleanup runs
		}
		self::$request['collateral'][] = (string) $env['snapshot']['id'];
		// Durable BEFORE Elementor's cleanup handler (priority 10) rewrites the
		// pages: an interrupted request must still name the envelope that can
		// undo them.
		if ( ! Aura_Worker_Door_Log::patch_pending( (int) self::$request['seq'], array( 'collateral_snapshot_ids' => self::$request['collateral'] ) ) ) {
			throw new RuntimeException( 'collateral id could not be recorded' );
		}
	}

	/**
	 * One creation at a time per site — because the watermark diff cannot tell
	 * two concurrent creations' posts apart.
	 *
	 * Taken with insert_unique(), NOT add_option(): core's add_option() ends in
	 * an `INSERT … ON DUPLICATE KEY UPDATE`, which is an upsert, so both racers
	 * would "take" it (Ruling P5). The reconciler clears a mutex older than
	 * CLAIM_STALE_MS, which is what `started_at` is for.
	 *
	 * @param int $seq Log seq.
	 * @return true|WP_Error `aura_creation_busy` (503) when another creation holds it.
	 */
	private static function take_creation_mutex( $seq ) {
		$taken = Aura_Worker_Door_Log::insert_unique(
			self::CREATING,
			array(
				'seq'        => (int) $seq,
				'started_at' => gmdate( 'c' ),
			)
		);
		if ( ! $taken ) {
			return new WP_Error(
				'aura_creation_busy',
				'Another creation is in progress on this site; retry in 30 seconds.',
				array(
					'status'      => 503,
					'retry_after' => 30,
				)
			);
		}
		return true;
	}

	/**
	 * WITNESS 2 — the high-water mark of wp_posts before the write, so an
	 * insert core's hook never fired for can still be found afterwards.
	 *
	 * @param int $seq Log seq.
	 * @return bool the watermark is on the row; false ⇒ the caller refuses the
	 *              write: a creation whose watermark is not durable could not
	 *              be reconstructed by anyone.
	 */
	private static function stamp_watermark( $seq ) {
		global $wpdb;
		$max = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		self::$request['watermark'] = $max;
		return Aura_Worker_Door_Log::patch_pending(
			$seq,
			array(
				'post_watermark'   => $max,
				'started_at'       => gmdate( 'c' ),
				'created_post_ids' => array(),
				'expected_types'   => self::$request['expected'],
			)
		);
	}

	/**
	 * After the inner callback of a creating ability: union the two witnesses,
	 * store the `creation` envelope, and — when it cannot be stored —
	 * compensate, because the write already happened.
	 *
	 * @param int   $seq    Log seq.
	 * @param mixed $result Inner result.
	 * @param bool  $failed Whether the ability reported failure.
	 * @return array|WP_Error terminal fields, or aura_snapshot_failed (compensated).
	 */
	private static function finish_creation( $seq, $result, $failed ) {
		global $wpdb;
		$types    = (array) self::$request['expected'];
		$actor_id = (int) ( self::$request['actor_id'] ?? 0 );
		$in       = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// NO watermark means this request never reached the write — the mutex
		// or the stamp itself failed, and execute()'s catch still finishes the
		// creation. There is no mark to diff against, and treating that as
		// "everything above id 0" would attribute every page this user ever
		// made to the call — and then TRASH them if the envelope could not be
		// stored. A stamped 0 (an empty posts table) is a real mark and does
		// diff; only its ABSENCE means "nothing to compare".
		$diff   = array();
		if ( isset( self::$request['watermark'] ) ) {
			$mark = (int) self::$request['watermark'];
			$diff = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ($in) AND post_author = %d", array_merge( array( $mark ), $types, array( $actor_id ) ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}
		$hooked   = array_map( 'intval', (array) self::$request['created'] );
		$missed   = array_values( array_diff( $diff, $hooked ) );
		$created  = array_values( array_unique( array_merge( $hooked, $missed ) ) );
		$fields   = array( 'created_post_ids' => $created );
		if ( ! empty( $missed ) ) {
			$fields['observed_by_watermark'] = $missed;
			$fields['hook_missed']           = count( $missed );
			self::bump_counter( 'hook_missed' );
		}
		if ( empty( $created ) && ! $failed ) {
			self::bump_counter( 'unobserved' ); // a success that inserted nothing the witnesses could see
		}
		$named = is_array( $result ) && isset( $result['id'] ) && is_numeric( $result['id'] ) ? (int) $result['id'] : 0;
		if ( $named > 0 && ! in_array( $named, $created, true ) ) {
			$fields['unattributed_result'] = $named;
		}
		try {
			if ( empty( $created ) ) {
				return $fields; // nothing was inserted: nothing to undo; the mutex is released in the finally
			}
			// A callback that inserted and THEN reported failure still left
			// posts behind: they get an envelope like any creation, or are
			// compensated when the envelope cannot be stored — `$failed`
			// decides the entry's result, never whether the rollback exists.
			$snaps = new Aura_Worker_Snapshots();
			$env   = $snaps->snapshot_creation(
				$created,
				(string) $types[0],
				array(
					'seq'     => $seq,
					'ability' => self::$request['slug'],
				)
			);
			if ( empty( $env['success'] ) ) {
				// Compensate: the write happened and cannot be made restorable.
				// VERIFIED per id — wp_trash_post() can return false on a hook
				// or database failure, and a post that stayed live is reported
				// as uncompensated, never as undone.
				$how  = ( defined( 'EMPTY_TRASH_DAYS' ) && 0 === (int) EMPTY_TRASH_DAYS ) ? 'delete' : 'trash';
				$done = array();
				$left = array();
				foreach ( $created as $pid ) {
					wp_trash_post( $pid );
					$post = get_post( $pid );
					if ( ! $post || 'trash' === $post->post_status ) {
						$done[] = $pid;
					} else {
						$left[] = $pid;
					}
				}
				Aura_Worker_Door_Log::settle(
					$seq,
					array_merge(
						$fields,
						array(
							'result'         => 'failed',
							'reason'         => 'snapshot_failed',
							'compensated'    => $done,
							'uncompensated'  => $left,
							'compensated_by' => $how,
						)
					)
				);
				$msg = empty( $left )
					? 'Aura could not record a rollback for what this call created; the creation was undone. '
					: sprintf( 'Aura could not record a rollback for what this call created, and could not undo post(s) %s — check the site. ', implode( ', ', $left ) );
				return new WP_Error(
					'aura_snapshot_failed',
					$msg . (string) ( $env['error'] ?? '' ),
					array(
						'status'        => 503,
						'uncompensated' => $left,
					)
				);
			}
			$fields['snapshot_id'] = (string) $env['snapshot']['id'];
			return $fields;
		} finally {
			delete_option( self::CREATING );
		}
	}
}
