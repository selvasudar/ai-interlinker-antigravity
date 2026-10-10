<?php
/**
 * Settings storage: defaults, access and sanitization.
 *
 * @package AILSUG
 */

namespace AILSUG;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for plugin settings (stored in one non-autoloaded option).
 */
final class Settings {

	const OPTION = 'ailsug_settings';

	/**
	 * Per-request cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default settings. Money is stored as integer micros (millionths of USD).
	 *
	 * @return array
	 */
	public static function defaults() {
		$defaults = array(
			// General.
			'post_types'          => self::default_post_types(),
			'statuses'            => array( 'publish' ),
			'excluded_posts'      => array(),
			'excluded_terms'      => array(),
			'update_mode'         => 'manual',
			'use_seo_adapters'    => true,
			'delete_on_uninstall' => false,
			// Linking rules.
			'max_suggestions'     => 5,
			'max_links_per_apply' => 5,
			'max_same_target'     => 1,
			'min_score'           => 60,
			'min_link_distance'   => 50,
			'new_tab'             => false,
			'priority_targets'    => array(),
			// AI & cost.
			'quality'             => 'economy',
			'only_model'          => '',
			'budget_cap_micros'   => 10000000,
			'alert_threshold'     => 80,
			'confirm_micros'      => 1000000,
			'strict_budget'       => true,
			'currency2'           => '',
			'currency2_rate'      => 0.0,
			// Advanced.
			'batch_size'          => 5,
			'schedule'            => 'off',
			'shortlist_size'      => 15,
			'max_chunks'          => 6,
			'excerpt_words'       => 300,
			'debug_log'           => false,
			'verbose_log'         => false,
			'onboarding_done'     => false,
		);

		/**
		 * Filters the default settings.
		 *
		 * @param array $defaults Default settings.
		 */
		return apply_filters( 'ailsug_default_settings', $defaults );
	}

	/**
	 * All public post types except attachments.
	 *
	 * @return string[]
	 */
	public static function default_post_types() {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		return array_values( $types );
	}

	/**
	 * Full settings array merged over defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}
		return self::$cache;
	}

	/**
	 * Get one setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $fallback Fallback when unknown.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Update some settings (values must already be sanitized).
	 *
	 * @param array $values Key => value.
	 * @return void
	 */
	public static function update( array $values ) {
		$merged = array_merge( self::all(), $values );
		update_option( self::OPTION, $merged, false );
		self::$cache = null;
	}

