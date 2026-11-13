<?php
/**
 * Fio banka API client (read-only "periods" endpoint).
 *
 * @package PaymentMatcherForFioBank
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PMFIO_Api {

	const BASE = 'https://fioapi.fio.cz/v1/rest/';
	const LOCK = 'pmfio_api_lock';

	/**
	 * @return array|WP_Error List of raw transaction arrays.
	 */
	public static function fetch_period( $token, $from, $to ) {
		if ( '' === $token ) {
			return new WP_Error( 'pmfio_no_token', __( 'The Fio API token is missing.', 'payment-matcher-for-fio-bank' ) );
		}
		if ( get_transient( self::LOCK ) ) {
			return new WP_Error( 'pmfio_locked', __( 'Fio allows one request per 30 seconds, please try again in a moment.', 'payment-matcher-for-fio-bank' ) );
		}
		set_transient( self::LOCK, 1, 35 );

		$url      = self::BASE . 'periods/' . rawurlencode( $token ) . '/' . $from . '/' . $to . '/transactions.json';
		$response = wp_remote_get( $url, array( 'timeout' => 20 ) );

		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message */
			return new WP_Error( 'pmfio_http', sprintf( __( 'Connection to Fio failed: %s', 'payment-matcher-for-fio-bank' ), self::mask( $response->get_error_message(), $token ) ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( 'pmfio_http_' . $code, self::error_message( $code ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['accountStatement'] ) ) {
			return new WP_Error( 'pmfio_json', __( 'Fio returned an unexpected response.', 'payment-matcher-for-fio-bank' ) );
		}

		$list = $data['accountStatement']['transactionList']['transaction'] ?? array();
		return is_array( $list ) ? $list : array();
	}

	public static function error_message( $code ) {
		switch ( $code ) {
			case 409:
				return __( 'Fio: requests too frequent (limit is one request per 30 seconds per token).', 'payment-matcher-for-fio-bank' );
			case 500:
				return __( 'Fio: the token does not exist or has expired. Extend it or create a new one in Fio internet banking (Settings → API).', 'payment-matcher-for-fio-bank' );
			case 422:
				return __( 'Fio: data older than 90 days requires authorisation in internet banking.', 'payment-matcher-for-fio-bank' );
			case 413:
				return __( 'Fio: too many movements, reduce the number of days to look back.', 'payment-matcher-for-fio-bank' );
			case 404:
				return __( 'Fio: malformed request (404).', 'payment-matcher-for-fio-bank' );
			default:
				/* translators: %d: HTTP status code */
				return sprintf( __( 'Fio: unexpected HTTP status %d.', 'payment-matcher-for-fio-bank' ), $code );
		}
	}

	public static function mask( $text, $token ) {
		return '' === $token ? $text : str_replace( $token, '***', $text );
	}
}
