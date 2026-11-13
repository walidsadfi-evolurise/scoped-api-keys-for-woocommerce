<?php
/**
 * WP-CLI.
 *
 * @package ScopedApiKeys
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manage WooCommerce REST API key scopes.
 *
 * ## EXAMPLES
 *
 *     wp wc scoped-keys list
 *     wp wc scoped-keys set 12 --scopes=orders:read,products:read --ip=203.0.113.0/24 --expires=2027-01-31 --rate=120
 *     wp wc scoped-keys disable 12
 *     wp wc scoped-keys usage 12 --outcome=blocked
 */
class WCSAK_CLI {

	/**
	 * List keys and their policies.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json, csv, yaml. Default table.
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Named.
	 */
	public function list( $args, $assoc_args ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames
		$rows = array();
		foreach ( WCSAK_Store::keys_with_policies() as $k ) {
			$rows[] = array(
				'key_id'      => (int) $k->key_id,
				'description' => $k->description,
				'wc_perms'    => $k->permissions,
				'scopes'      => null === $k->scopes ? '(unrestricted)' : $k->scopes,
				'ip'          => (string) $k->ip_allowlist,
				'expires_at'  => (string) $k->expires_at,
				'rate'        => (int) $k->rate_limit,
				'enabled'     => null === $k->enabled ? '-' : ( $k->enabled ? 'yes' : 'no' ),
				'blocked'     => (int) $k->blocked_count,
				'last_access' => (string) $k->last_access,
			);
		}
		WP_CLI\Utils\format_items( isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table', $rows, array( 'key_id', 'description', 'wc_perms', 'scopes', 'ip', 'expires_at', 'rate', 'enabled', 'blocked', 'last_access' ) );
	}

	/**
	 * Set the policy of a key.
	 *
	 * ## OPTIONS
	 *
	 * <key_id>
	 * : WooCommerce API key ID (WooCommerce → Settings → Advanced → REST API).
	 *
	 * [--scopes=<list>]
	 * : Comma-separated, e.g. orders:read,products:write. Empty string = no scope restriction.
	 *
	 * [--ip=<list>]
	 * : Comma-separated IPs or CIDRs. Empty string = any IP.
	 *
	 * [--expires=<date>]
	 * : YYYY-MM-DD (UTC, end of day) or "never".
	 *
	 * [--rate=<n>]
	 * : Requests per minute, 0 = unlimited.
	 *
	 * [--note=<text>]
	 * : Free text.
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Named.
	 */
	public function set( $args, $assoc_args ) {
		$key_id = (int) $args[0];
		if ( ! WCSAK_Store::wc_key( $key_id ) ) {
			WP_CLI::error( 'No WooCommerce API key with that ID.' );
		}
		$policy = WCSAK_Store::get_policy( $key_id );
		$data   = array(
			'enabled'      => $policy ? (int) $policy->enabled : 1,
			'scopes'       => $policy ? $policy->scopes : '',
			'ip_allowlist' => $policy ? $policy->ip_allowlist : '',
			'expires_at'   => $policy ? $policy->expires_at : null,
			'rate_limit'   => $policy ? (int) $policy->rate_limit : 0,
			'log_usage'    => $policy ? (int) $policy->log_usage : 1,
			'note'         => $policy ? $policy->note : '',
		);
		if ( isset( $assoc_args['scopes'] ) ) {
			$data['scopes'] = implode( ',', WCSAK_Scope::normalize( $assoc_args['scopes'] ) );
		}
		if ( isset( $assoc_args['ip'] ) ) {
			$data['ip_allowlist'] = implode( ',', array_filter( array_map( 'trim', explode( ',', $assoc_args['ip'] ) ) ) );
		}
		if ( isset( $assoc_args['expires'] ) ) {
			$data['expires_at'] = 'never' === $assoc_args['expires'] ? null : gmdate( 'Y-m-d 23:59:59', strtotime( $assoc_args['expires'] ) );
		}
		if ( isset( $assoc_args['rate'] ) ) {
			$data['rate_limit'] = max( 0, (int) $assoc_args['rate'] );
		}
		if ( isset( $assoc_args['note'] ) ) {
			$data['note'] = sanitize_text_field( $assoc_args['note'] );
		}
		WCSAK_Store::save_policy( $key_id, $data );
		WP_CLI::success( sprintf( 'Policy saved for key %d: scopes=[%s] ip=[%s] expires=%s rate=%d', $key_id, $data['scopes'], $data['ip_allowlist'], $data['expires_at'] ? $data['expires_at'] : 'never', $data['rate_limit'] ) );
	}

	/**
	 * Disable a key (requests answer 403) without deleting it.
	 *
	 * ## OPTIONS
	 *
	 * <key_id>
	 * : Key ID.
	 *
	 * @param array $args Positional.
	 */
	public function disable( $args ) {
		$this->toggle( (int) $args[0], 0 );
	}

	/**
	 * Enable a key.
	 *
	 * ## OPTIONS
	 *
	 * <key_id>
	 * : Key ID.
	 *
	 * @param array $args Positional.
	 */
	public function enable( $args ) {
		$this->toggle( (int) $args[0], 1 );
	}

	/**
	 * Remove the policy of a key (back to WooCommerce's read/write only).
	 *
	 * ## OPTIONS
	 *
	 * <key_id>
	 * : Key ID.
	 *
	 * @param array $args Positional.
	 */
	public function unrestrict( $args ) {
		WCSAK_Store::delete_policy( (int) $args[0] );
		WP_CLI::success( 'Policy removed.' );
	}

	/**
	 * Usage log of a key.
	 *
	 * ## OPTIONS
	 *
	 * <key_id>
	 * : Key ID.
	 *
	 * [--outcome=<outcome>]
	 * : allowed, or blocked / would_block (prefix match).
	 *
	 * [--limit=<n>]
	 * : Default 50.
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Named.
	 */
	public function usage( $args, $assoc_args ) {
		$res  = WCSAK_Store::usage(
			array(
				'key_id'   => (int) $args[0],
				'per_page' => isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 50,
			)
		);
		$rows = array();
		foreach ( $res['items'] as $r ) {
			if ( ! empty( $assoc_args['outcome'] ) && 0 !== strpos( $r->outcome, $assoc_args['outcome'] ) ) {
				continue;
			}
			$rows[] = array(
				'when'    => $r->created_at,
				'method'  => $r->method,
				'route'   => $r->route,
				'status'  => (int) $r->status,
				'outcome' => $r->outcome,
				'ip'      => $r->ip,
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'when', 'method', 'route', 'status', 'outcome', 'ip' ) );
	}

	/**
	 * Flip enabled.
	 *
	 * @param int $key_id  Key.
	 * @param int $enabled 0|1.
	 */
	private function toggle( $key_id, $enabled ) {
		if ( ! WCSAK_Store::wc_key( $key_id ) ) {
			WP_CLI::error( 'No WooCommerce API key with that ID.' );
		}
		$policy = WCSAK_Store::get_policy( $key_id );
		$data   = $policy ? (array) $policy : array();
		unset( $data['key_id'], $data['updated_at'] );
		$data['enabled'] = $enabled;
		WCSAK_Store::save_policy( $key_id, $data );
		WP_CLI::success( $enabled ? 'Key enabled.' : 'Key disabled.' );
	}
}
