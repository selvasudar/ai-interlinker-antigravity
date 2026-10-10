<?php
/**
 * Token and cost estimates. Every number here is an estimate and is labelled as such.
 *
 * @package AILSUG
 */

namespace AILSUG\Cost;

use AILSUG\AI\ModelResolver;
use AILSUG\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Heuristic token counts (characters per token, by script), calibrated against real usage.
 */
final class Estimator {

	/**
	 * Default assumptions (filterable).
	 *
	 * @return array
	 */
	public static function assumptions() {
		$defaults = array(
			// Stage A (keyword): title + headings + opening words.
			'keyword_base_input'    => 250,
			'keyword_output'        => 100,
			'keyword_max_output'    => 300,
			// Stage B (ranking): instructions + chunks + candidates.
			'rank_base_input'       => 450,
			'rank_tokens_per_chunk' => 90,
			'rank_tokens_per_cand'  => 45,
			'rank_tokens_per_sugg'  => 70,
			'rank_output_base'      => 30,
			'rank_max_output_base'  => 200,
			'rank_max_output_sugg'  => 120,
			// Words → tokens for Latin-script text.
			'tokens_per_word'       => 1.35,
			// Uncertainty band applied to token estimates.
			'low_factor'            => 0.8,
			'high_factor'           => 1.3,
		);

		/**
		 * Filters estimation assumptions.
		 *
		 * @param array $defaults Assumptions.
		 */
		return array_merge( $defaults, (array) apply_filters( 'ailsug_estimate_assumptions', $defaults ) );
	}

	/**
	 * Characters per token for a text, by dominant script. Conservative for non-Latin scripts.
	 *
	 * @param string $text Text.
	 * @return float
	 */
	public static function chars_per_token( $text ) {
		$text  = (string) $text;
		$total = max( 1, (int) preg_match_all( '/\p{L}/u', $text ) );
		$cjk   = (int) preg_match_all( '/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}\p{Thai}]/u', $text );
		$latin = (int) preg_match_all( '/\p{Latin}/u', $text );

		$ratios = array(
			'latin' => 4.0,
			'cjk'   => 1.0,
			'other' => 2.0,
		);

		/**
		 * Filters characters-per-token ratios by script group.
		 *
		 * @param array $ratios latin|cjk|other => float.
		 */
		$ratios = (array) apply_filters( 'ailsug_chars_per_token', $ratios );

		if ( $cjk / $total > 0.3 ) {
			return (float) $ratios['cjk'];
		}
		if ( $latin / $total < 0.7 ) {
			return (float) $ratios['other'];
		}
		return (float) $ratios['latin'];
	}

	/**
	 * Estimated tokens for a text.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public static function tokens( $text ) {
		$text  = (string) $text;
		$chars = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
		return (int) ceil( $chars / max( 0.5, self::chars_per_token( $text ) ) );
	}

	/**
	 * Max output tokens for a task.
	 *
	 * @param string $task keyword|rank.
	 * @param int    $suggestions Max suggestions.
	 * @return int
	 */
	public static function max_output( $task, $suggestions = 5 ) {
		$a = self::assumptions();
		if ( 'keyword' === $task ) {
			return (int) $a['keyword_max_output'];
		}
		return (int) $a['rank_max_output_base'] + (int) $a['rank_max_output_sugg'] * max( 1, (int) $suggestions );
	}

