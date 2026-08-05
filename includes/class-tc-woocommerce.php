<?php
/**
 * WooCommerce integration.
 *
 * Bookings are represented in WooCommerce as fee-based orders rather than
 * orders containing WC_Product line items. This avoids having to keep a
 * shadow WC_Product in sync with every Service/Extra edited in the TC admin
 * screens - the booking's own price snapshot (captured at creation time in
 * class-tc-rest-api.php) is the single source of truth, and WooCommerce is
 * used purely for its cart/checkout/payment/refund machinery.
 *
 * @package TC_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TC_Woocommerce {

	public static function init() {
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'sync_booking_from_order' ), 10, 4 );
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
		$party_multiplier = $service['allow_party'] ? $party_size : 1;
		$fee_label         = ( $service['allow_party'] && $party_size > 1 )
			? sprintf(
				/* translators: 1: service name, 2: date, 3: number of people */
				__( '%1$s (%2$s) × %3$d people', 'tc-booking' ),
				$service['name'],
				$date,
				$party_size
			)
			: sprintf( /* translators: 1: service name, 2: date */ __( '%1$s (%2$s)', 'tc-booking' ), $service['name'], $date );

		$order->add_fee( $fee_label, $service['price'] * $party_multiplier );

		if ( is_array( $extras ) ) {
			foreach ( $extras as $extra ) {
				if ( $extra['qty'] <= 0 ) {
					continue;
				}
				$order->add_fee(
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
