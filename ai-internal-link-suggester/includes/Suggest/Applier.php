<?php
/**
 * Applies accepted suggestions by splicing <a> markup into the raw post content.
 *
 * @package AILSUG
 */

namespace AILSUG\Suggest;

use AILSUG\Activator;
use AILSUG\Index\Collector;
use AILSUG\Index\Eligibility;
use AILSUG\Index\Indexer;
use AILSUG\Index\LinkGraph;
use AILSUG\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Surgical edits only: everything outside the inserted link stays byte-for-byte identical.
 * Every suggestion is revalidated against the current content right before applying.
 */
final class Applier {

	/**
	 * Whether bulk apply is enabled (feature flag; on once the apply→revert corpus tests pass).
	 *
	 * @return bool
	 */
	public static function bulk_enabled() {
		/**
		 * Filters whether applying many suggestions at once is enabled.
		 *
		 * @param bool $enabled Enabled.
		 */
		return (bool) apply_filters( 'ailsug_bulk_apply_enabled', defined( 'AILSUG_BULK_APPLY' ) ? (bool) AILSUG_BULK_APPLY : true );
	}

	/**
	 * Count what an apply would touch (for the confirmation dialog).
	 *
	 * @param int[] $ids Suggestion IDs.
	 * @return array{posts:int,links:int}
	 */
	public static function preview( array $ids ) {
		$posts = array();
		$links = 0;
		foreach ( $ids as $id ) {
			$s = Store::get( (int) $id );
			if ( $s && in_array( $s['status'], array( 'pending', 'accepted' ), true ) && current_user_can( 'edit_post', (int) $s['source_post_id'] ) ) {
				$posts[ (int) $s['source_post_id'] ] = true;
				++$links;
			}
		}
		return array(
			'posts' => count( $posts ),
			'links' => $links,
		);
	}

	/**
	 * Apply suggestions.
	 *
	 * @param int[] $ids Suggestion IDs.
	 * @return array{batch_id:string,results:array[]}
	 */
	public static function apply( array $ids ) {
		$batch   = wp_generate_uuid4();
		$results = array();
		$by_post = array();
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$s = Store::get( $id );
			if ( ! $s ) {
				$results[] = self::result( $id, 0, 'error', __( 'Suggestion not found.', 'linkcortex' ) );
				continue;
			}
			if ( ! in_array( $s['status'], array( 'pending', 'accepted' ), true ) ) {
				$results[] = self::result( $id, (int) $s['source_post_id'], 'skipped', __( 'Only open suggestions can be applied.', 'linkcortex' ) );
				continue;
			}
			$by_post[ (int) $s['source_post_id'] ][] = $s;
		}

		foreach ( $by_post as $post_id => $suggestions ) {
			try {
				$results = array_merge( $results, self::apply_to_post( $post_id, $suggestions, $batch ) );
			} catch ( \Throwable $e ) {
				foreach ( $suggestions as $s ) {
					$results[] = self::result( (int) $s['id'], $post_id, 'error', __( 'Unexpected error; this page was not changed.', 'linkcortex' ) );
				}
			}
		}

		/**
		 * Fires after an apply batch.
		 *
		 * @param string $batch   Batch ID.
		 * @param array  $results Results.
		 */
		do_action( 'ailsug_applied', $batch, $results );

