<?php
/**
 * Advanced tools: debug log viewer and index reset.
 *
 * @package AILSUG
 */

namespace AILSUG\Admin;

use AILSUG\Activator;
use AILSUG\Logger;
use AILSUG\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Rendered below the Advanced settings form.
 */
final class Tools {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'ailsug_settings_tools', array( __CLASS__, 'render' ) );
		add_action( 'admin_post_ailsug_reset_index', array( __CLASS__, 'reset_index' ) );
		add_action( 'admin_post_ailsug_clear_log', array( __CLASS__, 'clear_log' ) );
	}

	/**
	 * Render tools.
	 *
	 * @return void
	 */
	public static function render() {
		echo '<h3>' . esc_html__( 'Reset the page index', 'linkcortex' ) . '</h3>';
		echo '<p>' . esc_html__( 'Clears the stored keywords found by AI, the link map and all open suggestions, then you can rebuild the index for free. Applied links stay in your pages and can still be undone. Keywords you typed yourself are kept.', 'linkcortex' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ailsug_reset_index' );
		echo '<input type="hidden" name="action" value="ailsug_reset_index" />';
		echo '<label><input type="checkbox" name="confirm" value="1" required /> ' . esc_html__( 'Yes, reset the index', 'linkcortex' ) . '</label> ';
		submit_button( __( 'Reset index', 'linkcortex' ), 'delete small', 'submit', false );
		echo '</form>';

		if ( ! Settings::get( 'debug_log' ) ) {
			return;
		}
		$entries = array_reverse( Logger::entries() );
		echo '<h3>' . esc_html__( 'Debug log', 'linkcortex' ) . '</h3>';
		if ( ! $entries ) {
			echo '<p class="description">' . esc_html__( 'Nothing logged yet.', 'linkcortex' ) . '</p>';
			return;
		}
		echo '<div class="ailsug-log" tabindex="0" role="region" aria-label="' . esc_attr__( 'Debug log', 'linkcortex' ) . '"><table class="widefat striped"><tbody>';
		foreach ( array_slice( $entries, 0, 100 ) as $e ) {
			printf( '<tr><td>%1$s</td><td>%2$s</td><td><code>%3$s</code></td></tr>', esc_html( $e['time'] ), esc_html( $e['message'] ), esc_html( (string) wp_json_encode( $e['context'] ) ) );
		}
		echo '</tbody></table></div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ailsug_clear_log' );
		echo '<input type="hidden" name="action" value="ailsug_clear_log" />';
		submit_button( __( 'Clear log', 'linkcortex' ), 'secondary small', 'submit', false );
		echo '</form>';
	}

	/**
	 * Reset the index (confirmed).
	 *
	 * @return void
	 */
	public static function reset_index() {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'linkcortex' ) );
		}
		check_admin_referer( 'ailsug_reset_index' );
		if ( ! empty( $_POST['confirm'] ) ) {
			$t = Activator::tables();
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own tables.
			$wpdb->query( 'DELETE FROM ' . esc_sql( $t['index'] ) );
			$wpdb->query( 'DELETE FROM ' . esc_sql( $t['links'] ) );
			$wpdb->query( 'DELETE FROM ' . esc_sql( $t['suggestions'] ) . " WHERE status IN ('pending','accepted','stale','rejected')" );
			$wpdb->query( 'DELETE FROM ' . esc_sql( $t['queue'] ) . " WHERE status IN ('pending','paused','budget')" );
			// phpcs:enable
			add_settings_error( 'ailsug', 'reset', __( 'The index was reset. Use "Update page index" on the Keywords screen to rebuild it (free).', 'linkcortex' ), 'success' );
			set_transient( 'settings_errors', get_settings_errors(), 30 );
		}
		wp_safe_redirect( add_query_arg( 'settings-updated', 'true', Menu::url( 'settings', array( 'tab' => 'advanced' ) ) ) );
		exit;
	}

	/**
	 * Clear the debug log.
	 *
	 * @return void
	 */
	public static function clear_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'linkcortex' ) );
		}
		check_admin_referer( 'ailsug_clear_log' );
		Logger::clear();
		wp_safe_redirect( Menu::url( 'settings', array( 'tab' => 'advanced' ) ) );
		exit;
	}
}
