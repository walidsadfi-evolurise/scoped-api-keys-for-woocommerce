<?php
/**
 * Admin screens.
 *
 * @package ScopedApiKeys
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce → Scoped API Keys.
 */
class WCSAK_Admin {

	/**
	 * Register.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_wcsak_save_policy', array( __CLASS__, 'save_policy' ) );
		add_action( 'admin_post_wcsak_delete_policy', array( __CLASS__, 'delete_policy' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WCSAK_FILE ), array( __CLASS__, 'plugin_links' ) );
		add_action( 'admin_notices', array( __CLASS__, 'unscoped_keys_notice' ) );
	}

	/**
	 * Menu.
	 */
	public static function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Scoped API Keys', 'scoped-api-keys-for-woocommerce' ),
			__( 'Scoped API Keys', 'scoped-api-keys-for-woocommerce' ),
			'manage_woocommerce',
			'wcsak',
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Plugins screen link.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function plugin_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=wcsak' ) ) . '">' . esc_html__( 'Keys', 'scoped-api-keys-for-woocommerce' ) . '</a>' );
		return $links;
	}

	/**
	 * Settings API.
	 */
	public static function register_settings() {
		register_setting(
			'wcsak_settings_group',
			WCSAK_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'WCSAK_Settings', 'sanitize' ),
				'default'           => WCSAK_Settings::defaults(),
			)
		);
	}

	/**
	 * On the WooCommerce REST API keys screen, point to this plugin.
	 */
	public static function unscoped_keys_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'], $_GET['tab'], $_GET['section'] ) || 'wc-settings' !== $_GET['page'] || 'advanced' !== $_GET['tab'] || 'keys' !== $_GET['section'] ) {
			return;
		}
		// phpcs:enable
		$keys    = WCSAK_Store::keys_with_policies();
		$unscoped = 0;
		foreach ( $keys as $k ) {
			if ( null === $k->scopes ) {
				$unscoped++;
			}
		}
		if ( ! $unscoped ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %d: count */
					_n( '%d API key has full read/write access to the whole store.', '%d API keys have full read/write access to the whole store.', $unscoped, 'scoped-api-keys-for-woocommerce' ),
					$unscoped
				)
			),
			esc_url( admin_url( 'admin.php?page=wcsak' ) ),
			esc_html__( 'Limit what each key can do →', 'scoped-api-keys-for-woocommerce' )
		);
	}

	/**
	 * Save policy from the edit form.
	 */
	public static function save_policy() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'scoped-api-keys-for-woocommerce' ) );
		}
		check_admin_referer( 'wcsak_save_policy' );

		$key_id = isset( $_POST['key_id'] ) ? (int) $_POST['key_id'] : 0;
		if ( ! WCSAK_Store::wc_key( $key_id ) ) {
			wp_die( esc_html__( 'Unknown key.', 'scoped-api-keys-for-woocommerce' ) );
		}

		$scopes = array();
		if ( ! empty( $_POST['scope'] ) && is_array( $_POST['scope'] ) ) {
			foreach ( wp_unslash( $_POST['scope'] ) as $resource => $actions ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				foreach ( (array) $actions as $action => $on ) {
					$scopes[] = sanitize_key( $resource ) . ':' . sanitize_key( $action );
				}
			}
		}
		$expires = isset( $_POST['expires_at'] ) ? sanitize_text_field( wp_unslash( $_POST['expires_at'] ) ) : '';
		$expires = $expires ? gmdate( 'Y-m-d 23:59:59', strtotime( $expires ) ) : null;

		WCSAK_Store::save_policy(
			$key_id,
			array(
				'enabled'      => empty( $_POST['enabled'] ) ? 0 : 1,
				'scopes'       => implode( ',', WCSAK_Scope::normalize( $scopes ) ),
				'ip_allowlist' => isset( $_POST['ip_allowlist'] ) ? implode( ',', array_filter( array_map( 'trim', preg_split( '/[\s,]+/', sanitize_textarea_field( wp_unslash( $_POST['ip_allowlist'] ) ) ) ) ) ) : '',
				'expires_at'   => $expires,
				'rate_limit'   => isset( $_POST['rate_limit'] ) ? max( 0, (int) $_POST['rate_limit'] ) : 0,
				'log_usage'    => empty( $_POST['log_usage'] ) ? 0 : 1,
				'note'         => isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '',
			)
		);

		wp_safe_redirect( admin_url( 'admin.php?page=wcsak&saved=1' ) );
		exit;
	}

	/**
	 * Remove a policy.
	 */
	public static function delete_policy() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'scoped-api-keys-for-woocommerce' ) );
		}
		check_admin_referer( 'wcsak_delete_policy' );
		WCSAK_Store::delete_policy( isset( $_POST['key_id'] ) ? (int) $_POST['key_id'] : 0 );
		wp_safe_redirect( admin_url( 'admin.php?page=wcsak&deleted=1' ) );
		exit;
	}

	/**
	 * Page.
	 */
	public static function render() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'keys';
		echo '<div class="wrap"><h1>' . esc_html__( 'Scoped API Keys', 'scoped-api-keys-for-woocommerce' ) . '</h1>';
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Policy saved.', 'scoped-api-keys-for-woocommerce' ) . '</p></div>';
		}
		if ( isset( $_GET['deleted'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Policy removed; the key is back to WooCommerce\'s read/write permission only.', 'scoped-api-keys-for-woocommerce' ) . '</p></div>';
		}
		if ( 'monitor' === WCSAK_Settings::get( 'mode' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Monitor mode: violations are logged but nothing is blocked. Switch to Enforce in Settings when the log looks right.', 'scoped-api-keys-for-woocommerce' ) . '</p></div>';
		}
		$tabs = array(
			'keys'     => __( 'Keys', 'scoped-api-keys-for-woocommerce' ),
			'usage'    => __( 'Usage log', 'scoped-api-keys-for-woocommerce' ),
			'settings' => __( 'Settings', 'scoped-api-keys-for-woocommerce' ),
		);
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $k => $label ) {
			printf( '<a class="nav-tab %s" href="%s">%s</a>', $tab === $k ? 'nav-tab-active' : '', esc_url( admin_url( 'admin.php?page=wcsak&tab=' . $k ) ), esc_html( $label ) );
		}
		echo '</nav>';
		switch ( $tab ) {
			case 'edit':
				include WCSAK_DIR . 'admin/views/edit.php';
				break;
			case 'usage':
				include WCSAK_DIR . 'admin/views/usage.php';
				break;
			case 'settings':
				include WCSAK_DIR . 'admin/views/settings.php';
				break;
			default:
				include WCSAK_DIR . 'admin/views/keys.php';
		}
		echo '</div>';
		// phpcs:enable
	}
}
