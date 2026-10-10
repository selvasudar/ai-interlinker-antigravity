<?php
/**
 * Read-only adapters for focus keywords and noindex flags of popular SEO plugins.
 *
 * @package AILSUG
 */

namespace AILSUG\Index;

use AILSUG\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Storage formats were verified against each plugin's source (2026-10-10):
 * - Yoast SEO: postmeta _yoast_wpseo_focuskw; noindex _yoast_wpseo_meta-robots-noindex (1 = noindex, 0/empty = post type default).
 * - Rank Math: postmeta rank_math_focus_keyword (comma separated, first is primary); rank_math_robots array.
 * - AIOSEO: {prefix}aioseo_posts.keyphrases JSON (focus.keyphrase, additional[].keyphrase); robots_default/robots_noindex.
 * - SEOPress: postmeta _seopress_analysis_target_kw (comma separated); _seopress_robots_index = 'yes' means noindex.
 *
 * Values are hints only: anything missing or malformed falls through silently.
 */
final class SeoPluginReader {

	/**
	 * Adapter definitions. Each declares the version range it was verified against.
	 *
	 * @return array<string,array{label:string,active:callable,min:string,version:callable}>
	 */
	public static function adapters() {
		return array(
			'yoast'    => array(
				'label'   => 'Yoast SEO',
				'active'  => static function () {
					return defined( 'WPSEO_VERSION' );
				},
				'version' => static function () {
					return defined( 'WPSEO_VERSION' ) ? (string) WPSEO_VERSION : '';
				},
				'min'     => '14.0',
			),
			'rankmath' => array(
				'label'   => 'Rank Math',
				'active'  => static function () {
					return defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' );
				},
				'version' => static function () {
					return defined( 'RANK_MATH_VERSION' ) ? (string) RANK_MATH_VERSION : '';
				},
				'min'     => '1.0.40',
			),
			'aioseo'   => array(
				'label'   => 'All in One SEO',
				'active'  => static function () {
					return defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' );
				},
				'version' => static function () {
					return defined( 'AIOSEO_VERSION' ) ? (string) AIOSEO_VERSION : '';
				},
				'min'     => '4.0.0',
			),
			'seopress' => array(
				'label'   => 'SEOPress',
				'active'  => static function () {
					return defined( 'SEOPRESS_VERSION' );
				},
				'version' => static function () {
					return defined( 'SEOPRESS_VERSION' ) ? (string) SEOPRESS_VERSION : '';
				},
				'min'     => '5.0',
			),
		);
	}

	/**
	 * Active adapters whose version is new enough (unknown versions are allowed).
	 *
	 * @return string[]
	 */
	public static function active() {
		if ( ! Settings::get( 'use_seo_adapters', true ) ) {
			return array();
		}
		$out = array();
		foreach ( self::adapters() as $key => $adapter ) {
			if ( ! call_user_func( $adapter['active'] ) ) {
				continue;
			}
			$version = (string) call_user_func( $adapter['version'] );
			if ( '' !== $version && version_compare( $version, $adapter['min'], '<' ) ) {
				continue;
			}
			$out[] = $key;
		}

		/**
		 * Filters which SEO plugin adapters are used.
		 *
		 * @param string[] $out Adapter keys.
		 */
		return (array) apply_filters( 'ailsug_active_seo_adapters', $out );
	}

	/**
	 * Focus keyword from an SEO plugin, or null.
	 *
	 * @param int $post_id Post ID.
	 * @return array{keyword:string,secondary:string[],plugin:string}|null
	 */
	public static function keyword( $post_id ) {
		$post_id = (int) $post_id;
		$found   = null;

		foreach ( self::active() as $adapter ) {
			$found = self::read_adapter( $adapter, $post_id );
			if ( $found ) {
				break;
			}
		}

		if ( ! $found && Settings::get( 'use_seo_adapters', true ) ) {
			/**
			 * Extra post meta keys holding a focus keyword (first non-empty wins).
			 * Each entry: array( 'meta_key' => string, 'separator' => string|'' ).
			 *
			 * @param array[] $sources Sources.
			 */
			$sources = (array) apply_filters( 'ailsug_seo_keyword_sources', array() );
			foreach ( $sources as $source ) {
				if ( empty( $source['meta_key'] ) ) {
					continue;
				}
				$list = self::split( get_post_meta( $post_id, (string) $source['meta_key'], true ), (string) ( $source['separator'] ?? '' ) );
				if ( $list ) {
					$found = array(
						'keyword'   => array_shift( $list ),
						'secondary' => array_slice( $list, 0, 4 ),
						'plugin'    => 'custom',
					);
					break;
				}
			}
		}//end if

		/**
		 * Filters the SEO-plugin keyword found for a post.
		 *
		 * @param array|null $found   Result.
		 * @param int        $post_id Post ID.
		 */
		$found = apply_filters( 'ailsug_seo_keyword', $found, $post_id );

		if ( ! is_array( $found ) || ! isset( $found['keyword'] ) || ! is_string( $found['keyword'] ) ) {
			return null;
		}
		$keyword = self::clean( $found['keyword'] );
		if ( '' === $keyword ) {
			return null;
		}
		return array(
			'keyword'   => $keyword,
			'secondary' => array_values( array_filter( array_map( array( __CLASS__, 'clean' ), (array) ( $found['secondary'] ?? array() ) ) ) ),
			'plugin'    => (string) ( $found['plugin'] ?? '' ),
		);
	}

