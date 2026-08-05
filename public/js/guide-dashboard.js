/**
 * TC Booking - guide self-service calendar.
 *
 * A guide sees a month at a time. Green = available (the default - no
 * action needed). Clicking a date toggles it to blocked (a day off) and
 * back. Dates with an existing booking are shown but not clickable - to
 * change those, the guide needs to contact admin, since cancelling/moving
 * a paid booking has consequences (refunds, notifying the customer) that
 * deliberately stay an admin action rather than a guide self-service one.
 */
(function () {
	'use strict';

	var root = document.getElementById( 'tc-guide-dashboard-root' );
	if ( ! root ) {
		return;
	}

	var API_ROOT = window.tcGuideDashboard.restRoot;
	var NONCE    = window.tcGuideDashboard.nonce;

	var state = {
		monthOffset: 0,
		availability: {}, // date -> 'blocked' | 'available'
		loading: true,
		error: null,
	};

	function apiGet( path ) {
		return fetch( API_ROOT + path, { headers: { 'X-WP-Nonce': NONCE } } ).then( handleResponse );
	}
	function apiPost( path, body ) {
		return fetch( API_ROOT + path, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
			body: JSON.stringify( body ),
		} ).then( handleResponse );
	}
	function handleResponse( res ) {
		return res.json().then( function ( data ) {
			if ( ! res.ok ) {
				throw new Error( ( data && data.message ) || 'Something went wrong.' );
			}
			return data;
		} );
	}

	function isoDate( d ) {
		// Not toISOString() - that converts to UTC, which silently shifts the
		// date back a day for any browser ahead of UTC (e.g. the Netherlands,
		// this site's own market, at UTC+1/+2). Build the string from local
		// date parts so what's saved always matches the day actually clicked.
		var y = d.getFullYear();
		var m = String( d.getMonth() + 1 ).padStart( 2, '0' );
		var day = String( d.getDate() ).padStart( 2, '0' );
		return y + '-' + m + '-' + day;
	}

	function monthBounds( offset ) {
		var now   = new Date();
		var first = new Date( now.getFullYear(), now.getMonth() + offset, 1 );
		var last  = new Date( now.getFullYear(), now.getMonth() + offset + 1, 0 );
		return { first: first, last: last };
	}

	function loadMonth() {
		state.loading = true;
		render();
		var bounds = monthBounds( state.monthOffset );
		apiGet( '/guide/availability?start=' + isoDate( bounds.first ) + '&end=' + isoDate( bounds.last ) )
			.then( function ( rows ) {
				state.availability = {};
				rows.forEach( function ( r ) { state.availability[ r.date ] = r.status; } );
				state.loading = false;
				render();
			} )
			.catch( function ( err ) {
				state.error   = err.message;
				state.loading = false;
				render();
			} );
	}

	function toggleDate( iso, currentlyBlocked ) {
		var newStatus = currentlyBlocked ? 'available' : 'blocked';
		state.availability[ iso ] = newStatus; // optimistic
		render();
		apiPost( '/guide/availability', { date: iso, status: newStatus } ).catch( function ( err ) {
			state.error = err.message;
			render();
		} );
	}

	function render() {
		var bounds    = monthBounds( state.monthOffset );
		var monthName = bounds.first.toLocaleDateString( 'en-US', { month: 'long', year: 'numeric' } );
		var firstDow  = ( bounds.first.getDay() + 6 ) % 7; // Monday-first
		var daysInMo  = bounds.last.getDate();
		var today     = new Date();
		today.setHours( 0, 0, 0, 0 );

		var cells = '';
		for ( var i = 0; i < firstDow; i++ ) {
			cells += '<div class="tc-cal-day past"></div>';
		}
		for ( var d = 1; d <= daysInMo; d++ ) {
			var dateObj = new Date( bounds.first.getFullYear(), bounds.first.getMonth(), d );
			var iso     = isoDate( dateObj );
			var status  = state.availability[ iso ] || 'available';
			var isPast  = dateObj < today;
			var cls     = isPast ? 'past' : ( 'blocked' === status ? 'blocked' : 'available' );
			cells += '<div class="tc-cal-day ' + cls + '"' + ( isPast ? '' : ' data-date="' + iso + '" data-blocked="' + ( 'blocked' === status ? '1' : '0' ) + '"' ) + '>' + d + '</div>';
		}

		root.innerHTML = '<div class="tc-card">' +
			( state.error ? '<div class="tc-error">' + state.error + '</div>' : '' ) +
			'<h2 class="tc-title">Your availability</h2>' +
			'<p class="tc-sub">Tap a date to mark it as a day off, or tap again to reopen it. Everything is available by default.</p>' +
			'<div class="tc-grid-nav"><button id="tc-prev-month">\u2190</button><span class="range">' + monthName + '</span><button id="tc-next-month">\u2192</button></div>' +
			( state.loading ? '<p>Loading\u2026</p>' : '<div class="tc-cal-grid">' +
				[ 'M', 'T', 'W', 'T', 'F', 'S', 'S' ].map( function ( l ) { return '<div class="tc-cal-dow">' + l + '</div>'; } ).join( '' ) +
				cells + '</div>' ) +
			'<div class="tc-legend" style="margin-top:16px;">' +
			'<span><span class="tc-swatch" style="background:var(--available)"></span>Available</span>' +
			'<span><span class="tc-swatch" style="background:var(--unavailable)"></span>Day off</span>' +
			'</div></div>';

		var prev = document.getElementById( 'tc-prev-month' );
		if ( prev ) prev.onclick = function () { state.monthOffset -= 1; loadMonth(); };
		var next = document.getElementById( 'tc-next-month' );
		if ( next ) next.onclick = function () { state.monthOffset += 1; loadMonth(); };

		root.querySelectorAll( '[data-date]' ).forEach( function ( el ) {
			el.onclick = function () {
				toggleDate( el.dataset.date, '1' === el.dataset.blocked );
			};
		} );
	}

	loadMonth();
})();
