<?php
/**
 * Validates AI suggestions against the shortlist, the supplied chunks and the current content.
 *
 * @package AILSUG
 */

namespace AILSUG\Suggest;

use AILSUG\Index\LinkGraph;
use AILSUG\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Never trusts model output: every field is checked and anything unexpected is dropped.
 */
final class Validator {

	/**
	 * Anchor phrases that are never acceptable.
	 *
	 * @return string[]
	 */
	public static function generic_anchors() {
		/**
		 * Filters generic anchor texts that are always rejected.
		 *
		 * @param string[] $anchors Lowercase phrases.
		 */
		return (array) apply_filters( 'ailsug_generic_anchors', array( 'click here', 'here', 'read more', 'learn more', 'more', 'this', 'this page', 'this post', 'this article', 'link', 'website', 'see more', 'find out more', 'continue reading' ) );
	}

	/**
	 * Validate raw suggestions.
	 *
	 * @param mixed   $raw          Decoded model answer ['suggestions' => [...]].
	 * @param int[]   $target_ids   Shortlisted target IDs.
	 * @param array   $chunk_ids    Chunk IDs that were sent.
	 * @param TextMap $map          Map of the current full source content.
	 * @param int     $source_id    Source post ID.
	 * @return array[] Clean suggestions: target_id, chunk_id, anchor_text, score, reason, occurrence, context.
	 */
	public static function validate( $raw, array $target_ids, array $chunk_ids, TextMap $map, $source_id ) {
		$items = is_array( $raw ) && isset( $raw['suggestions'] ) && is_array( $raw['suggestions'] ) ? $raw['suggestions'] : array();
		$ids   = array_flip( array_map( 'intval', $target_ids ) );
		$chunk = array_flip( array_map( 'strval', $chunk_ids ) );
		$deny  = array_flip( self::generic_anchors() );

		$clean = array();
		foreach ( $items as $s ) {
			if ( ! is_array( $s ) ) {
				continue;
			}
			$target = isset( $s['target_id'] ) && is_numeric( $s['target_id'] ) ? (int) $s['target_id'] : 0;
			$cid    = isset( $s['chunk_id'] ) && is_scalar( $s['chunk_id'] ) ? (string) $s['chunk_id'] : '';
			$anchor = isset( $s['anchor_text'] ) && is_string( $s['anchor_text'] ) ? trim( preg_replace( '/\s+/u', ' ', $s['anchor_text'] ) ) : '';

			if ( ! isset( $ids[ $target ] ) || ! isset( $chunk[ $cid ] ) || '' === $anchor ) {
				continue;
				// Hallucinated target or chunk.
			}
			$words = count( Text::tokens( $anchor ) );
			$len   = function_exists( 'mb_strlen' ) ? mb_strlen( $anchor ) : strlen( $anchor );
			if ( $words < 1 || $words > 8 || $len > 100 || isset( $deny[ Text::lower( $anchor ) ] ) ) {
				continue;
			}
			if ( (int) $source_id === $target || LinkGraph::links_to( $source_id, $target ) ) {
				continue;
			}
			$found = $map->find( $anchor, $cid );
			if ( ! $found ) {
				continue;
				// Not verbatim in an eligible text node of that chunk.
			}
			$occurrence = $found[0];
			$score      = isset( $s['score'] ) && is_numeric( $s['score'] ) ? max( 1, min( 100, (int) $s['score'] ) ) : 1;
			$reason     = isset( $s['reason'] ) && is_string( $s['reason'] ) ? sanitize_text_field( $s['reason'] ) : '';
			$clean[]    = array(
				'target_id'   => $target,
				'chunk_id'    => $cid,
				'anchor_text' => $occurrence['text'],
				'score'       => $score,
				'reason'      => function_exists( 'mb_substr' ) ? mb_substr( $reason, 0, 140 ) : substr( $reason, 0, 140 ),
				'occurrence'  => $occurrence,
				'position'    => $map->word_position( $occurrence ),
				'context'     => $map->context( $occurrence ),
			);
		}//end foreach

		usort(
			$clean,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		// Linking rules: one suggestion per target, no overlapping anchors, spacing, max count.
		$max      = (int) Settings::get( 'max_suggestions', 5 );
		$distance = (int) Settings::get( 'min_link_distance', 50 );
		$kept     = array();
		$targets  = array();
		foreach ( $clean as $c ) {
			if ( isset( $targets[ $c['target_id'] ] ) || count( $kept ) >= $max ) {
				continue;
			}
			foreach ( $kept as $k ) {
				$overlap = $c['occurrence']['raw_start'] < $k['occurrence']['raw_end'] && $c['occurrence']['raw_end'] > $k['occurrence']['raw_start'];
				if ( $overlap || abs( $c['position'] - $k['position'] ) < $distance ) {
					continue 2;
				}
			}
			$targets[ $c['target_id'] ] = true;
			$kept[]                     = $c;
		}
		return $kept;
	}
}
