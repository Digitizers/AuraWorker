<?php
/**
 * Main plugin class.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Worker {

	/**
	 * API handler instance.
	 *
	 * @var Aura_Worker_API
	 */
	private $api;

	/**
	 * MCP router instance.
	 *
	 * @var Aura_Worker_MCP
	 */
	private $mcp;

	/**
	 * Abilities API bridge instance.
	 *
	 * @var Aura_Worker_Abilities
	 */
	private $abilities;

	/**
	 * Magic link onboarding handler instance.
	 *
	 * @var Aura_Worker_Magic_Link
	 */
	private $magic_link;

	/**
	 * Security handler instance.
	 *
	 * @var Aura_Worker_Security
	 */
	private $security;

	/**
	 * Initialize the plugin components.
	 */
	public function init() {
		$this->security    = new Aura_Worker_Security();
		$this->api         = new Aura_Worker_API( $this->security );
		$this->mcp         = new Aura_Worker_MCP( $this->security );
		$this->abilities   = new Aura_Worker_Abilities();
		$this->magic_link  = new Aura_Worker_Magic_Link();

		// Register REST API routes.
		add_action( 'rest_api_init', array( $this->api, 'register_routes' ) );
		add_action( 'rest_api_init', array( $this->mcp, 'register_routes' ) );

		// Which transport a call arrived on. Recorded before anything dispatches,
		// because the abilities path has no other way to tell a gateway call from
		// a co-installed MCP server serving the same ability.
		require_once plugin_dir_path( __FILE__ ) . 'class-aura-worker-call-context.php';
		Aura_Worker_Call_Context::init();

		// A rule holds against WordPress core's own REST API, not only
		// against SiteAgent's tools — the seam Aura's content tools, an
		// app-password agent and a second MCP server actually write through.
		Aura_Worker_Rules::init();

		// Standards-alignment: also expose tools via the WordPress Abilities API
		// (when present) so the official MCP adapter can discover them. Additive —
		// the aura/mcp namespace above is unaffected. The category must register
		// on its own earlier hook, else every ability is rejected for an
		// unregistered category.
		add_action( 'wp_abilities_api_categories_init', array( $this->abilities, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this->abilities, 'register' ) );

		// G-grants: delete each spent approval-grant nonce just past its expiry
		// (scheduled per-nonce by the verifier), so reservations self-clean.
		require_once plugin_dir_path( __FILE__ ) . 'class-aura-worker-grant.php';
		add_action( Aura_Worker_Grant::NONCE_GC_HOOK, array( 'Aura_Worker_Grant', 'delete_spent_nonce' ), 10, 1 );

		// Add settings page and privacy policy.
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
			add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
			add_action( 'wp_ajax_aura_worker_regenerate_token', array( $this, 'ajax_regenerate_token' ) );
		}
	}

	/**
	 * AJAX handler: rotate the site token.
	 *
	 * Generates a new raw token, stores only its hash, stashes the raw value in
	 * a short-lived one-time reveal transient for the admin to copy, and clears
	 * the dashboard connection (the old token is now invalid and the site must
	 * be reconnected with the new one).
	 */
	public function ajax_regenerate_token() {
		check_ajax_referer( 'aura_worker_regenerate', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'digitizer-site-worker' ) ), 403 );
		}

		$previous = $this->stored_token();
		$raw      = wp_generate_password( 48, false );
		$hashed   = Aura_Worker_Security::hash_token( $raw );

		// Claim the rotation with a compare-and-swap against the value this
		// request read, in one statement. Writing and then repairing cannot work
		// here: a read-back proves only what the row holds, never who put it
		// there, so a request that lost a race to a concurrent rotation could not
		// tell that from its own write being rewritten — and "repairing" it would
		// revoke the other administrator's fresh token and bring back the one
		// both requests were rotating away from. Exactly one of two concurrent
		// rotations can match the predicate; the loser writes nothing at all.
		//
		// Raw SQL also puts the write out of reach of any option filter, which is
		// the failure this whole change exists to remove (#67).
		if ( ! $this->swap_token( $previous, $hashed ) ) {
			$current = $this->stored_token();
			$message = hash_equals( $previous, $current )
				? __( 'The new site token could not be saved, so the current token is unchanged. Check the database for write errors and try again.', 'digitizer-site-worker' )
				: __( 'Another site token was stored while this request ran, so this one changed nothing. That token is now the current one — regenerate again if you still want a new one.', 'digitizer-site-worker' );
			wp_send_json_error( array( 'message' => $message ), 500 );
		}

		// Nothing is read back here, on purpose. The swap matched a row and
		// changed it, so the store already holds $hashed and this request is the
		// one that put it there — the affected-row count is the proof, and it is
		// the only proof available (a read answers what the row holds, never who
		// wrote it). A confirming read could only ever fail spuriously — a
		// transient database error, a stale autoloaded copy — and failing here
		// would revoke the previous token while revealing no replacement, which
		// is worse than the defect this rotation exists to fix.
		update_option( 'aura_worker_connect_user_id', get_current_user_id() );
		delete_option( 'aura_worker_dashboard_url' );
		// The dashboard's binding is what this rotation invalidates, and the
		// Application Password minted beside the old token is part of it
		// (2.11.0, round-6): left alone it would keep authenticating to
		// WordPress — and to every other REST/MCP plugin — while the UI says
		// the site is disconnected. Best-effort: a failure here must not cost
		// the operator the new token this response is about to reveal, so it
		// is logged, not fatal.
		if ( ! Aura_Worker_Magic_Link::revoke_managed_password() ) {
			// translators: internal log line, not shown to the user.
			error_log( 'SiteAgent: the Aura Application Password could not be revoked while regenerating the site token; revoke it by hand in Users → Profile → Application Passwords.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		set_transient( 'aura_worker_token_reveal', $raw, 2 * MINUTE_IN_SECONDS );

		wp_send_json_success( array( 'token' => $raw ) );
	}

	/**
	 * The stored token hash, read from the database rather than the cache.
	 *
	 * Every decision this handler makes is about what the row actually holds:
	 * whether a filter rewrote the write, whether a restore landed, whether a
	 * concurrent rotation won. get_option() answers from `alloptions` for this
	 * autoloaded option and cannot see a raw compare-and-swap, so it would report
	 * a correct database as a failure — and, with a persistent object cache, keep
	 * doing so. Falls back to the cached read only if the database itself errors,
	 * where a stale answer beats no answer.
	 *
	 * @since 2.10.3
	 *
	 * @return string
	 */
	private function stored_token() {
		$raw = Aura_Worker_Rules::site_token_uncached();
		return is_wp_error( $raw ) ? (string) get_option( 'aura_worker_site_token', '' ) : (string) $raw;
	}

	/**
	 * Store $new only if the row still holds $expected — one statement.
	 *
	 * The predicate is what makes concurrent rotation safe: two administrators
	 * both read the same previous value, both try to swap, and MySQL lets exactly
	 * one match. The loser is told so and writes nothing, instead of "repairing"
	 * a row that was never its own write to repair.
	 *
	 * The swap is always attempted first, including when the value read was ''.
	 * An empty '' is two different states — no row at all, and a row holding an
	 * empty string — and a read cannot tell them apart, because both the settings
	 * screen and get_option()'s default answer ''. Choosing the statement from
	 * that ambiguous read is what breaks: a site whose row exists but is empty
	 * would get only the conditional INSERT, whose NOT EXISTS can never be
	 * satisfied, so its rotation would fail forever and it could never be
	 * configured. The UPDATE settles it instead — it matches the empty row and
	 * changes nothing when there is no row — and only then, having learnt that no
	 * row matched, does an absent row get the conditional INSERT, so a racer's
	 * already-committed row is never clobbered.
	 *
	 * @since 2.10.3
	 *
	 * @param string $expected Value this request read; '' for an empty or absent row.
	 * @param string $new      Hash to store.
	 * @return bool True when this call wrote the row.
	 */
	private function swap_token( $expected, $new ) {
		global $wpdb;
		$name = 'aura_worker_site_token';
		$rows = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				(string) $new,
				$name,
				(string) $expected
			)
		);

		// 0 rows and an expected '' is the one case that may still be an absent
		// row rather than a lost race. false is a driver error — not a race, and
		// not something a second statement would clarify.
		if ( 0 === $rows && '' === (string) $expected ) {
			$rows = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) SELECT %s, %s, %s FROM DUAL WHERE NOT EXISTS ( SELECT 1 FROM {$wpdb->options} WHERE option_name = %s )",
					$name,
					(string) $new,
					'yes',
					$name
				)
			);
		}

		// The row was written behind get_option()'s back, so both caches core
		// might serve it from must go. `alloptions` is the one that matters: this
		// option is autoloaded (nothing ever passed an explicit $autoload), so
		// core serves it from that bucket and never from the per-key entry.
		// (swap_raw() in class-aura-worker-rules.php evicts only the key because
		// the ruleset option is written with autoload 'no'; not so here.)
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		// $wpdb->query() answers false for an SQL error and 0 for "matched
		// nothing" — a lost race, not a fault. Neither wrote the row.
		return is_int( $rows ) && $rows > 0;
	}

	/**
	 * Suggest privacy policy content for the site's Privacy Policy page.
	 */
	public function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			'SiteAgent',
			wp_kses_post( wpautop( __( 'This site uses the SiteAgent plugin to enable remote management from the Aura dashboard (my-aura.app). When connected, the Aura dashboard may access site health information including WordPress version, PHP version, installed plugins and themes, and database metadata. No personal user data is collected or transmitted by this plugin.', 'digitizer-site-worker' ) ) )
		);
	}

	/**
	 * Add settings page under Tools menu.
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'SiteAgent', 'digitizer-site-worker' ),
			__( 'SiteAgent', 'digitizer-site-worker' ),
			'manage_options',
			'digitizer-site-worker',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register plugin settings.
	 */
	public function register_settings() {
		// The site token is deliberately NOT registered as a setting. It is
		// display-only on this screen (render_token_field() emits no input
		// carrying its name), so nothing submits it, and leaving it out of the
		// group's allow-list is what stops options.php from ever writing it.
		//
		// It used to be registered with a sanitize_callback that returned the
		// stored value, to make it read-only. That guard reached far wider than
		// the form: register_setting() installs the callback as a
		// `sanitize_option_aura_worker_site_token` filter, and update_option()
		// applies that filter on every write from any caller — so regeneration
		// (and the legacy raw-to-hash migration) silently stored nothing while
		// still revealing a token to the admin (#67).

		register_setting( 'aura_worker_settings', 'aura_worker_allowed_ips', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
		) );

		register_setting( 'aura_worker_settings', 'aura_worker_allowed_domains', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
		) );

		add_settings_section(
			'aura_worker_main',
			__( 'Connection Settings', 'digitizer-site-worker' ),
			null,
			'digitizer-site-worker'
		);

		add_settings_field(
			'aura_worker_site_token',
			__( 'Site Token', 'digitizer-site-worker' ),
			array( $this, 'render_token_field' ),
			'digitizer-site-worker',
			'aura_worker_main'
		);

		add_settings_field(
			'aura_worker_allowed_ips',
			__( 'Allowed IPs', 'digitizer-site-worker' ),
			array( $this, 'render_ips_field' ),
			'digitizer-site-worker',
			'aura_worker_main'
		);

		add_settings_field(
			'aura_worker_allowed_domains',
			__( 'Allowed Domains', 'digitizer-site-worker' ),
			array( $this, 'render_domains_field' ),
			'digitizer-site-worker',
			'aura_worker_main'
		);
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<p><?php esc_html_e( 'Configure the connection between this site and your Aura dashboard.', 'digitizer-site-worker' ); ?></p>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'aura_worker_settings' );
				do_settings_sections( 'digitizer-site-worker' );
				submit_button();
				?>
			</form>

			<?php $this->magic_link->render_connect_section(); ?>

			<hr>
			<h2><?php esc_html_e( 'Connection Test', 'digitizer-site-worker' ); ?></h2>
			<p>
				<?php esc_html_e( 'API Endpoint:', 'digitizer-site-worker' ); ?>
				<code><?php echo esc_url( rest_url( 'aura/v1/status' ) ); ?></code>
			</p>
			<p>
				<?php esc_html_e( 'Plugin Version:', 'digitizer-site-worker' ); ?>
				<strong><?php echo esc_html( AURA_WORKER_VERSION ); ?></strong>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the token field.
	 */
	public function render_token_field() {
		$configured = '' !== (string) get_option( 'aura_worker_site_token', '' );
		$reveal     = get_transient( 'aura_worker_token_reveal' );
		if ( false !== $reveal ) {
			// Show the raw token exactly once, then burn it.
			delete_transient( 'aura_worker_token_reveal' );
		}
		$nonce = wp_create_nonce( 'aura_worker_regenerate' );
		?>
		<?php if ( false !== $reveal ) : ?>
			<input type="text" value="<?php echo esc_attr( $reveal ); ?>" class="regular-text code" readonly onclick="this.select();">
			<p class="description" style="color:#b26a00;">
				<strong><?php esc_html_e( 'Copy this token now — it will not be shown again.', 'digitizer-site-worker' ); ?></strong>
				<?php esc_html_e( 'Paste it into your Aura dashboard to connect this site.', 'digitizer-site-worker' ); ?>
			</p>
		<?php else : ?>
			<p>
				<?php if ( $configured ) : ?>
					<span class="dashicons dashicons-yes-alt" style="color:#2e7d32;"></span>
					<?php esc_html_e( 'A site token is configured (stored hashed and hidden for security).', 'digitizer-site-worker' ); ?>
				<?php else : ?>
					<span class="dashicons dashicons-warning" style="color:#b26a00;"></span>
					<?php esc_html_e( 'No site token set yet. Connect to Aura or regenerate a token below.', 'digitizer-site-worker' ); ?>
				<?php endif; ?>
			</p>
		<?php endif; ?>

		<button type="button" id="aura-regen-btn" class="button"
				data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<?php esc_html_e( 'Regenerate Token', 'digitizer-site-worker' ); ?>
		</button>
		<span id="aura-regen-status" style="margin-left:10px;"></span>
		<p class="description">
			<?php esc_html_e( 'Regenerating invalidates the current token and disconnects this site from Aura until you reconnect with the new token.', 'digitizer-site-worker' ); ?>
		</p>
		<script>
		(function() {
			var btn = document.getElementById('aura-regen-btn');
			if ( ! btn ) { return; }
			btn.addEventListener('click', function() {
				if ( ! window.confirm(<?php echo wp_json_encode( __( 'Regenerate the site token? The current connection to Aura will stop working until you reconnect.', 'digitizer-site-worker' ) ); ?>) ) { return; }
				var status = document.getElementById('aura-regen-status');
				btn.disabled = true;
				status.textContent = <?php echo wp_json_encode( __( 'Regenerating…', 'digitizer-site-worker' ) ); ?>;
				var data = new FormData();
				data.append('action', 'aura_worker_regenerate_token');
				data.append('nonce', btn.getAttribute('data-nonce'));
				fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', body: data })
					.then(function(r) { return r.json(); })
					.then(function(res) {
						if ( res.success ) {
							window.location.reload();
						} else {
							btn.disabled = false;
							status.style.color = '#c62828';
							status.textContent = (res.data && res.data.message) ? res.data.message : 'Error';
						}
					})
					.catch(function() {
						btn.disabled = false;
						status.style.color = '#c62828';
						status.textContent = <?php echo wp_json_encode( __( 'Network error. Please try again.', 'digitizer-site-worker' ) ); ?>;
					});
			});
		})();
		</script>
		<?php
	}

	/**
	 * Render the allowed IPs field.
	 */
	public function render_ips_field() {
		$ips = get_option( 'aura_worker_allowed_ips', '' );
		?>
		<textarea name="aura_worker_allowed_ips" rows="3" class="large-text"><?php echo esc_textarea( $ips ); ?></textarea>
		<p class="description">
			<?php esc_html_e( 'One IP per line. Leave empty to allow all IPs (less secure). Only these IPs can access the Aura API.', 'digitizer-site-worker' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the allowed domains field.
	 */
	public function render_domains_field() {
		$domains = get_option( 'aura_worker_allowed_domains', '' );
		?>
		<textarea name="aura_worker_allowed_domains" rows="3" class="large-text"><?php echo esc_textarea( $domains ); ?></textarea>
		<p class="description">
			<?php esc_html_e( 'One domain per line (e.g., my-aura.app). Leave empty to allow all origins. Checked against the Origin or Referer header of incoming requests.', 'digitizer-site-worker' ); ?>
		</p>
		<?php
	}
}
