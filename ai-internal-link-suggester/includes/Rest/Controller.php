<?php
/**
 * Shared REST helpers.
 *
 * @package AILSUG
 */

namespace AILSUG\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Base class: namespace and permission helpers.
 */
abstract class Controller {

	const NS = 'linkcortex/v1';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	abstract public function register_routes();

	/**
	 * Admins only (scans, settings, AI runs).
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Who may start and drive runs (AI spend). Filterable, default administrators.
	 *
	 * @return bool
	 */
	public function can_run() {
		/**
		 * Filters the capability needed to start scans and other AI runs.
		 *
		 * @param string $capability Capability.
		 */
		return current_user_can( (string) apply_filters( 'ailsug_run_capability', 'manage_options' ) );
	}

	/**
	 * Editors and above (site-wide views).
	 *
	 * @return bool
	 */
	public function can_edit_others() {
		return current_user_can( 'edit_others_posts' );
	}

	/**
	 * Register all controllers.
	 *
	 * @return void
	 */
	public static function register_all() {
		/**
		 * Filters the REST controller classes.
		 *
		 * @param string[] $classes Class names.
		 */
		$classes = apply_filters( 'ailsug_rest_controllers', array( Runs_Controller::class, Keywords_Controller::class, Estimate_Controller::class, Suggestions_Controller::class ) );
		foreach ( $classes as $class ) {
			if ( class_exists( $class ) ) {
				( new $class() )->register_routes();
			}
		}
	}
}