	/**
	 * Per-post token estimate without real content (calculator / planning).
	 *
	 * @param string $task       keyword|rank.
	 * @param array  $params     words, candidates, suggestions, chunks.
	 * @return array{input:int,output:int}
	 */
	public static function planned_tokens( $task, array $params ) {
		$a           = self::assumptions();
		$f           = Calibrator::factors( $task );
		$words       = max( 0, (int) ( $params['words'] ?? 800 ) );
		$candidates  = max( 1, (int) ( $params['candidates'] ?? Settings::get( 'shortlist_size', 15 ) ) );
		$suggestions = max( 1, (int) ( $params['suggestions'] ?? Settings::get( 'max_suggestions', 5 ) ) );
		$chunks      = max( 1, (int) ( $params['chunks'] ?? Settings::get( 'max_chunks', 6 ) ) );
		$excerpt     = (int) Settings::get( 'excerpt_words', 300 );

		if ( 'keyword' === $task ) {
			$input  = (int) $a['keyword_base_input'] + (int) ceil( min( $words, $excerpt ) * (float) $a['tokens_per_word'] ) + 40;
			$output = (int) $a['keyword_output'];
		} else {
			$input  = (int) $a['rank_base_input'] + $chunks * (int) $a['rank_tokens_per_chunk'] + $candidates * (int) $a['rank_tokens_per_cand'];
			$output = (int) $a['rank_output_base'] + $suggestions * (int) $a['rank_tokens_per_sugg'];
		}
		return array(
			'input'  => (int) ceil( $input * $f['input'] ),
			'output' => (int) ceil( $output * $f['output'] ),
		);
	}

	/**
	 * Price range over the plausible models for a tier.
	 *
	 * @param string|null $tier Tier.
	 * @return array{models:array,low:array|null,high:array|null,unknown:string[],firm:bool,likely:array|null}
	 */
	public static function price_range( $tier = null ) {
		$prediction = ModelResolver::predict( $tier );
		$models     = array();
		$unknown    = array();
		$low        = null;
		$high       = null;
		foreach ( $prediction['plausible'] as $pm ) {
			$price = Pricing::price_for( $pm[0], $pm[1] );
			if ( ! $price ) {
				$unknown[] = $pm[1];
				continue;
			}
			$models[] = array(
				'provider' => $pm[0],
				'model'    => $pm[1],
				'input'    => $price['input'],
				'output'   => $price['output'],
			);
			$weight   = $price['input'] + 4 * $price['output'];
			if ( null === $low || $weight < $low['input'] + 4 * $low['output'] ) {
				$low = $price;
			}
			if ( null === $high || $weight > $high['input'] + 4 * $high['output'] ) {
				$high = $price;
			}
		}
		return array(
			'models'  => $models,
			'low'     => $low,
			'high'    => $high,
			'unknown' => $unknown,
			'firm'    => $prediction['firm'],
			'likely'  => $prediction['likely'],
		);
	}

	/**
	 * Worst-case price among plausible models (for reservations), or null if any is unknown.
	 *
	 * @return array{input:int,output:int}|null
	 */
	public static function worst_price() {
		$range = self::price_range();
		if ( $range['unknown'] || ! $range['high'] ) {
			return null;
		}
		$input  = 0;
		$output = 0;
		foreach ( $range['models'] as $m ) {
			$input  = max( $input, (int) $m['input'] );
			$output = max( $output, (int) $m['output'] );
		}
		return array(
			'input'  => $input,
			'output' => $output,
		);
	}

	/**
	 * Cost range for given total tokens.
	 *
	 * @param int        $input  Input tokens.
	 * @param int        $output Output tokens.
	 * @param array|null $range  From price_range().
	 * @return array{low:int|null,high:int|null,known:bool,partial:bool}
	 */
	public static function cost_range( $input, $output, $range = null ) {
		$a     = self::assumptions();
		$range = $range ? $range : self::price_range();
		if ( ! $range['low'] ) {
			return array(
				'low'     => null,
				'high'    => null,
				'known'   => false,
				'partial' => false,
			);
		}
		$low  = Pricing::cost( (int) floor( $input * $a['low_factor'] ), $range['low']['input'] ) + Pricing::cost( (int) floor( $output * $a['low_factor'] ), $range['low']['output'] );
		$high = Pricing::cost( (int) ceil( $input * $a['high_factor'] ), $range['high']['input'] ) + Pricing::cost( (int) ceil( $output * $a['high_factor'] ), $range['high']['output'] );
		return array(
			'low'     => $low,
			'high'    => $high,
			'known'   => true,
			'partial' => (bool) $range['unknown'],
		);
	}

