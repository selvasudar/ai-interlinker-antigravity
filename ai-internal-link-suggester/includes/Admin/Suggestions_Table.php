<?php
/**
 * List table for the Suggestions screen.
 *
 * @package AILSUG
 */

namespace AILSUG\Admin;

use AILSUG\Activator;
use AILSUG\Settings;
use AILSUG\Suggest\Store;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Suggestions with filters, bulk actions and row actions.
 */
class Suggestions_Table extends \WP_List_Table {

	const PER_PAGE = 30;

	/**
	 * Applied records keyed by suggestion ID (for the applied view).
	 *
	 * @var array
	 */
	private $applied = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'ailsug-suggestion',
				'plural'   => 'ailsug-suggestions',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Current filters (whitelisted).
	 *
	 * @return array
	 */
	public static function filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$get    = static function ( $key ) {
			return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
		};
		$status = sanitize_key( $get( 'status' ) );
		$f      = array(
			'status'    => in_array( $status, array_merge( array( 'all' ), Store::STATUSES ), true ) ? $status : 'pending',
			'post_type' => sanitize_key( $get( 'post_type_filter' ) ),
			'min_score' => '' === $get( 'min_score' ) ? (int) Settings::get( 'min_score', 60 ) : max( 0, min( 100, (int) $get( 'min_score' ) ) ),
			'cat'       => absint( $get( 'cat' ) ),
			'orphan'    => '1' === $get( 'orphan' ),
			'source'    => absint( $get( 'source' ) ),
			's'         => $get( 's' ),
			'orderby'   => in_array( $get( 'orderby' ), array( 'score', 'created_at', 'source', 'target' ), true ) ? $get( 'orderby' ) : 'score',
			'order'     => 'asc' === strtolower( $get( 'order' ) ) ? 'ASC' : 'DESC',
		);
		// phpcs:enable
		return $f;
	}

	/**
	 * WHERE clause for filters.
	 *
	 * @param array $f            Filters.
	 * @param bool  $with_status  Include the status filter.
	 * @return array{0:string,1:array}
	 */
	public static function where( array $f, $with_status = true ) {
		global $wpdb;
		$sql    = array( '1=1' );
		$params = array();
		if ( $with_status && 'all' !== $f['status'] ) {
			$sql[]    = 's.status = %s';
			$params[] = $f['status'];
		}
		if ( $f['min_score'] > 0 && ! in_array( $f['status'], array( 'applied', 'reverted' ), true ) ) {
			$sql[]    = 's.score >= %d';
			$params[] = $f['min_score'];
		}
		if ( '' !== $f['post_type'] ) {
			$sql[]    = 'src.post_type = %s';
			$params[] = $f['post_type'];
		}
		if ( $f['cat'] ) {
			$sql[]    = "EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = s.source_post_id AND tt.term_id = %d)";
			$params[] = $f['cat'];
		}
		if ( $f['orphan'] ) {
			$sql[] = 'tgt.inbound_count = 0';
		}
		if ( $f['source'] ) {
			$sql[]    = 's.source_post_id = %d';
			$params[] = $f['source'];
		}
		if ( '' !== $f['s'] ) {
			$like     = '%' . $wpdb->esc_like( $f['s'] ) . '%';
			$sql[]    = '(s.anchor_text LIKE %s OR src.title LIKE %s OR tgt.title LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			$sql[]    = 'p.post_author = %d';
			$params[] = get_current_user_id();
		}
		return array( implode( ' AND ', $sql ), $params );
	}

	/**
	 * FROM clause.
	 *
	 * @return string
	 */
	public static function from() {
		global $wpdb;
		$t = Activator::tables();
		return esc_sql( $t['suggestions'] ) . ' s LEFT JOIN ' . esc_sql( $t['index'] ) . ' src ON src.post_id = s.source_post_id LEFT JOIN ' . esc_sql( $t['index'] ) . " tgt ON tgt.post_id = s.target_post_id INNER JOIN {$wpdb->posts} p ON p.ID = s.source_post_id";
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'     => '<input type="checkbox" />',
			'source' => __( 'Page to edit', 'ai-internal-link-suggester' ),
			'anchor' => __( 'Suggested link text (in context)', 'ai-internal-link-suggester' ),
			'target' => __( 'Links to', 'ai-internal-link-suggester' ),
			'score'  => __( 'Relevance', 'ai-internal-link-suggester' ),
			'reason' => __( 'Why', 'ai-internal-link-suggester' ),
			'status' => __( 'Status', 'ai-internal-link-suggester' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'score'  => array( 'score', true ),
			'source' => array( 'source', false ),
			'target' => array( 'target', false ),
		);
	}

	/**
	 * Status views.
	 *
	 * @return array
	 */
	protected function get_views() {
		global $wpdb;
		$f                      = self::filters();
		list( $where, $params ) = self::where( $f, false );
		$sql                    = 'SELECT s.status, COUNT(*) AS n FROM ' . self::from() . " WHERE {$where} GROUP BY s.status";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Placeholders only.
		$rows   = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ), OBJECT_K ) : $wpdb->get_results( $sql, OBJECT_K );
		$labels = array(
			'pending'  => __( 'To review', 'ai-internal-link-suggester' ),
			'accepted' => __( 'Accepted (ready to apply)', 'ai-internal-link-suggester' ),
			'applied'  => __( 'Applied', 'ai-internal-link-suggester' ),
			'rejected' => __( 'Rejected', 'ai-internal-link-suggester' ),
			'stale'    => __( 'Out of date', 'ai-internal-link-suggester' ),
			'reverted' => __( 'Undone', 'ai-internal-link-suggester' ),
			'all'      => __( 'All', 'ai-internal-link-suggester' ),
		);
		$views  = array();
		$total  = 0;
		foreach ( (array) $rows as $row ) {
			$total += (int) $row->n;
		}
		foreach ( $labels as $status => $label ) {
			$n                = 'all' === $status ? $total : (int) ( isset( $rows[ $status ] ) ? $rows[ $status ]->n : 0 );
			$views[ $status ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( Menu::url( 'suggestions', array( 'status' => $status ) ) ),
				$f['status'] === $status ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $n ) )
			);
		}
		return $views;
	}

	/**
	 * Bulk actions by view.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		$status = self::filters()['status'];
		if ( 'applied' === $status ) {
			return array( 'revert' => __( 'Undo (remove links)', 'ai-internal-link-suggester' ) );
		}
		return array(
			'accept' => __( 'Accept', 'ai-internal-link-suggester' ),
			'reject' => __( 'Reject', 'ai-internal-link-suggester' ),
			'apply'  => __( 'Apply to pages…', 'ai-internal-link-suggester' ),
		);
	}

	/**
	 * Filters above the table.
	 *
	 * @param string $which top|bottom.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$f = self::filters();
		echo '<div class="alignleft actions ailsug-filters">';

		echo '<label class="screen-reader-text" for="ailsug-f-type">' . esc_html__( 'Content type', 'ai-internal-link-suggester' ) . '</label><select name="post_type_filter" id="ailsug-f-type"><option value="">' . esc_html__( 'All content types', 'ai-internal-link-suggester' ) . '</option>';
		foreach ( Settings::default_post_types() as $type ) {
			$obj = get_post_type_object( $type );
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $type ), selected( $f['post_type'], $type, false ), esc_html( $obj ? $obj->labels->name : $type ) );
		}
		echo '</select>';

		wp_dropdown_categories(
			array(
				'show_option_all' => __( 'All categories', 'ai-internal-link-suggester' ),
				'name'            => 'cat',
				'id'              => 'ailsug-f-cat',
				'selected'        => $f['cat'],
				'hide_empty'      => true,
				'hierarchical'    => true,
			)
		);

		printf(
			'<label for="ailsug-f-score">%1$s</label> <input type="number" min="0" max="100" id="ailsug-f-score" name="min_score" value="%2$d" class="small-text" />',
			esc_html__( 'Min. relevance', 'ai-internal-link-suggester' ),
			(int) $f['min_score']
		);
		printf(
			' <label><input type="checkbox" name="orphan" value="1" %1$s /> %2$s</label> ',
			checked( $f['orphan'], true, false ),
			esc_html__( 'Only links to orphan pages', 'ai-internal-link-suggester' )
		);
		submit_button( __( 'Filter', 'ai-internal-link-suggester' ), '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * Load items.
	 *
	 * @return void
	 */
	public function prepare_items() {
		global $wpdb;
		$f                      = self::filters();
		list( $where, $params ) = self::where( $f );
		$orders                 = array(
			'score'      => 's.score',
			'created_at' => 's.created_at',
			'source'     => 'src.title',
			'target'     => 'tgt.title',
		);
		$order                  = $orders[ $f['orderby'] ] . ' ' . $f['order'];
		$page                   = $this->get_pagenum();
		$offset                 = ( $page - 1 ) * self::PER_PAGE;
		$from                   = self::from();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Placeholders only; order whitelisted.
		$count_sql   = "SELECT COUNT(*) FROM {$from} WHERE {$where}";
		$total       = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
		$list_sql    = "SELECT s.*, src.title AS source_title, src.post_type AS source_type, src.is_builder AS source_builder, tgt.title AS target_title, tgt.primary_keyword AS target_keyword, tgt.inbound_count AS target_inbound FROM {$from} WHERE {$where} ORDER BY {$order}, s.id DESC LIMIT %d OFFSET %d";
		$this->items = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, array( self::PER_PAGE, $offset ) ) ), ARRAY_A );
		// phpcs:enable

		if ( 'applied' === $f['status'] && $this->items ) {
			$t            = Activator::tables();
			$ids          = array_map( 'intval', wp_list_pluck( $this->items, 'id' ) );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders built above.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT a.*, (SELECT COUNT(*) FROM ' . esc_sql( $t['applied'] ) . ' b WHERE b.batch_id = a.batch_id AND b.reverted_at IS NULL) AS batch_size FROM ' . esc_sql( $t['applied'] ) . " a WHERE a.reverted_at IS NULL AND a.suggestion_id IN ({$placeholders})", $ids ), ARRAY_A );
			foreach ( (array) $rows as $r ) {
				$this->applied[ (int) $r['suggestion_id'] ] = $r;
			}
		}

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'source' );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/**
	 * Empty state with a call to action.
	 *
	 * @return void
	 */
	public function no_items() {
		$status = self::filters()['status'];
		if ( 'pending' === $status ) {
			esc_html_e( 'Nothing to review. Run a scan from the Internal Links dashboard to get link ideas, or lower the minimum relevance filter.', 'ai-internal-link-suggester' );
		} else {
			esc_html_e( 'No suggestions match these filters.', 'ai-internal-link-suggester' );
		}
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		if ( 'applied' === $item['status'] ) {
			$rec = $this->applied[ (int) $item['id'] ] ?? null;
			if ( ! $rec ) {
				return '';
			}
			return sprintf( '<label class="screen-reader-text" for="ailsug-cb-%1$d">%2$s</label><input type="checkbox" id="ailsug-cb-%1$d" name="applied_ids[]" value="%3$d" />', (int) $item['id'], esc_html__( 'Select', 'ai-internal-link-suggester' ), (int) $rec['id'] );
		}
		return sprintf( '<label class="screen-reader-text" for="ailsug-cb-%1$d">%2$s</label><input type="checkbox" id="ailsug-cb-%1$d" name="ids[]" value="%1$d" />', (int) $item['id'], esc_html__( 'Select', 'ai-internal-link-suggester' ) );
	}

	/**
	 * Action URL with nonce.
	 *
	 * @param string $action Action.
	 * @param array  $args   Args.
	 * @return string
	 */
	public static function action_url( $action, array $args ) {
		return wp_nonce_url( Menu::url( 'suggestions', array_merge( self::filters_query(), $args, array( 'ailsug_action' => $action ) ) ), 'ailsug_row_' . $action );
	}

	/**
	 * Current filters as query args (to keep them across actions).
	 *
	 * @return array
	 */
	private static function filters_query() {
		$f = self::filters();
		return array_filter(
			array(
				'status'           => $f['status'],
				'post_type_filter' => $f['post_type'],
				'cat'              => $f['cat'],
				'orphan'           => $f['orphan'] ? 1 : 0,
			)
		);
	}

	/**
	 * Source column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_source( $item ) {
		$id    = (int) $item['source_post_id'];
		$title = '' !== (string) $item['source_title'] ? $item['source_title'] : get_the_title( $id );
		$out   = '<strong><a href="' . esc_url( (string) get_edit_post_link( $id ) ) . '">' . esc_html( $title ) . '</a></strong>';
		if ( (int) $item['source_builder'] ) {
			$out .= '<br /><span class="ailsug-badge">' . esc_html__( 'Page builder: view only', 'ai-internal-link-suggester' ) . '</span>';
		}
		$actions = array(
			'view' => '<a href="' . esc_url( (string) get_permalink( $id ) ) . '">' . esc_html__( 'View page', 'ai-internal-link-suggester' ) . '</a>',
			'only' => '<a href="' . esc_url(
				Menu::url(
					'suggestions',
					array(
						'source' => $id,
						'status' => 'all',
					)
				)
			) . '">' . esc_html__( 'All for this page', 'ai-internal-link-suggester' ) . '</a>',
		);
		if ( current_user_can( (string) apply_filters( 'ailsug_run_capability', 'manage_options' ) ) ) {
			$actions['regen'] = '<button type="button" class="button-link ailsug-start-run" data-run-type="regenerate" data-post-ids="' . esc_attr( (string) wp_json_encode( array( $id ) ) ) . '">' . esc_html__( 'Re-generate…', 'ai-internal-link-suggester' ) . '</button>';
		}
		return $out . $this->row_actions( $actions );
	}

	/**
	 * Anchor in context, with preview and inline edit.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_anchor( $item ) {
		$ctx = Store::split_snippet( (string) $item['context_snippet'] );
		$id  = (int) $item['id'];
		$out = '<p class="ailsug-context">…' . esc_html( $ctx['before'] ) . '<mark>' . esc_html( '' !== $ctx['match'] ? $ctx['match'] : $item['anchor_text'] ) . '</mark>' . esc_html( $ctx['after'] ) . '…</p>';

		$actions = array();
		if ( in_array( $item['status'], array( 'pending', 'stale', 'rejected', 'reverted' ), true ) ) {
			$actions['accept'] = '<a href="' . esc_url( self::action_url( 'accept', array( 'id' => $id ) ) ) . '">' . esc_html__( 'Accept', 'ai-internal-link-suggester' ) . '</a>';
		}
		if ( in_array( $item['status'], array( 'pending', 'accepted' ), true ) ) {
			$actions['reject'] = '<a href="' . esc_url( self::action_url( 'reject', array( 'id' => $id ) ) ) . '">' . esc_html__( 'Reject', 'ai-internal-link-suggester' ) . '</a>';
			if ( ! (int) $item['source_builder'] ) {
				$actions['apply'] = '<a href="' . esc_url( self::action_url( 'apply', array( 'ids' => $id ) ) ) . '">' . esc_html__( 'Apply…', 'ai-internal-link-suggester' ) . '</a>';
			}
		}
		if ( 'applied' === $item['status'] && isset( $this->applied[ $id ] ) ) {
			$rec             = $this->applied[ $id ];
			$actions['undo'] = '<a href="' . esc_url( self::action_url( 'revert', array( 'applied_ids' => (int) $rec['id'] ) ) ) . '">' . esc_html__( 'Undo', 'ai-internal-link-suggester' ) . '</a>';
			if ( (int) $rec['batch_size'] > 1 ) {
				/* translators: %d: number of links in the batch. */
				$actions['undo_batch'] = '<a href="' . esc_url( self::action_url( 'revert_batch', array( 'batch' => $rec['batch_id'] ) ) ) . '">' . esc_html( sprintf( __( 'Undo whole batch (%d links)', 'ai-internal-link-suggester' ), (int) $rec['batch_size'] ) ) . '</a>';
			}
		}
		if ( ! in_array( $item['status'], array( 'applied' ), true ) ) {
			$actions['edit'] = '<button type="button" class="button-link ailsug-edit-toggle" aria-expanded="false" aria-controls="ailsug-edit-' . $id . '">' . esc_html__( 'Edit', 'ai-internal-link-suggester' ) . '</button>';
		}
		$actions['preview'] = '<button type="button" class="button-link ailsug-preview-toggle" data-id="' . $id . '" aria-expanded="false" aria-controls="ailsug-preview-' . $id . '">' . esc_html__( 'Preview', 'ai-internal-link-suggester' ) . '</button>';

		$out .= $this->row_actions( $actions, true );
		$out .= '<div class="ailsug-preview" id="ailsug-preview-' . $id . '" hidden aria-live="polite"></div>';
		if ( 'applied' !== $item['status'] ) {
			$out .= sprintf(
				'<div class="ailsug-edit" id="ailsug-edit-%1$d" data-id="%1$d" hidden><p><label for="ailsug-anchor-%1$d">%2$s</label><br /><input type="text" id="ailsug-anchor-%1$d" class="regular-text ailsug-edit-anchor" value="%3$s" maxlength="100" /></p><p><label for="ailsug-target-%1$d">%4$s</label><br /><input type="text" id="ailsug-target-%1$d" class="regular-text ailsug-edit-target" value="%5$s" /></p><p><button type="button" class="button button-small ailsug-edit-save">%6$s</button> <span class="ailsug-edit-status" role="status" aria-live="polite"></span></p></div>',
				$id,
				esc_html__( 'Link text (must appear in the page exactly)', 'ai-internal-link-suggester' ),
				esc_attr( $item['anchor_text'] ),
				esc_html__( 'Target page (ID or address)', 'ai-internal-link-suggester' ),
				esc_attr( (string) get_permalink( (int) $item['target_post_id'] ) ),
				esc_html__( 'Save changes', 'ai-internal-link-suggester' )
			);
		}
		return $out;
	}

	/**
	 * Target column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_target( $item ) {
		$id    = (int) $item['target_post_id'];
		$title = '' !== (string) $item['target_title'] ? $item['target_title'] : get_the_title( $id );
		$out   = '<a href="' . esc_url( (string) get_permalink( $id ) ) . '">' . esc_html( $title ) . '</a>';
		if ( '' !== (string) $item['target_keyword'] ) {
			/* translators: %s: keyword. */
			$out .= '<br /><span class="description">' . esc_html( sprintf( __( 'Keyword: %s', 'ai-internal-link-suggester' ), $item['target_keyword'] ) ) . '</span>';
		}
		if ( null !== $item['target_inbound'] && 0 === (int) $item['target_inbound'] ) {
			$out .= '<br /><span class="ailsug-badge is-orphan">' . esc_html__( 'Orphan page', 'ai-internal-link-suggester' ) . '</span>';
		}
		return $out;
	}

	/**
	 * Score badge with text.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_score( $item ) {
		$score = (int) $item['score'];
		$label = $score >= 80 ? __( 'strong', 'ai-internal-link-suggester' ) : ( $score >= 60 ? __( 'good', 'ai-internal-link-suggester' ) : __( 'weak', 'ai-internal-link-suggester' ) );
		$class = $score >= 80 ? 'is-strong' : ( $score >= 60 ? 'is-good' : 'is-weak' );
		return '<span class="ailsug-score ' . esc_attr( $class ) . '">' . esc_html( $score . ' · ' . $label ) . '</span>';
	}

	/**
	 * Reason.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_reason( $item ) {
		return esc_html( (string) $item['reason'] );
	}

	/**
	 * Status (with applied details).
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_status( $item ) {
		$labels = array(
			'pending'  => __( 'To review', 'ai-internal-link-suggester' ),
			'accepted' => __( 'Accepted', 'ai-internal-link-suggester' ),
			'applied'  => __( 'Applied', 'ai-internal-link-suggester' ),
			'rejected' => __( 'Rejected', 'ai-internal-link-suggester' ),
			'stale'    => __( 'Out of date', 'ai-internal-link-suggester' ),
			'reverted' => __( 'Undone', 'ai-internal-link-suggester' ),
		);
		$out    = '<span class="ailsug-badge is-status-' . esc_attr( $item['status'] ) . '">' . esc_html( $labels[ $item['status'] ] ?? $item['status'] ) . '</span>';
		if ( 'applied' === $item['status'] && isset( $this->applied[ (int) $item['id'] ] ) ) {
			$rec  = $this->applied[ (int) $item['id'] ];
			$user = get_userdata( (int) $rec['applied_by'] );
			/* translators: 1: date, 2: user name. */
			$out .= '<br /><span class="description">' . esc_html( sprintf( __( '%1$s by %2$s', 'ai-internal-link-suggester' ), get_date_from_gmt( $rec['applied_at'], get_option( 'date_format' ) ), $user ? $user->display_name : '—' ) ) . '</span>';
			if ( (int) $rec['revision_id'] && get_post( (int) $rec['revision_id'] ) ) {
				$out .= '<br /><a href="' . esc_url( admin_url( 'revision.php?revision=' . (int) $rec['revision_id'] ) ) . '">' . esc_html__( 'Compare revisions', 'ai-internal-link-suggester' ) . '</a>';
			}
		}
		if ( 'stale' === $item['status'] ) {
			$out .= '<br /><span class="description">' . esc_html__( 'A page changed since this idea was made. Re-generate, or edit the link text.', 'ai-internal-link-suggester' ) . '</span>';
		}
		return $out;
	}
}
