<?php
/**
 * Rules admin: list and editor for discount rules (Marketing → Discount rules) and
 * loyalty earning rules (Marketing → Loyalty → Earning rules).
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rules admin.
 */
class Kudora_Admin_Rules {

	/**
	 * Module: discount|earn.
	 *
	 * @var string
	 */
	protected $module;

	/**
	 * Whether the shared handlers are hooked.
	 *
	 * @var bool
	 */
	protected static $hooked = false;

	/**
	 * Constructor.
	 *
	 * @param string $module Module.
	 */
	public function __construct( $module ) {
		$this->module = $module;
	}

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public function register() {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;
		add_action( 'admin_post_kudora_save_rule', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_kudora_rule_action', array( __CLASS__, 'handle_action' ) );
	}

	/**
	 * Base URL of a module's rules screen.
	 *
	 * @param string $module Module.
	 * @return string
	 */
	public static function base_url( $module ) {
		return 'earn' === $module ? admin_url( 'admin.php?page=kudora-loyalty&tab=rules' ) : admin_url( 'admin.php?page=kudora-discounts' );
	}

	/**
	 * Marketing → Discount rules.
	 *
	 * @return void
	 */
	public static function render_discounts() {
		Kudora_Admin::header( __( 'Discount rules', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'kudora-discounts', array(), '' );
		self::render( 'discount' );
		echo '</div>';
	}

	/**
	 * List or editor.
	 *
	 * @param string $module Module.
	 * @return void
	 */
	public static function render( $module ) {
		Kudora_Admin::print_flash();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen routing.
		$edit = isset( $_GET['rule'] ) ? sanitize_text_field( wp_unslash( $_GET['rule'] ) ) : '';
		// phpcs:enable
		if ( '' !== $edit ) {
			$rule = 'new' === $edit ? null : Kudora_Rules_Repo::get( absint( $edit ) );
			self::render_edit( $module, $rule );
			return;
		}
		self::render_list( $module );
	}

	/**
	 * Rules list.
	 *
	 * @param string $module Module.
	 * @return void
	 */
	protected static function render_list( $module ) {
		$rules = Kudora_Rules_Repo::all( $module );
		$types = Kudora_Discount_Engine::types();
		$base  = self::base_url( $module );
		?>
		<p>
			<a href="<?php echo esc_url( add_query_arg( 'rule', 'new', $base ) ); ?>" class="page-title-action kudora-add"><?php echo esc_html( 'earn' === $module ? __( 'Add earning rule', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) : __( 'Add discount rule', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ); ?></a>
		</p>
		<?php if ( 'earn' === $module ) : ?>
			<p class="description"><?php esc_html_e( 'Every matching rule adds its points to the base points of an order (Booknetic-style: all conditions must match). Multiplier rules multiply the base points of the matching products.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></p>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'Rules run in priority order (lowest number first). An exclusive rule stops the rules after it.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></p>
		<?php endif; ?>
		<table class="wp-list-table widefat fixed striped kudora-rules-table">
			<thead>
				<tr>
					<th scope="col" class="column-primary"><?php esc_html_e( 'Name', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Priority', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Schedule', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Used', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $rules ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No rules yet.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rules as $rule ) : ?>
					<?php
					$edit_url = add_query_arg( 'rule', $rule['id'], $base );
					$act      = function ( $do ) use ( $rule, $module ) {
						return wp_nonce_url( admin_url( 'admin-post.php?action=kudora_rule_action&do=' . $do . '&id=' . $rule['id'] . '&module=' . $module ), 'kudora_rule_action_' . $rule['id'] );
					};
					if ( 'earn' === $module ) {
						$type_label = 'multiplier' === ( isset( $rule['config']['mode'] ) ? $rule['config']['mode'] : 'fixed' )
							/* translators: %s: multiplier */
							? sprintf( __( '%s× points', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), wc_format_localized_decimal( $rule['config']['multiplier'] ) )
							/* translators: %d: points */
							: sprintf( __( '+%d points', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), (int) ( isset( $rule['config']['award'] ) ? $rule['config']['award'] : 0 ) );
					} else {
						$type_label = isset( $types[ $rule['type'] ] ) ? $types[ $rule['type'] ] : $rule['type'];
					}
					?>
					<tr>
						<td class="column-primary">
							<strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( '' !== $rule['name'] ? $rule['name'] : __( '(no name)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ); ?></a></strong>
							<?php if ( $rule['exclusive'] ) : ?><span class="kudora-badge"><?php esc_html_e( 'Exclusive', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></span><?php endif; ?>
							<div class="row-actions">
								<span class="edit"><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></a> | </span>
								<span><a href="<?php echo esc_url( $act( 'duplicate' ) ); ?>"><?php esc_html_e( 'Duplicate', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></a> | </span>
								<span class="trash"><a href="<?php echo esc_url( $act( 'delete' ) ); ?>" class="kudora-confirm" data-confirm="<?php esc_attr_e( 'Delete this rule?', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>"><?php esc_html_e( 'Delete', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></a></span>
							</div>
						</td>
						<td><?php echo esc_html( $type_label ); ?></td>
						<td>
							<a href="<?php echo esc_url( $act( $rule['status'] ? 'disable' : 'enable' ) ); ?>" class="kudora-toggle <?php echo $rule['status'] ? 'is-on' : ''; ?>" role="switch" aria-checked="<?php echo $rule['status'] ? 'true' : 'false'; ?>">
								<span class="screen-reader-text"><?php echo esc_html( $rule['status'] ? __( 'Disable', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) : __( 'Enable', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ); ?></span>
							</a>
						</td>
						<td><?php echo esc_html( $rule['priority'] ); ?></td>
						<td>
							<?php
							$from = $rule['starts_at'] ? get_date_from_gmt( $rule['starts_at'], get_option( 'date_format' ) ) : '';
							$to   = $rule['ends_at'] ? get_date_from_gmt( $rule['ends_at'], get_option( 'date_format' ) ) : '';
							echo esc_html( $from || $to ? trim( $from . ' – ' . $to, ' –' ) : __( 'Always', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
							?>
						</td>
						<td><?php echo esc_html( null !== $rule['usage_limit'] ? $rule['usage_count'] . ' / ' . $rule['usage_limit'] : (string) $rule['usage_count'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Editor.
	 *
	 * @param string     $module Module.
	 * @param array|null $rule   Rule.
	 * @return void
	 */
	protected static function render_edit( $module, $rule ) {
		$r    = $rule ? $rule : array(
			'id'          => 0,
			'type'        => 'earn' === $module ? 'earn' : 'product_adjustment',
			'name'        => '',
			'status'      => 1,
			'priority'    => 10,
			'exclusive'   => 0,
			'conditions'  => array(),
			'config'      => array(),
			'starts_at'   => null,
			'ends_at'     => null,
			'usage_limit' => null,
		);
		$c    = $r['config'];
		$get  = function ( $key, $default = '' ) use ( $c ) {
			return isset( $c[ $key ] ) ? $c[ $key ] : $default;
		};
		$base = self::base_url( $module );
		?>
		<p><a href="<?php echo esc_url( $base ); ?>">&larr; <?php esc_html_e( 'All rules', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></a></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="kudora-rule-form" data-kudora-rule-form>
			<input type="hidden" name="action" value="kudora_save_rule" />
			<input type="hidden" name="module" value="<?php echo esc_attr( $module ); ?>" />
			<input type="hidden" name="id" value="<?php echo esc_attr( $r['id'] ); ?>" />
			<?php wp_nonce_field( 'kudora_save_rule' ); ?>

			<div class="kudora-card-box">
				<h2><?php esc_html_e( 'Rule', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="kudora_name"><?php esc_html_e( 'Name', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label></th>
						<td><input type="text" id="kudora_name" name="name" class="regular-text" value="<?php echo esc_attr( $r['name'] ); ?>" required /></td>
					</tr>
					<?php if ( 'discount' === $module ) : ?>
						<tr>
							<th><label for="kudora_type"><?php esc_html_e( 'Type', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label></th>
							<td>
								<select id="kudora_type" name="type" data-kudora-type>
									<?php foreach ( Kudora_Discount_Engine::types() as $key => $label ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $r['type'], $key ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr data-show-types="cart_discount bxgy bundle free_shipping product_adjustment bulk">
							<th><label for="kudora_label"><?php esc_html_e( 'Label shown to customers', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label></th>
							<td><input type="text" id="kudora_label" name="config[label]" class="regular-text" value="<?php echo esc_attr( $get( 'label' ) ); ?>" placeholder="<?php esc_attr_e( 'Defaults to the rule name', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>" /></td>
						</tr>
					<?php endif; ?>
					<tr>
						<th><?php esc_html_e( 'Status', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
						<td><label><input type="checkbox" name="status" value="1" <?php checked( $r['status'] ); ?> /> <?php esc_html_e( 'Active', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label></td>
					</tr>
					<tr>
						<th><label for="kudora_priority"><?php esc_html_e( 'Priority', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label></th>
						<td>
							<input type="number" id="kudora_priority" name="priority" class="small-text" value="<?php echo esc_attr( $r['priority'] ); ?>" />
							<label><input type="checkbox" name="exclusive" value="1" <?php checked( $r['exclusive'] ); ?> /> <?php esc_html_e( 'Exclusive — when this rule applies, skip the rules after it', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Schedule', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
						<td>
							<label><?php esc_html_e( 'From', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?> <input type="datetime-local" name="starts_at" value="<?php echo esc_attr( Kudora_Admin_Fields::local_datetime( $r['starts_at'] ) ); ?>" /></label>
							<label><?php esc_html_e( 'until', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?> <input type="datetime-local" name="ends_at" value="<?php echo esc_attr( Kudora_Admin_Fields::local_datetime( $r['ends_at'] ) ); ?>" /></label>
						</td>
					</tr>
					<?php if ( 'discount' === $module ) : ?>
						<tr>
							<th><label for="kudora_usage_limit"><?php esc_html_e( 'Usage limit', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label></th>
							<td>
								<input type="number" min="0" id="kudora_usage_limit" name="usage_limit" class="small-text" value="<?php echo esc_attr( null !== $r['usage_limit'] ? $r['usage_limit'] : '' ); ?>" placeholder="∞" />
								<span class="description"><?php esc_html_e( 'Paid orders that may use this rule in total.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></span>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'With coupons', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
							<td><label><input type="checkbox" name="config[skip_with_coupons]" value="1" <?php checked( (bool) $get( 'skip_with_coupons' ) ); ?> /> <?php esc_html_e( 'Do not apply when the customer uses a coupon', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label></td>
						</tr>
					<?php endif; ?>
				</table>
			</div>

			<div class="kudora-card-box">
				<h2><?php esc_html_e( 'What it does', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></h2>
				<?php if ( 'earn' === $module ) : ?>
					<table class="form-table" role="presentation">
						<tr>
							<th><?php esc_html_e( 'Reward', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
							<td>
								<label><input type="radio" name="config[mode]" value="fixed" <?php checked( 'multiplier' !== $get( 'mode', 'fixed' ) ); ?> /> <?php esc_html_e( 'Award points', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
								<input type="number" min="0" name="config[award]" class="small-text" value="<?php echo esc_attr( $get( 'award', 0 ) ); ?>" aria-label="<?php esc_attr_e( 'Points', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>" />
								<br />
								<label><input type="radio" name="config[mode]" value="multiplier" <?php checked( 'multiplier', $get( 'mode' ) ); ?> /> <?php esc_html_e( 'Multiply points by', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
								<input type="text" name="config[multiplier]" class="short wc_input_decimal" value="<?php echo esc_attr( wc_format_localized_decimal( $get( 'multiplier', 2 ) ) ); ?>" aria-label="<?php esc_attr_e( 'Multiplier', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>" />
								<span class="description"><?php esc_html_e( 'for the products below', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></span>
							</td>
						</tr>
					</table>
					<?php Kudora_Admin_Fields::scope( 'config[scope]', $get( 'scope', array() ), __( 'Multiplier applies to', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ); ?>
				<?php else : ?>
					<div data-show-types="product_adjustment cart_discount">
						<p class="kudora-inline">
							<label for="kudora_adjust"><?php esc_html_e( 'Discount', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
							<input type="text" class="short wc_input_decimal" name="config[value]" value="<?php echo esc_attr( wc_format_localized_decimal( $get( 'value', 10 ) ) ); ?>" aria-label="<?php esc_attr_e( 'Discount value', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>" />
							<select id="kudora_adjust" name="config[adjust]">
								<option value="percent" <?php selected( 'percent', $get( 'adjust', 'percent' ) ); ?>><?php esc_html_e( '% off', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></option>
								<option value="fixed" <?php selected( 'fixed', $get( 'adjust' ) ); ?>><?php esc_html_e( 'amount off', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></option>
								<option value="fixed_price" <?php selected( 'fixed_price', $get( 'adjust' ) ); ?> data-show-types="product_adjustment"><?php esc_html_e( 'fixed price', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></option>
							</select>
							<span data-show-types="cart_discount">
								<label for="kudora_max"><?php esc_html_e( 'up to', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
								<input type="text" id="kudora_max" class="short wc_input_price" name="config[max]" value="<?php echo esc_attr( $get( 'max' ) ? wc_format_localized_price( $get( 'max' ) ) : '' ); ?>" placeholder="∞" />
							</span>
						</p>
					</div>
					<div data-show-types="bulk">
						<p class="kudora-inline">
							<label for="kudora_count_by"><?php esc_html_e( 'Count quantity', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
							<select id="kudora_count_by" name="config[count_by]">
								<option value="line" <?php selected( 'line', $get( 'count_by', 'line' ) ); ?>><?php esc_html_e( 'per cart line', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></option>
								<option value="product" <?php selected( 'product', $get( 'count_by' ) ); ?>><?php esc_html_e( 'per product (all variations together)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></option>
								<option value="scope" <?php selected( 'scope', $get( 'count_by' ) ); ?>><?php esc_html_e( 'across all matching products', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></option>
							</select>
							<label><input type="checkbox" name="config[show_table]" value="1" <?php checked( (bool) $get( 'show_table' ) ); ?> /> <?php esc_html_e( 'Show a pricing table on product pages', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
						</p>
						<table class="widefat kudora-ranges" data-kudora-ranges>
							<thead><tr><th><?php esc_html_e( 'Min quantity', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Max quantity', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Discount', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th><th></th></tr></thead>
							<tbody>
								<?php
								$ranges = (array) $get( 'ranges', array() );
								if ( ! $ranges ) {
									$ranges = array( array( 'min' => 2, 'max' => '', 'adjust' => 'percent', 'value' => 5 ) );
								}
								foreach ( $ranges as $range ) :
									?>
									<tr>
										<td><input type="number" min="1" class="small-text" name="ranges[min][]" value="<?php echo esc_attr( $range['min'] ); ?>" aria-label="<?php esc_attr_e( 'Min quantity', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>" /></td>
										<td><input type="number" min="0" class="small-text" name="ranges[max][]" value="<?php echo esc_attr( $range['max'] ); ?>" placeholder="∞" aria-label="<?php esc_attr_e( 'Max quantity', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>" /></td>
										<td>
											<input type="text" class="short wc_input_decimal" name="ranges[value][]" value="<?php echo esc_attr( wc_format_localized_decimal( $range['value'] ) ); ?>" aria-label="<?php esc_attr_e( 'Discount value', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>" />
											<select name="ranges[adjust][]" aria-label="<?php esc_attr_e( 'Discount type', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>">
												<option value="percent" <?php selected( 'percent', $range['adjust'] ); ?>><?php esc_html_e( '% off', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></option>
												<option value="fixed" <?php selected( 'fixed', $range['adjust'] ); ?>><?php esc_html_e( 'amount off each', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></option>
												<option value="fixed_price" <?php selected( 'fixed_price', $range['adjust'] ); ?>><?php esc_html_e( 'fixed price each', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></option>
											</select>
										</td>
										<td><button type="button" class="button-link kudora-remove-row" aria-label="<?php esc_attr_e( 'Remove range', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>">&times;</button></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<p><button type="button" class="button kudora-add-row" data-target="[data-kudora-ranges]"><?php esc_html_e( 'Add range', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></button></p>
					</div>
					<div data-show-types="bundle">
						<p class="kudora-inline">
							<label><?php esc_html_e( 'Any', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?> <input type="number" min="2" class="small-text" name="config[qty]" value="<?php echo esc_attr( $get( 'qty', 3 ) ); ?>" /></label>
							<label><?php esc_html_e( 'items for', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?> <input type="text" class="short wc_input_price" name="config[price]" value="<?php echo esc_attr( wc_format_localized_price( $get( 'price', 0 ) ) ); ?>" /></label>
							<label><input type="checkbox" name="config[repeat]" value="1" <?php checked( (bool) $get( 'repeat', 1 ) ); ?> /> <?php esc_html_e( 'Repeat for every set', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
						</p>
					</div>
					<div data-show-types="bxgy">
						<?php Kudora_Admin_Fields::bxgy( 'bxgy', $r['type'] === 'bxgy' ? $c : array( 'get_same' => true ) ); ?>
					</div>
					<div data-show-types="free_shipping">
						<p>
							<label for="kudora_methods"><?php esc_html_e( 'Shipping methods made free', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label><br />
							<select id="kudora_methods" name="config[methods][]" multiple class="wc-enhanced-select" style="min-width:300px" data-placeholder="<?php esc_attr_e( 'All methods', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>">
								<?php foreach ( WC()->shipping() ? WC()->shipping()->get_shipping_methods() : array() as $method ) : ?>
									<option value="<?php echo esc_attr( $method->id ); ?>" <?php selected( in_array( $method->id, (array) $get( 'methods', array() ), true ) ); ?>><?php echo esc_html( $method->get_method_title() ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
					</div>
					<div data-show-types="product_adjustment bulk cart_discount bundle">
						<?php Kudora_Admin_Fields::scope( 'config[scope]', $get( 'scope', array() ), __( 'Applies to', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ); ?>
					</div>
					<p data-show-types="cart_discount bxgy bundle free_shipping">
						<label for="kudora_promo"><?php esc_html_e( 'Promotion message', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label><br />
						<input type="text" id="kudora_promo" name="config[promo]" class="large-text" value="<?php echo esc_attr( $get( 'promo' ) ); ?>" placeholder="<?php esc_attr_e( 'Add {amount} more to get {rule}!', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>" />
						<span class="description"><?php esc_html_e( 'Shown when only a "cart subtotal at least" condition is missing. {amount} and {rule} are replaced.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></span>
					</p>
				<?php endif; ?>
			</div>

			<div class="kudora-card-box">
				<h2><?php esc_html_e( 'Conditions', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></h2>
				<p class="description"><?php esc_html_e( 'All conditions must be true. No conditions = always.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></p>
				<?php Kudora_Admin_Fields::conditions( 'conditions_json', $r['conditions'] ); ?>
			</div>

			<?php submit_button( $r['id'] ? __( 'Save rule', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) : __( 'Create rule', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Save a rule.
	 *
	 * @return void
	 */
	public static function handle_save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'kudora_save_rule' );
		$module = isset( $_POST['module'] ) && 'earn' === $_POST['module'] ? 'earn' : 'discount';
		$post   = map_deep( wp_unslash( $_POST ), 'sanitize_text_field' );
		$config = isset( $post['config'] ) && is_array( $post['config'] ) ? $post['config'] : array();
		$type   = 'earn' === $module ? 'earn' : ( isset( $post['type'] ) && isset( Kudora_Discount_Engine::types()[ $post['type'] ] ) ? $post['type'] : 'product_adjustment' );

		$clean = array();
		if ( 'earn' === $module ) {
			$clean = array(
				'mode'       => isset( $config['mode'] ) && 'multiplier' === $config['mode'] ? 'multiplier' : 'fixed',
				'award'      => absint( isset( $config['award'] ) ? $config['award'] : 0 ),
				'multiplier' => max( 0, (float) wc_format_decimal( isset( $config['multiplier'] ) ? $config['multiplier'] : 1 ) ),
				'scope'      => Kudora_Scope::sanitize( isset( $config['scope'] ) ? $config['scope'] : array() ),
			);
		} else {
			$clean['label']             = isset( $config['label'] ) ? $config['label'] : '';
			$clean['promo']             = isset( $config['promo'] ) ? $config['promo'] : '';
			$clean['skip_with_coupons'] = ! empty( $config['skip_with_coupons'] );
			switch ( $type ) {
				case 'product_adjustment':
				case 'cart_discount':
					$clean['adjust'] = isset( $config['adjust'] ) && in_array( $config['adjust'], array( 'percent', 'fixed', 'fixed_price' ), true ) ? $config['adjust'] : 'percent';
					if ( 'cart_discount' === $type && 'fixed_price' === $clean['adjust'] ) {
						$clean['adjust'] = 'fixed';
					}
					$clean['value'] = max( 0, (float) wc_format_decimal( isset( $config['value'] ) ? $config['value'] : 0 ) );
					$clean['max']   = isset( $config['max'] ) && '' !== $config['max'] ? max( 0, (float) wc_format_decimal( $config['max'] ) ) : 0;
					$clean['scope'] = Kudora_Scope::sanitize( isset( $config['scope'] ) ? $config['scope'] : array() );
					break;
				case 'bulk':
					$clean['count_by']   = isset( $config['count_by'] ) && in_array( $config['count_by'], array( 'line', 'product', 'scope' ), true ) ? $config['count_by'] : 'line';
					$clean['show_table'] = ! empty( $config['show_table'] );
					$clean['scope']      = Kudora_Scope::sanitize( isset( $config['scope'] ) ? $config['scope'] : array() );
					$clean['ranges']     = array();
					$ranges              = isset( $post['ranges'] ) && is_array( $post['ranges'] ) ? $post['ranges'] : array();
					$count               = isset( $ranges['min'] ) ? count( (array) $ranges['min'] ) : 0;
					for ( $i = 0; $i < $count; $i++ ) {
						$min = absint( $ranges['min'][ $i ] );
						$val = (float) wc_format_decimal( isset( $ranges['value'][ $i ] ) ? $ranges['value'][ $i ] : 0 );
						if ( $min < 1 || $val <= 0 ) {
							continue;
						}
						$clean['ranges'][] = array(
							'min'    => $min,
							'max'    => isset( $ranges['max'][ $i ] ) && '' !== $ranges['max'][ $i ] ? absint( $ranges['max'][ $i ] ) : '',
							'adjust' => isset( $ranges['adjust'][ $i ] ) && in_array( $ranges['adjust'][ $i ], array( 'percent', 'fixed', 'fixed_price' ), true ) ? $ranges['adjust'][ $i ] : 'percent',
							'value'  => $val,
						);
					}
					usort(
						$clean['ranges'],
						function ( $a, $b ) {
							return $a['min'] <=> $b['min'];
						}
					);
					break;
				case 'bundle':
					$clean['qty']    = max( 2, absint( isset( $config['qty'] ) ? $config['qty'] : 2 ) );
					$clean['price']  = max( 0, (float) wc_format_decimal( isset( $config['price'] ) ? $config['price'] : 0 ) );
					$clean['repeat'] = ! empty( $config['repeat'] );
					$clean['scope']  = Kudora_Scope::sanitize( isset( $config['scope'] ) ? $config['scope'] : array() );
					break;
				case 'bxgy':
					$clean = array_merge( $clean, Kudora_Rule_Calc::sanitize_bxgy( isset( $post['bxgy'] ) ? $post['bxgy'] : array() ) );
					break;
				case 'free_shipping':
					$clean['methods'] = array_values( array_filter( array_map( 'sanitize_key', isset( $config['methods'] ) ? (array) $config['methods'] : array() ) ) );
					break;
			}
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON decoded then sanitised by Kudora_Conditions::sanitize().
		$conditions = isset( $_POST['conditions_json'] ) ? Kudora_Conditions::sanitize( json_decode( wp_unslash( $_POST['conditions_json'] ), true ) ) : array();

		$id = Kudora_Rules_Repo::save(
			array(
				'id'          => isset( $post['id'] ) ? absint( $post['id'] ) : 0,
				'module'      => $module,
				'type'        => $type,
				'name'        => isset( $post['name'] ) ? $post['name'] : '',
				'status'      => ! empty( $post['status'] ),
				'priority'    => isset( $post['priority'] ) ? (int) $post['priority'] : 10,
				'exclusive'   => ! empty( $post['exclusive'] ),
				'conditions'  => $conditions,
				'config'      => $clean,
				'starts_at'   => Kudora_Admin_Fields::utc_datetime( isset( $post['starts_at'] ) ? $post['starts_at'] : '' ),
				'ends_at'     => Kudora_Admin_Fields::utc_datetime( isset( $post['ends_at'] ) ? $post['ends_at'] : '' ),
				'usage_limit' => isset( $post['usage_limit'] ) && '' !== $post['usage_limit'] ? absint( $post['usage_limit'] ) : null,
			)
		);
		Kudora_Admin::flash( __( 'Rule saved.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		wp_safe_redirect( add_query_arg( 'rule', $id, self::base_url( $module ) ) );
		exit;
	}

	/**
	 * Enable / disable / duplicate / delete.
	 *
	 * @return void
	 */
	public static function handle_action() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'kudora_rule_action_' . $id );
		$do     = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		$module = isset( $_GET['module'] ) && 'earn' === $_GET['module'] ? 'earn' : 'discount';
		$rule   = Kudora_Rules_Repo::get( $id );
		if ( $rule ) {
			switch ( $do ) {
				case 'enable':
				case 'disable':
					Kudora_Rules_Repo::set_status( $id, 'enable' === $do ? 1 : 0 );
					break;
				case 'duplicate':
					$copy                = $rule;
					$copy['id']          = 0;
					$copy['status']      = 0;
					/* translators: %s: rule name */
					$copy['name']        = sprintf( __( '%s (copy)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), $rule['name'] );
					$copy['usage_count'] = 0;
					Kudora_Rules_Repo::save( $copy );
					Kudora_Admin::flash( __( 'Rule duplicated (inactive).', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
					break;
				case 'delete':
					Kudora_Rules_Repo::delete( $id );
					Kudora_Admin::flash( __( 'Rule deleted.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
					break;
			}
		}
		wp_safe_redirect( self::base_url( $module ) );
		exit;
	}
}
