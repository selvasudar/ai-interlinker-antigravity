<?php
/**
 * Local candidate shortlist (free): FULLTEXT search with a PHP fallback.
 *
 * @package AILSUG
 */

namespace AILSUG\Suggest;

use AILSUG\Activator;
use AILSUG\Index\Indexer;
use AILSUG\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the pages a source page is most likely to link to.
 */
final class Shortlister {

	/**
	 * Shortlist for a source post.
	 *
	 * Excludes: self, unpublished/password-protected/noindex/excluded pages (not in the index or
	 * flagged), targets already linked from the source, and other languages (Polylang/WPML).
	 *
	 * The query combines the source's own description (keyword, title, summary; weighted) with
	 * its body text, so pages whose topics the source already mentions rank high.
	 *
	 * @param int         $source_id Source post ID.
	 * @param int|null    $limit     Max candidates.
	 * @param string|null $body      Plain body text of the source (computed when null).
	 * @return array[] Rows: post_id, title, primary_keyword, secondary (array), summary, relevance.
	 */
	public static function shortlist( $source_id, $limit = null, $body = null ) {
		$row = Indexer::get_row( $source_id );
		if ( ! $row ) {
			return array();
		}
		$limit = $limit ? (int) $limit : (int) Settings::get( 'shortlist_size', 15 );
		if ( null === $body ) {
			$post = get_post( $source_id );
			$body = $post ? \AILSUG\Index\Collector::parse( $post->post_content )['text'] : '';
		}
		$body  = \AILSUG\Index\Collector::opening( $body, 1500 );
		$query = self::query_text( $row ) . ' ' . $body;
		if ( '' === trim( $query ) ) {
			return array();
		}

		$rows = self::fulltext( $row, $query, $limit * 2 );
		if ( null === $rows || ! $rows ) {
			$rows = self::fallback( $row, $query, $limit * 2 );
		}

		$priority = array_map( 'intval', (array) Settings::get( 'priority_targets' ) );
		foreach ( $rows as &$r ) {
			$r['relevance'] = (float) $r['relevance'] * ( in_array( (int) $r['post_id'], $priority, true ) ? 1.5 : 1.0 );
			$secondary      = json_decode( (string) $r['secondary_keywords'], true );
			$r['secondary'] = is_array( $secondary ) ? $secondary : array();
			if ( '' === (string) $r['primary_keyword'] ) {
				$r['primary_keyword'] = (string) $r['title'];
			}
		}
		unset( $r );
		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['relevance'] <=> $a['relevance'];
			}
		);

		/**
		 * Filters the candidate shortlist for a source.
		 *
		 * @param array[] $rows      Candidates.
		 * @param int     $source_id Source post ID.
		 */
		return array_slice( (array) apply_filters( 'ailsug_shortlist', $rows, (int) $source_id ), 0, $limit );
	}

	/**
	 * Text describing the source page.
	 *
	 * @param array $row Index row.
	 * @return string
	 */
	public static function query_text( array $row ) {
		$secondary = json_decode( (string) $row['secondary_keywords'], true );
		$parts     = array( $row['primary_keyword'], $row['primary_keyword'], $row['title'] );
		if ( is_array( $secondary ) ) {
			$parts = array_merge( $parts, $secondary );
		}
		$parts[] = $row['summary'];
		return trim( implode( ' ', array_filter( array_map( 'strval', $parts ) ) ) );
	}

	/**
	 * Shared WHERE clause for both strategies.
	 *
	 * @param array $row Source row.
	 * @return array{0:string,1:array}
	 */
	private static function where( array $row ) {
		$t      = Activator::tables();
		$sql    = "i.post_id <> %d AND p.post_status = 'publish' AND p.post_password = '' AND i.is_noindex = 0 AND i.post_id NOT IN (SELECT l.target_post_id FROM %i l WHERE l.source_post_id = %d)";
		$params = array( (int) $row['post_id'], $t['links'], (int) $row['post_id'] );
		if ( '' !== (string) $row['lang'] ) {
			$sql     .= " AND ( i.lang = %s OR i.lang = '' )";
			$params[] = (string) $row['lang'];
		}
		return array( $sql, $params );
	}

	/**
	 * MySQL FULLTEXT strategy. Returns null when unavailable.
	 *
	 * @param array  $row   Source row.
	 * @param string $query Query text.
	 * @param int    $limit Limit.
	 * @return array[]|null
	 */
	private static function fulltext( array $row, $query, $limit ) {
		global $wpdb;
		$t                      = Activator::tables();
		list( $where, $params ) = self::where( $row );
		$sql                    = "SELECT i.post_id, i.title, i.primary_keyword, i.secondary_keywords, i.summary, MATCH(i.title, i.primary_keyword, i.secondary_keywords, i.summary) AGAINST (%s IN NATURAL LANGUAGE MODE) AS relevance FROM %i i INNER JOIN %i p ON p.ID = i.post_id WHERE {$where} HAVING relevance > 0 ORDER BY relevance DESC LIMIT %d";
		$suppress               = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Placeholders only; values via prepare().
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( $query, $t['index'], $wpdb->posts ), $params, array( (int) $limit ) ) ), ARRAY_A );
		$err  = $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );
		if ( '' !== $err || ! is_array( $rows ) ) {
			return null;
		}
		return $rows;
	}

	/**
	 * PHP fallback: weighted term overlap with simple IDF (for hosts without FULLTEXT).
	 *
	 * @param array  $row   Source row.
	 * @param string $query Query text.
	 * @param int    $limit Limit.
	 * @return array[]
	 */
	private static function fallback( array $row, $query, $limit ) {
		global $wpdb;
		$t                      = Activator::tables();
		list( $where, $params ) = self::where( $row );
		$sql                    = "SELECT i.post_id, i.title, i.primary_keyword, i.secondary_keywords, i.summary FROM %i i INNER JOIN %i p ON p.ID = i.post_id WHERE {$where} LIMIT 5000";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Placeholders only; values via prepare().
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( $t['index'], $wpdb->posts ), $params ) ), ARRAY_A );
		if ( ! $rows ) {
			return array();
		}

		$q    = array_unique(
			array_filter(
				Text::stems( $query ),
				static function ( $w ) {
					return ! Text::is_stopword( $w );
				}
			)
		);
		$df   = array();
		$docs = array();
		foreach ( $rows as $k => $r ) {
			$strong     = array_unique( Text::stems( $r['title'] . ' ' . $r['primary_keyword'] ) );
			$weak       = array_unique( Text::stems( $r['secondary_keywords'] . ' ' . $r['summary'] ) );
			$docs[ $k ] = array( array_flip( $strong ), array_flip( $weak ) );
			foreach ( array_unique( array_merge( $strong, $weak ) ) as $w ) {
				$df[ $w ] = ( $df[ $w ] ?? 0 ) + 1;
			}
		}
		$n   = count( $rows );
		$out = array();
		foreach ( $rows as $k => $r ) {
			$score = 0.0;
			foreach ( $q as $w ) {
				$idf = log( 1 + $n / ( 1 + ( $df[ $w ] ?? 0 ) ) );
				if ( isset( $docs[ $k ][0][ $w ] ) ) {
					$score += 2 * $idf;
				} elseif ( isset( $docs[ $k ][1][ $w ] ) ) {
					$score += $idf;
				}
			}
			if ( $score > 0 ) {
				$r['relevance'] = $score;
				$out[]          = $r;
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['relevance'] <=> $a['relevance'];
			}
		);
		return array_slice( $out, 0, $limit );
	}
}
