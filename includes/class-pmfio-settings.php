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

	public static function token_from_constant() {
		return defined( 'PMFIO_FIO_TOKEN' ) && PMFIO_FIO_TOKEN;
	}
}
