<?php
/**
 * Plugin Name: Payment Matcher for Fio Bank
 * Plugin URI: https://wp.kasparek.app/fio
 * Description: Downloads incoming payments from the Fio banka API and marks bank-transfer orders as paid by variable symbol. Manual assignment, payment reminders, daily summary, token health alerts, multiple accounts and an optional QR payment code (QR Platba, PAY by square).
 * Version: 1.8.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: Jakub Kašpárek
 * Author URI: https://github.com/kasparek-net
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: payment-matcher-for-fio-bank
 * Domain Path: /languages
 * WC requires at least: 8.0
 * WC tested up to: 11.3
 *
 * @package PaymentMatcherForFioBank
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PMFIO_VERSION', '1.8.0' );
define( 'PMFIO_PLUGIN_FILE', __FILE__ );
define( 'PMFIO_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'PMFIO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PMFIO_DONATE_URL', 'https://wp.kasparek.app/fio#podpora' );

require_once PMFIO_PLUGIN_PATH . 'includes/class-pmfio-settings.php';
require_once PMFIO_PLUGIN_PATH . 'includes/class-pmfio-api.php';
require_once PMFIO_PLUGIN_PATH . 'includes/class-pmfio-store.php';
require_once PMFIO_PLUGIN_PATH . 'includes/class-pmfio-matcher.php';
require_once PMFIO_PLUGIN_PATH . 'includes/class-pmfio-runner.php';
require_once PMFIO_PLUGIN_PATH . 'includes/class-pmfio-qr-code.php';
require_once PMFIO_PLUGIN_PATH . 'includes/class-pmfio-pay-by-square.php';
require_once PMFIO_PLUGIN_PATH . 'includes/class-pmfio-qr-payment.php';
require_once PMFIO_PLUGIN_PATH . 'includes/class-pmfio-privacy.php';
require_once PMFIO_PLUGIN_PATH . 'includes/class-pmfio-status.php';
require_once PMFIO_PLUGIN_PATH . 'includes/class-pmfio-cron.php';
require_once PMFIO_PLUGIN_PATH . 'includes/class-pmfio-admin.php';

/**
 * The Fio banka API serves Czech and Slovak accounts only, so the plugin speaks
 * Czech everywhere (Slovak on Slovak sites), whatever the site or language packs say.
 *
 * @param string $locale Active locale; the translation is stored under it.
 */
function pmfio_load_translation( $locale = '' ) {
	$locale = $locale ? $locale : determine_locale();
	$lang   = 0 === strpos( $locale, 'sk' ) ? 'sk_SK' : 'cs_CZ';
	unload_textdomain( 'payment-matcher-for-fio-bank', true );
	load_textdomain( 'payment-matcher-for-fio-bank', PMFIO_PLUGIN_PATH . 'languages/payment-matcher-for-fio-bank-' . $lang . '.mo', $locale );
}
add_action( 'init', 'pmfio_load_translation', 0 );
add_action( 'change_locale', 'pmfio_load_translation' );

register_activation_hook( __FILE__, array( 'PMFIO_Store', 'install' ) );
register_deactivation_hook( __FILE__, array( 'PMFIO_Runner', 'unschedule' ) );

/**
 * HPOS compatibility.
 */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}
	if ( get_option( 'pmfio_db_version' ) !== PMFIO_VERSION ) {
		PMFIO_Store::install();
	}
	PMFIO_Runner::init();
	PMFIO_QR_Payment::init();
	PMFIO_Privacy::init();
	PMFIO_Status::init();
	PMFIO_Cron::init();
	if ( is_admin() ) {
		PMFIO_Admin::init();
	}
} );

add_filter( 'woocommerce_email_classes', function ( $emails ) {
	require_once PMFIO_PLUGIN_PATH . 'includes/class-pmfio-email-reminder.php';
	$emails['PMFIO_Email_Reminder'] = new PMFIO_Email_Reminder();
	return $emails;
} );

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=payment-matcher-for-fio-bank&tab=settings' ) ) . '">' . esc_html__( 'Settings', 'payment-matcher-for-fio-bank' ) . '</a>' );
	$links[] = '<a href="' . esc_url( PMFIO_DONATE_URL ) . '" target="_blank" rel="noopener">' . esc_html__( 'Support development', 'payment-matcher-for-fio-bank' ) . ' &#9829;</a>';
	return $links;
} );
