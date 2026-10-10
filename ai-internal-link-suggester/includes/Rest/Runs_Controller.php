<?php
/**
 * REST: runs (create, progress, process one batch, pause/resume/cancel).
 *
 * @package AILSUG
 */

namespace AILSUG\Rest;

use AILSUG\Queue\Queue;
use AILSUG\Queue\Runs;
use AILSUG\Queue\Worker;
use AILSUG\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * "Run now" works without cron: the admin page calls /process repeatedly.
 */
class Runs_Controller extends Controller {

	const RUN_ID = '(?P<run_id>[a-f0-9-]{36})';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/runs',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'type'       => array(
						'type'     => 'string',
						'enum'     => self::types(),
						'required' => true,
					),
					'post_types' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/runs/' . self::RUN_ID,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'show' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			self::NS,
			'/runs/' . self::RUN_ID . '/process',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'process' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			self::NS,
			'/runs/' . self::RUN_ID . '/(?P<action>pause|resume|cancel)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'control' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * Allowed run types.
	 *
	 * @return string[]
	 */
	public static function types() {
		/**
		 * Filters the run types that can be started over REST.
		 *
		 * @param string[] $types Types.
		 */
		return (array) apply_filters( 'ailsug_run_types', array( 'index' ) );
	}

	/**
	 * Create a run.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function create( WP_REST_Request $request ) {
		$type       = (string) $request['type'];
		$post_types = $request['post_types'] ? array_map( 'sanitize_key', (array) $request['post_types'] ) : null;

		if ( 'index' === $type ) {
			$run_id = Runs::start_index( $post_types );
		} else {
			/**
			 * Starts a run of a custom type. Return a run ID or WP_Error.
			 *
			 * @param string|WP_Error|null $run_id  Null if unhandled.
			 * @param string               $type    Run type.
			 * @param WP_REST_Request      $request Request.
			 */
			$run_id = apply_filters( 'ailsug_start_run', null, $type, $request );
			if ( null === $run_id ) {
				return new WP_Error( 'ailsug_bad_type', __( 'Unknown run type.', 'ai-internal-link-suggester' ), array( 'status' => 400 ) );
			}
			if ( is_wp_error( $run_id ) ) {
				return $run_id;
			}
		}
		return rest_ensure_response( Queue::progress( $run_id ) );
	}

	/**
	 * Progress.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function show( WP_REST_Request $request ) {
		$progress = Queue::progress( (string) $request['run_id'] );
		if ( ! $progress ) {
			return new WP_Error( 'ailsug_not_found', __( 'That run no longer exists.', 'ai-internal-link-suggester' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $progress );
	}

	/**
	 * Process one small batch of this run.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function process( WP_REST_Request $request ) {
		$run_id = (string) $request['run_id'];
		$run    = Queue::get_run( $run_id );
		if ( ! $run ) {
			return new WP_Error( 'ailsug_not_found', __( 'That run no longer exists.', 'ai-internal-link-suggester' ), array( 'status' => 404 ) );
		}
		if ( 'running' === $run['status'] ) {
			Worker::process( (int) Settings::get( 'batch_size', 5 ), $run_id, 15 );
		}
		return rest_ensure_response( Queue::progress( $run_id ) );
	}

	/**
	 * Pause, resume or cancel.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function control( WP_REST_Request $request ) {
		$run_id = (string) $request['run_id'];
		if ( ! Queue::get_run( $run_id ) ) {
			return new WP_Error( 'ailsug_not_found', __( 'That run no longer exists.', 'ai-internal-link-suggester' ), array( 'status' => 404 ) );
		}
		$map = array(
			'pause'  => 'paused',
			'resume' => 'running',
			'cancel' => 'cancelled',
		);
		Queue::set_run_status( $run_id, $map[ (string) $request['action'] ] );
		if ( 'resume' === $request['action'] ) {
			Worker::schedule();
		}
		return rest_ensure_response( Queue::progress( $run_id ) );
	}
}
