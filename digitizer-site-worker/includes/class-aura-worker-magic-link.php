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

		// Rounds 19-26 each found another path that ends without a usable
		// builder credential and each time the screen hid the way back. The
		// paths are not the bug — hiding the button is. It is ALWAYS rendered
		// now, so no failure anyone finds next can take the recovery control
		// away, and the state above it is DERIVED from the one thing that
		// answers the question — what credential the site actually holds —
		// rather than from a marker some path forgot to set.
		$state     = self::credential_state();
		$connected = ( $dashboard_url && $site_token );
		if ( 'unbound' === $state ) {
			// Aura has disconnected this site: both write boundaries refuse
			// every mutation, so the connected block below would describe a
			// connection that no longer does anything (#434 Task 9).
			self::render_unbound_panel();
		} elseif ( $connected ) {
			$healthy = ( 'delivered' === $state || 'unavailable' === $state );
			echo '<p style="color:' . ( $healthy ? '#2e7d32' : '#b26a00' ) . ';">';
			echo '<span class="dashicons dashicons-' . ( $healthy ? 'yes-alt' : 'warning' ) . '"></span> ';
			echo esc_html__( 'Connected to Aura dashboard:', 'digitizer-site-worker' ) . ' ';
			echo '<strong>' . esc_html( $dashboard_url ) . '</strong>';
			echo '</p>';
			if ( 'unavailable' === $state ) {
				echo '<p>' . esc_html__( 'This site cannot issue the Application Password the builder tools use, so the connection is token-only.', 'digitizer-site-worker' ) . '</p>';
			} elseif ( 'undelivered' === $state ) {
				echo '<p>' . esc_html__( 'The last connect could not deliver the credential the builder tools use. Connect again to issue a new one.', 'digitizer-site-worker' ) . '</p>';
			} elseif ( 'none' === $state ) {
				echo '<p>' . esc_html__( 'This connection has no credential for the builder tools — it was revoked, or never issued. Connect again to issue one.', 'digitizer-site-worker' ) . '</p>';
			}
		}

		$nonce = wp_create_nonce( 'aura_magic_link' );
		?>
		<p><?php esc_html_e( 'Connect this site to your Aura dashboard with one click.', 'digitizer-site-worker' ); ?></p>
		<button type="button" id="aura-connect-btn" class="button button-primary">
			<?php echo esc_html( $connected ? __( 'Reconnect to Aura', 'digitizer-site-worker' ) : __( 'Connect to Aura', 'digitizer-site-worker' ) ); ?>
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
	 * The "Disconnected by Aura" panel and its teardown control (#434 Task 9).
	 *
	 * The one screen a site whose dashboard is gone still has. Everything on it
	 * is read from the site's own state, uncached, and reported at exactly the
	 * confidence that state supports:
	 *
	 *  - The TIME comes from the marker read as a tri-state, never from
	 *    is_set(): a marker that could not be read still refuses every mutation
	 *    (which is why credential_state() sent us here), but it names no
	 *    moment, and printing an empty timestamp — or worse, inventing one —
	 *    would be a claim this site cannot make. It says so instead.
	 *  - The LEFTOVERS are named only for a marker that was actually read.
	 *    leftovers() fails closed and answers all four steps for an unreadable
	 *    one, which is right for a GATE and wrong for a screen: rendered as a
	 *    list it would report four specific debts nobody has established
	 *    (#434 Task 4 review, Task 9's half).
	 *  - The BUTTON is offered while anything remains, and "anything" includes
	 *    the site token, which leftovers() tracks steps (1)-(4) only and can
	 *    never name. An unreadable token row counts as present: absence has to
	 *    be proven.
	 *
	 * @return void
	 */
	private static function render_unbound_panel(): void {
		$marker    = Aura_Worker_Unbind::read();
		$at        = is_array( $marker ) ? (string) $marker['at'] : '';
		$malformed = is_wp_error( $marker ) && Aura_Worker_Unbind::MALFORMED_CODE === $marker->get_error_code();
		echo '<div class="notice notice-warning aura-unbound"><p>';
		if ( '' !== $at ) {
			printf(
				/* translators: %s: the moment Aura disconnected this site, ISO-8601. */
				esc_html__( 'Disconnected by Aura at %s', 'digitizer-site-worker' ),
				'<time datetime="' . esc_attr( $at ) . '">' . esc_html( $at ) . '</time>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attribute and text are escaped inline, immediately above.
			);
		} elseif ( $malformed ) {
			echo esc_html__( 'Disconnected by Aura. The disconnect record on this site is damaged, so it cannot say when — removing the remaining Aura data will rebuild the record from this site and then clear it.', 'digitizer-site-worker' );
		} else {
			echo esc_html__( 'Disconnected by Aura. This site cannot read its own disconnect record, so it cannot say when, or exactly what is left to remove.', 'digitizer-site-worker' );
		}
		echo '</p></div>';

		$leftovers = Aura_Worker_Unbind::leftovers();
		$token     = Aura_Worker_Rules::read_option_uncached( 'aura_worker_site_token' );
		// `null` is the ONE answer that means the row is gone: a WP_Error from
		// a read that did not complete is not null, so an unreadable token row
		// leaves the control on offer — unreadable is not absent, the same rule
		// Phase B's own step proofs follow. Written as the single comparison it
		// is; an `is_wp_error( $token ) ||` in front would read like a second
		// guard while being dead code (round-1 LOW-1).
		$has_token = null !== $token;
		if ( array() === $leftovers && ! $has_token ) {
			echo '<p>' . esc_html__( 'Nothing of the previous connection remains on this site.', 'digitizer-site-worker' ) . '</p>';
			return;
		}

		if ( is_array( $marker ) ) {
			echo '<p>' . esc_html__( 'Some of the previous connection is still on this site:', 'digitizer-site-worker' ) . ' ';
			echo '<code>' . esc_html( implode( ', ', array_merge( $leftovers, $has_token ? array( 'token' ) : array() ) ) ) . '</code></p>';
		} elseif ( $malformed ) {
			// BOTH halves, because the rebuilt list is a superset of what Aura
			// minted and NOT a subset of what it may remove. An operator who
			// reads only one of these sentences is misled either way.
			echo '<p>' . esc_html__( 'A damaged record may no longer name an Application Password that was supplied by hand rather than issued by Aura: revoke any such password yourself under Users → Profile → Application Passwords.', 'digitizer-site-worker' ) . '</p>';
			echo '<p>' . esc_html__( 'Every Application Password on this site named “Aura SiteAgent” will be removed, including one you created yourself under that name.', 'digitizer-site-worker' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'This site cannot determine what the previous connection left behind.', 'digitizer-site-worker' ) . '</p>';
		}

		$nonce = wp_create_nonce( 'aura_worker_remove_aura_data' );
		?>
		<button type="button" id="aura-remove-data-btn" class="button"
				data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<?php esc_html_e( 'Remove remaining Aura data', 'digitizer-site-worker' ); ?>
		</button>
		<span id="aura-remove-data-status" style="margin-left:10px;"></span>
		<p class="description">
			<?php esc_html_e( 'Removes the Application Passwords, options, ruleset and site token this connection installed. Anything that cannot be removed is named instead, and nothing is forgotten until all of it is gone.', 'digitizer-site-worker' ); ?>
		</p>
		<script>
		(function() {
			var btn = document.getElementById('aura-remove-data-btn');
			if ( ! btn ) { return; }
			btn.addEventListener('click', function() {
				if ( ! window.confirm(<?php echo wp_json_encode( __( 'Remove the remaining Aura data from this site? This cannot be undone.', 'digitizer-site-worker' ) ); ?>) ) { return; }
				var status = document.getElementById('aura-remove-data-status');
				btn.disabled = true;
				status.textContent = <?php echo wp_json_encode( __( 'Removing…', 'digitizer-site-worker' ) ); ?>;
				var data = new FormData();
				data.append('action', 'aura_worker_remove_aura_data');
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
		// An orphaned claim was originally NOT taken over by age at all
		// (round-7): a handler the dashboard gave up on may still be running,
		// and a takeover would let this install interleave with it. #434
		// review round 1 (I2) bounds that instead of ruling it out entirely —
		// SITE_CLAIM_TAKEOVER_AFTER seconds, well past any real request —
		// because every write below is already claim-conditional
		// (write_option_if_claimed()/bind()'s $claim,$fence/
		// delete_option_if_claimed(), all the way through the mint at the
		// end): a resumed original that lost the claim to a seize has every
		// one of its own writes refused, not racing the replacement. Recovery
		// of a genuinely stuck claim is no longer solely an operator action.
		$site_fence = self::claim_magic_link( $site_claim_key, self::SITE_CLAIM_TAKEOVER_AFTER );
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
		// THE WAY BACK, first half (#434 Task 7). A site Aura unbound refuses
		// every mutation until the marker goes, and this callback is one of the
		// only two things that may take it away. Before any of this install is
		// written, the DEPARTED binding's Phase-B debt is settled — under the
		// claim this handler already holds — because the token write a few
		// lines below permanently disarms the site's own sweep
		// (Aura_Worker_Unbind::maybe_finish() bails on the hash mismatch a
		// replacement token creates). An Application Password the marker names
		// and nothing revoked would be stranded live at that point, with
		// nothing left on the site that would ever look for it again. So a site
		// that still owes something is not reconnected at all: 409, the
		// leftovers named, the marker untouched, the old token still refused.
		// FIRST, before the connect-user write below: Phase B step (2) deletes
		// exactly that option, so settling afterwards would erase this
		// install's own write.
		$finished = Aura_Worker_Unbind::finish_before_rebind( $site_fence );
		if ( is_wp_error( $finished ) ) {
			$data = $finished->get_error_data();
			$release();
			return new WP_REST_Response(
				array(
					'error'    => $finished->get_error_message(),
					'code'     => $finished->get_error_code(),
					'leftover' => isset( $data['leftover'] ) && is_array( $data['leftover'] ) ? $data['leftover'] : array(),
				),
				409
			);
		}
		if ( ! empty( $stored['connect_user_id'] ) ) {
			Aura_Worker_Rules::write_option_if_claimed( 'aura_worker_connect_user_id', (int) $stored['connect_user_id'], $site_claim_key, $site_fence );
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
		// The token is stored, so the credential minted beside the PREVIOUS one
		// is now a credential without a token — revoke it here, before anything
		// else can fail (round-34). Left until the mint, a binding or gateway-key
		// failure returned 500 with the old administrator password still valid,
		// and if no retry completed before the magic link expired it stayed
		// valid indefinitely. Rotation is a promise about the OLD credential,
		// kept at the moment the token it belonged to is replaced.
		if ( self::tracking_is_incomplete() ) {
			// Something is recorded that names a password nothing here can
			// delete. Minting beside it would add a second live administrator
			// credential, so the link is consumed and the operator is told.
			delete_transient( 'aura_magic_' . $magic_id );
			$release();
			return new WP_REST_Response( array( 'error' => 'This site records half an Aura Application Password, so another cannot be minted beside it; revoke it by hand in Users → Profile → Application Passwords and delete the aura_worker_app_password option.', 'code' => 'app_password_tracking_incomplete' ), 500 );
		}
		if ( ! self::revoke_managed_password( $site_fence ) ) {
			$release();
			// WHICH failure it was is decided by what the site still records
			// (round-20/32): nothing recorded at all means a live credential
			// nothing can find, which no retry may mint beside.
			$anything = get_option( self::APP_PASSWORD_RECORD_OPTION, null );
			if ( null === $anything || false === $anything || '' === $anything ) {
				delete_transient( 'aura_magic_' . $magic_id );
				// translators: internal log line, not shown to the user.
				error_log( 'SiteAgent: a previous Aura Application Password could be neither revoked nor recorded; revoke it by hand in Users → Profile → Application Passwords.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				return new WP_REST_Response( array( 'error' => 'A previous Application Password could be neither revoked nor recorded; revoke it by hand in Users → Profile → Application Passwords.', 'code' => 'app_password_orphan_untracked' ), 500 );
			}
			return new WP_REST_Response( array( 'error' => 'A previous Application Password could not be revoked; retry.', 'code' => 'app_password_revoke_failed' ), 500 );
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
			$bound = Aura_Worker_Rules::bind( $client, $token_hash, $site_claim_key, $site_fence );
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
			// client's seq starts wherever it starts. Verified like every other
			// install write (round-35) — a clear that did not land leaves the
			// old client's policy governing the new dashboard behind a 200.
			if ( ! self::clear_ruleset_verified( $site_claim_key, $site_fence ) ) {
				$release();
				return new WP_REST_Response( array( 'error' => 'Connect not completed: the previous ruleset could not be cleared; retry.', 'code' => 'aura_connect_store_failed' ), 500 );
			}
		}
		// Every remaining install write is conditional on the claim too
		// (round-10). A handler that lost it would otherwise leave the winner's
		// install carrying this handler's dashboard URL and gateway key —
		// grants signed for the winner's key would then fail closed, behind a
		// 200 the winner already returned.
		Aura_Worker_Rules::write_option_if_claimed( 'aura_worker_dashboard_url', $dashboard_url, $site_claim_key, $site_fence );
		// Verified like the token and the gateway key (round-39): the settings
		// screen reads this row to know the site is connected at all, so a write
		// that did not land leaves the dashboard believing onboarding finished
		// over a site that reports itself disconnected.
		$stored_url = Aura_Worker_Rules::read_option_uncached( 'aura_worker_dashboard_url' );
		if ( is_wp_error( $stored_url ) || (string) $stored_url !== (string) $dashboard_url ) {
			$release();
			return new WP_REST_Response( array( 'error' => 'Connect not completed: the dashboard URL could not be stored; retry.', 'code' => 'aura_connect_store_failed' ), 500 );
		}
		if ( '' !== $grant_pubkey ) {
			// Provision the gateway key → turns on approval-grant enforcement
			// (Aura_Worker_Grant::is_enforced()).
			Aura_Worker_Rules::write_option_if_claimed( 'aura_worker_grant_pubkey', $grant_pubkey, $site_claim_key, $site_fence );
			// …and it is VERIFIED before this connect can succeed (round-18).
			// is_enforced() follows the key: a write that silently did not land
			// leaves enforcement OFF while the dashboard believes the site is
			// protected, so every approval-required and mutating tool stays
			// reachable with the site token alone. Retryable 500, transient
			// kept — never a 200 over an unprotected site.
			$stored_key = Aura_Worker_Rules::read_option_uncached( 'aura_worker_grant_pubkey' );
			if ( is_wp_error( $stored_key ) || ! is_string( $stored_key ) || ! hash_equals( $grant_pubkey, $stored_key ) ) {
				$release();
				return new WP_REST_Response( array( 'error' => 'Connect not completed: the approval-grant key could not be stored; retry.', 'code' => 'aura_connect_store_failed' ), 500 );
			}
		} else {
			// Keyless (re)connect: clear any previously provisioned key so a fresh
			// dashboard that doesn't use grants isn't left unable to run writes
			// against a stale key it can't sign for. Enforcement follows the key.
			Aura_Worker_Rules::delete_option_if_claimed( 'aura_worker_grant_pubkey', $site_claim_key, $site_fence );
			// Verified the same way, and for the mirror reason: a key this
			// dashboard cannot sign for, left in place, fails every write
			// closed. Enforcement follows the key in both directions.
			$stored_key = Aura_Worker_Rules::read_option_uncached( 'aura_worker_grant_pubkey' );
			if ( is_wp_error( $stored_key ) || ( is_string( $stored_key ) && '' !== $stored_key ) ) {
				$release();
				return new WP_REST_Response( array( 'error' => 'Connect not completed: the previous approval-grant key could not be cleared; retry.', 'code' => 'aura_connect_store_failed' ), 500 );
			}
			if ( '' === $client ) {
				// Keyless AND clientless: an older dashboard — clear as before,
				// and VERIFY it (round-35). A conditional delete that failed
				// leaves the previous ruleset visible through current(), so the
				// old client's block and warn policy would go on governing the
				// newly connected dashboard behind a 200.
				if ( ! self::clear_ruleset_verified( $site_claim_key, $site_fence ) ) {
					$release();
					return new WP_REST_Response( array( 'error' => 'Connect not completed: the previous ruleset could not be cleared; retry.', 'code' => 'aura_connect_store_failed' ), 500 );
				}
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
		$minted = self::mint_app_password( (int) ( $stored['connect_user_id'] ?? 0 ), $site_fence );
		if ( is_wp_error( $minted ) && in_array( $minted->get_error_code(), array( 'app_password_orphan_untracked', 'app_password_tracking_incomplete' ), true ) ) {
			// The one outcome that must NOT be retried (round-11): a live
			// administrator credential exists that nothing on the site records,
			// so another attempt would mint a second one beside it. The magic
			// link is consumed here precisely to stop that, and the operator is
			// told what to revoke by hand.
			delete_transient( 'aura_magic_' . $magic_id );
			$release();
			// translators: internal log line, not shown to the user.
			error_log( 'SiteAgent: an Aura Application Password could be neither recorded nor revoked; revoke it by hand in Users → Profile → Application Passwords.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return new WP_REST_Response( array( 'error' => $minted->get_error_message(), 'code' => $minted->get_error_code() ), 500 );
		}
		// The mint is the last protected step, and the only one that hands a
		// credential back — so the claim is verified after it too, whatever it
		// returned (round-12). A handler that lost the site revokes what it
		// created and reports nothing; every other outcome below assumes this
		// handler is still the install.
		if ( ! self::holds_site_claim( $site_fence ) ) {
			$creator = (int) ( $stored['connect_user_id'] ?? 0 );
			if ( ! is_wp_error( $minted ) ) {
				// Verified, never assumed (round-16). This handler's tracking
				// writes were refused with the claim, so the password it just
				// created is recorded nowhere — and the record that IS on the
				// site belongs to the install that replaced it, so this handler
				// must not write its own over it. If the revocation will not
				// land, the credential is a live orphan: the link is consumed
				// so retries cannot mint more beside it, and the operator is
				// told what to revoke.
				WP_Application_Passwords::delete_application_password( $creator, $minted['uuid'] );
				// "Could not read the list" is not a revocation (#434 I5):
				// managed_password_gone() is now PROVEN gone, so an unreadable
				// list takes this branch and the operator is told what to
				// revoke by hand rather than being told nothing.
				if ( ! self::managed_password_gone( $creator, $minted['uuid'] ) ) {
					delete_transient( 'aura_magic_' . $magic_id );
					$release();
					// translators: internal log line, not shown to the user.
					error_log( 'SiteAgent: an Aura Application Password minted by a superseded connect could not be revoked; revoke it by hand in Users → Profile → Application Passwords.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					return new WP_REST_Response( array( 'error' => 'A new Application Password could be neither recorded nor revoked; revoke it by hand in Users → Profile → Application Passwords.', 'code' => 'app_password_orphan_untracked' ), 500 );
				}
			}
			$release();
			return new WP_REST_Response( array( 'error' => 'This connect lost the site to another install; retry.', 'code' => 'aura_connect_lost_claim' ), 409 );
		}
		// Three mint outcomes must be retried rather than completed token-only.
		// Two leave an administrator-level credential live on the site: a
		// previous password that would not die (app_password_revoke_failed) and
		// a fresh one that could neither be recorded nor deleted
		// (app_password_orphaned, round-7). The third leaves none, but is an
		// operational failure of the site's own store rather than one of the
		// supported "this site cannot have one" cases (app_password_mint_failed,
		// round-12) — completing there would finish onboarding without the
		// credential the builder tools need and give the dashboard no way to
		// ask again. All three are retryable 500s that keep the transient.
		if ( is_wp_error( $minted ) && in_array( $minted->get_error_code(), array( 'app_password_revoke_failed', 'app_password_orphaned', 'app_password_mint_failed' ), true ) ) {
			$release(); // keep the transient — this connect is retryable
			return new WP_REST_Response(
				array(
					'error' => self::retryable_mint_message( $minted->get_error_code() ),
					'code'  => $minted->get_error_code(),
				),
				500
			);
		}
		// The lease is refreshed the moment the longest step is behind us
		// (#434 Codex round-8). The mint is where a slow host spends its
		// seconds, and a connect that overran SITE_CLAIM_TAKEOVER_AFTER while
		// legitimately working became seizable — so the tail below runs on a
		// lease measured from HERE rather than from the claim.
		// The refresh is announced, not merely done: it is the one step whose
		// absence changes nothing observable in a fast request, so without this
		// a mutant that deletes it leaves every test green.
		do_action( 'aura_worker_connect_lease_refreshed', self::touch_site_claim( $site_fence ) );
		// The mint's own bookkeeping — the LAST fallible write of the install,
		// and therefore ahead of the marker release below (round-1 NIT): the
		// bracket's discipline is that nothing that can fail sits after the
		// refusal is lifted.
		if ( is_wp_error( $minted ) ) {
			$body['app_password_unavailable'] = $minted->get_error_code();
			// Recorded, not merely reported (round-26): a site that CANNOT issue
			// an Application Password is healthy token-only, and the settings
			// screen must say so rather than ask forever for a credential this
			// site will never have. Written under the claim like every other
			// install write; the rotation ignores it (password_record() answers
			// null for it), so nothing is ever revoked by it.
			$pending = get_option( self::APP_PASSWORD_RECORD_OPTION, null );
			$carried = array( 'unavailable' => $minted->get_error_code() );
			if ( is_array( $pending ) && isset( $pending['intents'] ) && is_array( $pending['intents'] ) && array() !== $pending['intents'] ) {
				// Another handler's pending mint travels with it (round-38):
				// replacing the whole record would drop the app_id of a password
				// that handler may still create.
				$carried['intents'] = $pending['intents'];
			}
			Aura_Worker_Rules::write_option_if_claimed(
				self::APP_PASSWORD_RECORD_OPTION,
				$carried,
				$site_claim_key,
				$site_fence,
				'no'
			);
		} else {
			// The uuid is the site's own bookkeeping — never part of the response.
			$body['app_password'] = array( 'user_login' => $minted['user_login'], 'password' => $minted['password'] );
		}
		// THE WAY BACK, second half (#434 Task 7): the LAST fallible step of a
		// rebind that got all the way through. The token is installed and read
		// back, the previous credential revoked, the binding written, the
		// dashboard URL and gateway key stored and verified, and the
		// Application Password settled (minted, or this site proven unable to
		// have one) — only now may the refusal be lifted. EVERY error exit
		// above returns without reaching this line, which is the point: a
		// half-established replacement binding leaves the marker in place, so
		// the old token AND the half-installed new one go on being refused
		// everywhere.
		//
		// Placed BEFORE the magic transient is consumed, deliberately (a
		// deviation from the brief, which put it after): a failure here is a
		// store failure, and the connect must stay RETRYABLE — the retry's own
		// finish_before_rebind() finds nothing owed, revokes the password this
		// attempt minted and installs a fresh binding. Consuming the link first
		// would leave a live recorded administrator credential that no further
		// attempt could reach.
		//
		// Scoped to a MARKED site, and symmetric with ajax_regenerate_token()
		// (round-1 MINOR-2): "a proven rebind" has to mean the same thing in
		// both flows. `aura_worker_connect_user_id` is the half of the binding
		// a token-only request runs on, and Phase B DELETED it a few hundred
		// lines above — so this install's own write of it is not the refresh it
		// is on an ordinary connect but the only thing naming this install's
		// administrator, and it is proven by an uncached read before the
		// refusal is lifted. A positive id, because resolve_connect_user()
		// ignores 0 and falls back to the first administrator, which is not the
		// binding this connect established. On an unmarked site nothing here
		// runs and the connect behaves exactly as it always has.
		if ( Aura_Worker_Unbind::is_set() ) {
			$creator_id = (int) ( $stored['connect_user_id'] ?? 0 );
			$recorded   = Aura_Worker_Rules::read_option_uncached( 'aura_worker_connect_user_id' );
			$recorded   = is_wp_error( $recorded ) ? 0 : (int) maybe_unserialize( $recorded );
			if ( $creator_id <= 0 || $recorded !== $creator_id ) {
				$release(); // keep the transient — this connect is retryable
				return new WP_REST_Response( array( 'error' => 'Connect not completed: the site could not record which administrator this connection runs as; retry.', 'code' => 'aura_unbind_store_failed' ), 500 );
			}
		}
		if ( ! Aura_Worker_Unbind::release_marker_after_rebind( $site_fence ) ) {
			$release(); // keep the transient — this connect is retryable
			return new WP_REST_Response( array( 'error' => 'Connect not completed: the previous disconnect record could not be cleared; retry.', 'code' => 'aura_unbind_store_failed' ), 500 );
		}
		// OWNERSHIP IS PROVEN ONCE MORE, IMMEDIATELY BEFORE SUCCESS (#434
		// Codex round-8). Every step above verifies the claim when it acts, but
		// the LAST such check still had a tail after it — and a handler evicted
		// in that tail would answer 200 carrying a token and a password the
		// replacement connect had already revoked, leaving Aura holding
		// credentials that authenticate nothing. The lease refresh above makes
		// the eviction unlikely; this makes reporting it impossible.
		do_action( 'aura_worker_connect_before_success', $site_fence );
		if ( ! self::holds_site_claim( $site_fence ) ) {
			$release(); // conditional on the fence: somebody else's claim is untouched
			// The transient is KEPT: this connect never completed, so the link
			// must stay usable for the retry that follows.
			return new WP_REST_Response(
				array(
					'error' => 'Connect not completed: another connect took this site while this one was finishing; retry.',
					'code'  => 'aura_site_taken',
				),
				409
			);
		}
		// Consumed only now (the round-23 orphan rule still holds: the claim is
		// released with it below). Nothing fallible remains: the transient
		// delete is not part of the binding — a delete that did not land leaves
		// the link alive for a retry that would simply reconnect an already
		// bound, already unmarked site.
		delete_transient( 'aura_magic_' . $magic_id );
		// Released only NOW (round-3): the site-wide claim exists to make token
		// and password one handler's; released before the mint, a paused
		// handler could resume and rotate away the password the winner just
		// returned.
		$release();

		return new WP_REST_Response( $body, 200 );
	}

	/**
	 * The operator-facing message for a mint outcome that must be retried.
	 * One place, so the three codes and their wording cannot drift apart.
	 *
	 * @param string $code The WP_Error code mint_app_password() returned.
	 * @return string
	 */
	private static function retryable_mint_message( $code ) {
		if ( 'app_password_orphaned' === $code ) {
			return 'A new Application Password could not be recorded or revoked; retry.';
		}
		if ( 'app_password_mint_failed' === $code ) {
			return 'An Application Password could not be created on this site; retry.';
		}
		return 'A previous Application Password could not be revoked; retry.';
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
	 * The site claim originally had NO timed takeover at all (round-7, owner
	 * decision): a connect handler that a client timeout abandoned is not a
	 * handler that stopped, and an age-based takeover would let a replacement
	 * start writing while the original may still resume. That reasoning
	 * assumed only rare, operator-initiated lifecycle operations held this
	 * claim, with recovery as an explicit operator action (deactivate/
	 * reactivate — aura_worker_deactivate() in digitizer-site-worker.php).
	 *
	 * #434 (review round 1, I2) puts a routine, gateway-driven path —
	 * Aura_Worker_Rules::accept(), arbitrarily frequent — behind the same
	 * lock. `finally` cannot catch an OOM kill or a max_execution_time fatal,
	 * so without SOME recovery a single crashed push would strand the site:
	 * every later push, and every connect, 503s until a human deactivates the
	 * plugin. SITE_CLAIM_TAKEOVER_AFTER bounds that: claim_site() may now
	 * seize a claim recorded stale enough, via the SAME conditional
	 * compare-and-swap the ruleset store uses (never a blind overwrite) — see
	 * claim_magic_link()'s $takeover_after parameter. The round-7 hazard this
	 * guards against — a paused original resuming and writing over its
	 * replacement — is closed by Aura_Worker_Rules::accept_under_claim()'s own
	 * fence re-check immediately before every write (I1): a seized original's
	 * next write meets a fence that is no longer its own and is refused, not
	 * raced. Per-magic-link claims keep the original no-timed-takeover
	 * behaviour unchanged — they still assume nothing about the frequency or
	 * duration of the operation they guard, and nothing in this task changes
	 * that reasoning for them.
	 */
	const SITE_CLAIM_TAKEOVER_AFTER = 120; // seconds; well above any push.

	/**
	 * The CURRENT Aura-minted Application Password's record: the user who owns
	 * it (the rotation revokes theirs too, whoever connects next) and its UUID
	 * — the STABLE identity the rotation deletes by (round-5), since the
	 * display name is user-chosen, not unique, and renameable.
	 *
	 * ONE option holding both halves (round-17). As two options it was two
	 * writes, and a claim released between them left a half record: the owner
	 * without its UUID, which no code can act on and which then refused every
	 * later connect until someone repaired it by hand. One row, one statement,
	 * no half state to interpret.
	 */
	const APP_PASSWORD_RECORD_OPTION = 'aura_worker_app_password';

	/**
	 * Core's user-meta key for a user's Application Passwords
	 * (`WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS`), named
	 * here so the confirming raw read does not depend on the class being
	 * loaded. #434 Task 4, I5.
	 */
	const APP_PASSWORD_USERMETA_KEY = '_application_passwords';

	/** password_state(): the password is in that user's list. */
	const STATE_PRESENT = 'present';

	/** password_state(): that user's list was read and does not carry it. */
	const STATE_GONE = 'gone';

	/** password_state(): the list could not be read — never evidence of absence. */
	const STATE_UNKNOWN = 'unknown';

	/**
	 * The bounded breadcrumb an unprovable probe leaves (#434 Task 9). Not
	 * autoloaded: written only on a failure path, read only by `/status` and
	 * by an operator looking at a tombstone that will not finish.
	 */
	const PROBE_UNPROVEN_OPTION = 'aura_worker_app_password_probe_unproven';

	/**
	 * Where that count stops. "Bounded" is the requirement, and a counter that
	 * saturates says everything the operator needs — this is happening, a lot —
	 * without the option growing by one digit per event forever.
	 */
	const PROBE_UNPROVEN_MAX = 9999;



	/**
	 * Mint the dashboard's Application Password for a user, rotating any
	 * earlier one Aura minted (2.11.0).
	 *
	 * @param int    $user_id The admin who created the magic link.
	 * @param string $fence   The caller's site-claim fence, when it holds one.
	 * @return array{user_login:string,password:string,uuid:string}|WP_Error
	 */
	public static function mint_app_password( int $user_id, $fence = '' ) {
		if ( ! class_exists( 'WP_Application_Passwords' ) || ! function_exists( 'wp_is_application_passwords_available_for_user' ) ) {
			return new WP_Error( 'app_passwords_unsupported', 'This WordPress does not support Application Passwords.' );
		}
		// The rotation itself has already run, at the moment the token was
		// replaced (round-34). What remains here is the check that nothing
		// unusable is recorded — a mint beside an orphan is the one thing this
		// must never do.
		if ( self::tracking_is_incomplete() ) {
			return new WP_Error( 'app_password_tracking_incomplete', 'This site records half an Aura Application Password, so another cannot be minted beside it; revoke it by hand in Users → Profile → Application Passwords and delete the aura_worker_app_password option.' );
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
		// A password that exists but is recorded nowhere cannot be found by
		// anything afterwards (round-29). So the INTENT is written first, and
		// verified: a request killed between the two statements below leaves a
		// record naming the user and the moment, which reconcile_mint_intent()
		// above turns back into a real record on the next attempt. An intent
		// that will not persist means no password is created at all.
		$app_id = wp_generate_uuid4();
		self::persist_mint_intent( $user_id, $app_id, $fence );
		$intent = get_option( self::APP_PASSWORD_RECORD_OPTION, null );
		$listed = ( is_array( $intent ) && isset( $intent['intents'][ $app_id ]['user_id'] ) ) ? (int) $intent['intents'][ $app_id ]['user_id'] : 0;
		if ( $listed !== $user_id ) {
			return new WP_Error( 'app_password_mint_failed', 'The site could not record that an Application Password was about to be created; none was.' );
		}
		$created = WP_Application_Passwords::create_new_application_password( $user_id, array( 'name' => self::APP_PASSWORD_NAME, 'app_id' => $app_id ) );
		if ( is_wp_error( $created ) ) {
			// Nothing was created and this request cannot create anything now,
			// so its intent is settled before the error goes back (round-39).
			// Left behind, every retry would append another app_id that no
			// password will ever match, and uninstall deliberately keeps each
			// one — an option that grows and never shrinks.
			self::settle_intent( $app_id, $fence );
			// Core refusing to write the password — a failing user-meta write,
			// a database error — is an OPERATIONAL failure, not one of the
			// token-only cases (round-12). Given its own code so the caller
			// retries instead of completing onboarding without the credential
			// the builder tools need.
			return new WP_Error( 'app_password_mint_failed', $created->get_error_message() );
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
		self::persist_password_owner( $user_id, $uuid, $fence, false, '', $app_id );
		$recorded = self::password_record();
		if ( null === $recorded || $recorded['user_id'] !== $user_id || $recorded['uuid'] !== $uuid ) {
			// The cleanup is VERIFIED, never assumed (round-7): with option and
			// user-meta writes both failing, this delete can fail too, and an
			// ignored result would hand back a token-only success while a live
			// administrator credential sits on the site with nothing recording
			// it. Same proof the rotation uses — the password is gone only when
			// it is absent from the owner's list.
			WP_Application_Passwords::delete_application_password( $user_id, $uuid );
			// PROVEN gone (#434 I5). A list that could not be read falls to the
			// "still live and untracked" recovery below, which fails retryably
			// — the safe direction: the alternative reports a connect finished
			// beside a credential nothing recorded.
			if ( self::managed_password_gone( $user_id, $uuid ) ) {
				// The record must not outlive the password it named (round-14):
				// the credential is gone, so a surviving record would make every
				// later rotation chase a password that no longer exists, and
				// deactivation report a revocation failure. Logged if even the
				// delete will not land, so the operator can find the option.
				self::settle_intent( $app_id, $fence );
				if ( self::tracking_is_incomplete() ) {
					// translators: internal log line, not shown to the user.
					error_log( 'SiteAgent: the Aura Application Password tracking options could not be cleared after the password was revoked; delete aura_worker_app_password by hand.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
				return new WP_Error( 'app_password_owner_unrecorded', 'The Application Password owner could not be recorded; the password was revoked and none was returned.' );
			}
			// Still live and untracked. Try once more to record it — tracking is
			// what a later rotation revokes by, so recovering it is worth more
			// than the failed delete — and fail RETRYABLY either way, so the
			// connect is not reported as completed beside an orphan credential.
			self::persist_password_owner( $user_id, $uuid, $fence, true, '', $app_id );
			// …and the recovery is verified too (round-11). If the pair still
			// did not persist, the NEXT attempt's rotation would find nothing
			// recorded, mint again, and every retry would add another live
			// untracked administrator credential. Two different outcomes, so
			// two different codes: the caller must not let this one be retried.
			$recovered = self::password_record();
			if ( null === $recovered || $recovered['user_id'] !== $user_id || $recovered['uuid'] !== $uuid ) {
				return new WP_Error( 'app_password_orphan_untracked', 'A new Application Password could be neither recorded nor revoked; revoke it by hand in Users → Profile → Application Passwords.' );
			}
			return new WP_Error( 'app_password_orphaned', 'A new Application Password could not be revoked after its owner record failed to persist; no connection was completed.' );
		}
		return array( 'user_login' => (string) $user->user_login, 'password' => $created[0], 'uuid' => $uuid );
	}

	/**
	 * Store the site token ONLY while this handler still holds the site claim
	 * (round-9). One implementation for every claim-conditional install write
	 * lives in Aura_Worker_Rules; this names the option and the claim.
	 *
	 * The caller verifies the result by reading the row back (it already did,
	 * for the filter-rewrite case): 0 rows affected also means "the value was
	 * already this".
	 *
	 * @param string $token_hash The hash to store.
	 * @param string $fence      This handler's claim fence.
	 */
	private static function write_token_under_claim( $token_hash, $fence ) {
		Aura_Worker_Rules::write_option_if_claimed( 'aura_worker_site_token', $token_hash, self::SITE_CLAIM, $fence );
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
	public static function holds_site_claim( $fence ) {
		global $wpdb;
		if ( '' === (string) $fence ) {
			return false;
		}
		$held = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::SITE_CLAIM ) );
		return is_string( $held ) && 0 === strpos( $held, $fence . '|' );
	}

	/**
	 * Take the site EXCLUSIVELY for a lifecycle operation (round-34), and only
	 * if it is FREE (round-38).
	 *
	 * Holding the site while revoking is what keeps a callback from minting a
	 * replacement the revocation would then strip of its record. Taking it away
	 * from a live handler is a different thing, and a harmful one: evicted
	 * between its last ownership check and its response, that handler returns
	 * the plaintext of a password this revocation deletes a moment later. So a
	 * held site is simply left alone — the caller skips its revocation, and the
	 * record survives for the next activation, connect or uninstall.
	 *
	 * @return string The caller's fence, or '' if the site could not be taken.
	 */
	public static function seize_site() {
		return self::claim_magic_link( self::SITE_CLAIM );
	}

	/**
	 * Take the site for the REPAIR path — evicting whatever holds it first.
	 *
	 * Activation is where an operator goes when a connect left the site locked,
	 * so it is the one lifecycle hook that may evict. Deactivation must not
	 * (round-38): evicting a live handler between its last ownership check and
	 * its response has it return the plaintext of a password the revocation
	 * that follows has already deleted, and the dashboard finishes onboarding
	 * with a credential WordPress no longer has.
	 *
	 * @return string The caller's fence, or '' if the site could not be taken.
	 */
	public static function repair_site_claim() {
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			self::forget_site_claim();
			$fence = self::claim_magic_link( self::SITE_CLAIM );
			if ( '' !== $fence ) {
				return $fence;
			}
		}
		return '';
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
	 * Also Aura_Worker_Rules::accept()'s entry point since #434: a routine,
	 * gateway-driven ruleset push takes this same claim for its whole
	 * decision, so it may seize a stale one too (SITE_CLAIM_TAKEOVER_AFTER,
	 * review round 1, I2) — see that constant's docblock.
	 *
	 * @return string The caller's fence when it holds the claim, else ''.
	 */
	public static function claim_site() {
		return self::claim_magic_link( self::SITE_CLAIM, self::SITE_CLAIM_TAKEOVER_AFTER );
	}

	/**
	 * REFRESH THE LEASE WHILE THE WORK IS STILL RUNNING (#434 Codex round-8).
	 *
	 * seize_stale_claim() bounds a claim stranded by a fatal to
	 * SITE_CLAIM_TAKEOVER_AFTER seconds, and its own docblock says a claim "a
	 * live request refreshing it" cannot be seized — but nothing refreshed one.
	 * A connect that legitimately ran past the window (a slow host, a mint that
	 * waited on the database, and since #434 a full Phase B cleanup before the
	 * rebind) therefore became seizable while it was still working, and a
	 * replacement connect could revoke the credentials the first one was about
	 * to return.
	 *
	 * The same conditional compare-and-swap the seizure uses, in the other
	 * direction: the UPDATE names the exact bytes just read, so a claim already
	 * seized, released or refreshed by somebody else loses and answers false.
	 * A caller that gets false has lost the site and must not report success.
	 *
	 * @since 2.13.0
	 *
	 * @param string $fence The value claim_site() returned.
	 * @return bool True while this fence still holds the site.
	 */
	public static function touch_site_claim( $fence ) {
		return self::refresh_claim( self::SITE_CLAIM, $fence );
	}

	/**
	 * Renew a claim's lease while its holder is still working: the row's
	 * timestamp moves to now, by compare-and-swap on the exact bytes held, so a
	 * long operation is never seized as stale while it is alive (#434 round 8
	 * for the connect; #78 Codex round-22 for the self-update).
	 *
	 * @param string $claim_key Option name.
	 * @param string $fence     The value take_claim() / claim_site() returned.
	 * @return bool True while this fence still holds the claim.
	 */
	public static function refresh_claim( $claim_key, $fence ) {
		global $wpdb;
		if ( '' === (string) $fence ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The bytes this swap names must be the ROW's, never the option cache's: a cached copy is exactly the stale value a seizure may already have replaced.
		$held = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $claim_key ) );
		if ( ! is_string( $held ) || 0 !== strpos( $held, $fence . '|' ) ) {
			return false;
		}
		$fresh = $fence . '|' . time();
		if ( $held === $fresh ) {
			return true; // same second: the lease is already as fresh as it gets
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A compare-and-swap has no storage-function equivalent; the cache is evicted immediately below, as every other writer of this row does.
		$rows = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $fresh, $claim_key, $held ) );
		wp_cache_delete( $claim_key, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		return 1 === (int) $rows && '' === (string) $wpdb->last_error;
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
	 * Does the site record HALF an Aura Application Password? That is what a
	 * mint whose tracking writes only partly landed leaves behind, and it names
	 * a password nothing here can delete. Minting beside it would add a second
	 * live administrator credential, so it is refused until an operator clears
	 * it (round-13).
	 *
	 * @return bool
	 */
	private static function tracking_is_incomplete(): bool {
		$stored = get_option( self::APP_PASSWORD_RECORD_OPTION, null );
		if ( null === $stored || false === $stored || '' === $stored ) {
			return false; // nothing recorded
		}
		if ( is_array( $stored ) && ! isset( $stored['uuid'] ) && isset( $stored['intents'] ) ) {
			return false; // pending intents only — reconciled, not refused
		}
		if ( is_array( $stored ) && ! empty( $stored['unavailable'] ) ) {
			return false; // a token-only site, not an orphan
		}
		return null === self::password_record();
	}

	/**
	 * What credential the site holds for the dashboard's builder tools — the
	 * one question the settings screen has to answer, read from the one option
	 * that records it (round-26).
	 *
	 * 'unbound'     Aura disconnected this site; every mutation is refused
	 * 'delivered'   a password exists and the connect that minted it returned it
	 * 'undelivered' a password exists but no connect ever handed it over
	 * 'unavailable' this site cannot issue one; the connection is token-only
	 * 'none'        nothing recorded — revoked, or never issued
	 *
	 * @return string
	 */
	private static function credential_state(): string {
		// The marker outranks every credential question below it (#434 Task 9).
		// A disconnected site refuses every mutation at BOTH write boundaries,
		// so whatever credential it still holds buys nothing, and painting the
		// screen "Connected to Aura dashboard" over it — green check included —
		// describes a connection that no longer does anything.
		//
		// is_set(), deliberately, not the tri-state: an unreadable marker
		// refuses exactly as a present one does (it is the same boolean
		// Aura_Worker_Security::refuse_if_unbound() gates on), so the screen
		// must say the same thing about both. What must NOT be derived from
		// this boolean is the disconnect TIME — render_unbound_panel() reads
		// the tri-state itself for that, because a moment is a value and this
		// answer carries none.
		if ( Aura_Worker_Unbind::is_set() ) {
			return 'unbound';
		}
		$rec = get_option( self::APP_PASSWORD_RECORD_OPTION, null );
		if ( is_array( $rec ) && ! empty( $rec['unavailable'] ) ) {
			return 'unavailable';
		}
		$usable = self::password_record();
		if ( null === $usable ) {
			return 'none';
		}
		// The record is bookkeeping; WordPress holds the credential. An
		// administrator revoking it under Users → Profile, or the owning user
		// being deleted, changes nothing here — so the list is consulted before
		// this screen calls the connection healthy (round-27). One read, on an
		// admin page render.
		// DELIBERATELY the only caller that does not fail closed on 'unknown'
		// (#434 I5). This is a screen render, not a gate: an unreadable list is
		// reported exactly as it was before the tri-state existed — 'none',
		// whose advice ("connect again to issue one") is harmless and
		// self-correcting on the next page load. Reporting it as healthy
		// instead would be the worse lie. Nothing irreversible reads this.
		if ( ! class_exists( 'WP_Application_Passwords' ) || self::STATE_PRESENT !== self::managed_password_state( $usable['user_id'], $usable['uuid'] ) ) {
			return 'none';
		}
		// A password WordPress will no longer accept is not a working
		// credential (round-29): Application Passwords can be switched off for
		// a user after the fact — a security plugin's filter, HTTPS lost — and
		// the recorded UUID goes on existing while every Basic-auth call fails.
		$owner = function_exists( 'get_userdata' ) ? get_userdata( $usable['user_id'] ) : false;
		if ( ! $owner || ! function_exists( 'wp_is_application_passwords_available_for_user' ) || ! wp_is_application_passwords_available_for_user( $owner ) ) {
			return 'unavailable';
		}
		return empty( $usable['undelivered'] ) ? 'delivered' : 'undelivered';
	}

	/**
	 * The stored owner/UUID record, or null when nothing usable is recorded.
	 *
	 * @return array{user_id:int,uuid:string}|null
	 */
	private static function password_record() {
		$rec_raw = get_option( self::APP_PASSWORD_RECORD_OPTION, null );
		$rec     = $rec_raw;
		if ( ! is_array( $rec ) ) {
			return null;
		}
		if ( ! empty( $rec['unavailable'] ) ) {
			return null; // a statement about the SITE, not a password to revoke
		}
		$user_id = (int) ( $rec['user_id'] ?? 0 );
		$uuid    = (string) ( $rec['uuid'] ?? '' );
		if ( $user_id <= 0 || '' === $uuid ) {
			return null;
		}
		$rec = array( 'user_id' => $user_id, 'uuid' => $uuid );
		if ( ! empty( $rec_raw['undelivered'] ) ) {
			$rec['undelivered'] = true;
		}
		return $rec;
	}

	/**
	 * Record that an Application Password is ABOUT to be created for a user.
	 *
	 * Written before create_new_application_password() and verified, so the
	 * window in which a password can exist unrecorded is closed: a request
	 * killed inside it leaves this intent behind, and the next attempt adopts
	 * whatever it created (round-29).
	 *
	 * @param int    $user_id The admin the password is being minted for.
	 * @param string $app_id  The identifier creation will stamp on it — the
	 *                        EXACT credential this intent is about (round-30).
	 * @param string $fence   The caller's site-claim fence, when it holds one.
	 * @return int|false Rows written, as write_option_if_claimed() reports them.
	 */
	private static function persist_mint_intent( int $user_id, $app_id, $fence = '' ) {
		// APPENDED, never overwriting (round-35). Two handlers can be inside a
		// mint at once — one paused with its claim released, another holding the
		// site — and a record that holds only the latest intent forgets the
		// app_id of the password the first may still create, leaving it live and
		// unfindable. The record therefore carries a SET of pending intents
		// beside whatever credential it describes.
		$rec     = get_option( self::APP_PASSWORD_RECORD_OPTION, null );
		$record  = is_array( $rec ) ? $rec : array();
		$intents = isset( $record['intents'] ) && is_array( $record['intents'] ) ? $record['intents'] : array();
		$intents[ (string) $app_id ] = array( 'user_id' => $user_id, 'at' => time() );
		$record['intents'] = $intents;
		if ( '' !== (string) $fence ) {
			return Aura_Worker_Rules::write_option_if_claimed( self::APP_PASSWORD_RECORD_OPTION, $record, self::SITE_CLAIM, $fence, 'no' );
		}
		update_option( self::APP_PASSWORD_RECORD_OPTION, $record, false );
		wp_cache_delete( self::APP_PASSWORD_RECORD_OPTION, 'options' );
		return 1;
	}

	/**
	 * Turn a mint intent left by an interrupted attempt back into a real
	 * record — or clear it when nothing came of it (round-29).
	 *
	 * The evidence is narrow on purpose: a password of the intent's OWNER,
	 * carrying the fixed Aura name, created no earlier than the intent itself.
	 * Adopted rather than deleted — the ordinary rotation revokes it a moment
	 * later, and adopting a password that turned out to be someone else's
	 * (they would have had to create an identically named one for the same
	 * user inside the same second) costs them a credential they can re-create,
	 * where deleting by name outright was rejected in round 5 for good reason.
	 *
	 * @param string $fence The caller's site-claim fence, when it holds one.
	 * @return bool False when a credential was found but could not be recorded
	 *              — the caller must not go on to mint another beside it
	 *              (round-32). True when there is nothing to reconcile, or the
	 *              recovery is durably recorded.
	 */
	private static function reconcile_mint_intent( $fence = '' ) {
		$rec = get_option( self::APP_PASSWORD_RECORD_OPTION, null );
		if ( ! is_array( $rec ) || empty( $rec['intents'] ) || ! is_array( $rec['intents'] ) ) {
			return true;
		}
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return true;
		}
		$settled = array();
		foreach ( $rec['intents'] as $app_id => $intent ) {
			$owner = (int) ( $intent['user_id'] ?? 0 );
			$found = '';
			if ( $owner > 0 && '' !== (string) $app_id ) {
				foreach ( WP_Application_Passwords::get_user_application_passwords( $owner ) as $item ) {
					// By the app_id creation was told to stamp on it (round-30)
					// — the EXACT credential this intent is about. Matching on
					// the name and a timestamp adopted whichever same-named
					// password of that user came first, so a second one created
					// in the same second put the rotation onto an unrelated
					// credential while the real orphan stayed live.
					if ( '' !== (string) ( $item['app_id'] ?? '' ) && (string) $app_id === (string) $item['app_id'] && ! empty( $item['uuid'] ) ) {
						$found = (string) $item['uuid'];
						break;
					}
				}
			}
			if ( '' === $found ) {
				// Nothing was created under this intent — YET. It stays: any
				// rule for retiring it rests on knowing the request that wrote
				// it can no longer resume, and PHP offers no such proof
				// (round-31). It names no credential, so nothing depends on its
				// absence.
				continue;
			}
			// A password nobody ever received. It is revoked here rather than
			// adopted: the caller is about to mint a fresh one, and an
			// undelivered credential is worth nothing to anybody.
			WP_Application_Passwords::delete_application_password( $owner, $found );
			if ( ! self::managed_password_gone( $owner, $found ) ) {
				// Includes "the list could not be read" (#434 I5): the caller
				// must not mint a second administrator credential beside one
				// this request could not prove is gone.
				return false; // still live, or not provably gone
			}
			$settled[] = (string) $app_id;
		}
		if ( empty( $settled ) ) {
			return true;
		}
		// Drop only what was settled, and VERIFY: read back with an intent still
		// listed, this would let the caller mint beside a credential whose
		// description it had just failed to update (round-32).
		foreach ( $settled as $app_id ) {
			unset( $rec['intents'][ $app_id ] );
		}
		self::write_password_record( $rec, $fence );
		$after = get_option( self::APP_PASSWORD_RECORD_OPTION, null );
		$left  = ( is_array( $after ) && isset( $after['intents'] ) && is_array( $after['intents'] ) ) ? $after['intents'] : array();
		foreach ( $settled as $app_id ) {
			if ( isset( $left[ $app_id ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Clear the ruleset store under the claim and prove the row is gone.
	 * ONE implementation for both compatibility paths that clear it (round-35):
	 * a conditional delete that failed leaves the previous client's block and
	 * warn policy governing the newly connected dashboard behind a 200.
	 *
	 * Public since 2.13.0 for #434's Phase B, which clears the same store under
	 * the same claim and needs the same proof that the row is gone —
	 * Aura_Worker_Rules::clear() on its own reports nothing.
	 *
	 * @param string $claim The claim option's name.
	 * @param string $fence This handler's fence.
	 * @return bool True when the store is empty.
	 */
	public static function clear_ruleset_verified( $claim, $fence ): bool {
		Aura_Worker_Rules::clear( $claim, $fence );
		$left = Aura_Worker_Rules::read_option_uncached( Aura_Worker_Rules::OPTION );
		return ! is_wp_error( $left ) && ( null === $left || '' === (string) $left );
	}

	/**
	 * Drop ONE pending intent, leaving every other handler's alone (round-35)
	 * and clearing the credential fields with it. Used where a mint's own
	 * password has been revoked again: nothing of that attempt should remain,
	 * and nothing of anyone else's should go with it.
	 *
	 * @param string $app_id The intent this attempt owns.
	 * @param string $fence  The caller's site-claim fence, when it holds one.
	 */
	private static function settle_intent( $app_id, $fence = '' ) {
		$prev    = get_option( self::APP_PASSWORD_RECORD_OPTION, null );
		$intents = ( is_array( $prev ) && isset( $prev['intents'] ) && is_array( $prev['intents'] ) ) ? $prev['intents'] : array();
		unset( $intents[ (string) $app_id ] );
		self::write_password_record( array() === $intents ? array() : array( 'intents' => $intents ), $fence );
	}

	/**
	 * Write the whole record — ONE implementation for the paths that carry
	 * pending intents alongside the credential.
	 *
	 * @param array  $record The record to store.
	 * @param string $fence  The caller's site-claim fence, when it holds one.
	 * @return int|false Rows written, as write_option_if_claimed() reports them.
	 */
	private static function write_password_record( array $record, $fence = '' ) {
		if ( array() === $record || ( 1 === count( $record ) && isset( $record['intents'] ) && array() === $record['intents'] ) ) {
			return self::forget_password_owner( $fence );
		}
		if ( '' !== (string) $fence ) {
			return Aura_Worker_Rules::write_option_if_claimed( self::APP_PASSWORD_RECORD_OPTION, $record, self::SITE_CLAIM, $fence, 'no' );
		}
		update_option( self::APP_PASSWORD_RECORD_OPTION, $record, false );
		wp_cache_delete( self::APP_PASSWORD_RECORD_OPTION, 'options' );
		return 1;
	}

	/**
	 * Forget the owner/UUID pair — ONE implementation, used wherever the
	 * password it named is provably gone. Under a connect's site claim the
	 * deletes are conditional on it, like every other install write.
	 *
	 * @param string $fence The caller's site-claim fence, when it holds one.
	 */
	private static function forget_password_owner( $fence = '' ) {
		if ( '' !== (string) $fence ) {
			return Aura_Worker_Rules::delete_option_if_claimed( self::APP_PASSWORD_RECORD_OPTION, self::SITE_CLAIM, $fence );
		}
		delete_option( self::APP_PASSWORD_RECORD_OPTION );
		wp_cache_delete( self::APP_PASSWORD_RECORD_OPTION, 'options' );
		return 1;
	}

	/**
	 * Write the owner/UUID pair a later rotation revokes by, evicting the
	 * option cache so the verifying read that follows sees the database.
	 * ONE implementation — the mint's first attempt and its recovery attempt
	 * must not drift apart.
	 *
	 * Under a connect's site claim the pair is written conditionally on it
	 * (round-12), like every other install write: a handler that lost the claim
	 * would otherwise overwrite the winning install's owner/UUID with its own,
	 * then delete its own password — leaving the winner's administrator
	 * credential live and the site's record of it pointing at a password that
	 * no longer exists.
	 *
	 * @param int    $user_id Owner of the password.
	 * @param string $uuid    Its UUID.
	 * @param string $fence   The caller's site-claim fence, when it holds one.
	 */
	private static function persist_password_owner( int $user_id, string $uuid, $fence = '', $undelivered = false, $revoking = '', $fulfilled = '' ) {
		$record = array( 'user_id' => $user_id, 'uuid' => $uuid );
		// Whatever else is still pending travels with it (round-35), minus the
		// intent this write fulfils.
		$prev = get_option( self::APP_PASSWORD_RECORD_OPTION, null );
		if ( is_array( $prev ) && isset( $prev['intents'] ) && is_array( $prev['intents'] ) ) {
			$intents = $prev['intents'];
			unset( $intents[ (string) $fulfilled ] );
			if ( array() !== $intents ) {
				$record['intents'] = $intents;
			}
		}
		if ( '' !== (string) $revoking ) {
			// A revocation of THIS record is under way, by the holder of this
			// fence. The value differs from every other writer's, so the UPDATE
			// that sets it changes exactly one row for the claim holder and none
			// for anyone else — the ownership proof, without destroying the
			// record while the password it names is still live.
			$record['revoking'] = (string) $revoking;
		}
		if ( $undelivered ) {
			// The password this record names was minted but never returned to
			// the dashboard (round-23). It exists, so the rotation must find
			// and revoke it — but the connection it was minted for did not
			// complete, and the settings screen must not read it as proof that
			// one did.
			$record['undelivered'] = true;
		}
		if ( '' !== (string) $fence ) {
			return Aura_Worker_Rules::write_option_if_claimed( self::APP_PASSWORD_RECORD_OPTION, $record, self::SITE_CLAIM, $fence, 'no' );
		}
		update_option( self::APP_PASSWORD_RECORD_OPTION, $record, false );
		wp_cache_delete( self::APP_PASSWORD_RECORD_OPTION, 'options' );
		return 1;
	}

	/**
	 * Is that Application Password gone from that user's list? The public form
	 * of managed_password_gone(), for the callers outside this class that need
	 * the same proof: #434's Phase B revokes every credential the unbind
	 * marker names — the managed one AND any password that authenticated an
	 * unbind — and, like every step of that cleanup, may report a step
	 * complete only on evidence, never on a delete's return value; and Phase A
	 * uses it to CONFIRM a candidate owner before recording it, so that Phase
	 * B's single lookup is authoritative.
	 *
	 * Answers only about the user it is ASKED about. It is not, and cannot be
	 * made into, evidence that nobody else holds the password — three review
	 * rounds of #434 Task 4 turned on exactly that mistake.
	 *
	 * PROVEN gone, and only that (#434 Task 4, I5): a list that could not be
	 * read answers FALSE here, not true. See password_state() for the third
	 * answer and for why it exists.
	 *
	 * @since 2.13.0
	 *
	 * @param int    $user Owner user ID.
	 * @param string $uuid Password UUID.
	 * @return bool True only when that user's list was read and does not carry it.
	 */
	public static function password_gone( int $user, string $uuid ): bool {
		return self::STATE_GONE === self::password_state( $user, $uuid );
	}

	/**
	 * The tri-state behind password_gone(): 'present', 'gone' or 'unknown'.
	 *
	 * "Could not determine" is a THIRD answer, not a quiet 'gone' (#434 Task 4,
	 * I5). Callers that must fail closed read `STATE_GONE !==`; the one caller
	 * that must fail closed the OTHER way — Phase A confirming a candidate
	 * owner — reads `STATE_PRESENT ===`. Nobody has to remember which way a
	 * boolean leans.
	 *
	 * A WordPress with no Application Passwords class holds no such password
	 * and can authenticate nobody with one, so there is nothing left to
	 * revoke: 'gone', exactly as usable_password() already treats it.
	 *
	 * @since 2.13.0
	 *
	 * @param int    $user Owner user ID.
	 * @param string $uuid Password UUID.
	 * @return string One of STATE_PRESENT, STATE_GONE, STATE_UNKNOWN.
	 */
	public static function password_state( int $user, string $uuid ): string {
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return self::STATE_GONE;
		}
		return self::managed_password_state( $user, $uuid );
	}

	/**
	 * Is the password identified by $uuid really gone from $owner's list?
	 * delete_application_password() answers false for a failed user-meta
	 * write as well as for "not there", so its return value alone never
	 * proves a revocation landed — the owner's list does. ONE implementation,
	 * used by the rotation and by the mint's cleanup.
	 *
	 * PROVEN gone: an unreadable list is 'unknown', which is not true here
	 * (#434 Task 4, I5). Every caller of this form fails closed on that.
	 *
	 * @param int    $owner Owner user ID.
	 * @param string $uuid  Password UUID.
	 * @return bool True only when the list was read and does not carry it.
	 */
	private static function managed_password_gone( int $owner, string $uuid ): bool {
		return self::STATE_GONE === self::managed_password_state( $owner, $uuid );
	}

	/**
	 * Is that password present in that user's list, absent from it, or is the
	 * list not readable at all?
	 *
	 * The third answer is the point (#434 Task 4, I5). Core implements
	 * `WP_Application_Passwords::get_user_application_passwords()` as a
	 * `get_user_meta( …, true )` followed by `if ( ! is_array( $passwords ) )
	 * return array();` — so a meta read that could not be completed (a failed
	 * `update_meta_cache()` query caches an empty array and `get_metadata()`
	 * then answers `''`) is INDISTINGUISHABLE at that layer from "this user
	 * holds no Application Passwords". Every caller here was reading that as
	 * proven absence, and in Phase B of the unbind it was the sole evidence
	 * gating an irreversible step: the site token deleted beside a live
	 * `manage_options` credential.
	 *
	 * So absence is confirmed by a raw read that PROVES IT RAN: the statement
	 * carries a per-call nonce and echoes it back, so a row that does not
	 * return our nonce is somebody else's and settles nothing. (It does not
	 * consult `$wpdb->ready`, `last_query` or `last_error` — those are a
	 * database drop-in's to get wrong, and reading them that way stranded
	 * Phase B on every drop-in that never sets `ready`. The proof is a
	 * property of the ANSWER, not of wpdb's bookkeeping.) Core's own list
	 * still answers PRESENT, so any filter or
	 * alternative meta store keeps its say over the positive; the raw probe
	 * runs only on the path about to conclude absence, and can only turn that
	 * conclusion into 'present' or 'unknown' — never the other way.
	 *
	 * @since 2.13.0
	 *
	 * @param int    $owner Owner user ID.
	 * @param string $uuid  Password UUID.
	 * @return string One of STATE_PRESENT, STATE_GONE, STATE_UNKNOWN.
	 */
	private static function managed_password_state( int $owner, string $uuid ): string {
		// ONE implementation, in includes/credential-rules.php — uninstall.php
		// asks this same question and must not load the plugin to do it
		// (#434 Codex round-10). The two-stage reasoning above lives there.
		return aura_worker_app_password_state( $owner, $uuid );
	}


	/**
	 * That user's Application Passwords, from the ROW, proven to have been
	 * read (#434 Task 4's discipline, extracted in Task 9 so the repair path
	 * can read the whole list rather than ask about one uuid).
	 *
	 * The statement, the nonce and every reason for both are unchanged — this
	 * is the same read aura_worker_app_password_list() has always issued, with the
	 * uuid question moved out of it. Its answers are: the list (empty for a
	 * user with no row, or a row that does not hold an array — core reads both
	 * as "no passwords"), or NULL for a read that proved nothing, which no
	 * caller may treat as an absence.
	 *
	 * @since 2.13.0
	 *
	 * @param int $owner Owner user ID.
	 * @return array|null The list, or null when nothing was proved.
	 */
	private static function app_password_list( int $owner ): ?array {
		// ONE implementation, in includes/credential-rules.php, because
		// uninstall.php has to ask this same question and must not load the
		// plugin to do it (#434 Codex round-10). The statement, the nonce and
		// every reason for both live there.
		return aura_worker_app_password_list( $owner );
	}

	/**
	 * Which users' Application Password lists carry that UUID — asked of the
	 * WHOLE usermeta table, in ONE statement, and answered only when the
	 * statement can prove it ran (#434 Task 9).
	 *
	 * THIS IS NOT THE OWNER SEARCH TASK 4 DELETED, and the difference is the
	 * whole reason it may exist. That one walked a bounded CANDIDATE LIST — the
	 * connecting user, then up to 200 administrators — and read "in none of
	 * them" as absence, which stops being evidence the moment a 201st user, or
	 * a non-administrator holder, exists. This asks the TABLE: every row that
	 * holds an Application Password list is examined by the same statement, so
	 * an empty answer is a fact about the site rather than about a list
	 * somebody guessed.
	 *
	 * It is also on no automatic path. Phase B still does exactly one lookup,
	 * against the owner Phase A recorded, on every sweep forever; this runs
	 * only from the operator's explicit "Remove remaining Aura data", which is
	 * the one place a full scan's cost is bought deliberately — and `meta_key`
	 * is indexed, so the scan covers the users who have Application Passwords
	 * at all, not every row of usermeta.
	 *
	 * The `probe` nonce is the in-band proof aura_worker_app_password_list() uses, for
	 * the identical reason: wpdb::get_row() extracts from the PREVIOUS
	 * statement's result set when this one never ran, so only a value this call
	 * invented can tell "nobody holds it" from "nothing was asked".
	 *
	 * The LIKE over the serialized list is deliberately loose. It can name a
	 * user who does not really hold the uuid — some other Application Password
	 * list containing the same characters — and it cannot MISS one, because
	 * core serialises the uuid literally. A false positive costs one delete
	 * that removes nothing plus the confirming statement; a false negative is
	 * the thing that must never happen here.
	 *
	 * @since 2.13.0
	 *
	 * @param string $uuid The password's uuid.
	 * @return int[]|null The users whose list may hold it, EMPTY when the
	 *                    statement proved that no list on this site does, and
	 *                    null when nothing was proved at all.
	 */
	public static function password_holders( string $uuid ) {
		return self::usermeta_holders( $uuid );
	}

	/**
	 * The same statement, asked about the Application Password NAME this
	 * plugin mints under (#434 Task 9's repair path). Kept private: a name is
	 * user-controllable and therefore evidence about a CANDIDATE, never about
	 * a specific credential — only the repair, which tells the operator
	 * exactly that in so many words, may act on it.
	 *
	 * @since 2.13.0
	 *
	 * @return int[]|null Users whose list may carry an Aura-named password,
	 *                    empty when the statement proved none does, null when
	 *                    nothing was proved.
	 */
	private static function password_name_holders() {
		return self::usermeta_holders( self::APP_PASSWORD_NAME );
	}

	/**
	 * ONE statement over every Application Password list on the site, looking
	 * for a literal string. See password_holders() above for why this may
	 * exist at all and what its answers mean.
	 *
	 * @param string $needle The literal to look for in the serialized list.
	 * @return int[]|null
	 */
	private static function usermeta_holders( string $needle ) {
		global $wpdb;
		if ( '' === $needle || ! is_object( $wpdb ) || ! isset( $wpdb->usermeta ) ) {
			return null; // no way to ask: never a proof of absence
		}
		static $seq = 0;
		++$seq;
		$nonce = $seq . '-' . wp_generate_uuid4();
		// The owners come back as ROWS, never as an aggregate of them.
		//
		// This statement used to read GROUP_CONCAT(user_id). That is a BOUNDED
		// aggregate: MySQL stops concatenating at `group_concat_max_len`
		// (1024 bytes by default — about 170 ids) and reports the cut only in
		// a warning nothing here reads. A site with enough Application
		// Password holders would hand this code a list that LOOKS well formed
		// and is missing owners, and the teardown would report every
		// credential accounted for while the omitted ones stayed usable. A
		// list cannot testify that it is complete, and counting the same rows
		// to check it only narrows the window — a cut landing mid-number
		// yields the right number of ids, one of them wrong. So the bound is
		// gone rather than measured.
		//
		// One row per owner needs no bound; the leading UNION member is a
		// SENTINEL that guarantees the statement answers with at least one
		// row even when nobody holds the needle, which is what lets an empty
		// result stay readable as "the statement did not run" rather than as
		// an absence. Every row carries the nonce, so provenance is proved for
		// the whole answer and not just its head — wpdb::get_results() has the
		// same stale-`last_result` seam the per-user probe was hardened
		// against.
		$sql = $wpdb->prepare(
			"SELECT %s AS probe, 0 AS user_id UNION ALL SELECT %s AS probe, user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s",
			$nonce,
			$nonce,
			self::APP_PASSWORD_USERMETA_KEY,
			'%' . $wpdb->esc_like( $needle ) . '%'
		);
		if ( ! is_string( $sql ) || '' === $sql ) {
			return null; // nothing was issued, so nothing was proved
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql );
		if ( ! is_array( $rows ) ) {
			do_action( 'aura_worker_app_password_probe_unproven', 0 );
			return null;
		}
		$users    = array();
		$sentinel = false;
		foreach ( $rows as $row ) {
			if ( ! isset( $row->probe ) || $nonce !== (string) $row->probe ) {
				// Somebody else's result set, in whole or in part.
				do_action( 'aura_worker_app_password_probe_unproven', 0 );
				return null;
			}
			$id = isset( $row->user_id ) ? (int) $row->user_id : 0;
			if ( $id > 0 ) {
				$users[] = $id;
				continue;
			}
			// The sentinel. WordPress never issues user id 0, so orphaned
			// usermeta owning nobody is indistinguishable from it here — and
			// owns no credential either way.
			$sentinel = true;
		}
		if ( ! $sentinel ) {
			// The one row this statement cannot fail to return did not come
			// back, so what did come back is not this statement's answer. It is
			// also how a statement that FAILED is caught — it answers with no
			// rows at all, and no rows is no sentinel. Kept as the single test
			// rather than as a special case beside an is-empty check, so the
			// guard that decides this is one the suite can actually redden.
			// Same breadcrumb the per-user probe leaves, with no owner to name.
			do_action( 'aura_worker_app_password_probe_unproven', 0 );
			return null;
		}
		// Proven, and complete: EMPTY means no Application Password list on
		// this site carries the needle.
		return array_values( array_unique( $users ) );
	}

	/**
	 * EVERY Application Password on this site that a repaired marker must name
	 * (#434 Task 9's repair path) — uuid => owner.
	 *
	 * A marker whose row is malformed names nothing this site can act on, so
	 * the repair has to rebuild the credential list from the site itself. Two
	 * sources, both of them evidence rather than inference:
	 *
	 *  - the NAME sweep. mint_app_password() is the only caller of
	 *    create_new_application_password() in this plugin and it always stamps
	 *    APP_PASSWORD_NAME, so every credential SiteAgent has ever minted
	 *    carries that name: the sweep is a SUPERSET of them. It is NOT a
	 *    subset — the name is user-controllable, so a password an operator
	 *    happened to give the same name is swept too, and the teardown says so
	 *    to the operator in as many words before anything is removed.
	 *  - the plugin's own record (`aura_worker_app_password`), which names the
	 *    managed credential and its owner outright. Added even when the sweep
	 *    did not return it: a password whose name was changed afterwards is
	 *    still this binding's, and a uuid already gone costs one delete that
	 *    matches nothing.
	 *
	 * FAILS CLOSED, everywhere. A sweep that proved nothing, or a candidate
	 * whose list could not be read, answers null — and the repair refuses
	 * rather than write a marker that names fewer credentials than the site
	 * holds, which would hand the teardown a list it could complete while a
	 * live administrator credential stayed behind.
	 *
	 * @since 2.13.0
	 *
	 * @return array<string,int>|null uuid => owner, or null when nothing was
	 *                                proved.
	 */
	public static function minted_passwords(): ?array {
		$holders = self::password_name_holders();
		if ( null === $holders ) {
			return null;
		}
		$record = self::password_record();
		if ( null !== $record ) {
			$holders[] = (int) $record['user_id'];
		}
		$found = array();
		foreach ( array_unique( $holders ) as $owner ) {
			$owner = (int) $owner;
			if ( $owner <= 0 ) {
				continue;
			}
			$list = self::app_password_list( $owner );
			if ( null === $list ) {
				return null; // a list that could not be read is not an empty one
			}
			foreach ( $list as $item ) {
				if ( is_array( $item ) && ! empty( $item['uuid'] ) && isset( $item['name'] )
					&& self::APP_PASSWORD_NAME === (string) $item['name'] ) {
					$found[ (string) $item['uuid'] ] = $owner;
				}
			}
		}
		if ( null !== $record && ! isset( $found[ $record['uuid'] ] ) ) {
			$found[ (string) $record['uuid'] ] = (int) $record['user_id'];
		}
		return $found;
	}

	/**
	 * Record that a probe could not prove itself (#434 Task 9).
	 *
	 * Task 6 left `aura_worker_app_password_probe_unproven` firing into
	 * nothing: an unprovable probe owes `app_passwords` forever, so a tombstone
	 * that never completes had no explanation anywhere — not in the site, not
	 * on the wire. This is the listener, and get_status() carries it to Aura.
	 *
	 * BOUNDED, because the alternative writes an ever-growing option on a
	 * failure path: three scalars, one row, a count that stops climbing at
	 * PROBE_UNPROVEN_MAX. No list of occurrences, no per-user history — the
	 * question this answers is "is this happening, and when did it last
	 * happen", and a saturating counter answers it at fixed size.
	 *
	 * @param int $owner The user whose list could not be read, or 0 when the
	 *                   probe was site-wide and names nobody.
	 * @return void
	 */
	public static function record_probe_unproven( $owner = 0 ): void {
		$stored = get_option( self::PROBE_UNPROVEN_OPTION, null );
		$count  = ( is_array( $stored ) && isset( $stored['count'] ) && is_int( $stored['count'] ) && $stored['count'] > 0 )
			? (int) $stored['count']
			: 0;
		if ( $count < self::PROBE_UNPROVEN_MAX ) {
			++$count;
		}
		update_option(
			self::PROBE_UNPROVEN_OPTION,
			array(
				'count' => $count,
				'at'    => gmdate( 'c' ),
				'owner' => (int) $owner > 0 ? (int) $owner : 0,
			),
			false
		);
	}

	/**
	 * What `/status` reports about unprovable probes, or null when none has
	 * ever been recorded (#434 Task 9).
	 *
	 * @return array{count:int,at:string,owner:int}|null
	 */
	public static function probe_unproven_report(): ?array {
		$stored = get_option( self::PROBE_UNPROVEN_OPTION, null );
		if ( ! is_array( $stored ) || ! isset( $stored['count'] ) || (int) $stored['count'] <= 0 ) {
			return null;
		}
		return array(
			'count' => (int) $stored['count'],
			'at'    => isset( $stored['at'] ) ? (string) $stored['at'] : '',
			'owner' => isset( $stored['owner'] ) ? (int) $stored['owner'] : 0,
		);
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
	 * @param string $fence The caller's site-claim fence, when it holds one —
	 *                      the record is then forgotten conditionally on it
	 *                      (round-15), so a handler that lost the claim cannot
	 *                      delete the WINNING install's owner/UUID and leave
	 *                      its administrator credential untracked.
	 * @return bool True when nothing dangerous remains.
	 */
	public static function revoke_managed_password( $fence = '' ): bool {
		if ( ! self::reconcile_mint_intent( $fence ) ) {
			return false; // a credential was found and could not be recorded
		}
		$record = get_option( self::APP_PASSWORD_RECORD_OPTION, null );
		if ( null === $record || false === $record || '' === $record ) {
			return true; // nothing recorded — first mint, or already cleared
		}
		if ( is_array( $record ) && ! empty( $record['unavailable'] ) ) {
			// A statement that this site cannot have one. Nothing to revoke, and
			// the next connect overwrites it.
			return true;
		}
		$rec = self::password_record();
		if ( null === $rec && is_array( $record ) && isset( $record['intents'] ) ) {
			// Pending intents that reconciliation found nothing for. They name no
			// credential, so there is nothing to revoke; they travel on beside
			// whatever the mint about to run records.
			return true;
		}
		if ( null === $rec ) {
			// SOMETHING is recorded, but not a record this code can act on
			// (round-13). It names a password that cannot be deleted, so
			// reporting success would let the next connect mint another one
			// beside it. Refusing is all that is left; the operator repairs it
			// by revoking the password by hand and deleting the option.
			return false;
		}
		// Under a claim the record is CONSUMED first, in one conditional
		// statement, and only a caller that actually removed it goes on to
		// delete the password (round-17). Checking ownership and then deleting
		// were two steps: a rotation paused between them, its claim released,
		// would revoke the Application Password of the connect that replaced
		// it — the credential that connect had already returned to the
		// dashboard. Removing the row is the ownership test that cannot be
		// raced, because the row is the record.
		if ( '' !== (string) $fence ) {
			// MARKED, not consumed (round-28). Consuming the record first made
			// ownership provable but left a window in which a request killed
			// between the two statements destroyed the only description of a
			// credential that is still live — nothing afterwards could find it.
			// The mark is a single claim-conditional UPDATE, so it proves
			// ownership exactly as the delete did (one row changed, and only for
			// the claim holder, the fence inside the value making the write
			// distinct from anyone else's), while the record stays durable until
			// the password is provably gone.
			$marked = self::persist_password_owner( $rec['user_id'], $rec['uuid'], $fence, ! empty( $rec['undelivered'] ), $fence );
			if ( false === $marked ) {
				// The statement itself failed. Read as "0 rows — not mine" this
				// would report nothing owed, and the mint would then create a
				// replacement over a record whose password is still live and
				// about to become untracked (round-18). A failure is a failure.
				return false;
			}
			if ( 1 !== (int) $marked ) {
				return true; // the record is not this caller's to act on
			}
		}
		// By the STORED UUID, never the display name (round-5): the name is
		// user-chosen, so a stranger's "Aura SiteAgent" must not be nuked, and
		// a renamed Aura password must still be found.
		$deleted = WP_Application_Passwords::delete_application_password( $rec['user_id'], $rec['uuid'] );
		// Not provably gone — a genuine "still there", or a list that could not
		// be read at all (#434 I5). Either way the record must survive for the
		// next attempt; forgetting it would leave an administrator credential
		// with nothing tracking it.
		if ( true !== $deleted && ! self::managed_password_gone( $rec['user_id'], $rec['uuid'] ) ) {
			// The credential is still live, so its record must exist for the
			// next attempt to find. Under a claim it was consumed above — put
			// it back.
			if ( '' !== (string) $fence ) {
				// …with its delivery state intact (round-24): rewriting an
				// undelivered record as delivered would tell the settings screen
				// a credential reached the dashboard when none did. The
				// pending-revocation mark comes off with it.
				self::persist_password_owner( $rec['user_id'], $rec['uuid'], $fence, ! empty( $rec['undelivered'] ) );
			}
			return false; // a genuine delete failure — the credential is still live
		}
		// Gone for certain — now the CREDENTIAL's half of the record may go, and
		// only now (round-28). Pending intents belong to other handlers and stay
		// (round-36): dropping them with the credential would lose the app_id of
		// a password one of them may still create.
		$left    = get_option( self::APP_PASSWORD_RECORD_OPTION, null );
		$intents = ( is_array( $left ) && isset( $left['intents'] ) && is_array( $left['intents'] ) ) ? $left['intents'] : array();
		self::write_password_record( array() === $intents ? array() : array( 'intents' => $intents ), $fence );
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
	 * @param string $claim_key      Option name.
	 * @param int    $takeover_after Seconds; 0 (the default, and every
	 *                                per-magic-link caller) means no timed
	 *                                takeover at all, exactly as before. > 0
	 *                                (the site claim's callers, #434 review
	 *                                round 1, I2) additionally attempts
	 *                                seize_stale_claim() when the row is
	 *                                already held.
	 * @return string This handler's fence when it holds the claim, else ''.
	 */
	private static function claim_magic_link( $claim_key, $takeover_after = 0 ) {
		global $wpdb;
		$fence = bin2hex( random_bytes( 16 ) );
		$value = $fence . '|' . time();
		$rows  = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) SELECT %s, %s, %s FROM DUAL WHERE NOT EXISTS ( SELECT 1 FROM {$wpdb->options} WHERE option_name = %s )",
				$claim_key,
				$value,
				'no',
				$claim_key
			)
		);
		if ( 1 === (int) $rows && '' === (string) $wpdb->last_error ) {
			// The row was created behind the option cache's back, so evict what
			// add_option() would have maintained: this name, and the `notoptions`
			// entry any earlier miss on it left (see insert_if_absent()).
			wp_cache_delete( $claim_key, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			return $fence;
		}
		// 0: a row is there. false/last_error: nothing was claimed (and a
		// takeover attempt below could not tell those apart either — a
		// database refusing this INSERT would just as likely refuse the
		// takeover's UPDATE, which fails closed to '' on its own).
		if ( $takeover_after > 0 && self::seize_stale_claim( $claim_key, $fence, $value, $takeover_after ) ) {
			return $fence;
		}
		return '';
	}

	/**
	 * Seize a claim recorded stale enough — #434 review round 1 (I2). A
	 * claim row a fatal (OOM, max_execution_time — nothing `finally` can
	 * catch) stranded would otherwise 503 every later request against this
	 * claim forever; this bounds that to SITE_CLAIM_TAKEOVER_AFTER seconds,
	 * via the SAME conditional compare-and-swap the ruleset store uses —
	 * never a blind overwrite, so a claim that is genuinely still held (a
	 * live request refreshing it, or simply not yet stale) cannot be seized
	 * out from under it: the UPDATE names the exact bytes just read, and a
	 * change since then — a release, a refresh, another seize — loses it.
	 *
	 * A row with no `|<ts>` suffix (written before this backward-compatible
	 * format existed, or never at all) is treated as fresh and is never
	 * seized — there is nothing to measure an age against, and reporting one
	 * from nowhere would be worse than declining to seize.
	 *
	 * @param string $claim_key      Option name.
	 * @param string $fence          This handler's fence — $new_value's prefix.
	 * @param string $new_value      This handler's "<fence>|<ts>" to install.
	 * @param int    $takeover_after Seconds the existing claim's age must
	 *                                EXCEED (not merely reach) to be seizable.
	 * @return bool True when this handler seized the claim.
	 */
	private static function seize_stale_claim( $claim_key, $fence, $new_value, $takeover_after ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The claim's AGE decides a takeover, so it must come from the row, never the option cache: a cached copy is exactly the stale value this seize exists to measure.
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $claim_key ) );
		if ( ! is_string( $existing ) || '' === $existing ) {
			return false; // Gone already, or unreadable — a plain retry is the safe next step, not a seize.
		}
		$pipe = strrpos( $existing, '|' );
		if ( false === $pipe ) {
			return false; // No timestamp on record: never seizable.
		}
		$stamp = substr( $existing, $pipe + 1 );
		if ( '' === $stamp || ! ctype_digit( $stamp ) ) {
			// A pipe with no digits after it is not a timestamp either. Without
			// this, `(int)` would read "abc|xyz" as age = now and seize it at
			// once — the exact opposite of what the docblock above promises,
			// and a guarantee a test was asserting but the code did not deliver
			// (#434 Task 2 re-review N1).
			return false;
		}
		$age = time() - (int) $stamp;
		if ( $age <= $takeover_after ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A compare-and-swap on the exact bytes just read; update_option() cannot express the condition, and the row is evicted from the cache below.
		$rows = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$new_value,
				$claim_key,
				$existing
			)
		);
		if ( 1 !== (int) $rows ) {
			return false; // Someone else released, refreshed, or seized it first.
		}
		wp_cache_delete( $claim_key, 'options' );
		return true;
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

	/**
	 * A per-site mutex for OTHER long-running work — today the plugin's own
	 * self-update (#78, Codex rounds 20-21) — on the same claim row the connect
	 * uses: taken by a conditional INSERT, seized only when older than
	 * $takeover_after (a request that fatals never releases), and released only
	 * by its holder — a DELETE fenced on the value — so a holder that outlived
	 * the takeover cannot remove its successor's claim. Core's own
	 * WP_Upgrader::release_lock() is an unconditional delete and has exactly
	 * that hole.
	 *
	 * @param string $claim_key      Option name, under a prefix uninstall sweeps.
	 * @param int    $takeover_after Seconds a holder must EXCEED to be seized.
	 * @return string The fence to release with, or '' when not taken.
	 */
	public static function take_claim( $claim_key, $takeover_after ) {
		return self::claim_magic_link( (string) $claim_key, (int) $takeover_after );
	}

	/**
	 * Release a claim taken with take_claim(): removes the row only while it
	 * still carries this fence. A holder whose claim was seized removes nothing.
	 *
	 * @param string $claim_key Option name.
	 * @param string $fence     The fence take_claim() returned.
	 * @return void
	 */
	public static function release_claim( $claim_key, $fence ) {
		if ( '' === (string) $fence ) {
			return;
		}
		self::release_magic_link( (string) $claim_key, (string) $fence );
	}
}
