<?php
/**
 * Admin screens: WooCommerce → Fio payments, order meta box, health notice.
 *
 * @package PaymentMatcherForFioBank
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PMFIO_Admin {

	const SLUG = 'payment-matcher-for-fio-bank';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_pmfio_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_pmfio_run', array( __CLASS__, 'run_now' ) );
		add_action( 'admin_post_pmfio_assign', array( __CLASS__, 'assign' ) );
		add_action( 'admin_post_pmfio_restore', array( __CLASS__, 'restore' ) );
		add_action( 'admin_notices', array( __CLASS__, 'health_notice' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
	}

	/**
	 * Tags allowed in admin markup built by this class (forms, pills, links, QR SVG).
	 */
	public static function allowed_html() {
		$common = array( 'class' => true, 'id' => true );
		return array(
			'form'   => array_merge( $common, array( 'method' => true, 'action' => true ) ),
			'input'  => array_merge( $common, array( 'type' => true, 'name' => true, 'value' => true, 'placeholder' => true, 'inputmode' => true, 'aria-label' => true, 'autocomplete' => true, 'checked' => true, 'min' => true, 'max' => true, 'maxlength' => true ) ),
			'button' => array_merge( $common, array( 'type' => true ) ),
			'label'  => array_merge( $common, array( 'for' => true ) ),
			'span'   => $common,
			'div'    => $common,
			'p'      => $common,
			'strong' => array(),
			'br'     => array(),
			'code'   => array(),
			'a'      => array_merge( $common, array( 'href' => true, 'aria-current' => true ) ),
			'li'     => $common,
			'bdi'    => array(),
			'details' => array_merge( $common, array( 'open' => true ) ),
			'summary' => $common,
			'ol'      => $common,
			'em'      => array(),
		) + PMFIO_QR_Code::allowed_svg();
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'Fio payments', 'payment-matcher-for-fio-bank' ), __( 'Fio payments', 'payment-matcher-for-fio-bank' ), 'manage_woocommerce', self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function assets( $hook ) {
		$screen  = get_current_screen();
		$order   = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		$is_page = false !== strpos( (string) $hook, self::SLUG );
		if ( $is_page || ( $screen && $screen->id === $order ) ) {
			wp_enqueue_style( 'pmfio-admin', PMFIO_PLUGIN_URL . 'assets/admin.css', array(), PMFIO_VERSION );
		}
	}

	/**
	 * Result → [label, tone]. Tones: ok, warn, bad, info, muted.
	 */
	public static function result_meta() {
		return array(
			'matched'      => array( __( 'Paid', 'payment-matcher-for-fio-bank' ), 'ok' ),
			'overpaid'     => array( __( 'Paid, overpayment', 'payment-matcher-for-fio-bank' ), 'ok' ),
			'partial'      => array( __( 'Underpayment', 'payment-matcher-for-fio-bank' ), 'warn' ),
			'review'       => array( __( 'To review', 'payment-matcher-for-fio-bank' ), 'bad' ),
			'no_order'     => array( __( 'No order', 'payment-matcher-for-fio-bank' ), 'warn' ),
			'obs_matched'  => array( __( 'Would mark paid', 'payment-matcher-for-fio-bank' ), 'info' ),
			'obs_overpaid' => array( __( 'Would mark paid (overpayment)', 'payment-matcher-for-fio-bank' ), 'info' ),
			'obs_partial'  => array( __( 'Underpayment (observing)', 'payment-matcher-for-fio-bank' ), 'muted' ),
			'obs_review'   => array( __( 'To review (observing)', 'payment-matcher-for-fio-bank' ), 'muted' ),
			'obs_no_order' => array( __( 'No order (observing)', 'payment-matcher-for-fio-bank' ), 'muted' ),
			'seen'         => array( __( 'Seen while observing', 'payment-matcher-for-fio-bank' ), 'muted' ),
			'refund'       => array( __( 'Refunded', 'payment-matcher-for-fio-bank' ), 'info' ),
			'obs_refund'   => array( __( 'Refund (observing)', 'payment-matcher-for-fio-bank' ), 'muted' ),
		);
	}

	public static function labels() {
		return array_map( function ( $m ) {
			return $m[0];
		}, self::result_meta() );
	}

	private static function pill( $result ) {
		$meta = self::result_meta();
		list( $label, $tone ) = $meta[ $result ] ?? array( $result, 'muted' );
		return '<span class="pmfio-pill pmfio-pill--' . esc_attr( $tone ) . '">' . esc_html( $label ) . '</span>';
	}

	private static function page_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	private static function back( $msg, $tab = '', $type = 'info' ) {
		$args = array(
			'pmfio_msg'  => rawurlencode( $msg ),
			'pmfio_type' => $type,
		);
		if ( '' !== $tab ) {
			$args['tab'] = $tab;
		}
		wp_safe_redirect( self::page_url( $args ) );
		exit;
	}

	private static function guard( $nonce ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'payment-matcher-for-fio-bank' ) );
		}
		check_admin_referer( $nonce );
	}

	private static function user_label() {
		$u = wp_get_current_user();
		return $u && $u->exists() ? $u->display_name : 'admin';
	}

	/**
	 * Sanitized text from $_POST. Only called after guard() verified the nonce and capability.
	 */
	private static function post_text( $key ) {
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in guard().
	}

	private static function post_flag( $key ) {
		return ! empty( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in guard().
	}

	private static function post_int( $key, $default, $min, $max ) {
		$value = isset( $_POST[ $key ] ) ? absint( wp_unslash( $_POST[ $key ] ) ) : $default; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in guard().
		return max( $min, min( $max, $value ) );
	}

	private static function valid_date( $value ) {
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) && checkdate( (int) substr( $value, 5, 2 ), (int) substr( $value, 8, 2 ), (int) substr( $value, 0, 4 ) ) ? $value : '';
	}

	public static function save() {
		self::guard( 'pmfio_save' );
		$tab  = 'qr' === self::post_text( 'tab' ) ? 'qr' : 'settings';
		$curr = PMFIO_Settings::all();

		if ( 'qr' === $tab ) {
			$iban = strtoupper( preg_replace( '/\s+/', '', self::post_text( 'qr_iban' ) ) );
			if ( '' !== $iban && ! PMFIO_QR_Payment::valid_iban( $iban ) ) {
				self::back( __( 'The IBAN is not valid, nothing was saved.', 'payment-matcher-for-fio-bank' ), 'qr', 'error' );
			}
			$new = array_merge( $curr, array(
				'qr_thankyou' => self::post_flag( 'qr_thankyou' ) ? 'yes' : 'no',
				'qr_email'    => self::post_flag( 'qr_email' ) ? 'yes' : 'no',
				'qr_iban'     => $iban,
				'qr_message'  => self::post_text( 'qr_message' ),
			) );
			update_option( PMFIO_Settings::OPTION, $new, false );
			self::back( __( 'Saved.', 'payment-matcher-for-fio-bank' ), 'qr', 'success' );
		}

		$new = array_merge( $curr, array(
			'mode'          => 'live' === self::post_text( 'mode' ) ? 'live' : 'observe',
			'gateways'      => self::post_text( 'gateways' ),
			'statuses'      => self::post_text( 'statuses' ),
			'number_meta'   => sanitize_key( self::post_text( 'number_meta' ) ),
			'days_back'     => self::post_int( 'days_back', 3, 1, 89 ),
			'notify_email'  => sanitize_email( self::post_text( 'notify_email' ) ),
			'daily_summary' => self::post_flag( 'daily_summary' ) ? 'yes' : 'no',
			'reminder_days' => self::post_int( 'reminder_days', 3, 1, 30 ),
			'token_expires' => self::valid_date( self::post_text( 'token_expires' ) ),
			'retention'     => self::post_int( 'retention', 12, 0, 120 ),
		) );
		if ( 'live' === $new['mode'] && ! PMFIO_Settings::is_live() ) {
			PMFIO_Store::mark_observed_seen();
			update_option( 'pmfio_live_since', time(), false );
		}
		update_option( PMFIO_Settings::OPTION, $new, false );

		$token = preg_replace( '/[^A-Za-z0-9]/', '', self::post_text( 'token' ) );
		if ( '' !== $token ) {
			if ( '' === $new['token_expires'] || $new['token_expires'] === $curr['token_expires'] ) {
				$new['token_expires'] = wp_date( 'Y-m-d', time() + 180 * DAY_IN_SECONDS );
				update_option( PMFIO_Settings::OPTION, $new, false );
			}
			update_option( PMFIO_Settings::TOKEN_OPTION, $token, false );
			update_option( 'pmfio_token_saved', time(), false );
			delete_option( 'pmfio_last_error' );
			delete_option( 'pmfio_alert_sent' );
		}
		if ( self::post_flag( 'token_delete' ) ) {
			delete_option( PMFIO_Settings::TOKEN_OPTION );
			PMFIO_Runner::unschedule();
		}
		self::back( __( 'Saved.', 'payment-matcher-for-fio-bank' ), 'settings', 'success' );
	}

	public static function run_now() {
		self::guard( 'pmfio_run' );
		$res = PMFIO_Runner::run();
		self::back( $res['message'], '', $res['ok'] ? 'success' : 'error' );
	}

	public static function assign() {
		self::guard( 'pmfio_assign' );
		$id     = self::post_text( 'movement_id' );
		$number = self::post_text( 'order_number' );
		if ( '' === $number ) {
			self::back( __( 'Enter an order number.', 'payment-matcher-for-fio-bank' ), '', 'error' );
		}
		list( $ok, $msg ) = PMFIO_Matcher::assign( $id, $number, self::user_label() );
		self::back( $msg, '', $ok ? 'success' : 'error' );
	}

	public static function restore() {
		self::guard( 'pmfio_restore' );
		$id = self::post_text( 'movement_id' );
		list( $ok, $msg ) = PMFIO_Matcher::restore( $id, self::user_label() );
		self::back( $msg, '', $ok ? 'success' : 'error' );
	}

	public static function health_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$exp = PMFIO_Runner::token_expiry();
		if ( in_array( $exp['state'], array( 'soon', 'expired' ), true ) ) {
			$date = wp_date( get_option( 'date_format' ), strtotime( PMFIO_Settings::get( 'token_expires' ) . ' 12:00:00' ) );
			/* translators: %s: date */
			$text = 'expired' === $exp['state'] ? sprintf( __( 'The Fio API token expired on %s.', 'payment-matcher-for-fio-bank' ), $date ) : sprintf( __( 'The Fio API token expires on %s.', 'payment-matcher-for-fio-bank' ), $date );
			echo '<div class="notice notice-' . ( 'expired' === $exp['state'] ? 'error' : 'warning' ) . '"><p><strong>' . esc_html( $text ) . '</strong> ' . esc_html__( 'Log in to Fio internet banking to extend it (automatic renewal), or create a new token, then update the date.', 'payment-matcher-for-fio-bank' ) . ' <a href="' . esc_url( self::page_url( array( 'tab' => 'settings' ) ) ) . '">' . esc_html__( 'Open settings', 'payment-matcher-for-fio-bank' ) . '</a></p></div>';
		}
		$problem = PMFIO_Runner::health_problem();
		if ( '' === $problem ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Fio payment matching is not working:', 'payment-matcher-for-fio-bank' ) . '</strong> ' . esc_html( $problem ) . ' <a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Open Fio payments', 'payment-matcher-for-fio-bank' ) . '</a></p></div>';
	}

	public static function meta_box() {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'pmfio-payments', __( 'Fio payments', 'payment-matcher-for-fio-bank' ), array( __CLASS__, 'render_meta_box' ), $screen, 'side', 'default' );
	}

	private static function days_text( $days ) {
		/* translators: %d: number of days */
		return sprintf( _n( '%d day', '%d days', $days, 'payment-matcher-for-fio-bank' ), $days );
	}

	public static function render_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID ?? 0 );
		if ( ! $order ) {
			return;
		}
		$rows = PMFIO_Store::for_order( $order->get_id() );
		echo '<div class="pmfio-box">';
		if ( $rows ) {
			echo '<ul class="pmfio-box__list">';
			foreach ( $rows as $r ) {
				echo '<li><div class="pmfio-box__row"><strong>' . wp_kses_post( wc_price( $r->amount, array( 'currency' => $r->currency ?: $order->get_currency() ) ) ) . '</strong>' . wp_kses( self::pill( $r->result ), self::allowed_html() ) . '</div>';
				echo '<div class="pmfio-box__meta">' . esc_html( $r->moved_on ? wp_date( get_option( 'date_format' ), strtotime( $r->moved_on ) ) : '' ) . ' · ' . esc_html( trim( $r->counter_name ) ?: $r->counter_account ) . '</div></li>';
			}
			echo '</ul>';
		} else {
			echo '<p class="pmfio-box__empty">' . esc_html__( 'No Fio payment yet.', 'payment-matcher-for-fio-bank' ) . '</p>';
		}
		if ( ! $order->is_paid() && in_array( $order->get_payment_method(), PMFIO_Settings::list_of( 'gateways' ), true ) && $order->get_date_created() ) {
			$days = (int) floor( ( time() - $order->get_date_created()->getTimestamp() ) / DAY_IN_SECONDS );
			/* translators: %s: number of days */
			echo '<p class="pmfio-box__wait">' . esc_html( sprintf( __( 'Waiting for payment: %s', 'payment-matcher-for-fio-bank' ), self::days_text( $days ) ) );
			if ( $order->get_meta( '_pmfio_reminded' ) ) {
				/* translators: %s: date */
				echo '<br>' . esc_html( sprintf( __( 'Reminder sent %s', 'payment-matcher-for-fio-bank' ), wp_date( get_option( 'date_format' ), (int) $order->get_meta( '_pmfio_reminded' ) ) ) );
			}
			echo '</p>';
		}
		echo '</div>';
	}

	private static function order_link( $order_id ) {
		$order = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order ) {
			return '';
		}
		return '<a href="' . esc_url( $order->get_edit_order_url() ) . '">' . esc_html( $order->get_order_number() ) . '</a>';
	}

	private static function row_actions( $r ) {
		$open = array( 'no_order', 'review', 'obs_no_order', 'obs_review', 'obs_matched', 'obs_overpaid', 'obs_partial', 'seen' );
		if ( ! in_array( $r->result, $open, true ) ) {
			return '';
		}
		$post = esc_url( admin_url( 'admin-post.php' ) );
		$id   = esc_attr( $r->movement_id );
		$out  = '';

		$order = $r->order_id ? wc_get_order( (int) $r->order_id ) : null;
		if ( 'review' === $r->result && PMFIO_Matcher::is_restorable( $order ) ) {
			$out .= '<form method="post" action="' . $post . '" class="pmfio-act">' . wp_nonce_field( 'pmfio_restore', '_wpnonce', true, false )
				. '<input type="hidden" name="action" value="pmfio_restore"><input type="hidden" name="movement_id" value="' . $id . '">'
				. '<button class="button button-small">' . esc_html__( 'Restore and mark paid', 'payment-matcher-for-fio-bank' ) . '</button></form>';
		}

		$prefill = '';
		if ( $r->suggested_order_id ) {
			$sug = wc_get_order( (int) $r->suggested_order_id );
			if ( $sug ) {
				$prefill = $sug->get_order_number();
				/* translators: %s: order link */
				$out .= '<div class="pmfio-suggest">' . sprintf( esc_html__( 'Suggested: %s', 'payment-matcher-for-fio-bank' ), self::order_link( $sug->get_id() ) ) . '</div>';
			}
		}
		$out .= '<form method="post" action="' . $post . '" class="pmfio-act pmfio-act--assign">' . wp_nonce_field( 'pmfio_assign', '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="pmfio_assign"><input type="hidden" name="movement_id" value="' . $id . '">'
			. '<input type="text" name="order_number" value="' . esc_attr( $prefill ) . '" placeholder="' . esc_attr__( 'order no.', 'payment-matcher-for-fio-bank' ) . '" inputmode="numeric" aria-label="' . esc_attr__( 'Order number', 'payment-matcher-for-fio-bank' ) . '">'
			. '<button class="button button-small' . ( $prefill ? ' button-primary' : '' ) . '">' . esc_html__( 'Assign', 'payment-matcher-for-fio-bank' ) . '</button></form>';
		return $out;
	}

	/** "14:05" for today, otherwise date + time. */
	private static function when( $mysql ) {
		$ts = strtotime( get_gmt_from_date( $mysql ) . ' UTC' );
		if ( ! $ts ) {
			return '';
		}
		$today = wp_date( 'Y-m-d' ) === wp_date( 'Y-m-d', $ts );
		return $today ? wp_date( get_option( 'time_format' ), $ts ) : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
	}

	public static function render() {
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'payments'; // phpcs:ignore WordPress.Security.NonceVerification
		$tabs = array(
			'payments' => __( 'Payments', 'payment-matcher-for-fio-bank' ),
			'settings' => __( 'Settings', 'payment-matcher-for-fio-bank' ),
			'qr'       => __( 'QR payment', 'payment-matcher-for-fio-bank' ),
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'payments';
		}
		$msg  = isset( $_GET['pmfio_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['pmfio_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$type = isset( $_GET['pmfio_type'] ) && in_array( $_GET['pmfio_type'], array( 'success', 'error', 'info' ), true ) ? sanitize_key( $_GET['pmfio_type'] ) : 'info'; // phpcs:ignore WordPress.Security.NonceVerification
		$live = PMFIO_Settings::is_live();
		?>
		<div class="wrap pmfio">
			<div class="pmfio-head">
				<h1><?php esc_html_e( 'Fio payments', 'payment-matcher-for-fio-bank' ); ?></h1>
				<span class="pmfio-pill pmfio-pill--<?php echo $live ? 'ok' : 'info'; ?> pmfio-pill--lg"><?php echo $live ? esc_html__( 'Live', 'payment-matcher-for-fio-bank' ) : esc_html__( 'Observing only', 'payment-matcher-for-fio-bank' ); ?></span>
			</div>
			<hr class="wp-header-end">
			<?php if ( $msg ) : ?>
				<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
			<?php endif; ?>
			<nav class="nav-tab-wrapper pmfio-tabs">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( self::page_url( 'payments' === $key ? array() : array( 'tab' => $key ) ) ); ?>" class="nav-tab<?php echo $key === $tab ? ' nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php
			if ( 'settings' === $tab ) {
				self::render_settings();
			} elseif ( 'qr' === $tab ) {
				self::render_qr();
			} else {
				self::render_payments();
			}
			?>
		</div>
		<?php
	}

	private static function render_payments() {
		$has_tok = '' !== PMFIO_Settings::token();
		$last    = get_option( 'pmfio_last_run' );
		$next    = function_exists( 'as_next_scheduled_action' ) ? as_next_scheduled_action( PMFIO_Runner::HOOK, array(), PMFIO_Runner::GROUP ) : false;
		$week    = wp_date( 'Y-m-d', time() - 7 * DAY_IN_SECONDS );
		$paid    = PMFIO_Store::stats( array( 'matched', 'overpaid' ), $week );
		$groups  = PMFIO_Store::groups();
		$open    = PMFIO_Store::stats( $groups['open'], wp_date( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ) );
		$waiting = count( PMFIO_Runner::waiting_orders( 3 ) );
		$filter  = isset( $_GET['show'] ) ? sanitize_key( wp_unslash( $_GET['show'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$rows    = PMFIO_Store::latest( 100, $filter );

		if ( ! $has_tok ) {
			echo '<div class="pmfio-empty"><h2>' . esc_html__( 'Connect your Fio account', 'payment-matcher-for-fio-bank' ) . '</h2>' . wp_kses( self::token_guide( true ), self::allowed_html() ) . '<a class="button button-primary" href="' . esc_url( self::page_url( array( 'tab' => 'settings' ) ) ) . '">' . esc_html__( 'Open settings', 'payment-matcher-for-fio-bank' ) . '</a></div>';
			return;
		}
		?>
		<div class="pmfio-cards">
			<div class="pmfio-card">
				<div class="pmfio-card__label"><?php esc_html_e( 'Last check', 'payment-matcher-for-fio-bank' ); ?></div>
				<div class="pmfio-card__value"><?php echo $last ? esc_html( self::when( $last['time'] ) ) : esc_html__( 'not yet', 'payment-matcher-for-fio-bank' ); ?></div>
				<div class="pmfio-card__sub">
					<?php
					if ( $next ) {
						/* translators: %s: time */
						echo esc_html( sprintf( __( 'next at %s', 'payment-matcher-for-fio-bank' ), wp_date( get_option( 'time_format' ), $next ) ) );
					}
					?>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'pmfio_run' ); ?>
					<input type="hidden" name="action" value="pmfio_run">
					<button class="button button-small" id="pmfio-run"><?php esc_html_e( 'Check now', 'payment-matcher-for-fio-bank' ); ?></button>
				</form>
			</div>
			<div class="pmfio-card">
				<div class="pmfio-card__label"><?php esc_html_e( 'Paid in the last 7 days', 'payment-matcher-for-fio-bank' ); ?></div>
				<div class="pmfio-card__value"><?php echo wp_kses_post( wc_price( $paid['sum'] ) ); ?></div>
				<div class="pmfio-card__sub"><?php /* translators: %d: number of payments */ echo esc_html( sprintf( _n( '%d payment', '%d payments', $paid['count'], 'payment-matcher-for-fio-bank' ), $paid['count'] ) ); ?></div>
			</div>
			<a class="pmfio-card pmfio-card--link<?php echo $open['count'] ? ' pmfio-card--alert' : ''; ?>" href="<?php echo esc_url( self::page_url( array( 'show' => 'open' ) ) ); ?>">
				<div class="pmfio-card__label"><?php esc_html_e( 'To review', 'payment-matcher-for-fio-bank' ); ?></div>
				<div class="pmfio-card__value"><?php echo (int) $open['count']; ?></div>
				<div class="pmfio-card__sub"><?php esc_html_e( 'last 30 days', 'payment-matcher-for-fio-bank' ); ?></div>
			</a>
			<div class="pmfio-card">
				<div class="pmfio-card__label"><?php esc_html_e( 'Waiting > 3 days', 'payment-matcher-for-fio-bank' ); ?></div>
				<div class="pmfio-card__value"><?php echo (int) $waiting; ?></div>
				<div class="pmfio-card__sub"><?php esc_html_e( 'unpaid bank transfers', 'payment-matcher-for-fio-bank' ); ?></div>
			</div>
		</div>

		<?php if ( ! PMFIO_Settings::is_live() ) : ?>
			<div class="pmfio-callout"><?php esc_html_e( 'Observing only: payments are downloaded and listed below, but orders are not changed automatically and nothing is sent. Manual “Assign” always applies for real.', 'payment-matcher-for-fio-bank' ); ?></div>
		<?php endif; ?>

		<ul class="subsubsub pmfio-filters">
			<?php
			$filters = array(
				''        => __( 'All', 'payment-matcher-for-fio-bank' ),
				'open'    => __( 'To review', 'payment-matcher-for-fio-bank' ),
				'paid'    => __( 'Paid', 'payment-matcher-for-fio-bank' ),
				'nomatch' => __( 'No order', 'payment-matcher-for-fio-bank' ),
			);
			$links = array();
			foreach ( $filters as $key => $label ) {
				$links[] = '<li><a href="' . esc_url( self::page_url( '' === $key ? array() : array( 'show' => $key ) ) ) . '"' . ( $key === $filter ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
			}
			echo wp_kses( implode( ' |</li>', $links ) . '</li>', self::allowed_html() );
			?>
		</ul>

		<table class="widefat pmfio-table">
			<thead><tr>
				<th><?php esc_html_e( 'Date', 'payment-matcher-for-fio-bank' ); ?></th>
				<th class="pmfio-num"><?php esc_html_e( 'Amount', 'payment-matcher-for-fio-bank' ); ?></th>
				<th><?php esc_html_e( 'VS', 'payment-matcher-for-fio-bank' ); ?></th>
				<th><?php esc_html_e( 'From', 'payment-matcher-for-fio-bank' ); ?></th>
				<th><?php esc_html_e( 'Order', 'payment-matcher-for-fio-bank' ); ?></th>
				<th><?php esc_html_e( 'Result', 'payment-matcher-for-fio-bank' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'payment-matcher-for-fio-bank' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="7" class="pmfio-table__empty"><?php esc_html_e( 'No incoming payments yet.', 'payment-matcher-for-fio-bank' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $rows as $r ) : ?>
				<tr>
					<td data-label="<?php esc_attr_e( 'Date', 'payment-matcher-for-fio-bank' ); ?>"><?php echo esc_html( $r->moved_on ? wp_date( get_option( 'date_format' ), strtotime( $r->moved_on ) ) : '' ); ?></td>
					<td class="pmfio-num" data-label="<?php esc_attr_e( 'Amount', 'payment-matcher-for-fio-bank' ); ?>"><strong><?php echo wp_kses_post( wc_price( $r->amount, array( 'currency' => $r->currency ?: get_woocommerce_currency() ) ) ); ?></strong></td>
					<td data-label="<?php esc_attr_e( 'VS', 'payment-matcher-for-fio-bank' ); ?>"><code><?php echo esc_html( $r->vs ?: '–' ); ?></code></td>
					<td data-label="<?php esc_attr_e( 'From', 'payment-matcher-for-fio-bank' ); ?>">
						<?php echo esc_html( trim( $r->counter_name ) ?: '–' ); ?>
						<?php if ( $r->counter_account ) : ?><div class="pmfio-sub"><?php echo esc_html( $r->counter_account ); ?></div><?php endif; ?>
						<?php if ( $r->message ) : ?><div class="pmfio-sub">„<?php echo esc_html( $r->message ); ?>“</div><?php endif; ?>
					</td>
					<td data-label="<?php esc_attr_e( 'Order', 'payment-matcher-for-fio-bank' ); ?>"><?php echo wp_kses_post( self::order_link( (int) $r->order_id ) ?: '–' ); ?></td>
					<td data-label="<?php esc_attr_e( 'Result', 'payment-matcher-for-fio-bank' ); ?>"><?php echo wp_kses( self::pill( $r->result ), self::allowed_html() ); ?></td>
					<td class="pmfio-actions"><?php echo wp_kses( self::row_actions( $r ), self::allowed_html() ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function field( $label, $for, $control, $help = '' ) {
		echo '<div class="pmfio-field"><label class="pmfio-field__label" for="' . esc_attr( $for ) . '">' . esc_html( $label ) . '</label><div class="pmfio-field__control">' . wp_kses( $control, self::allowed_html() );
		if ( '' !== $help ) {
			echo '<p class="pmfio-help">' . wp_kses_post( $help ) . '</p>';
		}
		echo '</div></div>';
	}

	/** Step-by-step guide for creating the read-only Fio API token. */
	private static function token_guide( $open = false ) {
		$steps = array(
			__( 'Log in to Fio internet banking (ib.fio.cz).', 'payment-matcher-for-fio-bank' ),
			__( 'Open Settings → API.', 'payment-matcher-for-fio-bank' ),
			__( 'Choose the account that receives payments for orders and click “Add new token”. Enter a name, e.g. the website address.', 'payment-matcher-for-fio-bank' ),
			__( 'Choose the type “Account monitoring only” (read-only). The plugin never needs to send payments.', 'payment-matcher-for-fio-bank' ),
			__( 'Set the validity to the maximum (180 days) and turn on automatic renewal – every login to internet banking then extends it.', 'payment-matcher-for-fio-bank' ),
			__( 'Confirm with SMS or the Smartbanking app. The token works about 5 minutes after confirmation.', 'payment-matcher-for-fio-bank' ),
			__( 'Click “Show full token”, copy all 64 characters, paste them in the plugin settings and save.', 'payment-matcher-for-fio-bank' ),
		);
		$out = '<details class="pmfio-guide"' . ( $open ? ' open' : '' ) . '><summary>' . esc_html__( 'How to get the token', 'payment-matcher-for-fio-bank' ) . '</summary><ol>';
		foreach ( $steps as $step ) {
			$out .= '<li>' . esc_html( $step ) . '</li>';
		}
		$out .= '</ol><p class="pmfio-help">' . esc_html__( 'Keep the token private: anyone who has it can read the account’s movements. You can delete it in internet banking at any time.', 'payment-matcher-for-fio-bank' ) . '</p></details>';
		return $out;
	}

	private static function render_settings() {
		$s       = PMFIO_Settings::all();
		$has_tok = '' !== PMFIO_Settings::token();
		$active  = array_keys( WC()->payment_gateways()->get_available_payment_gateways() );
		$rem_url = admin_url( 'admin.php?page=wc-settings&tab=email&section=pmfio_email_reminder' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pmfio-form">
			<?php wp_nonce_field( 'pmfio_save' ); ?>
			<input type="hidden" name="action" value="pmfio_save">
			<input type="hidden" name="tab" value="settings">

			<section class="pmfio-panel">
				<h2><?php esc_html_e( 'Connection', 'payment-matcher-for-fio-bank' ); ?></h2>
				<?php
				if ( PMFIO_Settings::token_from_constant() ) {
					self::field( __( 'Fio API token', 'payment-matcher-for-fio-bank' ), 'pmfio-token', '<p class="pmfio-ok">✓ ' . esc_html__( 'Set in wp-config.php (constant PMFIO_FIO_TOKEN).', 'payment-matcher-for-fio-bank' ) . '</p>' );
				} else {
					$control = '<input type="password" id="pmfio-token" name="token" class="regular-text" autocomplete="new-password" placeholder="' . esc_attr( $has_tok ? __( 'saved – paste a new one to replace it', 'payment-matcher-for-fio-bank' ) : __( 'paste the token', 'payment-matcher-for-fio-bank' ) ) . '">';
					if ( $has_tok ) {
						$control .= '<p class="pmfio-ok">✓ ' . esc_html__( 'Token saved.', 'payment-matcher-for-fio-bank' ) . ' <label><input type="checkbox" name="token_delete" value="1"> ' . esc_html__( 'delete', 'payment-matcher-for-fio-bank' ) . '</label></p>';
					}
					$control .= self::token_guide();
					self::field( __( 'Fio API token', 'payment-matcher-for-fio-bank' ), 'pmfio-token', $control, __( 'Each website needs its own token.', 'payment-matcher-for-fio-bank' ) );
				}
				$mode = '<label class="pmfio-choice"><input type="radio" name="mode" value="observe" ' . checked( 'live' !== $s['mode'], true, false ) . '> <span><strong>' . esc_html__( 'Observing only', 'payment-matcher-for-fio-bank' ) . '</strong><br>' . esc_html__( 'Downloads and lists payments, changes nothing.', 'payment-matcher-for-fio-bank' ) . '</span></label>'
					. '<label class="pmfio-choice"><input type="radio" name="mode" value="live" ' . checked( 'live', $s['mode'], false ) . '> <span><strong>' . esc_html__( 'Live', 'payment-matcher-for-fio-bank' ) . '</strong><br>' . esc_html__( 'Paid orders move to “Processing” and the customer gets the usual e-mail.', 'payment-matcher-for-fio-bank' ) . '</span></label>';
				self::field( __( 'Mode', 'payment-matcher-for-fio-bank' ), '', $mode );
				self::field( __( 'Token valid until', 'payment-matcher-for-fio-bank' ), 'pmfio-exp', '<input type="date" id="pmfio-exp" name="token_expires" value="' . esc_attr( $s['token_expires'] ) . '">', __( 'Shown in Fio internet banking next to the token. Filled in as +180 days when you save a new token. You get an e-mail and an admin notice 14 days before it expires.', 'payment-matcher-for-fio-bank' ) );
				?>
			</section>

			<section class="pmfio-panel">
				<h2><?php esc_html_e( 'Matching', 'payment-matcher-for-fio-bank' ); ?></h2>
				<?php
				/* translators: %s: list of payment method IDs */
				self::field( __( 'Payment methods', 'payment-matcher-for-fio-bank' ), 'pmfio-gw', '<input type="text" id="pmfio-gw" name="gateways" class="regular-text" value="' . esc_attr( $s['gateways'] ) . '">', sprintf( __( 'Comma-separated payment method IDs. Active: %s', 'payment-matcher-for-fio-bank' ), '<code>' . esc_html( implode( ', ', $active ) ) . '</code>' ) );
				self::field( __( 'Order statuses', 'payment-matcher-for-fio-bank' ), 'pmfio-st', '<input type="text" id="pmfio-st" name="statuses" class="regular-text" value="' . esc_attr( $s['statuses'] ) . '">', __( 'Only orders in these statuses are marked as paid (without “wc-”).', 'payment-matcher-for-fio-bank' ) );
				/* translators: %s: meta key */
				self::field( __( 'Order number meta key', 'payment-matcher-for-fio-bank' ), 'pmfio-meta', '<input type="text" id="pmfio-meta" name="number_meta" class="regular-text" value="' . esc_attr( $s['number_meta'] ) . '">', sprintf( __( 'For sequential order number plugins (usually %s). If nothing is found, the order ID is tried.', 'payment-matcher-for-fio-bank' ), '<code>_order_number</code>' ) );
				self::field( __( 'Days to look back', 'payment-matcher-for-fio-bank' ), 'pmfio-days', '<input type="number" id="pmfio-days" name="days_back" min="1" max="89" class="small-text" value="' . esc_attr( $s['days_back'] ) . '">' );
				?>
			</section>

			<section class="pmfio-panel">
				<h2><?php esc_html_e( 'Notifications', 'payment-matcher-for-fio-bank' ); ?></h2>
				<?php
				self::field( __( 'Notification e-mail', 'payment-matcher-for-fio-bank' ), 'pmfio-mail', '<input type="email" id="pmfio-mail" name="notify_email" class="regular-text" value="' . esc_attr( $s['notify_email'] ) . '">', __( 'Gets underpayments, overpayments, payments to review and an alert when matching stops working.', 'payment-matcher-for-fio-bank' ) );
				self::field( __( 'Daily summary', 'payment-matcher-for-fio-bank' ), 'pmfio-daily', '<label><input type="checkbox" id="pmfio-daily" name="daily_summary" value="1" ' . checked( 'yes', $s['daily_summary'], false ) . '> ' . esc_html__( 'Every morning at 7:00 send payments from the last 24 hours and orders waiting for payment for more than 3 days', 'payment-matcher-for-fio-bank' ) . '</label>' );
				/* translators: %s: link to e-mail settings */
				self::field( __( 'Payment reminder', 'payment-matcher-for-fio-bank' ), 'pmfio-rem', '<input type="number" id="pmfio-rem" name="reminder_days" min="1" max="30" class="small-text" value="' . esc_attr( $s['reminder_days'] ) . '"> ' . esc_html__( 'days after the order', 'payment-matcher-for-fio-bank' ), sprintf( __( 'Live mode only, and only for orders created after switching to live. Turn on and edit the text in %s.', 'payment-matcher-for-fio-bank' ), '<a href="' . esc_url( $rem_url ) . '">' . esc_html__( 'WooCommerce → Settings → Emails → Payment reminder', 'payment-matcher-for-fio-bank' ) . '</a>' ) );
				?>
			</section>

			<section class="pmfio-panel">
				<h2><?php esc_html_e( 'Personal data', 'payment-matcher-for-fio-bank' ); ?></h2>
				<?php
				/* translators: 1: link to Export personal data, 2: link to Erase personal data, 3: link to Privacy policy guide */
				$help = sprintf( __( 'Payer name, account and message are stored with each payment. 0 = keep forever, otherwise at least 3 months. Customers’ requests are handled in %1$s and %2$s, a suggested text for the privacy policy is in %3$s.', 'payment-matcher-for-fio-bank' ), '<a href="' . esc_url( admin_url( 'export-personal-data.php' ) ) . '">' . esc_html__( 'Export Personal Data', 'payment-matcher-for-fio-bank' ) . '</a>', '<a href="' . esc_url( admin_url( 'erase-personal-data.php' ) ) . '">' . esc_html__( 'Erase Personal Data', 'payment-matcher-for-fio-bank' ) . '</a>', '<a href="' . esc_url( admin_url( 'options-privacy.php?tab=policyguide' ) ) . '">' . esc_html__( 'Privacy Policy Guide', 'payment-matcher-for-fio-bank' ) . '</a>' );
				self::field( __( 'Keep payments for', 'payment-matcher-for-fio-bank' ), 'pmfio-ret', '<input type="number" id="pmfio-ret" name="retention" min="0" max="120" class="small-text" value="' . esc_attr( $s['retention'] ) . '"> ' . esc_html__( 'months', 'payment-matcher-for-fio-bank' ), $help );
				?>
			</section>

			<?php submit_button( __( 'Save settings', 'payment-matcher-for-fio-bank' ) ); ?>
		</form>
		<?php
	}

	private static function render_qr() {
		$s       = PMFIO_Settings::all();
		$iban    = PMFIO_QR_Payment::iban();
		$own     = '' !== (string) $s['qr_iban'];
		/* translators: %s: order number placeholder */
		$preview = '' !== $iban ? PMFIO_QR_Code::svg( PMFIO_QR_Payment::spd( $iban, 1290, get_woocommerce_currency(), '12345', str_replace( '{order_number}', '12345', $s['qr_message'] ?: sprintf( __( 'Order %s', 'payment-matcher-for-fio-bank' ), '{order_number}' ) ) ), 180 ) : '';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pmfio-form">
			<?php wp_nonce_field( 'pmfio_save' ); ?>
			<input type="hidden" name="action" value="pmfio_save">
			<input type="hidden" name="tab" value="qr">
			<div class="pmfio-qr-layout">
				<section class="pmfio-panel">
					<h2><?php esc_html_e( 'QR payment (QR Platba)', 'payment-matcher-for-fio-bank' ); ?></h2>
					<p class="pmfio-lead"><?php esc_html_e( 'A QR code with the account, amount and variable symbol for unpaid bank-transfer orders. Czech and Slovak banking apps fill in the payment automatically. Generated on your server, no external service.', 'payment-matcher-for-fio-bank' ); ?></p>
					<?php
					$where = '<label class="pmfio-check"><input type="checkbox" name="qr_thankyou" value="1" ' . checked( 'yes', $s['qr_thankyou'], false ) . '> ' . esc_html__( 'Thank-you page after the order', 'payment-matcher-for-fio-bank' ) . '</label>'
						. '<label class="pmfio-check"><input type="checkbox" name="qr_email" value="1" ' . checked( 'yes', $s['qr_email'], false ) . '> ' . esc_html__( 'Order e-mails to the customer (including the payment reminder)', 'payment-matcher-for-fio-bank' ) . '</label>';
					self::field( __( 'Show QR code', 'payment-matcher-for-fio-bank' ), '', $where, __( 'Turn off if another plugin or your theme already shows a QR code, so customers do not see two.', 'payment-matcher-for-fio-bank' ) );

					/* translators: %s: IBAN */
					$auto = '' !== $iban && ! $own ? sprintf( __( 'Empty = taken from the bank-transfer account in WooCommerce: %s', 'payment-matcher-for-fio-bank' ), '<code>' . esc_html( trim( chunk_split( $iban, 4, ' ' ) ) ) . '</code>' ) : __( 'Empty = taken from the bank-transfer account in WooCommerce (Settings → Payments → Direct bank transfer).', 'payment-matcher-for-fio-bank' );
					self::field( __( 'IBAN', 'payment-matcher-for-fio-bank' ), 'pmfio-iban', '<input type="text" id="pmfio-iban" name="qr_iban" class="regular-text" value="' . esc_attr( $s['qr_iban'] ) . '" placeholder="CZ65 0800 0000 1920 0014 5399" autocomplete="off">', $auto );
					/* translators: %s: placeholder */
					self::field( __( 'Message for recipient', 'payment-matcher-for-fio-bank' ), 'pmfio-qmsg', '<input type="text" id="pmfio-qmsg" name="qr_message" class="regular-text" maxlength="60" value="' . esc_attr( $s['qr_message'] ) . '" placeholder="' . esc_attr( sprintf( __( 'Order %s', 'payment-matcher-for-fio-bank' ), '{order_number}' ) ) . '">', sprintf( __( 'Up to 60 characters, without diacritics in the bank. You can use %s.', 'payment-matcher-for-fio-bank' ), '<code>{order_number}</code>' ) );
					?>
				</section>
				<aside class="pmfio-panel pmfio-preview">
					<h2><?php esc_html_e( 'Preview', 'payment-matcher-for-fio-bank' ); ?></h2>
					<?php if ( $preview ) : ?>
						<div class="pmfio-preview__code"><?php echo wp_kses( $preview, self::allowed_html() ); ?></div>
						<p class="pmfio-preview__amount"><?php echo wp_kses_post( wc_price( 1290 ) ); ?> · VS 12345</p>
						<p class="pmfio-help"><?php esc_html_e( 'Sample order. Try scanning it in your banking app, but do not confirm the payment.', 'payment-matcher-for-fio-bank' ); ?></p>
					<?php else : ?>
						<p class="pmfio-warn"><?php esc_html_e( 'No valid account. Fill in the IBAN or the bank-transfer account in WooCommerce.', 'payment-matcher-for-fio-bank' ); ?></p>
					<?php endif; ?>
				</aside>
			</div>
			<?php submit_button( __( 'Save QR settings', 'payment-matcher-for-fio-bank' ) ); ?>
		</form>
		<?php
	}
}
