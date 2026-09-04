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
		wp_enqueue_style( 'tc-guide-dashboard', TC_BOOKING_URL . 'public/css/booking-app.css', array(), TC_BOOKING_VERSION );

		if ( ! is_user_logged_in() ) {
			return self::login_prompt();
		}
		if ( ! current_user_can( 'tc_manage_own_availability' ) ) {
			return self::access_denied();
		}

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
			'<div class="tc-card tc-skeleton" aria-busy="true" aria-label="' . esc_attr__( 'Je kalender wordt geladen…', 'tc-booking' ) . '">' .
			'<div class="tc-skel-line tc-skel-title"></div>' .
			'<div class="tc-skel-line tc-skel-sub"></div>' .
			'<div class="tc-skel-block"></div>' .
			'</div></div>';
	}

	/**
	 * GitHub issue #67 - previously a bare wp_login_form() call with a
	 * single line of plain text above it. wp_login_form()'s own output
	 * (#loginform, #user_login, #user_pass, .login-remember, #wp-submit -
	 * WordPress core's own markup, not this plugin's) is restyled via the
	 * .tc-auth-card rules in booking-app.css rather than rebuilding the
	 * form from scratch, so core's own sanitization/nonce/redirect
	 * handling is untouched.
	 */
	private static function login_prompt() {
		ob_start();
		wp_login_form( array( 'redirect' => get_permalink() ) );
		$form = ob_get_clean();

		return '<div id="tc-guide-dashboard-root"><div class="tc-auth-wrap"><div class="tc-card tc-auth-card">' .
			'<h2 class="tc-title">' . esc_html__( 'Inloggen', 'tc-booking' ) . '</h2>' .
			'<p class="tc-sub">' . esc_html__( 'Log in om je beschikbaarheidskalender te beheren.', 'tc-booking' ) . '</p>' .
			$form .
			'</div></div></div>';
	}

	/**
	 * GitHub issue #68 - previously a single line of plain text
	 * ("This page is only available to guides.") with a large empty page
	 * around it. Suggested Dutch copy/actions from the issue used as-is.
	 */
	private static function access_denied() {
		$icon = '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" aria-hidden="true">' .
			'<rect x="5" y="11" width="14" height="10" rx="2" stroke="currentColor" stroke-width="1.6"></rect>' .
			'<path d="M8 11V7a4 4 0 0 1 8 0v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"></path>' .
			'<circle cx="12" cy="16" r="1.4" fill="currentColor"></circle></svg>';

		// GitHub issue #68 - "Contact opnemen" has no single obvious
		// destination in this plugin (no dedicated contact page concept),
		// so it's filterable; defaults to a mailto: link to the site's own
		// admin email, which always works without needing a specific page
		// configured.
		$contact_url = apply_filters( 'tc_booking_contact_url', 'mailto:' . get_option( 'admin_email' ) );
		$account_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' );

		return '<div id="tc-guide-dashboard-root"><div class="tc-auth-wrap"><div class="tc-card tc-auth-card">' .
			'<div class="tc-auth-icon">' . $icon . '</div>' .
			'<h2 class="tc-title">' . esc_html__( 'Geen toegang tot het Guide Dashboard', 'tc-booking' ) . '</h2>' .
			'<p class="tc-sub">' . esc_html__( 'Je account heeft momenteel geen Guide-toegang. Neem contact op met de beheerder als je denkt dat dit een fout is.', 'tc-booking' ) . '</p>' .
			'<div class="tc-auth-actions">' .
			'<a class="tc-btn primary" href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Terug naar Home', 'tc-booking' ) . '</a>' .
			'<a class="tc-btn ghost" href="' . esc_url( $account_url ) . '">' . esc_html__( 'Mijn Account', 'tc-booking' ) . '</a>' .
			'<a class="tc-btn ghost" href="' . esc_url( $contact_url ) . '">' . esc_html__( 'Contact opnemen', 'tc-booking' ) . '</a>' .
			'</div></div></div></div>';
	}
}
