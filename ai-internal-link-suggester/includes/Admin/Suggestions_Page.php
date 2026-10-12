<?php
/**
 * Suggestions screen: review → accept → apply, with undo and history.
 *
 * @package AILSUG
 */

namespace AILSUG\Admin;

use AILSUG\Activator;
use AILSUG\Queue\Queue;
use AILSUG\Suggest\Applier;
use AILSUG\Suggest\Reverter;
use AILSUG\Suggest\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Applying is always an explicit, confirmed action that shows how many pages will change.
 */
final class Suggestions_Page {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_post_ailsug_export_suggestions', array( __CLASS__, 'export_suggestions' ) );
		add_action( 'admin_post_ailsug_export_applied', array( __CLASS__, 'export_applied' ) );
	}

	/**
	 * Handle actions before output (load-{screen} hook).
	 *
	 * @return void
	 */
	public static function load() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- Each branch verifies its own nonce.
		$row_action = isset( $_GET['ailsug_action'] ) ? sanitize_key( wp_unslash( $_GET['ailsug_action'] ) ) : '';
		$bulk       = '';
		foreach ( array( 'action', 'action2' ) as $key ) {
			if ( ! empty( $_REQUEST[ $key ] ) && '-1' !== $_REQUEST[ $key ] ) {
				$bulk = sanitize_key( wp_unslash( $_REQUEST[ $key ] ) );
				break;
			}
		}
		$post_action = isset( $_POST['ailsug_do'] ) ? sanitize_key( wp_unslash( $_POST['ailsug_do'] ) ) : '';
		// phpcs:enable

		if ( $row_action ) {
			check_admin_referer( 'ailsug_row_' . $row_action );
			self::dispatch( $row_action, self::request_ids( 'ids' ), self::request_ids( 'applied_ids' ), isset( $_GET['batch'] ) ? sanitize_key( wp_unslash( $_GET['batch'] ) ) : '' );
		} elseif ( $bulk && in_array( $bulk, array( 'accept', 'reject', 'apply', 'revert' ), true ) ) {
			check_admin_referer( 'bulk-ailsug-suggestions' );
			self::dispatch( $bulk, self::request_ids( 'ids' ), self::request_ids( 'applied_ids' ), '' );
		} elseif ( 'confirm_apply' === $post_action ) {
			check_admin_referer( 'ailsug_confirm_apply' );
			$token = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
			$ids   = self::take_token( $token );
			if ( $ids ) {
				if ( count( $ids ) > 1 && ! Applier::bulk_enabled() ) {
					self::finish( array( 'message' => __( 'Applying many links at once is turned off on this site. Apply them one at a time.', 'interlink-ai' ) ) );
				}
				$res = Applier::apply( $ids );
				self::finish( array( 'results' => $res['results'] ) );
			}
			self::finish( array( 'message' => __( 'This confirmation expired. Please select the links again.', 'interlink-ai' ) ) );
		} elseif ( 'accept_above' === $post_action ) {
			check_admin_referer( 'ailsug_accept_above' );
			$min = isset( $_POST['min'] ) ? max( 1, min( 100, (int) $_POST['min'] ) ) : 80;
			$res = self::accept_above( $min );
			self::finish( array( 'results' => $res['results'] ) );
		}//end if
	}

	/**
	 * Integer list from the request.
	 *
	 * @param string $key Key.
	 * @return int[]
	 */
	private static function request_ids( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked by caller; values cast to int.
		$raw = isset( $_REQUEST[ $key ] ) ? wp_unslash( $_REQUEST[ $key ] ) : ( 'ids' === $key && isset( $_REQUEST['id'] ) ? wp_unslash( $_REQUEST['id'] ) : array() );
		return array_values( array_filter( array_map( 'absint', is_array( $raw ) ? $raw : explode( ',', (string) $raw ) ) ) );
	}

	/**
	 * Perform an action.
	 *
	 * @param string $action      Action.
	 * @param int[]  $ids         Suggestion IDs.
	 * @param int[]  $applied_ids Applied record IDs.
	 * @param string $batch       Batch ID.
	 * @return void
	 */
	private static function dispatch( $action, array $ids, array $applied_ids, $batch ) {
		switch ( $action ) {
			case 'accept':
			case 'apply':
				if ( ! $ids ) {
					self::finish( array( 'message' => __( 'Select at least one suggestion to accept.', 'interlink-ai' ) ) );
				}
				if ( count( $ids ) > 1 && ! Applier::bulk_enabled() ) {
					self::finish( array( 'message' => __( 'Applying many links at once is turned off on this site. Apply them one at a time.', 'interlink-ai' ) ) );
				}
				$res = Applier::apply( $ids );
				self::finish( array( 'results' => $res['results'] ) );
				break;
			case 'reject':
				$n = 0;
				foreach ( $ids as $id ) {
					$s = Store::get( $id );
					if ( $s && 'applied' !== $s['status'] && current_user_can( 'edit_post', (int) $s['source_post_id'] ) ) {
						$n += Store::update( $id, array( 'status' => 'rejected' ) ) ? 1 : 0;
					}
				}
				self::finish(
					array(
						/* translators: %s: count. */
						'message' => sprintf( _n( '%s suggestion rejected. It will not be suggested again.', '%s suggestions rejected. They will not be suggested again.', $n, 'interlink-ai' ), number_format_i18n( $n ) ),
					)
				);
				break;
			case 'revert':
				$records = Reverter::records( $applied_ids );
				self::finish( array( 'results' => $records ? Reverter::revert( $records ) : array() ) );
				break;
			case 'revert_batch':
				$records = Reverter::records( array(), $batch );
				self::finish( array( 'results' => $records ? Reverter::revert( $records ) : array() ) );
				break;
		}//end switch
	}

	/**
	 * Accept and apply all pending suggestions at or above a score (that the user may edit).
	 *
	 * @param int $min Minimum score.
	 * @return array{batch_id?:string,results:array[]}
	 */
	private static function accept_above( $min ) {
		global $wpdb;
		$t   = Activator::tables();
		$sql = 'SELECT s.id, s.source_post_id FROM ' . esc_sql( $t['suggestions'] ) . " WHERE s.status = 'pending' AND s.score >= %d";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Placeholders only.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $min ), ARRAY_A );
		$ids  = array();
		foreach ( (array) $rows as $row ) {
			if ( current_user_can( 'edit_post', (int) $row['source_post_id'] ) ) {
				$ids[] = (int) $row['id'];
			}
		}
		if ( ! $ids ) {
			return array(
				'results' => array(
					array(
						'status'  => 'skipped',
						'post_id' => 0,
						'message' => __( 'No pending suggestions met that relevance threshold.', 'interlink-ai' ),
					),
				),
			);
		}
		if ( count( $ids ) > 1 && ! Applier::bulk_enabled() ) {
			return array(
				'results' => array(
					array(
						'status'  => 'error',
						'post_id' => 0,
						'message' => __( 'Applying many links at once is turned off on this site. Apply them one at a time.', 'interlink-ai' ),
					),
				),
			);
		}
		return Applier::apply( $ids );
	}

	/**
	 * Read and consume a confirmation token.
	 *
	 * @param string $token Token.
	 * @return int[]
	 */
	private static function take_token( $token ) {
		$key = 'ailsug_apply_' . get_current_user_id() . '_' . $token;
		$ids = get_transient( $key );
		delete_transient( $key );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * Store a result notice and redirect back to the list.
	 *
	 * @param array $notice Notice data.
	 * @return void
	 */
	private static function finish( array $notice ) {
		set_transient( 'ailsug_notice_' . get_current_user_id(), $notice, 5 * MINUTE_IN_SECONDS );
		$referer = wp_get_referer();
		$url     = $referer && false !== strpos( $referer, 'page=' . Menu::SLUG . '-suggestions' ) ? remove_query_arg( array( 'ailsug_action', '_wpnonce', 'ids', 'applied_ids', 'batch', 'confirm' ), $referer ) : Menu::url( 'suggestions' );
		wp_safe_redirect( $url );
		exit;
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
		echo '<div class="wrap ailsug-wrap">';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Token is user-bound and verified on submit.
		$token = isset( $_GET['confirm'] ) ? sanitize_key( wp_unslash( $_GET['confirm'] ) ) : '';
		if ( $token ) {
			self::render_confirm( $token );
			echo '</div>';
			return;
		}

		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Link suggestions', 'interlink-ai' ) . '</h1>';
		$f      = Suggestions_Table::filters();
		$export = wp_nonce_url(
			add_query_arg(
				array_filter(
					array(
						'action'           => 'ailsug_export_suggestions',
						'status'           => $f['status'],
						'post_type_filter' => $f['post_type'],
						'min_score'        => $f['min_score'],
						'source'           => $f['source'],
					)
				),
				admin_url( 'admin-post.php' )
			),
			'ailsug_export_suggestions'
		);
		echo ' <a class="page-title-action" href="' . esc_url( $export ) . '">' . esc_html__( 'Export CSV', 'interlink-ai' ) . '</a>';
		echo ' <a class="page-title-action" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ailsug_export_applied' ), 'ailsug_export_applied' ) ) . '">' . esc_html__( 'Export applied links', 'interlink-ai' ) . '</a>';
		echo '<hr class="wp-header-end" />';
		echo '<p class="description">' . esc_html__( 'Review each idea and accept suggestions to instantly add links to your pages. Every change is saved as a page revision and can be undone here.', 'interlink-ai' ) . '</p>';

		self::render_notice();
		Keywords_Page::progress_box( Queue::active_run( array( 'regenerate', 'scan' ) ) );

		if ( 'pending' === $f['status'] ) {
			echo '<form method="post" class="ailsug-inline-form ailsug-accept-above">';
			wp_nonce_field( 'ailsug_accept_above' );
			echo '<input type="hidden" name="ailsug_do" value="accept_above" />';
			echo '<label for="ailsug-accept-min">' . esc_html__( 'Accept and apply all with relevance of at least', 'interlink-ai' ) . '</label> ';
			echo '<input type="number" min="1" max="100" id="ailsug-accept-min" name="min" value="80" class="small-text" /> ';
			submit_button( __( 'Accept & Apply', 'interlink-ai' ), 'secondary', 'submit', false );
			echo '</form>';
		}

		$table = new Suggestions_Table();
		$table->prepare_items();
		$table->views();
		echo '<form method="get" id="ailsug-suggestions-form">';
		echo '<input type="hidden" name="page" value="' . esc_attr( Menu::SLUG . '-suggestions' ) . '" />';
		echo '<input type="hidden" name="status" value="' . esc_attr( $f['status'] ) . '" />';
		if ( $f['source'] ) {
			echo '<input type="hidden" name="source" value="' . (int) $f['source'] . '" />';
		}
		$table->search_box( __( 'Search', 'interlink-ai' ), 'ailsug-sugg-search' );
		$table->display();
		echo '</form></div>';
	}

	/**
	 * Confirmation screen before changing pages.
	 *
	 * @param string $token Token.
	 * @return void
	 */
	private static function render_confirm( $token ) {
		$ids = get_transient( 'ailsug_apply_' . get_current_user_id() . '_' . $token );
		echo '<h1>' . esc_html__( 'Apply links to your pages?', 'interlink-ai' ) . '</h1>';
		if ( ! is_array( $ids ) ) {
			echo '<p>' . esc_html__( 'This confirmation expired. Please select the links again.', 'interlink-ai' ) . '</p><p><a class="button" href="' . esc_url( Menu::url( 'suggestions' ) ) . '">' . esc_html__( 'Back to suggestions', 'interlink-ai' ) . '</a></p>';
			return;
		}
		$preview = Applier::preview( $ids );
		echo '<div class="ailsug-status-box is-warning">';
		/* translators: 1: links, 2: pages. */
		echo '<p><strong>' . esc_html( sprintf( __( '%1$s links will be added to %2$s pages.', 'interlink-ai' ), number_format_i18n( $preview['links'] ), number_format_i18n( $preview['posts'] ) ) ) . '</strong></p>';
		echo '<p>' . esc_html__( 'Only the link is added; nothing else in your pages changes. Each page is saved as a new revision, and you can undo every link from the Applied list.', 'interlink-ai' ) . '</p>';
		if ( $preview['links'] > 1 && ! Applier::bulk_enabled() ) {
			echo '<p>' . esc_html__( 'Applying many links at once is turned off on this site. Apply them one at a time.', 'interlink-ai' ) . '</p>';
		}
		echo '</div>';
		echo '<form method="post" action="' . esc_url( Menu::url( 'suggestions' ) ) . '">';
		wp_nonce_field( 'ailsug_confirm_apply' );
		echo '<input type="hidden" name="ailsug_do" value="confirm_apply" /><input type="hidden" name="token" value="' . esc_attr( $token ) . '" />';
		submit_button( __( 'Apply links', 'interlink-ai' ), 'primary', 'submit', false, $preview['links'] ? array() : array( 'disabled' => 'disabled' ) );
		echo ' <a class="button" href="' . esc_url( Menu::url( 'suggestions' ) ) . '">' . esc_html__( 'Cancel', 'interlink-ai' ) . '</a>';
		echo '</form>';
	}

	/**
	 * Show the stored result notice.
	 *
	 * @return void
	 */
	private static function render_notice() {
		$key    = 'ailsug_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		if ( ! empty( $notice['message'] ) ) {
			echo '<div class="notice notice-info is-dismissible" role="status"><p>' . esc_html( $notice['message'] ) . '</p></div>';
		}
		if ( ! isset( $notice['results'] ) ) {
			return;
		}
		$counts = array();
		foreach ( $notice['results'] as $r ) {
			$counts[ $r['status'] ] = ( $counts[ $r['status'] ] ?? 0 ) + 1;
		}
		$labels = array(
			'applied'  => __( 'added', 'interlink-ai' ),
			'reverted' => __( 'removed', 'interlink-ai' ),
			'skipped'  => __( 'skipped', 'interlink-ai' ),
			'stale'    => __( 'out of date', 'interlink-ai' ),
			'manual'   => __( 'need your attention', 'interlink-ai' ),
			'error'    => __( 'failed', 'interlink-ai' ),
		);
		$parts  = array();
		foreach ( $counts as $status => $n ) {
			$parts[] = number_format_i18n( $n ) . ' ' . ( $labels[ $status ] ?? $status );
		}
		$ok   = empty( $counts['error'] ) && empty( $counts['manual'] );
		$type = $ok ? 'notice-success' : 'notice-warning';
		echo '<div class="notice ' . esc_attr( $type ) . ' is-dismissible" role="status"><p><strong>' . esc_html__( 'Links:', 'interlink-ai' ) . '</strong> ' . esc_html( $parts ? implode( ', ', $parts ) : __( 'nothing to do', 'interlink-ai' ) ) . '</p>';
		$details = array_filter(
			$notice['results'],
			static function ( $r ) {
				return ! in_array( $r['status'], array( 'applied', 'reverted' ), true );
			}
		);
		if ( $details ) {
			echo '<ul class="ul-disc">';
			foreach ( $details as $r ) {
				echo '<li><strong>' . esc_html( get_the_title( (int) $r['post_id'] ) ) . ':</strong> ' . esc_html( $r['message'] );
				if ( ! empty( $r['revision_url'] ) ) {
					echo ' <a href="' . esc_url( $r['revision_url'] ) . '">' . esc_html__( 'Open the saved revision', 'interlink-ai' ) . '</a>';
				}
				echo '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	/**
	 * CSV of suggestions (current filters).
	 *
	 * @return void
	 */
	public static function export_suggestions() {
		global $wpdb;
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'interlink-ai' ) );
		}
		check_admin_referer( 'ailsug_export_suggestions' );
		$f                      = Suggestions_Table::filters();
		list( $where, $params ) = Suggestions_Table::where( $f );
		$sql                    = 'SELECT s.*, src.title AS source_title, tgt.title AS target_title FROM ' . Suggestions_Table::from() . " WHERE {$where} ORDER BY s.score DESC";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Placeholders only.
		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array( get_permalink( (int) $r['source_post_id'] ), $r['source_title'], $r['anchor_text'], get_permalink( (int) $r['target_post_id'] ), $r['target_title'], $r['score'], $r['reason'], $r['status'], $r['created_at'] );
		}
		Csv::download( 'internal-link-suggestions-' . gmdate( 'Y-m-d' ) . '.csv', array( 'Page URL', 'Page title', 'Link text', 'Target URL', 'Target title', 'Relevance', 'Reason', 'Status', 'Created (UTC)' ), $out );
	}

	/**
	 * CSV of applied links (history).
	 *
	 * @return void
	 */
	public static function export_applied() {
		global $wpdb;
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'interlink-ai' ) );
		}
		check_admin_referer( 'ailsug_export_applied' );
		$t = Activator::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$rows = $wpdb->get_results( 'SELECT * FROM ' . esc_sql( $t['applied'] ) . ' ORDER BY id DESC', ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$user  = get_userdata( (int) $r['applied_by'] );
			$out[] = array( get_permalink( (int) $r['post_id'] ), $r['anchor_text'], get_permalink( (int) $r['target_post_id'] ), $r['applied_at'], $user ? $user->user_login : '', $r['reverted_at'] ? $r['reverted_at'] : '', $r['batch_id'], $r['revision_id'] );
		}
		Csv::download( 'internal-links-applied-' . gmdate( 'Y-m-d' ) . '.csv', array( 'Page URL', 'Link text', 'Target URL', 'Applied (UTC)', 'Applied by', 'Undone (UTC)', 'Batch', 'Revision ID' ), $out );
	}
}
