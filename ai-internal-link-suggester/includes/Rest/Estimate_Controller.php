<?php
/**
 * REST: cost estimates (calculator and pre-run). Never calls AI.
 *
 * @package AILSUG
 */

namespace AILSUG\Rest;

use AILSUG\Cost\Estimator;
use AILSUG\Queue\Runs;
use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Estimates use the local pipeline only (dry run): zero AI calls.
 */
class Estimate_Controller extends Controller {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/estimate/calculator',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'calculator' ),
				'permission_callback' => array( $this, 'can_run' ),
				'args'                => array(
					'posts'         => array(
						'type'    => 'integer',
						'minimum' => 0,
						'maximum' => 1000000,
						'default' => 0,
					),
					'words'         => array(
						'type'    => 'integer',
						'minimum' => 0,
						'maximum' => 100000,
						'default' => 800,
					),
					'candidates'    => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 50,
						'default' => 15,
					),
					'suggestions'   => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 20,
						'default' => 5,
					),
					'new_per_month' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'maximum' => 100000,
						'default' => 0,
					),
					'keyword_share' => array(
						'type'    => 'number',
						'minimum' => 0,
						'maximum' => 1,
						'default' => 1,
					),
					'tier'          => array(
						'type'    => 'string',
						'enum'    => array( 'economy', 'balanced', 'best' ),
						'default' => 'economy',
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/estimate/run',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'run' ),
				'permission_callback' => array( $this, 'can_run' ),
				'args'                => array(
					'type'       => array(
						'type'     => 'string',
						'enum'     => array_keys( Runs::paid_types() ),
						'required' => true,
					),
					'post_types' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'post_ids'   => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
				),
			)
		);
	}

	/**
	 * Calculator.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function calculator( WP_REST_Request $request ) {
		return rest_ensure_response(
			Estimator::calculate(
				array(
					'posts'         => (int) $request['posts'],
					'words'         => (int) $request['words'],
					'candidates'    => (int) $request['candidates'],
					'suggestions'   => (int) $request['suggestions'],
					'new_per_month' => (int) $request['new_per_month'],
					'keyword_share' => (float) $request['keyword_share'],
					'tier'          => (string) $request['tier'],
				)
			)
		);
	}

	/**
	 * Pre-run estimate.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function run( WP_REST_Request $request ) {
		$post_types = $request['post_types'] ? array_map( 'sanitize_key', (array) $request['post_types'] ) : null;
		$post_ids   = $request['post_ids'] ? array_map( 'absint', (array) $request['post_ids'] ) : null;
		return rest_ensure_response( Runs::estimate( (string) $request['type'], $post_types, $post_ids ) );
	}
}
