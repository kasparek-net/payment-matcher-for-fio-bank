<?php
/**
 * Scheduled jobs: payment check, daily summary, reminders, health alerts.
 *
 * @package PaymentMatcherForFioBank
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PMFIO_Runner {

	const HOOK = 'pmfio_check';
	const DAILY = 'pmfio_daily';
	const GROUP = 'payment-matcher-for-fio-bank';
	const INTERVAL = 900;
	const STALE_AFTER = 6 * HOUR_IN_SECONDS;
	const REMINDERS_PER_RUN = 20;

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( self::DAILY, array( 'PMFIO_Cron', 'maybe_daily' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedule() {
		if ( ! function_exists( 'as_has_scheduled_action' ) || '' === PMFIO_Settings::token() ) {
			return;
		}
		if ( ! as_has_scheduled_action( self::HOOK, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + 60, self::INTERVAL, self::HOOK, array(), self::GROUP, true );
		}
		if ( ! as_has_scheduled_action( self::DAILY, array(), self::GROUP ) ) {
			$next = new DateTimeImmutable( 'tomorrow 07:00', new DateTimeZone( 'Europe/Prague' ) );
			as_schedule_recurring_action( $next->getTimestamp(), DAY_IN_SECONDS, self::DAILY, array(), self::GROUP, true );
		}
	}

	public static function unschedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
			as_unschedule_all_actions( self::DAILY, array(), self::GROUP );
		}
	}

	/**
	 * @return array{ok:bool,message:string,counts:array}
	 */
	public static function run() {
		$token = PMFIO_Settings::token();
		$days  = max( 1, min( 89, (int) PMFIO_Settings::get( 'days_back' ) ) );
		$tz    = new DateTimeZone( 'Europe/Prague' );
		$to    = ( new DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d' );
		$from  = ( new DateTimeImmutable( 'now', $tz ) )->modify( '-' . $days . ' days' )->format( 'Y-m-d' );

		$list = PMFIO_Api::fetch_period( $token, $from, $to );
		if ( is_wp_error( $list ) ) {
			// 409 = another client (e.g. a second plugin sharing the token) asked within 30 s. Transient, not an outage.
			if ( ! in_array( $list->get_error_code(), array( 'pmfio_locked', 'pmfio_http_409' ), true ) ) {
				update_option( 'pmfio_last_error', array( 'time' => current_time( 'mysql' ), 'message' => $list->get_error_message() ), false );
				self::log( 'error', $list->get_error_message() );
				self::maybe_alert( $list->get_error_message() );
			}
			return array( 'ok' => false, 'message' => $list->get_error_message(), 'counts' => array() );
		}

		$live   = PMFIO_Settings::is_live();
		$counts = array();
		foreach ( $list as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$result = PMFIO_Matcher::process( $raw, $live );
			if ( '' !== $result ) {
				$counts[ $result ] = ( $counts[ $result ] ?? 0 ) + 1;
			}
		}

		update_option( 'pmfio_last_run', array( 'time' => current_time( 'mysql' ), 'ts' => time(), 'movements' => count( $list ), 'counts' => $counts ), false );
		delete_option( 'pmfio_last_error' );
		delete_option( 'pmfio_alert_sent' );
		if ( $counts ) {
			self::log( 'info', 'Processed: ' . wp_json_encode( $counts ) );
		}

		$reminded = $live ? self::send_reminders() : 0;

		/* translators: 1: downloaded movements, 2: new movements */
		$msg = sprintf( __( 'Downloaded %1$d movements, %2$d new.', 'payment-matcher-for-fio-bank' ), count( $list ), array_sum( $counts ) );
		if ( $reminded ) {
			/* translators: %d: number of reminders */
			$msg .= ' ' . sprintf( __( 'Reminders sent: %d.', 'payment-matcher-for-fio-bank' ), $reminded );
		}
		return array( 'ok' => true, 'message' => $msg, 'counts' => $counts );
	}

	/**
	 * Problem the shop owner must know about: API error, or no successful run for a long time.
	 */
	public static function health_problem() {
		if ( '' === PMFIO_Settings::token() ) {
			return '';
		}
		$error = get_option( 'pmfio_last_error' );
		if ( $error ) {
			return $error['message'];
		}
		$last = get_option( 'pmfio_last_run' );
		$ts   = is_array( $last ) ? (int) ( $last['ts'] ?? 0 ) : 0;
		$since = (int) get_option( 'pmfio_token_saved', 0 );
		if ( $ts && time() - $ts > self::STALE_AFTER ) {
			/* translators: %s: date and time */
			return sprintf( __( 'No payment check has run since %s. The task scheduler (WP-Cron / Action Scheduler) is probably not running.', 'payment-matcher-for-fio-bank' ), $last['time'] );
		}
		if ( ! $ts && $since && time() - $since > self::STALE_AFTER ) {
			return __( 'No payment check has run yet.', 'payment-matcher-for-fio-bank' );
		}
		return '';
	}

	private static function maybe_alert( $message ) {
		if ( get_option( 'pmfio_alert_sent' ) ) {
			return;
		}
		$to = PMFIO_Settings::get( 'notify_email' );
		if ( ! is_email( $to ) ) {
			return;
		}
		/* translators: %s: site URL */
		$body  = '<p>' . sprintf( esc_html__( 'Automatic Fio payment matching on %s is not working right now:', 'payment-matcher-for-fio-bank' ), esc_html( home_url() ) ) . '</p>';
		$body .= '<p><strong>' . esc_html( $message ) . '</strong></p>';
		$body .= '<p>' . esc_html__( 'Until this is fixed, paid orders will not be marked automatically. If the token expired: Fio internet banking → Settings → API → extend the token (or create a new one and paste it on the Fio payments page).', 'payment-matcher-for-fio-bank' ) . '</p>';
		$body .= '<p><a href="' . esc_url( admin_url( 'admin.php?page=payment-matcher-for-fio-bank' ) ) . '">' . esc_html__( 'Open Fio payments', 'payment-matcher-for-fio-bank' ) . '</a></p>';
		wp_mail( $to, __( 'Fio payment matching is not working', 'payment-matcher-for-fio-bank' ), $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
		update_option( 'pmfio_alert_sent', time(), false );
	}

	const EXPIRY_WARN_DAYS = 14;

	/**
	 * Pure: token expiry state. $expires and $today are Y-m-d.
	 *
	 * @return array{state:string,days:int} state: unknown|ok|soon|expired
	 */
	public static function expiry_state( $expires, $today, $warn_days = self::EXPIRY_WARN_DAYS ) {
		if ( ! is_string( $expires ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expires ) ) {
			return array( 'state' => 'unknown', 'days' => 0 );
		}
		$days = (int) floor( ( strtotime( $expires . ' 00:00:00 UTC' ) - strtotime( $today . ' 00:00:00 UTC' ) ) / DAY_IN_SECONDS );
		if ( $days < 0 ) {
			return array( 'state' => 'expired', 'days' => $days );
		}
		return array( 'state' => $days <= $warn_days ? 'soon' : 'ok', 'days' => $days );
	}

	public static function token_expiry() {
		if ( '' === PMFIO_Settings::token() ) {
			return array( 'state' => 'unknown', 'days' => 0 );
		}
		return self::expiry_state( (string) PMFIO_Settings::get( 'token_expires' ), wp_date( 'Y-m-d' ) );
	}

	/** One e-mail per expiry date when the token expires within EXPIRY_WARN_DAYS days. */
	public static function maybe_expiry_alert() {
		$expires = (string) PMFIO_Settings::get( 'token_expires' );
		$exp     = self::token_expiry();
		if ( 'soon' !== $exp['state'] || get_option( 'pmfio_expiry_alert' ) === $expires ) {
			return;
		}
		$to = PMFIO_Settings::get( 'notify_email' );
		if ( ! is_email( $to ) ) {
			return;
		}
		$date  = wp_date( get_option( 'date_format' ), strtotime( $expires . ' 12:00:00' ) );
		/* translators: 1: site URL, 2: date */
		$body  = '<p>' . esc_html( sprintf( __( 'The Fio API token used on %1$s expires on %2$s. After that, payments will no longer be matched automatically.', 'payment-matcher-for-fio-bank' ), home_url(), $date ) ) . '</p>';
		$body .= '<p>' . esc_html__( 'If the token has automatic renewal, simply log in to Fio internet banking and it will be extended by 180 days. Otherwise extend it or create a new one in Settings → API. Then update the expiry date on the Fio payments settings page.', 'payment-matcher-for-fio-bank' ) . '</p>';
		$body .= '<p><a href="' . esc_url( admin_url( 'admin.php?page=payment-matcher-for-fio-bank&tab=settings' ) ) . '">' . esc_html__( 'Open settings', 'payment-matcher-for-fio-bank' ) . '</a></p>';
		/* translators: %s: date */
		wp_mail( $to, sprintf( __( 'Fio API token expires on %s', 'payment-matcher-for-fio-bank' ), $date ), $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
		update_option( 'pmfio_expiry_alert', $expires, false );
	}

	public static function daily_summary() {
		PMFIO_Privacy::cleanup();
		self::maybe_expiry_alert();
		$problem = self::health_problem();
		if ( $problem ) {
			self::maybe_alert( $problem );
		}
		if ( 'yes' !== PMFIO_Settings::get( 'daily_summary' ) ) {
			return;
		}
		$to = PMFIO_Settings::get( 'notify_email' );
		if ( ! is_email( $to ) ) {
			return;
		}

		$rows   = PMFIO_Store::created_since( wp_date( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );
		$groups = array( 'paid' => array(), 'review' => array(), 'other' => array() );
		foreach ( $rows as $r ) {
			$key = in_array( $r->result, array( 'matched', 'overpaid' ), true ) ? 'paid' : ( in_array( $r->result, array( 'review', 'partial' ), true ) ? 'review' : 'other' );
			$groups[ $key ][] = $r;
		}
		$waiting = self::waiting_orders( 3 );

		if ( ! $rows && ! $waiting ) {
			return;
		}

		$labels = PMFIO_Admin::labels();
		$line   = function ( $r ) use ( $labels ) {
			$order = $r->order_id ? wc_get_order( (int) $r->order_id ) : null;
			$sug   = $r->suggested_order_id ? wc_get_order( (int) $r->suggested_order_id ) : null;
			/* translators: %s: order number */
			$what  = $order ? sprintf( __( 'order %s', 'payment-matcher-for-fio-bank' ), $order->get_order_number() ) : ( $sug ? sprintf( __( 'suggestion: order %s', 'payment-matcher-for-fio-bank' ), $sug->get_order_number() ) : __( 'no order', 'payment-matcher-for-fio-bank' ) );
			return '<li>' . wp_kses_post( wc_price( $r->amount ) ) . ' – ' . esc_html( trim( $r->counter_name ) ?: __( 'unknown sender', 'payment-matcher-for-fio-bank' ) ) . ', VS ' . esc_html( $r->vs ?: '–' ) . ' → ' . esc_html( $what ) . ' (' . esc_html( $labels[ $r->result ] ?? $r->result ) . ')</li>';
		};

		$body = '<p>' . esc_html__( 'Fio payments in the last 24 hours:', 'payment-matcher-for-fio-bank' ) . '</p>';
		foreach ( array( 'paid' => __( 'Matched and paid', 'payment-matcher-for-fio-bank' ), 'review' => __( 'To review', 'payment-matcher-for-fio-bank' ), 'other' => __( 'Other incoming payments', 'payment-matcher-for-fio-bank' ) ) as $key => $title ) {
			if ( $groups[ $key ] ) {
				$body .= '<h3>' . esc_html( $title ) . ' (' . count( $groups[ $key ] ) . ')</h3><ul>' . implode( '', array_map( $line, $groups[ $key ] ) ) . '</ul>';
			}
		}
		if ( $waiting ) {
			$body .= '<h3>' . esc_html__( 'Waiting for payment for more than 3 days', 'payment-matcher-for-fio-bank' ) . ' (' . count( $waiting ) . ')</h3><ul>';
			foreach ( $waiting as $o ) {
				$days  = (int) floor( ( time() - $o->get_date_created()->getTimestamp() ) / DAY_IN_SECONDS );
				/* translators: %d: number of days */
				$body .= '<li><a href="' . esc_url( $o->get_edit_order_url() ) . '">' . esc_html( $o->get_order_number() ) . '</a> – ' . wp_kses_post( wc_price( $o->get_total() ) ) . ', ' . esc_html( $o->get_formatted_billing_full_name() ) . ', ' . esc_html( sprintf( _n( '%d day', '%d days', $days, 'payment-matcher-for-fio-bank' ), $days ) ) . '</li>';
			}
			$body .= '</ul>';
		}
		$body .= '<p><a href="' . esc_url( admin_url( 'admin.php?page=payment-matcher-for-fio-bank' ) ) . '">' . esc_html__( 'Open Fio payments', 'payment-matcher-for-fio-bank' ) . '</a></p>';

		/* translators: %s: date */
		wp_mail( $to, sprintf( __( 'Fio payments – summary %s', 'payment-matcher-for-fio-bank' ), wp_date( get_option( 'date_format' ) ) ), $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/** Unpaid bank-transfer orders older than $days. */
	public static function waiting_orders( $days, $limit = 50 ) {
		$gateways = PMFIO_Settings::list_of( 'gateways' );
		if ( ! $gateways ) {
			return array();
		}
		return wc_get_orders(
			array(
				'limit'          => $limit,
				'type'           => 'shop_order',
				'status'         => array( 'wc-on-hold' ),
				'payment_method' => $gateways,
				'date_created'   => ( time() - 30 * DAY_IN_SECONDS ) . '...' . ( time() - (int) $days * DAY_IN_SECONDS ),
				'orderby'        => 'date',
				'order'          => 'ASC',
			)
		);
	}

	public static function send_reminders() {
		$mailer = WC()->mailer();
		$emails = $mailer->get_emails();
		$email  = $emails['PMFIO_Email_Reminder'] ?? null;
		if ( ! $email || ! $email->is_enabled() ) {
			return 0;
		}
		$days       = max( 1, (int) PMFIO_Settings::get( 'reminder_days' ) );
		$live_since = (int) get_option( 'pmfio_live_since', 0 );
		$gateways   = PMFIO_Settings::list_of( 'gateways' );
		$sent       = 0;

		foreach ( self::waiting_orders( $days, 100 ) as $order ) {
			if ( $sent >= self::REMINDERS_PER_RUN ) {
				break;
			}
			$info = array(
				'gateway'  => $order->get_payment_method(),
				'status'   => $order->get_status(),
				'created'  => $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0,
				'reminded' => (bool) $order->get_meta( '_pmfio_reminded' ),
			);
			if ( ! PMFIO_Matcher::needs_reminder( $info, $gateways, $days, $live_since, time() ) ) {
				continue;
			}
			$order->update_meta_data( '_pmfio_reminded', time() );
			$order->save();
			$email->trigger( $order->get_id(), $order );
			$order->add_order_note( __( 'Payment reminder sent to the customer.', 'payment-matcher-for-fio-bank' ) );
			$sent++;
		}
		return $sent;
	}

	private static function log( $level, $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, PMFIO_Api::mask( $message, PMFIO_Settings::token() ), array( 'source' => 'payment-matcher-for-fio-bank' ) );
		}
	}
}
