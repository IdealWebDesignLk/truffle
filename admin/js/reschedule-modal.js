/**
 * TC Booking admin - reschedule modal (Bookings list screen).
 *
 * Replaces the old prompt()-based reschedule flow with the same
 * availability calendar the customer-facing booking widget uses (GitHub
 * issue #16), so an admin sees available/limited/unavailable dates
 * instead of blindly typing a date string. Submits to the exact same
 * admin-post.php action/handler as before (TC_Admin_Bookings::handle_reschedule())
 * - only the trigger UI changed.
 */
( function () {
	'use strict';

	var CFG = window.tcRescheduleModal;
	if ( ! CFG ) {
		return;
	}

	var state = {
		open: false,
		bookingId: null,
		nonce: null,
		serviceId: null,
		locationId: null,
		serviceName: '',
		currentDate: null,
		monthOffset: 0,
		grid: {}, // date -> 'available' | 'limited' | 'off'
		loading: false,
		selectedDate: null,
		error: null,
	};

	var root = null;

	function ensureRoot() {
		if ( root ) return root;
		root = document.createElement( 'div' );
		root.id = 'tc-reschedule-modal-root';
		document.body.appendChild( root );
		return root;
	}

	var SITE_TZ = 'Europe/Amsterdam';

	// This business only operates in the Netherlands, so "today"/"this
	// month" must always mean the Netherlands' calendar day, not the
	// admin's own device timezone. Built via Intl so CET/CEST
	// daylight-saving transitions are handled automatically and correctly.
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
		// Not toISOString() - that converts to UTC. Build the string from
		// local date parts instead, matching how the Date object was built
		// (nlToday()), so the date submitted always matches the Netherlands
		// calendar day shown in the calendar.
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

	function openModal( link ) {
		state.open         = true;
		state.bookingId     = link.dataset.bookingId;
		state.nonce         = link.dataset.nonce;
		state.serviceId     = link.dataset.serviceId;
		state.locationId    = link.dataset.locationId;
		state.serviceName   = link.dataset.serviceName;
		state.currentDate   = link.dataset.currentDate;
		state.monthOffset   = 0;
		state.selectedDate  = null;
		state.error         = null;
		render();
		loadMonth();
	}

	function closeModal() {
		state.open = false;
		render();
	}

	function loadMonth() {
		state.loading = true;
		render();
		var bounds = monthBounds( state.monthOffset );
		var url = CFG.restRoot + '/availability?service_id=' + state.serviceId + '&location_id=' + state.locationId +
			'&start=' + isoDate( bounds.first ) + '&end=' + isoDate( bounds.last );
		fetch( url ).then( function ( res ) { return res.json(); } ).then( function ( rows ) {
			state.grid = {};
			rows.forEach( function ( r ) { state.grid[ r.date ] = r.status; } );
			state.loading = false;
			render();
		} ).catch( function () {
			state.error   = 'Could not load availability.';
			state.loading = false;
			render();
		} );
	}

	function render() {
		var r = ensureRoot();
		if ( ! state.open ) {
			r.innerHTML = '';
			return;
		}

		var bounds    = monthBounds( state.monthOffset );
		var monthName = bounds.first.toLocaleDateString( 'en-US', { month: 'long', year: 'numeric' } );
		var firstDow  = ( bounds.first.getDay() + 6 ) % 7; // Monday-first
		var daysInMo  = bounds.last.getDate();
		var today     = nlToday();

		var cells = '';
		for ( var i = 0; i < firstDow; i++ ) {
			cells += '<div class="tc-cal-day past"></div>';
		}
		for ( var d = 1; d <= daysInMo; d++ ) {
			var dateObj   = new Date( bounds.first.getFullYear(), bounds.first.getMonth(), d );
			var iso       = isoDate( dateObj );
			var status    = state.grid[ iso ] || 'off';
			var isPast    = dateObj < today;
			var clickable = ! isPast && 'off' !== status;
			var cls       = isPast ? 'past' : status;
			if ( iso === state.selectedDate ) cls += ' selected';
			cells += '<div class="tc-cal-day ' + cls + '"' + ( clickable ? ' data-date="' + iso + '"' : '' ) + '>' + d + '</div>';
		}

		r.innerHTML = '<div class="tc-reschedule-overlay">' +
			'<div class="tc-reschedule-dialog tc-card">' +
			'<button type="button" class="tc-reschedule-close" aria-label="Close">&times;</button>' +
			'<h2 class="tc-title">Reschedule</h2>' +
			'<p class="tc-sub">' + escapeHtml( state.serviceName || '' ) + ' — currently ' + escapeHtml( state.currentDate || '' ) + '</p>' +
			( state.error ? '<div class="tc-error">' + escapeHtml( state.error ) + '</div>' : '' ) +
			'<div class="tc-grid-nav"><button type="button" id="tc-resched-prev">←</button><span class="range">' + monthName + '</span><button type="button" id="tc-resched-next">→</button></div>' +
			( state.loading ? '<p>Loading…</p>' : '<div class="tc-cal-grid">' +
				[ 'M', 'T', 'W', 'T', 'F', 'S', 'S' ].map( function ( l ) { return '<div class="tc-cal-dow">' + l + '</div>'; } ).join( '' ) +
				cells + '</div>' ) +
			'<div class="tc-legend" style="margin-top:16px;">' +
			'<span><span class="tc-swatch" style="background:var(--available)"></span>Available</span>' +
			'<span><span class="tc-swatch" style="background:var(--limited)"></span>Almost full</span>' +
			'<span><span class="tc-swatch" style="background:var(--unavailable)"></span>Not available</span>' +
			'</div>' +
			'<div class="tc-nav" style="margin-top:20px;">' +
			'<button type="button" class="tc-btn primary" id="tc-resched-confirm" style="width:100%;"' + ( state.selectedDate ? '' : ' disabled' ) + '>' +
			( state.selectedDate ? 'Reschedule to ' + state.selectedDate : 'Pick a date' ) + '</button>' +
			'</div>' +
			'</div></div>';

		attachHandlers( r );
	}

	function attachHandlers( r ) {
		r.querySelectorAll( '.tc-reschedule-close' ).forEach( function ( el ) { el.onclick = closeModal; } );

		var overlay = r.querySelector( '.tc-reschedule-overlay' );
		if ( overlay ) {
			overlay.onclick = function ( e ) { if ( e.target === overlay ) closeModal(); };
		}

		var prev = r.querySelector( '#tc-resched-prev' );
		if ( prev ) prev.onclick = function () { state.monthOffset -= 1; loadMonth(); };
		var next = r.querySelector( '#tc-resched-next' );
		if ( next ) next.onclick = function () { state.monthOffset += 1; loadMonth(); };

		r.querySelectorAll( '[data-date]' ).forEach( function ( el ) {
			el.onclick = function () {
				state.selectedDate = el.dataset.date;
				render();
			};
		} );

		var confirmBtn = r.querySelector( '#tc-resched-confirm' );
		if ( confirmBtn ) confirmBtn.onclick = submitReschedule;
	}

	function submitReschedule() {
		if ( ! state.selectedDate ) return;
		var form = document.createElement( 'form' );
		form.method = 'POST';
		form.action = CFG.postUrl;
		[
			[ 'action', 'tc_reschedule_booking' ],
			[ 'booking_id', state.bookingId ],
			[ 'new_date', state.selectedDate ],
			[ '_wpnonce', state.nonce ],
		].forEach( function ( pair ) {
			var input   = document.createElement( 'input' );
			input.type  = 'hidden';
			input.name  = pair[ 0 ];
			input.value = pair[ 1 ];
			form.appendChild( input );
		} );
		document.body.appendChild( form );
		form.submit();
	}

	function escapeHtml( str ) {
		var div = document.createElement( 'div' );
		div.textContent = str == null ? '' : String( str );
		return div.innerHTML;
	}

	document.addEventListener( 'click', function ( e ) {
		var link = e.target.closest( '.tc-reschedule-link' );
		if ( ! link ) return;
		e.preventDefault();
		openModal( link );
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && state.open ) closeModal();
	} );
} )();
