<?php
/**
 * One concurrent worker (run many in parallel via run.sh). Dev/test only.
 *
 * @package AILSUG
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput, WordPress.WP.GlobalVariablesOverride

$mode = getenv( 'AILSUG_RACE_MODE' );
if ( 'budget' === $mode ) {
	$id = AILSUG\Cost\Budget::reserve( 1000 );
	if ( $id ) {
		$s = AILSUG\Cost\Budget::status();
		usleep( wp_rand( 1000, 50000 ) );
		AILSUG\Cost\Budget::reconcile( $id, 900 );
		echo 'OK ' . ( $s['spent'] + $s['reserved'] ) . "\n";
	} else {
		echo "DENIED\n";
	}
} elseif ( 'queue' === $mode ) {
	$jobs = AILSUG\Queue\Queue::claim( 3, getenv( 'AILSUG_RACE_RUN' ) );
	foreach ( $jobs as $job ) {
		echo 'JOB ' . $job['id'] . "\n";
	}
}
