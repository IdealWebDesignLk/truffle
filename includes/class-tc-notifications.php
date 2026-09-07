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

		// Admin + guide copies are built and sent FIRST, before switching
		// language below - both should stay in whatever language is
		// already active for this request (normally Dutch, this site's
		// WPML default), not follow the customer. Building them after the
		// switch was a real bug in an earlier version of this method: the
		// switch has no "undo," so anything composed after it - including
		// these internal copies - rendered in the CUSTOMER's language
		// instead, contradicting the intent documented here.
		$admin_subject = sprintf( __( 'Nieuwe boeking: %s op %s', 'tc-booking' ), $b['service_name'], $b['date'] );
		$admin_body    = sprintf(
			__( "Nieuwe boeking bevestigd.\n\nKlant: %1\$s %2\$s (%3\$s, %4\$s)\nCeremonie: %5\$s\nLocatie: %6\$s\nGids: %7\$s\nDatum: %8\$s\nTotaal: \u{20AC}%9\$s", 'tc-booking' ),
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

		if ( $b['guide_email'] ) {
			$guide_subject = sprintf( __( 'Nieuwe boeking toegewezen: %s op %s', 'tc-booking' ), $b['service_name'], $b['date'] );
			$guide_body    = sprintf(
				__( "Hoi %1\$s,\n\nEr is een nieuwe boeking aan je toegewezen.\n\nCeremonie: %2\$s\nLocatie: %3\$s\nDatum: %4\$s%5\$s\nKlant: %6\$s (%7\$s)%8\$s", 'tc-booking' ),
				$b['guide_name'],
				$b['service_name'],
				$b['location_name'],
				$b['date'],
				$b['start_time'] ? ' ' . sprintf( __( 'om %s', 'tc-booking' ), $b['start_time'] ) : '',
				trim( $b['first_name'] . ' ' . $b['last_name'] ),
				$b['phone'],
				$b['party_size'] > 1 ? "\n" . sprintf( __( 'Groepsgrootte: %d', 'tc-booking' ), $b['party_size'] ) : ''
			);
			wp_mail( $b['guide_email'], $guide_subject, $guide_body );
		}

		// WPML support - this fires from a WooCommerce order-status-changed
		// hook (TC_Woocommerce::sync_booking_from_order()), a separate
		// request from the one the customer booked in, with no language
		// context of its own - so the language captured at booking time
		// (create_booking() in class-tc-rest-api.php) is switched to
		// explicitly here, only for the customer email below, rather than
		// relying on whatever WPML's "current language" happens to default
		// to for this request (which for an async payment webhook could be
		// anything, or nothing).
		TC_WPML::maybe_switch_language( $b['lang'] );

		$subject = sprintf( __( 'Boeking bevestigd: %s op %s', 'tc-booking' ), $b['service_name'], $b['date'] );
		$body    = sprintf(
			__( "Hoi %1\$s,\n\nJe boeking is bevestigd.\n\nCeremonie: %2\$s\nLocatie: %3\$s\nGids: %4\$s\nDatum: %5\$s%6\$s\n\nWe kijken ernaar uit je te zien.", 'tc-booking' ),
			$b['first_name'],
			$b['service_name'],
			$b['location_name'],
			$b['guide_name'],
			$b['date'],
			$b['start_time'] ? ' ' . sprintf( __( 'om %s', 'tc-booking' ), $b['start_time'] ) : ''
		);
		wp_mail( $b['email'], $subject, $body );
	}

	public static function send_cancellation( $booking_id ) {
		$b = self::booking_context( $booking_id );
		if ( ! $b ) {
			return;
		}

		// Guide copy first, same reasoning as send_confirmation() above -
		// stays in whatever language is already active, doesn't follow the
		// customer.
		if ( $b['guide_email'] ) {
			$guide_subject = sprintf( __( 'Boeking geannuleerd: %s op %s', 'tc-booking' ), $b['service_name'], $b['date'] );
			$guide_body    = sprintf(
				__( "Hoi %1\$s,\n\nDe volgende boeking is geannuleerd en staat niet langer in je agenda.\n\nCeremonie: %2\$s\nLocatie: %3\$s\nDatum: %4\$s", 'tc-booking' ),
				$b['guide_name'],
				$b['service_name'],
				$b['location_name'],
				$b['date']
			);
			wp_mail( $b['guide_email'], $guide_subject, $guide_body );
		}

		// WPML support - see the comment in send_confirmation() above; same
		// reasoning (admin/staff-triggered from wp-admin, no customer
		// language context of its own).
		TC_WPML::maybe_switch_language( $b['lang'] );

		$subject = sprintf( __( 'Boeking geannuleerd: %s op %s', 'tc-booking' ), $b['service_name'], $b['date'] );
		$body    = sprintf(
			__( "Hoi %1\$s,\n\nJe boeking voor %2\$s op %3\$s is geannuleerd. Als dit onverwacht is, neem dan contact met ons op.", 'tc-booking' ),
			$b['first_name'],
			$b['service_name'],
			$b['date']
		);
		wp_mail( $b['email'], $subject, $body );
		wp_mail( self::admin_email(), sprintf( __( 'Boeking #%d geannuleerd', 'tc-booking' ), $booking_id ), $body );
	}

	public static function send_reschedule( $booking_id ) {
		$b = self::booking_context( $booking_id );
		if ( ! $b ) {
			return;
		}

		// Guide copy first, same reasoning as send_confirmation() above.
		if ( $b['guide_email'] ) {
			$guide_subject = sprintf( __( 'Boeking verzet: %s', 'tc-booking' ), $b['service_name'] );
			$guide_body    = sprintf(
				__( "Hoi %1\$s,\n\nEen boeking in je agenda is verzet naar een nieuwe datum.\n\nCeremonie: %2\$s\nLocatie: %3\$s\nNieuwe datum: %4\$s", 'tc-booking' ),
				$b['guide_name'],
				$b['service_name'],
				$b['location_name'],
				$b['date']
			);
			wp_mail( $b['guide_email'], $guide_subject, $guide_body );
		}

		// WPML support - see the comment in send_confirmation() above.
		TC_WPML::maybe_switch_language( $b['lang'] );

		$subject = sprintf( __( 'Boeking verzet: %s', 'tc-booking' ), $b['service_name'] );
		$body    = sprintf(
			__( "Hoi %1\$s,\n\nJe boeking voor %2\$s is verplaatst naar een nieuwe datum: %3\$s met %4\$s.", 'tc-booking' ),
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

	private static function guide_email( $guide_id ) {
		if ( ! $guide_id ) {
			return '';
		}
		$user_id = (int) get_post_meta( $guide_id, '_tc_user_id', true );
		if ( ! $user_id ) {
			return '';
		}
		$user = get_userdata( $user_id );
		return $user ? $user->user_email : '';
	}

	/**
	 * Public since GitHub issue #69 - TC_Woocommerce also uses this to
	 * render the booking summary on the WooCommerce pay-for-order page,
	 * rather than duplicating this same meta-reading logic there.
	 */
	public static function booking_context( $booking_id ) {
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
			'party_size'    => max( 1, (int) get_post_meta( $booking_id, '_tc_party_size', true ) ),
			// WPML support - captured at booking time (create_booking() in
			// class-tc-rest-api.php); empty string on a WPML-inactive site,
			// which TC_WPML::maybe_switch_language() already no-ops on.
			'lang'          => get_post_meta( $booking_id, '_tc_customer_lang', true ),
			// The guide's own email - the WP user account linked via
			// _tc_user_id on the Guide post (class-tc-meta-boxes.php's
			// render_guide()), same account they log into the guide
			// dashboard with. '' if no guide is assigned, or that guide
			// isn't linked to a user account yet.
			'guide_email'   => self::guide_email( (int) get_post_meta( $booking_id, '_tc_guide_id', true ) ),
		);
	}
}
