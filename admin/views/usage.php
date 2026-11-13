<?php
/**
 * Usage log tab.
 *
 * @package ScopedApiKeys
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended
$wcsak_key_id  = isset( $_GET['key_id'] ) ? (int) $_GET['key_id'] : 0;
$wcsak_outcome = isset( $_GET['outcome'] ) ? sanitize_key( wp_unslash( $_GET['outcome'] ) ) : '';
$wcsak_page    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
// phpcs:enable
$wcsak_res  = WCSAK_Store::usage(
	array(
		'key_id'   => $wcsak_key_id,
		'per_page' => 50,
		'page'     => $wcsak_page,
	)
);
$wcsak_keys = WCSAK_Store::keys_with_policies();
$wcsak_names = array();
foreach ( $wcsak_keys as $wcsak_k ) {
	$wcsak_names[ (int) $wcsak_k->key_id ] = $wcsak_k->description;
}
?>
<form method="get" style="margin:12px 0">
	<input type="hidden" name="page" value="wcsak"><input type="hidden" name="tab" value="usage">
	<select name="key_id">
		<option value="0"><?php esc_html_e( 'All keys', 'scoped-api-keys-for-woocommerce' ); ?></option>
		<?php foreach ( $wcsak_names as $wcsak_id => $wcsak_desc ) : ?>
			<option value="<?php echo (int) $wcsak_id; ?>" <?php selected( $wcsak_key_id, $wcsak_id ); ?>><?php echo esc_html( $wcsak_desc ); ?> (#<?php echo (int) $wcsak_id; ?>)</option>
		<?php endforeach; ?>
	</select>
	<button class="button"><?php esc_html_e( 'Filter', 'scoped-api-keys-for-woocommerce' ); ?></button>
	<span class="description" style="margin-left:12px"><?php echo esc_html( sprintf( /* translators: %d: rows */ __( '%d rows', 'scoped-api-keys-for-woocommerce' ), (int) $wcsak_res['total'] ) ); ?></span>
</form>
<table class="widefat striped">
	<thead><tr>
		<th><?php esc_html_e( 'When (UTC)', 'scoped-api-keys-for-woocommerce' ); ?></th>
		<th><?php esc_html_e( 'Key', 'scoped-api-keys-for-woocommerce' ); ?></th>
		<th><?php esc_html_e( 'Method', 'scoped-api-keys-for-woocommerce' ); ?></th>
		<th><?php esc_html_e( 'Route', 'scoped-api-keys-for-woocommerce' ); ?></th>
		<th><?php esc_html_e( 'Status', 'scoped-api-keys-for-woocommerce' ); ?></th>
		<th><?php esc_html_e( 'Outcome', 'scoped-api-keys-for-woocommerce' ); ?></th>
		<th><?php esc_html_e( 'IP', 'scoped-api-keys-for-woocommerce' ); ?></th>
	</tr></thead>
	<tbody>
	<?php if ( ! $wcsak_res['items'] ) : ?>
		<tr><td colspan="7"><?php esc_html_e( 'Nothing logged yet.', 'scoped-api-keys-for-woocommerce' ); ?></td></tr>
	<?php endif; ?>
	<?php foreach ( $wcsak_res['items'] as $wcsak_r ) : ?>
		<tr>
			<td><small><?php echo esc_html( $wcsak_r->created_at ); ?></small></td>
			<td><?php echo esc_html( isset( $wcsak_names[ (int) $wcsak_r->key_id ] ) ? $wcsak_names[ (int) $wcsak_r->key_id ] : '#' . (int) $wcsak_r->key_id ); ?></td>
			<td><code><?php echo esc_html( $wcsak_r->method ); ?></code></td>
			<td><code><?php echo esc_html( $wcsak_r->route ); ?></code></td>
			<td><?php echo (int) $wcsak_r->status; ?></td>
			<td><?php echo 'allowed' === $wcsak_r->outcome ? esc_html( $wcsak_r->outcome ) : '<strong style="color:#b32d2e">' . esc_html( $wcsak_r->outcome ) . '</strong>'; ?></td>
			<td><small><?php echo esc_html( $wcsak_r->ip ); ?></small></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
<?php
$wcsak_pages = (int) ceil( $wcsak_res['total'] / 50 );
if ( $wcsak_pages > 1 ) {
	echo '<p>';
	for ( $wcsak_i = 1; $wcsak_i <= $wcsak_pages; $wcsak_i++ ) {
		printf( '<a class="button %s" href="%s">%d</a> ', $wcsak_i === $wcsak_page ? 'button-primary' : '', esc_url( add_query_arg( array( 'page' => 'wcsak', 'tab' => 'usage', 'key_id' => $wcsak_key_id, 'paged' => $wcsak_i ), admin_url( 'admin.php' ) ) ), (int) $wcsak_i );
	}
	echo '</p>';
}
