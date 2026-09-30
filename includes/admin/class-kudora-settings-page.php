<?php
/**
 * WooCommerce → Settings → Kudora: module switches and every module's settings.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings tab.
 */
class Kudora_Settings_Page extends WC_Settings_Page {

	/**
	 * Setup.
	 */
	public function __construct() {
		$this->id    = 'kudora';
		$this->label = __( 'Kudora', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
		parent::__construct();
		add_action( 'woocommerce_admin_field_kudora_ids', array( $this, 'field_ids' ) );
		add_action( 'woocommerce_admin_field_kudora_code_preview', array( $this, 'field_preview' ) );
		add_filter( 'woocommerce_admin_settings_sanitize_option', array( $this, 'sanitize' ), 10, 3 );
		add_action( 'woocommerce_update_options_' . $this->id, array( $this, 'after_save' ), 20 );
	}

	/**
	 * Sections.
	 *
	 * @return array
	 */
	protected function get_own_sections() {
		return array(
			''           => __( 'General', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'discounts'  => __( 'Discounts & coupons', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'gift_cards' => __( 'Gift cards', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'loyalty'    => __( 'Loyalty', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'referrals'  => __( 'Referrals', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
		);
	}

	/**
	 * Build one setting.
	 *
	 * @param string $key   Key without prefix.
	 * @param array  $field Field.
	 * @return array
	 */
	protected function f( $key, array $field ) {
		$defaults = Kudora_Settings::defaults();
		return array_merge(
			array(
				'id'      => 'kudora_' . $key,
				'default' => isset( $defaults[ $key ] ) ? $defaults[ $key ] : '',
			),
			$field
		);
	}

	/**
	 * General.
	 *
	 * @return array
	 */
	protected function get_settings_for_default_section() {
		return array(
			array(
				'title' => __( 'Modules', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Every Kudora feature is free. Turn off the modules you do not need.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'id'    => 'kudora_modules',
			),
			$this->f(
				'module_discounts',
				array(
					'title' => __( 'Smart coupons & discount rules', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					'desc'  => __( 'Automatic discounts, BOGO, bulk pricing, scheduled and auto-applied coupons', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					'type'  => 'checkbox',
				)
			),
			$this->f(
				'module_gift_cards',
				array(
					'title' => __( 'Gift cards', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					'desc'  => __( 'Sell, email and redeem gift cards', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					'type'  => 'checkbox',
				)
			),
			$this->f(
				'module_loyalty',
				array(
					'title' => __( 'Loyalty points & rewards', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					'desc'  => __( 'Points, tiers, rewards and referrals', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					'type'  => 'checkbox',
				)
			),
			array(
				'type' => 'sectionend',
				'id'   => 'kudora_modules',
			),
			array(
				'title' => __( 'Data', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'type'  => 'title',
				'id'    => 'kudora_data',
			),
			$this->f(
				'delete_data_on_uninstall',
				array(
					'title' => __( 'Delete data on uninstall', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					'desc'  => __( 'Remove all gift cards, balances, points, rules and settings when the plugin is deleted. Gift card balances are customer money — leave this off unless you are sure.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					'type'  => 'checkbox',
				)
			),
			array(
				'type' => 'sectionend',
				'id'   => 'kudora_data',
			),
		);
	}

	/**
	 * Discounts.
	 *
	 * @return array
	 */
	protected function get_settings_for_discounts_section() {
		return array(
			array(
				'title' => __( 'Discount rules', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => sprintf(
					/* translators: %s: URL of the rules screen */
					__( 'Create rules under <a href="%s">Marketing → Discount rules</a>.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					esc_url( admin_url( 'admin.php?page=kudora-discounts' ) )
				),
				'id'    => 'kudora_discounts',
			),
			$this->f(
				'discounts_catalog_prices',
				array(
					'title' => __( 'Show sale prices in the shop', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					'desc'  => __( 'Product discounts that do not depend on the cart appear as sale prices on shop and product pages.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					'type'  => 'checkbox',
				)
			),
			$this->f(
				'discounts_exclude_gift_cards',
				array(
					'title' => __( 'Never discount gift cards', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					'desc'  => __( 'Recommended: discounting gift cards lets customers buy money for less than it is worth.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					'type'  => 'checkbox',
				)
			),
			$this->f(
				'discounts_promo_notices',
				array(
					'title' => __( 'Promotion notices', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					'desc'  => __( 'Show "Add $X more to get …" in the cart and checkout.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					'type'  => 'checkbox',
				)
			),
			$this->f(
				'discounts_url_param',
				array(
					'title'    => __( 'Coupon link parameter', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					'desc'     => __( 'Links like example.com/?coupon=CODE apply a coupon. Leave empty to turn coupon links off.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					'desc_tip' => true,
					'type'     => 'text',
				)
			),
			array(
				'type' => 'sectionend',
				'id'   => 'kudora_discounts',
			),
		);
	}

	/**
	 * Gift cards.
	 *
	 * @return array
	 */
	protected function get_settings_for_gift_cards_section() {
		$gateways = array();
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gw ) {
				$gateways[ $id ] = wp_strip_all_tags( $gw->get_title() ? $gw->get_title() : $id );
			}
		}
		return array(
			array(
				'title' => __( 'Code format', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'type'  => 'title',
				'id'    => 'kudora_gc_format',
			),
			$this->f( 'gc_code_prefix', array( 'title' => __( 'Prefix', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'type' => 'text', 'custom_attributes' => array( 'maxlength' => 20 ) ) ),
			$this->f( 'gc_code_suffix', array( 'title' => __( 'Suffix', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'type' => 'text', 'custom_attributes' => array( 'maxlength' => 20 ) ) ),
			$this->f(
				'gc_code_charset',
				array(
					'title'   => __( 'Random characters', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					'type'    => 'select',
					'options' => array(
						'alnum'  => __( 'Letters and numbers (A–Z, 2–9)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
						'digits' => __( 'Numbers only (0–9)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					),
				)
			),
			$this->f( 'gc_code_length', array( 'title' => __( 'Random length', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'type' => 'number', 'custom_attributes' => array( 'min' => 8, 'max' => 32 ), 'desc_tip' => __( 'At least 12 is recommended so codes cannot be guessed.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ) ),
			$this->f( 'gc_code_group', array( 'title' => __( 'Dash every', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'type' => 'number', 'custom_attributes' => array( 'min' => 0, 'max' => 8 ), 'desc' => __( 'characters (0 = no dashes)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ) ),
			array(
				'id'   => 'kudora_gc_code_preview',
				'type' => 'kudora_code_preview',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'kudora_gc_format',
			),
			array(
				'title' => __( 'Selling and redeeming', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'type'  => 'title',
				'id'    => 'kudora_gc_rules',
			),
			$this->f( 'gc_default_expiry_days', array( 'title' => __( 'Default expiry (days)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'type' => 'number', 'custom_attributes' => array( 'min' => 0 ), 'desc' => __( '0 = never expires. Products can override this.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ) ),
			$this->f(
				'gc_allowed_gateways',
				array(
					'title'    => __( 'Payment methods for buying gift cards', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					'type'     => 'multiselect',
					'class'    => 'wc-enhanced-select',
					'options'  => $gateways,
					'desc_tip' => __( 'Leave empty to allow all. Gift cards can never be paid for with gift cards.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				)
			),
			$this->f( 'gc_coupon_field', array( 'title' => __( 'Accept codes in the coupon field', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'type' => 'checkbox', 'desc' => __( 'Customers can type a gift card code into the normal coupon field.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ) ),
			$this->f( 'gc_restore_on_refund', array( 'title' => __( 'Return card money on full refunds', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'type' => 'checkbox', 'desc' => __( 'When an order is fully refunded, put the gift card amount back on the card.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ) ),
			$this->f( 'gc_pdf', array( 'title' => __( 'Attach a PDF', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'type' => 'checkbox', 'desc' => __( 'Attach a printable PDF gift card to the gift card email.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ) ),
			$this->f( 'gc_balance_rate_limit', array( 'title' => __( 'Failed code attempts allowed', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'type' => 'number', 'custom_attributes' => array( 'min' => 3, 'max' => 100 ), 'desc' => __( 'per visitor every 10 minutes (stops code guessing).', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ) ),
			array(
				'type' => 'sectionend',
				'id'   => 'kudora_gc_rules',
			),
		);
	}

	/**
	 * Loyalty.
	 *
	 * @return array
	 */
	protected function get_settings_for_loyalty_section() {
		return array(
			array(
				'title' => __( 'Earning points', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => sprintf(
					/* translators: %s: URL of the loyalty screen */
					__( 'Earning rules, tiers and rewards are under <a href="%s">Marketing → Loyalty</a>.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					esc_url( admin_url( 'admin.php?page=kudora-loyalty' ) )
				),
				'id'    => 'kudora_loyalty_earn',
			),
			$this->f( 'loyalty_label', array( 'title' => __( 'Program name', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'type' => 'text', 'placeholder' => __( 'Points & rewards', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ) ),
			$this->f( 'loyalty_earn_rate', array( 'title' => __( 'Points per 1 unit of currency', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'type' => 'number', 'custom_attributes' => array( 'min' => 0, 'step' => '0.01' ) ) ),
			$this->f(
				'loyalty_earn_basis',
				array(
					'title'   => __( 'Earn on', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					'type'    => 'select',
					'options' => array(
						'subtotal_after_discounts' => __( 'Products after discounts (excl. tax & shipping)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
						'subtotal'                 => __( 'Products before discounts (excl. tax & shipping)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
						'total'                    => __( 'Order total', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					),
				)
			),
			$this->f(
				'loyalty_rounding',
				array(
					'title'   => __( 'Rounding', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					'type'    => 'select',
					'options' => array(
						'floor' => __( 'Round down', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
						'round' => __( 'Round to nearest', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
						'ceil'  => __( 'Round up', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					),
				)
			),
			$this->f(
				'loyalty_earn_status',
				array(
					'title'   => __( 'Give points when the order is', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					'type'    => 'select',
					'options' => array(
						'processing' => __( 'Paid (processing or completed)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
						'completed'  => __( 'Completed', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					),
				)
			),
			$this->f( 'loyalty_earn_on_redeem', array( 'title' => __( 'Earn on orders that use points', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'type' => 'checkbox', 'desc' => __( 'Off: an order that redeems points earns none.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ) ),
			$this->f( 'loyalty_exclude_gift_cards', array( 'title' => __( 'No points for buying gift cards', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'type' => 'checkbox', 'desc' => __( 'Customers earn when they spend the gift card instead.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ) ),
			$this->f(
				'loyalty_tier_basis',
				array(
					'title'   => __( 'Tiers are based on', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					'type'    => 'select',
					'options' => array(
						'lifetime' => __( 'Lifetime points earned (spending points keeps the tier)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
						'balance'  => __( 'Current balance', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					),
				)
			),
			$this->f( 'loyalty_expiry_days', array( 'title' => __( 'Points expire after (days)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'type' => 'number', 'custom_attributes' => array( 'min' => 0 ), 'desc' => __( '0 = never. Oldest points are used first.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ) ),
			$this->f( 'loyalty_expiry_warning_days', array( 'title' => __( 'Warn customers (days before expiry)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'type' => 'number', 'custom_attributes' => array( 'min' => 0 ) ) ),
			array(
				'type' => 'sectionend',
				'id'   => 'kudora_loyalty_earn',
			),
			array(
				'title' => __( 'Bonus points', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'type'  => 'title',
				'id'    => 'kudora_loyalty_actions',
			),
			$this->f( 'loyalty_signup_points', array( 'title' => __( 'Creating an account', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'type' => 'number', 'custom_attributes' => array( 'min' => 0 ) ) ),
			$this->f( 'loyalty_first_order_points', array( 'title' => __( 'First order', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'type' => 'number', 'custom_attributes' => array( 'min' => 0 ) ) ),
			$this->f( 'loyalty_review_points', array( 'title' => __( 'Product review', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'type' => 'number', 'custom_attributes' => array( 'min' => 0 ) ) ),
			$this->f( 'loyalty_review_verified_only', array( 'title' => __( 'Only verified buyers', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'type' => 'checkbox', 'desc' => __( 'Review points only for customers who bought the product.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ) ),
			$this->f( 'loyalty_birthday_points', array( 'title' => __( 'Birthday', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'type' => 'number', 'custom_attributes' => array( 'min' => 0 ), 'desc' => __( 'Adds a birthday field to My Account.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ) ),
			array(
				'type' => 'sectionend',
				'id'   => 'kudora_loyalty_actions',
			),
			array(
				'title' => __( 'Paying with points', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'type'  => 'title',
				'id'    => 'kudora_loyalty_redeem',
			),
			$this->f( 'loyalty_redeem_enabled', array( 'title' => __( 'Allow customers to redeem points', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'type' => 'checkbox' ) ),
			$this->f( 'loyalty_point_value', array( 'title' => __( 'Value of 1 point', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'type' => 'text', 'class' => 'wc_input_price', 'desc' => __( 'e.g. 0.01 = 100 points are worth 1 unit of currency.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ) ),
			$this->f(
				'loyalty_redeem_mode',
				array(
					'title'   => __( 'Discount application', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					'type'    => 'radio',
					'options' => array(
						'customer' => __( 'The customer chooses how many points to use', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
						'auto'     => __( 'Apply the largest allowed discount automatically', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					),
				)
			),
			$this->f(
				'loyalty_max_discount_type',
				array(
					'title'   => __( 'Maximum discount type', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					'type'    => 'select',
					'options' => array(
						'percent' => __( 'Percent of the order', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
						'fixed'   => __( 'Fixed amount', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					),
				)
			),
			$this->f( 'loyalty_max_discount_value', array( 'title' => __( 'Maximum discount', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'type' => 'text', 'class' => 'wc_input_decimal' ) ),
			$this->f( 'loyalty_min_points', array( 'title' => __( 'Minimum points per redemption', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'type' => 'number', 'custom_attributes' => array( 'min' => 0 ) ) ),
			$this->f( 'loyalty_max_points_per_order', array( 'title' => __( 'Maximum points per order', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'type' => 'number', 'custom_attributes' => array( 'min' => 0 ), 'desc' => __( '0 = no limit', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ) ),
			$this->f( 'loyalty_redeem_excluded_products', array( 'title' => __( 'Products that cannot be paid with points', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'type' => 'kudora_ids', 'kudora_kind' => 'products' ) ),
			$this->f( 'loyalty_redeem_excluded_categories', array( 'title' => __( 'Categories that cannot be paid with points', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'type' => 'kudora_ids', 'kudora_kind' => 'categories' ) ),
			array(
				'type' => 'sectionend',
				'id'   => 'kudora_loyalty_redeem',
			),
		);
	}

	/**
	 * Referrals.
	 *
	 * @return array
	 */
	protected function get_settings_for_referrals_section() {
		return array(
			array(
				'title' => __( 'Referral program', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Customers share their link from My Account. Friends get a first-order discount; the referrer is rewarded when the friend’s order is paid.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'id'    => 'kudora_referrals',
			),
			$this->f( 'referral_enabled', array( 'title' => __( 'Enable referrals', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'type' => 'checkbox' ) ),
			$this->f( 'referral_cookie_days', array( 'title' => __( 'Remember a referral for (days)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'type' => 'number', 'custom_attributes' => array( 'min' => 1, 'max' => 365 ) ) ),
			$this->f(
				'referral_friend_type',
				array(
					'title'   => __( 'Friend’s discount type', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					'type'    => 'select',
					'options' => array(
						'percent' => __( 'Percentage', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
						'fixed'   => __( 'Fixed amount', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					),
				)
			),
			$this->f( 'referral_friend_value', array( 'title' => __( 'Friend’s discount', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'type' => 'text', 'class' => 'wc_input_decimal', 'desc' => __( '0 = no friend discount', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ) ),
			$this->f(
				'referral_reward_type',
				array(
					'title'   => __( 'Referrer reward', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					'type'    => 'select',
					'options' => array(
						'points' => __( 'Points', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
						'coupon' => __( 'Coupon (fixed amount)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					),
				)
			),
			$this->f( 'referral_reward_points', array( 'title' => __( 'Reward points', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'type' => 'number', 'custom_attributes' => array( 'min' => 0 ) ) ),
			$this->f( 'referral_reward_amount', array( 'title' => __( 'Reward coupon amount', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'type' => 'text', 'class' => 'wc_input_price' ) ),
			$this->f( 'referral_min_order', array( 'title' => __( 'Minimum order', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'type' => 'text', 'class' => 'wc_input_price', 'desc' => __( 'Products subtotal the friend’s first order needs.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ) ),
			$this->f( 'referral_monthly_cap', array( 'title' => __( 'Rewards per referrer per month', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'type' => 'number', 'custom_attributes' => array( 'min' => 0 ), 'desc' => __( '0 = no limit', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ) ),
			$this->f( 'referral_block_same_ip', array( 'title' => __( 'Block same-IP referrals', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'type' => 'checkbox', 'desc' => __( 'Reject a referral when the friend orders from an IP the referrer used.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ) ),
			array(
				'type' => 'sectionend',
				'id'   => 'kudora_referrals',
			),
		);
	}

	/**
	 * Product / category id picker.
	 *
	 * @param array $field Field.
	 * @return void
	 */
	public function field_ids( $field ) {
		$ids  = Kudora_Settings::arr( substr( $field['id'], strlen( 'kudora_' ) ) );
		$kind = isset( $field['kudora_kind'] ) ? $field['kudora_kind'] : 'products';
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><label for="<?php echo esc_attr( $field['id'] ); ?>"><?php echo esc_html( $field['title'] ); ?></label></th>
			<td class="forminp">
				<?php if ( 'categories' === $kind ) : ?>
					<select id="<?php echo esc_attr( $field['id'] ); ?>" name="<?php echo esc_attr( $field['id'] ); ?>[]" multiple class="wc-enhanced-select" style="min-width:350px">
						<?php foreach ( get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) ) as $term ) : ?>
							<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( in_array( (string) $term->term_id, array_map( 'strval', $ids ), true ) ); ?>><?php echo esc_html( $term->name ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php else : ?>
					<select id="<?php echo esc_attr( $field['id'] ); ?>" name="<?php echo esc_attr( $field['id'] ); ?>[]" multiple class="wc-product-search" style="min-width:350px" data-placeholder="<?php esc_attr_e( 'Search for a product…', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>" data-action="woocommerce_json_search_products_and_variations">
						<?php foreach ( $ids as $id ) : ?>
							<?php $p = wc_get_product( $id ); ?>
							<?php if ( $p ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>" selected><?php echo esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ); ?></option>
							<?php endif; ?>
						<?php endforeach; ?>
					</select>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Gift card code preview.
	 *
	 * @return void
	 */
	public function field_preview() {
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php esc_html_e( 'Example code', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
			<td class="forminp"><code class="kudora-code-preview"><?php echo esc_html( Kudora_Code_Generator::preview( Kudora_Gift_Card_Repo::code_format() ) ); ?></code></td>
		</tr>
		<?php
	}

	/**
	 * Sanitise our custom field types and numeric values.
	 *
	 * @param mixed $value     Value.
	 * @param array $option    Field.
	 * @param mixed $raw_value Raw.
	 * @return mixed
	 */
	public function sanitize( $value, $option, $raw_value ) {
		if ( empty( $option['id'] ) || 0 !== strpos( $option['id'], 'kudora_' ) ) {
			return $value;
		}
		if ( isset( $option['type'] ) && 'kudora_ids' === $option['type'] ) {
			return array_values( array_filter( array_map( 'absint', (array) $raw_value ) ) );
		}
		if ( in_array( $option['id'], array( 'kudora_gc_code_prefix', 'kudora_gc_code_suffix' ), true ) ) {
			return Kudora_Code_Generator::clean_affix( $value );
		}
		if ( 'kudora_discounts_url_param' === $option['id'] ) {
			return sanitize_key( $value );
		}
		if ( 'kudora_gc_code_length' === $option['id'] ) {
			return max( 8, min( 32, absint( $value ) ) );
		}
		if ( in_array( $option['id'], array( 'kudora_loyalty_point_value', 'kudora_loyalty_max_discount_value', 'kudora_referral_friend_value', 'kudora_referral_reward_amount', 'kudora_referral_min_order', 'kudora_loyalty_earn_rate' ), true ) ) {
			return wc_format_decimal( $value );
		}
		return $value;
	}

	/**
	 * After saving: rewrite rules may need a flush (modules), tiers resync.
	 *
	 * @return void
	 */
	public function after_save() {
		update_option( 'kudora_flush_rewrite', 1, true );
		delete_transient( 'kudora_flushed_recently' );
	}
}
