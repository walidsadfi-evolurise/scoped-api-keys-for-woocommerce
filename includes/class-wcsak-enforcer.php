<?php
/**
 * Request-time enforcement.
 *
 * @package ScopedApiKeys
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs after WooCommerce authenticated the consumer key, before the controller.
 */
class WCSAK_Enforcer {

	/**
	 * Key row for the current request (null = not a keyed request).
	 *
	 * @var object|null
	 */
	private static $key = null;

	/**
	 * Resolved (resource, action) for the current request.
	 *
	 * @var array|null
	 */
	private static $target = null;

	/**
	 * Outcome recorded for logging.
	 *
	 * @var string
	 */
	private static $outcome = 'allowed';

	/**
	 * Register.
	 */
	public static function init() {
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'enforce' ), 20, 3 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'log' ), 60, 3 );
	}

	/**
	 * Which consumer key does this request carry? Same three sources WooCommerce reads.
	 *
	 * @return string
	 */
	public static function detect_consumer_key() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
		if ( ! empty( $_GET['consumer_key'] ) ) {
			return (string) wp_unslash( $_GET['consumer_key'] );
		}
		if ( ! empty( $_SERVER['PHP_AUTH_USER'] ) ) {
			return (string) wp_unslash( $_SERVER['PHP_AUTH_USER'] );
		}
		$header = '';
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = (string) wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] );
		} elseif ( function_exists( 'getallheaders' ) ) {
			foreach ( getallheaders() as $k => $v ) {
				if ( 'authorization' === strtolower( $k ) ) {
					$header = (string) $v;
				}
			}
		}
		if ( $header && preg_match( '/oauth_consumer_key="([^"]+)"/', $header, $m ) ) {
			return rawurldecode( $m[1] );
		}
		if ( ! empty( $_GET['oauth_consumer_key'] ) ) {
			return (string) wp_unslash( $_GET['oauth_consumer_key'] );
		}
		if ( ! empty( $_POST['oauth_consumer_key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- OAuth 1.0a consumer key, authenticated by WooCommerce before this runs.
			return (string) wp_unslash( $_POST['oauth_consumer_key'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		// phpcs:enable
		return '';
	}

	/**
	 * Client IP, honouring a trusted proxy header only when configured.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$header = WCSAK_Settings::get( 'ip_header' );
		if ( $header && ! empty( $_SERVER[ $header ] ) ) {
			$v = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
			return trim( explode( ',', $v )[0] );
		}
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * Is this the request the client actually sent, as opposed to an internal rest_do_request()?
	 *
	 * WordPress and WooCommerce Admin dispatch nested REST requests from server-side code (for
	 * example analytics indicators while routes register). Those are not something the API client
	 * asked for, so the policy applies to the top-level request only. Sub-requests of the core
	 * batch endpoint are the exception: there the client did ask for each of them.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function is_primary_request( $request ) {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' === $uri ) {
			return true; // No HTTP context (CLI, cron): nothing to compare against.
		}
		$route = untrailingslashit( $request->get_route() );
		$path  = untrailingslashit( (string) wp_parse_url( $uri, PHP_URL_PATH ) );
		if ( '' !== $route && substr( $path, -strlen( $route ) ) === $route ) {
			return true;
		}
		$query = array();
		wp_parse_str( (string) wp_parse_url( $uri, PHP_URL_QUERY ), $query );
		if ( isset( $query['rest_route'] ) && untrailingslashit( (string) $query['rest_route'] ) === $route ) {
			return true;
		}
		// Nested requests of /batch/v1 carry the client's own operations.
		if ( substr( $path, -9 ) === '/batch/v1' || ( isset( $query['rest_route'] ) && untrailingslashit( (string) $query['rest_route'] ) === '/batch/v1' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Decide.
	 *
	 * @param mixed           $result  Pre-dispatch result.
	 * @param WP_REST_Server  $server  Server.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function enforce( $result, $server, $request ) {
		if ( ! self::is_primary_request( $request ) ) {
			return $result; // Internal sub-request made by server-side code: the client's own request was already checked.
		}
		self::$key     = null;
		self::$target  = null;
		self::$outcome = 'allowed';

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$target = WCSAK_Scope::resolve( $request->get_route() );
		if ( ! $target ) {
			return $result;
		}

		$consumer_key = self::detect_consumer_key();
		if ( '' === $consumer_key ) {
			return $result; // Cookie/nonce or application-password request: not ours.
		}

		$key = WCSAK_Store::key_by_consumer_key( $consumer_key );
		if ( ! $key || get_current_user_id() !== (int) $key->user_id ) {
			return $result; // WooCommerce did not authenticate this key; it will answer 401 itself.
		}

		self::$key    = $key;
		self::$target = array(
			'resource' => $target[1],
			'action'   => WCSAK_Scope::action_for_method( $request->get_method() ),
		);

		$policy = WCSAK_Store::get_policy( (int) $key->key_id );
		if ( ! $policy ) {
			return $result; // No policy saved: WooCommerce's own read/write permission is the only limit.
		}

		$denied = self::check( $policy, self::$target['resource'], self::$target['action'] );
		if ( ! $denied ) {
			return $result;
		}

		list( $reason, $message, $status ) = $denied;
		$monitor                           = 'monitor' === WCSAK_Settings::get( 'mode' );
		self::$outcome                     = $monitor ? 'would_block:' . $reason : 'blocked:' . $reason;
		WCSAK_Store::record_block( (int) $key->key_id, $reason );

		/**
		 * Fires when a request violates a key policy (whether blocked or only logged).
		 *
		 * @param object          $key     Key row.
		 * @param string          $reason  scope | ip | expired | disabled | rate_limit.
		 * @param WP_REST_Request $request Request.
		 * @param bool            $blocked Blocked (true) or monitor mode (false).
		 */
		do_action( 'wcsak_violation', $key, $reason, $request, ! $monitor );

		if ( $monitor ) {
			return $result;
		}

		return new WP_Error(
			'wcsak_' . $reason,
			$message,
			array(
				'status'   => $status,
				'resource' => self::$target['resource'],
				'action'   => self::$target['action'],
			)
		);
	}

	/**
	 * Evaluate a policy. Order: disabled, expired, IP, rate limit, scope.
	 *
	 * @param object $policy   Policy row.
	 * @param string $bucket   Resource bucket.
	 * @param string $action   Action.
	 * @return array|null [reason, message, http status] or null when allowed.
	 */
	public static function check( $policy, $bucket, $action ) {
		if ( ! (int) $policy->enabled ) {
			return array( 'disabled', __( 'This API key is disabled.', 'scoped-api-keys-for-woocommerce' ), 403 );
		}
		if ( $policy->expires_at && strtotime( $policy->expires_at . ' UTC' ) < time() ) {
			return array( 'expired', __( 'This API key has expired.', 'scoped-api-keys-for-woocommerce' ), 403 );
		}
		if ( ! WCSAK_Scope::ip_allowed( self::client_ip(), (string) $policy->ip_allowlist ) ) {
			return array( 'ip', __( 'This API key cannot be used from this IP address.', 'scoped-api-keys-for-woocommerce' ), 403 );
		}
		if ( (int) $policy->rate_limit > 0 ) {
			$hits = WCSAK_Store::bump_rate_counter( (int) $policy->key_id );
			if ( ! WCSAK_Scope::within_rate( $hits, (int) $policy->rate_limit ) ) {
				return array( 'rate_limit', __( 'Rate limit exceeded for this API key. Try again in a minute.', 'scoped-api-keys-for-woocommerce' ), 429 );
			}
		}
		$scopes = WCSAK_Scope::normalize( (string) $policy->scopes );
		if ( $scopes && ! WCSAK_Scope::allows( $scopes, $bucket, $action ) ) {
			return array(
				'scope',
				sprintf(
					/* translators: 1: action, 2: resource */
					__( 'This API key is not allowed to %1$s %2$s.', 'scoped-api-keys-for-woocommerce' ),
					$action,
					$bucket
				),
				403,
			);
		}
		return null;
	}

	/**
	 * Usage log.
	 *
	 * @param WP_HTTP_Response $response Response.
	 * @param WP_REST_Server   $server   Server.
	 * @param WP_REST_Request  $request  Request.
	 * @return WP_HTTP_Response
	 */
	public static function log( $response, $server, $request ) {
		if ( ! self::$key || ! self::$target ) {
			return $response;
		}
		$policy = WCSAK_Store::get_policy( (int) self::$key->key_id );
		$want   = $policy ? (int) $policy->log_usage : (int) WCSAK_Settings::get( 'log_unmanaged' );
		if ( ! $want ) {
			return $response;
		}
		WCSAK_Store::log(
			array(
				'key_id'     => (int) self::$key->key_id,
				'route'      => mb_substr( $request->get_route(), 0, 191 ),
				'method'     => $request->get_method(),
				'resource'   => self::$target['resource'],
				'action'     => self::$target['action'],
				'status'     => (int) $response->get_status(),
				'outcome'    => mb_substr( self::$outcome, 0, 20 ),
				'ip'         => mb_substr( self::client_ip(), 0, 45 ),
				'user_agent' => mb_substr( isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '', 0, 191 ),
			)
		);
		return $response;
	}
}
