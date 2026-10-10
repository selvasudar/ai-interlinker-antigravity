<?php
/**
 * Small text helpers: tokenizing and light stemming.
 *
 * @package AILSUG
 */

namespace AILSUG\Suggest;

defined( 'ABSPATH' ) || exit;

/**
 * Light, language-agnostic tokenizer with an English-oriented suffix stemmer for Latin script.
 */
final class Text {

	/**
	 * Lowercase (multibyte safe).
	 *
	 * @param string $s Text.
	 * @return string
	 */
	public static function lower( $s ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $s, 'UTF-8' ) : strtolower( (string) $s );
	}

	/**
	 * Word tokens.
	 *
	 * @param string $s Text.
	 * @return string[]
	 */
	public static function tokens( $s ) {
		return preg_split( '/[^\p{L}\p{N}\p{M}\'’]+/u', self::lower( $s ), -1, PREG_SPLIT_NO_EMPTY );
	}

	/**
	 * Light stem: strips common English suffixes from Latin-script words only.
	 *
	 * @param string $word Lowercase word.
	 * @return string
	 */
	public static function stem( $word ) {
		$word = trim( $word, "'’" );
		if ( ! preg_match( '/^\p{Latin}+$/u', $word ) || strlen( $word ) <= 3 ) {
			return $word;
		}
		$rules = array(
			'ies'  => 'y',
			'sses' => 'ss',
			'ing'  => '',
			'ed'   => '',
			'es'   => '',
			'ly'   => '',
			's'    => '',
		);
		foreach ( $rules as $suffix => $replace ) {
			$len = strlen( $suffix );
			if ( substr( $word, -$len ) === $suffix && strlen( $word ) - $len >= 3 ) {
				if ( 's' === $suffix && 'ss' === substr( $word, -2 ) ) {
					continue;
				}
				return substr( $word, 0, -$len ) . $replace;
			}
		}
		return $word;
	}

	/**
	 * Stemmed tokens.
	 *
	 * @param string $s Text.
	 * @return string[]
	 */
	public static function stems( $s ) {
		return array_map( array( __CLASS__, 'stem' ), self::tokens( $s ) );
	}

	/**
	 * Very common words ignored for partial matching.
	 *
	 * @param string $word Word.
	 * @return bool
	 */
	public static function is_stopword( $word ) {
		static $stop = null;
		if ( null === $stop ) {
			$stop = array_flip( array( 'a', 'an', 'the', 'and', 'or', 'of', 'to', 'in', 'for', 'on', 'with', 'at', 'by', 'from', 'how', 'what', 'why', 'is', 'are', 'your', 'our', 'my', 'you', 'we', 'it', 'its', 'this', 'that', 'be', 'as', 'guide', 'best' ) );
		}
		return isset( $stop[ $word ] );
	}
}
