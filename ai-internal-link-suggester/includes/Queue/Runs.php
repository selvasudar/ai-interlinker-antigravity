<?php
/**
 * Starting runs of different kinds.
 *
 * @package AILSUG
 */

namespace AILSUG\Queue;

use AILSUG\Index\Eligibility;
use AILSUG\Index\Indexer;

defined( 'ABSPATH' ) || exit;

/**
 * Creates runs (sets of queued jobs) for the admin screens and REST API.
 */
final class Runs {

	/**
	 * Start a full local index run (free, no AI). Also prunes pages that left the index.
	 *
	 * @param string[]|null $post_types Optional subset of post types.
	 * @return string Run ID.
	 */
	public static function start_index( $post_types = null ) {
		$ids = Eligibility::post_ids( $post_types );
		if ( null === $post_types ) {
			Indexer::prune( Eligibility::post_ids() );
		}
		$jobs = array();
		foreach ( $ids as $id ) {
			$jobs[] = array( 'index', $id );
		}
		$run_id = Queue::create_run( 'index', $jobs );
		Worker::schedule();
		return $run_id;
	}
}
