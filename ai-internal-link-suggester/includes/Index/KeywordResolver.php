<?php
/**
 * Decides a page's primary keyword: manual > SEO plugin > AI.
 *
 * @package AILSUG
 */

namespace AILSUG\Index;

defined( 'ABSPATH' ) || exit;

/**
 * Keyword priority resolution. Never calls AI itself; it reports whether AI is needed.
 */
final class KeywordResolver {

	const META = '_ailsug_keyword';

	/**
	 * Resolve the keyword for a post.
	 *
	 * @param int        $post_id  Post ID.
	 * @param array|null $existing Existing index row (ARRAY_A) or null.
	 * @param string     $ai_hash  Current AI hash of the post.
	 * @return array{keyword:string,secondary:string[],source:string,needs_ai:bool}
	 */
	public static function resolve( $post_id, $existing, $ai_hash ) {
		$manual = SeoPluginReader::clean( (string) get_post_meta( (int) $post_id, self::META, true ) );
		if ( '' !== $manual ) {
			return array(
				'keyword'   => $manual,
				'secondary' => self::existing_secondary( $existing ),
				'source'    => 'manual',
				'needs_ai'  => false,
			);
		}

		$seo = SeoPluginReader::keyword( $post_id );
		if ( $seo ) {
			return array(
				'keyword'   => $seo['keyword'],
				'secondary' => $seo['secondary'] ? $seo['secondary'] : self::existing_secondary( $existing ),
				'source'    => 'seo_plugin',
				'needs_ai'  => false,
			);
		}

		if ( is_array( $existing ) && 'ai' === $existing['keyword_source'] && '' !== $existing['primary_keyword'] ) {
			return array(
				'keyword'   => (string) $existing['primary_keyword'],
				'secondary' => self::existing_secondary( $existing ),
				'source'    => 'ai',
				// Re-run AI only when what it reads has changed.
				'needs_ai'  => $existing['ai_hash'] !== $ai_hash,
			);
		}

		return array(
			'keyword'   => '',
			'secondary' => array(),
			'source'    => '',
			'needs_ai'  => true,
		);
	}

	/**
	 * Set or clear the manual keyword.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $keyword Keyword ('' clears).
	 * @return string The stored keyword.
	 */
	public static function set_manual( $post_id, $keyword ) {
		$keyword = SeoPluginReader::clean( $keyword );
		if ( '' === $keyword ) {
			delete_post_meta( (int) $post_id, self::META );
		} else {
			update_post_meta( (int) $post_id, self::META, $keyword );
		}
		return $keyword;
	}

	/**
	 * Secondary keywords from an existing row.
	 *
	 * @param array|null $existing Row.
	 * @return string[]
	 */
	private static function existing_secondary( $existing ) {
		if ( ! is_array( $existing ) || '' === (string) $existing['secondary_keywords'] ) {
			return array();
		}
		$list = json_decode( (string) $existing['secondary_keywords'], true );
		return is_array( $list ) ? array_values( array_filter( $list, 'is_string' ) ) : array();
	}
}
