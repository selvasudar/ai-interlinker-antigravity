<?php
/**
 * Environment requirement checks.
 *
 * @package AILSUG
 */

namespace AILSUG;

defined( 'ABSPATH' ) || exit;

/**
 * Checks that WordPress and PHP meet the plugin's minimums.
 */
final class Requirements {

	/**
	 * Returns a list of human-readable problems, empty when all requirements are met.
	 *
	 * @return string[]
	 */
	public static function problems() {
		$problems = array();

		if ( version_compare( PHP_VERSION, AILSUG_MIN_PHP, '<' ) ) {
			$problems[] = sprintf(
				/* translators: 1: required PHP version, 2: current PHP version. */
				__( 'LinkCortex needs PHP %1$s or newer. This site runs PHP %2$s.', 'linkcortex' ),
				AILSUG_MIN_PHP,
				PHP_VERSION
			);
		}

		if ( version_compare( get_bloginfo( 'version' ), AILSUG_MIN_WP, '<' ) ) {
			$problems[] = sprintf(
				/* translators: %s: required WordPress version. */
				__( 'LinkCortex needs WordPress %s or newer. Please update WordPress.', 'linkcortex' ),
				AILSUG_MIN_WP
			);
		}

		return $problems;
	}

	/**
	 * Whether all requirements are met.
	 *
	 * @return bool
	 */
	public static function met() {
		return array() === self::problems();
	}
}
