<?php
/**
 * Reverts applied links using their edit records, never overwriting newer edits.
 *
 * @package AILSUG
 */

namespace AILSUG\Suggest;

use AILSUG\Activator;
use AILSUG\Index\Indexer;

defined( 'ABSPATH' ) || exit;

/**
 * Removes only the exact inserted link, re-located by its stored before/after context.
 * If the surroundings changed so that safe removal is impossible, it stops and points to the
 * revision saved before the link was added.
 */
final class Reverter {

	/**
	 * Applied-record rows by ID or batch.
	 *
	 * @param int[]       $ids   Applied record IDs.
	 * @param string|null $batch Batch ID.
	 * @return array[]
	 */
	public static function records( array $ids, $batch = null ) {
		global $wpdb;
		$t = Activator::tables();
		if ( null !== $batch ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE batch_id = %s AND reverted_at IS NULL', $t['applied'], (string) $batch ), ARRAY_A );
			return is_array( $rows ) ? $rows : array();
		}
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders built above.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE id IN ({$placeholders}) AND reverted_at IS NULL", array_merge( array( $t['applied'] ), $ids ) ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Revert records.
	 *
	 * @param array[] $records Applied rows.
	 * @return array[] Results: id, post_id, status (reverted|manual|skipped|error), message, revision_url.
	 */
	public static function revert( array $records ) {
		$by_post = array();
		foreach ( $records as $r ) {
			$by_post[ (int) $r['post_id'] ][] = $r;
		}
		$out = array();
		foreach ( $by_post as $post_id => $rows ) {
			try {
				$out = array_merge( $out, self::revert_post( $post_id, $rows ) );
			} catch ( \Throwable $e ) {
				foreach ( $rows as $r ) {
					$out[] = self::result( $r, 'error', __( 'Unexpected error; this page was not changed.', 'linkcortex' ) );
				}
			}
		}
		return $out;
	}

	/**
	 * Revert several records on one post with a single save.
	 *
	 * @param int     $post_id Post ID.
	 * @param array[] $rows    Records.
	 * @return array[]
	 */
	private static function revert_post( $post_id, array $rows ) {
		global $wpdb;
		$t    = Activator::tables();
		$post = get_post( $post_id );
		$out  = array();
		if ( ! $post ) {
			foreach ( $rows as $r ) {
				$out[] = self::result( $r, 'manual', __( 'The page no longer exists.', 'linkcortex' ) );
			}
			return $out;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			foreach ( $rows as $r ) {
				$out[] = self::result( $r, 'skipped', __( 'You are not allowed to edit this page.', 'linkcortex' ) );
			}
			return $out;
		}
		if ( ! function_exists( 'wp_check_post_lock' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
		if ( wp_check_post_lock( $post_id ) ) {
			foreach ( $rows as $r ) {
				$out[] = self::result( $r, 'skipped', __( 'Someone is editing this page right now. Try again later.', 'linkcortex' ) );
			}
			return $out;
		}

		$content = (string) $post->post_content;
		$active  = wp_list_pluck( Content_Writer::active_records( $post_id ), 'inserted_markup' );
		$done    = array();

		// Latest links first.
		usort(
			$rows,
			static function ( $a, $b ) {
				return (int) $b['id'] <=> (int) $a['id'];
			}
		);

		foreach ( $rows as $r ) {
			$markup = (string) $r['inserted_markup'];
			$inner  = Content_Writer::inner( $markup );
			$pos    = null === $inner ? null : self::locate( $content, $r, $active );
			if ( null === $pos ) {
				$out[] = self::result( $r, 'manual', __( 'The page changed around this link, so it was not removed automatically (your newer edits are safe). Remove it by hand or restore the saved revision.', 'linkcortex' ) );
				continue;
			}
			$content = substr( $content, 0, $pos ) . $inner . substr( $content, $pos + strlen( $markup ) );
			$key     = array_search( $markup, $active, true );
			if ( false !== $key ) {
				unset( $active[ $key ] );
			}
			$done[] = $r;
		}

		if ( ! $done ) {
			return $out;
		}

		Content_Writer::snapshot( $post_id );
		$saved = Content_Writer::save( $post_id, $content );
		if ( is_wp_error( $saved ) ) {
			foreach ( $done as $r ) {
				$out[] = self::result( $r, 'error', $saved->get_error_message() );
			}
			return $out;
		}

		$now = current_time( 'mysql', true );
		foreach ( $done as $r ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
			$wpdb->update( $t['applied'], array( 'reverted_at' => $now ), array( 'id' => (int) $r['id'] ), array( '%s' ), array( '%d' ) );
			Store::update( (int) $r['suggestion_id'], array( 'status' => 'reverted' ) );
			$out[] = self::result( $r, 'reverted', __( 'Link removed.', 'linkcortex' ) );
		}
		Applier::refresh_open_suggestions( $post_id, $saved );
		Indexer::index_post( $post_id );
		return $out;
	}

	/**
	 * Find the record's markup in the content when the surroundings still match.
	 *
	 * Safe when both stored contexts match. Also accepted: the exact markup appears only once and
	 * one side still matches (the other side was edited, but the link itself is clearly ours).
	 *
	 * @param string $content Content.
	 * @param array  $r       Record.
	 * @param array  $active  Active markups for context normalization.
	 * @return int|null
	 */
	private static function locate( $content, array $r, array $active ) {
		$markup    = (string) $r['inserted_markup'];
		$positions = Content_Writer::positions( $content, $markup );
		$partial   = array();
		foreach ( $positions as $pos ) {
			list( $before, $after ) = Content_Writer::contexts( $content, $pos, strlen( $markup ), $active );
			$b                      = $before === $r['context_before'];
			$a                      = $after === $r['context_after'];
			if ( $a && $b ) {
				return $pos;
			}
			if ( $a || $b ) {
				$partial[] = $pos;
			}
		}
		return ( 1 === count( $positions ) && 1 === count( $partial ) ) ? $partial[0] : null;
	}

	/**
	 * Result row.
	 *
	 * @param array  $r       Record.
	 * @param string $status  Status.
	 * @param string $message Message.
	 * @return array
	 */
	private static function result( array $r, $status, $message ) {
		$rev = (int) $r['revision_id'];
		return array(
			'id'           => (int) $r['id'],
			'post_id'      => (int) $r['post_id'],
			'status'       => $status,
			'message'      => $message,
			'revision_url' => ( 'manual' === $status && $rev && get_post( $rev ) ) ? admin_url( 'revision.php?revision=' . $rev ) : '',
		);
	}
}
