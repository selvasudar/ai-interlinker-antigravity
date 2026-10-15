<?php
/**
 * AI usage log: one row per AI call, with estimated and actual tokens/cost.
 *
 * @package AILSUG
 */

namespace AILSUG\Cost;

use AILSUG\Activator;

defined( 'ABSPATH' ) || exit;

/**
 * Writes and summarizes the usage table.
 */
final class UsageLog {

	/**
	 * Log one call.
	 *
	 * @param array $row Columns.
	 * @return int Row ID.
	 */
	public static function log( array $row ) {
		global $wpdb;
		$t    = Activator::tables();
		$data = array(
			'run_id'            => (string) ( $row['run_id'] ?? '' ),
			'task'              => (string) ( $row['task'] ?? '' ),
			'provider'          => substr( (string) ( $row['provider'] ?? '' ), 0, 64 ),
			'model'             => substr( (string) ( $row['model'] ?? '' ), 0, 128 ),
			'requested_model'   => substr( (string) ( $row['requested_model'] ?? '' ), 0, 128 ),
			'input_tokens'      => (int) ( $row['input_tokens'] ?? 0 ),
			'output_tokens'     => (int) ( $row['output_tokens'] ?? 0 ),
			'est_input_tokens'  => (int) ( $row['est_input_tokens'] ?? 0 ),
			'est_output_tokens' => (int) ( $row['est_output_tokens'] ?? 0 ),
			'pre_cost_micros'   => (int) ( $row['pre_cost_micros'] ?? 0 ),
			'est_cost_micros'   => (int) ( $row['est_cost_micros'] ?? 0 ),
			'is_estimate'       => empty( $row['is_estimate'] ) ? 0 : 1,
			'price_known'       => empty( $row['price_known'] ) ? 0 : 1,
			'success'           => isset( $row['success'] ) && ! $row['success'] ? 0 : 1,
			'post_id'           => (int) ( $row['post_id'] ?? 0 ),
			'created_at'        => current_time( 'mysql', true ),
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Own table.
		$wpdb->insert( $t['usage'], $data, array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s' ) );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Total incoming and outgoing tokens for the current month.
	 *
	 * @return array{input_tokens:int,output_tokens:int,calls:int}
	 */
	public static function month_tokens() {
		global $wpdb;
		$t   = Activator::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS calls, COALESCE(SUM(input_tokens), 0) AS input_tokens, COALESCE(SUM(output_tokens), 0) AS output_tokens FROM %i WHERE created_at >= %s', $t['usage'], self::month_start() ), ARRAY_A );
		return array(
			'calls'         => (int) ( $row['calls'] ?? 0 ),
			'input_tokens'  => (int) ( $row['input_tokens'] ?? 0 ),
			'output_tokens' => (int) ( $row['output_tokens'] ?? 0 ),
		);
	}

	/**
	 * Start of the current UTC month.
	 *
	 * @return string
	 */
	private static function month_start() {
		return gmdate( 'Y-m-01 00:00:00' );
	}

	/**
	 * Spend grouped by day, task or model for this month.
	 *
	 * @param string $by day|task|model.
	 * @return array[]
	 */
	public static function summary( $by ) {
		global $wpdb;
		$t = Activator::tables();
		switch ( $by ) {
			case 'day':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(created_at) AS label, COUNT(*) AS calls, SUM(input_tokens) AS input_tokens, SUM(output_tokens) AS output_tokens, SUM(est_cost_micros) AS cost FROM %i WHERE created_at >= %s GROUP BY DATE(created_at) ORDER BY label ASC", $t['usage'], self::month_start() ), ARRAY_A );
				break;
			case 'model':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT CONCAT(provider, ' — ', model) AS label, COUNT(*) AS calls, SUM(input_tokens) AS input_tokens, SUM(output_tokens) AS output_tokens, SUM(est_cost_micros) AS cost FROM %i WHERE created_at >= %s GROUP BY CONCAT(provider, ' — ', model) ORDER BY label ASC", $t['usage'], self::month_start() ), ARRAY_A );
				break;
			case 'task':
			default:
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT task AS label, COUNT(*) AS calls, SUM(input_tokens) AS input_tokens, SUM(output_tokens) AS output_tokens, SUM(est_cost_micros) AS cost FROM %i WHERE created_at >= %s GROUP BY task ORDER BY label ASC", $t['usage'], self::month_start() ), ARRAY_A );
				break;
		}
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Recent runs with estimated (before) vs actual (after) cost.
	 *
	 * @param int $limit Rows.
	 * @return array[]
	 */
	public static function recent_runs( $limit = 10 ) {
		global $wpdb;
		$t = Activator::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own tables.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT r.run_id, r.type, r.status, r.total, r.est_low_micros, r.est_high_micros, r.created_at, COUNT(u.id) AS calls, COALESCE(SUM(u.est_cost_micros),0) AS actual, COALESCE(SUM(u.pre_cost_micros),0) AS pre, MIN(u.price_known) AS price_known, GROUP_CONCAT(DISTINCT u.model) AS models FROM %i r LEFT JOIN %i u ON u.run_id = r.run_id WHERE r.type NOT IN ('index','auto') GROUP BY r.run_id ORDER BY r.created_at DESC LIMIT %d", $t['runs'], $t['usage'], (int) $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * All rows of this month (CSV export).
	 *
	 * @return array[]
	 */
	public static function month_rows() {
		global $wpdb;
		$t = Activator::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE created_at >= %s ORDER BY id ASC', $t['usage'], self::month_start() ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Delete this month's rows (reset counters).
	 *
	 * @return void
	 */
	public static function reset_month() {
		global $wpdb;
		$t = Activator::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at >= %s', $t['usage'], self::month_start() ) );
	}
}
