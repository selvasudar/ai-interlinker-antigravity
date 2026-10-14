<?php
/**
 * Keywords screen.
 *
 * @package AILSUG
 */

namespace AILSUG\Admin;

use AILSUG\Activator;
use AILSUG\Queue\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Every indexed URL: keyword, where it came from, links in/out. Inline editing and CSV export.
 */
final class Keywords_Page {

	const CAP = 'edit_others_posts';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_post_ailsug_export_keywords', array( __CLASS__, 'export' ) );
	}

	/**
	 * Render.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'linkcortex' ) );
		}
		$table = new Keywords_Table();
		$table->prepare_items();
		$f = Keywords_Table::filters();

		echo '<div class="wrap ailsug-wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Keywords', 'linkcortex' ) . '</h1>';
		if ( current_user_can( 'manage_options' ) ) {
			echo ' <button type="button" class="page-title-action ailsug-start-run" data-run-type="index">' . esc_html__( 'Update page index (free, no AI)', 'linkcortex' ) . '</button>';
			if ( \AILSUG\AI\Availability::is_available() ) {
				echo ' <button type="button" class="page-title-action ailsug-start-run" data-run-type="keywords">' . esc_html__( 'Find missing keywords with AI…', 'linkcortex' ) . '</button>';
			}
		}
		$export = wp_nonce_url(
			add_query_arg(
				array_filter(
					array(
						'action'           => 'ailsug_export_keywords',
						'view'             => $f['view'],
						'post_type_filter' => $f['post_type'],
						's'                => $f['s'],
					)
				),
				admin_url( 'admin-post.php' )
			),
			'ailsug_export_keywords'
		);
		echo ' <a class="page-title-action" href="' . esc_url( $export ) . '">' . esc_html__( 'Export CSV', 'linkcortex' ) . '</a>';
		echo '<hr class="wp-header-end" />';

		echo '<p class="description">' . esc_html__( 'The primary keyword is the phrase people search to find a page. Other pages link to it using this phrase. Type your own to override the SEO plugin or AI.', 'linkcortex' ) . '</p>';

		self::progress_box( Queue::active_run( array( 'index', 'keywords' ) ) );

		$table->views();
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( Menu::SLUG . '-keywords' ) . '" />';
		if ( 'all' !== $f['view'] ) {
			echo '<input type="hidden" name="view" value="' . esc_attr( $f['view'] ) . '" />';
		}
		$table->search_box( __( 'Search pages', 'linkcortex' ), 'ailsug-kw-search' );
		$table->display();
		echo '</form></div>';
	}

	/**
	 * Progress box used by run buttons (filled by admin.js).
	 *
	 * @param array|null $active Active run progress, to resume display.
	 * @return void
	 */
	public static function progress_box( $active = null ) {
		printf(
			'<div class="ailsug-progress" hidden data-active-run="%1$s"><p class="ailsug-progress-label" role="status" aria-live="polite"></p><progress class="ailsug-progress-bar" max="100" value="0" aria-label="%5$s"></progress> <button type="button" class="button ailsug-run-pause">%2$s</button> <button type="button" class="button ailsug-run-resume" hidden>%3$s</button> <button type="button" class="button-link ailsug-run-cancel">%4$s</button></div>',
			esc_attr( $active ? $active['run_id'] : '' ),
			esc_html__( 'Pause', 'linkcortex' ),
			esc_html__( 'Resume', 'linkcortex' ),
			esc_html__( 'Cancel', 'linkcortex' ),
			esc_attr__( 'Progress', 'linkcortex' )
		);
	}

	/**
	 * CSV export of the (filtered) index.
	 *
	 * @return void
	 */
	public static function export() {
		global $wpdb;
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'linkcortex' ) );
		}
		check_admin_referer( 'ailsug_export_keywords' );
		$t                      = Activator::tables();
		$f                      = Keywords_Table::filters();
		list( $where, $params ) = Keywords_Table::where( $f );
		$sql                    = "SELECT * FROM %i WHERE {$where} ORDER BY post_id ASC";
		$args                   = array_merge( array( $t['index'] ), $params );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where uses placeholders; values go through prepare().
		$rows                   = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$secondary = json_decode( (string) $row['secondary_keywords'], true );
			$out[]     = array(
				get_permalink( (int) $row['post_id'] ),
				$row['title'],
				$row['post_type'],
				$row['primary_keyword'],
				$row['keyword_source'],
				is_array( $secondary ) ? implode( '; ', $secondary ) : '',
				$row['inbound_count'],
				$row['outbound_count'],
				$row['word_count'],
			);
		}
		Csv::download(
			'internal-links-keywords-' . gmdate( 'Y-m-d' ) . '.csv',
			array( 'URL', 'Title', 'Type', 'Primary keyword', 'Keyword source', 'Secondary keywords', 'Links in', 'Links out', 'Words' ),
			$out
		);
	}
}
