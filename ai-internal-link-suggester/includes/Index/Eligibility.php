<?php
/**
 * Which posts are part of the index.
 *
 * @package AILSUG
 */

namespace AILSUG\Index;

use AILSUG\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Applies the post type, status and exclusion settings.
 */
final class Eligibility {

	/**
	 * Whether one post should be indexed.
	 *
	 * @param \WP_Post|int $post Post.
	 * @return bool
	 */
	public static function is_eligible( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return false;
		}
		if ( ! in_array( $post->post_type, (array) Settings::get( 'post_types' ), true ) ) {
			return false;
		}
		if ( ! in_array( $post->post_status, (array) Settings::get( 'statuses' ), true ) ) {
			return false;
		}
		if ( '' !== $post->post_password ) {
			return false;
		}
		if ( in_array( (int) $post->ID, array_map( 'intval', (array) Settings::get( 'excluded_posts' ) ), true ) ) {
			return false;
		}
		$terms = array_map( 'intval', (array) Settings::get( 'excluded_terms' ) );
		if ( $terms ) {
			$taxonomies = get_object_taxonomies( $post->post_type );
			if ( $taxonomies ) {
				$post_terms = wp_get_object_terms( $post->ID, $taxonomies, array( 'fields' => 'ids' ) );
				if ( ! is_wp_error( $post_terms ) && array_intersect( $terms, array_map( 'intval', $post_terms ) ) ) {
					return false;
				}
			}
		}

		/**
		 * Filters whether a post is part of the internal link index.
		 *
		 * @param bool     $eligible Eligible.
		 * @param \WP_Post $post     Post.
		 */
		return (bool) apply_filters( 'ailsug_is_eligible', true, $post );
	}

	/**
	 * Whether a post can receive links (always published, never password protected).
	 *
	 * @param \WP_Post|int $post Post.
	 * @return bool
	 */
	public static function is_linkable_target( $post ) {
		$post = get_post( $post );
		return $post && 'publish' === $post->post_status && '' === $post->post_password && self::is_eligible( $post );
	}

	/**
	 * IDs of all eligible posts.
	 *
	 * @param string[]|null $post_types Limit to these types (must be a subset of the setting).
	 * @return int[]
	 */
	public static function post_ids( $post_types = null ) {
		$types = (array) Settings::get( 'post_types' );
		if ( is_array( $post_types ) ) {
			$types = array_values( array_intersect( $types, $post_types ) );
		}
		if ( ! $types ) {
			return array();
		}

		$args     = array(
			'post_type'              => $types,
			'post_status'            => (array) Settings::get( 'statuses' ),
			'has_password'           => false,
			'fields'                 => 'ids',
			'posts_per_page'         => -1,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
		);
		$excluded = array_map( 'intval', (array) Settings::get( 'excluded_posts' ) );
		if ( $excluded ) {
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- Admin-only, small list.
			$args['post__not_in'] = $excluded;
		}
		$terms = array_map( 'intval', (array) Settings::get( 'excluded_terms' ) );
		if ( $terms ) {
			$tax_query = array( 'relation' => 'AND' );
			foreach ( get_taxonomies( array( 'public' => true ) ) as $taxonomy ) {
				$tax_query[] = array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $terms,
					'operator' => 'NOT IN',
				);
			}
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Admin-only.
		}

		$query = new \WP_Query( $args );
		$ids   = array_map( 'intval', $query->posts );

		/** This filter is documented in includes/Index/Eligibility.php */
		return array_values(
			array_filter(
				$ids,
				static function ( $id ) {
					return (bool) apply_filters( 'ailsug_is_eligible', true, get_post( $id ) );
				}
			)
		);
	}

	/**
	 * Language code of a post when Polylang or WPML is active, else ''.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function language( $post_id ) {
		if ( function_exists( 'pll_get_post_language' ) ) {
			$lang = pll_get_post_language( $post_id );
			return is_string( $lang ) ? $lang : '';
		}
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$details = apply_filters( 'wpml_post_language_details', null, $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
			return is_array( $details ) && ! empty( $details['language_code'] ) ? (string) $details['language_code'] : '';
		}
		return '';
	}

	/**
	 * Whether the post is built with a page builder whose data we must not edit.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_page_builder( $post_id ) {
		$checks = array(
			'_elementor_edit_mode' => 'builder',
			'_et_pb_use_builder'   => 'on',
			'_fl_builder_enabled'  => '1',
			'_wpb_vc_js_status'    => 'true',
			'_bricks_editor_mode'  => 'bricks',
		);
		foreach ( $checks as $key => $value ) {
			if ( (string) get_post_meta( $post_id, $key, true ) === $value ) {
				return true;
			}
		}
		if ( '' !== (string) get_post_meta( $post_id, 'ct_builder_shortcodes', true ) ) {
			return true;
		}

		/**
		 * Filters whether a post is managed by a page builder (view-only for this plugin).
		 *
		 * @param bool $is_builder Detected.
		 * @param int  $post_id    Post ID.
		 */
		return (bool) apply_filters( 'ailsug_is_page_builder', false, $post_id );
	}
}
