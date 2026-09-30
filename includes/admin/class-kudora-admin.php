<?php
/**
 * Admin bootstrap: menus (under Marketing, or WooCommerce when Marketing is not
 * available), settings tab, assets, the order "Kudora" box (gift card refunds,
 * store credit, points) and the user profile points field.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin.
 */
class Kudora_Admin {

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menus' ), 20 );
		add_filter( 'woocommerce_get_settings_pages', array( $this, 'settings_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( KUDORA_FILE ), array( $this, 'action_links' ) );

		if ( Kudora_Settings::module( 'discounts' ) ) {
			( new Kudora_Admin_Rules( 'discount' ) )->register();
			( new Kudora_Admin_Coupon_Generator() )->register();
		}
		if ( Kudora_Settings::module( 'gift_cards' ) ) {
			( new Kudora_Admin_Gift_Cards() )->register();
		}
		if ( Kudora_Settings::module( 'loyalty' ) ) {
			( new Kudora_Admin_Rules( 'earn' ) )->register();
			( new Kudora_Admin_Loyalty() )->register();
			add_action( 'show_user_profile', array( $this, 'user_points' ) );
			add_action( 'edit_user_profile', array( $this, 'user_points' ) );
		}
		( new Kudora_Admin_Import_Export() )->register();

		add_action( 'add_meta_boxes', array( $this, 'order_box' ), 30 );
		add_action( 'wp_ajax_kudora_order_action', array( $this, 'order_action' ) );
	}

	/**
	 * Parent menu slug: Marketing when WooCommerce Admin provides it.
	 *
	 * @return string
	 */
	public static function parent_slug() {
		global $admin_page_hooks;
		return isset( $admin_page_hooks['woocommerce-marketing'] ) ? 'woocommerce-marketing' : 'woocommerce';
	}

	/**
	 * Admin pages.
	 *
	 * @return void
	 */
	public function menus() {
		$parent = self::parent_slug();
		if ( Kudora_Settings::module( 'discounts' ) ) {
			add_submenu_page( $parent, __( 'Discount rules', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), __( 'Discount rules', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'manage_woocommerce', 'kudora-discounts', array( 'Kudora_Admin_Rules', 'render_discounts' ) );
			add_submenu_page( $parent, __( 'Generate coupons', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), __( 'Generate coupons', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'manage_woocommerce', 'kudora-generate-coupons', array( 'Kudora_Admin_Coupon_Generator', 'render' ) );
		}
		if ( Kudora_Settings::module( 'gift_cards' ) ) {
			add_submenu_page( $parent, __( 'Gift cards', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), __( 'Gift cards', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'manage_woocommerce', 'kudora-gift-cards', array( 'Kudora_Admin_Gift_Cards', 'render' ) );
		}
		if ( Kudora_Settings::module( 'loyalty' ) ) {
			add_submenu_page( $parent, __( 'Loyalty', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), __( 'Loyalty', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'manage_woocommerce', 'kudora-loyalty', array( 'Kudora_Admin_Loyalty', 'render' ) );
		}
	}

	/**
	 * Register the settings tab.
	 *
	 * @param array $pages Pages.
	 * @return array
	 */
	public function settings_page( $pages ) {
		$pages[] = new Kudora_Settings_Page();
		return $pages;
	}

	/**
	 * Settings link on the plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=kudora' ) ) . '">' . esc_html__( 'Settings', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) . '</a>' );
		return $links;
	}

	/**
	 * Whether the current screen is one of ours (or a screen we extend).
	 *
	 * @param string $hook Hook suffix.
	 * @return bool
	 */
	protected static function is_our_screen( $hook ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- screen detection only.
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( 0 === strpos( $page, 'kudora-' ) || ( 'wc-settings' === $page && isset( $_GET['tab'] ) && 'kudora' === sanitize_key( wp_unslash( $_GET['tab'] ) ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}
		return $screen && in_array( $screen->id, array( 'shop_coupon', 'product', 'edit-shop_coupon', 'user-edit', 'profile', 'shop_order', 'woocommerce_page_wc-orders' ), true );
	}

	/**
	 * Admin assets.
	 *
	 * @param string $hook Hook suffix.
	 * @return void
	 */
	public function assets( $hook ) {
		wp_register_style( 'kudora-admin', KUDORA_URL . 'assets/css/admin.css', array(), KUDORA_VERSION );
		wp_register_script( 'kudora-admin-rules', KUDORA_URL . 'assets/js/admin-rules.js', array( 'jquery', 'wc-enhanced-select', 'wp-i18n' ), KUDORA_VERSION, true );
		wp_set_script_translations( 'kudora-admin-rules', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
		wp_register_script( 'kudora-admin', KUDORA_URL . 'assets/js/admin.js', array( 'jquery', 'wp-i18n' ), KUDORA_VERSION, true );
		wp_set_script_translations( 'kudora-admin', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
		if ( ! self::is_our_screen( $hook ) ) {
			return;
		}
		wp_enqueue_style( 'kudora-admin' );
		wp_enqueue_script( 'kudora-admin' );
		wp_localize_script(
			'kudora-admin',
			'kudoraAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'kudora-admin' ),
			)
		);
		wp_localize_script( 'kudora-admin-rules', 'kudoraRules', self::rules_js_data() );
		wp_enqueue_script( 'kudora-admin-rules' );
	}

	/**
	 * Data for the condition/scope builders.
	 *
	 * @return array
	 */
	public static function rules_js_data() {
		$roles = array( 'guest' => __( 'Guest', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		foreach ( wp_roles()->get_names() as $slug => $name ) {
			$roles[ $slug ] = translate_user_role( $name );
		}
		$cats = array();
		foreach ( get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) ) as $t ) {
			$cats[ $t->term_id ] = $t->name;
		}
		$tags = array();
		foreach ( get_terms( array( 'taxonomy' => 'product_tag', 'hide_empty' => false ) ) as $t ) {
			$tags[ $t->term_id ] = $t->name;
		}
		$tiers = array();
		foreach ( Kudora_Points_Repo::tiers() as $t ) {
			$tiers[ $t['id'] ] = $t['name'];
		}
		return array(
			'schema'     => Kudora_Conditions::js_schema(),
			'roles'      => $roles,
			'countries'  => WC()->countries ? WC()->countries->get_countries() : array(),
			'categories' => $cats,
			'tags'       => $tags,
			'tiers'      => $tiers,
			'weekdays'   => array(
				1 => __( 'Monday', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				2 => __( 'Tuesday', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				3 => __( 'Wednesday', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				4 => __( 'Thursday', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				5 => __( 'Friday', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				6 => __( 'Saturday', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				7 => __( 'Sunday', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			),
			'productNames' => self::product_names_for_js(),
			'currency'     => get_woocommerce_currency_symbol(),
			'searchNonce'  => wp_create_nonce( 'search-products' ),
		);
	}

	/**
	 * Names of products referenced by rules/coupons on screen (for pre-filled pickers).
	 *
	 * @return array<int,string>
	 */
	protected static function product_names_for_js() {
		$ids = array();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only.
		if ( isset( $_GET['rule'] ) ) {
			$rule = Kudora_Rules_Repo::get( absint( $_GET['rule'] ) );
			if ( $rule ) {
				$json = wp_json_encode( array( $rule['conditions'], $rule['config'] ) );
				preg_match_all( '/"(?:products|exclude_products)":\[([0-9,]*)\]/', $json, $m );
				foreach ( $m[1] as $list ) {
					$ids = array_merge( $ids, array_filter( array_map( 'intval', explode( ',', $list ) ) ) );
				}
				if ( ! empty( $rule['config']['auto_add'] ) ) {
					$ids[] = (int) $rule['config']['auto_add'];
				}
			}
		}
		if ( isset( $_GET['post'] ) && 'shop_coupon' === get_post_type( absint( $_GET['post'] ) ) ) {
			$json = wp_json_encode( array( get_post_meta( absint( $_GET['post'] ), '_kudora_conditions', true ), get_post_meta( absint( $_GET['post'] ), '_kudora_bxgy', true ) ) );
			preg_match_all( '/"(?:products|exclude_products)":\[([0-9,]*)\]/', $json, $m );
			foreach ( $m[1] as $list ) {
				$ids = array_merge( $ids, array_filter( array_map( 'intval', explode( ',', $list ) ) ) );
			}
		}
		// phpcs:enable
		$names = array();
		foreach ( array_unique( $ids ) as $id ) {
			$p = wc_get_product( $id );
			if ( $p ) {
				$names[ $id ] = wp_strip_all_tags( $p->get_formatted_name() );
			}
		}
		return $names;
	}

	/* ---------------------------------------------------------------------
	 * Order box
	 * ------------------------------------------------------------------- */

	/**
	 * Add the box on the order edit screen (HPOS and legacy).
	 *
	 * @return void
	 */
	public function order_box() {
		$screen = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'kudora-order', __( 'Gift cards & points', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), array( $this, 'render_order_box' ), $screen, 'side', 'default' );
	}

	/**
	 * Render the order box.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post or order.
	 * @return void
	 */
	public function render_order_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		include KUDORA_DIR . 'includes/admin/views/order-box.php';
	}

	/**
	 * Order box actions: refund to card, issue store credit.
	 *
	 * @return void
	 */
	public function order_action() {
		check_ajax_referer( 'kudora-admin', 'nonce' );
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ), 403 );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ), 404 );
		}
		$do     = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$amount = isset( $_POST['amount'] ) ? Kudora_Money::parse( sanitize_text_field( wp_unslash( $_POST['amount'] ) ) ) : 0.0;
		if ( 'refund_to_card' === $do ) {
			$card_id = isset( $_POST['card_id'] ) ? absint( $_POST['card_id'] ) : 0;
			$res     = Kudora_Gift_Card_Redeem::refund_to_card( $order, $card_id, $amount, __( 'Refunded by admin', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		} elseif ( 'store_credit' === $do ) {
			$res = self::issue_store_credit( $order, $amount );
		} else {
			$res = new WP_Error( 'kudora_action', __( 'Unknown action.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		}
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => __( 'Done.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ) );
	}

	/**
	 * Refund as store credit: a new gift card emailed to the customer.
	 *
	 * @param WC_Order $order  Order.
	 * @param float    $amount Amount.
	 * @return int|WP_Error Card id.
	 */
	public static function issue_store_credit( $order, $amount ) {
		$amount = Kudora_Money::round( $amount );
		$max    = Kudora_Money::round( (float) $order->get_total() + Kudora_Gift_Card_Redeem::order_amount( $order ) - (float) $order->get_meta( '_kudora_store_credit_total' ) );
		if ( $amount <= 0 || $amount > $max + 0.00001 ) {
			/* translators: %s: amount */
			return new WP_Error( 'kudora_amount', sprintf( __( 'Enter an amount between 0 and %s.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), Kudora_Money::plain( $max ) ) );
		}
		try {
			$id = Kudora_Gift_Card_Repo::create(
				array(
					'initial_amount'  => $amount,
					'order_id'        => $order->get_id(),
					'purchaser_id'    => $order->get_customer_id(),
					'purchaser_email' => $order->get_billing_email(),
					'owner_id'        => $order->get_customer_id(),
					'recipient_name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
					'recipient_email' => $order->get_billing_email(),
					'sender_name'     => get_bloginfo( 'name' ),
					'source'          => 'refund',
					/* translators: %s: order number */
					'ledger_note'     => sprintf( __( 'Store credit for order #%s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), $order->get_order_number() ),
				)
			);
		} catch ( RuntimeException $e ) {
			return new WP_Error( 'kudora_card', $e->getMessage() );
		}
		$order->update_meta_data( '_kudora_store_credit_total', Kudora_Money::round( (float) $order->get_meta( '_kudora_store_credit_total' ) + $amount ) );
		$order->save_meta_data();
		$card = Kudora_Gift_Card_Repo::get( $id );
		/* translators: 1: amount, 2: masked code */
		$order->add_order_note( sprintf( __( 'Issued %1$s store credit as gift card %2$s.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), Kudora_Money::plain( $amount ), Kudora_Gift_Card_Repo::mask( $card['code'] ) ) );
		( new Kudora_Gift_Card_Issuer() )->deliver( $id );
		return $id;
	}

	/* ---------------------------------------------------------------------
	 * User profile
	 * ------------------------------------------------------------------- */

	/**
	 * Points on the user profile.
	 *
	 * @param WP_User $user User.
	 * @return void
	 */
	public function user_points( $user ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$row  = Kudora_Points_Repo::row( $user->ID );
		$tier = Kudora_Points_Repo::tier_of( $user->ID );
		?>
		<h2><?php esc_html_e( 'Loyalty points', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><?php esc_html_e( 'Balance', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
				<td>
					<strong><?php echo esc_html( number_format_i18n( $row['balance'] ) ); ?></strong>
					<?php /* translators: %s: lifetime points */ ?>
					<span class="description"><?php echo esc_html( sprintf( __( 'Lifetime earned: %s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), number_format_i18n( $row['lifetime_earned'] ) ) ); ?></span>
					<?php if ( $tier ) : ?>
						<span class="kudora-tier-badge" style="--kudora-tier: <?php echo esc_attr( $tier['color'] ); ?>"><?php echo esc_html( $tier['name'] ); ?></span>
					<?php endif; ?>
					<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=kudora-loyalty&tab=customers&customer=' . $user->ID ) ); ?>"><?php esc_html_e( 'Adjust points and see history', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></a></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Standard admin page header with tabs.
	 *
	 * @param string $title Title.
	 * @param string $page  Page slug.
	 * @param array  $tabs  Tab key => label.
	 * @param string $current Current tab.
	 * @return void
	 */
	public static function header( $title, $page, array $tabs, $current ) {
		echo '<div class="wrap kudora-admin"><h1 class="wp-heading-inline">' . esc_html( $title ) . '</h1>';
		if ( $tabs ) {
			echo '<nav class="nav-tab-wrapper wp-clearfix" aria-label="' . esc_attr__( 'Secondary menu', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) . '">';
			foreach ( $tabs as $key => $label ) {
				printf(
					'<a href="%1$s" class="nav-tab %2$s" %3$s>%4$s</a>',
					esc_url( admin_url( 'admin.php?page=' . $page . '&tab=' . $key ) ),
					$key === $current ? 'nav-tab-active' : '',
					$key === $current ? 'aria-current="page"' : '',
					esc_html( $label )
				);
			}
			echo '</nav>';
		}
		settings_errors( 'kudora' );
	}

	/**
	 * Add an admin notice for the next screen.
	 *
	 * @param string $message Message.
	 * @param string $type    success|error.
	 * @return void
	 */
	public static function flash( $message, $type = 'success' ) {
		set_transient( 'kudora_flash_' . get_current_user_id(), array( $message, $type ), 60 );
	}

	/**
	 * Print a flashed notice.
	 *
	 * @return void
	 */
	public static function print_flash() {
		$flash = get_transient( 'kudora_flash_' . get_current_user_id() );
		if ( is_array( $flash ) ) {
			delete_transient( 'kudora_flash_' . get_current_user_id() );
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( 'error' === $flash[1] ? 'error' : 'success' ), wp_kses_post( $flash[0] ) );
		}
	}
}
