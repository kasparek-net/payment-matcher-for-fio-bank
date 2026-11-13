<?php
/**
 * Live payment status on the thank-you page: the page asks every few seconds,
 * the server checks Fio at most once a minute and answers whether the order is paid.
 *
 * @package PaymentMatcherForFioBank
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PMFIO_Status {

	const THROTTLE = 'pmfio_status_check';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'thankyou' ), 4 );
	}

	public static function enabled_for( $order ) {
		return PMFIO_Settings::is_live()
			&& '' !== PMFIO_Settings::token()
			&& $order instanceof WC_Order
			&& ! $order->is_paid()
			&& in_array( $order->get_payment_method(), PMFIO_Settings::list_of( 'gateways' ), true )
			&& in_array( $order->get_status(), PMFIO_Settings::list_of( 'statuses' ), true );
	}

	public static function thankyou( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! self::enabled_for( $order ) ) {
			return;
		}
		wp_enqueue_script( 'pmfio-status', PMFIO_PLUGIN_URL . 'assets/status.js', array(), PMFIO_VERSION, true );
		wp_localize_script(
			'pmfio-status',
			'pmfioStatus',
			array(
				'url'      => esc_url_raw( rest_url( 'pmfio/v1/status' ) ),
				'order'    => $order->get_id(),
				'key'      => $order->get_order_key(),
				'interval' => (int) apply_filters( 'pmfio_status_poll_interval', 15000 ),
				'maxTime'  => 20 * MINUTE_IN_SECONDS * 1000,
				'paid'     => __( 'Payment received, thank you! We are processing your order.', 'payment-matcher-for-fio-bank' ),
			)
		);
		echo '<p class="pmfio-status" role="status" aria-live="polite" style="display:flex;gap:10px;align-items:center;margin:0 0 24px;padding:12px 16px;border:1px solid rgba(128,128,128,.3);border-radius:10px">';
		echo '<span class="pmfio-status__dot" aria-hidden="true" style="flex:none;width:10px;height:10px;border-radius:50%;background:#dba617"></span>';
		echo '<span class="pmfio-status__text">' . esc_html__( 'Waiting for your bank transfer. This page updates by itself when the payment arrives.', 'payment-matcher-for-fio-bank' ) . '</span>';
		echo '</p>';
	}

	public static function routes() {
		register_rest_route(
			'pmfio/v1',
			'/status',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'status' ),
				'permission_callback' => '__return_true', // Public, protected by the order key below.
				'args'                => array(
					'order' => array(
						'type'     => 'integer',
						'required' => true,
					),
					'key'   => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	public static function status( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['order'] ) );
		if ( ! $order || ! hash_equals( (string) $order->get_order_key(), (string) $request['key'] ) ) {
			return new WP_Error( 'pmfio_not_found', 'Not found', array( 'status' => 404 ) );
		}
		if ( ! $order->is_paid() && self::enabled_for( $order ) && ! get_transient( self::THROTTLE ) ) {
			set_transient( self::THROTTLE, 1, (int) apply_filters( 'pmfio_status_check_throttle', MINUTE_IN_SECONDS ) );
			PMFIO_Runner::run();
			$order = wc_get_order( $order->get_id() );
		}
		return rest_ensure_response( array( 'paid' => $order->is_paid() ) );
	}
}
