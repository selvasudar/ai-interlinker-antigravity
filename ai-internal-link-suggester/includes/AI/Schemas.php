<?php
/**
 * JSON schemas for AI answers.
 *
 * @package AILSUG
 */

namespace AILSUG\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Kept simple so every provider accepts them; answers are validated locally anyway.
 */
final class Schemas {

	/**
	 * Schema for a task.
	 *
	 * @param string $task keyword|rank.
	 * @return array
	 */
	public static function get( $task ) {
		$schemas = array(
			'keyword' => array(
				'type'                 => 'object',
				'properties'           => array(
					'primary_keyword'    => array( 'type' => 'string' ),
					'secondary_keywords' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'search_intent'      => array(
						'type' => 'string',
						'enum' => array( 'informational', 'commercial', 'transactional', 'navigational' ),
					),
					'summary'            => array( 'type' => 'string' ),
				),
				'required'             => array( 'primary_keyword', 'secondary_keywords', 'search_intent', 'summary' ),
				'additionalProperties' => false,
			),
			'rank'    => array(
				'type'                 => 'object',
				'properties'           => array(
					'suggestions' => array(
						'type'  => 'array',
						'items' => array(
							'type'                 => 'object',
							'properties'           => array(
								'target_id'   => array( 'type' => 'integer' ),
								'chunk_id'    => array( 'type' => 'string' ),
								'anchor_text' => array( 'type' => 'string' ),
								'score'       => array( 'type' => 'integer' ),
								'reason'      => array( 'type' => 'string' ),
							),
							'required'             => array( 'target_id', 'chunk_id', 'anchor_text', 'score', 'reason' ),
							'additionalProperties' => false,
						),
					),
				),
				'required'             => array( 'suggestions' ),
				'additionalProperties' => false,
			),
		);

		/**
		 * Filters the JSON schema sent with a task.
		 *
		 * @param array  $schema Schema.
		 * @param string $task   Task.
		 */
		return (array) apply_filters( 'ailsug_schema', $schemas[ $task ] ?? array(), $task );
	}

	/**
	 * Normalize and validate a keyword answer.
	 *
	 * @param mixed $data Decoded JSON.
	 * @return array|null
	 */
	public static function clean_keyword( $data ) {
		if ( ! is_array( $data ) || ! isset( $data['primary_keyword'] ) || ! is_string( $data['primary_keyword'] ) ) {
			return null;
		}
		$primary = \AILSUG\Index\SeoPluginReader::clean( $data['primary_keyword'] );
		if ( '' === $primary ) {
			return null;
		}
		$secondary = array();
		foreach ( (array) ( $data['secondary_keywords'] ?? array() ) as $kw ) {
			$kw = \AILSUG\Index\SeoPluginReader::clean( $kw );
			if ( '' !== $kw && $kw !== $primary ) {
				$secondary[] = $kw;
			}
		}
		$intent  = isset( $data['search_intent'] ) && in_array( $data['search_intent'], array( 'informational', 'commercial', 'transactional', 'navigational' ), true ) ? $data['search_intent'] : '';
		$summary = isset( $data['summary'] ) && is_string( $data['summary'] ) ? sanitize_text_field( $data['summary'] ) : '';
		return array(
			'primary_keyword'    => $primary,
			'secondary_keywords' => array_slice( array_values( array_unique( $secondary ) ), 0, 4 ),
			'search_intent'      => $intent,
			'summary'            => function_exists( 'mb_substr' ) ? mb_substr( $summary, 0, 160 ) : substr( $summary, 0, 160 ),
		);
	}
}
