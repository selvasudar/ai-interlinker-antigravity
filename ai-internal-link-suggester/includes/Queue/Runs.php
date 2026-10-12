<?php
/**
 * Starting runs of different kinds.
 *
 * @package AILSUG
 */

namespace AILSUG\Queue;

use AILSUG\AI\Availability;
use AILSUG\Cost\Budget;
use AILSUG\Cost\Estimator;
use AILSUG\Index\Eligibility;
use AILSUG\Index\Indexer;
use AILSUG\Index\KeywordAi;
use AILSUG\Settings;
use WP_Error;

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

	/**
	 * Paid run types and the AI tasks they include.
	 *
	 * @return array<string,string[]>
	 */
	public static function paid_types() {
		/**
		 * Filters paid (AI) run types => tasks.
		 *
		 * @param array<string,string[]> $types Types.
		 */
		return (array) apply_filters(
			'ailsug_paid_run_types',
			array(
				'keywords'   => array( 'keyword' ),
				'scan'       => array( 'keyword', 'rank' ),
				'regenerate' => array( 'keyword', 'rerank' ),
			)
		);
	}

	/**
	 * Which posts a paid run would process, with what it needs.
	 *
	 * @param string        $type       Run type.
	 * @param string[]|null $post_types Subset of post types.
	 * @param int[]|null    $post_ids   Explicit posts (regenerate).
	 * @return array<int,array{words:int,keyword:bool,rank:bool}> post_id => needs.
	 */
	public static function selection( $type, $post_types = null, $post_ids = null ) {
		$ids = is_array( $post_ids ) ? array_values( array_filter( array_map( 'intval', $post_ids ), array( Eligibility::class, 'is_eligible' ) ) ) : Eligibility::post_ids( $post_types );
		$out = array();
		foreach ( $ids as $id ) {
			$row     = Indexer::get_row( $id );
			$keyword = KeywordAi::needs_ai( $id );
			$rank    = false;
			if ( 'regenerate' === $type ) {
				$rank = true;
			} elseif ( 'scan' === $type ) {
				$post = get_post( $id );
				$rank = ! $row || ! $post || \AILSUG\Index\Collector::content_hash( $post->post_content ) !== $row['rank_hash'];
			}
			$item = array(
				'words'   => $row ? (int) $row['word_count'] : 800,
				'keyword' => $keyword,
				'rank'    => $rank,
			);

			/**
			 * Filters what a paid run needs to do for one post.
			 *
			 * @param array  $item Needs: words, keyword, rank.
			 * @param string $type Run type.
			 * @param int    $id   Post ID.
			 */
			$item = (array) apply_filters( 'ailsug_run_selection_item', $item, $type, $id );
			if ( ! empty( $item['keyword'] ) || ! empty( $item['rank'] ) ) {
				$out[ $id ] = $item;
			}
		}//end foreach
		return $out;
	}

	/**
	 * Estimate a paid run before it starts (no AI calls).
	 *
	 * @param string        $type       Run type.
	 * @param string[]|null $post_types Subset.
	 * @param int[]|null    $post_ids   Explicit posts.
	 * @return array
	 */
	public static function estimate( $type, $post_types = null, $post_ids = null ) {
		$selection = self::selection( $type, $post_types, $post_ids );
		$estimate  = Estimator::for_run( array_values( $selection ) );
		$budget    = Budget::status();
		$status    = Availability::status();
		$strict    = (bool) Settings::get( 'strict_budget' ) && Budget::cap() > 0;

		$fits = count( $selection );
		if ( null !== $budget['remaining'] && $estimate['worst'] && $estimate['worst'] > $budget['remaining'] ) {
			$sum  = 0;
			$fits = 0;
			foreach ( $estimate['per_post_worst'] as $cost ) {
				if ( $sum + $cost > $budget['remaining'] ) {
					break;
				}
				$sum += $cost;
				++$fits;
			}
		}

		$blocker = '';
		if ( empty( $status['available'] ) ) {
			$blocker = $status['message'];
		} elseif ( $strict && ( ! $estimate['known'] || $estimate['unknown'] ) ) {
			$blocker = __( 'The price of the AI model that may answer is unknown, so the budget cannot be enforced. Enter its price below the settings on the AI & Cost tab.', 'interlink-ai' );
		} elseif ( $budget['reached'] ) {
			$blocker = __( 'The estimated monthly AI budget is reached. Raise the cap or wait until next month.', 'interlink-ai' );
		} elseif ( 0 === $fits && $estimate['posts'] > 0 ) {
			$blocker = __( 'Not even one page fits in the remaining monthly budget.', 'interlink-ai' );
		}

		unset( $estimate['per_post_worst'] );
		return array_merge(
			$estimate,
			array(
				'type'          => $type,
				'remaining'     => $budget['remaining'],
				'cap'           => $budget['cap'],
				'fits'          => $fits,
				'needs_confirm' => null === $estimate['high'] || $estimate['high'] >= (int) Settings::get( 'confirm_micros', 0 ),
				'blocker'       => $blocker,
				'keyword_posts' => count( array_filter( wp_list_pluck( $selection, 'keyword' ) ) ),
				'rank_posts'    => count( array_filter( wp_list_pluck( $selection, 'rank' ) ) ),
			)
		);
	}

	/**
	 * Start a paid run after the user confirmed the estimate.
	 *
	 * @param string        $type       Run type.
	 * @param string[]|null $post_types Subset.
	 * @param int           $limit      Process only the first N posts (0 = all).
	 * @param int[]|null    $post_ids   Explicit posts.
	 * @return string|WP_Error
	 */
	public static function start_paid( $type, $post_types = null, $limit = 0, $post_ids = null ) {
		$tasks = self::paid_types()[ $type ] ?? null;
		if ( ! $tasks ) {
			return new WP_Error( 'ailsug_bad_type', __( 'Unknown run type.', 'interlink-ai' ), array( 'status' => 400 ) );
		}
		$estimate = self::estimate( $type, $post_types, $post_ids );
		if ( '' !== $estimate['blocker'] ) {
			return new WP_Error( 'ailsug_blocked', $estimate['blocker'], array( 'status' => 409 ) );
		}
		$limit = (int) $limit;
		if ( $limit <= 0 && $estimate['fits'] < $estimate['posts'] ) {
			return new WP_Error(
				'ailsug_over_budget',
				/* translators: %d: number of pages. */
				sprintf( __( 'This run may exceed the remaining monthly budget. Only the first %d pages fit.', 'interlink-ai' ), $estimate['fits'] ),
				array(
					'status' => 409,
					'fits'   => $estimate['fits'],
				)
			);
		}

		$selection = self::selection( $type, $post_types, $post_ids );
		if ( $limit > 0 ) {
			$selection = array_slice( $selection, 0, $limit, true );
		}
		$jobs = array();
		// All keyword jobs first: ranking needs the keywords of other pages.
		foreach ( $tasks as $task ) {
			$need = 'rerank' === $task ? 'rank' : $task;
			foreach ( $selection as $id => $needs ) {
				if ( ! empty( $needs[ $need ] ) ) {
					$jobs[] = array( $task, $id );
				}
			}
		}
		$scaled = $estimate['posts'] > 0 ? count( $selection ) / $estimate['posts'] : 0;
		$run_id = Queue::create_run(
			$type,
			$jobs,
			array(
				'low'  => (int) round( (int) $estimate['low'] * $scaled ),
				'high' => (int) round( (int) $estimate['high'] * $scaled ),
			)
		);
		Worker::schedule();
		return $run_id;
	}
}
