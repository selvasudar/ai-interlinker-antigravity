<?php
/**
 * Lightweight job queue and run bookkeeping.
 *
 * @package AILSUG
 */

namespace AILSUG\Queue;

use AILSUG\Activator;

defined( 'ABSPATH' ) || exit;

/**
 * Jobs are claimed atomically with a conditional UPDATE so two workers never take the same job.
 */
final class Queue {

	const LOCK_SECONDS = 300;
	const MAX_ATTEMPTS = 3;

	/**
	 * Create a run and enqueue its jobs.
	 *
	 * @param string $type     Run type (index|scan|regenerate|keywords|auto).
	 * @param array  $jobs     List of [task, post_id] pairs.
	 * @param array  $estimate Optional ['low' => micros, 'high' => micros].
	 * @return string Run ID.
	 */
	public static function create_run( $type, array $jobs, array $estimate = array() ) {
		global $wpdb;
		$t      = Activator::tables();
		$run_id = wp_generate_uuid4();
		$now    = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Own table.
		$wpdb->insert(
			$t['runs'],
			array(
				'run_id'          => $run_id,
				'type'            => sanitize_key( $type ),
				'status'          => $jobs ? 'running' : 'done',
				'total'           => count( $jobs ),
				'est_low_micros'  => (int) ( $estimate['low'] ?? 0 ),
				'est_high_micros' => (int) ( $estimate['high'] ?? 0 ),
				'created_by'      => get_current_user_id(),
				'created_at'      => $now,
				'finished_at'     => $jobs ? null : $now,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s' )
		);

		self::add_jobs( $run_id, $jobs );
		return $run_id;
	}

	/**
	 * Insert jobs in chunks.
	 *
	 * @param string $run_id Run ID.
	 * @param array  $jobs   [task, post_id] pairs.
	 * @return void
	 */
	public static function add_jobs( $run_id, array $jobs ) {
		global $wpdb;
		$t   = Activator::tables();
		$now = current_time( 'mysql', true );
		foreach ( array_chunk( $jobs, 200 ) as $chunk ) {
			$values = array();
			$params = array();
			foreach ( $chunk as $job ) {
				$values[] = '(%s,%s,%d,%s,%s,%s)';
				array_push( $params, $run_id, sanitize_key( $job[0] ), (int) $job[1], 'pending', $now, $now );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $values only holds placeholder groups; data goes through prepare().
			$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . esc_sql( $t['queue'] ) . ' (run_id, task, post_id, status, created_at, updated_at) VALUES ' . implode( ',', $values ), $params ) );
		}
	}

	/**
	 * Atomically claim up to $limit jobs.
	 *
	 * @param int         $limit  Max jobs.
	 * @param string|null $run_id Only from this run.
	 * @return array[] Claimed rows (ARRAY_A).
	 */
	public static function claim( $limit, $run_id = null ) {
		global $wpdb;
		$t     = Activator::tables();
		$token = wp_generate_password( 24, false, false );
		$now   = current_time( 'mysql', true );
		$until = gmdate( 'Y-m-d H:i:s', time() + self::LOCK_SECONDS );

		$run_sql = '';
		$params  = array( $token, $until, $now, $now );
		if ( null !== $run_id ) {
			$run_sql  = ' AND run_id = %s';
			$params[] = (string) $run_id;
		}
		$params[] = max( 1, (int) $limit );

		// Pending jobs, or running jobs whose lock expired (worker died).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $run_sql is a fixed placeholder fragment; params are built to match.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . esc_sql( $t['queue'] ) . " SET status = 'running', claim_token = %s, locked_until = %s, attempts = attempts + 1, updated_at = %s WHERE ( status = 'pending' OR ( status = 'running' AND locked_until < %s ) ){$run_sql} ORDER BY id ASC LIMIT %d", $params ) );

		if ( ! $wpdb->rows_affected ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . esc_sql( $t['queue'] ) . " WHERE claim_token = %s AND status = 'running' ORDER BY id ASC", $token ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Mark a job done.
	 *
	 * @param int $id Job ID.
	 * @return void
	 */
	public static function complete( $id ) {
		self::set( $id, 'done', '' );
	}

	/**
	 * Record a failure; retry until MAX_ATTEMPTS.
	 *
	 * @param array  $job     Job row.
	 * @param string $message Error message (no content).
	 * @param bool   $retry   Whether a retry may help.
	 * @return void
	 */
	public static function fail( array $job, $message, $retry = true ) {
		$status = ( $retry && (int) $job['attempts'] < self::MAX_ATTEMPTS ) ? 'pending' : 'failed';
		self::set( (int) $job['id'], $status, $message );
	}

	/**
	 * Put a job on hold without counting the attempt (e.g. budget reached).
	 *
	 * @param array  $job     Job row.
	 * @param string $status  Hold status (budget).
	 * @param string $message Message.
	 * @return void
	 */
	public static function hold( array $job, $status, $message ) {
		global $wpdb;
		$t = Activator::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . esc_sql( $t['queue'] ) . " SET status = %s, last_error = %s, attempts = GREATEST(attempts - 1, 0), claim_token = '', locked_until = NULL, updated_at = %s WHERE id = %d", sanitize_key( $status ), self::short( $message ), current_time( 'mysql', true ), (int) $job['id'] ) );
	}

