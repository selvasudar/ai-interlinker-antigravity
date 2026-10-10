<?php
/**
 * Quality tiers, model preferences and a best-effort prediction of the model core will use.
 *
 * @package AILSUG
 */

namespace AILSUG\AI;

use AILSUG\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Model preferences are only hints: core may use a different compatible model. The
 * prediction here mirrors core's selection order (first preferred model that a configured
 * provider offers, else the first text model found) but is never treated as a price promise.
 */
final class ModelResolver {

	/**
	 * Preferred model ids per quality tier (verified against provider docs, 2026-10-10).
	 *
	 * @param string $tier economy|balanced|best.
	 * @return string[]
	 */
	public static function preferences( $tier = null ) {
		$tier = $tier ? $tier : (string) Settings::get( 'quality', 'economy' );
		$map  = array(
			'economy'  => array( 'claude-haiku-5-5', 'gpt-6-luna', 'gemini-3.1-flash-lite', 'gemini-3.5-flash-lite' ),
			'balanced' => array( 'claude-sonnet-5-5', 'gpt-6.1-sol', 'gemini-3.8-flash' ),
			'best'     => array( 'claude-opus-5-5', 'gpt-6-astra', 'gemini-3.1-pro-preview' ),
		);

		/**
		 * Filters the model preference lists per quality tier.
		 *
		 * @param array<string,string[]> $map Tier => ordered model ids.
		 */
		$map = apply_filters( 'ailsug_model_preferences', $map );

		return isset( $map[ $tier ] ) ? array_values( array_filter( (array) $map[ $tier ], 'is_string' ) ) : array();
	}

	/**
	 * Predict which model(s) a call may use.
	 *
	 * @param string|null $tier Quality tier.
	 * @return array{likely:array|null,plausible:array<int,array{0:string,1:string}>,firm:bool}
	 */
	public static function predict( $tier = null ) {
		$only = Availability::only_model();
		if ( $only ) {
			return array(
				'likely'    => $only,
				'plausible' => array( $only ),
				'firm'      => true,
			);
		}

		$candidates = Availability::text_models();
		$by_model   = array();
		$first      = null;
		foreach ( $candidates as $provider => $models ) {
			foreach ( $models as $model ) {
				if ( null === $first ) {
					$first = array( $provider, $model );
				}
				if ( ! isset( $by_model[ $model ] ) ) {
					$by_model[ $model ] = array( $provider, $model );
				}
			}
		}

		$likely = null;
		foreach ( self::preferences( $tier ) as $model ) {
			if ( isset( $by_model[ $model ] ) ) {
				$likely = $by_model[ $model ];
				break;
			}
		}

		$plausible = array();
		if ( $likely ) {
			$plausible[] = $likely;
		}
		// Core falls back to the first compatible model when no preference fits this prompt.
		if ( $first && $first !== $likely ) {
			$plausible[] = $first;
		}

		return array(
			'likely'    => $likely ? $likely : $first,
			'plausible' => $plausible,
			'firm'      => false,
		);
	}
}
