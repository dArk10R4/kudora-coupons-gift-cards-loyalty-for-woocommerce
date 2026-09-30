<?php
/**
 * Marketing → Loyalty (Booknetic tabs: Customer points, Logs + stats + CSV,
 * Earning rules, Tiers, Settings link; plus Rewards, Referrals, Import/export).
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loyalty admin.
 */
class Kudora_Admin_Loyalty {

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post_kudora_adjust_points', array( __CLASS__, 'handle_adjust' ) );
		add_action( 'admin_post_kudora_save_tiers', array( __CLASS__, 'handle_tiers' ) );
		add_action( 'admin_post_kudora_save_rewards', array( __CLASS__, 'handle_rewards' ) );
		add_action( 'admin_post_kudora_export_logs', array( __CLASS__, 'handle_export_logs' ) );
	}

	/**
	 * Tabs.
	 *
	 * @return array
	 */
	protected static function tabs() {
		$tabs = array(
			'customers' => __( 'Customers', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'logs'      => __( 'Logs', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'rules'     => __( 'Earning rules', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'tiers'     => __( 'Tiers', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'rewards'   => __( 'Rewards', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
		);
		if ( Kudora_Settings::yes( 'referral_enabled' ) ) {
			$tabs['referrals'] = __( 'Referrals', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
		}
		$tabs['import'] = __( 'Import / export', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
		return $tabs;
	}

	/**
	 * URL.
	 *
	 * @param array $args Args.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=kudora-loyalty' ) );
	}

	/**
	 * Page.
	 *
	 * @return void
	 */
	public static function render() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$tab = isset( $_GET['tab'] ) && isset( self::tabs()[ sanitize_key( $_GET['tab'] ) ] ) ? sanitize_key( $_GET['tab'] ) : 'customers';
		Kudora_Admin::header( Kudora_Frontend::title_for( 'points' ), 'kudora-loyalty', self::tabs(), $tab );
		echo '<p class="kudora-settings-link"><a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=kudora&section=loyalty' ) ) . '">' . esc_html__( 'Loyalty settings', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) . ' &rarr;</a></p>';
		switch ( $tab ) {
			case 'logs':
				Kudora_Admin::print_flash();
				self::render_logs();
				break;
			case 'rules':
				Kudora_Admin_Rules::render( 'earn' );
				break;
			case 'tiers':
				Kudora_Admin::print_flash();
				self::render_tiers();
				break;
			case 'rewards':
				Kudora_Admin::print_flash();
				self::render_rewards();
				break;
			case 'referrals':
				self::render_referrals();
				break;
			case 'import':
				Kudora_Admin::print_flash();
				Kudora_Admin_Import_Export::render_points();
				break;
			default:
				Kudora_Admin::print_flash();
				self::render_customers();
		}
		echo '</div>';
	}

	/* ---------------------------------------------------------------------
	 * Customers
	 * ------------------------------------------------------------------- */

	/**
	 * Customers tab (+ adjust form, Booknetic "Adjust points").
	 *
	 * @return void
	 */
	protected static function render_customers() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$customer = isset( $_GET['customer'] ) ? absint( $_GET['customer'] ) : 0;
		$args     = array(
			'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'tier'     => isset( $_GET['tier'] ) ? sanitize_key( $_GET['tier'] ) : '',
			'orderby'  => isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : '',
			'page'     => isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1,
			'per_page' => 25,
		);
		// phpcs:enable
		$tiers = array();
		foreach ( Kudora_Points_Repo::tiers() as $t ) {
			$tiers[ $t['id'] ] = $t;
		}
		?>
		<div class="kudora-columns">
			<div class="kudora-card-box kudora-grow">
				<form method="get" class="kudora-filters">
					<input type="hidden" name="page" value="kudora-loyalty" />
					<input type="hidden" name="tab" value="customers" />
					<label class="screen-reader-text" for="kudora-cust-search"><?php esc_html_e( 'Search customers', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
					<input type="search" id="kudora-cust-search" name="s" value="<?php echo esc_attr( $args['search'] ); ?>" placeholder="<?php esc_attr_e( 'Name or email', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>" />
					<label class="screen-reader-text" for="kudora-cust-tier"><?php esc_html_e( 'Tier', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
					<select id="kudora-cust-tier" name="tier">
						<option value=""><?php esc_html_e( 'All tiers', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></option>
						<?php foreach ( $tiers as $id => $t ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $args['tier'], $id ); ?>><?php echo esc_html( $t['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php submit_button( __( 'Filter', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'secondary', '', false ); ?>
				</form>
				<?php $res = Kudora_Points_Repo::customers( $args ); ?>
				<table class="wp-list-table widefat fixed striped">
					<thead><tr>
						<th class="column-primary"><?php esc_html_e( 'Customer', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
						<th><a href="<?php echo esc_url( add_query_arg( 'orderby', 'balance' ) ); ?>"><?php esc_html_e( 'Points', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></a></th>
						<th><a href="<?php echo esc_url( add_query_arg( 'orderby', 'lifetime' ) ); ?>"><?php esc_html_e( 'Lifetime earned', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></a></th>
						<th><?php esc_html_e( 'Tier', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
					</tr></thead>
					<tbody>
						<?php if ( ! $res['rows'] ) : ?>
							<tr><td colspan="4"><?php esc_html_e( 'No customers with points yet.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></td></tr>
						<?php endif; ?>
						<?php foreach ( $res['rows'] as $row ) : ?>
							<?php $t = isset( $tiers[ $row['tier_id'] ] ) ? $tiers[ $row['tier_id'] ] : null; ?>
							<tr>
								<td class="column-primary">
									<?php echo get_avatar( (int) $row['user_id'], 28 ); ?>
									<a href="<?php echo esc_url( self::url( array( 'tab' => 'customers', 'customer' => $row['user_id'] ) ) ); ?>"><strong><?php echo esc_html( $row['display_name'] ); ?></strong></a><br />
									<small><?php echo esc_html( $row['user_email'] ); ?></small>
								</td>
								<td><?php echo esc_html( number_format_i18n( (int) $row['balance'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $row['lifetime_earned'] ) ); ?></td>
								<td><?php echo $t ? '<span class="kudora-tier-badge" style="--kudora-tier:' . esc_attr( $t['color'] ) . '">' . esc_html( $t['name'] ) . '</span>' : '—'; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php Kudora_Admin_Gift_Cards::pagination( $res['total'], $args['page'], $args['per_page'] ); ?>
			</div>
			<div class="kudora-card-box">
				<h2><?php esc_html_e( 'Adjust points', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="kudora_adjust_points" />
					<?php wp_nonce_field( 'kudora_adjust_points' ); ?>
					<p>
						<label for="kudora_adj_user"><?php esc_html_e( 'Customer', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label><br />
						<select id="kudora_adj_user" name="user_id" class="wc-customer-search" style="width:100%" data-placeholder="<?php esc_attr_e( 'Search for a customer…', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>" required>
							<?php if ( $customer && get_userdata( $customer ) ) : ?>
								<option value="<?php echo esc_attr( $customer ); ?>" selected><?php echo esc_html( get_userdata( $customer )->display_name . ' (' . get_userdata( $customer )->user_email . ')' ); ?></option>
							<?php endif; ?>
						</select>
					</p>
					<p>
						<label><input type="radio" name="type" value="addition" checked /> <?php esc_html_e( 'Add', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
						<label><input type="radio" name="type" value="deletion" /> <?php esc_html_e( 'Deduct', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
					</p>
					<p><label for="kudora_adj_points"><?php esc_html_e( 'Points', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label><br /><input type="number" min="1" id="kudora_adj_points" name="points" required class="regular-text" /></p>
					<p><label for="kudora_adj_note"><?php esc_html_e( 'Note', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label><br /><input type="text" id="kudora_adj_note" name="note" class="regular-text" maxlength="200" /></p>
					<p><label><input type="checkbox" name="lifetime" value="1" checked /> <?php esc_html_e( 'Count additions towards tiers', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label></p>
					<?php submit_button( __( 'Save adjustment', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'primary', 'submit', false ); ?>
				</form>
				<?php if ( $customer ) : ?>
					<?php $row = Kudora_Points_Repo::row( $customer ); ?>
					<h3><?php echo esc_html( get_userdata( $customer ) ? get_userdata( $customer )->display_name : '#' . $customer ); ?></h3>
					<?php /* translators: 1: balance, 2: lifetime */ ?>
					<p><?php echo esc_html( sprintf( __( 'Balance %1$s · lifetime %2$s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), number_format_i18n( $row['balance'] ), number_format_i18n( $row['lifetime_earned'] ) ) ); ?></p>
					<p><a href="<?php echo esc_url( self::url( array( 'tab' => 'logs', 'customer' => $customer ) ) ); ?>"><?php esc_html_e( 'Full history', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?> &rarr;</a></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Adjust points (Booknetic `byAdmin`; deduction rejected when balance is too low).
	 *
	 * @return void
	 */
	public static function handle_adjust() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'kudora_adjust_points' );
		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$points  = isset( $_POST['points'] ) ? absint( $_POST['points'] ) : 0;
		$type    = isset( $_POST['type'] ) && 'deletion' === $_POST['type'] ? 'deletion' : 'addition';
		$note    = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
		$back    = self::url( array( 'tab' => 'customers', 'customer' => $user_id ) );
		if ( ! $user_id || ! get_userdata( $user_id ) || $points <= 0 ) {
			Kudora_Admin::flash( __( 'Choose a customer and a number of points.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'error' );
			wp_safe_redirect( $back );
			exit;
		}
		$key = 'admin:' . wp_generate_uuid4();
		if ( 'addition' === $type ) {
			Kudora_Points_Repo::add(
				$user_id,
				$points,
				'admin',
				array(
					'note'     => $note,
					'key'      => $key,
					'lifetime' => ! empty( $_POST['lifetime'] ),
				)
			);
			Kudora_Admin::flash( __( 'Points added.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		} else {
			$res = Kudora_Points_Repo::deduct(
				$user_id,
				$points,
				'admin',
				array(
					'note' => $note,
					'key'  => $key,
				)
			);
			if ( is_wp_error( $res ) ) {
				Kudora_Admin::flash( __( 'The customer does not have enough points.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'error' );
			} else {
				Kudora_Admin::flash( __( 'Points deducted.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
			}
		}
		wp_safe_redirect( $back );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Logs
	 * ------------------------------------------------------------------- */

	/**
	 * Current log filters.
	 *
	 * @return array
	 */
	protected static function log_filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		return array(
			'customer'  => isset( $_GET['customer'] ) ? absint( $_GET['customer'] ) : 0,
			'action'    => isset( $_GET['log_action'] ) ? sanitize_key( $_GET['log_action'] ) : '',
			'date_from' => isset( $_GET['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', sanitize_text_field( wp_unslash( $_GET['from'] ) ) ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '',
			'date_to'   => isset( $_GET['to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', sanitize_text_field( wp_unslash( $_GET['to'] ) ) ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '',
			'page'      => isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1,
			'per_page'  => 30,
		);
		// phpcs:enable
	}

	/**
	 * Logs tab with Booknetic's four stat cards.
	 *
	 * @return void
	 */
	protected static function render_logs() {
		$f      = self::log_filters();
		$stats  = Kudora_Points_Repo::stats( $f );
		$res    = Kudora_Points_Repo::logs( $f );
		$labels = Kudora_Points_Repo::action_labels();
		?>
		<div class="kudora-stats">
			<div class="kudora-stat"><span><?php esc_html_e( 'Earned', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></span><strong><?php echo esc_html( number_format_i18n( $stats['earned'] ) ); ?></strong></div>
			<div class="kudora-stat"><span><?php esc_html_e( 'Redeemed', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></span><strong><?php echo esc_html( number_format_i18n( $stats['redeemed'] ) ); ?></strong></div>
			<div class="kudora-stat"><span><?php esc_html_e( 'Redemption rate', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></span><strong><?php echo esc_html( number_format_i18n( $stats['rate'], 1 ) ); ?>%</strong></div>
			<div class="kudora-stat"><span><?php esc_html_e( 'Active customers', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></span><strong><?php echo esc_html( number_format_i18n( $stats['active'] ) ); ?></strong></div>
		</div>
		<form method="get" class="kudora-filters">
			<input type="hidden" name="page" value="kudora-loyalty" />
			<input type="hidden" name="tab" value="logs" />
			<label class="screen-reader-text" for="kudora-log-customer"><?php esc_html_e( 'Customer', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
			<select id="kudora-log-customer" name="customer" class="wc-customer-search" data-allow_clear="true" data-placeholder="<?php esc_attr_e( 'All customers', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>" style="min-width:220px">
				<?php if ( $f['customer'] && get_userdata( $f['customer'] ) ) : ?>
					<option value="<?php echo esc_attr( $f['customer'] ); ?>" selected><?php echo esc_html( get_userdata( $f['customer'] )->display_name ); ?></option>
				<?php endif; ?>
			</select>
			<label class="screen-reader-text" for="kudora-log-action"><?php esc_html_e( 'Action', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
			<select id="kudora-log-action" name="log_action">
				<option value=""><?php esc_html_e( 'All actions', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></option>
				<?php foreach ( $labels as $k => $label ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f['action'], $k ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<label><?php esc_html_e( 'From', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?> <input type="date" name="from" value="<?php echo esc_attr( $f['date_from'] ); ?>" /></label>
			<label><?php esc_html_e( 'To', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?> <input type="date" name="to" value="<?php echo esc_attr( $f['date_to'] ); ?>" /></label>
			<?php submit_button( __( 'Filter', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'secondary', '', false ); ?>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'kudora_export_logs' ), array_filter( array( 'customer' => $f['customer'], 'log_action' => $f['action'], 'from' => $f['date_from'], 'to' => $f['date_to'] ) ) ), admin_url( 'admin-post.php' ) ), 'kudora_export_logs' ) ); ?>"><?php esc_html_e( 'Export CSV', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></a>
		</form>
		<table class="wp-list-table widefat fixed striped">
			<thead><tr>
				<th><?php esc_html_e( 'Date', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Customer', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Points', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Action', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Related', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Note', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
			</tr></thead>
			<tbody>
				<?php if ( ! $res['rows'] ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'Nothing logged for these filters.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $res['rows'] as $row ) : ?>
					<?php $u = get_userdata( (int) $row['user_id'] ); ?>
					<tr>
						<td><?php echo esc_html( get_date_from_gmt( $row['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
						<td><?php echo $u ? '<a href="' . esc_url( self::url( array( 'tab' => 'customers', 'customer' => $u->ID ) ) ) . '">' . esc_html( $u->display_name ) . '</a>' : '#' . esc_html( $row['user_id'] ); ?></td>
						<td class="<?php echo (int) $row['points'] < 0 ? 'kudora-minus' : 'kudora-plus'; ?>"><?php echo esc_html( ( (int) $row['points'] > 0 ? '+' : '' ) . number_format_i18n( (int) $row['points'] ) ); ?></td>
						<td><?php echo esc_html( isset( $labels[ $row['action'] ] ) ? $labels[ $row['action'] ] : $row['action'] ); ?></td>
						<td>
							<?php
							if ( 'order' === $row['entity_type'] ) {
								$o = wc_get_order( (int) $row['entity_id'] );
								echo $o ? '<a href="' . esc_url( $o->get_edit_order_url() ) . '">#' . esc_html( $o->get_order_number() ) . '</a>' : '#' . esc_html( $row['entity_id'] );
							} elseif ( 'product' === $row['entity_type'] ) {
								echo '<a href="' . esc_url( get_edit_post_link( (int) $row['entity_id'] ) ) . '">' . esc_html( get_the_title( (int) $row['entity_id'] ) ) . '</a>';
							} else {
								echo '—';
							}
							?>
						</td>
						<td><?php echo esc_html( $row['note'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		Kudora_Admin_Gift_Cards::pagination( $res['total'], $f['page'], $f['per_page'] );
	}

	/**
	 * CSV export of the filtered logs (Booknetic columns + a few more).
	 *
	 * @return void
	 */
	public static function handle_export_logs() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'kudora_export_logs' );
		$f             = self::log_filters();
		$f['per_page'] = 5000;
		$labels        = Kudora_Points_Repo::action_labels();
		$rows          = array( array( 'Date & Time', 'Customer', 'Email', 'Points', 'Action Type', 'Order', 'Note' ) );
		$page          = 1;
		do {
			$f['page'] = $page;
			$res       = Kudora_Points_Repo::logs( $f );
			foreach ( $res['rows'] as $row ) {
				$u      = get_userdata( (int) $row['user_id'] );
				$rows[] = array(
					get_date_from_gmt( $row['created_at'], 'Y-m-d H:i:s' ),
					$u ? $u->display_name : '',
					$u ? $u->user_email : '',
					(int) $row['points'],
					isset( $labels[ $row['action'] ] ) ? $labels[ $row['action'] ] : $row['action'],
					'order' === $row['entity_type'] ? (int) $row['entity_id'] : '',
					$row['note'],
				);
			}
			++$page;
		} while ( count( $res['rows'] ) === $f['per_page'] && $page < 50 );
		Kudora_CSV::download( 'points-log-' . gmdate( 'Y-m-d' ) . '.csv', $rows );
	}

	/* ---------------------------------------------------------------------
	 * Tiers
	 * ------------------------------------------------------------------- */

	/**
	 * Tiers tab.
	 *
	 * @return void
	 */
	protected static function render_tiers() {
		$tiers = Kudora_Points_Repo::tiers();
		if ( ! $tiers ) {
			$tiers = array( array( 'id' => '', 'name' => '', 'color' => '#1f2a44', 'min_points' => 0, 'multiplier' => 1 ) );
		}
		?>
		<div class="kudora-card-box">
			<p class="description"><?php echo esc_html( 'balance' === Kudora_Settings::get( 'loyalty_tier_basis' ) ? __( 'Customers are in the highest tier whose minimum is at or below their current balance.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) : __( 'Customers are in the highest tier whose minimum is at or below the points they have earned in total. Spending points does not lower the tier.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="kudora_save_tiers" />
				<?php wp_nonce_field( 'kudora_save_tiers' ); ?>
				<table class="widefat kudora-repeat" data-kudora-repeat>
					<thead><tr>
						<th><?php esc_html_e( 'Name', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Badge colour', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Minimum points', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Earning multiplier', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $tiers as $t ) : ?>
							<tr>
								<td><input type="hidden" name="tiers[id][]" value="<?php echo esc_attr( $t['id'] ); ?>" /><input type="text" name="tiers[name][]" value="<?php echo esc_attr( $t['name'] ); ?>" required aria-label="<?php esc_attr_e( 'Tier name', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>" /></td>
								<td><input type="color" name="tiers[color][]" value="<?php echo esc_attr( $t['color'] ); ?>" aria-label="<?php esc_attr_e( 'Badge colour', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>" /></td>
								<td><input type="number" min="0" name="tiers[min_points][]" value="<?php echo esc_attr( $t['min_points'] ); ?>" class="small-text" aria-label="<?php esc_attr_e( 'Minimum points', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>" /></td>
								<td><input type="text" name="tiers[multiplier][]" value="<?php echo esc_attr( wc_format_localized_decimal( $t['multiplier'] ) ); ?>" class="short wc_input_decimal" aria-label="<?php esc_attr_e( 'Earning multiplier', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>" /></td>
								<td><button type="button" class="button-link kudora-remove-row" aria-label="<?php esc_attr_e( 'Remove tier', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>">&times;</button></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="button" class="button kudora-add-row" data-target="[data-kudora-repeat]"><?php esc_html_e( 'Add tier', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></button></p>
				<?php submit_button( __( 'Save tiers', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Save tiers and re-tier every customer.
	 *
	 * @return void
	 */
	public static function handle_tiers() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'kudora_save_tiers' );
		$in    = isset( $_POST['tiers'] ) ? map_deep( wp_unslash( $_POST['tiers'] ), 'sanitize_text_field' ) : array();
		$out   = array();
		$count = isset( $in['name'] ) ? count( (array) $in['name'] ) : 0;
		$used  = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$name = trim( $in['name'][ $i ] );
			if ( '' === $name ) {
				continue;
			}
			$id = sanitize_key( ! empty( $in['id'][ $i ] ) ? $in['id'][ $i ] : sanitize_title( $name ) );
			if ( '' === $id || isset( $used[ $id ] ) ) {
				$id .= '-' . wp_generate_password( 4, false, false );
			}
			$used[ $id ] = true;
			$out[]       = array(
				'id'         => $id,
				'name'       => $name,
				'color'      => sanitize_hex_color( $in['color'][ $i ] ) ? sanitize_hex_color( $in['color'][ $i ] ) : '#1f2a44',
				'min_points' => absint( $in['min_points'][ $i ] ),
				'multiplier' => max( 0, (float) wc_format_decimal( $in['multiplier'][ $i ] ) ),
			);
		}
		update_option( 'kudora_loyalty_tiers', $out, false );
		Kudora_Points_Repo::resync_all_tiers();
		Kudora_Admin::flash( __( 'Tiers saved and customers re-tiered.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		wp_safe_redirect( self::url( array( 'tab' => 'tiers' ) ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Rewards
	 * ------------------------------------------------------------------- */

	/**
	 * Rewards tab.
	 *
	 * @return void
	 */
	protected static function render_rewards() {
		$rewards = Kudora_Rewards::all();
		$tiers   = Kudora_Points_Repo::tiers();
		$types   = Kudora_Rewards::types();
		$blank   = Kudora_Rewards::sanitize( array( 'id' => '', 'status' => 1 ) );
		$blank['id'] = '';
		if ( ! $rewards ) {
			$rewards = array( $blank );
		}
		?>
		<div class="kudora-card-box">
			<p class="description"><?php esc_html_e( 'Customers claim rewards in My Account → Points & rewards. Each claim deducts the points and creates a single-use coupon locked to the customer’s email.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="kudora_save_rewards" />
				<?php wp_nonce_field( 'kudora_save_rewards' ); ?>
				<div class="kudora-reward-cards" data-kudora-repeat-cards>
					<?php foreach ( $rewards as $r ) : ?>
						<fieldset class="kudora-reward-card">
							<legend class="screen-reader-text"><?php esc_html_e( 'Reward', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></legend>
							<input type="hidden" name="rewards[id][]" value="<?php echo esc_attr( $r['id'] ); ?>" />
							<p class="kudora-grid">
								<label><?php esc_html_e( 'Name', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?><input type="text" name="rewards[name][]" value="<?php echo esc_attr( $r['name'] ); ?>" /></label>
								<label><?php esc_html_e( 'Cost (points)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?><input type="number" min="1" name="rewards[cost][]" value="<?php echo esc_attr( $r['cost'] ); ?>" /></label>
								<label><?php esc_html_e( 'Type', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>
									<select name="rewards[type][]">
										<?php foreach ( $types as $k => $label ) : ?>
											<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $r['type'], $k ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
								</label>
								<label><?php esc_html_e( 'Value (amount or %)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?><input type="text" class="wc_input_decimal" name="rewards[value][]" value="<?php echo esc_attr( wc_format_localized_decimal( $r['value'] ) ); ?>" /></label>
								<label><?php esc_html_e( 'Max discount (for %)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?><input type="text" class="wc_input_price" name="rewards[max_discount][]" value="<?php echo esc_attr( $r['max_discount'] ? wc_format_localized_price( $r['max_discount'] ) : '' ); ?>" /></label>
								<label><?php esc_html_e( 'Free product', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>
									<select class="wc-product-search" name="rewards[product_id][]" data-allow_clear="true" data-placeholder="<?php esc_attr_e( 'For "Free product" rewards', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>" data-action="woocommerce_json_search_products_and_variations"><option value=""></option><?php Kudora_Admin_Fields::product_options( $r['product_id'] ? array( $r['product_id'] ) : array() ); ?></select>
								</label>
								<label><?php esc_html_e( 'Minimum order', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?><input type="text" class="wc_input_price" name="rewards[min_order][]" value="<?php echo esc_attr( $r['min_order'] ? wc_format_localized_price( $r['min_order'] ) : '' ); ?>" /></label>
								<label><?php esc_html_e( 'Coupon valid (days)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?><input type="number" min="0" name="rewards[expiry_days][]" value="<?php echo esc_attr( $r['expiry_days'] ); ?>" /></label>
								<label><?php esc_html_e( 'Required tier', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>
									<select name="rewards[tier][]">
										<option value=""><?php esc_html_e( 'Any', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></option>
										<?php foreach ( $tiers as $t ) : ?>
											<option value="<?php echo esc_attr( $t['id'] ); ?>" <?php selected( $r['tier'], $t['id'] ); ?>><?php echo esc_html( $t['name'] ); ?></option>
										<?php endforeach; ?>
									</select>
								</label>
								<label><?php esc_html_e( 'Times per customer (0 = no limit)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?><input type="number" min="0" name="rewards[limit][]" value="<?php echo esc_attr( $r['limit'] ); ?>" /></label>
								<label class="kudora-wide"><?php esc_html_e( 'Description', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?><input type="text" name="rewards[description][]" value="<?php echo esc_attr( $r['description'] ); ?>" /></label>
								<label><?php esc_html_e( 'Active', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>
									<select name="rewards[status][]">
										<option value="1" <?php selected( $r['status'], 1 ); ?>><?php esc_html_e( 'Yes', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></option>
										<option value="0" <?php selected( $r['status'], 0 ); ?>><?php esc_html_e( 'No', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></option>
									</select>
								</label>
							</p>
							<button type="button" class="button-link kudora-remove-card"><?php esc_html_e( 'Remove reward', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></button>
						</fieldset>
					<?php endforeach; ?>
				</div>
				<p><button type="button" class="button kudora-add-card"><?php esc_html_e( 'Add reward', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></button></p>
				<?php submit_button( __( 'Save rewards', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Save rewards.
	 *
	 * @return void
	 */
	public static function handle_rewards() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'kudora_save_rewards' );
		$in    = isset( $_POST['rewards'] ) ? map_deep( wp_unslash( $_POST['rewards'] ), 'sanitize_text_field' ) : array();
		$list  = array();
		$count = isset( $in['name'] ) ? count( (array) $in['name'] ) : 0;
		$keys  = array( 'id', 'name', 'cost', 'type', 'value', 'max_discount', 'product_id', 'min_order', 'expiry_days', 'tier', 'limit', 'description', 'status' );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( '' === trim( $in['name'][ $i ] ) ) {
				continue;
			}
			$r = array();
			foreach ( $keys as $k ) {
				$r[ $k ] = isset( $in[ $k ][ $i ] ) ? $in[ $k ][ $i ] : '';
			}
			foreach ( array( 'value', 'max_discount', 'min_order' ) as $k ) {
				$r[ $k ] = wc_format_decimal( $r[ $k ] );
			}
			$list[] = $r;
		}
		Kudora_Rewards::save_all( $list );
		Kudora_Admin::flash( __( 'Rewards saved.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		wp_safe_redirect( self::url( array( 'tab' => 'rewards' ) ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Referrals
	 * ------------------------------------------------------------------- */

	/**
	 * Referrals tab.
	 *
	 * @return void
	 */
	protected static function render_referrals() {
		global $wpdb;
		$table = Kudora_DB::table( 'referrals' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
		$page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i", $table ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i ORDER BY id DESC LIMIT 30 OFFSET %d", $table, ( $page - 1 ) * 30 ), ARRAY_A );
		// phpcs:enable
		$reasons = array(
			'self'             => __( 'Self-referral', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'same_email'       => __( 'Same email as the referrer', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'same_ip'          => __( 'Same IP as the referrer', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'not_first_order'  => __( 'Not the customer’s first order', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'below_minimum'    => __( 'Order below the minimum', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'monthly_cap'      => __( 'Monthly limit reached', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'order_voided'     => __( 'Order cancelled or refunded', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'unknown_referrer' => __( 'Referrer no longer exists', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
		);
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead><tr>
				<th><?php esc_html_e( 'Date', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Referrer', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Friend', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Order', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Status', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Reward', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
			</tr></thead>
			<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No referrals yet.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( (array) $rows as $row ) : ?>
					<?php
					$ref = get_userdata( (int) $row['referrer_id'] );
					$o   = wc_get_order( (int) $row['order_id'] );
					?>
					<tr>
						<td><?php echo esc_html( get_date_from_gmt( $row['created_at'], get_option( 'date_format' ) ) ); ?></td>
						<td><?php echo $ref ? esc_html( $ref->display_name ) : '—'; ?></td>
						<td><?php echo esc_html( $row['referred_email'] ); ?></td>
						<td><?php echo $o ? '<a href="' . esc_url( $o->get_edit_order_url() ) . '">#' . esc_html( $o->get_order_number() ) . '</a>' : '—'; ?></td>
						<td><span class="kudora-badge kudora-badge-<?php echo esc_attr( 'rewarded' === $row['status'] ? 'active' : ( 'pending' === $row['status'] ? 'pending' : 'disabled' ) ); ?>"><?php echo esc_html( ucfirst( $row['status'] ) ); ?></span><?php echo $row['reason'] ? '<br /><small>' . esc_html( isset( $reasons[ $row['reason'] ] ) ? $reasons[ $row['reason'] ] : $row['reason'] ) . '</small>' : ''; ?></td>
						<td><?php echo esc_html( $row['reward'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		Kudora_Admin_Gift_Cards::pagination( $total, $page, 30 );
	}
}
