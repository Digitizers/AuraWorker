<?php
/**
 * Magic Link onboarding handler for SiteAgent.
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
			echo '<span class="dashicons dashicons-yes-alt"></span> ';
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

		$dashboard_url = defined( 'AURA_DASHBOARD_URL' ) ? AURA_DASHBOARD_URL : 'https://app.my-aura.app';
		$magic_id      = wp_generate_uuid4();
		$site_url      = get_site_url();
		$site_name     = get_bloginfo( 'name' );

		// One-time secret minted by this site. Handed to the dashboard now and
		// used by the dashboard to HMAC-sign the /connect callback, proving the
		// callback genuinely originates from the dashboard we just contacted.
		$connect_secret = wp_generate_password( 64, false );

		// Store site info + secret keyed by magic_id; expires in 10 minutes.
		set_transient(
			'aura_magic_' . $magic_id,
			array(
				'site_url'        => $site_url,
				'site_name'       => $site_name,
				'connect_secret'  => $connect_secret,
				'connect_user_id' => get_current_user_id(),
				'created_at'      => time(),
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
						'magic_id'       => $magic_id,
						'site_url'       => $site_url,
						'site_name'      => $site_name,
						'connect_secret' => $connect_secret,
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
	 * Validates the magic_id transient and the HMAC signature (proves the
	 * request originated from the dashboard this site contacted), enforces a
	 * timestamp freshness window, then stores the hashed token and dashboard_url.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public function handle_connect( $request ) {
		$magic_id      = sanitize_text_field( $request->get_param( 'magic_id' ) );
		$token         = sanitize_text_field( $request->get_param( 'token' ) );
		$dashboard_url = esc_url_raw( $request->get_param( 'dashboard_url' ) );
		$timestamp     = (int) $request->get_param( 'timestamp' );
		$signature     = sanitize_text_field( $request->get_param( 'signature' ) );
		// Optional G-grants provisioning: the gateway's Ed25519 public key. It is
		// covered by the signature (a 5th line, present only when non-empty), so a
		// stolen token alone can't provision an attacker-chosen key.
		$grant_pubkey  = sanitize_text_field( (string) $request->get_param( 'grant_pubkey' ) );

		if ( empty( $magic_id ) || empty( $token ) || empty( $dashboard_url ) || empty( $signature ) || $timestamp <= 0 ) {
			return new WP_REST_Response( array( 'error' => 'Missing required parameters.' ), 400 );
		}

		$stored = get_transient( 'aura_magic_' . $magic_id );
		if ( ! $stored || empty( $stored['connect_secret'] ) ) {
			return new WP_REST_Response( array( 'error' => 'Invalid or expired magic link.' ), 400 );
		}

		// Reject stale/replayed callbacks (±5 minutes).
		if ( abs( time() - $timestamp ) > 5 * MINUTE_IN_SECONDS ) {
			return new WP_REST_Response( array( 'error' => 'Request timestamp outside the allowed window.' ), 400 );
		}

		// Verify the HMAC signature using the one-time secret this site issued.
		$expected = self::sign_connect_payload( $stored['connect_secret'], $magic_id, $token, $dashboard_url, $timestamp, $grant_pubkey );
		if ( ! hash_equals( $expected, $signature ) ) {
			return new WP_REST_Response( array( 'error' => 'Invalid signature.' ), 401 );
		}

		// Validate a provisioned grant public key before storing anything: this
		// host must have libsodium (grants can't be verified without it), and the
		// key must be a base64 32-byte Ed25519 key. Signature already proved
		// authenticity. Rejecting here avoids provisioning a key that would only
		// ever fail closed and block every write.
		if ( '' !== $grant_pubkey ) {
			if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
				return new WP_REST_Response( array( 'error' => 'This host lacks libsodium; approval grants cannot be enabled.' ), 400 );
			}
			$raw = base64_decode( $grant_pubkey, true );
			if ( false === $raw || 32 !== strlen( $raw ) ) {
				return new WP_REST_Response( array( 'error' => 'Invalid grant public key.' ), 400 );
			}
		}

		// Persist the connecting administrator so token-only requests can run as
		// them (an admin context lets current_user_can() pass without an
		// application password). Falls back to the first admin if absent.
		if ( ! empty( $stored['connect_user_id'] ) ) {
			update_option( 'aura_worker_connect_user_id', (int) $stored['connect_user_id'] );
		}
		update_option( 'aura_worker_site_token', Aura_Worker_Security::hash_token( $token ) );
		// A (re)connect may bind this site to a different client. The old
		// client's rules are not this site's to keep, and the new client's seq
		// starts wherever it starts.
		Aura_Worker_Rules::clear();
		update_option( 'aura_worker_dashboard_url', $dashboard_url );
		if ( '' !== $grant_pubkey ) {
			// Provision the gateway key → turns on approval-grant enforcement
			// (Aura_Worker_Grant::is_enforced()).
			update_option( 'aura_worker_grant_pubkey', $grant_pubkey );
		} else {
			// Keyless (re)connect: clear any previously provisioned key so a fresh
			// dashboard that doesn't use grants isn't left unable to run writes
			// against a stale key it can't sign for. Enforcement follows the key.
			delete_option( 'aura_worker_grant_pubkey' );
			Aura_Worker_Rules::clear();
		}
		delete_transient( 'aura_magic_' . $magic_id );

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Build the HMAC-SHA256 signature for a /connect callback payload.
	 *
	 * The canonical message is the magic_id, token, dashboard_url and timestamp
	 * joined by newlines. The Aura dashboard MUST compute the signature the same
	 * way using the connect_secret it received from /api/onboarding/magic-link.
	 *
	 * @param string $secret        One-time connect secret.
	 * @param string $magic_id      Magic link ID.
	 * @param string $token         Raw site token issued by the dashboard.
	 * @param string $dashboard_url Dashboard base URL.
	 * @param int    $timestamp     Unix timestamp of the callback.
	 * @param string $grant_pubkey  Optional base64 Ed25519 gateway key; appended
	 *                              as a 5th line only when non-empty.
	 * @return string Lowercase hex HMAC-SHA256 digest.
	 */
	public static function sign_connect_payload( $secret, $magic_id, $token, $dashboard_url, $timestamp, $grant_pubkey = '' ) {
		$parts = array( $magic_id, $token, $dashboard_url, (string) $timestamp );
		// Append the grant public key as a 5th line ONLY when provisioning one, so
		// existing 4-field callbacks keep validating unchanged. The Aura dashboard
		// MUST follow the same rule (include iff non-empty).
		if ( '' !== (string) $grant_pubkey ) {
			$parts[] = (string) $grant_pubkey;
		}
		return hash_hmac( 'sha256', implode( "\n", $parts ), $secret );
	}
}
