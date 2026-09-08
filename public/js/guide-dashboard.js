/**
 * TC Booking - guide self-service calendar.
 *
 * A guide sees a month at a time. Green = available (the default - no
 * action needed). Clicking a date toggles it to blocked (a day off) and
 * back. Dates with an existing booking are shown (amber, with the
 * ceremony/customer as a tooltip) but not clickable - to change those,
 * the guide needs to contact admin, since cancelling/moving a paid
 * booking has consequences (refunds, notifying the customer) that
 * deliberately stay an admin action rather than a guide self-service one.
 * Also enforced server-side (guide_set_availability() in
 * class-tc-rest-api.php refuses to mark an already-booked date as a day
 * off) - this client-side omission of data-date is only the UI half.
 *
 * Past dates are already excluded from being clickable (isPast below),
 * and in fact not rendered at all (.tc-cal-day.past is visibility:hidden
 * in booking-app.css) - a guide can only ever set availability for today
 * or a future date.
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
		bookings: {}, // date -> summary string, for dates with a real booking
		loading: true,
		error: null,
		pending: {}, // date -> true while a save request for that date is in flight
		status: null, // 'saving' | 'saved' | null - small status message near the title
	};
	var savedTimer = null;

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

	function escapeHtml( str ) {
		var div = document.createElement( 'div' );
		div.textContent = str == null ? '' : String( str );
		return div.innerHTML;
	}
	function escapeAttr( str ) {
		return escapeHtml( str ).replace( /"/g, '&quot;' );
	}

	var SITE_TZ = 'Europe/Amsterdam';

	// This business only operates in the Netherlands, so "today"/"this
	// month" must always mean the Netherlands' calendar day, not the
	// visitor's own device timezone. Built via Intl so CET/CEST
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
		// Not toISOString() - that converts to UTC, which silently shifts the
		// date back a day for any browser ahead of UTC. Build the string from
		// local date parts instead, matching how the Date object was built
		// (nlToday()), so what's saved always matches the Netherlands
		// calendar day being displayed.
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

	function loadMonth() {
		state.loading = true;
		render();
		var bounds = monthBounds( state.monthOffset );
		apiGet( '/guide/availability?start=' + isoDate( bounds.first ) + '&end=' + isoDate( bounds.last ) )
			.then( function ( data ) {
				state.availability = {};
				( data.availability || [] ).forEach( function ( r ) { state.availability[ r.date ] = r.status; } );
				state.bookings = {};
				( data.bookings || [] ).forEach( function ( r ) { state.bookings[ r.date ] = r.summary; } );
				state.loading = false;
				render();
			} )
			.catch( function ( err ) {
				state.error   = err.message;
				state.loading = false;
				render();
			} );
	}

	// Reported as "sometimes it saves, sometimes it doesn't": the previous
	// version applied the optimistic change and never undid it on a failed
	// save, with no visible sign anything had gone wrong beyond a small
	// error banner easy to miss - the calendar just quietly kept showing a
	// status the server never actually stored. Fixed by reverting to the
	// PREVIOUS value on failure (so the calendar never lies about what's
	// saved) and by ignoring a repeat tap on a date that's still saving
	// (state.pending) - a fast double-tap firing two overlapping requests
	// for the same date, and whichever response lands second silently
	// "winning," was a real way for this to happen, especially on mobile.
	function toggleDate( iso, currentlyBlocked ) {
		if ( state.pending[ iso ] ) {
			return;
		}
		var newStatus = currentlyBlocked ? 'available' : 'blocked';
		var previous  = state.availability[ iso ];
		state.availability[ iso ] = newStatus; // optimistic
		state.pending[ iso ] = true;
		state.status = 'saving';
		state.error  = null;
		clearTimeout( savedTimer );
		render();
		apiPost( '/guide/availability', { date: iso, status: newStatus } )
			.then( function () {
				delete state.pending[ iso ];
				state.status = 'saved';
				render();
				savedTimer = setTimeout( function () {
					state.status = null;
					render();
				}, 2000 );
			} )
			.catch( function ( err ) {
				if ( previous ) {
					state.availability[ iso ] = previous;
				} else {
					delete state.availability[ iso ];
				}
				delete state.pending[ iso ];
				state.status = null;
				state.error  = err.message;
				render();
			} );
	}

	function render() {
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
			var dateObj = new Date( bounds.first.getFullYear(), bounds.first.getMonth(), d );
			var iso     = isoDate( dateObj );
			var status  = state.availability[ iso ] || 'available';
			var booking = state.bookings[ iso ];
			var isPast  = dateObj < today;
			var pending = !! state.pending[ iso ];
			// A booked date always shows as "booked" and is never
			// clickable, regardless of what the availability table says -
			// see the top-of-file comment.
			var cls = isPast ? 'past' : ( booking ? 'booked' : ( 'blocked' === status ? 'blocked' : 'available' ) );
			if ( pending ) {
				cls += ' pending';
			}
			var attrs = ( isPast || booking || pending ) ? '' : ' data-date="' + iso + '" data-blocked="' + ( 'blocked' === status ? '1' : '0' ) + '"';
			if ( booking && ! isPast ) {
				attrs += ' title="' + escapeAttr( booking ) + '"';
			}
			cells += '<div class="tc-cal-day ' + cls + '"' + attrs + '>' + d + '</div>';
		}

		var statusHtml = '';
		if ( 'saving' === state.status ) {
			statusHtml = '<div class="tc-cal-status saving" aria-live="polite">Saving\u2026</div>';
		} else if ( 'saved' === state.status ) {
			statusHtml = '<div class="tc-cal-status saved" aria-live="polite">\u2713 Saved</div>';
		}

		root.innerHTML = '<div class="tc-card">' +
			( state.error ? '<div class="tc-error">' + escapeHtml( state.error ) + '</div>' : '' ) +
			'<h2 class="tc-title">Your availability</h2>' +
			'<p class="tc-sub">This is your own calendar - tap a date to mark it as a day off, or tap again to reopen it. Everything is available by default. Booked dates (hover for details) can\u2019t be changed here - contact admin if one needs to move.</p>' +
			statusHtml +
			'<div class="tc-grid-nav"><button id="tc-prev-month">\u2190</button><span class="range">' + monthName + '</span><button id="tc-next-month">\u2192</button></div>' +
			( state.loading ? '<p>Loading\u2026</p>' : '<div class="tc-cal-grid">' +
				[ 'M', 'T', 'W', 'T', 'F', 'S', 'S' ].map( function ( l ) { return '<div class="tc-cal-dow">' + l + '</div>'; } ).join( '' ) +
				cells + '</div>' ) +
			'<div class="tc-legend" style="margin-top:16px;">' +
			'<span><span class="tc-swatch" style="background:var(--available)"></span>Available</span>' +
			'<span><span class="tc-swatch" style="background:var(--unavailable)"></span>Day off</span>' +
			'<span><span class="tc-swatch" style="background:var(--limited)"></span>Booked</span>' +
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
