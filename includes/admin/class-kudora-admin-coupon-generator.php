<?php
/**
 * Marketing → Generate coupons: create many unique coupons from a template coupon
 * (Booknetic code format), download the codes, and import/export coupons.
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bulk coupon generator.
 */
class Kudora_Admin_Coupon_Generator {

	/**
	 * Max coupons per run.
	 */
	const MAX = 1000;

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post_kudora_generate_coupons', array( __CLASS__, 'handle' ) );
	}

	/**
	 * Page.
	 *
	 * @return void
	 */
	public static function render() {
		$tabs = array(
			'generate' => __( 'Generate', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'import'   => __( 'Import / export', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$tab = isset( $_GET['tab'] ) && 'import' === $_GET['tab'] ? 'import' : 'generate';
		Kudora_Admin::header( __( 'Generate coupons', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'kudora-generate-coupons', $tabs, $tab );
		Kudora_Admin::print_flash();
		if ( 'import' === $tab ) {
			Kudora_Admin_Import_Export::render_coupons();
			echo '</div>';
			return;
		}
		$coupons = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		?>
		<div class="kudora-card-box">
			<p class="description"><?php esc_html_e( 'Pick an existing coupon as the template: every new coupon copies its discount, limits and restrictions, with its own unique code. Tip: set "Usage limit per coupon" to 1 on the template for single-use codes.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="kudora_generate_coupons" />
				<?php wp_nonce_field( 'kudora_generate_coupons' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="kudora_template"><?php esc_html_e( 'Template coupon', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label></th>
						<td>
							<select id="kudora_template" name="template" required>
								<option value=""><?php esc_html_e( 'Choose…', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></option>
								<?php foreach ( $coupons as $p ) : ?>
									<option value="<?php echo esc_attr( $p->ID ); ?>"><?php echo esc_html( $p->post_title ); ?></option>
								<?php endforeach; ?>
							</select>
							<?php /* translators: %s: URL */ ?>
							<p class="description"><?php echo wp_kses_post( sprintf( __( 'No suitable coupon yet? <a href="%s">Create one</a> first.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), esc_url( admin_url( 'post-new.php?post_type=shop_coupon' ) ) ) ); ?></p>
						</td>
					</tr>
					<tr><th><label for="kudora_gen_count"><?php esc_html_e( 'How many', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label></th><td><input type="number" id="kudora_gen_count" name="count" min="1" max="<?php echo esc_attr( self::MAX ); ?>" value="50" class="small-text" /></td></tr>
					<tr><th><label for="kudora_gen_prefix"><?php esc_html_e( 'Code prefix', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label></th><td><input type="text" id="kudora_gen_prefix" name="prefix" maxlength="20" value="SALE-" /></td></tr>
					<tr><th><label for="kudora_gen_suffix"><?php esc_html_e( 'Code suffix', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label></th><td><input type="text" id="kudora_gen_suffix" name="suffix" maxlength="20" /></td></tr>
					<tr><th><label for="kudora_gen_charset"><?php esc_html_e( 'Random characters', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label></th><td>
						<select id="kudora_gen_charset" name="charset">
							<option value="alnum"><?php esc_html_e( 'Letters and numbers', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></option>
							<option value="letters"><?php esc_html_e( 'Letters only', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></option>
							<option value="digits"><?php esc_html_e( 'Numbers only', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></option>
						</select>
					</td></tr>
					<tr><th><label for="kudora_gen_length"><?php esc_html_e( 'Random length', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label></th><td><input type="number" id="kudora_gen_length" name="length" min="4" max="20" value="8" class="small-text" /></td></tr>
					<tr><th><label for="kudora_gen_emails"><?php esc_html_e( 'One coupon per email', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label></th><td><textarea id="kudora_gen_emails" name="emails" rows="4" class="large-text" placeholder="anna@example.com&#10;ben@example.com"></textarea><p class="description"><?php esc_html_e( 'Optional. When filled, one coupon is created per email and restricted to it (the count is ignored).', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></p></td></tr>
				</table>
				<?php submit_button( __( 'Generate and download CSV', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ); ?>
			</form>
		</div>
		</div>
		<?php
	}

	/**
	 * Generate.
	 *
	 * @return void
	 */
	public static function handle() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'kudora_generate_coupons' );
		$p        = map_deep( wp_unslash( $_POST ), 'sanitize_text_field' );
		$template = new WC_Coupon( absint( isset( $p['template'] ) ? $p['template'] : 0 ) );
		if ( ! $template->get_id() ) {
			Kudora_Admin::flash( __( 'Choose a template coupon.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'error' );
			wp_safe_redirect( admin_url( 'admin.php?page=kudora-generate-coupons' ) );
			exit;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$raw    = isset( $_POST['emails'] ) ? sanitize_textarea_field( wp_unslash( $_POST['emails'] ) ) : '';
		$emails = array_values( array_filter( array_map( 'sanitize_email', preg_split( '/[\s,;]+/', $raw ) ), 'is_email' ) );
		$count  = $emails ? count( $emails ) : max( 1, min( self::MAX, absint( isset( $p['count'] ) ? $p['count'] : 1 ) ) );
		$count  = min( self::MAX, $count );
		$format = array(
			'prefix'  => isset( $p['prefix'] ) ? $p['prefix'] : '',
			'suffix'  => isset( $p['suffix'] ) ? $p['suffix'] : '',
			'charset' => isset( $p['charset'] ) ? $p['charset'] : 'alnum',
			'length'  => max( 4, min( 20, absint( isset( $p['length'] ) ? $p['length'] : 8 ) ) ),
		);
		$batch = 'k' . wp_generate_password( 8, false, false );
		$rows  = array( array( 'code', 'email', 'discount_type', 'amount', 'expiry_date' ) );
		$data  = $template->get_data();
		unset( $data['id'], $data['code'], $data['usage_count'], $data['used_by'], $data['date_created'], $data['date_modified'], $data['meta_data'] );
		for ( $i = 0; $i < $count; $i++ ) {
			$code = strtolower(
				Kudora_Code_Generator::unique(
					$format,
					function ( $c ) {
						return (bool) wc_get_coupon_id_by_code( $c );
					}
				)
			);
			$c    = new WC_Coupon();
			$c->set_props( $data );
			$c->set_code( $code );
			foreach ( $template->get_meta_data() as $meta ) {
				if ( 0 === strpos( $meta->key, '_kudora_' ) ) {
					$c->update_meta_data( $meta->key, $meta->value );
				}
			}
			$c->update_meta_data( '_kudora_batch', $batch );
			if ( $emails ) {
				$c->set_email_restrictions( array( $emails[ $i ] ) );
			}
			$c->save();
			$exp    = $c->get_date_expires();
			$rows[] = array( strtoupper( $code ), $emails ? $emails[ $i ] : '', $c->get_discount_type(), $c->get_amount(), $exp ? $exp->date( 'Y-m-d' ) : '' );
		}
		Kudora_CSV::download( 'coupons-' . $batch . '.csv', $rows );
	}
}
