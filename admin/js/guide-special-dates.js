/**
 * TC Booking admin - special-service date calendars (Guide edit screen,
 * GitHub issue #71).
 *
 * "Special" services (a Service's own "Special service" checkbox - see
 * class-tc-meta-boxes.php's render_service()) only happen once or twice a
 * month, so instead of the regular blocked/available calendar, a guide opts
 * into the exact dates they're offering one - separately per location,
 * since the same rare service can happen at a different place on a
 * different date.
 *
 * One calendar per (special service, location) pair - only shown once BOTH
 * that service and that location are checked in this same edit screen's
 * "Services provided" / "Locations covered" lists (render_guide()'s own
 * meta box, elsewhere on this page), reacting live to those checkboxes
 * without a page reload. Same staged-changes model as
 * admin/js/guide-availability.js - toggling a date only updates local
 * state; hidden tc_special_dates[SERVICE][LOCATION][]=DATE fields render on
 * every change and submit with the rest of this form's own Update button.
 * No REST route, no AJAX - validated again server-side in
 * TC_Meta_Boxes::save_guide_special_dates_changes().
 */
( function () {
	'use strict';

	var root = document.getElementById( 'tc-special-dates-root' );
	if ( ! root ) {
		return;
	}

	var CFG       = window.tcGuideSpecialDatesAdmin;
	var SERVICES  = CFG.services || [];
	var LOCATIONS = CFG.locations || [];

	if ( ! SERVICES.length ) {
		root.innerHTML = '<p class="tc-sub">' + escapeHtml( 'No services are marked "Special service" yet - add that on a Service’s own edit screen first.' ) + '</p>';
		return;
	}

	function escapeHtml( str ) {
		var div = document.createElement( 'div' );
		div.textContent = str == null ? '' : String( str );
		return div.innerHTML;
	}
	function escapeAttr( str ) {
		return escapeHtml( str ).replace( /"/g, '&quot;' );
	}

	var SITE_TZ = 'Europe/Amsterdam';

	// Same Netherlands-calendar-day handling as every other calendar widget
	// in this plugin - see the matching comment in guide-availability.js.
	function nlToday() {
		var parts = new Intl.DateTimeFormat( 'en-CA', { timeZone: SITE_TZ, year: 'numeric', month: '2-digit', day: '2-digit' } ).formatToParts( new Date() );
		var y, m, d;
		parts.forEach( function ( p ) {
			if ( 'year' === p.type ) y = parseInt( p.value, 10 );
			if ( 'month' === p.type ) m = parseInt( p.value, 10 );
			if ( 'day' === p.type ) d = parseInt( p.value, 10 );
		} );
		return new Date( y, m - 1, d );
	}
	function isoDate( d ) {
		var y = d.getFullYear();
		var m = String( d.getMonth() + 1 ).padStart( 2, '0' );
		var day = String( d.getDate() ).padStart( 2, '0' );
		return y + '-' + m + '-' + day;
	}
	function monthBounds( offset ) {
		var now   = nlToday();
		var first = new Date( now.getFullYear(), now.getMonth() + offset, 1 );
		var last  = new Date( now.getFullYear(), now.getMonth() + offset + 1, 0 );
		return { first: first, last: last };
	}

	function pairKey( serviceId, locationId ) {
		return serviceId + ':' + locationId;
	}

	// Group the dates this guide already offers by (service, location) pair.
	var initialByPair = {};
	( CFG.specialDates || [] ).forEach( function ( row ) {
		var k = pairKey( row.service_id, row.location_id );
		if ( ! initialByPair[ k ] ) {
			initialByPair[ k ] = {};
		}
		initialByPair[ k ][ row.date ] = true;
	} );

	// One entry per (service, location) pair ever shown, so toggled state
	// and month position survive a checkbox being unchecked and re-checked.
	var widgets = {};
	function getWidget( serviceId, locationId ) {
		var k = pairKey( serviceId, locationId );
		if ( ! widgets[ k ] ) {
			widgets[ k ] = {
				serviceId: serviceId,
				locationId: locationId,
				monthOffset: 0,
				dates: Object.assign( {}, initialByPair[ k ] || {} ),
			};
		}
		return widgets[ k ];
	}

	function checkedValues( name ) {
		var out = [];
		document.querySelectorAll( 'input[name="' + name + '"]:checked' ).forEach( function ( el ) {
			out.push( String( el.value ) );
		} );
		return out;
	}

	function renderCalendar( w, service, location ) {
		var bounds    = monthBounds( w.monthOffset );
		var monthName = bounds.first.toLocaleDateString( 'en-US', { month: 'long', year: 'numeric' } );
		var firstDow  = ( bounds.first.getDay() + 6 ) % 7; // Monday-first
		var daysInMo  = bounds.last.getDate();
		var today     = nlToday();

		var cells = '';
		for ( var i = 0; i < firstDow; i++ ) {
			cells += '<div class="tc-cal-day past"></div>';
		}
		for ( var d = 1; d <= daysInMo; d++ ) {
			var dateObj = new Date( bounds.first.getFullYear(), bounds.first.getMonth(), d );
			var iso     = isoDate( dateObj );
			var isPast  = dateObj < today;
			// Unlike the regular availability calendar, every future cell
			// here is clickable regardless of state (toggling this special
			// service on/off for that date) - 'off' is reused purely for
			// its neutral gray styling to make "not offered" read clearly
			// as the default, not because the cell is actually inert, so
			// the cursor is forced back to pointer for it below.
			var offered = Object.prototype.hasOwnProperty.call( w.dates, iso );
			var cls     = isPast ? 'past' : ( offered ? 'dirty available' : 'off' );
			var attrs   = isPast ? '' : ' data-date="' + iso + '" data-key="' + escapeAttr( pairKey( w.serviceId, w.locationId ) ) + '" style="cursor:pointer;"';
			cells += '<div class="tc-cal-day ' + cls + '"' + attrs + '>' + d + '</div>';
		}

		var offeredCount = Object.keys( w.dates ).length;
		var hiddenInputs = Object.keys( w.dates ).map( function ( date ) {
			return '<input type="hidden" name="tc_special_dates[' + escapeAttr( w.serviceId ) + '][' + escapeAttr( w.locationId ) + '][]" value="' + escapeAttr( date ) + '">';
		} ).join( '' );

		return '<div class="tc-card" style="margin-bottom:16px;">' +
			'<p><strong>' + escapeHtml( service.name ) + '</strong> — ' + escapeHtml( location.name ) + '</p>' +
			'<p class="tc-sub">Click a date to mark this special service as offered there - changes save when you click Update below.</p>' +
			( offeredCount ? '<div class="tc-cal-status saving" style="position:static;display:inline-block;">' + escapeHtml( offeredCount + ( 1 === offeredCount ? ' date' : ' dates' ) + ' offered' ) + '</div>' : '' ) +
			'<div class="tc-grid-nav"><button type="button" class="tc-sd-prev" data-key="' + escapeAttr( pairKey( w.serviceId, w.locationId ) ) + '">←</button><span class="range">' + monthName + '</span><button type="button" class="tc-sd-next" data-key="' + escapeAttr( pairKey( w.serviceId, w.locationId ) ) + '">→</button></div>' +
			'<div class="tc-cal-grid">' +
			[ 'M', 'T', 'W', 'T', 'F', 'S', 'S' ].map( function ( l ) { return '<div class="tc-cal-dow">' + l + '</div>'; } ).join( '' ) +
			cells + '</div>' + hiddenInputs + '</div>';
	}

	function render() {
		var checkedServiceIds  = checkedValues( 'tc_service_ids[]' );
		var checkedLocationIds = checkedValues( 'tc_location_ids[]' );

		var activeServices = SERVICES.filter( function ( s ) {
			return checkedServiceIds.indexOf( String( s.id ) ) !== -1;
		} );
		var activeLocations = LOCATIONS.filter( function ( l ) {
			return checkedLocationIds.indexOf( String( l.id ) ) !== -1;
		} );

		if ( ! activeServices.length || ! activeLocations.length ) {
			root.innerHTML = '<p class="tc-sub">' + escapeHtml( 'Check a special service under "Services provided" and at least one location under "Locations covered" above to set its dates here.' ) + '</p>';
			return;
		}

		var html = '';
		activeServices.forEach( function ( service ) {
			activeLocations.forEach( function ( location ) {
				html += renderCalendar( getWidget( service.id, location.id ), service, location );
			} );
		} );
		root.innerHTML = html;

		root.querySelectorAll( '.tc-sd-prev' ).forEach( function ( btn ) {
			btn.onclick = function () { widgets[ btn.dataset.key ].monthOffset -= 1; render(); };
		} );
		root.querySelectorAll( '.tc-sd-next' ).forEach( function ( btn ) {
			btn.onclick = function () { widgets[ btn.dataset.key ].monthOffset += 1; render(); };
		} );
		root.querySelectorAll( '[data-date]' ).forEach( function ( el ) {
			el.onclick = function () {
				var w = widgets[ el.dataset.key ];
				if ( Object.prototype.hasOwnProperty.call( w.dates, el.dataset.date ) ) {
					delete w.dates[ el.dataset.date ];
				} else {
					w.dates[ el.dataset.date ] = true;
				}
				render();
			};
		} );
	}

	// The Locations covered / Services provided checkboxes live in a
	// different meta box on the same page (render_guide(), not
	// render_guide_special_dates()) - both are just sections of the one
	// post-edit <form>, so a document-level listener reaches them fine.
	document.addEventListener( 'change', function ( e ) {
		if ( 'tc_service_ids[]' === e.target.name || 'tc_location_ids[]' === e.target.name ) {
			render();
		}
	} );

	render();
} )();
