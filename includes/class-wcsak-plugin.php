<?php
/**
 * Bootstrap.
 *
 * @package ScopedApiKeys
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires everything once WooCommerce is loaded.
 */
class WCSAK_Plugin {

	const CRON_PURGE = 'wcsak_purge_usage';

	/**
	 * Singleton.
	 *
	 * @var WCSAK_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Instance.
	 *
	 * @return WCSAK_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_api_hash' ) ) {
			add_action( 'admin_notices', array( $this, 'missing_woocommerce_notice' ) );
			return;
		}

		load_plugin_textdomain( 'scoped-api-keys-for-woocommerce', false, dirname( plugin_basename( WCSAK_FILE ) ) . '/languages' );

		if ( get_option( 'wcsak_db_version' ) !== WCSAK_VERSION ) {
			WCSAK_Store::install();
		}

		WCSAK_Enforcer::init();

		add_action( self::CRON_PURGE, array( $this, 'purge' ) );
		if ( ! wp_next_scheduled( self::CRON_PURGE ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_PURGE );
		}

		// Clean up when WooCommerce deletes a key.
		add_action( 'woocommerce_api_key_deleted', array( 'WCSAK_Store', 'delete_policy' ) );

		if ( is_admin() ) {
			require_once WCSAK_DIR . 'admin/class-wcsak-admin.php';
			WCSAK_Admin::init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once WCSAK_DIR . 'includes/class-wcsak-cli.php';
			WP_CLI::add_command( 'wc scoped-keys', 'WCSAK_CLI' );
		}
	}

	/**
	 * Notice.
	 */
	public function missing_woocommerce_notice() {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Scoped API Keys for WooCommerce needs WooCommerce 8.0 or newer to be active.', 'scoped-api-keys-for-woocommerce' ) . '</p></div>';
	}

	/**
	 * Daily purge.
	 */
	public function purge() {
		WCSAK_Store::purge( (int) WCSAK_Settings::get( 'retention_days' ) );
	}

	/**
	 * Activation.
	 */
	public static function activate() {
		WCSAK_Store::install();
	}

	/**
	 * Deactivation.
	 */
	public static function deactivate() {
		$ts = wp_next_scheduled( self::CRON_PURGE );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_PURGE );
		}
	}
}
