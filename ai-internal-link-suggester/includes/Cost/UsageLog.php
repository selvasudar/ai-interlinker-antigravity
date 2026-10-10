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
		$t      = Activator::tables();
		$groups = array(
			'day'   => 'DATE(created_at)',
			'task'  => 'task',
			'model' => "CONCAT(provider, ' — ', model)",
		);
		$expr   = $groups[ $by ] ?? 'task';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $expr is whitelisted.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$expr} AS label, COUNT(*) AS calls, SUM(input_tokens) AS input_tokens, SUM(output_tokens) AS output_tokens, SUM(est_cost_micros) AS cost FROM " . esc_sql( $t['usage'] ) . " WHERE created_at >= %s GROUP BY {$expr} ORDER BY label ASC", self::month_start() ), ARRAY_A );
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
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT r.run_id, r.type, r.status, r.total, r.est_low_micros, r.est_high_micros, r.created_at, COUNT(u.id) AS calls, COALESCE(SUM(u.est_cost_micros),0) AS actual, COALESCE(SUM(u.pre_cost_micros),0) AS pre, MIN(u.price_known) AS price_known, GROUP_CONCAT(DISTINCT u.model) AS models FROM ' . esc_sql( $t['runs'] ) . ' r LEFT JOIN ' . esc_sql( $t['usage'] ) . " u ON u.run_id = r.run_id WHERE r.type NOT IN ('index','auto') GROUP BY r.run_id ORDER BY r.created_at DESC LIMIT %d", (int) $limit ), ARRAY_A );
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
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . esc_sql( $t['usage'] ) . ' WHERE created_at >= %s ORDER BY id ASC', self::month_start() ), ARRAY_A );
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
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . esc_sql( $t['usage'] ) . ' WHERE created_at >= %s', self::month_start() ) );
	}
}
