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
				//
				// WPML always stores whatever text is passed to __() as
				// the SITE'S DEFAULT LANGUAGE version - it has no notion of
				// "this particular string is written in English" separate
				// from that. This site's WPML default language is Dutch, so
				// these strings are written in Dutch (not English) for that
				// reason: with the site's default set to Dutch, an English
				// __() value would show as raw, untranslated English to
				// Dutch visitors - the site's main audience - unless
				// someone manually added a Dutch "translation" of it, which
				// is backwards. Writing the source string in Dutch means
				// Dutch visitors see correct text with zero String
				// Translation entries needed, and English/German are then
				// genuine translations added under WPML -> String
				// Translation, same as everywhere else in this file.
				'i18n'     => array(
					// General / navigation
					'back'                    => __( 'Terug', 'tc-booking' ),
					'continue'                => __( 'Verder', 'tc-booking' ),
					'continueToCheckout'      => __( 'Doorgaan naar afrekenen', 'tc-booking' ),
					'processing'              => __( 'Bezig…', 'tc-booking' ),
					'close'                   => __( 'Sluiten', 'tc-booking' ),
					'loadingServices'         => __( 'Diensten laden…', 'tc-booking' ),
					'loadingAvailability'     => __( 'Beschikbaarheid laden…', 'tc-booking' ),
					'somethingWrong'          => __( 'Er is iets misgegaan. Probeer het opnieuw.', 'tc-booking' ),
					'couldNotLoadForm'        => __( 'Het boekingsformulier kon niet worden geladen:', 'tc-booking' ),
					'goBackPickDate'          => __( 'Ga terug en kies een datum.', 'tc-booking' ),

					// Validation messages
					'firstNameRequired'       => __( 'Voornaam is verplicht.', 'tc-booking' ),
					'lastNameRequired'        => __( 'Achternaam is verplicht.', 'tc-booking' ),
					'emailInvalid'            => __( 'Voer een geldig e-mailadres in.', 'tc-booking' ),
					'phoneInvalid'            => __( 'Voer een geldig telefoonnummer in.', 'tc-booking' ),
					'nameRequired'            => __( 'Naam is verplicht.', 'tc-booking' ),
					'fixFieldsPlural'         => __( 'Corrigeer de gemarkeerde velden voordat je verdergaat.', 'tc-booking' ),
					'fixFieldSingular'        => __( 'Corrigeer het gemarkeerde veld voordat je verdergaat.', 'tc-booking' ),

					// Location step
					'pickLocation'            => __( 'Kies een locatie', 'tc-booking' ),
					'pickLocationSub'         => __( 'Dit bepaalt welke gids en kalender je hierna te zien krijgt.', 'tc-booking' ),
					'mapCaption'              => __( 'Tik op een pin, of kies uit de lijst', 'tc-booking' ),
					'mapAriaLabel'            => __( 'Kaart van Nederland met ceremonielocaties', 'tc-booking' ),
					'yourGuide'               => __( 'Jouw gids', 'tc-booking' ),
					'yourGuideAtLocation'     => __( 'Jouw gids op deze locatie', 'tc-booking' ),
					'readMore'                => __( 'Lees meer', 'tc-booking' ),

					// Service step
					/* translators: %s: location name */
					'locationLabel'           => __( 'Locatie: %s', 'tc-booking' ),
					'chooseServiceSub'        => __( 'Kies een dienst. Hieronder verschijnen de beschikbare data.', 'tc-booking' ),
					'chooseServiceLabel'      => __( 'Kies een ceremonie', 'tc-booking' ),
					'noServicesAvailable'     => __( 'Er zijn op dit moment geen ceremonies beschikbaar op deze locatie.', 'tc-booking' ),
					/* translators: %s: service/ceremony name */
					'availableDatesFor'       => __( 'Beschikbare data voor %s', 'tc-booking' ),
					'selectOpenDay'           => __( 'Selecteer een beschikbare dag om verder te gaan.', 'tc-booking' ),
					'dayUnit'                 => __( 'dag', 'tc-booking' ),
					'daysUnit'                => __( 'dagen', 'tc-booking' ),

					// Availability calendar
					'statusOpen'              => __( 'Open', 'tc-booking' ),
					'statusAlmostFull'        => __( 'Bijna vol', 'tc-booking' ),
					'statusClosed'            => __( 'Gesloten', 'tc-booking' ),
					/* translators: %d: number of seats remaining */
					'leftSuffix'              => __( 'Nog %d', 'tc-booking' ),
					'legendAvailable'         => __( 'Beschikbaar', 'tc-booking' ),
					'legendNotAvailable'      => __( 'Niet beschikbaar', 'tc-booking' ),

					// Party (group size) step
					'howManyPeople'           => __( 'Met hoeveel personen kom je?', 'tc-booking' ),
					// Note: %d/%s here are positional, not sprintf's %1$d/%2$s -
					// i18nFmt() in booking-app.js just fills placeholders in
					// the order they appear, so don't reorder these two.
					/* translators: %d: max people, %s: "persoon" or "personen" */
					'includesYouUpTo'         => __( 'Inclusief jezelf — tot %d %s in totaal voor deze ceremonie', 'tc-booking' ),
					'personUnit'              => __( 'persoon', 'tc-booking' ),
					'peopleUnit'              => __( 'personen', 'tc-booking' ),
					'limitedAvailabilityNote' => __( '(beperkte beschikbaarheid op deze datum)', 'tc-booking' ),
					'basePriceChargedPerPerson' => __( 'De basisprijs wordt per persoon berekend.', 'tc-booking' ),
					'totalInGroup'            => __( 'Totaal in je groep', 'tc-booking' ),
					/* translators: %s: formatted price */
					'perPersonSuffix'         => __( '%s per persoon', 'tc-booking' ),

					// Guests step
					'yourGroupDetails'        => __( 'Gegevens van je groep', 'tc-booking' ),
					'yourGroupDetailsSub'     => __( 'We hebben contactgegevens nodig van iedereen die met je meekomt, zodat we hen indien nodig kunnen bereiken.', 'tc-booking' ),
					/* translators: %d: guest number */
					'guestHeading'            => __( 'Gast %d', 'tc-booking' ),
					'fullName'                => __( 'Volledige naam', 'tc-booking' ),
					'guestNamePlaceholder'    => __( 'Naam van gast', 'tc-booking' ),
					'email'                   => __( 'E-mail', 'tc-booking' ),
					'phoneOptional'           => __( 'Telefoon (optioneel)', 'tc-booking' ),

					// Extras step
					'extrasQuantity'          => __( 'Extra\'s & aantal', 'tc-booking' ),
					'noExtras'                => __( 'Geen extra\'s voor deze ceremonie.', 'tc-booking' ),
					'eachUnit'                => __( 'per stuk', 'tc-booking' ),
					'maxUnit'                 => __( 'max', 'tc-booking' ),
					/* translators: %s: extra's label */
					'moreInfoAbout'           => __( 'Meer informatie over %s', 'tc-booking' ),

					// Details step
					'yourDetails'             => __( 'Jouw gegevens', 'tc-booking' ),
					'firstName'               => __( 'Voornaam', 'tc-booking' ),
					'lastName'                => __( 'Achternaam', 'tc-booking' ),
					'phone'                   => __( 'Telefoon', 'tc-booking' ),

					// Review step
					'reviewBooking'           => __( 'Controleer je boeking', 'tc-booking' ),
					'location'                => __( 'Locatie', 'tc-booking' ),
					'guide'                   => __( 'Gids', 'tc-booking' ),
					'ceremony'                => __( 'Ceremonie', 'tc-booking' ),
					'date'                    => __( 'Datum', 'tc-booking' ),
					'bookedBy'                => __( 'Geboekt door', 'tc-booking' ),
					'groupSize'               => __( 'Groepsgrootte', 'tc-booking' ),
					'basePrice'               => __( 'Basisprijs', 'tc-booking' ),
					/* translators: %d: number of people */
					'basePriceTimesPeople'    => __( 'Basisprijs × %d personen', 'tc-booking' ),
					'total'                   => __( 'Totaal', 'tc-booking' ),
				),
			)
		);

		// Skeleton placeholder (not plain "Loading…" text) so there's no
		// layout shift when booking-app.js replaces this with the real
		// first step - see GitHub issue #13.
		return '<div id="tc-booking-root">' .
			'<div class="tc-progress"><div class="seg done"></div><div class="seg"></div><div class="seg"></div><div class="seg"></div><div class="seg"></div></div>' .
			'<div class="tc-card tc-skeleton" aria-busy="true" aria-label="' . esc_attr__( 'Boekingsformulier laden…', 'tc-booking' ) . '">' .
			'<div class="tc-skel-line tc-skel-title"></div>' .
			'<div class="tc-skel-line tc-skel-sub"></div>' .
			'<div class="tc-skel-block"></div>' .
			'</div></div>';
	}
}
