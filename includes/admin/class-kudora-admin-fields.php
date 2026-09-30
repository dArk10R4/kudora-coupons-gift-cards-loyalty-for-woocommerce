<?php
/**
 * Shared admin form fields: product scope pickers and the Buy X Get Y block, used by
 * discount rules, earning rules and coupons.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin field renderers.
 */
class Kudora_Admin_Fields {

	/**
	 * <option>s for selected products.
	 *
	 * @param int[] $ids Ids.
	 * @return void
	 */
	public static function product_options( $ids ) {
		foreach ( (array) $ids as $id ) {
			$p = wc_get_product( (int) $id );
			if ( $p ) {
				echo '<option value="' . esc_attr( $id ) . '" selected>' . esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ) . '</option>';
			}
		}
	}

	/**
	 * <option>s for a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param int[]  $selected Selected ids.
	 * @return void
	 */
	public static function term_options( $taxonomy, $selected ) {
		$selected = array_map( 'intval', (array) $selected );
		foreach ( get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) ) as $t ) {
			echo '<option value="' . esc_attr( $t->term_id ) . '" ' . selected( in_array( (int) $t->term_id, $selected, true ), true, false ) . '>' . esc_html( $t->name ) . '</option>';
		}
	}

	/**
	 * Product scope block.
	 *
	 * @param string $name     Input name prefix, e.g. config[scope].
	 * @param array  $scope    Scope.
	 * @param string $title    Heading.
	 * @param bool   $excludes Show exclusion fields.
	 * @return void
	 */
	public static function scope( $name, $scope, $title = '', $excludes = true ) {
		$scope = Kudora_Scope::sanitize( $scope );
		$id    = sanitize_html_class( str_replace( array( '[', ']' ), '_', $name ) );
		?>
		<fieldset class="kudora-scope">
			<legend><?php echo esc_html( '' !== $title ? $title : __( 'Products', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ); ?></legend>
			<p class="description"><?php esc_html_e( 'Leave the first three empty to include every product.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></p>
			<p>
				<label for="<?php echo esc_attr( $id ); ?>_products"><?php esc_html_e( 'Products', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
				<select class="wc-product-search" multiple id="<?php echo esc_attr( $id ); ?>_products" name="<?php echo esc_attr( $name ); ?>[products][]" data-placeholder="<?php esc_attr_e( 'Search for a product…', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>" data-action="woocommerce_json_search_products_and_variations"><?php self::product_options( $scope['products'] ); ?></select>
			</p>
			<p>
				<label for="<?php echo esc_attr( $id ); ?>_categories"><?php esc_html_e( 'Categories', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
				<select class="wc-enhanced-select" multiple id="<?php echo esc_attr( $id ); ?>_categories" name="<?php echo esc_attr( $name ); ?>[categories][]" data-placeholder="<?php esc_attr_e( 'Any category', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>"><?php self::term_options( 'product_cat', $scope['categories'] ); ?></select>
			</p>
			<p>
				<label for="<?php echo esc_attr( $id ); ?>_tags"><?php esc_html_e( 'Tags', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
				<select class="wc-enhanced-select" multiple id="<?php echo esc_attr( $id ); ?>_tags" name="<?php echo esc_attr( $name ); ?>[tags][]" data-placeholder="<?php esc_attr_e( 'Any tag', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>"><?php self::term_options( 'product_tag', $scope['tags'] ); ?></select>
			</p>
			<?php if ( $excludes ) : ?>
				<p>
					<label for="<?php echo esc_attr( $id ); ?>_exclude_products"><?php esc_html_e( 'Except products', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
					<select class="wc-product-search" multiple id="<?php echo esc_attr( $id ); ?>_exclude_products" name="<?php echo esc_attr( $name ); ?>[exclude_products][]" data-placeholder="<?php esc_attr_e( 'None', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>" data-action="woocommerce_json_search_products_and_variations"><?php self::product_options( $scope['exclude_products'] ); ?></select>
				</p>
				<p>
					<label for="<?php echo esc_attr( $id ); ?>_exclude_categories"><?php esc_html_e( 'Except categories', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
					<select class="wc-enhanced-select" multiple id="<?php echo esc_attr( $id ); ?>_exclude_categories" name="<?php echo esc_attr( $name ); ?>[exclude_categories][]" data-placeholder="<?php esc_attr_e( 'None', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>"><?php self::term_options( 'product_cat', $scope['exclude_categories'] ); ?></select>
				</p>
				<p>
					<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[exclude_sale]" value="1" <?php checked( $scope['exclude_sale'] ); ?> /> <?php esc_html_e( 'Skip products that are already on sale', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
				</p>
			<?php endif; ?>
		</fieldset>
		<?php
	}

	/**
	 * Buy X Get Y fields.
	 *
	 * @param string $name Prefix, e.g. config or kudora_bxgy.
	 * @param array  $cfg  Config.
	 * @param bool   $auto Show the auto-add option (rules only).
	 * @return void
	 */
	public static function bxgy( $name, $cfg, $auto = true ) {
		$c = Kudora_Rule_Calc::sanitize_bxgy( $cfg );
		?>
		<div class="kudora-bxgy-fields">
			<p class="kudora-inline">
				<label><?php esc_html_e( 'Buy', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?> <input type="number" min="1" name="<?php echo esc_attr( $name ); ?>[buy_qty]" value="<?php echo esc_attr( $c['buy_qty'] ); ?>" class="small-text" /></label>
				<label><?php esc_html_e( 'get', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?> <input type="number" min="1" name="<?php echo esc_attr( $name ); ?>[get_qty]" value="<?php echo esc_attr( $c['get_qty'] ); ?>" class="small-text" /></label>
				<label>
					<select name="<?php echo esc_attr( $name ); ?>[reward]" class="kudora-bxgy-reward">
						<option value="free" <?php selected( 'free', $c['reward'] ); ?>><?php esc_html_e( 'free', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></option>
						<option value="percent" <?php selected( 'percent', $c['reward'] ); ?>><?php esc_html_e( '% off', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></option>
						<option value="fixed" <?php selected( 'fixed', $c['reward'] ); ?>><?php esc_html_e( 'amount off each', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></option>
					</select>
				</label>
				<label class="kudora-bxgy-value"><span class="screen-reader-text"><?php esc_html_e( 'Reward value', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></span><input type="text" class="short wc_input_decimal" name="<?php echo esc_attr( $name ); ?>[reward_value]" value="<?php echo esc_attr( wc_format_localized_decimal( $c['reward_value'] ) ); ?>" /></label>
			</p>
			<p>
				<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[repeat]" value="1" <?php checked( $c['repeat'] ); ?> /> <?php esc_html_e( 'Repeat (buy 4 get 2 …)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
				&nbsp; <label><?php esc_html_e( 'Max times per order', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?> <input type="number" min="0" class="small-text" name="<?php echo esc_attr( $name ); ?>[max_sets]" value="<?php echo esc_attr( $c['max_sets'] ); ?>" /></label>
				&nbsp; <label><?php esc_html_e( 'Reward the', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>
					<select name="<?php echo esc_attr( $name ); ?>[pick]">
						<option value="cheapest" <?php selected( 'cheapest', $c['pick'] ); ?>><?php esc_html_e( 'cheapest items', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></option>
						<option value="expensive" <?php selected( 'expensive', $c['pick'] ); ?>><?php esc_html_e( 'most expensive items', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></option>
					</select>
				</label>
			</p>
			<?php self::scope( $name . '[buy_scope]', $c['buy_scope'], __( 'Customer buys', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ); ?>
			<p>
				<label><input type="checkbox" class="kudora-get-same" name="<?php echo esc_attr( $name ); ?>[get_same]" value="1" <?php checked( $c['get_same'] ); ?> /> <?php esc_html_e( 'The reward comes from the same products', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
			</p>
			<div class="kudora-get-scope">
				<?php self::scope( $name . '[get_scope]', $c['get_scope'], __( 'Customer gets', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ); ?>
				<?php if ( $auto ) : ?>
					<p>
						<label for="<?php echo esc_attr( sanitize_html_class( $name ) ); ?>_auto_add"><?php esc_html_e( 'Add this product to the cart automatically', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
						<select class="wc-product-search" id="<?php echo esc_attr( sanitize_html_class( $name ) ); ?>_auto_add" name="<?php echo esc_attr( $name ); ?>[auto_add]" data-allow_clear="true" data-placeholder="<?php esc_attr_e( 'Do not add', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>" data-action="woocommerce_json_search_products_and_variations"><?php self::product_options( $c['auto_add'] ? array( $c['auto_add'] ) : array() ); ?></select>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Conditions builder mount + hidden JSON.
	 *
	 * @param string $name       Hidden input name.
	 * @param array  $conditions Conditions.
	 * @return void
	 */
	public static function conditions( $name, $conditions ) {
		$id = sanitize_html_class( $name );
		?>
		<div class="kudora-conditions" data-kudora-conditions data-input="<?php echo esc_attr( $id ); ?>"></div>
		<input type="hidden" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( wp_json_encode( array_values( (array) $conditions ) ) ); ?>" />
		<?php
	}

	/**
	 * Local datetime-local value from UTC.
	 *
	 * @param string|null $utc UTC datetime.
	 * @return string
	 */
	public static function local_datetime( $utc ) {
		if ( empty( $utc ) ) {
			return '';
		}
		return get_date_from_gmt( $utc, 'Y-m-d\TH:i' );
	}

	/**
	 * UTC from a datetime-local value.
	 *
	 * @param string $local Local value.
	 * @return string|null
	 */
	public static function utc_datetime( $local ) {
		$local = trim( (string) $local );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $local ) ) {
			return null;
		}
		return get_gmt_from_date( str_replace( 'T', ' ', $local ) . ':00' );
	}
}
