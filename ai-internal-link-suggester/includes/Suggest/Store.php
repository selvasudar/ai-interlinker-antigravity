<?php
/**
 * Suggestion storage.
 *
 * @package AILSUG
 */

namespace AILSUG\Suggest;

use AILSUG\Activator;
use AILSUG\Index\Indexer;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the suggestions table.
 */
final class Store {

	const STATUSES = array( 'pending', 'accepted', 'rejected', 'applied', 'stale', 'reverted' );

	/**
	 * Replace a source's open suggestions with a fresh set.
	 *
	 * Pending and stale rows are replaced. Accepted, applied and rejected rows are kept, and no new
	 * suggestion is added for a target that already has one of those (a rejection is remembered).
	 *
	 * @param int     $source_id   Source post.
	 * @param string  $source_hash Content hash the suggestions were built from.
	 * @param array[] $suggestions From Validator.
	 * @param string  $run_id      Run.
	 * @return int Inserted rows.
	 */
	public static function replace_for_source( $source_id, $source_hash, array $suggestions, $run_id ) {
		global $wpdb;
		$t   = Activator::tables();
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . esc_sql( $t['suggestions'] ) . " WHERE source_post_id = %d AND status IN ('pending','stale')", (int) $source_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$keep = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT target_post_id FROM ' . esc_sql( $t['suggestions'] ) . " WHERE source_post_id = %d AND status IN ('accepted','applied','rejected')", (int) $source_id ) ) );

		$n = 0;
		foreach ( $suggestions as $s ) {
			if ( in_array( (int) $s['target_id'], $keep, true ) ) {
				continue;
			}
			$target  = Indexer::get_row( (int) $s['target_id'] );
			$context = $s['context']['before'] . "\u{2063}" . $s['context']['match'] . "\u{2063}" . $s['context']['after'];
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Own table.
			$wpdb->insert(
				$t['suggestions'],
				array(
					'source_post_id'  => (int) $source_id,
					'target_post_id'  => (int) $s['target_id'],
					'anchor_text'     => (string) $s['anchor_text'],
					'chunk_id'        => (string) $s['chunk_id'],
					'context_snippet' => $context,
					'reason'          => (string) $s['reason'],
					'score'           => (int) $s['score'],
					'status'          => 'pending',
					'source_hash'     => (string) $source_hash,
					'target_hash'     => $target ? (string) $target['content_hash'] : '',
					'created_at'      => $now,
					'updated_at'      => $now,
					'run_id'          => (string) $run_id,
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			++$n;
		}//end foreach
		return $n;
	}

	/**
	 * One suggestion.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$t = Activator::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . esc_sql( $t['suggestions'] ) . ' WHERE id = %d', (int) $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Update fields of a suggestion.
	 *
	 * @param int   $id     ID.
	 * @param array $fields Fields (status, anchor_text, target_post_id, context_snippet, chunk_id, applied_at).
	 * @return bool
	 */
	public static function update( $id, array $fields ) {
		global $wpdb;
		$t       = Activator::tables();
		$allowed = array(
			'status'          => '%s',
			'anchor_text'     => '%s',
			'target_post_id'  => '%d',
			'context_snippet' => '%s',
			'chunk_id'        => '%s',
			'applied_at'      => '%s',
			'reason'          => '%s',
			'target_hash'     => '%s',
		);
		$data    = array();
		$formats = array();
		foreach ( $fields as $key => $value ) {
			if ( isset( $allowed[ $key ] ) ) {
				if ( 'status' === $key && ! in_array( $value, self::STATUSES, true ) ) {
					continue;
				}
				$data[ $key ] = $value;
				$formats[]    = $allowed[ $key ];
			}
		}
		if ( ! $data ) {
			return false;
		}
		$data['updated_at'] = current_time( 'mysql', true );
		$formats[]          = '%s';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		return false !== $wpdb->update( $t['suggestions'], $data, array( 'id' => (int) $id ), $formats, array( '%d' ) );
	}

	/**
	 * Split a stored snippet into before / match / after.
	 *
	 * @param string $snippet Snippet.
	 * @return array{before:string,match:string,after:string}
	 */
	public static function split_snippet( $snippet ) {
		$parts = explode( "\u{2063}", (string) $snippet );
		return array(
			'before' => $parts[0] ?? '',
			'match'  => $parts[1] ?? '',
			'after'  => $parts[2] ?? '',
		);
	}
}
