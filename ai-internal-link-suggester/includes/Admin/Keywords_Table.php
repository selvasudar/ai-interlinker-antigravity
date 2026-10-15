<?php
/**
 * List table for the Keywords screen.
 *
 * @package AILSUG
 */

namespace AILSUG\Admin;

use AILSUG\Activator;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Every indexed URL with its keyword and link counts.
 */
class Keywords_Table extends \WP_List_Table {

	const PER_PAGE = 50;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'ailsug-keyword',
				'plural'   => 'ailsug-keywords',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Read and whitelist the current filters.
	 *
	 * @return array{view:string,post_type:string,s:string,orderby:string,order:string}
	 */
	public static function filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$view      = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'all';
		$post_type = isset( $_GET['post_type_filter'] ) ? sanitize_key( wp_unslash( $_GET['post_type_filter'] ) ) : '';
		$search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$orderby   = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'inbound_count';
		$order     = isset( $_GET['order'] ) && 'desc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? 'DESC' : 'ASC';
		// phpcs:enable
		return array(
			'view'      => in_array( $view, array( 'all', 'nokeyword', 'orphan', 'low' ), true ) ? $view : 'all',
			'post_type' => $post_type,
			's'         => $search,
			'orderby'   => in_array( $orderby, array( 'title', 'primary_keyword', 'inbound_count', 'outbound_count', 'word_count' ), true ) ? $orderby : 'inbound_count',
			'order'     => $order,
		);
	}

	/**
	 * Build a WHERE clause for filters.
	 *
	 * @param array $f Filters.
	 * @return array{0:string,1:array} SQL and params.
	 */
	/**
	 * Static WHERE clause SQL format string with placeholders.
	 *
	 * @return string
	 */
	public static function where_clause() {
		return '( %s = \'all\' OR ( %s = \'nokeyword\' AND primary_keyword = \'\' ) OR ( %s = \'orphan\' AND inbound_count = 0 ) OR ( %s = \'low\' AND inbound_count BETWEEN 1 AND 2 ) ) AND ( %s = \'\' OR post_type = %s ) AND ( %s = \'\' OR title LIKE %s OR primary_keyword LIKE %s )';
	}

	/**
	 * Parameter array for the static WHERE clause.
	 *
	 * @param array $f Filters.
	 * @return array
	 */
	public static function where_params( array $f ) {
		global $wpdb;
		$view      = (string) $f['view'];
		$post_type = (string) $f['post_type'];
		$search    = ( '' !== $f['s'] ) ? '%' . $wpdb->esc_like( $f['s'] ) . '%' : '';

		return array(
			$view,
			$view,
			$view,
			$view,
			$post_type,
			$post_type,
			$search,
			$search,
			$search,
		);
	}

	/**
	 * WHERE clause for filters.
	 *
	 * @param array $f Filters.
	 * @return array{0:string,1:array}
	 */
	public static function where( array $f ) {
		return array( self::where_clause(), self::where_params( $f ) );
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'title'           => __( 'Page', 'linkcortex' ),
			'primary_keyword' => __( 'Primary keyword', 'linkcortex' ),
			'source'          => __( 'Keyword from', 'linkcortex' ),
			'inbound_count'   => __( 'Links in', 'linkcortex' ),
			'outbound_count'  => __( 'Links out', 'linkcortex' ),
			'word_count'      => __( 'Words', 'linkcortex' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'title'           => array( 'title', false ),
			'primary_keyword' => array( 'primary_keyword', false ),
			'inbound_count'   => array( 'inbound_count', false ),
			'outbound_count'  => array( 'outbound_count', true ),
			'word_count'      => array( 'word_count', true ),
		);
	}

	/**
	 * Views (All / No keyword / Orphans / Few links in).
	 *
	 * @return array
	 */
	protected function get_views() {
		global $wpdb;
		$t = Activator::tables();
		$f = self::filters();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$counts = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS total, SUM(primary_keyword = '') AS nokeyword, SUM(inbound_count = 0) AS orphan, SUM(inbound_count BETWEEN 1 AND 2) AS low FROM %i", $t['index'] ), ARRAY_A );
		$labels = array(
			'all'       => __( 'All', 'linkcortex' ),
			'nokeyword' => __( 'No keyword yet', 'linkcortex' ),
			'orphan'    => __( 'Orphan pages (no links in)', 'linkcortex' ),
			'low'       => __( 'Few links in (1–2)', 'linkcortex' ),
		);
		$keys   = array(
			'all'       => 'total',
			'nokeyword' => 'nokeyword',
			'orphan'    => 'orphan',
			'low'       => 'low',
		);
		$views  = array();
		foreach ( $labels as $view => $label ) {
			$url            = Menu::url( 'keywords', 'all' === $view ? array() : array( 'view' => $view ) );
			$views[ $view ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( $url ),
				$f['view'] === $view ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( (int) ( $counts[ $keys[ $view ] ] ?? 0 ) ) )
			);
		}
		return $views;
	}

	/**
	 * Post type filter dropdown.
	 *
	 * @param string $which top|bottom.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$f = self::filters();
		echo '<div class="alignleft actions">';
		echo '<label class="screen-reader-text" for="ailsug-post-type-filter">' . esc_html__( 'Filter by content type', 'linkcortex' ) . '</label>';
		echo '<select name="post_type_filter" id="ailsug-post-type-filter"><option value="">' . esc_html__( 'All content types', 'linkcortex' ) . '</option>';
		foreach ( \AILSUG\Settings::default_post_types() as $type ) {
			$object = get_post_type_object( $type );
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $type ), selected( $f['post_type'], $type, false ), esc_html( $object ? $object->labels->name : $type ) );
		}
		echo '</select>';
		submit_button( __( 'Filter', 'linkcortex' ), '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * Load items.
	 *
	 * @return void
	 */
	public function prepare_items() {
		global $wpdb;
		$t        = Activator::tables();
		$f        = self::filters();
		$page     = $this->get_pagenum();
		$offset   = ( $page - 1 ) * self::PER_PAGE;
		$w_params = self::where_params( $f );
		$c_args   = array_merge( array( $t['index'] ), $w_params );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$total = (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic filter parameters passed as array.
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE ( %s = 'all' OR ( %s = 'nokeyword' AND primary_keyword = '' ) OR ( %s = 'orphan' AND inbound_count = 0 ) OR ( %s = 'low' AND inbound_count BETWEEN 1 AND 2 ) ) AND ( %s = '' OR post_type = %s ) AND ( %s = '' OR title LIKE %s OR primary_keyword LIKE %s )",
				$c_args
			)
		);

		$orderby   = in_array( $f['orderby'], array( 'title', 'primary_keyword', 'inbound_count', 'outbound_count', 'word_count' ), true ) ? $f['orderby'] : 'title';
		$order     = ( 'asc' === strtolower( $f['order'] ) ) ? 'ASC' : 'DESC';
		$list_args = array_merge( array( $t['index'] ), $w_params, array( self::PER_PAGE, $offset ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Direct query on own custom table with dynamic filter parameters.
		switch ( "{$orderby}_{$order}" ) {
			case 'primary_keyword_ASC':
				$this->items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE ( %s = 'all' OR ( %s = 'nokeyword' AND primary_keyword = '' ) OR ( %s = 'orphan' AND inbound_count = 0 ) OR ( %s = 'low' AND inbound_count BETWEEN 1 AND 2 ) ) AND ( %s = '' OR post_type = %s ) AND ( %s = '' OR title LIKE %s OR primary_keyword LIKE %s ) ORDER BY primary_keyword ASC, post_id ASC LIMIT %d OFFSET %d", $list_args ), ARRAY_A );
				break;
			case 'primary_keyword_DESC':
				$this->items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE ( %s = 'all' OR ( %s = 'nokeyword' AND primary_keyword = '' ) OR ( %s = 'orphan' AND inbound_count = 0 ) OR ( %s = 'low' AND inbound_count BETWEEN 1 AND 2 ) ) AND ( %s = '' OR post_type = %s ) AND ( %s = '' OR title LIKE %s OR primary_keyword LIKE %s ) ORDER BY primary_keyword DESC, post_id ASC LIMIT %d OFFSET %d", $list_args ), ARRAY_A );
				break;
			case 'inbound_count_ASC':
				$this->items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE ( %s = 'all' OR ( %s = 'nokeyword' AND primary_keyword = '' ) OR ( %s = 'orphan' AND inbound_count = 0 ) OR ( %s = 'low' AND inbound_count BETWEEN 1 AND 2 ) ) AND ( %s = '' OR post_type = %s ) AND ( %s = '' OR title LIKE %s OR primary_keyword LIKE %s ) ORDER BY inbound_count ASC, post_id ASC LIMIT %d OFFSET %d", $list_args ), ARRAY_A );
				break;
			case 'inbound_count_DESC':
				$this->items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE ( %s = 'all' OR ( %s = 'nokeyword' AND primary_keyword = '' ) OR ( %s = 'orphan' AND inbound_count = 0 ) OR ( %s = 'low' AND inbound_count BETWEEN 1 AND 2 ) ) AND ( %s = '' OR post_type = %s ) AND ( %s = '' OR title LIKE %s OR primary_keyword LIKE %s ) ORDER BY inbound_count DESC, post_id ASC LIMIT %d OFFSET %d", $list_args ), ARRAY_A );
				break;
			case 'outbound_count_ASC':
				$this->items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE ( %s = 'all' OR ( %s = 'nokeyword' AND primary_keyword = '' ) OR ( %s = 'orphan' AND inbound_count = 0 ) OR ( %s = 'low' AND inbound_count BETWEEN 1 AND 2 ) ) AND ( %s = '' OR post_type = %s ) AND ( %s = '' OR title LIKE %s OR primary_keyword LIKE %s ) ORDER BY outbound_count ASC, post_id ASC LIMIT %d OFFSET %d", $list_args ), ARRAY_A );
				break;
			case 'outbound_count_DESC':
				$this->items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE ( %s = 'all' OR ( %s = 'nokeyword' AND primary_keyword = '' ) OR ( %s = 'orphan' AND inbound_count = 0 ) OR ( %s = 'low' AND inbound_count BETWEEN 1 AND 2 ) ) AND ( %s = '' OR post_type = %s ) AND ( %s = '' OR title LIKE %s OR primary_keyword LIKE %s ) ORDER BY outbound_count DESC, post_id ASC LIMIT %d OFFSET %d", $list_args ), ARRAY_A );
				break;
			case 'word_count_ASC':
				$this->items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE ( %s = 'all' OR ( %s = 'nokeyword' AND primary_keyword = '' ) OR ( %s = 'orphan' AND inbound_count = 0 ) OR ( %s = 'low' AND inbound_count BETWEEN 1 AND 2 ) ) AND ( %s = '' OR post_type = %s ) AND ( %s = '' OR title LIKE %s OR primary_keyword LIKE %s ) ORDER BY word_count ASC, post_id ASC LIMIT %d OFFSET %d", $list_args ), ARRAY_A );
				break;
			case 'word_count_DESC':
				$this->items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE ( %s = 'all' OR ( %s = 'nokeyword' AND primary_keyword = '' ) OR ( %s = 'orphan' AND inbound_count = 0 ) OR ( %s = 'low' AND inbound_count BETWEEN 1 AND 2 ) ) AND ( %s = '' OR post_type = %s ) AND ( %s = '' OR title LIKE %s OR primary_keyword LIKE %s ) ORDER BY word_count DESC, post_id ASC LIMIT %d OFFSET %d", $list_args ), ARRAY_A );
				break;
			case 'title_DESC':
				$this->items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE ( %s = 'all' OR ( %s = 'nokeyword' AND primary_keyword = '' ) OR ( %s = 'orphan' AND inbound_count = 0 ) OR ( %s = 'low' AND inbound_count BETWEEN 1 AND 2 ) ) AND ( %s = '' OR post_type = %s ) AND ( %s = '' OR title LIKE %s OR primary_keyword LIKE %s ) ORDER BY title DESC, post_id ASC LIMIT %d OFFSET %d", $list_args ), ARRAY_A );
				break;
			case 'title_ASC':
			default:
				$this->items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE ( %s = 'all' OR ( %s = 'nokeyword' AND primary_keyword = '' ) OR ( %s = 'orphan' AND inbound_count = 0 ) OR ( %s = 'low' AND inbound_count BETWEEN 1 AND 2 ) ) AND ( %s = '' OR post_type = %s ) AND ( %s = '' OR title LIKE %s OR primary_keyword LIKE %s ) ORDER BY title ASC, post_id ASC LIMIT %d OFFSET %d", $list_args ), ARRAY_A );
				break;
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'title' );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/**
	 * Empty state.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No pages here yet. Click "Update page index" above to read your site (free, no AI).', 'linkcortex' );
	}

	/**
	 * Title column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_title( $item ) {
		$id    = (int) $item['post_id'];
		$title = '' !== $item['title'] ? $item['title'] : __( '(no title)', 'linkcortex' );
		$out   = '<strong>' . esc_html( $title ) . '</strong>';
		$type  = get_post_type_object( $item['post_type'] );
		$meta  = array( $type ? $type->labels->singular_name : $item['post_type'] );
		if ( (int) $item['is_noindex'] ) {
			$meta[] = __( 'noindex', 'linkcortex' );
		}
		if ( (int) $item['is_builder'] ) {
			$meta[] = __( 'page builder (view only)', 'linkcortex' );
		}
		$out    .= '<br /><span class="description">' . esc_html( implode( ' · ', $meta ) ) . '</span>';
		$actions = array();
		if ( current_user_can( 'edit_post', $id ) ) {
			$actions['edit'] = '<a href="' . esc_url( (string) get_edit_post_link( $id ) ) . '">' . esc_html__( 'Edit', 'linkcortex' ) . '</a>';
		}
		$actions['view'] = '<a href="' . esc_url( (string) get_permalink( $id ) ) . '">' . esc_html__( 'View', 'linkcortex' ) . '</a>';
		return $out . $this->row_actions( $actions );
	}

	/**
	 * Keyword column (inline editable).
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_primary_keyword( $item ) {
		$id = (int) $item['post_id'];
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return '' !== $item['primary_keyword'] ? esc_html( $item['primary_keyword'] ) : '<span aria-hidden="true">—</span>';
		}
		$input_id = 'ailsug-kw-' . $id;
		return sprintf(
			'<div class="ailsug-kw-edit" data-post-id="%1$d"><label class="screen-reader-text" for="%2$s">%3$s</label><input type="text" id="%2$s" class="ailsug-kw-input" value="%4$s" placeholder="%5$s" maxlength="120" /> <button type="button" class="button button-small ailsug-kw-save">%6$s</button> <span class="ailsug-kw-status" role="status" aria-live="polite"></span></div>',
			$id,
			esc_attr( $input_id ),
			/* translators: %s: page title. */
			esc_html( sprintf( __( 'Primary keyword for %s', 'linkcortex' ), $item['title'] ) ),
			esc_attr( $item['primary_keyword'] ),
			esc_attr__( 'Not set yet', 'linkcortex' ),
			esc_html__( 'Save', 'linkcortex' )
		);
	}

	/**
	 * Source badge.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_source( $item ) {
		return self::source_badge( (string) $item['keyword_source'] );
	}

	/**
	 * Badge markup for a keyword source (text, not color only).
	 *
	 * @param string $source Source.
	 * @return string
	 */
	public static function source_badge( $source ) {
		$labels = array(
			'manual'     => __( 'You', 'linkcortex' ),
			'seo_plugin' => __( 'SEO plugin', 'linkcortex' ),
			'ai'         => __( 'AI', 'linkcortex' ),
		);
		if ( ! isset( $labels[ $source ] ) ) {
			return '<span class="ailsug-badge is-none">' . esc_html__( 'Not set', 'linkcortex' ) . '</span>';
		}
		return '<span class="ailsug-badge is-' . esc_attr( str_replace( '_', '-', $source ) ) . '">' . esc_html( $labels[ $source ] ) . '</span>';
	}

	/**
	 * Inbound column (orphans flagged in text).
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_inbound_count( $item ) {
		$n = (int) $item['inbound_count'];
		return 0 === $n ? '<span class="ailsug-badge is-orphan">' . esc_html__( '0 · orphan', 'linkcortex' ) . '</span>' : esc_html( number_format_i18n( $n ) );
	}

	/**
	 * Default column.
	 *
	 * @param array  $item        Row.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return esc_html( number_format_i18n( (int) ( $item[ $column_name ] ?? 0 ) ) );
	}
}
