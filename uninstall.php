<?php
/**
 * Remove everything the plugin stored.
 *
 * @package ScopedApiKeys
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
$wcsak_p = $wpdb->prefix . 'wcsak_policies';
$wcsak_u = $wpdb->prefix . 'wcsak_usage';
$wpdb->query( "DROP TABLE IF EXISTS {$wcsak_p}" ); // phpcs:ignore WordPress.DB
$wpdb->query( "DROP TABLE IF EXISTS {$wcsak_u}" ); // phpcs:ignore WordPress.DB
delete_option( 'wcsak_settings' );
delete_option( 'wcsak_db_version' );
$wcsak_t = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_wcsak_rate_%' OR option_name LIKE '_transient_timeout_wcsak_rate_%'" ); // phpcs:ignore WordPress.DB
foreach ( (array) $wcsak_t as $wcsak_name ) {
	delete_option( $wcsak_name );
}
wp_clear_scheduled_hook( 'wcsak_purge_usage' );
