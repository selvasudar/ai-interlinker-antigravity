<?php
/**
 * Plugin bootstrap: wires every component to WordPress hooks.
 *
 * @package AILSUG
 */

namespace AILSUG;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 */
final class Plugin {

	/**
	 * Boot on plugins_loaded.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( ! Requirements::met() ) {
			add_action( 'admin_notices', array( __CLASS__, 'requirements_notice' ) );
			return;
		}

		Hooks::register();
		Queue\Worker::register();
		add_action( 'rest_api_init', array( Rest\Controller::class, 'register_all' ) );

		if ( is_admin() ) {
			add_action( 'admin_init', array( Activator::class, 'maybe_upgrade' ) );
			Admin\Menu::register();
			Admin\Settings_Page::register();
			Admin\Keywords_Page::register();
			Admin\Cost_Panel::register();
		}
	}

	/**
	 * Admin notice when requirements are not met.
	 *
	 * @return void
	 */
	public static function requirements_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		foreach ( Requirements::problems() as $problem ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $problem ) );
		}
	}
}
