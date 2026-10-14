<?php
/**
 * Saves surgically edited post content and builds edit-record contexts.
 *
 * @package AILSUG
 */

namespace AILSUG\Suggest;

use AILSUG\Activator;

defined( 'ABSPATH' ) || exit;

/**
 * Shared by the applier and the reverter.
 */
final class Content_Writer {

	const CONTEXT_BYTES = 40;
	const WINDOW_BYTES  = 800;

	/**
	 * Save new content for a post, keeping every byte we did not change.
	 *
	 * The content already passed the content_save_pre / pre_post_content filters when it was first
	 * saved. Running them again could rewrite unrelated markup (for example core's
	 * wp_targeted_link_rel), so they are detached for this one update and restored right after.
	 * The only new markup is an <a href> built with esc_url().
	 *
	 * @param int    $post_id Post ID.
	 * @param string $content New raw content.
	 * @return string|\WP_Error The content as stored.
	 */
	public static function save( $post_id, $content ) {
		global $wp_filter;
		$hooks   = array( 'content_save_pre', 'pre_post_content' );
		$removed = array();
		foreach ( $hooks as $hook ) {
			if ( isset( $wp_filter[ $hook ] ) ) {
				$removed[ $hook ] = $wp_filter[ $hook ];
				unset( $wp_filter[ $hook ] );
			}
		}

		try {
			$result = wp_update_post(
				array(
					'ID'           => (int) $post_id,
					'post_content' => wp_slash( $content ),
				),
				true
			);
		} finally {
			foreach ( $removed as $hook => $filters ) {
				$wp_filter[ $hook ] = $filters; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the exact filters removed above.
			}
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		clean_post_cache( (int) $post_id );
		$post = get_post( (int) $post_id );
		return $post ? (string) $post->post_content : new \WP_Error( 'ailsug_missing', __( 'The page could not be read back after saving.', 'linkcortex' ) );
	}

	/**
	 * Save a revision of the current content first (so the pre-change version is always recoverable).
	 *
	 * @param int $post_id Post ID.
	 * @return int Revision ID or 0.
	 */
	public static function snapshot( $post_id ) {
		if ( ! wp_revisions_enabled( get_post( $post_id ) ) ) {
			return 0;
		}
		$id = wp_save_post_revision( (int) $post_id );
		if ( $id && ! is_wp_error( $id ) ) {
			return (int) $id;
		}
		$latest = wp_get_post_revisions(
			(int) $post_id,
			array(
				'numberposts' => 1,
				'fields'      => 'ids',
			)
		);
		return $latest ? (int) reset( $latest ) : 0;
	}

	/**
	 * Unwrap our own active links in a piece of HTML (used to normalize contexts).
	 *
	 * @param string   $html    HTML.
	 * @param string[] $markups Inserted markups currently in the post.
	 * @return string
	 */
	public static function unwrap_all( $html, array $markups ) {
		foreach ( $markups as $markup ) {
			$inner = self::inner( $markup );
			if ( null !== $inner ) {
				$html = str_replace( $markup, $inner, $html );
			}
		}
		return $html;
	}

	/**
	 * The raw anchor inside an inserted markup.
	 *
	 * @param string $markup <a ...>inner</a>.
	 * @return string|null
	 */
	public static function inner( $markup ) {
		$open = strpos( $markup, '>' );
		if ( false === $open || '</a>' !== substr( $markup, -4 ) ) {
			return null;
		}
		return substr( $markup, $open + 1, -4 );
	}

	/**
	 * Context before and after a markup position, normalized by unwrapping our other links.
	 *
	 * @param string   $html    Content.
	 * @param int      $pos     Markup start.
	 * @param int      $len     Markup length.
	 * @param string[] $markups Active markups of this post.
	 * @return array{0:string,1:string}
	 */
	public static function contexts( $html, $pos, $len, array $markups ) {
		$start  = max( 0, $pos - self::WINDOW_BYTES );
		$before = self::unwrap_all( substr( $html, $start, $pos - $start ), $markups );
		$after  = self::unwrap_all( substr( $html, $pos + $len, self::WINDOW_BYTES ), $markups );
		return array( self::tail( $before ), self::head( $after ) );
	}

	/**
	 * Last N bytes on a character boundary.
	 *
	 * @param string $s String.
	 * @return string
	 */
	private static function tail( $s ) {
		if ( strlen( $s ) <= self::CONTEXT_BYTES ) {
			return $s;
		}
		$cut = substr( $s, -self::CONTEXT_BYTES );
		// Skip UTF-8 continuation bytes at the start.
		$i   = 0;
		$len = strlen( $cut );
		while ( $i < $len && ( ord( $cut[ $i ] ) & 0xC0 ) === 0x80 ) {
			++$i;
		}
		return substr( $cut, $i );
	}

	/**
	 * First N bytes on a character boundary.
	 *
	 * @param string $s String.
	 * @return string
	 */
	private static function head( $s ) {
		if ( strlen( $s ) <= self::CONTEXT_BYTES ) {
			return $s;
		}
		$cut = substr( $s, 0, self::CONTEXT_BYTES + 1 );
		$end = self::CONTEXT_BYTES;
		while ( $end > 0 && ( ord( $cut[ $end ] ) & 0xC0 ) === 0x80 ) {
			--$end;
		}
		return substr( $cut, 0, $end );
	}

	/**
	 * All byte positions of a needle.
	 *
	 * @param string $hay    Haystack.
	 * @param string $needle Needle.
	 * @return int[]
	 */
	public static function positions( $hay, $needle ) {
		$out = array();
		if ( '' === $needle ) {
			return $out;
		}
		$at = strpos( $hay, $needle );
		while ( false !== $at ) {
			$out[] = $at;
			$at    = strpos( $hay, $needle, $at + 1 );
		}
		return $out;
	}

	/**
	 * Inserted markups of a post that are still active (not reverted).
	 *
	 * @param int $post_id Post ID.
	 * @return array[] Rows of the applied table.
	 */
	public static function active_records( $post_id ) {
		global $wpdb;
		$t = Activator::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d AND reverted_at IS NULL ORDER BY id ASC', $t['applied'], (int) $post_id ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}
}
