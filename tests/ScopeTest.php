<?php
/**
 * Scope logic tests.
 *
 * @package ScopedApiKeys
 */

use PHPUnit\Framework\TestCase;

/**
 * WCSAK_Scope.
 */
class ScopeTest extends TestCase {

	/**
	 * Routes map to resource buckets.
	 */
	public function test_resolve_routes() {
		$this->assertSame( array( 'wc', 'orders' ), WCSAK_Scope::resolve( '/wc/v3/orders' ) );
		$this->assertSame( array( 'wc', 'orders' ), WCSAK_Scope::resolve( '/wc/v3/orders/12/refunds/3' ) );
		$this->assertSame( array( 'wc', 'orders' ), WCSAK_Scope::resolve( '/wc/v2/orders/12/notes' ) );
		$this->assertSame( array( 'wc', 'products' ), WCSAK_Scope::resolve( '/wc/v3/products/9/variations' ) );
		$this->assertSame( array( 'wc', 'products' ), WCSAK_Scope::resolve( '/wc/v3/products/categories' ) );
		$this->assertSame( array( 'wc', 'customers' ), WCSAK_Scope::resolve( '/wc/v3/customers/4' ) );
		$this->assertSame( array( 'wc', 'coupons' ), WCSAK_Scope::resolve( 'wc/v3/coupons' ) );
		$this->assertSame( array( 'wc', 'system_status' ), WCSAK_Scope::resolve( '/wc/v3/system_status/tools/clear_transients' ) );
		$this->assertSame( array( 'wc', 'shipping' ), WCSAK_Scope::resolve( '/wc/v3/shipping/zones' ) );
		$this->assertSame( array( 'wc', 'shipping' ), WCSAK_Scope::resolve( '/wc/v3/shipping_methods' ) );
		$this->assertSame( array( 'wc', 'taxes' ), WCSAK_Scope::resolve( '/wc/v3/taxes/classes' ) );
		$this->assertSame( array( 'wc', 'other' ), WCSAK_Scope::resolve( '/wc/v3/something_new' ) );
		$this->assertSame( array( 'wc-analytics', 'analytics' ), WCSAK_Scope::resolve( '/wc-analytics/reports/revenue/stats' ) );
		$this->assertSame( array( 'wc-admin', 'admin' ), WCSAK_Scope::resolve( '/wc-admin/options' ) );
	}

	/**
	 * Non-WooCommerce and public routes are left alone.
	 */
	public function test_resolve_ignores_other_routes() {
		$this->assertNull( WCSAK_Scope::resolve( '/wp/v2/posts' ) );
		$this->assertNull( WCSAK_Scope::resolve( '/wc/store/v1/cart' ) );
		$this->assertNull( WCSAK_Scope::resolve( '/wc/v3' ) );
		$this->assertNull( WCSAK_Scope::resolve( '/' ) );
	}

	/**
	 * Method → action.
	 */
	public function test_action_for_method() {
		$this->assertSame( 'read', WCSAK_Scope::action_for_method( 'GET' ) );
		$this->assertSame( 'read', WCSAK_Scope::action_for_method( 'head' ) );
		$this->assertSame( 'write', WCSAK_Scope::action_for_method( 'POST' ) );
		$this->assertSame( 'write', WCSAK_Scope::action_for_method( 'PUT' ) );
		$this->assertSame( 'write', WCSAK_Scope::action_for_method( 'DELETE' ) );
	}

	/**
	 * Normalisation drops junk and duplicates.
	 */
	public function test_normalize() {
		$this->assertSame( array( 'orders:read', 'products:write' ), WCSAK_Scope::normalize( 'Orders:READ, products:write, orders:read, nope:read, orders:delete' ) );
		$this->assertSame( array( '*:read' ), WCSAK_Scope::normalize( array( '*:read' ) ) );
		$this->assertSame( array(), WCSAK_Scope::normalize( '' ) );
	}

	/**
	 * Matching rules: exact, wildcard, write implies read.
	 */
	public function test_allows() {
		$s = WCSAK_Scope::normalize( 'orders:read,products:write' );
		$this->assertTrue( WCSAK_Scope::allows( $s, 'orders', 'read' ) );
		$this->assertFalse( WCSAK_Scope::allows( $s, 'orders', 'write' ) );
		$this->assertTrue( WCSAK_Scope::allows( $s, 'products', 'read' ) );
		$this->assertTrue( WCSAK_Scope::allows( $s, 'products', 'write' ) );
		$this->assertFalse( WCSAK_Scope::allows( $s, 'customers', 'read' ) );

		$all_read = WCSAK_Scope::normalize( '*:read' );
		$this->assertTrue( WCSAK_Scope::allows( $all_read, 'customers', 'read' ) );
		$this->assertFalse( WCSAK_Scope::allows( $all_read, 'customers', 'write' ) );

		$this->assertFalse( WCSAK_Scope::allows( array(), 'orders', 'read' ), 'no scope = nothing (the caller treats an empty list as unrestricted before asking)' );
	}

	/**
	 * IP allowlist: exact, CIDR v4/v6, wildcard, empty, garbage.
	 */
	public function test_ip_allowed() {
		$this->assertTrue( WCSAK_Scope::ip_allowed( '1.2.3.4', '' ) );
		$this->assertTrue( WCSAK_Scope::ip_allowed( '1.2.3.4', '1.2.3.4' ) );
		$this->assertFalse( WCSAK_Scope::ip_allowed( '1.2.3.5', '1.2.3.4' ) );
		$this->assertTrue( WCSAK_Scope::ip_allowed( '10.20.30.40', "192.168.0.1\n10.0.0.0/8" ) );
		$this->assertFalse( WCSAK_Scope::ip_allowed( '11.0.0.1', '10.0.0.0/8' ) );
		$this->assertTrue( WCSAK_Scope::ip_allowed( '203.0.113.200', '203.0.113.128/25' ) );
		$this->assertFalse( WCSAK_Scope::ip_allowed( '203.0.113.100', '203.0.113.128/25' ) );
		$this->assertTrue( WCSAK_Scope::ip_allowed( '2001:db8::1', '2001:db8::/32' ) );
		$this->assertFalse( WCSAK_Scope::ip_allowed( '2001:db9::1', '2001:db8::/32' ) );
		$this->assertTrue( WCSAK_Scope::ip_allowed( '8.8.8.8', '*' ) );
		$this->assertFalse( WCSAK_Scope::ip_allowed( '', '1.2.3.4' ) );
		$this->assertFalse( WCSAK_Scope::ip_allowed( 'not-an-ip', '1.2.3.4' ) );
		$this->assertFalse( WCSAK_Scope::ip_allowed( '1.2.3.4', '1.2.3.4/99' ) );
		$this->assertFalse( WCSAK_Scope::ip_allowed( '1.2.3.4', '2001:db8::/32' ), 'v4 against a v6 CIDR never matches' );
	}

	/**
	 * Rate window.
	 */
	public function test_within_rate() {
		$this->assertTrue( WCSAK_Scope::within_rate( 0, 0 ) );
		$this->assertTrue( WCSAK_Scope::within_rate( 999, 0 ) );
		$this->assertTrue( WCSAK_Scope::within_rate( 59, 60 ) );
		$this->assertFalse( WCSAK_Scope::within_rate( 60, 60 ) );
	}
}
