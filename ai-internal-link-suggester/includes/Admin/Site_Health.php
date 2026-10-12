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
			'label' => __( 'AI provider for internal link suggestions', 'interlink-ai' ),
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
		$link   = '<a href="' . esc_url( Menu::url( 'settings', array( 'tab' => 'ai' ) ) ) . '">' . esc_html__( 'Interlink AI → Settings → AI & Cost', 'interlink-ai' ) . '</a>';
		return array(
			'label'       => $ok ? __( 'An AI provider is available for internal link suggestions', 'interlink-ai' ) : __( 'No AI provider is available for internal link suggestions', 'interlink-ai' ),
			'status'      => $ok ? 'good' : 'recommended',
			'badge'       => array(
				'label' => __( 'Interlink AI', 'interlink-ai' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $status['message'] ) . '</p><p>' . esc_html__( 'Without an AI provider the page index, link map, orphan report and keyword editing still work.', 'interlink-ai' ) . '</p>',
			/* translators: %s: link to settings. */
			'actions'     => '<p>' . wp_kses_post( sprintf( __( 'See %s.', 'interlink-ai' ), $link ) ) . '</p>',
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

		$info['interlink-ai'] = array(
			'label'  => __( 'Interlink AI', 'interlink-ai' ),
			'fields' => array(
				'version'    => array(
					'label' => __( 'Version', 'interlink-ai' ),
					'value' => AILSUG_VERSION,
				),
				'db'         => array(
					'label' => __( 'Database version', 'interlink-ai' ),
					'value' => (string) get_option( Activator::DB_OPTION ),
				),
				'ai'         => array(
					'label' => __( 'AI status', 'interlink-ai' ),
					'value' => $status ? $status['code'] : 'unchecked',
				),
				'last_model' => array(
					'label' => __( 'Model that answered last', 'interlink-ai' ),
					'value' => is_array( $last ) && ! empty( $last['model'] ) ? $last['provider'] . ' / ' . $last['model'] : '—',
				),
				'queue'      => array(
					'label' => __( 'Jobs waiting', 'interlink-ai' ),
					'value' => $queue,
				),
				'budget'     => array(
					'label' => __( 'Estimated spend / cap (micro-USD)', 'interlink-ai' ),
					'value' => $budget['spent'] . ' / ' . $budget['cap'],
				),
				'cron'       => array(
					'label' => __( 'WP-Cron disabled', 'interlink-ai' ),
					'value' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'yes' : 'no',
				),
			),
		);
		return $info;
	}
}
