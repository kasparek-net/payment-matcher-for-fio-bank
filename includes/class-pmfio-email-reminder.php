<?php
/**
 * Customer payment reminder e-mail.
 *
 * @package PaymentMatcherForFioBank
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Customer payment reminder. Uses the standard order details block, so bank account
 * details (bacs) and anything hooked into order e-mails (e.g. QR code) are included.
 */
class PMFIO_Email_Reminder extends WC_Email {

	public function __construct() {
		$this->id             = 'pmfio_payment_reminder';
		$this->customer_email = true;
		$this->title          = __( 'Payment reminder', 'payment-matcher-for-fio-bank' );
		$this->description    = __( 'Sent to the customer when no payment arrives for a bank-transfer order within the set number of days (Payment Matcher for Fio Bank). The number of days is set in WooCommerce → Fio payments.', 'payment-matcher-for-fio-bank' );
		$this->placeholders   = array(
			'{order_number}' => '',
			'{order_date}'   => '',
		);
		parent::__construct();
		$this->enabled = $this->get_option( 'enabled', 'no' );
	}

	public function get_default_subject() {
		return __( 'Payment reminder for order {order_number}', 'payment-matcher-for-fio-bank' );
	}

	public function get_default_heading() {
		return __( 'Waiting for your payment', 'payment-matcher-for-fio-bank' );
	}

	public function get_default_additional_content() {
		return '';
	}

	public function get_default_intro() {
		return __( 'Hello, we have not received the payment for your order {order_number} yet. If you have already paid, it may simply not have arrived yet – in that case please ignore this e-mail. Otherwise you will find the payment details below.', 'payment-matcher-for-fio-bank' );
	}

	public function trigger( $order_id, $order = false ) {
		$this->setup_locale();
		// Gateways hook their instructions (bank account for bacs) into order e-mails only once loaded;
		// in Action Scheduler / admin-post requests they may not be yet.
		WC()->payment_gateways();
		if ( $order_id && ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( $order instanceof WC_Order ) {
			$this->object                         = $order;
			$this->recipient                      = $order->get_billing_email();
			$this->placeholders['{order_number}'] = $order->get_order_number();
			$this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );
		}
		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
		$this->restore_locale();
	}

	public function get_content_html() {
		ob_start();
		do_action( 'woocommerce_email_header', $this->get_heading(), $this ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core e-mail hook.
		echo wp_kses_post( wpautop( wptexturize( $this->format_string( $this->get_option( 'intro', $this->get_default_intro() ) ) ) ) );
		do_action( 'woocommerce_email_order_details', $this->object, false, false, $this ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core e-mail hook.
		$additional = $this->get_additional_content();
		if ( $additional ) {
			echo wp_kses_post( wpautop( wptexturize( $additional ) ) );
		}
		do_action( 'woocommerce_email_footer', $this ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core e-mail hook.
		return ob_get_clean();
	}

	public function get_content_plain() {
		$text  = $this->format_string( $this->get_option( 'intro', $this->get_default_intro() ) ) . "\n\n";
		$text .= __( 'Order:', 'payment-matcher-for-fio-bank' ) . ' ' . $this->object->get_order_number() . "\n";
		$text .= __( 'Amount:', 'payment-matcher-for-fio-bank' ) . ' ' . wp_strip_all_tags( wc_price( $this->object->get_total() ) ) . "\n";
		return $text;
	}

	public function init_form_fields() {
		parent::init_form_fields();
		$fields = array();
		foreach ( $this->form_fields as $key => $field ) {
			$fields[ $key ] = $field;
			if ( 'heading' === $key ) {
				$fields['intro'] = array(
					'title'       => __( 'Introduction', 'payment-matcher-for-fio-bank' ),
					'type'        => 'textarea',
					'css'         => 'width:400px;height:90px',
					'default'     => $this->get_default_intro(),
					'description' => __( 'The order and payment details are always shown below this text. You can use {order_number}.', 'payment-matcher-for-fio-bank' ),
				);
			}
		}
		$fields['enabled']['default'] = 'no';
		$this->form_fields            = $fields;
	}
}
