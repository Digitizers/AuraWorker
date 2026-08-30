<?php
/**
 * PHPUnit bootstrap for SiteAgent (digitizer-site-worker) unit tests.
 *
 * SiteAgent's classes are plain (global-namespace) PHP that lean on a small set
 * of WordPress functions. This bootstrap provides just enough WordPress surface
 * — configurable option/transient stores, a WP_Error, a WP_REST_Request stub,
 * and a real-filesystem WP_Filesystem shim — for the tool base, tool registry,
 * security layer, and rollback engine to run without a WordPress install.
 *
 * State the tests drive (reset in each test's setUp()):
 *   $GLOBALS['_options']       — get_option/update_option/delete_option store
 *   $GLOBALS['_transients']    — get/set/delete_transient store
 *   $GLOBALS['_caps']          — current_user_can control (null = allow all)
 *   $GLOBALS['_logged_in']     — is_user_logged_in() return
 *   $GLOBALS['_admins']        — get_users() administrator IDs
 *   $GLOBALS['_current_user']  — last wp_set_current_user() id
 *   $GLOBALS['_did_actions']   — do_action() call log
 *
 * @package Aura_Worker\Tests
 */

// ---------------------------------------------------------------------------
// Constants + paths
// ---------------------------------------------------------------------------

define( 'SA_TESTS_DIR', __DIR__ );
define( 'SA_PLUGIN_DIR', dirname( __DIR__ ) . '/digitizer-site-worker' );

// The raw site token sa_token_hash() installs the hash of. A request built by
// sa_token_request() presents THIS value in X-Aura-Token, so the security
// layer's hash_equals() against the stored digest succeeds — the two helpers
// have to agree on one string, and this is it.
define( 'SA_RAW_SITE_TOKEN', 'raw-site-token' );

if ( ! defined( 'ABSPATH' ) ) {
	// A fixture root, not the repo root: the only thing under it is
	// wp-admin/includes/*.php — trivial placeholders so the unconditional
	// require_once calls in class-aura-worker-updater.php (etc.) resolve
	// without a WordPress install. The real function/class definitions all
	// live in this file. Keeping them under tests/fixtures/ instead of the
	// repo root keeps them from reading as vendored WordPress core to anyone
	// browsing or scanning the plugin, and outside any packaging step's reach.
	define( 'ABSPATH', __DIR__ . '/fixtures/wp-root/' );
}

// Filesystem sandbox for the rollback engine. Kept under the system temp dir so
// tests never touch a real wp-content. Individual tests clean sub-paths.
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/sa-wp-content' );
}
if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );
}
if ( ! defined( 'FS_CHMOD_FILE' ) ) {
	define( 'FS_CHMOD_FILE', 0644 );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}
if ( ! defined( 'AURA_WORKER_VERSION' ) ) {
	// Aura_Worker_Updater::self_update() reads this for its "old_version" in
	// the result array. Only reached when a test drives self_update() all the
	// way through (normally the rule/grant guards stop it first).
	define( 'AURA_WORKER_VERSION', 'test' );
}

// ---------------------------------------------------------------------------
// Mutable state used by the stubs
// ---------------------------------------------------------------------------

$GLOBALS['_options']      = array();
$GLOBALS['_transients']   = array();
$GLOBALS['_caps']         = null;   // null = allow all (current_user_can).
$GLOBALS['_logged_in']    = false;
$GLOBALS['_admins']       = array();
$GLOBALS['_current_user'] = 0;
$GLOBALS['_did_actions']  = array();
$GLOBALS['_registered_settings'] = array();
$GLOBALS['_settings_fields']    = array();
$GLOBALS['_filters']      = array();
$GLOBALS['_abilities']    = array();
$GLOBALS['_ability_categories'] = array();
// Names the mutating stubs below (Plugin_Upgrader::upgrade(), wp_upgrade(), …)
// append themselves to. RulesRestCoverageTest's freeze sweep asserts this stays
// empty — a guarded handler that ran under a freeze would leave a mark here.
$GLOBALS['_mutations']    = array();

// ---------------------------------------------------------------------------
// WordPress function stubs
// ---------------------------------------------------------------------------

