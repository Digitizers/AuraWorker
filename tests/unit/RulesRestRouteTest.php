<?php
/**
 * Two things live here: the legacy REST update handlers enforce rules
 * explicitly (they do not go through execute_tool), and the /v2/rules route
 * accepts a signed ruleset.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class RulesRestRouteTest extends TestCase {

	private $api;

	protected function setUp(): void {
		sa_reset_state();
		$GLOBALS['_options']['aura_worker_site_token'] = Aura_Worker_Security::hash_token( 'tok' );
		$this->api = new Aura_Worker_API( new Aura_Worker_Security() );
	}

	private function install( array $rules ): void {
		$GLOBALS['_options'][ Aura_Worker_Rules::OPTION ] = array(
			'envelope'    => 'x.y',
			'seq'         => 1,
			'issued_at'   => '2026-08-21T00:00:00Z',
			'received_at' => time(),
			'rules'       => $rules,
		);
	}

	private function rule( string $key, string $effect, string $type, ?string $id = null ): array {
		return array(
			'key'    => $key,
			'effect' => $effect,
			'target' => array( 'type' => $type, 'id' => $id ),
			'reason' => "r:{$key}",
		);
	}

	private function request( array $params ): WP_REST_Request {
		$req = new WP_REST_Request();
		$req->set_header( 'X-Aura-Token', 'tok' );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return $req;
	}

	public function test_update_plugin_is_refused_by_a_plugin_rule(): void {
		$this->install( array( $this->rule( 'rule/woo', 'block', 'plugin', 'woocommerce' ) ) );
		$res = $this->api->update_plugin( $this->request( array( 'plugin' => 'woocommerce/woocommerce.php' ) ) );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_rule_blocked', $res->get_error_code() );
		$this->assertSame( 403, $res->get_error_data()['status'] );
	}

	public function test_update_plugin_of_another_plugin_is_not_refused_by_it(): void {
		$this->install( array( $this->rule( 'rule/woo', 'block', 'plugin', 'woocommerce' ) ) );
		$res = $this->api->update_plugin( $this->request( array( 'plugin' => 'akismet/akismet.php' ) ) );
		// Not a rule refusal. (It may fail later for other stubbed reasons; we
		// only assert the rule did not fire.)
		$this->assertFalse( is_wp_error( $res ) && 'aura_rule_blocked' === $res->get_error_code() );
	}

	public function test_update_core_is_refused_by_a_site_freeze(): void {
		$this->install( array( $this->rule( 'rule/freeze', 'block', 'site' ) ) );
		$res = $this->api->update_core( $this->request( array() ) );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_rule_blocked', $res->get_error_code() );
	}

	public function test_batch_update_is_refused_by_a_site_freeze(): void {
		$this->install( array( $this->rule( 'rule/freeze', 'block', 'site' ) ) );
		$res = $this->api->batch_update_plugins( $this->request( array( 'plugins' => array( 'akismet/akismet.php' ), 'chunk_size' => 1, 'create_backup' => true ) ) );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_rule_blocked', $res->get_error_code() );
	}

	public function test_batch_update_is_refused_when_any_listed_plugin_is_ruled(): void {
		$this->install( array( $this->rule( 'rule/woo', 'block', 'plugin', 'woocommerce' ) ) );
		$res = $this->api->batch_update_plugins( $this->request( array( 'plugins' => array( 'akismet/akismet.php', 'woocommerce/woocommerce.php' ), 'chunk_size' => 2, 'create_backup' => true ) ) );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_rule_blocked', $res->get_error_code() );
	}

	/**
	 * Every other grant-gated handler under a freeze. One provider, so a
	 * handler added later without a row here is at least a visible omission.
	 *
	 * @dataProvider site_wide_handlers
	 */
	public function test_every_site_wide_handler_is_refused_by_a_freeze( string $method, array $params ): void {
		$this->install( array( $this->rule( 'rule/freeze', 'block', 'site' ) ) );
		$res = $this->api->{$method}( $this->request( $params ) );
		$this->assertInstanceOf( WP_Error::class, $res, "{$method} ran under a freeze" );
		$this->assertSame( 'aura_rule_blocked', $res->get_error_code(), "{$method} failed for a reason other than the rule" );
	}

	public static function site_wide_handlers(): array {
		return array(
			array( 'update_theme', array( 'theme' => 'astra' ) ),
			array( 'update_translations', array() ),
			array( 'self_update', array( 'version' => '9.9.9' ) ),
			array( 'update_database', array() ),
			array( 'restore_snapshot', array( 'id' => 'snap-1' ) ),
		);
	}

	public function test_rollback_is_refused_by_a_rule_on_that_plugin(): void {
		$this->install( array( $this->rule( 'rule/woo', 'block', 'plugin', 'woocommerce' ) ) );
		$res = $this->api->rollback_plugin( $this->request( array( 'plugin' => 'woocommerce', 'backup_path' => '' ) ) );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_rule_blocked', $res->get_error_code() );
	}

	public function test_a_warn_on_a_direct_handler_reaches_the_response(): void {
		$this->install( array( $this->rule( 'rule/careful', 'warn', 'plugin', 'akismet' ) ) );
		$res = $this->api->update_plugin( $this->request( array( 'plugin' => 'akismet/akismet.php' ) ) );
		$this->assertInstanceOf( WP_REST_Response::class, $res );
		$this->assertSame( array( array( 'rule' => 'rule/careful', 'reason' => 'r:rule/careful' ) ), $res->data['warnings'] ?? null );
	}

	public function test_taking_a_snapshot_is_never_refused(): void {
		// The safety net must stay available during exactly the window a
		// freeze exists for. create_snapshot captures state; it changes nothing.
		$this->install( array( $this->rule( 'rule/freeze', 'block', 'site' ) ) );
		$res = $this->api->create_snapshot( $this->request( array( 'kind' => 'option', 'target' => 'blogname' ) ) );
		$this->assertFalse( is_wp_error( $res ) && 'aura_rule_blocked' === $res->get_error_code(), 'a freeze refused to take a snapshot' );
	}
}

