/**
 * TC Booking admin - guide availability calendar (Guide edit screen).
 *
 * Same calendar UI/markup/CSS as public/js/guide-dashboard.js (the guide's
 * own self-service page), but talks to the admin-only GET route and
 * operates on the guide ID baked into this edit screen instead of resolving
 * "the guide" from the currently logged-in user - so an admin can manage any
 * guide's calendar without logging in as them. Booked-date handling (shown
 * amber with a tooltip, not clickable, past dates hidden) mirrors that file
 * exactly - see its top-of-file comment for the full reasoning.
 *
 * GitHub feedback - this used to auto-save one date per tap via AJAX to a
 * separate admin-only POST route, which turned out confusing: everything
 * else on this same Guide edit screen (Locations covered, Services
 * provided, the linked user account, ...) only saves when the admin clicks
 * the post editor's own Update button, so a calendar with its own separate
 * "saved" notion sitting right there in the same form was a mismatch, not
 * a convenience. Toggling a date now only stages a local change
 * (state.dirty, same concept and persistence-across-months as the
 * front-end file); on every render, that staged state is written out as
 * hidden <input name="tc_availability[DATE]"> fields inside this meta
 * box - which is itself inside the post edit screen's own <form> - so it
 * submits and saves automatically as part of the normal Update, read and
 * applied in TC_Meta_Boxes::save_guide(). No REST write route for this
 * exists anymore.
 */
( function () {
	'use strict';

	var root = document.getElementById( 'tc-guide-availability-root' );
	if ( ! root ) {
		return;
	}

	var CFG      = window.tcGuideAvailabilityAdmin;
	var API_ROOT = CFG.restRoot;
	var NONCE    = CFG.nonce;
	var GUIDE_ID = CFG.guideId;
	var BASE     = '/admin/guides/' + GUIDE_ID + '/availability';

	var state = {
		monthOffset: 0,
		availability: {}, // date -> 'blocked' | 'available', as loaded from the server
		dirty: {}, // date -> 'blocked' | 'available', staged - submitted with the post form's own Update
		bookings: {}, // date -> summary string, for dates with a real booking
		loading: true,
		error: null,
	};

	// See the matching comment in public/js/guide-dashboard.js's apiGet() -
	// a caching plugin/CDN was serving a stale cached response for this
	// exact GET despite WordPress's own Cache-Control: no-store, which is
	// what actually caused "it says saved but reverts on refresh" (found by
	// live-site debugging, confirmed by a request that bypassed the cache
	// returning correct data the normal one didn't). Applied here too even
	// though this specific screen wasn't the one reported broken - the same
	// URL-keyed cache could serve either screen's identical request.
	function apiGet( path ) {
		var bust = path.indexOf( '?' ) === -1 ? '?' : '&';
		return fetch( API_ROOT + path + bust + '_=' + Date.now(), { headers: { 'X-WP-Nonce': NONCE }, cache: 'no-store' } ).then( handleResponse );
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

	function dirtyCount() {
		return Object.keys( state.dirty ).length;
	}

	function loadMonth() {
		state.loading = true;
		render();
		var bounds = monthBounds( state.monthOffset );
		apiGet( BASE + '?start=' + isoDate( bounds.first ) + '&end=' + isoDate( bounds.last ) )
			.then( function ( data ) {
				( data.availability || [] ).forEach( function ( r ) { state.availability[ r.date ] = r.status; } );
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

	// Stages a local change only - nothing is sent to the server here, see
	// this file's top-of-file comment. Toggling a date back to its
	// last-loaded value un-stages it entirely.
	function toggleDate( iso, currentlyBlocked ) {
		var newStatus = currentlyBlocked ? 'available' : 'blocked';
		var saved     = state.availability[ iso ] || 'available';
		if ( newStatus === saved ) {
			delete state.dirty[ iso ];
		} else {
			state.dirty[ iso ] = newStatus;
		}
		render();
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
			var isDirty = Object.prototype.hasOwnProperty.call( state.dirty, iso );
			var status  = isDirty ? state.dirty[ iso ] : ( state.availability[ iso ] || 'available' );
			var booking = state.bookings[ iso ];
			var isPast  = dateObj < today;
			var cls = isPast ? 'past' : ( booking ? 'booked' : ( 'blocked' === status ? 'blocked' : 'available' ) );
			if ( isDirty ) {
				cls += ' dirty';
			}
			var attrs = ( isPast || booking ) ? '' : ' data-date="' + iso + '" data-blocked="' + ( 'blocked' === status ? '1' : '0' ) + '"';
			if ( booking && ! isPast ) {
				attrs += ' title="' + escapeAttr( booking ) + '"';
			} else if ( isDirty ) {
				attrs += ' title="' + escapeAttr( 'Not saved yet - click Update to save' ) + '"';
			}
			cells += '<div class="tc-cal-day ' + cls + '"' + attrs + '>' + d + '</div>';
		}

		var dirty      = dirtyCount();
		var hiddenInputs = Object.keys( state.dirty ).map( function ( date ) {
			return '<input type="hidden" name="tc_availability[' + escapeAttr( date ) + ']" value="' + escapeAttr( state.dirty[ date ] ) + '">';
		} ).join( '' );

		root.innerHTML = '<div class="tc-card">' +
			( state.error ? '<div class="tc-error">' + escapeHtml( state.error ) + '</div>' : '' ) +
			'<p class="tc-sub">Tap a date to toggle it between available and a day off, on this guide’s behalf - changes save when you click Update below, same as the rest of this screen. Booked dates (hover for details) can’t be changed here.</p>' +
			( dirty ? '<div class="tc-cal-status saving" style="position:static;display:inline-block;">' + escapeHtml( dirty + ( 1 === dirty ? ' change' : ' changes' ) + ' will be saved when you click Update' ) + '</div>' : '' ) +
			'<div class="tc-grid-nav"><button id="tc-admin-prev-month" type="button">←</button><span class="range">' + monthName + '</span><button id="tc-admin-next-month" type="button">→</button></div>' +
			( state.loading ? '<p>Loading…</p>' : '<div class="tc-cal-grid">' +
				[ 'M', 'T', 'W', 'T', 'F', 'S', 'S' ].map( function ( l ) { return '<div class="tc-cal-dow">' + l + '</div>'; } ).join( '' ) +
				cells + '</div>' ) +
			'<div class="tc-legend" style="margin-top:16px;">' +
			'<span><span class="tc-swatch" style="background:var(--available)"></span>Available</span>' +
			'<span><span class="tc-swatch" style="background:var(--unavailable)"></span>Day off</span>' +
			'<span><span class="tc-swatch" style="background:var(--limited)"></span>Booked</span>' +
			'</div>' + hiddenInputs + '</div>';

		var prev = document.getElementById( 'tc-admin-prev-month' );
		if ( prev ) prev.onclick = function () { state.monthOffset -= 1; loadMonth(); };
		var next = document.getElementById( 'tc-admin-next-month' );
		if ( next ) next.onclick = function () { state.monthOffset += 1; loadMonth(); };

		root.querySelectorAll( '[data-date]' ).forEach( function ( el ) {
			el.onclick = function () {
				toggleDate( el.dataset.date, '1' === el.dataset.blocked );
			};
		} );
	}

	loadMonth();
} )();
