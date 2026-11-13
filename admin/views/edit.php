<?php
/**
 * Policy editor.
 *
 * @package ScopedApiKeys
 */

defined( 'ABSPATH' ) || exit;

$wcsak_key_id = isset( $_GET['key_id'] ) ? (int) $_GET['key_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$wcsak_key    = WCSAK_Store::wc_key( $wcsak_key_id );
if ( ! $wcsak_key ) {
	echo '<p>' . esc_html__( 'Unknown key.', 'scoped-api-keys-for-woocommerce' ) . '</p>';
	return;
}
$wcsak_policy = WCSAK_Store::get_policy( $wcsak_key_id );
$wcsak_scopes = $wcsak_policy ? WCSAK_Scope::normalize( (string) $wcsak_policy->scopes ) : array();
$wcsak_has    = function ( $resource, $action ) use ( $wcsak_scopes ) {
	return in_array( $resource . ':' . $action, $wcsak_scopes, true );
};
?>
<h2><?php echo esc_html( $wcsak_key->description ); ?> <code><?php echo esc_html( $wcsak_key->truncated_key ); ?></code></h2>
<p class="description">
	<?php
	echo esc_html(
		sprintf(
			/* translators: %s: permission */
			__( 'WooCommerce permission for this key: %s. Scopes below can only narrow it, never widen it.', 'scoped-api-keys-for-woocommerce' ),
			$wcsak_key->permissions
		)
	);
	?>
</p>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:900px">
	<?php wp_nonce_field( 'wcsak_save_policy' ); ?>
	<input type="hidden" name="action" value="wcsak_save_policy">
	<input type="hidden" name="key_id" value="<?php echo (int) $wcsak_key_id; ?>">

	<table class="form-table">
		<tr><th><?php esc_html_e( 'Enabled', 'scoped-api-keys-for-woocommerce' ); ?></th>
			<td><label><input type="checkbox" name="enabled" value="1" <?php checked( ! $wcsak_policy || (int) $wcsak_policy->enabled ); ?>> <?php esc_html_e( 'Uncheck to block every request with this key (403) without deleting it.', 'scoped-api-keys-for-woocommerce' ); ?></label></td></tr>
		<tr><th><?php esc_html_e( 'Scopes', 'scoped-api-keys-for-woocommerce' ); ?></th>
			<td>
				<p class="description" style="margin-bottom:8px"><?php esc_html_e( 'Leave everything unchecked to allow all resources (WooCommerce permission still applies). "Write" includes read.', 'scoped-api-keys-for-woocommerce' ); ?></p>
				<table class="widefat" style="max-width:640px">
					<thead><tr><th><?php esc_html_e( 'Resource', 'scoped-api-keys-for-woocommerce' ); ?></th><th style="width:80px"><?php esc_html_e( 'Read', 'scoped-api-keys-for-woocommerce' ); ?></th><th style="width:80px"><?php esc_html_e( 'Write', 'scoped-api-keys-for-woocommerce' ); ?></th></tr></thead>
					<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'Everything', 'scoped-api-keys-for-woocommerce' ); ?></strong></td>
						<td><input type="checkbox" name="scope[*][read]" value="1" <?php checked( $wcsak_has( '*', 'read' ) ); ?>></td>
						<td><input type="checkbox" name="scope[*][write]" value="1" <?php checked( $wcsak_has( '*', 'write' ) ); ?>></td>
					</tr>
					<?php foreach ( WCSAK_Scope::resources() as $wcsak_r => $wcsak_label ) : ?>
						<tr>
							<td><?php echo esc_html( $wcsak_label ); ?> <small><code><?php echo esc_html( $wcsak_r ); ?></code></small></td>
							<td><input type="checkbox" name="scope[<?php echo esc_attr( $wcsak_r ); ?>][read]" value="1" <?php checked( $wcsak_has( $wcsak_r, 'read' ) ); ?>></td>
							<td><input type="checkbox" name="scope[<?php echo esc_attr( $wcsak_r ); ?>][write]" value="1" <?php checked( $wcsak_has( $wcsak_r, 'write' ) ); ?>></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</td></tr>
		<tr><th><label for="wcsak_ip"><?php esc_html_e( 'IP allowlist', 'scoped-api-keys-for-woocommerce' ); ?></label></th>
			<td><textarea id="wcsak_ip" name="ip_allowlist" rows="3" class="large-text code" placeholder="203.0.113.10&#10;198.51.100.0/24&#10;2001:db8::/32"><?php echo esc_textarea( $wcsak_policy ? str_replace( ',', "\n", (string) $wcsak_policy->ip_allowlist ) : '' ); ?></textarea>
			<p class="description"><?php esc_html_e( 'One IP or CIDR per line. Empty = any IP. Behind Cloudflare or a proxy, set the client IP header in Settings first.', 'scoped-api-keys-for-woocommerce' ); ?></p></td></tr>
		<tr><th><label for="wcsak_expires"><?php esc_html_e( 'Expires on', 'scoped-api-keys-for-woocommerce' ); ?></label></th>
			<td><input type="date" id="wcsak_expires" name="expires_at" value="<?php echo esc_attr( $wcsak_policy && $wcsak_policy->expires_at ? substr( $wcsak_policy->expires_at, 0, 10 ) : '' ); ?>"> <span class="description"><?php esc_html_e( 'End of that day, UTC. Empty = never.', 'scoped-api-keys-for-woocommerce' ); ?></span></td></tr>
		<tr><th><label for="wcsak_rate"><?php esc_html_e( 'Rate limit (requests per minute)', 'scoped-api-keys-for-woocommerce' ); ?></label></th>
			<td><input type="number" min="0" id="wcsak_rate" name="rate_limit" value="<?php echo $wcsak_policy ? (int) $wcsak_policy->rate_limit : 0; ?>"> <span class="description"><?php esc_html_e( '0 = unlimited. Exceeding answers 429.', 'scoped-api-keys-for-woocommerce' ); ?></span></td></tr>
		<tr><th><?php esc_html_e( 'Usage log', 'scoped-api-keys-for-woocommerce' ); ?></th>
			<td><label><input type="checkbox" name="log_usage" value="1" <?php checked( ! $wcsak_policy || (int) $wcsak_policy->log_usage ); ?>> <?php esc_html_e( 'Record every request of this key (route, method, status, IP).', 'scoped-api-keys-for-woocommerce' ); ?></label></td></tr>
		<tr><th><label for="wcsak_note"><?php esc_html_e( 'Note', 'scoped-api-keys-for-woocommerce' ); ?></label></th>
			<td><input type="text" class="regular-text" id="wcsak_note" name="note" value="<?php echo esc_attr( $wcsak_policy ? $wcsak_policy->note : '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. ERP sync, read-only, contact ops@…', 'scoped-api-keys-for-woocommerce' ); ?>"></td></tr>
	</table>
	<?php submit_button( __( 'Save policy', 'scoped-api-keys-for-woocommerce' ) ); ?>
</form>
<?php if ( $wcsak_policy ) : ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Remove the policy? The key will be unrestricted again.', 'scoped-api-keys-for-woocommerce' ) ); ?>');">
	<?php wp_nonce_field( 'wcsak_delete_policy' ); ?>
	<input type="hidden" name="action" value="wcsak_delete_policy">
	<input type="hidden" name="key_id" value="<?php echo (int) $wcsak_key_id; ?>">
	<button type="submit" class="button-link-delete"><?php esc_html_e( 'Remove policy', 'scoped-api-keys-for-woocommerce' ); ?></button>
</form>
<?php endif; ?>
