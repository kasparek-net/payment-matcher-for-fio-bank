<?php
// Standalone test: php tests/harness.php (not shipped in the ZIP).
define( 'ABSPATH', __DIR__ );

$GLOBALS['opts']   = array( 'pmfio_settings' => array( 'mode' => 'live' ), 'admin_email' => 'admin@example.test' );
$GLOBALS['mails']  = array();
$GLOBALS['orders'] = array();

function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function apply_filters( $h, $v ) { return $v; }
function __( $s ) { return $s; }
function esc_html__( $s ) { return $s; }
function _n( $a, $b, $n ) { return 1 === $n ? $a : $b; }
function remove_accents( $s ) { return strtr( $s, array( 'á' => 'a', 'č' => 'c', 'ě' => 'e', 'í' => 'i', 'ř' => 'r', 'š' => 's', 'ž' => 'z', 'ý' => 'y', 'ů' => 'u', 'Č' => 'C' ) ); }
function add_filter() {}
function do_action( $hook, ...$args ) { $GLOBALS['actions'][] = $hook; }
function admin_url( $p = '' ) { return 'http://x/wp-admin/' . $p; }
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
function remove_filter() {}
function wc_price( $a ) { return number_format( (float) $a, 2, ',', ' ' ) . ' Kč'; }
function wc_get_order_status_name( $s ) { return $s; }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function esc_html( $s ) { return $s; }
function esc_url( $s ) { return $s; }
function wp_mail( $to, $subject ) { $GLOBALS['mails'][] = $subject; return true; }
function wc_get_order_statuses() { return array( 'wc-on-hold' => 1, 'wc-pending' => 1, 'wc-processing' => 1, 'wc-cancelled' => 1 ); }
function wc_get_order( $id ) { return $GLOBALS['orders'][ (int) $id ] ?? false; }
function wc_get_orders( $args ) {
	if ( ! isset( $args['meta_query'] ) ) { return array(); }
	$key = $args['meta_query'][0]['key'];
	$val = $args['meta_query'][0]['value'];
	$out = array();
	foreach ( $GLOBALS['orders'] as $o ) {
		if ( ( $o->meta[ $key ] ?? null ) === $val ) {
			$out[] = $o->get_id();
		}
	}
	return $out;
}

class WC_Order {
	public $notes = array();
	public $paid_tx = null;
	public $meta = array();
	public $id;
	public $number;
	public $total;
	public $status;
	public $gateway;
	public $currency;
	public function __construct( $id, $number, $total, $status = 'on-hold', $gateway = 'bacs', $currency = 'CZK' ) {
		$this->id       = $id;
		$this->number   = $number;
		$this->total    = $total;
		$this->status   = $status;
		$this->gateway  = $gateway;
		$this->currency = $currency;
		$this->meta['_order_number'] = (string) $number;
	}
	public function get_id() { return $this->id; }
	public function get_type() { return 'shop_order'; }
	public function get_order_number() { return (string) $this->number; }
	public function get_total() { return $this->total; }
	public function get_status() { return $this->status; }
	public function get_payment_method() { return $this->gateway; }
	public function get_payment_method_title() { return $this->gateway; }
	public function get_currency() { return $this->currency; }
	public function is_paid() { return in_array( $this->status, array( 'processing', 'completed' ), true ); }
	public function add_order_note( $n ) { $this->notes[] = $n; }
	public function payment_complete( $tx ) { $this->paid_tx = $tx; $this->status = 'processing'; }
	public function update_status( $s ) { $this->status = $s; }
	public function get_edit_order_url() { return 'http://x/' . $this->id; }
}

class PMFIO_Store {
	public static $rows = array();
	public static function exists( $id, $live ) {
		if ( ! isset( self::$rows[ $id ] ) ) { return false; }
		return $live ? 0 !== strpos( self::$rows[ $id ]['result'], 'obs_' ) : true;
	}
	public static function insert( $m, $order_id, $result, $sug = 0 ) { self::$rows[ $m['id'] ] = array( 'order_id' => $order_id, 'amount' => $m['amount'], 'result' => $result, 'm' => $m, 'sug' => $sug ); }
	public static function get( $id ) {
		if ( ! isset( self::$rows[ $id ] ) ) { return null; }
		$r = self::$rows[ $id ];
		return (object) array( 'movement_id' => $id, 'order_id' => $r['order_id'], 'suggested_order_id' => $r['sug'], 'result' => $r['result'], 'm' => $r['m'] );
	}
	public static function to_movement( $row ) { return $row->m; }
	public static function paid_sum( $order_id, $results ) {
		$s = 0;
		foreach ( self::$rows as $r ) {
			if ( $r['order_id'] === $order_id && in_array( $r['result'], $results, true ) ) { $s += $r['amount']; }
		}
		return $s;
	}
}

