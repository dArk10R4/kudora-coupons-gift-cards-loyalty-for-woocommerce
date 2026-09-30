<?php
/**
 * Import / export screens (gift cards, customer points, coupons): exports stream
 * CSV; imports upload a file, show a dry-run preview, then run (in background
 * batches for large files).
 *
 * @package Kudora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import / export admin.
 */
class Kudora_Admin_Import_Export {

	/**
	 * Rows processed immediately; larger files go to background batches.
	 */
	const INLINE_LIMIT = 500;

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post_kudora_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_kudora_import_upload', array( __CLASS__, 'handle_upload' ) );
		add_action( 'admin_post_kudora_import_run', array( __CLASS__, 'handle_run' ) );
	}

	/**
	 * Where the user returns after an import step.
	 *
	 * @param string $type Type.
	 * @return string
	 */
	protected static function back_url( $type ) {
		switch ( $type ) {
			case 'points':
				return admin_url( 'admin.php?page=kudora-loyalty&tab=import' );
			case 'coupons':
				return admin_url( 'admin.php?page=kudora-generate-coupons&tab=import' );
			default:
				return admin_url( 'admin.php?page=kudora-gift-cards&tab=import' );
		}
	}

	/**
	 * Gift cards import/export screen.
	 *
	 * @return void
	 */
	public static function render_gift_cards() {
		self::render_screen(
			'gift_cards',
			__( 'Columns: code (empty = generate), amount, balance, expires_on (YYYY-MM-DD), status (active, disabled, expired), recipient_email, recipient_name, sender_name, message.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			array(
				'create' => __( 'Create new cards; skip codes that already exist', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'update' => __( 'Create new cards and update existing ones (balance, expiry, status, recipient)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			)
		);
	}

	/**
	 * Points import/export screen.
	 *
	 * @return void
	 */
	public static function render_points() {
		self::render_screen(
			'points',
			__( 'Columns: email or user_id, points, note. Customers must already have an account.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			array(
				'add' => __( 'Add the points to each customer’s balance', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'set' => __( 'Set each customer’s balance to the points in the file', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			)
		);
	}

	/**
	 * Coupons import/export screen.
	 *
	 * @return void
	 */
	public static function render_coupons() {
		self::render_screen(
			'coupons',
			__( 'Columns: code, discount_type, amount, expiry_date, usage_limit, usage_limit_per_user, individual_use, free_shipping, minimum_amount, maximum_amount, email_restrictions (a|b), product_ids (1|2), excluded_product_ids, product_categories, excluded_product_categories, start_date, weekdays (1|5 = Mon, Fri), auto_apply, max_discount, description.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			array(
				'create' => __( 'Create new coupons; skip codes that already exist', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
				'update' => __( 'Create new coupons and update existing ones', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ),
			)
		);
	}

	/**
	 * Shared screen.
	 *
	 * @param string $type    Type.
	 * @param string $columns Column help.
	 * @param array  $modes   Modes.
	 * @return void
	 */
	protected static function render_screen( $type, $columns, array $modes ) {
		$preview = Kudora_Importer::get_preview( get_current_user_id() );
		$jobs    = self::jobs_for_user();
		?>
		<div class="kudora-columns">
			<div class="kudora-card-box">
				<h2><?php esc_html_e( 'Export', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></h2>
				<p><?php esc_html_e( 'Download everything as CSV (opens in Excel, Numbers or Google Sheets).', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></p>
				<p><a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=kudora_export&type=' . $type ), 'kudora_export_' . $type ) ); ?>"><?php esc_html_e( 'Download CSV', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></a></p>
			</div>
			<div class="kudora-card-box kudora-grow">
				<h2><?php esc_html_e( 'Import', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></h2>
				<p class="description"><?php echo esc_html( $columns ); ?></p>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="kudora_import_upload" />
					<input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>" />
					<?php wp_nonce_field( 'kudora_import_upload' ); ?>
					<p><label for="kudora_csv"><?php esc_html_e( 'CSV file', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label><br /><input type="file" id="kudora_csv" name="csv" accept=".csv,text/csv" required /></p>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Mode', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></legend>
						<?php $first = true; ?>
						<?php foreach ( $modes as $k => $label ) : ?>
							<label style="display:block"><input type="radio" name="mode" value="<?php echo esc_attr( $k ); ?>" <?php checked( $first ); ?> /> <?php echo esc_html( $label ); ?></label>
							<?php $first = false; ?>
						<?php endforeach; ?>
					</fieldset>
					<?php if ( 'points' === $type ) : ?>
						<p><label><input type="checkbox" name="lifetime" value="1" /> <?php esc_html_e( 'Count imported points towards tiers', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></label></p>
					<?php endif; ?>
					<?php submit_button( __( 'Upload and preview', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'secondary' ); ?>
				</form>

				<?php if ( is_array( $preview ) && $type === $preview['type'] ) : ?>
					<div class="kudora-preview">
						<h3><?php esc_html_e( 'Preview (nothing saved yet)', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ); ?></h3>
						<p>
							<?php
							/* translators: 1: rows, 2: new, 3: updated, 4: skipped, 5: errors */
							echo esc_html( sprintf( __( '%1$d rows: %2$d new, %3$d updated, %4$d skipped, %5$d with errors.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), $preview['total'], $preview['result']['created'], $preview['result']['updated'], $preview['result']['skipped'] - count( $preview['result']['errors'] ), count( $preview['result']['errors'] ) ) );
							?>
						</p>
						<?php if ( $preview['result']['errors'] ) : ?>
							<ul class="kudora-errors">
								<?php foreach ( array_slice( $preview['result']['errors'], 0, 30 ) as $e ) : ?>
									<li><?php echo esc_html( $e ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="kudora_import_run" />
							<?php wp_nonce_field( 'kudora_import_run' ); ?>
							<?php submit_button( __( 'Run import', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'primary', 'submit', false ); ?>
						</form>
					</div>
				<?php endif; ?>

				<?php foreach ( $jobs as $job => $state ) : ?>
					<?php if ( $type === $state['type'] ) : ?>
						<p class="kudora-job">
							<?php
							/* translators: 1: status, 2: done, 3: total, 4: created, 5: updated, 6: skipped */
							echo esc_html( sprintf( __( 'Background import %1$s: %2$d of %3$d rows (%4$d new, %5$d updated, %6$d skipped).', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), $state['status'], $state['done'], $state['total'], $state['created'], $state['updated'], $state['skipped'] ) );
							?>
						</p>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Recent background jobs of the current user.
	 *
	 * @return array
	 */
	protected static function jobs_for_user() {
		$ids = (array) get_user_meta( get_current_user_id(), '_kudora_import_jobs', true );
		$out = array();
		foreach ( array_filter( $ids ) as $job ) {
			$state = get_option( 'kudora_import_' . sanitize_key( $job ) );
			if ( is_array( $state ) ) {
				$out[ $job ] = $state;
			}
		}
		return $out;
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
	 * Upload + dry run.
	 *
	 * @return void
	 */
	public static function handle_upload() {
		self::guard_caps();
		check_admin_referer( 'kudora_import_upload' );
		$type = isset( $_POST['type'] ) && isset( Kudora_Importer::types()[ sanitize_key( $_POST['type'] ) ] ) ? sanitize_key( $_POST['type'] ) : 'gift_cards';
		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'create';
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- tmp_name is PHP's own path, checked with is_uploaded_file().
		$tmp  = isset( $_FILES['csv']['tmp_name'] ) ? (string) $_FILES['csv']['tmp_name'] : '';
		$name = isset( $_FILES['csv']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['csv']['name'] ) ) : '';
		// phpcs:enable
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			Kudora_Admin::flash( __( 'Choose a CSV file.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'error' );
			wp_safe_redirect( self::back_url( $type ) );
			exit;
		}
		$check = wp_check_filetype( $name, array( 'csv' => 'text/csv', 'txt' => 'text/plain' ) );
		if ( ! $check['ext'] ) {
			Kudora_Admin::flash( __( 'Upload a .csv file.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'error' );
			wp_safe_redirect( self::back_url( $type ) );
			exit;
		}
		// Parsed straight from PHP's temporary upload, which PHP deletes when the request
		// ends: the file (codes, balances, emails) never lands in a web-readable folder.
		$data = Kudora_CSV::read( $tmp );
		if ( is_wp_error( $data ) ) {
			Kudora_Admin::flash( esc_html( $data->get_error_message() ), 'error' );
			wp_safe_redirect( self::back_url( $type ) );
			exit;
		}
		$opts = array(
			'mode'     => $mode,
			'lifetime' => ! empty( $_POST['lifetime'] ),
		);
		Kudora_Importer::save_preview(
			get_current_user_id(),
			array(
				'type'   => $type,
				'opts'   => $opts,
				'rows'   => $data['rows'],
				'total'  => count( $data['rows'] ),
				'result' => Kudora_Importer::run( $type, $data['rows'], $opts, true ),
			)
		);
		wp_safe_redirect( self::back_url( $type ) );
		exit;
	}

	/**
	 * Run the previewed import.
	 *
	 * @return void
	 */
	public static function handle_run() {
		self::guard_caps();
		check_admin_referer( 'kudora_import_run' );
		$preview = Kudora_Importer::get_preview( get_current_user_id() );
		if ( ! $preview || ! isset( $preview['rows'] ) ) {
			Kudora_Admin::flash( __( 'The preview expired. Upload the file again.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), 'error' );
			wp_safe_redirect( admin_url( 'admin.php?page=kudora-gift-cards&tab=import' ) );
			exit;
		}
		Kudora_Importer::clear_preview( get_current_user_id() );
		$type = $preview['type'];
		if ( $preview['total'] > self::INLINE_LIMIT ) {
			$job    = Kudora_Importer::queue( $preview['rows'], $type, $preview['opts'] );
			$jobs   = (array) get_user_meta( get_current_user_id(), '_kudora_import_jobs', true );
			$jobs[] = $job;
			update_user_meta( get_current_user_id(), '_kudora_import_jobs', array_slice( array_filter( $jobs ), -5 ) );
			Kudora_Admin::flash( __( 'The import is running in the background. Reload this page to see its progress.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ) );
		} else {
			$res = Kudora_Importer::run( $type, $preview['rows'], $preview['opts'], false );
			/* translators: 1: new, 2: updated, 3: skipped */
			$msg = sprintf( __( 'Import finished: %1$d new, %2$d updated, %3$d skipped.', 'kudora-coupons-gift-cards-loyalty-for-woocommerce' ), $res['created'], $res['updated'], $res['skipped'] );
			if ( $res['errors'] ) {
				$msg .= '<br />' . implode( '<br />', array_map( 'esc_html', array_slice( $res['errors'], 0, 20 ) ) );
			}
			Kudora_Admin::flash( $msg, $res['errors'] ? 'error' : 'success' );
		}
		wp_safe_redirect( self::back_url( $type ) );
		exit;
	}

	/**
	 * Exports.
	 *
	 * @return void
	 */
	public static function handle_export() {
		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- part of the nonce action checked below.
		self::guard_caps();
		check_admin_referer( 'kudora_export_' . $type );
		$date = gmdate( 'Y-m-d' );
		if ( 'points' === $type ) {
			$rows = array( array( 'user_id', 'email', 'name', 'points', 'lifetime_earned', 'tier' ) );
			$page = 1;
			do {
				$res = Kudora_Points_Repo::customers( array( 'per_page' => 500, 'page' => $page ) );
				foreach ( $res['rows'] as $r ) {
					$rows[] = array( $r['user_id'], $r['user_email'], $r['display_name'], $r['balance'], $r['lifetime_earned'], $r['tier_id'] );
				}
				++$page;
			} while ( count( $res['rows'] ) === 500 );
			Kudora_CSV::download( "points-{$date}.csv", $rows );
		}
		if ( 'coupons' === $type ) {
			$rows = array( array( 'code', 'discount_type', 'amount', 'expiry_date', 'usage_count', 'usage_limit', 'usage_limit_per_user', 'individual_use', 'free_shipping', 'minimum_amount', 'maximum_amount', 'email_restrictions', 'product_ids', 'excluded_product_ids', 'product_categories', 'excluded_product_categories', 'start_date', 'weekdays', 'auto_apply', 'max_discount', 'description' ) );
			$ids  = get_posts(
				array(
					'post_type'      => 'shop_coupon',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);
			foreach ( $ids as $id ) {
				$c      = new WC_Coupon( $id );
				$exp    = $c->get_date_expires();
				$rows[] = array(
					$c->get_code(),
					$c->get_discount_type(),
					$c->get_amount(),
					$exp ? $exp->date( 'Y-m-d' ) : '',
					$c->get_usage_count(),
					$c->get_usage_limit(),
					$c->get_usage_limit_per_user(),
					$c->get_individual_use() ? 'yes' : 'no',
					$c->get_free_shipping() ? 'yes' : 'no',
					$c->get_minimum_amount(),
					$c->get_maximum_amount(),
					implode( '|', $c->get_email_restrictions() ),
					implode( '|', $c->get_product_ids() ),
					implode( '|', $c->get_excluded_product_ids() ),
					implode( '|', $c->get_product_categories() ),
					implode( '|', $c->get_excluded_product_categories() ),
					(string) $c->get_meta( '_kudora_start' ),
					implode( '|', array_filter( array_map( 'intval', (array) $c->get_meta( '_kudora_weekdays' ) ) ) ),
					'yes' === $c->get_meta( '_kudora_auto_apply' ) ? 'yes' : 'no',
					(string) $c->get_meta( '_kudora_max_discount' ),
					$c->get_description(),
				);
			}
			Kudora_CSV::download( "coupons-{$date}.csv", $rows );
		}
		$rows = array( array( 'code', 'amount', 'balance', 'expires_on', 'status', 'recipient_email', 'recipient_name', 'sender_name', 'message', 'order_id', 'source', 'created_at' ) );
		$page = 1;
		do {
			$res = Kudora_Gift_Card_Repo::query( array( 'per_page' => 500, 'page' => $page, 'order' => 'asc' ) );
			foreach ( $res['rows'] as $c ) {
				$rows[] = array( $c['code'], $c['initial_amount'], $c['balance'], $c['expires_on'], $c['status'], $c['recipient_email'], $c['recipient_name'], $c['sender_name'], $c['message'], $c['order_id'], $c['source'], $c['created_at'] );
			}
			++$page;
		} while ( count( $res['rows'] ) === 500 );
		Kudora_CSV::download( "gift-cards-{$date}.csv", $rows );
	}
}
