<?php
/**
 * Uninstall: removes plugin data only when the admin opted in.
 *
 * @package AILSUG
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$ailsug_settings = get_option( 'ailsug_settings', array() );

foreach ( array( 'ailsug_cron_tick', 'ailsug_cron_schedule', 'ailsug_cron_cleanup' ) as $ailsug_hook ) {
	wp_clear_scheduled_hook( $ailsug_hook );
}

if ( empty( $ailsug_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;

foreach ( array( 'index', 'links', 'suggestions', 'usage', 'queue', 'runs', 'applied', 'budget', 'reservations' ) as $ailsug_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping our own tables on uninstall.
	$wpdb->query( 'DROP TABLE IF EXISTS ' . esc_sql( $wpdb->prefix . 'ailsug_' . $ailsug_table ) );
}

foreach ( array( 'ailsug_settings', 'ailsug_db_version', 'ailsug_pricing_overrides', 'ailsug_calibration', 'ailsug_last_model', 'ailsug_dismissed_onboarding' ) as $ailsug_option ) {
	delete_option( $ailsug_option );
}

foreach ( array( 'ailsug_ai_status', 'ailsug_ai_models', 'ailsug_dashboard_kpis' ) as $ailsug_transient ) {
	delete_transient( $ailsug_transient );
}

delete_post_meta_by_key( '_ailsug_keyword' );
delete_metadata( 'user', 0, 'ailsug_dismissed_onboarding', '', true );
