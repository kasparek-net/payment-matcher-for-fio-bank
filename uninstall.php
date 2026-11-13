<?php
/**
 * Removes all plugin data.
 *
 * @package PaymentMatcherForFioBank
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'pmfio_movements' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- dropping the plugin's own table on uninstall.
foreach ( array( 'pmfio_settings', 'pmfio_token', 'pmfio_db_version', 'pmfio_last_run', 'pmfio_last_error', 'pmfio_alert_sent', 'pmfio_live_since', 'pmfio_token_saved', 'pmfio_expiry_alert' ) as $pmfio_option ) {
	delete_option( $pmfio_option );
}
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'pmfio_check', array(), 'payment-matcher-for-fio-bank' );
	as_unschedule_all_actions( 'pmfio_daily', array(), 'payment-matcher-for-fio-bank' );
}
