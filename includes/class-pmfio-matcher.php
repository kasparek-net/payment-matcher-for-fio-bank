<?php
/**
 * Matching of Fio movements to orders.
 *
 * @package PaymentMatcherForFioBank
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PMFIO_Matcher {

	const PAID_RESULTS = array( 'matched', 'overpaid', 'partial' );

	/**
	 * Normalize one raw Fio transaction (columnN => {value,name,id}|null).
	 */
	public static function normalize( array $t ) {
		$v = function ( $n ) use ( $t ) {
			$col = $t[ 'column' . $n ] ?? null;
			if ( ! is_array( $col ) || ! array_key_exists( 'value', $col ) || null === $col['value'] ) {
				return '';
			}
			return trim( (string) $col['value'] );
		};

		$account = $v( 2 );
		if ( '' !== $account && '' !== $v( 3 ) ) {
			$account .= '/' . $v( 3 );
		}

		return array(
			'id'       => $v( 22 ),
			'date'     => self::parse_date( $t['column0']['value'] ?? null ),
			'amount'   => (float) str_replace( ',', '.', $v( 1 ) ),
			'currency' => strtoupper( $v( 14 ) ),
			'vs'       => self::clean_vs( $v( 5 ) ),
			'account'  => $account,
			'name'     => $v( 10 ),
			'message'  => $v( 16 ),
			'user_id'  => $v( 7 ),
		);
	}

	public static function parse_date( $value ) {
		if ( is_int( $value ) || is_float( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
			$tz = new DateTimeZone( 'Europe/Prague' );
			return ( new DateTimeImmutable( '@' . (int) floor( $value / 1000 ) ) )->setTimezone( $tz )->format( 'Y-m-d' );
		}
		if ( is_string( $value ) && preg_match( '/^(\d{4}-\d{2}-\d{2})/', $value, $m ) ) {
			return $m[1];
		}
		return null;
	}

	public static function clean_vs( $vs ) {
		$vs = preg_replace( '/\D/', '', (string) $vs );
		$vs = ltrim( $vs, '0' );
		return $vs;
	}

	/**
	 * VS candidates: the VS field first, then numbers found in message / user identification.
	 */
	public static function vs_candidates( array $m ) {
		$out = array();
		if ( '' !== $m['vs'] ) {
			$out[] = $m['vs'];
		}
		foreach ( array( $m['message'], $m['user_id'] ) as $text ) {
			if ( preg_match_all( '/(?<!\d)\d{3,10}(?!\d)/', $text, $found ) ) {
				foreach ( $found[0] as $n ) {
					$n = self::clean_vs( $n );
					if ( '' !== $n ) {
						$out[] = $n;
					}
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Pure decision. $order_info: null or [gateway, status, currency, total, is_paid].
	 */
	public static function decide( $order_info, $amount, $currency, $paid_before, array $gateways, array $statuses ) {
		if ( null === $order_info ) {
			return 'no_order';
		}
		if ( ! in_array( $order_info['gateway'], $gateways, true ) ) {
			return 'review';
		}
		if ( ! in_array( $order_info['status'], $statuses, true ) || $order_info['is_paid'] ) {
			return 'review';
		}
		if ( '' !== $currency && strtoupper( $order_info['currency'] ) !== $currency ) {
			return 'review';
		}
		$sum   = round( $paid_before + $amount, 2 );
		$total = round( (float) $order_info['total'], 2 );
		if ( $sum < $total - 0.009 ) {
			return 'partial';
		}
		if ( $sum > $total + 0.009 ) {
			return 'overpaid';
		}
		return 'matched';
	}

	public static function find_order( array $candidates ) {
		$meta_key = (string) apply_filters( 'pmfio_order_number_meta_key', PMFIO_Settings::get( 'number_meta' ) );
		foreach ( $candidates as $vs ) {
			$order = apply_filters( 'pmfio_find_order', null, $vs );
			if ( $order instanceof WC_Order ) {
				return array( $order, $vs );
			}
			if ( '' !== $meta_key ) {
				$ids = wc_get_orders(
					array(
						'limit'      => 2,
						'return'     => 'ids',
						'type'       => 'shop_order',
						'status'     => array_keys( wc_get_order_statuses() ),
						'meta_query' => array( array( 'key' => $meta_key, 'value' => $vs ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one exact order-number lookup, limit 2.
					)
				);
				if ( 1 === count( $ids ) ) {
					return array( wc_get_order( $ids[0] ), $vs );
				}
			}
			$order = wc_get_order( (int) $vs );
			if ( $order instanceof WC_Order && 'shop_order' === $order->get_type() && (string) $order->get_order_number() === $vs ) {
				return array( $order, $vs );
			}
		}
		return array( null, '' );
	}

	public static function order_info( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return null;
		}
		return array(
			'gateway'  => $order->get_payment_method(),
			'status'   => $order->get_status(),
			'currency' => $order->get_currency(),
			'total'    => (float) $order->get_total(),
			'is_paid'  => $order->is_paid(),
		);
	}


	/**
	 * Process one raw transaction. Returns the stored result or '' when skipped.
	 */
	public static function process( array $raw, $live ) {
		$m = self::normalize( $raw );
		if ( '' === $m['id'] || 0.0 === $m['amount'] ) {
			return '';
		}
		if ( PMFIO_Store::exists( $m['id'], $live ) ) {
			return '';
		}
		if ( $m['amount'] < 0 ) {
			return self::process_outgoing( $m, $live );
		}

		list( $order, $vs ) = self::find_order( self::vs_candidates( $m ) );
		if ( '' !== $vs ) {
			$m['vs'] = $vs;
		}
		$order_id = $order ? $order->get_id() : 0;

		$paid_results = $live ? self::PAID_RESULTS : array_merge( self::PAID_RESULTS, array( 'obs_matched', 'obs_overpaid', 'obs_partial' ) );
		$paid_before  = $order_id ? PMFIO_Store::paid_sum( $order_id, $paid_results ) : 0.0;

		$result    = self::decide_for( $order, $m, $paid_before );
		$suggested = 'no_order' === $result ? self::suggest_for( $m ) : 0;

		if ( ! $live ) {
			PMFIO_Store::insert( $m, $order_id, 'obs_' . $result, $suggested );
			return 'obs_' . $result;
		}

		PMFIO_Store::insert( $m, $order_id, $result, $suggested );
		self::apply( $order, $m, $result, $paid_before );
		if ( $order && in_array( $result, self::PAID_RESULTS, true ) ) {
			/**
			 * Fires when an incoming Fio payment is assigned to an order (matched, overpaid or partial).
			 *
			 * @param WC_Order $order  The order.
			 * @param string   $result matched|overpaid|partial.
			 * @param array    $m      Normalized Fio movement.
			 */
			do_action( 'pmfio_payment_matched', $order, $result, $m );
		}
		return $result;
	}

	/**
	 * Pure: is an outgoing payment a refund of the order?
	 * Only when money for the order actually arrived and the refunds do not exceed it.
	 */
	public static function is_refund( $paid_sum, $refunded_before, $amount ) {
		$amount = abs( (float) $amount );
		return $paid_sum > 0 && $amount > 0 && round( $refunded_before + $amount, 2 ) <= round( $paid_sum, 2 ) + 0.009;
	}

	/**
	 * Outgoing payment: a refund when the VS points to an order that was paid through Fio,
	 * otherwise stored without personal data so it is evaluated only once.
	 */
	private static function process_outgoing( array $m, $live ) {
		list( $order, $vs ) = self::find_order( self::vs_candidates( $m ) );
		if ( $order ) {
			$paid     = PMFIO_Store::paid_sum( $order->get_id(), $live ? self::PAID_RESULTS : array_merge( self::PAID_RESULTS, array( 'obs_matched', 'obs_overpaid', 'obs_partial', 'seen' ) ) );
			$refunded = abs( PMFIO_Store::paid_sum( $order->get_id(), array( 'refund', 'obs_refund' ) ) );
			if ( self::is_refund( $paid, $refunded, $m['amount'] ) ) {
				$m['vs'] = $vs;
				$result  = $live ? 'refund' : 'obs_refund';
				PMFIO_Store::insert( $m, $order->get_id(), $result );
				if ( $live ) {
					$amount = wc_price( abs( $m['amount'] ), array( 'currency' => $m['currency'] ?: $order->get_currency() ) );
					$to     = trim( $m['name'] . ( '' !== $m['account'] ? ' (' . $m['account'] . ')' : '' ) );
					/* translators: 1: amount, 2: Fio movement ID, 3: date, 4: recipient */
					$order->add_order_note( sprintf( __( 'Refunded by bank transfer: %1$s (Fio movement %2$s from %3$s, to %4$s).', 'payment-matcher-for-fio-bank' ), $amount, $m['id'], $m['date'], '' !== $to ? $to : __( 'unknown recipient', 'payment-matcher-for-fio-bank' ) ) );
					/**
					 * Fires when an outgoing Fio payment is matched as a refund of an order.
					 *
					 * @param WC_Order $order  The order.
					 * @param float    $amount Refunded amount (positive).
					 * @param array    $m      Normalized Fio movement.
					 */
					do_action( 'pmfio_refund_matched', $order, abs( $m['amount'] ), $m );
				}
				return $result;
			}
		}
		$m['name']    = '';
		$m['account'] = '';
		$m['message'] = '';
		PMFIO_Store::insert( $m, 0, 'outgoing' );
		return '';
	}

	public static function decide_for( $order, array $m, $paid_before ) {
		return self::decide(
			self::order_info( $order ),
			$m['amount'],
			$m['currency'],
			$paid_before,
			PMFIO_Settings::list_of( 'gateways' ),
			PMFIO_Settings::list_of( 'statuses' )
		);
	}

	/**
	 * Manual assignment from the admin table. Always applied for real.
	 *
	 * @return array{0:bool,1:string}
	 */
	public static function assign( $movement_id, $order_number, $user_label ) {
		$row = PMFIO_Store::get( $movement_id );
		if ( ! $row ) {
			return array( false, __( 'Payment not found.', 'payment-matcher-for-fio-bank' ) );
		}
		if ( in_array( $row->result, self::PAID_RESULTS, true ) ) {
			return array( false, __( 'This payment is already assigned to an order.', 'payment-matcher-for-fio-bank' ) );
		}
		list( $order ) = self::find_order( array( self::clean_vs( $order_number ) ) );
		if ( ! $order ) {
			/* translators: %s: order number */
			return array( false, sprintf( __( 'Order %s does not exist.', 'payment-matcher-for-fio-bank' ), $order_number ) );
		}
		$m           = PMFIO_Store::to_movement( $row );
		$paid_before = PMFIO_Store::paid_sum( $order->get_id(), self::PAID_RESULTS );
		$result      = self::decide_for( $order, $m, $paid_before );
		if ( 'review' === $result ) {
			if ( self::is_restorable( $order ) ) {
				/* translators: %s: order number */
				return array( false, sprintf( __( 'Order %s is cancelled. Use “Restore and mark paid”.', 'payment-matcher-for-fio-bank' ), $order->get_order_number() ) );
			}
			/* translators: 1: order number, 2: order status, 3: payment method, 4: currency */
			return array( false, sprintf( __( 'Order %1$s cannot be marked as paid (status “%2$s”, payment method %3$s, currency %4$s).', 'payment-matcher-for-fio-bank' ), $order->get_order_number(), wc_get_order_status_name( $order->get_status() ), $order->get_payment_method_title(), $order->get_currency() ) );
		}
		PMFIO_Store::insert( $m, $order->get_id(), $result );
		/* translators: %s: user name */
		self::apply( $order, $m, $result, $paid_before, ' ' . sprintf( __( '(assigned manually by %s)', 'payment-matcher-for-fio-bank' ), $user_label ) );
		/* translators: 1: order number, 2: result label */
		return array( true, sprintf( __( 'Payment assigned to order %1$s: %2$s.', 'payment-matcher-for-fio-bank' ), $order->get_order_number(), self::result_label( $result ) ) );
	}

	public static function is_restorable( $order ) {
		return $order instanceof WC_Order && in_array( $order->get_status(), array( 'cancelled', 'failed' ), true ) && ! $order->is_paid();
	}

	/**
	 * Payment arrived for a cancelled order: put it back on hold and pay it.
	 *
	 * @return array{0:bool,1:string}
	 */
	public static function restore( $movement_id, $user_label ) {
		$row   = PMFIO_Store::get( $movement_id );
		$order = $row ? wc_get_order( (int) ( $row->order_id ?: $row->suggested_order_id ) ) : null;
		if ( ! self::is_restorable( $order ) ) {
			return array( false, __( 'This order cannot be restored.', 'payment-matcher-for-fio-bank' ) );
		}
		add_filter( 'woocommerce_email_enabled_customer_on_hold_order', '__return_false', 999 );
		/* translators: %s: user name */
		$order->update_status( 'on-hold', sprintf( __( 'Restored, the payment arrived after the order was cancelled (%s).', 'payment-matcher-for-fio-bank' ), $user_label ) );
		remove_filter( 'woocommerce_email_enabled_customer_on_hold_order', '__return_false', 999 );
		return self::assign( $movement_id, $order->get_order_number(), $user_label );
	}

	public static function result_label( $result ) {
		$labels = array(
			'matched'  => __( 'paid', 'payment-matcher-for-fio-bank' ),
			'overpaid' => __( 'paid with overpayment', 'payment-matcher-for-fio-bank' ),
			'partial'  => __( 'partial payment', 'payment-matcher-for-fio-bank' ),
		);
		return $labels[ $result ] ?? $result;
	}

	/** Lowercase ASCII for comparing Czech names. */
	public static function fold( $s ) {
		$map = array(
			'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z',
			'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ľ' => 'l', 'ĺ' => 'l', 'ô' => 'o', 'ŕ' => 'r',
		);
		return strtr( mb_strtolower( (string) $s, 'UTF-8' ), $map );
	}

	/**
	 * Pure: pick the one candidate the payment most likely belongs to, or 0.
	 * $candidates: list of [id, total, name] (name = billing first + last name).
	 */
	public static function suggest( $amount, $counter_name, array $candidates ) {
		$same = array_values( array_filter( $candidates, function ( $c ) use ( $amount ) {
			return abs( (float) $c['total'] - (float) $amount ) < 0.01;
		} ) );
		if ( 1 === count( $same ) ) {
			return (int) $same[0]['id'];
		}
		if ( count( $same ) < 2 ) {
			return 0;
		}
		$words = array_filter( preg_split( '/[\s,.]+/', self::fold( $counter_name ) ), function ( $w ) {
			return strlen( $w ) >= 3;
		} );
		if ( ! $words ) {
			return 0;
		}
		$hits = array();
		foreach ( $same as $c ) {
			$name_words = preg_split( '/[\s,.]+/', self::fold( $c['name'] ) );
			if ( array_intersect( $words, $name_words ) ) {
				$hits[] = (int) $c['id'];
			}
		}
		return 1 === count( $hits ) ? $hits[0] : 0;
	}

	public static function suggest_for( array $m ) {
		$gateways = PMFIO_Settings::list_of( 'gateways' );
		$statuses = PMFIO_Settings::list_of( 'statuses' );
		if ( ! $gateways || ! $statuses ) {
			return 0;
		}
		$orders = wc_get_orders(
			array(
				'limit'          => 50,
				'type'           => 'shop_order',
				'status'         => array_map( function ( $s ) { return 'wc-' . $s; }, $statuses ),
				'payment_method' => $gateways,
				'date_created'   => '>' . ( time() - 30 * DAY_IN_SECONDS ),
			)
		);
		$candidates = array();
		foreach ( $orders as $o ) {
			if ( ! $o->is_paid() ) {
				$candidates[] = array( 'id' => $o->get_id(), 'total' => (float) $o->get_total(), 'name' => $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() );
			}
		}
		return self::suggest( $m['amount'], $m['name'], $candidates );
	}

	/**
	 * Pure: should this order get a payment reminder now?
	 * $info: [gateway, status, created (timestamp), reminded (bool)].
	 */
	public static function needs_reminder( array $info, array $gateways, $days, $live_since, $now ) {
		if ( $info['reminded'] || 'on-hold' !== $info['status'] || ! in_array( $info['gateway'], $gateways, true ) ) {
			return false;
		}
		if ( ! $live_since || $info['created'] < $live_since ) {
			return false;
		}
		return ( $now - $info['created'] ) >= (int) $days * 86400;
	}

	public static function apply( $order, array $m, $result, $paid_before, $suffix = '' ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$amount = wc_price( $m['amount'], array( 'currency' => $m['currency'] ?: $order->get_currency() ) );
		$from   = trim( $m['name'] . ( '' !== $m['account'] ? ' (' . $m['account'] . ')' : '' ) );
		/* translators: 1: Fio movement ID, 2: date, 3: sender */
		$detail = sprintf( __( 'Fio movement %1$s from %2$s, %3$s.', 'payment-matcher-for-fio-bank' ), $m['id'], $m['date'], '' !== $from ? $from : __( 'unknown sender', 'payment-matcher-for-fio-bank' ) ) . $suffix;
		$status = wc_get_order_status_name( $order->get_status() );

		switch ( $result ) {
			case 'matched':
				/* translators: 1: amount, 2: movement detail */
				$order->add_order_note( sprintf( __( 'Bank transfer received: %1$s. %2$s', 'payment-matcher-for-fio-bank' ), $amount, $detail ) );
				$order->payment_complete( $m['id'] );
				break;
			case 'overpaid':
				$extra = wc_price( round( $paid_before + $m['amount'] - (float) $order->get_total(), 2 ) );
				/* translators: 1: overpaid amount, 2: amount, 3: movement detail */
				$order->add_order_note( sprintf( __( 'Bank transfer received with an overpayment of %1$s: %2$s. %3$s', 'payment-matcher-for-fio-bank' ), $extra, $amount, $detail ) );
				$order->payment_complete( $m['id'] );
				/* translators: %s: overpaid amount */
				self::notify( $order, __( 'Overpayment on order', 'payment-matcher-for-fio-bank' ), sprintf( __( '%s more than the order total was received. The order is marked as paid.', 'payment-matcher-for-fio-bank' ), $extra ), $m );
				break;
			case 'partial':
				$sum = wc_price( $paid_before + $m['amount'] );
				$tot = wc_price( $order->get_total() );
				/* translators: 1: amount, 2: received so far, 3: order total, 4: movement detail */
				$order->add_order_note( sprintf( __( 'Partial payment received: %1$s (%2$s of %3$s in total). %4$s', 'payment-matcher-for-fio-bank' ), $amount, $sum, $tot, $detail ) );
				/* translators: 1: received so far, 2: order total */
				self::notify( $order, __( 'Underpayment on order', 'payment-matcher-for-fio-bank' ), sprintf( __( 'So far %1$s of %2$s has been received. The order status has not changed.', 'payment-matcher-for-fio-bank' ), $sum, $tot ), $m );
				break;
			case 'review':
				/* translators: 1: amount, 2: order status, 3: movement detail */
				$order->add_order_note( sprintf( __( 'A payment of %1$s arrived, but the order is “%2$s” or uses another payment method or currency, so nothing was changed. %3$s', 'payment-matcher-for-fio-bank' ), $amount, $status, $detail ) );
				$hint = self::is_restorable( $order ) ? ' ' . __( 'You can restore the order with “Restore and mark paid” on the Fio payments page.', 'payment-matcher-for-fio-bank' ) : '';
				/* translators: 1: order status, 2: payment method */
				self::notify( $order, __( 'Payment to review', 'payment-matcher-for-fio-bank' ), sprintf( __( 'A payment arrived for an order that cannot be marked as paid automatically (status “%1$s”, payment method %2$s). Please check it manually.', 'payment-matcher-for-fio-bank' ), $status, $order->get_payment_method_title() ) . $hint, $m );
				break;
		}
	}

	private static function notify( WC_Order $order, $subject, $text, array $m ) {
		$to = PMFIO_Settings::get( 'notify_email' );
		if ( ! is_email( $to ) ) {
			return;
		}
		$body  = '<p>' . $text . '</p>';
		$body .= '<p>' . esc_html__( 'Order:', 'payment-matcher-for-fio-bank' ) . ' <a href="' . esc_url( $order->get_edit_order_url() ) . '">' . esc_html( $order->get_order_number() ) . '</a><br>';
		$body .= esc_html__( 'Payment:', 'payment-matcher-for-fio-bank' ) . ' ' . wc_price( $m['amount'] ) . ', ' . esc_html( $m['date'] ) . '<br>';
		$body .= esc_html__( 'From:', 'payment-matcher-for-fio-bank' ) . ' ' . esc_html( trim( $m['name'] . ' ' . $m['account'] ) ) . '<br>';
		$body .= esc_html__( 'Message:', 'payment-matcher-for-fio-bank' ) . ' ' . esc_html( $m['message'] ) . '</p>';
		$body .= '<p><a href="' . esc_url( admin_url( 'admin.php?page=payment-matcher-for-fio-bank' ) ) . '">' . esc_html__( 'All Fio payments', 'payment-matcher-for-fio-bank' ) . '</a></p>';
		wp_mail( $to, $subject . ' ' . $order->get_order_number(), $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}
}