if ( ! function_exists( 'absint' ) ) {
	function absint( $maybeint ): int {
		return abs( (int) $maybeint );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		return htmlspecialchars( $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ): string {
		return trim( strip_tags( (string) $str ) );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $str ): string {
		return trim( strip_tags( (string) $str ) );
	}
}

// Post-meta store (for the SEO meta tools). Keyed [ postId ][ metaKey ] = value.
$GLOBALS['_post_meta'] = array();

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key = '', $single = false ) {
		$val = $GLOBALS['_post_meta'][ (int) $post_id ][ $key ] ?? '';
		return $single ? $val : ( '' === $val ? array() : array( $val ) );
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $key, $value, $prev = '' ) {
		$override = $GLOBALS['_sa_state']['update_post_meta_return'][ (int) $post_id ][ $key ] ?? true;
		if ( false === $override ) {
			return false;
		}
		$GLOBALS['_post_meta'][ (int) $post_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'metadata_exists' ) ) {
	function metadata_exists( $meta_type, $object_id, $meta_key ) {
		return isset( $GLOBALS['_post_meta'][ (int) $object_id ][ $meta_key ] );
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( $post_id, $key, $value = '' ) {
		$override = $GLOBALS['_sa_state']['delete_post_meta_return'][ (int) $post_id ][ $key ] ?? true;
		if ( false === $override ) {
			return false; // simulate a filter veto / DB failure: leave meta in place
		}
		unset( $GLOBALS['_post_meta'][ (int) $post_id ][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'wp_slash' ) ) {
	function wp_slash( $value ) {
		return $value;
	}
}

if ( ! function_exists( 'wp_is_post_revision' ) ) {
	function wp_is_post_revision( $post ) {
		$id = (int) ( is_object( $post ) ? ( $post->ID ?? 0 ) : $post );
		$p  = $GLOBALS['_posts'][ $id ] ?? null;
		if ( $p && ( $p->post_type ?? '' ) === 'revision' ) {
			return (int) ( $p->post_parent ?? 0 ) ?: true; // real WP returns parent id
		}
		return false;
	}
}

if ( ! function_exists( 'clean_post_cache' ) ) {
	function clean_post_cache( $post ) {
		$GLOBALS['_cleaned_post_cache'][] = (int) ( is_object( $post ) ? ( $post->ID ?? 0 ) : $post );
	}
}

$GLOBALS['_did_delete_expired'] = false;

if ( ! function_exists( 'delete_expired_transients' ) ) {
	function delete_expired_transients( $force_db = false ) {
		$GLOBALS['_did_delete_expired'] = true;
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ): string {
		return trim( (string) $url );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $string ): string {
		return rtrim( $string, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( string $file ): string {
		return trailingslashit( dirname( $file ) );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $options = 0, int $depth = 512 ) {
		$json = json_encode( $data, $options, $depth );
		return ( JSON_ERROR_NONE === json_last_error() ) ? $json : false;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

// --- Serialization (real WP core semantics; ruleset CAS compares raw bytes) --

if ( ! function_exists( 'is_serialized' ) ) {
	function is_serialized( $data, $strict = true ) {
		if ( ! is_string( $data ) ) {
			return false;
		}
		$data = trim( $data );
		if ( 'N;' === $data ) {
			return true;
		}
		if ( strlen( $data ) < 4 ) {
			return false;
		}
		if ( ':' !== $data[1] ) {
			return false;
		}
		if ( $strict ) {
			$lastc = substr( $data, -1 );
			if ( ';' !== $lastc && '}' !== $lastc ) {
				return false;
			}
		} else {
			$semicolon = strpos( $data, ';' );
			$brace     = strpos( $data, '}' );
			if ( false === $semicolon && false === $brace ) {
				return false;
			}
			if ( false !== $semicolon && $semicolon < 3 ) {
				return false;
			}
			if ( false !== $brace && $brace < 4 ) {
				return false;
			}
		}
		$token = $data[0];
		switch ( $token ) {
			case 's':
				if ( $strict && '"' !== substr( $data, -2, 1 ) ) {
					return false;
				} elseif ( false === strpos( $data, '"' ) ) {
					return false;
				}
				return true;
			case 'a':
			case 'O':
			case 'E':
				return (bool) preg_match( "/^{$token}:[0-9]+:/s", $data );
			case 'b':
			case 'i':
			case 'd':
				$end = $strict ? '$' : '';
				return (bool) preg_match( "/^{$token}:[0-9.E+-]+;$end/", $data );
		}
		return false;
	}
}

if ( ! function_exists( 'maybe_serialize' ) ) {
	function maybe_serialize( $data ) {
		if ( is_array( $data ) || is_object( $data ) ) {
			return serialize( $data );
		}
		if ( is_serialized( $data, false ) ) {
			return serialize( $data );
		}
		return $data;
	}
}

if ( ! function_exists( 'maybe_unserialize' ) ) {
	function maybe_unserialize( $data ) {
		if ( is_serialized( $data ) ) {
			return @unserialize( trim( (string) $data ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		return $data;
	}
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ) {
		$GLOBALS['_cache_deletes'][] = array(
			'key'   => $key,
			'group' => $group,
		);
		// The one cache entry this stub models: core's `notoptions` negative
		// cache (wp-includes/option.php). Evicting it forgets every miss.
		if ( 'notoptions' === $key && 'options' === $group ) {
			$GLOBALS['_notoptions'] = array();
		}
		return true;
	}
}

// --- Option store ----------------------------------------------------------

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default = false ) {
		// This request's option cache (2.10.2). WordPress caches every option
		// it reads for the rest of the request, and another request's
		// update_option() cannot invalidate that copy. A test populates this
		// to model a request that read a value BEFORE a connect replaced it —
		// exactly what accept()'s uncached reads must not be fooled by.
		if ( isset( $GLOBALS['_sa_option_cache'] ) && array_key_exists( $option, (array) $GLOBALS['_sa_option_cache'] ) ) {
			return $GLOBALS['_sa_option_cache'][ $option ];
		}
		// Core consults `notoptions` FIRST: a name listed there is answered
		// "absent" without looking at the cache or the database, and a miss
		// below lists the name. A raw $wpdb INSERT creates a row behind this
		// cache's back, so the code that issues one must evict `notoptions`
		// (wp_cache_delete( 'notoptions', 'options' )) or get_option() keeps
		// answering the default for a row that exists.
		if ( isset( $GLOBALS['_notoptions'][ $option ] ) ) {
			return $default;
		}
		if ( array_key_exists( $option, $GLOBALS['_options'] ) ) {
			return $GLOBALS['_options'][ $option ];
		}
		if ( array_key_exists( $option, $GLOBALS['_rows'] ) ) {
			// An ordinary cache miss: nothing decoded is cached for this key,
			// but the row is in the "database" ($_rows) — the ruleset store's
			// raw $wpdb INSERT/UPDATE never populates $_options (it bypasses
			// add_option()/update_option() on purpose), so this is the only
			// way its own writes are ever visible through get_option().
			return maybe_unserialize( $GLOBALS['_rows'][ $option ] );
		}
		$GLOBALS['_notoptions'][ $option ] = true;
		return $default;
	}
}

/**
 * A MySQL LIKE pattern, as a PCRE that matches the way MySQL would.
 *
 * The stub used to "apply" a LIKE by undoing esc_like()'s backslashes and
 * then prefix-matching the result literally, which quietly made every `_` in
 * a pattern a LITERAL underscore. Real LIKE does the opposite: an unescaped
 * `_` matches ANY single character. Production code that forgot to escape a
 * prefix therefore over-matched on a real site and matched exactly right
 * here, so no test could see it — the fake was the reason the bug was
 * invisible (#434 Task 10, Codex round-2 P2).
 *
 * @param string $like The LIKE pattern, already free of SQL-literal escaping.
 * @return string A PCRE anchored at both ends.
 */
function sa_like_to_regex( string $like ): string {
	$out = '';
	$len = strlen( $like );
	for ( $i = 0; $i < $len; $i++ ) {
		$c = $like[ $i ];
		if ( '\\' === $c && $i + 1 < $len ) {
			$out .= preg_quote( $like[ ++$i ], '/' ); // \_ and \% stand for themselves
			continue;
		}
		if ( '_' === $c ) {
			$out .= '.';
			continue;
		}
		if ( '%' === $c ) {
			$out .= '.*';
			continue;
		}
		$out .= preg_quote( $c, '/' );
	}
	return '/^' . $out . '$/s';
}

/**
 * Does the claim row named $claim exist with a value matching the LIKE pattern
 * the caller built from its fence? Mirrors MySQL's LIKE for the one shape the
 * plugin issues: an esc_like()'d prefix followed by '%'.
 */
function sa_claim_like_matches( string $claim, string $like ): bool {
	$held = sa_read_option_uncached( $claim );
	if ( ! is_string( $held ) ) {
		return false;
	}
	return 1 === preg_match( sa_like_to_regex( $like ), $held );
}

// --- Admin-screen escaping/nonce stubs (render_connect_section) -------------
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( string $text, string $domain = '' ): void {
		echo esc_html( $text );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ): string {
		return (string) $url;
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string {
		// Since WP 7.0 core's wp_generate_uuid4() draws from wp_rand(), which
		// IS pluggable and is loaded AFTER plugins — so a third party can pin
		// the uuid to a constant. $GLOBALS['_sa_uuid_fixed'] models exactly
		// that host, because a probe whose nonce cannot change is no probe.
		if ( isset( $GLOBALS['_sa_uuid_fixed'] ) ) {
			return (string) $GLOBALS['_sa_uuid_fixed'];
		}
		return sprintf( '%08x-%04x-4%03x-%04x-%012x', random_int( 0, 0xffffffff ), random_int( 0, 0xffff ), random_int( 0, 0x0fff ), random_int( 0, 0x3fff ) | 0x8000, random_int( 0, 0xffffffffffff ) );
	}
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = -1 ): string {
		return 'nonce-' . md5( (string) $action );
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, $value, $autoload = null ): bool {
		// The database refusing (or a filter short-circuiting) a write:
		// update_option() answers false and stores NOTHING. Code that must
		// prove a value landed cannot use the return value alone — it reads
		// the row back.
		if ( ! empty( $GLOBALS['_sa_option_write_fail'][ $option ] ) ) {
			// `true` fails every write; a positive INT fails that many writes
			// and then lets the option through — a transient database refusal,
			// which is what a recovery path is for.
			if ( is_int( $GLOBALS['_sa_option_write_fail'][ $option ] ) ) {
				--$GLOBALS['_sa_option_write_fail'][ $option ];
			}
			return false;
		}
		// Core sanitises before storing: update_option() calls sanitize_option(),
		// which applies the `sanitize_option_{$option}` filter that
		// register_setting() installs for a registered setting. Without this the
		// harness stores whatever a caller passes, so a filter that rejects or
		// rewrites the value — the exact shape of the bug in #67, where a
		// read-only guard silently froze the site token — is invisible to tests.
		$value = apply_filters( "sanitize_option_{$option}", $value, $option, $value );
		unset( $GLOBALS['_notoptions'][ $option ] );
		$GLOBALS['_options'][ $option ] = $value;
		$GLOBALS['_rows'][ $option ]    = maybe_serialize( $value );
		// Core keeps the existing autoload flag when none is passed — only
		// record one when the caller actually supplied it.
		if ( null !== $autoload ) {
			$GLOBALS['_rows_autoload'][ $option ] = $autoload;
		}
		// Witness every write, unconditionally. An option name is caller-chosen
		// data on the restore_snapshot path (create_snapshot accepts any
		// `target`), so excluding an `aura_worker_*` prefix would blind the
		// witness to exactly the case it exists for: a snapshot taken over the
		// plugin's own state (e.g. the site token) and restored under a freeze.
		// No guarded handler writes an `aura_worker_*` option on any path this
		// suite exercises, so nothing needs the exclusion — confirmed by running
		// the full suite with it removed.
		$GLOBALS['_mutations'][] = 'update_option:' . $option;
		// Which options a code path writes, in order — appended, never reset
		// here, so a test that empties it sees exactly its own call's writes.
		$GLOBALS['_option_writes'][] = array( 'set', $option );
		return true;
	}
}

/**
 * admin-ajax surface used by the token regeneration handler (#67).
 *
 * Core's wp_send_json_*() emit and then exit; the handler's code after the call
 * must not run. A test needs to observe both the payload and that the request
 * ended there, so these throw a dedicated exception the test catches — an exit
 * a test can assert on, rather than one that would kill the runner.
 */
/**
 * Settings API, modelled on core closely enough to matter.
 *
 * register_setting() does two things that shape behaviour: it adds the option
 * to the group's allow-list (options.php saves nothing outside it) and, when a
 * sanitize_callback is supplied, installs it as a `sanitize_option_{$option}`
 * filter — which update_option() then applies on EVERY write, from any caller.
 * That second effect is the whole of #67, so the stub must reproduce it.
 */
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return (int) ( $GLOBALS['_current_user_id'] ?? 0 );
	}
}

if ( ! function_exists( 'register_setting' ) ) {
	function register_setting( string $group, string $option, $args = array() ): void {
		$GLOBALS['_registered_settings'][ $group ][] = $option;
		if ( is_array( $args ) && ! empty( $args['sanitize_callback'] ) ) {
			add_filter( "sanitize_option_{$option}", $args['sanitize_callback'] );
		}
	}
}

if ( ! function_exists( 'add_settings_section' ) ) {
	function add_settings_section( $id, $title, $callback, $page, $args = array() ): void {}
}

if ( ! function_exists( 'add_settings_field' ) ) {
	function add_settings_field( $id, $title, $callback, $page, $section = 'default', $args = array() ): void {
		$GLOBALS['_settings_fields'][ $page ][] = $id;
	}
}

if ( ! class_exists( 'SA_Json_Response' ) ) {
	final class SA_Json_Response extends RuntimeException {
		public bool $success;
		public $data;
		public ?int $status;
		public function __construct( bool $success, $data, ?int $status = null ) {
			parent::__construct( $success ? 'json_success' : 'json_error' );
			$this->success = $success;
			$this->data    = $data;
			$this->status  = $status;
		}
	}
}

if ( ! function_exists( 'wp_send_json_success' ) ) {
	function wp_send_json_success( $data = null, ?int $status_code = null ): void {
		throw new SA_Json_Response( true, $data, $status_code );
	}
}

if ( ! function_exists( 'wp_send_json_error' ) ) {
	function wp_send_json_error( $data = null, ?int $status_code = null ): void {
		throw new SA_Json_Response( false, $data, $status_code );
	}
}

if ( ! function_exists( 'check_ajax_referer' ) ) {
	// Nonce verification is not what these tests exercise; a test that needs a
	// failing referer sets $GLOBALS['_sa_ajax_referer_fails'].
	function check_ajax_referer( $action = -1, $query_arg = false, $stop = true ) {
		if ( ! empty( $GLOBALS['_sa_ajax_referer_fails'] ) ) {
			throw new SA_Json_Response( false, array( 'message' => 'bad nonce' ), 403 );
		}
		return 1;
	}
}

if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( int $length = 12, bool $special_chars = true, bool $extra_special_chars = false ): string {
		$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		if ( $special_chars ) {
			$chars .= '!@#$%^&*()';
		}
		$out = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$out .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
		}
		return $out;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	// Atomic in core (INSERT guarded by option_name's unique index): fails when
	// the option already exists. The verifier relies on this for single-use
	// nonce reservation, so the stub mirrors that fail-if-exists semantics.
	// The ruleset store does NOT use add_option() for its own writes (a real
	// conditional INSERT through $wpdb replaces it — add_option() skips its
	// existence check whenever `notoptions` lists the key, and runs
	// INSERT ... ON DUPLICATE KEY UPDATE instead, which would clobber a
	// winning racer's row), so this stub carries no ruleset-specific seams.
	function add_option( string $option, $value = '', $deprecated = '', $autoload = 'yes' ): bool {
		if ( array_key_exists( $option, $GLOBALS['_options'] ) ) {
			return false;
		}
		// …but core's check and its write are TWO statements, and the write is
		// `INSERT … ON DUPLICATE KEY UPDATE` (option.php): a second caller that
		// passed the same check in between also "succeeds", and its value
		// overwrites the first one's. Anything that needs a real mutex must use
		// a conditional INSERT instead — this seam is how a test shows the
		// difference (inert when unset).
		sa_before_swap();
		unset( $GLOBALS['_notoptions'][ $option ] );
		$GLOBALS['_options'][ $option ]       = $value;
		$GLOBALS['_rows'][ $option ]          = maybe_serialize( $value );
		$GLOBALS['_rows_autoload'][ $option ] = $autoload;
		return true;
	}
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( int $timestamp, string $hook, array $args = array() ) {
		$GLOBALS['_scheduled'][] = array( 'ts' => $timestamp, 'hook' => $hook, 'args' => $args );
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $option ): bool {
		unset( $GLOBALS['_options'][ $option ] );
		unset( $GLOBALS['_rows'][ $option ] );
		unset( $GLOBALS['_rows_autoload'][ $option ] );
		// Core lists a deleted name in `notoptions` (option.php, delete_option()).
		$GLOBALS['_notoptions'][ $option ] = true;
		$GLOBALS['_option_writes'][] = array( 'delete', $option );
		return true;
	}
}

/**
 * One options-table row, read the way $wpdb reads it: the DATABASE, never this
 * request's option cache ($GLOBALS['_sa_option_cache'], which get_option()
 * serves from). $_rows holds the raw serialized bytes the ruleset CAS writes;
 * a test that seeds $_options directly is seeding the database too, so that is
 * the fallback — serialized, because callers maybe_unserialize() what they get.
 *
 * @param string $name Option name.
 * @return string|null Raw value, or null when there is no row.
 */
function sa_read_option_uncached( string $name ) {
	if ( array_key_exists( $name, $GLOBALS['_rows'] ) ) {
		return $GLOBALS['_rows'][ $name ];
	}
	if ( array_key_exists( $name, $GLOBALS['_options'] ) ) {
		return maybe_serialize( $GLOBALS['_options'][ $name ] );
	}
	return null;
}

/**
 * Base64url-encode, no padding — the envelope-segment encoding every signed
 * document (grant, ruleset) uses.
 *
 * @param string $s Raw bytes.
 * @return string
 */
function sa_b64url( string $s ): string {
	return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
}

/**
 * Install a fresh Ed25519 gateway keypair the way a real connect would:
 * the public half into `aura_worker_grant_pubkey` (what the site verifies
 * signed documents against), the secret half stashed as sa_sign_ruleset()'s
 * default so a test doesn't have to thread it through every call. Callers
 * that need a second, untrusted key (e.g. "signed by someone else") still
 * get one back to pass explicitly.
 *
 * Requires ext-sodium; callers check function_exists('sodium_crypto_sign_keypair')
 * and skip themselves the way RulesetStoreTest does — this helper does not
 * skip on their behalf.
 *
 * @return string The raw secret key.
 */
function sa_install_gateway_key(): string {
	$keypair = sodium_crypto_sign_keypair();
	$secret  = sodium_crypto_sign_secretkey( $keypair );
	$GLOBALS['_options']['aura_worker_grant_pubkey'] = base64_encode( sodium_crypto_sign_publickey( $keypair ) );
	$GLOBALS['_sa_gateway_secret'] = $secret;
	return $secret;
}

/**
 * Sign an arbitrary payload the way Aura signs a ruleset (or grant)
 * envelope: JSON, detached Ed25519 signature, both segments base64url,
 * joined with a dot. Defaults to the secret sa_install_gateway_key() last
 * installed.
 *
 * @param array       $payload Document to sign.
 * @param string|null $secret  Secret key, or null to use the installed one.
 * @return string
 */
function sa_sign_ruleset( array $payload, ?string $secret = null ): string {
	$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	$sig  = sodium_crypto_sign_detached( $json, $secret ?? ( $GLOBALS['_sa_gateway_secret'] ?? '' ) );
	return sa_b64url( $json ) . '.' . sa_b64url( $sig );
}

/**
 * This site's own token hash — the value a ruleset's `site` field must
 * match. Installs a fixed raw token's hash under `aura_worker_site_token`
 * the first time it's asked for, so repeated calls in one test agree.
 *
 * @return string
 */
function sa_token_hash(): string {
	if ( empty( $GLOBALS['_options']['aura_worker_site_token'] ) ) {
		$GLOBALS['_options']['aura_worker_site_token'] = hash( 'sha256', SA_RAW_SITE_TOKEN );
	}
	return (string) $GLOBALS['_options']['aura_worker_site_token'];
}

/**
 * Seed the unbind marker (#434) straight into the "database", the way a
 * completed Phase A leaves it — so a test can start from an already-unbound
 * site without driving accept() there first. Only the fields a test cares
 * about need naming; the rest take the shape Phase A writes.
 *
 * Written to $GLOBALS['_options'], which sa_read_option_uncached() serves as
 * the raw row: Aura_Worker_Unbind::read() goes round the option cache, so a
 * marker seeded anywhere else would read as absent.
 *
 * @param array $over Fields to override.
 * @return array The seeded marker.
 */
function sa_set_marker( array $over = array() ): array {
	$marker = array_merge(
		array(
			'at'                 => '2026-08-29T10:00:00Z',
			'site'               => sa_token_hash(),
			'site_ref'           => 'r1',
			'client'             => 'c1',
			'seq'                => 9,
			'connect_user_id'    => 3,
			'app_password_uuids' => array(),
			'app_password_users' => array(),
		),
		$over
	);
	$GLOBALS['_options'][ Aura_Worker_Unbind::OPTION ] = $marker;
	unset( $GLOBALS['_rows'][ Aura_Worker_Unbind::OPTION ], $GLOBALS['_notoptions'][ Aura_Worker_Unbind::OPTION ] );
	return $marker;
}

/**
 * Seed the Application Password a connect would have minted: the plugin's own
 * bookkeeping row (aura_worker_app_password = { user_id, uuid }) AND the
 * WP_Application_Passwords stub entry, so both the marker copy (Phase A) and
 * the revoke (Phase B) see the same password.
 *
 * @param int    $user_id Owning administrator.
 * @param string $uuid    The password's uuid.
 * @return void
 */
function sa_set_managed_app_password( int $user_id, string $uuid ): void {
	$GLOBALS['_options'][ Aura_Worker_Magic_Link::APP_PASSWORD_RECORD_OPTION ] = array(
		'user_id' => $user_id,
		'uuid'    => $uuid,
	);
	$GLOBALS['_app_passwords'][ $user_id ][] = array(
		'uuid'    => $uuid,
		'name'    => Aura_Worker_Magic_Link::APP_PASSWORD_NAME,
		'created' => time(),
	);
}

/**
 * Clear the unbind marker from the "database" — the state a site that was
 * never unbound (or one Task 7 has rebound) is in.
 *
 * @return void
 */
function sa_clear_marker(): void {
	unset(
		$GLOBALS['_options'][ Aura_Worker_Unbind::OPTION ],
		$GLOBALS['_rows'][ Aura_Worker_Unbind::OPTION ],
		$GLOBALS['_rows_autoload'][ Aura_Worker_Unbind::OPTION ]
	);
	$GLOBALS['_notoptions'][ Aura_Worker_Unbind::OPTION ] = true;
}

/**
 * Add ONE Application Password to a user's list without touching the plugin's
 * own bookkeeping row — the shape of a password SiteAgent never minted: a
 * manually connected site's, one Aura's PATCH installed, or an unrelated
 * credential the site owner created (which Phase B must leave alone).
 *
 * @param int    $user_id Owner.
 * @param string $uuid    The password's uuid.
 * @return void
 */
function sa_add_app_password( int $user_id, string $uuid ): void {
	$GLOBALS['_app_passwords'][ $user_id ][] = array(
		'uuid'    => $uuid,
		'name'    => 'Manual',
		'created' => time(),
	);
}

/**
 * Is that Application Password still on the user's list? The same proof
 * Aura_Worker_Magic_Link::password_gone() uses, from the test's side.
 *
 * @param int    $user_id Owner.
 * @param string $uuid    The password's uuid.
 * @return bool
 */
function sa_app_password_exists( int $user_id, string $uuid ): bool {
	foreach ( $GLOBALS['_app_passwords'][ $user_id ] ?? array() as $item ) {
		if ( isset( $item['uuid'] ) && $uuid === (string) $item['uuid'] ) {
			return true;
		}
	}
	return false;
}

/**
 * Capture what a renderer echoes (#434 Task 9's settings panel).
 *
 * The buffer is closed on EVERY exit, a throwing body included — a fixture
 * that leaks an output buffer takes the rest of the run's output with it, and
 * PHPUnit's own report along with it.
 *
 * @param callable $body The renderer.
 * @return string Everything it echoed.
 */
function sa_capture( callable $body ): string {
	ob_start();
	try {
		$body();
	} catch ( Throwable $e ) {
		ob_end_clean();
		throw $e;
	}
	return (string) ob_get_clean();
}

/**
 * Dispatch an admin-ajax action the way admin-ajax.php does (#434 Task 9):
 * through the hook the PLUGIN registered, as the given user.
 *
 * Going through `wp_ajax_{$action}` rather than calling the handler is the
 * point — a handler nobody registered, or registered under another name, is a
 * handler no button can reach, and this helper fails loudly on it.
 *
 * The capability follows the site's own idea of who administers it
 * ($GLOBALS['_admins'], which is what user_can() reads), so a test names its
 * administrators once and every check in the request agrees with it.
 *
 * @param string $action  The action, without the `wp_ajax_` prefix.
 * @param int    $user_id The user making the request.
 * @return array{success:bool,data:mixed,status:?int}
 */
function sa_admin_ajax_call( string $action, int $user_id ): array {
	if ( empty( $GLOBALS['_filters'][ 'wp_ajax_' . $action ] ) ) {
		// admin-ajax.php is an admin request, which is the branch the plugin
		// registers these handlers in. Registered once per test: init() adds
		// listeners, and a second registration would count one probe twice.
		$GLOBALS['_is_admin'] = true;
		( new Aura_Worker() )->init();
	}
	$GLOBALS['_current_user_id'] = $user_id;
	$GLOBALS['_current_user']    = $user_id;
	$GLOBALS['_caps']            = in_array( $user_id, array_map( 'intval', $GLOBALS['_admins'] ), true )
		? array( 'manage_options', 'read' )
		: array( 'read' );
	try {
		do_action( 'wp_ajax_' . $action );
	} catch ( SA_Json_Response $res ) {
		return array(
			'success' => $res->success,
			'data'    => $res->data,
			'status'  => $res->status,
		);
	}
	throw new RuntimeException( 'nothing answered wp_ajax_' . $action . ' — is the handler registered?' );
}

/**
 * The seam that runs immediately after ONE uncached option read has been
 * answered — the window in which a concurrent request rewrites the same row.
 * A test sets $GLOBALS['_sa_after_option_read'] to a callable taking the
 * option name; clearing it is the callable's own business, because "fires
 * once" and "fires on the second read" are both cases worth modelling.
 * Inert when unset.
 *
 * @param string $name The option just read.
 */
function sa_after_option_read( string $name ): void {
	if ( isset( $GLOBALS['_sa_after_option_read'] ) && is_callable( $GLOBALS['_sa_after_option_read'] ) ) {
		call_user_func( $GLOBALS['_sa_after_option_read'], $name );
	}
}

/**
 * The seam that runs between a caller's read and its compare-and-swap — the
 * window in which a concurrent connect writes its binding. A test sets
 * $GLOBALS['_sa_before_swap'] to a callable (which clears itself, so it fires
 * once). Inert when unset.
 */
function sa_before_swap(): void {
	if ( isset( $GLOBALS['_sa_before_swap'] ) && is_callable( $GLOBALS['_sa_before_swap'] ) ) {
		call_user_func( $GLOBALS['_sa_before_swap'] );
	}
}

// --- Transient store --------------------------------------------------------

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ) {
		return array_key_exists( $key, $GLOBALS['_transients'] ) ? $GLOBALS['_transients'][ $key ] : false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, $value, int $expiration = 0 ): bool {
		$GLOBALS['_transients'][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $key ): bool {
		unset( $GLOBALS['_transients'][ $key ] );
		// Core deletes the transient's two OPTION ROWS as well (option.php), and
		// on a site with no persistent object cache those rows are where the
		// transient actually lives. Modelled because uninstall.php's prefix
		// sweep finds a transient as a `_transient_…` row and hands the name
		// back to delete_transient() to remove — if that left the row behind,
		// the sweep would look like it worked and the value would survive.
		foreach ( array( '_transient_' . $key, '_transient_timeout_' . $key ) as $row ) {
			unset( $GLOBALS['_options'][ $row ], $GLOBALS['_rows'][ $row ], $GLOBALS['_rows_autoload'][ $row ] );
		}
		return true;
	}
}

// --- Auth / capabilities ----------------------------------------------------

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap, ...$args ): bool {
		if ( null === $GLOBALS['_caps'] ) {
			return true;
		}
		return in_array( $cap, (array) $GLOBALS['_caps'], true );
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		return (bool) $GLOBALS['_logged_in'];
	}
}

if ( ! function_exists( 'wp_set_current_user' ) ) {
	function wp_set_current_user( int $id, string $name = '' ) {
		$GLOBALS['_current_user'] = $id;
		return $id;
	}
}

