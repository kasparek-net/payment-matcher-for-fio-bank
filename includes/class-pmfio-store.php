<?php
/**
 * Movement storage (custom table).
 *
 * @package PaymentMatcherForFioBank
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom table owned by this plugin, no WP API exists for it.
class PMFIO_Store {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'pmfio_movements';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta() cannot take placeholders; table name is \$wpdb->prefix + constant.
		dbDelta( "CREATE TABLE {$table} (
			movement_id varchar(32) NOT NULL,
			moved_on date DEFAULT NULL,
			amount decimal(14,2) NOT NULL DEFAULT 0,
			currency varchar(3) NOT NULL DEFAULT '',
			vs varchar(20) NOT NULL DEFAULT '',
			counter_account varchar(64) NOT NULL DEFAULT '',
			counter_name varchar(255) NOT NULL DEFAULT '',
			message varchar(255) NOT NULL DEFAULT '',
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			suggested_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			result varchar(20) NOT NULL DEFAULT '',
			created datetime NOT NULL,
			PRIMARY KEY  (movement_id),
			KEY order_id (order_id),
			KEY created (created)
		) {$charset};" );
		update_option( 'pmfio_db_version', PMFIO_VERSION, false );
	}

	/**
	 * In live mode rows saved while observing do not count, so they get processed for real.
	 */
	public static function exists( $movement_id, $live ) {
		global $wpdb;
		$table = self::table();
		if ( $live ) {
			return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM %i WHERE movement_id = %s AND result NOT LIKE %s", $table, $movement_id, $wpdb->esc_like( 'obs_' ) . '%' ) );
		}
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM %i WHERE movement_id = %s", $table, $movement_id ) );
	}

	public static function insert( array $m, $order_id, $result, $suggested_id = 0 ) {
		global $wpdb;
		$wpdb->replace(
			self::table(),
			array(
				'movement_id'        => $m['id'],
				'moved_on'           => $m['date'],
				'amount'             => $m['amount'],
				'currency'           => $m['currency'],
				'vs'                 => $m['vs'],
				'counter_account'    => $m['account'],
				'counter_name'       => mb_substr( $m['name'], 0, 255 ),
				'message'            => mb_substr( $m['message'], 0, 255 ),
				'order_id'           => (int) $order_id,
				'suggested_order_id' => (int) $suggested_id,
				'result'             => $result,
				'created'            => current_time( 'mysql' ),
			)
		);
	}

	public static function get( $movement_id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE movement_id = %s", $table, $movement_id ) );
	}

	/** Row object -> movement array as produced by PMFIO_Matcher::normalize(). */
	public static function to_movement( $row ) {
		return array(
			'id'       => (string) $row->movement_id,
			'date'     => $row->moved_on,
			'amount'   => (float) $row->amount,
			'currency' => (string) $row->currency,
			'vs'       => (string) $row->vs,
			'account'  => (string) $row->counter_account,
			'name'     => (string) $row->counter_name,
			'message'  => (string) $row->message,
			'user_id'  => '',
		);
	}

	public static function paid_sum( $order_id, array $results ) {
		global $wpdb;
		$table = self::table();
		$in    = implode( ',', array_fill( 0, count( $results ), '%s' ) );
		$args  = array_merge( array( $table, (int) $order_id ), $results );
		return (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM %i WHERE order_id = %d AND result IN ({$in})", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is a list of %s placeholders, values are passed as one array.
	}

	/**
	 * Result groups used by the admin filters and overview.
	 */
	public static function groups() {
		return array(
			'open'    => array( 'review', 'partial', 'no_order' ),
			'paid'    => array( 'matched', 'overpaid' ),
			'nomatch' => array( 'no_order', 'obs_no_order' ),
		);
	}

	public static function latest( $limit = 100, $group = '' ) {
		global $wpdb;
		$table  = self::table();
		$groups = self::groups();
		if ( isset( $groups[ $group ] ) ) {
			$in   = implode( ',', array_fill( 0, count( $groups[ $group ] ), '%s' ) );
			$args = array_merge( array( $table ), $groups[ $group ], array( $limit ) );
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE result IN ({$in}) ORDER BY moved_on DESC, created DESC LIMIT %d", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is a list of %s placeholders, values are passed as one array.
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE result <> %s ORDER BY moved_on DESC, created DESC LIMIT %d", $table, 'outgoing', $limit ) );
	}

	/**
	 * @return array{count:int,sum:float}
	 */
	public static function stats( array $results, $since_date ) {
		global $wpdb;
		$table = self::table();
		$in    = implode( ',', array_fill( 0, count( $results ), '%s' ) );
		$args  = array_merge( array( $table ), $results, array( $since_date ) );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS c, COALESCE(SUM(amount),0) AS s FROM %i WHERE result IN ({$in}) AND moved_on >= %s", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is a list of %s placeholders, values are passed as one array.
		return array( 'count' => $row ? (int) $row->c : 0, 'sum' => $row ? (float) $row->s : 0.0 );
	}

	public static function for_order( $order_id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE order_id = %d ORDER BY moved_on ASC", $table, (int) $order_id ) );
	}

	public static function created_since( $mysql_datetime ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE created >= %s ORDER BY moved_on ASC", $table, $mysql_datetime ) );
	}

	/**
	 * Movements assigned to any of the given orders (privacy exporter / eraser).
	 * Suggestions are only guesses and may belong to someone else, so they are never included.
	 */
	public static function for_orders( array $order_ids ) {
		global $wpdb;
		$order_ids = array_values( array_filter( array_map( 'absint', $order_ids ) ) );
		if ( ! $order_ids ) {
			return array();
		}
		$in = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE order_id IN ({$in}) ORDER BY moved_on ASC", array_merge( array( self::table() ), $order_ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is a list of %d placeholders, values are passed as one array.
	}

	/** Removes the payer's personal data, keeps amounts and dates for bookkeeping. */
	public static function anonymize( $movement_id ) {
		global $wpdb;
		return (bool) $wpdb->update(
			self::table(),
			array(
				'counter_name'    => '',
				'counter_account' => '',
				'message'         => '',
			),
			array( 'movement_id' => $movement_id )
		);
	}

	/** Deletes movements older than the given date (Y-m-d). Returns the number of deleted rows. */
	public static function delete_before( $date ) {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE moved_on < %s', self::table(), $date ) );
	}

	/**
	 * When switching to live, movements seen while observing were handled by hand
	 * (or by another plugin), so they must not be processed again.
	 */
	public static function mark_observed_seen() {
		global $wpdb;
		$table = self::table();
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET result = 'seen' WHERE result LIKE %s", $table, $wpdb->esc_like( 'obs_' ) . '%' ) );
	}
}
// phpcs:enable
