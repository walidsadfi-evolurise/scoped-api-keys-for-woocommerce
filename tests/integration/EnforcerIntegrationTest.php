<?php
/**
 * Real REST requests through WP_REST_Server with a WooCommerce consumer key.
 *
 * @package ScopedApiKeys
 */

/**
 * Runs against WordPress + WooCommerce (see .github/workflows/ci.yml).
 */
class EnforcerIntegrationTest extends WP_UnitTestCase {

	/**
	 * Key row id.
	 *
	 * @var int
	 */
	private $key_id;

	/**
	 * Raw consumer key.
	 *
	 * @var string
	 */
	private $ck;

	/**
	 * Raw consumer secret.
	 *
	 * @var string
	 */
	private $cs;

	/**
	 * Admin user id.
	 *
	 * @var int
	 */
	private $admin;

	/**
	 * Create an admin and a read/write API key for them, the same way WooCommerce does.
	 */
	public function set_up() {
		parent::set_up();
		global $wpdb;
		$this->admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->ck    = 'ck_' . wc_rand_hash();
		$this->cs    = 'cs_' . wc_rand_hash();
		$wpdb->insert(
			$wpdb->prefix . 'woocommerce_api_keys',
			array(
				'user_id'         => $this->admin,
				'description'     => 'test',
				'permissions'     => 'read_write',
				'consumer_key'    => wc_api_hash( $this->ck ),
				'consumer_secret' => $this->cs,
				'truncated_key'   => substr( $this->ck, -7 ),
			)
		);
		$this->key_id = (int) $wpdb->insert_id;
		update_option( WCSAK_Settings::OPTION, WCSAK_Settings::defaults() );
		$_SERVER['HTTPS']       = 'on';
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
		$_SERVER['PHP_AUTH_USER'] = $this->ck;
		$_SERVER['PHP_AUTH_PW']   = $this->cs;
		wp_set_current_user( 0 );
		// Force a fresh REST server so our filters and WooCommerce's run on each request.
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * Clean request globals.
	 */
	public function tear_down() {
		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_SERVER['HTTPS'] );
		parent::tear_down();
	}

	/**
	 * Dispatch a request the way wp-json would, re-running authentication.
	 *
	 * @param string $method Method.
	 * @param string $route  Route.
	 * @return WP_REST_Response
	 */
	private function call( $method, $route ) {
		wp_set_current_user( 0 );
		$GLOBALS['current_user']   = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$_SERVER['REQUEST_URI']    = '/wp-json' . $route;
		$_SERVER['REQUEST_METHOD'] = $method;
		// WooCommerce authenticates on determine_current_user; trigger it like a real request.
		$user_id = apply_filters( 'determine_current_user', false );
		wp_set_current_user( (int) $user_id );
		$server   = rest_get_server();
		$request  = new WP_REST_Request( $method, $route );
		$response = $server->dispatch( $request );
		// WP_REST_Server::serve_request() applies this after dispatch(); it is where usage is logged.
		return apply_filters( 'rest_post_dispatch', rest_ensure_response( $response ), $server, $request );
	}

	/**
	 * A key with no policy behaves like plain WooCommerce.
	 */
	public function test_unmanaged_key_is_unrestricted() {
		$r = $this->call( 'GET', '/wc/v3/products' );
		$this->assertSame( 200, $r->get_status() );
	}

	/**
	 * Scope "orders:read": orders OK, products 403, POST orders 403.
	 */
	public function test_scope_is_enforced() {
		WCSAK_Store::save_policy( $this->key_id, array( 'enabled' => 1, 'scopes' => 'orders:read', 'ip_allowlist' => '', 'expires_at' => null, 'rate_limit' => 0, 'log_usage' => 1, 'note' => '' ) );

		$this->assertSame( 200, $this->call( 'GET', '/wc/v3/orders' )->get_status() );

		$r = $this->call( 'GET', '/wc/v3/products' );
		$this->assertSame( 403, $r->get_status() );
		$this->assertSame( 'wcsak_scope', $r->get_data()['code'] );

		$r = $this->call( 'POST', '/wc/v3/orders' );
		$this->assertSame( 403, $r->get_status() );

		$usage = WCSAK_Store::usage( array( 'key_id' => $this->key_id ) );
		$this->assertSame( 3, $usage['total'] );
		$this->assertSame( 'blocked:scope', $usage['items'][0]->outcome );
		$this->assertSame( 'allowed', $usage['items'][2]->outcome );
		$this->assertSame( 2, (int) WCSAK_Store::get_policy( $this->key_id )->blocked_count );
	}

