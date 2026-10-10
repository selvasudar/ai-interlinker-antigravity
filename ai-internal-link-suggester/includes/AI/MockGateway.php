<?php
/**
 * Deterministic fake gateway for tests and demos (AILSUG_MOCK_AI). Never touches the network.
 *
 * @package AILSUG
 */

namespace AILSUG\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Returns predictable answers with fake token counts so the UI and tests work with zero spend.
 */
class MockGateway extends Gateway {

	const PROVIDER = 'mock';
	const MODEL    = 'mock-model-1';

	/**
	 * Number of transport calls made (tests).
	 *
	 * @var int
	 */
	public $calls = 0;

	/**
	 * Fake transport.
	 *
	 * @param array $request Request.
	 * @return array|\WP_Error
	 */
	protected function transport( array $request ) {
		++$this->calls;

		/**
		 * Short-circuit the mock response (tests): return an array or WP_Error to use it.
		 *
		 * @param mixed $response Null to continue.
		 * @param array $request  Request.
		 */
		$override = apply_filters( 'ailsug_mock_response', null, $request );
		if ( null !== $override ) {
			return $override;
		}

		$data = isset( $request['data'] ) ? (array) $request['data'] : array();
		$body = 'rank' === $request['task'] ? $this->rank( $data ) : $this->keyword( $data );

		$text = (string) wp_json_encode( $body );
		return array(
			'text'          => $text,
			'input_tokens'  => (int) ceil( ( strlen( $request['system'] ) + strlen( $request['prompt'] ) ) / 4 ),
			'output_tokens' => (int) ceil( strlen( $text ) / 4 ),
			'provider'      => self::PROVIDER,
			'model'         => (string) apply_filters( 'ailsug_mock_model', self::MODEL, $request ),
		);
	}

	/**
	 * Fake keyword extraction from the title.
	 *
	 * @param array $data Payload with title/headings/text.
	 * @return array
	 */
	private function keyword( array $data ) {
		$stop  = array( 'a', 'an', 'the', 'and', 'or', 'of', 'to', 'in', 'for', 'on', 'with', 'how', 'what', 'why', 'is', 'are', 'your', 'our', 'my' );
		$words = preg_split( '/[^\p{L}\p{N}]+/u', function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) ( $data['title'] ?? '' ) ) : strtolower( (string) ( $data['title'] ?? '' ) ), -1, PREG_SPLIT_NO_EMPTY );
		$words = array_values( array_diff( $words, $stop ) );
		$kw    = implode( ' ', array_slice( $words, 0, 3 ) );

		$secondary = array();
		foreach ( (array) ( $data['headings'] ?? array() ) as $heading ) {
			$secondary[] = trim( function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $heading ) : strtolower( (string) $heading ) );
			if ( count( $secondary ) >= 4 ) {
				break;
			}
		}

		$summary = trim( (string) ( $data['text'] ?? '' ) );
		$summary = function_exists( 'mb_substr' ) ? mb_substr( $summary, 0, 150 ) : substr( $summary, 0, 150 );

		return array(
			'primary_keyword'    => '' !== $kw ? $kw : 'untitled page',
			'secondary_keywords' => $secondary,
			'search_intent'      => 'informational',
			'summary'            => $summary,
		);
	}

	/**
	 * Fake ranking: link a candidate when its keyword appears verbatim (case-insensitive) in a chunk.
	 *
	 * @param array $data Payload with chunks/candidates/max.
	 * @return array
	 */
	private function rank( array $data ) {
		$out   = array();
		$max   = max( 1, (int) ( $data['max'] ?? 5 ) );
		$score = 95;
		foreach ( (array) ( $data['candidates'] ?? array() ) as $candidate ) {
			$keyword = trim( (string) ( $candidate['keyword'] ?? '' ) );
			if ( '' === $keyword ) {
				continue;
			}
			foreach ( (array) ( $data['chunks'] ?? array() ) as $chunk ) {
				$pos = function_exists( 'mb_stripos' ) ? mb_stripos( (string) $chunk['text'], $keyword ) : stripos( (string) $chunk['text'], $keyword );
				if ( false === $pos ) {
					continue;
				}
				$anchor = function_exists( 'mb_substr' ) ? mb_substr( (string) $chunk['text'], $pos, mb_strlen( $keyword ) ) : substr( (string) $chunk['text'], $pos, strlen( $keyword ) );
				$out[]  = array(
					'target_id'   => (int) $candidate['id'],
					'chunk_id'    => (string) $chunk['chunk_id'],
					'anchor_text' => $anchor,
					'score'       => max( 1, $score ),
					'reason'      => 'Test mode: the paragraph mentions this page\'s main topic.',
				);
				$score -= 5;
				break;
			}
			if ( count( $out ) >= $max ) {
				break;
			}
		}//end foreach
		return array( 'suggestions' => $out );
	}
}
