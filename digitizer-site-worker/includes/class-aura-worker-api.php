<?php
/**
 * REST API handler for SiteAgent.
 *
 * Registers and handles all /wp-json/aura/v1/ endpoints.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Worker_API {

	/**
	 * REST API namespace (v1).
	 *
	 * @var string
	 */
	const NAMESPACE = 'aura/v1';

	/**
	 * REST API namespace (v2).
	 *
	 * @var string
	 */
	const NAMESPACE_V2 = 'aura/v2';

	/**
	 * Security handler.
	 *
	 * @var Aura_Worker_Security
	 */
	private $security;

	/**
	 * Updater handler.
	 *
	 * @var Aura_Worker_Updater
	 */
	private $updater;

	/**
	 * Constructor.
	 *
	 * @param Aura_Worker_Security $security Security handler instance.
	 */
	public function __construct( Aura_Worker_Security $security ) {
		$this->security = $security;
		$this->updater  = new Aura_Worker_Updater();
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes() {
		// Magic link: receive site token from Aura dashboard (public — validated by transient).
		register_rest_route( self::NAMESPACE, '/connect', array(
			'methods'             => 'POST',
			'callback'            => array( new Aura_Worker_Magic_Link(), 'handle_connect' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'magic_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => __( 'One-time magic link ID generated during the connect flow.', 'digitizer-site-worker' ),
				),
				'token' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => __( 'Site token issued by the Aura dashboard.', 'digitizer-site-worker' ),
				),
				'dashboard_url' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
					'description'       => __( 'Base URL of the Aura dashboard that issued the token.', 'digitizer-site-worker' ),
				),
				'timestamp' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'description'       => __( 'Unix timestamp of the callback, for replay protection.', 'digitizer-site-worker' ),
				),
				'signature' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => __( 'HMAC-SHA256 signature of the connect payload.', 'digitizer-site-worker' ),
				),
				'grant_pubkey' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => __( 'Optional base64 Ed25519 gateway public key to provision for approval-grant verification. Covered by the signature.', 'digitizer-site-worker' ),
				),
			),
		) );

		// Status & health check (read-only).
		register_rest_route( self::NAMESPACE, '/status', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_status' ),
			'permission_callback' => array( $this->security, 'check_read_permission' ),
			'args'                => array(
				// Ruling S82 (Codex round-33 P2 on #88): see
				// Aura_Worker_Elementor_Door::maybe_restamp_observation_forward()'s
				// own docblock for the whole-DB-restore hole this closes
				// and why only Aura's own record can name it.
				//
				// Ruling S83 (Codex round-34 P1 on #88): CAPPED at
				// Aura_Worker_Door_Log::MAX_OBSERVATION_SEEN, never
				// merely "a non-negative integer" — an UNCAPPED
				// `PHP_INT_MAX - 1` (which the pre-ruling check above
				// happily accepted) made `restamp_observation_forward()`'s
				// own `$seen + 1` overflow a 64-bit int into a FLOAT,
				// which then floated straight through a `%d` placeholder
				// in `$wpdb->prepare()` uncontrolled — pinning every
				// LATER version this site could ever report. A custom
				// `aura_invalid_param` WP_Error (never a bare `false`,
				// which core would answer with its own generic
				// `rest_invalid_param`) names the actual reason plainly.
				//
				// Ruling S88 (Codex round-38 P2 on #88): the ceiling is
				// `MAX_OBSERVATION_SEEN - 2`, NEVER `MAX_OBSERVATION_SEEN`
				// itself — see that constant's own docblock
				// (Aura_Worker_Door_Log) for the exact arithmetic this
				// mirrors. A legal `MAX_OBSERVATION_SEEN - 1` used to
				// pass this check and come back out the OTHER end as a
				// SERVED observation of `MAX_OBSERVATION_SEEN + 1` --
				// restamp_observation_forward()'s own `$seen + 1` (one
				// increment), THEN versioned()'s own generic version
				// bump on the SAME mutating unit (a SECOND, unconditional
				// increment on top of what the restamp just wrote) --
				// which this SAME validator would then refuse FOREVER,
				// since it exceeds `MAX_OBSERVATION_SEEN`. Reserving the
				// top TWO integers means the largest value THIS check
				// ever accepts (`MAX_OBSERVATION_SEEN - 2`) can absorb
				// BOTH increments (restamp: `MAX_OBSERVATION_SEEN - 1`;
				// the bump: `MAX_OBSERVATION_SEEN`) without the served
				// observation ever exceeding `MAX_OBSERVATION_SEEN`
				// itself — the INVARIANT this ruling exists to hold: no
				// value this site could ever be made to serve is a value
				// ABOVE what this class's own ceiling constant permits.
				'door_observation_seen' => array(
					'required'          => false,
					'type'              => 'integer',
					'validate_callback' => function( $value ) {
						if ( null === $value ) {
							return true;
						}
						if ( ! is_numeric( $value ) || (int) $value != $value || (int) $value < 0 ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
							return new WP_Error( 'aura_invalid_param', 'door_observation_seen must be a non-negative integer.', array( 'status' => 400 ) );
						}
						if ( (int) $value > Aura_Worker_Door_Log::MAX_OBSERVATION_SEEN - 2 ) {
							return new WP_Error( 'aura_invalid_param', 'door_observation_seen exceeds the maximum accepted value.', array( 'status' => 400 ) );
						}
						return true;
					},
					'description'       => __( "Aura's own last-accepted `observation` for the door epoch named by `door_epoch` — a non-negative integer, capped at Aura_Worker_Door_Log::MAX_OBSERVATION_SEEN - 2 (two below the class constant, reserved so the restamp's own +1 and the version bump's own +1 that follow it can never carry the SERVED observation past the constant itself). When it EXCEEDS this site's current door version under that SAME epoch, the site treats it as a rewind of the witness itself (a whole-DB restore that left content unchanged but rewound the version alongside it) and forces its own version strictly past it before serving. Silently ignored (never honoured, never a bump) when it is not greater than the current version, or when `door_epoch` does not name this site's CURRENT epoch. A value above the cap is refused outright with a 400 `aura_invalid_param`, never honoured.", 'digitizer-site-worker' ),
				),
			),
		) );

		// Available updates (read-only).
		register_rest_route( self::NAMESPACE, '/updates', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_updates' ),
			'permission_callback' => array( $this->security, 'check_read_permission' ),
		) );

		// Update core.
		register_rest_route( self::NAMESPACE, '/update/core', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'update_core' ),
			'permission_callback' => array( $this->security, 'check_update_core_permission' ),
		) );

		// Update a plugin.
		register_rest_route( self::NAMESPACE, '/update/plugin', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'update_plugin' ),
			'permission_callback' => array( $this->security, 'check_update_plugins_permission' ),
			'args'                => array(
				'plugin' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => function( $value ) {
						return is_string( $value ) && preg_match( '/^[a-zA-Z0-9_\-]+\/[a-zA-Z0-9_\-]+\.php$/', $value );
					},
					'description'       => __( 'Plugin file path (e.g., akismet/akismet.php)', 'digitizer-site-worker' ),
				),
			),
		) );

		// Update a theme.
		register_rest_route( self::NAMESPACE, '/update/theme', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'update_theme' ),
			'permission_callback' => array( $this->security, 'check_update_themes_permission' ),
			'args'                => array(
				'theme' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => function( $value ) {
						return is_string( $value ) && preg_match( '/^[a-zA-Z0-9_\-]+$/', $value );
					},
					'description'       => __( 'Theme stylesheet slug', 'digitizer-site-worker' ),
				),
			),
		) );

		// Update translations.
		register_rest_route( self::NAMESPACE, '/update/translations', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'update_translations' ),
			'permission_callback' => array( $this->security, 'check_update_core_permission' ),
		) );

		// Database migration status (read-only).
		register_rest_route( self::NAMESPACE, '/database-status', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_database_status' ),
			'permission_callback' => array( $this->security, 'check_read_permission' ),
		) );

		// Self-update SiteAgent from a zip URL.
		register_rest_route( self::NAMESPACE, '/self-update', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'self_update' ),
			'permission_callback' => array( $this->security, 'check_update_plugins_permission' ),
			'args'                => array(
				'zip_url' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
					'validate_callback' => function( $value ) {
						// Single source of truth: defer to the same allowlist the
							// handler enforces, so the aura_worker_self_update_allowed_hosts
							// filter can actually extend the permitted sources instead of
							// being shadowed by a hard-coded pattern here.
							return is_string( $value ) && $this->is_allowed_self_update_url( $value );
					},
					'description'       => __( 'GitHub release zip URL for SiteAgent.', 'digitizer-site-worker' ),
				),
			),
		) );

		// Update database tables (core or plugin-specific).
		register_rest_route( self::NAMESPACE, '/update/database', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'update_database' ),
			'permission_callback' => array( $this->security, 'check_update_core_permission' ),
			'args'                => array(
				'plugin' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => __( 'Plugin migration key (e.g., elementor, woocommerce). Omit for core wp_upgrade.', 'digitizer-site-worker' ),
				),
			),
		) );

		// v2: Chunked batch plugin update with health-check auto-rollback.
		register_rest_route( self::NAMESPACE_V2, '/update/batch', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'batch_update_plugins' ),
			'permission_callback' => array( $this->security, 'check_update_plugins_permission' ),
			'args'                => array(
				'plugins' => array(
					'required'    => true,
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Array of plugin file paths (e.g. ["akismet/akismet.php"]).', 'digitizer-site-worker' ),
				),
				'chunk_size' => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 5,
					'minimum'           => 1,
					'sanitize_callback' => 'absint',
					'description'       => __( 'Number of plugins to process per chunk (default 5).', 'digitizer-site-worker' ),
				),
				'create_backup' => array(
					'required'    => false,
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'Whether to backup each plugin before updating (default true).', 'digitizer-site-worker' ),
				),
			),
		) );

		// v2: Health check (HTTP, PHP errors, WSOD, DB).
		register_rest_route( self::NAMESPACE_V2, '/health', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_health' ),
			'permission_callback' => array( $this->security, 'check_read_permission' ),
		) );

		// v2: Plugin rollback.
		register_rest_route( self::NAMESPACE_V2, '/rollback/(?P<plugin>[a-z0-9\-]+)', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rollback_plugin' ),
			'permission_callback' => array( $this->security, 'check_update_plugins_permission' ),
			'args'                => array(
				'plugin' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => __( 'Plugin folder slug to roll back.', 'digitizer-site-worker' ),
				),
				'backup_path' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => __( 'Absolute path to a specific backup zip. Omit to use the most recent backup.', 'digitizer-site-worker' ),
				),
			),
		) );

		// v2: Snapshots — capture-before-write for files/options (the reversal
		// substrate the Governed Power Tools use; created before a power write,
		// restored to undo it).
		register_rest_route( self::NAMESPACE_V2, '/snapshot', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'create_snapshot' ),
			'permission_callback' => array( $this->security, 'check_admin_permission' ),
			'args'                => array(
				'kind' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => __( 'Snapshot kind: "file" or "option".', 'digitizer-site-worker' ),
				),
				'target' => array(
					'required'    => true,
					'type'        => 'string',
					'description' => __( 'File path (kind=file) or option name (kind=option).', 'digitizer-site-worker' ),
				),
			),
		) );

		register_rest_route( self::NAMESPACE_V2, '/snapshot/restore', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'restore_snapshot' ),
			'permission_callback' => array( $this->security, 'check_admin_permission' ),
			'args'                => array(
				'id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => __( 'Snapshot id to restore.', 'digitizer-site-worker' ),
				),
				'aura_ref' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => __( "Aura's correlation id for this restore; echoed on the door-log entry. When sent, it is BOUND INTO THE GRANT: on a grant-enforced site the approval grant must be minted over { id, aura_ref }, not { id } alone.", 'digitizer-site-worker' ),
				),
			),
		) );

		register_rest_route( self::NAMESPACE_V2, '/snapshots', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_snapshots' ),
			'permission_callback' => array( $this->security, 'check_read_permission' ),
		) );

		// POST /aura/v2/rules — accept the client's signed operator ruleset.
		register_rest_route( self::NAMESPACE_V2, '/rules', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'receive_rules' ),
			'permission_callback' => array( $this->security, 'check_admin_permission' ),
			'args'                => array(
				// NOT required (#434 Task 8). An unkeyed site — one that holds
				// no gateway public key and can therefore verify nothing — ends
				// its binding with a bare `{ unbind: true, … }` body and no
				// envelope at all; with `required => true` WordPress's own
				// argument validation would 400 that body before
				// receive_rules() ever ran. WHICH of the two forms a request is
				// is decided in the handler, the one place both a dispatched
				// request and a direct call reach.
				'ruleset'  => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				// The bare unbind body (#434 Task 8). Declared for the schema;
				// NOTHING downstream trusts these to have been validated or
				// sanitized here — a direct call reaches the handler without
				// core's argument pipeline, so Aura_Worker_Rules
				// ::accept_bare_unbind() validates every one of them itself.
				'unbind'   => array(
					'required' => false,
					'type'     => 'boolean',
				),
				'site_ref' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'client'   => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'seq'      => array(
					'required' => false,
					'type'     => 'integer',
				),
				'final'    => array(
					'required' => false,
					'type'     => 'boolean',
				),
			),
		) );

		// v1: reject a held Elementor-door write outright — the operator's "no"
		// (spec §3.6-3.7). The site's own approval channel for the "yes" is
		// elementor_replay_ability; this is its refusal counterpart.
		register_rest_route( self::NAMESPACE, '/door/reject', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'reject_door_holds' ),
			'permission_callback' => array( $this->security, 'check_admin_permission' ),
			'args'                => array(
				'refs' => array(
					'required'          => true,
					'type'              => 'array',
					'items'             => array( 'type' => 'string' ),
					'sanitize_callback' => array( __CLASS__, 'sanitize_door_refs' ),
					'description'       => __( 'Hold references (door_…) to reject.', 'digitizer-site-worker' ),
				),
			),
		) );

		// v1: Aura's ack of the door log (spec §3.8, §3.10) — raises the site's
		// ack floor and lets it drop everything at or under it.
		register_rest_route( self::NAMESPACE, '/door/ack', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'ack_door_log' ),
			'permission_callback' => array( $this->security, 'check_admin_permission' ),
			'args'                => array(
				'epoch' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => __( "The log epoch Aura is acking; ignored (acks nothing) when it does not match the site's current one.", 'digitizer-site-worker' ),
				),
				'seq' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'description'       => __( "Highest seq of Aura's contiguous committed prefix.", 'digitizer-site-worker' ),
				),
			),
		) );

		// v1: Aura's decision to rotate the door-log epoch after `/status`
		// reported `rewind.detected` (Ruling P20). A WRITE on the log's
		// identity, so it is grant-gated — `/status` itself never rotates.
		register_rest_route( self::NAMESPACE, '/door/rotate', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rotate_door_epoch' ),
			'permission_callback' => array( $this->security, 'check_admin_permission' ),
			'args'                => array(
				'epoch' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => __( "The log epoch Aura saw the rewind under; the site rotates only when it is still the current one, so a retry of a rotation that already happened changes nothing.", 'digitizer-site-worker' ),
				),
			),
		) );

	}

	/**
	 * The `refs` sanitize_callback for POST /aura/v1/door/reject, and the same
	 * pass the handler re-applies to whatever it reads off the request — one
	 * source of truth for both the registered pipeline and a direct call that
	 * bypasses it (the pattern is_allowed_self_update_url() already uses).
	 * Non-string entries are dropped (no result entry is owed for a value that
	 * was never a ref); survivors are sanitize_text_field()d and the list is
	 * capped at 50. A survivor that sanitizes to something no hold ever used —
	 * garbage characters, or simply unknown — is not special-cased here: it is
	 * answered `not_held` downstream by Aura_Worker_Door_Holds::reject() itself,
	 * exactly like any other ref nothing is held under.
	 *
	 * @param mixed $value Raw `refs` value.
	 * @return string[]
	 */
	public static function sanitize_door_refs( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) ) {
				continue;
			}
			$out[] = sanitize_text_field( $item );
			if ( count( $out ) >= 50 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * GET /aura/v1/status
	 *
	 * Returns comprehensive site health information.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response Site status data.
	 */
	public function get_status( $request ) {
		global $wpdb;

		// Get active theme.
		$theme = wp_get_theme();

		// Get all plugins.
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins    = get_plugins();
		$active_plugins = get_option( 'active_plugins', array() );

		$plugins = array();
		foreach ( $all_plugins as $file => $data ) {
			$plugins[] = array(
				'file'    => $file,
				'name'    => $data['Name'],
				'version' => $data['Version'],
				'active'  => in_array( $file, $active_plugins, true ),
				'slug'    => dirname( $file ),
			);
		}

		// Get WordPress environment info.
		$status = array(
			'aura_worker_version' => AURA_WORKER_VERSION,
			'wp_version'          => get_bloginfo( 'version' ),
			'php_version'         => phpversion(),
			'mysql_version'       => $wpdb->db_version(),
			'db_version'          => get_option( 'db_version' ),
			'site_url'            => get_site_url(),
			'home_url'            => get_home_url(),
			'is_multisite'        => is_multisite(),
			'locale'              => get_locale(),
			'timezone'            => wp_timezone_string(),
			'memory_limit'        => WP_MEMORY_LIMIT,
			'max_upload_size'     => wp_max_upload_size(),
			'debug_mode'          => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'theme'               => array(
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
				'slug'    => $theme->get_stylesheet(),
				'parent'  => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
			),
			'plugins'             => $plugins,
			'plugin_count'        => array(
				'total'  => count( $all_plugins ),
				'active' => count( $active_plugins ),
			),
			'db_prefix'           => $wpdb->prefix,
			'db_tables'           => count( $wpdb->get_results( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			'disk_usage'          => $this->get_disk_usage(),
			'server_software'     => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
			'timestamp'           => gmdate( 'c' ),
		);

		$unbound = Aura_Worker_Unbind::status_fragment();
		if ( null !== $unbound ) {
			// An OBJECT on the wire, always (#434 Task 4, M10). PHP's empty
			// array serialises as JSON `[]`, so the "no field was readable"
			// answer would reach Aura as an array where every other answer is
			// an object — and a strict parse of `unbound` as {at, site_ref}
			// would reject it and read the site as bound again. The key's
			// PRESENCE is the signal; its shape must not change with its
			// contents.
			$status['unbound'] = (object) $unbound;
		}

		// Why a tombstone will not finish (#434 Task 9). An Application
		// Password probe that cannot prove itself owes `app_passwords` forever
		// — cleanup_complete stays false and Aura waits on a site that will
		// never converge — and until now the reason was visible nowhere. The
		// key's PRESENCE is the signal; an object, like `unbound` above, so its
		// shape never changes with its contents.
		$probe = Aura_Worker_Magic_Link::probe_unproven_report();
		if ( null !== $probe ) {
			$status['app_password_probe_unproven'] = (object) $probe;
		}

		// The Elementor door (2.16.0, spec §3.10). The reconciler runs FIRST,
		// so what a died request left behind is settled in the very response
		// that reports it: `/status` is the only clock this site has. An
		// OBJECT on the wire, like `unbound` above, and ABSENT on a site with
		// no door AND no persisted door state — Aura keys on its presence to
		// decide whether this site is governed at all.
		//
		// present(), not active() (Ruling P28): deactivating Elementor after
		// the governor stored holds, unacked rows or an in-flight claim used
		// to drop the fragment AND skip the reconciler on the next request,
		// so Aura lost sight of outstanding approvals and terminal results
		// while nothing settled them. Persisted door state is enough to keep
		// reporting and reconciling; the fragment's own `active` says whether
		// Elementor is still there.
		if ( class_exists( 'Aura_Worker_Elementor_Door' ) && Aura_Worker_Elementor_Door::present() ) {
			Aura_Worker_Elementor_Door::reconcile();
			// Ruling S82 (Codex round-33 P2 on #88): the REST arg's own
			// `validate_callback` already refused anything but a
			// non-negative integer or absence; `get_param()` answers
			// `null` when the caller sent nothing at all, which
			// `status_fragment()`'s own default treats as a no-op.
			$observation_seen = $request->get_param( 'door_observation_seen' );
			$status['door']   = (object) Aura_Worker_Elementor_Door::status_fragment(
				(int) $request->get_param( 'door_after' ),
				(string) $request->get_param( 'door_epoch' ), // the epoch the cursor belongs to; '' ⇒ served from 0
				null === $observation_seen ? null : (int) $observation_seen
			);
		}

		return rest_ensure_response( $status );
	}

	/**
	 * GET /aura/v1/updates
	 *
	 * Returns all available updates. Uses cached data by default.
	 * Add ?refresh=1 to force fresh check (may fail on low-memory servers).
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response Available updates.
	 */
	public function get_updates( $request ) {
		$refresh = (bool) $request->get_param( 'refresh' );
		$updates = $this->updater->get_available_updates( $refresh );
		return rest_ensure_response( $updates );
	}

	/**
	 * POST /aura/v1/update/core
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Update result, or WP_Error(403) if a grant is required.
	 */
	public function update_core( $request ) {
		$guard = Aura_Worker_Grant::require_for( $request, 'wp.update.core', array() );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		// No narrower resource in the vocabulary: a freeze catches this, and
		// nothing else can. (Spec §11 keeps theme/db types out until asked for.)
		$rule = Aura_Worker_Rules::guard_rest( array( array( 'type' => 'site', 'id' => '*' ) ), 'wp.update.core' );
		if ( is_wp_error( $rule ) ) {
			return $rule;
		}

		$result = $this->updater->update_core();
		$status = $result['success'] ? 200 : 500;
		return new WP_REST_Response( Aura_Worker_Rules::with_warnings( $result ), $status );
	}

	/**
	 * POST /aura/v1/update/plugin
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Update result, or WP_Error(403) if a grant is required.
	 */
	public function update_plugin( $request ) {
		$plugin_file = $request->get_param( 'plugin' );

		$guard = Aura_Worker_Grant::require_for( $request, 'wp.update.plugin', array( 'plugin' => $plugin_file ) );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		// Operator rules. This handler bypasses execute_tool() (it calls the
		// updater directly), so it enforces here; see Aura_Worker_Rules for why
		// there are exactly two enforcement points and this is the second.
		$rule = Aura_Worker_Rules::guard_rest(
			array( array( 'type' => 'plugin', 'id' => Aura_Worker_Rules::plugin_slug( $plugin_file ) ) ),
			'wp.update.plugin'
		);
		if ( is_wp_error( $rule ) ) {
			return $rule;
		}

		// Validate plugin exists.
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins = get_plugins();

		if ( ! isset( $all_plugins[ $plugin_file ] ) ) {
			return new WP_REST_Response( Aura_Worker_Rules::with_warnings( array(
				'success' => false,
				'error'   => __( 'Plugin not found.', 'digitizer-site-worker' ),
			) ), 404 );
		}

		$result = $this->updater->update_plugin( $plugin_file );
		$status = $result['success'] ? 200 : 500;
		return new WP_REST_Response( Aura_Worker_Rules::with_warnings( $result ), $status );
	}

	/**
	 * POST /aura/v1/update/theme
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Update result, or WP_Error(403) if a grant is required.
	 */
	public function update_theme( $request ) {
		$theme_slug = $request->get_param( 'theme' );

		$guard = Aura_Worker_Grant::require_for( $request, 'wp.update.theme', array( 'theme' => $theme_slug ) );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		// No narrower resource in the vocabulary: a freeze catches this, and
		// nothing else can. (Spec §11 keeps theme/db types out until asked for.)
		$rule = Aura_Worker_Rules::guard_rest( array( array( 'type' => 'site', 'id' => '*' ) ), 'wp.update.theme' );
		if ( is_wp_error( $rule ) ) {
			return $rule;
		}

		// Validate theme exists.
		$theme = wp_get_theme( $theme_slug );
		if ( ! $theme->exists() ) {
			return new WP_REST_Response( Aura_Worker_Rules::with_warnings( array(
				'success' => false,
				'error'   => __( 'Theme not found.', 'digitizer-site-worker' ),
			) ), 404 );
		}

		$result = $this->updater->update_theme( $theme_slug );
		$status = $result['success'] ? 200 : 500;
		return new WP_REST_Response( Aura_Worker_Rules::with_warnings( $result ), $status );
	}

	/**
	 * POST /aura/v1/update/translations
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Update result, or WP_Error(403) if a grant is required.
	 */
	public function update_translations( $request ) {
		$guard = Aura_Worker_Grant::require_for( $request, 'wp.update.translations', array() );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		// No narrower resource in the vocabulary: a freeze catches this, and
		// nothing else can. (Spec §11 keeps theme/db types out until asked for.)
		$rule = Aura_Worker_Rules::guard_rest( array( array( 'type' => 'site', 'id' => '*' ) ), 'wp.update.translations' );
		if ( is_wp_error( $rule ) ) {
			return $rule;
		}

		$result = $this->updater->update_translations();
		$status = $result['success'] ? 200 : 500;
		return new WP_REST_Response( Aura_Worker_Rules::with_warnings( $result ), $status );
	}

	/**
	 * GET /aura/v1/database-status
	 *
	 * Returns pending database migration status for detected plugins.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response Database migration status.
	 */
	public function get_database_status( $request ) {
		$status = $this->updater->get_database_status();
		return rest_ensure_response( $status );
	}

	/**
	 * POST /aura/v1/self-update
	 *
	 * Updates the SiteAgent plugin from a GitHub release zip URL.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Update result with version info, or WP_Error(403) if a grant is required.
	 */
	public function self_update( $request ) {
		$zip_url = $request->get_param( 'zip_url' );
		$sha256  = (string) $request->get_param( 'sha256' );

		// Bind sha256 into the grant when the gateway supplied it, so the Ed25519
		// signature covers the expected bytes too — the grant then can't be spent
		// against a different digest. Absent → { zip_url } only (back-compat with
		// gateways/releases that provide no digest).
		$grant_params = ( '' !== $sha256 )
			? array( 'zip_url' => $zip_url, 'sha256' => $sha256 )
			: array( 'zip_url' => $zip_url );
		$guard = Aura_Worker_Grant::require_for( $request, 'wp.self_update', $grant_params );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		// No narrower resource in the vocabulary: a freeze catches this, and
		// nothing else can. (Spec §11 keeps theme/db types out until asked for.)
		$rule = Aura_Worker_Rules::guard_rest( array( array( 'type' => 'site', 'id' => '*' ) ), 'wp.self_update' );
		if ( is_wp_error( $rule ) ) {
			return $rule;
		}

		// Source allowlist: only install self-update zips from the official repo.
		// Bounds a signed grant to a trusted source, so even an approved
		// self-update can't be pointed at attacker-hosted code.
		if ( ! $this->is_allowed_self_update_url( $zip_url ) ) {
			return new WP_REST_Response( Aura_Worker_Rules::with_warnings( array(
				'success' => false,
				'error'   => __( 'Self-update source not allowed.', 'digitizer-site-worker' ),
			) ), 400 );
		}

		$result = $this->updater->self_update( $zip_url, $sha256 );
		$status = $result['success'] ? 200 : 500;
		return new WP_REST_Response( Aura_Worker_Rules::with_warnings( $result ), $status );
	}

	/**
	 * Whether a self-update zip URL is from an allowlisted source.
	 *
	 * Defaults to the official GitHub repo release-download path
	 * (`github.com/Digitizers/SiteAgent/`) over HTTPS — the exact form the Aura
	 * gateway sends (a GitHub release `browser_download_url`). GitHub 302-redirects
	 * that URL to its asset CDN, but WordPress's HTTP layer follows the redirect
	 * internally, so the CDN host is never itself a `zip_url` input and does not
	 * need allowlisting. Override via the `aura_worker_self_update_allowed_hosts`
	 * filter (host => required path prefix, '' means any path on that host).
	 *
	 * @param string $url Candidate zip URL.
	 * @return bool
	 */
	private function is_allowed_self_update_url( $url ) {
		$url = (string) $url;
		if ( 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
			return false;
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		// Reject dot-segment traversal (raw OR percent-encoded). An HTTP transport
		// may normalize `..` before fetching, so a URL like
		// `/Digitizers/SiteAgent/releases/download/../../attacker/evil/x.zip` would
		// otherwise pass the prefix check but be fetched from another repo.
		$lower_path = strtolower( $path );
		if ( false !== strpos( $path, '..' )
			|| false !== strpos( rawurldecode( $path ), '..' )
			|| false !== strpos( $lower_path, '%2e' )
			|| false !== strpos( $lower_path, '%2f' )
			|| false !== strpos( $lower_path, '%5c' ) ) {
			return false;
		}

		// Only ever install a .zip — never an archive tarball or arbitrary asset.
		if ( '.zip' !== strtolower( substr( $path, -4 ) ) ) {
			return false;
		}

		// Default: the official repo's RELEASE-DOWNLOAD path only — not
		// /archive/… branch/tag tarballs, which would let a grant approve
		// arbitrary repo contents rather than a published release.
		$allowed = array(
			'github.com' => '/Digitizers/SiteAgent/releases/download/',
		);
		$allowed = apply_filters( 'aura_worker_self_update_allowed_hosts', $allowed );

		if ( ! isset( $allowed[ $host ] ) ) {
			return false;
		}
		$prefix = (string) $allowed[ $host ];
		if ( '' !== $prefix && 0 !== strpos( $path, $prefix ) ) {
			return false;
		}
		return true;
	}

	/**
	 * POST /aura/v1/update/database
	 *
	 * Runs core wp_upgrade or a plugin-specific database migration.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Update result, or WP_Error(403) if a grant is required.
	 */
	public function update_database( $request ) {
		$plugin = $request->get_param( 'plugin' );

		// Core DB optimization sends no target (binds {}); a specific plugin
		// migration binds { plugin }. Both must be individually approvable.
		$grant_params = ( null === $plugin || '' === $plugin ) ? array() : array( 'plugin' => $plugin );
		$guard        = Aura_Worker_Grant::require_for( $request, 'wp.update.database', $grant_params );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		// No narrower resource in the vocabulary: a freeze catches this, and
		// nothing else can. (Spec §11 keeps theme/db types out until asked for.)
		$rule = Aura_Worker_Rules::guard_rest( array( array( 'type' => 'site', 'id' => '*' ) ), 'wp.update.database' );
		if ( is_wp_error( $rule ) ) {
			return $rule;
		}

		$result = $this->updater->update_database( $plugin );
		$status = $result['success'] ? 200 : 500;
		return new WP_REST_Response( Aura_Worker_Rules::with_warnings( $result ), $status );
	}

	/**
	 * POST /aura/v2/update/batch
	 *
	 * Processes plugins in chunks, backing up and health-checking each one.
	 * Automatically rolls back if the health check fails after an update.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Batch update summary and per-plugin results, or WP_Error(403) if a grant is required.
	 */
	public function batch_update_plugins( $request ) {
		$plugins       = $request->get_param( 'plugins' );
		$chunk_size    = $request->get_param( 'chunk_size' ) ?? 5;
		$create_backup = $request->get_param( 'create_backup' ) ?? true;

		if ( empty( $plugins ) || ! is_array( $plugins ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => __( 'No plugins provided.', 'digitizer-site-worker' ),
			), 400 );
		}

		// Sanitize + validate the plugin file paths FIRST, then bind the grant over
		// the EXACT list that will be executed. Binding post-sanitize matters: a
		// value that normalizes into a different valid path must not be able to
		// slip past the exact-parameter grant. The gateway sends already-valid
		// paths, so sanitize is a no-op there and the bound hash still matches.
		$plugins = array_values( array_filter( array_map( 'sanitize_text_field', $plugins ), function( $p ) {
			return preg_match( '/^[a-zA-Z0-9_\-]+\/[a-zA-Z0-9_\-]+\.php$/', $p );
		} ) );

		if ( empty( $plugins ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => __( 'No valid plugin file paths provided.', 'digitizer-site-worker' ),
			), 400 );
		}

		// Bind the whole effective payload — including the safety options — so an
		// approved batch can't be replayed with create_backup flipped off.
		$guard = Aura_Worker_Grant::require_for(
			$request,
			'wp.update.batch',
			array(
				'plugins'       => $plugins,
				'chunk_size'    => (int) $chunk_size,
				'create_backup' => (bool) $create_backup,
			)
		);
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		// A batch touches the site (it is a maintenance operation) AND each
		// named plugin, so a freeze and a per-plugin rule both apply.
		$touches = array( array( 'type' => 'site', 'id' => '*' ) );
		foreach ( (array) $plugins as $p ) {
			$touches[] = array( 'type' => 'plugin', 'id' => Aura_Worker_Rules::plugin_slug( $p ) );
		}
		$rule = Aura_Worker_Rules::guard_rest( $touches, 'wp.update.batch' );
		if ( is_wp_error( $rule ) ) {
			return $rule;
		}

		$result = $this->updater->batch_update_plugins( $plugins, (int) $chunk_size, (bool) $create_backup );
		return new WP_REST_Response( Aura_Worker_Rules::with_warnings( array_merge( array( 'success' => true ), $result ) ), 200 );
	}

	/**
	 * GET /aura/v2/health
	 *
	 * Runs HTTP, PHP error log, white-screen, and database checks.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response Health check results.
	 */
	public function get_health( $request ) {
		$health = new Aura_Worker_Health();
		$result = $health->run_health_check();
		return rest_ensure_response( $result );
	}

	/**
	 * POST /aura/v2/rollback/{plugin}
	 *
	 * Backs up (if needed) and restores a plugin from a backup zip.
	 * If no backup_path is supplied, the most recent backup for the plugin is used.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Rollback result, or WP_Error(403) if a grant is required.
	 */
	public function rollback_plugin( $request ) {
		$plugin_slug = $request->get_param( 'plugin' );
		$backup_path = $request->get_param( 'backup_path' );

		// Bind BOTH the plugin and the caller-supplied backup_path: the handler
		// passes a request-provided backup_path straight to restore_plugin(), so
		// a grant approved for one backup must not be spent to restore a
		// different zip. An empty backup_path (server picks the most recent) binds
		// as '' and must be signed the same way by the gateway.
		$guard = Aura_Worker_Grant::require_for(
			$request,
			'wp.rollback',
			array(
				'plugin'      => $plugin_slug,
				'backup_path' => (string) $backup_path,
			)
		);
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$rule = Aura_Worker_Rules::guard_rest(
			array( array( 'type' => 'plugin', 'id' => Aura_Worker_Rules::plugin_slug( (string) $plugin_slug ) ) ),
			'wp.rollback'
		);
		if ( is_wp_error( $rule ) ) {
			return $rule;
		}

		$rollback = new Aura_Worker_Rollback();

		// If no specific backup path given, use the most recent backup.
		if ( empty( $backup_path ) ) {
			$backups = $rollback->list_backups( $plugin_slug );
			if ( empty( $backups ) ) {
				return new WP_REST_Response( Aura_Worker_Rules::with_warnings( array(
					'success' => false,
					'error'   => __( 'No backups found for this plugin.', 'digitizer-site-worker' ),
				) ), 404 );
			}
			$backup_path = $backups[0]['path'];
		}

		// SiteAgent's own restore runs under the self-update claim (Codex
		// round-24 P1); every other plugin restores as before.
		$result = $this->updater->restore_plugin_guarded( $rollback, $plugin_slug, $backup_path );
		$status = $result['success'] ? 200 : 500;
		return new WP_REST_Response( Aura_Worker_Rules::with_warnings( $result ), $status );
	}

	/**
	 * Resolve + jail a snapshot file target to wp-content, refusing wp-config.php.
	 *
	 * @param string $target Requested file path.
	 * @return string|WP_Error Safe real path, or a WP_Error to refuse.
	 */
	private function validate_snapshot_file_target( $target ) {
		$target = (string) $target;
		if ( '' === $target || false !== strpos( $target, "\0" ) ) {
			return new WP_Error( 'aura_invalid_target', __( 'Invalid file target.', 'digitizer-site-worker' ) );
		}

		$real = realpath( $target );
		if ( false === $real || ! is_file( $real ) ) {
			return new WP_Error( 'aura_not_found', __( 'File not found.', 'digitizer-site-worker' ) );
		}
		if ( 'wp-config.php' === strtolower( basename( $real ) ) ) {
			return new WP_Error( 'aura_refused', __( 'Refused: wp-config.php cannot be snapshotted.', 'digitizer-site-worker' ) );
		}

		$root = realpath( WP_CONTENT_DIR );
		if ( false === $root ) {
			return new WP_Error( 'aura_no_root', __( 'Content directory not found.', 'digitizer-site-worker' ) );
		}
		$in_jail = ( $real === $root ) || ( 0 === strpos( $real, rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR ) );
		if ( ! $in_jail ) {
			return new WP_Error( 'aura_outside_jail', __( 'Refused: path is outside wp-content.', 'digitizer-site-worker' ) );
		}

		return $real;
	}

	/**
	 * POST /aura/v2/snapshot
	 *
	 * Capture a file or option before a power write, so it can be reversed.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Result, or WP_Error(403) if a grant is required.
	 */
	public function create_snapshot( $request ) {
		$kind   = $request->get_param( 'kind' );
		$target = $request->get_param( 'target' );

		// Deliberately NOT rule-guarded: a snapshot captures state and changes
		// nothing, and a freeze must not refuse the safety net during exactly the
		// window it exists for. RulesRestCoverageTest lists this as the one
		// exemption.
		$guard = Aura_Worker_Grant::require_for(
			$request,
			'wp.snapshot.create',
			array( 'kind' => $kind, 'target' => $target )
		);
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$snapshots = new Aura_Worker_Snapshots();

		if ( 'file' === $kind ) {
			// Jail the file target to wp-content and refuse wp-config.php — otherwise
			// a caller could snapshot a sensitive absolute path (e.g. wp-config.php)
			// into the snapshots dir and fetch the payload, bypassing the read jail.
			$jail = $this->validate_snapshot_file_target( $target );
			if ( is_wp_error( $jail ) ) {
				return new WP_REST_Response( array( 'success' => false, 'error' => $jail->get_error_message() ), 400 );
			}
			$result = $snapshots->snapshot_file( $jail );
		} elseif ( 'option' === $kind ) {
			$result = $snapshots->snapshot_option( $target );
		} else {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => __( 'Unknown snapshot kind. Use "file" or "option".', 'digitizer-site-worker' ),
			), 400 );
		}

		$status = $result['success'] ? 200 : 400;
		return new WP_REST_Response( $result, $status );
	}

	/**
	 * POST /aura/v2/rules
	 *
	 * The gateway pushes the whole signed ruleset whenever a rule changes.
	 * Verification, the monotonic seq and last-known-good all live in
	 * Aura_Worker_Rules::accept(); this is transport.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function receive_rules( $request ) {
		// WHICH of the two forms this request is (#434 Task 8). Decided here,
		// not in a route-level validate_callback: the handler is the one place
		// BOTH a dispatched request and a direct call reach, and a second copy
		// of the rule in the route args could drift from this one.
		//
		// Strict: only an unambiguous `true` opts into the bare form. Anything
		// else falls through to the ruleset form and is answered by the shape
		// check below — never treated as an unbind on a guess.
		$bare = self::says_unbind( $request->get_param( 'unbind' ) );

		// Neither form. Answered BEFORE the 412: "send one of the two forms" is
		// the accurate answer for an empty body, where "reconnect this site to
		// Aura" would send the operator after a problem the site does not have.
		$ruleset = $request->get_param( 'ruleset' );
		if ( ! $bare && ( ! is_string( $ruleset ) || '' === trim( $ruleset ) ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'aura_ruleset_rejected',
					'error'   => 'Ruleset refused: send a signed `ruleset`, or the bare unbind body (`unbind`, `client`, `seq`).',
				),
				400
			);
		}

		// The 412 below must NOT pre-empt the unbind marker's fast path (#434,
		// spec §2.3): Phase B deletes the gateway key BEFORE the site token, so
		// a tombstone retried after a partial cleanup would otherwise be
		// stranded on "reconnect the site" forever — for a site Aura has
		// already disconnected. is_set() is the loose probe on purpose: it says
		// only "let accept() decide", and accept()'s step 0 does the strict,
		// fail-closed read (is_set_strict()) that actually rules.
		// …nor the bare unkeyed form (#434 Task 8), whose whole precondition is
		// that this site holds no usable key: answering it 412 "reconnect" is
		// exactly the dead end that form exists to open.
		if ( ! $bare && ! Aura_Worker_Unbind::is_set() && ! Aura_Worker_Grant::has_usable_key() ) {
			// No USABLE gateway key on this site: nothing can be verified.
			// Deliberately not is_enforced(), which means only "the option is
			// non-empty" — a truncated or corrupt key would then answer 400 on
			// every push, and Aura would spend forever telling the operator to
			// fix a rule that is fine instead of to reconnect the site.
			// Distinct from a bad document so the rollup can say "reconnect".
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'no_gateway_key',
					'error'   => 'This site holds no gateway public key and cannot verify a ruleset; reconnect it to Aura.',
				),
				412
			);
		}
		// Both forms answer through the SAME mapping below — the unbind body,
		// the WP_Error transport and the ordinary 200 — so the bare form's
		// response cannot drift from the enveloped one's shape (#434 Task 8).
		// The raw body values travel; accept_bare_unbind() validates them.
		$res = $bare
			? Aura_Worker_Rules::accept_bare_unbind(
				array(
					'site_ref' => $request->get_param( 'site_ref' ),
					'client'   => $request->get_param( 'client' ),
					'seq'      => $request->get_param( 'seq' ),
					'final'    => $request->get_param( 'final' ),
				)
			)
			: Aura_Worker_Rules::accept( (string) $ruleset );
		if ( is_array( $res ) && ! empty( $res['unbound'] ) ) {
			return self::unbind_response( $res );
		}
		if ( is_wp_error( $res ) ) {
			$data = $res->get_error_data();
			return new WP_REST_Response(
				array(
					'success' => false,
					// The code travels too: Aura retries 503 aura_ruleset_contended
					// and 500 aura_ruleset_store_failed, and does not treat either
					// as "your ruleset is bad" the way it treats a 400.
					'code'    => $res->get_error_code(),
					'error'   => $res->get_error_message(),
				),
				isset( $data['status'] ) ? (int) $data['status'] : 400
			);
		}
		// current() can still read null right after a successful accept(): a
		// lost insert race falls through to a repaired/re-read row whose
		// notoptions cache entry a persistent object cache would otherwise
		// keep stale (see insert_if_absent()) — so this is not merely
		// defensive, Fix 2 makes it reachable. Report seq as null rather than
		// fatal on an array-index into null.
		$rec = Aura_Worker_Rules::current();
		$seq = is_array( $rec ) && isset( $rec['seq'] ) ? (int) $rec['seq'] : null;
		return new WP_REST_Response(
			array(
				'success' => true,
				'seq'     => $seq,
			),
			200
		);
	}

	/**
	 * The unbind answer, on the wire (#434). `seq` is the one THIS request
	 * carried, so Aura can retire the tombstone it actually sent;
	 * `cleanup_complete` says whether Phase B finished — false leaves the
	 * tombstone pending and the site finishes the job itself. Authenticated by
	 * the token before Phase B deleted it.
	 *
	 * `leftovers` names what is still owed (#434 Task 4, M9). A false
	 * `cleanup_complete` has two opposite causes — something could not be
	 * proven removed, or the token was deliberately kept because the document
	 * was not `final` — and Aura cannot separate them from the bool alone.
	 * Empty exactly when nothing is owed; a non-empty list means a credential
	 * or a store this site still holds, and the tombstone must stay.
	 *
	 * Its default is the FAIL-CLOSED list, not `array()` (#434 Task 8). An
	 * empty list is a CLAIM — "this site is proven to owe nothing" — and Aura
	 * retires the tombstone on it; an answer that carries no list at all has
	 * made no such claim, and reading one into it is exactly how a tombstone
	 * naming a live administrator credential gets retired. Both of today's
	 * producers always send the list, and their own tests pin that, so the
	 * default answers a THIRD one that forgets — which is why this mapping is
	 * a separately callable function: a defence no caller can currently reach
	 * is a defence no test can pin, and an unpinned one is indistinguishable
	 * from a wrong one (round-1 LOW-2).
	 *
	 * @internal Transport only — a pure function of the answer it is handed.
	 *           Public solely so its own contract can be tested directly.
	 *
	 * @since 2.13.0
	 *
	 * @param array $res The unbind answer from Aura_Worker_Rules.
	 * @return WP_REST_Response
	 */
	public static function unbind_response( array $res ) {
		$leftovers = ( isset( $res['leftovers'] ) && is_array( $res['leftovers'] ) )
			? $res['leftovers']
			: array( 'app_passwords', 'options', 'ruleset', 'grant_pubkey' );
		return new WP_REST_Response(
			array(
				'success'          => true,
				'seq'              => (int) $res['seq'],
				'unbound'          => true,
				'cleanup_complete' => (bool) $res['cleanup_complete'],
				'leftovers'        => array_values( array_map( 'strval', $leftovers ) ),
			),
			200
		);
	}

	/**
	 * Does this request opt into the bare unbind form (#434 Task 8)?
	 *
	 * Strict on purpose. A form-encoded body carries `true` as the string
	 * '1' or 'true', so those count; everything else — absent, false, '0',
	 * 'yes', an array — does not. The cost of reading an ambiguous value as
	 * "not an unbind" is a 400 telling the caller to send one of the two
	 * forms; the cost of reading one as an unbind is a marker written from a
	 * body nobody meant as one.
	 *
	 * @since 2.13.0
	 *
	 * @param mixed $value The `unbind` parameter, raw.
	 * @return bool
	 */
	private static function says_unbind( $value ) {
		return true === $value || 1 === $value || '1' === $value || 'true' === $value;
	}

	/**
	 * POST /aura/v2/snapshot/restore
	 *
	 * Restore state captured by a prior snapshot (undo a power write).
	 *
	 * GRANT BINDING: `aura_ref` is bound into the grant whenever the request
	 * carries one, so Aura MUST mint the grant over `{ id, aura_ref }` when
	 * it sends a correlation id — a grant over `{ id }` alone is refused for
	 * such a request. The ref is written as the door-log entry's `ref`, which
	 * is how ingestion associates the terminal result with an AgentAction; a
	 * grant that did not cover it let anyone able to replay a valid restore
	 * grant substitute a different correlation id (Ruling P13). A request
	 * with NO `aura_ref` still binds `{ id }` alone, so legacy callers are
	 * unchanged.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Result, or WP_Error(403) if a grant is required.
	 */
	public function restore_snapshot( $request ) {
		$id = $request->get_param( 'id' );
		// Sanitised HERE as well as by the route's own callback: the value
		// bound into the grant, echoed onto the log entry, must be one
		// string read once, whatever dispatched the request.
		$raw_ref  = $request->get_param( 'aura_ref' );
		$aura_ref = is_string( $raw_ref ) ? sanitize_text_field( $raw_ref ) : '';

		$guard = Aura_Worker_Grant::require_for(
			$request,
			'wp.snapshot.restore',
			'' === $aura_ref ? array( 'id' => $id ) : array(
				'id'       => $id,
				'aura_ref' => $aura_ref,
			)
		);
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		// The envelope is not read yet, so this guard can only declare the
		// site: a freeze catches it, and nothing narrower can. A DOOR
		// envelope names what it covers, and is judged again on THOSE
		// touches inside open_restore_entry() below — a rule protecting one
		// page refuses a restore that would roll that page back, with the
		// same `aura_rule_blocked` 403 this guard returns (Ruling P12).
		// (Spec §11 keeps theme/db types out until asked for.)
		$rule = Aura_Worker_Rules::guard_rest( array( array( 'type' => 'site', 'id' => '*' ) ), 'wp.snapshot.restore' );
		if ( is_wp_error( $rule ) ) {
			return $rule;
		}

		$snapshots = new Aura_Worker_Snapshots();
		$record    = $snapshots->get( $id );
		$pre       = null;
		$seq       = null;
		if ( is_array( $record ) && ! Aura_Worker_Snapshots::belongs_to_current_blog( $record ) ) {
			// Answered HERE, before the door branch opens an entry or captures
			// anything: a foreign envelope is refused, not attempted, so this
			// blog's state is never captured for a restore that cannot run
			// (Ruling P15). restore() refuses it too — this is the early exit,
			// not the only guard.
			return new WP_REST_Response(
				Aura_Worker_Rules::with_warnings(
					array(
						'success' => false,
						'code'    => 'aura_foreign_blog',
						'error'   => Aura_Worker_Snapshots::FOREIGN_BLOG_ERROR,
					)
				),
				409
			);
		}
		if ( is_array( $record ) && in_array( (string) ( $record['door_kind'] ?? '' ), Aura_Worker_Snapshots::DOOR_KINDS, true ) ) {
			// A door restore is itself a governed write: RESERVE the log entry
			// first (a closed or failing log refuses the restore, as it refuses
			// any other write), then capture, then restore, then settle.
			$seq = Aura_Worker_Elementor_Door::open_restore_entry( $record, $aura_ref );
			if ( is_wp_error( $seq ) ) {
				return $seq;
			}
		}
		// ONE RELEASE FUNNEL FOR THE SEQ LEASE (Ruling P94).
		// `open_restore_entry()` HANDS the lease out of the governor when it
		// returns a seq, and the two restore termini no longer release it — so
		// every exit of the path below passes through here: the ones that reach
		// a terminus, and the ones that do not (a MISSING row has nothing to
		// settle, a rules refusal answers before it captures, a throw answers
		// nothing at all). It used to be released only by the termini, so those
		// other exits left the named lock held — and on a persistent database
		// connection a lock outlives the request that took it.
		try {
			return self::restore_after_admission( $snapshots, $record, $id, $seq, $aura_ref );
		} finally {
			if ( null !== $seq ) {
				Aura_Worker_Elementor_Door::release_seq_lease();
			}
		}
	}

	/**
	 * Everything a restore does once its door entry (if any) is reserved.
	 *
	 * Extracted so `restore_snapshot()` can wrap it in the single `finally`
	 * that owns the seq lease (Ruling P94); the body itself is unchanged.
	 *
	 * @param Aura_Worker_Snapshots $snapshots The snapshot store.
	 * @param array|null            $record    The envelope, or null when absent.
	 * @param string                $id        The envelope id.
	 * @param int|null              $seq       The reserved door entry, or null.
	 * @param string                $aura_ref  Aura's correlation id.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function restore_after_admission( Aura_Worker_Snapshots $snapshots, $record, $id, $seq, $aura_ref ) {
		$pre = null;
		if ( null !== $seq ) {
			$cap = Aura_Worker_Elementor_Door::pre_restore_capture( $record, $seq, $aura_ref );
			if ( empty( $cap['success'] ) ) {
				if ( ! Aura_Worker_Elementor_Door::settle_restore_entry( $seq, null, array( 'success' => false, 'error' => 'pre-restore capture failed: ' . (string) ( $cap['error'] ?? '' ) ) ) ) {
					return self::restore_unsettled( $seq, false );
				}
				return new WP_REST_Response( array( 'success' => false, 'error' => 'Could not capture the current state before restoring: ' . (string) ( $cap['error'] ?? '' ) ), 503 );
			}
			$pre = $cap['snapshot'];
			// The pre-restore id lands on the pending row BEFORE the restore
			// mutates anything: an interrupted restore must still name the
			// envelope that undoes it.
			if ( ! Aura_Worker_Door_Log::patch_pending( $seq, array( 'snapshot_id' => (string) $pre['id'] ) ) ) {
				if ( ! Aura_Worker_Elementor_Door::settle_restore_entry( $seq, $pre, array( 'success' => false, 'error' => 'could not record the pre-restore snapshot' ) ) ) {
					return self::restore_unsettled( $seq, false );
				}
				return new WP_REST_Response( array( 'success' => false, 'error' => 'Could not record the pre-restore snapshot; nothing was restored.' ), 503 );
			}
		}
		if ( ! is_array( $record ) ) {
			// 404 means ONE thing: the envelope is not on this site.
			return new WP_REST_Response( Aura_Worker_Rules::with_warnings( array( 'success' => false, 'error' => 'Snapshot not found.' ) ), 404 );
		}

		// THE SITE IS ABOUT TO BE WRITTEN (Ruling P65). A restore is a governed
		// mutation like any other, and everything between its admission above
		// and this line — the pre-restore capture, the patch — takes time a
		// rebind can land in. `execute()`'s callback fence catches that for an
		// ability call; this is the same fence, on the same predicate, for the
		// path that does not go through a callback at all. Read UNCACHED, so a
		// rebind another PHP process completed is seen (Ruling P64).
		$fence = ( null === $seq ) ? 'ok' : Aura_Worker_Elementor_Door::binding_unchanged_for_row( $seq );
		if ( 'ok' !== $fence ) {
			// A MISSING row has nothing to settle (Ruling P74); an UNREADABLE
			// one is attempted, and says which fact it is refusing on.
			if ( 'missing' !== $fence
				&& ! Aura_Worker_Elementor_Door::refuse_restore_entry( $seq, $pre, ( 'unreadable' === $fence ) ? 'fence_unreadable' : 'binding_changed' ) ) {
				return self::restore_unsettled( $seq, false );
			}
			if ( 'changed' === $fence ) {
				return new WP_REST_Response(
					Aura_Worker_Rules::with_warnings(
						array(
							'success' => false,
							'code'    => 'aura_binding_changed',
							'error'   => 'This site was rebound to another Aura client while this restore was being admitted; nothing was restored.',
						)
					),
					409
				);
			}
			// Not a proven rebind: the fence could not be established.
			// Retryable, and nothing was restored.
			return new WP_REST_Response(
				Aura_Worker_Rules::with_warnings(
					array(
						'success' => false,
						'code'    => 'aura_log_failed',
						'error'   => 'This site could not establish which Aura binding this restore belongs to; nothing was restored.',
					)
				),
				503
			);
		}

		$result = $snapshots->restore( $id );
		if ( null !== $seq && ! Aura_Worker_Elementor_Door::settle_restore_entry( $seq, $pre, $result ) ) {
			// The restore RAN — succeeded or failed, it touched the site — and
			// the log could not record what came of it (Ruling P19). A 200
			// here would tell Aura the rollback is recorded while the entry
			// sits pending, to be reconciled `interrupted` ten minutes later.
			return self::restore_unsettled( $seq, true );
		}

		// 200 restored; 409 a designated refusal (aura_trash_disabled); 500 an
		// execution failure of an envelope that IS here — never 404, which Aura
		// reads as "no longer restorable on the site".
		$status = $result['success'] ? 200 : ( isset( $result['code'] ) ? 409 : 500 );
		return new WP_REST_Response( Aura_Worker_Rules::with_warnings( $result ), $status );		return new WP_REST_Response( Aura_Worker_Rules::with_warnings( $result ), $status );
	}

	/**
	 * A restore whose door entry could not be settled: the site cannot say
	 * what happened, so neither does the response (Ruling P19). The same
	 * shape the governor answers an unsettled write with — `may_have_run`
	 * and the `seq` to look the entry up by — so one reader handles both.
	 *
	 * The row is deliberately LEFT pending: the reconciler calls it
	 * `interrupted`, which is the honest terminal state for an outcome
	 * nothing recorded.
	 *
	 * @param int  $seq The reserved entry.
	 * @param bool $ran Whether the restore itself ran.
	 * @return WP_Error
	 */
	private static function restore_unsettled( $seq, $ran ) {
		return new WP_Error(
			'aura_log_failed',
			$ran
				? 'The restore ran but this site could not record its outcome; check the site before retrying.'
				: 'This site could not record the outcome of this restore; nothing was restored.',
			array(
				'status'       => 503,
				'may_have_run' => (bool) $ran,
				'seq'          => (int) $seq,
			)
		);
	}

	/**
	 * GET /aura/v2/snapshots
	 *
	 * List stored snapshots (newest first).
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response
	 */
	public function list_snapshots( $request ) {
		$snapshots = new Aura_Worker_Snapshots();
		$list      = $snapshots->list_snapshots();

		return rest_ensure_response( array(
			'snapshots' => $list,
			'count'     => count( $list ),
		) );
	}

	/**
	 * POST /aura/v1/door/reject
	 *
	 * The operator's refusal of a held Elementor-door write (spec §3.6-3.7):
	 * removes the held row so the call can never be replayed. Not itself a
	 * site mutation — deliberately EXEMPT from the rule-guard invariant
	 * (RulesRestCoverageTest): it can only PREVENT a write from ever running,
	 * never cause one, so a freeze refusing it would strand exactly the calls
	 * an operator most wants to clear while frozen.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Result, or WP_Error(403) if a grant is required.
	 */
	public function reject_door_holds( $request ) {
		$refs = self::sanitize_door_refs( $request->get_param( 'refs' ) );

		$guard = Aura_Worker_Grant::require_for( $request, 'door.reject', array( 'refs' => $refs ) );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$results = array();
		foreach ( $refs as $ref ) {
			$results[ $ref ] = Aura_Worker_Door_Holds::reject( $ref );
		}

		return new WP_REST_Response( array( 'results' => $results ), 200 );
	}

	/**
	 * POST /aura/v1/door/ack
	 *
	 * Aura's ack of the door log (spec §3.8, §3.10): raises the site's ack
	 * floor so it can drop everything at or under it. Transport only — every
	 * rule (epoch match, floor-only-rises, reopen-under-the-bound) lives in
	 * Aura_Worker_Door_Log::ack(). Deliberately EXEMPT from the rule-guard
	 * invariant (RulesRestCoverageTest): this is governance-plane bookkeeping
	 * on Aura's OWN log, not a site mutation, and a freeze blocking it could
	 * starve the log toward its MAX_UNACKED bound and close the door — during
	 * exactly the window an operator most wants visibility into it.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Result, or WP_Error(403) if a grant is required.
	 */
	public function ack_door_log( $request ) {
		$epoch = (string) $request->get_param( 'epoch' );
		$seq   = (int) $request->get_param( 'seq' );

		$guard = Aura_Worker_Grant::require_for( $request, 'door.ack', array( 'epoch' => $epoch, 'seq' => $seq ) );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$result = Aura_Worker_Door_Log::ack( $epoch, $seq );

		// Ruling S15 (Codex round-6 P2 on #88): `committed: false` means the
		// whole unit rolled back — nothing was acked, nothing was purged, and
		// `Aura_Worker_Door_Log::ack()` did not even try to compute a
		// success-shaped answer. RETRYABLE, not a designated refusal: the
		// same `aura_log_failed` code `restore_unsettled()` already answers
		// with for the identical shape of fact — this site could not record
		// an outcome, so the caller repeats the call rather than assuming its
		// ack landed.
		//
		// `committed` is now TRI-STATE (Ruling S51, Codex round-20 P1 on
		// #88): `null` when even the durable witness itself could not be
		// read. `! $result['committed']` is already `true` for BOTH `false`
		// and `null` — retrying an ack is always safe regardless of which
		// (ack_write() is itself idempotent on a repeat with the SAME
		// epoch/seq, per its own docblock), so this check is unchanged and
		// correct as written; it is not the `not_held()`-style permanent
		// refusal `claim()` had to stop conflating the two for.
		if ( array_key_exists( 'committed', $result ) && ! $result['committed'] ) {
			return new WP_Error(
				'aura_log_failed',
				'This site could not record the outcome of this ack; nothing was acknowledged. Retry.',
				array( 'status' => 503 )
			);
		}

		$body = array(
			'acked'   => (int) $result['acked'],
			'floor'   => (int) $result['floor'],
			'epoch'   => Aura_Worker_Door_Log::epoch(),
			'unacked' => Aura_Worker_Door_Log::count_unacked(),
			'door'    => Aura_Worker_Elementor_Door::door_state(),
		);
		// STALE: the cursor named rows this log does not have (Ruling P95).
		// Nothing was written — not the floor, not the purge — because such an
		// ack comes from a log that was rewound out from under Aura, and
		// clamping it to the current top would delete rows Aura never received.
		// Aura re-reads `/status` rather than assuming its ack landed.
		if ( ! empty( $result['stale'] ) ) {
			$body['stale'] = true;
		}

		return new WP_REST_Response( $body, 200 );
	}

	/**
	 * POST /aura/v1/door/rotate
	 *
	 * Aura's decision to rotate the door-log epoch (Ruling P20). `/status`
	 * REPORTS a rewound log — a cursor from this epoch that is above every
	 * row and above the ack floor, which only a restore or a `wp_options`
	 * roll-back can produce — as `rewind.detected`; acting on it is a WRITE
	 * on the log's durable identity and lives here, behind a grant.
	 *
	 * It used to happen inside `/status`, where a holder of a leaked site
	 * token could trigger it at will: every rotation invalidates the ack
	 * Aura is about to send, so repeating it between the poll and
	 * `/door/ack` starved the log to MAX_UNACKED and closed the write door.
	 *
	 * IDEMPOTENT: the rotation happens only when the epoch named is still
	 * the current one, so a retry (or a second Aura instance answering the
	 * same report) answers the epoch now in force with `rotated: false`
	 * rather than minting another.
	 *
	 * Deliberately EXEMPT from the rule-guard invariant
	 * (RulesRestCoverageTest), for the same reason as `door.ack`: this is
	 * governance-plane bookkeeping on Aura's own log, not a site mutation,
	 * and a freeze blocking it would strand a rewound log — no ack can ever
	 * match again — until the door closed itself.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Result, or WP_Error(403) if a grant is required.
	 */
	public function rotate_door_epoch( $request ) {
		$epoch = (string) $request->get_param( 'epoch' );

		$guard = Aura_Worker_Grant::require_for( $request, 'door.rotate', array( 'epoch' => $epoch ) );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		// No read-compare here: rotate_epoch() IS the compare, fenced on the
		// epoch named, so two granted rotations answering the same rewind
		// cannot both mint one (Ruling P23).
		$out = Aura_Worker_Door_Log::rotate_epoch( $epoch );

		// Ruling S77 (Codex round-31 P2 on #88): `rotated: null` — an
		// AMBIGUOUS commit whose own verifying re-read ALSO could not be
		// proven — is genuinely UNKNOWN, never the same fact as `false`
		// (rotate_epoch()'s own docblock). Answering `rotated: false`
		// here would tell Aura the rotation did not happen; a retry with
		// the SAME `$epoch` would then lose the fence against whatever IS
		// actually current (this call's own mint, if it landed) and
		// report `false` again, forever — `restamp_binding_epoch()` never
		// runs, and the binding record is left naming an epoch this site
		// may already have left. The retryable 503 this answers instead
		// is the SAME shape `Aura_Worker_Door_Holds`' own
		// `retry_may_have_run()` uses: the caller retries, and the NEXT
		// attempt's own verify — reading a by-then-healthy epoch_raw()
		// that may already equal what THIS attempt minted — completes
		// idempotently.
		if ( null === $out['rotated'] ) {
			return new WP_Error(
				'aura_log_failed',
				'This site could not prove whether the rotation landed; retry.',
				array( 'status' => 503, 'retry_after' => 5, 'may_have_run' => true )
			);
		}

		// A LEGITIMATE ROTATION SAYS SO ON THE BINDING RECORD (Ruling P91).
		// The record names the epoch it was written with, and P81's repair
		// reads a disagreement as a half-done rebind — so without this the next
		// same-identity connect performed a FULL rebind: a new generation for
		// an identity that never changed, holds queued since the rotation gone
		// foreign, in-flight writes failing their fence. A rewind cost the site
		// its queue.
		//
		// Only the witness moves; the generation, the state and the identity
		// are untouched, so this hands the site to nobody and needs no site
		// claim. A stamp that will not land is reported rather than refused —
		// the rotation itself succeeded, and P81's repair on the next connect
		// is the fallback.
		$body = array(
			'rotated' => (bool) $out['rotated'],
			'epoch'   => (string) $out['epoch'],
			'floor'   => Aura_Worker_Door_Log::floor(),
		);
		if ( ! empty( $out['rotated'] ) && ! Aura_Worker_Door_Log::restamp_binding_epoch( (string) $out['epoch'] ) ) {
			$body['witness_stale'] = true;
		}

		return new WP_REST_Response( $body, 200 );
	}

	/**
	 * Calculate disk usage of the WordPress installation.
	 *
	 * @return string Human-readable disk usage.
	 */
	private function get_disk_usage() {
		$uploads_dir = wp_get_upload_dir();
		$upload_path = $uploads_dir['basedir'];

		if ( ! is_dir( $upload_path ) ) {
			return 'unknown';
		}

		// Only check uploads directory size (fast).
		$size = 0;
		$iter = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $upload_path, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY,
			RecursiveIteratorIterator::CATCH_GET_CHILD
		);

		foreach ( $iter as $file ) {
			if ( $file->isFile() ) {
				$size += $file->getSize();
			}
		}

		return size_format( $size );
	}
}
