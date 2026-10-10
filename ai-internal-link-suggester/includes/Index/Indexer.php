<?php
/**
 * Builds and maintains the local page index (no AI).
 *
 * @package AILSUG
 */

namespace AILSUG\Index;

use AILSUG\Activator;

defined( 'ABSPATH' ) || exit;

/**
 * Indexes one post at a time: collect → resolve keyword → store row → update link graph.
 */
final class Indexer {

	/**
	 * Index row for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	public static function get_row( $post_id ) {
		global $wpdb;
		$t = Activator::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table, live data.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . esc_sql( $t['index'] ) . ' WHERE post_id = %d', (int) $post_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Queue task handler for "index".
	 *
	 * @param int    $post_id Post ID.
	 * @param string $run_id  Run ID.
	 * @return true
	 */
	public static function task( $post_id, $run_id = '' ) {
		$result = self::index_post( $post_id );

		/**
		 * Fires after a post was (re)indexed locally.
		 *
		 * @param int    $post_id Post ID.
		 * @param array  $result  Result from Indexer::index_post().
		 * @param string $run_id  Run ID.
		 */
		do_action( 'ailsug_post_indexed', (int) $post_id, $result, (string) $run_id );
		return true;
	}

	/**
	 * Index (or un-index) one post.
	 *
	 * @param int $post_id Post ID.
	 * @return array{status:string,needs_ai:bool,content_changed:bool}
	 */
	public static function index_post( $post_id ) {
		global $wpdb;
		$t       = Activator::tables();
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );

		if ( ! $post || ! Eligibility::is_eligible( $post ) ) {
			self::remove( $post_id );
			return array(
				'status'          => 'removed',
				'needs_ai'        => false,
				'content_changed' => false,
			);
		}

		$existing  = self::get_row( $post_id );
		$data      = Collector::collect( $post );
		$keyword   = KeywordResolver::resolve( $post_id, $existing, $data['ai_hash'] );
		$changed   = $existing && $existing['content_hash'] !== $data['content_hash'];
		$title     = wp_strip_all_tags( get_the_title( $post ) );
		$text_hash = md5( $title . "\0" . $data['text'] );
		// A target's topic changes when its words change; adding or removing links does not.
		$ai_side = $existing && ( '' === (string) $existing['text_hash'] ? $changed : $existing['text_hash'] !== $text_hash );

		$row = array(
			'post_id'            => $post_id,
			'post_type'          => $post->post_type,
			'title'              => $title,
			'primary_keyword'    => $keyword['keyword'],
			'keyword_source'     => $keyword['source'],
			'secondary_keywords' => (string) wp_json_encode( array_values( $keyword['secondary'] ) ),
			'search_intent'      => $existing ? (string) $existing['search_intent'] : '',
			'summary'            => $existing ? (string) $existing['summary'] : '',
			'content_hash'       => $data['content_hash'],
			'ai_hash'            => $existing ? (string) $existing['ai_hash'] : '',
			'rank_hash'          => $existing ? (string) $existing['rank_hash'] : '',
			'text_hash'          => $text_hash,
			'word_count'         => $data['word_count'],
			'inbound_count'      => $existing ? (int) $existing['inbound_count'] : 0,
			'outbound_count'     => $existing ? (int) $existing['outbound_count'] : 0,
			'lang'               => Eligibility::language( $post_id ),
			'is_noindex'         => SeoPluginReader::is_noindex( $post_id ) ? 1 : 0,
			'is_builder'         => Eligibility::is_page_builder( $post_id ) ? 1 : 0,
			'indexed_at'         => current_time( 'mysql', true ),
			'status'             => 'indexed',
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->replace( $t['index'], $row, array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s' ) );

		$affected   = LinkGraph::replace_links( $post_id, $data['links'] );
		$affected[] = $post_id;
		LinkGraph::recount( $affected );

		if ( $changed ) {
			self::mark_stale_source( $post_id, $data['content_hash'] );
		}
		if ( $ai_side ) {
			self::mark_stale_target( $post_id );
		}

		return array(
			'status'          => 'indexed',
			'needs_ai'        => $keyword['needs_ai'],
			'content_changed' => $changed,
		);
	}

	/**
	 * Remove a post from the index and the link graph.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function remove( $post_id ) {
		global $wpdb;
		$t = Activator::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->delete( $t['index'], array( 'post_id' => (int) $post_id ), array( '%d' ) );
		$affected = LinkGraph::remove_source( $post_id );
		LinkGraph::recount( $affected );
		self::mark_stale_source( $post_id, '' );
		self::mark_stale_target( $post_id );
	}

	/**
	 * Remove index rows for posts no longer in the eligible set.
	 *
	 * @param int[] $eligible_ids Eligible IDs.
	 * @return int Rows removed.
	 */
	public static function prune( array $eligible_ids ) {
		global $wpdb;
		$t = Activator::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$indexed = array_map( 'intval', $wpdb->get_col( 'SELECT post_id FROM ' . esc_sql( $t['index'] ) ) );
		$gone    = array_diff( $indexed, array_map( 'intval', $eligible_ids ) );
		foreach ( $gone as $id ) {
			self::remove( $id );
		}
		return count( $gone );
	}

	/**
	 * Mark open suggestions from a source stale when its content no longer matches.
	 *
	 * @param int    $post_id  Source post.
	 * @param string $new_hash Current content hash ('' = always stale).
	 * @return void
	 */
	public static function mark_stale_source( $post_id, $new_hash ) {
		global $wpdb;
		$t = Activator::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . esc_sql( $t['suggestions'] ) . " SET status = 'stale', updated_at = %s WHERE source_post_id = %d AND status IN ('pending','accepted') AND source_hash <> %s",
				current_time( 'mysql', true ),
				(int) $post_id,
				(string) $new_hash
			)
		);
	}

	/**
	 * Mark open suggestions pointing to a target stale (its topic may have changed).
	 *
	 * @param int $post_id Target post.
	 * @return void
	 */
	public static function mark_stale_target( $post_id ) {
		global $wpdb;
		$t = Activator::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . esc_sql( $t['suggestions'] ) . " SET status = 'stale', updated_at = %s WHERE target_post_id = %d AND status IN ('pending','accepted')",
				current_time( 'mysql', true ),
				(int) $post_id
			)
		);
	}
}
