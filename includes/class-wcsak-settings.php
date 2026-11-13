<?php
/**
 * Settings.
 *
 * @package ScopedApiKeys
 */

defined( 'ABSPATH' ) || exit;

/**
 * One option row.
 */
class WCSAK_Settings {

	const OPTION = 'wcsak_settings';

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'mode'           => 'enforce',   // enforce | monitor.
			'ip_header'      => '',          // e.g. HTTP_CF_CONNECTING_IP, HTTP_X_FORWARDED_FOR.
			'log_unmanaged'  => 0,           // Log requests of keys that have no policy.
			'retention_days' => 30,
		);
	}

	/**
	 * All settings.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Sanitise.
	 *
	 * @param array $raw Raw.
	 * @return array
	 */
	public static function sanitize( $raw ) {
		$raw    = is_array( $raw ) ? $raw : array();
		$header = isset( $raw['ip_header'] ) ? strtoupper( preg_replace( '/[^A-Za-z0-9_]/', '', (string) $raw['ip_header'] ) ) : '';
		if ( $header && 0 !== strpos( $header, 'HTTP_' ) ) {
			$header = 'HTTP_' . str_replace( '-', '_', $header );
		}
		return array(
			'mode'           => isset( $raw['mode'] ) && 'monitor' === $raw['mode'] ? 'monitor' : 'enforce',
			'ip_header'      => $header,
			'log_unmanaged'  => empty( $raw['log_unmanaged'] ) ? 0 : 1,
			'retention_days' => max( 1, min( 365, isset( $raw['retention_days'] ) ? (int) $raw['retention_days'] : 30 ) ),
		);
	}
}
