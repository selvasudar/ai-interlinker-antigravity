<?php
/**
 * Processes queued jobs in small batches (WP-Cron or the "Run now" REST loop).
 *
 * @package AILSUG
 */

namespace AILSUG\Queue;

use AILSUG\Index\Indexer;
use AILSUG\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Small, time-boxed batches. Never runs on page load or inside save_post.
 */
final class Worker {

	const TICK = 'ailsug_cron_tick';

	/**
	 * Register cron hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( self::TICK, array( __CLASS__, 'cron_tick' ) );
		add_action( 'ailsug_cron_cleanup', array( __CLASS__, 'cleanup' ) );
		add_action( 'admin_init', array( __CLASS__, 'ensure_cleanup_scheduled' ) );
	}

	/**
	 * Daily housekeeping.
	 *
	 * @return void
	 */
	public static function cleanup() {
		Queue::purge_old();
		\AILSUG\Cost\Budget::release_stale();
		if ( get_option( 'ailsug_budget_period' ) !== \AILSUG\Cost\Budget::period() ) {
			update_option( 'ailsug_budget_period', \AILSUG\Cost\Budget::period(), false );
			\AILSUG\Cost\Budget::on_change();
		}

		/**
		 * Fires during daily housekeeping.
		 */
		do_action( 'ailsug_daily_cleanup' );
	}

	/**
	 * Make sure the daily cleanup event exists.
	 *
	 * @return void
	 */
	public static function ensure_cleanup_scheduled() {
		if ( ! wp_next_scheduled( 'ailsug_cron_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ailsug_cron_cleanup' );
		}
	}

	/**
	 * Task handlers: task => callable( int $post_id, string $run_id ): true|WP_Error.
	 *
	 * Return a WP_Error with data ['hold' => 'budget'] to park the job, or ['retry' => false] to fail permanently.
	 *
	 * @return array<string,callable>
	 */
	public static function handlers() {
		$handlers = array(
			'index'   => array( Indexer::class, 'task' ),
			'keyword' => array( \AILSUG\Index\KeywordAi::class, 'task' ),
			'rank'    => array( \AILSUG\Suggest\Ranker::class, 'task' ),
			'rerank'  => array( \AILSUG\Suggest\Ranker::class, 'task_force' ),
		);

		/**
		 * Filters the queue task handlers.
		 *
		 * @param array<string,callable> $handlers Handlers.
		 */
		return (array) apply_filters( 'ailsug_task_handlers', $handlers );
	}

	/**
	 * Process up to $limit jobs.
	 *
	 * @param int         $limit   Max jobs.
	 * @param string|null $run_id  Only this run.
	 * @param int         $seconds Soft time limit.
	 * @return int Jobs processed.
	 */
	public static function process( $limit, $run_id = null, $seconds = 20 ) {
		$start    = microtime( true );
		$handlers = self::handlers();
		$jobs     = Queue::claim( $limit, $run_id );
		$count    = 0;

		foreach ( $jobs as $index => $job ) {
			if ( microtime( true ) - $start > $seconds ) {
				// Out of time: give the rest back untouched.
				foreach ( array_slice( $jobs, $index ) as $left ) {
					Queue::hold( $left, 'pending', '' );
				}
				break;
			}

			$task = (string) $job['task'];
			if ( ! isset( $handlers[ $task ] ) || ! is_callable( $handlers[ $task ] ) ) {
				Queue::fail( $job, 'Unknown task.', false );
				continue;
			}

			try {
				$result = call_user_func( $handlers[ $task ], (int) $job['post_id'], (string) $job['run_id'] );
			} catch ( \Throwable $e ) {
				$result = new \WP_Error( 'ailsug_exception', 'Unexpected error while processing.' );
			}

			if ( is_wp_error( $result ) ) {
				$data = (array) $result->get_error_data();
				if ( ! empty( $data['hold'] ) ) {
					Queue::hold( $job, (string) $data['hold'], $result->get_error_message() );
				} else {
					Queue::fail( $job, $result->get_error_message(), ! isset( $data['retry'] ) || $data['retry'] );
				}
			} else {
				Queue::complete( (int) $job['id'] );
			}
			++$count;
		}//end foreach
		return $count;
	}

	/**
	 * Cron tick: process one batch and reschedule while work remains.
	 *
	 * @return void
	 */
	public static function cron_tick() {
		self::process( (int) Settings::get( 'batch_size', 5 ) );
		self::schedule();
	}

	/**
	 * Schedule the next tick if jobs are waiting.
	 *
	 * @param int $delay Seconds.
	 * @return void
	 */
	public static function schedule( $delay = 15 ) {
		if ( Queue::has_pending() && ! wp_next_scheduled( self::TICK ) ) {
			wp_schedule_single_event( time() + $delay, self::TICK );
		}
	}
}
