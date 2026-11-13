<?php
/**
 * Storage: per-key policy and usage log.
 *
 * @package ScopedApiKeys
 */

defined( 'ABSPATH' ) || exit;

/**
 * Two tables: policies (one row per WooCommerce API key) and usage (one row per request, optional).
 */
class WCSAK_Store {

	/**
	 * Policies table.
	 *
	 * @return string
	 */
	public static function policies_table() {
		global $wpdb;
		return $wpdb->prefix . 'wcsak_policies';
	}

	/**
	 * Usage table.
	 *
	 * @return string
	 */
	public static function usage_table() {
		global $wpdb;
		return $wpdb->prefix . 'wcsak_usage';
	}

	/**
	 * Create/upgrade tables.
	 */
	public static function install() {
		global $wpdb;
		$charset  = $wpdb->get_charset_collate();
		$policies = self::policies_table();
		$usage    = self::usage_table();

		$sql = "CREATE TABLE {$policies} (
			key_id BIGINT UNSIGNED NOT NULL,
			enabled TINYINT(1) NOT NULL DEFAULT 1,
			scopes TEXT NULL,
			ip_allowlist TEXT NULL,
			expires_at DATETIME NULL,
			rate_limit INT UNSIGNED NOT NULL DEFAULT 0,
			log_usage TINYINT(1) NOT NULL DEFAULT 1,
			note VARCHAR(255) NOT NULL DEFAULT '',
			blocked_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
			last_blocked_at DATETIME NULL,
			last_blocked_reason VARCHAR(40) NOT NULL DEFAULT '',
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (key_id)
		) {$charset};
		CREATE TABLE {$usage} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			key_id BIGINT UNSIGNED NOT NULL,
			route VARCHAR(191) NOT NULL DEFAULT '',
			method VARCHAR(10) NOT NULL DEFAULT '',
			resource VARCHAR(40) NOT NULL DEFAULT '',
			action VARCHAR(10) NOT NULL DEFAULT '',
			status SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			outcome VARCHAR(20) NOT NULL DEFAULT 'allowed',
			ip VARCHAR(45) NOT NULL DEFAULT '',
			user_agent VARCHAR(191) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY key_created (key_id, created_at),
			KEY outcome_created (outcome, created_at),
			KEY created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( 'wcsak_db_version', WCSAK_VERSION, false );
	}

	/**
	 * Policy for a key (null when none was ever saved → unrestricted).
	 *
	 * @param int $key_id Key ID.
	 * @return object|null
	 */
	public static function get_policy( $key_id ) {
		global $wpdb;
		$cache_key = 'wcsak_policy_' . (int) $key_id;
		$row       = wp_cache_get( $cache_key, 'wcsak' );
		if ( false !== $row ) {
			return $row ? $row : null;
		}
		$table = self::policies_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE key_id = %d", (int) $key_id ) ); // phpcs:ignore WordPress.DB
		wp_cache_set( $cache_key, $row ? $row : 0, 'wcsak', 300 );
		return $row ? $row : null;
	}

	/**
	 * Save a policy.
	 *
	 * @param int   $key_id Key ID.
	 * @param array $data   Columns.
	 * @return bool
	 */
	public static function save_policy( $key_id, array $data ) {
		global $wpdb;
		$row = array_merge(
			array(
				'key_id'     => (int) $key_id,
				'updated_at' => current_time( 'mysql', true ),
			),
			$data
		);
		$ok = $wpdb->replace( self::policies_table(), $row ); // phpcs:ignore WordPress.DB
		wp_cache_delete( 'wcsak_policy_' . (int) $key_id, 'wcsak' );
		return false !== $ok;
	}

	/**
	 * Delete a policy (key becomes unrestricted again).
	 *
	 * @param int $key_id Key ID.
	 */
	public static function delete_policy( $key_id ) {
		global $wpdb;
		$wpdb->delete( self::policies_table(), array( 'key_id' => (int) $key_id ) ); // phpcs:ignore WordPress.DB
		wp_cache_delete( 'wcsak_policy_' . (int) $key_id, 'wcsak' );
	}

	/**
	 * Count a block on the policy row.
	 *
	 * @param int    $key_id Key ID.
	 * @param string $reason Reason.
	 */
	public static function record_block( $key_id, $reason ) {
		global $wpdb;
		$table = self::policies_table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET blocked_count = blocked_count + 1, last_blocked_at = %s, last_blocked_reason = %s WHERE key_id = %d", current_time( 'mysql', true ), $reason, (int) $key_id ) ); // phpcs:ignore WordPress.DB
		wp_cache_delete( 'wcsak_policy_' . (int) $key_id, 'wcsak' );
	}

	/**
	 * All WooCommerce API keys joined with their policy.
	 *
	 * @return object[]
	 */
	public static function keys_with_policies() {
		global $wpdb;
		$keys     = $wpdb->prefix . 'woocommerce_api_keys';
		$policies = self::policies_table();
		return (array) $wpdb->get_results( "SELECT k.key_id, k.user_id, k.description, k.permissions, k.truncated_key, k.last_access, p.enabled, p.scopes, p.ip_allowlist, p.expires_at, p.rate_limit, p.log_usage, p.note, p.blocked_count, p.last_blocked_at, p.last_blocked_reason FROM {$keys} k LEFT JOIN {$policies} p ON p.key_id = k.key_id ORDER BY k.key_id DESC" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * One WooCommerce key row.
	 *
	 * @param int $key_id Key ID.
	 * @return object|null
	 */
	public static function wc_key( $key_id ) {
		global $wpdb;
		$keys = $wpdb->prefix . 'woocommerce_api_keys';
		return $wpdb->get_row( $wpdb->prepare( "SELECT key_id, user_id, description, permissions, truncated_key, last_access FROM {$keys} WHERE key_id = %d", (int) $key_id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Find the key row by consumer key (same hashing WooCommerce uses).
	 *
	 * @param string $consumer_key Raw consumer key.
	 * @return object|null
	 */
	public static function key_by_consumer_key( $consumer_key ) {
		global $wpdb;
		$keys = $wpdb->prefix . 'woocommerce_api_keys';
		return $wpdb->get_row( $wpdb->prepare( "SELECT key_id, user_id, permissions FROM {$keys} WHERE consumer_key = %s", wc_api_hash( sanitize_text_field( $consumer_key ) ) ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Log a request.
	 *
	 * @param array $data Columns.
	 */
	public static function log( array $data ) {
		global $wpdb;
		$wpdb->insert( self::usage_table(), array_merge( array( 'created_at' => current_time( 'mysql', true ) ), $data ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Usage query.
	 *
	 * @param array $args key_id, outcome, per_page, page.
	 * @return array{items: object[], total: int}
	 */
	public static function usage( array $args ) {
		global $wpdb;
		$table  = self::usage_table();
		$args   = array_merge( array( 'key_id' => 0, 'outcome' => '', 'per_page' => 50, 'page' => 1 ), $args );
		$where  = array( '1=1' );
		$params = array();
		if ( $args['key_id'] ) {
			$where[]  = 'key_id = %d';
			$params[] = (int) $args['key_id'];
		}
		if ( '' !== $args['outcome'] ) {
			$where[]  = 'outcome = %s';
			$params[] = $args['outcome'];
		}
		$where_sql = implode( ' AND ', $where );
		$limit     = max( 1, (int) $args['per_page'] );
		$offset    = max( 0, ( (int) $args['page'] - 1 ) * $limit );
		// phpcs:disable WordPress.DB
		$total = $params ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params ) ) : (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}" );
		$items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $limit, $offset ) ) ) );
		// phpcs:enable
		return array(
			'items' => $items ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Hits for a key in the current minute (fixed window) via a transient counter.
	 *
	 * @param int $key_id Key ID.
	 * @return int Hits before this one.
	 */
	public static function bump_rate_counter( $key_id ) {
		$window = (int) floor( time() / 60 );
		$name   = 'wcsak_rate_' . (int) $key_id . '_' . $window;
		$hits   = (int) get_transient( $name );
		set_transient( $name, $hits + 1, 120 );
		return $hits;
	}

	/**
	 * Purge usage rows.
	 *
	 * @param int $days Retention.
	 * @return int
	 */
	public static function purge( $days ) {
		global $wpdb;
		$table  = self::usage_table();
		$before = gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) $days ) * DAY_IN_SECONDS );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $before ) ); // phpcs:ignore WordPress.DB
	}
}