	/**
	 * Release jobs held for budget so they run again (cap raised or new month).
	 *
	 * @return int Rows released.
	 */
	public static function release_held() {
		global $wpdb;
		$t = Activator::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		return (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . esc_sql( $t['queue'] ) . " SET status = 'pending', updated_at = %s WHERE status = 'budget'", current_time( 'mysql', true ) ) );
	}

	/**
	 * Update one job.
	 *
	 * @param int    $id      Job ID.
	 * @param string $status  Status.
	 * @param string $message Message.
	 * @return void
	 */
	private static function set( $id, $status, $message ) {
		global $wpdb;
		$t = Activator::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->update(
			$t['queue'],
			array(
				'status'       => $status,
				'last_error'   => self::short( $message ),
				'claim_token'  => '',
				'locked_until' => null,
				'updated_at'   => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s', '%s', null, '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Truncate a message for storage.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	private static function short( $message ) {
		$message = sanitize_text_field( (string) $message );
		return function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 250 ) : substr( $message, 0, 250 );
	}

	/**
	 * Run row.
	 *
	 * @param string $run_id Run ID.
	 * @return array|null
	 */
	public static function get_run( $run_id ) {
		global $wpdb;
		$t = Activator::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . esc_sql( $t['runs'] ) . ' WHERE run_id = %s', (string) $run_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Progress for a run.
	 *
	 * @param string $run_id Run ID.
	 * @return array|null
	 */
	public static function progress( $run_id ) {
		global $wpdb;
		$run = self::get_run( $run_id );
		if ( ! $run ) {
			return null;
		}
		$t = Activator::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM ' . esc_sql( $t['queue'] ) . ' WHERE run_id = %s GROUP BY status', (string) $run_id ), OBJECT_K );
		$counts = array_fill_keys( array( 'pending', 'running', 'done', 'failed', 'paused', 'budget', 'cancelled' ), 0 );
		foreach ( (array) $rows as $status => $row ) {
			$counts[ $status ] = (int) $row->n;
		}
		$total = array_sum( $counts );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$spent = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(est_cost_micros),0) FROM ' . esc_sql( $t['usage'] ) . ' WHERE run_id = %s', (string) $run_id ) );

		$finished = 0 === $counts['pending'] + $counts['running'] + $counts['paused'] + $counts['budget'];
		if ( $finished && 'running' === $run['status'] ) {
			self::set_run_status( $run_id, 'done' );
			$run['status'] = 'done';
		}
		if ( 'running' === $run['status'] && $counts['budget'] > 0 && 0 === $counts['pending'] + $counts['running'] ) {
			$run['status'] = 'budget';
		}

		return array(
			'run_id'          => $run['run_id'],
			'type'            => $run['type'],
			'status'          => $run['status'],
			'total'           => $total,
			'processed'       => $counts['done'] + $counts['failed'],
			'counts'          => $counts,
			'spent_micros'    => $spent,
			'est_low_micros'  => (int) $run['est_low_micros'],
			'est_high_micros' => (int) $run['est_high_micros'],
			'created_at'      => $run['created_at'],
		);
	}

	/**
	 * Change a run's status (pause/resume/cancel/done).
	 *
	 * @param string $run_id Run ID.
	 * @param string $status running|paused|cancelled|done.
	 * @return void
	 */
	public static function set_run_status( $run_id, $status ) {
		global $wpdb;
		$t   = Activator::tables();
		$now = current_time( 'mysql', true );
		$map = array(
			'paused'    => array( 'pending', 'paused' ),
			'running'   => array( 'paused', 'pending' ),
			'cancelled' => array( 'pending', 'cancelled' ),
		);
		if ( isset( $map[ $status ] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . esc_sql( $t['queue'] ) . ' SET status = %s, updated_at = %s WHERE run_id = %s AND status = %s', $map[ $status ][1], $now, (string) $run_id, $map[ $status ][0] ) );
			if ( 'cancelled' === $status ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . esc_sql( $t['queue'] ) . " SET status = 'cancelled', updated_at = %s WHERE run_id = %s AND status IN ('paused','budget')", $now, (string) $run_id ) );
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->update(
			$t['runs'],
			array(
				'status'      => $status,
				'finished_at' => in_array( $status, array( 'done', 'cancelled' ), true ) ? $now : null,
			),
			array( 'run_id' => (string) $run_id ),
			array( '%s', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Whether any job is waiting.
	 *
	 * @return bool
	 */
	public static function has_pending() {
		global $wpdb;
		$t = Activator::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . esc_sql( $t['queue'] ) . " WHERE status = 'pending' OR ( status = 'running' AND locked_until < %s ) LIMIT 1", current_time( 'mysql', true ) ) );
	}

	/**
	 * Most recent active run of a type (to resume progress display).
	 *
	 * @param string[] $types Types.
	 * @return array|null Progress.
	 */
	public static function active_run( array $types ) {
		global $wpdb;
		$t     = Activator::tables();
		$types = array_map( 'sanitize_key', $types );
		if ( ! $types ) {
			return null;
		}
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders built above.
		$run_id = $wpdb->get_var( $wpdb->prepare( 'SELECT run_id FROM ' . esc_sql( $t['runs'] ) . " WHERE status IN ('running','paused') AND type IN ({$placeholders}) ORDER BY created_at DESC LIMIT 1", $types ) );
		return $run_id ? self::progress( $run_id ) : null;
	}

	/**
	 * Delete finished jobs older than a week.
	 *
	 * @return void
	 */
	public static function purge_old() {
		global $wpdb;
		$t = Activator::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . esc_sql( $t['queue'] ) . " WHERE status IN ('done','cancelled','failed') AND updated_at < %s", gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS ) ) );
	}
}
