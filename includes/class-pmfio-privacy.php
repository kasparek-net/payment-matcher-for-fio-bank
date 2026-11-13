<?php
/**
 * Personal data: privacy policy text, exporter, eraser and retention.
 *
 * The plugin stores the payer's name, account number and message for recipient
 * of every incoming payment. Amounts and dates stay for bookkeeping when erasing.
 *
 * @package PaymentMatcherForFioBank
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PMFIO_Privacy {

	const GROUP = 'pmfio-payments';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'policy_content' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	public static function policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text  = '<p class="privacy-policy-tutorial">' . esc_html__( 'Suggested text for your privacy policy. Adjust it to how you actually use the data.', 'payment-matcher-for-fio-bank' ) . '</p>';
		$text .= '<p><strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'payment-matcher-for-fio-bank' ) . ' </strong>';
		$text .= esc_html__( 'When you pay by bank transfer, we receive from our bank (Fio banka, a.s.) the details of your payment: the amount, date, variable symbol, the sender’s name and account number and the message for recipient. We use them only to match the payment to your order and to deal with underpayments, overpayments and refunds.', 'payment-matcher-for-fio-bank' ) . ' ';
		$retention = (int) PMFIO_Settings::get( 'retention' );
		if ( $retention > 0 ) {
			/* translators: %d: number of months */
			$text .= esc_html( sprintf( _n( 'We keep these details for %d month and then delete them automatically.', 'We keep these details for %d months and then delete them automatically.', $retention, 'payment-matcher-for-fio-bank' ), $retention ) );
		}
		$text .= '</p>';
		wp_add_privacy_policy_content( __( 'Payment Matcher for Fio Bank', 'payment-matcher-for-fio-bank' ), wp_kses_post( $text ) );
	}

	public static function register_exporter( $exporters ) {
		$exporters['payment-matcher-for-fio-bank'] = array(
			'exporter_friendly_name' => __( 'Bank transfers (Fio)', 'payment-matcher-for-fio-bank' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	public static function register_eraser( $erasers ) {
		$erasers['payment-matcher-for-fio-bank'] = array(
			'eraser_friendly_name' => __( 'Bank transfers (Fio)', 'payment-matcher-for-fio-bank' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/** Order IDs belonging to an e-mail address (billing e-mail or customer account). */
	public static function order_ids_for( $email ) {
		if ( ! is_email( $email ) ) {
			return array();
		}
		$ids  = wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'type' => 'shop_order', 'billing_email' => $email ) );
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$ids = array_merge( $ids, wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'type' => 'shop_order', 'customer_id' => $user->ID ) ) );
		}
		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	public static function export( $email, $page = 1 ) {
		$items = array();
		foreach ( PMFIO_Store::for_orders( self::order_ids_for( $email ) ) as $r ) {
			$order   = wc_get_order( (int) $r->order_id );
			$items[] = array(
				'group_id'    => self::GROUP,
				'group_label' => __( 'Bank transfers (Fio)', 'payment-matcher-for-fio-bank' ),
				'item_id'     => 'pmfio-' . $r->movement_id,
				'data'        => array(
					array( 'name' => __( 'Date', 'payment-matcher-for-fio-bank' ), 'value' => (string) $r->moved_on ),
					array( 'name' => __( 'Amount', 'payment-matcher-for-fio-bank' ), 'value' => $r->amount . ' ' . $r->currency ),
					array( 'name' => __( 'Order', 'payment-matcher-for-fio-bank' ), 'value' => $order ? $order->get_order_number() : '' ),
					array( 'name' => __( 'Sender', 'payment-matcher-for-fio-bank' ), 'value' => (string) $r->counter_name ),
					array( 'name' => __( 'Account', 'payment-matcher-for-fio-bank' ), 'value' => (string) $r->counter_account ),
					array( 'name' => __( 'Message', 'payment-matcher-for-fio-bank' ), 'value' => (string) $r->message ),
				),
			);
		}
		return array(
			'data' => $items,
			'done' => true,
		);
	}

	public static function erase( $email, $page = 1 ) {
		$removed  = false;
		$retained = false;
		foreach ( PMFIO_Store::for_orders( self::order_ids_for( $email ) ) as $r ) {
			if ( '' === $r->counter_name && '' === $r->counter_account && '' === $r->message ) {
				continue;
			}
			$removed = PMFIO_Store::anonymize( $r->movement_id ) || $removed;
			$retained = true;
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $retained ? array( __( 'Payer name, account and message were removed from Fio payments. Amounts and dates are kept for bookkeeping.', 'payment-matcher-for-fio-bank' ) ) : array(),
			'done'           => true,
		);
	}

	/**
	 * Pure: the first day that is kept for a retention of $months (0 = keep forever → '').
	 * Never shorter than 3 months, so movements still downloaded from Fio are not re-processed.
	 */
	public static function cutoff( $months, $today ) {
		$months = (int) $months;
		if ( $months <= 0 ) {
			return '';
		}
		$months = max( 3, $months );
		return ( new DateTimeImmutable( $today ) )->modify( '-' . $months . ' months' )->format( 'Y-m-d' );
	}

	public static function cleanup() {
		$cutoff = self::cutoff( PMFIO_Settings::get( 'retention' ), wp_date( 'Y-m-d' ) );
		return '' === $cutoff ? 0 : PMFIO_Store::delete_before( $cutoff );
	}
}
