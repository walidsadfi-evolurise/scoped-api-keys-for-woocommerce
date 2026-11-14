<?php
/**
 * Integration bootstrap: WordPress test suite + WooCommerce + this plugin.
 *
 * WP_TESTS_DIR must point to the wordpress-develop tests/phpunit directory (see bin/install-wp-tests.sh).
 *
 * @package ScopedApiKeys
 */

$wcsak_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $wcsak_tests_dir ) {
	$wcsak_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}
if ( ! file_exists( $wcsak_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find {$wcsak_tests_dir}/includes/functions.php, run bin/install-wp-tests.sh first.\n";
	exit( 1 );
}

require_once $wcsak_tests_dir . '/includes/functions.php';

// Let the WP bootstrap find the Polyfills when they are not on the Composer autoloader.
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) && getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) );
}

tests_add_filter(
	'muplugins_loaded',
	function () {
		$wc = getenv( 'WC_PLUGIN_FILE' );
		if ( ! $wc ) {
			$wc = dirname( __DIR__, 2 ) . '/vendor/woocommerce/woocommerce/woocommerce.php';
		}
		if ( ! file_exists( $wc ) ) {
			$wc = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		}
		require_once $wc;
		require_once dirname( __DIR__, 2 ) . '/scoped-api-keys-for-woocommerce.php';
	}
);

tests_add_filter(
	'setup_theme',
	function () {
		// Install WooCommerce tables and defaults.
		if ( class_exists( 'WC_Install' ) ) {
			WC_Install::install();
		}
		WCSAK_Store::install();
		$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_roles();
	}
);

require $wcsak_tests_dir . '/includes/bootstrap.php';
