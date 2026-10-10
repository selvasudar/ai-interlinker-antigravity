<?php
/**
 * Admin menu: "Internal Links".
 *
 * @package AILSUG
 */

namespace AILSUG\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the top-level menu and its screens, and enqueues admin assets.
 */
final class Menu {

	const SLUG = 'ailsug';

	/**
	 * Screen hook suffixes keyed by short name.
	 *
	 * @var array<string,string>
	 */
	private static $hooks = array();

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Screens: slug suffix => [title, capability, callback].
	 *
	 * @return array
	 */
	public static function screens() {
		$screens = array(
			''             => array( __( 'Dashboard', 'ai-internal-link-suggester' ), 'edit_posts', array( Dashboard::class, 'render' ) ),
			'-suggestions' => array( __( 'Suggestions', 'ai-internal-link-suggester' ), 'edit_posts', array( Suggestions_Page::class, 'render' ) ),
			'-keywords'    => array( __( 'Keywords', 'ai-internal-link-suggester' ), 'edit_others_posts', array( Keywords_Page::class, 'render' ) ),
			'-settings'    => array( __( 'Settings', 'ai-internal-link-suggester' ), 'manage_options', array( Settings_Page::class, 'render' ) ),
		);
		foreach ( $screens as $suffix => $screen ) {
			if ( ! is_callable( $screen[2] ) ) {
				unset( $screens[ $suffix ] );
			}
		}
		return $screens;
	}

	/**
	 * Build the menu.
	 *
	 * @return void
	 */
	public static function menu() {
		$screens = self::screens();
		$first   = reset( $screens );

		self::$hooks['dashboard'] = add_menu_page(
			__( 'Internal Links', 'ai-internal-link-suggester' ),
			__( 'Internal Links', 'ai-internal-link-suggester' ),
			'edit_posts',
			self::SLUG,
			$first[2],
			'dashicons-admin-links',
			58
		);

		foreach ( $screens as $suffix => $screen ) {
			$hook = add_submenu_page( self::SLUG, $screen[0], $screen[0], $screen[1], self::SLUG . $suffix, $screen[2] );
			if ( $hook ) {
				self::$hooks[ '' === $suffix ? 'dashboard' : ltrim( $suffix, '-' ) ] = $hook;
			}
		}
	}

	/**
	 * Whether the current screen belongs to this plugin.
	 *
	 * @param string $hook_suffix Hook suffix.
	 * @return bool
	 */
	public static function is_ours( $hook_suffix ) {
		return in_array( $hook_suffix, self::$hooks, true );
	}

	/**
	 * URL of one of our screens.
	 *
	 * @param string $screen dashboard|suggestions|keywords|settings.
	 * @param array  $args   Extra query args.
	 * @return string
	 */
	public static function url( $screen = 'dashboard', array $args = array() ) {
		$page = 'dashboard' === $screen ? self::SLUG : self::SLUG . '-' . $screen;
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Enqueue shared admin CSS/JS on our screens only.
	 *
	 * @param string $hook_suffix Hook suffix.
	 * @return void
	 */
	public static function assets( $hook_suffix ) {
		if ( ! self::is_ours( $hook_suffix ) ) {
			return;
		}
		wp_enqueue_style( 'ailsug-admin', AILSUG_URL . 'assets/css/admin.css', array(), AILSUG_VERSION );
		wp_style_add_data( 'ailsug-admin', 'rtl', 'replace' );
		wp_enqueue_script( 'ailsug-admin', AILSUG_URL . 'assets/js/admin.js', array( 'wp-i18n' ), AILSUG_VERSION, true );
		wp_set_script_translations( 'ailsug-admin', 'ai-internal-link-suggester', AILSUG_DIR . 'languages' );
		wp_localize_script(
			'ailsug-admin',
			'ailsugAdmin',
			array(
				'restUrl'       => esc_url_raw( rest_url( 'ai-internal-link-suggester/v1/' ) ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'screen'        => array_search( $hook_suffix, self::$hooks, true ),
				'currency2'     => (string) \AILSUG\Settings::get( 'currency2' ),
				'currency2Rate' => (float) \AILSUG\Settings::get( 'currency2_rate' ),
			)
		);
	}
}
