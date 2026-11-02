<?php
/**
 * Plugin Name:       Voozin
 * Plugin URI:        https://voozin.com/integrations/wordpress
 * Description:       Send all WordPress email through the Voozin API with a delivery log, and receive and answer inbound email in wp-admin.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Voozin
 * Author URI:        https://voozin.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       voozin
 * Domain Path:       /languages
 *
 * @package Voozin
 */

defined( 'ABSPATH' ) || exit;

define( 'VOOZIN_VERSION', '0.1.0' );
define( 'VOOZIN_FILE', __FILE__ );
define( 'VOOZIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VOOZIN_URL', plugin_dir_url( __FILE__ ) );

require_once VOOZIN_DIR . 'includes/class-voozin-settings.php';
require_once VOOZIN_DIR . 'includes/class-voozin-api.php';
require_once VOOZIN_DIR . 'includes/class-voozin-log.php';
require_once VOOZIN_DIR . 'includes/class-voozin-mailer.php';
require_once VOOZIN_DIR . 'includes/class-voozin-webhook.php';
require_once VOOZIN_DIR . 'includes/class-voozin-inbox.php';
require_once VOOZIN_DIR . 'includes/class-voozin-admin.php';

register_activation_hook( __FILE__, array( 'Voozin_Log', 'install' ) );
register_deactivation_hook( __FILE__, array( 'Voozin_Log', 'unschedule' ) );

add_action(
	'plugins_loaded',
	static function () {
		Voozin_Log::init();
		Voozin_Mailer::init();
		Voozin_Webhook::init();
		Voozin_Inbox::init();
		if ( is_admin() ) {
			Voozin_Admin::init();
		}
	}
);

add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'voozin', false, dirname( plugin_basename( VOOZIN_FILE ) ) . '/languages' );
	}
);
