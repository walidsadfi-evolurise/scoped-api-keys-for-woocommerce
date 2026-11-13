<?php
/**
 * Plugin Name:       Scoped API Keys for WooCommerce
 * Plugin URI:        https://github.com/walidsadfi-evolurise/scoped-api-keys-for-woocommerce
 * Description:       Give each WooCommerce REST API key a scope (orders, products, customers… read or write), an IP allowlist, an expiry date, a rate limit and a usage log. Made for integrations, agencies and AI agents that should not hold the keys to the whole store.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Walid Sadfi
 * Author URI:        https://walidsadfi.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       scoped-api-keys-for-woocommerce
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:   11.4
 *
 * @package ScopedApiKeys
 */

defined( 'ABSPATH' ) || exit;

define( 'WCSAK_VERSION', '1.0.0' );
define( 'WCSAK_FILE', __FILE__ );
define( 'WCSAK_DIR', plugin_dir_path( __FILE__ ) );

require_once WCSAK_DIR . 'includes/class-wcsak-scope.php';
require_once WCSAK_DIR . 'includes/class-wcsak-settings.php';
require_once WCSAK_DIR . 'includes/class-wcsak-store.php';
require_once WCSAK_DIR . 'includes/class-wcsak-enforcer.php';
require_once WCSAK_DIR . 'includes/class-wcsak-plugin.php';

register_activation_hook( __FILE__, array( 'WCSAK_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WCSAK_Plugin', 'deactivate' ) );

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', array( 'WCSAK_Plugin', 'instance' ), 20 );