	/**
	 * IP allowlist, expiry, disabled, rate limit.
	 */
	public function test_ip_expiry_disabled_rate() {
		$base = array( 'enabled' => 1, 'scopes' => '', 'ip_allowlist' => '', 'expires_at' => null, 'rate_limit' => 0, 'log_usage' => 0, 'note' => '' );

		WCSAK_Store::save_policy( $this->key_id, array_merge( $base, array( 'ip_allowlist' => '198.51.100.0/24' ) ) );
		$this->assertSame( 403, $this->call( 'GET', '/wc/v3/orders' )->get_status() );
		WCSAK_Store::save_policy( $this->key_id, array_merge( $base, array( 'ip_allowlist' => '203.0.113.0/24' ) ) );
		$this->assertSame( 200, $this->call( 'GET', '/wc/v3/orders' )->get_status() );

		WCSAK_Store::save_policy( $this->key_id, array_merge( $base, array( 'expires_at' => '2000-01-01 00:00:00' ) ) );
		$r = $this->call( 'GET', '/wc/v3/orders' );
		$this->assertSame( 403, $r->get_status() );
		$this->assertSame( 'wcsak_expired', $r->get_data()['code'] );

		WCSAK_Store::save_policy( $this->key_id, array_merge( $base, array( 'enabled' => 0 ) ) );
		$this->assertSame( 'wcsak_disabled', $this->call( 'GET', '/wc/v3/orders' )->get_data()['code'] );

		WCSAK_Store::save_policy( $this->key_id, array_merge( $base, array( 'rate_limit' => 2 ) ) );
		$this->assertSame( 200, $this->call( 'GET', '/wc/v3/orders' )->get_status() );
		$this->assertSame( 200, $this->call( 'GET', '/wc/v3/orders' )->get_status() );
		$r = $this->call( 'GET', '/wc/v3/orders' );
		$this->assertSame( 429, $r->get_status() );
		$this->assertSame( 'wcsak_rate_limit', $r->get_data()['code'] );
	}

	/**
	 * Monitor mode logs but lets the request through.
	 */
	public function test_monitor_mode() {
		update_option( WCSAK_Settings::OPTION, WCSAK_Settings::sanitize( array( 'mode' => 'monitor' ) ) );
		WCSAK_Store::save_policy( $this->key_id, array( 'enabled' => 1, 'scopes' => 'orders:read', 'ip_allowlist' => '', 'expires_at' => null, 'rate_limit' => 0, 'log_usage' => 1, 'note' => '' ) );
		$this->assertSame( 200, $this->call( 'GET', '/wc/v3/products' )->get_status() );
		$usage = WCSAK_Store::usage( array( 'key_id' => $this->key_id ) );
		$this->assertSame( 'would_block:scope', $usage['items'][0]->outcome );
	}

	/**
	 * Internal rest_do_request() calls made by server-side code are not the client's requests: not blocked, not counted.
	 */
	public function test_internal_subrequests_are_not_policed() {
		WCSAK_Store::save_policy( $this->key_id, array( 'enabled' => 1, 'scopes' => 'orders:read', 'ip_allowlist' => '', 'expires_at' => null, 'rate_limit' => 0, 'log_usage' => 1, 'note' => '' ) );
		// The client asked for orders; while handling it, server code fetches products internally.
		wp_set_current_user( 0 );
		$GLOBALS['current_user']   = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$_SERVER['REQUEST_URI']    = '/wp-json/wc/v3/orders';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		wp_set_current_user( (int) apply_filters( 'determine_current_user', false ) );
		$internal = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/products' ) );
		$this->assertSame( 200, $internal->get_status(), 'internal sub-request passes' );
		$this->assertSame( 0, (int) WCSAK_Store::get_policy( $this->key_id )->blocked_count );
		$this->assertSame( 0, WCSAK_Store::usage( array( 'key_id' => $this->key_id ) )['total'] );

		// The same route requested by the client is blocked.
		$this->assertSame( 403, $this->call( 'GET', '/wc/v3/products' )->get_status() );
		$this->assertSame( 1, (int) WCSAK_Store::get_policy( $this->key_id )->blocked_count );
	}

	/**
	 * Cookie-authenticated admin requests are never touched.
	 */
	public function test_logged_in_user_without_key_is_ignored() {
		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );
		WCSAK_Store::save_policy( $this->key_id, array( 'enabled' => 0, 'scopes' => '', 'ip_allowlist' => '', 'expires_at' => null, 'rate_limit' => 0, 'log_usage' => 1, 'note' => '' ) );
		wp_set_current_user( $this->admin );
		$server   = rest_get_server();
		$response = $server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/products' ) );
		$this->assertSame( 200, $response->get_status() );
	}
}
