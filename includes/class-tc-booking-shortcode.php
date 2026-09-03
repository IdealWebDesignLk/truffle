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
				// WPML support - passed back on catalog requests
				// (booking-app.js) so tc/v1 routes know which language to
				// query in, since a REST request doesn't necessarily carry
				// the same language context a normal page load would.
				// Empty string (falsy) when WPML isn't active.
				'lang'     => TC_WPML::current_language(),
				// WPML support - every static UI string booking-app.js
				// renders (headings, buttons, labels, validation messages)
				// lives here instead of hardcoded in the JS, so WPML's
				// automatic "Strings in theme and plugins" scanner (which
				// only sees PHP __()/_e() calls, never JS) can find and
				// translate each one under WPML -> String Translation, the
				// same as any other plugin-provided text. Templates keep
				// their %s/%d placeholder(s) as literal text - JS fills
				// them in at render time (see i18nFmt() in booking-app.js).
				// Day/month names and number formatting are handled
				// separately via the browser's own Intl APIs (see
				// jsLocale() in booking-app.js), not through this list -
				// that's both less translation work and more correct.
				'i18n'     => array(
					// General / navigation
					'back'                    => __( 'Back', 'tc-booking' ),
					'continue'                => __( 'Continue', 'tc-booking' ),
					'continueToCheckout'      => __( 'Continue to checkout', 'tc-booking' ),
					'processing'              => __( 'Processing…', 'tc-booking' ),
					'close'                   => __( 'Close', 'tc-booking' ),
					'loadingServices'         => __( 'Loading services…', 'tc-booking' ),
					'loadingAvailability'     => __( 'Loading availability…', 'tc-booking' ),
					'somethingWrong'          => __( 'Something went wrong. Please try again.', 'tc-booking' ),
					'couldNotLoadForm'        => __( 'Could not load the booking form:', 'tc-booking' ),
					'goBackPickDate'          => __( 'Please go back and pick a date.', 'tc-booking' ),

					// Validation messages
					'firstNameRequired'       => __( 'First name is required.', 'tc-booking' ),
					'lastNameRequired'        => __( 'Last name is required.', 'tc-booking' ),
					'emailInvalid'            => __( 'Enter a valid email address.', 'tc-booking' ),
					'phoneInvalid'            => __( 'Enter a valid phone number.', 'tc-booking' ),
					'nameRequired'            => __( 'Name is required.', 'tc-booking' ),
					'fixFieldsPlural'         => __( 'Please fix the highlighted fields before continuing.', 'tc-booking' ),
					'fixFieldSingular'        => __( 'Please fix the highlighted field before continuing.', 'tc-booking' ),

					// Location step
					'pickLocation'            => __( 'Pick a location', 'tc-booking' ),
					'pickLocationSub'         => __( 'This determines which guide and calendar you’ll see next.', 'tc-booking' ),
					'mapCaption'              => __( 'Tap a pin, or pick from the list', 'tc-booking' ),
					'mapAriaLabel'            => __( 'Map of the Netherlands with ceremony locations', 'tc-booking' ),
					'yourGuide'               => __( 'Your guide', 'tc-booking' ),
					'yourGuideAtLocation'     => __( 'Your guide at this location', 'tc-booking' ),
					'readMore'                => __( 'Read more', 'tc-booking' ),

					// Service step
					/* translators: %s: location name */
					'locationLabel'           => __( 'Location: %s', 'tc-booking' ),
					'chooseServiceSub'        => __( 'Choose a service. Dates will appear below.', 'tc-booking' ),
					'chooseServiceLabel'      => __( 'Choose a ceremony', 'tc-booking' ),
					'noServicesAvailable'     => __( 'No ceremonies are available at this location right now.', 'tc-booking' ),
					/* translators: %s: service/ceremony name */
					'availableDatesFor'       => __( 'Available dates for %s', 'tc-booking' ),
					'selectOpenDay'           => __( 'Select an open day to continue.', 'tc-booking' ),
					'dayUnit'                 => __( 'day', 'tc-booking' ),
					'daysUnit'                => __( 'days', 'tc-booking' ),

					// Availability calendar
					'statusOpen'              => __( 'Open', 'tc-booking' ),
					'statusAlmostFull'        => __( 'Almost full', 'tc-booking' ),
					'statusClosed'            => __( 'Closed', 'tc-booking' ),
					/* translators: %d: number of seats remaining */
					'leftSuffix'              => __( '%d left', 'tc-booking' ),
					'legendAvailable'         => __( 'Available', 'tc-booking' ),
					'legendNotAvailable'      => __( 'Not available', 'tc-booking' ),

					// Party (group size) step
					'howManyPeople'           => __( 'How many people are you bringing?', 'tc-booking' ),
					// Note: %d/%s here are positional, not sprintf's %1$d/%2$s -
					// i18nFmt() in booking-app.js just fills placeholders in
					// the order they appear, so don't reorder these two.
					/* translators: %d: max people, %s: "person" or "people" */
					'includesYouUpTo'         => __( 'Includes you — up to %d %s total for this ceremony', 'tc-booking' ),
					'personUnit'              => __( 'person', 'tc-booking' ),
					'peopleUnit'              => __( 'people', 'tc-booking' ),
					'limitedAvailabilityNote' => __( '(limited availability on this date)', 'tc-booking' ),
					'basePriceChargedPerPerson' => __( 'The base price is charged per person.', 'tc-booking' ),
					'totalInGroup'            => __( 'Total in your group', 'tc-booking' ),
					/* translators: %s: formatted price */
					'perPersonSuffix'         => __( '%s per person', 'tc-booking' ),

					// Guests step
					'yourGroupDetails'        => __( 'Your group’s details', 'tc-booking' ),
					'yourGroupDetailsSub'     => __( 'We need contact details for everyone joining you, so we can reach them if needed.', 'tc-booking' ),
					/* translators: %d: guest number */
					'guestHeading'            => __( 'Guest %d', 'tc-booking' ),
					'fullName'                => __( 'Full name', 'tc-booking' ),
					'guestNamePlaceholder'    => __( 'Guest name', 'tc-booking' ),
					'email'                   => __( 'Email', 'tc-booking' ),
					'phoneOptional'           => __( 'Phone (optional)', 'tc-booking' ),

					// Extras step
					'extrasQuantity'          => __( 'Extras & quantity', 'tc-booking' ),
					'noExtras'                => __( 'No extras for this ceremony.', 'tc-booking' ),
					'eachUnit'                => __( 'each', 'tc-booking' ),
					'maxUnit'                 => __( 'max', 'tc-booking' ),
					/* translators: %s: extra's label */
					'moreInfoAbout'           => __( 'More info about %s', 'tc-booking' ),

					// Details step
					'yourDetails'             => __( 'Your details', 'tc-booking' ),
					'firstName'               => __( 'First name', 'tc-booking' ),
					'lastName'                => __( 'Last name', 'tc-booking' ),
					'phone'                   => __( 'Phone', 'tc-booking' ),

					// Review step
					'reviewBooking'           => __( 'Review your booking', 'tc-booking' ),
					'location'                => __( 'Location', 'tc-booking' ),
					'guide'                   => __( 'Guide', 'tc-booking' ),
					'ceremony'                => __( 'Ceremony', 'tc-booking' ),
					'date'                    => __( 'Date', 'tc-booking' ),
					'bookedBy'                => __( 'Booked by', 'tc-booking' ),
					'groupSize'               => __( 'Group size', 'tc-booking' ),
					'basePrice'               => __( 'Base price', 'tc-booking' ),
					/* translators: %d: number of people */
					'basePriceTimesPeople'    => __( 'Base price × %d people', 'tc-booking' ),
					'total'                   => __( 'Total', 'tc-booking' ),
				),
			)
		);

		// Skeleton placeholder (not plain "Loading…" text) so there's no
		// layout shift when booking-app.js replaces this with the real
		// first step - see GitHub issue #13.
		return '<div id="tc-booking-root">' .
			'<div class="tc-progress"><div class="seg done"></div><div class="seg"></div><div class="seg"></div><div class="seg"></div><div class="seg"></div></div>' .
			'<div class="tc-card tc-skeleton" aria-busy="true" aria-label="' . esc_attr__( 'Loading booking form…', 'tc-booking' ) . '">' .
			'<div class="tc-skel-line tc-skel-title"></div>' .
			'<div class="tc-skel-line tc-skel-sub"></div>' .
			'<div class="tc-skel-block"></div>' .
			'</div></div>';
	}
}