	/**
	 * Clears the per-request cache (used after option writes and in tests).
	 *
	 * @return void
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Sanitize a partial settings submission and merge it with stored values.
	 *
	 * Only keys present in $input are changed, so each settings tab can submit its own fields.
	 * Checkbox fields are listed per tab in the hidden `_ailsug_bools` field so that unchecked boxes become false.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$current = self::all();
		if ( ! is_array( $input ) ) {
			return $current;
		}

		$out = $current;

		$bools     = array( 'use_seo_adapters', 'delete_on_uninstall', 'new_tab', 'strict_budget', 'debug_log', 'verbose_log', 'onboarding_done' );
		$tab_bools = isset( $input['_ailsug_bools'] ) ? array_map( 'sanitize_key', explode( ',', (string) $input['_ailsug_bools'] ) ) : array();
		foreach ( $bools as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$out[ $key ] = (bool) $input[ $key ];
			} elseif ( in_array( $key, $tab_bools, true ) ) {
				$out[ $key ] = false;
			}
		}

		if ( array_key_exists( 'post_types', $input ) || in_array( 'post_types', $tab_bools, true ) ) {
			$public            = self::default_post_types();
			$out['post_types'] = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $input['post_types'] ?? array() ) ), $public ) );
		}
		if ( array_key_exists( 'statuses', $input ) || in_array( 'statuses', $tab_bools, true ) ) {
			$allowed         = array( 'publish', 'private', 'future' );
			$statuses        = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $input['statuses'] ?? array() ) ), $allowed ) );
			$out['statuses'] = $statuses ? $statuses : array( 'publish' );
		}

		foreach ( array( 'excluded_posts', 'excluded_terms', 'priority_targets' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$out[ $key ] = self::id_list( $input[ $key ] );
			}
		}

		$enums = array(
			'update_mode' => array( 'manual', 'auto' ),
			'quality'     => array( 'economy', 'balanced', 'best' ),
			'schedule'    => array( 'off', 'daily', 'weekly' ),
		);
		foreach ( $enums as $key => $allowed ) {
			if ( isset( $input[ $key ] ) && in_array( $input[ $key ], $allowed, true ) ) {
				$out[ $key ] = $input[ $key ];
			}
		}

		$ints = array(
			'max_suggestions'     => array( 1, 20 ),
			'max_links_per_apply' => array( 1, 20 ),
			'max_same_target'     => array( 1, 5 ),
			'min_score'           => array( 1, 100 ),
			'min_link_distance'   => array( 0, 1000 ),
			'alert_threshold'     => array( 1, 100 ),
			'batch_size'          => array( 1, 50 ),
			'shortlist_size'      => array( 3, 50 ),
			'max_chunks'          => array( 1, 20 ),
			'excerpt_words'       => array( 50, 1000 ),
		);
		foreach ( $ints as $key => $range ) {
			if ( isset( $input[ $key ] ) && '' !== $input[ $key ] ) {
				$out[ $key ] = max( $range[0], min( $range[1], (int) $input[ $key ] ) );
			}
		}

		// Canonical micros (from code paths such as Settings::update()).
		foreach ( array( 'budget_cap_micros', 'confirm_micros' ) as $key ) {
			if ( isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ) {
				$out[ $key ] = max( 0, (int) $input[ $key ] );
			}
		}

		// Money fields from forms arrive as decimal amounts in USD.
		foreach ( array(
			'budget_cap'     => 'budget_cap_micros',
			'confirm_amount' => 'confirm_micros',
		) as $field => $key ) {
			if ( isset( $input[ $field ] ) && '' !== $input[ $field ] ) {
				$out[ $key ] = self::to_micros( $input[ $field ] );
			}
		}

		if ( isset( $input['only_model'] ) ) {
			$model             = sanitize_text_field( wp_unslash( (string) $input['only_model'] ) );
			$out['only_model'] = preg_match( '/^[a-z0-9_-]+:[A-Za-z0-9._\/:-]+$/', $model ) ? $model : '';
		}
		if ( isset( $input['currency2'] ) ) {
			$code             = strtoupper( sanitize_text_field( wp_unslash( (string) $input['currency2'] ) ) );
			$out['currency2'] = preg_match( '/^[A-Z]{3}$/', $code ) ? $code : '';
		}
		if ( isset( $input['currency2_rate'] ) ) {
			$out['currency2_rate'] = max( 0.0, (float) $input['currency2_rate'] );
		}

		self::$cache = null;
		return $out;
	}

	/**
	 * Convert a decimal amount (string or number) to integer micros without float drift.
	 *
	 * @param mixed $amount Amount such as "12.50".
	 * @return int
	 */
	public static function to_micros( $amount ) {
		$amount = trim( str_replace( ',', '.', (string) $amount ) );
		if ( ! preg_match( '/^\d+(\.\d+)?$/', $amount ) ) {
			return 0;
		}
		$parts = explode( '.', $amount );
		$whole = (int) $parts[0];
		$frac  = isset( $parts[1] ) ? (int) str_pad( substr( $parts[1], 0, 6 ), 6, '0' ) : 0;
		return $whole * 1000000 + $frac;
	}

	/**
	 * Parse a comma/space separated list or array into unique positive ints.
	 *
	 * @param mixed $value Raw value.
	 * @return int[]
	 */
	public static function id_list( $value ) {
		if ( ! is_array( $value ) ) {
			$value = preg_split( '/[\s,]+/', (string) $value );
		}
		return array_values( array_unique( array_filter( array_map( 'absint', $value ) ) ) );
	}
}
