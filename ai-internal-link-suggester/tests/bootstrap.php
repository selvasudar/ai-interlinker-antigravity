<?php
/**
 * PHPUnit bootstrap for the WordPress test suite (wp-env provides WP_TESTS_DIR).
 *
 * @package AILSUG
 */

$ailsug_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $ailsug_tests_dir ) {
	$ailsug_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );
}

require_once $ailsug_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/ai-internal-link-suggester.php';
	}
);

require $ailsug_tests_dir . '/includes/bootstrap.php';

\AILSUG\Activator::install();
