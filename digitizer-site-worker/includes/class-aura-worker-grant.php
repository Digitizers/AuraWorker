<?php
/**
 * Signed single-use approval grants (G-grants).
 *
 * Verifies a gateway-minted Ed25519 grant that authorizes ONE execution of ONE
 * approval-required tool, with EXACTLY the given parameters, on THIS site,
 * once, within a short window. This closes the gap where a stolen site token
 * alone could run a power tool: when a gateway public key is provisioned,
 * enforcement here means a fresh, human-approved, gateway-signed grant is also
 * required — so a leaked token is no longer sufficient.
 *
 * Asymmetric on purpose: the plugin stores only the gateway PUBLIC key
 * (`aura_worker_grant_pubkey`). Even a fully compromised site — one whose
 * database an attacker can read — cannot mint its own grants, because the
 * signing (private) key never leaves the Aura gateway.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Worker_Grant {

	/**
	 * Allowed clock skew, in seconds, between the gateway and this site.
	 */
	const CLOCK_SKEW = 60;

	/**
	 * Reject a grant whose declared lifetime (exp - iat) exceeds this, so a
	 * mis-minted long-lived grant can't be stockpiled.
	 */
	const MAX_TTL = 900;

	/**
	 * Option-name prefix for spent nonces (single-use enforcement).
	 */
	const NONCE_PREFIX = 'aura_grant_nonce_';

	/**
	 * Cron hook that deletes a single spent-nonce reservation after it expires.
	 */
	const NONCE_GC_HOOK = 'aura_worker_delete_grant_nonce';

	/**
	 * Whether grant enforcement is active for this site.
	 *
	 * Enforcement turns ON as soon as a key is CONFIGURED (the option is
	 * non-empty), regardless of whether that key parses. This is deliberate: a
	 * misconfigured, truncated, or corrupted key must fail CLOSED in verify() —
	 * never silently revert approval-required tools to token-only execution.
	 * Until a key is configured, the site keeps its prior behavior (forensic hook
	 * only), so existing deployments don't break on upgrade.
	 *
	 * @return bool
	 */
	public static function is_enforced() {
		return '' !== (string) get_option( 'aura_worker_grant_pubkey', '' );
	}

	/**
	 * The raw 32-byte Ed25519 public key, or '' when unconfigured / invalid /
	 * unusable (no libsodium). Callers reached via is_enforced() treat '' as a
	 * hard failure, not a bypass.
	 *
	 * @return string
	 */
	private static function pubkey_raw() {
		// Guard the sodium constant so a no-libsodium host doesn't fatal here
		// before verify() can return its intended error.
		if ( ! defined( 'SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES' ) ) {
			return '';
		}
		$b64 = (string) get_option( 'aura_worker_grant_pubkey', '' );
		if ( '' === $b64 ) {
			return '';
		}
		$raw = base64_decode( $b64, true );
		if ( false === $raw || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $raw ) ) {
			return '';
		}
		return $raw;
	}

	/**
	 * Verify a grant authorizes THIS tool + params on THIS site, right now, once.
	 *
	 * @param string $header X-Aura-Approval-Grant header value ("b64url.b64url").
	 * @param string $tool   Tool name being executed.
	 * @param array  $params Parameters the tool was called with.
	 * @return true|string   True when valid; otherwise a short failure reason.
	 */
	public static function verify( $header, $tool, $params ) {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return 'server is missing libsodium';
		}

		$pubkey = self::pubkey_raw();
		if ( '' === $pubkey ) {
			// Reached only when is_enforced() is true (a key IS configured), so an
			// empty result means the configured key is invalid — fail closed.
			return 'grant key is misconfigured';
		}

		$header = trim( (string) $header );
		if ( '' === $header ) {
			return 'missing grant';
		}

		$parts = explode( '.', $header );
		if ( 2 !== count( $parts ) ) {
			return 'malformed grant';
		}

		$payload_json = self::b64url_decode( $parts[0] );
		$sig          = self::b64url_decode( $parts[1] );
		if ( false === $payload_json || false === $sig ) {
			return 'malformed grant';
		}
		if ( SODIUM_CRYPTO_SIGN_BYTES !== strlen( $sig ) ) {
			return 'bad signature length';
		}

		// Verify the signature FIRST, over the exact bytes that were signed.
		if ( ! sodium_crypto_sign_verify_detached( $sig, $payload_json, $pubkey ) ) {
			return 'signature verification failed';
		}

		$g = json_decode( $payload_json, true );
		if ( ! is_array( $g ) ) {
			return 'unparseable grant';
		}
		if ( 1 !== ( isset( $g['v'] ) ? (int) $g['v'] : 0 ) ) {
			return 'unsupported grant version';
		}

		// Bind to the exact tool.
		if ( ! isset( $g['tool'] ) || ! hash_equals( (string) $g['tool'], (string) $tool ) ) {
			return 'tool mismatch';
		}

		// Bind to the exact parameters.
		$expected = hash( 'sha256', self::canonical_json( $params ) );
		if ( ! isset( $g['params_sha256'] ) || ! hash_equals( (string) $g['params_sha256'], $expected ) ) {
			return 'params mismatch';
		}

		// Bind to THIS site (a grant for site A can't be replayed on site B). The
		// gateway computes sha256(raw token); the plugin stores that same hash.
		$site = (string) get_option( 'aura_worker_site_token', '' );
		if ( '' === $site || ! isset( $g['site'] ) || ! hash_equals( $site, (string) $g['site'] ) ) {
			return 'site mismatch';
		}

		// Validity window.
		$now = time();
		$iat = isset( $g['iat'] ) ? (int) $g['iat'] : 0;
		$exp = isset( $g['exp'] ) ? (int) $g['exp'] : 0;
		if ( $iat <= 0 || $exp <= 0 ) {
			return 'missing validity window';
		}
		if ( $exp - $iat > self::MAX_TTL ) {
			return 'grant lifetime too long';
		}
		if ( $now + self::CLOCK_SKEW < $iat ) {
			return 'grant not yet valid';
		}
		if ( $now - self::CLOCK_SKEW > $exp ) {
			return 'grant expired';
		}

		// Single-use: reserve the nonce ATOMICALLY. add_option() is an INSERT
		// guarded by the option_name unique index, so exactly one of two
		// concurrent workers handling the same grant wins — a check-then-set on a
		// transient would let both pass. The row is self-cleaning: each nonce
		// schedules its own deletion just past the grant's expiry, so spent
		// nonces don't accumulate and a bulk GC query is unnecessary.
		$nonce = isset( $g['nonce'] ) ? (string) $g['nonce'] : '';
		if ( '' === $nonce || strlen( $nonce ) > 128 || ! ctype_xdigit( $nonce ) ) {
			return 'bad nonce';
		}
		$key = self::NONCE_PREFIX . hash( 'sha256', $nonce );
		if ( ! add_option( $key, $exp, '', 'no' ) ) {
			return 'grant already used';
		}
		if ( function_exists( 'wp_schedule_single_event' ) ) {
			// Delete strictly AFTER the grant's last acceptable second. A grant is
			// still valid at exp + CLOCK_SKEW (the expiry check only rejects when
			// now - skew > exp), so cleaning up any earlier could delete the
			// reservation while the grant is still verifiable and open a replay.
			wp_schedule_single_event( $exp + ( 2 * self::CLOCK_SKEW ) + 1, self::NONCE_GC_HOOK, array( $key ) );
		}

		return true;
	}

	/**
	 * Verify any document signed by the gateway key, and return its payload.
	 *
	 * Same envelope as a grant — `b64url(json).b64url(sig)` — and the same
	 * public key, so anything Aura signs for this site is checked one way. The
	 * ruleset (Aura_Worker_Rules) is the first non-grant user. This does NOT
	 * reserve a nonce or check expiry: documents are not single-use, and the
	 * caller decides freshness (the ruleset uses a monotonic `seq`).
	 *
	 * @param string $envelope Signed document.
	 * @return array|string Decoded payload, or a reason string on failure.
	 */
	public static function has_usable_key() {
		// NOT is_enforced(), which asks only whether the option is non-empty.
		// pubkey_raw() returns '' unless the stored value decodes to exactly a
		// valid Ed25519 public key on a host that has libsodium — which is the
		// real question behind "can this site verify anything?".
		return '' !== self::pubkey_raw();
	}

	public static function verify_signed_document( $envelope ) {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return 'server is missing libsodium';
		}
		if ( ! self::is_enforced() ) {
			return 'no gateway key provisioned';
		}
		$pubkey = self::pubkey_raw();
		if ( '' === $pubkey ) {
			return 'gateway key is invalid';
		}
		$parts = explode( '.', (string) $envelope );
		if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
			return 'malformed envelope';
		}
		$json = self::b64url_decode( $parts[0] );
		$sig  = self::b64url_decode( $parts[1] );
		if ( '' === $json || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $sig ) ) {
			return 'malformed envelope';
		}
		if ( ! sodium_crypto_sign_verify_detached( $sig, $json, $pubkey ) ) {
			return 'bad signature';
		}
		$doc = json_decode( $json, true );
		if ( ! is_array( $doc ) ) {
			return 'payload is not an object';
		}
		return $doc;
	}

	/**
	 * Guard a non-MCP REST write endpoint with the same grant policy.
	 *
	 * The MCP `tools/execute` handler enforces grants for mutating tools; the
	 * direct REST write endpoints (updates, batch, self-update, rollback) run as
	 * admin off a valid `X-Aura-Token` and were previously ungated. This lets each
	 * write handler require the same fresh, single-use, gateway-signed grant when
	 * enforcement is on — so a stolen token can no longer trigger a code update or
	 * rollback. A no-op when no pubkey is provisioned (legacy token-only sites keep
	 * working until they reconnect).
	 *
	 * The caller passes the EXACT params the handler will act on, with defaults
	 * already resolved, so the bound hash matches what the handler executes (and
	 * what the gateway signed) byte-for-byte.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @param string          $action  Stable action name the grant must bind (e.g. "wp.update.plugin").
	 * @param array           $params  Exact params the handler will act on (defaults resolved).
	 * @return true|WP_Error  True when allowed; WP_Error(403) when a grant is required and missing/invalid.
	 */
	public static function require_for( $request, $action, $params ) {
		if ( ! self::is_enforced() ) {
			return true;
		}
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		$grant  = (string) $request->get_header( 'X-Aura-Approval-Grant' );
		$reason = self::verify( $grant, (string) $action, $params );
		if ( true !== $reason ) {
			// Distinct forensic signal for a refused write (kept separate from the
			// power-execute hook, which means a tool actually ran).
			do_action( 'aura_worker_grant_denied', (string) $action, $params, $reason );
			return new WP_Error(
				'aura_grant_required',
				'Approval grant required or invalid: ' . $reason,
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Delete a spent-nonce reservation. Fired by the single event scheduled when
	 * the nonce was reserved; also runs as a sweep in case an event was missed.
	 *
	 * @param string $key Nonce option name.
	 * @return void
	 */
	public static function delete_spent_nonce( $key ) {
		if ( is_string( $key ) && 0 === strpos( $key, self::NONCE_PREFIX ) ) {
			delete_option( $key );
		}
	}

	/**
	 * Canonical JSON used for the params hash. MUST match the gateway byte-for-byte.
	 *
	 * Rule: object keys are sorted recursively; list order is preserved; ANY empty
	 * container encodes as `{}`. The empty→`{}` rule sidesteps PHP's inability to
	 * distinguish an empty array from an empty object — the gateway applies the
	 * identical rule (empty array OR empty object ⇒ `{}`), so both sides agree.
	 *
	 * @param mixed $data Parameters.
	 * @return string
	 */
	public static function canonical_json( $data ) {
		return (string) wp_json_encode(
			self::canonicalize( $data ),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
	}

	/**
	 * @param mixed $data
	 * @return mixed
	 */
	private static function canonicalize( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		if ( empty( $data ) ) {
			return new stdClass(); // Emit {} deterministically for any empty container.
		}
		$is_list = array_keys( $data ) === range( 0, count( $data ) - 1 );
		$out     = array();
		foreach ( $data as $k => $v ) {
			$out[ $k ] = self::canonicalize( $v );
		}
		if ( ! $is_list ) {
			ksort( $out );
		}
		return $out;
	}

	/**
	 * The payload of a signed envelope WITHOUT verifying it.
	 *
	 * For the one caller that has no key to verify with: the unbind marker's
	 * fast path (#434, spec §2.3 step 0). There, the token that already
	 * authenticated the request is the authority and the gateway public key
	 * may have been deleted by an earlier Phase B — so the document is read
	 * only to learn which `seq` the retry is echoing and whether it says
	 * `final`. NEVER use this to make a trust decision; verify_signed_document()
	 * is the only function that answers that question.
	 *
	 * @param string $envelope Signed document — or anything at all.
	 * @return array Decoded payload, or an empty array for anything that is not one.
	 */
	public static function peek_payload( $envelope ) {
		$parts = explode( '.', (string) $envelope );
		if ( '' === $parts[0] ) {
			return array();
		}
		$json = self::b64url_decode( $parts[0] );
		if ( ! is_string( $json ) || '' === $json ) {
			return array();
		}
		$doc = json_decode( $json, true );
		return is_array( $doc ) ? $doc : array();
	}

	/**
	 * URL-safe base64 decode (strict).
	 *
	 * @param string $s
	 * @return string|false
	 */
	private static function b64url_decode( $s ) {
		$s   = strtr( (string) $s, '-_', '+/' );
		$pad = strlen( $s ) % 4;
		if ( $pad ) {
			$s .= str_repeat( '=', 4 - $pad );
		}
		return base64_decode( $s, true );
	}
}
