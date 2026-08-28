<?php
/**
 * WPML integration helpers.
 *
 * Locations, Services, and Guides are registered translatable via
 * wpml-config.xml (Bookings itself stays untranslatable - it's a
 * transactional record, not content). That alone lets WPML duplicate those
 * CPTs per language, but Guide<->Location/Service assignments
 * (_tc_location_ids / _tc_service_ids on the Guide post, see
 * class-tc-meta-boxes.php) are stored by post ID, and a translated post gets
 * its own ID - so a raw ID comparison would silently fail to match once a
 * site has more than one language. Every method here is a guarded no-op on
 * a site without WPML, so this class is always safe to call.
 *
 * @package TC_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TC_WPML {

	public static function is_active() {
		return defined( 'ICL_SITEPRESS_VERSION' );
	}

	/**
	 * Resolves a post ID (in whatever language it happens to be) to the
	 * same translation group's ID in the site's default language. Used to
	 * normalize both the write side (save_guide() converts whichever
	 * language's location/service checkboxes were shown into default
	 * -language IDs before storing) and the read side
	 * (TC_Availability::get_guides_for() normalizes the incoming
	 * location_id/service_id the same way before matching) - as long as
	 * both sides normalize to the same target, matching works regardless
	 * of which language the admin was in, or which language the customer
	 * is browsing in.
	 */
	public static function to_default_language_id( $post_id, $post_type ) {
		$post_id = (int) $post_id;
		if ( ! $post_id || ! self::is_active() ) {
			return $post_id;
		}
		$default_lang = apply_filters( 'wpml_default_language', null );
		$mapped       = apply_filters( 'wpml_object_id', $post_id, $post_type, true, $default_lang );
		return $mapped ? (int) $mapped : $post_id;
	}

	/**
	 * The customer's current front-end language, localized into
	 * window.tcBooking.lang (see class-tc-booking-shortcode.php) so the
	 * booking widget can pass it back on its catalog requests - a REST
	 * request doesn't necessarily carry the same language context a normal
	 * page load would (depends on WPML's URL format), so this is passed
	 * explicitly rather than assumed.
	 */
	public static function current_language() {
		if ( ! self::is_active() ) {
			return '';
		}
		$lang = apply_filters( 'wpml_current_language', null );
		return is_string( $lang ) ? $lang : '';
	}

	/**
	 * Switches WPML's active language for the rest of the request, given a
	 * lang param a REST route received back from the front-end. No-op if
	 * WPML isn't active or no language was given.
	 */
	public static function maybe_switch_language( $lang ) {
		if ( ! $lang || ! self::is_active() ) {
			return;
		}
		do_action( 'wpml_switch_language', sanitize_text_field( $lang ) );
	}
}
