<?php
/**
 * Content change hooks: only enqueue work, never do heavy work inline.
 *
 * @package AILSUG
 */

namespace AILSUG;

use AILSUG\Queue\Queue;
use AILSUG\Queue\Worker;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the local index fresh when content changes.
 */
final class Hooks {

	/**
	 * Posts touched during this request.
	 *
	 * @var int[]
	 */
	private static $touched = array();

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'save_post', array( __CLASS__, 'touch' ), 20, 2 );
		add_action( 'transition_post_status', array( __CLASS__, 'transition' ), 20, 3 );
		add_action( 'deleted_post', array( __CLASS__, 'touch' ), 20, 1 );
		add_action( 'shutdown', array( __CLASS__, 'flush' ) );
		add_action( 'ailsug_post_indexed', array( __CLASS__, 'maybe_auto_ai' ), 10, 3 );
		add_action( 'ailsug_cron_schedule', array( __CLASS__, 'scheduled_scan' ) );
		add_action( 'update_option_' . Settings::OPTION, array( __CLASS__, 'sync_schedule' ) );
		add_action( 'admin_init', array( __CLASS__, 'sync_schedule' ) );
	}

	/**
	 * Auto-update mode: after a content change, queue AI work for that page only.
	 * Runs through the gateway, so the budget cap and strict mode still apply.
	 *
	 * @param int    $post_id Post ID.
	 * @param array  $result  Index result.
	 * @param string $run_id  Run that indexed it.
	 * @return void
	 */
	public static function maybe_auto_ai( $post_id, $result, $run_id ) {
		if ( 'auto' !== Settings::get( 'update_mode' ) || empty( $result['content_changed'] ) ) {
			return;
		}
		$run = $run_id ? Queue::get_run( $run_id ) : null;
		if ( ! $run || 'auto' !== $run['type'] ) {
			return;
		}
		$jobs = array();
		if ( ! empty( $result['needs_ai'] ) ) {
			$jobs[] = array( 'keyword', $post_id );
		}
		$jobs[] = array( 'rank', $post_id );
		Queue::create_run( 'auto_ai', $jobs );
		Worker::schedule( 30 );
	}

	/**
	 * Keep the scheduled scan event in line with the setting.
	 *
	 * @return void
	 */
	public static function sync_schedule() {
		$want = (string) Settings::get( 'schedule', 'off' );
		$next = wp_next_scheduled( 'ailsug_cron_schedule' );
		$have = $next ? wp_get_schedule( 'ailsug_cron_schedule' ) : 'off';
		if ( $want === $have ) {
			return;
		}
		wp_clear_scheduled_hook( 'ailsug_cron_schedule' );
		if ( in_array( $want, array( 'daily', 'weekly' ), true ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, $want, 'ailsug_cron_schedule' );
		}
	}

	/**
	 * Scheduled scan of changed pages, limited to what fits the remaining budget.
	 *
	 * @return void
	 */
	public static function scheduled_scan() {
		$estimate = \AILSUG\Queue\Runs::estimate( 'scan' );
		if ( '' !== $estimate['blocker'] || 0 === $estimate['posts'] ) {
			\AILSUG\Logger::debug( 'Scheduled scan skipped', array( 'reason' => $estimate['blocker'] ? $estimate['blocker'] : 'nothing changed' ) );
			return;
		}
		\AILSUG\Queue\Runs::start_paid( 'scan', null, (int) $estimate['fits'] );
	}

	/**
	 * Remember a changed post (cheap).
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post.
	 * @return void
	 */
	public static function touch( $post_id, $post = null ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$post = $post ? $post : get_post( $post_id );
		if ( $post && ! in_array( $post->post_type, (array) Settings::get( 'post_types' ), true ) ) {
			return;
		}
		self::$touched[ (int) $post_id ] = (int) $post_id;
	}

	/**
	 * Status transitions (publish ↔ draft/trash) change eligibility.
	 *
	 * @param string   $new_status New.
	 * @param string   $old_status Old.
	 * @param \WP_Post $post       Post.
	 * @return void
	 */
	public static function transition( $new_status, $old_status, $post ) {
		if ( $new_status !== $old_status && $post instanceof \WP_Post ) {
			self::touch( $post->ID, $post );
		}
	}

	/**
	 * At shutdown, enqueue one cheap local re-index job per touched post.
	 *
	 * @return void
	 */
	public static function flush() {
		if ( ! self::$touched ) {
			return;
		}
		$jobs = array();
		foreach ( self::$touched as $id ) {
			$jobs[] = array( 'index', $id );
		}
		self::$touched = array();
		Queue::create_run( 'auto', $jobs );
		Worker::schedule( 5 );
	}
}
