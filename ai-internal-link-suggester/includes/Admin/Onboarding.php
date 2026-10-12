<?php
/**
 * First-run setup checklist (dismissible).
 *
 * @package AILSUG
 */

namespace AILSUG\Admin;

use AILSUG\AI\Availability;

defined( 'ABSPATH' ) || exit;

/**
 * Four plain-language steps: connect AI, choose content, see costs, run the first scan.
 */
final class Onboarding {

	const STEP_TYPES = 'ailsug_onboarding_types';
	const STEP_COSTS = 'ailsug_onboarding_costs';

	/**
	 * Register hooks that mark steps as done.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'update_option_' . \AILSUG\Settings::OPTION, array( __CLASS__, 'settings_saved' ) );
		add_action( 'ailsug_settings_ai_tab', array( __CLASS__, 'costs_seen' ), 1 );
	}

	/**
	 * Settings saved: the content step is done.
	 *
	 * @return void
	 */
	public static function settings_saved() {
		if ( current_user_can( 'manage_options' ) ) {
			update_user_meta( get_current_user_id(), self::STEP_TYPES, 1 );
		}
	}

	/**
	 * AI & Cost tab opened: the cost step is done.
	 *
	 * @return void
	 */
	public static function costs_seen() {
		update_user_meta( get_current_user_id(), self::STEP_COSTS, 1 );
	}

	/**
	 * Steps with their state.
	 *
	 * @param array $kpis Dashboard KPIs.
	 * @return array[]
	 */
	public static function steps( array $kpis ) {
		$user   = get_current_user_id();
		$status = Availability::cached();
		$ai_ok  = Availability::is_available();
		return array(
			array(
				'done'  => $ai_ok,
				'title' => __( 'Connect an AI provider', 'interlink-ai' ),
				'hint'  => $ai_ok
					? __( 'AI is connected and ready.', 'interlink-ai' )
					: ( $status ? $status['message'] : __( 'Open the AI & Cost settings to check your connection. The plugin never asks for API keys: WordPress keeps them in Settings → Connectors.', 'interlink-ai' ) ),
				'link'  => Menu::url( 'settings', array( 'tab' => 'ai' ) ),
				'label' => __( 'Check AI connection', 'interlink-ai' ),
			),
			array(
				'done'  => (bool) get_user_meta( $user, self::STEP_TYPES, true ),
				'title' => __( 'Choose which content to include', 'interlink-ai' ),
				'hint'  => __( 'Pick the post types that should get links. Fewer types means a cheaper scan.', 'interlink-ai' ),
				'link'  => Menu::url( 'settings', array( 'tab' => 'general' ) ),
				'label' => __( 'Choose content', 'interlink-ai' ),
			),
			array(
				'done'  => (bool) get_user_meta( $user, self::STEP_COSTS, true ),
				'title' => __( 'See what it will cost', 'interlink-ai' ),
				'hint'  => __( 'Use the calculator and set a monthly budget cap so there are no surprises.', 'interlink-ai' ),
				'link'  => Menu::url( 'settings', array( 'tab' => 'ai' ) ) . '#ailsug-calculator',
				'label' => __( 'Open the cost calculator', 'interlink-ai' ),
			),
			array(
				'done'  => $kpis['scans'] > 0,
				'title' => __( 'Run your first scan', 'interlink-ai' ),
				'hint'  => __( 'Use the Scan button below. You will see the estimated cost first.', 'interlink-ai' ),
				'link'  => '',
				'label' => '',
			),
		);
	}

	/**
	 * Render the checklist unless dismissed or complete.
	 *
	 * @param array $kpis Dashboard KPIs.
	 * @return void
	 */
	public static function render( array $kpis ) {
		if ( get_user_meta( get_current_user_id(), 'ailsug_dismissed_onboarding', true ) ) {
			return;
		}
		$steps = self::steps( $kpis );
		$done  = count( array_filter( wp_list_pluck( $steps, 'done' ) ) );
		if ( count( $steps ) === $done ) {
			return;
		}
		echo '<section class="ailsug-onboarding" aria-labelledby="ailsug-onboarding-title">';
		echo '<h2 id="ailsug-onboarding-title">' . esc_html__( 'Get started', 'interlink-ai' ) . '</h2>';
		/* translators: 1: done, 2: total. */
		echo '<p>' . esc_html( sprintf( __( '%1$d of %2$d steps done.', 'interlink-ai' ), $done, count( $steps ) ) ) . '</p><ol>';
		foreach ( $steps as $step ) {
			echo '<li class="' . ( $step['done'] ? 'is-done' : 'is-todo' ) . '">';
			echo '<span class="ailsug-check" aria-hidden="true">' . ( $step['done'] ? '✓' : '✗' ) . '</span> ';
			echo '<strong>' . esc_html( $step['title'] ) . '</strong> <span class="screen-reader-text">' . ( $step['done'] ? esc_html__( '(done)', 'interlink-ai' ) : esc_html__( '(not done yet)', 'interlink-ai' ) ) . '</span>';
			echo '<br /><span class="description">' . esc_html( $step['hint'] ) . '</span>';
			if ( ! $step['done'] && '' !== $step['link'] ) {
				echo ' <a href="' . esc_url( $step['link'] ) . '">' . esc_html( $step['label'] ) . '</a>';
			}
			if ( ! $step['done'] && 0 === $done && 0 === array_search( $step, $steps, true ) && ! Availability::is_available() ) {
				echo '<br /><span class="description">' . esc_html__( 'Official provider plugins:', 'interlink-ai' ) . ' ';
				$links = array();
				foreach ( Availability::provider_plugins() as $slug => $label ) {
					$url     = current_user_can( 'install_plugins' ) ? self_admin_url( 'plugin-install.php?tab=plugin-information&plugin=' . $slug ) : 'https://wordpress.org/plugins/' . $slug . '/';
					$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
				}
				echo wp_kses_post( implode( ', ', $links ) ) . '</span>';
			}
			echo '</li>';
		}
		echo '</ol>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ailsug_dismiss_onboarding' );
		echo '<input type="hidden" name="action" value="ailsug_dismiss_onboarding" />';
		submit_button( __( 'Hide this checklist', 'interlink-ai' ), 'link', 'submit', false );
		echo '</form></section>';
	}
}
