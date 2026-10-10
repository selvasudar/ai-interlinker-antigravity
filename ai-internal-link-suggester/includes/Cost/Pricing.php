<?php
/**
 * Model price table: bundled data/pricing.json + user overrides + filter.
 *
 * @package AILSUG
 */

namespace AILSUG\Cost;

use AILSUG\AI\Availability;
use AILSUG\AI\MockGateway;
use AILSUG\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Prices are kept as integer micro-USD per 1M tokens. Unknown prices are never guessed.
 */
final class Pricing {

	const OVERRIDES = 'ailsug_pricing_overrides';

	/**
	 * Per-request cache.
	 *
	 * @var array|null
	 */
	private static $table = null;

	/**
	 * Bundled pricing file contents.
	 *
	 * @return array
	 */
	public static function bundled() {
		static $data = null;
		if ( null === $data ) {
			$file = AILSUG_DIR . 'data/pricing.json';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local bundled file.
			$json = is_readable( $file ) ? file_get_contents( $file ) : '';
			$data = json_decode( (string) $json, true );
			if ( ! is_array( $data ) ) {
				$data = array(
					'as_of'   => '',
					'sources' => array(),
					'models'  => array(),
				);
			}
		}
		return $data;
	}

	/**
	 * Effective table: model id => [provider, input, output (micros/1M), origin, as_of].
	 *
	 * @return array<string,array{provider:string,input:int,output:int,origin:string}>
	 */
	public static function table() {
		if ( null !== self::$table ) {
			return self::$table;
		}
		$bundled = self::bundled();
		$today   = gmdate( 'Y-m-d' );
		$table   = array();

		foreach ( (array) ( $bundled['models'] ?? array() ) as $id => $row ) {
			if ( ! is_array( $row ) || ! isset( $row['input_per_million'], $row['output_per_million'] ) ) {
				continue;
			}
			$input  = $row['input_per_million'];
			$output = $row['output_per_million'];
			foreach ( (array) ( $row['changes'] ?? array() ) as $change ) {
				if ( isset( $change['effective_from'] ) && $change['effective_from'] <= $today ) {
					$input  = $change['input_per_million'] ?? $input;
					$output = $change['output_per_million'] ?? $output;
				}
			}
			$table[ (string) $id ] = array(
				'provider' => (string) ( $row['provider'] ?? '' ),
				'input'    => self::decimal_to_micros( $input ),
				'output'   => self::decimal_to_micros( $output ),
				'origin'   => 'bundled',
			);
		}

		if ( Availability::is_mock() ) {
			$table[ MockGateway::MODEL ] = array(
				'provider' => MockGateway::PROVIDER,
				'input'    => 100000,
				'output'   => 500000,
				'origin'   => 'bundled',
			);
		}

		foreach ( self::overrides() as $id => $row ) {
			$table[ $id ] = array(
				'provider' => (string) $row['provider'],
				'input'    => (int) $row['input'],
				'output'   => (int) $row['output'],
				'origin'   => 'user',
			);
		}

		/**
		 * Filters the price table (micro-USD per 1 million tokens).
		 *
		 * @param array $table model id => ['provider' => string, 'input' => int, 'output' => int, 'origin' => string].
		 */
		$table = apply_filters( 'ailsug_pricing_table', $table );

		self::$table = is_array( $table ) ? $table : array();
		return self::$table;
	}

