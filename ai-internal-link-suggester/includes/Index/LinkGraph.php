<?php
/**
 * Internal link graph: who links to whom, inbound/outbound counts, orphans.
 *
 * @package AILSUG
 */

namespace AILSUG\Index;

use AILSUG\Activator;

defined( 'ABSPATH' ) || exit;

/**
 * Stores existing internal links found in content (no AI involved).
 */
final class LinkGraph {

	/**
	 * Replace the stored outbound links of a source post.
	 *
	 * @param int   $source Source post ID.
	 * @param array $links  List of ['target' => int, 'anchor' => string].
	 * @return int[] Target IDs affected (old and new), for recounting.
	 */
	public static function replace_links( $source, array $links ) {
		global $wpdb;
		$t      = Activator::tables();
		$source = (int) $source;

		$old = self::targets_of( $source );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->delete( $t['links'], array( 'source_post_id' => $source ), array( '%d' ) );

		$new = array();
		foreach ( $links as $link ) {
			$target = (int) $link['target'];
			if ( $target <= 0 || $target === $source ) {
				continue;
			}
			$anchor = (string) $link['anchor'];
			$anchor = function_exists( 'mb_substr' ) ? mb_substr( $anchor, 0, 250 ) : substr( $anchor, 0, 250 );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Own table.
			$wpdb->insert(
				$t['links'],
				array(
					'source_post_id' => $source,
					'target_post_id' => $target,
					'anchor_text'    => $anchor,
				),
				array( '%d', '%d', '%s' )
			);
			$new[] = $target;
		}
		return array_values( array_unique( array_merge( $old, $new ) ) );
	}

	/**
	 * Remove all links of a source (post deleted/unpublished/excluded).
	 *
	 * @param int $source Source ID.
	 * @return int[] Affected targets.
	 */
	public static function remove_source( $source ) {
		return self::replace_links( $source, array() );
	}

	/**
	 * Distinct targets a source links to.
	 *
	 * @param int $source Source ID.
	 * @return int[]
	 */
	public static function targets_of( $source ) {
		global $wpdb;
		$t = Activator::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table, live data.
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT target_post_id FROM ' . esc_sql( $t['links'] ) . ' WHERE source_post_id = %d', (int) $source ) );
		return array_map( 'intval', $ids );
	}

	/**
	 * Whether source already links to target.
	 *
	 * @param int $source Source.
	 * @param int $target Target.
	 * @return bool
	 */
	public static function links_to( $source, $target ) {
		return in_array( (int) $target, self::targets_of( $source ), true );
	}

	/**
	 * Recount inbound (distinct linking pages) and outbound counts.
	 *
	 * The links table only ever holds links from indexed pages: rows are removed when a
	 * source leaves the index, so no join against the index is needed here.
	 *
	 * @param int[]|null $post_ids IDs to recount, or null for all.
	 * @return void
	 */
	public static function recount( $post_ids = null ) {
		global $wpdb;
		$t     = Activator::tables();
		$where = '';
		if ( null !== $post_ids ) {
			$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $post_ids ) ) ) );
			if ( ! $ids ) {
				return;
			}
			$where = ' WHERE i.post_id IN (' . implode( ',', $ids ) . ')';
		}

		$index = esc_sql( $t['index'] );
		$links = esc_sql( $t['links'] );
		// $where holds integers only.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query(
			"UPDATE {$index} i SET
				i.inbound_count = (SELECT COUNT(DISTINCT l.source_post_id) FROM {$links} l WHERE l.target_post_id = i.post_id),
				i.outbound_count = (SELECT COUNT(DISTINCT l2.target_post_id) FROM {$links} l2 WHERE l2.source_post_id = i.post_id){$where}"
		);
		// phpcs:enable
	}

	/**
	 * Number of orphan pages (no inbound internal links).
	 *
	 * @return int
	 */
	public static function orphan_count() {
		global $wpdb;
		$t = Activator::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . esc_sql( $t['index'] ) . ' WHERE inbound_count = 0' );
	}
}