		return array(
			'batch_id' => $batch,
			'results'  => $results,
		);
	}

	/**
	 * Apply several suggestions to one post with a single save (one revision).
	 *
	 * @param int     $post_id     Post ID.
	 * @param array[] $suggestions Suggestion rows.
	 * @param string  $batch       Batch ID.
	 * @return array[] Results.
	 */
	private static function apply_to_post( $post_id, array $suggestions, $batch ) {
		global $wpdb;
		$t    = Activator::tables();
		$post = get_post( $post_id );
		$out  = array();
		$fail = static function ( $message, $status = 'skipped' ) use ( $suggestions, $post_id ) {
			$r = array();
			foreach ( $suggestions as $s ) {
				$r[] = self::result( (int) $s['id'], $post_id, $status, $message );
			}
			return $r;
		};

		if ( ! $post || ! Eligibility::is_eligible( $post ) ) {
			foreach ( $suggestions as $s ) {
				Store::update( (int) $s['id'], array( 'status' => 'stale' ) );
			}
			return $fail( __( 'This page is no longer published or included, so the suggestion is out of date.', 'linkcortex' ), 'stale' );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return $fail( __( 'You are not allowed to edit this page.', 'linkcortex' ) );
		}
		if ( ! function_exists( 'wp_check_post_lock' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
		$lock = wp_check_post_lock( $post_id );
		if ( $lock ) {
			$user = get_userdata( $lock );
			/* translators: %s: user display name. */
			return $fail( sprintf( __( '%s is editing this page right now. Try again later.', 'linkcortex' ), $user ? $user->display_name : __( 'Someone', 'linkcortex' ) ) );
		}
		if ( Eligibility::is_page_builder( $post_id ) ) {
			return $fail( __( 'This page is built with a page builder. View only: copy the link and add it in the builder.', 'linkcortex' ) );
		}

		$original    = (string) $post->post_content;
		$content     = $original;
		$hash        = Collector::content_hash( $original );
		$linked      = array_flip( LinkGraph::targets_of( $post_id ) );
		$limit       = (int) Settings::get( 'max_links_per_apply', 5 );
		$distance    = (int) Settings::get( 'min_link_distance', 50 );
		$new_tab     = (bool) Settings::get( 'new_tab' );
		$edits       = array();
		$positions   = array();
		$prev_active = wp_list_pluck( Content_Writer::active_records( $post_id ), 'inserted_markup' );

		usort(
			$suggestions,
			static function ( $a, $b ) {
				return (int) $b['score'] <=> (int) $a['score'];
			}
		);

		foreach ( $suggestions as $s ) {
			$sid    = (int) $s['id'];
			$target = (int) $s['target_post_id'];

			if ( ! Eligibility::is_linkable_target( $target ) ) {
				Store::update( $sid, array( 'status' => 'stale' ) );
				$out[] = self::result( $sid, $post_id, 'stale', __( 'The target page is no longer published.', 'linkcortex' ) );
				continue;
			}
			if ( isset( $linked[ $target ] ) ) {
				$out[] = self::result( $sid, $post_id, 'skipped', __( 'This page already links to the target.', 'linkcortex' ) );
				continue;
			}
			if ( count( $edits ) >= $limit ) {
				$out[] = self::result( $sid, $post_id, 'skipped', __( 'The limit of new links per page for one apply was reached. Apply again to add more.', 'linkcortex' ) );
				continue;
			}

			// Revalidate against the current content (including links added in this batch).
			$map   = new TextMap( $content );
			$occ   = $map->find( (string) $s['anchor_text'], (string) $s['chunk_id'] );
			$where = 'chunk';
			if ( ! $occ ) {
				$occ   = $map->find( (string) $s['anchor_text'] );
				$where = 'anywhere';
			}
			if ( ! $occ ) {
				Store::update( $sid, array( 'status' => 'stale' ) );
				$out[] = self::result( $sid, $post_id, 'stale', __( 'The anchor text is no longer in the page. Re-generate suggestions for this page.', 'linkcortex' ) );
				continue;
			}
			$o        = $occ[0];
			$position = $map->word_position( $o );
			foreach ( $positions as $p ) {
				if ( abs( $p - $position ) < $distance ) {
					$out[] = self::result( $sid, $post_id, 'skipped', __( 'Too close to another new link on this page.', 'linkcortex' ) );
					continue 2;
				}
			}

			$all        = $map->find( (string) $s['anchor_text'] );
			$occurrence = 0;
			foreach ( $all as $i => $candidate ) {
				if ( $candidate['raw_start'] === $o['raw_start'] ) {
					$occurrence = $i;
					break;
				}
			}

			$url    = get_permalink( $target );
			$open   = '<a href="' . esc_url( $url ) . '"' . ( $new_tab ? ' target="_blank" rel="noopener"' : '' ) . '>';
			$inner  = substr( $content, $o['raw_start'], $o['raw_end'] - $o['raw_start'] );
			$markup = $open . $inner . '</a>';

			/**
			 * Filters the inserted link markup. Must remain "<a ...>" + the anchor bytes + "</a>".
			 *
			 * @param string $markup Markup.
			 * @param array  $s      Suggestion.
			 */
			$filtered = (string) apply_filters( 'ailsug_link_markup', $markup, $s );
			if ( 0 === strpos( $filtered, '<a ' ) && Content_Writer::inner( $filtered ) === $inner ) {
				$markup = $filtered;
			}

			$content           = substr( $content, 0, $o['raw_start'] ) . $markup . substr( $content, $o['raw_end'] );
			$positions[]       = $position;
			$linked[ $target ] = true;
			$edits[]           = array(
				'suggestion' => $s,
				'markup'     => $markup,
				'start'      => $o['raw_start'],
				'occurrence' => $occurrence,
				'where'      => $where,
				'context'    => $map->context( $o ),
			);
		}//end foreach

		if ( ! $edits ) {
			return $out;
		}

		$revision = Content_Writer::snapshot( $post_id );
		$saved    = Content_Writer::save( $post_id, $content );
		if ( is_wp_error( $saved ) ) {
			foreach ( $edits as $e ) {
				$out[] = self::result( (int) $e['suggestion']['id'], $post_id, 'error', $saved->get_error_message() );
			}
			return $out;
		}
		$modified = $saved !== $content;

		$markups = array_merge( $prev_active, wp_list_pluck( $edits, 'markup' ) );
		$now     = current_time( 'mysql', true );
		foreach ( $edits as $e ) {
			$pos  = null;
			$best = PHP_INT_MAX;
			foreach ( Content_Writer::positions( $saved, $e['markup'] ) as $candidate ) {
				$gap = abs( $candidate - $e['start'] );
				if ( $candidate >= $e['start'] && $gap < $best ) {
					$best = $gap;
					$pos  = $candidate;
				}
			}
			$sid = (int) $e['suggestion']['id'];
			if ( null === $pos ) {
				$out[] = self::result( $sid, $post_id, 'error', __( 'Another plugin changed the page while saving; this link could not be confirmed.', 'linkcortex' ) );
				continue;
			}
			list( $before, $after ) = Content_Writer::contexts( $saved, $pos, strlen( $e['markup'] ), $markups );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Own table.
			$wpdb->insert(
				$t['applied'],
				array(
					'suggestion_id'       => $sid,
					'post_id'             => $post_id,
					'target_post_id'      => (int) $e['suggestion']['target_post_id'],
					'batch_id'            => $batch,
					'content_hash_before' => $hash,
					'content_hash_after'  => Collector::content_hash( $saved ),
					'inserted_markup'     => $e['markup'],
					'anchor_text'         => (string) $e['suggestion']['anchor_text'],
					'context_before'      => $before,
					'context_after'       => $after,
					'occurrence'          => (int) $e['occurrence'],
					'revision_id'         => $revision,
					'applied_by'          => get_current_user_id(),
					'applied_at'          => $now,
				),
				array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s' )
			);
			Store::update(
				$sid,
				array(
					'status'          => 'applied',
					'applied_at'      => $now,
					'context_snippet' => $e['context']['before'] . "\u{2063}" . $e['context']['match'] . "\u{2063}" . $e['context']['after'],
				)
			);
			$message = 'chunk' === $e['where'] ? __( 'Link added.', 'linkcortex' ) : __( 'Link added (the paragraph had changed, so the first matching place was used).', 'linkcortex' );
			if ( $modified ) {
				$message .= ' ' . __( 'Note: another plugin also adjusted the page while saving.', 'linkcortex' );
			}
			$out[] = self::result( $sid, $post_id, 'applied', $message );
		}//end foreach

		self::refresh_open_suggestions( $post_id, $saved );
		Indexer::index_post( $post_id );
		return $out;
	}

	/**
	 * After a page changed through us, keep its other open suggestions if they still validate.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $content New content.
	 * @return void
	 */
	public static function refresh_open_suggestions( $post_id, $content ) {
		global $wpdb;
		$t      = Activator::tables();
		$hash   = Collector::content_hash( $content );
		$map    = new TextMap( $content );
		$linked = array_flip( LinkGraph::targets_of( $post_id ) );
		foreach ( Content_Writer::active_records( $post_id ) as $r ) {
			$linked[ (int) $r['target_post_id'] ] = true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$open = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . esc_sql( $t['suggestions'] ) . " WHERE source_post_id = %d AND status IN ('pending','accepted')", (int) $post_id ), ARRAY_A );
		foreach ( (array) $open as $s ) {
			$ok = ! isset( $linked[ (int) $s['target_post_id'] ] ) && $map->find( (string) $s['anchor_text'], (string) $s['chunk_id'] );
			if ( $ok ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
				$wpdb->update( $t['suggestions'], array( 'source_hash' => $hash ), array( 'id' => (int) $s['id'] ), array( '%s' ), array( '%d' ) );
			} else {
				Store::update( (int) $s['id'], array( 'status' => 'stale' ) );
			}
		}
	}

	/**
	 * Result row.
	 *
	 * @param int    $id      Suggestion ID.
	 * @param int    $post_id Post ID.
	 * @param string $status  applied|skipped|stale|error.
	 * @param string $message Message.
	 * @return array
	 */
	private static function result( $id, $post_id, $status, $message ) {
		return array(
			'id'      => (int) $id,
			'post_id' => (int) $post_id,
			'status'  => $status,
			'message' => $message,
		);
	}
}
