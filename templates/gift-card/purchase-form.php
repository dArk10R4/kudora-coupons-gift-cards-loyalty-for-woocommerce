<?php
/**
 * Gift card purchase fields, shown inside the add-to-cart form.
 *
 * Override by copying to yourtheme/woocommerce/gift-card/purchase-form.php.
 *
 * @package Kudora
 * @var WC_Product $product
 * @var array      $settings
 * @var array[]    $designs
 * @var string     $sender
 * @var string     $today
 * @var string     $max_date
 * @var array      $posted
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$kudora_amounts   = $settings['amounts'];
$kudora_is_simple = ! $settings['variable'];
$kudora_chosen    = '' !== $posted['amount'] ? $posted['amount'] : ( $kudora_amounts ? wc_format_decimal( $kudora_amounts[0] ) : ( $settings['custom'] ? 'custom' : '' ) );
$kudora_send_to   = $posted['has_form'] ? $posted['send_to'] : 'other';
$kudora_design    = $posted['design_id'] ? $posted['design_id'] : ( $designs ? (int) $designs[0]['id'] : 0 );
?>
<div class="kudora-gc-form" data-currency="<?php echo esc_attr( html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) ); ?>" data-price-format="<?php echo esc_attr( get_woocommerce_price_format() ); ?>" data-decimals="<?php echo esc_attr( wc_get_price_decimals() ); ?>">
	<input type="hidden" name="kudora_gc_form" value="1" />

	<?php if ( $kudora_is_simple && ( count( $kudora_amounts ) > 1 || $settings['custom'] ) ) : ?>
		<fieldset class="kudora-gc-amounts">
			<legend><?php esc_html_e( 'Amount', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></legend>
			<div class="kudora-gc-pills">
				<?php foreach ( $kudora_amounts as $kudora_amount ) : ?>
					<?php $kudora_value = wc_format_decimal( $kudora_amount ); ?>
					<label class="kudora-gc-pill">
						<input type="radio" name="kudora_gc_amount" value="<?php echo esc_attr( $kudora_value ); ?>" <?php checked( abs( (float) $kudora_chosen - $kudora_amount ) < 0.001 && 'custom' !== $kudora_chosen ); ?> data-amount="<?php echo esc_attr( $kudora_value ); ?>" />
						<span><?php echo wp_kses_post( wc_price( $kudora_amount ) ); ?></span>
					</label>
				<?php endforeach; ?>
				<?php if ( $settings['custom'] ) : ?>
					<label class="kudora-gc-pill">
						<input type="radio" name="kudora_gc_amount" value="custom" <?php checked( 'custom', $kudora_chosen ); ?> />
						<span><?php esc_html_e( 'Other amount', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></span>
					</label>
				<?php endif; ?>
			</div>
			<?php if ( $settings['custom'] ) : ?>
				<p class="kudora-gc-custom">
					<label for="kudora_gc_custom_amount">
						<?php
						if ( $settings['min'] > 0 && $settings['max'] > 0 ) {
							/* translators: 1: min amount, 2: max amount */
							printf( esc_html__( 'Enter an amount (%1$s – %2$s)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), wp_kses_post( wc_price( $settings['min'] ) ), wp_kses_post( wc_price( $settings['max'] ) ) );
						} else {
							esc_html_e( 'Enter an amount', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
						}
						?>
					</label>
					<input type="number" inputmode="decimal" id="kudora_gc_custom_amount" name="kudora_gc_custom_amount" value="<?php echo esc_attr( $posted['custom_amount'] ); ?>" step="<?php echo esc_attr( 1 / pow( 10, wc_get_price_decimals() ) ); ?>" <?php echo $settings['min'] > 0 ? 'min="' . esc_attr( $settings['min'] ) . '"' : 'min="0"'; ?> <?php echo $settings['max'] > 0 ? 'max="' . esc_attr( $settings['max'] ) . '"' : ''; ?> />
				</p>
			<?php endif; ?>
		</fieldset>
	<?php elseif ( $kudora_is_simple && 1 === count( $kudora_amounts ) ) : ?>
		<input type="hidden" name="kudora_gc_amount" value="<?php echo esc_attr( wc_format_decimal( $kudora_amounts[0] ) ); ?>" data-amount="<?php echo esc_attr( wc_format_decimal( $kudora_amounts[0] ) ); ?>" />
	<?php endif; ?>

	<?php if ( ! $settings['physical'] ) : ?>
		<fieldset class="kudora-gc-send-to">
			<legend><?php esc_html_e( 'Who is it for?', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></legend>
			<label><input type="radio" name="kudora_gc_send_to" value="other" <?php checked( 'other', $kudora_send_to ); ?> /> <?php esc_html_e( 'Someone else', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
			<label><input type="radio" name="kudora_gc_send_to" value="self" <?php checked( 'self', $kudora_send_to ); ?> /> <?php esc_html_e( 'Myself', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
		</fieldset>

		<div class="kudora-gc-recipient" <?php echo 'self' === $kudora_send_to ? 'hidden' : ''; ?>>
			<p class="form-row">
				<label for="kudora_gc_recipient_name"><?php esc_html_e( 'Recipient name', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
				<input type="text" id="kudora_gc_recipient_name" name="kudora_gc_recipient_name" value="<?php echo esc_attr( $posted['recipient_name'] ); ?>" maxlength="100" autocomplete="off" />
			</p>
			<p class="form-row">
				<label for="kudora_gc_recipient_email"><?php esc_html_e( 'Recipient email', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?> <abbr class="required" title="<?php esc_attr_e( 'required', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>">*</abbr></label>
				<input type="email" id="kudora_gc_recipient_email" name="kudora_gc_recipient_email" value="<?php echo esc_attr( $posted['recipient_email'] ); ?>" maxlength="200" autocomplete="off" />
			</p>
		</div>
	<?php endif; ?>

	<p class="form-row">
		<label for="kudora_gc_sender_name"><?php esc_html_e( 'From', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
		<input type="text" id="kudora_gc_sender_name" name="kudora_gc_sender_name" value="<?php echo esc_attr( '' !== $posted['sender_name'] ? $posted['sender_name'] : $sender ); ?>" maxlength="100" />
	</p>
	<p class="form-row">
		<label for="kudora_gc_message"><?php esc_html_e( 'Message (optional)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
		<textarea id="kudora_gc_message" name="kudora_gc_message" rows="3" maxlength="<?php echo esc_attr( Kudora_Gift_Card_Product::MESSAGE_MAX ); ?>" aria-describedby="kudora_gc_message_count"><?php echo esc_textarea( $posted['message'] ); ?></textarea>
		<small id="kudora_gc_message_count" class="kudora-gc-count" aria-live="polite"></small>
	</p>

	<?php if ( ! $settings['physical'] ) : ?>
		<p class="form-row">
			<label for="kudora_gc_deliver_date"><?php esc_html_e( 'Delivery date', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
			<input type="date" id="kudora_gc_deliver_date" name="kudora_gc_deliver_date" value="<?php echo esc_attr( $posted['deliver_date'] ); ?>" min="<?php echo esc_attr( $today ); ?>" max="<?php echo esc_attr( $max_date ); ?>" aria-describedby="kudora_gc_deliver_help" />
			<small id="kudora_gc_deliver_help"><?php esc_html_e( 'Leave empty to send it as soon as the order is paid.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></small>
		</p>
	<?php endif; ?>

	<?php if ( count( $designs ) > 1 ) : ?>
		<fieldset class="kudora-gc-designs">
			<legend><?php esc_html_e( 'Design', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></legend>
			<div class="kudora-gc-swatches">
				<?php foreach ( $designs as $kudora_d ) : ?>
					<label class="kudora-gc-swatch" title="<?php echo esc_attr( $kudora_d['name'] ); ?>">
						<input type="radio" name="kudora_gc_design" value="<?php echo esc_attr( $kudora_d['id'] ); ?>" <?php checked( $kudora_design, (int) $kudora_d['id'] ); ?> data-style="<?php echo esc_attr( Kudora_Gift_Card_Designs::style( $kudora_d ) ); ?>" />
						<span class="kudora-gc-swatch-chip" style="<?php echo esc_attr( Kudora_Gift_Card_Designs::style( $kudora_d ) ); ?>"></span>
						<span class="screen-reader-text"><?php echo esc_html( $kudora_d['name'] ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
		</fieldset>
	<?php elseif ( 1 === count( $designs ) ) : ?>
		<input type="hidden" name="kudora_gc_design" value="<?php echo esc_attr( $designs[0]['id'] ); ?>" />
	<?php endif; ?>

	<?php $kudora_preview = $designs ? $designs[0] : Kudora_Gift_Card_Designs::fallback(); ?>
	<?php
	foreach ( $designs as $kudora_d ) {
		if ( (int) $kudora_d['id'] === $kudora_design ) {
			$kudora_preview = $kudora_d;
		}
	}
	?>
	<div class="kudora-gc-preview" aria-hidden="true">
		<div class="kudora-card" style="<?php echo esc_attr( Kudora_Gift_Card_Designs::style( $kudora_preview ) ); ?>">
			<div class="kudora-card-top">
				<span class="kudora-card-store"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
				<span class="kudora-card-label"><?php esc_html_e( 'Gift card', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></span>
			</div>
			<div class="kudora-card-amount" data-kudora-preview="amount"><?php echo $kudora_amounts ? wp_kses_post( wc_price( $kudora_amounts[0] ) ) : '&nbsp;'; ?></div>
			<div class="kudora-card-to" data-kudora-preview="to"></div>
			<div class="kudora-card-message" data-kudora-preview="message"></div>
		</div>
	</div>
</div>
