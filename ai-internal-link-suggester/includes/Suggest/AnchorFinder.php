<?php
/**
 * Local anchor opportunities across the full source post (free).
 *
 * @package AILSUG
 */

namespace AILSUG\Suggest;

defined( 'ABSPATH' ) || exit;

/**
 * For each candidate, finds the chunks that mention its keywords (exact or lightly stemmed),
 * then keeps the few best chunks so AI only reads those paragraphs.
 */
final class AnchorFinder {

	/**
	 * Score chunks per candidate and select the chunks to send.
	 *
	 * @param TextMap $map        Source map.
	 * @param array[] $candidates Shortlist rows.
	 * @param int     $max_chunks Max chunks to send.
	 * @return array{chunks:array<string,string>,candidates:array[]}
	 */
	public static function opportunities( TextMap $map, array $candidates, $max_chunks ) {
		$chunks = $map->chunks();
		if ( ! $chunks || ! $candidates ) {
			return array(
				'chunks'     => array(),
				'candidates' => array(),
			);
		}
		$chunk_stems = array();
		foreach ( $chunks as $id => $text ) {
			$chunk_stems[ $id ] = Text::stems( $text );
		}

		$per_candidate = array();
		$chunk_total   = array_fill_keys( array_keys( $chunks ), 0.0 );
		foreach ( $candidates as $c ) {
			$scores  = array();
			$phrases = array( array( (string) $c['primary_keyword'], 3.0 ) );
			foreach ( array_slice( (array) $c['secondary'], 0, 4 ) as $kw ) {
				$phrases[] = array( (string) $kw, 2.0 );
			}
			foreach ( $phrases as $p ) {
				list( $phrase, $weight ) = $p;
				if ( '' === trim( $phrase ) ) {
					continue;
				}
				foreach ( $map->find( $phrase, null, true ) as $occ ) {
					$scores[ $occ['chunk'] ] = max( $scores[ $occ['chunk'] ] ?? 0, $weight );
				}
				$stems = array_values(
					array_filter(
						Text::stems( $phrase ),
						static function ( $w ) {
							return ! Text::is_stopword( $w );
						}
					)
				);
				if ( ! $stems ) {
					continue;
				}
				foreach ( $chunk_stems as $id => $cs ) {
					if ( self::contains_sequence( $cs, Text::stems( $phrase ) ) ) {
						$scores[ $id ] = max( $scores[ $id ] ?? 0, $weight * 0.6 );
					} elseif ( count( $stems ) >= 2 ) {
						$hit = count( array_intersect( $stems, $cs ) );
						if ( $hit / count( $stems ) >= 0.66 ) {
							$scores[ $id ] = max( $scores[ $id ] ?? 0, $weight * 0.25 );
						}
					}
				}
			}//end foreach
			if ( ! $scores ) {
				continue;
			}
			arsort( $scores );
			$per_candidate[ (int) $c['post_id'] ] = $scores;
			foreach ( $scores as $id => $s ) {
				$chunk_total[ $id ] += $s;
			}
		}//end foreach

		// Each candidate's best chunk first (by candidate relevance order), then the best overall.
		$selected = array();
		foreach ( $candidates as $c ) {
			$id = (int) $c['post_id'];
			if ( empty( $per_candidate[ $id ] ) || count( $selected ) >= $max_chunks ) {
				continue;
			}
			$best              = (string) array_key_first( $per_candidate[ $id ] );
			$selected[ $best ] = true;
		}
		arsort( $chunk_total );
		foreach ( $chunk_total as $id => $total ) {
			if ( count( $selected ) >= $max_chunks || $total <= 0 ) {
				break;
			}
			$selected[ (string) $id ] = true;
		}

		$kept = array();
		foreach ( $candidates as $c ) {
			$id = (int) $c['post_id'];
			if ( empty( $per_candidate[ $id ] ) ) {
				continue;
			}
			if ( array_intersect_key( $per_candidate[ $id ], $selected ) ) {
				$kept[] = $c;
			}
		}

		$out = array();
		foreach ( $chunks as $id => $text ) {
			if ( isset( $selected[ (string) $id ] ) ) {
				$out[ (string) $id ] = $text;
			}
		}
		return array(
			'chunks'     => $out,
			'candidates' => $kept,
		);
	}

	/**
	 * Whether $needle appears as a consecutive sequence in $hay.
	 *
	 * @param string[] $hay    Tokens.
	 * @param string[] $needle Tokens.
	 * @return bool
	 */
	private static function contains_sequence( array $hay, array $needle ) {
		$n = count( $needle );
		if ( ! $n || $n > count( $hay ) ) {
			return false;
		}
		for ( $i = 0, $max = count( $hay ) - $n; $i <= $max; $i++ ) {
			if ( array_slice( $hay, $i, $n ) === $needle ) {
				return true;
			}
		}
		return false;
	}
}
