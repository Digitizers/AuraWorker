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

		// Register REST API routes.
		add_action( 'rest_api_init', array( $this->api, 'register_routes' ) );

		// Add settings page.
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
		}
	}

	/**
	 * Add settings page under Tools menu.
	 */
	public function add_settings_page() {
		add_management_page(
			__( 'Aura Worker', 'aura-worker' ),
			__( 'Aura Worker', 'aura-worker' ),
			'manage_options',
			'aura-worker',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register plugin settings.
	 */
	public function register_settings() {
		register_setting( 'aura_worker_settings', 'aura_worker_site_token', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		) );

		register_setting( 'aura_worker_settings', 'aura_worker_allowed_ips', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
		) );

		add_settings_section(
			'aura_worker_main',
			__( 'Connection Settings', 'aura-worker' ),
			null,
			'aura-worker'
		);

		add_settings_field(
			'aura_worker_site_token',
			__( 'Site Token', 'aura-worker' ),
			array( $this, 'render_token_field' ),
			'aura-worker',
			'aura_worker_main'
		);

		add_settings_field(
			'aura_worker_allowed_ips',
			__( 'Allowed IPs', 'aura-worker' ),
			array( $this, 'render_ips_field' ),
			'aura-worker',
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
			<p><?php esc_html_e( 'Configure the connection between this site and your Aura dashboard.', 'aura-worker' ); ?></p>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'aura_worker_settings' );
				do_settings_sections( 'aura-worker' );
				submit_button();
				?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Connection Test', 'aura-worker' ); ?></h2>
			<p>
				<?php esc_html_e( 'API Endpoint:', 'aura-worker' ); ?>
				<code><?php echo esc_url( rest_url( 'aura/v1/status' ) ); ?></code>
			</p>
			<p>
				<?php esc_html_e( 'Plugin Version:', 'aura-worker' ); ?>
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
			<?php esc_html_e( 'Auto-generated token. Copy this to your Aura dashboard when connecting this site.', 'aura-worker' ); ?>
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
			<?php esc_html_e( 'One IP per line. Leave empty to allow all IPs (less secure). Only these IPs can access the Aura API.', 'aura-worker' ); ?>
		</p>
		<?php
	}
}
