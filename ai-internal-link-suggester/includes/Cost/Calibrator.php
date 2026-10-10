<?php
/**
 * Self-calibrating correction factors (actual ÷ estimated tokens).
 *
 * @package AILSUG
 */

namespace AILSUG\Cost;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps a rolling (exponentially weighted) ratio per task and direction.
 */
final class Calibrator {

	const OPTION      = 'ailsug_calibration';
	const MIN_SAMPLES = 20;
	const ALPHA       = 0.1;

	/**
	 * Record one call.
	 *
	 * @param string $task       keyword|rank.
	 * @param int    $est_input  Estimated input tokens.
	 * @param int    $act_input  Actual input tokens.
	 * @param int    $est_output Estimated output tokens.
	 * @param int    $act_output Actual output tokens.
	 * @return void
	 */
	public static function record( $task, $est_input, $act_input, $est_output, $act_output ) {
		if ( $est_input <= 0 || $act_input <= 0 ) {
			return;
		}
		$data = self::all();
		$row  = $data[ $task ] ?? array(
			'n'      => 0,
			'input'  => 1.0,
			'output' => 1.0,
		);

		$in_ratio  = max( 0.1, min( 10.0, $act_input / $est_input ) );
		$out_ratio = $est_output > 0 && $act_output > 0 ? max( 0.1, min( 10.0, $act_output / $est_output ) ) : (float) $row['output'];

		if ( 0 === (int) $row['n'] ) {
			$row['input']  = $in_ratio;
			$row['output'] = $out_ratio;
		} else {
			$row['input']  = ( 1 - self::ALPHA ) * (float) $row['input'] + self::ALPHA * $in_ratio;
			$row['output'] = ( 1 - self::ALPHA ) * (float) $row['output'] + self::ALPHA * $out_ratio;
		}
		$row['n']      = (int) $row['n'] + 1;
		$data[ $task ] = $row;
		update_option( self::OPTION, $data, false );
	}

	/**
	 * Correction factors for a task (1.0 until enough samples exist).
	 *
	 * @param string $task Task.
	 * @return array{input:float,output:float,samples:int,active:bool}
	 */
	public static function factors( $task ) {
		$row    = self::all()[ $task ] ?? null;
		$n      = $row ? (int) $row['n'] : 0;
		$active = $n >= self::MIN_SAMPLES;
		return array(
			'input'   => $active ? (float) $row['input'] : 1.0,
			'output'  => $active ? (float) $row['output'] : 1.0,
			'samples' => $n,
			'active'  => $active,
		);
	}

	/**
	 * Stored data.
	 *
	 * @return array
	 */
	public static function all() {
		$data = get_option( self::OPTION, array() );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Forget calibration.
	 *
	 * @return void
	 */
	public static function reset() {
		delete_option( self::OPTION );
	}
}
