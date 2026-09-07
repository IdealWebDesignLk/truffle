/**
 * TC Booking admin - extras/additional-guests for the "Add New" booking
 * form (class-tc-meta-boxes.php's render_new_booking_form()).
 *
 * Neither extras nor how many guest rows to show is known until a service
 * (and, for guests, a group size) is picked, so both are rendered here
 * rather than server-side. Plain DOM/jQuery, no build step, matching
 * admin.js. Field names - tc_new_extra_qty[key], tc_new_guest_name[]/
 * email[]/phone[] - are read directly as $_POST arrays by
 * TC_Meta_Boxes::save_new_booking(), no JSON encoding needed; that method
 * re-validates everything against the actual selected service's real
 * extras list server-side regardless of what this script rendered.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var data = window.tcNewBookingForm || {};
		var services = data.services || [];
		var i18n     = data.i18n || {};
		var $service = $( '#tc_new_service_id' );
		var $party   = $( '#tc_new_party_size' );
		var $extras  = $( '#tc-new-extras-list' );
		var $guests  = $( '#tc-new-guests-list' );

		if ( ! $service.length ) {
			return;
		}

		function findService( id ) {
			var found = null;
			services.forEach( function ( s ) {
				if ( String( s.id ) === String( id ) ) {
					found = s;
				}
			} );
			return found;
		}

		function escapeHtml( str ) {
			var div = document.createElement( 'div' );
			div.textContent = str === null || undefined === str ? '' : String( str );
			return div.innerHTML;
		}
		function escapeAttr( str ) {
			return escapeHtml( str ).replace( /"/g, '&quot;' );
		}

		function renderExtras( service ) {
			if ( ! service ) {
				$extras.html( '<p class="description">' + escapeHtml( i18n.pickService ) + '</p>' );
				return;
			}
			if ( ! service.extras.length ) {
				$extras.html( '<p class="description">' + escapeHtml( i18n.noExtras ) + '</p>' );
				return;
			}
			var html = '';
			service.extras.forEach( function ( e ) {
				html += '<p class="tc-new-extra-row">' +
					'<label>' + escapeHtml( e.label ) + ' (€' + Number( e.price ).toFixed( 2 ) + ' ' + escapeHtml( i18n.each ) + ', max ' + parseInt( e.max, 10 ) + ')</label> ' +
					'<input type="number" name="tc_new_extra_qty[' + escapeAttr( e.key ) + ']" min="0" max="' + escapeAttr( e.max ) + '" value="0" style="width:70px;">' +
					'</p>';
			} );
			$extras.html( html );
		}

		function renderGuests( service ) {
			$guests.empty();
			if ( ! service || ! service.allow_party ) {
				return;
			}
			var count = Math.max( 0, ( parseInt( $party.val(), 10 ) || 1 ) - 1 );
			var html  = '';
			for ( var i = 0; i < count; i++ ) {
				html += '<p class="tc-new-guest-row">' +
					'<strong>' + escapeHtml( i18n.guest ) + ' ' + ( i + 1 ) + '</strong><br>' +
					'<input type="text" name="tc_new_guest_name[]" placeholder="' + escapeAttr( i18n.name ) + '" class="regular-text"> ' +
					'<input type="email" name="tc_new_guest_email[]" placeholder="' + escapeAttr( i18n.email ) + '" class="regular-text"> ' +
					'<input type="text" name="tc_new_guest_phone[]" placeholder="' + escapeAttr( i18n.phone ) + '" class="regular-text">' +
					'</p>';
			}
			$guests.html( html );
		}

		function refresh() {
			var service = findService( $service.val() );
			if ( service && service.allow_party ) {
				$party.prop( 'disabled', false ).attr( 'max', service.max_capacity );
			} else {
				$party.val( '1' ).prop( 'disabled', true ).removeAttr( 'max' );
			}
			renderExtras( service );
			renderGuests( service );
		}

		$service.on( 'change', refresh );
		$party.on( 'input change', function () {
			renderGuests( findService( $service.val() ) );
		} );

		refresh();
	} );
} )( jQuery );
