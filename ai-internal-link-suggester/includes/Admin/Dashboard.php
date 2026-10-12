<?php
/**
 * Dashboard screen: KPIs, onboarding, quick wins and the Scan button.
 *
 * @package AILSUG
 */

namespace AILSUG\Admin;

use AILSUG\Activator;
use AILSUG\AI\Availability;
use AILSUG\Cost\Budget;
use AILSUG\Index\LinkGraph;
use AILSUG\Queue\Queue;
use AILSUG\Suggest\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Step 1 of Scan → Review → Apply.
 */
final class Dashboard {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_post_ailsug_dismiss_onboarding', array( __CLASS__, 'dismiss_onboarding' ) );
	}

	/**
	 * Render.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'interlink-ai' ) );
		}
		$is_admin = current_user_can( 'manage_options' );
		$kpis     = self::kpis();

		echo '<div class="wrap ailsug-wrap">';
		echo '<h1>' . esc_html__( 'Interlink AI', 'interlink-ai' ) . '</h1>';
		echo '<ol class="ailsug-steps" aria-label="' . esc_attr__( 'How it works', 'interlink-ai' ) . '"><li><strong>' . esc_html__( 'Scan', 'interlink-ai' ) . '</strong> ' . esc_html__( 'AI reads your pages and suggests links.', 'interlink-ai' ) . '</li><li><strong>' . esc_html__( 'Review & Apply', 'interlink-ai' ) . '</strong> ' . esc_html__( 'Accept suggestions to instantly write links to your pages.', 'interlink-ai' ) . '</li><li><strong>' . esc_html__( 'Undo anytime', 'interlink-ai' ) . '</strong> ' . esc_html__( 'One-click undo from the Applied list.', 'interlink-ai' ) . '</li></ol>';

		if ( $is_admin ) {
			Onboarding::render( $kpis );
		}

		$budget = $kpis['budget'];
		if ( $budget['reached'] ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The estimated monthly AI budget is used up. AI scans are paused until you raise the cap or a new month starts. Everything else keeps working.', 'interlink-ai' ) . '</p></div>';
		} elseif ( $budget['alert'] ) {
			/* translators: %s: percent. */
			echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( __( 'You have used %s%% of this month\'s estimated AI budget.', 'interlink-ai' ), number_format_i18n( (int) round( $budget['percent'] ) ) ) ) . '</p></div>';
		}

		self::render_kpis( $kpis );

		$status = Availability::cached();
		echo '<div class="ailsug-scan-box">';
		if ( $is_admin && Availability::is_available() ) {
			echo '<button type="button" class="button button-primary button-hero ailsug-start-run" data-run-type="scan">' . esc_html__( 'Scan for link suggestions…', 'interlink-ai' ) . '</button>';
			echo '<p class="description">' . esc_html__( 'You will see the estimated cost and can cancel before anything is sent to AI. Pages that have not changed since the last scan are skipped for free.', 'interlink-ai' ) . '</p>';
		} elseif ( $is_admin ) {
			echo '<p><strong>' . esc_html__( 'AI is not ready yet.', 'interlink-ai' ) . '</strong> ' . esc_html( $status ? $status['message'] : __( 'Check the AI connection to start scanning.', 'interlink-ai' ) ) . '</p>';
			echo '<p><a class="button" href="' . esc_url( Menu::url( 'settings', array( 'tab' => 'ai' ) ) ) . '">' . esc_html__( 'Check AI connection', 'interlink-ai' ) . '</a> ';
			echo '<button type="button" class="button ailsug-start-run" data-run-type="index">' . esc_html__( 'Update page index (free, no AI)', 'interlink-ai' ) . '</button></p>';
			echo '<p class="description">' . esc_html__( 'Your page index, link map and orphan report work without AI.', 'interlink-ai' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'An administrator runs scans. You can review suggestions for your own pages.', 'interlink-ai' ) . ' <a href="' . esc_url( Menu::url( 'suggestions' ) ) . '">' . esc_html__( 'Review suggestions', 'interlink-ai' ) . '</a></p>';
		}
		Keywords_Page::progress_box( $is_admin ? Queue::active_run( array( 'scan', 'regenerate', 'keywords', 'index' ) ) : null );
		echo '</div>';

		self::render_quick_wins();
		echo '</div>';
	}

	/**
	 * KPI numbers.
	 *
	 * @return array
	 */
	public static function kpis() {
		global $wpdb;
		$t = Activator::tables();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own tables, live numbers.
		$indexed = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . esc_sql( $t['index'] ) );
		$pending = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . esc_sql( $t['suggestions'] ) . " WHERE status IN ('pending','accepted')" );
		$applied = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . esc_sql( $t['applied'] ) . ' WHERE reverted_at IS NULL AND applied_at >= %s', gmdate( 'Y-m-01 00:00:00' ) ) );
		$scans   = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . esc_sql( $t['runs'] ) . " WHERE type IN ('scan','regenerate')" );
		// phpcs:enable
		return array(
			'indexed' => $indexed,
			'orphans' => LinkGraph::orphan_count(),
			'pending' => $pending,
			'applied' => $applied,
			'scans'   => $scans,
			'budget'  => Budget::status(),
		);
	}

	/**
	 * KPI cards.
	 *
	 * @param array $k KPIs.
	 * @return void
	 */
	private static function render_kpis( array $k ) {
		$b     = $k['budget'];
		$spend = $b['cap'] > 0
			/* translators: 1: spent, 2: cap. */
			? sprintf( __( '%1$s of %2$s', 'interlink-ai' ), Cost_Panel::money( $b['spent'] ), Cost_Panel::money( $b['cap'] ) )
			: Cost_Panel::money( $b['spent'] );
		$cards = array(
			array( __( 'Pages indexed', 'interlink-ai' ), number_format_i18n( $k['indexed'] ), Menu::url( 'keywords' ), current_user_can( 'edit_others_posts' ) ),
			array( __( 'Orphan pages', 'interlink-ai' ), number_format_i18n( $k['orphans'] ), Menu::url( 'keywords', array( 'view' => 'orphan' ) ), current_user_can( 'edit_others_posts' ), __( 'No other page links to them.', 'interlink-ai' ) ),
			array( __( 'Suggestions to review', 'interlink-ai' ), number_format_i18n( $k['pending'] ), Menu::url( 'suggestions' ), true ),
			array( __( 'Links applied this month', 'interlink-ai' ), number_format_i18n( $k['applied'] ), Menu::url( 'suggestions', array( 'status' => 'applied' ) ), true ),
			array( __( 'Estimated AI spend this month', 'interlink-ai' ), $spend, Menu::url( 'settings', array( 'tab' => 'ai' ) ), current_user_can( 'manage_options' ) ),
		);
		echo '<ul class="ailsug-kpis">';
		foreach ( $cards as $card ) {
			echo '<li class="ailsug-kpi">';
			echo '<span class="ailsug-kpi-value">' . esc_html( $card[1] ) . '</span>';
			echo '<span class="ailsug-kpi-label">' . ( $card[3] ? '<a href="' . esc_url( $card[2] ) . '">' . esc_html( $card[0] ) . '</a>' : esc_html( $card[0] ) ) . '</span>';
			if ( ! empty( $card[4] ) ) {
				echo '<span class="ailsug-kpi-hint">' . esc_html( $card[4] ) . '</span>';
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Top 20 suggestions by score × the target's inbound-link deficit.
	 *
	 * @return array[]
	 */
	public static function quick_wins() {
		global $wpdb;
		$t = Activator::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$avg   = (float) $wpdb->get_var( 'SELECT AVG(inbound_count) FROM ' . esc_sql( $t['index'] ) );
		$where = "s.status = 'pending'";
		$args  = array( $avg );
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			$where .= ' AND p.post_author = %d';
			$args[] = get_current_user_id();
		}
		$args[] = 20;
		// Deficit: how far the target is below the site's average number of links in (at least 1).
		$sql = 'SELECT s.*, src.title AS source_title, tgt.title AS target_title, tgt.inbound_count AS target_inbound, s.score * GREATEST(1, %f + 1 - tgt.inbound_count) AS win FROM ' . Suggestions_Table::from() . " WHERE {$where} ORDER BY win DESC, s.score DESC LIMIT %d";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Placeholders only.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Quick wins table.
	 *
	 * @return void
	 */
	private static function render_quick_wins() {
		$rows = self::quick_wins();
		echo '<h2>' . esc_html__( 'Quick wins', 'interlink-ai' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'The most useful links to add first: strong suggestions that point to pages with few or no links yet.', 'interlink-ai' ) . '</p>';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No suggestions to review yet. Run a scan to get link ideas.', 'interlink-ai' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped ailsug-quick-wins"><thead><tr>';
		foreach ( array( __( 'Page to edit', 'interlink-ai' ), __( 'Link text', 'interlink-ai' ), __( 'Links to', 'interlink-ai' ), __( 'Relevance', 'interlink-ai' ), __( 'Action', 'interlink-ai' ) ) as $h ) {
			echo '<th scope="col">' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$ctx    = Store::split_snippet( (string) $r['context_snippet'] );
			$target = esc_html( (string) $r['target_title'] );
			if ( 0 === (int) $r['target_inbound'] ) {
				$target .= ' <span class="ailsug-badge is-orphan">' . esc_html__( 'Orphan page', 'interlink-ai' ) . '</span>';
			}
			printf(
				'<tr><td>%1$s</td><td><mark>%2$s</mark></td><td>%3$s</td><td>%4$s</td><td><a class="button button-small" href="%5$s">%6$s<span class="screen-reader-text"> %7$s</span></a></td></tr>',
				esc_html( (string) $r['source_title'] ),
				esc_html( '' !== $ctx['match'] ? $ctx['match'] : (string) $r['anchor_text'] ),
				wp_kses_post( $target ),
				esc_html( (string) (int) $r['score'] ),
				esc_url(
					Menu::url(
						'suggestions',
						array(
							'source'    => (int) $r['source_post_id'],
							'min_score' => 0,
						)
					)
				),
				esc_html__( 'Review', 'interlink-ai' ),
				/* translators: %s: page title. */
				esc_html( sprintf( __( 'suggestions for %s', 'interlink-ai' ), (string) $r['source_title'] ) )
			);
		}//end foreach
		echo '</tbody></table>';
	}

	/**
	 * Hide the onboarding checklist for this user.
	 *
	 * @return void
	 */
	public static function dismiss_onboarding() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'interlink-ai' ) );
		}
		check_admin_referer( 'ailsug_dismiss_onboarding' );
		update_user_meta( get_current_user_id(), 'ailsug_dismissed_onboarding', 1 );
		wp_safe_redirect( Menu::url() );
		exit;
	}
}
