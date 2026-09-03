<?php
/**
 * WPML integration helpers.
 *
 * All four post types this plugin registers are non-translatable
 * (wpml-config.xml) - a single canonical post per location/service/
 * guide/booking, never duplicated per language. An earlier design had
 * Service/Guide fully "Translatable" (WPML creates a separate post - a
 * separate ID - per language); that broke a Guide's own-dashboard
 * availability calendar from staying in sync across languages, since the
 * calendar is keyed by post ID and a translated post gets its own ID. A
 * follow-up attempt used a "Display as Translated" WPML mode that turned
 * out not to exist as an actual selectable option in this site's WPML
 * setup (its Post Types Translation screen only offers two flavors of
 * full duplication, or fully non-translatable - no middle ground). See
 * PROJECT_NOTES.md's "WPML support" section for the full history.
 *
 * With every post type non-translatable, per-language TEXT (a guide's
 * name/bio, a service's name/description/extras) is instead handled via
 * WPML's *String Translation* module - translate_string() below - which
 * translates a specific string independently of any post, with no
 * duplication involved. to_default_language_id() is kept as a defensive
 * no-op given nothing here duplicates posts per language, but costs
 * nothing to leave in place.
 *
 * Every method here is a guarded no-op on a site without WPML, so this
 * class is always safe to call.
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

	/**
	 * Registers $value with WPML's String Translation module under
	 * ($context, $name) - so it shows up under WPML -> String Translation
	 * for someone to provide a per-language alternative - and returns its
	 * value in whatever language maybe_switch_language() last set (or the
	 * site's current language), falling back to $value itself if no
	 * translation has been provided yet or WPML isn't active. $name should
	 * be stable for a given piece of content (e.g. including the post ID)
	 * so re-registering it on every request updates the same string
	 * instead of creating a new one each time the underlying value changes.
	 *
	 * This is how Guide/Service text gets translated now that none of
	 * this plugin's post types duplicate per language (see this file's
	 * top comment) - registered fresh on every read rather than once,
	 * since WPML needs the current default-language value to detect when
	 * it's changed (e.g. an admin editing a guide's bio) and prompt for
	 * re-translation.
	 */
	public static function translate_string( $context, $name, $value ) {
		if ( ! self::is_active() || ! is_string( $value ) || '' === $value ) {
			return $value;
		}
		// The registration hook is wpml_register_single_string, taking
		// (context, name, value) in that order - NOT wpml_register_string.
		// Getting this wrong (wrong hook name/argument order) previously
		// caused WPML to receive a guide's full bio text where it expected
		// a short context slug, and reject it with a REST 500 "The string
		// did not match the expected pattern." - caught on the live site.
		do_action( 'wpml_register_single_string', $context, $name, $value );
		return apply_filters( 'wpml_translate_single_string', $value, $context, $name );
	}
}
