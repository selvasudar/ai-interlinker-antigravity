<?php
/**
 * Dashboard screen.
 *
 * @package AILSUG
 */

namespace AILSUG\Admin;

use AILSUG\AI\Availability;

defined( 'ABSPATH' ) || exit;

/**
 * Main landing screen: KPIs, quick wins and the Scan button.
 */
final class Dashboard {

	/**
	 * Render.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'ai-internal-link-suggester' ) );
		}
		echo '<div class="wrap ailsug-wrap">';
		echo '<h1>' . esc_html__( 'Internal Links', 'ai-internal-link-suggester' ) . '</h1>';
		$status = Availability::cached();
		if ( null === $status || empty( $status['available'] ) ) {
			echo '<div class="notice notice-info inline"><p>';
			echo esc_html__( 'AI features are not ready yet. Your link map and orphan report still work without AI.', 'ai-internal-link-suggester' ) . ' ';
			if ( current_user_can( 'manage_options' ) ) {
				echo '<a href="' . esc_url( Menu::url( 'settings', array( 'tab' => 'ai' ) ) ) . '">' . esc_html__( 'Check AI connection', 'ai-internal-link-suggester' ) . '</a>';
			}
			echo '</p></div>';
		}
		if ( current_user_can( 'manage_options' ) && \AILSUG\AI\Availability::is_available() ) {
			echo '<p><button type="button" class="button button-primary button-hero ailsug-start-run" data-run-type="scan">' . esc_html__( 'Scan for link suggestions…', 'ai-internal-link-suggester' ) . '</button></p>';
			echo '<p class="description">' . esc_html__( 'Step 1 of 3: Scan → Review → Apply. You will see the estimated cost before anything is sent to AI.', 'ai-internal-link-suggester' ) . '</p>';
		}
		Keywords_Page::progress_box( \AILSUG\Queue\Queue::active_run( array( 'scan', 'regenerate', 'keywords', 'index' ) ) );
		echo '</div>';
	}
}
