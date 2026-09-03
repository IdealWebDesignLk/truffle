<?php
/**
 * WPML integration helpers.
 *
 * Location and Booking are non-translatable, Service and Guide are
 * "Display as Translated" (wpml-config.xml) - none of the four duplicate
 * a post per language (an earlier design had Service/Guide fully
 * "Translatable", which does duplicate per language; that broke a Guide's
 * own-dashboard availability calendar from staying in sync across
 * languages, since the calendar is keyed by post ID and a translated post
 * gets its own ID - see PROJECT_NOTES.md's "WPML support" section for the
 * full story). to_default_language_id() below is kept as a defensive
 * no-op for that reason: nothing in this plugin's data model duplicates
 * posts per language anymore, so there's no ID drift left to normalize,
 * but the normalization is harmless if that ever changes. Every method
 * here is a guarded no-op on a site without WPML, so this class is always
 * safe to call.
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
