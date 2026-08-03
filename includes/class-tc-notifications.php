<?php
/**
 * Email notifications. Deliberately plain wp_mail() - no SMS, per final scope.
 *
 * @package TC_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TC_Notifications {

	public static function init() {
		// No hooks of its own - called directly from TC_Woocommerce / TC_Rest_Api
		// at the exact moments those classes already know a status changed,
		// rather than re-deriving "what changed" from a generic save hook.
	}

	public static function send_confirmation( $booking_id ) {
		$b = self::booking_context( $booking_id );
		if ( ! $b ) {
			return;
		}

		$subject = sprintf( __( 'Booking confirmed: %s on %s', 'tc-booking' ), $b['service_name'], $b['date'] );
		$body    = sprintf(
			__( "Hi %1\$s,\n\nYour booking is confirmed.\n\nCeremony: %2\$s\nLocation: %3\$s\nGuide: %4\$s\nDate: %5\$s%6\$s\n\nWe look forward to seeing you.", 'tc-booking' ),
			$b['first_name'],
			$b['service_name'],
			$b['location_name'],
			$b['guide_name'],
			$b['date'],
			$b['start_time'] ? ' ' . sprintf( __( 'at %s', 'tc-booking' ), $b['start_time'] ) : ''
		);
		wp_mail( $b['email'], $subject, $body );

		$admin_subject = sprintf( __( 'New booking: %s on %s', 'tc-booking' ), $b['service_name'], $b['date'] );
		$admin_body    = sprintf(
			__( "New booking confirmed.\n\nCustomer: %1\$s %2\$s (%3\$s, %4\$s)\nCeremony: %5\$s\nLocation: %6\$s\nGuide: %7\$s\nDate: %8\$s\nTotal: \u{20AC}%9\$s", 'tc-booking' ),
			$b['first_name'],
			$b['last_name'],
			$b['email'],
			$b['phone'],
			$b['service_name'],
			$b['location_name'],
			$b['guide_name'],
			$b['date'],
			$b['total']
		);
		wp_mail( self::admin_email(), $admin_subject, $admin_body );
	}

	public static function send_cancellation( $booking_id ) {
		$b = self::booking_context( $booking_id );
		if ( ! $b ) {
			return;
		}
		$subject = sprintf( __( 'Booking cancelled: %s on %s', 'tc-booking' ), $b['service_name'], $b['date'] );
		$body    = sprintf(
			__( "Hi %1\$s,\n\nYour booking for %2\$s on %3\$s has been cancelled. If this wasn't expected, please get in touch.", 'tc-booking' ),
			$b['first_name'],
			$b['service_name'],
			$b['date']
		);
		wp_mail( $b['email'], $subject, $body );
		wp_mail( self::admin_email(), sprintf( __( 'Booking #%d cancelled', 'tc-booking' ), $booking_id ), $body );
	}

	public static function send_reschedule( $booking_id ) {
		$b = self::booking_context( $booking_id );
		if ( ! $b ) {
			return;
		}
		$subject = sprintf( __( 'Booking rescheduled: %s', 'tc-booking' ), $b['service_name'] );
		$body    = sprintf(
			__( "Hi %1\$s,\n\nYour booking for %2\$s has been moved to a new date: %3\$s with %4\$s.", 'tc-booking' ),
			$b['first_name'],
			$b['service_name'],
			$b['date'],
			$b['guide_name']
		);
		wp_mail( $b['email'], $subject, $body );
	}

	private static function admin_email() {
		return apply_filters( 'tc_booking_admin_email', get_option( 'admin_email' ) );
	}

	private static function booking_context( $booking_id ) {
		$post = get_post( $booking_id );
		if ( ! $post ) {
			return null;
		}
		$service_id  = (int) get_post_meta( $booking_id, '_tc_service_id', true );
		$service     = TC_Availability::get_service_data( $service_id );
		return array(
			'first_name'    => get_post_meta( $booking_id, '_tc_customer_first_name', true ),
			'last_name'     => get_post_meta( $booking_id, '_tc_customer_last_name', true ),
			'email'         => get_post_meta( $booking_id, '_tc_customer_email', true ),
			'phone'         => get_post_meta( $booking_id, '_tc_customer_phone', true ),
			'service_name'  => get_the_title( $service_id ),
			'start_time'    => $service['start_time'] ?? '',
			'location_name' => get_the_title( (int) get_post_meta( $booking_id, '_tc_location_id', true ) ),
			'guide_name'    => get_the_title( (int) get_post_meta( $booking_id, '_tc_guide_id', true ) ),
			'date'          => get_post_meta( $booking_id, '_tc_date', true ),
			'total'         => get_post_meta( $booking_id, '_tc_total', true ),
		);
	}
}
