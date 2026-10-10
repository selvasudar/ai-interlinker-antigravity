<?php
/**
 * Map of the eligible text in raw post content: chunks, text segments and byte offsets.
 *
 * @package AILSUG
 */

namespace AILSUG\Suggest;

defined( 'ABSPATH' ) || exit;

/**
 * Shared by the suggestion engine (chunking, local matching, validation) and the applier
 * (re-locating anchors at apply time). Built from the raw post_content, never from rendered HTML.
 *
 * Eligible text is text outside links, headings, code/pre, captions, buttons, form controls,
 * scripts and styles, outside "raw" blocks (Custom HTML, code, shortcode, buttons, navigation,
 * embeds…) and outside shortcode-like [brackets].
 *
 * chunk_id = "b{top-level block index}p{container index within that block}".
 */
final class TextMap {

	const EXCLUDED_TAGS = array( 'A', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'CODE', 'PRE', 'KBD', 'SAMP', 'VAR', 'FIGCAPTION', 'CAPTION', 'BUTTON', 'LABEL', 'SELECT', 'OPTION', 'TEXTAREA', 'SCRIPT', 'STYLE', 'NOSCRIPT', 'IFRAME', 'SVG', 'MATH', 'TITLE', 'OBJECT', 'TEMPLATE', 'SUMMARY' );

	const CONTAINER_TAGS = array( 'P', 'LI', 'TD', 'TH', 'DD', 'DT' );

	const INLINE_TAGS = array( 'A', 'ABBR', 'B', 'BDI', 'BDO', 'BR', 'CITE', 'CODE', 'DATA', 'DEL', 'DFN', 'EM', 'I', 'IMG', 'INS', 'KBD', 'MARK', 'Q', 'S', 'SAMP', 'SMALL', 'SPAN', 'STRONG', 'SUB', 'SUP', 'TIME', 'U', 'VAR', 'WBR' );

	/**
	 * Raw HTML.
	 *
	 * @var string
	 */
	private $html;

	/**
	 * Segments: chunk, start, raw, text, pieces, shortcodes.
	 *
	 * @var array[]
	 */
	private $segments = array();

	/**
	 * Chunk ids in document order => segment indexes.
	 *
	 * @var array<string,int[]>
	 */
	private $chunks = array();

	/**
	 * Build a map.
	 *
	 * @param string $html Raw post_content.
	 */
	public function __construct( $html ) {
		$this->html = (string) $html;
		$this->scan();
	}

	/**
	 * Block names whose content is never touched.
	 *
	 * @return string[]
	 */
	public static function skip_blocks() {
		/**
		 * Filters block names (without "core/" prefix for core blocks) whose text is never linked.
		 *
		 * @param string[] $blocks Block names.
		 */
		return (array) apply_filters( 'ailsug_skip_blocks', array( 'html', 'code', 'preformatted', 'shortcode', 'buttons', 'button', 'navigation', 'navigation-link', 'embed', 'verse', 'freeform-html', 'table-of-contents', 'search', 'file', 'audio', 'video', 'more', 'nextpage', 'query', 'post-template', 'latest-posts' ) );
	}

