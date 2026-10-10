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
