<?php
/**
 * Settings tab.
 *
 * @package ScopedApiKeys
 */

defined( 'ABSPATH' ) || exit;

$wcsak_s = WCSAK_Settings::all();
?>
<form method="post" action="options.php" style="max-width:800px">
	<?php settings_fields( 'wcsak_settings_group' ); ?>
	<table class="form-table">
		<tr><th><?php esc_html_e( 'Mode', 'scoped-api-keys-for-woocommerce' ); ?></th>
			<td>
				<label><input type="radio" name="wcsak_settings[mode]" value="monitor" <?php checked( 'monitor', $wcsak_s['mode'] ); ?>> <strong><?php esc_html_e( 'Monitor', 'scoped-api-keys-for-woocommerce' ); ?></strong> — <?php esc_html_e( 'log what would be blocked, block nothing. Use it for a week after adding policies to existing integrations.', 'scoped-api-keys-for-woocommerce' ); ?></label><br>
				<label><input type="radio" name="wcsak_settings[mode]" value="enforce" <?php checked( 'enforce', $wcsak_s['mode'] ); ?>> <strong><?php esc_html_e( 'Enforce', 'scoped-api-keys-for-woocommerce' ); ?></strong> — <?php esc_html_e( 'violations answer 403 (or 429 for rate limits).', 'scoped-api-keys-for-woocommerce' ); ?></label>
			</td></tr>
		<tr><th><label for="wcsak_ip_header"><?php esc_html_e( 'Client IP header', 'scoped-api-keys-for-woocommerce' ); ?></label></th>
			<td><input type="text" class="regular-text code" id="wcsak_ip_header" name="wcsak_settings[ip_header]" value="<?php echo esc_attr( $wcsak_s['ip_header'] ); ?>" placeholder="HTTP_CF_CONNECTING_IP">
			<p class="description"><?php esc_html_e( 'Empty = REMOTE_ADDR. Behind Cloudflare use HTTP_CF_CONNECTING_IP; behind another proxy HTTP_X_FORWARDED_FOR (first value) or HTTP_X_REAL_IP. Only set this when the proxy overwrites the header, otherwise a client can spoof it.', 'scoped-api-keys-for-woocommerce' ); ?></p></td></tr>
		<tr><th><?php esc_html_e( 'Log unmanaged keys', 'scoped-api-keys-for-woocommerce' ); ?></th>
			<td><label><input type="checkbox" name="wcsak_settings[log_unmanaged]" value="1" <?php checked( $wcsak_s['log_unmanaged'] ); ?>> <?php esc_html_e( 'Also record requests of keys that have no policy yet (helps you see which resources an integration really uses before scoping it).', 'scoped-api-keys-for-woocommerce' ); ?></label></td></tr>
		<tr><th><label for="wcsak_retention"><?php esc_html_e( 'Keep usage rows for (days)', 'scoped-api-keys-for-woocommerce' ); ?></label></th>
			<td><input type="number" min="1" max="365" id="wcsak_retention" name="wcsak_settings[retention_days]" value="<?php echo (int) $wcsak_s['retention_days']; ?>"></td></tr>
	</table>
	<?php submit_button(); ?>
</form>