	/**
	 * Calculator: plan costs for a site.
	 *
	 * @param array $p posts, words, candidates, suggestions, new_per_month, tier, keyword_share (0..1).
	 * @return array
	 */
	public static function calculate( array $p ) {
		$posts         = max( 0, (int) ( $p['posts'] ?? 0 ) );
		$new           = max( 0, (int) ( $p['new_per_month'] ?? 0 ) );
		$keyword_share = isset( $p['keyword_share'] ) ? max( 0.0, min( 1.0, (float) $p['keyword_share'] ) ) : 1.0;
		$range         = self::price_range( $p['tier'] ?? null );

		$kw   = self::planned_tokens( 'keyword', $p );
		$rank = self::planned_tokens( 'rank', $p );

		$per_post_in  = (int) ceil( $kw['input'] * $keyword_share ) + $rank['input'];
		$per_post_out = (int) ceil( $kw['output'] * $keyword_share ) + $rank['output'];

		$full     = self::cost_range( $per_post_in * $posts, $per_post_out * $posts, $range );
		$per_post = self::cost_range( $kw['input'] + $rank['input'], $kw['output'] + $rank['output'], $range );
		$monthly  = self::cost_range( ( $kw['input'] + $rank['input'] ) * $new, ( $kw['output'] + $rank['output'] ) * $new, $range );
		$sugg     = max( 1, (int) ( $p['suggestions'] ?? Settings::get( 'max_suggestions', 5 ) ) );
		$per_sugg = $per_post['known'] ? array(
			'low'     => intdiv( (int) $per_post['low'], $sugg ),
			'high'    => (int) ceil( $per_post['high'] / $sugg ),
			'known'   => true,
			'partial' => $per_post['partial'],
		) : $per_post;

		return array(
			'tokens'      => array(
				'keyword' => $kw,
				'rank'    => $rank,
			),
			'full_scan'   => $full,
			'per_post'    => $per_post,
			'monthly'     => $monthly,
			'per_sugg'    => $per_sugg,
			'models'      => $range['models'],
			'unknown'     => $range['unknown'],
			'firm'        => $range['firm'],
			'calibration' => array(
				'keyword' => Calibrator::factors( 'keyword' ),
				'rank'    => Calibrator::factors( 'rank' ),
			),
			'assumptions' => self::assumptions(),
		);
	}

	/**
	 * Estimate a concrete run from real word counts.
	 *
	 * @param array $posts List of ['words' => int, 'keyword' => bool, 'rank' => bool].
	 * @return array{posts:int,input:int,output:int,max_output:int,low:int|null,high:int|null,known:bool,partial:bool,worst:int|null,per_post_worst:array<int,int>,firm:bool,unknown:string[],likely:array|null}
	 */
	public static function for_run( array $posts ) {
		$range      = self::price_range();
		$worst      = self::worst_price();
		$input      = 0;
		$output     = 0;
		$max_output = 0;
		$per_post   = array();
		$a          = self::assumptions();
		foreach ( $posts as $post ) {
			$pin  = 0;
			$pout = 0;
			$pmax = 0;
			foreach ( array( 'keyword', 'rank' ) as $task ) {
				if ( empty( $post[ $task ] ) ) {
					continue;
				}
				$tok   = self::planned_tokens( $task, array( 'words' => (int) $post['words'] ) );
				$pin  += $tok['input'];
				$pout += $tok['output'];
				$pmax += self::max_output( $task, (int) Settings::get( 'max_suggestions', 5 ) );
			}
			$input      += $pin;
			$output     += $pout;
			$max_output += $pmax;
			$per_post[]  = $worst ? Pricing::cost( (int) ceil( $pin * $a['high_factor'] ), $worst['input'] ) + Pricing::cost( $pmax, $worst['output'] ) : 0;
		}
		$cost = self::cost_range( $input, $output, $range );
		return array(
			'posts'          => count( $posts ),
			'input'          => $input,
			'output'         => $output,
			'max_output'     => $max_output,
			'low'            => $cost['low'],
			'high'           => $cost['high'],
			'known'          => $cost['known'],
			'partial'        => $cost['partial'],
			'worst'          => $worst ? array_sum( $per_post ) : null,
			'per_post_worst' => $per_post,
			'firm'           => $range['firm'],
			'unknown'        => $range['unknown'],
			'likely'         => $range['likely'],
		);
	}
}