if ( ! function_exists( 'user_can' ) ) {
	function user_can( $user, string $cap, ...$args ): bool {
		// Administrators in $GLOBALS['_admins'] hold every capability.
		return in_array( (int) $user, array_map( 'intval', $GLOBALS['_admins'] ), true );
	}
}

// --- WP_User ----------------------------------------------------------------
// Minimal stand-in for core's user object. It exists so the
// `application_password_did_authenticate` hook can be fired with the SHAPE
// WordPress fires it with: the capture only accepts a WP_User with a positive
// ID as an identity (#434 Task 4, C4), and a stdClass must NOT satisfy it.
if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public $ID = 0;

		public function __construct( $id = 0 ) {
			$this->ID = (int) $id;
		}
	}
}

// --- Application Passwords (2.11.0: the /connect callback mints one) --------
// $GLOBALS['_app_passwords'][user_id] = list of items { uuid, name, created };
// $GLOBALS['_app_passwords_available'] gates wp_is_application_passwords_available_for_user().
$GLOBALS['_app_passwords']           = array();
$GLOBALS['_app_passwords_available'] = true;
$GLOBALS['_app_passwords_delete_fail'] = false;
$GLOBALS['_fail_delete_app_password']  = null; // ONE uuid whose delete fails (#434 Task 4).
$GLOBALS['_sa_app_password_read_fail'] = array(); // user_id => true: that user's app-password meta row cannot be read (#434 I5).
$GLOBALS['_sa_app_password_scan_fail']  = false; // the site-wide holder statement itself fails (#434 Task 9).
$GLOBALS['_sa_app_password_scan_answer'] = null; // replaces that statement's result SET outright — an array of rows (#434 Task 9).
$GLOBALS['_sa_app_password_scan_rewrite_probe'] = null; // stamps the OWNER rows of that answer with a foreign nonce (#434 Task 10).
$GLOBALS['_unbind_trace']              = array(); // Phase B's step trace; sa_reset_state() re-arms the recorder.
if ( ! function_exists( 'wp_is_application_passwords_available_for_user' ) ) {
	function wp_is_application_passwords_available_for_user( $user ): bool {
		return (bool) $GLOBALS['_app_passwords_available'];
	}
}
if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( int $user_id ) {
		if ( $user_id <= 0 ) {
			return false;
		}
		return (object) array( 'ID' => $user_id, 'user_login' => 'user' . $user_id );
	}
}
if ( ! class_exists( 'WP_Application_Passwords' ) ) {
	class WP_Application_Passwords {
		public static function create_new_application_password( int $user_id, array $args = array() ) {
			if ( ! empty( $GLOBALS['_sa_app_password_create_fails'] ) ) {
				// Core's own failure mode: the user-meta write did not land.
				return new WP_Error( 'db_error', 'Could not save application password.' );
			}
			// Witness for the connect tests: was the site-wide claim still held at mint time?
			$GLOBALS['_sa_site_claim_during_mint'] = get_option( 'aura_worker_connect_lock', false );
			// A test can model losing the site to another install while this
			// mint runs (round-8): the claim vanishes mid-handler.
			if ( ! empty( $GLOBALS['_sa_steal_site_claim_during_mint'] ) ) {
				unset( $GLOBALS['_options']['aura_worker_connect_lock'], $GLOBALS['_rows']['aura_worker_connect_lock'] );
			}
			$item = array( 'uuid' => 'uuid-' . bin2hex( random_bytes( 4 ) ), 'app_id' => (string) ( $args['app_id'] ?? '' ), 'name' => (string) ( $args['name'] ?? '' ), 'created' => time() );
			$GLOBALS['_app_passwords'][ $user_id ][] = $item;
			return array( 'pw-' . bin2hex( random_bytes( 8 ) ), $item );
		}
		public static function get_user_application_passwords( int $user_id ): array {
			// Core maps a user-meta read it could not complete to array(),
			// indistinguishable from "this user has none" — the whole of #434
			// Task 4's I5. The seam models THAT, not a magic error return:
			// with the row unreadable this answers empty, exactly as core
			// would, and only the confirming raw read can tell the difference.
			if ( ! empty( $GLOBALS['_sa_app_password_read_fail'][ $user_id ] ) ) {
				return array();
			}
			return $GLOBALS['_app_passwords'][ $user_id ] ?? array();
		}
		public static function delete_application_password( int $user_id, string $uuid ) {
			if ( ! empty( $GLOBALS['_app_passwords_delete_fail'] ) ) {
				return new WP_Error( 'db_update_error', 'user meta write failed' );
			}
			// One password whose delete fails, unlike the switch above which
			// fails every one: Phase B walks a LIST, and a test that must prove
			// the walk survives a single refusal (and that the token is never
			// reached) needs the others to go through (#434 Task 4).
			if ( isset( $GLOBALS['_fail_delete_app_password'] ) && $uuid === (string) $GLOBALS['_fail_delete_app_password'] ) {
				return new WP_Error( 'db_update_error', 'user meta write failed' );
			}
			$before = count( $GLOBALS['_app_passwords'][ $user_id ] ?? array() );
			$GLOBALS['_app_passwords'][ $user_id ] = array_values( array_filter( $GLOBALS['_app_passwords'][ $user_id ] ?? array(), static fn( $i ) => $i['uuid'] !== $uuid ) );
			return count( $GLOBALS['_app_passwords'][ $user_id ] ) < $before ? true : new WP_Error( 'application_password_not_found', 'not found' );
		}
	}
}

if ( ! function_exists( 'get_users' ) ) {
	function get_users( array $args = array() ): array {
		return $GLOBALS['_admins'];
	}
}

// --- Hooks ------------------------------------------------------------------

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $tag, ...$args ): void {
		$GLOBALS['_did_actions'][] = array( 'tag' => $tag, 'args' => $args );
		// Mirrors apply_filters() below: a listener registered via add_action()
		// must actually run (Aura_Worker_Rules::record_block()/record_warn()
		// bump the audit counters this way), not merely be logged here.
		$hooks = array();
		foreach ( $GLOBALS['_filters'][ $tag ] ?? array() as $i => $entry ) {
			if ( is_array( $entry ) && array_key_exists( 'callback', $entry ) ) {
				$hooks[] = $entry;
			} else {
				$hooks[] = array( 'priority' => 10, 'seq' => $i, 'callback' => $entry );
			}
		}
		usort(
			$hooks,
			static function ( $a, $b ) {
				return $a['priority'] <=> $b['priority'] ?: $a['seq'] <=> $b['seq'];
			}
		);
		foreach ( $hooks as $hook ) {
			// WordPress passes each listener only as many arguments as its
			// registration declared. A bare callable pushed straight onto
			// $GLOBALS['_filters'] declared nothing, so it keeps them all.
			$pass = isset( $hook['accepted_args'] ) ? array_slice( $args, 0, (int) $hook['accepted_args'] ) : $args;
			( $hook['callback'] )( ...$pass );
		}
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	// Aura_Worker::init() branches on this to register the settings screen and
	// the admin-ajax handlers. Defaults to false — a REST request, which is
	// what every test here models.
	function is_admin(): bool {
		return (bool) ( $GLOBALS['_is_admin'] ?? false );
	}
}

if ( ! function_exists( 'has_action' ) ) {
	// Mirrors add_action's store ($_filters) so a hook registered through the
	// normal API is visible here, matching production.
	function has_action( $tag, $callback = false ) {
		return ! empty( $GLOBALS['_filters'][ $tag ] );
	}
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( string $name, array $args ): bool {
		$GLOBALS['_abilities'][ $name ] = $args;
		return true;
	}
}

if ( ! function_exists( 'wp_register_ability_category' ) ) {
	function wp_register_ability_category( string $slug, array $args ): bool {
		$GLOBALS['_ability_categories'][ $slug ] = $args;
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $tag, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		// Wrapped with priority + insertion order, so apply_filters() can run
		// hooks in the order WordPress actually does: two callbacks on the
		// same tag (e.g. open_frame() at priority 1, guard_core_any() at
		// priority 5) must run lowest-priority-first regardless of which
		// add_filter() call happened to run last.
		$GLOBALS['_filters'][ $tag ][] = array(
			'priority'      => $priority,
			'seq'           => count( $GLOBALS['_filters'][ $tag ] ?? array() ),
			'callback'      => $callback,
			// Recorded so do_action() can slice the arguments the way WordPress
			// does, and so a test can assert the arity a registration declared:
			// add_action( $tag, $cb, 10, 1 ) on a two-argument hook silently
			// drops the second argument in production, and a stub that always
			// passed both would hide exactly that bug.
			'accepted_args' => $accepted_args,
		);
		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $tag, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		return add_filter( $tag, $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $tag, $value, ...$args ) {
		$hooks = array();
		foreach ( $GLOBALS['_filters'][ $tag ] ?? array() as $i => $entry ) {
			// A test may push a bare callable straight onto $GLOBALS['_filters']
			// (bypassing add_filter()), so normalise both shapes rather than
			// assuming every entry carries a priority wrapper.
			if ( is_array( $entry ) && array_key_exists( 'callback', $entry ) ) {
				$hooks[] = $entry;
			} else {
				$hooks[] = array( 'priority' => 10, 'seq' => $i, 'callback' => $entry );
			}
		}
		usort(
			$hooks,
			static function ( $a, $b ) {
				return $a['priority'] <=> $b['priority'] ?: $a['seq'] <=> $b['seq'];
			}
		);
		foreach ( $hooks as $hook ) {
			// Same arity rule as do_action(): a filter receives $value plus
			// accepted_args - 1 of the extra arguments, so a listener declared
			// `10, 1` never sees the request/handler arguments a `10, 3`
			// registration would. Half-applying this (actions only) would leave
			// the core-REST seam's filters — rest_request_before_callbacks and
			// friends, registered at 5, 3 — with an invisible arity regression
			// (#434 Task 3 re-review M7).
			$extra = isset( $hook['accepted_args'] ) ? array_slice( $args, 0, max( 0, (int) $hook['accepted_args'] - 1 ) ) : $args;
			$value = ( $hook['callback'] )( $value, ...$extra );
		}
		return $value;
	}
}

// --- Filesystem (used by the rollback engine) -------------------------------

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( string $dir ): bool {
		return is_dir( $dir ) || mkdir( $dir, 0777, true );
	}
}

if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( string $file ): bool {
		$existed = file_exists( $file );
		$ok      = @unlink( $file );
		if ( $existed && $ok ) {
			$GLOBALS['_mutations'][] = 'wp_delete_file';
		}
		return $ok;
	}
}

if ( ! function_exists( 'WP_Filesystem' ) ) {
	function WP_Filesystem() {
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			$wp_filesystem = new SA_Test_Filesystem();
		}
		return true;
	}
}

// --- Update/upgrade surface -------------------------------------------------
//
// The nine direct REST handlers in class-aura-worker-api.php (Task 6) reach
// this rather than execute_tool(). In the passing suite every one of them is
// exercised under a matching rule (block or warn) except update_plugin and
// create_snapshot, whose "not this plugin" / "always allowed" paths run the
// real Aura_Worker_Updater / Aura_Worker_Snapshots code — so update_plugin's
// dependencies are stubbed for real; the rest exist so a temporarily-unguarded
// handler (RulesRestCoverageTest's revert-verify) fails on the rule-blocked
// assertion rather than a fatal from a missing WP core file.
//
// load_upgrade_dependencies() require_once's wp-admin/includes/*.php
// unconditionally (no function_exists guard — that is how WordPress itself
// does it), so the files below exist on disk purely so those requires resolve;
// the real definitions are the ones in this file, loaded first.

if ( ! function_exists( 'get_plugins' ) ) {
	function get_plugins() {
		return isset( $GLOBALS['_installed_plugins'] )
			? $GLOBALS['_installed_plugins']
			: array( 'akismet/akismet.php' => array( 'Name' => 'Akismet', 'Version' => '1.0' ) );
	}
}

if ( ! function_exists( 'is_plugin_active' ) ) {
	function is_plugin_active( $plugin ) {
		return isset( $GLOBALS['_active_plugins'][ $plugin ] );
	}
}

if ( ! function_exists( 'activate_plugin' ) ) {
	function activate_plugin( $plugin ) {
		$GLOBALS['_active_plugins'][ $plugin ] = true;
		$GLOBALS['_mutations'][]               = 'activate_plugin';
	}
}

if ( ! class_exists( 'SA_Test_Theme' ) ) {
	/** Minimal WP_Theme stand-in: exists() is true for any non-empty slug. */
	class SA_Test_Theme {
		private string $slug;

		public function __construct( $slug = '' ) {
			$this->slug = '' !== (string) $slug ? (string) $slug : 'sa-test-theme';
		}

		public function exists(): bool {
			return '' !== $this->slug && ! isset( $GLOBALS['_missing_themes'][ $this->slug ] );
		}

		/** get_status()'s health report reads Name/Version headers off the theme. */
		public function get( string $header ) {
			return $GLOBALS['_theme_headers'][ $this->slug ][ $header ] ?? $header;
		}

		public function get_stylesheet(): string {
			return $this->slug;
		}

		/** No parent theme modelled — get_status() treats this as "not a child theme". */
		public function parent() {
			return false;
		}
	}
}

if ( ! function_exists( 'wp_get_theme' ) ) {
	function wp_get_theme( $stylesheet = '' ) {
		return new SA_Test_Theme( $stylesheet );
	}
}

if ( ! function_exists( 'switch_theme' ) ) {
	function switch_theme( $stylesheet ) {
		$GLOBALS['_mutations'][] = 'switch_theme';
	}
}

if ( ! function_exists( 'get_core_updates' ) ) {
	function get_core_updates() {
		// Default: "already up to date" — update_core() returns success without
		// reaching Core_Upgrader. Tests that need an available update set
		// $GLOBALS['_core_updates'] themselves.
		return isset( $GLOBALS['_core_updates'] )
			? $GLOBALS['_core_updates']
			: array( (object) array( 'response' => 'latest' ) );
	}
}

if ( ! function_exists( 'wp_raise_memory_limit' ) ) {
	function wp_raise_memory_limit( $context = 'admin' ) {
		return false;
	}
}

if ( ! function_exists( 'wp_cache_flush' ) ) {
	function wp_cache_flush() {
		return true;
	}
}

if ( ! function_exists( 'wp_clean_plugins_cache' ) ) {
	function wp_clean_plugins_cache( $clear_update_cache = true ) {}
}

if ( ! function_exists( 'get_plugin_data' ) ) {
	function get_plugin_data( $plugin_file, $markup = true, $translate = true ) {
		return array( 'Version' => 'unknown' );
	}
}

if ( ! function_exists( 'download_url' ) ) {
	function download_url( $url, $timeout = 300, $signature_verification = false ) {
		return isset( $GLOBALS['_download_url_result'] ) ? $GLOBALS['_download_url_result'] : '';
	}
}

if ( ! function_exists( 'wp_upgrade' ) ) {
	function wp_upgrade() {
		$GLOBALS['_mutations'][] = 'wp_upgrade';
	}
}

if ( ! class_exists( 'Automatic_Upgrader_Skin' ) ) {
	class Automatic_Upgrader_Skin {
		public function get_upgrade_messages() {
			return array();
		}
	}
}

if ( ! class_exists( 'Plugin_Upgrader' ) ) {
	class Plugin_Upgrader {
		public function __construct( $skin = null ) {}

		public function upgrade( $plugin_file ) {
			$GLOBALS['_mutations'][] = 'Plugin_Upgrader::upgrade';
			return true;
		}

		public function install( $package, $args = array() ) {
			$GLOBALS['_mutations'][] = 'Plugin_Upgrader::install';
			return true;
		}
	}
}

if ( ! class_exists( 'Theme_Upgrader' ) ) {
	class Theme_Upgrader {
		public function __construct( $skin = null ) {}

		public function upgrade( $theme_slug ) {
			$GLOBALS['_mutations'][] = 'Theme_Upgrader::upgrade';
			return true;
		}
	}
}

if ( ! class_exists( 'Core_Upgrader' ) ) {
	class Core_Upgrader {
		public function __construct( $skin = null ) {}

		public function upgrade( $update ) {
			$GLOBALS['_mutations'][] = 'Core_Upgrader::upgrade';
			return true;
		}
	}
}

if ( ! class_exists( 'Language_Pack_Upgrader' ) ) {
	class Language_Pack_Upgrader {
		public function __construct( $skin = null ) {}

		public function bulk_upgrade() {
			$GLOBALS['_mutations'][] = 'Language_Pack_Upgrader::bulk_upgrade';
			return array();
		}
	}
}

