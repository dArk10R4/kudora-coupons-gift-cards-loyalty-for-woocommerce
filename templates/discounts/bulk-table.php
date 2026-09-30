<?php
/**
 * Bulk pricing table on the product page.
 *
 * Override by copying to yourtheme/woocommerce/discounts/bulk-table.php.
 *
 * @package Kudora
 * @var array[]    $rules
 * @var WC_Product $product
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$kudora_base = (float) $product->get_price( 'edit' );
?>
<?php foreach ( $rules as $kudora_rule ) : ?>
	<table class="kudora-bulk-table">
		<caption><?php echo esc_html( ! empty( $kudora_rule['config']['label'] ) ? $kudora_rule['config']['label'] : $kudora_rule['name'] ); ?></caption>
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Quantity', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Price', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( (array) $kudora_rule['config']['ranges'] as $kudora_range ) : ?>
				<?php
				$kudora_min = (int) $kudora_range['min'];
				$kudora_max = isset( $kudora_range['max'] ) && (int) $kudora_range['max'] > 0 ? (int) $kudora_range['max'] : 0;
				$kudora_new = Kudora_Discount_Engine::adjust( $kudora_base, $kudora_range['adjust'], (float) $kudora_range['value'] );
				?>
				<tr>
					<td><?php echo esc_html( $kudora_max ? $kudora_min . '–' . $kudora_max : $kudora_min . '+' ); ?></td>
					<td>
						<?php echo wp_kses_post( wc_price( wc_get_price_to_display( $product, array( 'price' => $kudora_new ) ) ) ); ?>
						<?php if ( 'percent' === $kudora_range['adjust'] ) : ?>
							<?php /* translators: %s: percent */ ?>
							<small><?php echo esc_html( sprintf( __( '(%s%% off)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), wc_format_localized_decimal( $kudora_range['value'] ) ) ); ?></small>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endforeach; ?>