	/**
	 * Walk tokens and collect eligible text segments.
	 *
	 * @return void
	 */
	private function scan() {
		if ( '' === trim( $this->html ) ) {
			return;
		}
		$scanner      = new Html_Scanner( $this->html );
		$skip         = self::skip_blocks();
		$block_stack  = array();
		$block_index  = -1;
		$container_n  = 0;
		$excluded     = 0;
		$exclude_tags = array();
		$containers   = array();
		$orphan       = null;

		while ( $scanner->next_token() ) {
			$type = $scanner->get_token_type();

			if ( '#comment' === $type ) {
				$comment = trim( $scanner->get_modifiable_text() );
				if ( preg_match( '#^(/)?wp:([a-z0-9_/-]+)(.*?)(/)?$#s', $comment, $m ) ) {
					$name = preg_replace( '#^core/#', '', $m[2] );
					if ( '/' === $m[1] ) {
						array_pop( $block_stack );
					} else {
						if ( ! $block_stack ) {
							++$block_index;
							$container_n = 0;
						}
						if ( empty( $m[4] ) ) {
							$block_stack[] = $name;
						}
					}
					$orphan = null;
				}
				continue;
			}

			if ( '#tag' === $type ) {
				$tag    = $scanner->get_tag();
				$closer = $scanner->is_tag_closer();
				if ( in_array( $tag, self::EXCLUDED_TAGS, true ) ) {
					if ( $closer ) {
						if ( ! empty( $exclude_tags[ $tag ] ) ) {
							--$exclude_tags[ $tag ];
							--$excluded;
						}
					} else {
						$exclude_tags[ $tag ] = ( $exclude_tags[ $tag ] ?? 0 ) + 1;
						++$excluded;
					}
				}
				if ( in_array( $tag, self::CONTAINER_TAGS, true ) ) {
					if ( $closer ) {
						array_pop( $containers );
					} else {
						if ( 'P' === $tag && $containers && 'P' === end( $containers )['tag'] ) {
							array_pop( $containers );
							// Implicitly closed paragraph.
						}
						$id           = 'b' . max( 0, $block_index ) . 'p' . ( $container_n++ );
						$containers[] = array(
							'tag' => $tag,
							'id'  => $id,
						);
					}
					$orphan = null;
				} elseif ( ! in_array( $tag, self::INLINE_TAGS, true ) ) {
					$orphan = null;
				}
				continue;
			}//end if

			if ( '#text' !== $type ) {
				continue;
			}
			$span = $scanner->current_span();
			if ( ! $span ) {
				continue;
			}
			if ( $excluded > 0 || array_intersect( $block_stack, $skip ) ) {
				continue;
			}
			$raw = substr( $this->html, $span['start'], $span['length'] );
			if ( '' === trim( $raw ) ) {
				continue;
			}

			if ( $containers ) {
				$this->add_segment( end( $containers )['id'], $span['start'], $raw );
				continue;
			}

			// Text outside any container (classic editor content): blank lines separate paragraphs.
			$parts = preg_split( '/(\r?\n[ \t]*\r?\n\s*)/', $raw, -1, PREG_SPLIT_OFFSET_CAPTURE | PREG_SPLIT_DELIM_CAPTURE );
			foreach ( $parts as $part ) {
				if ( preg_match( '/^\r?\n[ \t]*\r?\n\s*$/', $part[0] ) ) {
					$orphan = null;
					continue;
				}
				if ( '' === trim( $part[0] ) ) {
					continue;
				}
				if ( null === $orphan ) {
					$orphan = 'b' . max( 0, $block_index ) . 'p' . ( $container_n++ );
				}
				$this->add_segment( $orphan, $span['start'] + $part[1], $part[0] );
			}
		}//end while
	}

	/**
	 * Add a segment.
	 *
	 * @param string $chunk Chunk id.
	 * @param int    $start Raw byte offset.
	 * @param string $raw   Raw text.
	 * @return void
	 */
	private function add_segment( $chunk, $start, $raw ) {
		$decoded = self::decode( $raw );
		$shorts  = array();
		if ( preg_match_all( '/\[[^\[\]]*\]/u', $decoded['text'], $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $hit ) {
				$shorts[] = array( $hit[1], $hit[1] + strlen( $hit[0] ) );
			}
		}
		$this->segments[]         = array(
			'chunk'  => $chunk,
			'start'  => $start,
			'raw'    => $raw,
			'text'   => $decoded['text'],
			'pieces' => $decoded['pieces'],
			'shorts' => $shorts,
		);
		$this->chunks[ $chunk ][] = count( $this->segments ) - 1;
	}