// ---------------------------------------------------------------------------
// Stub classes
// ---------------------------------------------------------------------------

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;
		private $data;

		public function __construct( string $code = '', string $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message( string $code = '' ): string {
			return $this->message;
		}

		public function get_error_data( string $code = '' ) {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Minimal WP_REST_Request stub — carries headers + a route.
	 */
	class WP_REST_Request {
		private array $headers = array();
		private array $params  = array();
		private string $route  = '/aura/v1/status';
		private string $method = 'GET';

		/**
		 * Core's own signature is __construct( $method = '', $route = '', … ),
		 * and a test that wrote `new WP_REST_Request( 'POST', '/wp/v2/posts' )`
		 * against a stub with no constructor got neither: PHP discards the
		 * arguments silently and the request stays a GET on this class's
		 * default route — a test that reads as a write and is not one. The
		 * defaults above are kept for the many tests that rely on them: an
		 * argument overrides, an omitted one does not.
		 *
		 * @param string $method HTTP method.
		 * @param string $route  Route path.
		 */
		public function __construct( string $method = '', string $route = '' ) {
			if ( '' !== $method ) {
				$this->set_method( $method );
			}
			if ( '' !== $route ) {
				$this->route = $route;
			}
		}

		public function set_method( string $m ): void {
			$this->method = strtoupper( $m );
		}

		public function get_method(): string {
			return $this->method;
		}

		public function set_header( string $key, $value ): void {
			$this->headers[ strtolower( $key ) ] = $value;
		}

		public function get_header( string $key ) {
			return $this->headers[ strtolower( $key ) ] ?? null;
		}

		public function set_param( string $key, $value ): void {
			$this->params[ $key ] = $value;
		}

		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}

		public function set_route( string $route ): void {
			$this->route = $route;
		}

		public function get_route(): string {
			return $this->route;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public int $status;
		private array $headers = array();

		public function __construct( $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		public function get_data() {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}

		public function header( string $key, $value ): void {
			$this->headers[ $key ] = $value;
		}

		public function get_headers(): array {
			return $this->headers;
		}
	}
}

// --- Media library ----------------------------------------------------------
// Only the cleanup_orphaned_assets tool reaches these, and only through the
// PREVIEW sweep in UnbindRefusalTest (#434 Task 5, round-1 MINOR-4): that
// exemption says a preview mutates nothing, and without a delete that records
// itself the claim could only ever be proved by a fatal.
if ( ! function_exists( 'get_attached_file' ) ) {
	function get_attached_file( $attachment_id, $unfiltered = false ) {
		return $GLOBALS['_attached_files'][ (int) $attachment_id ] ?? false;
	}
}

if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $post = 0 ) {
		$id  = (int) ( is_object( $post ) ? ( $post->ID ?? 0 ) : $post );
		$obj = $GLOBALS['_posts'][ $id ] ?? null;
		return is_object( $obj ) ? (string) ( $obj->post_title ?? '' ) : '';
	}
}

if ( ! function_exists( 'wp_delete_attachment' ) ) {
	function wp_delete_attachment( $post_id, $force_delete = false ) {
		$id                      = (int) $post_id;
		$GLOBALS['_mutations'][] = 'wp_delete_attachment:' . $id;
		unset( $GLOBALS['_posts'][ $id ] );
		$GLOBALS['_wp_query_posts'] = array_values( array_diff( $GLOBALS['_wp_query_posts'], array( $id ) ) );
		return true;
	}
}

// Core's own permission callback for a route that authenticates itself
// (/aura/v1/connect proves a signed magic link inside its handler). Defined
// here so the route table's callbacks are all actually callable.
if ( ! function_exists( '__return_true' ) ) {
	function __return_true(): bool {
		return true;
	}
}

// --- REST route table -------------------------------------------------------
// Records what the plugin passes to register_rest_route(), so a test can
// enumerate the LIVE route table instead of hard-coding a list that goes stale
// the day a route is added (#434 Task 5: the unbind refusal is asserted for
// EVERY registered non-safe-method route). Recording only — nothing here
// dispatches a request; sa_dispatch_permission() below calls one endpoint's
// permission_callback and nothing else.
if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( string $namespace, string $route, array $args = array(), bool $override = false ): bool {
		// Core normalises a single endpoint into a list of endpoints before
		// storing it (rest-api.php, register_rest_route()); an enumeration that
		// did not would miss every endpoint of a multi-endpoint route.
		if ( isset( $args['callback'] ) ) {
			$args = array( $args );
		}
		$full = '/' . trim( $namespace, '/' ) . '/' . ltrim( $route, '/' );
		foreach ( $args as $endpoint ) {
			$GLOBALS['_rest_routes'][ $full ][] = $endpoint;
		}
		return true;
	}
}

if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( $response ) {
		return ( $response instanceof WP_REST_Response ) ? $response : new WP_REST_Response( $response, 200 );
	}
}

if ( ! function_exists( 'rest_convert_error_to_response' ) ) {
	// The conversion send_warning_header() performs one statement before core
	// would (rest-api.php:3464): the status comes from the error data, the body
	// is the code, message and that same data.
	function rest_convert_error_to_response( $error ) {
		$code   = $error->get_error_code();
		$data   = $error->get_error_data( $code );
		$status = ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 500;
		return new WP_REST_Response(
			array( 'code' => $code, 'message' => $error->get_error_message( $code ), 'data' => $data ),
			$status
		);
	}
}

if ( ! function_exists( 'rest_get_authenticated_app_password' ) ) {
	function rest_get_authenticated_app_password() {
		return $GLOBALS['_rest_app_password'] ?? null;
	}
}

