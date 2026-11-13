<?php
/**
 * Server cron endpoint (?pmfio-cron=KEY) and WP-CLI command, for sites where WP-Cron is unreliable.
 *
 * @package PaymentMatcherForFioBank
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PMFIO_Cron {

	const QUERY    = 'pmfio-cron';
	const KEY      = 'pmfio_cron_key';
	const LAST     = 'pmfio_last_cron';
	const DAILY    = 'pmfio_last_daily';
	const THROTTLE = 'pmfio_cron_throttle';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'handle' ), 5 );
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			WP_CLI::add_command( 'pmfio check', array( __CLASS__, 'cli_check' ) );
		}
	}

	public static function key() {
		$key = (string) get_option( self::KEY, '' );
		if ( strlen( $key ) < 32 ) {
			$key = self::regenerate();
		}
		return $key;
	}

	public static function regenerate() {
		$key = wp_generate_password( 40, false, false );
		update_option( self::KEY, $key, false );
		return $key;
	}

	public static function url() {
		return add_query_arg( self::QUERY, self::key(), home_url( '/' ) );
	}

	/**
	 * Pure: is the daily summary due? Once a day, at 7:00 or later (site time).
	 */
	public static function daily_due( $last_date, $today, $hour ) {
		return (int) $hour >= 7 && (string) $last_date !== (string) $today;
	}

	/** Daily summary at most once per day, whoever triggers it (server cron or WP-Cron). */
	public static function maybe_daily() {
		$today = wp_date( 'Y-m-d' );
		if ( ! self::daily_due( get_option( self::DAILY ), $today, (int) wp_date( 'G' ) ) ) {
			return false;
		}
		update_option( self::DAILY, $today, false );
		PMFIO_Runner::daily_summary();
		return true;
	}

	public static function handle() {
		if ( ! isset( $_GET[ self::QUERY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- authenticated by the secret key below.
			return;
		}
		$given = sanitize_text_field( wp_unslash( $_GET[ self::QUERY ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		$key = (string) get_option( self::KEY, '' );
		if ( strlen( $key ) < 32 || ! hash_equals( $key, $given ) ) {
			status_header( 403 );
			echo 'Forbidden';
			exit;
		}
		update_option( self::LAST, time(), false );
		if ( get_transient( self::THROTTLE ) ) {
			echo 'OK: skipped, last run less than a minute ago.';
			exit;
		}
		set_transient( self::THROTTLE, 1, MINUTE_IN_SECONDS );
		$res   = PMFIO_Runner::run();
		$daily = self::maybe_daily();
		echo ( $res['ok'] ? 'OK: ' : 'ERROR: ' ) . esc_html( $res['message'] ) . ( $daily ? ' Daily summary sent.' : '' );
		exit;
	}

	/**
	 * Downloads Fio movements and matches them now.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pmfio check
	 */
	public static function cli_check() {
		// Fio allows one request per 30 s; on the command line it is friendlier to wait than to fail.
		for ( $i = 0; $i < 36 && get_transient( PMFIO_Api::LOCK ); $i++ ) {
			sleep( 1 );
		}
		$res = PMFIO_Runner::run();
		self::maybe_daily();
		if ( $res['ok'] ) {
			WP_CLI::success( $res['message'] );
		} else {
			WP_CLI::error( $res['message'] );
		}
	}
}
