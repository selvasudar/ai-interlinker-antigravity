<?php
/**
 * Opt-in debug log stored in a non-autoloaded option (never in PHP's error log).
 *
 * @package AILSUG
 */

namespace AILSUG;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the last 200 entries. Content is only logged in verbose mode.
 */
final class Logger {

	const OPTION = 'ailsug_debug_log';
	const MAX    = 200;

	/**
	 * Log a message if debug logging is on.
	 *
	 * @param string $message Message (no page content).
	 * @param array  $context Small scalar context.
	 * @return void
	 */
	public static function debug( $message, array $context = array() ) {
		if ( ! Settings::get( 'debug_log' ) ) {
			return;
		}
		self::write( $message, $context );
	}

	/**
	 * Log content (prompts) only in verbose mode.
	 *
	 * @param string $message Message.
	 * @param string $content Content.
	 * @return void
	 */
	public static function verbose( $message, $content ) {
		if ( ! Settings::get( 'debug_log' ) || ! Settings::get( 'verbose_log' ) ) {
			return;
		}
		$content = function_exists( 'mb_substr' ) ? mb_substr( (string) $content, 0, 4000 ) : substr( (string) $content, 0, 4000 );
		self::write( $message, array( 'content' => $content ) );
	}

	/**
	 * Append an entry.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return void
	 */
	private static function write( $message, array $context ) {
		$log   = get_option( self::OPTION, array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'time'    => gmdate( 'Y-m-d H:i:s' ),
			'message' => (string) $message,
			'context' => array_map(
				static function ( $v ) {
					return is_scalar( $v ) ? $v : wp_json_encode( $v );
				},
				$context
			),
		);
		update_option( self::OPTION, array_slice( $log, -self::MAX ), false );
	}

	/**
	 * Entries.
	 *
	 * @return array
	 */
	public static function entries() {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Clear.
	 *
	 * @return void
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}
}
