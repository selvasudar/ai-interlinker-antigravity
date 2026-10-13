<?php
/**
 * Editor integration: block editor panel and a classic-editor metabox fallback.
 *
 * @package AILSUG
 */

namespace AILSUG\Admin;

use AILSUG\Activator;
use AILSUG\Index\Indexer;
use AILSUG\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Works for every included post type, including custom types and WooCommerce products.
 */
final class Editor_Panel {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'metabox' ), 10, 1 );
	}

	/**
	 * Whether the panel applies to the current screen.
	 *
	 * @return bool
	 */
	private static function applies() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && ! empty( $screen->post_type ) && in_array( $screen->post_type, (array) Settings::get( 'post_types' ), true ) && current_user_can( 'edit_posts' );
	}

	/**
	 * Enqueue the built panel (source in src/editor).
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! self::applies() ) {
			return;
		}
		$asset_file = AILSUG_DIR . 'build/index.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		wp_enqueue_script( 'ailsug-editor', AILSUG_URL . 'build/index.js', $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( 'ailsug-editor', 'linkcortex', AILSUG_DIR . 'languages' );
		wp_enqueue_style( 'ailsug-editor', AILSUG_URL . 'assets/css/editor.css', array(), AILSUG_VERSION );
		wp_style_add_data( 'ailsug-editor', 'rtl', 'replace' );
	}

	/**
	 * Classic editor metabox (hidden in the block editor, which has the panel).
	 *
	 * @param string $post_type Post type.
	 * @return void
	 */
	public static function metabox( $post_type ) {
		if ( ! in_array( $post_type, (array) Settings::get( 'post_types' ), true ) || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		add_meta_box(
			'ailsug-metabox',
			__( 'LinkCortex', 'linkcortex' ),
			array( __CLASS__, 'render_metabox' ),
			$post_type,
			'side',
			'default',
			array( '__back_compat_meta_box' => true )
		);
	}

	/**
	 * Render the metabox.
	 *
	 * @param \WP_Post $post Post.
	 * @return void
	 */
	public static function render_metabox( $post ) {
		global $wpdb;
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}
		$row = Indexer::get_row( $post->ID );
		if ( ! $row ) {
			echo '<p>' . esc_html__( 'This page is added to the link index after it is published.', 'linkcortex' ) . '</p>';
			return;
		}
		echo '<p><strong>' . esc_html__( 'Primary keyword:', 'linkcortex' ) . '</strong> ' . esc_html( '' !== $row['primary_keyword'] ? $row['primary_keyword'] : __( 'not set yet', 'linkcortex' ) ) . '</p>';
		/* translators: 1: links in, 2: links out. */
		echo '<p>' . esc_html( sprintf( __( 'Links to this page: %1$d · Links from this page: %2$d', 'linkcortex' ), (int) $row['inbound_count'], (int) $row['outbound_count'] ) ) . '</p>';

		$t = Activator::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . esc_sql( $t['suggestions'] ) . " WHERE source_post_id = %d AND status IN ('pending','accepted') ORDER BY score DESC LIMIT 10", $post->ID ), ARRAY_A );
		if ( $rows ) {
			echo '<ul class="ul-disc">';
			foreach ( $rows as $s ) {
				echo '<li><mark>' . esc_html( $s['anchor_text'] ) . '</mark> → ' . esc_html( get_the_title( (int) $s['target_post_id'] ) ) . '</li>';
			}
			echo '</ul>';
		} else {
			echo '<p>' . esc_html__( 'No link suggestions for this page yet.', 'linkcortex' ) . '</p>';
		}
		echo '<p><a class="button" href="' . esc_url(
			Menu::url(
				'suggestions',
				array(
					'source' => $post->ID,
					'status' => 'all',
				)
			)
		) . '">' . esc_html__( 'Review and apply', 'linkcortex' ) . '</a></p>';
		echo '<p class="description">' . esc_html__( 'Save your changes before applying links.', 'linkcortex' ) . '</p>';
	}
}
