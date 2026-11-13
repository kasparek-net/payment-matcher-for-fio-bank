<?php
/**
 * Czech "QR Platba" (Short Payment Descriptor) on the thank-you page and in order e-mails.
 *
 * @package PaymentMatcherForFioBank
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PMFIO_QR_Payment {

	const QUERY = 'pmfio-qr';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'serve_png' ), 20 );
		if ( 'yes' === PMFIO_Settings::get( 'qr_thankyou' ) ) {
			add_action( 'woocommerce_thankyou', array( __CLASS__, 'thankyou' ), 5 );
		}
		if ( 'yes' === PMFIO_Settings::get( 'qr_email' ) ) {
			add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'email' ), 15, 4 );
		}
	}

	/**
	 * Czech account "prefix-number/bank" → IBAN. Returns '' when the input is not a valid Czech account.
	 */
	public static function czech_iban( $account, $bank = '' ) {
		$account = preg_replace( '/\s+/', '', (string) $account );
		if ( false !== strpos( $account, '/' ) ) {
			list( $account, $bank ) = explode( '/', $account, 2 );
		}
		$bank = preg_replace( '/\D/', '', (string) $bank );
		if ( false !== strpos( $account, '-' ) ) {
			list( $prefix, $number ) = explode( '-', $account, 2 );
		} else {
			$prefix = '';
			$number = $account;
		}
		if ( ! preg_match( '/^\d{0,6}$/', $prefix ) || ! preg_match( '/^\d{2,10}$/', $number ) || ! preg_match( '/^\d{4}$/', $bank ) ) {
			return '';
		}
		$bban = $bank . str_pad( $prefix, 6, '0', STR_PAD_LEFT ) . str_pad( $number, 10, '0', STR_PAD_LEFT );
		$check = 98 - self::mod97( $bban . '123500' );
		return 'CZ' . str_pad( (string) $check, 2, '0', STR_PAD_LEFT ) . $bban;
	}

	public static function mod97( $digits ) {
		$rem = 0;
		foreach ( str_split( $digits ) as $d ) {
			$rem = ( $rem * 10 + (int) $d ) % 97;
		}
		return $rem;
	}

	public static function valid_iban( $iban ) {
		$iban = strtoupper( preg_replace( '/\s+/', '', (string) $iban ) );
		if ( ! preg_match( '/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $iban ) ) {
			return false;
		}
		$moved  = substr( $iban, 4 ) . substr( $iban, 0, 4 );
		$digits = '';
		foreach ( str_split( $moved ) as $c ) {
			$digits .= ctype_alpha( $c ) ? (string) ( ord( $c ) - 55 ) : $c;
		}
		return 1 === self::mod97( $digits );
	}

	/**
	 * IBAN used for QR codes: explicit setting first, then the first WooCommerce bank-transfer account.
	 */
	public static function iban() {
		$own = strtoupper( preg_replace( '/\s+/', '', (string) PMFIO_Settings::get( 'qr_iban' ) ) );
		if ( '' !== $own ) {
			return self::valid_iban( $own ) ? $own : '';
		}
		$accounts = get_option( 'woocommerce_bacs_accounts', array() );
		$first    = is_array( $accounts ) && $accounts ? reset( $accounts ) : array();
		if ( ! empty( $first['iban'] ) && self::valid_iban( $first['iban'] ) ) {
			return strtoupper( preg_replace( '/\s+/', '', $first['iban'] ) );
		}
		if ( ! empty( $first['account_number'] ) ) {
			return self::czech_iban( $first['account_number'], $first['sort_code'] ?? '' );
		}
		return '';
	}

	/**
	 * SPD string. Fields per the Czech Banking Association standard (SPD 1.0).
	 */
	public static function spd( $iban, $amount, $currency, $vs, $message ) {
		$clean = function ( $s, $max ) {
			$s = function_exists( 'remove_accents' ) ? remove_accents( (string) $s ) : (string) $s;
			$s = strtoupper( str_replace( '*', ' ', $s ) );
			return trim( substr( preg_replace( '/[^\x20-\x7E]/', '', $s ), 0, $max ) );
		};
		$parts = array( 'SPD', '1.0', 'ACC:' . $iban, 'AM:' . number_format( (float) $amount, 2, '.', '' ), 'CC:' . strtoupper( $currency ) );
		$vs    = trim( (string) $vs );
		if ( '' !== $vs && ctype_digit( $vs ) && strlen( $vs ) <= 10 ) {
			$parts[] = 'X-VS:' . $vs;
		}
		$msg = $clean( $message, 60 );
		if ( '' !== $msg ) {
			$parts[] = 'MSG:' . $msg;
		}
		return implode( '*', $parts );
	}

	public static function message_for( WC_Order $order ) {
		$template = (string) PMFIO_Settings::get( 'qr_message' );
		if ( '' === trim( $template ) ) {
			/* translators: %s: order number */
			$template = sprintf( __( 'Order %s', 'payment-matcher-for-fio-bank' ), '{order_number}' );
		}
		return str_replace( '{order_number}', $order->get_order_number(), $template );
	}

	public static function spd_for( WC_Order $order ) {
		$iban = self::iban();
		if ( '' === $iban ) {
			return '';
		}
		return self::spd( $iban, $order->get_total(), $order->get_currency(), $order->get_order_number(), self::message_for( $order ) );
	}

	public static function applies( $order ) {
		return $order instanceof WC_Order
			&& ! $order->is_paid()
			&& in_array( $order->get_payment_method(), PMFIO_Settings::list_of( 'gateways' ), true )
			&& in_array( $order->get_status(), array( 'on-hold', 'pending' ), true )
			&& in_array( strtoupper( $order->get_currency() ), array( 'CZK', 'EUR' ), true );
	}

	public static function png_url( WC_Order $order ) {
		return add_query_arg( array( self::QUERY => $order->get_id(), 'key' => $order->get_order_key() ), home_url( '/' ) );
	}

	public static function thankyou( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! self::applies( $order ) ) {
			return;
		}
		$spd = self::spd_for( $order );
		$svg = '' !== $spd ? PMFIO_QR_Code::svg( $spd, 200 ) : '';
		if ( '' === $svg ) {
			return;
		}
		echo '<section class="pmfio-qr" style="display:flex;flex-wrap:wrap;gap:20px;align-items:center;margin:0 0 32px;padding:20px;border:1px solid rgba(128,128,128,.3);border-radius:12px">';
		echo '<div style="flex:none;background:#fff;padding:6px;border-radius:8px;line-height:0">' . wp_kses( $svg, PMFIO_QR_Code::allowed_svg() ) . '</div>';
		echo '<div style="flex:1 1 220px"><h2 style="margin:0 0 8px;font-size:1.25em">' . esc_html__( 'Pay by QR code', 'payment-matcher-for-fio-bank' ) . '</h2>';
		echo '<p style="margin:0 0 8px">' . esc_html__( 'Scan the code in your banking app, the amount and variable symbol will fill in automatically.', 'payment-matcher-for-fio-bank' ) . '</p>';
		/* translators: 1: amount, 2: variable symbol */
		echo '<p style="margin:0"><strong>' . wp_kses_post( sprintf( __( '%1$s · variable symbol %2$s', 'payment-matcher-for-fio-bank' ), wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ), esc_html( $order->get_order_number() ) ) ) . '</strong></p></div>';
		echo '</section>';
	}

	public static function email( $order, $sent_to_admin = false, $plain_text = false, $email = null ) {
		if ( $sent_to_admin || ! self::applies( $order ) || '' === self::spd_for( $order ) ) {
			return;
		}
		if ( $plain_text ) {
			return;
		}
		echo '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 24px"><tr>';
		echo '<td style="padding:0 16px 0 0;vertical-align:middle"><img src="' . esc_url( self::png_url( $order ) ) . '" width="160" height="160" alt="' . esc_attr__( 'QR payment', 'payment-matcher-for-fio-bank' ) . '" style="display:block;border:0"></td>';
		echo '<td style="vertical-align:middle"><strong>' . esc_html__( 'Pay by QR code', 'payment-matcher-for-fio-bank' ) . '</strong><br>' . esc_html__( 'Scan the code in your banking app, the amount and variable symbol will fill in automatically.', 'payment-matcher-for-fio-bank' ) . '</td>';
		echo '</tr></table>';
	}

	/**
	 * Serves the QR PNG for e-mails. Protected by the order key; only for unpaid orders.
	 */
	public static function serve_png() {
		if ( ! isset( $_GET[ self::QUERY ], $_GET['key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- public image, protected by order key.
			return;
		}
		$order = wc_get_order( absint( $_GET[ self::QUERY ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$key   = sanitize_text_field( wp_unslash( $_GET['key'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $order || ! hash_equals( (string) $order->get_order_key(), $key ) || ! self::applies( $order ) ) {
			status_header( 404 );
			exit;
		}
		$png = PMFIO_QR_Code::png( self::spd_for( $order ), 6 );
		if ( '' === $png ) {
			status_header( 404 );
			exit;
		}
		nocache_headers();
		header( 'Content-Type: image/png' );
		header( 'Content-Length: ' . strlen( $png ) );
		echo $png; // phpcs:ignore WordPress.Security.EscapeOutput -- binary image.
		exit;
	}
}
