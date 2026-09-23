/**
 * TC Booking - live payment-gateway surcharge preview on WooCommerce's
 * "Pay for order" page.
 *
 * create_order_for_booking() sends every TC Booking customer straight to
 * this page (never through WC()->cart - see class-tc-woocommerce.php's
 * own file header), so a site-level snippet that adds a payment-gateway
 * surcharge via woocommerce_cart_calculate_fees never fires for a booking
 * order at all. The actual, authoritative charge is always (re)applied
 * server-side in TC_Woocommerce::apply_gateway_fee_before_payment(),
 * hooked to woocommerce_before_pay_action, regardless of whether this
 * script runs. This script exists purely so what's shown BEFORE the
 * customer commits to paying already matches what they'll actually be
 * charged.
 *
 * WooCommerce's own order-review table on this page (templates/checkout/
 * form-pay.php) builds its rows from get_order_item_totals() with no
 * stable per-row class to hook (verified against WooCommerce's own
 * template - every row is a bare <tr>, distinguished only by its
 * translated label text) - patching it in place would be fragile. A full
 * reload instead re-renders that table AND this plugin's own summary
 * panel (render_booking_summary(), reading the same $order) both fresh
 * from the order's already-saved state, so the two never disagree.
 */
( function () {
	'use strict';

	var CFG = window.tcPayGatewayFee;
	if ( ! CFG ) {
		return;
	}

	function currentPaymentMethod() {
		var checked = document.querySelector( 'input[name="payment_method"]:checked' );
		return checked ? checked.value : '';
	}

	// A gateway chosen just before the reload below is carried across via
	// ?tc_pm= so the radio stays selected across it, and so the fee
	// (already correct, already saved) isn't pointlessly reapplied and
	// re-reloaded on the very next load.
	function preselectFromUrl() {
		var pm = new URLSearchParams( window.location.search ).get( 'tc_pm' );
		if ( ! pm ) {
			return;
		}
		var radio = document.querySelector( 'input[name="payment_method"][value="' + pm.replace( /"/g, '' ) + '"]' );
		if ( radio ) {
			radio.checked = true;
		}
	}

	function reloadWithPaymentMethod( pm ) {
		var url = new URL( window.location.href );
		url.searchParams.set( 'tc_pm', pm );
		window.location.href = url.toString();
	}

	function applyFee( paymentMethod ) {
		if ( ! paymentMethod ) {
			return;
		}
		var body = new URLSearchParams();
		body.set( 'action', 'tc_preview_gateway_fee' );
		body.set( 'nonce', CFG.nonce );
		body.set( 'order_id', CFG.orderId );
		body.set( 'order_key', CFG.orderKey );
		body.set( 'payment_method', paymentMethod );

		fetch( CFG.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( res ) { return res.json(); } )
			.then( function ( data ) {
				// Only reload when the fee actually changed - otherwise every
				// load would re-trigger this for the default-selected
				// gateway and reload forever.
				if ( data && data.success && data.data && data.data.changed ) {
					reloadWithPaymentMethod( paymentMethod );
				}
			} )
			.catch( function () {} );
	}

	preselectFromUrl();

	document.addEventListener( 'change', function ( e ) {
		if ( 'payment_method' === e.target.name ) {
			applyFee( e.target.value );
		}
	} );

	// Also apply once for whichever gateway is selected on load - the
	// customer might never touch the radio buttons at all.
	applyFee( currentPaymentMethod() );
} )();
