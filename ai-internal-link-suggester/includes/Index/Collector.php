<?php
/**
 * Local (free) analysis of a post: hashes, words, headings and internal links.
 *
 * @package AILSUG
 */

namespace AILSUG\Index;

defined( 'ABSPATH' ) || exit;

/**
 * Parses raw post_content with WP_HTML_Tag_Processor. Read-only: never changes content.
 */
final class Collector {

	/**
	 * URL → post ID cache for this request.
	 *
	 * @var array<string,int>
	 */
	private static $url_cache = array();

	/**
	 * Hash of the raw content (used to detect edits and validate apply/revert).
	 *
	 * @param string $content Raw post_content.
	 * @return string
	 */
	public static function content_hash( $content ) {
		return md5( (string) $content );
	}

	/**
	 * Hash of what the keyword AI step reads (title + content).
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public static function ai_hash( \WP_Post $post ) {
		return md5( $post->post_title . "\0" . $post->post_content );
	}

	/**
	 * Analyze a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array{content_hash:string,ai_hash:string,word_count:int,headings:string[],text:string,links:array<int,array{target:int,anchor:string}>}
	 */
	public static function collect( \WP_Post $post ) {
		$parsed  = self::parse( $post->post_content );
		$targets = array();
		foreach ( $parsed['links'] as $link ) {
			$target = self::resolve_internal( $link['href'] );
			if ( $target && $target !== (int) $post->ID ) {
				$targets[] = array(
					'target' => $target,
					'anchor' => $link['text'],
				);
			}
		}

		return array(
			'content_hash' => self::content_hash( $post->post_content ),
			'ai_hash'      => self::ai_hash( $post ),
			'word_count'   => self::word_count( $parsed['text'] ),
			'headings'     => $parsed['headings'],
			'text'         => $parsed['text'],
			'links'        => $targets,
		);
	}

	/**
	 * Walk HTML tokens and pull out plain text, headings and links.
	 *
	 * @param string $html Raw HTML (may contain block comments).
	 * @return array{text:string,headings:string[],links:array<int,array{href:string,text:string}>}
	 */
	public static function parse( $html ) {
		$out  = array(
			'text'     => '',
			'headings' => array(),
			'links'    => array(),
		);
		$html = (string) $html;
		if ( '' === trim( $html ) ) {
			return $out;
		}

		$processor = new \WP_HTML_Tag_Processor( $html );
		$text      = '';
		$heading   = null;
		$link      = null;
		$block     = array( 'P', 'LI', 'DIV', 'BR', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'TD', 'TH', 'BLOCKQUOTE', 'FIGCAPTION', 'PRE', 'SECTION', 'ARTICLE', 'UL', 'OL', 'TR' );

		while ( $processor->next_token() ) {
			$type = $processor->get_token_type();
			if ( '#text' === $type ) {
				$chunk = $processor->get_modifiable_text();
				$text .= $chunk;
				if ( null !== $heading ) {
					$heading .= $chunk;
				}
				if ( null !== $link ) {
					$link['text'] .= $chunk;
				}
				continue;
			}
			if ( '#tag' !== $type ) {
				continue;
			}
			$name   = $processor->get_tag();
			$closer = $processor->is_tag_closer();

			if ( in_array( $name, $block, true ) ) {
				$text .= "\n";
			}
			if ( preg_match( '/^H[1-6]$/', $name ) ) {
				if ( $closer && null !== $heading ) {
					$clean = trim( preg_replace( '/\s+/u', ' ', $heading ) );
					if ( '' !== $clean ) {
						$out['headings'][] = $clean;
					}
					$heading = null;
				} elseif ( ! $closer ) {
					$heading = '';
				}
			} elseif ( 'A' === $name ) {
				if ( $closer && null !== $link ) {
					$link['text']   = trim( preg_replace( '/\s+/u', ' ', $link['text'] ) );
					$out['links'][] = $link;
					$link           = null;
				} elseif ( ! $closer ) {
					$href = $processor->get_attribute( 'href' );
					$link = is_string( $href ) ? array(
						'href' => $href,
						'text' => '',
					) : null;
				}
			}//end if
		}//end while

		$text        = function_exists( 'strip_shortcodes' ) ? strip_shortcodes( $text ) : $text;
		$out['text'] = trim( preg_replace( "/[ \t]*\n[\s]*/u", "\n", preg_replace( '/[ \t]+/u', ' ', $text ) ) );
		return $out;
	}

	/**
	 * Unicode-aware word count.
	 *
	 * @param string $text Plain text.
	 * @return int
	 */
	public static function word_count( $text ) {
		return (int) preg_match_all( '/[\p{L}\p{N}][\p{L}\p{N}\p{M}\'’-]*/u', (string) $text );
	}

	/**
	 * First N words of plain text.
	 *
	 * @param string $text  Text.
	 * @param int    $words Word limit.
	 * @return string
	 */
	public static function opening( $text, $words ) {
		$parts = preg_split( '/\s+/u', trim( (string) $text ), (int) $words + 1 );
		if ( count( $parts ) > $words ) {
			array_pop( $parts );
		}
		return implode( ' ', $parts );
	}

	/**
	 * Resolve an href to a local post ID, or 0 for external/unknown links.
	 *
	 * @param string $href Href.
	 * @return int
	 */
	public static function resolve_internal( $href ) {
		$href = trim( html_entity_decode( (string) $href, ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $href || '#' === $href[0] || preg_match( '/^(mailto|tel|javascript|data|sms):/i', $href ) ) {
			return 0;
		}
		if ( isset( self::$url_cache[ $href ] ) ) {
			return self::$url_cache[ $href ];
		}

		$home  = wp_parse_url( home_url() );
		$parts = wp_parse_url( $href );
		if ( false === $parts ) {
			self::$url_cache[ $href ] = 0;
			return 0;
		}
		if ( ! empty( $parts['host'] ) ) {
			$host = strtolower( preg_replace( '/^www\./i', '', $parts['host'] ) );
			$self = strtolower( preg_replace( '/^www\./i', '', (string) ( $home['host'] ?? '' ) ) );
			if ( $host !== $self ) {
				self::$url_cache[ $href ] = 0;
				return 0;
			}
			$url = $href;
		} elseif ( 0 === strpos( $href, '//' ) ) {
			self::$url_cache[ $href ] = 0;
			return 0;
		} else {
			$url = 0 === strpos( $href, '/' ) ? ( $home['scheme'] ?? 'https' ) . '://' . ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) . $href : home_url( '/' . $href );
		}

		$url = strtok( $url, '#' );
		$id  = (int) url_to_postid( $url );

		/**
		 * Filters the post ID an internal URL resolves to.
		 *
		 * @param int    $id   Post ID or 0.
		 * @param string $href Original href.
		 */
		$id = (int) apply_filters( 'ailsug_resolve_internal_url', $id, $href );

		self::$url_cache[ $href ] = $id;
		return $id;
	}

	/**
	 * Clear the URL cache (tests).
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$url_cache = array();
	}
}