if ( ! class_exists( 'SA_Test_Wpdb' ) ) {
	/**
	 * Minimal $wpdb stub. get_results() returns whatever a test placed in
	 * $GLOBALS['_db_rows'] and records the SQL it was asked to run.
	 */
	class SA_Test_Wpdb {
		public string $prefix     = 'wp_';
		public string $options    = 'wp_options';
		public string $usermeta   = 'wp_usermeta';
		// Core's wpdb sets a property per core table; the tools that read the
		// media library name these two.
		public string $posts      = 'wp_posts';
		public string $postmeta   = 'wp_postmeta';
		public string $last_error = '';
		public string $last_query = '';
		/**
		 * wpdb::$ready, modelled because production reads it: wpdb::query()
		 * returns at its very first line when this is false (#434 M12).
		 */
		public bool $ready = true;
		/**
		 * What the LAST statement that actually ran left behind — wpdb keeps
		 * it in $last_result, and get_var() extracts from there whether or not
		 * the statement it was handed ever reached the database.
		 */
		private $sa_last_var = null;
		/**
		 * The same thing for get_row(). wpdb keeps ONE $last_result; modelling
		 * the two separately is the harsher choice, because it means the stale
		 * answer a probe can meet is always another PROBE's row — the most
		 * dangerous shape there is — rather than some unrelated statement's.
		 */
		private $sa_last_row = null;

		/** Used only by get_status()'s health report — a fixed stand-in, not modelled state. */
		public function db_version(): string {
			return '8.0.30';
		}

		/**
		 * get_results returns the next queued result-set (for tools that run
		 * several SELECTs), falling back to the single $_db_rows for callers
		 * that only run one query.
		 */
		public function get_results( $query, $output = OBJECT ) {
			$this->last_query = (string) $query;
			// The one shape the value-parsed sweep issues: names AND values for
			// a prefix, read against the "database" ($_rows, else $_options).
			if ( preg_match( "/^SELECT option_name, option_value FROM \S+ WHERE option_name LIKE '([^']+)%'$/", (string) $query, $m ) ) {
				$GLOBALS['_db_queries'][] = (string) $query;
				$re    = sa_like_to_regex( stripslashes( $m[1] ) . '%' );
				$out   = array();
				$names = array_unique( array_merge( array_keys( $GLOBALS['_rows'] ), array_keys( $GLOBALS['_options'] ) ) );
				foreach ( $names as $name ) {
					if ( preg_match( $re, (string) $name ) ) {
						$out[] = array(
							'option_name'  => (string) $name,
							'option_value' => (string) sa_read_option_uncached( (string) $name ),
						);
					}
				}
				return $out;
			}
			// The site-wide holder statement (#434 Task 9, reshaped in Task 10's
			// fix round): the same nonce proof, asked of every Application
			// Password list at once, answering with ONE ROW PER OWNER behind a
			// sentinel row rather than with a bounded GROUP_CONCAT. Modelled
			// over $GLOBALS['_app_passwords'] the way MySQL would run the LIKE
			// — the uuid appears literally in the serialized list — so a
			// password nobody recorded an owner for is still found.
			if ( preg_match( "/^SELECT '([^']*)' AS probe, 0 AS user_id UNION ALL SELECT '([^']*)' AS probe, user_id FROM \S+ WHERE meta_key = '_application_passwords' AND meta_value LIKE '%([^']*)%'$/", (string) $query, $m ) ) {
				$GLOBALS['_db_queries'][] = (string) $query;
				// A statement that failed at the driver: no result set at all,
				// nothing proved — not even the sentinel comes back. Separate
				// from _sa_app_password_read_fail, which scopes a failure to
				// ONE user's row.
				if ( ! empty( $GLOBALS['_sa_app_password_scan_fail'] ) ) {
					$this->last_error = 'scan failed';
					return array();
				}
				$needle = str_replace( array( '\\_', '\\%', '\\\\' ), array( '_', '%', '\\' ), stripslashes( $m[3] ) );
				$rows = array( (object) array( 'probe' => $m[1], 'user_id' => 0 ) ); // the sentinel
				// A result set whose OWNER rows came from somebody else's
				// statement while the sentinel is this call's: the shape a
				// check that read only the first row would wave through.
				$owner_probe = empty( $GLOBALS['_sa_app_password_scan_rewrite_probe'] )
					? $m[2]
					: (string) $GLOBALS['_sa_app_password_scan_rewrite_probe'];
				foreach ( $GLOBALS['_app_passwords'] as $user => $list ) {
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
					if ( false !== strpos( serialize( $list ), $needle ) ) {
						$rows[] = (object) array( 'probe' => $owner_probe, 'user_id' => (int) $user );
					}
				}
				// _sa_app_password_scan_answer replaces the answer outright, so
				// a test can hand this code a result set no fake would produce
				// on its own — a foreign result set, or one the sentinel never
				// reached.
				return array_key_exists( '_sa_app_password_scan_answer', $GLOBALS ) && null !== $GLOBALS['_sa_app_password_scan_answer']
					? $GLOBALS['_sa_app_password_scan_answer']
					: $rows;
			}
			// Reformatting the site-wide holder statement would otherwise
			// unhook every test that depends on it silently — they would fall
			// through to $_db_rows and go on passing while proving nothing.
			if ( false !== strpos( (string) $query, '_application_passwords' ) ) {
				throw new RuntimeException( 'wpdb stub: unrecognised _application_passwords query shape — usermeta_holders() was reformatted and its tests would prove nothing: ' . (string) $query );
			}
			if ( ! empty( $GLOBALS['_db_results_queue'] ) ) {
				return array_shift( $GLOBALS['_db_results_queue'] );
			}
			return $GLOBALS['_db_rows'];
		}

		/**
		 * get_var returns the next queued scalar, else $_db_var — except the one
		 * shape the ruleset store's insert_if_absent() issues to classify a
		 * losing INSERT: a raw re-read of the row from $_rows (the
		 * "database"), recorded into $_db_queries so a test can confirm the
		 * classification really came from a query, not from get_option().
		 */
		public function get_var( $query = null, $x = 0, $y = 0 ) {
			// wpdb::query() has TWO early returns before its flush() — an
			// unready handle, and a `query` filter that blanks the SQL — and
			// each returns false leaving last_result, last_error and
			// last_query exactly as the previous statement left them.
			// wpdb::get_var() ignores that return value and extracts its
			// answer from the stale last_result regardless. A stub that always
			// ran the query it was handed is more forgiving than WordPress,
			// and that is how #434 M12 hid: the probe's absence proof came
			// from a statement that never ran. Off by default; narrowly
			// scoped to get_var(), which is the only shape production reads a
			// row with here.
			if ( ! $this->ready || ! empty( $GLOBALS['_sa_wpdb_query_filtered_out'] ) ) {
				return $this->sa_last_var; // another statement's answer
			}
			$this->sa_last_var = $this->sa_get_var_ran( $query, $x, $y );
			return $this->sa_last_var;
		}

		/** get_var()'s body for the case where the statement really is issued. */
		private function sa_get_var_ran( $query = null, $x = 0, $y = 0 ) {
			$this->last_query = (string) $query;
			// A driver-level failure answers null AND sets last_error — the one
			// thing that tells "no such row" apart from "the database is
			// broken". Code that reads a row to decide must consult it.
			$this->last_error = (string) ( $GLOBALS['_sa_wpdb_error'] ?? '' );
			if ( '' !== $this->last_error ) {
				$GLOBALS['_db_queries'][] = (string) $query;
				return null;
			}
			if ( preg_match( "/^SELECT option_value FROM \S+ WHERE option_name = '([^']+)' LIMIT 1$/", (string) $query, $m ) ) {
				$GLOBALS['_db_queries'][] = (string) $query;
				$name = stripslashes( $m[1] );
				// A read failure scoped to ONE option, unlike _sa_wpdb_error
				// which breaks every read of the request. A test that must
				// prove a specific boundary fails closed needs the boundary's
				// own read to fail while the rest of the request still works —
				// otherwise a later shared failure would satisfy the assertion
				// just as well and the test would prove nothing.
				if ( ! empty( $GLOBALS['_sa_option_read_fail'][ $name ] ) ) {
					$this->last_error = 'read failed';
					return null;
				}
				// The row, not the cache (see sa_read_option_uncached()).
				$answer = sa_read_option_uncached( $name );
				// …and then the window AFTER this read, in which another
				// request writes the same row. A caller that reads one option
				// TWICE to decide (#434 Task 9's repair, which will not rewrite
				// a row on the strength of a single "malformed") can only be
				// pinned if the two reads can disagree. Inert when unset;
				// one-shot is the listener's own business.
				sa_after_option_read( $name );
				return $answer;
			}
			// app_password_row_state()'s confirming read is a get_row() now
			// (#434 N1), so NO get_var() shape may touch this meta key except
			// the admin-accounts audit's size pre-check. Anything else means
			// the probe was moved or reformatted, and every I5/M12 test would
			// otherwise fall through to $_db_var, read null, and go on passing
			// while proving nothing. The seam fails LOUD instead.
			if (
				! preg_match( "/^SELECT LENGTH\(meta_value\) FROM \S+ WHERE user_id = \d+ AND meta_key = '_application_passwords' LIMIT 1$/", (string) $query )
				&& false !== strpos( (string) $query, '_application_passwords' )
			) {
				throw new RuntimeException( 'wpdb stub: unrecognised _application_passwords query shape — app_password_row_state() was reformatted and its tests would prove nothing: ' . (string) $query );
			}
			if ( ! empty( $GLOBALS['_db_var_queue'] ) ) {
				return array_shift( $GLOBALS['_db_var_queue'] );
			}
			return $GLOBALS['_db_var'];
		}

		/**
		 * get_row, modelled the way wpdb really behaves — because this is the
		 * shape app_password_row_state()'s confirming read issues (#434 N1).
		 *
		 * wpdb::get_row() ignores wpdb::query()'s return value and extracts
		 * from $last_result, so a statement that never ran hands back the
		 * PREVIOUS statement's row: an unready handle and a `query` filter
		 * that blanks the SQL both return before flush(). A stub that always
		 * ran the statement it was handed is more forgiving than WordPress,
		 * and that is how #434 M12 hid. Both switches are off by default. A
		 * falsy $query is the one case core answers null outright (get_row's
		 * `else { return null; }`, unlike get_var, which reads last_result).
		 */
		public function get_row( $query = null, $output = OBJECT, $y = 0 ) {
			if ( ! $query ) {
				return null;
			}
			if ( ! $this->ready || ! empty( $GLOBALS['_sa_wpdb_query_filtered_out'] ) ) {
				return $this->sa_last_row; // another statement's answer
			}
			$this->sa_last_row = $this->sa_get_row_ran( $query, $output, $y );
			return $this->sa_last_row;
		}

		/** get_row()'s body for the case where the statement really is issued. */
		private function sa_get_row_ran( $query = null, $output = OBJECT, $y = 0 ) {
			$this->last_query = (string) $query;
			// A driver-level failure has no result set at all, so wpdb has no
			// row to extract and answers null — the shape the probe reads as
			// "this proved nothing".
			$this->last_error = (string) ( $GLOBALS['_sa_wpdb_error'] ?? '' );
			if ( '' !== $this->last_error ) {
				$GLOBALS['_db_queries'][] = (string) $query;
				return null;
			}
			// The one shape app_password_row_state() issues (#434 N1): the
			// call's own nonce as a `probe` column, beside that user's
			// Application Passwords meta row read straight from the
			// "database" ($GLOBALS['_app_passwords'], which is what the
			// WP_Application_Passwords stub above is backed by). Serialised,
			// as core stores it. The nonce is echoed back exactly as the
			// statement asked for it — a real SELECT of a literal cannot do
			// anything else.
			if ( preg_match( "/^SELECT '([^']*)' AS probe, \(SELECT meta_value FROM \S+ WHERE user_id = (\d+) AND meta_key = '_application_passwords' LIMIT 1\) AS v$/", (string) $query, $m ) ) {
				$GLOBALS['_db_queries'][] = (string) $query;
				$user = (int) $m[2];
				// A read failure scoped to ONE user's row, not the whole
				// request: a test proving THIS boundary fails closed needs the
				// rest of the request to keep working, or a later shared
				// failure would satisfy the assertion just as well.
				if ( ! empty( $GLOBALS['_sa_app_password_read_fail'][ $user ] ) ) {
					$this->last_error = 'read failed';
					return null; // the statement failed: no result set, no row
				}
				return (object) array(
					'probe' => $m[1],
					'v'     => isset( $GLOBALS['_app_passwords'][ $user ] )
						? serialize( $GLOBALS['_app_passwords'][ $user ] ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
						: null, // no row at all
				);
			}
			// Reformatting the production probe would otherwise unhook every
			// I5/M12/N1 test silently — they would fall through to $_db_row,
			// read null, and go on passing while proving nothing. LOUD instead.
			if ( false !== strpos( (string) $query, '_application_passwords' ) ) {
				throw new RuntimeException( 'wpdb stub: unrecognised _application_passwords query shape — app_password_row_state() was reformatted and its tests would prove nothing: ' . (string) $query );
			}
			return $GLOBALS['_db_row'];
		}

		/**
		 * Forget what the last statement left behind, so one test's result set
		 * can never be the stale answer another test's probe meets.
		 */
		public function sa_forget_last_result(): void {
			$this->sa_last_var = null;
			$this->sa_last_row = null;
		}

		/**
		 * get_col: the one shape sweep_options() issues (a LIKE-prefix bounded
		 * by an upper name), read against $_rows — the same table query()/
		 * get_var() treat as the "database". Every sweep in the class is
		 * bounded, so there is no unbounded form to emulate.
		 */
		public function get_col( $query = null, $x = 0 ) {
			$this->last_query         = (string) $query;
			$GLOBALS['_db_queries'][] = (string) $query;
			// The unbounded prefix listing uninstall.php issues (#434 Task 10).
			// Read against $_rows — the "database" — so a row written by raw SQL
			// (the rule counters) is as visible to the sweep here as it is on a
			// real site, which is the whole point of asking the table rather
			// than a list of names somebody maintained.
			if ( preg_match( "/^SELECT option_name FROM \S+ WHERE option_name LIKE '([^']+)%'$/", (string) $query, $m ) ) {
				$re = sa_like_to_regex( stripslashes( $m[1] ) . '%' );
				return array_values(
					array_filter(
						array_keys( $GLOBALS['_rows'] ),
						static function ( $k ) use ( $re ) {
							return 1 === preg_match( $re, (string) $k );
						}
					)
				);
			}
			if ( preg_match( "/^SELECT option_name FROM \S+ WHERE option_name LIKE '([^']+)%' AND option_name < '([^']+)'$/", (string) $query, $m ) ) {
				$re     = sa_like_to_regex( stripslashes( $m[1] ) . '%' );
				$before = stripslashes( $m[2] );
				return array_values(
					array_filter(
						array_keys( $GLOBALS['_rows'] ),
						static function ( $k ) use ( $re, $before ) {
							return 1 === preg_match( $re, (string) $k ) && strcmp( (string) $k, $before ) < 0;
						}
					)
				);
			}
			return array();
		}

		/**
		 * Records the prepared (query, args) and returns the query with each
		 * `%s` substituted by its escaped, quoted argument (and `%d` by its
		 * integer value) — real enough for the ruleset CAS statements, which
		 * this stub's query()/get_var() match by parsing the substituted SQL.
		 */
		public function prepare( $query, ...$args ) {
			// Some callers pass a single array of args.
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			$query                      = (string) $query;
			$GLOBALS['_db_prepared'][] = array( 'query' => $query, 'args' => $args );
			// core's prepare() answers null when it refuses the call (a
			// placeholder/argument mismatch, an empty query). Nothing is
			// issued then, and a caller that issued it anyway would read back
			// whatever result set was already there (#434 N3).
			if ( ! empty( $GLOBALS['_sa_wpdb_prepare_null'] ) ) {
				return null;
			}
			$i = 0;
			return preg_replace_callback(
				'/%[sd]/',
				static function ( $m ) use ( $args, &$i ) {
					$arg = $args[ $i ] ?? '';
					++$i;
					if ( '%d' === $m[0] ) {
						return (string) (int) $arg;
					}
					return "'" . addslashes( (string) $arg ) . "'";
				},
				$query
			);
		}

		public function esc_like( $text ) {
			return addcslashes( (string) $text, '_%\\' );
		}

		/**
		 * The write paths the ruleset store's compare-and-swap issues, plus
		 * whatever a test has queued in $_db_query_result for anything else.
		 * Models both statements against $_rows (the "database"):
		 *
		 *  - the conditional INSERT ( insert_if_absent() ) — a real
		 *    `INSERT ... SELECT ... WHERE NOT EXISTS`, not add_option(), so a
		 *    racer's already-committed row is never clobbered by this one;
		 *  - the UPDATE ( swap_raw() ) — the byte-exact compare-and-swap.
		 *
		 * `_insert_racer` / `_cas_racer` inject a second write between this
		 * caller's read and its own write; `_cas_always_lose` and
		 * `_db_query_error` inject unresolved contention and a hard DB error
		 * respectively (on both statements — a real driver doesn't care which
		 * statement it was asked to run when the connection is the problem).
		 */
		public function query( $query ) {
			$query                    = (string) $query;
			$this->last_query         = $query;
			$this->last_error         = ''; // As wpdb::flush() does before every statement.
			$GLOBALS['_db_queries'][] = $query;

			if ( preg_match( "/^DELETE o FROM \S+ o JOIN \S+ c ON c\.option_name = '([^']+)' AND c\.option_value LIKE '([^']*)' WHERE o\.option_name = '([^']+)'$/s", $query, $m ) ) {
				list( , $claim, $like, $name ) = array_map( 'stripslashes', $m );
				if ( ! empty( $GLOBALS['_sa_option_delete_fail'][ $name ] ) ) {
					// The statement itself failing — NOT "no row matched".
					// Kept apart from _sa_option_write_fail so a test can refuse
					// the write while letting the delete land, and vice versa.
					$this->last_error = 'delete failed';
					return false;
				}
				if ( ! sa_claim_like_matches( $claim, $like ) || null === sa_read_option_uncached( $name ) ) {
					return 0;
				}
				unset( $GLOBALS['_options'][ $name ], $GLOBALS['_rows'][ $name ], $GLOBALS['_rows_autoload'][ $name ] );
				$GLOBALS['_option_writes'][] = array( 'delete', $name );
				return 1;
			}
			// The site token written CONDITIONALLY on the site claim (2.11.0,
			// round-9): one UPDATE joined to the claim row, and its INSERT
			// counterpart for a site whose token row does not exist yet. A
			// caller that no longer owns the claim matches no row.
			if ( preg_match( "/^UPDATE \S+ o JOIN \S+ c ON c\.option_name = '([^']+)' AND c\.option_value LIKE '([^']*)' SET o\.option_value = '(.*)' WHERE o\.option_name = '([^']+)'$/s", $query, $m ) ) {
				list( , $claim, $like, $value, $name ) = array_map( 'stripslashes', $m );
				if ( ! empty( $GLOBALS['_sa_option_write_fail'][ $name ] ) ) {
					// `true` fails every write; a positive INT fails that many
					// and then lets it through, as update_option() does; a
					// CALLABLE decides per value, which is how a test refuses
					// one write of a sequence and allows another.
					$fail = $GLOBALS['_sa_option_write_fail'][ $name ];
					if ( is_callable( $fail ) && ! $fail( $value ) ) {
						// allowed through
					} else {
						if ( is_int( $fail ) ) {
							--$GLOBALS['_sa_option_write_fail'][ $name ];
						}
						return false; // the database refusing the statement outright
					}
				}
				if ( ! sa_claim_like_matches( $claim, $like ) || null === sa_read_option_uncached( $name ) ) {
					return 0;
				}
				// A claimed write that REPORTS SUCCESS while the stored value
				// diverges from what the caller asked for — a silently lost or
				// rewritten write (a replication lag, a filter, a trigger),
				// which _sa_option_write_fail cannot model because it fails the
				// statement outright. `true` keeps the row exactly as it was;
				// a CALLABLE receives the raw value the caller tried to write
				// and returns the raw value that actually lands. Either way the
				// statement answers 1, so only a caller that VERIFIES by
				// re-reading the field it changed can tell.
				if ( ! empty( $GLOBALS['_sa_option_write_divert'][ $name ] ) ) {
					$divert = $GLOBALS['_sa_option_write_divert'][ $name ];
					$kept   = sa_read_option_uncached( $name );
					$value  = is_callable( $divert ) ? (string) $divert( $value ) : (string) $kept;
				}
				$GLOBALS['_rows'][ $name ]    = $value;
				$GLOBALS['_options'][ $name ] = maybe_unserialize( $value );
				$GLOBALS['_option_writes'][]  = array( 'set', $name );
				return 1;
			}
			if ( preg_match( "/^INSERT INTO \S+ \(option_name, option_value, autoload\\) SELECT '([^']*)', '(.*)', '([^']*)' FROM \S+ c WHERE c\.option_name = '([^']+)' AND c\.option_value LIKE '([^']*)' AND NOT EXISTS \\( SELECT 1 FROM \S+ WHERE option_name = '([^']*)' \\)$/s", $query, $m ) ) {
				list( , $name, $value, $autoload, $claim, $like ) = array_map( 'stripslashes', $m );
				if ( ! empty( $GLOBALS['_sa_option_write_fail'][ $name ] ) ) {
					// `true` fails every write; a positive INT fails that many
					// and then lets it through, as update_option() does; a
					// CALLABLE decides per value, which is how a test refuses
					// one write of a sequence and allows another.
					$fail = $GLOBALS['_sa_option_write_fail'][ $name ];
					if ( is_callable( $fail ) && ! $fail( $value ) ) {
						// allowed through
					} else {
						if ( is_int( $fail ) ) {
							--$GLOBALS['_sa_option_write_fail'][ $name ];
						}
						return false; // the database refusing the statement outright
					}
				}
				if ( ! sa_claim_like_matches( $claim, $like ) || null !== sa_read_option_uncached( $name ) ) {
					return 0;
				}
				if ( ! empty( $GLOBALS['_sa_option_write_divert'][ $name ] ) ) {
					// Same seam as the UPDATE above; on an INSERT there is no
					// prior value, so `true` means "store the empty row" and a
					// callable decides. See the comment there. No test drives
					// this half today (the verified writes it models are all
					// updates) — it exists so an INSERT-path verification can
					// be pinned without reshaping the stub (#434 Task 3
					// re-review M8).
					$divert = $GLOBALS['_sa_option_write_divert'][ $name ];
					$value  = is_callable( $divert ) ? (string) $divert( $value ) : '';
				}
				$GLOBALS['_rows'][ $name ]          = $value;
				$GLOBALS['_options'][ $name ]       = maybe_unserialize( $value );
				$GLOBALS['_rows_autoload'][ $name ] = $autoload;
				$GLOBALS['_option_writes'][]        = array( 'set', $name );
				return 1;
			}

			if ( preg_match( "/^INSERT INTO \S+ \(option_name, option_value, autoload\\) SELECT '([^']*)', '(.*)', '([^']*)' FROM DUAL WHERE NOT EXISTS \\( SELECT 1 FROM \S+ WHERE option_name = '([^']*)' \\)$/s", $query, $m ) ) {
				list( , $name, $value, ) = array_map( 'stripslashes', $m );
				// This exact SQL shape is also Aura_Worker_Magic_Link::claim_magic_link()'s
				// statement (the site-wide claim, per-link claims) — and, since
				// #434, accept() takes the site claim before it ever touches
				// the ruleset row. A seam armed to fail/race/inspect "the
				// ruleset's first insert" must not instead fire on an
				// unrelated claim row that happens to be the FIRST matching
				// statement of the request; every seam below is therefore
				// scoped to the ruleset option specifically.
				$is_ruleset_insert = ( Aura_Worker_Rules::OPTION === $name );
				if ( $is_ruleset_insert && true === $GLOBALS['_db_query_error'] ) {
					return false; // An SQL error, which is NOT a lost race.
				}
				if ( $is_ruleset_insert ) {
					sa_before_swap();
					// A second request inserting between this caller's own
					// existence check (there is none — that's the point of a
					// real conditional INSERT) and this statement running.
					if ( ! empty( $GLOBALS['_insert_racer'] ) ) {
						$racer                    = $GLOBALS['_insert_racer'];
						$GLOBALS['_insert_racer'] = null;
						Aura_Worker_Rules::accept( $racer );
					}
				}
				// The row as the DATABASE holds it — $_rows, else an $_options
				// value a test seeded directly (sa_read_option_uncached()).
				if ( null !== sa_read_option_uncached( $name ) ) {
					if ( $is_ruleset_insert && 'duplicate' === $GLOBALS['_db_query_error'] ) {
						// The race decided by the unique index rather than by
						// the NOT EXISTS subquery: MySQL 1062, reported by
						// $wpdb->query() as false with last_error set. The
						// message is deliberately NOT English: lc_messages
						// localises it on real servers, and the code under
						// test must classify the race without reading it.
						$this->last_error = "Doppelter Eintrag '{$name}' für Schlüssel 'option_name'";
						return false;
					}
					return 0; // A row is already there — lost the race.
				}
				$GLOBALS['_rows'][ $name ]    = $value;
				$GLOBALS['_options'][ $name ] = maybe_unserialize( $value );
				return 1;
			}

			if ( preg_match( "/^UPDATE \S+ SET option_value = '(.*)' WHERE option_name = '([^']+)' AND option_value = '(.*)'$/s", $query, $m ) ) {
				if ( true === $GLOBALS['_db_query_error'] ) {
					return false; // An SQL error, which is NOT a lost race.
				}
				if ( ! empty( $GLOBALS['_cas_always_lose'] ) ) {
					return 0; // Contention that never resolves.
				}
				sa_before_swap();
				// A second request landing between this caller's read and its
				// write — exactly the window the CAS exists to close.
				if ( ! empty( $GLOBALS['_cas_racer'] ) ) {
					$racer                 = $GLOBALS['_cas_racer'];
					$GLOBALS['_cas_racer'] = null;
					Aura_Worker_Rules::accept( $racer );
				}
				list( , $new, $name, $expected ) = array_map( 'stripslashes', $m );
				// Compare the ROW, byte for byte, the way MySQL does. Going
				// through $_options and re-serializing would decode `i:5;` to 5
				// and compare "5" — so the corrupt-row repair, whose whole point
				// is that the predicate is the raw bytes, could never match.
				if ( ! isset( $GLOBALS['_rows'][ $name ] ) || (string) $GLOBALS['_rows'][ $name ] !== $expected ) {
					return 0; // Someone else wrote first.
				}
				$GLOBALS['_rows'][ $name ]    = $new;
				$GLOBALS['_options'][ $name ] = maybe_unserialize( $new );
				// The mirror of sa_before_swap(): a second request landing right
				// AFTER this caller's write, which is the window a confirming
				// read is about.
				if ( isset( $GLOBALS['_sa_after_swap'] ) && is_callable( $GLOBALS['_sa_after_swap'] ) ) {
					$after                    = $GLOBALS['_sa_after_swap'];
					$GLOBALS['_sa_after_swap'] = null;
					$after( $name );
				}
				return 1;
			}

			// Emulate the counters' atomic create-or-increment: one statement,
			// no read, so a first bump inserts '1' and every later bump in the
			// same hour adds one to whatever is there — never the two-step
			// add_option()-then-UPDATE this replaced, which core's real
			// add_option() could silently reset to the seed value (see
			// bump()'s comment). Writes BOTH $_rows (the "database" get_col()
			// and get_var() read) and $_options (the cache get_option() reads
			// first), matching the CAS branches above — a bump that only
			// touched $_options would leave the "database" holding a stale
			// count the moment anything reads it back through $_rows.
			if ( preg_match( "/^INSERT INTO \S+ \(option_name, option_value, autoload\) VALUES \('([^']+)', '1', 'no'\) ON DUPLICATE KEY UPDATE option_value = option_value \+ 1$/", $query, $m ) ) {
				$name = stripslashes( $m[1] );
				$GLOBALS['_rows'][ $name ]    = isset( $GLOBALS['_rows'][ $name ] ) ? (string) ( (int) $GLOBALS['_rows'][ $name ] + 1 ) : '1';
				$GLOBALS['_options'][ $name ] = $GLOBALS['_rows'][ $name ];
				return 1;
			}
			// The conditional DELETE a magic-link claim release issues: the row
			// goes only while it still carries THIS handler's fence, so a
			// double release can never remove somebody else's claim.
			if ( preg_match( "/^DELETE FROM \S+ WHERE option_name = '([^']+)' AND option_value LIKE '(.*)%'$/", $query, $m ) ) {
				$name  = stripslashes( $m[1] );
				$fence = str_replace( array( '\\_', '\\%' ), array( '_', '%' ), stripslashes( $m[2] ) );
				if ( isset( $GLOBALS['_options'][ $name ] ) && 0 === strpos( (string) $GLOBALS['_options'][ $name ], $fence ) ) {
					unset( $GLOBALS['_options'][ $name ], $GLOBALS['_rows'][ $name ] );
					return 1;
				}
				return 0;
			}
			// Used by the counters AND by the expired-notice claim sweep.
			if ( preg_match( "/^DELETE FROM \S+ WHERE option_name LIKE '([^']+)%' AND option_name < '([^']+)'$/", $query, $m ) ) {
				// Two layers of escaping to undo, or nothing matches: prepare()
				// escaped the string for SQL, and esc_like() escaped `_` and
				// `%` for LIKE beforehand — and every option name here is full
				// of underscores.
				$re = sa_like_to_regex( stripslashes( $m[1] ) . '%' );
				$n  = 0;
				foreach ( array_keys( $GLOBALS['_options'] ) as $k ) {
					if ( preg_match( $re, (string) $k ) && strcmp( (string) $k, stripslashes( $m[2] ) ) < 0 ) {
						unset( $GLOBALS['_options'][ $k ], $GLOBALS['_rows'][ $k ] );
						++$n;
					}
				}
				return $n;
			}

			return isset( $GLOBALS['_db_query_result'] ) ? $GLOBALS['_db_query_result'] : 0;
		}
	}

	$GLOBALS['_db_rows']          = array();
	$GLOBALS['_db_results_queue'] = array();
	$GLOBALS['_db_var']           = 0;
	$GLOBALS['_db_var_queue']     = array();
	$GLOBALS['_db_row']           = null;
	$GLOBALS['_db_prepared']      = array();
	$GLOBALS['_db_query_result']  = 0;
	$GLOBALS['_db_queries']       = array();
	$GLOBALS['_cache_deletes']    = array();
	$GLOBALS['_notoptions']       = array(); // Core's negative option cache — see get_option().
	$GLOBALS['_rows']             = array(); // Raw, serialized bytes — the "database" the ruleset CAS reads/writes.
	$GLOBALS['_rows_autoload']    = array(); // Per-option autoload flag for rows this stub actually tracks (add_option/update_option and the claim-fenced INSERT branch).
	$GLOBALS['_cas_racer']         = null;
	$GLOBALS['_insert_racer']      = null;
	$GLOBALS['_sa_gateway_secret'] = null; // sa_install_gateway_key()'s default signing key for sa_sign_ruleset().
	$GLOBALS['_cas_always_lose']   = false;
	$GLOBALS['_db_query_error']    = false;
	$GLOBALS['_sa_option_cache']      = array(); // This request's option cache — see get_option().
	$GLOBALS['_sa_wpdb_error']        = '';      // A driver-level failure on the next $wpdb read.
	$GLOBALS['_sa_wpdb_query_filtered_out'] = false; // A `query` filter blanks the SQL: wpdb::query() returns before flush() (#434 M12).
	$GLOBALS['_sa_wpdb_prepare_null']       = false; // wpdb::prepare() refuses the call and answers null (#434 N3).
	$GLOBALS['_sa_option_read_fail']  = array(); // Option names whose UNCACHED read fails at the driver.
	$GLOBALS['_sa_option_write_divert'] = array(); // Claimed writes that report success while the row diverges.
	$GLOBALS['_sa_option_write_fail'] = array(); // Option names update_option() must refuse to store.
	$GLOBALS['_sa_option_delete_fail'] = array(); // Option names the claim-conditional DELETE must fail on.
	$GLOBALS['_option_writes']        = array(); // Witnessed update_option()/delete_option() calls.
	$GLOBALS['_sa_before_swap']       = null;    // Runs between a read and its compare-and-swap.
	$GLOBALS['_sa_after_swap']        = null;    // Runs immediately after a successful compare-and-swap.
	$GLOBALS['_sa_after_store_read']  = null;    // Runs between accept()'s store read and its token read.
	$GLOBALS['_sa_after_option_read'] = null;    // Runs just after ONE uncached option read is answered (#434 Task 9).
	$GLOBALS['wpdb']              = new SA_Test_Wpdb();
}

if ( ! defined( 'DB_NAME' ) ) {
	define( 'DB_NAME', 'testdb' );
}

if ( ! defined( 'WP_MEMORY_LIMIT' ) ) {
	define( 'WP_MEMORY_LIMIT', '256M' );
}

if ( ! function_exists( 'wp_convert_hr_to_bytes' ) ) {
	function wp_convert_hr_to_bytes( $value ) {
		$value = strtolower( trim( (string) $value ) );
		$bytes = (int) $value;
		if ( false !== strpos( $value, 'g' ) ) {
			$bytes *= 1024 * 1024 * 1024;
		} elseif ( false !== strpos( $value, 'm' ) ) {
			$bytes *= 1024 * 1024;
		} elseif ( false !== strpos( $value, 'k' ) ) {
			$bytes *= 1024;
		}
		return $bytes;
	}
}

if ( ! function_exists( 'size_format' ) ) {
	function size_format( $bytes, $decimals = 0 ) {
		$bytes = (float) $bytes;
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB', 'PB' );
		$i     = 0;
		while ( $bytes >= 1024 && $i < count( $units ) - 1 ) {
			$bytes /= 1024;
			$i++;
		}
		return round( $bytes, $decimals ) . ' ' . $units[ $i ];
	}
}

if ( ! class_exists( 'SA_Test_Filesystem' ) ) {
	/**
	 * Real-filesystem shim standing in for WP_Filesystem in the rollback tests.
	 * Only the methods the rollback engine calls are implemented.
	 */
	class SA_Test_Filesystem {
		public function put_contents( string $file, string $contents, $mode = false ): bool {
			return false !== file_put_contents( $file, $contents );
		}

		/**
		 * Recursively delete a path. Mirrors $wp_filesystem->delete( $dir, true, 'd' ).
		 *
		 * Aura_Worker_Rollback::delete_directory() routes a real rollback's
		 * directory-replace step through here (`$wp_filesystem->delete( $dir, true,
		 * 'd' )`), and it is the only caller that ever passes a non-false $type — the
		 * recursive descent below always passes false. That makes $type the marker
		 * for "this is the outer call a guarded handler made", so one mutation is
		 * recorded per real delete rather than once per file/directory underneath it.
		 * The plugin's own directory bootstrapping (the .htaccess/index.php sentinel
		 * writes in the Snapshots/Rollback constructors) goes through put_contents(),
		 * never through here, so it needs no exclusion.
		 */
		public function delete( string $path, bool $recursive = false, $type = false ): bool {
			if ( false !== $type && ( is_file( $path ) || is_dir( $path ) || is_link( $path ) ) ) {
				$GLOBALS['_mutations'][] = 'SA_Test_Filesystem::delete';
			}
			if ( is_file( $path ) || is_link( $path ) ) {
				return @unlink( $path );
			}
			if ( ! is_dir( $path ) ) {
				return false;
			}
			$items = array_diff( scandir( $path ), array( '.', '..' ) );
			foreach ( $items as $item ) {
				$this->delete( $path . '/' . $item, true, false );
			}
			return @rmdir( $path );
		}
	}
}

// ---------------------------------------------------------------------------
// Post + Gutenberg-block stubs (for the block tools). Blocks are represented as
// JSON in these tests so parse/serialize round-trip cleanly; the real plugin
// uses WordPress's parse_blocks()/serialize_blocks() on real block markup.
// ---------------------------------------------------------------------------

$GLOBALS['_posts'] = array();

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post = null, string $output = 'OBJECT', string $filter = 'raw' ) {
		$id = (int) ( is_object( $post ) ? ( $post->ID ?? 0 ) : $post );
		return $GLOBALS['_posts'][ $id ] ?? null;
	}
}

