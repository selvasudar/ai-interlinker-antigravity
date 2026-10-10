<?php
/**
 * Stage B: choose links and anchors with AI, using only locally selected chunks.
 *
 * @package AILSUG
 */

namespace AILSUG\Suggest;

use AILSUG\Activator;
use AILSUG\AI\Gateway;
use AILSUG\Index\Collector;
use AILSUG\Index\Eligibility;
use AILSUG\Index\Indexer;
use AILSUG\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Collect → shortlist → local anchor matching → AI ranking → validation → storage.
 */
final class Ranker {

	/**
	 * Queue handler "rank": skips pages whose content has not changed since the last ranking.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $run_id  Run ID.
	 * @return true|\WP_Error
	 */
	public static function task( $post_id, $run_id = '' ) {
		return self::rank( $post_id, $run_id, false );
	}

	/**
	 * Queue handler "rerank": always regenerates (user asked for it).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $run_id  Run ID.
	 * @return true|\WP_Error
	 */
	public static function task_force( $post_id, $run_id = '' ) {
		return self::rank( $post_id, $run_id, true );
	}

	/**
	 * Rank one source page.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $run_id  Run ID.
	 * @param bool   $force   Ignore the cache.
	 * @return true|\WP_Error
	 */
	public static function rank( $post_id, $run_id, $force ) {
		$post = get_post( $post_id );
		if ( ! $post || ! Eligibility::is_eligible( $post ) ) {
			Indexer::remove( $post_id );
			return true;
		}
		$hash = Collector::content_hash( $post->post_content );
		$row  = Indexer::get_row( $post_id );
		if ( ! $row || $row['content_hash'] !== $hash ) {
			Indexer::index_post( $post_id );
			$row = Indexer::get_row( $post_id );
		}
		if ( ! $force && $row && $row['rank_hash'] === $hash ) {
			return true;
			// Unchanged since last ranking: nothing to pay for.
		}

		$map        = new TextMap( $post->post_content );
		$candidates = Shortlister::shortlist( $post_id, null, implode( "\n", $map->chunks() ) );
		$found      = AnchorFinder::opportunities( $map, $candidates, (int) Settings::get( 'max_chunks', 6 ) );

		if ( ! $found['candidates'] || ! $found['chunks'] ) {
			// No paragraph mentions any candidate: no AI call needed.
			Store::replace_for_source( $post_id, $hash, array(), $run_id );
			self::mark_ranked( $post_id, $hash );
			return true;
		}

		$chunks = array();
		foreach ( $found['chunks'] as $id => $text ) {
			$chunks[] = array(
				'chunk_id' => (string) $id,
				'text'     => self::trim_words( $text, 150 ),
			);
		}
		$cands = array();
		foreach ( $found['candidates'] as $c ) {
			$cands[] = array(
				'id'      => (int) $c['post_id'],
				'title'   => (string) $c['title'],
				'keyword' => (string) $c['primary_keyword'],
				'summary' => (string) $c['summary'],
			);
		}

		$result = Gateway::instance()->run(
			'rank',
			array(
				'title'      => wp_strip_all_tags( get_the_title( $post ) ),
				'chunks'     => $chunks,
				'candidates' => $cands,
				'max'        => (int) Settings::get( 'max_suggestions', 5 ),
			),
			array(
				'run_id'  => $run_id,
				'post_id' => $post_id,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$valid = Validator::validate( $result['data'], wp_list_pluck( $cands, 'id' ), array_keys( $found['chunks'] ), $map, $post_id );
		Store::replace_for_source( $post_id, $hash, $valid, $run_id );
		self::mark_ranked( $post_id, $hash );
		return true;
	}

	/**
	 * Remember which content version was ranked.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $hash    Hash.
	 * @return void
	 */
	private static function mark_ranked( $post_id, $hash ) {
		global $wpdb;
		$t = Activator::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->update( $t['index'], array( 'rank_hash' => $hash ), array( 'post_id' => (int) $post_id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * Limit text to N words.
	 *
	 * @param string $text  Text.
	 * @param int    $words Words.
	 * @return string
	 */
	private static function trim_words( $text, $words ) {
		$parts = preg_split( '/\s+/u', trim( $text ) );
		return count( $parts ) > $words ? implode( ' ', array_slice( $parts, 0, $words ) ) . ' …' : $text;
	}
}
