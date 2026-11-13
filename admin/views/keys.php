<?php
/**
 * Keys tab.
 *
 * @package ScopedApiKeys
 */

defined( 'ABSPATH' ) || exit;

$wcsak_keys = WCSAK_Store::keys_with_policies();
?>
<p class="description" style="margin-top:12px">
	<?php esc_html_e( 'Keys are created in WooCommerce → Settings → Advanced → REST API. A key without a policy keeps WooCommerce\'s behaviour (read, write or read/write on everything). Add a policy to limit it.', 'scoped-api-keys-for-woocommerce' ); ?>
</p>
<table class="widefat striped">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Key', 'scoped-api-keys-for-woocommerce' ); ?></th>
			<th><?php esc_html_e( 'User', 'scoped-api-keys-for-woocommerce' ); ?></th>
			<th><?php esc_html_e( 'WooCommerce permission', 'scoped-api-keys-for-woocommerce' ); ?></th>
			<th><?php esc_html_e( 'Scopes', 'scoped-api-keys-for-woocommerce' ); ?></th>
			<th><?php esc_html_e( 'IP allowlist', 'scoped-api-keys-for-woocommerce' ); ?></th>
			<th><?php esc_html_e( 'Expires', 'scoped-api-keys-for-woocommerce' ); ?></th>
			<th><?php esc_html_e( 'Rate / min', 'scoped-api-keys-for-woocommerce' ); ?></th>
			<th><?php esc_html_e( 'Blocked', 'scoped-api-keys-for-woocommerce' ); ?></th>
			<th><?php esc_html_e( 'Last access', 'scoped-api-keys-for-woocommerce' ); ?></th>
			<th></th>
		</tr>
	</thead>
	<tbody>
	<?php if ( ! $wcsak_keys ) : ?>
		<tr><td colspan="10"><?php esc_html_e( 'No REST API key yet.', 'scoped-api-keys-for-woocommerce' ); ?></td></tr>
	<?php endif; ?>
	<?php
	foreach ( $wcsak_keys as $wcsak_k ) :
		$wcsak_user    = get_user_by( 'id', (int) $wcsak_k->user_id );
		$wcsak_managed = null !== $wcsak_k->scopes;
		$wcsak_expired = $wcsak_k->expires_at && strtotime( $wcsak_k->expires_at . ' UTC' ) < time();
		?>
		<tr>
			<td><strong><?php echo esc_html( $wcsak_k->description ); ?></strong><br><code><?php echo esc_html( $wcsak_k->truncated_key ); ?></code> <small>#<?php echo (int) $wcsak_k->key_id; ?></small></td>
			<td><?php echo $wcsak_user ? esc_html( $wcsak_user->user_login ) : '—'; ?></td>
			<td><code><?php echo esc_html( $wcsak_k->permissions ); ?></code></td>
			<td>
				<?php if ( ! $wcsak_managed ) : ?>
					<span style="color:#b32d2e"><?php esc_html_e( 'unrestricted', 'scoped-api-keys-for-woocommerce' ); ?></span>
				<?php elseif ( ! (int) $wcsak_k->enabled ) : ?>
					<span style="color:#b32d2e"><strong><?php esc_html_e( 'disabled', 'scoped-api-keys-for-woocommerce' ); ?></strong></span>
				<?php elseif ( '' === (string) $wcsak_k->scopes ) : ?>
					<?php esc_html_e( 'all resources', 'scoped-api-keys-for-woocommerce' ); ?>
				<?php else : ?>
					<?php foreach ( explode( ',', $wcsak_k->scopes ) as $wcsak_s ) : ?>
						<code><?php echo esc_html( $wcsak_s ); ?></code>
					<?php endforeach; ?>
				<?php endif; ?>
			</td>
			<td><small><?php echo esc_html( (string) $wcsak_k->ip_allowlist ? str_replace( ',', ', ', $wcsak_k->ip_allowlist ) : '—' ); ?></small></td>
			<td><?php echo $wcsak_k->expires_at ? ( $wcsak_expired ? '<span style="color:#b32d2e">' . esc_html( substr( $wcsak_k->expires_at, 0, 10 ) ) . '</span>' : esc_html( substr( $wcsak_k->expires_at, 0, 10 ) ) ) : '—'; ?></td>
			<td><?php echo (int) $wcsak_k->rate_limit ? (int) $wcsak_k->rate_limit : '—'; ?></td>
			<td><?php echo (int) $wcsak_k->blocked_count; ?><?php echo $wcsak_k->last_blocked_reason ? ' <small>(' . esc_html( $wcsak_k->last_blocked_reason ) . ')</small>' : ''; ?></td>
			<td><small><?php echo esc_html( $wcsak_k->last_access ? $wcsak_k->last_access : __( 'never', 'scoped-api-keys-for-woocommerce' ) ); ?></small></td>
			<td><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wcsak&tab=edit&key_id=' . (int) $wcsak_k->key_id ) ); ?>"><?php echo $wcsak_managed ? esc_html__( 'Edit policy', 'scoped-api-keys-for-woocommerce' ) : esc_html__( 'Add policy', 'scoped-api-keys-for-woocommerce' ); ?></a></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
