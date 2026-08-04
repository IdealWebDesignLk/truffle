/**
 * TC Booking admin - guide availability calendar (Guide edit screen).
 *
 * Same calendar UI/markup/CSS as public/js/guide-dashboard.js (the guide's
 * own self-service page), but talks to the admin-only REST routes and
 * operates on the guide ID baked into this edit screen instead of resolving
 * "the guide" from the currently logged-in user - so an admin can manage any
 * guide's calendar without logging in as them.
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
		return d.toISOString().slice( 0, 10 );
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
		apiGet( BASE + '?start=' + isoDate( bounds.first ) + '&end=' + isoDate( bounds.last ) )
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
		apiPost( BASE, { date: iso, status: newStatus } ).catch( function ( err ) {
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
			'<p class="tc-sub">Tap a date to toggle it between available and a day off, on this guide’s behalf.</p>' +
			'<div class="tc-grid-nav"><button id="tc-admin-prev-month" type="button">←</button><span class="range">' + monthName + '</span><button id="tc-admin-next-month" type="button">→</button></div>' +
			( state.loading ? '<p>Loading…</p>' : '<div class="tc-cal-grid">' +
				[ 'M', 'T', 'W', 'T', 'F', 'S', 'S' ].map( function ( l ) { return '<div class="tc-cal-dow">' + l + '</div>'; } ).join( '' ) +
				cells + '</div>' ) +
			'<div class="tc-legend" style="margin-top:16px;">' +
			'<span><span class="tc-swatch" style="background:var(--available)"></span>Available</span>' +
			'<span><span class="tc-swatch" style="background:var(--unavailable)"></span>Day off</span>' +
			'</div></div>';

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
