<?php
/**
 * Plugin settings.
 *
 * @package PaymentMatcherForFioBank
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PMFIO_Settings {

	const OPTION       = 'pmfio_settings';
	const TOKEN_OPTION = 'pmfio_token';
	const EXTRA_OPTION = 'pmfio_extra_accounts';
	const INFO_OPTION  = 'pmfio_account_info';
	const MAX_EXTRA    = 4;

	public static function defaults() {
		return array(
			'mode'          => 'observe',
			'gateways'      => 'bacs',
			'statuses'      => 'on-hold,pending',
			'number_meta'   => '_order_number',
			'days_back'     => 3,
			'notify_email'  => get_option( 'admin_email' ),
			'daily_summary' => 'yes',
			'reminder_days' => 3,
			'token_expires' => '',
			'retention'     => 12,
			'qr_thankyou'   => 'no',
			'qr_email'      => 'no',
			'qr_iban'       => '',
			'qr_message'    => '',
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	public static function list_of( $key ) {
		return array_values( array_filter( array_map( 'trim', explode( ',', (string) self::get( $key ) ) ) ) );
	}

	public static function is_live() {
		return 'live' === self::get( 'mode' );
	}

	public static function token() {
		if ( defined( 'PMFIO_FIO_TOKEN' ) && PMFIO_FIO_TOKEN ) {
			return (string) PMFIO_FIO_TOKEN;
		}
		return (string) get_option( self::TOKEN_OPTION, '' );
	}

	/**
	 * Additional Fio accounts (one read-only token each), e.g. a EUR account next to the CZK one.
	 *
	 * @return array<int,array{token:string,expires:string}>
	 */
	public static function extra_accounts() {
		$list = get_option( self::EXTRA_OPTION, array() );
		$out  = array();
		foreach ( is_array( $list ) ? $list : array() as $a ) {
			if ( is_array( $a ) && ! empty( $a['token'] ) ) {
				$out[] = array( 'token' => (string) $a['token'], 'expires' => (string) ( $a['expires'] ?? '' ) );
			}
		}
		return $out;
	}

	/**
	 * All connected accounts, the main one first.
	 *
	 * @return array<int,array{token:string,expires:string}>
	 */
	public static function accounts() {
		$out  = array();
		$main = self::token();
		if ( '' !== $main ) {
			$out[] = array( 'token' => $main, 'expires' => (string) self::get( 'token_expires' ) );
		}
		return array_merge( $out, self::extra_accounts() );
	}

	public static function token_id( $token ) {
		return substr( md5( (string) $token ), 0, 12 );
	}

	/**
	 * What Fio reported about the account behind a token (IBAN, currency…), saved after each download.
	 *
	 * @return array{iban?:string,bic?:string,currency?:string,account?:string}
	 */
	public static function account_info( $token ) {
		$all = get_option( self::INFO_OPTION, array() );
		$id  = self::token_id( $token );
		return is_array( $all ) && isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? $all[ $id ] : array();
	}

	public static function save_account_info( $token, array $info ) {
		$all = get_option( self::INFO_OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		$new = array(
			'iban'     => strtoupper( preg_replace( '/\s+/', '', (string) ( $info['iban'] ?? '' ) ) ),
			'bic'      => strtoupper( (string) ( $info['bic'] ?? '' ) ),
			'currency' => strtoupper( (string) ( $info['currency'] ?? '' ) ),
			'account'  => trim( (string) ( $info['accountId'] ?? '' ) . ( empty( $info['bankId'] ) ? '' : '/' . $info['bankId'] ) ),
		);
		if ( ( $all[ self::token_id( $token ) ] ?? null ) !== $new ) {
			$all[ self::token_id( $token ) ] = $new;
			update_option( self::INFO_OPTION, $all, false );
		}
	}

	/** Human label of an account, e.g. "2900000000/2010 · EUR". */
	public static function account_label( $token ) {
		$i = self::account_info( $token );
		if ( empty( $i['account'] ) && empty( $i['iban'] ) ) {
			return '';
		}
		return trim( ( $i['account'] ?: $i['iban'] ) . ( empty( $i['currency'] ) ? '' : ' · ' . $i['currency'] ) );
	}

	public static function token_from_constant() {
		return defined( 'PMFIO_FIO_TOKEN' ) && PMFIO_FIO_TOKEN;
	}
}
