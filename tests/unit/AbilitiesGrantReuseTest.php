<?php
/**
 * A valid grant must survive the permission callback running twice.
 *
 * `WP_Ability::execute()` re-runs the permission callback before executing,
 * and the official adapter checks permissions before calling execute() too. So
 * the callback runs at least twice for one call — while
 * `Aura_Worker_Grant::verify()` reserves the nonce as it validates, because
 * single-use is the whole point of a grant.
 *
 * Verifying on each invocation therefore spends the grant on the first check
 * and refuses the second: every APPROVED mutation over the abilities path
 * would be denied, and the denial would look exactly like an attack. The
 * failure needs a real adapter to appear, which is why it is pinned here with
 * a real signature rather than left to be discovered on a client site.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class AbilitiesGrantReuseTest extends TestCase {

	/** @var string Ed25519 secret (signing) key. */
	private $secret;

	/** @var string */
	private $site_hash;

	protected function setUp(): void {
		sa_reset_state();
		Aura_Worker_Call_Context::reset();
		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
			$this->markTestSkipped( 'ext-sodium is not available.' );
		}

		$keypair      = sodium_crypto_sign_keypair();
		$this->secret = sodium_crypto_sign_secretkey( $keypair );
		$GLOBALS['_options']['aura_worker_grant_pubkey'] = base64_encode( sodium_crypto_sign_publickey( $keypair ) );

		$this->site_hash = hash( 'sha256', 'raw-site-token' );
		$GLOBALS['_options']['aura_worker_site_token'] = $this->site_hash;
		$GLOBALS['_caps'] = array( 'manage_options' );
	}

	protected function tearDown(): void {
		Aura_Worker_Call_Context::reset();
		unset( $_SERVER['HTTP_X_AURA_APPROVAL_GRANT'] );
	}

	private function mint( string $tool, array $params ): string {
		$payload = array(
			'v'             => 1,
			'tool'          => $tool,
			'params_sha256' => hash( 'sha256', Aura_Worker_Grant::canonical_json( $params ) ),
			'site'          => $this->site_hash,
			'nonce'         => bin2hex( random_bytes( 16 ) ),
			'iat'           => time(),
			'exp'           => time() + 300,
		);
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$sig  = sodium_crypto_sign_detached( $json, $this->secret );
		$b64  = static function ( string $s ): string {
			return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
		};
		return $b64( $json ) . '.' . $b64( $sig );
	}

	/** The permission callback of a mutating ability. */
	private function gate(): callable {
		$abilities = new Aura_Worker_Abilities();
		$abilities->register_category();
		$abilities->register();
		return $GLOBALS['_abilities']['aura-worker/test-double-tool']['permission_callback'];
	}

	public function test_a_valid_grant_still_passes_on_the_second_permission_check(): void {
		$params = array( 'target' => 'homepage' );
		$_SERVER['HTTP_X_AURA_APPROVAL_GRANT'] = $this->mint( 'test_double_tool', $params );
		Aura_Worker_Call_Context::set_rest_route_for_tests( '/mcp/angie' );

		$gate = $this->gate();

		$this->assertTrue( true === $gate( $params ), 'first permission check refused a valid grant' );
		$this->assertTrue( true === $gate( $params ), 'the grant was spent by the permission check itself' );
	}

	public function test_the_memo_does_not_answer_for_a_different_call(): void {
		// The grant is bound to one tool and one set of parameters. Remembering
		// that it verified must not let it stand for anything else.
		$params = array( 'target' => 'homepage' );
		$_SERVER['HTTP_X_AURA_APPROVAL_GRANT'] = $this->mint( 'test_double_tool', $params );
		Aura_Worker_Call_Context::set_rest_route_for_tests( '/mcp/angie' );

		$gate = $this->gate();
		$this->assertTrue( true === $gate( $params ) );

		$other = $gate( array( 'target' => 'checkout' ) );
		$this->assertTrue(
			is_wp_error( $other ) || false === $other,
			'a grant verified for one call was accepted for another'
		);
	}

	public function test_one_grant_authorises_one_execution_only(): void {
		// The memo exists to survive the paired permission check, not to make a
		// single-use grant reusable. A batch-capable adapter running the same
		// ability twice in one request must be refused the second time.
		$params = array( 'target' => 'homepage' );
		$_SERVER['HTTP_X_AURA_APPROVAL_GRANT'] = $this->mint( 'test_double_tool', $params );
		Aura_Worker_Call_Context::set_rest_route_for_tests( '/mcp/angie' );

		$abilities = new Aura_Worker_Abilities();
		$abilities->register_category();
		$abilities->register();
		$ability = $GLOBALS['_abilities']['aura-worker/test-double-tool'];
		$gate    = $ability['permission_callback'];
		$run     = $ability['execute_callback'];

		// First call: checked (twice, as the adapter does), then executed.
		$this->assertTrue( true === $gate( $params ) );
		$this->assertTrue( true === $gate( $params ) );
		$result = $run( $params );
		$this->assertTrue( $result['success'] );

		// Second call on the same grant: the nonce is spent, and the memo went
		// with the execution it authorised.
		$again = $gate( $params );
		$this->assertTrue(
			is_wp_error( $again ) || false === $again,
			'one grant authorised a second mutation'
		);
	}

	public function test_a_grant_for_another_tool_is_refused(): void {
		$params = array( 'target' => 'homepage' );
		$_SERVER['HTTP_X_AURA_APPROVAL_GRANT'] = $this->mint( 'some_other_tool', $params );
		Aura_Worker_Call_Context::set_rest_route_for_tests( '/mcp/angie' );

		$refused = ( $this->gate() )( $params );
		$this->assertTrue( is_wp_error( $refused ) || false === $refused );
	}
}
