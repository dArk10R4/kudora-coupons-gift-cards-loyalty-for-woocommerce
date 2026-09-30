<?php
/**
 * Marketing → Gift cards (Booknetic tabs: Codes, Sales, Designs; plus liability).
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gift cards admin.
 */
class Kudora_Admin_Gift_Cards {

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post_kudora_gc_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_kudora_gc_bulk_create', array( __CLASS__, 'handle_bulk_create' ) );
		add_action( 'admin_post_kudora_gc_action', array( __CLASS__, 'handle_action' ) );
		add_action( 'admin_post_kudora_design_save', array( __CLASS__, 'handle_design_save' ) );
		add_action( 'admin_post_kudora_design_delete', array( __CLASS__, 'handle_design_delete' ) );
	}

	/**
	 * Tabs.
	 *
	 * @return array
	 */
	protected static function tabs() {
		return array(
			'codes'   => __( 'Codes', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'sales'   => __( 'Sales', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'designs' => __( 'Designs', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'import'  => __( 'Import / export', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
		);
	}

	/**
	 * Page.
	 *
	 * @return void
	 */
	public static function render() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$tab = isset( $_GET['tab'] ) && isset( self::tabs()[ sanitize_key( $_GET['tab'] ) ] ) ? sanitize_key( $_GET['tab'] ) : 'codes';
		// phpcs:enable
		Kudora_Admin::header( __( 'Gift cards', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'kudora-gift-cards', self::tabs(), $tab );
		Kudora_Admin::print_flash();
		switch ( $tab ) {
			case 'sales':
				self::render_sales();
				break;
			case 'designs':
				self::render_designs();
				break;
			case 'import':
				Kudora_Admin_Import_Export::render_gift_cards();
				break;
			default:
				self::render_codes();
		}
		echo '</div>';
	}

	/**
	 * URL of this screen.
	 *
	 * @param array $args Query args.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=kudora-gift-cards' ) );
	}

	/* ---------------------------------------------------------------------
	 * Codes
	 * ------------------------------------------------------------------- */

	/**
	 * Codes tab: list, single card, create forms.
	 *
	 * @return void
	 */
	protected static function render_codes() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$card_id = isset( $_GET['card'] ) ? absint( $_GET['card'] ) : 0;
		$new     = isset( $_GET['new'] ) ? sanitize_key( $_GET['new'] ) : '';
		// phpcs:enable
		if ( $card_id ) {
			self::render_card( $card_id );
			return;
		}
		if ( 'one' === $new ) {
			self::render_card_form( null );
			return;
		}
		if ( 'bulk' === $new ) {
			self::render_bulk_form();
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$args = array(
			'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'status'   => isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '',
			'source'   => isset( $_GET['source'] ) ? sanitize_key( $_GET['source'] ) : '',
			'page'     => isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1,
			'per_page' => 25,
			'orderby'  => isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'id',
			'order'    => isset( $_GET['order'] ) ? sanitize_key( $_GET['order'] ) : 'desc',
		);
		// phpcs:enable
		$res = Kudora_Gift_Card_Repo::query( $args );
		?>
		<p class="kudora-toolbar">
			<a class="page-title-action" href="<?php echo esc_url( self::url( array( 'tab' => 'codes', 'new' => 'one' ) ) ); ?>"><?php esc_html_e( 'Create gift card', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></a>
			<a class="page-title-action" href="<?php echo esc_url( self::url( array( 'tab' => 'codes', 'new' => 'bulk' ) ) ); ?>"><?php esc_html_e( 'Create in bulk', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></a>
			<?php /* translators: %s: outstanding balance */ ?>
			<span class="kudora-liability"><?php echo wp_kses_post( sprintf( __( 'Outstanding balance on active cards: %s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), '<strong>' . wc_price( Kudora_Gift_Card_Repo::liability() ) . '</strong>' ) ); ?></span>
		</p>
		<form method="get" class="kudora-filters">
			<input type="hidden" name="page" value="kudora-gift-cards" />
			<input type="hidden" name="tab" value="codes" />
			<label class="screen-reader-text" for="kudora-gc-search"><?php esc_html_e( 'Search gift cards', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
			<input type="search" id="kudora-gc-search" name="s" value="<?php echo esc_attr( $args['search'] ); ?>" placeholder="<?php esc_attr_e( 'Code, name or email', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>" />
			<label class="screen-reader-text" for="kudora-gc-status"><?php esc_html_e( 'Status', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
			<select id="kudora-gc-status" name="status">
				<option value=""><?php esc_html_e( 'All statuses', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></option>
				<?php foreach ( Kudora_Gift_Card_Repo::statuses() as $k => $label ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $args['status'], $k ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<label class="screen-reader-text" for="kudora-gc-source"><?php esc_html_e( 'Source', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label>
			<select id="kudora-gc-source" name="source">
				<option value=""><?php esc_html_e( 'All sources', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></option>
				<?php foreach ( self::sources() as $k => $label ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $args['source'], $k ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'secondary', '', false ); ?>
		</form>
		<table class="wp-list-table widefat fixed striped kudora-gc-table">
			<thead>
				<tr>
					<th scope="col" class="column-primary"><?php esc_html_e( 'Code', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Amount', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Spent', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Balance', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Recipient', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Expires', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $res['rows'] ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No gift cards found.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $res['rows'] as $card ) : ?>
					<?php $status = Kudora_Gift_Card_Repo::is_expired( $card ) && 'active' === $card['status'] ? 'expired' : $card['status']; ?>
					<tr>
						<td class="column-primary"><a href="<?php echo esc_url( self::url( array( 'tab' => 'codes', 'card' => $card['id'] ) ) ); ?>"><code><?php echo esc_html( $card['code'] ); ?></code></a>
							<?php if ( $card['order_id'] ) : ?><br /><small><?php /* translators: %d: order id */ echo esc_html( sprintf( __( 'Order #%d', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), $card['order_id'] ) ); ?></small><?php endif; ?>
						</td>
						<td><?php echo wp_kses_post( wc_price( $card['initial_amount'] ) ); ?></td>
						<td><?php echo wp_kses_post( wc_price( max( 0, $card['initial_amount'] - $card['balance'] ) ) ); ?></td>
						<td><strong><?php echo wp_kses_post( wc_price( $card['balance'] ) ); ?></strong></td>
						<td><?php echo esc_html( trim( $card['recipient_name'] . ' ' . $card['recipient_email'] ) ); ?></td>
						<td><?php echo $card['expires_on'] ? esc_html( date_i18n( get_option( 'date_format' ), strtotime( $card['expires_on'] . ' 12:00:00' ) ) ) : esc_html__( 'Never', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></td>
						<td><span class="kudora-badge kudora-badge-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( Kudora_Gift_Card_Repo::statuses()[ $status ] ); ?></span></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		self::pagination( $res['total'], $args['page'], $args['per_page'] );
	}

	/**
	 * Source labels.
	 *
	 * @return array
	 */
	public static function sources() {
		return array(
			'purchase' => __( 'Bought', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'manual'   => __( 'Created by admin', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'bulk'     => __( 'Bulk created', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'refund'   => __( 'Store credit', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'import'   => __( 'Imported', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
		);
	}

	/**
	 * Simple pagination.
	 *
	 * @param int $total    Total.
	 * @param int $page     Page.
	 * @param int $per_page Per page.
	 * @return void
	 */
	public static function pagination( $total, $page, $per_page ) {
		$pages = (int) ceil( $total / max( 1, $per_page ) );
		if ( $pages <= 1 ) {
			return;
		}
		echo '<div class="tablenav"><div class="tablenav-pages"><span class="displaying-num">' . esc_html( sprintf( /* translators: %s: count */ _n( '%s item', '%s items', $total, 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), number_format_i18n( $total ) ) ) . '</span> ';
		echo wp_kses_post(
			paginate_links(
				array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $page,
					'total'   => $pages,
				)
			)
		);
		echo '</div></div>';
	}

	/**
	 * One card: details, adjust, actions, history.
	 *
	 * @param int $id Card id.
	 * @return void
	 */
	protected static function render_card( $id ) {
		$card = Kudora_Gift_Card_Repo::get( $id );
		if ( ! $card ) {
			echo '<p>' . esc_html__( 'Gift card not found.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) . '</p>';
			return;
		}
		$act = function ( $do ) use ( $id ) {
			return wp_nonce_url( admin_url( 'admin-post.php?action=kudora_gc_action&do=' . $do . '&id=' . $id ), 'kudora_gc_action_' . $id );
		};
		?>
		<p><a href="<?php echo esc_url( self::url( array( 'tab' => 'codes' ) ) ); ?>">&larr; <?php esc_html_e( 'All gift cards', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></a></p>
		<div class="kudora-columns">
			<div class="kudora-card-box">
				<h2><code><?php echo esc_html( $card['code'] ); ?></code></h2>
				<table class="form-table" role="presentation">
					<tr><th><?php esc_html_e( 'Balance', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th><td><strong><?php echo wp_kses_post( wc_price( $card['balance'] ) ); ?></strong> <?php /* translators: %s: amount */ ?><span class="description"><?php echo wp_kses_post( sprintf( __( 'of %s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), wc_price( $card['initial_amount'] ) ) ); ?></span></td></tr>
					<tr><th><?php esc_html_e( 'Status', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th><td><span class="kudora-badge kudora-badge-<?php echo esc_attr( $card['status'] ); ?>"><?php echo esc_html( Kudora_Gift_Card_Repo::statuses()[ $card['status'] ] ); ?></span></td></tr>
					<tr><th><?php esc_html_e( 'Source', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th><td><?php echo esc_html( isset( self::sources()[ $card['source'] ] ) ? self::sources()[ $card['source'] ] : $card['source'] ); ?>
						<?php
						if ( $card['order_id'] ) {
							$order = wc_get_order( $card['order_id'] );
							if ( $order ) {
								/* translators: %s: order number */
								echo ' — <a href="' . esc_url( $order->get_edit_order_url() ) . '">' . esc_html( sprintf( __( 'Order #%s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), $order->get_order_number() ) ) . '</a>';
							}
						}
						?>
					</td></tr>
					<tr><th><?php esc_html_e( 'Created', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th><td><?php echo esc_html( get_date_from_gmt( $card['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Delivery', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th><td>
						<?php
						if ( $card['is_physical'] ) {
							esc_html_e( 'Physical card (not emailed)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
						} elseif ( $card['delivered_at'] ) {
							/* translators: %s: date */
							echo esc_html( sprintf( __( 'Emailed %s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), get_date_from_gmt( $card['delivered_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) );
						} elseif ( $card['deliver_at'] ) {
							/* translators: %s: date */
							echo esc_html( sprintf( __( 'Scheduled for %s', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), get_date_from_gmt( $card['deliver_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) );
						} else {
							esc_html_e( 'Not emailed', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' );
						}
						?>
					</td></tr>
				</table>
				<p class="kudora-actions">
					<a class="button" href="<?php echo esc_url( Kudora_Gift_Card_Frontend::printable_url( $card ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Print', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></a>
					<a class="button" href="<?php echo esc_url( Kudora_Gift_Card_Frontend::printable_url( $card, 'pdf' ) ); ?>"><?php esc_html_e( 'PDF', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></a>
					<?php if ( is_email( $card['recipient_email'] ) && ! $card['is_physical'] ) : ?>
						<a class="button" href="<?php echo esc_url( $act( 'resend' ) ); ?>"><?php esc_html_e( 'Send email again', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></a>
					<?php endif; ?>
					<?php if ( 'disabled' === $card['status'] ) : ?>
						<a class="button" href="<?php echo esc_url( $act( 'enable' ) ); ?>"><?php esc_html_e( 'Enable', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></a>
					<?php else : ?>
						<a class="button kudora-confirm" data-confirm="<?php esc_attr_e( 'Disable this gift card? It can no longer be used.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>" href="<?php echo esc_url( $act( 'disable' ) ); ?>"><?php esc_html_e( 'Disable', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></a>
					<?php endif; ?>
				</p>
			</div>
			<div class="kudora-card-box">
				<h2><?php esc_html_e( 'Edit', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></h2>
				<?php self::card_fields_form( $card ); ?>
			</div>
		</div>
		<div class="kudora-card-box">
			<h2><?php esc_html_e( 'Usage history', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Date', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Event', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Amount', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Balance after', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Order', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Note', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( Kudora_Gift_Card_Repo::ledger( $id ) as $row ) : ?>
						<tr>
							<td><?php echo esc_html( get_date_from_gmt( $row['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
							<td><?php echo esc_html( self::ledger_label( $row['type'] ) ); ?></td>
							<td><?php echo 'status' === $row['type'] ? '—' : wp_kses_post( wc_price( (float) $row['amount'] ) ); ?></td>
							<td><?php echo wp_kses_post( wc_price( (float) $row['balance_after'] ) ); ?></td>
							<td>
								<?php
								$o = $row['order_id'] ? wc_get_order( (int) $row['order_id'] ) : null;
								echo $o ? '<a href="' . esc_url( $o->get_edit_order_url() ) . '">#' . esc_html( $o->get_order_number() ) . '</a>' : '—';
								?>
							</td>
							<td><?php echo esc_html( $row['note'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Ledger type label.
	 *
	 * @param string $type Type.
	 * @return string
	 */
	public static function ledger_label( $type ) {
		$map = array(
			'issue'   => __( 'Issued', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'redeem'  => __( 'Spent', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'restore' => __( 'Returned (order cancelled)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'refund'  => __( 'Refunded to card', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'adjust'  => __( 'Balance adjusted', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'status'  => __( 'Status changed', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			'import'  => __( 'Imported', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
		);
		return isset( $map[ $type ] ) ? $map[ $type ] : $type;
	}

	/**
	 * Create-one form.
	 *
	 * @param array|null $card Card (null = new).
	 * @return void
	 */
	protected static function render_card_form( $card ) {
		?>
		<p><a href="<?php echo esc_url( self::url( array( 'tab' => 'codes' ) ) ); ?>">&larr; <?php esc_html_e( 'All gift cards', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></a></p>
		<div class="kudora-card-box">
			<h2><?php esc_html_e( 'Create gift card', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></h2>
			<?php self::card_fields_form( null ); ?>
		</div>
		<?php
	}

	/**
	 * Card fields (create or edit).
	 *
	 * @param array|null $card Card.
	 * @return void
	 */
	protected static function card_fields_form( $card ) {
		$is_new = null === $card;
		$v      = function ( $k, $d = '' ) use ( $card ) {
			return $card && isset( $card[ $k ] ) && null !== $card[ $k ] ? $card[ $k ] : $d;
		};
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="kudora_gc_save" />
			<input type="hidden" name="id" value="<?php echo esc_attr( $v( 'id', 0 ) ); ?>" />
			<?php wp_nonce_field( 'kudora_gc_save' ); ?>
			<table class="form-table" role="presentation">
				<?php if ( $is_new ) : ?>
					<tr><th><label for="kudora_code"><?php esc_html_e( 'Code', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label></th><td><input type="text" id="kudora_code" name="code" class="regular-text" placeholder="<?php esc_attr_e( 'Leave empty to generate', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>" maxlength="64" /></td></tr>
					<tr><th><label for="kudora_amount"><?php esc_html_e( 'Amount', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label></th><td><input type="text" id="kudora_amount" name="amount" class="short wc_input_price" required /></td></tr>
				<?php else : ?>
					<tr>
						<th><label for="kudora_balance"><?php esc_html_e( 'Balance', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label></th>
						<td>
							<input type="text" id="kudora_balance" name="balance" class="short wc_input_price" value="<?php echo esc_attr( wc_format_localized_price( $card['balance'] ) ); ?>" />
							<input type="text" name="balance_note" class="regular-text" placeholder="<?php esc_attr_e( 'Reason for the change (optional)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>" aria-label="<?php esc_attr_e( 'Reason for the change', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>" />
						</td>
					</tr>
				<?php endif; ?>
				<tr><th><label for="kudora_expires"><?php esc_html_e( 'Expires on', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label></th><td><input type="date" id="kudora_expires" name="expires_on" value="<?php echo esc_attr( $v( 'expires_on' ) ); ?>" /> <span class="description"><?php esc_html_e( 'Empty = never. Valid through the whole day.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></span></td></tr>
				<tr><th><label for="kudora_rname"><?php esc_html_e( 'Recipient name', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label></th><td><input type="text" id="kudora_rname" name="recipient_name" class="regular-text" value="<?php echo esc_attr( $v( 'recipient_name' ) ); ?>" /></td></tr>
				<tr><th><label for="kudora_remail"><?php esc_html_e( 'Recipient email', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label></th><td><input type="email" id="kudora_remail" name="recipient_email" class="regular-text" value="<?php echo esc_attr( $v( 'recipient_email' ) ); ?>" /></td></tr>
				<tr><th><label for="kudora_sender"><?php esc_html_e( 'From', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label></th><td><input type="text" id="kudora_sender" name="sender_name" class="regular-text" value="<?php echo esc_attr( $v( 'sender_name', $is_new ? get_bloginfo( 'name' ) : '' ) ); ?>" /></td></tr>
				<tr><th><label for="kudora_message"><?php esc_html_e( 'Message', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label></th><td><textarea id="kudora_message" name="message" rows="3" class="large-text" maxlength="300"><?php echo esc_textarea( $v( 'message' ) ); ?></textarea></td></tr>
				<tr><th><label for="kudora_design"><?php esc_html_e( 'Design', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label></th><td>
					<select id="kudora_design" name="design_id">
						<?php foreach ( Kudora_Gift_Card_Designs::all( true ) as $d ) : ?>
							<option value="<?php echo esc_attr( $d['id'] ); ?>" <?php selected( (int) $v( 'design_id' ), $d['id'] ); ?>><?php echo esc_html( $d['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</td></tr>
				<tr><th><label for="kudora_note"><?php esc_html_e( 'Internal note', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label></th><td><input type="text" id="kudora_note" name="note" class="regular-text" value="<?php echo esc_attr( $v( 'note' ) ); ?>" maxlength="255" /></td></tr>
				<?php if ( $is_new ) : ?>
					<tr><th><?php esc_html_e( 'Email', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th><td><label><input type="checkbox" name="send" value="1" checked /> <?php esc_html_e( 'Email the gift card to the recipient now', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label></td></tr>
				<?php endif; ?>
			</table>
			<?php submit_button( $is_new ? __( 'Create gift card', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) : __( 'Save changes', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Bulk create form.
	 *
	 * @return void
	 */
	protected static function render_bulk_form() {
		?>
		<p><a href="<?php echo esc_url( self::url( array( 'tab' => 'codes' ) ) ); ?>">&larr; <?php esc_html_e( 'All gift cards', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></a></p>
		<div class="kudora-card-box">
			<h2><?php esc_html_e( 'Create gift cards in bulk', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></h2>
			<p class="description"><?php esc_html_e( 'For printed cards, promotions or corporate gifts. Codes use the format from the settings; a CSV of the new codes downloads right away.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="kudora_gc_bulk_create" />
				<?php wp_nonce_field( 'kudora_gc_bulk_create' ); ?>
				<table class="form-table" role="presentation">
					<tr><th><label for="kudora_count"><?php esc_html_e( 'How many', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label></th><td><input type="number" id="kudora_count" name="count" min="1" max="1000" value="10" class="small-text" /></td></tr>
					<tr><th><label for="kudora_bamount"><?php esc_html_e( 'Amount each', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label></th><td><input type="text" id="kudora_bamount" name="amount" class="short wc_input_price" required /></td></tr>
					<tr><th><label for="kudora_bexp"><?php esc_html_e( 'Expires on', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label></th><td><input type="date" id="kudora_bexp" name="expires_on" /></td></tr>
					<tr><th><label for="kudora_bnote"><?php esc_html_e( 'Internal note', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label></th><td><input type="text" id="kudora_bnote" name="note" class="regular-text" maxlength="255" /></td></tr>
				</table>
				<?php submit_button( __( 'Create and download CSV', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Capability guard (nonces are checked by the caller).
	 * @return void
	 */
	protected static function guard_caps() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Save (create or edit).
	 *
	 * @return void
	 */
	public static function handle_save() {
		self::guard_caps();
		check_admin_referer( 'kudora_gc_save' );
		$p     = map_deep( wp_unslash( $_POST ), 'sanitize_text_field' );
		$id    = isset( $p['id'] ) ? absint( $p['id'] ) : 0;
		$email = isset( $p['recipient_email'] ) ? sanitize_email( $p['recipient_email'] ) : '';
		$exp   = isset( $p['expires_on'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $p['expires_on'] ) ? $p['expires_on'] : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer() above.
		$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		$fields  = array(
			'expires_on'      => $exp,
			'recipient_name'  => isset( $p['recipient_name'] ) ? $p['recipient_name'] : '',
			'recipient_email' => strtolower( $email ),
			'sender_name'     => isset( $p['sender_name'] ) ? $p['sender_name'] : '',
			'message'         => substr( $message, 0, 300 ),
			'design_id'       => isset( $p['design_id'] ) ? absint( $p['design_id'] ) : 0,
			'note'            => isset( $p['note'] ) ? $p['note'] : '',
		);
		if ( $id ) {
			$card = Kudora_Gift_Card_Repo::get( $id );
			if ( ! $card ) {
				wp_die( esc_html__( 'Gift card not found.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
			}
			if ( $exp && $exp >= Kudora_Gift_Card_Repo::today() && 'expired' === $card['status'] ) {
				$fields['status'] = 'active';
			}
			Kudora_Gift_Card_Repo::update( $id, $fields );
			if ( isset( $p['balance'] ) && '' !== $p['balance'] ) {
				$new = Kudora_Money::parse( $p['balance'] );
				if ( abs( $new - $card['balance'] ) > 0.00001 ) {
					Kudora_Gift_Card_Repo::set_balance( $id, $new, '' !== $p['balance_note'] ? $p['balance_note'] : __( 'Adjusted by admin', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
				}
			}
			Kudora_Admin::flash( __( 'Gift card saved.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
			wp_safe_redirect( self::url( array( 'tab' => 'codes', 'card' => $id ) ) );
			exit;
		}
		$amount = isset( $p['amount'] ) ? Kudora_Money::parse( $p['amount'] ) : 0;
		try {
			$id = Kudora_Gift_Card_Repo::create(
				array_merge(
					$fields,
					array(
						'code'           => isset( $p['code'] ) ? $p['code'] : '',
						'initial_amount' => $amount,
						'source'         => 'manual',
						'ledger_note'    => __( 'Created by admin', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
					)
				)
			);
		} catch ( RuntimeException $e ) {
			Kudora_Admin::flash( esc_html( $e->getMessage() ), 'error' );
			wp_safe_redirect( self::url( array( 'tab' => 'codes', 'new' => 'one' ) ) );
			exit;
		}
		if ( ! empty( $p['send'] ) && is_email( $email ) ) {
			( new Kudora_Gift_Card_Issuer() )->deliver( $id );
		}
		Kudora_Admin::flash( __( 'Gift card created.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		wp_safe_redirect( self::url( array( 'tab' => 'codes', 'card' => $id ) ) );
		exit;
	}

	/**
	 * Bulk create + CSV download.
	 *
	 * @return void
	 */
	public static function handle_bulk_create() {
		self::guard_caps();
		check_admin_referer( 'kudora_gc_bulk_create' );
		$p      = map_deep( wp_unslash( $_POST ), 'sanitize_text_field' );
		$count  = max( 1, min( 1000, absint( isset( $p['count'] ) ? $p['count'] : 1 ) ) );
		$amount = Kudora_Money::parse( isset( $p['amount'] ) ? $p['amount'] : 0 );
		$exp    = isset( $p['expires_on'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $p['expires_on'] ) ? $p['expires_on'] : null;
		if ( $amount <= 0 ) {
			Kudora_Admin::flash( __( 'Enter an amount greater than zero.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'error' );
			wp_safe_redirect( self::url( array( 'tab' => 'codes', 'new' => 'bulk' ) ) );
			exit;
		}
		$rows = array( array( 'code', 'amount', 'expires_on' ) );
		for ( $i = 0; $i < $count; $i++ ) {
			$id     = Kudora_Gift_Card_Repo::create(
				array(
					'initial_amount' => $amount,
					'expires_on'     => $exp,
					'source'         => 'bulk',
					'note'           => isset( $p['note'] ) ? $p['note'] : '',
					'sender_name'    => get_bloginfo( 'name' ),
				)
			);
			$card   = Kudora_Gift_Card_Repo::get( $id );
			$rows[] = array( $card['code'], wc_format_decimal( $amount, wc_get_price_decimals() ), (string) $exp );
		}
		Kudora_CSV::download( 'gift-cards-' . gmdate( 'Y-m-d-His' ) . '.csv', $rows );
	}

	/**
	 * Single-card actions.
	 *
	 * @return void
	 */
	public static function handle_action() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- part of the nonce action checked below.
		self::guard_caps();
		check_admin_referer( 'kudora_gc_action_' . $id );
		$do = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		switch ( $do ) {
			case 'disable':
				Kudora_Gift_Card_Repo::set_status( $id, 'disabled', __( 'by admin', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
				Kudora_Admin::flash( __( 'Gift card disabled.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
				break;
			case 'enable':
				$card = Kudora_Gift_Card_Repo::get( $id );
				Kudora_Gift_Card_Repo::set_status( $id, $card && Kudora_Gift_Card_Repo::is_expired( $card ) ? 'expired' : 'active', __( 'by admin', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
				Kudora_Admin::flash( __( 'Gift card enabled.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
				break;
			case 'resend':
				$ok = ( new Kudora_Gift_Card_Issuer() )->deliver( $id, true );
				Kudora_Admin::flash( $ok ? __( 'Email sent.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) : __( 'The email could not be sent. Is the "Gift card" email enabled under WooCommerce → Settings → Emails?', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), $ok ? 'success' : 'error' );
				break;
		}
		wp_safe_redirect( self::url( array( 'tab' => 'codes', 'card' => $id ) ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Sales
	 * ------------------------------------------------------------------- */

	/**
	 * Sales tab (Booknetic): purchased cards with filters.
	 *
	 * @return void
	 */
	protected static function render_sales() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$args = array(
			'source'    => 'purchase',
			'design_id' => isset( $_GET['design'] ) ? absint( $_GET['design'] ) : 0,
			'date_from' => isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '',
			'date_to'   => isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '',
			'page'      => isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1,
			'per_page'  => 25,
		);
		$gateway = isset( $_GET['gateway'] ) ? sanitize_key( wp_unslash( $_GET['gateway'] ) ) : '';
		// phpcs:enable
		$res  = Kudora_Gift_Card_Repo::query( $args );
		$gws  = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		$sum  = 0.0;
		?>
		<form method="get" class="kudora-filters">
			<input type="hidden" name="page" value="kudora-gift-cards" />
			<input type="hidden" name="tab" value="sales" />
			<label><?php esc_html_e( 'Design', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>
				<select name="design">
					<option value="0"><?php esc_html_e( 'All', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></option>
					<?php foreach ( Kudora_Gift_Card_Designs::all( true ) as $d ) : ?>
						<option value="<?php echo esc_attr( $d['id'] ); ?>" <?php selected( $args['design_id'], $d['id'] ); ?>><?php echo esc_html( $d['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label><?php esc_html_e( 'Payment method', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>
				<select name="gateway">
					<option value=""><?php esc_html_e( 'All', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></option>
					<?php foreach ( $gws as $gid => $gw ) : ?>
						<option value="<?php echo esc_attr( $gid ); ?>" <?php selected( $gateway, $gid ); ?>><?php echo esc_html( wp_strip_all_tags( $gw->get_title() ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label><?php esc_html_e( 'From', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?> <input type="date" name="from" value="<?php echo esc_attr( $args['date_from'] ); ?>" /></label>
			<label><?php esc_html_e( 'To', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?> <input type="date" name="to" value="<?php echo esc_attr( $args['date_to'] ); ?>" /></label>
			<?php submit_button( __( 'Filter', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'secondary', '', false ); ?>
		</form>
		<table class="wp-list-table widefat fixed striped">
			<thead><tr>
				<th><?php esc_html_e( 'Design', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Amount', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Gift card', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Customer', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Date', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Payment method', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $res['rows'] as $card ) : ?>
					<?php
					$order = $card['order_id'] ? wc_get_order( $card['order_id'] ) : null;
					if ( $gateway && ( ! $order || $order->get_payment_method() !== $gateway ) ) {
						continue;
					}
					$design = Kudora_Gift_Card_Designs::get( $card['design_id'] );
					$sum   += $card['initial_amount'];
					?>
					<tr>
						<td><?php echo esc_html( $design ? $design['name'] : '—' ); ?></td>
						<td><?php echo wp_kses_post( wc_price( $card['initial_amount'] ) ); ?></td>
						<td><a href="<?php echo esc_url( self::url( array( 'tab' => 'codes', 'card' => $card['id'] ) ) ); ?>"><code><?php echo esc_html( Kudora_Gift_Card_Repo::mask( $card['code'] ) ); ?></code></a></td>
						<td><?php echo $order ? esc_html( trim( $order->get_formatted_billing_full_name() . ' ' . $order->get_billing_email() ) ) : esc_html( $card['purchaser_email'] ); ?></td>
						<td><?php echo esc_html( get_date_from_gmt( $card['created_at'], get_option( 'date_format' ) ) ); ?></td>
						<td><?php echo $order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">' . esc_html( $order->get_payment_method_title() ? $order->get_payment_method_title() : '—' ) . '</a>' : '—'; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot><tr><th><?php esc_html_e( 'Total on this page', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th><th colspan="5"><?php echo wp_kses_post( wc_price( $sum ) ); ?></th></tr></tfoot>
		</table>
		<?php
		self::pagination( $res['total'], $args['page'], $args['per_page'] );
	}

	/* ---------------------------------------------------------------------
	 * Designs
	 * ------------------------------------------------------------------- */

	/**
	 * Designs tab.
	 *
	 * @return void
	 */
	protected static function render_designs() {
		wp_enqueue_media();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$edit   = isset( $_GET['design'] ) ? sanitize_key( wp_unslash( $_GET['design'] ) ) : '';
		$design = ( '' !== $edit && 'new' !== $edit ) ? Kudora_Gift_Card_Designs::get( absint( $edit ) ) : null;
		?>
		<div class="kudora-columns">
			<div class="kudora-card-box">
				<h2><?php esc_html_e( 'Designs', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></h2>
				<ul class="kudora-design-list">
					<?php foreach ( Kudora_Gift_Card_Designs::all( true ) as $d ) : ?>
						<li>
							<span class="kudora-card kudora-card-mini" style="<?php echo esc_attr( Kudora_Gift_Card_Designs::style( $d ) ); ?>"><span><?php echo esc_html( $d['name'] ); ?></span></span>
							<span>
								<strong><?php echo esc_html( $d['name'] ); ?></strong><?php echo $d['status'] ? '' : ' <em>(' . esc_html__( 'hidden', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) . ')</em>'; ?><br />
								<a href="<?php echo esc_url( self::url( array( 'tab' => 'designs', 'design' => $d['id'] ) ) ); ?>"><?php esc_html_e( 'Edit', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></a> |
								<a class="kudora-confirm" data-confirm="<?php esc_attr_e( 'Delete this design? Cards that use it switch to the first design.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?>" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=kudora_design_delete&id=' . $d['id'] ), 'kudora_design_delete_' . $d['id'] ) ); ?>"><?php esc_html_e( 'Delete', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></a>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
				<p><a class="button" href="<?php echo esc_url( self::url( array( 'tab' => 'designs', 'design' => 'new' ) ) ); ?>"><?php esc_html_e( 'Add design', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></a></p>
			</div>
			<?php if ( '' !== $edit ) : ?>
				<?php
				$d = $design ? $design : array(
					'id'          => 0,
					'name'        => '',
					'description' => '',
					'color'       => '#1f2a44',
					'text_color'  => '#ffffff',
					'image_id'    => 0,
					'image_url'   => '',
					'sort_order'  => 0,
					'status'      => 1,
				);
				?>
				<div class="kudora-card-box">
					<h2><?php echo esc_html( $d['id'] ? __( 'Edit design', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) : __( 'New design', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="kudora-design-form">
						<input type="hidden" name="action" value="kudora_design_save" />
						<input type="hidden" name="id" value="<?php echo esc_attr( $d['id'] ); ?>" />
						<?php wp_nonce_field( 'kudora_design_save' ); ?>
						<table class="form-table" role="presentation">
							<tr><th><label for="kd_name"><?php esc_html_e( 'Name', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label></th><td><input type="text" id="kd_name" name="name" class="regular-text" value="<?php echo esc_attr( $d['name'] ); ?>" required /></td></tr>
							<tr><th><label for="kd_desc"><?php esc_html_e( 'Description', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label></th><td><input type="text" id="kd_desc" name="description" class="regular-text" maxlength="100" value="<?php echo esc_attr( $d['description'] ); ?>" /></td></tr>
							<tr><th><label for="kd_color"><?php esc_html_e( 'Background colour', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label></th><td><input type="color" id="kd_color" name="color" value="<?php echo esc_attr( $d['color'] ); ?>" data-kudora-preview="bg" /></td></tr>
							<tr><th><label for="kd_text"><?php esc_html_e( 'Text colour', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label></th><td><input type="color" id="kd_text" name="text_color" value="<?php echo esc_attr( $d['text_color'] ); ?>" data-kudora-preview="fg" /></td></tr>
							<tr><th><?php esc_html_e( 'Background image', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th><td>
								<input type="hidden" name="image_id" value="<?php echo esc_attr( $d['image_id'] ); ?>" class="kudora-image-id" />
								<button type="button" class="button kudora-pick-image"><?php esc_html_e( 'Choose image', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></button>
								<button type="button" class="button-link kudora-clear-image"><?php esc_html_e( 'Remove', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></button>
								<p class="description"><?php esc_html_e( 'JPEG or PNG work in the PDF too.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></p>
							</td></tr>
							<tr><th><label for="kd_sort"><?php esc_html_e( 'Sort order', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label></th><td><input type="number" id="kd_sort" name="sort_order" class="small-text" value="<?php echo esc_attr( $d['sort_order'] ); ?>" /></td></tr>
							<tr><th><?php esc_html_e( 'Visible', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></th><td><label><input type="checkbox" name="status" value="1" <?php checked( $d['status'] ); ?> /> <?php esc_html_e( 'Customers can choose this design', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label></td></tr>
						</table>
						<p><strong><?php esc_html_e( 'Gift card preview', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></strong></p>
						<div class="kudora-card kudora-design-preview" style="<?php echo esc_attr( Kudora_Gift_Card_Designs::style( $d ) ); ?>">
							<div class="kudora-card-top"><span class="kudora-card-store"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span><span class="kudora-card-label"><?php esc_html_e( 'Gift card', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></span></div>
							<div class="kudora-card-amount"><?php echo wp_kses_post( wc_price( 50 ) ); ?></div>
							<div class="kudora-card-to"><?php esc_html_e( 'To: Alex', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></div>
						</div>
						<?php submit_button( __( 'Save design', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) ); ?>
					</form>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Save a design.
	 *
	 * @return void
	 */
	public static function handle_design_save() {
		self::guard_caps();
		check_admin_referer( 'kudora_design_save' );
		$p  = map_deep( wp_unslash( $_POST ), 'sanitize_text_field' );
		$id = Kudora_Gift_Card_Designs::save(
			array(
				'id'          => isset( $p['id'] ) ? absint( $p['id'] ) : 0,
				'name'        => isset( $p['name'] ) ? $p['name'] : '',
				'description' => isset( $p['description'] ) ? $p['description'] : '',
				'color'       => isset( $p['color'] ) ? $p['color'] : '',
				'text_color'  => isset( $p['text_color'] ) ? $p['text_color'] : '',
				'image_id'    => isset( $p['image_id'] ) ? absint( $p['image_id'] ) : 0,
				'sort_order'  => isset( $p['sort_order'] ) ? (int) $p['sort_order'] : 0,
				'status'      => ! empty( $p['status'] ),
			)
		);
		Kudora_Admin::flash( __( 'Design saved.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		wp_safe_redirect( self::url( array( 'tab' => 'designs', 'design' => $id ) ) );
		exit;
	}

	/**
	 * Delete a design.
	 *
	 * @return void
	 */
	public static function handle_design_delete() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- part of the nonce action checked below.
		self::guard_caps();
		check_admin_referer( 'kudora_design_delete_' . $id );
		Kudora_Gift_Card_Designs::delete( $id );
		Kudora_Admin::flash( __( 'Design deleted.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		wp_safe_redirect( self::url( array( 'tab' => 'designs' ) ) );
		exit;
	}
}