	/**
	 * Read one adapter. Never throws.
	 *
	 * @param string $adapter Adapter key.
	 * @param int    $post_id Post ID.
	 * @return array|null
	 */
	private static function read_adapter( $adapter, $post_id ) {
		$list = array();
		switch ( $adapter ) {
			case 'yoast':
				$list = self::split( get_post_meta( $post_id, '_yoast_wpseo_focuskw', true ), '' );
				break;
			case 'rankmath':
				$list = self::split( get_post_meta( $post_id, 'rank_math_focus_keyword', true ), ',' );
				break;
			case 'seopress':
				$list = self::split( get_post_meta( $post_id, '_seopress_analysis_target_kw', true ), ',' );
				break;
			case 'aioseo':
				$row = self::aioseo_row( $post_id );
				if ( $row && ! empty( $row['keyphrases'] ) ) {
					$data = json_decode( (string) $row['keyphrases'], true );
					if ( is_array( $data ) ) {
						if ( isset( $data['focus']['keyphrase'] ) && is_string( $data['focus']['keyphrase'] ) ) {
							$list[] = $data['focus']['keyphrase'];
						}
						if ( isset( $data['additional'] ) && is_array( $data['additional'] ) ) {
							foreach ( $data['additional'] as $extra ) {
								if ( is_array( $extra ) && isset( $extra['keyphrase'] ) && is_string( $extra['keyphrase'] ) ) {
									$list[] = $extra['keyphrase'];
								}
							}
						}
					}
				}
				$list = array_values( array_filter( array_map( array( __CLASS__, 'clean' ), $list ) ) );
				break;
		}//end switch
		if ( ! $list ) {
			return null;
		}
		return array(
			'keyword'   => array_shift( $list ),
			'secondary' => array_slice( $list, 0, 4 ),
			'plugin'    => $adapter,
		);
	}

	/**
	 * Whether an SEO plugin marks the post noindex. Unknown counts as indexable.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_noindex( $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}
		$noindex = false;

		foreach ( self::active() as $adapter ) {
			switch ( $adapter ) {
				case 'yoast':
					$flag = (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
					if ( '1' === $flag ) {
						$noindex = true;
					} elseif ( '' === $flag || '0' === $flag ) {
						$titles  = get_option( 'wpseo_titles' );
						$noindex = is_array( $titles ) && ! empty( $titles[ 'noindex-' . $post->post_type ] );
					}
					break;
				case 'rankmath':
					$robots = get_post_meta( $post_id, 'rank_math_robots', true );
					if ( ! is_array( $robots ) || ! $robots ) {
						$options = get_option( 'rank-math-options-titles' );
						$robots  = array();
						if ( is_array( $options ) ) {
							if ( ! empty( $options[ 'pt_' . $post->post_type . '_custom_robots' ] ) && 'off' !== $options[ 'pt_' . $post->post_type . '_custom_robots' ] && isset( $options[ 'pt_' . $post->post_type . '_robots' ] ) ) {
								$robots = (array) $options[ 'pt_' . $post->post_type . '_robots' ];
							} elseif ( isset( $options['robots_global'] ) ) {
								$robots = (array) $options['robots_global'];
							}
						}
					}
					$noindex = in_array( 'noindex', $robots, true );
					break;
				case 'aioseo':
					$row     = self::aioseo_row( $post_id );
					$noindex = $row && empty( $row['robots_default'] ) && ! empty( $row['robots_noindex'] );
					break;
				case 'seopress':
					$noindex = 'yes' === (string) get_post_meta( $post_id, '_seopress_robots_index', true );
					break;
			}//end switch
			if ( $noindex ) {
				break;
			}
		}//end foreach

		/**
		 * Filters whether a post is noindex (noindex posts are never suggested as targets).
		 *
		 * @param bool $noindex Noindex.
		 * @param int  $post_id Post ID.
		 */
		return (bool) apply_filters( 'ailsug_is_noindex', $noindex, $post_id );
	}

	/**
	 * AIOSEO row for a post (null when the table is missing).
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	private static function aioseo_row( $post_id ) {
		global $wpdb;
		static $has_table = null;
		$table            = $wpdb->prefix . 'aioseo_posts';
		if ( null === $has_table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only schema probe.
			$has_table = ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) );
		}
		if ( ! $has_table ) {
			return null;
		}
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only lookup in another plugin's table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . esc_sql( $table ) . ' WHERE post_id = %d', $post_id ), ARRAY_A );
		$wpdb->suppress_errors( $suppress );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Split a stored value into clean keywords.
	 *
	 * @param mixed  $value     Raw value.
	 * @param string $separator Separator or '' for a single value.
	 * @return string[]
	 */
	private static function split( $value, $separator ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return array();
		}
		$parts = '' === $separator ? array( $value ) : explode( $separator, $value );
		return array_values( array_filter( array_map( array( __CLASS__, 'clean' ), $parts ) ) );
	}

	/**
	 * Normalize a keyword string.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function clean( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $value ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 120 ) : substr( $value, 0, 120 );
	}
}