if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( array $args, bool $wp_error = false ) {
		static $next = 1000;
		// Honor import_id (as real WP does) when the id is free — used to recreate a
		// deleted post with its original id.
		$import = (int) ( $args['import_id'] ?? 0 );
		if ( $import > 0 && ! isset( $GLOBALS['_posts'][ $import ] ) ) {
			$id = $import;
		} else {
			$id = ++$next;
		}
		$GLOBALS['_posts'][ $id ] = (object) array(
			'ID'             => $id,
			'post_title'     => $args['post_title'] ?? '',
			'post_name'      => $args['post_name'] ?? '',
			'post_content'   => $args['post_content'] ?? '',
			'post_excerpt'   => $args['post_excerpt'] ?? '',
			'post_status'    => $args['post_status'] ?? 'draft',
			'post_type'      => $args['post_type'] ?? 'page',
			'post_parent'    => (int) ( $args['post_parent'] ?? 0 ),
			'menu_order'     => (int) ( $args['menu_order'] ?? 0 ),
			'post_author'    => $args['post_author'] ?? 0,
			'post_date'      => $args['post_date'] ?? '',
			'post_date_gmt'  => $args['post_date_gmt'] ?? '',
			'comment_status' => $args['comment_status'] ?? 'open',
			'ping_status'    => $args['ping_status'] ?? 'open',
		);
		return $id;
	}
}

if ( ! function_exists( 'wp_delete_post' ) ) {
	function wp_delete_post( $post_id, $force_delete = false ) {
		$id = (int) $post_id;
		if ( ! isset( $GLOBALS['_posts'][ $id ] ) ) {
			return false;
		}
		$post = $GLOBALS['_posts'][ $id ];
		// Simulate a pre_delete_post short-circuit: return a truthy value WITHOUT
		// deleting, so the caller must verify removal by existence, not the return.
		if ( ! empty( $GLOBALS['_sa_state']['wp_delete_post_noop'][ $id ] ) ) {
			return $post;
		}
		unset( $GLOBALS['_posts'][ $id ], $GLOBALS['_post_meta'][ $id ] );
		return $post;
	}
}

if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( array $args, bool $wp_error = false ) {
		$id = (int) ( $args['ID'] ?? 0 );
		if ( ! isset( $GLOBALS['_posts'][ $id ] ) ) {
			return $wp_error ? new WP_Error( 'invalid_post', 'Post does not exist.' ) : 0;
		}
		foreach ( $args as $k => $v ) {
			$GLOBALS['_posts'][ $id ]->$k = $v;
		}
		$GLOBALS['_mutations'][] = 'wp_update_post';
		return $id;
	}
}

if ( ! function_exists( 'parse_blocks' ) ) {
	function parse_blocks( string $content ): array {
		$content = trim( $content );
		if ( '' === $content ) {
			return array();
		}
		$decoded = json_decode( $content, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}

if ( ! function_exists( 'serialize_blocks' ) ) {
	function serialize_blocks( array $blocks ): string {
		return wp_json_encode( $blocks );
	}
}

// ---------------------------------------------------------------------------
// User-query stubs (for the list_users tool). WP_User_Query records the args it
// was built with into $GLOBALS['_user_queries'] so tests can assert argument
// building (clamping, role/search, wildcards), and returns configured results
// so tests can assert output shape. The admin-count query (role=administrator,
// fields=ID) reports $GLOBALS['_admin_total']; the main query reports
// $GLOBALS['_users'] / $GLOBALS['_users_total'].
// ---------------------------------------------------------------------------

$GLOBALS['_users']        = array();
$GLOBALS['_users_total']  = 0;
$GLOBALS['_admin_total']  = 0;
$GLOBALS['_user_queries'] = array();
$GLOBALS['_post_counts']  = array();

if ( ! class_exists( 'WP_User_Query' ) ) {
	class WP_User_Query {
		/** @var array */
		public $query_vars;

		public function __construct( $args = array() ) {
			$this->query_vars           = is_array( $args ) ? $args : array();
			$GLOBALS['_user_queries'][] = $this->query_vars;
		}

		public function get_results() {
			// The admin-count query asks only for IDs — it never reads results.
			return $GLOBALS['_users'];
		}

		public function get_total() {
			$is_admin_count = ( isset( $this->query_vars['role'] ) && 'administrator' === $this->query_vars['role'] )
				&& ( isset( $this->query_vars['fields'] ) && 'ID' === $this->query_vars['fields'] );
			return $is_admin_count ? (int) $GLOBALS['_admin_total'] : (int) $GLOBALS['_users_total'];
		}
	}
}

if ( ! function_exists( 'count_user_posts' ) ) {
	function count_user_posts( $user_id, $post_type = 'post', $public_only = false ) {
		return isset( $GLOBALS['_post_counts'][ (int) $user_id ] ) ? (int) $GLOBALS['_post_counts'][ (int) $user_id ] : 0;
	}
}

// ---------------------------------------------------------------------------
// Post-query / URL stubs (broken-links, cleanup-assets, site-context). WP_Query
// records the args it was built with and returns $_wp_query_posts as ->posts;
// get_post_field reads $_post_content; url_to_postid maps a URL to a post id (0
// = unresolved); home_url returns the configured site URL.
// ---------------------------------------------------------------------------

$GLOBALS['_home_url']        = 'https://example.com';
$GLOBALS['_wp_query_posts']  = array();
$GLOBALS['_wp_queries']      = array();
$GLOBALS['_post_content']    = array();
$GLOBALS['_url_to_postid']   = array();

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '', $scheme = null ) {
		return $GLOBALS['_home_url'] . $path;
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		/** @var array */
		public $posts;
		/** @var array */
		public $query_vars;

		public function __construct( $args = array() ) {
			$this->query_vars       = is_array( $args ) ? $args : array();
			$GLOBALS['_wp_queries'][] = $this->query_vars;
			$this->posts            = $GLOBALS['_wp_query_posts'];
		}
	}
}

if ( ! function_exists( 'get_post_field' ) ) {
	function get_post_field( $field, $post = null, $context = 'display' ) {
		$id = (int) ( is_object( $post ) ? ( $post->ID ?? 0 ) : $post );
		if ( 'post_content' === $field ) {
			return $GLOBALS['_post_content'][ $id ] ?? '';
		}
		$obj = $GLOBALS['_posts'][ $id ] ?? null;
		return ( $obj && isset( $obj->$field ) ) ? $obj->$field : '';
	}
}

if ( ! function_exists( 'url_to_postid' ) ) {
	function url_to_postid( $url ) {
		return isset( $GLOBALS['_url_to_postid'][ $url ] ) ? (int) $GLOBALS['_url_to_postid'][ $url ] : 0;
	}
}

$GLOBALS['_bloginfo']   = array();
$GLOBALS['_thumbnails'] = array();
$GLOBALS['_attached_files'] = array(); // get_attached_file() — see the media stubs above.

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show = '', $filter = 'raw' ) {
		return $GLOBALS['_bloginfo'][ $show ] ?? '';
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $string, $remove_breaks = false ) {
		return trim( strip_tags( (string) $string ) );
	}
}

if ( ! function_exists( 'has_post_thumbnail' ) ) {
	function has_post_thumbnail( $post = null ) {
		$id = (int) ( is_object( $post ) ? ( $post->ID ?? 0 ) : $post );
		return ! empty( $GLOBALS['_thumbnails'][ $id ] );
	}
}

// ---------------------------------------------------------------------------
// Load the classes under test
// ---------------------------------------------------------------------------

require_once SA_PLUGIN_DIR . '/includes/tools/class-tool-base.php';
require_once SA_PLUGIN_DIR . '/includes/class-aura-worker-tools.php';
require_once SA_PLUGIN_DIR . '/includes/class-aura-worker-security.php';
require_once SA_PLUGIN_DIR . '/includes/class-aura-worker-rollback.php';
require_once SA_PLUGIN_DIR . '/includes/class-aura-worker-snapshots.php';
require_once SA_PLUGIN_DIR . '/includes/class-aura-worker-mcp.php';
require_once SA_PLUGIN_DIR . '/includes/class-aura-worker-grant.php';
require_once SA_PLUGIN_DIR . '/includes/class-aura-worker-updater.php';
require_once SA_PLUGIN_DIR . '/includes/class-aura-worker-api.php';
require_once SA_PLUGIN_DIR . '/includes/class-aura-worker-magic-link.php';
require_once SA_PLUGIN_DIR . '/includes/class-aura-worker-call-context.php';
require_once SA_PLUGIN_DIR . '/includes/class-aura-worker-rules.php';
require_once SA_PLUGIN_DIR . '/includes/class-aura-worker-abilities.php';
require_once SA_PLUGIN_DIR . '/includes/class-aura-worker-unbind.php';
// The plugin's admin/settings class: registers settings and owns the token
// regeneration handler (#67).
require_once SA_PLUGIN_DIR . '/includes/class-aura-worker.php';

