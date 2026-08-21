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

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
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
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
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
$GLOBALS['_filters']      = array();
$GLOBALS['_abilities']    = array();
$GLOBALS['_ability_categories'] = array();

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
		return true;
	}
}

// --- Option store ----------------------------------------------------------

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default = false ) {
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
		return $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, $value, $autoload = null ): bool {
		$GLOBALS['_options'][ $option ] = $value;
		$GLOBALS['_rows'][ $option ]    = maybe_serialize( $value );
		return true;
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
		$GLOBALS['_options'][ $option ] = $value;
		$GLOBALS['_rows'][ $option ]    = maybe_serialize( $value );
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
		return true;
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

if ( ! function_exists( 'get_users' ) ) {
	function get_users( array $args = array() ): array {
		return $GLOBALS['_admins'];
	}
}

// --- Hooks ------------------------------------------------------------------

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $tag, ...$args ): void {
		$GLOBALS['_did_actions'][] = array( 'tag' => $tag, 'args' => $args );
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
		$GLOBALS['_filters'][ $tag ][] = $callback;
		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $tag, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['_filters'][ $tag ][] = $callback;
		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $tag, $value, ...$args ) {
		foreach ( $GLOBALS['_filters'][ $tag ] ?? array() as $callback ) {
			$value = $callback( $value, ...$args );
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
		return @unlink( $file );
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
	}
}

