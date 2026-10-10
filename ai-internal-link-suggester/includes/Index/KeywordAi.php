<?php
/**
 * Stage A: AI keyword extraction for pages without a manual or SEO-plugin keyword.
 *
 * @package AILSUG
 */

namespace AILSUG\Index;

use AILSUG\Activator;
use AILSUG\AI\Gateway;
use AILSUG\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Sends only title, headings and the opening words. Skips pages whose text has not changed.
 */
final class KeywordAi {

	/**
	 * Whether a post needs an AI keyword right now.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function needs_ai( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}
		return KeywordResolver::resolve( $post_id, Indexer::get_row( $post_id ), Collector::ai_hash( $post ) )['needs_ai'];
	}

	/**
	 * Queue task handler for "keyword".
	 *
	 * @param int    $post_id Post ID.
	 * @param string $run_id  Run ID.
	 * @return true|\WP_Error
	 */
	public static function task( $post_id, $run_id = '' ) {
		global $wpdb;
		$post = get_post( $post_id );
		if ( ! $post || ! Eligibility::is_eligible( $post ) ) {
			Indexer::remove( $post_id );
			return true;
		}

		$row = Indexer::get_row( $post_id );
		if ( ! $row || Collector::content_hash( $post->post_content ) !== $row['content_hash'] ) {
			Indexer::index_post( $post_id );
			$row = Indexer::get_row( $post_id );
		}
		$ai_hash = Collector::ai_hash( $post );
		if ( ! KeywordResolver::resolve( $post_id, $row, $ai_hash )['needs_ai'] ) {
			return true;
			// Already known: nothing to pay for.
		}

		$parsed = Collector::parse( $post->post_content );
		$result = Gateway::instance()->run(
			'keyword',
			array(
				'title'    => wp_strip_all_tags( get_the_title( $post ) ),
				'headings' => $parsed['headings'],
				'text'     => Collector::opening( $parsed['text'], (int) Settings::get( 'excerpt_words', 300 ) ),
			),
			array(
				'run_id'  => $run_id,
				'post_id' => $post_id,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = $result['data'];
		$t    = Activator::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->update(
			$t['index'],
			array(
				'primary_keyword'    => $data['primary_keyword'],
				'keyword_source'     => 'ai',
				'secondary_keywords' => (string) wp_json_encode( $data['secondary_keywords'] ),
				'search_intent'      => $data['search_intent'],
				'summary'            => $data['summary'],
				'ai_hash'            => $ai_hash,
			),
			array( 'post_id' => (int) $post_id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
		if ( $row && $row['primary_keyword'] !== $data['primary_keyword'] ) {
			Indexer::mark_stale_target( $post_id );
		}
		return true;
	}
}
