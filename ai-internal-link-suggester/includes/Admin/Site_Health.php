<?php
/**
 * Site Health: "AI provider available" test and debug information.
 *
 * @package AILSUG
 */

namespace AILSUG\Admin;

use AILSUG\Activator;
use AILSUG\AI\Availability;
use AILSUG\Cost\Budget;

defined( 'ABSPATH' ) || exit;

/**
 * Integrates with Tools → Site Health.
 */
final class Site_Health {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'site_status_tests', array( __CLASS__, 'tests' ) );
		add_filter( 'debug_information', array( __CLASS__, 'info' ) );
	}

	/**
	 * Add the direct test.
	 *
	 * @param array $tests Tests.
	 * @return array
	 */
	public static function tests( $tests ) {
		$tests['direct']['ailsug_ai_provider'] = array(
			'label' => __( 'AI provider for internal link suggestions', 'ai-internal-link-suggester' ),
			'test'  => array( __CLASS__, 'test_ai' ),
		);
		return $tests;
	}

	/**
	 * The test.
	 *
	 * @return array
	 */
	public static function test_ai() {
		$status = Availability::status( true );
		$ok     = ! empty( $status['available'] );
		$link   = '<a href="' . esc_url( Menu::url( 'settings', array( 'tab' => 'ai' ) ) ) . '">' . esc_html__( 'Internal Links → Settings → AI & Cost', 'ai-internal-link-suggester' ) . '</a>';
		return array(
			'label'       => $ok ? __( 'An AI provider is available for internal link suggestions', 'ai-internal-link-suggester' ) : __( 'No AI provider is available for internal link suggestions', 'ai-internal-link-suggester' ),
			'status'      => $ok ? 'good' : 'recommended',
			'badge'       => array(
				'label' => __( 'Internal Links', 'ai-internal-link-suggester' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $status['message'] ) . '</p><p>' . esc_html__( 'Without an AI provider the page index, link map, orphan report and keyword editing still work.', 'ai-internal-link-suggester' ) . '</p>',
			/* translators: %s: link to settings. */
			'actions'     => '<p>' . wp_kses_post( sprintf( __( 'See %s.', 'ai-internal-link-suggester' ), $link ) ) . '</p>',
			'test'        => 'ailsug_ai_provider',
		);
	}

	/**
	 * Debug info section.
	 *
	 * @param array $info Info.
	 * @return array
	 */
	public static function info( $info ) {
		global $wpdb;
		$t      = Activator::tables();
		$status = Availability::cached();
		$budget = Budget::status();
		$last   = get_option( 'ailsug_last_model', array() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$queue = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . esc_sql( $t['queue'] ) . " WHERE status IN ('pending','running','budget')" );

		$info['ai-internal-link-suggester'] = array(
			'label'  => __( 'AI Internal Link Suggester', 'ai-internal-link-suggester' ),
			'fields' => array(
				'version'    => array(
					'label' => __( 'Version', 'ai-internal-link-suggester' ),
					'value' => AILSUG_VERSION,
				),
				'db'         => array(
					'label' => __( 'Database version', 'ai-internal-link-suggester' ),
					'value' => (string) get_option( Activator::DB_OPTION ),
				),
				'ai'         => array(
					'label' => __( 'AI status', 'ai-internal-link-suggester' ),
					'value' => $status ? $status['code'] : 'unchecked',
				),
				'last_model' => array(
					'label' => __( 'Model that answered last', 'ai-internal-link-suggester' ),
					'value' => is_array( $last ) && ! empty( $last['model'] ) ? $last['provider'] . ' / ' . $last['model'] : '—',
				),
				'queue'      => array(
					'label' => __( 'Jobs waiting', 'ai-internal-link-suggester' ),
					'value' => $queue,
				),
				'budget'     => array(
					'label' => __( 'Estimated spend / cap (micro-USD)', 'ai-internal-link-suggester' ),
					'value' => $budget['spent'] . ' / ' . $budget['cap'],
				),
				'cron'       => array(
					'label' => __( 'WP-Cron disabled', 'ai-internal-link-suggester' ),
					'value' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'yes' : 'no',
				),
			),
		);
		return $info;
	}
}