require __DIR__ . '/../includes/class-pmfio-settings.php';
require __DIR__ . '/../includes/class-pmfio-matcher.php';
require __DIR__ . '/../includes/class-pmfio-qr-code.php';
require __DIR__ . '/../includes/class-pmfio-qr-payment.php';
require __DIR__ . '/../includes/class-pmfio-runner.php';
require __DIR__ . '/../includes/class-pmfio-privacy.php';

function tx( $id, $amount, $vs = null, $msg = null, $cur = 'CZK', $date = '2026-11-12+0100' ) {
	$c = function ( $v ) { return null === $v ? null : array( 'value' => $v, 'name' => '', 'id' => 0 ); };
	return array(
		'column22' => $c( $id ), 'column0' => $c( $date ), 'column1' => $c( $amount ), 'column14' => $c( $cur ),
		'column5' => $c( $vs ), 'column16' => $c( $msg ), 'column7' => $c( ' ' ), 'column2' => $c( '123456' ),
		'column3' => $c( '0800' ), 'column10' => $c( 'Jana Nováková' ),
	);
}

$fails = 0;
function check( $name, $cond, $info = '' ) { global $fails; echo ( $cond ? 'OK   ' : 'FAIL ' ) . $name . ( $cond || '' === $info ? '' : '  [' . $info . ']' ) . "\n"; if ( ! $cond ) { $fails++; } }

$o = array(
	1001 => new WC_Order( 1001, 5001, 1290.00 ),
	1002 => new WC_Order( 1002, 5002, 1000.00 ),
	1003 => new WC_Order( 1003, 5003, 500.00 ),
	1004 => new WC_Order( 1004, 5004, 700.00, 'cancelled' ),
	1005 => new WC_Order( 1005, 5005, 300.00, 'on-hold', 'cod' ),
	1006 => new WC_Order( 1006, 5006, 400.00 ),
	1007 => new WC_Order( 1007, 5007, 250.00 ),
	1008 => new WC_Order( 1008, 1008, 99.00 ),
);
$GLOBALS['orders'] = $o;

check( 'exact payment', 'matched' === PMFIO_Matcher::process( tx( 1, 1290, '5001' ), true ) && 'processing' === $o[1001]->status && '1' === $o[1001]->paid_tx );
check( 'duplicate id skipped', '' === PMFIO_Matcher::process( tx( 1, 1290, '5001' ), true ) );
check( 'already paid -> review', 'review' === PMFIO_Matcher::process( tx( 2, 1290, '5001' ), true ) );
check( 'leading zeros VS', 'overpaid' === PMFIO_Matcher::process( tx( 3, 1100, '0005002' ), true ) && 'processing' === $o[1002]->status );
check( 'partial then rest', 'partial' === PMFIO_Matcher::process( tx( 4, 200, '5003' ), true ) && 'on-hold' === $o[1003]->status
	&& 'matched' === PMFIO_Matcher::process( tx( 5, 300, '5003' ), true ) && 'processing' === $o[1003]->status );
check( 'cancelled -> review, not revived', 'review' === PMFIO_Matcher::process( tx( 6, 700, '5004' ), true ) && 'cancelled' === $o[1004]->status );
check( 'other gateway -> review', 'review' === PMFIO_Matcher::process( tx( 7, 300, '5005' ), true ) && 'on-hold' === $o[1005]->status );
check( 'outgoing ignored', '' === PMFIO_Matcher::process( tx( 8, -400, '5006' ), true ) && 'on-hold' === $o[1006]->status );
check( 'other currency -> review', 'review' === PMFIO_Matcher::process( tx( 9, 400, '5006', null, 'EUR' ), true ) );
check( 'VS in message', 'matched' === PMFIO_Matcher::process( tx( 10, 400, null, 'objednavka c. 5006 dekuji' ), true ) && 'processing' === $o[1006]->status );
check( 'unknown VS -> no_order', 'no_order' === PMFIO_Matcher::process( tx( 11, 50, '999999' ), true ) );
check( 'no VS, space-only fields', 'no_order' === PMFIO_Matcher::process( tx( 12, 50, ' ', ' ' ), true ) );
check( 'fallback to order id', 'matched' === PMFIO_Matcher::process( tx( 13, 99, '1008' ), true ) );
check( 'epoch ms date', '2012-06-26' === PMFIO_Matcher::parse_date( 1340661600000 ) );

// Observe mode: nothing changes, later live run processes the same movement for real.
check( 'observe does not touch order', 'obs_matched' === PMFIO_Matcher::process( tx( 14, 250, '5007' ), false ) && 'on-hold' === $o[1007]->status && ! $o[1007]->notes );
check( 'observe then live processes it', 'matched' === PMFIO_Matcher::process( tx( 14, 250, '5007' ), true ) && 'processing' === $o[1007]->status );

