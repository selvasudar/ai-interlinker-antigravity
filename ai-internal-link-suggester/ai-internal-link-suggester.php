<?php
/**
 * Plugin Name:       AI Internal Link Suggester
 * Description:       Finds each page's main keyword and suggests internal links you can review, apply and undo in bulk, with clear AI cost estimates and a monthly budget cap.
 * Version:           0.1.0
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Author:            AI Internal Link Suggester contributors
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-internal-link-suggester
 * Domain Path:       /languages
 *
 * @package AILSUG
 */

defined( 'ABSPATH' ) || exit;

define( 'AILSUG_VERSION', '0.1.0' );
define( 'AILSUG_DB_VERSION', '3' );
define( 'AILSUG_FILE', __FILE__ );
define( 'AILSUG_DIR', plugin_dir_path( __FILE__ ) );
define( 'AILSUG_URL', plugin_dir_url( __FILE__ ) );
define( 'AILSUG_MIN_WP', '7.0' );
define( 'AILSUG_MIN_PHP', '7.4' );

spl_autoload_register(
	static function ( $class_name ) {
		if ( 0 !== strpos( $class_name, 'AILSUG\\' ) ) {
			return;
		}
		$relative = str_replace( '\\', '/', substr( $class_name, strlen( 'AILSUG\\' ) ) );
		$file     = AILSUG_DIR . 'includes/' . $relative . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'AILSUG\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AILSUG\\Activator', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'AILSUG\\Plugin', 'boot' ) );