if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( $response ) {
		return ( $response instanceof WP_REST_Response ) ? $response : new WP_REST_Response( $response, 200 );
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
		public string $last_error = '';
		public string $last_query = '';

		/**
		 * get_results returns the next queued result-set (for tools that run
		 * several SELECTs), falling back to the single $_db_rows for callers
		 * that only run one query.
		 */
		public function get_results( $query, $output = OBJECT ) {
			$this->last_query = (string) $query;
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
			$this->last_query = (string) $query;
			if ( preg_match( "/^SELECT option_value FROM \S+ WHERE option_name = '([^']+)' LIMIT 1$/", (string) $query, $m ) ) {
				$GLOBALS['_db_queries'][] = (string) $query;
				// The row, not the cache: $_rows is what the "database" holds.
				return isset( $GLOBALS['_rows'][ $m[1] ] ) ? $GLOBALS['_rows'][ $m[1] ] : null;
			}
			if ( ! empty( $GLOBALS['_db_var_queue'] ) ) {
				return array_shift( $GLOBALS['_db_var_queue'] );
			}
			return $GLOBALS['_db_var'];
		}

		/** get_row returns the configured single row. */
		public function get_row( $query = null, $output = OBJECT, $y = 0 ) {
			$this->last_query = (string) $query;
			return $GLOBALS['_db_row'];
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
			if ( preg_match( "/^SELECT option_name FROM \S+ WHERE option_name LIKE '([^']+)%' AND option_name < '([^']+)'$/", (string) $query, $m ) ) {
				$prefix = str_replace( array( '\\_', '\\%' ), array( '_', '%' ), stripslashes( $m[1] ) );
				$before = stripslashes( $m[2] );
				return array_values(
					array_filter(
						array_keys( $GLOBALS['_rows'] ),
						static function ( $k ) use ( $prefix, $before ) {
							return 0 === strpos( $k, $prefix ) && strcmp( $k, $before ) < 0;
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
			$GLOBALS['_db_queries'][] = $query;

			if ( preg_match( "/^INSERT INTO \S+ \(option_name, option_value, autoload\\) SELECT '([^']*)', '(.*)', '([^']*)' FROM DUAL WHERE NOT EXISTS \\( SELECT 1 FROM \S+ WHERE option_name = '([^']*)' \\)$/s", $query, $m ) ) {
				if ( ! empty( $GLOBALS['_db_query_error'] ) ) {
					return false; // An SQL error, which is NOT a lost race.
				}
				// A second request inserting between this caller's own
				// existence check (there is none — that's the point of a real
				// conditional INSERT) and this statement running.
				if ( ! empty( $GLOBALS['_insert_racer'] ) ) {
					$racer                    = $GLOBALS['_insert_racer'];
					$GLOBALS['_insert_racer'] = null;
					Aura_Worker_Rules::accept( $racer );
				}
				list( , $name, $value, ) = array_map( 'stripslashes', $m );
				if ( isset( $GLOBALS['_rows'][ $name ] ) ) {
					return 0; // A row is already there — lost the race.
				}
				$GLOBALS['_rows'][ $name ]    = $value;
				$GLOBALS['_options'][ $name ] = maybe_unserialize( $value );
				return 1;
			}

			if ( preg_match( "/^UPDATE \S+ SET option_value = '(.*)' WHERE option_name = '([^']+)' AND option_value = '(.*)'$/s", $query, $m ) ) {
				if ( ! empty( $GLOBALS['_db_query_error'] ) ) {
					return false; // An SQL error, which is NOT a lost race.
				}
				if ( ! empty( $GLOBALS['_cas_always_lose'] ) ) {
					return 0; // Contention that never resolves.
				}
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
				return 1;
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
	$GLOBALS['_rows']             = array(); // Raw, serialized bytes — the "database" the ruleset CAS reads/writes.
	$GLOBALS['_cas_racer']        = null;
	$GLOBALS['_insert_racer']     = null;
	$GLOBALS['_cas_always_lose']  = false;
	$GLOBALS['_db_query_error']   = false;
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
		 */
		public function delete( string $path, bool $recursive = false, $type = false ): bool {
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

// Load every shipped tool class so tool-level tests can instantiate them
// directly (the registry auto-loads the same set at construction time).
foreach ( glob( SA_PLUGIN_DIR . '/includes/tools/class-tool-*.php' ) as $tool_file ) {
	require_once $tool_file;
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

function sa_reset_state(): void {
	$GLOBALS['_abilities']    = array();
	$GLOBALS['_options']      = array();
	$GLOBALS['_transients']   = array();
	$GLOBALS['_caps']         = null;
	$GLOBALS['_logged_in']    = false;
	$GLOBALS['_admins']       = array();
	$GLOBALS['_current_user'] = 0;
	$GLOBALS['_did_actions']  = array();
	$GLOBALS['_filters']      = array();
	$GLOBALS['_db_rows']          = array();
	$GLOBALS['_db_results_queue'] = array();
	$GLOBALS['_db_var']           = 0;
	$GLOBALS['_db_var_queue']     = array();
	$GLOBALS['_db_row']           = null;
	$GLOBALS['_db_prepared']      = array();
	$GLOBALS['_db_query_result']  = 0;
	$GLOBALS['_db_queries']       = array();
	$GLOBALS['_cache_deletes']    = array();
	$GLOBALS['_rows']             = array();
	$GLOBALS['_cas_racer']        = null;
	$GLOBALS['_insert_racer']     = null;
	$GLOBALS['_cas_always_lose']  = false;
	$GLOBALS['_db_query_error']   = false;
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
	$GLOBALS['_wp_query_posts'] = array();
	$GLOBALS['_wp_queries']     = array();
	$GLOBALS['_post_content']   = array();
	$GLOBALS['_url_to_postid']  = array();
	$GLOBALS['_bloginfo']       = array();
	$GLOBALS['_thumbnails']     = array();
	$GLOBALS['_abilities']    = array();
	$GLOBALS['_ability_categories'] = array();
	$GLOBALS['_scheduled']    = array();
	$GLOBALS['_sa_state']     = array();
	$GLOBALS['_is_multisite']   = false;
	$GLOBALS['_site_options']   = array();
	$GLOBALS['_user_meta']      = array();
	$GLOBALS['_cron_array']     = array();
	$GLOBALS['_cron_schedules'] = null;
	$GLOBALS['_http_response']  = null;
	$GLOBALS['_http_error']     = false;
	if ( isset( $GLOBALS['wpdb'] ) ) {
		$GLOBALS['wpdb']->last_error = '';
		$GLOBALS['wpdb']->last_query = '';
	}
	$_SERVER['REMOTE_ADDR']   = '203.0.113.10';
}