	/**
	 * Clear the cache.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$table = null;
	}

	/**
	 * Price for a model, tolerant of provider prefixes and dated snapshot suffixes.
	 *
	 * @param string $provider Provider id.
	 * @param string $model    Model id as reported.
	 * @return array{input:int,output:int,origin:string,id:string}|null
	 */
	public static function price_for( $provider, $model ) {
		$table = self::table();
		$model = preg_replace( '#^(models/|publishers/[^/]+/models/)#', '', (string) $model );
		if ( isset( $table[ $model ] ) ) {
			return array_merge( $table[ $model ], array( 'id' => $model ) );
		}
		// Dated snapshots such as "claude-haiku-5-5-20260601" use the base model's price.
		$best = '';
		foreach ( array_keys( $table ) as $id ) {
			if ( 0 === strpos( $model, $id . '-' ) && strlen( $id ) > strlen( $best ) ) {
				if ( '' === $provider || '' === $table[ $id ]['provider'] || $table[ $id ]['provider'] === $provider ) {
					$best = $id;
				}
			}
		}
		return '' !== $best ? array_merge( $table[ $best ], array( 'id' => $best ) ) : null;
	}

	/**
	 * Cost in micro-USD (rounded up) for a token count at a micros-per-1M price.
	 *
	 * @param int $tokens            Tokens.
	 * @param int $micros_per_million Price.
	 * @return int
	 */
	public static function cost( $tokens, $micros_per_million ) {
		$product = max( 0, (int) $tokens ) * max( 0, (int) $micros_per_million );
		return intdiv( $product + 999999, 1000000 );
	}

	/**
	 * Cost of a call, or null when the model has no known price.
	 *
	 * @param string $provider      Provider.
	 * @param string $model         Model.
	 * @param int    $input_tokens  Input tokens.
	 * @param int    $output_tokens Output tokens.
	 * @return int|null
	 */
	public static function call_cost( $provider, $model, $input_tokens, $output_tokens ) {
		$price = self::price_for( $provider, $model );
		if ( ! $price ) {
			return null;
		}
		return self::cost( $input_tokens, $price['input'] ) + self::cost( $output_tokens, $price['output'] );
	}

	/**
	 * User price overrides.
	 *
	 * @return array<string,array{provider:string,input:int,output:int}>
	 */
	public static function overrides() {
		$stored = get_option( self::OVERRIDES, array() );
		$out    = array();
		foreach ( is_array( $stored ) ? $stored : array() as $id => $row ) {
			if ( is_string( $id ) && is_array( $row ) && isset( $row['input'], $row['output'] ) ) {
				$out[ $id ] = array(
					'provider' => isset( $row['provider'] ) ? sanitize_key( $row['provider'] ) : '',
					'input'    => max( 0, (int) $row['input'] ),
					'output'   => max( 0, (int) $row['output'] ),
				);
			}
		}
		return $out;
	}

	/**
	 * Save overrides (already in micros), dropping invalid rows.
	 *
	 * @param array $rows Rows.
	 * @return void
	 */
	public static function save_overrides( array $rows ) {
		$clean = array();
		foreach ( $rows as $id => $row ) {
			$id = is_string( $id ) ? trim( $id ) : '';
			if ( '' === $id || ! preg_match( '/^[A-Za-z0-9._\/:-]{1,128}$/', $id ) || ! is_array( $row ) ) {
				continue;
			}
			$clean[ $id ] = array(
				'provider' => sanitize_key( (string) ( $row['provider'] ?? '' ) ),
				'input'    => max( 0, (int) ( $row['input'] ?? 0 ) ),
				'output'   => max( 0, (int) ( $row['output'] ?? 0 ) ),
			);
		}
		update_option( self::OVERRIDES, $clean, false );
		self::flush();
	}

	/**
	 * Convert a JSON number or numeric string (USD) to micros without float drift.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	public static function decimal_to_micros( $value ) {
		if ( is_float( $value ) || is_int( $value ) ) {
			$value = number_format( (float) $value, 6, '.', '' );
		}
		return Settings::to_micros( (string) $value );
	}

	/**
	 * "As of" date of the bundled prices.
	 *
	 * @return string
	 */
	public static function as_of() {
		return (string) ( self::bundled()['as_of'] ?? '' );
	}

	/**
	 * Official pricing page per provider.
	 *
	 * @return array<string,string>
	 */
	public static function sources() {
		return (array) ( self::bundled()['sources'] ?? array() );
	}
}
