<?php
/**
 * Magic Link onboarding handler for Aura Worker.
 *
 * Provides a "Connect to Aura" button in the admin settings page and handles
 * the magic link flow: generating a temporary token, posting it to the Aura
 * dashboard, and receiving the site token back via a public REST endpoint.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Worker_Magic_Link {

	/**
	 * Constructor — register AJAX hook.
	 */
	public function __construct() {
		add_action( 'wp_ajax_aura_create_magic_link', array( $this, 'ajax_create_magic_link' ) );
	}

	/**
	 * Render the "Connect to Aura" section inside the settings page.
	 *
	 * Shows a connected status when aura_worker_dashboard_url and
	 * aura_worker_site_token options are both present; otherwise shows the
	 * connect button wired to the AJAX handler.
	 */
	public function render_connect_section() {
		$dashboard_url = get_option( 'aura_worker_dashboard_url', '' );
		$site_token    = get_option( 'aura_worker_site_token', '' );

		echo '<hr>';
		echo '<h2>' . esc_html__( 'Aura Dashboard Connection', 'digitizer-site-worker' ) . '</h2>';

		if ( $dashboard_url && $site_token ) {
			echo '<p style="color:#2e7d32;">';
			echo '<span dashicons dashicons-yes-alt></span> ';
			echo esc_html__( 'Connected to Aura dashboard:', 'digitizer-site-worker' ) . ' ';
			echo '<strong>' . esc_html( $dashboard_url ) . '</strong>';
			echo '</p>';
			return;
		}

		$nonce = wp_create_nonce( 'aura_magic_link' );
		?>
		<p><?php esc_html_e( 'Connect this site to your Aura dashboard with one click.', 'digitizer-site-worker' ); ?></p>
		<button type="button" id="aura-connect-btn" class="button button-primary">
			<?php esc_html_e( 'Connect to Aura', 'digitizer-site-worker' ); ?>
		</button>
		<span id="aura-connect-status" style="margin-left:10px;"></span>
		<script>
		(function() {
			document.getElementById('aura-connect-btn').addEventListener('click', function() {
				var btn    = this;
				var status = document.getElementById('aura-connect-status');
				btn.disabled = true;
				status.textContent = <?php echo wp_json_encode( __( 'Generating link…', 'digitizer-site-worker' ) ); ?>;

				var data = new FormData();
				data.append('action', 'aura_create_magic_link');
				data.append('nonce', <?php echo wp_json_encode( $nonce ); ?>);

				fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
					method: 'POST',
					body: data,
				})
				.then(function(r) { return r.json(); })
				.then(function(res) {
					if (res.success && res.data && res.data.magic_link) {
						status.textContent = '';
						window.location.href = res.data.magic_link;
					} else {
						btn.disabled = false;
						status.style.color = '#c62828';
						status.textContent = (res.data && res.data.message)
							? res.data.message
							: <?php echo wp_json_encode( __( 'Failed to create magic link. Please try again.', 'digitizer-site-worker' ) ); ?>;
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
	 * AJAX handler: generate a magic ID, store it in a transient, POST it to
	 * the Aura dashboard, and return the magic_link URL for the browser to
	 * redirect to.
	 *
	 * Requires: wp_ajax_aura_create_magic_link, nonce aura_magic_link,
	 *           current user must have manage_options capability.
	 */
	public function ajax_create_magic_link() {
		check_ajax_referer( 'aura_magic_link', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'digitizer-site-worker' ) ), 403 );
		}

		$dashboard_url = defined( 'AURA_DASHBOARD_URL' ) ? AURA_DASHBOARD_URL : 'https://my-aura.app';
		$magic_id      = wp_generate_uuid4();
		$site_url      = get_site_url();
		$site_name     = get_bloginfo( 'name' );

		// Store site info keyed by magic_id; expires in 10 minutes.
		set_transient(
			'aura_magic_' . $magic_id,
			array(
				'site_url'   => $site_url,
				'site_name'  => $site_name,
				'created_at' => time(),
			),
			10 * MINUTE_IN_SECONDS
		);

		// Notify the Aura dashboard so it can pre-populate the onboarding flow.
		$response = wp_remote_post(
			$dashboard_url . '/api/onboarding/magic-link',
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'magic_id'  => $magic_id,
						'site_url'  => $site_url,
						'site_name' => $site_name,
					)
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			delete_transient( 'aura_magic_' . $magic_id );
			wp_send_json_error(
				array( 'message' => sprintf(
					/* translators: %s: error message */
					__( 'Could not reach Aura dashboard: %s', 'digitizer-site-worker' ),
					$response->get_error_message()
				) ),
				502
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || empty( $body['magic_link'] ) ) {
			delete_transient( 'aura_magic_' . $magic_id );
			wp_send_json_error(
				array( 'message' => __( 'Aura dashboard did not return a magic link. Please try again.', 'digitizer-site-worker' ) ),
				502
			);
		}

		wp_send_json_success( array( 'magic_link' => $body['magic_link'] ) );
	}

	/**
	 * REST endpoint: receive the site token from the Aura dashboard.
	 *
	 * POST /wp-json/aura/v1/connect
	 *
	 * Validates the magic_id transient (proves the request originated from a
	 * genuine magic-link flow initiated by an admin of this site), then stores
	 * the aura_token and dashboard_url options.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public function handle_connect( $request ) {
		$magic_id      = sanitize_text_field( $request->get_param( 'magic_id' ) );
		$aura_token    = sanitize_text_field( $request->get_param( 'aura_token' ) );
		$dashboard_url = esc_url_raw( $request->get_param( 'dashboard_url' ) );

		if ( empty( $magic_id ) || empty( $aura_token ) || empty( $dashboard_url ) ) {
			return new WP_REST_Response( array( 'error' => 'Missing required parameters.' ), 400 );
		}

		$stored = get_transient( 'aura_magic_' . $magic_id );
		if ( ! $stored ) {
			return new WP_REST_Response( array( 'error' => 'Invalid or expired magic link.' ), 400 );
		}

		update_option( 'aura_worker_site_token', $aura_token );
		update_option( 'aura_worker_dashboard_url', $dashboard_url );
		delete_transient( 'aura_magic_' . $magic_id );

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}
}
