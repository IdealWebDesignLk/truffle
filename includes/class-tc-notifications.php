<?php
/**
 * Email notifications. No SMS, per final scope. Sent as HTML (see
 * send_html_mail()/email_shell() near the bottom) - table-based layout with
 * inline styles only, no <style> block or external assets, since those are
 * the two things most likely to get stripped or mangled by a real-world
 * mail client. Colors are hardcoded to match this plugin's own brand
 * palette (public/css/booking-app.css's --brand-deep etc.) rather than
 * reading it at runtime - email HTML can't use CSS custom properties
 * reliably across clients anyway, so there was nothing to gain by trying
 * to share the source of truth, and copying the literal values keeps this
 * file readable without needing that other file open alongside it.
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
		$extras = self::extras_rows( $b['extras'] );

		$admin_subject = sprintf( __( 'Nieuwe boeking: %s op %s', 'tc-booking' ), $b['service_name'], self::format_date( $b['date'] ) );
		$admin_rows    = array_merge(
			array(
				array( __( 'Klant', 'tc-booking' ), trim( $b['first_name'] . ' ' . $b['last_name'] ) ),
				array( __( 'E-mail', 'tc-booking' ), $b['email'] ),
				array( __( 'Telefoon', 'tc-booking' ), $b['phone'] ),
				array( __( 'Ceremonie', 'tc-booking' ), $b['service_name'] ),
				array( __( 'Locatie', 'tc-booking' ), $b['location_name'] ),
				array( __( 'Gids', 'tc-booking' ), $b['guide_name'] ),
				array( __( 'Datum', 'tc-booking' ), self::format_date( $b['date'], $b['start_time'] ) ),
			),
			$extras,
			array( array( __( 'Totaal', 'tc-booking' ), self::format_price( $b['total'] ) ) )
		);
		$admin_body    = self::email_shell( __( 'Nieuwe boeking bevestigd', 'tc-booking' ), self::email_rows( $admin_rows ) );
		self::send_html_mail( self::admin_email(), $admin_subject, $admin_body );

		if ( $b['guide_email'] ) {
			$guide_subject = sprintf( __( 'Nieuwe boeking toegewezen: %s op %s', 'tc-booking' ), $b['service_name'], self::format_date( $b['date'] ) );
			$guide_rows    = array_merge(
				array(
					array( __( 'Ceremonie', 'tc-booking' ), $b['service_name'] ),
					array( __( 'Locatie', 'tc-booking' ), $b['location_name'] ),
					array( __( 'Datum', 'tc-booking' ), self::format_date( $b['date'], $b['start_time'] ) ),
					array( __( 'Klant', 'tc-booking' ), trim( $b['first_name'] . ' ' . $b['last_name'] ) ),
					array( __( 'Telefoon', 'tc-booking' ), $b['phone'] ),
					array( __( 'Groepsgrootte', 'tc-booking' ), $b['party_size'] > 1 ? $b['party_size'] : '' ),
				),
				$extras
			);
			$guide_body    = self::email_shell(
				__( 'Nieuwe boeking toegewezen', 'tc-booking' ),
				self::email_p( sprintf( __( 'Hoi %s,', 'tc-booking' ), $b['guide_name'] ) ) .
				self::email_p( __( 'Er is een nieuwe boeking aan je toegewezen.', 'tc-booking' ) ) .
				self::email_rows( $guide_rows )
			);
			self::send_html_mail( $b['guide_email'], $guide_subject, $guide_body );
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

		$subject = sprintf( __( 'Boeking bevestigd: %s op %s', 'tc-booking' ), $b['service_name'], self::format_date( $b['date'] ) );
		$body    = self::email_shell(
			__( 'Je boeking is bevestigd', 'tc-booking' ),
			self::email_p( sprintf( __( 'Hoi %s,', 'tc-booking' ), $b['first_name'] ) ) .
			self::email_p( __( 'Je boeking is bevestigd. Hieronder vind je de details.', 'tc-booking' ) ) .
			self::email_rows(
				array(
					array( __( 'Ceremonie', 'tc-booking' ), $b['service_name'] ),
					array( __( 'Locatie', 'tc-booking' ), $b['location_name'] ),
					array( __( 'Gids', 'tc-booking' ), $b['guide_name'] ),
					array( __( 'Datum', 'tc-booking' ), self::format_date( $b['date'], $b['start_time'] ) ),
					array( __( 'Extras', 'tc-booking' ), $extras ),
					array( __( 'Totaal', 'tc-booking' ), self::format_price( $b['total'] ) ),
				)
			) .
			self::email_p( __( 'We kijken ernaar uit je te zien.', 'tc-booking' ) )
		);
		self::send_html_mail( $b['email'], $subject, $body );
	}

	public static function send_cancellation( $booking_id ) {
		$b      = self::booking_context( $booking_id );
		if ( ! $b ) {
			return;
		}
		$extras = self::extras_rows( $b['extras'] );

		// Guide copy first, same reasoning as send_confirmation() above -
		// stays in whatever language is already active, doesn't follow the
		// customer.
		if ( $b['guide_email'] ) {
			$guide_subject = sprintf( __( 'Boeking geannuleerd: %s op %s', 'tc-booking' ), $b['service_name'], self::format_date( $b['date'] ) );
			$guide_rows    = array_merge(
				array(
					array( __( 'Ceremonie', 'tc-booking' ), $b['service_name'] ),
					array( __( 'Locatie', 'tc-booking' ), $b['location_name'] ),
					array( __( 'Datum', 'tc-booking' ), self::format_date( $b['date'] ) ),
				),
				$extras
			);
			$guide_body    = self::email_shell(
				__( 'Boeking geannuleerd', 'tc-booking' ),
				self::email_p( sprintf( __( 'Hoi %s,', 'tc-booking' ), $b['guide_name'] ) ) .
				self::email_p( __( 'De volgende boeking is geannuleerd en staat niet langer in je agenda.', 'tc-booking' ) ) .
				self::email_rows( $guide_rows )
			);
			self::send_html_mail( $b['guide_email'], $guide_subject, $guide_body );
		}

		// WPML support - see the comment in send_confirmation() above; same
		// reasoning (admin/staff-triggered from wp-admin, no customer
		// language context of its own).
		TC_WPML::maybe_switch_language( $b['lang'] );

		$subject = sprintf( __( 'Boeking geannuleerd: %s op %s', 'tc-booking' ), $b['service_name'], self::format_date( $b['date'] ) );
		$rows    = array_merge(
			array(
				array( __( 'Ceremonie', 'tc-booking' ), $b['service_name'] ),
				array( __( 'Datum', 'tc-booking' ), self::format_date( $b['date'] ) ),
			),
			$extras
		);
		$body    = self::email_shell(
			__( 'Je boeking is geannuleerd', 'tc-booking' ),
			self::email_p( sprintf( __( 'Hoi %s,', 'tc-booking' ), $b['first_name'] ) ) .
			self::email_p( __( 'Je boeking is geannuleerd. Als dit onverwacht is, neem dan contact met ons op.', 'tc-booking' ) ) .
			self::email_rows( $rows )
		);
		// Same body sent to both the customer and admin, exactly as before
		// this email was restyled - not worth two near-identical templates
		// just to swap the greeting for a recipient who already knows the
		// booking's details are addressed to the customer.
		self::send_html_mail( $b['email'], $subject, $body );
		self::send_html_mail( self::admin_email(), sprintf( __( 'Boeking #%d geannuleerd', 'tc-booking' ), $booking_id ), $body );
	}

	public static function send_reschedule( $booking_id ) {
		$b      = self::booking_context( $booking_id );
		if ( ! $b ) {
			return;
		}
		$extras = self::extras_rows( $b['extras'] );

		// Guide copy first, same reasoning as send_confirmation() above.
		if ( $b['guide_email'] ) {
			$guide_subject = sprintf( __( 'Boeking verzet: %s', 'tc-booking' ), $b['service_name'] );
			$guide_rows    = array_merge(
				array(
					array( __( 'Ceremonie', 'tc-booking' ), $b['service_name'] ),
					array( __( 'Locatie', 'tc-booking' ), $b['location_name'] ),
					array( __( 'Nieuwe datum', 'tc-booking' ), self::format_date( $b['date'] ) ),
				),
				$extras
			);
			$guide_body    = self::email_shell(
				__( 'Boeking verzet', 'tc-booking' ),
				self::email_p( sprintf( __( 'Hoi %s,', 'tc-booking' ), $b['guide_name'] ) ) .
				self::email_p( __( 'Een boeking in je agenda is verzet naar een nieuwe datum.', 'tc-booking' ) ) .
				self::email_rows( $guide_rows )
			);
			self::send_html_mail( $b['guide_email'], $guide_subject, $guide_body );
		}

		// WPML support - see the comment in send_confirmation() above.
		TC_WPML::maybe_switch_language( $b['lang'] );

		$subject = sprintf( __( 'Boeking verzet: %s', 'tc-booking' ), $b['service_name'] );
		$rows    = array_merge(
			array(
				array( __( 'Ceremonie', 'tc-booking' ), $b['service_name'] ),
				array( __( 'Nieuwe datum', 'tc-booking' ), self::format_date( $b['date'] ) ),
				array( __( 'Gids', 'tc-booking' ), $b['guide_name'] ),
			),
			$extras
		);
		$body    = self::email_shell(
			__( 'Je boeking is verzet', 'tc-booking' ),
			self::email_p( sprintf( __( 'Hoi %s,', 'tc-booking' ), $b['first_name'] ) ) .
			self::email_p( __( 'Je boeking is verplaatst naar een nieuwe datum.', 'tc-booking' ) ) .
			self::email_rows( $rows )
		);
		self::send_html_mail( $b['email'], $subject, $body );
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
	 * rather than duplicating this same meta-reading logic there. Also
	 * used by TC_Woocommerce::add_booking_details_to_order_email() (GitHub
	 * follow-up request) to add the same details to WooCommerce's own
	 * "New order" email.
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
			'guests'        => get_post_meta( $booking_id, '_tc_guests', true ),
			'extras'        => get_post_meta( $booking_id, '_tc_selected_extras', true ),
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

	/* ------------------------------------------------------------------ */
	/* HTML email building                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * $date is a raw 'Y-m-d' from _tc_date; date_i18n() (not plain date())
	 * so month/weekday names follow the site's own language the same way
	 * the WooCommerce pay-page booking summary does
	 * (TC_Woocommerce::render_booking_summary()) - both run after
	 * whichever maybe_switch_language() call was relevant for that email,
	 * so this picks up the right language automatically.
	 */
	private static function format_date( $date, $start_time = '' ) {
		if ( ! $date ) {
			return '';
		}
		$formatted = date_i18n( get_option( 'date_format' ), strtotime( $date ) );
		if ( $start_time ) {
			/* translators: 1: formatted date, 2: start time */
			return sprintf( __( '%1$s om %2$s', 'tc-booking' ), $formatted, $start_time );
		}
		return $formatted;
	}

	private static function format_price( $amount ) {
		return '€' . number_format_i18n( (float) $amount, 2 );
	}

	/**
	 * One [label, value] pair per extra - so each ends up on its own row
	 * via email_rows(), rather than one comma-joined "Label ×qty, Label
	 * ×qty" row (extras used to be a single such string, which read as
	 * one big blob when a booking had more than one). Every label is just
	 * "Extra" - the value carries the actual name and quantity - so
	 * several extras stack as a natural, repeated-label list rather than
	 * needing a numbered heading per row.
	 *
	 * Public since TC_Woocommerce::add_booking_details_to_order_email()
	 * reuses this too, rather than a second copy of this exact formatting
	 * living in that file. Returns array() if there aren't any, which
	 * array_merge()s in as a no-op wherever this is spliced into a rows
	 * array.
	 */
	public static function extras_rows( $extras ) {
		if ( ! is_array( $extras ) || ! $extras ) {
			return array();
		}
		$rows = array();
		foreach ( $extras as $extra ) {
			if ( empty( $extra['qty'] ) ) {
				continue;
			}
			/* translators: 1: extra label, 2: quantity */
			$rows[] = array( __( 'Extra', 'tc-booking' ), sprintf( __( '%1$s ×%2$d', 'tc-booking' ), $extra['label'], $extra['qty'] ) );
		}
		return $rows;
	}

	/**
	 * Wraps wp_mail() with the wp_mail_content_type filter scoped to just
	 * this one call (added immediately before, removed immediately after)
	 * rather than switching it globally - other code on the site calling
	 * wp_mail() for something unrelated (WooCommerce, WordPress core,
	 * another plugin) must keep getting whatever content type it expects,
	 * not silently start receiving HTML because this class changed a
	 * global filter and forgot to change it back.
	 */
	private static function send_html_mail( $to, $subject, $html_body ) {
		$set_html = function () {
			return 'text/html';
		};
		add_filter( 'wp_mail_content_type', $set_html );
		wp_mail( $to, $subject, $html_body );
		remove_filter( 'wp_mail_content_type', $set_html );
	}

	/**
	 * Branded shell every email is wrapped in - table-based layout with
	 * inline styles only (no <style> block, no external stylesheet/image),
	 * since both are liable to be stripped or blocked in a real-world mail
	 * client. Colors match this plugin's own brand palette in
	 * public/css/booking-app.css (--brand-deep #4B2E7D, --ink #231F2E,
	 * --ink-soft #6E6A78, --line #E4E0EC, --surface-dim #F1EEF7) - kept as
	 * literal hex here since email HTML can't reliably use CSS custom
	 * properties across clients anyway.
	 */
	private static function email_shell( $heading, $body_html ) {
		$site_name = get_bloginfo( 'name' );
		// The <meta charset> here is what actually matters for rendering -
		// wp_mail()'s own Content-Type header already declares UTF-8 (via
		// its own wp_mail_charset filter, defaulting to the site's
		// configured charset - practically always UTF-8), but some mail
		// clients render from the HTML's own declared charset regardless
		// of the transport header, and get it wrong without one. Found via
		// a rendered-preview check: without this, "€" (a multi-byte UTF-8
		// sequence) showed up as mangled bytes ("â‚¬").
		return '<!doctype html><html><head><meta charset="utf-8"></head><body style="margin:0;padding:0;background:#F1EEF7;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Arial,sans-serif;">' .
			'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F1EEF7;padding:32px 16px;"><tr><td align="center">' .
			'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:680px;">' .
			'<tr><td style="background:#4B2E7D;border-radius:12px 12px 0 0;padding:20px 28px;">' .
			'<span style="color:#ffffff;font-size:18px;font-weight:600;">' . esc_html( $site_name ) . '</span>' .
			'</td></tr>' .
			'<tr><td style="background:#ffffff;border:1px solid #E4E0EC;border-top:none;border-radius:0 0 12px 12px;padding:28px;">' .
			'<h1 style="margin:0 0 18px;font-size:20px;line-height:1.3;color:#231F2E;">' . esc_html( $heading ) . '</h1>' .
			$body_html .
			'</td></tr>' .
			'<tr><td style="padding:16px 8px;text-align:center;color:#6E6A78;font-size:12px;">' . esc_html( $site_name ) . '</td></tr>' .
			'</table></td></tr></table></body></html>';
	}

	private static function email_p( $text ) {
		return '<p style="margin:0 0 16px;font-size:14.5px;line-height:1.6;color:#231F2E;">' . esc_html( $text ) . '</p>';
	}

	/**
	 * A label/value table - the booking-detail rows every email above
	 * builds with this. Pairs with an empty value are skipped (e.g. no
	 * guide assigned yet, or a solo booking's "Groepsgrootte") rather than
	 * showing a blank row.
	 *
	 * Public since it's also reused by TC_Woocommerce::
	 * add_booking_details_to_order_email() to add this same styled table to
	 * WooCommerce's own "New order" email, rather than a second, likely-to-
	 * drift copy of this markup living in that file too.
	 */
	public static function email_rows( $pairs ) {
		$html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px;">';
		foreach ( $pairs as $pair ) {
			list( $label, $value ) = $pair;
			if ( '' === $value || null === $value ) {
				continue;
			}
			$html .= '<tr>' .
				'<td style="padding:9px 0;border-bottom:1px solid #E4E0EC;font-size:13.5px;color:#6E6A78;">' . esc_html( $label ) . '</td>' .
				'<td style="padding:9px 0;border-bottom:1px solid #E4E0EC;font-size:13.5px;color:#231F2E;font-weight:600;text-align:right;">' . esc_html( $value ) . '</td>' .
				'</tr>';
		}
		$html .= '</table>';
		return $html;
	}
}
