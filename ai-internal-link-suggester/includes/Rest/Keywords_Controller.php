<?php
/**
 * REST: edit a page's primary keyword.
 *
 * @package AILSUG
 */

namespace AILSUG\Rest;

use AILSUG\Index\Indexer;
use AILSUG\Index\KeywordResolver;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Inline keyword editing.
 */
class Keywords_Controller extends Controller {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/keywords/(?P<post_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'can_edit_post' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'can_edit_post' ),
					'args'                => array(
						'keyword' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * The user must be able to edit this specific post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function can_edit_post( WP_REST_Request $request ) {
		return current_user_can( 'edit_post', (int) $request['post_id'] );
	}

	/**
	 * Current keyword data.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function show( WP_REST_Request $request ) {
		$row = Indexer::get_row( (int) $request['post_id'] );
		if ( ! $row ) {
			return new WP_Error( 'ailsug_not_indexed', __( 'This page is not in the index yet.', 'linkcortex' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( self::format( $row ) );
	}

	/**
	 * Set (or clear) the manual keyword, then re-index locally.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function update( WP_REST_Request $request ) {
		$post_id = (int) $request['post_id'];
		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'ailsug_not_found', __( 'Page not found.', 'linkcortex' ), array( 'status' => 404 ) );
		}
		$before = Indexer::get_row( $post_id );
		KeywordResolver::set_manual( $post_id, (string) $request['keyword'] );
		Indexer::index_post( $post_id );
		$row = Indexer::get_row( $post_id );
		if ( ! $row ) {
			return new WP_Error( 'ailsug_not_indexed', __( 'This page is not part of the index (check Settings → General).', 'linkcortex' ), array( 'status' => 409 ) );
		}
		if ( ! $before || $before['primary_keyword'] !== $row['primary_keyword'] ) {
			// Suggestions built on the old keyword may no longer fit.
			Indexer::mark_stale_source( $post_id, '' );
			Indexer::mark_stale_target( $post_id );

			/**
			 * Fires when a page's primary keyword changed through the plugin.
			 *
			 * @param int   $post_id Post ID.
			 * @param array $row     New index row.
			 */
			do_action( 'ailsug_keyword_changed', $post_id, $row );
		}
		return rest_ensure_response( self::format( $row ) );
	}

	/**
	 * Public shape of an index row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function format( array $row ) {
		$secondary = json_decode( (string) $row['secondary_keywords'], true );
		return array(
			'post_id'            => (int) $row['post_id'],
			'primary_keyword'    => (string) $row['primary_keyword'],
			'keyword_source'     => (string) $row['keyword_source'],
			'secondary_keywords' => is_array( $secondary ) ? $secondary : array(),
			'inbound_count'      => (int) $row['inbound_count'],
			'outbound_count'     => (int) $row['outbound_count'],
			'word_count'         => (int) $row['word_count'],
		);
	}
}
