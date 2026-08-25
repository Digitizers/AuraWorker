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
		// covered by the signature (a bare line, present only when non-empty), so a
		// stolen token alone can't provision an attacker-chosen key.
		$grant_pubkey  = sanitize_text_field( (string) $request->get_param( 'grant_pubkey' ) );
		// Optional client binding (2.10.2): the Aura client this site now belongs
		// to. Covered by the signature (an extra line, present only when
		// non-empty), so a stolen token alone cannot re-home a site.
		$client        = sanitize_text_field( (string) $request->get_param( 'client' ) );

		if ( '' === $magic_id ) {
			return new WP_REST_Response( array( 'error' => 'Missing required parameters.' ), 400 );
		}
		// ONE handler per magic link at a time (2.10.2). A second request for
		// the same magic link — a retry, a double submit, an earlier attempt of
		// Aura's still in flight — must not run while this one may be past the
		// transient check and about to write. Serialise on a conditional
		// INSERT (the ruleset store's insert_if_absent() pattern — NOT
		// add_option(), which upserts): a second handler is refused here,
		// before the transient, before any option is touched.
		// Released by THIS handler on every exit — each refusal below (the next
		// variant may then try), the store-failure 500, and success after the
		// transient is consumed. NEVER taken over by age: a dead handler costs
		// this one magic link; its orphan row is swept after an hour.
		$claim_key = Aura_Worker_Rules::MAGIC_CLAIM . $magic_id; // 'aura_magic_claim_<id>' — one definition, next to the sweep that ages it out
		$fence     = self::claim_magic_link( $claim_key );
		if ( '' === $fence ) {
			return new WP_REST_Response( array( 'error' => 'A connect for this magic link is already in progress; retry.', 'code' => 'aura_connect_in_progress' ), 409 );
		}
		// Release = delete ONLY the value this handler wrote (conditional on its
		// own fence). Nobody else ever removes a live claim — there is no timed
		// takeover — so this is belt-and-braces against a double release.
		// …and ONE claim for the whole SITE (2.11.0): two valid callbacks for
		// different magic links of the same site overlapped on the per-link
		// claims alone, and the token write and the Application Password
		// rotation below could interleave — the callback that won the token
		// could lose its password to the other's rotation, leaving the two
		// credentials the dashboard holds split across two responses. Under
		// this claim the whole install (token, binding, key, password) is one
		// handler's at a time; the loser answers 409 and the dashboard's next
		// variant retries. Same mechanism, same prefix, same age sweep.
		// It is taken only AFTER the transient and the HMAC signature have
		// been verified (round-4): the route is public, and a site-wide claim
		// taken on unverified input would let anyone win it with junk and
		// answer a legitimate signed callback 409. Until then the per-link
		// claim alone serializes; $release covers whichever claims are held.
		$site_claim_key = self::SITE_CLAIM;
		$site_fence     = '';
		$release        = static function () use ( $claim_key, $fence, $site_claim_key, &$site_fence ) {
			if ( '' !== $site_fence ) {
				self::release_magic_link( $site_claim_key, $site_fence );
				$site_fence = '';
			}
			self::release_magic_link( $claim_key, $fence );
		};

		if ( empty( $token ) || empty( $dashboard_url ) || empty( $signature ) || $timestamp <= 0 ) {
			$release();
			return new WP_REST_Response( array( 'error' => 'Missing required parameters.' ), 400 );
		}

		$stored = get_transient( 'aura_magic_' . $magic_id );
		if ( ! $stored || empty( $stored['connect_secret'] ) ) {
			$release();
			return new WP_REST_Response( array( 'error' => 'Invalid or expired magic link.' ), 400 );
		}

		// Reject stale/replayed callbacks (±5 minutes).
		if ( abs( time() - $timestamp ) > 5 * MINUTE_IN_SECONDS ) {
			$release();
			return new WP_REST_Response( array( 'error' => 'Request timestamp outside the allowed window.' ), 400 );
		}

		// Verify the HMAC signature using the one-time secret this site issued.
		$expected = self::sign_connect_payload( $stored['connect_secret'], $magic_id, $token, $dashboard_url, $timestamp, $grant_pubkey, $client );
		if ( ! hash_equals( $expected, $signature ) ) {
			$release();
			return new WP_REST_Response( array( 'error' => 'Invalid signature.' ), 401 );
		}

		// Validate a provisioned grant public key before storing anything: this
		// host must have libsodium (grants can't be verified without it), and the
		// key must be a base64 32-byte Ed25519 key. Signature already proved
		// authenticity. Rejecting here avoids provisioning a key that would only
		// ever fail closed and block every write.
		if ( '' !== $grant_pubkey ) {
			if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
				$release();
				return new WP_REST_Response( array( 'error' => 'This host lacks libsodium; approval grants cannot be enabled.' ), 400 );
			}
			$raw = base64_decode( $grant_pubkey, true );
			if ( false === $raw || 32 !== strlen( $raw ) ) {
				$release();
				return new WP_REST_Response( array( 'error' => 'Invalid grant public key.' ), 400 );
			}
		}

		// Persist the connecting administrator so token-only requests can run as
		// them (an admin context lets current_user_can() pass without an
		// application password). Falls back to the first admin if absent.
		// Verified. From here the whole install — token, binding, key, password
		// — is one handler's: take the site-wide claim now.
		// An orphaned claim is NOT taken over by age (round-7): a handler the
		// dashboard gave up on may still be running, and a takeover would let
		// this install interleave with it. A stuck claim is released by
		// deactivating the plugin.
		$site_fence = self::claim_magic_link( $site_claim_key );
		if ( '' === $site_fence ) {
			$release();
			return new WP_REST_Response( array( 'error' => 'A connect for this site is already in progress; retry.', 'code' => 'aura_connect_in_progress' ), 409 );
		}

		// Nothing this handler writes runs without the claim still being its own
		// (round-8): a connect the dashboard timed out on keeps executing, and
		// an operator releasing what looks like a stuck claim must not let it
		// interleave with the install that follows.
		if ( ! self::holds_site_claim( $site_fence ) ) {
			$release();
			return new WP_REST_Response( array( 'error' => 'This connect lost the site to another install; retry.', 'code' => 'aura_connect_lost_claim' ), 409 );
		}
		if ( ! empty( $stored['connect_user_id'] ) ) {
			update_option( 'aura_worker_connect_user_id', (int) $stored['connect_user_id'] );
		}
		$token_hash = Aura_Worker_Security::hash_token( $token );
		// The token is the ONE write whose loss the dashboard cannot see: a
		// handler resuming after its claim was released could otherwise
		// overwrite the winner's token AFTER the winner answered 200, leaving
		// Aura holding a token the site rejects and no error anywhere
		// (round-9). So it is not merely preceded by a check — it is
		// CONDITIONAL on the claim, in one statement, and a handler that no
		// longer owns the claim writes nothing at all.
		self::write_token_under_claim( $token_hash, $site_fence );
		// The token write is verified the same way the binding's is: read the row
		// back from the database and compare (Codex round 30). update_option()
		// answers false for "unchanged" as well as "failed", and a filter or a
		// refused write can leave the OLD token in place — binding a sentinel to
		// the requested hash would then read as stale, the site would be unbound
		// behind a 200, and Aura would hold a token the site rejects. Retryable
		// 500, claim released, transient kept.
		$stored_token = Aura_Worker_Rules::site_token_uncached();
		if ( is_wp_error( $stored_token ) || '' === $stored_token || ! hash_equals( $token_hash, $stored_token ) ) {
			// Asked BEFORE the release, which deletes the very row the question
			// is about. A lost claim is the one cause that is not a store
			// failure: this handler was superseded, and its 409 tells the
			// dashboard to retry rather than blaming the site's database.
			$superseded = ! self::holds_site_claim( $site_fence );
			$release();
			if ( $superseded ) {
				return new WP_REST_Response( array( 'error' => 'This connect lost the site to another install; retry.', 'code' => 'aura_connect_lost_claim' ), 409 );
			}
			return new WP_REST_Response( array( 'error' => 'Connect not completed: the site token could not be stored; retry.', 'code' => 'aura_connect_store_failed' ), 500 );
		}
		if ( '' !== $client ) {
			// A (re)connect binds this site to a client. The binding is written
			// INTO the ruleset store, as a seq-0 sentinel record that names the
			// client and the token just installed — one write to the one value
			// accept() reads, decides against and swaps. Not a separate option:
			// two writes can be interleaved by a /rules request that
			// authenticated before the token rotated, and a second read of a
			// separate option is served stale by this request's option cache.
			// The sentinel is not a ruleset (current() reads it as null — no
			// policy until the new client's first push) and it names its token,
			// so a sentinel from a connect whose token was then overwritten by a
			// concurrent connect is stale, never a lock-out.
			$bound = Aura_Worker_Rules::bind( $client, $token_hash );
			if ( is_wp_error( $bound ) ) {
				// The token is stored but the binding is not (Codex round 19). Do
				// NOT consume the magic transient and do NOT report success: Aura
				// retries the same variant against the same magic_id, and a
				// connect that "succeeded" unbound would leave the re-home race
				// open behind a green onboarding. 5xx: retryable, never a reason
				// for Aura to fall back to a variant without the client line.
				// Release the claim so that retry is not refused as in progress.
				$release();
				return new WP_REST_Response( array( 'error' => $bound->get_error_message(), 'code' => $bound->get_error_code() ), 500 );
			}
		} else {
			// An older dashboard names no client: clear, exactly as before. The
			// old client's rules are not this site's to keep, and the new
			// client's seq starts wherever it starts.
			Aura_Worker_Rules::clear();
		}
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
			if ( '' === $client ) {
				Aura_Worker_Rules::clear(); // keyless AND clientless: an older dashboard — clear as before
			}
		}
		// 2.11.0: the callback also mints an Application Password for the admin
		// who created the link, so a magic-link connection can run the
		// builder tools (Elementor MCP etc.) that authenticate with WordPress
		// Basic auth — until now only a manual connect could. Returned ONCE, in
		// this response to the signed request, over the same TLS the token
		// travelled; the dashboard stores it encrypted beside the site token.
		// Every connect rotates it: earlier passwords under the fixed name are
		// deleted first, so the site never accumulates them and the previous
		// one dies with the previous token. Unavailable (no HTTPS, disabled,
		// no admin user, pre-5.6 core) is not a failure of the connect — the
		// field is omitted and the reason is named, and the connection stays
		// token-only exactly as before.
		// Minted BEFORE the transient is consumed (round-5): revoking the
		// PREVIOUS owner's Aura password is the rotation's security promise,
		// and a revocation that did not land leaves an administrator-level
		// credential live beside the new token. That is the ONE outcome that
		// must be retryable — so the transient (and the claim) survive it and
		// the dashboard retries the whole connect. Every other mint failure
		// (creator not an admin, Application Passwords unavailable, the owner
		// record not persisting — which revokes its own fresh password inside
		// mint) leaves nothing dangerous and completes token-only.
		$body = array( 'success' => true );
		if ( ! self::holds_site_claim( $site_fence ) ) {
			$release();
			return new WP_REST_Response( array( 'error' => 'This connect lost the site to another install; retry.', 'code' => 'aura_connect_lost_claim' ), 409 );
		}
		$minted = self::mint_app_password( (int) ( $stored['connect_user_id'] ?? 0 ) );
		// Two mint outcomes leave an administrator-level credential live on the
		// site: a previous password that would not die (app_password_revoke_failed)
		// and a fresh one that could neither be recorded nor deleted
		// (app_password_orphaned, round-7). Both are retryable 500s that keep
		// the transient and the claim — never a token-only "success".
		if ( is_wp_error( $minted ) && in_array( $minted->get_error_code(), array( 'app_password_revoke_failed', 'app_password_orphaned' ), true ) ) {
			$release(); // keep the transient — this connect is retryable
			return new WP_REST_Response(
				array(
					'error' => 'app_password_orphaned' === $minted->get_error_code()
						? 'A new Application Password could not be recorded or revoked; retry.'
						: 'A previous Application Password could not be revoked; retry.',
					'code'  => $minted->get_error_code(),
				),
				500
			);
		}
		// The mint is the last protected write, and the only one that hands a
		// credential back — so it is verified after the fact too (round-8). A
		// handler that lost the site here revokes what it just created rather
		// than returning a password beside another install's token.
		if ( ! is_wp_error( $minted ) && ! self::holds_site_claim( $site_fence ) ) {
			WP_Application_Passwords::delete_application_password( (int) ( $stored['connect_user_id'] ?? 0 ), $minted['uuid'] );
			$release();
			return new WP_REST_Response( array( 'error' => 'This connect lost the site to another install; retry.', 'code' => 'aura_connect_lost_claim' ), 409 );
		}
		// Consumed only now (the round-23 orphan rule still holds: the claim is released with it below).
		delete_transient( 'aura_magic_' . $magic_id );
		if ( is_wp_error( $minted ) ) {
			$body['app_password_unavailable'] = $minted->get_error_code();
		} else {
			// The uuid is the site's own bookkeeping — never part of the response.
			$body['app_password'] = array( 'user_login' => $minted['user_login'], 'password' => $minted['password'] );
		}
		// Released only NOW (round-3): the site-wide claim exists to make token
		// and password one handler's; released before the mint, a paused
		// handler could resume and rotate away the password the winner just
		// returned.
		$release();

		return new WP_REST_Response( $body, 200 );
	}

	/** The fixed name every Aura-minted Application Password carries — the rotation key. */
	const APP_PASSWORD_NAME = 'Aura SiteAgent';

	/**
	 * The site-wide connect claim (2.11.0): one install at a time.
	 *
	 * Deliberately NOT under Aura_Worker_Rules::MAGIC_CLAIM (round-8): every
	 * option under that prefix is deleted by the hourly sweep once it is an
	 * hour old. For a per-link claim that is harmless — the magic transient it
	 * belongs to expired fifty minutes earlier, so the link is refused at the
	 * transient check anyway. For this one it would be an age-based takeover
	 * by another name, admitting a second install beside a handler that may
	 * still resume — the exact mechanism this claim exists to prevent, and the
	 * one the owner ruled out. Its own name keeps it out of every sweep.
	 */
	const SITE_CLAIM = 'aura_worker_connect_lock';
	/**
	 * The site claim has NO timed takeover (round-7, owner decision). A
	 * connect handler that a client timeout abandoned is not a handler that
	 * stopped: PHP keeps running it, and an age-based takeover would let a
	 * replacement start writing while the original may still resume — exactly
	 * the credential-splitting race the claim exists to prevent. Recovery of
	 * an orphaned claim is therefore an explicit operator action: deactivating
	 * the plugin (which no handler survives) deletes it. See
	 * aura_worker_deactivate() in digitizer-site-worker.php.
	 */

	/** The user who owns the CURRENT Aura-minted Application Password — the rotation revokes theirs too, whoever connects next. */
	const APP_PASSWORD_OWNER_OPTION = 'aura_worker_app_password_user_id';
	/** The UUID of the CURRENT Aura-minted Application Password — the STABLE identity the rotation deletes by (round-5): the display name is user-chosen, not unique, and renameable. */
	const APP_PASSWORD_UUID_OPTION = 'aura_worker_app_password_uuid';

	/**
	 * Mint the dashboard's Application Password for a user, rotating any
	 * earlier one Aura minted (2.11.0).
	 *
	 * @param int $user_id The admin who created the magic link.
	 * @return array{user_login:string,password:string,uuid:string}|WP_Error
	 */
	public static function mint_app_password( int $user_id ) {
		if ( ! class_exists( 'WP_Application_Passwords' ) || ! function_exists( 'wp_is_application_passwords_available_for_user' ) ) {
			return new WP_Error( 'app_passwords_unsupported', 'This WordPress does not support Application Passwords.' );
		}
		// REVOKE FIRST, whatever happens next (round-3): the token was just
		// replaced, so the previous owner's Aura password must die with it even
		// when no replacement can be minted for this creator (not an admin,
		// Application Passwords unavailable for them). Rotation is a promise
		// about the OLD credential, not a side effect of minting a new one.
		if ( ! self::revoke_managed_password() ) {
			// A revocation that did not land is NOT reported as a rotation
			// (round-4): the old credential may still be valid, so nothing new
			// is minted beside it and the dashboard is told why.
			return new WP_Error( 'app_password_revoke_failed', 'A previous Aura Application Password could not be revoked; no new one was minted.' );
		}
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user || empty( $user->user_login ) ) {
			return new WP_Error( 'connect_user_unknown', 'The user who created this connect link no longer exists.' );
		}
		if ( ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'connect_user_not_admin', 'Only an administrator\'s connect link can mint an Application Password.' );
		}
		if ( ! wp_is_application_passwords_available_for_user( $user ) ) {
			return new WP_Error( 'app_passwords_unavailable', 'Application Passwords are unavailable for this user (HTTPS required, or disabled by a filter).' );
		}
		$created = WP_Application_Passwords::create_new_application_password( $user_id, array( 'name' => self::APP_PASSWORD_NAME ) );
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		if ( ! is_array( $created ) || empty( $created[0] ) || ! is_string( $created[0] ) || empty( $created[1]['uuid'] ) ) {
			return new WP_Error( 'app_password_mint_failed', 'WordPress did not return a new Application Password.' );
		}
		$uuid = (string) $created[1]['uuid'];
		// The owner record is what a later rotation revokes by — it must be
		// DURABLE before the password is handed out (round-4). Verified by a
		// fresh read, not update_option()'s return (false also means
		// "unchanged"); if it did not persist, the password just created is
		// revoked again and the connect stays token-only.
		self::persist_password_owner( $user_id, $uuid );
		if ( (int) get_option( self::APP_PASSWORD_OWNER_OPTION, 0 ) !== $user_id || (string) get_option( self::APP_PASSWORD_UUID_OPTION, '' ) !== $uuid ) {
			// The cleanup is VERIFIED, never assumed (round-7): with option and
			// user-meta writes both failing, this delete can fail too, and an
			// ignored result would hand back a token-only success while a live
			// administrator credential sits on the site with nothing recording
			// it. Same proof the rotation uses — the password is gone only when
			// it is absent from the owner's list.
			WP_Application_Passwords::delete_application_password( $user_id, $uuid );
			if ( self::managed_password_gone( $user_id, $uuid ) ) {
				return new WP_Error( 'app_password_owner_unrecorded', 'The Application Password owner could not be recorded; the password was revoked and none was returned.' );
			}
			// Still live and untracked. Try once more to record it — tracking is
			// what a later rotation revokes by, so recovering it is worth more
			// than the failed delete — and fail RETRYABLY either way, so the
			// connect is not reported as completed beside an orphan credential.
			self::persist_password_owner( $user_id, $uuid );
			return new WP_Error( 'app_password_orphaned', 'A new Application Password could not be revoked after its owner record failed to persist; no connection was completed.' );
		}
		return array( 'user_login' => (string) $user->user_login, 'password' => $created[0], 'uuid' => $uuid );
	}

	/**
	 * Store the site token ONLY while this handler still holds the site claim,
	 * in a single statement (round-9). A check followed by a write is two
	 * statements: a handler paused between them resumes and writes anyway, and
	 * a check cannot be retried into atomicity. Joining the claim row into the
	 * UPDATE makes ownership part of the write's own predicate, so a handler
	 * that lost the claim matches no row.
	 *
	 * The caller verifies the result by reading the row back (it already did,
	 * for the filter-rewrite case): 0 rows affected also means "the value was
	 * already this", and a site whose token row does not exist yet needs the
	 * INSERT below, likewise conditional on the claim.
	 *
	 * @param string $token_hash The hash to store.
	 * @param string $fence      This handler's claim fence.
	 */
	private static function write_token_under_claim( $token_hash, $fence ) {
		global $wpdb;
		if ( '' === (string) $fence ) {
			return;
		}
		$like = $wpdb->esc_like( $fence . '|' ) . '%';
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} o JOIN {$wpdb->options} c ON c.option_name = %s AND c.option_value LIKE %s SET o.option_value = %s WHERE o.option_name = %s",
				self::SITE_CLAIM,
				$like,
				$token_hash,
				'aura_worker_site_token'
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) SELECT %s, %s, %s FROM {$wpdb->options} c WHERE c.option_name = %s AND c.option_value LIKE %s AND NOT EXISTS ( SELECT 1 FROM {$wpdb->options} WHERE option_name = %s )",
				'aura_worker_site_token',
				$token_hash,
				'yes',
				self::SITE_CLAIM,
				$like,
				'aura_worker_site_token'
			)
		);
		// Both statements went round the option cache; evict what update_option()
		// would have maintained, including the autoloaded bundle.
		wp_cache_delete( 'aura_worker_site_token', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Does this handler still hold the site-wide claim? Read from the row, not
	 * the option cache — the claim is written and deleted with raw SQL, so the
	 * cache can hold a copy from before either.
	 *
	 * Every write the claim protects checks this first (round-8). The claim's
	 * release policy is then no longer what keeps two installs apart: an
	 * operator releasing a stuck claim (or anything else removing it) cannot
	 * corrupt a handler that resumes afterwards, because that handler's next
	 * protected write refuses to run. Residual, and unavoidable in an option
	 * store with no compare-and-set: a handler descheduled between this check
	 * and the write it guards can still land one write. The window is
	 * microseconds rather than the whole length of a request.
	 *
	 * @param string $fence The value claim_magic_link() returned.
	 * @return bool
	 */
	private static function holds_site_claim( $fence ) {
		global $wpdb;
		if ( '' === (string) $fence ) {
			return false;
		}
		$held = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::SITE_CLAIM ) );
		return is_string( $held ) && 0 === strpos( $held, $fence . '|' );
	}

	/**
	 * Delete the site-wide connect claim — the operator's explicit release
	 * (round-7, owner decision). The claim has no timed takeover, so a handler
	 * killed mid-connect leaves one that refuses every later connect and no
	 * clock may clear it. Wired ONLY to the plugin's activation and
	 * deactivation hooks: no connect handler survives either, so removing the
	 * claim there cannot admit a second install beside a live one. Never call
	 * it from a request path.
	 */
	public static function forget_site_claim() {
		delete_option( self::SITE_CLAIM );
	}

	/**
	 * Take the site-wide connect claim from OUTSIDE a connect callback
	 * (round-7): "Regenerate Token" invalidates the same binding a connect
	 * installs — token, dashboard URL, Application Password — so it must be
	 * ordered against a connect the same way one connect is ordered against
	 * another. Without it, a regeneration that runs between a callback's
	 * revocation and its mint sees nothing to revoke and reports the site
	 * disconnected, while the callback goes on to hand out a fresh
	 * administrator credential the UI no longer admits exists.
	 *
	 * @return string The caller's fence when it holds the claim, else ''.
	 */
	public static function claim_site() {
		return self::claim_magic_link( self::SITE_CLAIM );
	}

	/**
	 * Release a claim taken with claim_site(). Conditional on the fence, so
	 * only the holder's own claim is removed.
	 *
	 * @param string $fence The value claim_site() returned.
	 */
	public static function release_site( $fence ) {
		if ( '' === (string) $fence ) {
			return;
		}
		self::release_magic_link( self::SITE_CLAIM, $fence );
	}

	/**
	 * Write the owner/UUID pair a later rotation revokes by, evicting the
	 * option cache so the verifying read that follows sees the database.
	 * ONE implementation — the mint's first attempt and its recovery attempt
	 * must not drift apart.
	 *
	 * @param int    $user_id Owner of the password.
	 * @param string $uuid    Its UUID.
	 */
	private static function persist_password_owner( int $user_id, string $uuid ) {
		update_option( self::APP_PASSWORD_OWNER_OPTION, $user_id );
		update_option( self::APP_PASSWORD_UUID_OPTION, $uuid );
		wp_cache_delete( self::APP_PASSWORD_OWNER_OPTION, 'options' );
		wp_cache_delete( self::APP_PASSWORD_UUID_OPTION, 'options' );
	}

	/**
	 * Is the password identified by $uuid really gone from $owner's list?
	 * delete_application_password() answers false for a failed user-meta
	 * write as well as for "not there", so its return value alone never
	 * proves a revocation landed — the owner's list does. ONE implementation,
	 * used by the rotation and by the mint's cleanup.
	 *
	 * @param int    $owner Owner user ID.
	 * @param string $uuid  Password UUID.
	 * @return bool True when nothing with that UUID remains.
	 */
	private static function managed_password_gone( int $owner, string $uuid ): bool {
		foreach ( WP_Application_Passwords::get_user_application_passwords( $owner ) as $item ) {
			if ( isset( $item['uuid'] ) && $uuid === (string) $item['uuid'] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Delete every Aura-minted Application Password (the fixed name is the
	 * key) of the new creator AND the previous owner, and forget the owner.
	 * Admin B reconnecting a site admin A connected must revoke A's too, or an
	 * administrator-level REST credential outlives the token it was minted
	 * beside (round-2). The owner is recorded per mint, so it is always known.
	 *
	 * Called by every path that invalidates the dashboard's binding — this
	 * rotation, "Regenerate Token", and uninstall (round-6) — so an
	 * administrator-level credential never outlives the token it was minted
	 * beside.
	 *
	 * @return bool True when nothing dangerous remains.
	 */
	public static function revoke_managed_password(): bool {
		$owner = (int) get_option( self::APP_PASSWORD_OWNER_OPTION, 0 );
		$uuid  = (string) get_option( self::APP_PASSWORD_UUID_OPTION, '' );
		if ( $owner <= 0 || '' === $uuid ) {
			return true; // nothing recorded — first mint, or already cleared
		}
		// By the STORED UUID, never the display name (round-5): the name is
		// user-chosen, so a stranger's "Aura SiteAgent" must not be nuked, and
		// a renamed Aura password must still be found.
		$deleted = WP_Application_Passwords::delete_application_password( $owner, $uuid );
		if ( true !== $deleted && ! self::managed_password_gone( $owner, $uuid ) ) {
			return false; // a genuine delete failure — the credential is still live
		}
		delete_option( self::APP_PASSWORD_OWNER_OPTION );
		delete_option( self::APP_PASSWORD_UUID_OPTION );
		return true;
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
	 *                              as a bare line only when non-empty.
	 * @param string $client        Optional Aura client id this site is being
	 *                              bound to; appended as `client:<id>` only when
	 *                              non-empty (2.10.2).
	 * @return string Lowercase hex HMAC-SHA256 digest.
	 */
	public static function sign_connect_payload( $secret, $magic_id, $token, $dashboard_url, $timestamp, $grant_pubkey = '', $client = '' ) {
		$parts = array( $magic_id, $token, $dashboard_url, (string) $timestamp );
		// Optional lines, appended ONLY when non-empty and in this fixed order —
		// grant public key, then client — so existing 4- and 5-field callbacks
		// keep validating unchanged. The Aura dashboard MUST follow the same
		// rule (include iff non-empty, same order).
		if ( '' !== (string) $grant_pubkey ) {
			$parts[] = (string) $grant_pubkey;
		}
		if ( '' !== (string) $client ) {
			// Labelled: the pubkey line is bare (2.x wire format, unchangeable), so
			// an unlabelled client line would make { pubkey: X, client: '' } and
			// { pubkey: '', client: X } the same message — a valid key could be
			// moved into `client` without the secret (Codex round 32). The label
			// is part of the signed text, never of the stored client.
			$parts[] = 'client:' . (string) $client;
		}
		return hash_hmac( 'sha256', implode( "\n", $parts ), $secret );
	}

	/**
	 * Take the per-magic-link claim. The value is "<fence>|<unix ts>": the
	 * FENCE is a random token only this handler knows (its release names it),
	 * the timestamp is for the orphan sweep. ONE atomic path: a real
	 * conditional INSERT through $wpdb, decided by wp_options' UNIQUE KEY on
	 * option_name. An existing row — however old — is refused.
	 *
	 * NOT add_option(). Core's add_option() checks for the option (skipping
	 * that check entirely whenever `notoptions` lists the name) and then runs
	 * `INSERT … ON DUPLICATE KEY UPDATE` — two statements. Two callbacks for
	 * one magic link can both pass the check, both be answered true, and the
	 * later one's fence overwrites the earlier claim; both handlers then run
	 * on past the still-live transient and interleave their writes, which is
	 * precisely what this claim exists to prevent (Codex round 1 on #66). It
	 * is the same hazard insert_if_absent() documents for the ruleset store,
	 * and the same remedy: let the database decide. The affected-row count is
	 * the answer directly — 1 inserted (this handler holds the claim),
	 * 0 a row was already there, false a database error (including the
	 * duplicate-key/deadlock a concurrent insert is reported as). Anything
	 * that is not exactly 1 means this handler did not take the claim, so it
	 * must not proceed: all of them are answered 409 aura_connect_in_progress,
	 * and retry is the right client behaviour in every one of those cases.
	 *
	 * No timed takeover, deliberately. Every takeover rule reviewed for this
	 * plan (an age, twice the execution limit, fences re-checked before each
	 * write) left an interleaving in which a paused original resumed over its
	 * replacement, because a check and the write after it are two statements.
	 * Without takeover the guarantee holds by construction: while a claim row
	 * exists, exactly one handler — the one that inserted it — is working this
	 * magic link. A handler that dies holding the claim costs that one magic
	 * link (one-time, ten-minute transient): the operator generates another.
	 * Orphaned rows are garbage only (their transient is gone, so nobody can
	 * use the magic link again) and are swept by age in sweep_options().
	 *
	 * A falsy add_option() is answered as 409 aura_connect_in_progress whatever
	 * caused it — a row already there, an INSERT the database refused, or a
	 * `default_option_*` filter making core think the option exists. This
	 * handler did not take the claim, so it must not proceed, and "retry" is
	 * the right client behaviour in every one of those cases.
	 *
	 * @since 2.10.2
	 *
	 * @param string $claim_key Option name.
	 * @return string This handler's fence when it holds the claim, else ''.
	 */
	private static function claim_magic_link( $claim_key ) {
		global $wpdb;
		$fence = bin2hex( random_bytes( 16 ) );
		$rows  = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) SELECT %s, %s, %s FROM DUAL WHERE NOT EXISTS ( SELECT 1 FROM {$wpdb->options} WHERE option_name = %s )",
				$claim_key,
				$fence . '|' . time(),
				'no',
				$claim_key
			)
		);
		if ( 1 !== (int) $rows || '' !== (string) $wpdb->last_error ) {
			return ''; // 0: a row is there. false/last_error: nothing was claimed.
		}
		// The row was created behind the option cache's back, so evict what
		// add_option() would have maintained: this name, and the `notoptions`
		// entry any earlier miss on it left (see insert_if_absent()).
		wp_cache_delete( $claim_key, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return $fence;
	}

	/**
	 * Release: delete the claim ONLY if it still carries this handler's fence
	 * (a conditional DELETE). Nobody else removes a live claim, so this guards
	 * only against a double release within one handler.
	 *
	 * @since 2.10.2
	 *
	 * @param string $claim_key Option name.
	 * @param string $fence     This handler's fence.
	 */
	private static function release_magic_link( $claim_key, $fence ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s", $claim_key, $wpdb->esc_like( $fence . '|' ) . '%' ) );
		wp_cache_delete( $claim_key, 'options' );
	}
}
