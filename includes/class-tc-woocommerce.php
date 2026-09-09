<?php
/**
 * WooCommerce integration.
 *
 * Bookings were originally represented in WooCommerce as fee-based orders
 * (WC_Order_Item_Fee, no linked product) rather than orders containing real
 * WC_Product line items, specifically to avoid keeping a shadow WC_Product
 * in sync with every Service/Extra edited in the TC admin screens.
 *
 * That changed when a third-party invoicing plugin (Booster for
 * WooCommerce's PDF invoicing module) turned out to render a generic
 * "Appointment" placeholder for fee-only line items, because it has no
 * product to read a name/description from - see
 * get_or_create_placeholder_product(). Order items now reference a real
 * (catalog-hidden, zero-price) WC_Product per service/extra, so tools that
 * expect a normal product-backed order still see one. This doesn't bring
 * back the sync problem the original design avoided: the placeholder
 * product's own title is only ever a fallback label, not what our own
 * emails/invoices show - every line item still gets an explicit
 * set_name() override built from the booking's own price snapshot, which
 * remains the single source of truth for what's charged.
 *
 * @package TC_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TC_Woocommerce {

	public static function init() {
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'sync_booking_from_order' ), 10, 4 );
		// GitHub issue #69 - the booking's own details (location/guide/
		// ceremony/date/contact info) were only ever shown on the booking
		// widget's review step, not on the WooCommerce page the customer is
		// then redirected to for payment - see render_booking_summary().
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_pay_page_assets' ) );
		add_action( 'before_woocommerce_pay', array( __CLASS__, 'render_booking_summary' ) );
		// Follow-up request - the same booking details weren't in
		// WooCommerce's own "New order" admin email either, only the fee
		// line item's (fairly compressed) name/price - see
		// add_booking_details_to_order_email().
		add_action( 'woocommerce_email_order_details', array( __CLASS__, 'add_booking_details_to_order_email' ), 5, 4 );
	}

	/**
	 * booking-app.css is reused here rather than a new stylesheet, so the
	 * summary below (.tc-card/.tc-title/.tc-rline) looks like the same
	 * component as the review step, not a bolted-on block - see the
	 * #tc-checkout-summary-root rules at the top of that file.
	 */
	public static function enqueue_pay_page_assets() {
		if ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) {
			wp_enqueue_style( 'tc-booking-app', TC_BOOKING_URL . 'public/css/booking-app.css', array(), TC_BOOKING_VERSION );
		}
	}

	/**
	 * GitHub issue #69 - shows the same Location/Guide/Ceremony/Date/Booked
	 * by/Email/Phone/Total breakdown the customer already saw on the
	 * booking widget's review step, again on WooCommerce's "pay for order"
	 * page - the page create_booking() actually sends the customer to
	 * (get_checkout_payment_url()); this plugin never uses the standard
	 * cart/checkout flow, only this direct pay-for-a-specific-order one.
	 * before_woocommerce_pay is WooCommerce's own hook for this exact page,
	 * firing before the payment form.
	 */
	public static function render_booking_summary() {
		$order_id = absint( get_query_var( 'order-pay' ) );
		if ( ! $order_id ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$booking_id = (int) $order->get_meta( '_tc_booking_id' );
		if ( ! $booking_id ) {
			return; // Not a TC Booking order.
		}
		$b = TC_Notifications::booking_context( $booking_id );
		if ( ! $b ) {
			return;
		}

		$rows = array(
			array( __( 'Locatie', 'tc-booking' ), $b['location_name'] ),
			array( __( 'Gids', 'tc-booking' ), $b['guide_name'] ),
			array( __( 'Ceremonie', 'tc-booking' ), $b['service_name'] ),
			array( __( 'Datum', 'tc-booking' ), date_i18n( get_option( 'date_format' ), strtotime( $b['date'] ) ) ),
			array( __( 'Geboekt door', 'tc-booking' ), trim( $b['first_name'] . ' ' . $b['last_name'] ) ),
			array( __( 'E-mail', 'tc-booking' ), $b['email'] ),
			array( __( 'Telefoon', 'tc-booking' ), $b['phone'] ),
		);

		echo '<div id="tc-checkout-summary-root"><div class="tc-card">';
		echo '<h2 class="tc-title">' . esc_html__( 'Jouw boeking', 'tc-booking' ) . '</h2>';
		foreach ( $rows as $row ) {
			if ( '' === $row[1] ) {
				continue; // e.g. no guide assigned yet.
			}
			echo '<div class="tc-rline"><span class="l">' . esc_html( $row[0] ) . '</span><span>' . esc_html( $row[1] ) . '</span></div>';
		}
		echo '<div class="tc-rline total"><span class="l">' . esc_html__( 'Totaal', 'tc-booking' ) . '</span><span class="r">&euro;' .
			esc_html( number_format_i18n( (float) $b['total'], 2 ) ) . '</span></div>';
		echo '</div></div>';
	}

	/**
	 * Follow-up to GitHub issue #69 - the booking's fuller context
	 * (location, guide, group size, extras, additional guests) wasn't in
	 * WooCommerce's own "New order" admin email either, only the fee line
	 * item(s)' name/price already on the order (a service line reading
	 * something like "Zonsopgang ceremonie (2026-09-03) × 3 personen", plus
	 * one line per extra) and whatever billing fields WooCommerce shows by
	 * default (name/email/phone, from set_billing_*() in
	 * create_order_for_booking()). Hooked at priority 5 so this appears
	 * BEFORE WooCommerce's own order-items table (the default priority-10
	 * hooks that render it), reading top-to-bottom as "here's the booking,
	 * here's what's on it" rather than the reverse.
	 *
	 * Scoped to the "New order" email specifically ($email->id check) -
	 * not every WooCommerce email this fires on (cancelled/failed/refunded
	 * order emails trigger this same action too), matching what was
	 * actually asked for; extending to other emails is a one-line change
	 * to that check if wanted later.
	 */
	public static function add_booking_details_to_order_email( $order, $sent_to_admin, $plain_text, $email ) {
		if ( ! $email || 'new_order' !== $email->id ) {
			return;
		}
		$booking_id = (int) $order->get_meta( '_tc_booking_id' );
		if ( ! $booking_id ) {
			return; // Not a TC Booking order.
		}
		$b = TC_Notifications::booking_context( $booking_id );
		if ( ! $b ) {
			return;
		}

		$extras_rows = TC_Notifications::extras_rows( $b['extras'] );

		$guests_summary = '';
		if ( is_array( $b['guests'] ) && $b['guests'] ) {
			$names = array_filter( array_map( function ( $guest ) {
				return $guest['name'] ?? '';
			}, $b['guests'] ) );
			$guests_summary = implode( ', ', $names );
		}

		$rows = array_merge(
			array(
				array( __( 'Locatie', 'tc-booking' ), $b['location_name'] ),
				array( __( 'Gids', 'tc-booking' ), $b['guide_name'] ),
				array( __( 'Datum', 'tc-booking' ), date_i18n( get_option( 'date_format' ), strtotime( $b['date'] ) ) ),
				array( __( 'Groepsgrootte', 'tc-booking' ), $b['party_size'] > 1 ? $b['party_size'] : '' ),
				array( __( 'Extra gasten', 'tc-booking' ), $guests_summary ),
			),
			$extras_rows
		);

		if ( $plain_text ) {
			echo esc_html__( 'Boekingsgegevens', 'tc-booking' ) . "\n";
			foreach ( $rows as $row ) {
				if ( '' === $row[1] ) {
					continue;
				}
				// A blank label (extras_rows() follow-up rows) means "still
				// part of the row above" - indent instead of printing a bare
				// ": value" line.
				echo '' === $row[0] ? '  ' . esc_html( $row[1] ) . "\n" : esc_html( $row[0] ) . ': ' . esc_html( $row[1] ) . "\n";
			}
			echo "\n";
			return;
		}

		echo '<h2 style="margin:24px 0 8px;font-size:16px;color:#231F2E;">' . esc_html__( 'Boekingsgegevens', 'tc-booking' ) . '</h2>';
		echo TC_Notifications::email_rows( $rows ); // phpcs:ignore -- already-escaped HTML, matching how WooCommerce's own order-details table is echoed unescaped here too.
	}

	/**
	 * @param int $booking_id
	 * @return WC_Order|WP_Error
	 */
	public static function create_order_for_booking( $booking_id ) {
		$service_id = (int) get_post_meta( $booking_id, '_tc_service_id', true );
		$service    = TC_Availability::get_service_data( $service_id );
		$date       = get_post_meta( $booking_id, '_tc_date', true );
		$extras     = get_post_meta( $booking_id, '_tc_selected_extras', true );
		$party_size = max( 1, (int) get_post_meta( $booking_id, '_tc_party_size', true ) );
		$guests     = get_post_meta( $booking_id, '_tc_guests', true );
		$first_name = get_post_meta( $booking_id, '_tc_customer_first_name', true );
		$last_name  = get_post_meta( $booking_id, '_tc_customer_last_name', true );
		$email      = get_post_meta( $booking_id, '_tc_customer_email', true );
		$phone      = get_post_meta( $booking_id, '_tc_customer_phone', true );

		try {
			$order = wc_create_order();
		} catch ( Exception $e ) {
			return new WP_Error( 'tc_order_failed', $e->getMessage(), array( 'status' => 500 ) );
		}

		// "Bring anyone with you" (GitHub issue #6): base price is only
		// multiplied for services that opt into it - see the matching
		// comment in TC_Rest_Api::create_booking().
		//
		// $date itself stays the raw 'Y-m-d' from _tc_date (nothing else in
		// this function needs a display version) - $date_display is only
		// for the fee line's own name below, which is what actually shows
		// on every WooCommerce order screen/email (cart, checkout, "New
		// order", order-received, ...). Reported as showing a raw
		// "2026-10-31" there - confusing for a Dutch audience expecting
		// day-month-year with a spelled-out month, not the other way
		// around. 'j F Y' (not get_option('date_format')) to guarantee
		// that exact "31 oktober 2026" shape regardless of whatever the
		// site's own Settings -> General date format happens to be set to.
		$date_display      = date_i18n( 'j F Y', strtotime( $date ) );
		$party_multiplier = $service['allow_party'] ? $party_size : 1;
		$fee_label         = ( $service['allow_party'] && $party_size > 1 )
			? sprintf(
				// WPML support - Dutch (this site's WPML default language),
				// not English, for the reason explained at the top of the
				// i18n array in class-tc-booking-shortcode.php. This runs
				// inside create_order_for_booking(), called synchronously
				// from TC_Rest_Api::create_booking() after that switches to
				// the customer's language (see its maybe_switch_language()
				// call), so this is correctly localized per booking.
				/* translators: 1: service name, 2: date, 3: number of people */
				__( '%1$s (%2$s) × %3$d personen', 'tc-booking' ),
				$service['name'],
				$date_display,
				$party_size
			)
			: sprintf( /* translators: 1: service name, 2: date */ __( '%1$s (%2$s)', 'tc-booking' ), $service['name'], $date_display );

		$service_product = self::get_or_create_placeholder_product( 'tc-service-' . $service_id, $service['name'] );
		self::add_product_line( $order, $service_product, $fee_label, $service['price'] * $party_multiplier );

		if ( is_array( $extras ) ) {
			foreach ( $extras as $extra ) {
				if ( $extra['qty'] <= 0 ) {
					continue;
				}
				// Keyed by service + extra key (not just the label) since
				// two services can each define their own extra of the same
				// name at a different price - the placeholder product's own
				// price is irrelevant either way (the line's real amount is
				// always set explicitly below), but keeping them distinct
				// avoids one service's extra edits ever touching another's.
				$extra_sku     = 'tc-extra-' . $service_id . '-' . ( isset( $extra['key'] ) ? $extra['key'] : sanitize_title( $extra['label'] ) );
				$extra_product = self::get_or_create_placeholder_product( $extra_sku, $extra['label'] );
				self::add_product_line(
					$order,
					$extra_product,
					sprintf( '%s x%d', $extra['label'], $extra['qty'] ),
					$extra['price'] * $extra['qty']
				);
			}
		}

		// Guest details go on the order as a note (visible on the WooCommerce
		// Edit Order screen's Order notes panel) plus raw meta for any future
		// custom display - deliberately not building a custom order admin
		// panel just for this.
		if ( $service['allow_party'] && is_array( $guests ) && $guests ) {
			$lines = array();
			foreach ( $guests as $i => $guest ) {
				$lines[] = sprintf(
					'%d. %s (%s%s)',
					$i + 1,
					$guest['name'] ? $guest['name'] : __( 'unnamed', 'tc-booking' ),
					$guest['email'] ? $guest['email'] : '-',
					$guest['phone'] ? ', ' . $guest['phone'] : ''
				);
			}
			$order->add_order_note(
				sprintf(
					/* translators: 1: total group size, 2: list of additional guests */
					__( "Group of %1\$d. Additional guests:\n%2\$s", 'tc-booking' ),
					$party_size,
					implode( "\n", $lines )
				)
			);
			$order->update_meta_data( '_tc_guests', $guests );
		}

		$order->set_billing_first_name( $first_name );
		$order->set_billing_last_name( $last_name );
		$order->set_billing_email( $email );
		$order->set_billing_phone( $phone );

		$order->update_meta_data( '_tc_booking_id', $booking_id );
		$order->calculate_totals();
		$order->set_status( 'pending' );
		$order->save();

		update_post_meta( $booking_id, '_tc_wc_order_id', $order->get_id() );

		return $order;
	}

	/**
	 * Finds (by SKU) or lazily creates a hidden, zero-price placeholder
	 * WC_Product to back a booking's order line items - see this file's
	 * header comment for why this exists (a third-party invoicing plugin
	 * needs a real product to read a name from). `catalog_visibility`
	 * 'hidden' plus a 'publish' status keeps it a fully functional product
	 * (addable to orders, visible to any tool that reads WooCommerce
	 * products/reports) without it ever appearing in the shop, search, or
	 * being purchasable directly. Price is deliberately left at 0 - it is
	 * never read for what a booking actually charges; see add_product_line().
	 */
	private static function get_or_create_placeholder_product( $sku, $name ) {
		$product_id = wc_get_product_id_by_sku( $sku );
		if ( $product_id ) {
			return wc_get_product( $product_id );
		}
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_sku( $sku );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_virtual( true );
		$product->set_tax_status( 'none' );
		$product->set_regular_price( 0 );
		$product->save();
		return $product;
	}

	/**
	 * Adds a product-linked line item to an order, with the display name
	 * and amount fully overridden - the placeholder product's own name and
	 * (zero) price are never what's actually shown or charged.
	 *
	 * `WC_Order::add_fee( $name, $amount )` is NOT a real WooCommerce method -
	 * it was deprecated in WC 2.7 (2016) in favor of building an order item
	 * object and adding it via add_item(), and even the old deprecated
	 * version took a single fee object, not (name, amount) arguments.
	 * Calling the old two-argument form (as this file used to) fatals with
	 * "Call to undefined method" against any real WooCommerce install -
	 * this plugin was only ever exercised against a hand-written stub that
	 * (incorrectly) mirrored that call shape. This is what GitHub issue #11
	 * ("checkout issue") turned out to be: the order got created by
	 * wc_create_order() itself (which saves immediately), but every line
	 * failed to attach, leaving a real $0 order that WooCommerce then
	 * correctly refuses to take payment for.
	 *
	 * No tax is added - the booking's own price snapshot (service price +
	 * extras, already final) is the single source of truth per this
	 * class's file header, so calculate_totals()'s tax pass must not add
	 * anything on top of it. That's driven by the placeholder product's own
	 * tax_status ('none', set in get_or_create_placeholder_product()) since
	 * order items read tax status live from their linked product.
	 */
	private static function add_product_line( WC_Order $order, WC_Product $product, $name, $amount ) {
		$item = new WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_name( $name );
		$item->set_quantity( 1 );
		$item->set_subtotal( $amount );
		$item->set_total( $amount );
		$order->add_item( $item );
	}

	public static function cancel_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( ! in_array( $order->get_status(), array( 'cancelled', 'refunded' ), true ) ) {
			$order->update_status( 'cancelled', __( 'Cancelled from TC Booking admin.', 'tc-booking' ) );
		}
	}

	/**
	 * Keep the booking's own status mirror in sync when the order changes
	 * status - covers cases outside our own admin actions, e.g. a payment
	 * failing, or the merchant issuing a refund from the WooCommerce order
	 * screen directly instead of the TC Bookings screen.
	 */
	public static function sync_booking_from_order( $order_id, $from, $to, $order ) {
		$booking_id = (int) $order->get_meta( '_tc_booking_id' );
		if ( ! $booking_id ) {
			return;
		}

		$map = array(
			'processing' => 'confirmed',
			'completed'  => 'confirmed',
			'cancelled'  => 'cancelled',
			'refunded'   => 'cancelled',
			'failed'     => 'payment_failed',
			'pending'    => 'pending_payment',
			'on-hold'    => 'pending_payment',
		);

		if ( isset( $map[ $to ] ) ) {
			$previous_status = get_post_meta( $booking_id, '_tc_status', true );
			update_post_meta( $booking_id, '_tc_status', $map[ $to ] );

			if ( 'confirmed' === $map[ $to ] && 'confirmed' !== $previous_status ) {
				TC_Notifications::send_confirmation( $booking_id );
			}
		}
	}
}
