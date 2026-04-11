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
	 * Security handler instance.
	 *
	 * @var Aura_Worker_Security
	 */
	private $security;

	/**
	 * Initialize the plugin components.
	 */
	public function init() {
		$this->security = new Aura_Worker_Security();
		$this->api      = new Aura_Worker_API( $this->security );
		$this->mcp      = new Aura_Worker_MCP( $this->security );

		// Register REST API routes.
		add_action( 'rest_api_init', array( $this->api, 'register_routes' ) );
		add_action( 'rest_api_init', array( $this->mcp, 'register_routes' ) );

		// Add settings page and privacy policy.
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
			add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
		}
	}

	/**
	 * Suggest privacy policy content for the site's Privacy Policy page.
	 */
	public function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			'Digitizer Site Worker',
			wp_kses_post( wpautop( __( 'This site uses the AuraWorker plugin to enable remote management from the Aura dashboard (my-aura.app). When connected, the Aura dashboard may access site health information including WordPress version, PHP version, installed plugins and themes, and database metadata. No personal user data is collected or transmitted by this plugin.', 'digitizer-site-worker' ) ) )
		);
	}

	/**
	 * Add settings page under Tools menu.
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'Digitizer Site Worker', 'digitizer-site-worker' ),
			__( 'Digitizer Site Worker', 'digitizer-site-worker' ),
			'manage_options',
			'digitizer-site-worker',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register plugin settings.
	 */
	public function register_settings() {
		register_setting( 'aura_worker_settings', 'aura_worker_site_token', array(
			'type'              => 'string',
			'sanitize_callback' => function( $new_value ) {
				// Token is read-only; always preserve the existing value.
				return get_option( 'aura_worker_site_token', $new_value );
			},
		) );

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
		$token = get_option( 'aura_worker_site_token', '' );
		?>
		<input type="text" name="aura_worker_site_token"
			   value="<?php echo esc_attr( $token ); ?>"
			   class="regular-text" readonly>
		<p class="description">
			<?php esc_html_e( 'Auto-generated token. Copy this to your Aura dashboard when connecting this site.', 'digitizer-site-worker' ); ?>
		</p>
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