check( 'mails: overpaid, partial, 4x review', 6 === count( $GLOBALS['mails'] ) );

// Manual assign: unknown payment, assigned by hand even in observe mode.
$o[1009] = new WC_Order( 1009, 5009, 150.00 ); $GLOBALS['orders'] = $o;
PMFIO_Matcher::process( tx( 20, 150, null, 'platba' ), false );
list( $ok ) = PMFIO_Matcher::assign( '20', '5009', 'Jakub' );
check( 'manual assign pays order', $ok && 'processing' === $o[1009]->status && false !== strpos( end( $o[1009]->notes ), 'manually' ) );
list( $ok ) = PMFIO_Matcher::assign( '20', '5009', 'Jakub' );
check( 'manual assign twice refused', ! $ok );
list( $ok, $msg ) = PMFIO_Matcher::assign( '6', '5004', 'Jakub' );
check( 'assign to cancelled points to restore', ! $ok && false !== strpos( $msg, 'Restore' ) );

// Restore cancelled order (movement 6 was review on cancelled 5004, 700 Kč).
list( $ok ) = PMFIO_Matcher::restore( '6', 'Jakub' );
check( 'restore cancelled -> paid', $ok && 'processing' === $o[1004]->status );

// suggest()
$c = array( array( 'id' => 1, 'total' => 990, 'name' => 'Jana Nováková' ), array( 'id' => 2, 'total' => 990, 'name' => 'Petra Dvořáková' ), array( 'id' => 3, 'total' => 450, 'name' => 'Eva Malá' ) );
check( 'suggest single amount', 3 === PMFIO_Matcher::suggest( 450, 'Kdokoli', $c ) );
check( 'suggest by surname w/o diacritics', 2 === PMFIO_Matcher::suggest( 990, 'DVORAKOVA PETRA', $c ) );
check( 'suggest ambiguous -> 0', 0 === PMFIO_Matcher::suggest( 990, 'Lucie Černá', $c ) );
check( 'suggest no amount -> 0', 0 === PMFIO_Matcher::suggest( 123, 'Jana Nováková', $c ) );

// needs_reminder()
$now = 1800000000; $live = $now - 30 * 86400;
$base = array( 'gateway' => 'bacs', 'status' => 'on-hold', 'created' => $now - 4 * 86400, 'reminded' => false );
check( 'reminder due', PMFIO_Matcher::needs_reminder( $base, array( 'bacs' ), 3, $live, $now ) );
check( 'reminder too early', ! PMFIO_Matcher::needs_reminder( array_merge( $base, array( 'created' => $now - 2 * 86400 ) ), array( 'bacs' ), 3, $live, $now ) );
check( 'reminder already sent', ! PMFIO_Matcher::needs_reminder( array_merge( $base, array( 'reminded' => true ) ), array( 'bacs' ), 3, $live, $now ) );
check( 'reminder older than live switch', ! PMFIO_Matcher::needs_reminder( $base, array( 'bacs' ), 3, $now - 86400, $now ) );
check( 'reminder never when not live', ! PMFIO_Matcher::needs_reminder( $base, array( 'bacs' ), 3, 0, $now ) );
check( 'reminder wrong gateway', ! PMFIO_Matcher::needs_reminder( array_merge( $base, array( 'gateway' => 'cod' ) ), array( 'bacs' ), 3, $live, $now ) );
check( 'reminder processing', ! PMFIO_Matcher::needs_reminder( array_merge( $base, array( 'status' => 'processing' ) ), array( 'bacs' ), 3, $live, $now ) );

// QR payment
check( 'czech IBAN with prefix', 'CZ6508000000192000145399' === PMFIO_QR_Payment::czech_iban( '19-2000145399', '0800' ) );
check( 'czech IBAN from number/bank', 'CZ6508000000192000145399' === PMFIO_QR_Payment::czech_iban( '19-2000145399/0800' ) );
check( 'IBAN validates', PMFIO_QR_Payment::valid_iban( 'CZ65 0800 0000 1920 0014 5399' ) && ! PMFIO_QR_Payment::valid_iban( 'CZ66 0800 0000 1920 0014 5399' ) );
check( 'generated IBAN is valid', PMFIO_QR_Payment::valid_iban( PMFIO_QR_Payment::czech_iban( '2900000000', '2010' ) ) );
check( 'bad account -> empty', '' === PMFIO_QR_Payment::czech_iban( 'abc', '0800' ) && '' === PMFIO_QR_Payment::czech_iban( '123456', '08' ) );
$spd = PMFIO_QR_Payment::spd( 'CZ6508000000192000145399', 1290, 'czk', '5013', 'Objednávka *5013* Čeká' );
check( 'SPD string', 'SPD*1.0*ACC:CZ6508000000192000145399*AM:1290.00*CC:CZK*X-VS:5013*MSG:OBJEDNAVKA  5013  CEKA' === $spd, $spd );
check( 'SPD skips non-numeric VS', false === strpos( PMFIO_QR_Payment::spd( 'CZ6508000000192000145399', 10, 'CZK', 'AB-12', '' ), 'X-VS' ) );
check( 'QR matrix for SPD', is_array( PMFIO_QR_Code::matrix( $spd ) ) && 0 === strpos( PMFIO_QR_Code::svg( $spd ), '<svg' ) );
check( 'QR too long -> null', null === PMFIO_QR_Code::matrix( str_repeat( 'x', 300 ) ) );

