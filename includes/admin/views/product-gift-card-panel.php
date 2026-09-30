<?php
/**
 * Product data → Gift card panel.
 *
 * @package Kudora
 * @var WC_Product|null $product
 * @var array[]         $designs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$kudora_s = $product ? Kudora_Gift_Card_Product::settings( $product ) : array(
	'amounts'      => array(),
	'custom'       => false,
	'min'          => 0,
	'max'          => 0,
	'expiry_days'  => '',
	'designs'      => array(),
	'physical'     => false,
	'restrictions' => array(),
);
$kudora_r   = Kudora_Scope::sanitize( $kudora_s['restrictions'] );
$kudora_raw = $product ? (string) $product->get_meta( '_kudora_gc_expiry_days' ) : '';
$kudora_cat = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
$kudora_products = function ( $ids ) {
	foreach ( (array) $ids as $id ) {
		$p = wc_get_product( $id );
		if ( $p ) {
			echo '<option value="' . esc_attr( $id ) . '" selected>' . esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ) . '</option>';
		}
	}
};
?>
<div id="kudora_gift_card_data" class="panel woocommerce_options_panel hidden">
	<div class="options_group show_if_simple">
		<?php
		woocommerce_wp_text_input(
			array(
				'id'          => '_kudora_gc_amounts',
				'label'       => __( 'Amounts', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'placeholder' => '25, 50, 100',
				'value'       => implode( ', ', array_map( 'wc_format_localized_price', $kudora_s['amounts'] ) ),
				'description' => __( 'Comma-separated amounts the customer can choose. Variable products use their variations as amounts instead.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'desc_tip'    => true,
			)
		);
		woocommerce_wp_checkbox(
			array(
				'id'          => '_kudora_gc_custom',
				'label'       => __( 'Custom amount', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'description' => __( 'Let customers type their own amount', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'value'       => $kudora_s['custom'] ? 'yes' : 'no',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'        => '_kudora_gc_min',
				'label'     => __( 'Minimum', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'data_type' => 'price',
				'value'     => $kudora_s['min'] > 0 ? wc_format_localized_price( $kudora_s['min'] ) : '',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'        => '_kudora_gc_max',
				'label'     => __( 'Maximum', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'data_type' => 'price',
				'value'     => $kudora_s['max'] > 0 ? wc_format_localized_price( $kudora_s['max'] ) : '',
			)
		);
		?>
	</div>
	<div class="options_group">
		<?php
		woocommerce_wp_text_input(
			array(
				'id'                => '_kudora_gc_expiry_days',
				'label'             => __( 'Expires after (days)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'type'              => 'number',
				'value'             => $kudora_raw,
				/* translators: %d: default days */
				'placeholder'       => sprintf( __( 'Default: %d', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), Kudora_Settings::int( 'gc_default_expiry_days' ) ),
				'custom_attributes' => array( 'min' => 0 ),
				'description'       => __( 'Empty uses the default from settings; 0 never expires.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'desc_tip'          => true,
			)
		);
		woocommerce_wp_checkbox(
			array(
				'id'          => '_kudora_gc_physical',
				'label'       => __( 'Physical card', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'description' => __( 'Shipped to the customer; no email is sent.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'value'       => $kudora_s['physical'] ? 'yes' : 'no',
			)
		);
		?>
		<p class="form-field">
			<label for="_kudora_gc_designs"><?php esc_html_e( 'Designs', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
			<select id="_kudora_gc_designs" name="_kudora_gc_designs[]" multiple class="wc-enhanced-select" style="width:50%" data-placeholder="<?php esc_attr_e( 'All designs', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>">
				<?php foreach ( $designs as $kudora_d ) : ?>
					<option value="<?php echo esc_attr( $kudora_d['id'] ); ?>" <?php selected( in_array( (int) $kudora_d['id'], array_map( 'intval', $kudora_s['designs'] ), true ) ); ?>><?php echo esc_html( $kudora_d['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php /* translators: %s: URL */ ?>
			<span class="description"><?php echo wp_kses_post( sprintf( __( 'Manage designs under <a href="%s">Gift cards → Designs</a>.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), esc_url( admin_url( 'admin.php?page=kudora-gift-cards&tab=designs' ) ) ) ); ?></span>
		</p>
	</div>
	<div class="options_group">
		<p class="form-field"><strong><?php esc_html_e( 'Where the card can be spent', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></strong></p>
		<p class="form-field">
			<label for="_kudora_gc_r_products"><?php esc_html_e( 'Only these products', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
			<select class="wc-product-search" multiple style="width:50%" id="_kudora_gc_r_products" name="_kudora_gc_r_products[]" data-placeholder="<?php esc_attr_e( 'Any product', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>" data-action="woocommerce_json_search_products_and_variations"><?php $kudora_products( $kudora_r['products'] ); ?></select>
		</p>
		<p class="form-field">
			<label for="_kudora_gc_r_categories"><?php esc_html_e( 'Only these categories', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
			<select class="wc-enhanced-select" multiple style="width:50%" id="_kudora_gc_r_categories" name="_kudora_gc_r_categories[]" data-placeholder="<?php esc_attr_e( 'Any category', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>">
				<?php foreach ( $kudora_cat as $kudora_t ) : ?>
					<option value="<?php echo esc_attr( $kudora_t->term_id ); ?>" <?php selected( in_array( (int) $kudora_t->term_id, $kudora_r['categories'], true ) ); ?>><?php echo esc_html( $kudora_t->name ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="form-field">
			<label for="_kudora_gc_r_exclude_products"><?php esc_html_e( 'Never these products', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
			<select class="wc-product-search" multiple style="width:50%" id="_kudora_gc_r_exclude_products" name="_kudora_gc_r_exclude_products[]" data-placeholder="<?php esc_attr_e( 'None', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>" data-action="woocommerce_json_search_products_and_variations"><?php $kudora_products( $kudora_r['exclude_products'] ); ?></select>
		</p>
		<p class="form-field">
			<label for="_kudora_gc_r_exclude_categories"><?php esc_html_e( 'Never these categories', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
			<select class="wc-enhanced-select" multiple style="width:50%" id="_kudora_gc_r_exclude_categories" name="_kudora_gc_r_exclude_categories[]" data-placeholder="<?php esc_attr_e( 'None', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>">
				<?php foreach ( $kudora_cat as $kudora_t ) : ?>
					<option value="<?php echo esc_attr( $kudora_t->term_id ); ?>" <?php selected( in_array( (int) $kudora_t->term_id, $kudora_r['exclude_categories'], true ) ); ?>><?php echo esc_html( $kudora_t->name ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
	</div>
</div>
