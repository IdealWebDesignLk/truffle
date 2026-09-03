<?php
/**
 * Plugin Name:       TC Booking
 * Plugin URI:        https://truffelceremonie.com
 * Description:       Custom booking system for truffelceremonie.com. Replaces the Amelia-based booking widget with a fully custom stack - data model, availability engine, admin panel, front-end, and WooCommerce checkout - for locations, services, and guides.
 * Version:           0.24.1
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Ideal Web Design
 * Text Domain:       tc-booking
 * Domain Path:       /languages
 * WC requires at least: 8.0
 *
 * @package TC_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'TC_BOOKING_VERSION', '0.24.1' );
define( 'TC_BOOKING_FILE', __FILE__ );
define( 'TC_BOOKING_PATH', plugin_dir_path( __FILE__ ) );
define( 'TC_BOOKING_URL', plugin_dir_url( __FILE__ ) );
define( 'TC_BOOKING_DB_VERSION', '1'); // Bump when schema in class-tc-activator.php changes.

/**
 * Autoload plugin classes on demand.
 *
 * File naming convention: class-tc-{name}.php maps to class TC_{Name} (words split on '-').
 */
spl_autoload_register(
	function ( $class ) {
		if ( strpos( $class, 'TC_' ) !== 0 ) {
			return;
		}
		$slug = strtolower( str_replace( '_', '-', $class ) );
		$path = TC_BOOKING_PATH . 'includes/class-' . $slug . '.php';
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
);

/**
 * GitHub-based update checker so the plugin shows up in wp-admin -> Updates
 * whenever a new release is tagged on GitHub. See PROJECT_NOTES.md for the
 * release process.
 */
require_once TC_BOOKING_PATH . 'includes/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

PucFactory::buildUpdateChecker(
	'https://github.com/IdealWebDesignLk/truffle/',
	TC_BOOKING_FILE,
	'tc-booking'
)->setBranch( 'main' );

/**
 * The plugin header declares Text Domain: tc-booking, but that alone never
 * registers the domain with WordPress core - every __()/_e() call using it
 * was working (no fatal, no notice) purely because WPML overrides gettext
 * output directly rather than needing an actual loaded domain, but that
 * also meant WordPress/WPML had no formal record tying the "tc-booking"
 * domain to this plugin, which is very likely why WPML's String
 * Translation listed it as an "Unknown" domain instead of recognizing it.
 * Hooked on init (not plugins_loaded, where the rest of this file's
 * bootstrap runs) per current WordPress guidance - loading a text domain
 * any earlier triggers a _load_textdomain_just_in_time doing-it-wrong
 * notice on WP 6.7+.
 */
function tc_booking_load_textdomain() {
	load_plugin_textdomain( 'tc-booking', false, dirname( plugin_basename( TC_BOOKING_FILE ) ) . '/languages' );
}
add_action( 'init', 'tc_booking_load_textdomain' );

/**
 * Core bootstrap. Hooked on plugins_loaded so WooCommerce (if active) is available.
 */
function tc_booking_init() {
	// Fail loudly but gracefully in wp-admin if WooCommerce is missing - the whole
	// checkout flow depends on it, so there is no useful degraded mode.
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'tc_booking_missing_woocommerce_notice' );
		return;
	}

	TC_CPT::init();
	TC_Meta_Boxes::init();
	TC_Availability::init();
	TC_Rest_Api::init();
	TC_Woocommerce::init();
	TC_Guide_Dashboard::init();
	TC_Notifications::init();
	TC_Booking_Shortcode::init();
	TC_Admin_Bookings::init();
}
add_action( 'plugins_loaded', 'tc_booking_init' );

function tc_booking_missing_woocommerce_notice() {
	echo '<div class="notice notice-error"><p>' .
		esc_html__( 'TC Booking requires WooCommerce to be installed and active.', 'tc-booking' ) .
		'</p></div>';
}

/**
 * Activation: create custom tables and roles/capabilities.
 */
function tc_booking_activate() {
	require_once TC_BOOKING_PATH . 'includes/class-tc-activator.php';
	TC_Activator::activate();
}
register_activation_hook( __FILE__, 'tc_booking_activate' );

function tc_booking_deactivate() {
	require_once TC_BOOKING_PATH . 'includes/class-tc-activator.php';
	TC_Activator::deactivate();
}
register_deactivation_hook( __FILE__, 'tc_booking_deactivate' );
