<?php
/**
 * Pure scope logic: route → resource, method → action, scope matching, IP matching.
 * No WordPress dependency, unit-tested on its own.
 *
 * @package ScopedApiKeys
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scopes are strings "resource:action" where action is read|write and resource is one of
 * the known WooCommerce REST resources or "*". "orders:write" implies "orders:read".
 */
class WCSAK_Scope {

	/**
	 * Resources a key can be limited to. Keys are what appears in the route, values are labels.
	 *
	 * @return array
	 */
	public static function resources() {
		return array(
			'orders'           => 'Orders (incl. refunds, notes)',
			'products'         => 'Products (incl. variations, categories, tags, attributes, reviews)',
			'customers'        => 'Customers',
			'coupons'          => 'Coupons',
			'reports'          => 'Reports',
			'settings'         => 'Settings',
			'shipping'         => 'Shipping (zones, methods, classes)',
			'taxes'            => 'Taxes',
			'payment_gateways' => 'Payment gateways',
			'webhooks'         => 'Webhooks',
			'system_status'    => 'System status & tools',
			'data'             => 'Data (countries, currencies, continents)',
			'analytics'        => 'Analytics (wc-analytics namespace)',
			'admin'            => 'Admin (wc-admin namespace)',
			'other'            => 'Anything else under /wc/',
		);
	}

	/**
	 * Map the first route segment to a resource bucket.
	 *
	 * @var array
	 */
	private static $aliases = array(
		'orders'              => 'orders',
		'refunds'             => 'orders',
		'products'            => 'products',
		'product_variations'  => 'products',
		'variations'          => 'products',
		'customers'           => 'customers',
		'coupons'             => 'coupons',
		'reports'             => 'reports',
		'settings'            => 'settings',
		'shipping'            => 'shipping',
		'shipping_methods'    => 'shipping',
		'taxes'               => 'taxes',
		'payment_gateways'    => 'payment_gateways',
		'webhooks'            => 'webhooks',
		'system_status'       => 'system_status',
		'data'                => 'data',
	);

	/**
	 * Resolve a REST route to (namespace, resource).
	 *
	 * @param string $route e.g. "/wc/v3/orders/12/refunds".
	 * @return array{0:string,1:string}|null null when the route is not a WooCommerce route.
	 */
	public static function resolve( $route ) {
		$route = '/' . trim( (string) $route, '/' );
		if ( preg_match( '#^/wc/v\d+/([^/]+)#', $route, $m ) ) {
			$seg = $m[1];
			if ( isset( self::$aliases[ $seg ] ) ) {
				return array( 'wc', self::$aliases[ $seg ] );
			}
			// /wc/v3/products/categories, /wc/v3/products/attributes/… are "products".
			return array( 'wc', 'other' );
		}
		if ( preg_match( '#^/wc-analytics/#', $route ) ) {
			return array( 'wc-analytics', 'analytics' );
		}
		if ( preg_match( '#^/wc-admin/#', $route ) ) {
			return array( 'wc-admin', 'admin' );
		}
		if ( preg_match( '#^/wc/store/#', $route ) || preg_match( '#^/wc/v\d+$#', $route ) || '/wc' === $route ) {
			return null; // Store API (public) and index routes are not key-scoped.
		}
		return null;
	}

	/**
	 * HTTP method → read|write.
	 *
	 * @param string $method Method.
	 * @return string
	 */
	public static function action_for_method( $method ) {
		return in_array( strtoupper( (string) $method ), array( 'GET', 'HEAD', 'OPTIONS' ), true ) ? 'read' : 'write';
	}

	/**
	 * Normalise a list of scopes: lowercase, trimmed, valid only, de-duplicated.
	 *
	 * @param string[]|string $scopes List or comma/space separated string.
	 * @return string[]
	 */
	public static function normalize( $scopes ) {
		if ( is_string( $scopes ) ) {
			$scopes = preg_split( '/[\s,]+/', $scopes );
		}
		$valid = array_keys( self::resources() );
		$out   = array();
		foreach ( (array) $scopes as $s ) {
			$s = strtolower( trim( (string) $s ) );
			if ( ! preg_match( '/^([a-z_*]+):(read|write)$/', $s, $m ) ) {
				continue;
			}
			if ( '*' !== $m[1] && ! in_array( $m[1], $valid, true ) ) {
				continue;
			}
			$out[ $s ] = $s;
		}
		return array_values( $out );
	}

	/**
	 * Does the scope list allow (resource, action)?
	 *
	 * @param string[] $scopes   Normalised scopes.
	 * @param string   $resource Resource bucket.
	 * @param string   $action   read|write.
	 * @return bool
	 */
	public static function allows( array $scopes, $resource, $action ) {
		$resource = (string) $resource;
		$action   = 'write' === $action ? 'write' : 'read';
		foreach ( $scopes as $s ) {
			list( $r, $a ) = explode( ':', $s, 2 );
			if ( '*' !== $r && $r !== $resource ) {
				continue;
			}
			if ( 'write' === $a || $a === $action ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * IP allowlist check. Accepts single IPs, CIDR (v4 and v6) and "*".
	 *
	 * @param string          $ip        Client IP.
	 * @param string[]|string $allowlist Entries.
	 * @return bool True when allowed (an empty allowlist allows everything).
	 */
	public static function ip_allowed( $ip, $allowlist ) {
		if ( is_string( $allowlist ) ) {
			$allowlist = preg_split( '/[\s,]+/', $allowlist );
		}
		$allowlist = array_filter( array_map( 'trim', (array) $allowlist ) );
		if ( ! $allowlist ) {
			return true;
		}
		$ip = trim( (string) $ip );
		if ( '' === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		foreach ( $allowlist as $entry ) {
			if ( '*' === $entry ) {
				return true;
			}
			if ( false === strpos( $entry, '/' ) ) {
				if ( inet_pton( $entry ) === inet_pton( $ip ) ) {
					return true;
				}
				continue;
			}
			if ( self::cidr_match( $ip, $entry ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * CIDR match for v4 and v6.
	 *
	 * @param string $ip   IP.
	 * @param string $cidr "10.0.0.0/8" or "2001:db8::/32".
	 * @return bool
	 */
	public static function cidr_match( $ip, $cidr ) {
		list( $subnet, $bits ) = array_pad( explode( '/', $cidr, 2 ), 2, null );
		$ip_bin                = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$net_bin               = @inet_pton( (string) $subnet ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $ip_bin || false === $net_bin || strlen( $ip_bin ) !== strlen( $net_bin ) || null === $bits ) {
			return false;
		}
		$bits = (int) $bits;
		$max  = strlen( $ip_bin ) * 8;
		if ( $bits < 0 || $bits > $max ) {
			return false;
		}
		$full_bytes = intdiv( $bits, 8 );
		$rem_bits   = $bits % 8;
		if ( $full_bytes && substr( $ip_bin, 0, $full_bytes ) !== substr( $net_bin, 0, $full_bytes ) ) {
			return false;
		}
		if ( $rem_bits ) {
			$mask = ( 0xFF << ( 8 - $rem_bits ) ) & 0xFF;
			if ( ( ord( $ip_bin[ $full_bytes ] ) & $mask ) !== ( ord( $net_bin[ $full_bytes ] ) & $mask ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Fixed-window rate limit decision, pure: given hits in the current window.
	 *
	 * @param int $hits  Hits already counted in this window (before this request).
	 * @param int $limit Limit per window (0 = unlimited).
	 * @return bool True when this request is allowed.
	 */
	public static function within_rate( $hits, $limit ) {
		$limit = (int) $limit;
		return $limit <= 0 || (int) $hits < $limit;
	}
}
