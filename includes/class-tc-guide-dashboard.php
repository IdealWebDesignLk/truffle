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

		// Skeleton placeholder instead of plain "Loading…" text, to avoid
		// layout shift when guide-dashboard.js replaces this - see GitHub
		// issue #13 (same fix as the booking widget shortcode).
		return '<div id="tc-guide-dashboard-root">' .
			'<div class="tc-card tc-skeleton" aria-busy="true" aria-label="' . esc_attr__( 'Loading your calendar…', 'tc-booking' ) . '">' .
			'<div class="tc-skel-line tc-skel-title"></div>' .
			'<div class="tc-skel-line tc-skel-sub"></div>' .
			'<div class="tc-skel-block"></div>' .
			'</div></div>';
	}

	private static function login_prompt() {
		ob_start();
		wp_login_form( array( 'redirect' => get_permalink() ) );
		return '<p>' . esc_html__( 'Please log in to manage your calendar.', 'tc-booking' ) . '</p>' . ob_get_clean();
	}
}
