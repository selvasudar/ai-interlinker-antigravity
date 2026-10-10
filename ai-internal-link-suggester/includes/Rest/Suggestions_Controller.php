<?php
/**
 * REST: suggestions (list for a post, edit, preview, apply, revert).
 *
 * @package AILSUG
 */

namespace AILSUG\Rest;

use AILSUG\AI\Availability;
use AILSUG\Index\Eligibility;
use AILSUG\Index\Indexer;
use AILSUG\Index\LinkGraph;
use AILSUG\Suggest\Applier;
use AILSUG\Suggest\Reverter;
use AILSUG\Suggest\Store;
use AILSUG\Suggest\TextMap;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Every route checks edit_post on the source page of each suggestion it touches.
 */
class Suggestions_Controller extends Controller {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$ids = array(
			'type'     => 'array',
			'items'    => array( 'type' => 'integer' ),
			'required' => true,
			'maxItems' => 500,
		);
		register_rest_route(
			self::NS,
			'/posts/(?P<post_id>\d+)/links',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'for_post' ),
				'permission_callback' => array( $this, 'can_edit_post_param' ),
			)
		);
		register_rest_route(
			self::NS,
			'/suggestions/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update' ),
				'permission_callback' => array( $this, 'can_edit_suggestion' ),
				'args'                => array(
					'status'      => array(
						'type' => 'string',
						'enum' => array( 'pending', 'accepted', 'rejected' ),
					),
					'anchor_text' => array(
						'type'      => 'string',
						'maxLength' => 100,
					),
					'target'      => array( 'type' => 'string' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/suggestions/(?P<id>\d+)/preview',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'preview' ),
				'permission_callback' => array( $this, 'can_edit_suggestion' ),
			)
		);
		register_rest_route(
			self::NS,
			'/suggestions/apply-preview',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'apply_preview' ),
				'permission_callback' => array( $this, 'can_edit_posts' ),
				'args'                => array( 'ids' => $ids ),
			)
		);
		register_rest_route(
			self::NS,
			'/suggestions/apply',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'apply' ),
				'permission_callback' => array( $this, 'can_edit_posts' ),
				'args'                => array(
					'ids'       => $ids,
					'confirmed' => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/applied/revert',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'revert' ),
				'permission_callback' => array( $this, 'can_edit_posts' ),
				'args'                => array(
					'ids'      => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
					'batch_id' => array(
						'type'    => 'string',
						'pattern' => '^[a-f0-9-]{36}$',
					),
				),
			)
		);
	}

	/**
	 * Any user who can edit posts (per-item checks happen inside).
	 *
	 * @return bool
	 */
	public function can_edit_posts() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Edit permission on the post in the route.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function can_edit_post_param( WP_REST_Request $request ) {
		return current_user_can( 'edit_post', (int) $request['post_id'] );
	}

	/**
	 * Edit permission on the suggestion's source page.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function can_edit_suggestion( WP_REST_Request $request ) {
		$s = Store::get( (int) $request['id'] );
		return $s && current_user_can( 'edit_post', (int) $s['source_post_id'] );
	}

	/**
	 * Public shape of a suggestion.
	 *
	 * @param array $s Row.
	 * @return array
	 */
	public static function format( array $s ) {
		$target = Indexer::get_row( (int) $s['target_post_id'] );
		return array(
			'id'          => (int) $s['id'],
			'source_id'   => (int) $s['source_post_id'],
			'target_id'   => (int) $s['target_post_id'],
			'target'      => array(
				'title'   => $target ? (string) $target['title'] : get_the_title( (int) $s['target_post_id'] ),
				'url'     => (string) get_permalink( (int) $s['target_post_id'] ),
				'keyword' => $target ? (string) $target['primary_keyword'] : '',
				'inbound' => $target ? (int) $target['inbound_count'] : 0,
			),
			'anchor_text' => (string) $s['anchor_text'],
			'context'     => Store::split_snippet( (string) $s['context_snippet'] ),
			'reason'      => (string) $s['reason'],
			'score'       => (int) $s['score'],
			'status'      => (string) $s['status'],
		);
	}

	/**
	 * Everything the editor panel needs for one post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function for_post( WP_REST_Request $request ) {
		global $wpdb;
		$post_id = (int) $request['post_id'];
		$t       = \AILSUG\Activator::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . esc_sql( $t['suggestions'] ) . " WHERE source_post_id = %d AND status IN ('pending','accepted') ORDER BY score DESC", $post_id ), ARRAY_A );
		$row  = Indexer::get_row( $post_id );
		return rest_ensure_response(
			array(
				'indexed'     => (bool) $row,
				'keyword'     => $row ? Keywords_Controller::format( $row ) : null,
				'suggestions' => array_map( array( __CLASS__, 'format' ), (array) $rows ),
				'can_run'     => current_user_can( (string) apply_filters( 'ailsug_run_capability', 'manage_options' ) ),
				'ai'          => Availability::is_available(),
				'view_only'   => Eligibility::is_page_builder( $post_id ),
				'bulk'        => Applier::bulk_enabled(),
			)
		);
	}

	/**
	 * Accept/reject, or edit anchor/target (re-validated against the source text).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function update( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		$s  = Store::get( $id );
		if ( in_array( $s['status'], array( 'applied' ), true ) ) {
			return new WP_Error( 'ailsug_applied', __( 'This link is already in the page. Undo it first to change it.', 'ai-internal-link-suggester' ), array( 'status' => 409 ) );
		}
		$fields = array();

		if ( null !== $request['target'] && '' !== (string) $request['target'] ) {
			$raw    = trim( (string) $request['target'] );
			$target = ctype_digit( $raw ) ? (int) $raw : url_to_postid( esc_url_raw( $raw ) );
			if ( ! $target || $target === (int) $s['source_post_id'] || ! Eligibility::is_linkable_target( $target ) ) {
				return new WP_Error( 'ailsug_bad_target', __( 'That target page cannot be linked. Enter the ID or address of a published page on this site.', 'ai-internal-link-suggester' ), array( 'status' => 400 ) );
			}
			if ( LinkGraph::links_to( (int) $s['source_post_id'], $target ) ) {
				return new WP_Error( 'ailsug_already_linked', __( 'The page already links to that target.', 'ai-internal-link-suggester' ), array( 'status' => 409 ) );
			}
			$fields['target_post_id'] = $target;
		}

		if ( null !== $request['anchor_text'] && (string) $request['anchor_text'] !== $s['anchor_text'] ) {
			$anchor = trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) $request['anchor_text'] ) ) );
			$post   = get_post( (int) $s['source_post_id'] );
			$map    = new TextMap( $post ? $post->post_content : '' );
			$occ    = $map->find( $anchor, (string) $s['chunk_id'] );
			if ( ! $occ ) {
				$occ = $map->find( $anchor );
			}
			if ( '' === $anchor || ! $occ ) {
				return new WP_Error( 'ailsug_anchor_missing', __( 'That text is not in the page (or it is already a link, heading or code). Use words exactly as they appear in a paragraph.', 'ai-internal-link-suggester' ), array( 'status' => 400 ) );
			}
			$ctx                       = $map->context( $occ[0] );
			$fields['anchor_text']     = $occ[0]['text'];
			$fields['chunk_id']        = $occ[0]['chunk'];
			$fields['context_snippet'] = $ctx['before'] . "\u{2063}" . $ctx['match'] . "\u{2063}" . $ctx['after'];
		}

		if ( null !== $request['status'] ) {
			$fields['status'] = (string) $request['status'];
		} elseif ( $fields && 'stale' === $s['status'] ) {
			$fields['status'] = 'pending';
		}

		if ( $fields ) {
			Store::update( $id, $fields );
		}
		return rest_ensure_response( self::format( Store::get( $id ) ) );
	}

	/**
	 * Source paragraph with the anchor highlighted (from the current content).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function preview( WP_REST_Request $request ) {
		$s    = Store::get( (int) $request['id'] );
		$post = get_post( (int) $s['source_post_id'] );
		$map  = new TextMap( $post ? $post->post_content : '' );
		$occ  = $map->find( (string) $s['anchor_text'], (string) $s['chunk_id'] );
		$ctx  = $occ ? $map->context( $occ[0], 400 ) : Store::split_snippet( (string) $s['context_snippet'] );
		return rest_ensure_response(
			array(
				'found'   => (bool) $occ,
				'context' => $ctx,
				'target'  => self::format( $s )['target'],
			)
		);
	}

	/**
	 * Count pages and links an apply would change.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function apply_preview( WP_REST_Request $request ) {
		return rest_ensure_response( Applier::preview( array_map( 'intval', (array) $request['ids'] ) ) );
	}

	/**
	 * Apply (always an explicit, confirmed action).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function apply( WP_REST_Request $request ) {
		$ids = array_map( 'intval', (array) $request['ids'] );
		if ( ! $request['confirmed'] ) {
			return new WP_Error( 'ailsug_confirm', __( 'Please confirm before changing pages.', 'ai-internal-link-suggester' ), array( 'status' => 400 ) );
		}
		if ( count( $ids ) > 1 && ! Applier::bulk_enabled() ) {
			return new WP_Error( 'ailsug_bulk_disabled', __( 'Applying many links at once is turned off on this site. Apply them one at a time.', 'ai-internal-link-suggester' ), array( 'status' => 403 ) );
		}
		return rest_ensure_response( Applier::apply( $ids ) );
	}

	/**
	 * Revert links (per link or per batch).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function revert( WP_REST_Request $request ) {
		$records = Reverter::records( array_map( 'intval', (array) $request['ids'] ), $request['batch_id'] ? (string) $request['batch_id'] : null );
		if ( ! $records ) {
			return new WP_Error( 'ailsug_nothing', __( 'Nothing to undo.', 'ai-internal-link-suggester' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( array( 'results' => Reverter::revert( $records ) ) );
	}
}
