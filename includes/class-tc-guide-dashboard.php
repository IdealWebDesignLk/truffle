<?php
/**
 * [tc_guide_dashboard] shortcode - the guide's own calendar page.
 *
 * Place this shortcode on a page (e.g. /guide-dashboard/) and give guides
 * that URL plus their login. No wp-admin access needed or granted.
 *
 * @package TC_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TC_Guide_Dashboard {

	public static function init() {
		add_shortcode( 'tc_guide_dashboard', array( __CLASS__, 'render' ) );
	}

	public static function render() {
		if ( ! is_user_logged_in() ) {
			return self::login_prompt();
		}
		if ( ! current_user_can( 'tc_manage_own_availability' ) ) {
			return '<p>' . esc_html__( 'This page is only available to guides.', 'tc-booking' ) . '</p>';
		}

		wp_enqueue_style( 'tc-guide-dashboard', TC_BOOKING_URL . 'public/css/booking-app.css', array(), TC_BOOKING_VERSION );
		wp_enqueue_script( 'tc-guide-dashboard', TC_BOOKING_URL . 'public/js/guide-dashboard.js', array(), TC_BOOKING_VERSION, true );
		wp_localize_script(
			'tc-guide-dashboard',
			'tcGuideDashboard',
			array(
				'restRoot' => esc_url_raw( rest_url( 'tc/v1' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
			)
		);

		return '<div id="tc-guide-dashboard-root">' . esc_html__( 'Loading your calendar\u2026', 'tc-booking' ) . '</div>';
	}

	private static function login_prompt() {
		ob_start();
		wp_login_form( array( 'redirect' => get_permalink() ) );
		return '<p>' . esc_html__( 'Please log in to manage your calendar.', 'tc-booking' ) . '</p>' . ob_get_clean();
	}
}