// Load every shipped tool class so tool-level tests can instantiate them
// directly (the registry auto-loads the same set at construction time).
foreach ( glob( SA_PLUGIN_DIR . '/includes/tools/class-tool-*.php' ) as $tool_file ) {
	require_once $tool_file;
}

/**
 * Run $body with ONE extra PHP file present inside the plugin tree, then remove
 * it — and remove it whatever $body does, including throwing a failed
 * assertion, so a red test never leaves the working tree dirty.
 *
 * It exists for the source-scanning guards (#434 Tasks 5-7), which enumerate
 * the plugin's own files and whose whole value is COMPLETENESS. The only
 * honest way to show such a scan cannot lose a finding is to put a second
 * finding on disk where it would be lost — a same-named file in another
 * directory — and watch the scan still report both. Nothing may be asserted
 * about that from memory: keying a scan by basename silently collapsed two
 * findings into one for a whole task before anyone looked (round-2).
 *
 * @param string   $relative Path under SA_PLUGIN_DIR, e.g. 'includes/x/y.php'.
 * @param string   $contents The file's PHP source.
 * @param callable $body     Run while the file exists.
 * @return mixed Whatever $body returned.
 */
function sa_with_plugin_file( string $relative, string $contents, callable $body ) {
	$path = SA_PLUGIN_DIR . '/' . ltrim( $relative, '/' );

	// This helper writes a real PHP file INTO THE SHIPPED PLUGIN TREE so a source
	// scan's key space can be pinned with a genuine collision. Two things must be
	// true by CONSTRUCTION rather than by the good manners of its callers, because
	// the next caller will not read this comment:
	//
	// (1) It must not escape the plugin directory. `ltrim` alone does not stop
	//     `../../…`; the containment check does.
	// (2) It must not write anywhere the PRODUCTION loader would pick up. The tool
	//     auto-loader (class-aura-worker-tools.php) globs
	//     `includes/tools/class-tool-*.php` and require_once's every match, so a
	//     fixture landing there would be LOADED by the plugin — in a later test, or
	//     on a site, if a killed run ever stranded it. Today's two call sites write
	//     to includes/aaa_legacy/, which matches nothing; that is a property of the
	//     call sites, not of this helper, and this is what makes it a property of
	//     the helper.
	$root = realpath( SA_PLUGIN_DIR );
	$want = $root . '/' . ltrim( $relative, '/' );
	if ( false === $root || 0 !== strpos( $want, $root . '/' ) || false !== strpos( $relative, '..' ) ) {
		throw new RuntimeException( "sa_with_plugin_file(): {$relative} escapes the plugin directory" );
	}
	if ( 1 === preg_match( '#(^|/)includes/tools/class-tool-[^/]*\.php$#', ltrim( $relative, '/' ) ) ) {
		throw new RuntimeException( "sa_with_plugin_file(): {$relative} is inside the tool auto-loader's glob — a fixture there would be require_once'd by the plugin" );
	}

	$dir  = dirname( $path );
	$made = ! is_dir( $dir );
	if ( $made ) {
		mkdir( $dir, 0777, true );
	}
	if ( file_exists( $path ) ) {
		throw new RuntimeException( "sa_with_plugin_file(): {$relative} already exists — refusing to overwrite a real plugin file" );
	}
	file_put_contents( $path, $contents );
	try {
		return $body();
	} finally {
		@unlink( $path );
		if ( $made ) {
			@rmdir( $dir );
		}
	}
}

/**
 * Every PHP file the plugin ships, COMMENTS STRIPPED with PHP's own tokeniser,
 * keyed by PATH RELATIVE to SA_PLUGIN_DIR.
 *
 * Comments go because a docblock that DISCUSSES a call is prose, not a call,
 * and this tree's docblocks discuss `add_option()` and `update_option()` at
 * length. The key is a path and never a basename, because two identically named
 * files in different directories would collide and SILENTLY drop one from every
 * scan built on this — the exact failure that hid a REST registrar for a whole
 * task (#434 Task 7 round-2), pinned by a fixture in UnbindRefusalTest and again
 * in UninstallCoverageTest.
 *
 * @return array<string,string> relative path => comment-free source.
 */
function sa_plugin_php_sources(): array {
	$sources  = array();
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( SA_PLUGIN_DIR ) );
	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}
		$stripped = '';
		foreach ( token_get_all( (string) file_get_contents( $file->getPathname() ) ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$stripped .= is_array( $token ) ? $token[1] : $token;
		}
		$relative             = ltrim( str_replace( (string) SA_PLUGIN_DIR, '', (string) $file->getPathname() ), '/' );
		$sources[ $relative ] = $stripped;
	}
	// A scan that quietly matched almost nothing proves nothing at all.
	if ( count( $sources ) <= 30 ) {
		throw new RuntimeException( 'sa_plugin_php_sources(): only ' . count( $sources ) . ' files — is SA_PLUGIN_DIR right?' );
	}
	return $sources;
}

/**
 * Reset all mutable stub state. Call from each test's setUp().
 */
// ---------------------------------------------------------------------------
// K5 security-audit tool stubs
// ---------------------------------------------------------------------------

if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite(): bool {
		return (bool) ( $GLOBALS['_is_multisite'] ?? false );
	}
}

if ( ! function_exists( 'get_site_option' ) ) {
	function get_site_option( string $option, $default = false ) {
		return $GLOBALS['_site_options'][ $option ] ?? $default;
	}
}

if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( int $user_id, string $key = '', bool $single = false ) {
		if ( isset( $GLOBALS['_user_meta'][ $user_id ][ $key ] ) ) {
			return $GLOBALS['_user_meta'][ $user_id ][ $key ];
		}
		return $single ? '' : array();
	}
}

if ( ! function_exists( '_get_cron_array' ) ) {
	function _get_cron_array() {
		return $GLOBALS['_cron_array'] ?? array();
	}
}

if ( ! function_exists( 'wp_get_schedules' ) ) {
	function wp_get_schedules(): array {
		return $GLOBALS['_cron_schedules'] ?? array(
			'hourly' => array( 'interval' => 3600, 'display' => 'Hourly' ),
			'daily'  => array( 'interval' => 86400, 'display' => 'Daily' ),
		);
	}
}

if ( ! function_exists( 'wp_get_upload_dir' ) ) {
	function wp_get_upload_dir(): array {
		return array(
			'basedir' => $GLOBALS['_upload_basedir'] ?? sys_get_temp_dir() . '/sa-test-uploads-none',
			'baseurl' => 'http://example.com/wp-content/uploads',
		);
	}
}

if ( ! function_exists( 'get_locale' ) ) {
	function get_locale(): string {
		return 'en_US';
	}
}

if ( ! function_exists( 'wp_timezone_string' ) ) {
	function wp_timezone_string(): string {
		return $GLOBALS['_timezone_string'] ?? 'UTC';
	}
}

if ( ! function_exists( 'wp_max_upload_size' ) ) {
	function wp_max_upload_size(): int {
		return $GLOBALS['_max_upload_size'] ?? 2097152;
	}
}

if ( ! function_exists( 'get_site_url' ) ) {
	// A separate global from get_home_url()'s: real WordPress lets these
	// differ (a subdirectory install), so the stub does too, even though
	// nothing here currently sets them apart.
	function get_site_url(): string {
		return $GLOBALS['_site_url'] ?? 'https://example.com';
	}
}