	/**
	 * Decode entities while remembering how decoded bytes map to raw bytes.
	 *
	 * @param string $raw Raw text.
	 * @return array{text:string,pieces:array}
	 */
	public static function decode( $raw ) {
		$text   = '';
		$pieces = array();
		$pos    = 0;
		$len    = strlen( $raw );
		if ( preg_match_all( '/&(?:#[0-9]{1,7};?|#[xX][0-9a-fA-F]{1,6};?|[A-Za-z][A-Za-z0-9]{1,31};)/', $raw, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $hit ) {
				list( $entity, $at ) = $hit;
				$char                = html_entity_decode( $entity, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				if ( $char === $entity ) {
					continue;
					// Unknown entity: keep as literal text.
				}
				if ( $at > $pos ) {
					$pieces[] = array( strlen( $text ), $pos, $at - $pos, true );
					$text    .= substr( $raw, $pos, $at - $pos );
				}
				$pieces[] = array( strlen( $text ), $at, strlen( $entity ), false, strlen( $char ) );
				$text    .= $char;
				$pos      = $at + strlen( $entity );
			}
		}
		if ( $pos < $len ) {
			$pieces[] = array( strlen( $text ), $pos, $len - $pos, true );
			$text    .= substr( $raw, $pos );
		}
		return array(
			'text'   => $text,
			'pieces' => $pieces,
		);
	}

	/**
	 * Convert a decoded byte offset within a segment to a raw byte offset (relative to the segment).
	 *
	 * @param array $pieces Pieces from decode().
	 * @param int   $offset Decoded offset.
	 * @return int|null Null when the offset falls inside an entity.
	 */
	private static function raw_offset( array $pieces, $offset ) {
		foreach ( $pieces as $p ) {
			$dec_len = $p[3] ? $p[2] : $p[4];
			if ( $offset < $p[0] || $offset > $p[0] + $dec_len ) {
				continue;
			}
			if ( $p[3] ) {
				return $p[1] + ( $offset - $p[0] );
			}
			if ( $offset === $p[0] ) {
				return $p[1];
			}
			if ( $offset === $p[0] + $dec_len ) {
				return $p[1] + $p[2];
			}
			return null;
		}
		return 0 === $offset ? 0 : null;
	}

	/**
	 * Chunks with their plain text, in document order.
	 *
	 * @return array<string,string>
	 */
	public function chunks() {
		$out = array();
		foreach ( $this->chunks as $id => $indexes ) {
			$text = '';
			$end  = null;
			foreach ( $indexes as $i ) {
				$seg   = $this->segments[ $i ];
				$text .= ( null !== $end && $seg['start'] !== $end ? ' ' : '' ) . $seg['text'];
				$end   = $seg['start'] + strlen( $seg['raw'] );
			}
			$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
			if ( '' !== $text ) {
				$out[ $id ] = $text;
			}
		}
		return $out;
	}

	/**
	 * Whether a chunk id exists.
	 *
	 * @param string $chunk_id Chunk id.
	 * @return bool
	 */
	public function has_chunk( $chunk_id ) {
		return isset( $this->chunks[ (string) $chunk_id ] );
	}

	/**
	 * Find eligible occurrences of a phrase (whole words; within a single text segment).
	 *
	 * By default case-sensitive matches win and case-insensitive matches are only returned when
	 * there is no exact match anywhere (used to validate verbatim anchors). With $any_case every
	 * match regardless of case is returned (used for local keyword scoring).
	 *
	 * @param string      $phrase   Phrase.
	 * @param string|null $chunk_id Restrict to one chunk.
	 * @param bool        $any_case Return all matches regardless of case.
	 * @return array[] Each: chunk, segment, raw_start, raw_end, text.
	 */
	public function find( $phrase, $chunk_id = null, $any_case = false ) {
		$words = preg_split( '/\s+/u', trim( (string) $phrase ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! $words ) {
			return array();
		}
		$body = implode(
			'\s+',
			array_map(
				static function ( $w ) {
					return preg_quote( $w, '/' );
				},
				$words
			)
		);
		foreach ( $any_case ? array( 'iu' ) : array( 'u', 'iu' ) as $flags ) {
			$pattern = '/(?<![\p{L}\p{N}\p{M}_])' . $body . '(?![\p{L}\p{N}\p{M}_])/' . $flags;
			$found   = array();
			foreach ( $this->segments as $i => $seg ) {
				if ( null !== $chunk_id && $seg['chunk'] !== $chunk_id ) {
					continue;
				}
				if ( ! preg_match_all( $pattern, $seg['text'], $m, PREG_OFFSET_CAPTURE ) ) {
					continue;
				}
				foreach ( $m[0] as $hit ) {
					$dec_start = $hit[1];
					$dec_end   = $hit[1] + strlen( $hit[0] );
					foreach ( $seg['shorts'] as $short ) {
						if ( $dec_start < $short[1] && $dec_end > $short[0] ) {
							continue 2;
						}
					}
					$rs = self::raw_offset( $seg['pieces'], $dec_start );
					$re = self::raw_offset( $seg['pieces'], $dec_end );
					if ( null === $rs || null === $re ) {
						continue;
					}
					$found[] = array(
						'chunk'     => $seg['chunk'],
						'segment'   => $i,
						'raw_start' => $seg['start'] + $rs,
						'raw_end'   => $seg['start'] + $re,
						'text'      => $hit[0],
					);
				}//end foreach
			}//end foreach
			if ( $found ) {
				return $found;
			}
		}//end foreach
		return array();
	}

	/**
	 * Plain-text context around an occurrence, taken from its chunk (for previews and snippets).
	 *
	 * @param array $occurrence From find().
	 * @param int   $chars      Characters each side.
	 * @return array{before:string,match:string,after:string}
	 */
	public function context( array $occurrence, $chars = 80 ) {
		$before = '';
		$after  = '';
		$seen   = false;
		$end    = null;
		foreach ( $this->chunks[ $occurrence['chunk'] ] ?? array() as $i ) {
			$seg = $this->segments[ $i ];
			$gap = ( null !== $end && $seg['start'] !== $end ) ? ' ' : '';
			$end = $seg['start'] + strlen( $seg['raw'] );
			if ( $i === $occurrence['segment'] ) {
				$rel_start = strlen( self::decode( substr( $seg['raw'], 0, $occurrence['raw_start'] - $seg['start'] ) )['text'] );
				$rel_end   = strlen( self::decode( substr( $seg['raw'], 0, $occurrence['raw_end'] - $seg['start'] ) )['text'] );
				$before   .= $gap . substr( $seg['text'], 0, $rel_start );
				$after    .= substr( $seg['text'], $rel_end );
				$seen      = true;
			} elseif ( $seen ) {
				$after .= $gap . $seg['text'];
			} else {
				$before .= $gap . $seg['text'];
			}
		}
		$before = preg_replace( '/\s+/u', ' ', $before );
		$after  = preg_replace( '/\s+/u', ' ', $after );
		return array(
			'before' => function_exists( 'mb_substr' ) ? mb_substr( $before, -$chars ) : substr( $before, -$chars ),
			'match'  => (string) $occurrence['text'],
			'after'  => function_exists( 'mb_substr' ) ? mb_substr( $after, 0, $chars ) : substr( $after, 0, $chars ),
		);
	}

	/**
	 * Word position of an occurrence within all eligible text (for link spacing rules).
	 *
	 * @param array $occurrence From find().
	 * @return int
	 */
	public function word_position( array $occurrence ) {
		$words = 0;
		foreach ( $this->segments as $i => $seg ) {
			if ( $i === $occurrence['segment'] ) {
				$before = self::decode( substr( $seg['raw'], 0, $occurrence['raw_start'] - $seg['start'] ) )['text'];
				return $words + (int) preg_match_all( '/[\p{L}\p{N}]+/u', $before );
			}
			$words += (int) preg_match_all( '/[\p{L}\p{N}]+/u', $seg['text'] );
		}
		return $words;
	}

	/**
	 * Raw HTML.
	 *
	 * @return string
	 */
	public function html() {
		return $this->html;
	}
}
