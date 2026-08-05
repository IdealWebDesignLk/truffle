<?php
/**
 * [tc_booking_widget] shortcode - the customer-facing booking flow.
 *
 * Renders a mount point and enqueues the front-end app (public/js/booking-app.js),
 * which talks exclusively through the tc/v1 REST routes. This is the flow
 * already designed and approved: location (+ map) -> availability grid ->
 * time & guide -> extras -> details -> review -> checkout -> confirmation.
 *
 * @package TC_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TC_Booking_Shortcode {

	public static function init() {
		add_shortcode( 'tc_booking_widget', array( __CLASS__, 'render' ) );
	}

	public static function render( $atts ) {
		wp_enqueue_style( 'tc-booking-app', TC_BOOKING_URL . 'public/css/booking-app.css', array(), TC_BOOKING_VERSION );
		wp_enqueue_script( 'tc-booking-app', TC_BOOKING_URL . 'public/js/booking-app.js', array(), TC_BOOKING_VERSION, true );
		wp_localize_script(
			'tc-booking-app',
			'tcBooking',
			array(
				'restRoot' => esc_url_raw( rest_url( 'tc/v1' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
			)
		);

		// Skeleton placeholder (not plain "Loading…" text) so there's no
		// layout shift when booking-app.js replaces this with the real
		// first step - see GitHub issue #13.
		return '<div id="tc-booking-root">' .
			'<div class="tc-progress"><div class="seg done"></div><div class="seg"></div><div class="seg"></div><div class="seg"></div><div class="seg"></div></div>' .
			'<div class="tc-card tc-skeleton" aria-busy="true" aria-label="' . esc_attr__( 'Loading booking form…', 'tc-booking' ) . '">' .
			'<div class="tc-skel-line tc-skel-eyebrow"></div>' .
			'<div class="tc-skel-line tc-skel-title"></div>' .
			'<div class="tc-skel-line tc-skel-sub"></div>' .
			'<div class="tc-skel-block"></div>' .
			'</div></div>';
	}
}