if ( ! function_exists( 'get_home_url' ) ) {
	function get_home_url(): string {
		return $GLOBALS['_home_url'] ?? 'https://example.com';
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( array $args, string $url ): string {
		return $url . ( false !== strpos( $url, '?' ) ? '&' : '?' ) . http_build_query( $args );
	}
}

if ( ! function_exists( 'rawurlencode_deep' ) ) {
	// no-op helper space reserved
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	/**
	 * Recording HTTP stub: returns $GLOBALS['_http_response'] (or a WP_Error
	 * when $GLOBALS['_http_error'] is set) and records the request.
	 */
	function wp_remote_get( string $url, array $args = array() ) {
		$GLOBALS['_wp_http_calls'][] = array(
			'url'  => $url,
			'args' => $args,
		);
		if ( ! empty( $GLOBALS['_http_error'] ) ) {
			return new WP_Error( 'http_request_failed', 'stubbed failure' );
		}
		return $GLOBALS['_http_response'] ?? array(
			'response' => array( 'code' => 200 ),
			'body'     => '',
		);
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		if ( is_array( $response ) && isset( $response['response']['code'] ) ) {
			return (int) $response['response']['code'];
		}
		return 0;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ): string {
		return is_array( $response ) && isset( $response['body'] ) ? (string) $response['body'] : '';
	}
}

if ( ! function_exists( 'wp_get_abilities' ) ) {
	/**
	 * The WordPress Abilities API registry, as the audit tools read it.
	 *
	 * Returns objects answering get_name() and get_meta() — the two methods the
	 * exposure rule depends on. Seed with sa_register_ability().
	 *
	 * @return object[]
	 */
	function wp_get_abilities(): array {
		return array_values( $GLOBALS['_abilities'] ?? array() );
	}
}

if ( ! function_exists( 'sa_register_ability' ) ) {
	/**
	 * Seed one ability into the stub registry.
	 *
	 * @param string $name Ability name.
	 * @param array  $meta Ability meta (mcp.type, annotations.readonly, ...).
	 */
	function sa_register_ability( string $name, array $meta = array() ): void {
		$GLOBALS['_abilities'][ $name ] = new class( $name, $meta ) {
			private string $name;
			private array $meta;
			public function __construct( string $name, array $meta ) {
				$this->name = $name;
				$this->meta = $meta;
			}
			public function get_name(): string {
				return $this->name;
			}
			public function get_meta(): array {
				return $this->meta;
			}
		};
	}
}

/**
 * ONE uncached build of the plugin's REST route table, contained.
 *
 * Runs the plugin's own bootstrap and fires the hook WordPress fires —
 * `( new Aura_Worker() )->init(); do_action( 'rest_api_init' )` — so the table
 * is whatever the plugin actually registers at runtime. Never a scan of
 * source, never a list of routes, and (round-1 IMPORTANT-1) never a list of
 * REGISTRARS either: naming Aura_Worker_API::register_routes() and
 * Aura_Worker_MCP::register_routes() by hand made the SET OF REGISTRARS a
 * maintained list — the forbidden artefact moved up one level — and a third
 * `rest_api_init` registrar exposing an unauthenticated mutating route left
 * the whole suite green.
 *
 * Two containment properties, both pinned by tests in UnbindRefusalTest
 * (round-1 NEW-3) rather than merely asserted here:
 *
 *  1. The build registers NOTHING that outlives it. Aura_Worker::init() also
 *     hooks up the rules' core-REST filters, the app-password capture, the
 *     unbind sweep and the abilities hooks; $GLOBALS['_filters'] is saved and
 *     restored WHOLE, so none of them leak into a caller. Every init() this
 *     reaches does nothing but register hooks, which is what makes a
 *     whole-array restore complete.
 *  2. The table contains ONLY what the plugin registered. Filters are emptied
 *     before the build, so an ambient `rest_api_init` listener a test happens
 *     to have registered cannot smuggle a route into the sweep.
 *
 * Separate from sa_registered_routes() precisely so both are testable: the
 * memoisation belongs to the wrapper, and it is memoisation — not the guards —
 * that would otherwise make a second build unobservable.
 *
 * @return array<string,array<int,array>> Route pattern => endpoints.
 */
function sa_build_route_table(): array {
	$filters_before          = $GLOBALS['_filters'];
	$routes_before           = $GLOBALS['_rest_routes'] ?? array();
	$GLOBALS['_filters']     = array();
	$GLOBALS['_rest_routes'] = array();
	try {
		( new Aura_Worker() )->init();
		do_action( 'rest_api_init' );
		$table = $GLOBALS['_rest_routes'];
	} finally {
		$GLOBALS['_rest_routes'] = $routes_before;
		$GLOBALS['_filters']     = $filters_before;
	}
	return $table;
}

/**
 * The plugin's LIVE REST route table, memoised for the process: every route
 * Aura_Worker registers, as `'/aura/v1/update/core' => [ endpoint, ... ]`,
 * where each endpoint is the array the plugin handed register_rest_route()
 * (`methods`, `callback`, `permission_callback`, `args`).
 *
 * The table is a fact about the CODE, not about a test's state — the handlers
 * read $GLOBALS live, and Aura_Worker_Security's only per-request state is a
 * static that sa_reset_state() clears — so rebuilding it per test would buy
 * nothing. For the same reason it is NOT cleared in sa_reset_state(). A test
 * that needs a build under different conditions calls sa_build_route_table()
 * directly.
 *
 * @return array<string,array<int,array>> Route pattern => endpoints.
 */
function sa_registered_routes(): array {
	static $table = null;
	if ( null === $table ) {
		$table = sa_build_route_table();
	}
	return $table;
}

/**
 * A request the security layer will authenticate: the raw site token in the
 * header whose hash sa_token_hash() stored, on the given route and method.
 *
 * Deliberately does NOT install the token option — a test that deleted it (the
 * state Phase B's final step leaves behind) must be able to build a request
 * without silently putting it back.
 *
 * @param string $method HTTP method.
 * @param string $route  Full REST route, e.g. '/aura/v1/update/core'.
 * @return WP_REST_Request
 */
function sa_token_request( string $method, string $route ): WP_REST_Request {
	$request = new WP_REST_Request();
	$request->set_method( $method );
	$request->set_route( $route );
	$request->set_header( 'X-Aura-Token', SA_RAW_SITE_TOKEN );
	return $request;
}

/**
 * Authenticate this request the way core's Application Password path does:
 * the user is logged in, rest_get_authenticated_app_password() names the
 * password, and `application_password_did_authenticate` fires with the
 * (user, item) pair WordPress hands it.
 *
 * Through do_action(), never by writing the capture directly: whether
 * Aura_Worker_Security is LISTENING is part of what the unbind seam depends
 * on, and a helper that set the static itself would keep every test green
 * with the listener unregistered. A tag with no listener is an exception
 * rather than a silent no-op, for the same reason.
 *
 * @param int    $user_id The authenticating user.
 * @param string $uuid    The Application Password's uuid.
 * @return void
 * @throws RuntimeException When nothing is listening for the capture hook.
 */
function sa_authenticate_app_password( int $user_id, string $uuid ): void {
	if ( empty( $GLOBALS['_filters']['application_password_did_authenticate'] ) ) {
		throw new RuntimeException( 'nothing is listening for application_password_did_authenticate — call Aura_Worker_Security::init() first, or this proves nothing' );
	}
	$GLOBALS['_logged_in']         = true;
	$GLOBALS['_current_user']      = $user_id;
	$GLOBALS['_current_user_id']   = $user_id;
	$GLOBALS['_rest_app_password'] = $uuid; // rest_get_authenticated_app_password()
	do_action( 'application_password_did_authenticate', new WP_User( $user_id ), array( 'uuid' => $uuid ) );
}

/**
 * Drive the REAL token-only path — Layer 2.5 of
 * Aura_Worker_Security::validate_request(): a request presenting the site
 * token with no application-password user, which resolves an administrator
 * and runs as them.
 *
 * Nothing here fakes the run-as record. The helper only supplies the request
 * state (a valid token, an administrator to resolve, no logged-in user) and
 * lets production code decide, so a test built on it goes red if Layer 2.5
 * stops recording what it did.
 *
 * $GLOBALS['_admins'] is set to $admin ALONE, which is what makes the fallback
 * observable: with `aura_worker_connect_user_id` deleted (the state Phase B
 * leaves), resolve_connect_user() returns the first administrator, whoever
 * that is — not necessarily the user the unbind marker recorded.
 *
 * @param int $admin The administrator the site can resolve.
 * @return int The user the request actually ran as.
 * @throws RuntimeException When the security layer refused the request.
 */
function sa_token_run_as( int $admin ): int {
	sa_token_hash();                  // installs the digest of SA_RAW_SITE_TOKEN
	$GLOBALS['_admins']    = array( $admin );
	$GLOBALS['_logged_in'] = false;   // no app-password user: Layer 2.5's precondition
	$security = new Aura_Worker_Security();
	$result   = $security->validate_request( sa_token_request( 'POST', '/aura/v2/snapshot' ) );
	if ( true !== $result ) {
		$code = is_wp_error( $result ) ? $result->get_error_code() : var_export( $result, true );
		throw new RuntimeException( "the security layer refused the token-only request ({$code}) — no run-as happened, so the test would prove nothing" );
	}
	// What wp_set_current_user() does in WordPress and this file's stub does
	// not: a request that has been set to a user IS logged in as them.
	$GLOBALS['_logged_in']       = true;
	$GLOBALS['_current_user_id'] = (int) $GLOBALS['_current_user'];
	return (int) $GLOBALS['_current_user'];
}

/**
 * A human at the keyboard: core authenticated this REST request from a cookie
 * session with a verified nonce (rest_cookie_collect_status() sets the global
 * this reads), and no Application Password is involved.
 *
 * @param int $user_id The signed-in user.
 * @return void
 */
function sa_cookie_session( int $user_id ): void {
	$GLOBALS['_logged_in']            = true;
	$GLOBALS['_current_user']         = $user_id;
	$GLOBALS['_current_user_id']      = $user_id;
	$GLOBALS['_rest_app_password']    = null;
	$GLOBALS['wp_rest_auth_cookie']   = true;
}

/**
 * Run the permission_callback the LIVE route table registered for this
 * request's route and method — and nothing else. The route callback is never
 * reached, so a refusal proved here is a refusal proved before any handler
 * could act.
 *
 * A route the table does not carry is an exception, never a silent skip: an
 * enumeration that quietly matched nothing is exactly the vacuous pass this
 * seam exists to prevent.
 *
 * @param WP_REST_Request $request The request.
 * @return mixed Whatever the permission callback answered.
 * @throws RuntimeException When no registered route/method matches.
 */
function sa_dispatch_permission( WP_REST_Request $request ) {
	$endpoint = sa_route_endpoint( $request );
	return call_user_func( $endpoint['permission_callback'], $request );
}

/**
 * The ONE endpoint the LIVE route table registered for this request's route
 * and method — the array the plugin handed register_rest_route().
 *
 * A route the table does not carry is an exception, never a silent skip: an
 * enumeration that quietly matched nothing is exactly the vacuous pass these
 * seams exist to prevent.
 *
 * @param WP_REST_Request $request The request.
 * @return array The registered endpoint.
 * @throws RuntimeException When no registered route/method matches.
 */
function sa_route_endpoint( WP_REST_Request $request ): array {
	$route  = $request->get_route();
	$method = strtoupper( $request->get_method() );
	foreach ( sa_registered_routes() as $registered => $endpoints ) {
		// A registered route is a PATTERN ('/aura/v2/rollback/(?P<plugin>...)');
		// core matches the request path against it, so this does too.
		if ( $registered !== $route && 1 !== preg_match( '#^' . $registered . '$#', $route ) ) {
			continue;
		}
		foreach ( $endpoints as $endpoint ) {
			$methods = array();
			foreach ( (array) $endpoint['methods'] as $declared ) {
				foreach ( explode( ',', (string) $declared ) as $one ) {
					$methods[] = strtoupper( trim( $one ) );
				}
			}
			if ( ! in_array( $method, $methods, true ) ) {
				continue;
			}
			return $endpoint;
		}
	}
	throw new RuntimeException( "no registered route matches {$method} {$route}" );
}

/**
 * Dispatch a request the way WP_REST_Server does: the registered ARGUMENTS
 * first, then the permission callback, then the route callback. The point of
 * going through here rather than calling a handler directly is the first step
 * — core refuses a request whose `required` argument is missing before any
 * handler runs, so a route arg declared `required` is a real refusal a
 * handler-level test can never see (#434 Task 8).
 *
 * What it implements, in core's order (WP_REST_Request::has_valid_params(),
 * ::sanitize_params(), WP_REST_Server::respond_to_request()):
 *   - each arg's `required` => `rest_missing_callback_param`, 400;
 *   - each present arg's `validate_callback` (false or WP_Error => 400);
 *   - the endpoint-level `validate_callback`, called with the request;
 *   - each present arg's `sanitize_callback`, written back onto the request;
 *   - the permission callback (a WP_Error is answered at its own status);
 *   - the route callback.
 *
 * What it deliberately does NOT implement is core's TYPE validation
 * (`rest_parse_request_arg` on `type`/`enum`/`format`), which would make this
 * helper the authority on values the handler must judge for itself: every
 * caller reaching a handler directly — most of this suite, and Aura's own
 * retries — arrives without it. Omitting it therefore makes this seam MORE
 * permissive than WordPress, never less, so a refusal proved here is one the
 * plugin makes on its own and a pass proved here cannot be resting on core.
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response|WP_Error Whatever the route answered, or the 400 /
 *                                   permission refusal that pre-empted it.
 * @throws RuntimeException When no registered route/method matches.
 */
function sa_dispatch_route( WP_REST_Request $request ) {
	$endpoint = sa_route_endpoint( $request );
	$args     = isset( $endpoint['args'] ) && is_array( $endpoint['args'] ) ? $endpoint['args'] : array();

	foreach ( $args as $key => $arg ) {
		$value   = $request->get_param( $key );
		$present = null !== $value;
		if ( ! empty( $arg['required'] ) && ! $present ) {
			return new WP_REST_Response(
				array(
					'code'    => 'rest_missing_callback_param',
					'message' => sprintf( 'Missing parameter(s): %s', $key ),
					'data'    => array( 'status' => 400 ),
				),
				400
			);
		}
		if ( $present && isset( $arg['validate_callback'] ) ) {
			$valid = call_user_func( $arg['validate_callback'], $value, $request, $key );
			if ( false === $valid || is_wp_error( $valid ) ) {
				return sa_rest_error_response( is_wp_error( $valid ) ? $valid : new WP_Error( 'rest_invalid_param', "Invalid parameter(s): {$key}", array( 'status' => 400 ) ) );
			}
		}
	}
	if ( isset( $endpoint['validate_callback'] ) ) {
		$valid = call_user_func( $endpoint['validate_callback'], $request );
		if ( false === $valid || is_wp_error( $valid ) ) {
			return sa_rest_error_response( is_wp_error( $valid ) ? $valid : new WP_Error( 'rest_invalid_param', 'Invalid parameter(s).', array( 'status' => 400 ) ) );
		}
	}
	foreach ( $args as $key => $arg ) {
		if ( isset( $arg['sanitize_callback'] ) && null !== $request->get_param( $key ) ) {
			$request->set_param( $key, call_user_func( $arg['sanitize_callback'], $request->get_param( $key ), $request, $key ) );
		}
	}

	$permission = call_user_func( $endpoint['permission_callback'], $request );
	if ( is_wp_error( $permission ) ) {
		return sa_rest_error_response( $permission );
	}
	if ( true !== $permission ) {
		return sa_rest_error_response( new WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', array( 'status' => 401 ) ) );
	}

	$response = call_user_func( $endpoint['callback'], $request );
	return is_wp_error( $response ) ? sa_rest_error_response( $response ) : $response;
}

/**
 * A WP_Error rendered the way the REST server renders one: the error's own
 * status, and the code on the body so a test can name what refused.
 *
 * @param WP_Error $error The refusal.
 * @return WP_REST_Response
 */
function sa_rest_error_response( WP_Error $error ): WP_REST_Response {
	$data = $error->get_error_data();
	return new WP_REST_Response(
		array(
			'code'    => $error->get_error_code(),
			'message' => $error->get_error_message(),
			'data'    => is_array( $data ) ? $data : array(),
		),
		isset( $data['status'] ) ? (int) $data['status'] : 500
	);
}

/**
 * Everything the stubs record when something actually HAPPENS: the mutating
 * stubs' own log ($GLOBALS['_mutations'] — upgrades, activations, file
 * deletes), every witnessed option write or delete, and every scheduled event.
 * A permission callback that refuses must leave all three exactly as it found
 * them.
 *
 * @return array
 */
function sa_snapshot_side_effects(): array {
	return array(
		'mutations'     => $GLOBALS['_mutations'],
		'option_writes' => $GLOBALS['_option_writes'],
		'scheduled'     => $GLOBALS['_scheduled'],
	);
}

function sa_reset_state(): void {
	// Static request state on the security layer: the UUID of the Application
	// Password that authenticated THIS request (#434 Phase A copies it into
	// the marker). A static survives the test that set it, so it is cleared
	// here like every $GLOBALS store below.
	if ( class_exists( 'Aura_Worker_Security' ) ) {
		Aura_Worker_Security::_set_authenticating_uuid_for_tests( null ); // clears the captured user too
		// The other piece of per-request identity: the user Layer 2.5 ran a
		// token-only request as (#434 Task 6). A static, so one test's run-as
		// would otherwise still be "the departed binding" in the next.
		Aura_Worker_Security::_set_ran_as_for_tests( null );
	}
	if ( class_exists( 'Aura_Worker_Call_Context' ) ) {
		Aura_Worker_Call_Context::reset(); // the dispatching route is a static too
	}
	$GLOBALS['_app_passwords']           = array();
	$GLOBALS['_app_passwords_available'] = true;
	$GLOBALS['_app_passwords_delete_fail'] = false;
	$GLOBALS['_fail_delete_app_password']  = null; // ONE uuid whose delete fails; see the stub above.
	$GLOBALS['_sa_app_password_read_fail']  = array(); // user_id => true: that user's app-password meta row cannot be read (#434 I5).
	$GLOBALS['_sa_app_password_scan_fail']  = false; // the site-wide holder statement itself fails (#434 Task 9).
	$GLOBALS['_sa_app_password_scan_answer'] = null; // replaces that statement's result SET outright — an array of rows (#434 Task 9).
	$GLOBALS['_sa_app_password_scan_rewrite_probe'] = null; // stamps the OWNER rows of that answer with a foreign nonce (#434 Task 10).
	$GLOBALS['_sa_steal_site_claim_during_mint'] = false;
	$GLOBALS['_sa_app_password_create_fails']    = false;
	$GLOBALS['_abilities']    = array();
	$GLOBALS['_options']      = array();
	$GLOBALS['_transients']   = array();
	$GLOBALS['_caps']         = null;
	$GLOBALS['_logged_in']    = false;
	$GLOBALS['_admins']       = array();
	$GLOBALS['_current_user'] = 0;
	$GLOBALS['_current_user_id'] = 0; // get_current_user_id()'s store — see the stub above.
	$GLOBALS['_did_actions']  = array();
	unset( $GLOBALS['_sa_uuid_fixed'] );
	$GLOBALS['_filters']      = array();
	$GLOBALS['_registered_settings'] = array();
	$GLOBALS['_settings_fields']    = array();
	$GLOBALS['_db_rows']          = array();
	$GLOBALS['_db_results_queue'] = array();
	$GLOBALS['_db_var']           = 0;
	$GLOBALS['_db_var_queue']     = array();
	$GLOBALS['_db_row']           = null;
	$GLOBALS['_db_prepared']      = array();
	$GLOBALS['_db_query_result']  = 0;
	$GLOBALS['_db_queries']       = array();
	$GLOBALS['_cache_deletes']    = array();
	$GLOBALS['_notoptions']       = array(); // Core's negative option cache — see get_option().
	$GLOBALS['_rows']             = array();
	$GLOBALS['_rows_autoload']    = array(); // Per-option autoload flag — see the top-level init above.
	$GLOBALS['_cas_racer']         = null;
	$GLOBALS['_insert_racer']      = null;
	$GLOBALS['_sa_gateway_secret'] = null; // sa_install_gateway_key()'s default signing key for sa_sign_ruleset().
	$GLOBALS['_cas_always_lose']   = false;
	$GLOBALS['_db_query_error']    = false;
	$GLOBALS['_sa_option_cache']      = array(); // This request's option cache — see get_option().
	$GLOBALS['_sa_wpdb_error']        = '';      // A driver-level failure on the next $wpdb read.
	$GLOBALS['_sa_wpdb_query_filtered_out'] = false; // A `query` filter blanks the SQL: wpdb::query() returns before flush() (#434 M12).
	$GLOBALS['_sa_wpdb_prepare_null']       = false; // wpdb::prepare() refuses the call and answers null (#434 N3).
	$GLOBALS['_sa_option_read_fail']  = array(); // Option names whose UNCACHED read fails at the driver.
	$GLOBALS['_sa_option_write_divert'] = array(); // Claimed writes that report success while the row diverges.
	$GLOBALS['_sa_option_write_fail'] = array(); // Option names update_option() must refuse to store.
	$GLOBALS['_sa_option_delete_fail'] = array(); // Option names the claim-conditional DELETE must fail on.
	$GLOBALS['_option_writes']        = array(); // Witnessed update_option()/delete_option() calls.
	$GLOBALS['_sa_before_swap']       = null;    // Runs between a read and its compare-and-swap.
	$GLOBALS['_sa_after_swap']        = null;    // Runs immediately after a successful compare-and-swap.
	$GLOBALS['_sa_after_store_read']  = null;    // Runs between accept()'s store read and its token read.
	$GLOBALS['_sa_after_option_read'] = null;    // Runs just after ONE uncached option read is answered (#434 Task 9).
	$GLOBALS['_posts']        = array();
	$GLOBALS['_post_meta']    = array();
	$GLOBALS['_cleaned_post_cache'] = array();
	$GLOBALS['_did_delete_expired'] = false;
	$GLOBALS['_users']        = array();
	$GLOBALS['_users_total']  = 0;
	$GLOBALS['_admin_total']  = 0;
	$GLOBALS['_user_queries'] = array();
	$GLOBALS['_post_counts']  = array();
	$GLOBALS['_home_url']       = 'https://example.com';
	$GLOBALS['_site_url']       = 'https://example.com';
	$GLOBALS['_wp_query_posts'] = array();
	$GLOBALS['_wp_queries']     = array();
	$GLOBALS['_post_content']   = array();
	$GLOBALS['_url_to_postid']  = array();
	$GLOBALS['_bloginfo']       = array();
	$GLOBALS['_thumbnails']     = array();
	$GLOBALS['_attached_files'] = array();
	$GLOBALS['_abilities']    = array();
	$GLOBALS['_ability_categories'] = array();
	$GLOBALS['_scheduled']    = array();
	$GLOBALS['_sa_state']     = array();
	$GLOBALS['_is_admin']       = false; // is_admin() — see the stub above.
	$GLOBALS['_is_multisite']   = false;
	$GLOBALS['_site_options']   = array();
	$GLOBALS['_user_meta']      = array();
	$GLOBALS['_cron_array']     = array();
	$GLOBALS['_cron_schedules'] = null;
	$GLOBALS['_http_response']  = null;
	$GLOBALS['_http_error']     = false;
	$GLOBALS['_mutations']      = array();
	unset( $GLOBALS['wp_rest_auth_cookie'] );
	$GLOBALS['_rest_app_password'] = null;
	if ( isset( $GLOBALS['wpdb'] ) ) {
		$GLOBALS['wpdb']->last_error = '';
		$GLOBALS['wpdb']->last_query = '';
		if ( property_exists( $GLOBALS['wpdb'], 'ready' ) ) {
			$GLOBALS['wpdb']->ready = true; // a test that took the handle down never leaks it (#434 M12)
		}
		if ( method_exists( $GLOBALS['wpdb'], 'sa_forget_last_result' ) ) {
			$GLOBALS['wpdb']->sa_forget_last_result(); // no test's result set is another test's stale answer (#434 N1)
		}
	}
	if ( class_exists( 'Aura_Worker_Rules' ) ) {
		Aura_Worker_Rules::reset_records();
		// A test-only seam and a REST-detection override, both statics: a test
		// that sets either and forgets to clear it would otherwise poison
		// every test that runs after it in the same process, silently, rather
		// than failing the test that actually left it set.
		Aura_Worker_Rules::$rest_request_override = null;
		Aura_Worker_Rules::$cookie_auth_override  = null;
	}
	// Update-tool fixtures: a test that seeds these and forgets to clear them
	// would otherwise leak into every later test's get_plugins()/
	// get_core_updates()/wp_get_theme() stub, in place of the intended
	// defaults (see the stubs' own comments a few hundred lines up).
	unset( $GLOBALS['_installed_plugins'], $GLOBALS['_core_updates'], $GLOBALS['_missing_themes'] );
	// Phase B's own step trace (#434 Task 4). Registered HERE, after $_filters
	// is emptied above, so every test starts with the recorder attached and an
	// empty trace: cleanup()'s fixed order — the token strictly last — is only
	// checkable if the order is observable, and do_action() is the one seam
	// that reports it without the test reaching inside the class.
	$GLOBALS['_unbind_trace'] = array();
	add_action(
		'aura_worker_unbind_step',
		static function ( $step ) {
			$GLOBALS['_unbind_trace'][] = (string) $step;
		}
	);
	$_SERVER['REMOTE_ADDR']   = '203.0.113.10';
}
