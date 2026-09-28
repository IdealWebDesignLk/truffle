/**
 * TC Booking - interactivity on WooCommerce's "Pay for order" page: a live
 * payment-gateway surcharge preview, and applying/removing a coupon code
 * (GitHub issue #77).
 *
 * create_order_for_booking() sends every TC Booking customer straight to
 * this page (never through WC()->cart - see class-tc-woocommerce.php's
 * own file header), so neither a site-level cart-fees snippet nor
 * WooCommerce's own cart/coupon UI ever reaches a booking order at all.
 *
 * The gateway fee's actual, authoritative charge is always (re)applied
 * server-side in TC_Woocommerce::apply_gateway_fee_before_payment(),
 * hooked to woocommerce_before_pay_action, regardless of whether this
 * script runs - it exists purely so what's shown BEFORE the customer
 * commits to paying already matches what they'll actually be charged. A
 * coupon, by contrast, only ever gets applied through this script's own
 * AJAX call (there's no separate submit-time hook for it the way there is
 * for the gateway fee) - ajax_apply_coupon()/ajax_remove_coupon() are the
 * one and only path a coupon reaches an order through.
 *
 * WooCommerce's own order-review table on this page (templates/checkout/
 * form-pay.php) builds its rows from get_order_item_totals() with no
 * stable per-row class to hook (verified against WooCommerce's own
 * template - every row is a bare <tr>, distinguished only by its
 * translated label text) - patching it in place would be fragile. A full
 * reload instead re-renders that table AND this plugin's own summary
 * panel (render_booking_summary(), reading the same $order) both fresh
 * from the order's already-saved state, so nothing shown on the page can
 * ever disagree with what's actually stored.
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

	// A gateway chosen just before a reload is carried across via ?tc_pm=
	// so the radio stays selected across it (whether the reload came from
	// switching gateways or from applying/removing a coupon), and so the
	// fee (already correct, already saved) isn't pointlessly reapplied and
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

	function reloadPage() {
		var pm  = currentPaymentMethod();
		var url = new URL( window.location.href );
		if ( pm ) {
			url.searchParams.set( 'tc_pm', pm );
		}
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
					reloadPage();
				}
			} )
			.catch( function () {} );
	}

	// GitHub issue #77 - the coupon form/applied-code chip itself
	// (render_booking_summary() in class-tc-woocommerce.php). Unlike the
	// gateway fee, applying/removing is always a deliberate click, never
	// something to redo automatically on page load - so no "on load"
	// counterpart to applyFee() above is needed here.
	function couponMessage( text, isError ) {
		var el = document.getElementById( 'tc-coupon-message' );
		if ( ! el ) {
			return;
		}
		el.textContent = text || '';
		el.classList.toggle( 'tc-error', !! isError );
	}

	function submitCoupon( action, code, button ) {
		if ( button ) {
			button.disabled = true;
		}
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', CFG.nonce );
		body.set( 'order_id', CFG.orderId );
		body.set( 'order_key', CFG.orderKey );
		body.set( 'coupon_code', code );

		fetch( CFG.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( res ) { return res.json(); } )
			.then( function ( data ) {
				if ( data && data.success ) {
					reloadPage();
					return;
				}
				couponMessage( ( data && data.data && data.data.message ) || CFG.genericError, true );
				if ( button ) {
					button.disabled = false;
				}
			} )
			.catch( function () {
				couponMessage( CFG.genericError, true );
				if ( button ) {
					button.disabled = false;
				}
			} );
	}

	function wireCouponForm() {
		var applyBtn = document.getElementById( 'tc-coupon-apply' );
		var input    = document.getElementById( 'tc-coupon-input' );
		if ( applyBtn && input ) {
			applyBtn.onclick = function () {
				var code = input.value.trim();
				if ( ! code ) {
					couponMessage( CFG.couponRequired, true );
					return;
				}
				couponMessage( '' );
				submitCoupon( 'tc_apply_coupon', code, applyBtn );
			};
			input.addEventListener( 'keydown', function ( e ) {
				if ( 'Enter' === e.key ) {
					e.preventDefault();
					applyBtn.click();
				}
			} );
		}
		document.querySelectorAll( '[data-remove-coupon]' ).forEach( function ( el ) {
			el.onclick = function () {
				submitCoupon( 'tc_remove_coupon', el.dataset.removeCoupon, el );
			};
		} );
	}

	preselectFromUrl();
	wireCouponForm();

	document.addEventListener( 'change', function ( e ) {
		if ( 'payment_method' === e.target.name ) {
			applyFee( e.target.value );
		}
	} );

	// Also apply once for whichever gateway is selected on load - the
	// customer might never touch the radio buttons at all.
	applyFee( currentPaymentMethod() );
} )();