// Paid by something else (other plugin, by hand)
$o[1012] = new WC_Order( 1012, 5012, 640.00, 'processing' ); $GLOBALS['orders'] = $o;
check( 'paid elsewhere -> already_paid, no note', 'already_paid' === PMFIO_Matcher::process( tx( 40, 640, '5012' ), true ) && empty( $o[1012]->notes ) );
$o[1013] = new WC_Order( 1013, 5013, 640.00, 'processing', 'gpwebpay-crd' ); $GLOBALS['orders'] = $o;
check( 'transfer to card-paid order -> review', 'review' === PMFIO_Matcher::process( tx( 41, 640, '5013' ), true ) );

// Refunds
check( 'is_refund within paid', PMFIO_Matcher::is_refund( 1000, 0, -500 ) && PMFIO_Matcher::is_refund( 1000, 500, -500 ) );
check( 'is_refund over paid -> no', ! PMFIO_Matcher::is_refund( 1000, 600, -500 ) && ! PMFIO_Matcher::is_refund( 0, 0, -100 ) );
$GLOBALS['actions'] = array();
check( 'refund of paid order', 'refund' === PMFIO_Matcher::process( tx( 30, -500, '5002' ), true ) && false !== strpos( end( $o[1002]->notes ), 'Refunded' ) );
check( 'refund fires action', in_array( 'pmfio_refund_matched', $GLOBALS['actions'], true ) );
check( 'refund over paid sum ignored', '' === PMFIO_Matcher::process( tx( 31, -900, '5002' ), true ) );
check( 'ignored outgoing stored without personal data', isset( PMFIO_Store::$rows['31'] ) && 'outgoing' === PMFIO_Store::$rows['31']['result'] && '' === PMFIO_Store::$rows['31']['m']['name'] );
$o[1010] = new WC_Order( 1010, 5010, 300.00 ); $o[1011] = new WC_Order( 1011, 5011, 120.00 ); $GLOBALS['orders'] = $o;
check( 'outgoing to unpaid order not a refund', '' === PMFIO_Matcher::process( tx( 32, -100, '5010' ), true ) && empty( $o[1010]->notes ) );
check( 'outgoing not processed twice', '' === PMFIO_Matcher::process( tx( 31, -900, '5002' ), true ) );
$GLOBALS['actions'] = array();
check( 'payment_matched action fired', 'matched' === PMFIO_Matcher::process( tx( 33, 120, '5011' ), true ) && in_array( 'pmfio_payment_matched', $GLOBALS['actions'], true ) );

// Token expiry
$e = PMFIO_Runner::expiry_state( '2026-12-01', '2026-11-13' );
check( 'expiry ok (18 days)', 'ok' === $e['state'] && 18 === $e['days'] );
check( 'expiry soon (14 days)', 'soon' === PMFIO_Runner::expiry_state( '2026-11-27', '2026-11-13' )['state'] );
check( 'expiry today = soon', 'soon' === PMFIO_Runner::expiry_state( '2026-11-13', '2026-11-13' )['state'] );
check( 'expired yesterday', 'expired' === PMFIO_Runner::expiry_state( '2026-11-12', '2026-11-13' )['state'] );
check( 'expiry unknown', 'unknown' === PMFIO_Runner::expiry_state( '', '2026-11-13' )['state'] && 'unknown' === PMFIO_Runner::expiry_state( '13. 11. 2026', '2026-11-13' )['state'] );

// Retention cutoff
check( 'retention 12 months', '2025-11-13' === PMFIO_Privacy::cutoff( 12, '2026-11-13' ) );
check( 'retention 0 = keep', '' === PMFIO_Privacy::cutoff( 0, '2026-11-13' ) );
check( 'retention min 3 months', '2026-08-13' === PMFIO_Privacy::cutoff( 1, '2026-11-13' ) );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit( $fails ? 1 : 0 );
